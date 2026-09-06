<?php
/**
 * Order push: WooCommerce order hooks → Action Scheduler job → POST
 * /connect/orders. Idempotent (payload hash skip + platform-side dedupe on
 * externalId) with exponential backoff retry.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Order_Sync {

	const MAX_ATTEMPTS = 5;

	/**
	 * How many times one order may be deferred for a rate limit before it is
	 * treated as a real failure. At the platform's 60s window this is roughly
	 * two hours of patience — long enough to ride out any normal burst, short
	 * enough that a permanently throttled store surfaces instead of silently
	 * re-queueing forever.
	 */
	const MAX_RATE_DEFERRALS = 100;

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Api_Client */
	private $api;
	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Api_Client $api, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;
	}

	public function register() {
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		// A refund does not necessarily change the order status — a partial
		// refund leaves it `processing` — so without this the mapped
		// `refundedAmount` never reached the platform and the two systems
		// disagreed about money for the rest of the order's life.
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );
		// An admin editing an order (corrected address, changed line items) is
		// likewise a silent change today.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'on_admin_save' ), 90, 1 );
		add_action( AISOOQ_SYNC_ACTION, array( $this, 'handle_job' ), 10, 2 );
	}

	/**
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_refunded( $order_id, $refund_id ) {
		$this->enqueue( (int) $order_id );
	}

	/** @param int $order_id */
	public function on_admin_save( $order_id ) {
		$this->enqueue( (int) $order_id );
	}

	public function on_new_order( $order_id ) {
		$this->enqueue( (int) $order_id );
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->enqueue( (int) $order_id );
	}

	/**
	 * Queue (or, without Action Scheduler, run) a push for an order.
	 */
	public function enqueue( $order_id, $is_backfill = false ) {
		try {
			if ( ! $this->settings->get( 'enable_orders' ) ) {
				return;
			}
			// Don't echo back a change the platform just wrote to us.
			if ( AI_Sooq_Status_Poller::is_writing_back() ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			// An empty list means the operator unticked every status, i.e. "push
			// nothing". Treating empty as "no filter" inverted that into "push
			// EVERYTHING" — including Store API `checkout-draft` carts and any
			// custom status a third-party plugin registers. The default is the
			// full seven-status list, so empty is only ever reached deliberately.
			$allowed = (array) $this->settings->get( 'order_statuses' );
			if ( ! in_array( $order->get_status(), $allowed, true ) ) {
				return;
			}

			$args = array( $order_id, $is_backfill ? 1 : 0 );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				if ( function_exists( 'as_has_scheduled_action' )
					&& as_has_scheduled_action( AISOOQ_SYNC_ACTION, $args, AISOOQ_AS_GROUP ) ) {
					return; // already queued
				}
				as_enqueue_async_action( AISOOQ_SYNC_ACTION, $args, AISOOQ_AS_GROUP );
			} else {
				$this->push_order( $order_id, (bool) $is_backfill );
			}
		} catch ( \Throwable $e ) {
			// `woocommerce_new_order` fires during order creation — a scheduling
			// error here must never break the order the shopper just placed.
			$this->logger->error( 'Order sync enqueue error (order kept): ' . $e->getMessage() );
		}
	}

	/** Action Scheduler callback. */
	public function handle_job( $order_id, $is_backfill = 0 ) {
		$this->push_order( (int) $order_id, (bool) $is_backfill );
	}

	/**
	 * Manual backfill: enqueue the most recent orders (in the configured status
	 * set) for a push. Used by the "Sync now" button. Returns how many were
	 * queued.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill( $limit = 100 ) {
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return 0;
		}
		$args = array(
			'limit'   => max( 1, (int) $limit ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);
		// Same rule as enqueue(): no statuses selected means nothing is pushed,
		// so a backfill must not quietly fall back to every status.
		$statuses = (array) $this->settings->get( 'order_statuses' );
		if ( empty( $statuses ) ) {
			return 0;
		}
		$args['status'] = $statuses;
		$ids = wc_get_orders( $args );
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			$this->enqueue( (int) $id, true ); // backfill: mirror WooCommerce status as-is
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' orders.' );
		return $n;
	}

	/**
	 * Build + send the order. Skips when the payload is byte-identical to the
	 * last successful push (status polls / meta saves won't re-send).
	 */
	/**
	 * Force-sync ONE order to the platform now (the per-order Sync button in the
	 * orders list). Unlike push_order() this ignores the enable_orders toggle and
	 * the unchanged-hash skip — the operator explicitly asked for this order —
	 * and returns a result the caller can render. Stamps the sync meta on
	 * success so the column flips to "Synced".
	 *
	 * @param int $order_id
	 * @return array{ok:bool,id?:string,message:string}
	 */
	public function sync_one( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Order not found.', 'aisooq-connector' ) );
		}
		$payload = AI_Sooq_Order_Mapper::map( $order, false );
		$res     = $this->api->post( '/connect/orders', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Order ' . $order_id . ' manual sync failed: ' . $res->get_error_message() );
			$message = $res->get_error_message();
			// The shared rate-limit message ends "the sync will retry
			// automatically", which is true of the queued push but NOT here:
			// this is the per-order Sync button and nothing is scheduled. Tell
			// the operator what actually happens, which is that they retry.
			if ( 'aisooq_rate_limited' === $res->get_error_code() ) {
				$data    = $res->get_error_data();
				$seconds = ( is_array( $data ) && ! empty( $data['retry_after'] ) ) ? (int) $data['retry_after'] : 60;
				$message = sprintf(
					/* translators: %d: seconds to wait before trying again. */
					__( 'The platform is rate limiting this store. Try again in about %d seconds.', 'aisooq-connector' ),
					$seconds
				);
			}
			return array( 'ok' => false, 'message' => $message );
		}
		$order->update_meta_data( AISOOQ_META_HASH, md5( (string) wp_json_encode( $payload ) ) );
		if ( ! empty( $res['id'] ) ) {
			$order->update_meta_data( AISOOQ_META_ID, (string) $res['id'] );
		}
		$order->update_meta_data( AISOOQ_META_SYNCED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( AISOOQ_META_ATTEMPTS );
		$order->delete_meta_data( AISOOQ_META_RATE_DEFERRALS );
		$order->save();
		return array(
			'ok'      => true,
			'id'      => isset( $res['id'] ) ? (string) $res['id'] : '',
			'message' => __( 'Synced.', 'aisooq-connector' ),
		);
	}

	public function push_order( $order_id, $is_backfill = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return;
		}

		$payload = AI_Sooq_Order_Mapper::map( $order, (bool) $is_backfill );
		$hash    = md5( (string) wp_json_encode( $payload ) );
		if ( $order->get_meta( AISOOQ_META_HASH ) === $hash ) {
			$this->logger->debug( 'Order ' . $order_id . ' unchanged since last sync — skipping.' );
			return;
		}

		$res = $this->api->post( '/connect/orders', $payload );
		if ( is_wp_error( $res ) ) {
			$this->handle_failure( $order, $res, (bool) $is_backfill );
			return;
		}

		$order->update_meta_data( AISOOQ_META_HASH, $hash );
		if ( ! empty( $res['id'] ) ) {
			$order->update_meta_data( AISOOQ_META_ID, (string) $res['id'] );
		}
		$order->update_meta_data( AISOOQ_META_SYNCED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( AISOOQ_META_ATTEMPTS );
		$order->delete_meta_data( AISOOQ_META_RATE_DEFERRALS );
		$order->save();

		// Close the in-flight window.
		//
		// enqueue() skips scheduling when as_has_scheduled_action() says one
		// exists — but that also matches an action that is RUNNING RIGHT NOW.
		// So a status change that lands while this push is in the air was
		// dropped: no new job was queued, and this job had already built its
		// payload from the older state. Re-read the order and, if it no longer
		// matches what we just sent, queue one more pass. Bounded by
		// construction — the second pass only re-queues if the order changed
		// again during it.
		$fresh = wc_get_order( $order_id );
		if ( $fresh && md5( (string) wp_json_encode( AI_Sooq_Order_Mapper::map( $fresh, (bool) $is_backfill ) ) ) !== $hash ) {
			$this->logger->debug( 'Order ' . $order_id . ' changed during its push — re-queuing.' );
			$this->enqueue( $order_id, $is_backfill );
		}

		$this->logger->debug(
			'Order ' . $order_id . ' synced (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) .
			', deduped=' . ( empty( $res['deduped'] ) ? '0' : '1' ) . ').'
		);
	}

	private function handle_failure( WC_Order $order, WP_Error $err, $is_backfill = false ) {
		// A rate limit is not a failure of this order.
		//
		// Nothing about the payload is wrong and the same push will succeed
		// once the window opens, so counting it against MAX_ATTEMPTS would let
		// a busy hour permanently abandon orders that were never broken. Wait
		// as long as the platform asked, and leave the attempt counter alone.
		//
		// It still needs its OWN ceiling. Deferring without any bound means a
		// platform that is misconfigured — or throttling this store
		// indefinitely — re-queues every order forever: the queue never drains,
		// Action Scheduler grows without limit, and nothing in the admin ever
		// says so. After MAX_RATE_DEFERRALS the order falls through to the
		// normal failure path, which spends an attempt and eventually surfaces
		// it as a genuinely failed order the operator can see and retry.
		$data = $err->get_error_data();
		if ( 'aisooq_rate_limited' === $err->get_error_code() && is_array( $data ) && ! empty( $data['retry_after'] ) ) {
			$deferrals = (int) $order->get_meta( AISOOQ_META_RATE_DEFERRALS ) + 1;

			if ( $deferrals <= self::MAX_RATE_DEFERRALS && function_exists( 'as_schedule_single_action' ) ) {
				$order->update_meta_data( AISOOQ_META_RATE_DEFERRALS, $deferrals );
				$order->save();

				// Jitter the wake-up. Every order throttled in the same window
				// gets the same Retry-After, so scheduling them all on the
				// exact same second re-creates the burst that caused the 429 —
				// the queue would thunder against the limiter indefinitely.
				$wait = (int) $data['retry_after'];
				$wait += wp_rand( 0, max( 1, (int) round( $wait * 0.2 ) ) );

				as_schedule_single_action(
					time() + $wait,
					AISOOQ_SYNC_ACTION,
					array( $order->get_id(), $is_backfill ? 1 : 0 ),
					AISOOQ_AS_GROUP
				);
				$this->logger->debug(
					'Order ' . $order->get_id() . ' rate limited; re-queued in ' . $wait . 's' .
					' (deferral ' . $deferrals . '/' . self::MAX_RATE_DEFERRALS . ').'
				);
				return;
			}

			// Either the store has no Action Scheduler — in which case nothing
			// re-queued it and claiming otherwise would hide a dropped order —
			// or the order has been deferred too many times. Both fall through
			// to the failure path below so the order is counted, logged as an
			// error, and eventually visible as failed.
			$this->logger->error(
				'Order ' . $order->get_id() . ' rate limited and no longer deferrable (' .
				( function_exists( 'as_schedule_single_action' )
					? 'exceeded ' . self::MAX_RATE_DEFERRALS . ' deferrals'
					: 'Action Scheduler unavailable' ) .
				'); counting it as a failed attempt.'
			);
		}

		$attempts = (int) $order->get_meta( AISOOQ_META_ATTEMPTS ) + 1;
		$order->update_meta_data( AISOOQ_META_ATTEMPTS, $attempts );
		$order->save();

		$this->logger->error(
			'Order ' . $order->get_id() . ' push failed (attempt ' . $attempts . '): ' . $err->get_error_message()
		);

		if ( $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			$delay = min( 3600, 60 * (int) pow( 2, $attempts ) ); // 2,4,8,16 min, capped 1h
			as_schedule_single_action(
				time() + $delay,
				AISOOQ_SYNC_ACTION,
				array( $order->get_id(), $is_backfill ? 1 : 0 ), // preserve backfill flag on retry
				AISOOQ_AS_GROUP
			);
		}
	}
}
