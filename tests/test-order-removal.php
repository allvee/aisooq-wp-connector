<?php
/**
 * Removing an order in WooCommerce has to reach the platform.
 *
 * It never did. A test, spam or duplicate order trashed or deleted here stayed
 * a live order there — in revenue, in the customer's history, and in the
 * courier-ratio fallback that counts this store's own settled deliveries. The
 * ingest contract already cancels a platform order when `wcStatus` is
 * `cancelled`, so removal is expressed as exactly that.
 *
 * @package AISooq
 */

class Test_Order_Removal extends WP_UnitTestCase {

	/** @var AI_Sooq_Order_Sync */
	private $sync;

	/** @var array[] Every POST to /connect/orders, decoded. */
	private $pushed = array();

	/** @var int HTTP status the stub answers with. */
	private $status = 200;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		update_option(
			AISOOQ_OPTION,
			array(
				'active'         => 1,
				'api_base'       => 'https://api.example.test',
				'sid'            => 'store1',
				'client_id'      => 'cid',
				'client_secret'  => 'csecret',
				'enable_orders'  => 1,
				'order_statuses' => array( 'processing', 'completed' ),
			)
		);
		set_transient( AISOOQ_TOKEN_TRANSIENT, 'test-token', HOUR_IN_SECONDS );

