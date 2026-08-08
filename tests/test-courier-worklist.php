<?php
/**
 * The courier check as an operator actually experiences it on the Abandoned
 * carts screen: the rendered cell, and the AJAX round-trip behind the button.
 *
 * The original bug was not in the data layer — it was that the worklist always
 * rendered a fresh "Check ratio" button regardless of what had already been
 * looked up, so the result vanished on any re-render. These tests assert on the
 * HTML, because the HTML is where that bug lived.
 *
 * @package AISooq
 */

class Test_Courier_Worklist extends WP_Ajax_UnitTestCase {

	/** @var AI_Sooq_Abandoned_Sync */
	private $sync;
	/** @var AI_Sooq_Abandoned_Admin */
	private $admin;
	/** @var AI_Sooq_Settings */
	private $settings;

	/** Platform response for a phone with real history. */
	private function api_payload() {
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

		// A configured, active connection with abandoned sync on.
		update_option( AISOOQ_OPTION, array(
			'active'           => 1,
			'api_base'         => 'https://api.example.test',
			'sid'              => 'store1',
			'client_id'        => 'cid',
			'client_secret'    => 'csecret',
			'enable_abandoned' => 1,
		) );
		$this->settings = new AI_Sooq_Settings();
		$logger         = new AI_Sooq_Logger( $this->settings );
		$this->sync     = new AI_Sooq_Abandoned_Sync( $this->settings, new AI_Sooq_Api_Client( $this->settings, $logger ), $logger );
		$this->admin    = new AI_Sooq_Abandoned_Admin( $this->settings, $this->sync, $logger );

		// The plugin booted on `plugins_loaded`, before this fixture wrote its
		// options, and AI_Sooq_Settings memoises `all()` for the request. Its
		// registered wp_ajax_* handlers — and the shared API client they reach
		// through — would otherwise answer "Missing Store SID" forever.
		// Production invalidates this in Settings::save(); the fixture writes
		// the option directly, so it has to invalidate by hand.
		$cache = new ReflectionProperty( 'AI_Sooq_Settings', 'cache' );
		$cache->setAccessible( true );
		$cache->setValue( AI_Sooq_Plugin::instance()->settings(), null );

		// Skip the OAuth mint — the token endpoint isn't what's under test.
		set_transient( AISOOQ_TOKEN_TRANSIENT, 'test-token', HOUR_IN_SECONDS );

		$this->_setRole( 'administrator' );
		$_POST = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function make_cart( $phone = '+8801712345678' ) {
		global $wpdb;
		$key = 'sess_' . wp_generate_password( 12, false );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			AI_Sooq_Abandoned_Sync::table_name(),
			array(
				'session_key'   => $key,
				'customer_name' => 'Karim Rahman',
				'phone'         => $phone,
				'email'         => 'karim@example.com',
				'cart_json'     => wp_json_encode( array( array( 'title' => 'Imperial Oud 50ml', 'qty' => 1, 'price' => 1000 ) ) ),
				'subtotal'      => 1000,
				'status'        => 'active',
				'created_at'    => '2026-08-01 09:00:00',
				'updated_at'    => '2026-08-01 09:00:00',
			)
		);
		return $key;
	}

	/** Render the worklist <tbody> exactly as the screen (and AJAX search) does. */
	private function render( $active = true ) {
		$m = new ReflectionMethod( 'AI_Sooq_Abandoned_Admin', 'render_rows' );
		$m->setAccessible( true );
		$rows = new ReflectionMethod( 'AI_Sooq_Abandoned_Admin', 'rows' );
		$rows->setAccessible( true );
		return $m->invoke( $this->admin, $rows->invoke( $this->admin, array( 'status' => 'active' ) ), 'BDT', $active );
	}

