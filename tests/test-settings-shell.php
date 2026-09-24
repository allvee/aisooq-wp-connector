<?php
/**
 * The settings screen's chrome, and the icon set it is drawn with.
 *
 * Two failure modes motivate this file, and both are silent on the machine
 * that introduces them:
 *
 *   1. A MISTYPED ICON NAME paints nothing. AI_Sooq_Icons::svg() returns '' for
 *      a name it does not have, so the nav item keeps its label and quietly
 *      loses its glyph. Nobody reviewing a diff notices a missing 16px square.
 *      This is the same argument tests/test-palette-drift.php makes about an
 *      unseeded custom property, and the same remedy: assert the reference.
 *
 *   2. AN EXTERNAL ASSET works perfectly for whoever adds it. The design this
 *      screen implements pulled its icons from a CDN, and a copy-paste of that
 *      would keep working on every developer's laptop while breaking on
 *      intranet installs, leaking every merchant's admin IP, and failing a
 *      WordPress.org review. It has to be asserted, because it cannot be seen.
 *
 * Deliberately has NO WooCommerce guard, for the reason test-packaging.php
 * gives: none of this has anything to do with WooCommerce, and a test that
 * skips is a test that stays green while the thing it guards walks back in.
 *
 * @package AISooq
 */

class Test_Settings_Shell extends WP_UnitTestCase {

	/** @var AI_Sooq_Settings */
	private $settings;

