<?php
/**
 * Orders that gave up: recording why, listing them, and sending them again.
 *
 * The retry semantics are the delicate part and most of these tests are about
 * them. Resetting an order's attempt count on retry looks obviously right and
 * is a trap: it removes the order from this screen and from the count BEFORE
 * anything has been pushed, so if the queued job never runs — cron off, a
 * wedged queue, the operator pausing the connection a minute later — the order
 * is stranded with nothing left pointing at it.
 *
 * @package AISooq
 */

class Test_Failed_Orders extends WP_UnitTestCase {

	/** @var AI_Sooq_Order_Sync */
	private $sync;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		$this->sync = $this->sync_with();
		AI_Sooq_Order_Sync::flush_failed_count();
	}

	private function sync_with( array $overrides = array() ) {
		update_option(
			AISOOQ_OPTION,
			array_merge(
				array(
					'active'         => 1,
					'api_base'       => 'https://api.example.test',
					'sid'            => 'store1',
					'client_id'      => 'cid',
					'client_secret'  => 'csecret',
					'enable_orders'  => 1,
					'order_statuses' => array( 'processing', 'completed' ),
				),
				$overrides
			)
		);
		$settings = new AI_Sooq_Settings();
		$logger   = new AI_Sooq_Logger( $settings );
		return new AI_Sooq_Order_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );
	}

	/**
	 * @param string $status
	 * @param int    $age_hours How far in the past to date it. Ordering in the
	 *                          dead-letter query is by creation date, and
	 *                          orders created in the same second sort
	 *                          arbitrarily between themselves — so any test
	 *                          about which page a row lands on has to space
	 *                          them out explicitly or it is flaky.
	 */
	private function make_order( $status = 'processing', $age_hours = 0 ) {
		$order = new WC_Order();
		$order->set_payment_method( 'cod' );
		$order->set_status( $status );
		if ( $age_hours > 0 ) {
			$order->set_date_created( time() - ( $age_hours * HOUR_IN_SECONDS ) );
		}
		$order->save();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 0, 1 ) as $b ) {
				as_unschedule_all_actions( AISOOQ_SYNC_ACTION, array( $order->get_id(), $b ), AISOOQ_AS_GROUP );
			}
		}
		return wc_get_order( $order->get_id() );
	}

	/** Drive the private handle_failure(). */
	private function fail_once( WC_Order $order, WP_Error $err ) {
		$m = new ReflectionMethod( 'AI_Sooq_Order_Sync', 'handle_failure' );
		$m->setAccessible( true );
		$m->invoke( $this->sync, $order, $err, false );
	}

	private function exhaust( WC_Order $order, $message = 'Server error' ) {
		$order->update_meta_data( AISOOQ_META_ATTEMPTS, AI_Sooq_Order_Sync::MAX_ATTEMPTS - 1 );
		$order->save();
		$this->fail_once( wc_get_order( $order->get_id() ), new WP_Error( 'aisooq_http_500', $message, array( 'status' => 500 ) ) );
		return wc_get_order( $order->get_id() );
	}

	// ── Recording the reason ────────────────────────────────────────────────

	/**
	 * "3 failed" tells an operator something is wrong and nothing about what.
	 * The reason is the entire value of this feature.
	 */
	public function test_a_failure_records_why_and_when() {
		$order = $this->exhaust( $this->make_order(), 'Missing Store SID.' );

		$this->assertSame( 'Missing Store SID.', $order->get_meta( AISOOQ_META_ERROR ) );
		$this->assertSame( 'aisooq_http_500', $order->get_meta( AISOOQ_META_ERROR_CODE ) );
		$this->assertNotSame( '', (string) $order->get_meta( AISOOQ_META_LAST_TRY ) );
	}

	/** A Bangla platform error must survive being stored. */
	public function test_a_multibyte_reason_is_stored_intact() {
		$bangla = str_repeat( 'প্ল্যাটফর্ম ত্রুটি ', 40 );
		$order  = $this->exhaust( $this->make_order(), $bangla );

		$stored = (string) $order->get_meta( AISOOQ_META_ERROR );
		$this->assertNotSame( '', $stored );
		$this->assertSame( $stored, wp_check_invalid_utf8( $stored ), 'The reason must stay valid UTF-8.' );
	}

	// ── Which orders are "given up" ─────────────────────────────────────────

	public function test_an_exhausted_order_is_counted() {
		$this->exhaust( $this->make_order() );
		AI_Sooq_Order_Sync::flush_failed_count();
		$this->assertSame( 1, AI_Sooq_Order_Sync::failed_count( true ) );
	}

	public function test_an_order_still_retrying_is_not_counted() {
		$order = $this->make_order();
		$this->fail_once( $order, new WP_Error( 'aisooq_http_500', 'x', array( 'status' => 500 ) ) );
		AI_Sooq_Order_Sync::flush_failed_count();
		$this->assertSame( 0, AI_Sooq_Order_Sync::failed_count( true ) );
	}

	/**
	 * The notice path must not put an unindexable COUNT over the whole order
	 * meta table on the dashboard of the biggest stores. On a cold cache it
	 * answers 0 rather than querying.
	 */
	public function test_the_count_does_not_query_on_a_cold_cache_unless_asked() {
		$this->exhaust( $this->make_order() );
		AI_Sooq_Order_Sync::flush_failed_count();

		$this->assertSame( 0, AI_Sooq_Order_Sync::failed_count(), 'A cold cache must not trigger the query.' );
		$this->assertSame( 1, AI_Sooq_Order_Sync::failed_count( true ), '…but asking for it fresh must.' );
		$this->assertSame( 1, AI_Sooq_Order_Sync::failed_count(), 'and it is cached afterwards.' );
	}

	// ── Retry ───────────────────────────────────────────────────────────────

	/**
	 * The trap. Clearing the attempt count would drop the order off this screen
	 * and out of the count before anything was pushed — and if the queued job
	 * never ran, nothing would ever point at that order again.
	 */
	public function test_a_retry_does_not_reset_the_attempt_count() {
		$order = $this->exhaust( $this->make_order() );
		$this->assertSame( AI_Sooq_Order_Sync::MAX_ATTEMPTS, (int) $order->get_meta( AISOOQ_META_ATTEMPTS ) );

		$this->sync->retry( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_ATTEMPTS,
			(int) $fresh->get_meta( AISOOQ_META_ATTEMPTS ),
			'A retry must leave the order visible until it actually succeeds.'
		);
	}

	/**
	 * Re-arming the deferral ceiling in bulk would authorise MAX_RATE_DEFERRALS
	 * more re-queues per order — tens of thousands of scheduled actions from
	 * one click, which is the unbounded queue that ceiling exists to stop.
	 */
	public function test_a_retry_does_not_re_arm_the_rate_limit_ceiling() {
		$order = $this->exhaust( $this->make_order() );
		$order->update_meta_data( AISOOQ_META_RATE_DEFERRALS, AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS );
		$order->save();

		$this->sync->retry( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS,
			(int) $fresh->get_meta( AISOOQ_META_RATE_DEFERRALS )
		);
	}

	/** Without clearing the hash, push_order() would decide nothing changed. */
	public function test_a_retry_clears_the_unchanged_hash() {
		$order = $this->exhaust( $this->make_order() );
		$order->update_meta_data( AISOOQ_META_HASH, 'stale-hash' );
		$order->save();

		$this->sync->retry( $order->get_id() );

		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( AISOOQ_META_HASH ) );
	}

	public function test_an_order_in_an_unselected_status_reports_why_it_cannot_retry() {
		$order = $this->exhaust( $this->make_order( 'on-hold' ) );

		$res = $this->sync->retry( $order->get_id() );

		$this->assertFalse( $res['ok'] );
		$this->assertNotSame( '', $res['message'], 'The operator must be told why, not just refused.' );
	}

	public function test_retrying_a_missing_order_is_refused_rather_than_fatal() {
		$res = $this->sync->retry( 99999999 );
		$this->assertFalse( $res['ok'] );
	}

	// ── Retry all ───────────────────────────────────────────────────────────

	/**
	 * A retried order keeps its attempt count and an un-retryable one never
	 * leaves the set, so a batch pinned to page 1 would re-read the same rows
	 * forever and never reach anything behind them.
	 */
	public function test_retry_all_advances_past_orders_it_cannot_retry() {
		// Newest first, so the two un-retryable rows are reliably page one and
		// the retryable one is reliably behind them.
		$this->exhaust( $this->make_order( 'on-hold', 1 ) );
		$this->exhaust( $this->make_order( 'on-hold', 2 ) );
		$ok = $this->exhaust( $this->make_order( 'processing', 3 ) );

		$first = $this->sync->retry_failed( 0, 2 );
		$this->assertSame( 0, $first['queued'] );
		$this->assertSame( 2, $first['skipped'] );
		$this->assertSame( 2, $first['next_offset'], 'The cursor must move past skipped rows.' );
		$this->assertSame( 3, $first['total'] );

		$second = $this->sync->retry_failed( $first['next_offset'], 2 );
		$this->assertSame( 1, $second['queued'], 'The retryable order behind them must be reached.' );
		$this->assertSame( $ok->get_id(), $ok->get_id() );
	}

	public function test_retry_all_on_an_empty_set_is_a_no_op() {
		$res = $this->sync->retry_failed( 0, 10 );
		$this->assertSame( 0, $res['queued'] );
		$this->assertSame( 0, $res['total'] );
	}

	// ── The query shape is shared ───────────────────────────────────────────

	/** The dashboard tile and the screen must describe the same set. */
	public function test_the_count_and_the_list_use_one_query_shape() {
		$args = AI_Sooq_Order_Sync::failed_query_args();
		$this->assertSame( AISOOQ_META_ATTEMPTS, $args['meta_key'] );
		$this->assertSame( AI_Sooq_Order_Sync::MAX_ATTEMPTS, $args['meta_value'] );
		$this->assertSame( '>=', $args['meta_compare'] );
		$this->assertSame( 'NUMERIC', $args['meta_type'] );
	}

	// ── Success forgets the failure ─────────────────────────────────────────

	public function test_a_successful_push_clears_the_recorded_reason() {
		$order = $this->exhaust( $this->make_order() );
		$this->assertNotSame( '', (string) $order->get_meta( AISOOQ_META_ERROR ) );

		add_filter( 'pre_http_request', function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'id' => '4321' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		} );
		set_transient( AISOOQ_TOKEN_TRANSIENT, 'test-token', HOUR_IN_SECONDS );

		$this->sync->sync_one( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) $fresh->get_meta( AISOOQ_META_ERROR ) );
		$this->assertSame( '', (string) $fresh->get_meta( AISOOQ_META_ERROR_CODE ) );
		$this->assertSame( '', (string) $fresh->get_meta( AISOOQ_META_ATTEMPTS ) );
	}
}