	/** Stub the platform HTTP call. */
	private function stub_api( $body, $status = 200 ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $body, $status ) {
			if ( false === strpos( $url, '/connect/courier' ) ) {
				return $pre;
			}
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
	 * Dispatch a real admin-ajax request and return the decoded JSON.
	 *
	 * Deliberately not `_handleAjax()`: that also fires `admin_init`, and
	 * WooCommerce's admin notices then land in the same buffer as the response,
	 * so the JSON arrives with a wall of HTML in front of it. Dispatching the
	 * one hook keeps the buffer to what the handler actually emitted.
	 *
	 * `wp_send_json_*` terminates via wp_die, which the harness turns into an
	 * exception and drains the buffer into `_last_response` — that is the normal
	 * path here, not a failure.
	 */
	private function call_ajax( $action ) {
		$this->_last_response = '';
		$_REQUEST             = array_merge( $_POST, $_GET );
		$level                = ob_get_level();
		ob_start();
		try {
			do_action( 'wp_ajax_' . $action );
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

	private function post_check( $key ) {
		$_POST = array(
			'session_key' => $key,
			'nonce'       => wp_create_nonce( 'aisooq_abandoned' ),
		);
		return $this->call_ajax( 'aisooq_abandoned_courier' );
	}

	// ── The reported bug ────────────────────────────────────────────────────

	public function test_an_unchecked_cart_offers_the_check_button() {
		$this->make_cart();
		$html = $this->render();
		$this->assertStringContainsString( 'aisooq-check-courier', $html );
		$this->assertStringContainsString( 'Check ratio', $html );
		$this->assertStringNotContainsString( 'aisooq-ratio', $html );
	}

	public function test_a_checked_cart_renders_the_ratio_not_the_check_button() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		// A completely fresh render — the page reload the operator does next.
		$html = $this->render();

		$this->assertStringContainsString( 'aisooq-ratio', $html, 'The saved ratio must render after a reload.' );
		$this->assertStringContainsString( '76%', $html );
		$this->assertStringContainsString( '25', $html );
		$this->assertStringNotContainsString( 'Check ratio', $html, 'A checked cart must not re-offer a paid lookup.' );
		$this->assertStringContainsString( 'Recheck', $html );
	}

	public function test_the_full_per_courier_breakdown_is_rendered() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		$html = $this->render();

		$this->assertStringContainsString( 'Breakdown', $html );
		$this->assertStringContainsString( 'Pathao', $html );
		$this->assertStringContainsString( 'Steadfast', $html );
		$this->assertStringContainsString( '90%', $html, 'Per-courier rate for Pathao.' );
		$this->assertStringContainsString( '20%', $html, 'Per-courier rate for Steadfast.' );
		$this->assertStringContainsString( 'All couriers', $html, 'Headline totals row.' );
	}

	public function test_the_result_survives_a_search_re_render() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		// Typing in the search box: this is the re-render that used to wipe it.
		$_POST = array(
			'nonce'  => wp_create_nonce( 'aisooq_abandoned' ),
			'status' => 'active',
			'search' => 'Karim',
		);
		$res = $this->call_ajax( 'aisooq_abandoned_query' );

		$this->assertTrue( $res['success'] );
		$this->assertStringContainsString( '76%', $res['data']['html'] );
		$this->assertStringNotContainsString( 'Check ratio', $res['data']['html'] );
	}

	// ── Cost control ────────────────────────────────────────────────────────