	public function set_up() {
		parent::set_up();
		update_option( AISOOQ_OPTION, array(
			'active'        => 1,
			'api_base'      => 'https://api.example.test',
			'sid'           => 'store1',
			'client_id'     => 'cid',
			'client_secret' => 'csecret',
		) );
		$this->settings = new AI_Sooq_Settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render() {
		// Verify would go out to the network; the page renders fine without it.
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'offline', 'no network in tests' );
		} );
		ob_start();
		$this->settings->render_page();
		return ob_get_clean();
	}

	/* ── The icon set ───────────────────────────────────────────────────── */

	/**
	 * Every glyph the source asks for by name must exist.
	 *
	 * Reads the source rather than the rendered page on purpose: several icons
	 * only appear in a branch this fixture does not reach (the permissions
	 * grid needs a connected store, the fraud layers need a live platform), and
	 * a test that only covers the rendered page would let exactly those rot.
	 */
	public function test_every_icon_the_source_asks_for_is_bundled() {
		$missing = array();
		$seen    = 0;

		foreach ( (array) glob( AISOOQ_DIR . 'includes/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file );
			if ( ! preg_match_all( "/AI_Sooq_Icons::svg\(\s*'([^']+)'/", $src, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $name ) {
				++$seen;
				if ( ! AI_Sooq_Icons::has( $name ) ) {
					$missing[] = basename( $file ) . '  "' . $name . '"';
				}
			}
		}

		/*
		 * A regex that stops matching turns this into a test that passes
		 * without asserting anything — the exact failure it exists to catch,
		 * one level up. If the call sites are ever reformatted past what the
		 * pattern above recognises, fail here rather than go quiet.
		 */
		$this->assertGreaterThan(
			// Only call sites passing a string LITERAL are visible here; the
			// ones passing `$section['icon']` and friends are covered by the
			// table tests below. Ten is comfortably under the real count and
			// comfortably over zero.
			10,
			$seen,
			'The icon-reference scan found almost nothing, so its pattern no longer matches the source.'
		);

		$this->assertSame(
			array(),
			$missing,
			"These icon names are not in AI_Sooq_Icons::PATHS, so they paint nothing:\n  "
			. implode( "\n  ", $missing )
		);
	}

	/**
	 * The icon tables that map a value to a glyph must resolve too.
	 *
	 * `sections()` and the permission map name their icons in an array rather
	 * than at a `::svg()` call site, so the check above cannot see them.
	 */
	public function test_every_section_names_a_bundled_icon() {
		$method = new ReflectionMethod( 'AI_Sooq_Settings', 'sections' );
		$method->setAccessible( true );

		foreach ( $method->invoke( $this->settings ) as $key => $section ) {
			$this->assertTrue(
				AI_Sooq_Icons::has( $section['icon'] ),
				"Section {$key} names icon \"{$section['icon']}\", which is not bundled."
			);
		}
	}

	public function test_every_screen_names_a_bundled_icon_and_a_real_page() {
		foreach ( AI_Sooq_Admin_Shell::screens() as $slug => $screen ) {
			$this->assertTrue( AI_Sooq_Icons::has( $screen['icon'] ), "Unbundled icon: {$screen['icon']}" );
			$this->assertNotSame( '', trim( $screen['label'] ), 'A nav entry with no label is not a link.' );
		}

		// Every screen in the registry must be one that actually exists, or the
		// sidebar sends an operator to an empty admin page.
		$this->assertSame(
			array(
				AI_Sooq_Settings::PAGE_SLUG,
				AI_Sooq_Blocklist_Admin::PAGE_SLUG,
				AI_Sooq_Failed_Admin::PAGE_SLUG,
				AI_Sooq_Abandoned_Admin::PAGE_SLUG,
			),
			array_keys( AI_Sooq_Admin_Shell::screens() )
		);
	}

	/**
	 * A screen must never link to itself.
	 *
	 * The sidebar's second nav means "the others". Listing the current screen
	 * there gives an operator a link that appears to do nothing, which reads as
	 * a broken page rather than a no-op.
	 */
	public function test_a_screen_never_lists_itself_among_the_others() {
		foreach ( array_keys( AI_Sooq_Admin_Shell::screens() ) as $slug ) {
			$others = AI_Sooq_Admin_Shell::other_screens( $slug );

			$this->assertCount( 3, $others, "{$slug} should see exactly the other three screens." );
			foreach ( $others as $link ) {
				$this->assertStringNotContainsString(
					'page=' . $slug,
					$link['url'],
					"{$slug} links to itself in its own sidebar."
				);
			}
		}
	}

	/** An unknown name must be loud, not quietly skipped — see the header. */
	public function test_an_unknown_icon_complains_rather_than_rendering_nothing() {
		$this->setExpectedIncorrectUsage( 'AI_Sooq_Icons::svg' );

		$this->assertSame( '', AI_Sooq_Icons::svg( 'no-such-glyph' ) );
	}

	/**
	 * An icon beside its own visible label must not be announced, and one
	 * standing alone must be. Getting this backwards is how a screen reader
	 * ends up saying "link, Connection" for every item in a nav.
	 */
	public function test_an_icon_is_decorative_unless_it_is_given_a_label() {
		$plain = AI_Sooq_Icons::svg( 'link' );
		$this->assertStringContainsString( 'aria-hidden="true"', $plain );
		$this->assertStringNotContainsString( 'role="img"', $plain );

		$named = AI_Sooq_Icons::svg( 'link', array( 'label' => 'Connection' ) );
		$this->assertStringContainsString( 'role="img"', $named );
		$this->assertStringContainsString( 'aria-label="Connection"', $named );
		$this->assertStringNotContainsString( 'aria-hidden', $named );
	}

	public function test_icon_size_is_applied_to_both_axes() {
		$svg = AI_Sooq_Icons::svg( 'gear', array( 'size' => 20 ) );
		$this->assertStringContainsString( 'width="20"', $svg );
		$this->assertStringContainsString( 'height="20"', $svg );
	}

	/* ── Nothing leaves the building ────────────────────────────────────── */

	/**
	 * The screen must not fetch anything from a third party.
	 *
	 * The design document this implements loaded Phosphor from unpkg and a
	 * Google font, and both would have survived review by looking fine.
	 */
	public function test_the_page_loads_no_external_resource() {
		$html = $this->render();

		foreach ( array( 'unpkg.com', 'cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.' ) as $host ) {
			$this->assertStringNotContainsString(
				$host,
				$html,
				"The settings screen must not load anything from {$host} — see includes/class-aisooq-icons.php."
			);
		}
	}

	/** The stylesheets it does load are this plugin's own, and local. */
	public function test_every_aisooq_screen_enqueues_both_local_stylesheets() {
		foreach ( array_keys( AI_Sooq_Admin_Shell::screens() ) as $slug ) {
			wp_dequeue_style( 'aisooq-admin' );
			wp_dequeue_style( 'aisooq-app' );

			// The settings screen is the top-level menu entry; the rest hang
			// off it as submenus, so their hooks are shaped differently.
			$hook = AI_Sooq_Settings::PAGE_SLUG === $slug
				? 'toplevel_page_' . $slug
				: 'aisooq_page_' . $slug;
			$this->settings->enqueue_admin_assets( $hook );

			foreach ( array( 'aisooq-admin', 'aisooq-app' ) as $handle ) {
				$this->assertTrue(
					wp_style_is( $handle, 'enqueued' ),
					"{$handle} is not enqueued on {$slug} — it would render unstyled."
				);
			}
		}
	}

	/** ...and on nothing else. */
	public function test_no_stylesheet_loads_on_an_unrelated_screen() {
		wp_dequeue_style( 'aisooq-admin' );
		wp_dequeue_style( 'aisooq-app' );

		$this->settings->enqueue_admin_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'aisooq-admin', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'aisooq-app', 'enqueued' ) );
	}

	/* ── The shell ──────────────────────────────────────────────────────── */

	public function test_the_shell_renders_its_landmarks() {
		$html = $this->render();

		$this->assertStringContainsString( 'class="wrap aisooq aisooq-app"', $html, 'The layout root carries the class the stylesheet is scoped to.' );
		$this->assertStringContainsString( 'aisooq-appbar', $html );
		$this->assertStringContainsString( 'aisooq-side', $html );
		$this->assertStringContainsString( 'aisooq-footer', $html );
	}

	/**
	 * Exactly one tablist, at every width.
	 *
	 * The first attempt rendered a second, `aria-hidden` pill strip for phones
	 * and hid the sidebar with `display:none` — which also removed it from the
	 * focus order, leaving no keyboard route to another tab on a phone. The
	 * fix was to render once and reflow with CSS, and this is what stops a
	 * future "just add a mobile nav" from bringing the bug back.
	 */
	public function test_there_is_exactly_one_tablist_and_one_control_per_panel() {
		$html   = $this->render();
		$method = new ReflectionMethod( 'AI_Sooq_Settings', 'sections' );
		$method->setAccessible( true );
		$sections = $method->invoke( $this->settings );

		$this->assertSame( 1, substr_count( $html, 'role="tablist"' ), 'Two tablists would both claim the same panels.' );
		$this->assertSame(
			count( $sections ),
			substr_count( $html, 'role="tab"' ),
			'There must be one tab control per section — no more, no fewer.'
		);

		foreach ( array_keys( $sections ) as $key ) {
			$this->assertSame(
				1,
				substr_count( $html, 'aria-controls="aisooq-panel-' . $key . '"' ),
				"Panel {$key} is claimed by more than one control."
			);
		}
	}

	/** One nav means one search field — duplicate ids are their own bug. */
	public function test_the_search_field_is_rendered_once() {
		$this->assertSame( 1, substr_count( $this->render(), 'id="aisooq-find"' ) );
	}

	/**
	 * wp-admin splices its notices after the first <h1> and screen-reader users
	 * navigate by heading. The app bar is not a heading, so one has to exist
	 * for those two jobs even though nothing shows it.
	 */
	public function test_the_page_still_has_a_level_one_heading() {
		$this->assertMatchesRegularExpression( '/<h1[^>]*screen-reader-text/', $this->render() );
	}

	/**
	 * The unsaved bar's Save must be a proxy, never a second submit.
	 *
	 * test-settings-page.php already counts the submit's NAME; this asserts the
	 * other half, which is that the button in the bar does not submit at all.
	 */
	public function test_the_unsaved_bar_save_is_not_a_second_submit() {
		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/<button type="button"[^>]*id="aisooq-save-proxy"/',
			$html,
			'The unsaved bar must not contain a submit button.'
		);
		$this->assertStringContainsString( 'id="aisooq-unsaved"', $html );
		// Hidden on load: a page that claims unsaved work the moment it renders
		// teaches people to ignore the warning.
		$this->assertMatchesRegularExpression( '/id="aisooq-unsaved"[^>]*hidden/', $html );
	}

	/** Each panel carries its own heading, so a no-JS page reads as six sections. */
	public function test_every_panel_states_its_own_name() {
		$html   = $this->render();
		$method = new ReflectionMethod( 'AI_Sooq_Settings', 'sections' );
		$method->setAccessible( true );

		foreach ( $method->invoke( $this->settings ) as $key => $section ) {
			$start = strpos( $html, 'id="aisooq-panel-' . $key . '"' );
			$this->assertNotFalse( $start, "No panel: {$key}" );
			$end   = strpos( $html, '<section class="aisooq-panel', $start + 1 );
			$panel = false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start );

			$this->assertStringContainsString(
				'<h2 class="aisooq-tabtitle">',
				$panel,
				"Panel {$key} has no heading, so it is anonymous when the tab script does not run."
			);
		}
	}

	/** The secret is never echoed, no matter which surface renders it. */
	public function test_the_shell_never_echoes_the_stored_secret() {
		$this->assertStringNotContainsString( 'csecret', $this->render() );
	}
}
