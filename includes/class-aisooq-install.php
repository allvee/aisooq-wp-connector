<?php
/**
 * Activation / deactivation: the abandoned-cart capture table and the two
 * WP-Cron schedules (abandoned sweep, status poll).
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Install {

	/** Register custom cron cadences. Hooked on `cron_schedules` globally. */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['aisooq_10min'] ) ) {
			$schedules['aisooq_10min'] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 10 minutes (AI Sooq)', 'aisooq-connector' ),
			);
		}
		if ( ! isset( $schedules['aisooq_15min'] ) ) {
			$schedules['aisooq_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (AI Sooq)', 'aisooq-connector' ),
			);
		}
		return $schedules;
	}

	public static function activate() {
		self::migrate_legacy();
		self::create_table();
		self::schedule_crons();
		// Drop any cached access token so the next request re-mints with the
		// current scope logic (an old token may carry a stale narrow scope).
		delete_transient( AISOOQ_TOKEN_TRANSIENT );
		update_option( 'aisooq_version', AISOOQ_VERSION, false );
	}

	/**
	 * Carry state + data over from both earlier names this plugin shipped under:
	 * the original "Wafi Commerce Connector" (`wafi_connector_*` options,
	 * `_wafi_*` meta) and the interim "Shopify Pulse Connector"
	 * (`shopify_pulse_*` options, `_sp_*` meta).
	 *
	 * Copies the old settings + status options, RENAMES the abandoned-cart
	 * table, and RE-KEYS our meta onto the `_aisooq_` prefix across every meta
	 * store (posts / products, users, terms, HPOS order meta) so already-synced
	 * orders keep their platform link.
	 *
	 * Idempotent: runs on activate + on every version change; each step is
	 * guarded so re-runs are cheap no-ops. Ordering matters — the Shopify Pulse
	 * era is applied last so that on an install that somehow carries both, the
	 * newer values win.
	 */
	private static function migrate_legacy() {
		global $wpdb;

		// The names this plugin has shipped under, oldest first.
		$eras = array(
			array(
				'settings' => 'wafi_connector_settings',
				'status'   => 'wafi_connector_status',
				'version'  => 'wafi_connector_version',
				'table'    => 'wafi_abandoned_carts',
				'meta'     => '_wafi_',
				'token'    => 'wafi_connector_token',
				'crons'    => array(
					'wafi_connector_abandoned_sweep',
					'wafi_connector_status_poll',
					'wafi_connector_customer_pull',
					'wafi_connector_catalog_pull',
				),
			),
			array(
				'settings' => 'shopify_pulse_settings',
				'status'   => 'shopify_pulse_status',
				'version'  => 'shopify_pulse_version',
				'table'    => 'sp_abandoned_carts',
				'meta'     => '_sp_',
				'token'    => 'shopify_pulse_token',
				'crons'    => array(
					'shopify_pulse_abandoned_sweep',
					'shopify_pulse_status_poll',
					'shopify_pulse_customer_pull',
					'shopify_pulse_catalog_pull',
					'shopify_pulse_sync_order',
					'shopify_pulse_sync_customer',
					'shopify_pulse_sync_term',
					'shopify_pulse_sync_product',
					'shopify_pulse_abandoned_push',
				),
			),
		);

		$new_table   = $wpdb->prefix . 'aisooq_abandoned_carts';
		$meta_tables = array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->termmeta );
		$hpos        = $wpdb->prefix . 'wc_orders_meta';
		if ( $hpos === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) ) { // phpcs:ignore WordPress.DB
			$meta_tables[] = $hpos;
		}

		foreach ( $eras as $era ) {
			// 1. Options. Only adopt an old value when we don't already have one,
			//    so a re-run can't clobber settings edited under the new name.
			$options = array(
				AISOOQ_OPTION    => $era['settings'],
				'aisooq_status'  => $era['status'],
			);
			foreach ( $options as $new => $old ) {
				if ( false === get_option( $new, false ) ) {
					$val = get_option( $old, null );
					if ( null !== $val ) {
						update_option( $new, $val, false );
					}
				}
			}

			// 2. Rename the abandoned-cart table (preserves rows). Guarded on the
			//    destination not existing, so this runs at most once.
			$old_table = $wpdb->prefix . $era['table'];
			if ( $old_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) // phpcs:ignore WordPress.DB
				&& $new_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB
			}

			// 3. Re-key our meta onto `_aisooq_` in every meta store.
			$prefix = $era['meta'];
			$len    = strlen( $prefix );
			foreach ( $meta_tables as $t ) {
				$wpdb->query( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						"UPDATE `{$t}` SET meta_key = CONCAT('_aisooq_', SUBSTRING(meta_key, %d)) WHERE SUBSTRING(meta_key, 1, %d) = %s", // phpcs:ignore WordPress.DB
						$len + 1,
						$len,
						$prefix
					)
				);
			}

			// 4. Drop the era's cached access token and its scheduled hooks, so
			//    schedule_crons() re-registers on the current names + cadence.
			delete_transient( $era['token'] );
			foreach ( $era['crons'] as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			delete_option( $era['version'] );
		}

		// Our own hooks too — schedule_crons() re-adds them on the current
		// cadence, which the rename changed (sp_15min -> aisooq_15min).
		foreach ( array( AISOOQ_ABANDONED_CRON, AISOOQ_POLL_CRON, AISOOQ_CUSTOMER_PULL_CRON, AISOOQ_CATALOG_PULL_CRON ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Self-heal on update-in-place. WordPress does NOT fire the activation hook
	 * when a plugin is updated by replacing its files, so re-run the idempotent
	 * setup (dbDelta table + cron scheduling) whenever the stored version differs
	 * from the running one. Cheap: after the first post-update request stores the
	 * new version, this is a single get_option no-op.
	 */
	public static function maybe_upgrade() {
		if ( AISOOQ_VERSION === get_option( 'aisooq_version' ) ) {
			return;
		}
		self::migrate_legacy();
		self::create_table();
		self::schedule_crons();
		// Drop any cached access token so the next request re-mints with the
		// current scope logic (an old token may carry a stale narrow scope).
		delete_transient( AISOOQ_TOKEN_TRANSIENT );
		update_option( 'aisooq_version', AISOOQ_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( AISOOQ_ABANDONED_CRON );
		wp_clear_scheduled_hook( AISOOQ_POLL_CRON );
		wp_clear_scheduled_hook( AISOOQ_CUSTOMER_PULL_CRON );
		wp_clear_scheduled_hook( AISOOQ_CATALOG_PULL_CRON );
	}

	public static function schedule_crons() {
		if ( ! wp_next_scheduled( AISOOQ_ABANDONED_CRON ) ) {
			wp_schedule_event( time() + 300, 'aisooq_15min', AISOOQ_ABANDONED_CRON );
		}
		if ( ! wp_next_scheduled( AISOOQ_POLL_CRON ) ) {
			wp_schedule_event( time() + 300, 'aisooq_10min', AISOOQ_POLL_CRON );
		}
		if ( ! wp_next_scheduled( AISOOQ_CUSTOMER_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'aisooq_15min', AISOOQ_CUSTOMER_PULL_CRON );
		}
		if ( ! wp_next_scheduled( AISOOQ_CATALOG_PULL_CRON ) ) {
			wp_schedule_event( time() + 300, 'aisooq_15min', AISOOQ_CATALOG_PULL_CRON );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table           = AI_Sooq_Abandoned_Sync::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  session_key varchar(64) NOT NULL,
  customer_name varchar(191) DEFAULT NULL,
  email varchar(191) DEFAULT NULL,
  phone varchar(64) DEFAULT NULL,
  address_json longtext,
  cart_json longtext,
  subtotal decimal(18,4) NOT NULL DEFAULT 0,
  currency varchar(8) DEFAULT NULL,
  furthest_step varchar(32) DEFAULT NULL,
  utm_source varchar(128) DEFAULT NULL,
  utm_medium varchar(128) DEFAULT NULL,
  utm_campaign varchar(128) DEFAULT NULL,
  utm_term varchar(128) DEFAULT NULL,
  utm_content varchar(128) DEFAULT NULL,
  referrer varchar(1024) DEFAULT NULL,
  landing_path varchar(1024) DEFAULT NULL,
  traffic_source varchar(32) DEFAULT NULL,
  attribution_json longtext,
  status varchar(20) NOT NULL DEFAULT 'active',
  wc_order_id bigint(20) unsigned DEFAULT NULL,
  converted tinyint(1) NOT NULL DEFAULT 0,
  synced tinyint(1) NOT NULL DEFAULT 0,
  synced_hash varchar(64) DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (session_key),
  KEY status_synced_updated (status, synced, updated_at),
  KEY converted_synced_updated (converted, synced, updated_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Back-fill the disposition column on installs upgrading from a build
		// that only had the `converted` flag, so an already-recovered cart keeps
		// its status after the column is added (dbDelta defaults it to 'active').
		$col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'status' ) ); // phpcs:ignore WordPress.DB
		if ( 'status' === $col ) {
			$wpdb->query( "UPDATE {$table} SET status = 'converted' WHERE converted = 1 AND status = 'active'" ); // phpcs:ignore WordPress.DB
		}
	}
}
