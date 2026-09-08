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
		self::forget_failure( $order );
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
		self::forget_failure( $order );
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
		// Keep the reason. A count with no reason tells an operator that
		// something is wrong and nothing about what, which is the state this
		// plugin was in: the dashboard could say "3 failed" and there was no
		// way, anywhere, to find out why.
		$order->update_meta_data( AISOOQ_META_ERROR, self::clamp_error( $err->get_error_message() ) );
		$order->update_meta_data( AISOOQ_META_ERROR_CODE, (string) $err->get_error_code() );
		$order->update_meta_data( AISOOQ_META_LAST_TRY, current_time( 'mysql' ) );
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

	// ── The dead letter office ──────────────────────────────────────────────

	/** Error text is for a human reading one table cell, not a log. */
	private static function clamp_error( $message ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		// Characters, not bytes: a platform error can come back in Bangla.
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 250, 'UTF-8' ) : substr( $message, 0, 250 );
	}

	/** Forget why an order failed, because it just succeeded. */
	private static function forget_failure( WC_Order $order ) {
		$order->delete_meta_data( AISOOQ_META_ERROR );
		$order->delete_meta_data( AISOOQ_META_ERROR_CODE );
		$order->delete_meta_data( AISOOQ_META_LAST_TRY );
	}

	/**
	 * The one query shape for "orders that gave up", so the count on the
	 * dashboard and the rows on the screen can never describe different sets.
	 *
	 * HPOS-safe: wc_get_orders() maps meta_query onto whichever store is
	 * active, and `offset` is supported by both.
	 *
	 * $filters narrows that same set without reshaping it — `code` (one
	 * AISOOQ_META_ERROR_CODE, or CAUSE_NONE for the orders that stopped before
	 * codes were recorded) and `search` (order number / customer name / phone /
	 * e-mail). The meta_key/meta_value pair below is what MAKES this the
	 * given-up set, so it is always here: a filter can only ever narrow.
	 *
	 * @param array $extra   limit / offset / return / paginate.
	 * @param array $filters code / search.
	 * @return array
	 */
	public static function failed_query_args( array $extra = array(), array $filters = array() ) {
		$args = array_merge(
			array(
				'limit'        => 25,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_key'     => AISOOQ_META_ATTEMPTS, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => self::MAX_ATTEMPTS,   // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => '>=',
				'meta_type'    => 'NUMERIC',
			),
			$extra
		);

		$ids = self::failed_filter_ids( $filters );
		if ( null === $ids ) {
			return $args;
		}

		// `post__in`, not `include`. The legacy store hands arguments it does
		// not recognise straight to WP_Query, which has no `include` — the
		// filter would have vanished and the screen would have shown the whole
		// pile under a heading saying otherwise — while HPOS remaps `post__in`
		// onto its id column. And "nothing matched" has to be spelled as an id
		// that cannot exist, because both stores read an empty array as "no
		// constraint" and would again list everything.
		$args['post__in'] = $ids ? $ids : array( 0 );
		return $args;
	}

	/**
	 * How many orders have given up.
	 *
	 * Cached, and by default it will NOT run the query to fill an empty cache —
	 * this number is wanted by an admin notice that would otherwise put a
	 * COUNT over the whole order-meta table (unindexable on a numeric value)
	 * on the dashboard and the orders list of the largest stores. Only the
	 * screens that exist to show it ask for a fresh one.
	 *
	 * @param bool $fresh Recompute rather than answer 0 on a cold cache.
	 * @return int
	 */
	public static function failed_count( $fresh = false ) {
		$cached = get_transient( 'aisooq_failed_count' );
		if ( false !== $cached && ! $fresh ) {
			return (int) $cached;
		}
		if ( ! $fresh && false === $cached ) {
			return 0;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$q = wc_get_orders( self::failed_query_args( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) ) );
		$n = ( is_object( $q ) && isset( $q->total ) ) ? (int) $q->total : 0;
		set_transient( 'aisooq_failed_count', $n, HOUR_IN_SECONDS );
		return $n;
	}

	/**
	 * Drop the cached count — anything that changes the set calls this.
	 *
	 * The cause rollup describes the SAME set, so it is dropped here rather
	 * than behind a second call the retry paths, the poller and the admin
	 * screens would each have had to remember: missing one leaves the screen
	 * offering "5 × HTTP 500" to retry after those five have gone.
	 */
	public static function flush_failed_count() {
		delete_transient( 'aisooq_failed_count' );
		delete_transient( self::CAUSES_TRANSIENT );
	}

	// ── Reading the pile: causes, filters, selections ────────────────────────

	/**
	 * The cause rollup lives beside the count and dies with it — see
	 * flush_failed_count().
	 */
	const CAUSES_TRANSIENT = 'aisooq_failed_causes';

	/**
	 * The cause of an order that stopped before this plugin recorded codes.
	 *
	 * Those orders are real and will never fail again (nothing reschedules them
	 * past the ceiling), so leaving them out of the rollup would make the
	 * causes add up to less than the header and look like a bug. They get their
	 * own bucket instead, under a value no WP_Error code can collide with.
	 */
	const CAUSE_NONE = '_aisooq_no_code';

	/** Distinct causes worth rendering; a rollup is a shortlist, not a report. */
	const MAX_CAUSES = 50;

	/**
	 * Ceiling on the ids one filtered view resolves.
	 *
	 * A filter is answered as an explicit id set (see failed_filter_ids), which
	 * is what keeps the list and its count describing one set on both order
	 * stores — but an unbounded set would put every given-up order id into an
	 * IN() clause. The newest MAX_FILTER_MATCHES are kept, which is the end of
	 * the list the operator is looking at anyway.
	 */
	const MAX_FILTER_MATCHES = 2000;

	/** Orders one bulk retry call may touch before it hands the rest back. */
	const MAX_BULK_RETRY = 50;

	/**
	 * Seconds a bulk retry may spend when there is no Action Scheduler and each
	 * retry is therefore a blocking HTTP call. Past this the operator gets a
	 * dead spinner and a PHP timeout instead of a result.
	 */
	const INLINE_BUDGET_SECONDS = 10;

	/**
	 * Longest search term honoured. Past this it is not a search, it is a
	 * pasted paragraph, and every extra character is another LIKE comparison.
	 */
	const SEARCH_MAX_CHARS = 100;

	/** Width of a 'Y-m-d H:i:s' stamp — see query_causes() for why it matters. */
	const STAMP_WIDTH = 19;

	/** Sorts before every real stamp, and is exactly STAMP_WIDTH wide. */
	const STAMP_NEVER = '0000-00-00 00:00:00';

	/**
	 * Why the given-up orders gave up: one row per distinct error code, biggest
	 * first.
	 *
	 * This is what turns forty failures into three decisions — "30 Missing
	 * Store SID, 5 HTTP 500, 3 rate limited" are three different emergencies,
	 * and one of them is not an emergency at all.
	 *
	 * Cached and cold-cache-shy for the same reason as failed_count(): it runs
	 * on every view of a screen an operator may leave open, and it is a
	 * grouped read over the order-meta table. One query, no orders hydrated —
	 * counting in PHP would mean loading every failed order on every page view.
	 *
	 * @param bool $fresh Recompute rather than answer empty on a cold cache.
	 * @return array<int,array{code:string,label:string,count:int}> `label` is
	 *         the last error message stored for that code and may be '' — the
	 *         wording for that case belongs to the screen, not here.
	 */
	public static function failed_by_cause( $fresh = false ) {
		$cached = get_transient( self::CAUSES_TRANSIENT );
		if ( is_array( $cached ) && ! $fresh ) {
			return $cached;
		}
		if ( ! $fresh ) {
			return array();
		}
		$rollup = self::query_causes();
		set_transient( self::CAUSES_TRANSIENT, $rollup, HOUR_IN_SECONDS );
		return $rollup;
	}

	/**
	 * The rollup query itself.
	 *
	 * The label is the trick. "Newest message for this code" normally means
	 * either a window function (not available on the MySQL floor WordPress
	 * supports) or a second query per code, so instead the last-attempt stamp
	 * is glued in front of the message and MAX() picks the winner: 'Y-m-d
	 * H:i:s' is fixed width and sorts lexicographically the same way it sorts
	 * chronologically, so the prefix decides and PHP slices it back off. Orders
	 * with no message at all CONCAT to NULL and MAX() skips them, which is
	 * wanted — a code with one recorded message and nine blanks should show
	 * that message.
	 *
	 * @return array<int,array{code:string,label:string,count:int}>
	 */
	private static function query_causes() {
		global $wpdb;

		$scope = self::order_scope();
		if ( null === $scope ) {
			return array();
		}
		$t = self::order_tables();

		// SIGNED, not UNSIGNED, because that is what meta_type NUMERIC becomes
		// in the query behind failed_count(): MySQL casts '-1' to a colossal
		// unsigned number, so the two would have disagreed about a junk value
		// and the causes would have added up to more than the header.
		$sql = "SELECT COALESCE( code.meta_value, '' ) AS cause_code,
				COUNT( DISTINCT o.{$t['id']} ) AS orders,
				MAX( CONCAT( COALESCE( lt.meta_value, %s ), msg.meta_value ) ) AS newest
			FROM {$t['orders']} o
			JOIN {$t['meta']} att ON att.{$t['meta_id']} = o.{$t['id']} AND att.meta_key = %s
			LEFT JOIN {$t['meta']} code ON code.{$t['meta_id']} = o.{$t['id']} AND code.meta_key = %s
			LEFT JOIN {$t['meta']} msg ON msg.{$t['meta_id']} = o.{$t['id']} AND msg.meta_key = %s
			LEFT JOIN {$t['meta']} lt ON lt.{$t['meta_id']} = o.{$t['id']} AND lt.meta_key = %s
			WHERE CAST( att.meta_value AS SIGNED ) >= %d
				AND o.{$t['type']} IN ( " . self::placeholders( $scope['types'] ) . " )
				AND o.{$t['status']} IN ( " . self::placeholders( $scope['statuses'] ) . " )
			GROUP BY COALESCE( code.meta_value, '' )
			ORDER BY orders DESC, cause_code ASC
			LIMIT %d";

		$args = array_merge(
			array(
				self::STAMP_NEVER,
				AISOOQ_META_ATTEMPTS,
				AISOOQ_META_ERROR_CODE,
				AISOOQ_META_ERROR,
				AISOOQ_META_LAST_TRY,
				(int) self::MAX_ATTEMPTS,
			),
			$scope['types'],
			$scope['statuses'],
			array( (int) self::MAX_CAUSES )
		);

		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rollup = array();
		foreach ( (array) $rows as $row ) {
			$code = (string) $row['cause_code'];
			// Byte offset, deliberately: the prefix is ASCII of known width and
			// everything after it is the message untouched, so a Bangla error
			// comes back whole where mb_substr on a character count would not
			// line up with the stamp at all.
			$newest = (string) $row['newest'];
			$rollup[] = array(
				'code'  => '' === $code ? self::CAUSE_NONE : $code,
				'label' => strlen( $newest ) > self::STAMP_WIDTH ? substr( $newest, self::STAMP_WIDTH ) : '',
				'count' => (int) $row['orders'],
			);
		}
		return $rollup;
	}

	/**
	 * Order ids in the given-up set that match the filters, newest first.
	 *
	 * Answering a filter with ids rather than with more query args is not a
	 * detour, it is the only thing that works on both stores: the legacy store
	 * drops `meta_query` on the floor (with a _doing_it_wrong notice) so a
	 * second meta condition cannot be expressed there at all, and HPOS keeps
	 * customer names in its own address table where postmeta joins mean
	 * nothing. One prepared query per store, driven off the attempts meta so it
	 * only ever walks orders that already failed, and both the list and its
	 * count are then handed the same ids.
	 *
	 * Memoised per request because the screen asks twice — once for the count,
	 * once for the rows — and that would otherwise be the same query run twice
	 * per page view.
	 *
	 * @param array $filters code / search.
	 * @return int[]|null null means "no filters" — do not constrain at all,
	 *                    which is NOT the same as an empty match.
	 */
	public static function failed_filter_ids( array $filters ) {
		static $memo = array();

		$code   = isset( $filters['code'] ) ? trim( (string) $filters['code'] ) : '';
		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			$search = function_exists( 'mb_substr' )
				? mb_substr( $search, 0, self::SEARCH_MAX_CHARS, 'UTF-8' )
				: substr( $search, 0, self::SEARCH_MAX_CHARS );
		}
		if ( '' === $code && '' === $search ) {
			return null;
		}

		$key = md5( $code . "\n" . $search );
		if ( ! array_key_exists( $key, $memo ) ) {
			$memo[ $key ] = self::query_filter_ids( $code, $search );
		}
		return $memo[ $key ];
	}

	/**
	 * How many given-up orders match the filters.
	 *
	 * No query: resolving the filter already produced the exact id set the list
	 * pages through, so the header and the rows cannot disagree — the failure
	 * this whole module is built around. With no filters it defers to
	 * failed_count(), which is the same set counted by the same query shape.
	 *
	 * @param array $filters code / search.
	 * @return int
	 */
	public static function failed_count_matching( array $filters = array() ) {
		$ids = self::failed_filter_ids( $filters );
		return ( null === $ids ) ? self::failed_count( true ) : count( $ids );
	}

	/**
	 * The filter query, per order store.
	 *
	 * @param string $code   Error code, CAUSE_NONE, or '' for any.
	 * @param string $search Free text over order number / name / phone / e-mail.
	 * @return int[]
	 */
	private static function query_filter_ids( $code, $search ) {
		global $wpdb;

		$scope = self::order_scope();
		if ( null === $scope ) {
			return array();
		}
		$t = self::order_tables();

		$joins      = array( "JOIN {$t['meta']} att ON att.{$t['meta_id']} = o.{$t['id']} AND att.meta_key = %s" );
		$join_args  = array( AISOOQ_META_ATTEMPTS );
		$where      = array( 'CAST( att.meta_value AS SIGNED ) >= %d' );
		$where_args = array( (int) self::MAX_ATTEMPTS );

		$where[]    = "o.{$t['type']} IN ( " . self::placeholders( $scope['types'] ) . ' )';
		$where_args = array_merge( $where_args, $scope['types'] );
		$where[]    = "o.{$t['status']} IN ( " . self::placeholders( $scope['statuses'] ) . ' )';
		$where_args = array_merge( $where_args, $scope['statuses'] );

		if ( self::CAUSE_NONE === $code ) {
			$joins[]     = "LEFT JOIN {$t['meta']} code ON code.{$t['meta_id']} = o.{$t['id']} AND code.meta_key = %s";
			$join_args[] = AISOOQ_META_ERROR_CODE;
			$where[]     = "( code.meta_value IS NULL OR code.meta_value = '' )";
		} elseif ( '' !== $code ) {
			$joins[]     = "JOIN {$t['meta']} code ON code.{$t['meta_id']} = o.{$t['id']} AND code.meta_key = %s AND code.meta_value = %s";
			$join_args[] = AISOOQ_META_ERROR_CODE;
			$join_args[] = $code;
		}

		if ( '' !== $search ) {
			// esc_like first, then the wildcards, or a customer whose name
			// contains a % would match every order on the shop.
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$or    = array();
			$likes = array();

			if ( '' !== $t['addresses'] ) {
				$joins[]     = "LEFT JOIN {$t['addresses']} addr ON addr.order_id = o.{$t['id']} AND addr.address_type = %s";
				$join_args[] = 'billing';
				$or[]        = 'addr.first_name LIKE %s';
				$or[]        = 'addr.last_name LIKE %s';
				// A shopper searched for by full name matches neither column on
				// its own, which is how most people type a name.
				$or[]        = "CONCAT_WS( ' ', addr.first_name, addr.last_name ) LIKE %s";
				$or[]        = 'addr.phone LIKE %s';
				$or[]        = 'addr.email LIKE %s';
				// HPOS also keeps the billing e-mail on the order row, and an
				// order can exist with no address row at all.
				$or[]        = 'o.billing_email LIKE %s';
				$likes       = array( $like, $like, $like, $like, $like, $like );
			} else {
				foreach ( array( 'fn' => '_billing_first_name', 'ln' => '_billing_last_name', 'ph' => '_billing_phone', 'em' => '_billing_email' ) as $alias => $meta_key ) {
					$joins[]     = "LEFT JOIN {$t['meta']} {$alias} ON {$alias}.{$t['meta_id']} = o.{$t['id']} AND {$alias}.meta_key = %s";
					$join_args[] = $meta_key;
				}
				$or[]  = 'fn.meta_value LIKE %s';
				$or[]  = 'ln.meta_value LIKE %s';
				$or[]  = "CONCAT_WS( ' ', fn.meta_value, ln.meta_value ) LIKE %s";
				$or[]  = 'ph.meta_value LIKE %s';
				$or[]  = 'em.meta_value LIKE %s';
				$likes = array( $like, $like, $like, $like, $like );
			}

			$where_args = array_merge( $where_args, $likes );

			// Order numbers are what an operator actually copies out of a
			// support message, and on a default store that number IS the id.
			if ( ctype_digit( $search ) ) {
				$or[]         = "o.{$t['id']} = %d";
				$where_args[] = (int) $search;
			}
			$where[] = '( ' . implode( ' OR ', $or ) . ' )';
		}

		// The date is selected, not just sorted on: under DISTINCT a column that
		// is only in the ORDER BY is an error on MySQL, and sorting on the alias
		// keeps the two spellings from drifting apart.
		$sql = "SELECT DISTINCT o.{$t['id']} AS order_id, o.{$t['date']} AS ordered_at
			FROM {$t['orders']} o
			" . implode( "\n\t\t\t", $joins ) . '
			WHERE ' . implode( ' AND ', $where ) . "
			ORDER BY ordered_at DESC
			LIMIT %d";

		$args = array_merge( $join_args, $where_args, array( (int) self::MAX_FILTER_MATCHES ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$ids[] = (int) $row->order_id;
		}
		return $ids;
	}

	/**
	 * Which statuses and order types wc_get_orders() would have matched.
	 *
	 * The raw queries above have to spell this out or they describe a different
	 * set from the list: a trashed order still carries the attempt meta, and
	 * counting it would leave the causes adding up to more than the header
	 * while the row it refers to is nowhere on the screen. These are the same
	 * two defaults WC_Order_Query applies.
	 *
	 * @return array{statuses:string[],types:string[]}|null null when
	 *         WooCommerce is not loaded and there is nothing to ask.
	 */
	private static function order_scope() {
		if ( ! function_exists( 'wc_get_order_statuses' ) || ! function_exists( 'wc_get_order_types' ) ) {
			return null;
		}
		$statuses = array_values( array_keys( (array) wc_get_order_statuses() ) );
		$types    = array_values( (array) wc_get_order_types( 'view-orders' ) );
		if ( ! $statuses || ! $types ) {
			return null;
		}
		return array( 'statuses' => $statuses, 'types' => $types );
	}

	/**
	 * Table and column names for wherever this store keeps its orders.
	 *
	 * `addresses` is empty on the legacy store, and doubles as the answer to
	 * "which shape is this?" — the customer's name is a row in a table on HPOS
	 * and four postmeta rows without it.
	 *
	 * @return array<string,string>
	 */
	private static function order_tables() {
		global $wpdb;

		if ( self::hpos_enabled() ) {
			return array(
				'orders'    => $wpdb->prefix . 'wc_orders',
				'meta'      => $wpdb->prefix . 'wc_orders_meta',
				'addresses' => $wpdb->prefix . 'wc_order_addresses',
				'id'        => 'id',
				'meta_id'   => 'order_id',
				'status'    => 'status',
				'type'      => 'type',
				'date'      => 'date_created_gmt',
			);
		}
		return array(
			'orders'    => $wpdb->posts,
			'meta'      => $wpdb->postmeta,
			'addresses' => '',
			'id'        => 'ID',
			'meta_id'   => 'post_id',
			'status'    => 'post_status',
			'type'      => 'post_type',
			'date'      => 'post_date_gmt',
		);
	}

	/**
	 * Are orders in WooCommerce's own tables?
	 *
	 * AI_Sooq_Order_Courier owns this question so the plugin cannot hold two
	 * answers. The fallback is not decoration: guessing "legacy" on an HPOS
	 * store would query wp_postmeta, find nothing, and quietly report "no
	 * causes" beside a screen listing forty failures — a wrong answer that
	 * looks like a working feature.
	 */
	private static function hpos_enabled() {
		if ( class_exists( 'AI_Sooq_Order_Courier' ) ) {
			return AI_Sooq_Order_Courier::hpos_enabled();
		}
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** Placeholder list for an IN() clause — the values are always prepared. */
	private static function placeholders( array $values, $format = '%s' ) {
		return implode( ', ', array_fill( 0, count( $values ), $format ) );
	}

	/**
	 * Why this order cannot be retried right now, or '' if it can.
	 *
	 * @param WC_Abstract_Order $order
	 * @return string
	 */
	public function retry_blocker( $order ) {
		if ( ! $this->settings->get( 'enable_orders' ) ) {
			return __( 'Order sync is switched off in settings.', 'aisooq-connector' );
		}
		$allowed = (array) $this->settings->get( 'order_statuses' );
		if ( ! in_array( $order->get_status(), $allowed, true ) ) {
			return __( 'This order\'s status is not in the list you chose to push.', 'aisooq-connector' );
		}
		return '';
	}

	/**
	 * Push one given-up order again, at the operator's request.
	 *
	 * Deliberately does NOT reset the attempt counter or the rate-limit
	 * deferrals.
	 *
	 * Resetting attempts would remove the order from this very screen before
	 * anything had actually been pushed — and if the queued job then never ran
	 * (cron disabled, a wedged Action Scheduler, the connection paused a minute
	 * later) the order would be gone from the list, gone from the count, and
	 * nothing would ever push it. That is worse than leaving it where it is.
	 *
	 * Resetting the deferral counter would be worse still in bulk: it re-arms
	 * MAX_RATE_DEFERRALS per order, so one "retry all" on a throttled platform
	 * could authorise tens of thousands of scheduled actions — the exact
	 * unbounded queue that ceiling exists to prevent.
	 *
	 * Clearing the hash is the one thing needed: without it push_order() would
	 * decide the payload is unchanged and skip.
	 *
	 * @param int $order_id
	 * @return array{ok:bool,message:string,queued:bool}
	 */
	public function retry( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return array( 'ok' => false, 'message' => __( 'Order not found.', 'aisooq-connector' ), 'queued' => false );
		}
		$blocked = $this->retry_blocker( $order );
		if ( '' !== $blocked ) {
			return array( 'ok' => false, 'message' => $blocked, 'queued' => false );
		}

		$order->delete_meta_data( AISOOQ_META_HASH );
		$order->save();

		// Without Action Scheduler, enqueue() pushes inline — so reporting
		// "queued" would tell the operator a retry is pending when it has
		// already happened and may already have failed again. Use the force
		// path, which returns what actually happened.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$res = $this->sync_one( $order_id );
			self::flush_failed_count();
			return array(
				'ok'      => ! empty( $res['ok'] ),
				'message' => isset( $res['message'] ) ? $res['message'] : '',
				'queued'  => false,
			);
		}

		$this->enqueue( $order_id );
		self::flush_failed_count();
		return array( 'ok' => true, 'message' => __( 'Queued for another attempt.', 'aisooq-connector' ), 'queued' => true );
	}

	/**
	 * Retry a page of given-up orders.
	 *
	 * Cursored rather than self-consuming: a retried order keeps its attempt
	 * count (see retry()), and an order the operator cannot retry — wrong
	 * status, sync switched off — never leaves the result set at all. Pinning
	 * the query to page 1 would re-read the same rows forever and never reach
	 * anything behind them.
	 *
	 * $filters restricts the walk to one cause or one search — the "retry
	 * everything that failed for THIS reason" the screen is built around. It
	 * changes which orders are walked and nothing about how each one is
	 * retried.
	 *
	 * @param int   $offset  Where to resume.
	 * @param int   $limit
	 * @param array $filters code / search, as failed_query_args() takes them.
	 * @return array{queued:int,skipped:int,next_offset:int,total:int}
	 */
	public function retry_failed( $offset = 0, $limit = 50, array $filters = array() ) {
		$offset = max( 0, (int) $offset );
		$limit  = max( 1, min( self::MAX_BULK_RETRY, (int) $limit ) );

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'queued' => 0, 'skipped' => 0, 'next_offset' => $offset, 'total' => 0 );
		}
		$q = wc_get_orders( self::failed_query_args(
			array(
				'limit'    => $limit,
				'offset'   => $offset,
				'paginate' => true,
				'return'   => 'ids',
			),
			$filters
		) );

		$ids   = ( is_object( $q ) && isset( $q->orders ) ) ? (array) $q->orders : array();
		$total = ( is_object( $q ) && isset( $q->total ) ) ? (int) $q->total : 0;

		// Without Action Scheduler each retry is a blocking HTTP call at the
		// client's timeout, so a page of them would exceed max_execution_time
		// and leave the operator with a dead spinner. Time-box it.
		$inline = ! function_exists( 'as_enqueue_async_action' );
		$start  = microtime( true );

		$queued = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$res = $this->retry( (int) $id );
			if ( ! empty( $res['ok'] ) ) {
				$queued++;
			} else {
				$skipped++;
			}
			if ( $inline && ( microtime( true ) - $start ) > self::INLINE_BUDGET_SECONDS ) {
				break;
			}
		}

		self::flush_failed_count();
		return array(
			'queued'      => $queued,
			'skipped'     => $skipped,
			'next_offset' => $offset + $queued + $skipped,
			'total'       => $total,
		);
	}

	/**
	 * Retry every order that failed for one cause, a page at a time.
	 *
	 * Cursored for the same reason as retry_failed(), and it is the same walk:
	 * fixing a cause and sending back exactly the orders that hit it is the
	 * whole reason the rollup exists.
	 *
	 * @param string $code   An AISOOQ_META_ERROR_CODE, or CAUSE_NONE.
	 * @param int    $offset Where to resume.
	 * @param int    $limit
	 * @return array{queued:int,skipped:int,next_offset:int,total:int}
	 */
	public function retry_by_cause( $code, $offset = 0, $limit = 50 ) {
		return $this->retry_failed( $offset, $limit, array( 'code' => (string) $code ) );
	}

	/**
	 * Retry exactly the orders an operator ticked.
	 *
	 * Goes through retry() per order, so the two rules that make a retry safe —
	 * the attempt count is not reset, the rate-limit ceiling is not re-armed —
	 * hold here by construction instead of by remembering to copy them.
	 *
	 * Bounded, and it hands back what it did not reach rather than pretending
	 * it finished: without Action Scheduler every retry is a blocking HTTP call
	 * at the client's timeout, so a long selection would hit
	 * max_execution_time and the operator would be left with a dead spinner and
	 * no idea which orders were sent. The caller sends `remaining` back in.
	 *
	 * @param int[] $order_ids
	 * @return array{queued:int,skipped:int,results:array<int,array{ok:bool,message:string}>,remaining:int[]}
	 */
	public function retry_orders( array $order_ids ) {
		$all       = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		$batch     = array_slice( $all, 0, self::MAX_BULK_RETRY );
		$remaining = array_slice( $all, count( $batch ) );

		$inline  = ! function_exists( 'as_enqueue_async_action' );
		$start   = microtime( true );
		$queued  = 0;
		$skipped = 0;
		$results = array();

		foreach ( $batch as $i => $id ) {
			$res            = $this->retry( $id );
			$results[ $id ] = array(
				'ok'      => ! empty( $res['ok'] ),
				'message' => isset( $res['message'] ) ? (string) $res['message'] : '',
			);
			if ( ! empty( $res['ok'] ) ) {
				$queued++;
			} else {
				$skipped++;
			}
			if ( $inline && ( microtime( true ) - $start ) > self::INLINE_BUDGET_SECONDS ) {
				$remaining = array_merge( array_slice( $batch, $i + 1 ), $remaining );
				break;
			}
		}

		self::flush_failed_count();
		return array(
			'queued'    => $queued,
			'skipped'   => $skipped,
			'results'   => $results,
			'remaining' => array_values( $remaining ),
		);
	}
}
