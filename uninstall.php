<?php
/**
 * Uninstall cleanup: settings, cached token, cursors, cron events and the
 * abandoned-cart capture table. Order meta (_aisooq_order_id …) is intentionally
 * left in place so re-installing keeps the mapping.
 *
 * Also clears the option / transient / cron names this plugin used before it
 * was renamed, so uninstalling doesn't strand rows from the Shopify Pulse or
 * Wafi era. The legacy TABLES only still exist if the migration never ran —
 * once it has, they've been renamed, and the DROP is a no-op.
 *
 * @package AISooq
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'aisooq_settings',
	'aisooq_status',
	'aisooq_version',
	'aisooq_poll_cursor',
	// Previous names.
	'shopify_pulse_settings',
	'shopify_pulse_status',
	'shopify_pulse_version',
	'shopify_pulse_poll_cursor',
	'wafi_connector_settings',
	'wafi_connector_status',
	'wafi_connector_version',
	'wafi_connector_poll_cursor',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

foreach ( array( 'aisooq_token', 'shopify_pulse_token', 'wafi_connector_token' ) as $transient ) {
	delete_transient( $transient );
}

$crons = array(
	'aisooq_abandoned_sweep',
	'aisooq_status_poll',
	'aisooq_customer_pull',
	'aisooq_catalog_pull',
	// Previous names.
	'shopify_pulse_abandoned_sweep',
	'shopify_pulse_status_poll',
	'shopify_pulse_customer_pull',
	'shopify_pulse_catalog_pull',
	'wafi_connector_abandoned_sweep',
	'wafi_connector_status_poll',
	'wafi_connector_customer_pull',
	'wafi_connector_catalog_pull',
);
foreach ( $crons as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

foreach ( array( 'aisooq_abandoned_carts', 'sp_abandoned_carts', 'wafi_abandoned_carts' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
