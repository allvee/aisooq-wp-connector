<?php
/**
 * Uninstall cleanup: settings, cached token, cursors, cron events, queued
 * Action Scheduler jobs and the abandoned-cart capture table.
 *
 * Order meta (_aisooq_order_id …) is intentionally left in place so
 * re-installing keeps the order→platform mapping. Everything else goes.
 *
 * Also clears the option / transient / cron names this plugin used before it
 * was renamed, so uninstalling doesn't strand rows from the Shopify Pulse or
 * Wafi era. The legacy TABLES only still exist if the migration never ran —
 * once it has, they've been renamed, and the DROP is a no-op.
 *
 * On a multisite network this runs ONCE for the whole network, so it has to
 * walk every site itself; without that, uninstalling left the table and every
 * option behind on all but one site.
 *
 * @package AISooq
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove every trace of the plugin from the CURRENT site.
 */
function aisooq_uninstall_site() {
	global $wpdb;

	$options = array(
		'aisooq_settings',
		'aisooq_status',
		'aisooq_version',
		'aisooq_table_missing',
		'aisooq_blocklist_notice',
		// Sync cursors and caches.
		'aisooq_poll_cursor',
		'aisooq_customer_cursor',
		'aisooq_prod_pull_cursor',
		'aisooq_redirect_cursor',
		'aisooq_redirect_push_hashes',
		'aisooq_redirects',
		'aisooq_robots_disallow',
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
	delete_transient( 'aisooq_dashboard_stats' );

	$crons = array(
		'aisooq_abandoned_sweep',
		'aisooq_status_poll',
		'aisooq_customer_pull',
		'aisooq_catalog_pull',
		'aisooq_block_gc',
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

	// Queued Action Scheduler work outlives the plugin files, so an order push
	// scheduled minutes before uninstall would keep being retried against a
	// callback that no longer exists.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'aisooq_sync_order', 'aisooq_sync_customer', 'aisooq_sync_term', 'aisooq_sync_product', 'aisooq_delete_product', 'aisooq_abandoned_push' ) as $action ) {
			as_unschedule_all_actions( $action, array(), 'aisooq-connector' );
		}
	}

	// Per-user sync bookkeeping. Order meta is deliberately kept (see above),
	// but user meta maps this store's users onto a platform we're disconnecting.
	foreach ( array( '_aisooq_cust_hash', '_aisooq_cust_platform_updated', '_aisooq_cust_synced_at', '_aisooq_platform_customer_id' ) as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	foreach ( array( 'aisooq_abandoned_carts', 'aisooq_blocklist', 'aisooq_block_log', 'sp_abandoned_carts', 'wafi_abandoned_carts' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		aisooq_uninstall_site();
		restore_current_blog();
	}
} else {
	aisooq_uninstall_site();
}
