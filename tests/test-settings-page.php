<?php
/**
 * The settings screen after it was split into tabbed sections.
 *
 * A reorganisation's characteristic failure is not a crash — it's a field that
 * quietly stops being rendered. Because this is one form that posts every input
 * on every save, a dropped field doesn't just become uneditable: the next save
 * writes its default over whatever the merchant had set. So the load-bearing
 * test here is "every setting still has an input", derived from defaults()
 * rather than from a list someone has to remember to update.
 *
 * @package AISooq
 */

class Test_Settings_Page extends WP_UnitTestCase {

	/** @var AI_Sooq_Settings */
	private $settings;

	/**
	 * Keys that legitimately have no input of their own.
	 *
	 * Anything not listed here MUST be rendered. A new setting added to
	 * defaults() fails this test until it is either given a control or
	 * explicitly justified below — which is the point.
	 */
	private function not_rendered() {
		return array(
			// Written by maybe_save() from the three granular switches; kept only
			// so a pre-split install still resolves a sane value.
			'enable_catalog_sync',
			'catalog_sync_dir',
			// Rendered per WooCommerce shipping method — nothing to show on a
			// store with no shipping zones configured, as in this fixture.
			'shipping_map',
		);
	}

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

	// ── Nothing was lost ────────────────────────────────────────────────────

	public function test_every_setting_still_has_an_input() {
		$html    = $this->render();
		$missing = array();

		foreach ( array_keys( $this->settings->defaults() ) as $key ) {
			if ( in_array( $key, $this->not_rendered(), true ) ) {
				continue;
			}
			// order_statuses posts as an array.
			$needles = array( 'aisooq[' . $key . ']', 'aisooq[' . $key . '][]' );
			$found   = false;
			foreach ( $needles as $n ) {
				if ( false !== strpos( $html, 'name="' . $n . '"' ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These settings have no control on the page, so the next save overwrites them with defaults: ' . implode( ', ', $missing )
		);
	}

	public function test_the_secret_field_is_never_prefilled_with_the_secret() {
		$html = $this->render();
		$this->assertStringNotContainsString( 'csecret', $html, 'The stored client secret must not be echoed into the page source.' );
		$this->assertStringContainsString( 'name="aisooq[client_secret]"', $html );
	}

	// ── The tab layout ──────────────────────────────────────────────────────

	public function test_every_section_renders_a_tab_and_a_panel() {
		$html = $this->render();
		$secs = new ReflectionMethod( 'AI_Sooq_Settings', 'sections' );
		$secs->setAccessible( true );

		foreach ( array_keys( $secs->invoke( $this->settings ) ) as $key ) {
			$this->assertStringContainsString( 'id="aisooq-tab-' . $key . '"', $html, "Missing tab: {$key}" );
			$this->assertStringContainsString( 'id="aisooq-panel-' . $key . '"', $html, "Missing panel: {$key}" );
			$this->assertStringContainsString( 'aria-controls="aisooq-panel-' . $key . '"', $html, "Tab {$key} is not wired to its panel." );
		}
	}

	public function test_no_panel_is_hidden_in_the_markup() {
		// Tabs are a view concern applied by script. If the server ever emitted
		// a hidden panel, a no-JS operator would lose those settings entirely —
		// and, worse, saving would reset them.
		$html = $this->render();
		$this->assertSame(
			0,
			preg_match_all( '/<section class="aisooq-panel[^>]*\bhidden\b/', $html ),
			'Panels must be hidden by the script, never by the server.'
		);
	}

	public function test_the_form_carries_one_nonce_and_one_save_button() {
		$html = $this->render();
		$this->assertSame( 1, substr_count( $html, 'name="aisooq_save"' ), 'Two submit buttons would double-post the form.' );
		$this->assertStringContainsString( 'id="aisooq-settings-form"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	// ── The new courier setting ─────────────────────────────────────────────

	public function test_the_auto_courier_switch_is_on_the_fraud_panel_and_states_the_cost() {
		$html  = $this->render();
		$panel = $this->panel( $html, 'fraud' );

		$this->assertStringContainsString( 'name="aisooq[auto_courier_check]"', $panel, 'Courier auto-check belongs with the other courier settings.' );
		$this->assertStringContainsString( 'billed lookup', $panel, 'A setting that spends money must say so where it is switched on.' );
	}

	public function test_a_dependent_field_declares_what_it_depends_on() {
		$html = $this->render();
		$this->assertStringContainsString( 'data-requires="enable_fraud"', $html );
		$this->assertStringContainsString( 'data-requires="enable_orders"', $html );
		$this->assertStringContainsString( 'data-requires="enable_abandoned"', $html );
	}

	// ── Fields landed in the right section ──────────────────────────────────

	/** @dataProvider placements */
	public function test_a_setting_lives_in_the_section_an_operator_would_look_in( $section, $field ) {
		$this->assertStringContainsString(
			'name="aisooq[' . $field . ']"',
			$this->panel( $this->render(), $section ),
			"{$field} is not on the {$section} panel."
		);
	}

	public function placements() {
		return array(
			'credentials with the connection' => array( 'connection', 'client_id' ),
			'master switch with the connection' => array( 'connection', 'active' ),
			'order push with sync'            => array( 'sync', 'enable_orders' ),
			'idle threshold with sync'        => array( 'sync', 'abandoned_idle_min' ),
			'writeback with sync'             => array( 'sync', 'allow_status_writeback' ),
			'product direction with sync'     => array( 'sync', 'product_sync_dir' ),
			'fraud action with fraud'         => array( 'fraud', 'fraud_action' ),
			'courier gate with fraud'         => array( 'fraud', 'courier_min_ratio' ),
			'support numbers with fraud'      => array( 'fraud', 'support_whatsapp' ),
			'blocked copy with messages'      => array( 'messages', 'msg_courier' ),
			'debug log with advanced'         => array( 'advanced', 'debug_log' ),
		);
	}

	/** Slice one panel out of the page so a placement assertion means something. */
	private function panel( $html, $key ) {
		$start = strpos( $html, 'id="aisooq-panel-' . $key . '"' );
		$this->assertNotFalse( $start, "No panel: {$key}" );
		$end = strpos( $html, '<section class="aisooq-panel', $start + 1 );
		return false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start );
	}
}
