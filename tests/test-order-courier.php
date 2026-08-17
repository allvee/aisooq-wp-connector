<?php
/**
 * Automatic courier history on placed orders.
 *
 * Three things have to hold, and each one costs the merchant money if it
 * doesn't: the check happens without a click when it's switched on, it does
 * NOT happen when it isn't, and it happens exactly once per number.
 *
 * @package AISooq
 */

class Test_Order_Courier extends WP_Ajax_UnitTestCase {

	/** @var AI_Sooq_Order_Courier */
	private $courier;
	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var int */
	private $api_calls = 0;

	private function api_payload() {
		return array(
			'successRatio'    => 76.0,
			'totalParcel'     => 25,
			'successParcel'   => 19,
			'cancelledParcel' => 6,
			'couriers'        => array(
				array( 'slug' => 'pathao', 'name' => 'Pathao', 'total' => 20, 'success' => 18, 'cancelled' => 2, 'ratio' => 90.0 ),
				array( 'slug' => 'steadfast', 'name' => 'Steadfast', 'total' => 5, 'success' => 1, 'cancelled' => 4, 'ratio' => 20.0 ),
			),
		);
	}

	private function configure( array $overrides = array() ) {
		update_option( AISOOQ_OPTION, array_merge( array(
			'active'             => 1,
			'api_base'           => 'https://api.example.test',
			'sid'                => 'store1',
			'client_id'          => 'cid',
			'client_secret'      => 'csecret',
			'auto_courier_check' => 1,
		), $overrides ) );

		$this->settings = new AI_Sooq_Settings();
		$logger         = new AI_Sooq_Logger( $this->settings );
		$this->courier  = new AI_Sooq_Order_Courier( $this->settings, new AI_Sooq_Api_Client( $this->settings, $logger ), $logger );

		// The plugin booted before this fixture wrote its options, and settings
		// are memoised per request — the registered ajax handler would otherwise
		// answer from the pre-fixture state.
		$cache = new ReflectionProperty( 'AI_Sooq_Settings', 'cache' );
		$cache->setAccessible( true );
		$cache->setValue( AI_Sooq_Plugin::instance()->settings(), null );
	}

