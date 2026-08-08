<?php
/**
 * Plugin Name:       AI Sooq Connector
 * Plugin URI:        https://github.com/allvee/aisooq-wp-connector
 * Description:        Mirrors WooCommerce orders, incomplete/abandoned carts and analytics into the AI Sooq platform so a store can be managed from there. Connects any WooCommerce site to one AI Sooq store via OAuth.
 * Version:           2.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AI Sooq
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aisooq-connector
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'AISOOQ_VERSION', '2.1.0' );
define( 'AISOOQ_FILE', __FILE__ );
define( 'AISOOQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISOOQ_URL', plugin_dir_url( __FILE__ ) );
define( 'AISOOQ_BASENAME', plugin_basename( __FILE__ ) );

// Shared identifiers used across the plugin.
define( 'AISOOQ_OPTION', 'aisooq_settings' );
define( 'AISOOQ_TOKEN_TRANSIENT', 'aisooq_token' );
define( 'AISOOQ_AS_GROUP', 'aisooq-connector' );
define( 'AISOOQ_SYNC_ACTION', 'aisooq_sync_order' );
define( 'AISOOQ_ABANDONED_CRON', 'aisooq_abandoned_sweep' );
define( 'AISOOQ_ABANDONED_PUSH_ACTION', 'aisooq_abandoned_push' );
define( 'AISOOQ_POLL_CRON', 'aisooq_status_poll' );
define( 'AISOOQ_CUSTOMER_SYNC_ACTION', 'aisooq_sync_customer' );
define( 'AISOOQ_CUSTOMER_PULL_CRON', 'aisooq_customer_pull' );
define( 'AISOOQ_TERM_SYNC_ACTION', 'aisooq_sync_term' );
define( 'AISOOQ_PRODUCT_SYNC_ACTION', 'aisooq_sync_product' );
define( 'AISOOQ_CATALOG_PULL_CRON', 'aisooq_catalog_pull' );

// Order meta keys.
define( 'AISOOQ_META_ID', '_aisooq_order_id' );
define( 'AISOOQ_META_HASH', '_aisooq_sync_hash' );
define( 'AISOOQ_META_SYNCED_AT', '_aisooq_synced_at' );
define( 'AISOOQ_META_ATTEMPTS', '_aisooq_sync_attempts' );
define( 'AISOOQ_META_PIXEL_SENT', '_aisooq_purchase_pixel_sent' );

require_once AISOOQ_DIR . 'includes/class-aisooq-logger.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-settings.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-api-client.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-attribution.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-order-mapper.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-order-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-abandoned-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-abandoned-admin.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-block-beacon.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-analytics.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-fraud.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-customer-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-seo.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-catalog-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-product-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-seo-sync.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-status-poller.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-orders-column.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-products-column.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-install.php';
require_once AISOOQ_DIR . 'includes/class-aisooq-plugin.php';

register_activation_hook( __FILE__, array( 'AI_Sooq_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AI_Sooq_Install', 'deactivate' ) );

// Custom cron cadences must be available on every request (wp-cron included),
// independent of whether the full plugin boots — register globally.
add_filter( 'cron_schedules', array( 'AI_Sooq_Install', 'cron_schedules' ) );

/**
 * Boot the plugin once WooCommerce is loaded. If WooCommerce is absent we show
 * an admin notice and stay dormant — every flow here is WooCommerce-specific.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'AI Sooq Connector requires WooCommerce to be installed and active.', 'aisooq-connector' );
					echo '</p></div>';
				}
			);
			return;
		}
		// Self-heal DB table + crons after an update-in-place (the activation
		// hook doesn't fire when plugin files are replaced).
		AI_Sooq_Install::maybe_upgrade();
		AI_Sooq_Plugin::instance()->init();
	},
	20
);

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
