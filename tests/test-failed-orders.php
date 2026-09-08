<?php
/**
 * Orders that gave up: recording why, listing them, and sending them again.
 *
 * The retry semantics are the delicate part and most of these tests are about
 * them. Resetting an order's attempt count on retry looks obviously right and
 * is a trap: it removes the order from this screen and from the count BEFORE
 * anything has been pushed, so if the queued job never runs — cron off, a
 * wedged queue, the operator pausing the connection a minute later — the order
 * is stranded with nothing left pointing at it. Every path that retries in
 * bulk — a ticked selection, a whole cause — is pinned against that same trap
 * separately, because it is a rule each new call site can quietly break.
 *
 * The filters are the other delicate part, in the opposite direction: a filter
 * that matches nothing must answer "nothing". Resolved as an id set, "matched
 * nothing" is an empty list and both order stores read an empty id list as "no
 * constraint" — so the inversion is one missing line away, and it would show
 * the whole pile beside a button offering to retry all of it.
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

	/**
	 * @param string $code The WP_Error code. This is what the cause rollup
	 *                     groups on, so anything asserting about causes has to
	 *                     say it; the default keeps the older tests reading the
	 *                     way they were written.
	 */
	private function exhaust( WC_Order $order, $message = 'Server error', $code = 'aisooq_http_500' ) {
		$order->update_meta_data( AISOOQ_META_ATTEMPTS, AI_Sooq_Order_Sync::MAX_ATTEMPTS - 1 );
		$order->save();
		$this->fail_once( wc_get_order( $order->get_id() ), new WP_Error( $code, $message, array( 'status' => 500 ) ) );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * A failure from before the error code was recorded — the orders that were
	 * already sitting on this screen when the rollup shipped.
	 */
	private function exhaust_without_code( WC_Order $order, $message = 'Server error' ) {
		$order = $this->exhaust( $order, $message );
		$order->delete_meta_data( AISOOQ_META_ERROR_CODE );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Date a failure explicitly.
	 *
	 * Same reason as $age_hours on make_order(): two failures recorded in the
	 * same second carry the same stamp, and the rollup then picks its label by
	 * comparing the messages instead of the times — so a test about which
	 * message wins has to set the times or it is asserting alphabetical order.
	 */
	private function last_try_at( WC_Order $order, $stamp ) {
		$order->update_meta_data( AISOOQ_META_LAST_TRY, $stamp );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Give an order someone to be searched for. make_order() builds the barest
	 * order that can exist and a name search has nothing to match on one.
	 *
	 * @param array $billing first / last / phone / email.
	 */
	private function with_billing( WC_Order $order, array $billing ) {
		$order->set_billing_first_name( isset( $billing['first'] ) ? $billing['first'] : '' );
		$order->set_billing_last_name( isset( $billing['last'] ) ? $billing['last'] : '' );
		$order->set_billing_phone( isset( $billing['phone'] ) ? $billing['phone'] : '' );
		if ( isset( $billing['email'] ) ) {
			$order->set_billing_email( $billing['email'] );
		}
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * A filter value nothing else in this file can collide with.
	 *
	 * failed_filter_ids() memoises resolved ids in a `static`, which lives for
	 * one page view in production and for the whole PHPUnit run here. Two tests
	 * filtering on the same code or the same term would hand the second one the
	 * first one's ids — long after the transaction rollback removed those
	 * orders — and it would fail with an empty list for no visible reason.
	 *
	 * @param string $tag Distinguishes several values within one test.
	 * @return string
	 */
	private function term( $tag ) {
		return 'aisooq' . substr( md5( $this->getName() . '|' . $tag ), 0, 10 );
	}

	/** The cause rollup keyed by code, which is how every assertion reads it. */
	private function causes_by_code( $fresh = true ) {
		$by_code = array();
		foreach ( AI_Sooq_Order_Sync::failed_by_cause( $fresh ) as $cause ) {
			$by_code[ $cause['code'] ] = $cause;
		}
		return $by_code;
	}

	/** Every order the screen would list for these filters. */
	private function listed_ids( array $filters = array() ) {
		$args = AI_Sooq_Order_Sync::failed_query_args( array( 'limit' => -1, 'return' => 'ids' ), $filters );
		return array_map( 'intval', (array) wc_get_orders( $args ) );
	}

	/**
	 * Plant a stale payload hash on each order.
	 *
	 * Which orders a bulk retry actually touched cannot be read off the counts
	 * it returns, and with Action Scheduler present nothing is pushed
	 * synchronously either. But retry() clears AISOOQ_META_HASH on every order
	 * it accepts and on no other (see test_a_retry_clears_the_unchanged_hash),
	 * so a hash planted here and gone afterwards is proof this order was
	 * retried, and one still holding its hash is proof it was left alone.
	 */
	private function plant_hashes( array $orders ) {
		foreach ( $orders as $order ) {
			$order->update_meta_data( AISOOQ_META_HASH, 'stale-hash' );
			$order->save();
		}
	}

	/** @return bool Whether a retry was accepted for this order. */
	private function was_retried( $order_id ) {
		return '' === (string) wc_get_order( $order_id )->get_meta( AISOOQ_META_HASH );
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

	// ── Why they gave up ────────────────────────────────────────────────────

	/**
	 * Forty failures are not forty problems. The code that says so was already
	 * being recorded and never shown, so an operator could read "40 failed" and
	 * had no way to tell one broken setting from forty broken orders.
	 */
	public function test_the_rollup_counts_orders_per_cause() {
		$sid  = $this->term( 'sid' );
		$http = $this->term( 'http' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Missing Store SID.', $sid );
		$this->exhaust( $this->make_order( 'processing', 2 ), 'Missing Store SID.', $sid );
		$this->exhaust( $this->make_order( 'processing', 3 ), 'Server error', $http );

		$causes = $this->causes_by_code();

		$this->assertArrayHasKey( $sid, $causes );
		$this->assertArrayHasKey( $http, $causes );
		$this->assertSame( 2, $causes[ $sid ]['count'] );
		$this->assertSame( 1, $causes[ $http ]['count'] );
	}

	/** The rail is read top down, so the emergency has to be the first tile. */
	public function test_the_biggest_cause_comes_first() {
		$rare   = $this->term( 'rare' );
		$common = $this->term( 'common' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Rare', $rare );
		foreach ( array( 2, 3, 4 ) as $age_hours ) {
			$this->exhaust( $this->make_order( 'processing', $age_hours ), 'Common', $common );
		}

		$causes = AI_Sooq_Order_Sync::failed_by_cause( true );

		$this->assertSame( $common, $causes[0]['code'], 'The cause with the most orders behind it must lead.' );
		$this->assertSame( $rare, $causes[1]['code'] );
	}

	/**
	 * The label is the whole tile as far as a human is concerned, and it is
	 * chosen inside the grouped query by gluing the last-attempt stamp in front
	 * of the message. A code whose newest failure says something new must stop
	 * showing the old wording — here the stale message would win on text alone.
	 */
	public function test_a_cause_shows_its_most_recent_message() {
		$code = $this->term( 'code' );
		$old  = $this->exhaust( $this->make_order( 'processing', 2 ), 'Zzz an older wording', $code );
		$this->last_try_at( $old, '2020-01-01 00:00:00' );
		$new = $this->exhaust( $this->make_order( 'processing', 1 ), 'Missing Store SID.', $code );
		$this->last_try_at( $new, '2020-06-01 00:00:00' );

		$causes = $this->causes_by_code();

		$this->assertSame( 'Missing Store SID.', $causes[ $code ]['label'] );
	}

	/**
	 * That stamp is sliced back off by byte offset, so a Bangla platform error
	 * would come back cut mid-character if the offset were ever counted in
	 * characters instead.
	 */
	public function test_a_multibyte_cause_label_survives_the_rollup() {
		$code   = $this->term( 'code' );
		$bangla = 'প্ল্যাটফর্ম ত্রুটি';
		$this->exhaust( $this->make_order( 'processing', 1 ), $bangla, $code );

		$causes = $this->causes_by_code();

		$this->assertSame( $bangla, $causes[ $code ]['label'] );
	}

	/**
	 * Failures recorded before the code existed are real orders still sitting
	 * on the screen. Leaving them out of the rollup would make the tiles add up
	 * to less than the heading above them, which reads as a broken count and
	 * hides the oldest failures on the store.
	 */
	public function test_failures_with_no_code_recorded_get_their_own_cause() {
		$code = $this->term( 'code' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Server error', $code );
		$this->exhaust_without_code( $this->make_order( 'processing', 2 ) );

		$causes = $this->causes_by_code();

		$this->assertArrayHasKey( AI_Sooq_Order_Sync::CAUSE_NONE, $causes );
		$this->assertSame( 1, $causes[ AI_Sooq_Order_Sync::CAUSE_NONE ]['count'] );
		$this->assertSame( 1, $causes[ $code ]['count'] );
	}

	/**
	 * The tiles and the heading count the same orders or one of them is lying —
	 * and a trashed order is the way they drift apart: it keeps the attempt
	 * meta forever while being on no screen at all.
	 */
	public function test_the_causes_add_up_to_the_header_count() {
		$code = $this->term( 'code' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Server error', $code );
		$this->exhaust( $this->make_order( 'processing', 2 ), 'Server error', $code );
		$this->exhaust_without_code( $this->make_order( 'processing', 3 ) );

		$trashed = $this->exhaust( $this->make_order( 'processing', 4 ), 'Server error', $code );
		$trashed->delete( false );

		$still_going = $this->make_order( 'processing', 5 );
		$this->fail_once( $still_going, new WP_Error( $code, 'x', array( 'status' => 500 ) ) );

		AI_Sooq_Order_Sync::flush_failed_count();
		$total = array_sum( wp_list_pluck( AI_Sooq_Order_Sync::failed_by_cause( true ), 'count' ) );

		$this->assertSame( 3, $total, 'A trashed order and one still retrying are on nobody\'s screen.' );
		$this->assertSame( AI_Sooq_Order_Sync::failed_count( true ), $total );
	}

	/**
	 * The notice path must not put an unindexable grouped read over the whole
	 * order-meta table on every admin page of the biggest stores, for the same
	 * reason the count must not — see
	 * test_the_count_does_not_query_on_a_cold_cache_unless_asked. On a cold
	 * cache it answers "no causes" rather than querying.
	 */
	public function test_the_rollup_does_not_query_on_a_cold_cache_unless_asked() {
		$this->exhaust( $this->make_order() );
		AI_Sooq_Order_Sync::flush_failed_count();

		$this->assertSame( array(), AI_Sooq_Order_Sync::failed_by_cause(), 'A cold cache must not trigger the query.' );
		$this->assertCount( 1, AI_Sooq_Order_Sync::failed_by_cause( true ), '…but asking for it fresh must.' );
		$this->assertCount( 1, AI_Sooq_Order_Sync::failed_by_cause(), 'and it is cached afterwards.' );
	}

	/**
	 * The rollup describes the same set as the count, so it has to die with it.
	 * A second flush call to remember is a call somebody forgets, and the screen
	 * then offers "5 × HTTP 500" to retry an hour after those five were sent.
	 */
	public function test_flushing_the_count_flushes_the_rollup_with_it() {
		$code = $this->term( 'code' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Server error', $code );

		$causes = $this->causes_by_code();
		$this->assertSame( 1, $causes[ $code ]['count'] );

		$this->exhaust( $this->make_order( 'processing', 2 ), 'Server error', $code );
		AI_Sooq_Order_Sync::flush_failed_count();

		$this->assertSame( array(), AI_Sooq_Order_Sync::failed_by_cause(), 'The count and the rollup share one flush.' );

		$causes = $this->causes_by_code();
		$this->assertSame( 2, $causes[ $code ]['count'] );
	}

	/** An order with attempts left has not given up, so it is not yet a cause. */
	public function test_an_order_still_retrying_is_not_a_cause() {
		$order = $this->make_order();
		$this->fail_once( $order, new WP_Error( $this->term( 'code' ), 'x', array( 'status' => 500 ) ) );

		$this->assertSame( array(), AI_Sooq_Order_Sync::failed_by_cause( true ) );
	}

	// ── Narrowing the pile ──────────────────────────────────────────────────

	public function test_filtering_by_cause_lists_only_that_cause() {
		$wanted = $this->term( 'wanted' );
		$other  = $this->term( 'other' );
		$a      = $this->exhaust( $this->make_order( 'processing', 1 ), 'Missing Store SID.', $wanted );
		$b      = $this->exhaust( $this->make_order( 'processing', 2 ), 'Missing Store SID.', $wanted );
		$this->exhaust( $this->make_order( 'processing', 3 ), 'Server error', $other );

		$this->assertEqualSets( array( $a->get_id(), $b->get_id() ), $this->listed_ids( array( 'code' => $wanted ) ) );
		$this->assertSame(
			2,
			AI_Sooq_Order_Sync::failed_count_matching( array( 'code' => $wanted ) ),
			'The heading counts what the rows show, or the operator is told to retry a set they cannot see.'
		);
	}

	/** The code-less bucket is a separate branch of the query and can rot alone. */
	public function test_filtering_by_the_code_less_cause_lists_only_those() {
		$code = $this->term( 'code' );
		$this->exhaust( $this->make_order( 'processing', 1 ), 'Server error', $code );
		$old = $this->exhaust_without_code( $this->make_order( 'processing', 2 ) );

		$this->assertSame(
			array( $old->get_id() ),
			$this->listed_ids( array( 'code' => AI_Sooq_Order_Sync::CAUSE_NONE ) )
		);
	}

	/**
	 * The order number is what an operator copies out of the customer's message,
	 * and on a default store that number is the order id.
	 */
	public function test_a_search_finds_an_order_by_its_number() {
		$wanted = $this->exhaust( $this->make_order( 'processing', 1 ) );
		$this->exhaust( $this->make_order( 'processing', 2 ) );

		$this->assertSame( array( $wanted->get_id() ), $this->listed_ids( array( 'search' => (string) $wanted->get_id() ) ) );
	}

	/**
	 * Typing the whole name is how people search, and a full name matches
	 * neither the first-name nor the last-name column on its own.
	 */
	public function test_a_search_finds_a_customer_by_full_name() {
		$last   = $this->term( 'last' );
		$wanted = $this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 1 ) ),
			array( 'first' => 'Ayesha', 'last' => $last )
		);
		$this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 2 ) ),
			array( 'first' => 'Karim', 'last' => 'Rahman' )
		);

		$this->assertSame( array( $wanted->get_id() ), $this->listed_ids( array( 'search' => 'Ayesha ' . $last ) ) );
	}

	/** A phone number is what a Bangladeshi shop has instead of an account. */
	public function test_a_search_finds_a_customer_by_phone() {
		$wanted = $this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 1 ) ),
			array( 'first' => 'Ayesha', 'phone' => '+8801711000111' )
		);
		$this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 2 ) ),
			array( 'first' => 'Karim', 'phone' => '+8801999888777' )
		);

		$this->assertSame( array( $wanted->get_id() ), $this->listed_ids( array( 'search' => '1711000111' ) ) );
	}

	public function test_a_search_finds_a_customer_by_email() {
		$mailbox = $this->term( 'mail' );
		$wanted  = $this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 1 ) ),
			array( 'first' => 'Ayesha', 'email' => $mailbox . '@example.test' )
		);
		$this->with_billing(
			$this->exhaust( $this->make_order( 'processing', 2 ) ),
			array( 'first' => 'Karim', 'email' => 'karim@example.test' )
		);

		$this->assertSame( array( $wanted->get_id() ), $this->listed_ids( array( 'search' => $mailbox ) ) );
	}

	/**
	 * The classic bug in this shape, and the reason it gets its own test: a
	 * filter that matches nothing resolves to an empty id set, both order stores
	 * read an empty id set as "no constraint at all", and the screen answers a
	 * search for a customer who is not here with every failure on the store —
	 * under a heading saying otherwise, beside a button offering to retry them.
	 */
	public function test_a_search_that_matches_nothing_lists_nothing() {
		$this->exhaust( $this->make_order( 'processing', 1 ) );
		$this->exhaust( $this->make_order( 'processing', 2 ) );
		$filters = array( 'search' => $this->term( 'nobody' ) );

		$this->assertSame( array(), $this->listed_ids( $filters ) );
		$this->assertSame( 0, AI_Sooq_Order_Sync::failed_count_matching( $filters ) );
		$this->assertSame( 2, AI_Sooq_Order_Sync::failed_count( true ), 'and the pile itself is untouched.' );
	}

	/**
	 * A filter narrows the given-up set and can never widen it. An order that
	 * matches the search but is still being retried on its own has not given up:
	 * listing it here would offer a retry for an order already queued for one.
	 */
	public function test_a_search_only_ever_reaches_orders_that_gave_up() {
		$name  = $this->term( 'name' );
		$still = $this->with_billing( $this->make_order( 'processing', 1 ), array( 'first' => $name ) );
		$this->fail_once( wc_get_order( $still->get_id() ), new WP_Error( 'aisooq_http_500', 'x', array( 'status' => 500 ) ) );
		$given_up = $this->with_billing( $this->exhaust( $this->make_order( 'processing', 2 ) ), array( 'first' => $name ) );

		$this->assertSame( array( $given_up->get_id() ), $this->listed_ids( array( 'search' => $name ) ) );
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

	// ── Retrying a selection, and retrying a cause ──────────────────────────

	/**
	 * The lever this screen exists to give an operator: they fixed one thing and
	 * want those orders back. Sending an order nobody ticked is worse than
	 * sending none — it re-pushes orders whose cause is still broken, which
	 * spends their attempt on the same error and buries the ones that were
	 * actually fixed.
	 */
	public function test_retrying_a_selection_retries_exactly_those_orders() {
		$a = $this->exhaust( $this->make_order( 'processing', 1 ) );
		$b = $this->exhaust( $this->make_order( 'processing', 2 ) );
		$c = $this->exhaust( $this->make_order( 'processing', 3 ) );
		$this->plant_hashes( array( $a, $b, $c ) );

		$res = $this->sync->retry_orders( array( $a->get_id(), $c->get_id() ) );

		$this->assertSame( array( $a->get_id(), $c->get_id() ), array_keys( $res['results'] ) );
		$this->assertTrue( $this->was_retried( $a->get_id() ) );
		$this->assertTrue( $this->was_retried( $c->get_id() ) );
		$this->assertFalse( $this->was_retried( $b->get_id() ), 'An order nobody ticked must not be pushed.' );
		$this->assertSame( array(), $res['remaining'] );
	}

	/**
	 * The trap again, on the path where it would do the most damage: clearing
	 * the attempt count in bulk drops a whole selection off this screen and out
	 * of the count before anything was pushed, and if those queued jobs never
	 * ran nothing would point at any of them again.
	 */
	public function test_retrying_a_selection_does_not_reset_the_attempt_count() {
		$order = $this->exhaust( $this->make_order() );

		$this->sync->retry_orders( array( $order->get_id() ) );

		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_ATTEMPTS,
			(int) wc_get_order( $order->get_id() )->get_meta( AISOOQ_META_ATTEMPTS ),
			'A bulk retry must leave every order visible until it actually succeeds.'
		);
	}

	/**
	 * Re-arming the ceiling per order is precisely a bulk-path bug: fifty ticked
	 * orders would authorise fifty × MAX_RATE_DEFERRALS re-queues from one
	 * click, which is the unbounded queue that ceiling exists to stop.
	 */
	public function test_retrying_a_selection_does_not_re_arm_the_rate_limit_ceiling() {
		$order = $this->exhaust( $this->make_order() );
		$order->update_meta_data( AISOOQ_META_RATE_DEFERRALS, AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS );
		$order->save();

		$this->sync->retry_orders( array( $order->get_id() ) );

		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS,
			(int) wc_get_order( $order->get_id() )->get_meta( AISOOQ_META_RATE_DEFERRALS )
		);
	}

	/**
	 * "I fixed the SID, send those back" is the entire point of the rollup. It
	 * has to send back the orders that hit that cause and no others: the rest
	 * are still broken and re-pushing them just fails them again.
	 */
	public function test_retrying_a_cause_retries_only_orders_with_that_cause() {
		$wanted = $this->term( 'wanted' );
		$other  = $this->term( 'other' );
		$a      = $this->exhaust( $this->make_order( 'processing', 1 ), 'Missing Store SID.', $wanted );
		$b      = $this->exhaust( $this->make_order( 'processing', 2 ), 'Missing Store SID.', $wanted );
		$c      = $this->exhaust( $this->make_order( 'processing', 3 ), 'Server error', $other );
		$this->plant_hashes( array( $a, $b, $c ) );

		$res = $this->sync->retry_by_cause( $wanted );

		$this->assertSame( 2, $res['total'], 'The walk is over one cause, not over the pile.' );
		$this->assertTrue( $this->was_retried( $a->get_id() ) );
		$this->assertTrue( $this->was_retried( $b->get_id() ) );
		$this->assertFalse( $this->was_retried( $c->get_id() ), 'Fixing one cause must not re-push the others.' );
	}

	/** Same trap as the selection path; a different call site to regress in. */
	public function test_retrying_a_cause_does_not_reset_the_attempt_count() {
		$code  = $this->term( 'code' );
		$order = $this->exhaust( $this->make_order( 'processing', 1 ), 'Missing Store SID.', $code );

		$this->sync->retry_by_cause( $code );

		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_ATTEMPTS,
			(int) wc_get_order( $order->get_id() )->get_meta( AISOOQ_META_ATTEMPTS )
		);
	}

	public function test_retrying_a_cause_does_not_re_arm_the_rate_limit_ceiling() {
		$code  = $this->term( 'code' );
		$order = $this->exhaust( $this->make_order( 'processing', 1 ), 'Rate limited', $code );
		$order->update_meta_data( AISOOQ_META_RATE_DEFERRALS, AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS );
		$order->save();

		$this->sync->retry_by_cause( $code );

		$this->assertSame(
			AI_Sooq_Order_Sync::MAX_RATE_DEFERRALS,
			(int) wc_get_order( $order->get_id() )->get_meta( AISOOQ_META_RATE_DEFERRALS )
		);
	}

	/**
	 * The same inversion as the empty search, one click further along: if a
	 * filter that matches nothing were read as "no filter", the button labelled
	 * "retry all matching" would retry the entire pile.
	 */
	public function test_retrying_a_filter_that_matches_nothing_retries_nothing() {
		$a = $this->exhaust( $this->make_order( 'processing', 1 ) );
		$b = $this->exhaust( $this->make_order( 'processing', 2 ) );
		$this->plant_hashes( array( $a, $b ) );

		$res = $this->sync->retry_failed( 0, 50, array( 'search' => $this->term( 'nobody' ) ) );

		$this->assertSame( 0, $res['total'] );
		$this->assertSame( 0, $res['queued'] );
		$this->assertFalse( $this->was_retried( $a->get_id() ) );
		$this->assertFalse( $this->was_retried( $b->get_id() ) );
	}

	/**
	 * Without Action Scheduler every retry is a blocking HTTP call, so an
	 * operator ticking a whole page cannot be allowed to turn one click into one
	 * PHP timeout with no record of which orders were sent. What the batch did
	 * not reach comes back so the caller can send it again.
	 */
	public function test_a_selection_larger_than_the_ceiling_hands_back_the_rest() {
		$ceiling = AI_Sooq_Order_Sync::MAX_BULK_RETRY;
		// Ids that resolve to nothing: this is about the bound, and building a
		// ceiling's worth of real orders to prove it costs seconds per run.
		$ids = range( 900001, 900001 + $ceiling + 4 );

		$res = $this->sync->retry_orders( $ids );

		$this->assertCount( $ceiling, $res['results'], 'One call may only touch MAX_BULK_RETRY orders.' );
		$this->assertSame( array_slice( $ids, $ceiling ), $res['remaining'] );
	}

	/** Ids that no longer resolve are refused, one by one, rather than fatal. */
	public function test_a_selection_holding_a_deleted_order_is_refused_not_fatal() {
		$order = $this->exhaust( $this->make_order() );

		$res = $this->sync->retry_orders( array( $order->get_id(), 99999999 ) );

		$this->assertSame( 1, $res['queued'] );
		$this->assertSame( 1, $res['skipped'] );
		$this->assertFalse( $res['results'][99999999]['ok'] );
	}

	/**
	 * The rollup is cached for an hour, so a retry that did not drop it leaves
	 * the rail offering a cause the operator just dealt with — and the tile
	 * counts would keep disagreeing with the rows underneath them.
	 */
	public function test_a_bulk_retry_drops_the_cached_rollup() {
		$code  = $this->term( 'code' );
		$order = $this->exhaust( $this->make_order( 'processing', 1 ), 'Server error', $code );
		$this->assertArrayHasKey( $code, $this->causes_by_code() );

		$this->sync->retry_orders( array( $order->get_id() ) );

		$this->assertSame(
			array(),
			AI_Sooq_Order_Sync::failed_by_cause(),
			'The rail must be rebuilt after a retry, not served from before it.'
		);
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

	/**
	 * A filter may only ever narrow this set. The meta pair below is what MAKES
	 * it the given-up set, so a filtered view that reshaped the query would list
	 * orders that never failed under a heading that says they did.
	 */
	public function test_a_filtered_query_keeps_the_shared_shape() {
		$args = AI_Sooq_Order_Sync::failed_query_args( array(), array( 'code' => $this->term( 'code' ) ) );

		$this->assertSame( AISOOQ_META_ATTEMPTS, $args['meta_key'] );
		$this->assertSame( AI_Sooq_Order_Sync::MAX_ATTEMPTS, $args['meta_value'] );
		$this->assertSame( '>=', $args['meta_compare'] );
		$this->assertSame( 'NUMERIC', $args['meta_type'] );
	}

	/**
	 * And with nothing filtered there must be no id constraint at all — not an
	 * empty one. Both order stores read an empty id list as "everything", so
	 * "no filters" and "matched nothing" have to be different values or one of
	 * them silently becomes the other.
	 */
	public function test_an_unfiltered_query_constrains_nothing() {
		$this->assertArrayNotHasKey( 'post__in', AI_Sooq_Order_Sync::failed_query_args() );
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
