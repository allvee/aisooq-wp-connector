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
			$old_table = $wpdb->prefix . $era['table'];

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
			if ( $old_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) // phpcs:ignore WordPress.DB
				&& $new_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB
			}

			// 3. Re-key our meta onto `_aisooq_` in every meta store.
			//
			// Renaming EVERY key that merely starts with the era prefix was
			// data loss waiting to happen: `_sp_` is generic enough that other
			// plugins use it, and their meta was being silently renamed into
			// this plugin's namespace with no way back. Only the keys this
			// plugin has ever written are touched, matched exactly.
			//
			// Exact `meta_key IN (...)` is also sargable, so it uses the
			// meta_key index instead of scanning the whole table — the old
			// SUBSTRING() predicate could not.
			$prefix   = $era['meta'];
			$old_keys = array();
			foreach ( self::owned_meta_keys() as $k ) {
				$old_keys[ $prefix . substr( $k, strlen( '_aisooq_' ) ) ] = $k;
			}

			// One statement per table: a CASE over an IN() list. Exact-match
			// keys use the meta_key index, where the old
			// `SUBSTRING(meta_key, 1, n) = prefix` predicate could not and
			// scanned the entire table.
			$case = '';
			$args = array();
			foreach ( $old_keys as $from => $to ) {
				$case  .= ' WHEN %s THEN %s';
				$args[] = $from;
				$args[] = $to;
			}
			$in   = implode( ', ', array_fill( 0, count( $old_keys ), '%s' ) );
			$args = array_merge( $args, array_keys( $old_keys ) );

			foreach ( $meta_tables as $t ) {
				$wpdb->query( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						"UPDATE `{$t}` SET meta_key = CASE meta_key{$case} ELSE meta_key END WHERE meta_key IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL
						$args
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
	 * Every meta key this plugin has ever written, on the current `_aisooq_`
	 * namespace. Used to re-key a legacy install exactly, without touching meta
	 * that merely shares the old prefix.
	 *
	 * @return string[]
	 */
	private static function owned_meta_keys() {
		return array(
			'_aisooq_attribution',
			'_aisooq_cart_fingerprint',
			'_aisooq_courier_checked_at',
			'_aisooq_courier_json',
			'_aisooq_courier_parcels',
			'_aisooq_courier_phone',
			'_aisooq_courier_ratio',
			'_aisooq_cust_hash',
			'_aisooq_cust_platform_updated',
			'_aisooq_cust_synced_at',
			'_aisooq_fraud_flagged',
			'_aisooq_fraud_layer',
			'_aisooq_fraud_reason',
			'_aisooq_order_id',
			'_aisooq_platform_customer_id',
			'_aisooq_platform_id',
			'_aisooq_prod_hash',
			'_aisooq_prod_platform_updated',
			'_aisooq_purchase_pixel_sent',
			'_aisooq_rate_deferrals',
			'_aisooq_sync_attempts',
			'_aisooq_sync_hash',
			'_aisooq_synced_at',
			'_aisooq_term_hash',
			'_aisooq_term_platform_updated',
		);
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
		// Stamp the version FIRST.
		//
		// This runs on `plugins_loaded`, so the request that pays for it is
		// whatever hit the site first after the files were replaced — usually a
		// shopper, not an admin. Stamping only at the end meant that if any step
		// below timed out or fatalled, the very next request started the whole
		// upgrade again, and the site could sit in that loop indefinitely.
		// Each step below is independently guarded and idempotent, so losing one
		// to a crash is recoverable; an unbounded retry loop is not.
		update_option( 'aisooq_version', AISOOQ_VERSION, false );

		self::migrate_legacy();
		self::create_table();
		self::schedule_crons();
		// Drop any cached access token so the next request re-mints with the
		// current scope logic (an old token may carry a stale narrow scope).
		delete_transient( AISOOQ_TOKEN_TRANSIENT );
	}

	/**
	 * Surface a failed table creation to the operator.
	 *
	 * dbDelta() reports nothing useful on failure, so a CREATE TABLE that the
	 * database refused (no permission, disk full, row-size limit) used to leave
	 * the plugin silently writing abandoned carts nowhere, forever, with a
	 * perfectly healthy-looking settings screen.
	 */
	public static function admin_notices() {
		if ( ! get_option( 'aisooq_table_missing' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: comma-separated list of database table names. */
			esc_html__(
				'AI Sooq Connector could not create these database tables: %s. The features that depend on them are disabled until this is fixed — check the database user\'s CREATE TABLE permission, then deactivate and reactivate the plugin.',
				'aisooq-connector'
			),
			esc_html( (string) get_option( 'aisooq_table_missing' ) )
		);
		echo '</p></div>';
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( AISOOQ_BLOCK_GC_CRON );
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
		// Daily is plenty: the block log is pruned on retention, not volume.
		if ( ! wp_next_scheduled( AISOOQ_BLOCK_GC_CRON ) ) {
			wp_schedule_event( time() + 600, 'daily', AISOOQ_BLOCK_GC_CRON );
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
  courier_ratio decimal(6,2) DEFAULT NULL,
  courier_parcels int(11) DEFAULT NULL,
  courier_json longtext,
  courier_checked_at datetime DEFAULT NULL,
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

		// The operator's own block/allow list, and the record of every checkout
		// this plugin refused. Both are local by design — see AI_Sooq_Blocklist.
		$block_table = AI_Sooq_Blocklist::table_name();
		$sql        .= "\nCREATE TABLE {$block_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(16) NOT NULL,
  value varchar(191) NOT NULL,
  mode varchar(8) NOT NULL DEFAULT 'block',
  reason varchar(255) DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  hits int(11) unsigned NOT NULL DEFAULT 0,
  last_hit_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_value (type, value),
  KEY mode_type (mode, type),
  KEY expires_at (expires_at)
) {$charset_collate};";

		$log_table = AI_Sooq_Blocklist::log_table_name();
		$sql      .= "\nCREATE TABLE {$log_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  gate varchar(32) NOT NULL,
  action varchar(16) NOT NULL DEFAULT 'block',
  reason varchar(255) DEFAULT NULL,
  phone varchar(64) DEFAULT NULL,
  email varchar(191) DEFAULT NULL,
  ip varchar(45) DEFAULT NULL,
  name varchar(191) DEFAULT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY gate_created (gate, created_at),
  KEY phone (phone),
  KEY ip (ip)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta() swallows failures, so verify the tables are really there.
		// Without this a refused CREATE TABLE left every cart capture writing
		// into nothing, indefinitely and invisibly — and a missing blocklist
		// table would silently mean "nobody is blocked", which is worse than
		// an error because it looks like everything is working.
		$missing = array();
		foreach ( array( $table, $block_table, $log_table ) as $t ) {
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
				$missing[] = $t;
			}
		}
		if ( $missing ) {
			update_option( 'aisooq_table_missing', implode( ', ', $missing ), false );
			return;
		}
		delete_option( 'aisooq_table_missing' );

		// Back-fill the disposition column on installs upgrading from a build
		// that only had the `converted` flag, so an already-recovered cart keeps
		// its status after the column is added (dbDelta defaults it to 'active').
		$col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'status' ) ); // phpcs:ignore WordPress.DB
		if ( 'status' === $col ) {
			$wpdb->query( "UPDATE {$table} SET status = 'converted' WHERE converted = 1 AND status = 'active'" ); // phpcs:ignore WordPress.DB
		}
	}
}
