<?php
/**
 * The update channel.
 *
 * This is the one part of the plugin that causes a zip to be downloaded and
 * executed, so most of these tests are about REFUSING things. The rule the
 * whole design rests on: the manifest chooses which release, never where the
 * code comes from — that URL is rebuilt from hard-coded parts. A tampered
 * manifest must at worst point at a different genuine release of this plugin.
 *
 * @package AISooq
 */

class Test_Self_Update extends WP_UnitTestCase {

	/** @var AI_Sooq_Updater */
	private $updater;

	public function set_up() {
		parent::set_up();
		$this->updater = AI_Sooq_Updater::instance();
		$this->updater->forget();
		update_option( AISOOQ_OPTION, array( 'enable_updates' => 1 ) );
		// manifest() will not reach out on a front-end request; these tests are
		// not admin requests, so open the gate explicitly.
		add_filter( 'aisooq_update_may_fetch', '__return_true' );
		// …and then make sure nothing actually leaves the machine. Every test
		// here stubs what it needs; anything unstubbed must fail loudly rather
		// than quietly asking GitHub what the latest release is, which would
		// make the suite depend on what happens to be published.
		add_filter( 'pre_http_request', array( $this, 'block_unstubbed_http' ), 99, 3 );
	}

	/**
	 * Anything reaching this filter was not stubbed by its test.
	 *
	 * `pre_http_request` CHAINS — this runs after the per-test stubs and is
	 * handed whatever they returned, so it must pass a handled request straight
	 * through. Only an untouched `false` means nobody answered.
	 */
	public function block_unstubbed_http( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'aisooq_test_no_network', 'Unstubbed HTTP request in a unit test: ' . $url );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_unstubbed_http' ), 99 );
		remove_filter( 'aisooq_update_may_fetch', '__return_true' );
		$this->updater->forget();
		parent::tear_down();
	}

	/** Stub the GitHub releases endpoint. */
	private function stub_release( array $release, $status = 200 ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $release, $status ) {
			if ( false === strpos( $url, 'api.github.com' ) ) {
				return $pre;
			}
			return array(
				'headers'  => array( 'etag' => '"abc123"' ),
				'body'     => wp_json_encode( $release ),
				'response' => array( 'code' => $status, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}, 10, 3 );
	}

	private function good_release( $version = '9.9.9' ) {
		$file = 'aisooq-connector-' . $version . '.zip';
		return array(
			'tag_name'   => 'v' . $version,
			'draft'      => false,
			'prerelease' => false,
			'body'       => 'Notes.',
			'assets'     => array(
				array(
					'name'                 => $file,
					'browser_download_url' => 'https://github.com/allvee/aisooq-wp-connector/releases/download/v' . $version . '/' . $file,
				),
			),
		);
	}

	// ── The header ──────────────────────────────────────────────────────────

	/**
	 * Without `Update URI`, core matches this plugin to WordPress.org by folder
	 * name — so an unrelated wp.org plugin sharing the slug could be offered as
	 * an update and overwrite it.
	 */
	public function test_the_plugin_declares_an_update_uri() {
		$main = (string) file_get_contents( AISOOQ_DIR . 'aisooq-connector.php' );
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Update URI:\s*https:\/\/github\.com\/allvee\/aisooq-wp-connector\s*$/m',
			$main,
			'The Update URI header is what stops wordpress.org answering for this slug.'
		);
	}

	// ── Accepting a good release ────────────────────────────────────────────

	public function test_a_valid_release_is_offered() {
		$this->stub_release( $this->good_release( '9.9.9' ) );
		$m = $this->updater->manifest( true );

		$this->assertIsArray( $m );
		$this->assertSame( '9.9.9', $m['version'] );
		$this->assertSame(
			'https://github.com/allvee/aisooq-wp-connector/releases/download/v9.9.9/aisooq-connector-9.9.9.zip',
			$m['package']
		);
	}

	public function test_the_offer_pins_the_supported_floors() {
		$this->stub_release( $this->good_release( '9.9.9' ) );
		$offer = $this->updater->update( false, array(), AISOOQ_BASENAME );

		$this->assertIsArray( $offer );
		// Omitting these lets WP_Automatic_Updater treat the release as
		// compatible with any PHP, so a future version that raises the floor
		// could install itself on an old one and fatal the store.
		$this->assertSame( '7.4', $offer['requires_php'] );
		$this->assertSame( '5.8', $offer['requires'] );
		$this->assertSame( '9.9.9', $offer['new_version'] );
	}

	public function test_an_older_or_equal_release_is_not_offered() {
		$this->stub_release( $this->good_release( '0.0.1' ) );
		$this->assertFalse( $this->updater->update( false, array(), AISOOQ_BASENAME ) );
	}

	public function test_another_plugin_is_left_alone() {
		$this->stub_release( $this->good_release( '9.9.9' ) );
		$untouched = array( 'some' => 'payload' );
		$this->assertSame( $untouched, $this->updater->update( $untouched, array(), 'other-plugin/other.php' ) );
	}

	// ── Refusing ────────────────────────────────────────────────────────────

	public function test_a_draft_release_is_refused() {
		$r = $this->good_release();
		$r['draft'] = true;
		$this->stub_release( $r );
		$this->assertFalse( $this->updater->manifest( true ) );
	}

	public function test_a_prerelease_is_refused() {
		$r = $this->good_release();
		$r['prerelease'] = true;
		$this->stub_release( $r );
		$this->assertFalse( $this->updater->manifest( true ) );
	}

	public function test_a_tag_that_is_not_a_version_is_refused() {
		$r = $this->good_release();
		$r['tag_name'] = 'nightly';
		$this->stub_release( $r );
		$this->assertFalse( $this->updater->manifest( true ) );
	}

	public function test_a_release_without_the_expected_asset_is_refused() {
		$r = $this->good_release( '9.9.9' );
		$r['assets'][0]['name'] = 'something-else.zip';
		$this->stub_release( $r );
		$this->assertFalse( $this->updater->manifest( true ), 'Offering an update whose download 404s would strand the store mid-upgrade.' );
	}

	/**
	 * The heart of it: a manifest pointing somewhere else must not be able to
	 * move the download. The URL is rebuilt, so a hostile asset URL is only
	 * ever a reason to refuse.
	 */
	public function test_a_foreign_download_url_can_never_become_the_package() {
		$r = $this->good_release( '9.9.9' );
		$r['assets'][0]['browser_download_url'] = 'https://evil.example/aisooq-connector-9.9.9.zip';
		$this->stub_release( $r );

		$this->assertFalse( $this->updater->manifest( true ) );
	}

	public function test_a_non_200_response_is_silent() {
		$this->stub_release( array(), 500 );
		$this->assertFalse( $this->updater->manifest( true ) );
		// Silence means core sees no update at all — not a broken offer.
		$this->assertFalse( $this->updater->update( false, array(), AISOOQ_BASENAME ) );
	}

	// ── The transient guard ─────────────────────────────────────────────────

	/**
	 * Whatever put an offer in the transient, it does not get to name an
	 * arbitrary download host.
	 */
	public function test_an_offer_from_a_foreign_host_is_stripped_from_the_transient() {
		$t = (object) array(
			'response' => array(
				AISOOQ_BASENAME => (object) array(
					'new_version' => '9.9.9',
					'package'     => 'https://evil.example/payload.zip',
				),
			),
		);
		$out = $this->updater->guard( $t );
		$this->assertArrayNotHasKey( AISOOQ_BASENAME, $out->response );
	}

	public function test_a_github_offer_survives_the_guard() {
		$t = (object) array(
			'response' => array(
				AISOOQ_BASENAME => (object) array(
					'new_version' => '9.9.9',
					'package'     => 'https://github.com/allvee/aisooq-wp-connector/releases/download/v9.9.9/aisooq-connector-9.9.9.zip',
				),
			),
		);
		$out = $this->updater->guard( $t );
		$this->assertArrayHasKey( AISOOQ_BASENAME, $out->response );
	}

	public function test_a_plain_http_package_is_stripped() {
		$t = (object) array(
			'response' => array(
				AISOOQ_BASENAME => (object) array( 'package' => 'http://github.com/allvee/x.zip' ),
			),
		);
		$this->assertArrayNotHasKey( AISOOQ_BASENAME, $this->updater->guard( $t )->response );
	}

	// ── Switched off ────────────────────────────────────────────────────────

	public function test_turning_updates_off_stops_the_offer() {
		update_option( AISOOQ_OPTION, array( 'enable_updates' => 0 ) );
		$this->stub_release( $this->good_release( '9.9.9' ) );
		$this->assertFalse( $this->updater->update( false, array(), AISOOQ_BASENAME ) );
	}

	/** A store that has never seen this setting still gets updates. */
	public function test_updates_are_on_by_default() {
		delete_option( AISOOQ_OPTION );
		$this->stub_release( $this->good_release( '9.9.9' ) );
		$this->assertIsArray( $this->updater->update( false, array(), AISOOQ_BASENAME ) );
	}

	// ── The details modal ───────────────────────────────────────────────────

	/**
	 * Offering an update puts a permanent "View details" link on the plugin
	 * row. It must be answered here — falling through sends the merchant to
	 * wordpress.org, which has never heard of this plugin.
	 */
	public function test_the_details_modal_is_answered_even_with_no_manifest() {
		// Stub a failure so there is genuinely no manifest. Without this the
		// call goes to the real api.github.com and the assertion depends on
		// whatever is published — a test that passes or fails for reasons
		// outside the repository.
		$this->stub_release( array(), 500 );

		$info = $this->updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'aisooq-connector' ) );

		$this->assertIsObject( $info );
		$this->assertSame( 'aisooq-connector', $info->slug );
		$this->assertSame( AISOOQ_VERSION, $info->version );
		$this->assertTrue( $info->external );
	}

	public function test_the_details_modal_ignores_other_plugins() {
		$this->assertFalse( $this->updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	// ── State for the settings screen ───────────────────────────────────────

	public function test_state_does_no_io_and_is_always_answerable() {
		$this->updater->forget();
		$s = $this->updater->state();
		$this->assertSame( 'unknown', $s['state'], 'A cold cache must not trigger a request from a render.' );

		$this->stub_release( $this->good_release( '9.9.9' ) );
		$this->updater->manifest( true );
		$this->assertSame( 'available', $this->updater->state()['state'] );
	}
}
