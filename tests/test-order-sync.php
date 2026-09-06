<?php
/**
 * The order push pipeline — which orders are eligible, and what happens to one
 * the platform refuses to accept right now.
 *
 * This class had no tests at all, which is how the status filter came to mean
 * the opposite of what the settings screen says, and how the rate-limit
 * deferral came to have no ceiling.
 *
 * @package AISooq
 */

class Test_Order_Sync extends WP_UnitTestCase {

	/** @var AI_Sooq_Order_Sync */
	private $sync;

	/** @var AI_Sooq_Settings */
	private $settings;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		$this->settings = $this->settings_with( array() );
		$logger         = new AI_Sooq_Logger( $this->settings );
		$this->sync     = new AI_Sooq_Order_Sync( $this->settings, new AI_Sooq_Api_Client( $this->settings, $logger ), $logger );
	}

	/** A settings object reading a freshly written option. */
	private function settings_with( array $overrides ) {
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
		return new AI_Sooq_Settings();
	}

	private function make_order( $status = 'processing' ) {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '1000' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		// WP_UnitTestCase rolls the DB back between tests, so order ids repeat —
		// but Action Scheduler's own rows survive. Clear anything queued for
		// this id, or a previous test's action reads as this one's.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 0, 1 ) as $backfill ) {
				as_unschedule_all_actions( AISOOQ_SYNC_ACTION, array( $order->get_id(), $backfill ), AISOOQ_AS_GROUP );
			}
		}
		return wc_get_order( $order->get_id() );
	}

	private function is_queued( $order_id ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available.' );
		}
		return as_has_scheduled_action( AISOOQ_SYNC_ACTION, array( $order_id, 0 ), AISOOQ_AS_GROUP );
	}

	// ── Which orders are eligible ───────────────────────────────────────────

	public function test_a_selected_status_is_queued() {
		$order = $this->make_order( 'processing' );
		$this->sync->enqueue( $order->get_id() );
		$this->assertTrue( $this->is_queued( $order->get_id() ) );
	}

	public function test_an_unselected_status_is_not_queued() {
		$order = $this->make_order( 'on-hold' );
		$this->sync->enqueue( $order->get_id() );
		$this->assertFalse( $this->is_queued( $order->get_id() ) );
	}

	/**
	 * Unticking every status on the settings screen means "push nothing".
	 *
	 * It used to mean the exact opposite: an empty list was read as "no filter"
	 * and every order was pushed — including Store API `checkout-draft` rows,
	 * which are abandoned carts, not orders. The default is the full
	 * seven-status list, so an empty list is only ever reached on purpose.
	 */
	public function test_no_statuses_selected_pushes_nothing() {
		$settings = $this->settings_with( array( 'order_statuses' => array() ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Order_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$order = $this->make_order( 'processing' );
		$sync->enqueue( $order->get_id() );

		$this->assertFalse( $this->is_queued( $order->get_id() ) );
	}

	public function test_backfill_queues_nothing_when_no_statuses_selected() {
		$settings = $this->settings_with( array( 'order_statuses' => array() ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Order_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$this->make_order( 'processing' );

		$this->assertSame( 0, $sync->backfill( 10 ) );
	}

	// ── What a rate limit costs the order ───────────────────────────────────

	/** Invoke the private handle_failure(). */
	private function run_failure( WC_Order $order, WP_Error $err ) {
		$m = new ReflectionMethod( 'AI_Sooq_Order_Sync', 'handle_failure' );
		$m->setAccessible( true );
		$m->invoke( $this->sync, $order, $err, false );
	}

	private function rate_limit_error( $after = 60 ) {
		return new WP_Error(
			'aisooq_rate_limited',
			'Too many requests',
			array( 'status' => 429, 'retry_after' => $after )
		);
	}

	/**
	 * A 429 is not the order's fault, so it must not spend an attempt — that is
	 * what let a busy hour permanently abandon orders that were never broken.
	 */
	public function test_a_rate_limit_does_not_spend_an_attempt() {
		$order = $this->make_order();
		$this->run_failure( $order, $this->rate_limit_error() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) $fresh->get_meta( AISOOQ_META_ATTEMPTS ) );
		$this->assertSame( 1, (int) $fresh->get_meta( AISOOQ_META_RATE_DEFERRALS ) );
	}

	/**
	 * …but it cannot be free forever. A platform that throttles this store
	 * indefinitely would otherwise re-queue every order for eternity: the queue
	 * never drains and nothing in the admin ever says why.
	 */
	public function test_rate_limit_deferrals_are_bounded() {
		$order = $this->make_order();
		$order->update_meta_data( AISOOQ_META_RATE_DEFERRALS, AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS );
		$order->save();

		$this->run_failure( wc_get_order( $order->get_id() ), $this->rate_limit_error() );

		// Past the ceiling it falls through to the ordinary failure path, so the
		// order starts spending attempts and can eventually surface as failed.
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 1, (int) $fresh->get_meta( AISOOQ_META_ATTEMPTS ) );
	}

	/** An ordinary failure still spends an attempt. */
	public function test_a_real_failure_spends_an_attempt() {
		$order = $this->make_order();
		$this->run_failure( $order, new WP_Error( 'aisooq_http_500', 'Server error', array( 'status' => 500 ) ) );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 1, (int) $fresh->get_meta( AISOOQ_META_ATTEMPTS ) );
		$this->assertSame( '', (string) $fresh->get_meta( AISOOQ_META_RATE_DEFERRALS ) );
	}
}
