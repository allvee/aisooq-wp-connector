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

class AI_Sooq_Settings {

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
			$stats['failed'] = count( (array) as_get_scheduled_actions( array_merge( $base, array( 'status' => 'failed' ) ), 'ids' ) );
		}

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
	}

	/**
	 * Recolour the top-level admin-menu icon: white in the resting state,
	 * marigold when the item is current/open/hovered. WordPress renders the
	 * base64 icon as a dimmed background/img and can't tint it, so we hide that
	 * and paint the same glyph as a CSS mask whose colour we control. Printed in
	 * admin_head (all screens) because the menu shows everywhere.
	 */
	public function menu_icon_css() {
		$icon = self::menu_icon();
		$sel  = '#toplevel_page_' . self::PAGE_SLUG;
		echo '<style id="aisooq-menu-icon">'
			. $sel . ' .wp-menu-image,' . $sel . ' .wp-menu-image.svg{background-image:none !important;}'
			. $sel . ' .wp-menu-image img{opacity:0 !important;}'
			. $sel . ' .wp-menu-image{position:relative;}'
			. $sel . ' .wp-menu-image:after{content:"";position:absolute;top:7px;left:0;right:0;margin:0 auto;width:20px;height:20px;background-color:#fff;'
			. '-webkit-mask:url(\'' . $icon . '\') center/20px no-repeat;mask:url(\'' . $icon . '\') center/20px no-repeat;transition:background-color .15s ease;}'
			. $sel . ':hover .wp-menu-image:after,'
			. $sel . '.current .wp-menu-image:after,'
			. $sel . '.wp-has-current-submenu .wp-menu-image:after,'
			. $sel . '.opensub .wp-menu-image:after{background-color:#FDC137;}'
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
		$clean['api_base']              = untrailingslashit( esc_url_raw( isset( $raw['api_base'] ) ? $raw['api_base'] : '' ) );
		$clean['storefront_base']       = untrailingslashit( esc_url_raw( isset( $raw['storefront_base'] ) ? $raw['storefront_base'] : '' ) );
		$clean['sid']                   = sanitize_text_field( isset( $raw['sid'] ) ? $raw['sid'] : '' );
		$clean['client_id']             = sanitize_text_field( isset( $raw['client_id'] ) ? $raw['client_id'] : '' );
		// Secret is write-only in the UI: blank submit keeps the stored value.
		$secret_in                      = isset( $raw['client_secret'] ) ? trim( $raw['client_secret'] ) : '';
		$clean['client_secret']         = ( '' === $secret_in ) ? $existing['client_secret'] : sanitize_text_field( $secret_in );
		$clean['enable_orders']         = empty( $raw['enable_orders'] ) ? 0 : 1;
		$clean['enable_abandoned']      = empty( $raw['enable_abandoned'] ) ? 0 : 1;
		$clean['enable_analytics']      = empty( $raw['enable_analytics'] ) ? 0 : 1;
		$clean['enable_fraud']          = empty( $raw['enable_fraud'] ) ? 0 : 1;
		$fraud_action                   = isset( $raw['fraud_action'] ) ? sanitize_key( $raw['fraud_action'] ) : 'block';
		$clean['fraud_action']          = in_array( $fraud_action, array( 'block', 'hold', 'flag' ), true ) ? $fraud_action : 'block';
		$clean['courier_min_ratio']     = max( 0, min( 100, absint( isset( $raw['courier_min_ratio'] ) ? $raw['courier_min_ratio'] : 0 ) ) );
		$clean['courier_min_parcels']   = max( 1, absint( isset( $raw['courier_min_parcels'] ) ? $raw['courier_min_parcels'] : 3 ) );
		$clean['support_phone']         = sanitize_text_field( isset( $raw['support_phone'] ) ? $raw['support_phone'] : '' );
		$clean['support_whatsapp']      = sanitize_text_field( isset( $raw['support_whatsapp'] ) ? $raw['support_whatsapp'] : '' );
		$clean['support_messenger']     = esc_url_raw( isset( $raw['support_messenger'] ) ? trim( $raw['support_messenger'] ) : '' );
		foreach ( array( 'msg_courier', 'msg_fraud_contact', 'msg_fraud_velocity', 'msg_fraud_generic', 'msg_help' ) as $mk ) {
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

		update_option( AISOOQ_OPTION, $clean );
		$this->cache = null;
		// Reset the cached token whenever credentials might have changed.
		delete_transient( AISOOQ_TOKEN_TRANSIENT );

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
		$fraud = isset( $_POST['aisooq_fraud'] ) && is_array( $_POST['aisooq_fraud'] ) ? wp_unslash( $_POST['aisooq_fraud'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in maybe_save()
		if ( null === $fraud ) {
			return; // fraud card not on this submit
		}
		$status = get_option( self::STATUS_OPTION, array() );
		if ( empty( $status['ok'] ) ) {
			add_settings_error( 'aisooq_connector', 'fraud_offline', __( 'Fraud layers not saved to the platform — connect the store first (Verify connection).', 'aisooq-connector' ), 'warning' );
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
	 * Read the platform fraud config for the settings form (GET
	 * /connect/fraud-config). Returns the config array, or null when the store
	 * isn't connected / the call fails (the form then shows a connect prompt).
	 */
	private function load_platform_fraud( $status ) {
		if ( empty( $status['ok'] ) ) {
			return null;
		}
		$res = AI_Sooq_Plugin::instance()->api()->get( '/connect/fraud-config' );
		return is_wp_error( $res ) || ! is_array( $res ) ? null : $res;
	}

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
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

	/**
	 * "Connected to" panel: the tenant/store profile + the OAuth permissions this
	 * plugin was granted, grouped by resource with read/write badges, so the
	 * operator can see at a glance what the connection can do.
	 */
	private function render_connection_panel( $status ) {
		if ( empty( $status['ok'] ) ) {
			return;
		}
		$store   = isset( $status['store'] ) && is_array( $status['store'] ) ? $status['store'] : array();
		$scopes  = isset( $status['scopes'] ) && is_array( $status['scopes'] ) ? $status['scopes'] : array();
		$sid     = isset( $status['sid'] ) ? $status['sid'] : '';
		$name    = ! empty( $store['name'] ) ? $store['name'] : $sid;
		$initial = $name ? mb_strtoupper( mb_substr( (string) $name, 0, 1 ) ) : 'S';
		$groups  = $this->group_scopes( $scopes );
		?>
		<div class="aisooq-conn">
			<div class="aisooq-conn__top">
				<span class="aisooq-conn__avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
				<div style="min-width:0;">
					<div class="aisooq-conn__name"><?php echo esc_html( $name ); ?></div>
					<div class="aisooq-dim" style="font-size:12px;font-family:monospace;"><?php echo esc_html( $sid ); ?></div>
				</div>
			</div>
			<div class="aisooq-conn__meta">
				<?php if ( ! empty( $store['contactPhone'] ) ) : ?><span><?php esc_html_e( 'Contact', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $store['contactPhone'] ); ?></b></span><?php endif; ?>
				<?php if ( ! empty( $store['email'] ) ) : ?><span><?php esc_html_e( 'Email', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $store['email'] ); ?></b></span><?php endif; ?>
				<?php if ( ! empty( $store['currency'] ) ) : ?><span><?php esc_html_e( 'Currency', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $store['currency'] ); ?></b></span><?php endif; ?>
				<?php if ( ! empty( $store['country'] ) ) : ?><span><?php esc_html_e( 'Country', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $store['country'] ); ?></b></span><?php endif; ?>
				<?php if ( ! empty( $store['domain'] ) ) : ?><span><?php esc_html_e( 'Domain', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $store['domain'] ); ?></b></span><?php endif; ?>
				<?php if ( ! empty( $status['time'] ) ) : ?><span><?php esc_html_e( 'Verified', 'aisooq-connector' ); ?>: <b><?php echo esc_html( $status['time'] ); ?></b></span><?php endif; ?>
			</div>
			<?php if ( $groups ) : ?>
				<div class="aisooq-perms">
					<p class="aisooq-perms__h"><?php echo esc_html( sprintf( _n( '%d permission granted to this plugin', '%d permissions granted to this plugin', count( $scopes ), 'aisooq-connector' ), count( $scopes ) ) ); ?></p>
					<div class="aisooq-perms__grid">
						<?php foreach ( $groups as $resource => $actions ) : ?>
							<div class="aisooq-perm">
								<div class="aisooq-perm__name"><span class="dashicons <?php echo esc_attr( $this->perm_icon( $resource ) ); ?>" aria-hidden="true"></span> <?php echo esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $resource ) ) ); ?></div>
								<div class="aisooq-perm__acts">
									<?php foreach ( $actions as $a ) : $cls = ( 'read' === $a ? 'read' : ( 'write' === $a ? 'write' : 'other' ) ); ?>
										<span class="aisooq-act-badge <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $a ); ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
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

	/** Dashicon for a permission resource group. */
	private function perm_icon( $resource ) {
		$map = array(
			'orders'      => 'dashicons-cart',
			'products'    => 'dashicons-products',
			'customers'   => 'dashicons-groups',
			'categories'  => 'dashicons-category',
			'brands'      => 'dashicons-tag',
			'collections' => 'dashicons-portfolio',
			'inventory'   => 'dashicons-archive',
			'analytics'   => 'dashicons-chart-bar',
			'fraud'       => 'dashicons-shield',
			'courier'     => 'dashicons-airplane',
			'shipping'    => 'dashicons-airplane',
		);
		return isset( $map[ $resource ] ) ? $map[ $resource ] : 'dashicons-admin-network';
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
				$msg   = sprintf( _n( 'Queued %d product for sync.', 'Queued %d products for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'customers':
				if ( ! $this->get( 'enable_customer_sync' ) ) {
					wp_send_json_error( array( 'message' => __( 'Customer sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->customer_sync()->backfill( 500 );
				$msg   = sprintf( _n( 'Queued %d customer for sync.', 'Queued %d customers for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'categories':
				if ( ! $this->get( 'enable_category_sync' ) ) {
					wp_send_json_error( array( 'message' => __( 'Category sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->catalog_sync()->backfill_categories( 500 );
				$msg   = sprintf( _n( 'Queued %d category for sync.', 'Queued %d categories for sync.', $count, 'aisooq-connector' ), $count );
				break;
			case 'orders':
			default:
				if ( ! $this->get( 'enable_orders' ) ) {
					wp_send_json_error( array( 'message' => __( 'Order sync is turned off.', 'aisooq-connector' ) ) );
				}
				$count = $plugin->order_sync()->backfill( 100 );
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
			$res = AI_Sooq_Plugin::instance()->api()->get( '/connect/shipping-rates' );
			if ( ! is_wp_error( $res ) && isset( $res['rates'] ) && is_array( $res['rates'] ) ) {
				$rates = $res['rates'];
			}
		}
		return array( $methods, $rates );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s          = $this->all();
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		list( $ship_methods, $ship_rates ) = $this->shipping_map_data();
		settings_errors( 'aisooq_connector' );
		?>
		<?php
		$status = $this->status();
		// Back-fill the store profile for a connection that was verified before
		// the profile existed (its saved status has no `store`) — one ping, then
		// cached — so the store name/permissions show without a manual re-verify.
		if ( ! empty( $status['ok'] ) && empty( $status['store'] ) && $this->is_configured() ) {
			$ping = AI_Sooq_Plugin::instance()->api()->get( '/connect/ping' );
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
		$fraud = $this->load_platform_fraud( $status );
		$active = $this->is_active();
		$k      = $this->stats();
		if ( ! $active ) {
			$badge_class = 'warn';
			$badge_text  = __( 'Paused', 'aisooq-connector' );
		} elseif ( ! empty( $status['ok'] ) ) {
			$badge_class = 'ok';
			$badge_text  = sprintf( /* translators: %s: store sid */ __( 'Connected · %s', 'aisooq-connector' ), isset( $status['sid'] ) ? $status['sid'] : '?' );
		} else {
			$badge_class = 'err';
			$badge_text  = __( 'Not verified', 'aisooq-connector' );
		}
		?>
		<div class="wrap aisooq">
			<style>
				.sp{--pri:#2271b1;--ok:#00844a;--warn:#996800;--err:#b32d2e;--bd:#dcdcde;--muted:#646970}
				.sp h1.wp-heading-inline{margin:0}
				.aisooq-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid var(--bd);border-radius:8px;padding:16px 20px;margin:16px 0}
				.aisooq-hero__title{margin:0;font-size:20px;line-height:1.2}
				.aisooq-hero__sub{margin:4px 0 0;color:var(--muted);font-size:13px}
				.aisooq-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.aisooq-sync-group{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;padding:4px 8px;border:1px solid var(--bd);border-radius:6px}
				.aisooq-sync-label{font-size:12px;font-weight:600;color:var(--muted)}
				.aisooq-sync-group .button{padding:0 8px}
				.aisooq-badge{display:inline-flex;align-items:center;gap:7px;font-weight:600;padding:5px 13px;border-radius:999px;font-size:13px}
				.aisooq-badge::before{content:'';width:8px;height:8px;border-radius:50%;background:currentColor}
				.aisooq-badge.ok{background:#edfaef;color:var(--ok)}.aisooq-badge.warn{background:#fcf5e6;color:var(--warn)}.aisooq-badge.err{background:#fcebea;color:var(--err)}
				.aisooq-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:14px;margin:0 0 22px}
				.aisooq-kpi{background:#fff;border:1px solid var(--bd);border-left:3px solid var(--pri);border-radius:8px;padding:14px 16px}
				.aisooq-kpi.warn{border-left-color:var(--warn)}.aisooq-kpi.err{border-left-color:var(--err)}
				.aisooq-kpi__label{display:flex;align-items:center;gap:6px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
				.aisooq-kpi__label .dashicons{font-size:15px;width:15px;height:15px;color:var(--pri)}
				.aisooq-kpi__num{font-size:26px;font-weight:700;line-height:1.15;margin-top:7px;font-variant-numeric:tabular-nums;color:#1d2327}
				.aisooq-kpi__sub{font-size:12px;color:var(--muted);margin-top:2px}
				.aisooq-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}
				.aisooq-card{background:#fff;border:1px solid var(--bd);border-radius:8px;overflow:hidden}
				.aisooq-card__head{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--bd);font-weight:600;font-size:14px}
				.aisooq-card__head .dashicons{color:var(--pri)}
				.aisooq-card__body{padding:16px}
				.aisooq-field{margin:0 0 16px}.aisooq-field:last-child{margin-bottom:0}
				.aisooq-field>label.h{display:block;font-weight:600;margin-bottom:5px}
				.aisooq-field .description{margin:5px 0 0}
				.aisooq-field input[type=url],.aisooq-field input[type=text],.aisooq-field input[type=password],.aisooq-field select{width:100%;max-width:100%}
				.aisooq-check{display:block;margin:0 0 8px}.aisooq-check:last-child{margin-bottom:0}
				.aisooq-help{background:#fff;border:1px solid var(--bd);border-radius:8px;padding:6px 16px;margin:0 0 16px}
				.aisooq-help summary{cursor:pointer;font-weight:600;padding:8px 0}
				@media(max-width:782px){.aisooq-hero{flex-direction:column;align-items:flex-start}}
				@media(max-width:600px){
					.aisooq-hero{padding:14px}
					.aisooq-actions{width:100%}
					.aisooq-sync-group{width:100%;justify-content:flex-start}
					.aisooq-sync-group .button{flex:1 1 auto}
					.aisooq-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
					.aisooq-kpi__num{font-size:22px}
					.aisooq-grid{grid-template-columns:1fr}
					.aisooq-field label.h{font-size:13px}
				}
				@media(max-width:400px){.aisooq-kpis{grid-template-columns:1fr}}
				.sp a:focus-visible,.sp button:focus-visible,.sp input:focus-visible,.sp select:focus-visible{outline:2px solid var(--pri);outline-offset:1px;border-radius:4px}
				@media(prefers-reduced-motion:reduce){.sp *{transition:none !important;animation:none !important}}
				.aisooq-conn{background:#fff;border:1px solid var(--bd);border-radius:8px;padding:16px 18px;margin:0 0 16px}
				.aisooq-conn__top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
				.aisooq-conn__avatar{width:40px;height:40px;border-radius:10px;background:var(--pri);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex:0 0 auto}
				.aisooq-conn__name{font-size:16px;font-weight:700;line-height:1.2;color:#1d2327}
				.aisooq-conn__meta{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:10px;font-size:12.5px;color:var(--muted)}
				.aisooq-conn__meta b{color:#1d2327;font-weight:600}
				.aisooq-perms{margin-top:14px;border-top:1px solid #f0f0f1;padding-top:12px}
				.aisooq-perms__h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 8px}
				.aisooq-perms__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
				.aisooq-perm{border:1px solid var(--bd);border-radius:8px;padding:8px 10px;background:#fbfbfc}
				.aisooq-perm__name{display:flex;align-items:center;gap:6px;font-weight:600;font-size:13px;color:#1d2327}
				.aisooq-perm__name .dashicons{font-size:16px;width:16px;height:16px;color:var(--pri)}
				.aisooq-perm__acts{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
				.aisooq-act-badge{font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:999px;text-transform:capitalize}
				.aisooq-act-badge.read{background:#e9f2fd;color:#1d6ad4}
				.aisooq-act-badge.write{background:#fcf5e6;color:#996800}
				.aisooq-act-badge.other{background:#f0f0f1;color:var(--muted)}
			</style>

			<div class="aisooq-hero">
				<div>
					<h1 class="aisooq-hero__title" style="margin:0;line-height:0;">
						<img src="<?php echo esc_url( AISOOQ_URL . 'assets/img/logo-horizontal.svg' ); ?>" alt="<?php esc_attr_e( 'AI Sooq', 'aisooq-connector' ); ?>" height="40" style="height:40px;width:auto;display:block;" />
					</h1>
					<p class="aisooq-hero__sub"><?php echo esc_html( isset( $status['time'] ) && ! empty( $status['ok'] ) ? sprintf( __( 'Last verified %s', 'aisooq-connector' ), $status['time'] ) : __( 'Two-way sync between WooCommerce and your AI Sooq store.', 'aisooq-connector' ) ); ?></p>
				</div>
				<div class="aisooq-actions">
					<span class="aisooq-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
					<button type="button" id="aisooq-test-connection" class="button"><?php esc_html_e( 'Verify connection', 'aisooq-connector' ); ?></button>
					<span class="aisooq-sync-group">
						<span class="aisooq-sync-label"><?php esc_html_e( 'Sync:', 'aisooq-connector' ); ?></span>
						<button type="button" class="button aisooq-sync" data-entity="orders"><?php esc_html_e( 'Orders', 'aisooq-connector' ); ?></button>
						<button type="button" class="button aisooq-sync" data-entity="products"><?php esc_html_e( 'Products', 'aisooq-connector' ); ?></button>
						<button type="button" class="button aisooq-sync" data-entity="customers"><?php esc_html_e( 'Customers', 'aisooq-connector' ); ?></button>
						<button type="button" class="button aisooq-sync" data-entity="categories"><?php esc_html_e( 'Categories', 'aisooq-connector' ); ?></button>
					</span>
					<span id="aisooq-test-result" style="margin-left:4px;"></span>
				</div>
			</div>

			<?php $this->render_connection_panel( $status ); ?>

			<div class="aisooq-kpis">
				<div class="aisooq-kpi">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-cart"></span><?php esc_html_e( 'Orders synced', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['orders_synced'] ) ); ?></div>
				</div>
				<div class="aisooq-kpi <?php echo $k['queue'] > 0 ? 'warn' : ''; ?>">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'In queue', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( $this->kpi_num( $k['queue'] ) ); ?></div>
					<div class="aisooq-kpi__sub"><?php esc_html_e( 'awaiting push', 'aisooq-connector' ); ?></div>
				</div>
				<div class="aisooq-kpi <?php echo $k['failed'] > 0 ? 'err' : ''; ?>">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-warning"></span><?php esc_html_e( 'Failed', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( $this->kpi_num( $k['failed'] ) ); ?></div>
					<div class="aisooq-kpi__sub"><?php esc_html_e( 'retrying w/ backoff', 'aisooq-connector' ); ?></div>
				</div>
				<div class="aisooq-kpi">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-archive"></span><?php esc_html_e( 'Abandoned pushed', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['abandoned'] ) ); ?></div>
				</div>
				<div class="aisooq-kpi">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-products"></span><?php esc_html_e( 'Products synced', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['products'] ) ); ?></div>
				</div>
				<div class="aisooq-kpi">
					<div class="aisooq-kpi__label"><span class="dashicons dashicons-groups"></span><?php esc_html_e( 'Customers synced', 'aisooq-connector' ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['customers'] ) ); ?></div>
				</div>
			</div>

			<details class="aisooq-help">
				<summary><?php esc_html_e( 'Quick setup guide', 'aisooq-connector' ); ?></summary>
				<ol style="margin:4px 0 12px 18px;line-height:1.7;">
					<li><?php esc_html_e( 'Register an OAuth app for this store on the AI Sooq platform (scopes below). Copy the Client ID, Client Secret (shown once) and Store SID.', 'aisooq-connector' ); ?></li>
					<li><?php esc_html_e( 'Admin API base URL = your admin host (host only — /api/v1 is added). Storefront base = your storefront host, or blank if same.', 'aisooq-connector' ); ?></li>
					<li><?php esc_html_e( 'Paste credentials, tick Active, choose what to sync, Save, then Verify connection. Use the Sync buttons to backfill orders, products, customers or categories.', 'aisooq-connector' ); ?></li>
				</ol>
			</details>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<div class="aisooq-grid">

					<div class="aisooq-card">
						<div class="aisooq-card__head"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Connection', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[active]" value="1" <?php checked( $s['active'] ); ?> /> <strong><?php esc_html_e( 'Active', 'aisooq-connector' ); ?></strong> — <?php esc_html_e( 'sync orders, carts, analytics & fraud', 'aisooq-connector' ); ?></label>
								<p class="description"><?php esc_html_e( 'Uncheck to pause all syncing without losing settings.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_api_base"><?php esc_html_e( 'Admin API base URL', 'aisooq-connector' ); ?></label>
								<input name="aisooq[api_base]" id="aisooq_api_base" type="url" class="code" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://api.admin.yourdomain.com" />
								<p class="description"><?php esc_html_e( 'Host only — /api/v1 is appended. Handles OAuth + /connect/*.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_storefront_base"><?php esc_html_e( 'Storefront API base URL', 'aisooq-connector' ); ?></label>
								<input name="aisooq[storefront_base]" id="aisooq_storefront_base" type="url" class="code" value="<?php echo esc_attr( $s['storefront_base'] ); ?>" placeholder="https://api.yourdomain.com" />
								<p class="description"><?php esc_html_e( 'Handles analytics + fraud. Blank = same host as admin.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_sid"><?php esc_html_e( 'Store SID', 'aisooq-connector' ); ?></label>
								<input name="aisooq[sid]" id="aisooq_sid" type="text" class="code" value="<?php echo esc_attr( $s['sid'] ); ?>" />
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_client_id"><?php esc_html_e( 'OAuth Client ID', 'aisooq-connector' ); ?></label>
								<input name="aisooq[client_id]" id="aisooq_client_id" type="text" class="code" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="wapp_..." />
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'aisooq-connector' ); ?></label>
								<input name="aisooq[client_secret]" id="aisooq_client_secret" type="password" class="code" value="" placeholder="<?php echo '' !== $s['client_secret'] ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'aisooq-connector' ) : 'wsk_...'; ?>" autocomplete="new-password" />
							</div>
						</div>
					</div>

					<div class="aisooq-card">
						<div class="aisooq-card__head"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'What to sync', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_orders]" value="1" <?php checked( $s['enable_orders'] ); ?> /> <?php esc_html_e( 'Orders (incl. incomplete/unpaid)', 'aisooq-connector' ); ?></label>
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_abandoned]" value="1" <?php checked( $s['enable_abandoned'] ); ?> /> <?php esc_html_e( 'Abandoned carts (pushed instantly)', 'aisooq-connector' ); ?></label>
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_analytics]" value="1" <?php checked( $s['enable_analytics'] ); ?> /> <?php esc_html_e( 'Analytics events (pixel / CAPI)', 'aisooq-connector' ); ?></label>
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_fraud]" value="1" <?php checked( $s['enable_fraud'] ); ?> /> <?php esc_html_e( '4-layer fraud screening at checkout', 'aisooq-connector' ); ?></label>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_fraud_action"><?php esc_html_e( 'When fraud is detected', 'aisooq-connector' ); ?></label>
								<select name="aisooq[fraud_action]" id="aisooq_fraud_action">
									<option value="block" <?php selected( $s['fraud_action'], 'block' ); ?>><?php esc_html_e( 'Block checkout', 'aisooq-connector' ); ?></option>
									<option value="hold" <?php selected( $s['fraud_action'], 'hold' ); ?>><?php esc_html_e( 'Allow, set order On hold', 'aisooq-connector' ); ?></option>
									<option value="flag" <?php selected( $s['fraud_action'], 'flag' ); ?>><?php esc_html_e( 'Allow, add a flag note', 'aisooq-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Phone/name/address, IP velocity, courier history. Fails open if the API is unreachable.', 'aisooq-connector' ); ?></p>
							</div>
							<?php if ( null === $fraud ) : ?>
								<div class="aisooq-field">
									<p class="description"><?php esc_html_e( 'Connect the store (Verify connection) to configure the screening layers below.', 'aisooq-connector' ); ?></p>
								</div>
							<?php else :
								$fv = function ( $key, $default ) use ( $fraud ) { return array_key_exists( $key, $fraud ) ? $fraud[ $key ] : $default; };
								$pm = $fv( 'phoneMode', 'bd' );
								?>
								<input type="hidden" name="aisooq_fraud[_present]" value="1" />
								<div class="aisooq-field">
									<label class="h"><?php esc_html_e( 'Layer 1 — basic validation', 'aisooq-connector' ); ?></label>
									<label class="aisooq-check"><input type="checkbox" name="aisooq_fraud[name_validation]" value="1" <?php checked( ! empty( $fv( 'nameValidation', true ) ) ); ?> /> <?php esc_html_e( 'Block fake / gibberish customer names', 'aisooq-connector' ); ?></label>
									<label class="aisooq-check"><input type="checkbox" name="aisooq_fraud[address_validation]" value="1" <?php checked( ! empty( $fv( 'addressValidation', true ) ) ); ?> /> <?php esc_html_e( 'Block fake / gibberish delivery addresses', 'aisooq-connector' ); ?></label>
									<p class="description"><?php esc_html_e( 'Smart heuristics reject keyboard-mash names ("Ahshs Hsjs"), junk addresses and malformed numbers instantly.', 'aisooq-connector' ); ?></p>
								</div>
								<div class="aisooq-field">
									<label class="h" for="aisooq_fraud_phone_mode"><?php esc_html_e( 'Phone number check', 'aisooq-connector' ); ?></label>
									<select name="aisooq_fraud[phone_mode]" id="aisooq_fraud_phone_mode">
										<option value="bd" <?php selected( $pm, 'bd' ); ?>><?php esc_html_e( 'Bangladesh mobile only (recommended)', 'aisooq-connector' ); ?></option>
										<option value="intl" <?php selected( $pm, 'intl' ); ?>><?php esc_html_e( 'International', 'aisooq-connector' ); ?></option>
										<option value="off" <?php selected( $pm, 'off' ); ?>><?php esc_html_e( 'Off', 'aisooq-connector' ); ?></option>
									</select>
								</div>
								<div class="aisooq-field">
									<label class="h"><?php esc_html_e( 'Layer 2 — IP rate limiting', 'aisooq-connector' ); ?></label>
									<span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
										<?php esc_html_e( 'Auto-block after', 'aisooq-connector' ); ?>
										<input name="aisooq_fraud[ip_max_attempts]" type="number" min="1" max="100" step="1" value="<?php echo esc_attr( (int) $fv( 'ipMaxAttempts', 3 ) ); ?>" class="small-text" />
										<?php esc_html_e( 'blocked attempts within', 'aisooq-connector' ); ?>
										<input name="aisooq_fraud[ip_window_hours]" type="number" min="1" max="168" step="1" value="<?php echo esc_attr( (int) $fv( 'ipWindowHours', 24 ) ); ?>" class="small-text" />
										<?php esc_html_e( 'hours from one IP', 'aisooq-connector' ); ?>
									</span>
									<p class="description"><?php esc_html_e( 'Detects spam bursts from a single IP. Layer 3 (BDCourier success-ratio gate) is the “Courier ratio gate” below.', 'aisooq-connector' ); ?></p>
								</div>
							<?php endif; ?>
							<div class="aisooq-field">
								<label class="h" for="aisooq_courier_ratio"><?php esc_html_e( 'Courier ratio gate', 'aisooq-connector' ); ?></label>
								<span style="display:inline-flex;align-items:center;gap:6px;">
									<?php esc_html_e( 'Block orders below', 'aisooq-connector' ); ?>
									<input name="aisooq[courier_min_ratio]" id="aisooq_courier_ratio" type="number" min="0" max="100" step="1" value="<?php echo esc_attr( $s['courier_min_ratio'] ); ?>" class="small-text" /> %
									<?php esc_html_e( 'success, once the customer has', 'aisooq-connector' ); ?>
									<input name="aisooq[courier_min_parcels]" type="number" min="1" step="1" value="<?php echo esc_attr( $s['courier_min_parcels'] ); ?>" class="small-text" />
									<?php esc_html_e( 'parcels', 'aisooq-connector' ); ?>
								</span>
								<p class="description"><?php esc_html_e( 'Set 0 to disable. e.g. 60 or 75 — customers whose bdcourier delivery-success ratio is below this (with enough parcel history) are blocked at checkout. Fails open if the API is unreachable.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_support_phone"><?php esc_html_e( 'Support contact shown on a blocked checkout', 'aisooq-connector' ); ?></label>
								<div style="display:flex;gap:8px;flex-wrap:wrap;">
									<input name="aisooq[support_phone]" id="aisooq_support_phone" type="text" value="<?php echo esc_attr( $s['support_phone'] ); ?>" placeholder="<?php esc_attr_e( 'Call number, e.g. 01XXXXXXXXX', 'aisooq-connector' ); ?>" style="flex:1 1 180px;" />
									<input name="aisooq[support_whatsapp]" id="aisooq_support_whatsapp" type="text" value="<?php echo esc_attr( $s['support_whatsapp'] ); ?>" placeholder="<?php esc_attr_e( 'WhatsApp, e.g. 8801XXXXXXXXX', 'aisooq-connector' ); ?>" style="flex:1 1 180px;" />
									<input name="aisooq[support_messenger]" id="aisooq_support_messenger" type="url" value="<?php echo esc_attr( $s['support_messenger'] ); ?>" placeholder="<?php esc_attr_e( 'Messenger, e.g. https://m.me/yourpage', 'aisooq-connector' ); ?>" style="flex:1 1 180px;" />
								</div>
								<p class="description"><?php esc_html_e( 'When fraud or the courier gate blocks a checkout, the shopper sees a popup with these Call / WhatsApp buttons so a genuine buyer can still reach you. Leave blank to use the connected store’s contact number.', 'aisooq-connector' ); ?></p>
							</div>
						</div>
						<div class="aisooq-card__head" style="border-top:1px solid var(--bd);"><span class="dashicons dashicons-format-chat"></span><?php esc_html_e( 'Checkout messages', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<p class="description" style="margin-top:0;"><?php esc_html_e( 'The exact text a shopper sees when checkout is blocked. Write it in Bangla, English, or both. Leave a box blank to use the built-in default.', 'aisooq-connector' ); ?></p>
							<div class="aisooq-field">
								<label class="h" for="aisooq_msg_courier"><?php esc_html_e( 'Courier delivery gate', 'aisooq-connector' ); ?></label>
								<textarea name="aisooq[msg_courier]" id="aisooq_msg_courier" rows="2" style="width:100%;"><?php echo esc_textarea( $s['msg_courier'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Tokens: {ratio} = delivery-success %, {parcels} = past parcel count.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_msg_fraud_contact"><?php esc_html_e( 'Fraud — details could not be verified', 'aisooq-connector' ); ?></label>
								<textarea name="aisooq[msg_fraud_contact]" id="aisooq_msg_fraud_contact" rows="2" style="width:100%;"><?php echo esc_textarea( $s['msg_fraud_contact'] ); ?></textarea>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_msg_fraud_velocity"><?php esc_html_e( 'Fraud — too many attempts', 'aisooq-connector' ); ?></label>
								<textarea name="aisooq[msg_fraud_velocity]" id="aisooq_msg_fraud_velocity" rows="2" style="width:100%;"><?php echo esc_textarea( $s['msg_fraud_velocity'] ); ?></textarea>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_msg_fraud_generic"><?php esc_html_e( 'Fraud — other / fallback', 'aisooq-connector' ); ?></label>
								<textarea name="aisooq[msg_fraud_generic]" id="aisooq_msg_fraud_generic" rows="2" style="width:100%;"><?php echo esc_textarea( $s['msg_fraud_generic'] ); ?></textarea>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_msg_help"><?php esc_html_e( 'Popup contact prompt', 'aisooq-connector' ); ?></label>
								<input name="aisooq[msg_help]" id="aisooq_msg_help" type="text" value="<?php echo esc_attr( $s['msg_help'] ); ?>" style="width:100%;" />
								<p class="description"><?php esc_html_e( 'Line shown above the Call / WhatsApp buttons in the blocked-checkout popup.', 'aisooq-connector' ); ?></p>
							</div>
						</div>
					</div>

					<div class="aisooq-card">
						<div class="aisooq-card__head"><span class="dashicons dashicons-randomize"></span><?php esc_html_e( 'Two-way sync', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_customer_sync]" value="1" <?php checked( $s['enable_customer_sync'] ); ?> /> <strong><?php esc_html_e( 'Customers', 'aisooq-connector' ); ?></strong></label>
								<select name="aisooq[customer_sync_dir]" id="aisooq_cust_dir">
									<option value="both" <?php selected( $s['customer_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'aisooq-connector' ); ?></option>
									<option value="push" <?php selected( $s['customer_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'aisooq-connector' ); ?></option>
									<option value="pull" <?php selected( $s['customer_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'aisooq-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Matched by email/phone. Needs customers.read + customers.write.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_category_sync]" value="1" <?php checked( $s['enable_category_sync'] ); ?> /> <strong><?php esc_html_e( 'Categories', 'aisooq-connector' ); ?></strong></label>
								<select name="aisooq[category_sync_dir]" id="aisooq_category_dir">
									<option value="push" <?php selected( $s['category_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'aisooq-connector' ); ?></option>
									<option value="both" <?php selected( $s['category_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'aisooq-connector' ); ?></option>
									<option value="pull" <?php selected( $s['category_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'aisooq-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Product categories + hierarchy. Matched to the platform by handle/slug. Needs categories.read + categories.write.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_brand_sync]" value="1" <?php checked( $s['enable_brand_sync'] ); ?> /> <strong><?php esc_html_e( 'Brands', 'aisooq-connector' ); ?></strong></label>
								<select name="aisooq[brand_sync_dir]" id="aisooq_brand_dir">
									<option value="push" <?php selected( $s['brand_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'aisooq-connector' ); ?></option>
									<option value="both" <?php selected( $s['brand_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'aisooq-connector' ); ?></option>
									<option value="pull" <?php selected( $s['brand_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'aisooq-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Any brand taxonomy (native WC, Perfect Brands, YITH…). Matched by handle/slug. Needs brands.read + brands.write.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_product_sync]" value="1" <?php checked( $s['enable_product_sync'] ); ?> /> <strong><?php esc_html_e( 'Products', 'aisooq-connector' ); ?></strong></label>
								<select name="aisooq[product_sync_dir]" id="aisooq_product_dir">
									<option value="both" <?php selected( $s['product_sync_dir'], 'both' ); ?>><?php esc_html_e( 'Two-way (last edit wins)', 'aisooq-connector' ); ?></option>
									<option value="push" <?php selected( $s['product_sync_dir'], 'push' ); ?>><?php esc_html_e( 'WooCommerce → Platform', 'aisooq-connector' ); ?></option>
									<option value="pull" <?php selected( $s['product_sync_dir'], 'pull' ); ?>><?php esc_html_e( 'Platform → WooCommerce', 'aisooq-connector' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Products + variants, mapped to existing platform products by SKU/handle. On pull, a product’s categories + brand are linked too. Needs products.read + products.write.', 'aisooq-connector' ); ?></p>
							</div>
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[auto_sku]" value="1" <?php checked( $s['auto_sku'] ); ?> /> <strong><?php esc_html_e( 'Auto-generate missing SKUs', 'aisooq-connector' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'A product/variant with no SKU gets a unique one (SP-<id>) written to WooCommerce at sync time, so the platform can map it. Turn off if you manage SKUs yourself.', 'aisooq-connector' ); ?></p>
							</div>
						</div>
					</div>

					<div class="aisooq-card">
						<div class="aisooq-card__head"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Advanced', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<div class="aisooq-field">
								<label class="h"><?php esc_html_e( 'Order statuses to push', 'aisooq-connector' ); ?></label>
								<?php foreach ( $wc_statuses as $key => $label ) : ?>
									<?php $slug = preg_replace( '/^wc-/', '', $key ); ?>
									<label class="aisooq-check" style="display:inline-block;min-width:150px;margin-right:8px;">
										<input type="checkbox" name="aisooq[order_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['order_statuses'], true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<div class="aisooq-field">
								<label class="h" for="aisooq_idle"><?php esc_html_e( 'Abandoned idle threshold (minutes)', 'aisooq-connector' ); ?></label>
								<input name="aisooq[abandoned_idle_min]" id="aisooq_idle" type="number" min="5" value="<?php echo esc_attr( $s['abandoned_idle_min'] ); ?>" class="small-text" />
							</div>
							<div class="aisooq-field">
								<label class="aisooq-check"><input type="checkbox" name="aisooq[allow_status_writeback]" value="1" <?php checked( $s['allow_status_writeback'] ); ?> /> <?php esc_html_e( 'Let the platform update WooCommerce order status', 'aisooq-connector' ); ?></label>
								<label class="aisooq-check"><input type="checkbox" name="aisooq[debug_log]" value="1" <?php checked( $s['debug_log'] ); ?> /> <?php esc_html_e( 'Verbose debug logging (WooCommerce › Status › Logs)', 'aisooq-connector' ); ?></label>
							</div>
						</div>
					</div>

					<div class="aisooq-card">
						<div class="aisooq-card__head"><span class="dashicons dashicons-location"></span><?php esc_html_e( 'Shipping mapping', 'aisooq-connector' ); ?></div>
						<div class="aisooq-card__body">
							<?php if ( empty( $ship_methods ) ) : ?>
								<p class="description" style="margin-top:0;"><?php esc_html_e( 'No WooCommerce shipping methods found. Add zones + methods in WooCommerce › Settings › Shipping.', 'aisooq-connector' ); ?></p>
							<?php else : ?>
								<p class="description" style="margin-top:0;"><?php esc_html_e( 'Map each WooCommerce shipping method to a platform shipping rate. Mapped charges link to that rate on the platform; unmapped ones raise a reconciliation alert.', 'aisooq-connector' ); ?></p>
								<?php $map = (array) $s['shipping_map']; ?>
								<?php foreach ( $ship_methods as $key => $label ) : ?>
									<div class="aisooq-field">
										<label class="h"><?php echo esc_html( $label ); ?> <span class="description">(<?php echo esc_html( $key ); ?>)</span></label>
										<?php if ( ! empty( $ship_rates ) ) : ?>
											<select name="aisooq[shipping_map][<?php echo esc_attr( $key ); ?>]">
												<option value="0"><?php esc_html_e( '— not mapped —', 'aisooq-connector' ); ?></option>
												<?php foreach ( $ship_rates as $r ) : $rid = isset( $r['id'] ) ? (int) $r['id'] : 0; ?>
													<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( isset( $map[ $key ] ) ? (int) $map[ $key ] : 0, $rid ); ?>>
														<?php echo esc_html( ( isset( $r['zoneName'] ) ? $r['zoneName'] . ' / ' : '' ) . ( isset( $r['name'] ) ? $r['name'] : '' ) . ( isset( $r['amount'] ) ? ' (' . $r['amount'] . ')' : '' ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="number" min="0" name="aisooq[shipping_map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $map[ $key ] ) ? $map[ $key ] : '' ); ?>" placeholder="<?php esc_attr_e( 'platform rate id', 'aisooq-connector' ); ?>" class="small-text" />
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
								<?php if ( empty( $ship_rates ) ) : ?>
									<p class="description"><?php esc_html_e( 'Could not load platform rates — Verify the connection, or create shipping rates on the platform first. You can enter rate ids manually meanwhile.', 'aisooq-connector' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

				</div>
				<p class="submit">
					<button type="submit" name="aisooq_save" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'aisooq-connector' ); ?></button>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Save first, then use Verify / Sync now above.', 'aisooq-connector' ); ?></span>
				</p>
			</form>
		</div>
		<script>
		( function () {
			var out   = document.getElementById( 'aisooq-test-result' );
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			function call( action, pending, entity ) {
				out.textContent = pending;
				out.style.color = '#555';
				var data = new FormData();
				data.append( 'action', action );
				data.append( 'nonce', nonce );
				if ( entity ) { data.append( 'entity', entity ); }
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						out.textContent = ( j && j.data && j.data.message ) ? j.data.message : 'Error';
						out.style.color = ( j && j.success ) ? '#146c43' : '#b32d2e';
						// Verify success returns fresh store profile + permissions;
						// reload to render the connection panel from the saved status.
						if ( j && j.success && j.data && j.data.reload ) { setTimeout( function () { location.reload(); }, 900 ); }
					} )
					.catch( function () { out.textContent = 'Request failed'; out.style.color = '#b32d2e'; } );
			}
			var t = document.getElementById( 'aisooq-test-connection' );
			if ( t ) { t.addEventListener( 'click', function () { call( 'aisooq_test', <?php echo wp_json_encode( __( 'Verifying…', 'aisooq-connector' ) ); ?> ); } ); }
			var pending = <?php echo wp_json_encode( __( 'Queueing…', 'aisooq-connector' ) ); ?>;
			Array.prototype.forEach.call( document.querySelectorAll( '.aisooq-sync' ), function ( b ) {
				b.addEventListener( 'click', function () {
					call( 'aisooq_sync', pending, b.getAttribute( 'data-entity' ) );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