	public function test_a_second_check_is_only_made_when_the_operator_asks() {
		$key   = $this->make_cart();
		$calls = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false === strpos( $url, '/connect/courier' ) ) {
				return $pre;
			}
			$calls++;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $this->api_payload() ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}, 10, 3 );

		$this->post_check( $key );
		$this->render();
		$this->render();
		$this->assertSame( 1, $calls, 'Rendering the worklist must never call the billed endpoint.' );

		$this->post_check( $key ); // Explicit Recheck.
		$this->assertSame( 2, $calls );
	}

	public function test_a_no_history_answer_is_shown_and_not_re_queried() {
		$key = $this->make_cart();
		$this->stub_api( array( 'successRatio' => null, 'totalParcel' => null, 'couriers' => array() ) );
		$this->post_check( $key );

		$html = $this->render();
		$this->assertStringContainsString( 'No data', $html );
		$this->assertStringNotContainsString( 'Check ratio', $html );
		$this->assertStringContainsString( 'Recheck', $html );
	}

	// ── Degradation + edge cases ────────────────────────────────────────────

	public function test_headline_still_shows_against_a_platform_without_the_breakdown() {
		$key = $this->make_cart();
		$this->stub_api( array( 'successRatio' => 88.0, 'totalParcel' => 20 ) );
		$this->post_check( $key );

		$html = $this->render();
		$this->assertStringContainsString( '88%', $html );
		$this->assertStringNotContainsString( 'Breakdown', $html, 'No breakdown button when there is nothing to break down.' );
	}

	public function test_a_paused_connection_still_shows_the_saved_result() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		$html = $this->render( false ); // Connection paused.

		$this->assertStringContainsString( '76%', $html, 'Saved data is local — pausing must not hide it.' );
		$this->assertStringContainsString( 'disabled', $html, 'But a new lookup cannot be made.' );
	}

	public function test_a_cart_with_no_phone_has_no_courier_control() {
		$this->make_cart( '' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'aisooq-check-courier', $html );
	}

	public function test_an_upstream_error_is_reported_and_nothing_is_saved() {
		$key = $this->make_cart();
		$this->stub_api( array( 'message' => 'bdcourier rate limited' ), 429 );

		$res = $this->post_check( $key );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'rate limited', $res['data']['message'] );
		$this->assertNull(
			$this->sync->courier_snapshot( $this->sync->get_row( $key ) ),
			'A failed lookup must not leave a phantom result behind.'
		);
		$this->assertStringContainsString( 'Check ratio', $this->render() );
	}

	public function test_the_check_targets_the_stored_phone_not_the_posted_one() {
		$key = $this->make_cart( '+8801712345678' );
		$seen = '';
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$seen ) {
			if ( false === strpos( $url, '/connect/courier' ) ) {
				return $pre;
			}
			$seen = $url;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $this->api_payload() ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}, 10, 3 );

		$_POST = array(
			'session_key' => $key,
			'phone'       => '+8809999999999', // A stale/forged DOM value.
			'nonce'       => wp_create_nonce( 'aisooq_abandoned' ),
		);
		$this->call_ajax( 'aisooq_abandoned_courier' );

		$this->assertStringContainsString( rawurlencode( '+8801712345678' ), $seen );
		$this->assertStringNotContainsString( '9999999999', $seen );
	}

	public function test_an_unknown_cart_is_rejected_before_any_paid_lookup() {
		$calls = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false !== strpos( $url, '/connect/courier' ) ) {
				$calls++;
			}
			return $pre;
		}, 10, 3 );

		$res = $this->post_check( 'sess_does_not_exist' );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 0, $calls );
	}

	public function test_the_breakdown_table_parses_into_the_expected_rows() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body><table><tbody>' . $this->render() . '</tbody></table></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		$xp    = new DOMXPath( $doc );
		$cells = $xp->query( "//table[contains(@class,'aisooq-courier-tbl')]/tbody/tr" );
		$this->assertSame( 2, $cells->length, 'One row per courier — the table must actually parse.' );

		// Row 1: Pathao — 20 sent, 18 delivered, 2 returned, 90%.
		$first = $xp->query( 'td', $cells->item( 0 ) );
		$this->assertSame( 'Pathao', trim( $first->item( 0 )->textContent ) );
		$this->assertSame( '20', trim( $first->item( 1 )->textContent ) );
		$this->assertSame( '18', trim( $first->item( 2 )->textContent ) );
		$this->assertSame( '2', trim( $first->item( 3 )->textContent ) );
		$this->assertSame( '90%', trim( $first->item( 4 )->textContent ) );

		// Footer reconciles with the platform's own summary.
		$foot = $xp->query( "//table[contains(@class,'aisooq-courier-tbl')]/tfoot/tr/td" );
		$this->assertSame( '25', trim( $foot->item( 1 )->textContent ) );
		$this->assertSame( '19', trim( $foot->item( 2 )->textContent ) );
		$this->assertSame( '6', trim( $foot->item( 3 )->textContent ) );
	}

	public function test_the_breakdown_starts_collapsed() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body><table><tbody>' . $this->render() . '</tbody></table></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		$xp    = new DOMXPath( $doc );
		$panel = $xp->query( "//div[contains(@class,'aisooq-courier-detail')]" )->item( 0 );
		$this->assertNotNull( $panel );
		$this->assertTrue( $panel->hasAttribute( 'hidden' ), 'The breakdown is opt-in — a dense list must not explode by default.' );
		$this->assertSame(
			'false',
			$xp->query( "//button[contains(@class,'aisooq-courier-toggle')]" )->item( 0 )->getAttribute( 'aria-expanded' )
		);
	}

	// ── Who is allowed to spend the merchant's money ────────────────────────

	public function test_a_shop_customer_cannot_trigger_a_paid_lookup() {
		$key   = $this->make_cart();
		$calls = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false !== strpos( $url, '/connect/courier' ) ) {
				$calls++;
			}
			return $pre;
		}, 10, 3 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array( 'session_key' => $key, 'nonce' => wp_create_nonce( 'aisooq_abandoned' ) );
		$res   = $this->call_ajax( 'aisooq_abandoned_courier' );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 0, $calls, 'Only shop managers may spend a BDCourier lookup.' );
	}

	public function test_a_request_without_a_valid_nonce_is_rejected() {
		$key   = $this->make_cart();
		$calls = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false !== strpos( $url, '/connect/courier' ) ) {
				$calls++;
			}
			return $pre;
		}, 10, 3 );

		$_POST = array( 'session_key' => $key, 'nonce' => 'forged' );
		$this->call_ajax( 'aisooq_abandoned_courier' );

		$this->assertSame( 0, $calls, 'A cross-site request must not be able to bill the merchant.' );
		$this->assertNull( $this->sync->courier_snapshot( $this->sync->get_row( $key ) ) );
	}

	// ── Freshness ───────────────────────────────────────────────────────────

	public function test_recheck_replaces_the_stored_result() {
		$key = $this->make_cart();
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );
		$this->assertStringContainsString( '76%', $this->render() );

		// The customer's record got worse since; the operator rechecks.
		remove_all_filters( 'pre_http_request' );
		$this->stub_api( array(
			'successRatio'    => 40.0,
			'totalParcel'     => 30,
			'successParcel'   => 12,
			'cancelledParcel' => 18,
			'couriers'        => array(
				array( 'slug' => 'pathao', 'name' => 'Pathao', 'total' => 30, 'success' => 12, 'cancelled' => 18, 'ratio' => 40.0 ),
			),
		) );
		$this->post_check( $key );

		$html = $this->render();
		$this->assertStringContainsString( '40%', $html );
		$this->assertStringNotContainsString( '76%', $html, 'A recheck must replace the old answer, not sit beside it.' );
	}

	public function test_a_corrected_phone_invalidates_the_earlier_lookup() {
		global $wpdb;
		$key = $this->make_cart( '+88017123' ); // Beacon caught a half-typed number.
		$this->stub_api( $this->api_payload() );
		$this->post_check( $key );
		$this->assertStringContainsString( '76%', $this->render() );

		// The shopper finishes typing and the beacon updates the row.
		$wpdb->update( // phpcs:ignore WordPress.DB
			AI_Sooq_Abandoned_Sync::table_name(),
			array( 'phone' => '+8801712345678' ),
			array( 'session_key' => $key )
		);

		$html = $this->render();
		$this->assertStringNotContainsString( '76%', $html, 'That ratio described a different number.' );
		$this->assertStringContainsString( 'Check ratio', $html );
	}

	public function test_a_check_does_not_reorder_the_worklist() {
		$older = $this->make_cart( '+8801712345678' );
		global $wpdb;
		$newer = $this->make_cart( '+8801811111111' );
		$wpdb->update( // phpcs:ignore WordPress.DB
			AI_Sooq_Abandoned_Sync::table_name(),
			array( 'updated_at' => '2026-08-05 09:00:00' ),
			array( 'session_key' => $newer )
		);

		$this->stub_api( $this->api_payload() );
		$this->post_check( $older );

		// The list is ordered by updated_at DESC; reading a ratio is not activity.
		$html = $this->render();
		$this->assertLessThan(
			strpos( $html, $older ),
			strpos( $html, $newer ),
			'Checking a ratio must not push a cart to the top of the worklist.'
		);
	}
}
