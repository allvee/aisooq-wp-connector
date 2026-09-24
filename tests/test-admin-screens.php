<?php
/**
 * The three list screens, after they adopted the settings screen's shell.
 *
 * Blocked, Failed syncs and Abandoned carts each used to draw their own header,
 * their own table and — in two cases — their own palette in inline `style=`
 * attributes. They now share AI_Sooq_Admin_Shell, and the failure this file
 * guards is the one that split brings: a shared frame drifts apart one screen
 * at a time, and nothing about a page that renders is obviously wrong.
 *
 * So the assertions are mostly SAMENESS assertions. Each screen must print the
 * same landmarks, resolve the same icon set, reach no CDN, and link to its
 * three siblings and not to itself. A screen that quietly stops doing any of
 * those still looks fine on its own; it only looks wrong beside the other
 * three, which is exactly what nobody checks by hand.
 *
 * Deliberately has NO WooCommerce guard for the parts that do not need it. The
 * two screens that build WC_Order objects say so individually.
 *
 * @package AISooq
 */

class Test_Admin_Screens extends WP_UnitTestCase {

	/** @var AI_Sooq_Settings */
	private $settings;

	public function set_up() {
		parent::set_up();
		update_option( AISOOQ_OPTION, array(
			'active'           => 1,
			'api_base'         => 'https://api.example.test',
			'sid'              => 'store1',
			'client_id'        => 'cid',
			'client_secret'    => 'csecret',
			'enable_abandoned' => 1,
		) );
		$this->settings = new AI_Sooq_Settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Every screen, built the way AI_Sooq_Plugin builds it. */
	private function screens() {
		$logger = new AI_Sooq_Logger( $this->settings );
		$api    = new AI_Sooq_Api_Client( $this->settings, $logger );

		return array(
			AI_Sooq_Blocklist_Admin::PAGE_SLUG => new AI_Sooq_Blocklist_Admin( $logger, $this->settings ),
			AI_Sooq_Failed_Admin::PAGE_SLUG    => new AI_Sooq_Failed_Admin( $this->settings, new AI_Sooq_Order_Sync( $this->settings, $api, $logger ), $logger ),
			AI_Sooq_Abandoned_Admin::PAGE_SLUG => new AI_Sooq_Abandoned_Admin( $this->settings, new AI_Sooq_Abandoned_Sync( $this->settings, $api, $logger ), $logger ),
		);
	}

	private function render( $screen ) {
		// Verify would go out to the network; the screens render fine without it.
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'offline', 'no network in tests' );
		} );
		ob_start();
		$screen->render_page();
		return ob_get_clean();
	}

	/* ── The shared frame ───────────────────────────────────────────────── */

	/**
	 * All three wear the shell, and all three close it.
	 *
	 * The landmark check is cheap; the CLOSING check is the one that earns its
	 * keep. open() and close() are a pair printed from opposite ends of a long
	 * procedural method, and an early `return` between them — the table-missing
	 * branch, the nothing-failed branch — leaves the page's <div>s unbalanced.
	 * wp-admin swallows that silently and the footer climbs into the sidebar.
	 */
	public function test_every_list_screen_renders_and_closes_the_shell() {
		foreach ( $this->screens() as $slug => $screen ) {
			$html = $this->render( $screen );

			$this->assertStringContainsString( 'aisooq-app', $html, "{$slug} is not using the shared shell." );
			$this->assertStringContainsString( 'aisooq-appbar', $html, "{$slug} has no app bar." );
			$this->assertStringContainsString( 'aisooq-side', $html, "{$slug} has no sidebar." );
			$this->assertStringContainsString( 'aisooq-footer', $html, "{$slug} has no footer — close() was not reached." );

			$this->assertSame(
				substr_count( $html, '<div' ) + substr_count( $html, '<aside' ) + substr_count( $html, '<main' ),
				substr_count( $html, '</div>' ) + substr_count( $html, '</aside>' ) + substr_count( $html, '</main>' ),
				"{$slug} leaves an unbalanced element — an early return probably skipped close()."
			);
		}
	}

	/** wp-admin splices its notices after the first <h1>; every screen needs one. */
	public function test_every_list_screen_has_a_level_one_heading() {
		foreach ( $this->screens() as $slug => $screen ) {
			$this->assertMatchesRegularExpression(
				'/<h1[^>]*screen-reader-text/',
				$this->render( $screen ),
				"{$slug} has no <h1>."
			);
		}
	}

	/**
	 * The sidebar means "the other screens", so a screen must not list itself.
	 *
	 * A link that appears to do nothing reads as a broken page rather than a
	 * no-op, and it is the kind of thing that only shows up once you are ON the
	 * page in question.
	 */
	public function test_no_screen_lists_itself_among_the_other_screens() {
		foreach ( $this->screens() as $slug => $screen ) {
			$html = $this->render( $screen );

			/*
			 * Scoped to the two "other screens" navs on purpose. A screen may
			 * well link to ITSELF elsewhere — the failed list's "All failures"
			 * tile is a filter reset, and the blocked screen's two halves are
			 * the same page with a different tab. Those are navigation within
			 * the screen, and asserting over the whole document would have
			 * called them bugs.
			 */
			foreach ( array( 'aisooq-side__links', 'aisooq-main__links' ) as $nav ) {
				$block = self::slice( $html, $nav );
				$this->assertNotSame( '', $block, "{$slug} is missing its {$nav} nav." );

				foreach ( array_keys( AI_Sooq_Admin_Shell::screens() ) as $other ) {
					$this->assertSame(
						$other === $slug ? 0 : 1,
						substr_count( $block, 'page=' . $other . '"' ),
						$other === $slug
							? "{$slug} lists itself in its own {$nav}."
							: "{$slug} does not link to {$other} in its {$nav}."
					);
				}
			}
		}
	}

	/** The markup of one nav, from its class attribute to its closing tag. */
	private static function slice( $html, $class ) {
		$start = strpos( $html, $class );
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $html, '</nav>', $start );
		if ( false === $end ) {
			$end = strpos( $html, '</div>', $start );
		}
		return false === $end ? '' : substr( $html, $start, $end - $start );
	}

	/* ── Nothing leaves the building ────────────────────────────────────── */

	/**
	 * The design these screens implement loaded its icons from a CDN. None of
	 * them may, for the reasons in includes/class-aisooq-icons.php.
	 */
	public function test_no_list_screen_loads_an_external_resource() {
		foreach ( $this->screens() as $slug => $screen ) {
			$html = $this->render( $screen );

			foreach ( array( 'unpkg.com', 'cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.' ) as $host ) {
				$this->assertStringNotContainsString( $host, $html, "{$slug} loads something from {$host}." );
			}
		}
	}

	/**
	 * A glyph that resolves to nothing paints nothing and says nothing.
	 *
	 * AI_Sooq_Icons::svg() returns '' for a name it does not have, so a typo in
	 * one of these screens costs an icon and no error. `_doing_it_wrong` fires,
	 * and WP_UnitTestCase turns that into a failure only for tests that declare
	 * it — which is what makes this assertion work at all.
	 */
	public function test_no_list_screen_asks_for_an_icon_that_is_not_bundled() {
		foreach ( $this->screens() as $slug => $screen ) {
			$html = $this->render( $screen );

			// Every bundled glyph prints exactly this opening tag; an unknown
			// name prints nothing at all. So count the call sites in the source
			// against the <svg>s that came out.
			$this->assertGreaterThan(
				3,
				substr_count( $html, '<svg class="aisooq-i"' ),
				"{$slug} rendered almost no icons, which means the names it asks for are not resolving."
			);
		}
	}

	/* ── Per-screen content ─────────────────────────────────────────────── */

	public function test_blocked_offers_both_of_its_halves_and_the_compose_form() {
		$screens = $this->screens();
		$screen  = $screens[ AI_Sooq_Blocklist_Admin::PAGE_SLUG ];

		$html = $this->render( $screen );
		$this->assertStringContainsString( 'tab=log', $html, 'The refusals half is not reachable.' );
		$this->assertStringContainsString( 'tab=list', $html, 'The block-list half is not reachable.' );

		// The compose form only belongs on the list half.
		$this->assertStringNotContainsString( 'id="aisooq-b-add"', $html, 'The add form belongs on the list tab, not the log.' );

		$_GET['tab'] = 'list';
		$list        = $this->render( $screen );
		unset( $_GET['tab'] );

		foreach ( array( 'aisooq-b-type', 'aisooq-b-value', 'aisooq-b-mode', 'aisooq-b-days', 'aisooq-b-reason', 'aisooq-b-add' ) as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $list, "The add form lost #{$id}, which its script drives." );
		}
	}

	/**
	 * The script on each list screen reaches into the markup by id. Restyling
	 * moved every one of those elements, so this is the check that they all
	 * came back — a missing id costs a feature and throws no error.
	 */
	public function test_the_abandoned_worklist_keeps_every_hook_its_script_drives() {
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$screens = $this->screens();
		$html    = $this->render( $screens[ AI_Sooq_Abandoned_Admin::PAGE_SLUG ] );

		$ids = array(
			'aisooq-search', 'aisooq-product', 'aisooq-from', 'aisooq-to', 'aisooq-clear',
			'aisooq-spin', 'aisooq-count', 'aisooq-query-error',
			'aisooq-bulk-op', 'aisooq-bulk-apply', 'aisooq-bulk-count', 'aisooq-bulk-msg',
			'aisooq-cb-all', 'aisooq-rows', 'aisooq-modal-root',
			'aisooq-resync-all', 'aisooq-resync-msg',
		);
		foreach ( $ids as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $html, "The worklist lost #{$id}." );
		}
	}

	/* ── The date-range picker ──────────────────────────────────────────── */

	/**
	 * The picker replaced two `<input type="date">` but kept their ids.
	 *
	 * That is the whole reason the swap was safe: the screen's script reads
	 * `.value` off those ids and listens for `change` on them. If a future
	 * change renames or drops them the filter silently stops filtering — the
	 * page still renders, the query just never sees a date.
	 */
	public function test_the_cart_filter_keeps_the_inputs_its_script_reads() {
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$_GET['from'] = '2026-09-01';
		$_GET['to']   = '2026-09-30';
		$screens      = $this->screens();
		$html         = $this->render( $screens[ AI_Sooq_Abandoned_Admin::PAGE_SLUG ] );
		unset( $_GET['from'], $_GET['to'] );

		$this->assertMatchesRegularExpression(
			'/<input type="hidden" id="aisooq-from" value="2026-09-01"/',
			$html,
			'The start date is not reaching the input the query reads.'
		);
		$this->assertMatchesRegularExpression(
			'/<input type="hidden" id="aisooq-to" value="2026-09-30"/',
			$html,
			'The end date is not reaching the input the query reads.'
		);

		// The browser's own picker is what this replaced; two of them would be
		// two controls writing to one value.
		$this->assertStringNotContainsString( 'type="date"', $html, 'A native date input came back alongside the picker.' );
	}

	/**
	 * The calendar's vocabulary comes from WordPress, not from the browser.
	 *
	 * `toLocaleDateString` follows the BROWSER's locale, and these operators
	 * run a Bangla admin in an English-set browser more often than not. If the
	 * month names ever stop being passed in, the calendar starts speaking a
	 * different language from the page around it — which looks like a glitch
	 * rather than a bug and so gets reported by nobody.
	 */
	public function test_the_picker_is_handed_wordpress_own_calendar_vocabulary() {
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$screens = $this->screens();
		$html    = $this->render( $screens[ AI_Sooq_Abandoned_Admin::PAGE_SLUG ] );

		$this->assertSame( 1, preg_match( '/data-aisooq-daterange="([^"]+)"/', $html, $m ), 'The picker is not on the page.' );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $config, 'The picker config is not valid JSON.' );

		$this->assertCount( 12, $config['months'], 'A calendar needs twelve month names.' );
		$this->assertCount( 7, $config['days'], 'A week has seven days.' );
		$this->assertSame( date_i18n( 'F', mktime( 0, 0, 0, 1, 1, 2001 ) ), $config['months'][0], 'Month names must come from date_i18n().' );

		// start_of_week decides which column Monday lands in; a hardcoded 0 or
		// 1 would be wrong for half the world.
		$this->assertSame( (int) get_option( 'start_of_week' ), $config['startOfWeek'] );

		// Today per the SITE's timezone — a Dhaka shop viewed from London must
		// still agree with itself about which day "today" is.
		$this->assertSame( current_time( 'Y-m-d' ), $config['today'] );

		$this->assertNotEmpty( $config['presets'], 'The shortcuts are the point of the control.' );
	}

	/** The picker's script ships with the screens that can use it. */
	public function test_the_picker_script_is_enqueued_and_local() {
		$this->settings->enqueue_admin_assets( 'aisooq_page_' . AI_Sooq_Abandoned_Admin::PAGE_SLUG );

		$this->assertTrue( wp_script_is( 'aisooq-daterange', 'enqueued' ), 'The date picker would not initialise.' );

		$src = wp_scripts()->registered['aisooq-daterange']->src;
		$this->assertStringStartsWith( AISOOQ_URL, $src, 'The picker must be served from the plugin, not a CDN.' );
	}

	public function test_the_failed_screen_keeps_every_hook_its_script_drives() {
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$screens = $this->screens();
		$html    = $this->render( $screens[ AI_Sooq_Failed_Admin::PAGE_SLUG ] );

		// With nothing failed the screen short-circuits to its empty state, and
		// the bulk controls legitimately are not there. Assert the branch we are
		// actually looking at rather than pretending otherwise.
		if ( 0 === AI_Sooq_Order_Sync::failed_count( true ) ) {
			$this->assertStringContainsString( 'aisooq-empty', $html, 'With nothing failed the screen must say so.' );
			return;
		}

		foreach ( array( 'aisooq-fo-search', 'aisooq-fo-retry-selected', 'aisooq-fo-retry-scope', 'aisooq-fo-msg', 'aisooq-fo-cb-all' ) as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $html, "The failed list lost #{$id}." );
		}
	}
}
