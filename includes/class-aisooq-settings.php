<?php
/**
 * Settings store + admin screen for the connector.
 *
 * One WooCommerce site connects to exactly one AI Sooq store (an OAuth app is
 * bound to one sid). Credentials are entered once by the operator.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Required here rather than relying on the main file's load order: the admin
// menu glyph is painted from it on every single admin screen.
require_once __DIR__ . '/class-aisooq-palette.php';

class AI_Sooq_Settings {

	/**
	 * The palette entries menu_icon_css() seeds and then paints with.
	 *
	 * A constant so tests/test-palette-drift.php can hold it against the
	 * `var(--aisooq-*)` uses in that block: a name used but not seeded resolves
	 * to nothing and the sidebar glyph vanishes on every admin screen.
	 */
	const MENU_COLOURS = array(
		'hl',
		'wp-menu-ink',
	);

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'aisooq-connector';
	const NONCE      = 'aisooq_settings';

	/** @var array|null */
	private $cache = null;

	const STATUS_OPTION = 'aisooq_status';

	public function defaults() {
		return array(
			'active'                => 1,
			'api_base'              => '',
			'storefront_base'       => '',
			'sid'                   => '',
			'client_id'             => '',
			'client_secret'         => '',
			'enable_orders'         => 1,
			'enable_abandoned'      => 0,
			'enable_analytics'      => 0,
			'enable_fraud'          => 0,
			'fraud_action'          => 'block',
			'courier_min_ratio'     => 0,
			'courier_min_parcels'   => 3,
			// Refuse a second order from the same buyer inside a short window.
			// Off by default: a real shopper legitimately places a second order
			// the same day, so this is only right for stores whose problem is
			// repeat prank/COD orders from one number.
			'dup_order_block'       => 0,
			'dup_order_window_hours' => 24,
			// Look up courier delivery history automatically when an order is
			// placed, instead of an operator pressing a button per order.
			// Off by default: BDCourier bills per lookup, so turning this on for
			// a merchant without asking would spend their money.
			'auto_courier_check'    => 0,
			// Shown to a shopper when checkout is blocked by fraud/courier — so a
			// genuine buyer can still reach the store. Blank = use the connected
			// tenant's contact number.
			'support_phone'         => '',
			'support_whatsapp'      => '',
			// Optional Messenger link (m.me/<page> or a full messenger URL) shown as
			// a third contact button on a blocked checkout.
			'support_messenger'     => '',
			// Configurable checkout block messages, per case (Bangla defaults; the
			// operator can set any language). Blank = the built-in default.
			// {ratio} and {parcels} tokens are substituted in the courier message.
			'msg_courier'           => 'দুঃখিত, এই মোবাইল নম্বরে কুরিয়ার ডেলিভারি সফলতার হার কম ({ratio}% — {parcels}টি পার্সেলের মধ্যে)। অর্ডারটি নিশ্চিত করতে আমাদের সাথে যোগাযোগ করুন।',
			'msg_fraud_contact'     => 'আপনার দেওয়া তথ্য যাচাই করা যায়নি। সঠিক নাম, মোবাইল নম্বর ও ঠিকানা দিয়ে আবার চেষ্টা করুন অথবা আমাদের সাথে যোগাযোগ করুন।',
			'msg_fraud_velocity'    => 'অল্প সময়ে অনেকবার চেষ্টা করা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন অথবা আমাদের সাথে যোগাযোগ করুন।',
			'msg_fraud_generic'     => 'দুঃখিত, এই মুহূর্তে অর্ডারটি গ্রহণ করা যাচ্ছে না। সহায়তার জন্য আমাদের সাথে যোগাযোগ করুন।',
			// {hours} is substituted with the configured window.
			'msg_duplicate'         => 'আপনার একটি অর্ডার ইতিমধ্যে গ্রহণ করা হয়েছে। {hours} ঘণ্টার মধ্যে একই নম্বর থেকে আবার অর্ডার করা যাবে না। অর্ডারে কিছু যোগ বা পরিবর্তন করতে আমাদের সাথে যোগাযোগ করুন।',
			// Shown when the operator's own block list refuses a checkout. It is
			// deliberately vague about WHY: naming the reason would tell an
			// abuser exactly which identifier to change.
			'msg_blocked'           => '',
			'msg_help'              => 'অর্ডার সম্পন্ন করতে সাহায্য দরকার? আমাদের সাথে যোগাযোগ করুন:',
			'enable_customer_sync'  => 0,
			'customer_sync_dir'     => 'both',
			// Catalog is three independently-controlled entities, each with its
			// own on/off + direction (mirrors the platform, which already splits
			// them by route + scope: /connect/categories, /connect/brands,
			// /connect/products).
			'enable_category_sync'  => 0,
			'category_sync_dir'     => 'push',
			'enable_brand_sync'     => 0,
			'brand_sync_dir'        => 'push',
			'enable_product_sync'   => 0,
			'product_sync_dir'      => 'both',
			// Auto-generate a unique SKU on WooCommerce products/variants that
			// lack one at sync time, so the platform can map them by SKU.
			'auto_sku'              => 1,
			// Legacy bundled switch — kept only so a pre-split install can
			// inherit its value into the three keys above (see all()).
			'enable_catalog_sync'   => 0,
			'catalog_sync_dir'      => 'push',
			'order_statuses'        => array( 'pending', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled', 'failed' ),
			'abandoned_idle_min'    => 30,
			'allow_status_writeback' => 0,
			'debug_log'             => 0,
			'enable_updates'        => 1,
			// WooCommerce-method → platform-shipping-rate map, keyed by the
			// shipping line code "<method_id>:<instance_id>" → platform rate id.
			'shipping_map'          => array(),
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( AISOOQ_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$merged = wp_parse_args( $stored, $this->defaults() );

			// One-time forward-migration: an install saved before catalog was
			// split into category/brand/product inherits its single bundled
			// switch + direction into all three granular controls, so its
			// behaviour is unchanged after upgrade.
			if ( array_key_exists( 'enable_catalog_sync', $stored ) && ! array_key_exists( 'enable_category_sync', $stored ) ) {
				$legacy_on  = empty( $stored['enable_catalog_sync'] ) ? 0 : 1;
				$legacy_dir = isset( $stored['catalog_sync_dir'] ) ? $stored['catalog_sync_dir'] : 'push';
				foreach ( array( 'category', 'brand', 'product' ) as $e ) {
					$merged[ "enable_{$e}_sync" ] = $legacy_on;
					$merged[ "{$e}_sync_dir" ]    = $legacy_dir;
				}
			}
			$this->cache = $merged;
		}
		return $this->cache;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/** Admin host root, no trailing slash. The API client appends `/api/v1`. */
	public function get_api_base() {
		return untrailingslashit( trim( (string) $this->get( 'api_base' ) ) );
	}

	/** Storefront host root (client-api: /pixel, /fraud). Blank ⇒ falls back
	 *  to the admin host for single-host deployments. */
	public function get_storefront_base() {
		return untrailingslashit( trim( (string) $this->get( 'storefront_base' ) ) );
	}

	public function get_sid() {
		return trim( (string) $this->get( 'sid' ) );
	}

	public function is_configured() {
		return '' !== $this->get_api_base() && '' !== $this->get_sid()
			&& '' !== trim( (string) $this->get( 'client_id' ) )
			&& '' !== trim( (string) $this->get( 'client_secret' ) );
	}

	/** Master switch. When off, no sync/ingest hooks are registered. */
	public function is_active() {
		return (bool) $this->get( 'active' );
	}

	/**
	 * The connected store's profile, as the platform last reported it.
	 *
	 * Cached from `/connect/ping` — name, contact, currency, and the storefront
	 * choices the owner made in AI Sooq admin (currently the Bengali typeface).
	 * Public because a theme legitimately needs it: the alternative is a theme
	 * reaching into this plugin's private option, which breaks the moment the
	 * option's shape changes.
	 *
	 * @param string|null $key Dot-free key to read, or null for the whole array.
	 * @return mixed
	 */
	public static function store_profile( $key = null, $default = null ) {
		$status = get_option( self::STATUS_OPTION, array() );
		$store  = ( is_array( $status ) && isset( $status['store'] ) && is_array( $status['store'] ) )
			? $status['store']
			: array();
		if ( null === $key ) {
			return $store;
		}
		return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
	}

	/** Last successful verify result (sid, scopes, time) or empty. */
	public function status() {
		$s = get_option( self::STATUS_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Read-only dashboard KPIs (cached 5 min). Cheap, HPOS-safe: order count via
	 * WC_Order_Query pagination, queue/failures via Action Scheduler, catalog +
	 * customer counts via meta, abandoned from the capture table.
	 *
	 * @return array
	 */
	public function stats() {
		$cached = get_transient( 'aisooq_dashboard_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$stats = array(
			'orders_synced' => 0,
			'queue'         => 0,
			'failed'        => 0,
			'queue_errors'  => 0,
			'abandoned'     => 0,
			'products'      => 0,
			'customers'     => 0,
		);

		if ( function_exists( 'wc_get_orders' ) ) {
			$q = wc_get_orders( array(
				'limit'        => 1,
				'paginate'     => true,
				'return'       => 'ids',
				'meta_key'     => AISOOQ_META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
			) );
			if ( is_object( $q ) && isset( $q->total ) ) {
				$stats['orders_synced'] = (int) $q->total;
			}
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$base = array( 'group' => AISOOQ_AS_GROUP, 'per_page' => 500 );
			$stats['queue']  = count( (array) as_get_scheduled_actions( array_merge( $base, array( 'status' => 'pending' ) ), 'ids' ) );
			// Action Scheduler's own `failed` status is not the number we want.
			// handle_failure() CATCHES the API error and returns normally, so
			// the action completes successfully and this count is structurally
			// zero — the screen reported a clean queue while orders were
			// silently giving up. It is kept as a separate queue-level figure.
			$stats['queue_errors'] = count( (array) as_get_scheduled_actions( array_merge( $base, array( 'status' => 'failed' ) ), 'ids' ) );
		}

		// Orders that exhausted their retry budget. One query shape, shared with
		// the Failed syncs screen, so the tile and the list can never describe
		// different sets of orders.
		$stats['failed'] = class_exists( 'AI_Sooq_Order_Sync' ) ? AI_Sooq_Order_Sync::failed_count( true ) : 0;

		if ( class_exists( 'AI_Sooq_Abandoned_Sync' ) ) {
			$ab = AI_Sooq_Abandoned_Sync::table_name();
			if ( $ab === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ab ) ) ) { // phpcs:ignore WordPress.DB
				$stats['abandoned'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ab} WHERE synced = 1" ); // phpcs:ignore WordPress.DB
			}
		}

		$stats['products'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(1) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product'",
			'_aisooq_platform_id'
		) );
		$stats['customers'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(1) FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'_aisooq_platform_customer_id'
		) );

		set_transient( 'aisooq_dashboard_stats', $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/** Format a KPI count, showing "500+" when the query was capped. */
	private function kpi_num( $n, $cap = 500 ) {
		return $n >= $cap ? ( $cap . '+' ) : number_format_i18n( $n );
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'wp_ajax_aisooq_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_aisooq_sync', array( $this, 'ajax_sync' ) );
		// Shared "Bazaar Console" admin design system for both plugin screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// Menu icon: white by default, marigold when the menu is current/hovered.
		add_action( 'admin_head', array( $this, 'menu_icon_css' ) );
		add_filter(
			'plugin_action_links_' . AISOOQ_BASENAME,
			array( $this, 'action_links' )
		);
	}

	/** Load the shared admin stylesheet on the plugin's own screens only. */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'aisooq' ) ) {
			return;
		}
		wp_enqueue_style( 'aisooq-admin', AISOOQ_URL . 'assets/css/aisooq-admin.css', array(), AISOOQ_VERSION );

		/*
		 * The application shell — app bar, sidebar, cards, tables. Every AI
		 * Sooq screen wears it, so it rides along with the base sheet on the
		 * same hook test rather than naming the four screens individually: a
		 * fifth screen would otherwise render unstyled until someone
		 * remembered to add it here.
		 */
		wp_enqueue_style(
			'aisooq-app',
			AISOOQ_URL . 'assets/css/aisooq-app.css',
			array( 'aisooq-admin' ),
			AISOOQ_VERSION
		);

		/*
		 * The date-range picker. Enqueued on every AI Sooq screen rather than
		 * only the one that uses it today: it costs 5KB, it initialises itself
		 * from whatever markup is on the page, and a screen that adds a range
		 * filter should not also have to remember to add a script.
		 *
		 * In the footer, because it reads the trigger out of the DOM on load.
		 */
		wp_enqueue_script(
			'aisooq-daterange',
			AISOOQ_URL . 'assets/js/aisooq-daterange.js',
			array(),
			AISOOQ_VERSION,
			true
		);
	}

	/**
	 * Recolour the top-level admin-menu icon: white in the resting state,
	 * marigold when the item is current/open/hovered. WordPress renders the
	 * base64 icon as a dimmed background/img and can't tint it, so we hide that
	 * and paint the same glyph as a CSS mask whose colour we control. Printed in
	 * admin_head (all screens) because the menu shows everywhere.
	 *
	 * WHY THIS BLOCK SEEDS ITS OWN COLOURS INSTEAD OF JUST USING THE ONES IN
	 * assets/css/aisooq-admin.css: that sheet declares its tokens on
	 * `.wrap.aisooq, .wrap.aisooq-ab, .wrap.aisooq-bl`, and it is enqueued only
	 * on hooks containing "aisooq" (see enqueue_admin_assets() above). This rule
	 * targets #adminmenu, which is neither inside `.wrap.aisooq*` nor on a
	 * plugin screen most of the time. Custom properties only inherit down the
	 * tree, so a bare `var(--hl)` here would be invalid at computed-value time
	 * and background-color would compute to transparent — the sidebar glyph
	 * would simply vanish on every screen. Seeding on $sel keeps the values
	 * named and greppable while resolving locally, whatever is enqueued.
	 */
	public function menu_icon_css() {
		$icon = self::menu_icon();
		$sel  = '#toplevel_page_' . self::PAGE_SLUG;
		echo '<style id="aisooq-menu-icon">'
			/*
			 * Both colours come from AI_Sooq_Palette, which is the one place
			 * either is written down. `hl` is the gold, and it is drift-tested
			 * against the stylesheet's own --hl so this copy cannot fall
			 * behind it — the thing the old hand-typed literal here could not
			 * promise. `wp-menu-ink` is the resting white, and it stays a
			 * WordPress colour rather than one of ours: --pri-fg means "ink on
			 * navy" and --bg means "page surface", neither describes wp-admin's
			 * dark sidebar, and borrowing one would tie the sidebar to a token
			 * that may move without us.
			 *
			 * $sel, not `body`: unlike the two order screens this block styles
			 * exactly one subtree, so the seed goes on it.
			 */
			. AI_Sooq_Palette::vars( self::MENU_COLOURS, $sel ) // phpcs:ignore WordPress.Security.EscapeOutput -- allow-listed hex only; see AI_Sooq_Palette::hex().
			. $sel . ' .wp-menu-image,' . $sel . ' .wp-menu-image.svg{background-image:none !important;}'
			. $sel . ' .wp-menu-image img{opacity:0 !important;}'
			. $sel . ' .wp-menu-image{position:relative;}'
			. $sel . ' .wp-menu-image:after{content:"";position:absolute;top:7px;left:0;right:0;margin:0 auto;width:20px;height:20px;background-color:var(--aisooq-wp-menu-ink);'
			. '-webkit-mask:url(\'' . $icon . '\') center/20px no-repeat;mask:url(\'' . $icon . '\') center/20px no-repeat;transition:background-color .15s ease;}'
			. $sel . ':hover .wp-menu-image:after,'
			. $sel . '.current .wp-menu-image:after,'
			. $sel . '.wp-has-current-submenu .wp-menu-image:after,'
			. $sel . '.opensub .wp-menu-image:after{background-color:var(--aisooq-hl);}'
			. '</style>';
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'aisooq-connector' ) . '</a>' );
		return $links;
	}

	public function add_menu() {
		add_menu_page(
			__( 'AI Sooq', 'aisooq-connector' ),
			__( 'AI Sooq', 'aisooq-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			self::menu_icon(),
			58
		);
		// Re-title the auto-created first submenu to "Settings" with an icon
		// (same slug replaces the default "AI Sooq" entry).
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'aisooq-connector' ),
			'<span class="dashicons dashicons-admin-generic" style="font-size:17px;width:17px;height:17px;vertical-align:-3px;"></span> ' . __( 'Settings', 'aisooq-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Admin-menu icon as a base64 SVG data URI — the WordPress-recommended
	 * pattern. A single-colour (fill) 20×20 mark that WP tints via its own CSS
	 * (grey → white/blue on hover/current), and renders at the correct menu
	 * size, unlike a full-colour SVG referenced by URL.
	 */
	private static function menu_icon() {
		// The AI Sooq mark — a speech bubble with a circuit routed inside it —
		// flattened to filled paths at 20x20. Strokes are drawn as thin filled
		// rects rather than `stroke`, because WordPress tints this icon by
		// setting `fill`/`background-color` on a mask, and a stroked path would
		// stay its original colour.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			// Bubble outline with tail (even-odd: outer shape minus inner shape).
			. '<path fill-rule="evenodd" d="M5.2 2.2h9.6a3.2 3.2 0 0 1 3.2 3.2v5.1a3.2 3.2 0 0 1-3.2 3.2H8.4l-4 4 2-4h-1.2a3.2 3.2 0 0 1-3.2-3.2V5.4a3.2 3.2 0 0 1 3.2-3.2Zm0 1.2a2 2 0 0 0-2 2v5.1a2 2 0 0 0 2 2h3.1l-1 2 2-2h5.5a2 2 0 0 0 2-2V5.4a2 2 0 0 0-2-2Z"/>'
			// Routed traces.
			. '<path d="M5.3 7.9h3.1V5.2h5.5v4.6h-1V6.2H9.4v1.7h1.1v2.9h2.2v1H9.5V8.9H5.3z"/>'
			// Nodes.
			. '<circle cx="5.3" cy="7.9" r="1.2"/>'
			. '<circle cx="11.6" cy="11.4" r="1.2"/>'
			. '<circle cx="8.4" cy="5.2" r=".8"/>'
			. '<circle cx="13.9" cy="9.8" r=".8"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Handle the settings form POST. Uses a manual save (not register_setting)
	 * so we can mask the secret and keep the old value when the field is blank.
	 */
	/**
	 * Validate an operator-supplied platform endpoint.
	 *
	 * Bearer tokens and OAuth credentials are sent to whatever this resolves
	 * to, so it must be a plain https origin. A non-https scheme would put the
	 * client secret on the wire in clear; a URL with credentials, a port, a
	 * path or a query is not an API origin and usually means someone is trying
	 * to steer the request somewhere it should not go.
	 *
	 * Returns the previous value when the input is unusable, so a typo cannot
	 * silently blank the connection.
	 *
	 * @param string $value    Submitted URL.
	 * @param string $fallback Currently stored value.
	 * @return string
	 */
	private static function clean_endpoint( $value, $fallback ) {
		$value = untrailingslashit( esc_url_raw( trim( (string) $value ) ) );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return (string) $fallback;
		}
		$host  = strtolower( $parts['host'] );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

		// http is tolerated only for local development.
		if ( 'https' !== $parts['scheme'] && ! ( 'http' === $parts['scheme'] && $local ) ) {
			return (string) $fallback;
		}
		// No embedded credentials.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return (string) $fallback;
		}
		// Host only — the client appends its own paths.
		if ( ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return (string) $fallback;
		}
		return $value;
	}

	public function maybe_save() {
		if ( empty( $_POST['aisooq_save'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$existing = $this->all();
		$raw      = isset( $_POST['aisooq'] ) && is_array( $_POST['aisooq'] ) ? wp_unslash( $_POST['aisooq'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above

		$clean                          = array();
		$clean['active']                = empty( $raw['active'] ) ? 0 : 1;

		// The connection credentials are administrator-only.
		//
		// This screen is gated on `manage_woocommerce`, which a Shop Manager
		// has. The client secret is write-only in the form, but the ENDPOINT is
		// not: a Shop Manager could repoint `api_base` at a host they control
		// and press Verify, and the token mint would post this store's
		// client_id and client_secret straight to them. Steering where the
		// credentials are sent is as sensitive as reading them, so both are
		// held to `manage_options`; everything else on this screen stays
		// editable by a shop manager.
		if ( current_user_can( 'manage_options' ) ) {
			$clean['api_base']        = self::clean_endpoint( isset( $raw['api_base'] ) ? $raw['api_base'] : '', $existing['api_base'] );
			$clean['storefront_base'] = self::clean_endpoint( isset( $raw['storefront_base'] ) ? $raw['storefront_base'] : '', $existing['storefront_base'] );
			$clean['sid']             = sanitize_text_field( isset( $raw['sid'] ) ? $raw['sid'] : '' );
			$clean['client_id']       = sanitize_text_field( isset( $raw['client_id'] ) ? $raw['client_id'] : '' );
			// Secret is write-only in the UI: blank submit keeps the stored value.
			$secret_in                = isset( $raw['client_secret'] ) ? trim( $raw['client_secret'] ) : '';
			$clean['client_secret']   = ( '' === $secret_in ) ? $existing['client_secret'] : sanitize_text_field( $secret_in );
		} else {
			$clean['api_base']        = $existing['api_base'];
			$clean['storefront_base'] = $existing['storefront_base'];
			$clean['sid']             = $existing['sid'];
			$clean['client_id']       = $existing['client_id'];
			$clean['client_secret']   = $existing['client_secret'];
		}
		// Held to `update_plugins`, not this screen's `manage_woocommerce`: a
		// shop manager should not be able to switch off the channel that
		// delivers security fixes to the whole site.
		if ( current_user_can( 'update_plugins' ) ) {
			$clean['enable_updates'] = empty( $raw['enable_updates'] ) ? 0 : 1;
		} else {
			$clean['enable_updates'] = empty( $existing['enable_updates'] ) ? 0 : 1;
		}
		$clean['enable_orders']         = empty( $raw['enable_orders'] ) ? 0 : 1;
		$clean['enable_abandoned']      = empty( $raw['enable_abandoned'] ) ? 0 : 1;
		$clean['enable_analytics']      = empty( $raw['enable_analytics'] ) ? 0 : 1;
		$clean['enable_fraud']          = empty( $raw['enable_fraud'] ) ? 0 : 1;
		$fraud_action                   = isset( $raw['fraud_action'] ) ? sanitize_key( $raw['fraud_action'] ) : 'block';
		$clean['fraud_action']          = in_array( $fraud_action, array( 'block', 'hold', 'flag' ), true ) ? $fraud_action : 'block';
		$clean['courier_min_ratio']     = max( 0, min( 100, absint( isset( $raw['courier_min_ratio'] ) ? $raw['courier_min_ratio'] : 0 ) ) );
		$clean['courier_min_parcels']   = max( 1, absint( isset( $raw['courier_min_parcels'] ) ? $raw['courier_min_parcels'] : 3 ) );
		$clean['auto_courier_check']    = empty( $raw['auto_courier_check'] ) ? 0 : 1;
		$clean['dup_order_block']       = empty( $raw['dup_order_block'] ) ? 0 : 1;
		// 1 hour floor (0 would mean "block forever" once saved, which reads as
		// the toggle being broken); 168 = one week ceiling.
		$dup_hours                      = absint( isset( $raw['dup_order_window_hours'] ) ? $raw['dup_order_window_hours'] : 24 );
		$clean['dup_order_window_hours'] = max( 1, min( 168, $dup_hours ? $dup_hours : 24 ) );
		$clean['support_phone']         = sanitize_text_field( isset( $raw['support_phone'] ) ? $raw['support_phone'] : '' );
		$clean['support_whatsapp']      = sanitize_text_field( isset( $raw['support_whatsapp'] ) ? $raw['support_whatsapp'] : '' );
		$clean['support_messenger']     = esc_url_raw( isset( $raw['support_messenger'] ) ? trim( $raw['support_messenger'] ) : '' );
		foreach ( array( 'msg_courier', 'msg_fraud_contact', 'msg_fraud_velocity', 'msg_fraud_generic', 'msg_duplicate', 'msg_blocked', 'msg_help' ) as $mk ) {
			$clean[ $mk ] = isset( $raw[ $mk ] ) ? sanitize_textarea_field( $raw[ $mk ] ) : '';
		}
		$clean['enable_customer_sync']  = empty( $raw['enable_customer_sync'] ) ? 0 : 1;
		$cust_dir                       = isset( $raw['customer_sync_dir'] ) ? sanitize_key( $raw['customer_sync_dir'] ) : 'both';
		$clean['customer_sync_dir']     = in_array( $cust_dir, array( 'push', 'pull', 'both' ), true ) ? $cust_dir : 'both';
		$dir_wl = array( 'push', 'pull', 'both' );
		$dir_of = function ( $key, $fallback ) use ( $raw, $dir_wl ) {
			$v = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : $fallback;
			return in_array( $v, $dir_wl, true ) ? $v : $fallback;
		};
		$clean['enable_category_sync']  = empty( $raw['enable_category_sync'] ) ? 0 : 1;
		$clean['category_sync_dir']     = $dir_of( 'category_sync_dir', 'push' );
		$clean['enable_brand_sync']     = empty( $raw['enable_brand_sync'] ) ? 0 : 1;
		$clean['brand_sync_dir']        = $dir_of( 'brand_sync_dir', 'push' );
		$clean['enable_product_sync']   = empty( $raw['enable_product_sync'] ) ? 0 : 1;
		$clean['product_sync_dir']      = $dir_of( 'product_sync_dir', 'both' );
		$clean['auto_sku']              = empty( $raw['auto_sku'] ) ? 0 : 1;
		// Mirror into the legacy bundled keys so any not-yet-updated reader (and
		// the migration guard in all()) still resolves a sane value.
		$clean['enable_catalog_sync']   = ( $clean['enable_category_sync'] || $clean['enable_brand_sync'] || $clean['enable_product_sync'] ) ? 1 : 0;
		$clean['catalog_sync_dir']      = $clean['category_sync_dir'];
		$clean['allow_status_writeback'] = empty( $raw['allow_status_writeback'] ) ? 0 : 1;
		$clean['debug_log']             = empty( $raw['debug_log'] ) ? 0 : 1;
		$clean['abandoned_idle_min']    = max( 5, absint( isset( $raw['abandoned_idle_min'] ) ? $raw['abandoned_idle_min'] : 30 ) );

		$statuses = isset( $raw['order_statuses'] ) && is_array( $raw['order_statuses'] ) ? $raw['order_statuses'] : array();
		$clean['order_statuses'] = array_values( array_map( 'sanitize_key', $statuses ) );

		// Shipping-rate map: keep only "code => positive rate id" entries. When
		// the form doesn't post a map, preserve the stored one (so a map set by
		// a future UI / filter isn't wiped by an unrelated save).
		if ( isset( $raw['shipping_map'] ) && is_array( $raw['shipping_map'] ) ) {
			$clean['shipping_map'] = array();
			foreach ( $raw['shipping_map'] as $code => $rate_id ) {
				$rid = absint( $rate_id );
				if ( $rid > 0 ) {
					$clean['shipping_map'][ substr( sanitize_text_field( (string) $code ), 0, 64 ) ] = $rid;
				}
			}
		} else {
			$clean['shipping_map'] = isset( $existing['shipping_map'] ) && is_array( $existing['shipping_map'] ) ? $existing['shipping_map'] : array();
		}

		// autoload=false: this option holds the OAuth client secret, and an
		// autoloaded option is read into memory on EVERY request, front end
		// included. Only the handful of paths that talk to the platform need it.
		update_option( AISOOQ_OPTION, $clean, false );
		$this->cache = null;
		// Reset the cached token whenever credentials might have changed.
		delete_transient( AISOOQ_TOKEN_TRANSIENT );
		// …and the cached platform reads, so a negative result cached during an
		// outage does not outlive the credentials the operator just corrected.
		$this->flush_page_cache();

		add_settings_error( 'aisooq_connector', 'saved', __( 'Settings saved.', 'aisooq-connector' ), 'updated' );

		// Push the platform-side fraud layers (name/address/phone/IP) + arm the
		// master switch to match the local toggle. The platform is the source of
		// truth for these; the plugin dashboard just configures them.
		$this->push_platform_fraud( $clean );
	}

	/**
	 * Sync the fraud-prevention configuration to the platform via
	 * PUT /connect/fraud-config. The master switch mirrors the local
	 * `enable_fraud` toggle (so one action arms both the plugin's fraud-screen
	 * call AND the platform engine), and the layer settings come from the
	 * `aisooq_fraud[...]` fields. No-op when the fraud card wasn't submitted or the
	 * store isn't connected. Never blocks the save — a failure just warns.
	 *
	 * @param array $clean the sanitized local settings just saved
	 */
	private function push_platform_fraud( $clean ) {
		$fraud  = isset( $_POST['aisooq_fraud'] ) && is_array( $_POST['aisooq_fraud'] ) ? wp_unslash( $_POST['aisooq_fraud'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in maybe_save()
		$status = get_option( self::STATUS_OPTION, array() );

		if ( empty( $status['ok'] ) ) {
			if ( null !== $fraud ) {
				add_settings_error( 'aisooq_connector', 'fraud_offline', __( 'Fraud layers not saved to the platform — connect the store first (Verify connection).', 'aisooq-connector' ), 'warning' );
			}
			return;
		}

		// The layer card is absent whenever the config read failed — but the
		// MASTER SWITCH was still on this submit, and it is the switch that arms
		// the platform's engine. Returning here left the local toggle reading ON
		// while the platform stayed off, which is the worst possible split:
		// the operator believes checkouts are screened and they are not.
		// Send `enabled` alone, and leave the layer fields untouched rather than
		// overwriting the platform's real values with defaults we never read.
		if ( null === $fraud ) {
			$res = AI_Sooq_Plugin::instance()->api()->request(
				'PUT',
				'/connect/fraud-config',
				array( 'enabled' => (bool) $clean['enable_fraud'] )
			);
			if ( is_wp_error( $res ) ) {
				add_settings_error(
					'aisooq_connector',
					'fraud_put',
					sprintf(
						/* translators: %s: error */
						__( 'The fraud master switch could not be sent to the platform: %s', 'aisooq-connector' ),
						$res->get_error_message()
					),
					'warning'
				);
			}
			return;
		}

		$phone_mode = isset( $fraud['phone_mode'] ) ? sanitize_key( $fraud['phone_mode'] ) : 'bd';
		if ( ! in_array( $phone_mode, array( 'bd', 'intl', 'off' ), true ) ) {
			$phone_mode = 'bd';
		}
		$payload = array(
			// One master: the local checkbox arms the platform engine too.
			'enabled'           => (bool) $clean['enable_fraud'],
			'phoneMode'         => $phone_mode,
			'nameValidation'    => ! empty( $fraud['name_validation'] ),
			'addressValidation' => ! empty( $fraud['address_validation'] ),
			'ipMaxAttempts'     => max( 1, min( 100, absint( isset( $fraud['ip_max_attempts'] ) ? $fraud['ip_max_attempts'] : 3 ) ) ),
			'ipWindowHours'     => max( 1, min( 168, absint( isset( $fraud['ip_window_hours'] ) ? $fraud['ip_window_hours'] : 24 ) ) ),
		);

		$res = AI_Sooq_Plugin::instance()->api()->request( 'PUT', '/connect/fraud-config', $payload );
		if ( is_wp_error( $res ) ) {
			add_settings_error( 'aisooq_connector', 'fraud_put', sprintf( /* translators: %s: error */ __( 'Fraud layers could not be saved to the platform: %s', 'aisooq-connector' ), $res->get_error_message() ), 'warning' );
		}
	}

	/**
	 * A platform GET whose answer is cached — including its failures.
	 *
	 * The settings screen used to make up to three uncached 20-second calls
	 * before emitting a single byte, so the one page an operator opens to
	 * diagnose an outage was the page the outage made unusable, and every
	 * reload paid the full cost again.
	 *
	 * The failure is cached too, deliberately: without that, a down platform
	 * costs a fresh 20s wait per call per reload. The key carries the api_base
	 * and store id, so pointing at a different store invalidates it for free.
	 *
	 * @param string $path     Admin API path.
	 * @param int    $ttl      Seconds to cache a successful answer.
	 * @param int    $fail_ttl Seconds to remember a failure.
	 * @return array|WP_Error
	 */
	private function flush_page_cache() {
		foreach ( array( '/connect/fraud-config', '/connect/shipping-rates', '/connect/ping' ) as $path ) {
			delete_transient( 'aisooq_pg_' . md5( $this->get_api_base() . '|' . $this->get_sid() . '|' . $path ) );
		}
	}

	private function cached_get( $path, $ttl = 900, $fail_ttl = 60 ) {
		$key = 'aisooq_pg_' . md5( $this->get_api_base() . '|' . $this->get_sid() . '|' . $path );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return ( is_array( $hit ) && isset( $hit['__aisooq_error'] ) )
				? new WP_Error( 'aisooq_cached_error', (string) $hit['__aisooq_error'] )
				: $hit;
		}
		// Shorter than the background timeout: a human is watching this render.
		$res = AI_Sooq_Plugin::instance()->api()->get( $path, 8 );
		if ( is_wp_error( $res ) ) {
			set_transient( $key, array( '__aisooq_error' => $res->get_error_message() ), $fail_ttl );
			return $res;
		}
		set_transient( $key, $res, $ttl );
		return $res;
	}

	/**
	 * Read the platform fraud config for the settings form.
	 *
	 * Three outcomes, kept distinct on purpose. Collapsing them all to null
	 * meant a store that WAS connected but whose fraud-config read failed was
	 * told "connect the store" — advice that is both wrong and unactionable —
	 * while the real error (a missing OAuth scope, most often) went unsaid.
	 *
	 * @param array $status Stored connection status.
	 * @return array{state:string,config:?array,message:string}
	 */
	private function load_platform_fraud( $status ) {
		if ( empty( $status['ok'] ) ) {
			return array( 'state' => 'disconnected', 'config' => null, 'message' => '' );
		}
		$res = $this->cached_get( '/connect/fraud-config' );
		if ( is_wp_error( $res ) ) {
			return array( 'state' => 'error', 'config' => null, 'message' => $res->get_error_message() );
		}
		if ( ! is_array( $res ) ) {
			return array( 'state' => 'error', 'config' => null, 'message' => __( 'The platform returned an unexpected response.', 'aisooq-connector' ) );
		}
		return array( 'state' => 'ok', 'config' => $res, 'message' => '' );
	}

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		// Verify means "ask the platform now", so it must never be answered
		// from the render cache — and its result should refresh that cache.
		$this->flush_page_cache();
		$api    = AI_Sooq_Plugin::instance()->api();
		$result = $api->get( '/connect/ping' );
		if ( is_wp_error( $result ) ) {
			update_option(
				self::STATUS_OPTION,
				array( 'ok' => 0, 'error' => $result->get_error_message(), 'time' => current_time( 'mysql' ) )
			);
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$scopes = isset( $result['scopes'] ) && is_array( $result['scopes'] ) ? $result['scopes'] : array();
		$store  = isset( $result['store'] ) && is_array( $result['store'] ) ? $result['store'] : array();
		update_option(
			self::STATUS_OPTION,
			array(
				'ok'     => 1,
				'sid'    => isset( $result['sid'] ) ? $result['sid'] : '',
				'scopes' => $scopes,
				'store'  => $store,
				'time'   => current_time( 'mysql' ),
			)
		);
		$who = ! empty( $store['name'] ) ? $store['name'] : ( isset( $result['sid'] ) ? $result['sid'] : '?' );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: store name, 2: number of permissions */
					__( 'Connected to "%1$s" — %2$d permissions granted. Reloading…', 'aisooq-connector' ),
					$who,
					count( $scopes )
				),
				'reload'  => true,
			)
		);
	}

	/** Group `resource.action` scopes into resource → [actions]. */
	private function group_scopes( $scopes ) {
		$groups = array();
		foreach ( (array) $scopes as $s ) {
			$s        = (string) $s;
			$parts    = explode( '.', $s, 2 );
			$resource = $parts[0];
			$action   = ( isset( $parts[1] ) && '' !== $parts[1] ) ? $parts[1] : $s;
			if ( ! isset( $groups[ $resource ] ) ) {
				$groups[ $resource ] = array();
			}
			if ( ! in_array( $action, $groups[ $resource ], true ) ) {
				$groups[ $resource ][] = $action;
			}
		}
		ksort( $groups );
		return $groups;
	}

	/**
	 * Backfill one entity type to the platform (the per-entity Sync buttons).
	 * Dispatches on the `entity` param: orders | products | customers |
	 * categories. Each is gated on its own enable-toggle.
	 */
	public function ajax_sync() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		if ( ! $this->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'aisooq-connector' ) ) );
		}

		$entity = isset( $_POST['entity'] ) ? sanitize_key( wp_unslash( $_POST['entity'] ) ) : 'orders';
		$plugin = AI_Sooq_Plugin::instance();

		switch ( $entity ) {
			case 'products':
				if ( ! $this->get( 'enable_product_sync' ) ) {
					wp_send_json_error( array( 'message' => __( 'Product sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->product_sync()->backfill( 200 );
				/* translators: %d: number of products queued. */
				$msg   = sprintf( _n( 'Queued %d product for sync.', 'Queued %d products for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'customers':
				if ( ! $this->get( 'enable_customer_sync' ) ) {
					wp_send_json_error( array( 'message' => __( 'Customer sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->customer_sync()->backfill( 500 );
				/* translators: %d: number of customers queued. */
				$msg   = sprintf( _n( 'Queued %d customer for sync.', 'Queued %d customers for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'categories':
				if ( ! $this->get( 'enable_category_sync' ) ) {
					wp_send_json_error( array( 'message' => __( 'Category sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->catalog_sync()->backfill_categories( 500 );
				/* translators: %d: number of categories queued. */
				$msg   = sprintf( _n( 'Queued %d category for sync.', 'Queued %d categories for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'orders':
			default:
				if ( ! $this->get( 'enable_orders' ) ) {
					wp_send_json_error( array( 'message' => __( 'Order sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->order_sync()->backfill( 100 );
				/* translators: %d: number of orders queued. */
				$msg   = sprintf( _n( 'Queued %d order for sync.', 'Queued %d orders for sync.', $count, 'aisooq-connector' ), $count );
				break;
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Data for the shipping-mapping card: the store's WooCommerce shipping
	 * methods (keyed by "<method_id>:<instance_id>" — the shipping line code)
	 * and the platform's shipping rates (fetched once, fail-soft).
	 *
	 * @return array{0:array<string,string>,1:array}
	 */
	private function shipping_map_data() {
		$methods = array();
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$list = array();
			foreach ( WC_Shipping_Zones::get_zones() as $z ) {
				$list[] = array( 'name' => $z['zone_name'], 'methods' => $z['shipping_methods'] );
			}
			$rest = WC_Shipping_Zones::get_zone( 0 );
			if ( $rest ) {
				$list[] = array( 'name' => __( 'Rest of the World', 'aisooq-connector' ), 'methods' => $rest->get_shipping_methods() );
			}
			foreach ( $list as $z ) {
				foreach ( (array) $z['methods'] as $mobj ) {
					if ( ! is_object( $mobj ) || ! isset( $mobj->id ) ) {
						continue;
					}
					$key             = $mobj->id . ':' . $mobj->instance_id;
					$methods[ $key ] = $z['name'] . ' — ' . $mobj->get_title();
				}
			}
		}

		$rates = array();
		if ( $this->is_configured() ) {
			$res = $this->cached_get( '/connect/shipping-rates' );
			if ( ! is_wp_error( $res ) && isset( $res['rates'] ) && is_array( $res['rates'] ) ) {
				$rates = $res['rates'];
			}
		}
		return array( $methods, $rates );
	}

	/**
	 * The settings screen, as sections rather than one scroll.
	 *
	 * There are ~40 controls here spanning six unrelated jobs — credentials,
	 * what syncs, fraud rules, shopper-facing copy, shipping ids, diagnostics.
	 * As one column that is a wall: the thing you came for is never where you
	 * look, and it all reads as equally important. So the fields are grouped by
	 * the job they belong to and shown one group at a time, WordPress's own
	 * nav-tab idiom rather than a bespoke widget, so it reads as part of the
	 * admin instead of a plugin's private universe.
	 *
	 * THE ONE RULE THIS LAYOUT MUST NOT BREAK: every panel stays in the DOM.
	 * Tabs toggle visibility, nothing more. This is a single form that posts
	 * every field on every save, and an unchecked box posts nothing at all — if
	 * a hidden tab's inputs were actually absent, saving from any other tab
	 * would silently switch off everything you couldn't see. Rendering all of
	 * them is also why this degrades correctly: with JS off the tab strip never
	 * appears and the page is exactly the long form it was before.
	 */
	private function sections() {
		return array(
			'connection' => array( 'icon' => 'link',              'label' => __( 'Connection', 'aisooq-connector' ) ),
			'sync'       => array( 'icon' => 'arrows-clockwise',  'label' => __( 'Sync', 'aisooq-connector' ) ),
			'fraud'      => array( 'icon' => 'shield-check',      'label' => __( 'Fraud & courier', 'aisooq-connector' ) ),
			'messages'   => array( 'icon' => 'chats',             'label' => __( 'Checkout messages', 'aisooq-connector' ) ),
			'shipping'   => array( 'icon' => 'map-pin',           'label' => __( 'Shipping', 'aisooq-connector' ) ),
			'advanced'   => array( 'icon' => 'gear',              'label' => __( 'Advanced', 'aisooq-connector' ) ),
		);
	}


	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s           = $this->all();
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		list( $ship_methods, $ship_rates ) = $this->shipping_map_data();

		$status = $this->status();
		// Back-fill the store profile for a connection that was verified before
		// the profile existed (its saved status has no `store`) — one ping, then
		// cached — so the store name/permissions show without a manual re-verify.
		if ( ! empty( $status['ok'] ) && empty( $status['store'] ) && $this->is_configured() ) {
			$ping = $this->cached_get( '/connect/ping' );
			if ( ! is_wp_error( $ping ) && isset( $ping['store'] ) && is_array( $ping['store'] ) ) {
				$status['store'] = $ping['store'];
				if ( isset( $ping['scopes'] ) && is_array( $ping['scopes'] ) ) {
					$status['scopes'] = $ping['scopes'];
				}
				update_option( self::STATUS_OPTION, $status );
			}
		}

		// Live platform fraud config (name/address/phone/IP layers) so the form
		// renders the real per-tenant values; null when disconnected.
		$fraud  = $this->load_platform_fraud( $status );
		$active = $this->is_active();
		$k      = $this->stats();
		$sections = $this->sections();
		?>
		<div class="wrap aisooq aisooq-app">
			<?php
			/*
			 * wp-admin needs an <h1> in `.wrap` — it is where it splices admin
			 * notices in, and a screen with no level-one heading is a real
			 * navigation failure for a screen-reader user. The app bar carries
			 * the visible identity, so this one is for those two jobs only.
			 */
			?>
			<h1 class="screen-reader-text"><?php esc_html_e( 'AI Sooq settings', 'aisooq-connector' ); ?></h1>
			<?php settings_errors( 'aisooq_connector' ); ?>

			<?php AI_Sooq_Admin_Shell::unsaved_bar(); ?>
			<?php
			AI_Sooq_Admin_Shell::app_bar( array(
				'name'   => __( 'AI Sooq', 'aisooq-connector' ),
				'verify' => true,
				'sync'   => array(
					'orders'     => __( 'Orders', 'aisooq-connector' ),
					'products'   => __( 'Products', 'aisooq-connector' ),
					'customers'  => __( 'Customers', 'aisooq-connector' ),
					'categories' => __( 'Categories', 'aisooq-connector' ),
				),
			) + AI_Sooq_Admin_Shell::state( $this ) );
			?>

			<div class="aisooq-body">
				<aside class="aisooq-side">
					<?php
					AI_Sooq_Admin_Shell::settings_nav( $sections );
					AI_Sooq_Admin_Shell::screen_links( AI_Sooq_Admin_Shell::other_screens( self::PAGE_SLUG ) );
					?>
				</aside>

				<main class="aisooq-main">
					<section class="aisooq-overview">
						<?php
						if ( ! empty( $status['ok'] ) ) {
							$store = isset( $status['store'] ) && is_array( $status['store'] ) ? $status['store'] : array();
							$sid   = isset( $status['sid'] ) ? $status['sid'] : '';
							AI_Sooq_Admin_Shell::store_bar(
								! empty( $store['name'] ) ? $store['name'] : $sid,
								$this->store_meta( $store )
							);
						}

						AI_Sooq_Admin_Shell::stats( $this->stat_cards( $k ) );

						$scopes = isset( $status['scopes'] ) && is_array( $status['scopes'] ) ? $status['scopes'] : array();
						AI_Sooq_Admin_Shell::disclosures(
							$this->setup_steps( $s, $status, $ship_methods ),
							count( $scopes ),
							$this->group_scopes( $scopes )
						);
						?>
					</section>

					<form method="post" action="" id="aisooq-settings-form">
						<?php wp_nonce_field( self::NONCE ); ?>

						<div class="aisooq-panels">
							<?php
							AI_Sooq_Settings_Fields::render_all( array(
								'settings'     => $s,
								'wc_statuses'  => $wc_statuses,
								'fraud'        => $fraud,
								'ship_methods' => $ship_methods,
								'ship_rates'   => $ship_rates,
							) );
							?>
						</div>

						<div class="aisooq-savebar">
							<button type="submit" id="aisooq-save" name="aisooq_save" value="1" class="aisooq-btn aisooq-btn--primary"><?php esc_html_e( 'Save', 'aisooq-connector' ); ?></button>
							<?php echo AI_Sooq_Settings_Fields::hint( __( 'Save first, then use Verify / Sync now above.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hint(). ?>
						</div>
					</form>

					<?php AI_Sooq_Admin_Shell::main_links( AI_Sooq_Admin_Shell::other_screens( self::PAGE_SLUG ) ); ?>
					<?php AI_Sooq_Admin_Shell::footer( AISOOQ_VERSION ); ?>
				</main>
			</div>
		</div>
		<?php
		$this->render_page_script();
	}

	/**
	 * The store strip's rows, minus the ones this platform did not send.
	 *
	 * Deliberately does NOT include the last-verified time. It is already the
	 * second half of the app bar's connection tooltip, and a fifth row wrapped
	 * this strip onto a second line at every width it was drawn for.
	 */
	private function store_meta( array $store ) {
		$candidates = array(
			array( 'icon' => 'phone',           'label' => __( 'Contact', 'aisooq-connector' ),  'value' => isset( $store['contactPhone'] ) ? $store['contactPhone'] : '' ),
			array( 'icon' => 'envelope-simple', 'label' => __( 'Email', 'aisooq-connector' ),    'value' => isset( $store['email'] ) ? $store['email'] : '' ),
			array( 'icon' => 'globe',           'label' => __( 'Domain', 'aisooq-connector' ),   'value' => isset( $store['domain'] ) ? $store['domain'] : '' ),
			array(
				'icon'  => 'coins',
				'label' => __( 'Currency · Country', 'aisooq-connector' ),
				'value' => trim( ( isset( $store['currency'] ) ? $store['currency'] : '' ) . ' · ' . ( isset( $store['country'] ) ? $store['country'] : '' ), ' ·' ),
			),
		);

		$rows = array();
		foreach ( $candidates as $row ) {
			if ( '' !== trim( (string) $row['value'] ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** The six figures, in the order the design puts them. */
	private function stat_cards( array $k ) {
		return array(
			array( 'icon' => 'shopping-cart',        'label' => __( 'Orders', 'aisooq-connector' ),    'value' => number_format_i18n( $k['orders_synced'] ), 'sub' => __( 'Orders synced', 'aisooq-connector' ), 'tone' => '' ),
			array( 'icon' => 'arrows-clockwise',     'label' => __( 'Queue', 'aisooq-connector' ),     'value' => $this->kpi_num( $k['queue'] ),  'sub' => __( 'In queue — awaiting push', 'aisooq-connector' ), 'tone' => $k['queue'] > 0 ? 'is-warn' : '' ),
			array(
				'icon'  => 'warning-circle',
				'label' => __( 'Failed', 'aisooq-connector' ),
				'value' => $this->kpi_num( $k['failed'] ),
				// Counts orders that exhausted their retries — they are NOT
				// still retrying, and saying so was the reason a merchant could
				// watch this tile read zero-and-fine while orders quietly
				// stopped syncing.
				'sub'   => sprintf(
					/* translators: %d: the retry limit. */
					__( 'Gave up after %d attempts', 'aisooq-connector' ),
					(int) AI_Sooq_Order_Sync::MAX_ATTEMPTS
				),
				'tone'  => $k['failed'] > 0 ? 'is-err' : '',
			),
			array( 'icon' => 'shopping-cart-simple', 'label' => __( 'Carts', 'aisooq-connector' ),     'value' => number_format_i18n( $k['abandoned'] ), 'sub' => __( 'Abandoned carts pushed', 'aisooq-connector' ), 'tone' => '' ),
			array( 'icon' => 'bag',                  'label' => __( 'Products', 'aisooq-connector' ),  'value' => number_format_i18n( $k['products'] ),  'sub' => __( 'Products synced', 'aisooq-connector' ), 'tone' => '' ),
			array( 'icon' => 'users',                'label' => __( 'Customers', 'aisooq-connector' ), 'value' => number_format_i18n( $k['customers'] ), 'sub' => __( 'Customers synced', 'aisooq-connector' ), 'tone' => '' ),
		);
	}

	/**
	 * The setup guide, with each step's "done" derived from the real settings
	 * rather than remembered in an option.
	 *
	 * Deriving it is the whole point: a stored checklist goes stale the moment
	 * someone clears a credential, and then the guide cheerfully reports a
	 * finished setup for a store that is not syncing.
	 */
	private function setup_steps( array $s, array $status, array $ship_methods ) {
		$mapped = true;
		if ( $ship_methods ) {
			$map = (array) $s['shipping_map'];
			foreach ( array_keys( $ship_methods ) as $method ) {
				if ( empty( $map[ $method ] ) ) {
					$mapped = false;
					break;
				}
			}
		}

		return array(
			array(
				'label' => __( 'Add credentials', 'aisooq-connector' ),
				'done'  => '' !== $s['api_base'] && '' !== $s['client_id'] && '' !== $s['sid'],
				'tab'   => 'connection',
			),
			array(
				'label' => __( 'Verify connection', 'aisooq-connector' ),
				'done'  => ! empty( $status['ok'] ),
				// Not a place you can navigate to — it is the button in the bar.
				'tab'   => '',
			),
			array(
				'label' => __( 'Choose what to sync', 'aisooq-connector' ),
				'done'  => ! empty( $s['enable_orders'] ) || ! empty( $s['enable_abandoned'] ),
				'tab'   => 'sync',
			),
			array(
				'label' => __( 'Turn on fraud screening', 'aisooq-connector' ),
				'done'  => ! empty( $s['enable_fraud'] ),
				'tab'   => 'fraud',
			),
			array(
				'label' => __( 'Map shipping', 'aisooq-connector' ),
				'done'  => $mapped,
				'tab'   => 'shipping',
			),
		);
	}

	/**
	 * The screen's behaviour. Small jobs, no framework:
	 * tab switching, search, dependent-field dimming, the unsaved bar, and the
	 * pre-existing Verify / Sync calls.
	 *
	 * Printed inline rather than enqueued because it is single-screen and needs
	 * a nonce and several translated strings interpolated into it.
	 *
	 * EVERY PROGRESSIVE-ENHANCEMENT DECISION HERE POINTS THE SAME WAY: nothing
	 * below is required to use the page. Panels are hidden by this script and
	 * by nothing else, so a blocked or broken script leaves all six visible,
	 * each under its own heading, with one Save at the bottom that still works.
	 */
	private function render_page_script() {
		$default = key( $this->sections() );
		?>
		<script>
		( function () {
			var wrap = document.querySelector( '.wrap.aisooq-app' );
			if ( ! wrap ) { return; }

			/* ── Tabs ────────────────────────────────────────────────────────
			 * One tablist at every width. The nav is a column on a desktop and
			 * a scrolling row of pills on a phone, but it is the same six
			 * buttons either way — see AI_Sooq_Admin_Shell::sidebar() for why
			 * rendering a second set was wrong. */
			var tabs   = [].slice.call( wrap.querySelectorAll( '.aisooq-tab' ) );
			var panels = [].slice.call( wrap.querySelectorAll( '.aisooq-panel' ) );
			var KEY    = 'aisooq_settings_tab';
			var current = '';

			function show( name, focusTab ) {
				if ( ! name || ! panels.some( function ( p ) { return p.dataset.panel === name; } ) ) {
					name = <?php echo wp_json_encode( $default ); ?>;
				}
				current = name;
				panels.forEach( function ( p ) { p.hidden = p.dataset.panel !== name; } );
				tabs.forEach( function ( t ) {
					var on = t.dataset.tab === name;
					t.classList.toggle( 'is-active', on );
					t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					t.tabIndex = on ? 0 : -1;
					if ( on && focusTab ) { t.focus(); }
					// Keep the active item in view while the nav is a scroller.
					if ( on && t.scrollIntoView ) { t.scrollIntoView( { block: 'nearest', inline: 'nearest' } ); }
				} );
				try { window.sessionStorage.setItem( KEY, name ); } catch ( e ) {}
				if ( history.replaceState ) {
					history.replaceState( null, '', '#' + name );
				}
			}

			tabs.forEach( function ( t, i ) {
				t.addEventListener( 'click', function () { show( t.dataset.tab ); } );
				// Roving focus: a tablist is one stop, arrows move within it.
				t.addEventListener( 'keydown', function ( e ) {
					/*
					 * Both axes are accepted because the nav is a column on a
					 * desktop and a row on a phone, and the same markup serves
					 * both. Binding only one pair would leave whichever arrow
					 * matches what the user sees doing nothing.
					 */
					var d = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1, Home: -Infinity, End: Infinity }[ e.key ];
					if ( undefined === d ) { return; }
					e.preventDefault();
					var next = d === -Infinity ? 0 : d === Infinity ? tabs.length - 1 : ( i + d + tabs.length ) % tabs.length;
					show( tabs[ next ].dataset.tab, true );
				} );
			} );
			// The setup guide's steps are shortcuts to the tab that finishes them.
			[].slice.call( wrap.querySelectorAll( '[data-goto]' ) ).forEach( function ( b ) {
				b.addEventListener( 'click', function () { show( b.dataset.goto ); } );
			} );

			var stored = '';
			try { stored = window.sessionStorage.getItem( KEY ) || ''; } catch ( e ) {}
			show( ( location.hash || '' ).replace( '#', '' ) || stored );

			/* ── Search ──────────────────────────────────────────────────────
			 * With six tabs, "which tab is that in?" is the problem the
			 * grouping created. The index is built from the rendered labels
			 * rather than from a hand-kept list, so a setting added to a panel
			 * is findable the same day without anyone remembering to register
			 * it — the failure mode of every hardcoded search index. */
			var index = [];
			panels.forEach( function ( p ) {
				var tabBtn = wrap.querySelector( '#aisooq-tab-' + p.dataset.panel );
				var tabLbl = tabBtn ? ( tabBtn.querySelector( 'span' ) || {} ).textContent || '' : '';
				[].slice.call( p.querySelectorAll( '.aisooq-field, .aisooq-check, .aisooq-row-item' ) ).forEach( function ( f ) {
					var label = f.querySelector( '.h, .aisooq-row-item__label' );
					var text  = ( label ? label.textContent : f.textContent ) || '';
					text = text.replace( /\s+/g, ' ' ).trim();
					if ( ! text ) { return; }
					// A whole field's prose makes every query match everything.
					if ( text.length > 60 ) { text = text.slice( 0, 60 ).trim() + '…'; }
					index.push( { text: text, hay: text.toLowerCase(), tab: p.dataset.panel, tabLabel: tabLbl, el: f } );
				} );
			} );

			var NO_MATCH = <?php echo wp_json_encode( __( 'No matches', 'aisooq-connector' ) ); ?>;

			function wireSearch( input ) {
				if ( ! input ) { return; }
				var box = input.closest( '.aisooq-search' );
				var pop = null;
				var hits = [];
				var at = -1;

				function close() {
					if ( pop ) { pop.remove(); pop = null; }
					at = -1;
					input.setAttribute( 'aria-expanded', 'false' );
				}

				function pick( h ) {
					close();
					input.value = '';
					show( h.tab );
					// Highlight rather than only scroll: on a short panel the
					// scroll is a no-op and the jump looks like nothing happened.
					h.el.classList.add( 'is-found' );
					setTimeout( function () { h.el.classList.remove( 'is-found' ); }, 1600 );
					if ( h.el.scrollIntoView ) { h.el.scrollIntoView( { block: 'center' } ); }
					var focusable = h.el.querySelector( 'input, select, textarea' );
					if ( focusable ) { focusable.focus( { preventScroll: true } ); }
				}

				function render() {
					if ( pop ) { pop.remove(); }
					pop = document.createElement( 'div' );
					pop.className = 'aisooq-results';
					pop.setAttribute( 'role', 'listbox' );
					if ( ! hits.length ) {
						var none = document.createElement( 'div' );
						none.className = 'aisooq-results__none';
						none.textContent = NO_MATCH;
						pop.appendChild( none );
					}
					hits.forEach( function ( h, i ) {
						var b = document.createElement( 'button' );
						b.type = 'button';
						b.className = 'aisooq-results__item' + ( i === at ? ' is-active' : '' );
						b.setAttribute( 'role', 'option' );
						b.setAttribute( 'aria-selected', i === at ? 'true' : 'false' );
						var l = document.createElement( 'span' );
						l.textContent = h.text;
						var t = document.createElement( 'span' );
						t.className = 'aisooq-results__tab';
						t.textContent = h.tabLabel;
						b.appendChild( l );
						b.appendChild( t );
						b.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); pick( h ); } );
						pop.appendChild( b );
					} );
					box.appendChild( pop );
					input.setAttribute( 'aria-expanded', 'true' );
				}

				input.setAttribute( 'role', 'combobox' );
				input.setAttribute( 'aria-expanded', 'false' );
				input.setAttribute( 'aria-autocomplete', 'list' );

				input.addEventListener( 'input', function () {
					var q = input.value.trim().toLowerCase();
					if ( ! q ) { close(); return; }
					var seen = {};
					hits = index.filter( function ( r ) {
						if ( r.hay.indexOf( q ) === -1 || seen[ r.text ] ) { return false; }
						seen[ r.text ] = 1;
						return true;
					} ).slice( 0, 8 );
					at = hits.length ? 0 : -1;
					render();
				} );

				input.addEventListener( 'keydown', function ( e ) {
					if ( 'Escape' === e.key ) { input.value = ''; close(); return; }
					if ( ! pop || ! hits.length ) { return; }
					if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
						e.preventDefault();
						at = ( at + ( 'ArrowDown' === e.key ? 1 : -1 ) + hits.length ) % hits.length;
						render();
					} else if ( 'Enter' === e.key ) {
						e.preventDefault();
						pick( hits[ at < 0 ? 0 : at ] );
					}
				} );

				input.addEventListener( 'blur', function () { setTimeout( close, 120 ); } );
			}
			wireSearch( document.getElementById( 'aisooq-find' ) );

			/* ── Status chips ────────────────────────────────────────────── */
			[].slice.call( wrap.querySelectorAll( '.aisooq-chip input[type="checkbox"]' ) ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					cb.closest( '.aisooq-chip' ).classList.toggle( 'is-on', cb.checked );
				} );
			} );

			/* ── Dependent fields ────────────────────────────────────────────
			 * Dimmed, not hidden: an operator who came to change this setting
			 * must still find it, and a control that disappears reads as a
			 * missing feature rather than an inactive one. */
			function syncDeps() {
				[].slice.call( wrap.querySelectorAll( '[data-requires]' ) ).forEach( function ( f ) {
					var src = wrap.querySelector( '[name="aisooq[' + f.dataset.requires + ']"]' );
					var on  = ! src || src.checked;
					f.classList.toggle( 'is-inactive', ! on );
					var note = f.querySelector( '.aisooq-dep-note' );
					if ( ! note ) {
						note = document.createElement( 'p' );
						note.className = 'description aisooq-dep-note';
						f.appendChild( note );
					}
					note.textContent = f.dataset.requiresNote;
					note.hidden = on;
				} );
			}
			syncDeps();

			/* ── Unsaved changes ─────────────────────────────────────────────
			 * Most of the form is off-screen behind a tab, so an edit made two
			 * tabs ago is easy to walk away from. The bar is the only thing
			 * that says so, and it offers both ways out. */
			var form    = document.getElementById( 'aisooq-settings-form' );
			var bar     = document.getElementById( 'aisooq-unsaved' );
			var isDirty = false;
			if ( form ) {
				// Compare against the state the page loaded with, rather than
				// latching on the first input event. A password manager filling
				// the secret field fires a real input event on load, and a
				// "you have unsaved changes" warning nobody caused is the kind
				// of false alarm that teaches people to click through warnings.
				var serialise = function () {
					var out = [];
					[].slice.call( form.elements ).forEach( function ( el ) {
						if ( ! el.name ) { return; }
						out.push( el.name + '=' + ( ( 'checkbox' === el.type || 'radio' === el.type ) ? ( el.checked ? 1 : 0 ) : el.value ) );
					} );
					return out.join( '&' );
				};
				var initial = serialise();

				var recheck = function () {
					var now = serialise() !== initial;
					if ( now === isDirty ) { return; }
					isDirty = now;
					if ( bar ) { bar.hidden = ! now; }
					wrap.classList.toggle( 'is-dirty', now );
				};
				form.addEventListener( 'input', recheck );
				form.addEventListener( 'change', function () { syncDeps(); recheck(); } );
				form.addEventListener( 'submit', function () { isDirty = false; } );
				window.addEventListener( 'beforeunload', function ( e ) {
					if ( ! isDirty ) { return; }
					e.preventDefault();
					e.returnValue = '';
				} );

				var proxy = document.getElementById( 'aisooq-save-proxy' );
				if ( proxy ) {
					/*
					 * Clicks the one real submit rather than being a second one
					 * — see the comment where this button is printed. Found by
					 * id and not by a name attribute selector: this comment is
					 * JavaScript, so it is printed into the page, and spelling
					 * the submit's name out here would put that string in the
					 * source a second time. test-settings-page.php counts those
					 * occurrences to prove there is only one such control, and
					 * a comment must not be what breaks it.
					 */
					proxy.addEventListener( 'click', function () {
						var real = document.getElementById( 'aisooq-save' );
						if ( real ) { real.click(); }
					} );
				}
				var discard = document.getElementById( 'aisooq-discard' );
				if ( discard ) {
					discard.addEventListener( 'click', function () {
						form.reset();
						// `reset()` restores the DOM defaults, which are the
						// values this page rendered with — the same baseline
						// `initial` was taken from. Everything derived from
						// those values has to be recomputed by hand.
						[].slice.call( wrap.querySelectorAll( '.aisooq-chip input[type="checkbox"]' ) ).forEach( function ( cb ) {
							cb.closest( '.aisooq-chip' ).classList.toggle( 'is-on', cb.checked );
						} );
						syncDeps();
						recheck();
					} );
				}
			}

			/* ── Verify + Sync ───────────────────────────────────────────── */
			var out   = document.getElementById( 'aisooq-test-result' );
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var toastEl = null;
			var toastT  = null;

			/*
			 * The toast is the design's feedback, and it is `aria-hidden` on
			 * purpose: `out` in the app bar already carries the same words in a
			 * live region that does not vanish after two seconds. Announcing
			 * both would say everything twice.
			 */
			function toast( msg, ok ) {
				if ( ! toastEl ) {
					toastEl = document.createElement( 'div' );
					toastEl.className = 'aisooq-toast';
					toastEl.setAttribute( 'aria-hidden', 'true' );
					wrap.appendChild( toastEl );
				}
				toastEl.className = 'aisooq-toast' + ( ok ? '' : ' is-err' );
				toastEl.textContent = msg;
				toastEl.hidden = false;
				clearTimeout( toastT );
				toastT = setTimeout( function () { toastEl.hidden = true; }, 2600 );
			}

			// Every button that can start one of these requests. Disabling them
			// for the duration is not cosmetic: without it a second click queues
			// a second backfill, and `aria-busy` is the only signal a
			// screen-reader user gets that anything is happening at all.
			var actionBtns = [].slice.call( document.querySelectorAll( '#aisooq-test-connection, .aisooq-sync' ) );
			function setBusy( busy ) {
				actionBtns.forEach( function ( b ) {
					b.disabled = busy;
					b.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
				} );
			}
			function call( action, pending, entity ) {
				setBusy( true );
				out.textContent = pending;
				out.className = 'aisooq-appbar__result is-pending';
				var data = new FormData();
				data.append( 'action', action );
				data.append( 'nonce', nonce );
				if ( entity ) { data.append( 'entity', entity ); }
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						setBusy( false );
						var ok  = !! ( j && j.success );
						var msg = ( j && j.data && j.data.message ) ? j.data.message : 'Error';
						out.textContent = msg;
						out.className = 'aisooq-appbar__result ' + ( ok ? 'is-ok' : 'is-err' );
						toast( msg, ok );
						// Verify success returns a fresh store profile + permissions;
						// reload to render the overview from the saved status.
						if ( ok && j.data && j.data.reload ) {
							isDirty = false;
							setBusy( true ); // the page is about to go
							setTimeout( function () { location.reload(); }, 900 );
						}
					} )
					.catch( function () {
						setBusy( false );
						var msg = <?php echo wp_json_encode( __( 'Request failed', 'aisooq-connector' ) ); ?>;
						out.textContent = msg;
						out.className = 'aisooq-appbar__result is-err';
						toast( msg, false );
					} );
			}
			var t = document.getElementById( 'aisooq-test-connection' );
			if ( t ) { t.addEventListener( 'click', function () { call( 'aisooq_test', <?php echo wp_json_encode( __( 'Verifying…', 'aisooq-connector' ) ); ?> ); } ); }
			var pending = <?php echo wp_json_encode( __( 'Queueing…', 'aisooq-connector' ) ); ?>;
			[].slice.call( document.querySelectorAll( '.aisooq-sync' ) ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					call( 'aisooq_sync', pending, b.getAttribute( 'data-entity' ) );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