		$settings   = new AI_Sooq_Settings();
		$logger     = new AI_Sooq_Logger( $settings );
		$this->sync = new AI_Sooq_Order_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );
		$this->sync->register();
		$this->detach_other_sync_instances();

		// WP_UnitTestCase rolls the DB back, so order ids REPEAT between tests, but a
		// static property does not reset. Without this an earlier test that deleted
		// order N leaves N marked as already-cancelled, and the next test to create
		// an order N sees its delete silently skipped. In production ids never repeat.
		$guard = new ReflectionProperty( 'AI_Sooq_Order_Sync', 'cancelled_on_delete' );
		$guard->setAccessible( true );
		$guard->setValue( null, array() );

		$this->pushed = array();
		$this->status = 200;
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	/**
	 * The plugin boots its OWN order-sync instance at load, before this test has
	 * written any settings, so that instance has no store configured. Left
	 * attached it claims the delete guard first and then fails to send, which
	 * starves the configured instance below and reads as "nothing was sent".
	 * Production has exactly one instance, configured at boot; the test must too.
	 * WP_UnitTestCase restores every hook in tear_down, so nothing leaks.
	 */
	private function detach_other_sync_instances() {
		global $wp_filter;
		foreach ( array( 'woocommerce_trash_order', 'trashed_post', 'woocommerce_untrash_order', 'untrashed_post', 'woocommerce_before_delete_order', 'before_delete_post', 'woocommerce_order_status_changed', 'woocommerce_new_order' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof AI_Sooq_Order_Sync && $cb['function'][0] !== $this->sync ) {
						remove_action( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $pre, $args, $url ) {
		if ( false === strpos( $url, '/connect/orders' ) ) {
			return $pre;
		}
		$this->pushed[] = json_decode( (string) $args['body'], true );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( 200 === $this->status ? array( 'id' => 9001 ) : array( 'message' => 'boom' ) ),
			'response' => array( 'code' => $this->status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @param bool $synced Mark it as already accepted by the platform.
	 */
	private function make_order( $synced ) {
		$order = new WC_Order();
		$order->set_payment_method( 'cod' );
		$order->set_status( 'processing' );
		if ( $synced ) {
			$order->update_meta_data( AISOOQ_META_HASH, 'previously-accepted' );
			$order->update_meta_data( AISOOQ_META_ID, '9001' );
		}
		$order->save();
		$this->clear_queue( $order->get_id() );
		return wc_get_order( $order->get_id() );
	}

	private function clear_queue( $order_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 0, 1 ) as $backfill ) {
				as_unschedule_all_actions( AISOOQ_SYNC_ACTION, array( $order_id, $backfill ), AISOOQ_AS_GROUP );
			}
		}
	}

	private function is_queued( $order_id ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available.' );
		}
		return (bool) as_has_scheduled_action( AISOOQ_SYNC_ACTION, array( $order_id, 0 ), AISOOQ_AS_GROUP );
	}

	// ── Trash ───────────────────────────────────────────────────────────────

	/** The mapping itself: `trash` is not a status the platform knows. */
	public function test_a_trashed_order_maps_to_cancelled() {
		$order = $this->make_order( true );
		$id    = $order->get_id();
		$order->delete( false );

		$payload = AI_Sooq_Order_Mapper::map( wc_get_order( $id ), false );
		$this->assertSame( 'cancelled', $payload['wcStatus'] );
	}

	public function test_trashing_a_synced_order_queues_its_cancellation() {
		$order = $this->make_order( true );
		$id    = $order->get_id();
		$order->delete( false );

		$this->assertTrue(
			$this->is_queued( $id ),
			'Trash is not an operator-selectable status, so without an explicit branch the status gate filtered it out and the cancellation never left the store.'
		);
	}

	/**
	 * Pushing a never-synced order because it was trashed would CREATE it on the
	 * platform only to cancel it — a phantom order that did not exist there
	 * until the operator deleted it.
	 */
	public function test_trashing_an_order_the_platform_never_had_sends_nothing() {
		$order = $this->make_order( false );
		$id    = $order->get_id();
		$order->delete( false );

		$this->assertFalse( $this->is_queued( $id ) );
		$this->assertSame( array(), $this->pushed );
	}

	// ── Restore ─────────────────────────────────────────────────────────────

	public function test_restoring_a_trashed_order_queues_its_real_status() {
		$order = $this->make_order( true );
		$id    = $order->get_id();
		$order->delete( false );
		$this->clear_queue( $id );

		$trashed = wc_get_order( $id );
		if ( method_exists( $trashed, 'untrash' ) ) {
			$trashed->untrash();
		} else {
			wp_untrash_post( $id );
		}

		$restored = wc_get_order( $id );
		$this->assertNotSame( 'trash', $restored->get_status(), 'precondition: the order came back' );
		$this->assertTrue( $this->is_queued( $id ), 'A restored order must push again, or the platform keeps it cancelled with nothing saying why.' );
		$this->assertNotSame( 'cancelled', AI_Sooq_Order_Mapper::map( $restored, false )['wcStatus'] );
	}

	// ── Permanent delete ────────────────────────────────────────────────────

	/**
	 * A force-delete cannot be queued: by the time a background job ran there
	 * would be no order left to build a payload from. It has to go now.
	 */
	public function test_force_deleting_a_synced_order_cancels_it_immediately() {
		$order = $this->make_order( true );
		$id    = $order->get_id();

		$order->delete( true );

		$this->assertCount( 1, $this->pushed, 'Exactly one synchronous cancel, even though both the WooCommerce and the WordPress delete hooks fire.' );
		$this->assertSame( 'cancelled', $this->pushed[0]['wcStatus'] );
		$this->assertSame( (string) $id, (string) $this->pushed[0]['externalId'] );
		$this->assertFalse( wc_get_order( $id ), 'precondition: the order is really gone' );
	}

	/** Trash already cancelled it; emptying the trash must not send it twice. */
	public function test_deleting_an_already_trashed_order_sends_nothing_more() {
		$order = $this->make_order( true );
		$id    = $order->get_id();
		$order->delete( false );
		$this->pushed = array();

		wc_get_order( $id )->delete( true );

		$this->assertSame( array(), $this->pushed );
	}

	public function test_force_deleting_an_order_the_platform_never_had_sends_nothing() {
		$order = $this->make_order( false );
		$order->delete( true );

		$this->assertSame( array(), $this->pushed );
	}

	/**
	 * The platform refusing the cancel must not stop the operator deleting the
	 * order — a connector can never block a store's own admin action.
	 */
	public function test_a_refused_cancel_does_not_stop_the_delete() {
		$this->status = 500;
		$order        = $this->make_order( true );
		$id           = $order->get_id();

		$order->delete( true );

		$this->assertCount( 1, $this->pushed, 'the cancel was attempted' );
		$this->assertFalse( wc_get_order( $id ), 'and the order was still deleted' );
	}

	/** A paused connection sends nothing at all, including cancellations. */
	public function test_a_paused_connection_does_not_cancel_on_delete() {
		$settings           = get_option( AISOOQ_OPTION );
		$settings['active'] = 0;
		update_option( AISOOQ_OPTION, $settings );

		$order = $this->make_order( true );
		$order->delete( true );

		$this->assertSame( array(), $this->pushed );
	}
}
