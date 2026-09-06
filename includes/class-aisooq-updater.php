<?php
/**
 * Self-hosted updates.
 *
 * This plugin ships as a zip from GitHub Releases, so WordPress had no way to
 * update it: every store had to be patched by hand, which means security fixes
 * did not propagate. Worse, with no `Update URI` header, core matched the
 * plugin to WordPress.org BY FOLDER NAME — any wp.org plugin whose slug is also
 * `aisooq-connector` could be offered as an "update" and overwrite this one.
 *
 * Both are fixed by the two primitives core provides for exactly this, added in
 * WP 5.8 (this plugin's declared floor): the `Update URI` header, which makes
 * wp.org ignore the slug and names the filter, and `update_plugins_{hostname}`,
 * which lets us answer for ourselves.
 *
 * ─── THIS CODE CAUSES A ZIP TO BE DOWNLOADED AND EXECUTED ───
 *
 * So the manifest is never trusted about WHERE the code comes from. It selects
 * WHICH release; the URL is rebuilt here from hard-coded owner, repo and
 * filename. A manifest that has been tampered with can at worst point at a
 * different real release of this plugin — it cannot introduce a foreign host,
 * a foreign repository or a foreign file. On top of that a late filter on the
 * assembled transient drops any payload for this plugin whose package is not
 * on github.com, so even a misbehaving wp.org response cannot land one.
 *
 * Every failure returns false, which core reads as "no update exists": silent,
 * no notice, no half-offer. An update that cannot be verified is not offered.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Updater {

	/** Cache of a good manifest. */
	const TTL = 12 * HOUR_IN_SECONDS;

	/** Cache of a failure — short, so an outage is not sticky. */
	const TTL_FAIL = 30 * MINUTE_IN_SECONDS;

	const CACHE_KEY = 'aisooq_update_manifest';
	const ETAG_KEY  = 'aisooq_update_etag';

	/**
	 * The floors this plugin actually supports, pinned rather than omitted.
	 *
	 * These gate WP_Automatic_Updater::should_update(), which treats a MISSING
	 * requires_php as "compatible". Since offering an update at all switches on
	 * the "Enable auto-updates" toggle for this plugin, omitting them would let
	 * a future release that raises the floor install itself silently on a store
	 * running the old PHP — and fatal it. They must match the plugin header.
	 */
	const REQUIRES_PHP = '7.4';
	const REQUIRES_WP  = '5.8';

	/** @var AI_Sooq_Updater|null */
	private static $instance = null;

	/**
	 * Always non-null, constructs nothing, touches no network and registers no
	 * hooks. The settings screen reads state() from it during render, so it
	 * must be safe to ask for at any time, including in a unit test where
	 * boot() never ran.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Registered from the main plugin file, outside the WooCommerce gate. */
	public static function boot() {
		self::instance()->register();
	}

	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'guard' ), 999 );
	}

	// ── Is this switched on? ────────────────────────────────────────────────

	/**
	 * A managed host that patches plugins itself can turn this off entirely
	 * without touching the database.
	 */
	private function disabled() {
		return defined( 'AISOOQ_DISABLE_UPDATE_CHECK' ) && AISOOQ_DISABLE_UPDATE_CHECK;
	}

	/**
	 * Read straight from the option rather than through AI_Sooq_Settings: this
	 * class runs before — and independently of — the WooCommerce gate, so it
	 * must not need the plugin's object graph to exist.
	 */
	private function enabled() {
		if ( $this->disabled() ) {
			return false;
		}
		$opt = get_option( AISOOQ_OPTION, array() );
		// Default ON. An update mechanism that ships switched off does not fix
		// the problem it was built for.
		return ! is_array( $opt ) || ! array_key_exists( 'enable_updates', $opt ) || ! empty( $opt['enable_updates'] );
	}

	// ── The offer ───────────────────────────────────────────────────────────

	/**
	 * Core's `update_plugins_{$hostname}` filter.
	 *
	 * Returning false means "no update", and core then puts this plugin in
	 * neither the update list nor the no-update list — no row, no counter, no
	 * error. That is the required behaviour for every failure path here.
	 *
	 * @param array|false $update      Whatever a previous filter decided.
	 * @param array       $plugin_data Headers of the plugin being asked about.
	 * @param string      $plugin_file Its basename.
	 * @return array|false
	 */
	public function update( $update, $plugin_data, $plugin_file ) {
		if ( AISOOQ_BASENAME !== $plugin_file ) {
			return $update; // another plugin using the same GitHub filter
		}
		if ( ! $this->enabled() ) {
			return false;
		}
		$m = $this->manifest();
		if ( ! $m ) {
			return false;
		}
		if ( version_compare( $m['version'], AISOOQ_VERSION, '<=' ) ) {
			return false; // already current, or older
		}

		return array(
			'slug'         => 'aisooq-connector',
			'plugin'       => AISOOQ_BASENAME,
			'version'      => $m['version'],
			'new_version'  => $m['version'],
			'url'          => $m['html_url'],
			'package'      => $m['package'],
			'requires'     => self::REQUIRES_WP,
			'requires_php' => self::REQUIRES_PHP,
			'icons'        => array( 'default' => AISOOQ_URL . 'assets/img/icon-512.png' ),
		);
	}

	/**
	 * Last line of defence on the assembled transient.
	 *
	 * Whatever put a payload for this plugin there — us, a stale cache, a wp.org
	 * response that should not exist — it does not get to name an arbitrary
	 * download. Anything whose package is not on github.com is dropped.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function guard( $value ) {
		if ( ! is_object( $value ) || empty( $value->response[ AISOOQ_BASENAME ] ) ) {
			return $value;
		}
		$offer   = $value->response[ AISOOQ_BASENAME ];
		$package = is_object( $offer ) ? ( isset( $offer->package ) ? $offer->package : '' ) : ( isset( $offer['package'] ) ? $offer['package'] : '' );

		if ( ! self::is_our_host( $package ) ) {
			unset( $value->response[ AISOOQ_BASENAME ] );
		}
		return $value;
	}

	/** Only github.com and its release-asset host may serve this plugin. */
	private static function is_our_host( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] ) {
			return false;
		}
		return in_array(
			strtolower( $parts['host'] ),
			array( 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' ),
			true
		);
	}

	// ── The manifest ────────────────────────────────────────────────────────

	/**
	 * The latest release, or false.
	 *
	 * @param bool $force Bypass the cache (the "check now" button).
	 * @return array|false
	 */
	public function manifest( $force = false ) {
		if ( $this->disabled() ) {
			return false;
		}
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && false !== $cached ) {
			// A cached failure is stored as an empty array, so an outage costs
			// one request per TTL_FAIL rather than one per page load.
			return is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : false;
		}

		/**
		 * Whether this request may reach out to GitHub.
		 *
		 * Normally only admin, cron and WP-CLI requests do — a shopper's page
		 * view must never wait on it. Tests override this.
		 *
		 * @param bool $may
		 */
		$may = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! apply_filters( 'aisooq_update_may_fetch', $may ) ) {
			return false;
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'AI-Sooq-Connector/' . AISOOQ_VERSION . '; ' . home_url( '/' ),
		);
		// Revalidation keeps this off GitHub's 60/hour anonymous budget: a 304
		// costs nothing against it.
		$etag = get_site_transient( self::ETAG_KEY );
		if ( ! $force && $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/allvee/aisooq-wp-connector/releases/latest',
			array( 'timeout' => 10, 'headers' => $headers )
		);

		if ( is_wp_error( $res ) ) {
			return $this->remember_failure();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( 304 === $code && is_array( $cached ) && ! empty( $cached['version'] ) ) {
			set_site_transient( self::CACHE_KEY, $cached, self::TTL );
			return $cached;
		}
		if ( 200 !== $code ) {
			return $this->remember_failure();
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$m    = $this->verify( is_array( $body ) ? $body : array() );
		if ( ! $m ) {
			return $this->remember_failure();
		}

		$new_etag = wp_remote_retrieve_header( $res, 'etag' );
		if ( $new_etag ) {
			set_site_transient( self::ETAG_KEY, is_array( $new_etag ) ? reset( $new_etag ) : $new_etag, self::TTL );
		}
		set_site_transient( self::CACHE_KEY, $m, self::TTL );
		return $m;
	}

	private function remember_failure() {
		set_site_transient( self::CACHE_KEY, array(), self::TTL_FAIL );
		return false;
	}

	/**
	 * Turn a release into an offer, or reject it.
	 *
	 * Everything here is a reason to refuse. The download URL is BUILT, never
	 * read: the release only chooses a version.
	 *
	 * @param array $r Decoded GitHub release.
	 * @return array|false
	 */
	private function verify( array $r ) {
		if ( ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {
			return false;
		}
		$tag = isset( $r['tag_name'] ) ? (string) $r['tag_name'] : '';
		if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $m ) ) {
			return false; // not a release tag we recognise
		}
		$version = $m[1];

		// The one URL this plugin will ever install from, assembled here.
		$filename = 'aisooq-connector-' . $version . '.zip';
		$package  = 'https://github.com/allvee/aisooq-wp-connector/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $filename );

		// The release must actually carry that asset. Without this we would
		// offer an update whose download 404s and leave the store mid-upgrade.
		$found = false;
		foreach ( (array) ( isset( $r['assets'] ) ? $r['assets'] : array() ) as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'] ) ) {
				continue;
			}
			if ( $filename === $asset['name'] && self::is_our_host( isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '' ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}

		return array(
			'version'  => $version,
			'package'  => $package,
			'html_url' => 'https://github.com/allvee/aisooq-wp-connector/releases/tag/' . rawurlencode( $tag ),
			'body'     => isset( $r['body'] ) ? wp_strip_all_tags( (string) $r['body'] ) : '',
		);
	}

	// ── The "View details" modal ────────────────────────────────────────────

	/**
	 * Offering an update puts a permanent "View details" link on this plugin's
	 * row. That link goes to api.wordpress.org, which has never heard of this
	 * plugin, so it must be answered here — ALWAYS, including when the manifest
	 * is unavailable. Falling through would show the merchant "An unexpected
	 * error occurred" on an ordinary screen.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'aisooq-connector' !== $args->slug ) {
			return $result;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( AISOOQ_FILE, false, false );
		$m    = $this->manifest();

		$info = array(
			'name'          => isset( $data['Name'] ) ? $data['Name'] : 'AI Sooq Connector',
			'slug'          => 'aisooq-connector',
			'version'       => $m ? $m['version'] : AISOOQ_VERSION,
			'author'        => isset( $data['Author'] ) ? $data['Author'] : 'AI Sooq',
			'homepage'      => isset( $data['PluginURI'] ) ? $data['PluginURI'] : '',
			'requires'      => self::REQUIRES_WP,
			'requires_php'  => self::REQUIRES_PHP,
			'external'      => true,
			'sections'      => array(
				'description' => isset( $data['Description'] ) ? $data['Description'] : '',
				'changelog'   => $m && '' !== $m['body']
					? wpautop( esc_html( $m['body'] ) )
					: esc_html__( 'Release notes are published on GitHub.', 'aisooq-connector' ),
			),
		);
		if ( $m && version_compare( $m['version'], AISOOQ_VERSION, '>' ) ) {
			$info['download_link'] = $m['package'];
		}
		return (object) $info;
	}

	// ── For the settings screen ─────────────────────────────────────────────

	/**
	 * What to tell the operator, without doing any I/O.
	 *
	 * @return array{state:string,latest:string,checked:bool}
	 */
	public function state() {
		if ( $this->disabled() ) {
			return array( 'state' => 'disabled', 'latest' => '', 'checked' => false );
		}
		if ( ! $this->enabled() ) {
			return array( 'state' => 'off', 'latest' => '', 'checked' => false );
		}
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			return array( 'state' => 'unknown', 'latest' => '', 'checked' => false );
		}
		if ( ! is_array( $cached ) || empty( $cached['version'] ) ) {
			return array( 'state' => 'unreachable', 'latest' => '', 'checked' => true );
		}
		return array(
			'state'   => version_compare( $cached['version'], AISOOQ_VERSION, '>' ) ? 'available' : 'current',
			'latest'  => $cached['version'],
			'checked' => true,
		);
	}

	/** Forget everything cached, so the next ask really asks. */
	public function forget() {
		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( self::ETAG_KEY );
	}
}
