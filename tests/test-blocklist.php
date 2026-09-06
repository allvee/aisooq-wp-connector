<?php
/**
 * The operator's own block / allow list, and the record of refused checkouts.
 *
 * @package AISooq
 */

class Test_Blocklist extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		AI_Sooq_Install::create_table();
		AI_Sooq_Blocklist::flush_caches();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AI_Sooq_Blocklist::table_name() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . AI_Sooq_Blocklist::log_table_name() ); // phpcs:ignore WordPress.DB
	}

	// ── Normalisation ───────────────────────────────────────────────────────

	/**
	 * A list that matched the characters rather than the person would be
	 * trivially defeated: the same customer types their number four ways.
	 */
	public function test_a_phone_matches_however_it_was_written() {
		$this->assertTrue( AI_Sooq_Blocklist::add( 'phone', '+880 1712-345678', 'block', 'abuse' ) );

		foreach ( array( '01712345678', '01712-345678', '+8801712345678', '8801712345678' ) as $written ) {
			AI_Sooq_Blocklist::flush_caches();
			$res = AI_Sooq_Blocklist::decide( $written, '', '' );
			$this->assertSame( 'block', $res['decision'], "{$written} should be blocked." );
		}
	}

	public function test_an_email_matches_case_insensitively() {
		AI_Sooq_Blocklist::add( 'email', 'Abuser@Example.COM', 'block' );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '', 'abuser@example.com', '' )['decision'] );
	}

	public function test_rubbish_is_rejected_rather_than_stored_matching_nothing() {
		$this->assertWPError( AI_Sooq_Blocklist::add( 'phone', 'not a phone', 'block' ) );
		$this->assertWPError( AI_Sooq_Blocklist::add( 'email', 'not an email', 'block' ) );
		$this->assertWPError( AI_Sooq_Blocklist::add( 'ip', '999.999.999.999', 'block' ) );
		$this->assertWPError( AI_Sooq_Blocklist::add( 'ip', '203.0.113.0/99', 'block' ) );
		$this->assertWPError( AI_Sooq_Blocklist::add( 'nonsense', 'x', 'block' ) );
	}

	// ── IP and CIDR ─────────────────────────────────────────────────────────

	public function test_an_exact_ip_is_blocked() {
		AI_Sooq_Blocklist::add( 'ip', '203.0.113.4', 'block' );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '', '', '203.0.113.4' )['decision'] );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( '', AI_Sooq_Blocklist::decide( '', '', '203.0.113.5' )['decision'] );
	}

	/** Blocking one address is nearly useless against anyone with a range. */
	public function test_a_cidr_range_covers_its_addresses() {
		AI_Sooq_Blocklist::add( 'ip', '203.0.113.0/24', 'block' );

		foreach ( array( '203.0.113.1', '203.0.113.200' ) as $inside ) {
			AI_Sooq_Blocklist::flush_caches();
			$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '', '', $inside )['decision'], $inside );
		}
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( '', AI_Sooq_Blocklist::decide( '', '', '203.0.114.1' )['decision'] );
	}

	/**
	 * A prefix that does not land on a byte boundary is where a hand-rolled
	 * mask goes wrong, and getting it wrong in the permissive direction blocks
	 * bystanders who merely share a neighbourhood with an abuser.
	 */
	public function test_cidr_matching_handles_partial_bytes() {
		// /30 covers .0 through .3 only.
		$this->assertTrue( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.2', '10.0.0.0/30' ) );
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.5', '10.0.0.0/30' ) );

		// /28 covers .16 through .31.
		$this->assertTrue( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.31', '10.0.0.16/28' ) );
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.15', '10.0.0.16/28' ) );
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.32', '10.0.0.16/28' ) );
	}

	public function test_cidr_matching_keeps_the_address_families_apart() {
		// A v6 address judged against a v4 range must not match by accident.
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '2001:db8::1', '10.0.0.0/8' ) );
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '10.0.0.1', '2001:db8::/32' ) );
		$this->assertTrue( AI_Sooq_Blocklist::ip_in_cidr( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( AI_Sooq_Blocklist::ip_in_cidr( '2001:db9::1', '2001:db8::/32' ) );
	}

	// ── Allow beats block ───────────────────────────────────────────────────

	/**
	 * An allow entry is the operator overruling the machine. If a block could
	 * outrank it they would have no final say over their own shop.
	 */
	public function test_allow_wins_over_a_block_on_another_identifier() {
		AI_Sooq_Blocklist::add( 'ip', '203.0.113.4', 'block' );
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'allow', 'known good customer' );
		AI_Sooq_Blocklist::flush_caches();

		$res = AI_Sooq_Blocklist::decide( '01712345678', '', '203.0.113.4' );
		$this->assertSame( 'allow', $res['decision'] );
	}

	// ── Expiry ──────────────────────────────────────────────────────────────

	/** A temporary block must stop applying on its own. */
	public function test_an_expired_entry_no_longer_matches() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', 'temporary', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( '', AI_Sooq_Blocklist::decide( '01712345678', '', '' )['decision'] );
	}

	public function test_a_future_expiry_still_matches() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', 'temporary', gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '01712345678', '', '' )['decision'] );
	}

	// ── Editing ─────────────────────────────────────────────────────────────

	/** Changing your mind must edit the entry, not fail on the unique index. */
	public function test_re_adding_the_same_value_flips_it_rather_than_erroring() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block' );
		$this->assertTrue( AI_Sooq_Blocklist::add( 'phone', '01712345678', 'allow', 'was wrong' ) );

		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'allow', AI_Sooq_Blocklist::decide( '01712345678', '', '' )['decision'] );

		$entries = AI_Sooq_Blocklist::entries();
		$this->assertSame( 1, $entries['total'], 'Re-adding must not create a second row.' );
	}

	public function test_removing_an_entry_stops_it_matching() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block' );
		$entries = AI_Sooq_Blocklist::entries();
		$this->assertTrue( AI_Sooq_Blocklist::remove( (int) $entries['rows'][0]->id ) );

		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( '', AI_Sooq_Blocklist::decide( '01712345678', '', '' )['decision'] );
	}

	public function test_a_block_records_that_it_fired() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block' );
		AI_Sooq_Blocklist::flush_caches();
		AI_Sooq_Blocklist::decide( '01712345678', '', '' );

		$entries = AI_Sooq_Blocklist::entries();
		$this->assertSame( 1, (int) $entries['rows'][0]->hits );
	}

	// ── The log ─────────────────────────────────────────────────────────────

	/**
	 * A `block` never becomes an order, so before this the refusal left no
	 * trace at all and nobody could tell a wall of fraud from a threshold
	 * turning away real customers.
	 */
	public function test_a_refusal_is_recorded_with_its_gate_and_reason() {
		AI_Sooq_Blocklist::log(
			'courier',
			'Delivery success 31%',
			array( 'phone' => '+8801712345678', 'ip' => '203.0.113.4', 'name' => 'A Shopper' )
		);

		$log = AI_Sooq_Blocklist::log_entries();
		$this->assertSame( 1, $log['total'] );
		$row = $log['rows'][0];
		$this->assertSame( 'courier', $row->gate );
		$this->assertSame( 'Delivery success 31%', $row->reason );
		// Stored normalised, so searching the log finds the number however the
		// shopper typed it.
		$this->assertSame( '01712345678', $row->phone );
	}

	public function test_the_summary_counts_by_gate() {
		AI_Sooq_Blocklist::log( 'fraud', 'x', array( 'phone' => '01712345678' ) );
		AI_Sooq_Blocklist::log( 'fraud', 'x', array( 'phone' => '01712345679' ) );
		AI_Sooq_Blocklist::log( 'courier', 'y', array( 'phone' => '01712345670' ) );

		$k = AI_Sooq_Blocklist::log_summary( 7 );
		$this->assertSame( 3, $k['total'] );
		$this->assertSame( 2, $k['fraud'] );
		$this->assertSame( 1, $k['courier'] );
	}

	public function test_gc_prunes_old_log_rows_but_keeps_recent_ones() {
		global $wpdb;
		$table = AI_Sooq_Blocklist::log_table_name();

		$wpdb->insert( $table, array( 'gate' => 'fraud', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) );
		$wpdb->insert( $table, array( 'gate' => 'fraud', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );

		AI_Sooq_Blocklist::gc();

		$this->assertSame( 1, AI_Sooq_Blocklist::log_entries()['total'] );
	}

	public function test_gc_removes_expired_entries() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', '', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		AI_Sooq_Blocklist::add( 'phone', '01812345678', 'block' );

		AI_Sooq_Blocklist::gc();

		$this->assertSame( 1, AI_Sooq_Blocklist::entries()['total'] );
	}

	// ── The gate at checkout ────────────────────────────────────────────────

	/**
	 * Driven through screen_classic(), the hook WooCommerce actually calls, so
	 * these test the behaviour a shopper meets rather than a private helper.
	 */
	private function fraud_with( array $overrides = array() ) {
		update_option(
			AISOOQ_OPTION,
			array_merge(
				array(
					'active'                 => 1,
					'api_base'               => 'https://api.example.test',
					'sid'                    => 'store1',
					'client_id'              => 'cid',
					'client_secret'          => 'csecret',
					// Platform layers off: this is about the local list, and an
					// HTTP call here would be a network dependency in a unit test.
					'enable_fraud'           => 0,
					'courier_min_ratio'      => 0,
					'dup_order_block'        => 0,
				),
				$overrides
			)
		);
		$settings = new AI_Sooq_Settings();
		$logger   = new AI_Sooq_Logger( $settings );
		return new AI_Sooq_Fraud( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );
	}

	private function screen( AI_Sooq_Fraud $fraud, $phone, $email = '' ) {
		$errors = new WP_Error();
		$fraud->screen_classic(
			array(
				'billing_first_name' => 'Karim',
				'billing_last_name'  => 'Rahman',
				'billing_phone'      => $phone,
				'billing_email'      => $email,
				'billing_address_1'  => 'House 4, Road 7, Dhanmondi',
			),
			$errors
		);
		return $errors;
	}

	public function test_a_listed_number_is_refused_at_checkout() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', 'repeat abuse' );
		AI_Sooq_Blocklist::flush_caches();

		$errors = $this->screen( $this->fraud_with(), '01712345678' );

		$this->assertContains( 'aisooq_blocked', $errors->get_error_codes() );
	}

	/** …however they choose to type it. */
	public function test_a_listed_number_is_refused_however_it_is_written() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block' );
		AI_Sooq_Blocklist::flush_caches();

		$errors = $this->screen( $this->fraud_with(), '+880 1712-345678' );

		$this->assertContains( 'aisooq_blocked', $errors->get_error_codes() );
	}

	public function test_an_unlisted_number_passes() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block' );
		AI_Sooq_Blocklist::flush_caches();

		$errors = $this->screen( $this->fraud_with(), '01999999999' );

		$this->assertNotContains( 'aisooq_blocked', $errors->get_error_codes() );
	}

	/**
	 * The whole point of an allow entry: it must outrank the gates that would
	 * otherwise refuse this shopper. Here the duplicate guard is armed and the
	 * shopper already has an order, so without the allow they would be blocked.
	 */
	public function test_an_allowed_number_survives_a_gate_that_would_refuse_it() {
		$order = new WC_Order();
		$order->set_billing_phone( '01712345678' );
		$order->set_status( 'processing' );
		$order->save();

		$fraud = $this->fraud_with( array( 'dup_order_block' => 1, 'dup_order_window_hours' => 24 ) );

		// Without the allow entry, the duplicate guard refuses them.
		$this->assertContains( 'aisooq_duplicate', $this->screen( $fraud, '01712345678' )->get_error_codes() );

		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'allow', 'known good customer' );
		AI_Sooq_Blocklist::flush_caches();

		$errors = $this->screen( $fraud, '01712345678' );
		$this->assertSame( array(), $errors->get_error_codes(), 'An allow entry must clear every gate.' );
	}

	/** A refusal at checkout must leave the record the screen reads. */
	public function test_a_checkout_refusal_is_written_to_the_log() {
		AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', 'repeat abuse' );
		AI_Sooq_Blocklist::flush_caches();

		$this->screen( $this->fraud_with(), '01712345678' );

		$log = AI_Sooq_Blocklist::log_entries();
		$this->assertSame( 1, $log['total'] );
		$this->assertSame( 'manual', $log['rows'][0]->gate );
		$this->assertSame( '01712345678', $log['rows'][0]->phone );
	}

	/**
	 * The duplicate gate blocks before an order exists, so its refusal was
	 * previously invisible. It must reach the log too.
	 */
	public function test_a_duplicate_refusal_is_written_to_the_log() {
		$order = new WC_Order();
		$order->set_billing_phone( '01712345678' );
		$order->set_status( 'processing' );
		$order->save();

		$fraud  = $this->fraud_with( array( 'dup_order_block' => 1, 'dup_order_window_hours' => 24 ) );
		$errors = $this->screen( $fraud, '01712345678' );
		$this->assertContains( 'aisooq_duplicate', $errors->get_error_codes(), 'precondition: the gate must actually fire' );

		$log = AI_Sooq_Blocklist::log_entries();
		$this->assertSame( 1, $log['total'] );
		$this->assertSame( 'duplicate', $log['rows'][0]->gate );
	}

	// ── Nothing to say ──────────────────────────────────────────────────────

	/**
	 * Every default checkout message this plugin ships is Bangla, where one
	 * character is three bytes. Truncating the reason with substr() cut
	 * mid-character, wpdb rejected the invalid UTF-8, and the row vanished —
	 * so the refusal the log existed to record was the thing it lost.
	 */
	public function test_a_bangla_reason_is_stored_rather_than_silently_dropped() {
		$bangla = 'আপনার একটি অর্ডার ইতিমধ্যে গ্রহণ করা হয়েছে। ২৪ ঘণ্টার মধ্যে একই নম্বর থেকে আবার অর্ডার করা যাবে না। অর্ডারে কিছু যোগ বা পরিবর্তন করতে আমাদের সাথে যোগাযোগ করুন। অতিরিক্ত তথ্যের জন্য আমাদের হেল্পলাইনে কল করুন এবং আপনার অর্ডার নম্বরটি প্রস্তুত রাখুন।';
		$this->assertGreaterThan( 255, strlen( $bangla ), 'precondition: long enough in BYTES to need truncating' );

		AI_Sooq_Blocklist::log( 'duplicate', $bangla, array( 'phone' => '01712345678' ) );

		$log = AI_Sooq_Blocklist::log_entries();
		$this->assertSame( 1, $log['total'], 'The row must survive truncation.' );
		$this->assertSame( 'duplicate', $log['rows'][0]->gate );
		$this->assertNotSame( '', (string) $log['rows'][0]->reason );
	}

	/** A Bangla customer name must survive the same path. */
	public function test_a_bangla_name_is_stored() {
		AI_Sooq_Blocklist::log( 'fraud', 'x', array( 'phone' => '01712345678', 'name' => str_repeat( 'করিম রহমান ', 40 ) ) );
		$log = AI_Sooq_Blocklist::log_entries();
		$this->assertSame( 1, $log['total'] );
		$this->assertNotSame( '', (string) $log['rows'][0]->name );
	}

	/**
	 * The body the fraud screen sends must remain encodable. A Bangla name long
	 * enough to hit the 255 limit was cut mid-character, wp_json_encode()
	 * returned false, and the screen silently stopped working for exactly those
	 * customers.
	 */
	public function test_a_bangla_name_still_produces_an_encodable_fraud_body() {
		$fraud = $this->fraud_with();
		$m     = new ReflectionMethod( 'AI_Sooq_Fraud', 'ctx' );
		$m->setAccessible( true );

		$long_name    = str_repeat( 'মোহাম্মদ ', 60 );  // well past 255 bytes
		$long_address = str_repeat( 'ঢাকা রোড ', 120 ); // well past 500 bytes
		$ctx          = $m->invoke( $fraud, $long_name, '01712345678', $long_address );

		$this->assertNotFalse( wp_json_encode( $ctx ), 'The screen body must still encode as JSON.' );
		$this->assertSame( $ctx['name'], wp_check_invalid_utf8( $ctx['name'] ), 'name must stay valid UTF-8' );
		$this->assertSame( $ctx['address'], wp_check_invalid_utf8( $ctx['address'] ), 'address must stay valid UTF-8' );
	}

	public function test_an_empty_checkout_is_not_blocked() {
		$this->assertSame( '', AI_Sooq_Blocklist::decide( '', '', '' )['decision'] );
	}
}
