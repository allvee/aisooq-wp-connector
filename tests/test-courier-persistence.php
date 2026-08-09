<?php
/**
 * Courier delivery-history persistence on the abandoned-cart worklist.
 *
 * A courier lookup costs the merchant a paid BDCourier call on the platform, so
 * the answer has to live in the DB, not on the screen. Before this was fixed the
 * result was rendered straight into the DOM and thrown away — a reload, or even
 * typing in the search box (which re-renders the table), sent every checked row
 * back to an unchecked "Check ratio" button and invited a second paid lookup.
 *
 * These tests go through the real table, because "it survives a re-render" is
 * exactly the property that was broken.
 *
 * @package AISooq
 */

class Test_Courier_Persistence extends WP_UnitTestCase {

	/** @var AI_Sooq_Abandoned_Sync */
	private $sync;

	/** Full-fat response from the widened `GET /connect/courier`. */
	private function payload() {
		return array(
			'successRatio'    => 76.0,
			'totalParcel'     => 25,
			'successParcel'   => 19,
			'cancelledParcel' => 6,
			'fetchedAt'       => '2026-08-08T10:00:00.000Z',
			'couriers'        => array(
				array( 'slug' => 'pathao', 'name' => 'Pathao', 'total' => 20, 'success' => 18, 'cancelled' => 2, 'ratio' => 90.0 ),
				array( 'slug' => 'steadfast', 'name' => 'Steadfast', 'total' => 5, 'success' => 1, 'cancelled' => 4, 'ratio' => 20.0 ),
			),
		);
	}

	public function set_up() {
		parent::set_up();
		AI_Sooq_Install::create_table();
		$settings   = new AI_Sooq_Settings();
		$logger     = new AI_Sooq_Logger( $settings );
		$this->sync = new AI_Sooq_Abandoned_Sync(
			$settings,
			new AI_Sooq_Api_Client( $settings, $logger ),
			$logger
		);
	}

	/** Insert a bare cart row and return its session key. */
	private function make_cart( $phone = '+8801712345678', $updated = '2026-08-01 09:00:00' ) {
		global $wpdb;
		$key = 'sess_' . wp_generate_password( 12, false );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			AI_Sooq_Abandoned_Sync::table_name(),
			array(
				'session_key' => $key,
				'phone'       => $phone,
				'cart_json'   => wp_json_encode( array( array( 'title' => 'Attar', 'qty' => 1, 'price' => 900 ) ) ),
				'subtotal'    => 900,
				'status'      => 'active',
				'created_at'  => $updated,
				'updated_at'  => $updated,
			)
		);
		return $key;
	}

	public function test_saved_lookup_survives_a_reload() {
		$key = $this->make_cart();
		$this->sync->save_courier( $key, '+8801712345678', $this->payload() );

		// Re-read from the DB exactly as a fresh page render would.
		$snap = $this->sync->courier_snapshot( $this->sync->get_row( $key ) );

		$this->assertNotNull( $snap, 'A checked cart must still be checked after a re-render.' );
		$this->assertSame( 76.0, $snap['ratio'] );
		$this->assertSame( 25, $snap['parcels'] );
		$this->assertSame( 19, $snap['success'] );
		$this->assertSame( 6, $snap['cancelled'] );
	}

	public function test_stores_the_full_per_courier_breakdown() {
		$key = $this->make_cart();
		$this->sync->save_courier( $key, '+8801712345678', $this->payload() );

		$snap = $this->sync->courier_snapshot( $this->sync->get_row( $key ) );

		$this->assertCount( 2, $snap['couriers'] );
		$this->assertSame( 'Pathao', $snap['couriers'][0]['name'] );
		$this->assertSame( 20, $snap['couriers'][0]['total'] );
		$this->assertSame( 18, $snap['couriers'][0]['success'] );
		$this->assertSame( 2, $snap['couriers'][0]['cancelled'] );
		$this->assertSame( 90.0, $snap['couriers'][0]['ratio'] );
		$this->assertSame( 'steadfast', $snap['couriers'][1]['slug'] );
	}

	public function test_records_a_no_history_answer_so_it_is_not_re_queried() {
		$key = $this->make_cart();
		// What the platform returns for an unknown number, or when BDCourier
		// isn't configured for the store.
		$this->sync->save_courier( $key, '+8801712345678', array(
			'successRatio' => null,
			'totalParcel'  => null,
			'couriers'     => array(),
		) );

		$snap = $this->sync->courier_snapshot( $this->sync->get_row( $key ) );

		$this->assertNotNull( $snap, 'A "no history" answer is still an answer — do not re-charge for it.' );
		$this->assertNull( $snap['ratio'] );
		$this->assertNull( $snap['parcels'] );
		$this->assertSame( array(), $snap['couriers'] );
	}

	public function test_persists_headline_when_the_platform_omits_the_breakdown() {
		$key = $this->make_cart();
		// A platform build predating the widened endpoint: two fields, no couriers[].
		$this->sync->save_courier( $key, '+8801712345678', array(
			'successRatio' => 88.0,
			'totalParcel'  => 20,
		) );

		$snap = $this->sync->courier_snapshot( $this->sync->get_row( $key ) );

		$this->assertSame( 88.0, $snap['ratio'] );
		$this->assertSame( 20, $snap['parcels'] );
		$this->assertSame( array(), $snap['couriers'] );
	}

	public function test_discards_a_result_checked_against_a_different_phone() {
		global $wpdb;
		$key = $this->make_cart( '+88017123' ); // Shopper is still typing.
		$this->sync->save_courier( $key, '+88017123', $this->payload() );

		// The beacon lands the completed number.
		$wpdb->update( // phpcs:ignore WordPress.DB
			AI_Sooq_Abandoned_Sync::table_name(),
			array( 'phone' => '+8801712345678' ),
			array( 'session_key' => $key )
		);

		$this->assertNull(
			$this->sync->courier_snapshot( $this->sync->get_row( $key ) ),
			'A ratio fetched for a half-typed number must not be shown against the corrected one.'
		);
	}

	public function test_unchecked_cart_has_no_snapshot() {
		$key = $this->make_cart();
		$this->assertNull( $this->sync->courier_snapshot( $this->sync->get_row( $key ) ) );
	}

	public function test_checking_a_ratio_does_not_reorder_the_worklist() {
		$key = $this->make_cart( '+8801712345678', '2026-08-01 09:00:00' );
		$this->sync->save_courier( $key, '+8801712345678', $this->payload() );

		$row = $this->sync->get_row( $key );

		// `updated_at` orders the worklist and defines the resync working set.
		// Reading a ratio is not cart activity.
		$this->assertSame( '2026-08-01 09:00:00', $row->updated_at );
		$this->assertNotEmpty( $row->courier_checked_at );
	}
}