	public function set_up() {
		parent::set_up();
		$this->api_calls = 0;
		$this->configure();
		set_transient( AISOOQ_TOKEN_TRANSIENT, 'test-token', HOUR_IN_SECONDS );
		$this->_setRole( 'administrator' );
		$_POST = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function stub_api( $body, $status = 200 ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $body, $status ) {
			if ( false === strpos( $url, '/connect/courier' ) ) {
				return $pre;
			}
			++$this->api_calls;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $status, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}, 10, 3 );
	}

	/**
	 * @param string|array $args Phone (the original signature), or overrides:
	 *                           billing_phone / billing_email / status / total.
	 */
	private function make_order( $args = '+8801712345678' ) {
		if ( is_string( $args ) ) {
			$args = array( 'billing_phone' => $args );
		}
		$args = wp_parse_args(
			$args,
			array(
				'billing_phone' => '+8801712345678',
				'billing_email' => '',
				'status'        => 'processing',
				'total'         => 0,
			)
		);

		$order = new WC_Order();
		$order->set_billing_first_name( 'Karim' );
		$order->set_billing_phone( $args['billing_phone'] );
		if ( '' !== $args['billing_email'] ) {
			$order->set_billing_email( $args['billing_email'] );
		}
		if ( $args['total'] ) {
			$order->set_total( (string) $args['total'] );
		}
		$order->set_status( $args['status'] );
		$order->save();
		return $order;
	}

	/** Re-read from storage — a stale in-memory object would hide a save bug. */
	private function reload( WC_Order $order ) {
		return wc_get_order( $order->get_id() );
	}

	// ── The lookup ──────────────────────────────────────────────────────────

	public function test_a_placed_order_gets_its_courier_history_without_a_click() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$this->assertTrue( $this->courier->run_check( $order->get_id() ) );

		$snap = $this->courier->snapshot( $this->reload( $order ) );
		$this->assertNotNull( $snap, 'The lookup must be stored on the order.' );
		$this->assertSame( 76.0, $snap['ratio'] );
		$this->assertSame( 25, $snap['parcels'] );
		$this->assertSame( 19, $snap['success'] );
		$this->assertSame( 6, $snap['cancelled'] );
		$this->assertCount( 2, $snap['couriers'] );
		$this->assertSame( 'Pathao', $snap['couriers'][0]['name'] );
		$this->assertSame( 90.0, $snap['couriers'][0]['ratio'] );
	}

	public function test_the_result_survives_a_reload_and_is_not_re_fetched() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();
		$this->courier->run_check( $order->get_id() );
		$this->assertSame( 1, $this->api_calls );

		// The scheduler firing again (a status change, a retried action) must not
		// buy the same answer a second time.
		$schedule = new ReflectionMethod( 'AI_Sooq_Order_Courier', 'schedule' );
		$schedule->setAccessible( true );
		$schedule->invoke( $this->courier, $order->get_id() );

		$this->assertSame( 1, $this->api_calls, 'A stored answer must not be re-purchased.' );
	}

	public function test_a_number_with_no_history_is_stored_as_no_history() {
		// Not an error and not "unchecked" — storing it is what stops the screen
		// re-querying an unknown number on every load.
		$this->stub_api( array( 'successRatio' => null, 'totalParcel' => 0, 'couriers' => array() ) );
		$order = $this->make_order();
		$this->courier->run_check( $order->get_id() );

		$snap = $this->courier->snapshot( $this->reload( $order ) );
		$this->assertNotNull( $snap );
		$this->assertNull( $snap['ratio'] );
		$this->assertStringContainsString( 'No history', $this->courier->cell( $this->reload( $order ) ) );
	}

	public function test_a_transport_failure_stores_nothing() {
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		} );
		$order = $this->make_order();

		$this->assertFalse( $this->courier->run_check( $order->get_id() ) );
		$this->assertNull( $this->courier->snapshot( $this->reload( $order ) ), 'A failed lookup must not look like a checked order.' );
	}

	public function test_an_order_without_a_phone_is_skipped() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order( '' );

		$this->assertFalse( $this->courier->run_check( $order->get_id() ) );
		$this->assertSame( 0, $this->api_calls );
		$this->assertStringContainsString( 'No phone', $this->courier->cell( $this->reload( $order ) ) );
	}

	public function test_editing_the_billing_phone_discards_the_old_answer() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();
		$this->courier->run_check( $order->get_id() );

		$order = $this->reload( $order );
		$order->set_billing_phone( '+8801999999999' );
		$order->save();

		$this->assertNull(
			$this->courier->snapshot( $this->reload( $order ) ),
			'A ratio fetched for the previous number does not describe the new one.'
		);
	}

	// ── The opt-in ──────────────────────────────────────────────────────────

	public function test_automatic_checking_is_off_by_default() {
		$fresh = new AI_Sooq_Settings();
		$this->assertEmpty( $fresh->defaults()['auto_courier_check'], 'Each lookup is billed — it cannot default to on.' );
	}

	public function test_nothing_is_scheduled_while_the_setting_is_off() {
		$this->configure( array( 'auto_courier_check' => 0 ) );
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$this->assertFalse( $this->courier->is_enabled() );
		$this->courier->on_new_order( $order->get_id() );

		$this->assertSame( 0, $this->api_calls );
		$this->assertNull( $this->courier->snapshot( $this->reload( $order ) ) );
	}

	public function test_nothing_is_scheduled_while_the_connection_is_paused() {
		$this->configure( array( 'active' => 0 ) );
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$this->assertFalse( $this->courier->is_enabled() );
		$this->courier->on_new_order( $order->get_id() );
		$this->assertSame( 0, $this->api_calls );
	}

	// ── What the operator sees ──────────────────────────────────────────────

	public function test_an_unchecked_order_offers_the_button_and_a_checked_one_offers_recheck() {
		$order = $this->make_order();
		$before = $this->courier->cell( $order );
		$this->assertStringContainsString( 'Check courier history', $before );
		$this->assertStringNotContainsString( 'aisooq-ratio', $before );

		$this->stub_api( $this->api_payload() );
		$this->courier->run_check( $order->get_id() );

		$after = $this->courier->cell( $this->reload( $order ) );
		$this->assertStringContainsString( 'aisooq-ratio', $after );
		$this->assertStringContainsString( '76%', $after );
		$this->assertStringContainsString( 'Recheck', $after );
		$this->assertStringNotContainsString( 'Check courier history', $after, 'A checked order must not re-offer a paid lookup.' );
	}

	public function test_the_badge_tone_matches_the_risk() {
		$this->assertSame( 'ok', AI_Sooq_Order_Courier::tone( 92.0 ) );
		$this->assertSame( 'warn', AI_Sooq_Order_Courier::tone( 70.0 ) );
		$this->assertSame( 'err', AI_Sooq_Order_Courier::tone( 41.0 ) );
		$this->assertSame( 'muted', AI_Sooq_Order_Courier::tone( null ) );
	}

	// ── The ratio bar ───────────────────────────────────────────────────────

	public function test_the_bar_fills_to_the_ratio_and_carries_the_figure() {
		$html = AI_Sooq_Order_Courier::ratio_bar( 76.0, 25 );

		$this->assertStringContainsString( 'width:76%', $html );
		$this->assertStringContainsString( '>76%<', $html, 'The figure has to be on the bar, not only in the tooltip.' );
		$this->assertStringContainsString( '25 parcels', $html );
		// Screen readers get the value, not just a coloured rectangle.
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'aria-valuenow="76"', $html );
	}

	public function test_the_bar_runs_green_to_red_across_the_range() {
		// Hue 0 is red and 120 is green, so the hue must climb with the ratio.
		preg_match( '/hsl\((\d+)/', AI_Sooq_Order_Courier::ratio_bar( 0.0 ), $low );
		preg_match( '/hsl\((\d+)/', AI_Sooq_Order_Courier::ratio_bar( 50.0 ), $mid );
		preg_match( '/hsl\((\d+)/', AI_Sooq_Order_Courier::ratio_bar( 100.0 ), $high );

		$this->assertSame( 0, (int) $low[1], '0% is red.' );
		$this->assertSame( 120, (int) $high[1], '100% is green.' );
		$this->assertGreaterThan( (int) $low[1], (int) $mid[1] );
		$this->assertLessThan( (int) $high[1], (int) $mid[1] );
	}

	public function test_a_low_ratio_puts_the_figure_outside_the_fill() {
		// A 6% fill is far too short to hold "6%" — and a low ratio is exactly
		// the number the operator must be able to read.
		$this->assertStringContainsString( 'aisooq-ratio err outside', AI_Sooq_Order_Courier::ratio_bar( 6.0 ) );
		$this->assertStringContainsString( 'aisooq-ratio ok inside', AI_Sooq_Order_Courier::ratio_bar( 93.0 ) );
	}

	public function test_the_orders_list_shows_the_bar_alone_and_the_panel_shows_the_detail() {
		$order = $this->make_order();
		$this->stub_api( $this->api_payload() );
		$this->courier->run_check( $order->get_id() );
		$order = $this->reload( $order );

		// Orders list: the column competes with a dozen others for width, so
		// it carries the bar and the action, nothing else.
		$list = $this->courier->cell( $order, false );
		$this->assertStringContainsString( 'aisooq-ratio', $list );
		$this->assertStringContainsString( '76%', $list );
		$this->assertStringContainsString( 'Recheck', $list );
		$this->assertStringNotContainsString( 'parcels', $list );
		$this->assertStringNotContainsString( 'Checked', $list );

		// The order's own panel has room for the whole picture.
		$panel = $this->courier->cell( $order, true );
		$this->assertStringContainsString( 'parcels', $panel );
		$this->assertStringContainsString( 'Checked', $panel );
	}

	public function test_delivered_and_returned_counts_are_coloured_apart() {
		$order = $this->make_order();
		$this->stub_api( $this->api_payload() );
		$this->courier->run_check( $order->get_id() );

		$panel = $this->courier->cell( $this->reload( $order ), true );
		// Two adjacent integers; colour is what says which way is good.
		$this->assertStringContainsString( 'aisooq-num-ok', $panel );
		$this->assertStringContainsString( 'aisooq-num-err', $panel );
	}

	public function test_the_list_offers_an_eye_and_the_panel_does_not() {
		$order = $this->make_order();
		$this->stub_api( $this->api_payload() );
		$this->courier->run_check( $order->get_id() );
		$order = $this->reload( $order );

		$this->assertStringContainsString( 'aisooq-ordc-eye', $this->courier->cell( $order, false ) );
		// The panel already IS the detail; an eye there would open itself.
		$this->assertStringNotContainsString( 'aisooq-ordc-eye', $this->courier->cell( $order, true ) );
	}

	// ── Past orders from this customer ──────────────────────────────────────

	public function test_customer_history_counts_kept_and_cancelled_orders() {
		$email = 'repeat@example.com';
		$this->make_order( array( 'billing_email' => $email, 'status' => 'completed' ) );
		$this->make_order( array( 'billing_email' => $email, 'status' => 'completed' ) );
		$this->make_order( array( 'billing_email' => $email, 'status' => 'cancelled' ) );
		$current = $this->make_order( array( 'billing_email' => $email, 'status' => 'processing' ) );

		$hist = $this->courier->customer_history( $current );

		// Three past orders — the current one is not part of its own history.
		$this->assertSame( 3, $hist['total'] );
		$this->assertSame( 2, $hist['completed'] );
		$this->assertSame( 1, $hist['cancelled'] );
	}

	public function test_a_first_time_customer_has_no_history() {
		$order = $this->make_order( array( 'billing_email' => 'brand-new@example.com' ) );
		$hist  = $this->courier->customer_history( $order );
		$this->assertSame( 0, $hist['total'] );
	}

	public function test_the_bar_clamps_nonsense_input() {
		// The upstream figure is not ours; a bar wider than its track would
		// spill over neighbouring cells.
		$this->assertStringContainsString( 'width:100%', AI_Sooq_Order_Courier::ratio_bar( 140.0 ) );
		$this->assertStringContainsString( 'width:0%', AI_Sooq_Order_Courier::ratio_bar( -12.0 ) );
	}

	public function test_a_paused_connection_disables_the_button_and_says_why() {
		$this->configure( array( 'active' => 0 ) );
		$html = $this->courier->cell( $this->make_order() );

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'connection paused', $html, 'A dead button with no explanation reads as a broken feature.' );
	}

	public function test_the_detailed_view_breaks_the_history_down_by_courier() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();
		$this->courier->run_check( $order->get_id() );

		$html = $this->courier->cell( $this->reload( $order ), true );
		$this->assertStringContainsString( 'Pathao', $html );
		$this->assertStringContainsString( 'Steadfast', $html );
		$this->assertStringContainsString( 'aisooq-ordc-tbl', $html );

		// The list column stays compact — the same data, without the table.
		$this->assertStringNotContainsString( 'aisooq-ordc-tbl', $this->courier->cell( $this->reload( $order ) ) );
	}

	public function test_the_orders_list_gains_a_courier_column() {
		$columns = $this->courier->add_column( array( 'order_number' => 'Order', 'order_status' => 'Status', 'order_total' => 'Total' ) );
		$this->assertArrayHasKey( 'aisooq_courier', $columns );
		$this->assertSame(
			array( 'order_number', 'order_status', 'aisooq_courier', 'order_total' ),
			array_keys( $columns ),
			'Risk belongs next to status, not tacked on the end.'
		);
	}

	// ── Recheck ─────────────────────────────────────────────────────────────

	private function post_recheck( $order_id, $nonce = null ) {
		$_POST = array(
			'order_id' => $order_id,
			'nonce'    => null === $nonce ? wp_create_nonce( AI_Sooq_Order_Courier::NONCE ) : $nonce,
		);
		$this->_last_response = '';
		$_REQUEST             = array_merge( $_POST, $_GET );
		$level                = ob_get_level();
		ob_start();
		try {
			do_action( 'wp_ajax_aisooq_order_courier_recheck' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Expected: the handler responded and stopped.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Expected: the handler responded and stopped.
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		return json_decode( $this->_last_response, true );
	}

	public function test_recheck_replaces_the_stored_answer_and_returns_the_new_cell() {
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();
		$this->courier->run_check( $order->get_id() );

		remove_all_filters( 'pre_http_request' );
		$this->stub_api( array( 'successRatio' => 41.0, 'totalParcel' => 17, 'successParcel' => 7, 'cancelledParcel' => 10, 'couriers' => array() ) );

		$res = $this->post_recheck( $order->get_id() );
		$this->assertTrue( (bool) $res['success'], 'Recheck should succeed: ' . wp_json_encode( $res ) );
		$this->assertStringContainsString( '41%', $res['data']['html'] );

		$snap = $this->courier->snapshot( $this->reload( $order ) );
		$this->assertSame( 41.0, $snap['ratio'], 'Recheck must overwrite, not append.' );
	}

	public function test_recheck_returns_the_same_html_a_reload_would_render() {
		// The reported bug in the cart worklist was the two paths disagreeing.
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$res = $this->post_recheck( $order->get_id() );
		$this->assertSame(
			$this->courier->cell( $this->reload( $order ) ),
			$res['data']['html'],
			'What appears after a check must be what a reload renders.'
		);
	}

	public function test_recheck_rejects_a_bad_nonce() {
		$order = $this->make_order();
		$res   = $this->post_recheck( $order->get_id(), 'not-a-nonce' );
		$this->assertNotTrue( isset( $res['success'] ) && $res['success'] );
	}

	public function test_recheck_refuses_while_the_connection_is_paused() {
		$this->configure( array( 'active' => 0 ) );
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$res = $this->post_recheck( $order->get_id() );
		$this->assertFalse( (bool) $res['success'] );
		$this->assertSame( 0, $this->api_calls );
	}

	public function test_recheck_is_refused_for_a_user_who_cannot_edit_orders() {
		$this->_setRole( 'subscriber' );
		$this->stub_api( $this->api_payload() );
		$order = $this->make_order();

		$res = $this->post_recheck( $order->get_id() );
		$this->assertFalse( (bool) $res['success'] );
		$this->assertSame( 0, $this->api_calls );
	}
}
