<?php
/**
 * The v1.2.0 data migration — the one path that touches persisted synced-order
 * data. Proves settings carry over, the capture table is renamed (rows kept),
 * our `_wafi_*` meta is re-keyed to `_aisooq_*`, and stale crons are cleared.
 *
 * @package AISooq
 */

class Test_Migrate_Legacy extends WP_UnitTestCase {

	/** Invoke the private static AI_Sooq_Install::migrate_legacy(). */
	private function run_migrate() {
		$m = new ReflectionMethod( 'AI_Sooq_Install', 'migrate_legacy' );
		$m->setAccessible( true );
		$m->invoke( null );
	}

	public function test_copies_legacy_settings_and_status() {
		update_option( 'wafi_connector_settings', array( 'sid' => 'store1', 'active' => 1 ) );
		update_option( 'wafi_connector_status', array( 'ok' => 1, 'sid' => 'store1' ) );
		delete_option( 'aisooq_settings' );
		delete_option( 'aisooq_status' );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'store1', 'active' => 1 ), get_option( 'aisooq_settings' ) );
		$this->assertSame( array( 'ok' => 1, 'sid' => 'store1' ), get_option( 'aisooq_status' ) );
	}

	public function test_does_not_overwrite_existing_new_settings() {
		update_option( 'wafi_connector_settings', array( 'sid' => 'old' ) );
		update_option( 'aisooq_settings', array( 'sid' => 'new' ) );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'new' ), get_option( 'aisooq_settings' ) );
	}

	public function test_rekeys_wafi_meta_to_aisooq_across_stores() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		update_post_meta( $post_id, '_wafi_order_id', '12345' );
		update_post_meta( $post_id, '_wafi_sync_hash', 'abc' );
		update_user_meta( $user_id, '_wafi_platform_customer_id', '77' );
		update_term_meta( $term_id, '_wafi_platform_id', '9' );

		$this->run_migrate();

		$this->assertSame( '12345', get_post_meta( $post_id, '_aisooq_order_id', true ) );
		$this->assertSame( 'abc', get_post_meta( $post_id, '_aisooq_sync_hash', true ) );
		$this->assertSame( '77', get_user_meta( $user_id, '_aisooq_platform_customer_id', true ) );
		$this->assertSame( '9', get_term_meta( $term_id, '_aisooq_platform_id', true ) );

		// Old keys gone (so the sync dedup reads the new key, never a stale one).
		$this->assertSame( '', get_post_meta( $post_id, '_wafi_order_id', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_wafi_platform_customer_id', true ) );
	}

	public function test_leaves_unrelated_meta_untouched() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', '123' );
		update_post_meta( $post_id, '_wafi_order_id', '5' );

		$this->run_migrate();

		$this->assertSame( '123', get_post_meta( $post_id, '_edit_lock', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, '_aisooq_order_id', true ) );
	}

	public function test_renames_capture_table_preserving_rows() {
		global $wpdb;
		// WP_UnitTestCase rewrites CREATE TABLE -> CREATE TEMPORARY TABLE (a
		// `query` filter), which SHOW TABLES / RENAME TABLE can't see. Drop those
		// filters so this test exercises a real table like production.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$old = $wpdb->prefix . 'wafi_abandoned_carts';
		$new = $wpdb->prefix . 'aisooq_abandoned_carts';
		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS `$old`" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE `$old` (session_key varchar(64) NOT NULL, email varchar(191), PRIMARY KEY (session_key))" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO `$old` (session_key, email) VALUES ('k1', 'a@b.com')" ); // phpcs:ignore
		// Persist the seed row before migrate's RENAME (DDL) implicit-commits —
		// otherwise the INSERT is still inside WP_UnitTestCase's open transaction.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore

		$this->run_migrate();

		$this->assertSame( $new, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		$this->assertSame( 'a@b.com', $wpdb->get_var( "SELECT email FROM `$new` WHERE session_key = 'k1'" ) ); // phpcs:ignore

		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
	}

	public function test_clears_legacy_cron_hooks() {
		wp_schedule_event( time() + 3600, 'daily', 'wafi_connector_abandoned_sweep' );
		$this->assertNotFalse( wp_next_scheduled( 'wafi_connector_abandoned_sweep' ) );

		$this->run_migrate();

		$this->assertFalse( wp_next_scheduled( 'wafi_connector_abandoned_sweep' ) );
	}

	// ── The Shopify Pulse era → AI Sooq (v2.0.0) ────────────────────────────
	// Most live installs are on this one, not the original Wafi naming.

	public function test_copies_shopify_pulse_settings_and_status() {
		update_option( 'shopify_pulse_settings', array( 'sid' => 'store9', 'active' => 1 ) );
		update_option( 'shopify_pulse_status', array( 'ok' => 1, 'sid' => 'store9' ) );
		delete_option( 'aisooq_settings' );
		delete_option( 'aisooq_status' );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'store9', 'active' => 1 ), get_option( 'aisooq_settings' ) );
		$this->assertSame( array( 'ok' => 1, 'sid' => 'store9' ), get_option( 'aisooq_status' ) );
	}

	public function test_shopify_pulse_does_not_overwrite_existing_new_settings() {
		update_option( 'shopify_pulse_settings', array( 'sid' => 'old' ) );
		update_option( 'aisooq_settings', array( 'sid' => 'new' ) );

		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'new' ), get_option( 'aisooq_settings' ) );
	}

	public function test_rekeys_sp_meta_to_aisooq_across_stores() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		update_post_meta( $post_id, '_sp_order_id', '54321' );
		update_post_meta( $post_id, '_sp_sync_hash', 'zyx' );
		update_post_meta( $post_id, '_sp_purchase_pixel_sent', '1' );
		update_user_meta( $user_id, '_sp_platform_customer_id', '88' );
		update_term_meta( $term_id, '_sp_platform_id', '4' );

		$this->run_migrate();

		$this->assertSame( '54321', get_post_meta( $post_id, '_aisooq_order_id', true ) );
		$this->assertSame( 'zyx', get_post_meta( $post_id, '_aisooq_sync_hash', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_aisooq_purchase_pixel_sent', true ) );
		$this->assertSame( '88', get_user_meta( $user_id, '_aisooq_platform_customer_id', true ) );
		$this->assertSame( '4', get_term_meta( $term_id, '_aisooq_platform_id', true ) );

		// Old keys gone, so the sync dedup can never read a stale one.
		$this->assertSame( '', get_post_meta( $post_id, '_sp_order_id', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_sp_platform_customer_id', true ) );
	}

	/**
	 * `_sp_` is 4 chars where `_wafi_` is 6, so the SUBSTRING offset differs per
	 * era. A hard-coded offset would silently truncate the key (`_aisooq_er_id`)
	 * or leave the old prefix embedded in it. Separate posts, so the assertion
	 * is about the offset and not about which of two duplicate keys wins.
	 */
	public function test_rekey_offset_is_correct_per_era() {
		$sp   = self::factory()->post->create();
		$wafi = self::factory()->post->create();
		update_post_meta( $sp, '_sp_cart_fingerprint', 'sp-value' );
		update_post_meta( $wafi, '_wafi_cart_fingerprint', 'wafi-value' );

		$this->run_migrate();

		$this->assertSame( 'sp-value', get_post_meta( $sp, '_aisooq_cart_fingerprint', true ) );
		$this->assertSame( 'wafi-value', get_post_meta( $wafi, '_aisooq_cart_fingerprint', true ) );
		$this->assertSame( '', get_post_meta( $sp, '_sp_cart_fingerprint', true ) );
		$this->assertSame( '', get_post_meta( $wafi, '_wafi_cart_fingerprint', true ) );
	}

	public function test_leaves_unrelated_underscore_meta_untouched() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', '123' );
		update_post_meta( $post_id, '_spice_level', 'hot' );   // starts with _sp but isn't ours
		update_post_meta( $post_id, '_sp_order_id', '5' );

		$this->run_migrate();

		$this->assertSame( '123', get_post_meta( $post_id, '_edit_lock', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, '_aisooq_order_id', true ) );
		// `_spice_level` starts with `_sp` but not `_sp_`, so it must survive.
		$this->assertSame( 'hot', get_post_meta( $post_id, '_spice_level', true ) );
	}

	public function test_renames_sp_capture_table_preserving_rows() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$old = $wpdb->prefix . 'sp_abandoned_carts';
		$new = $wpdb->prefix . 'aisooq_abandoned_carts';
		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS `$old`" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE `$old` (session_key varchar(64) NOT NULL, email varchar(191), PRIMARY KEY (session_key))" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO `$old` (session_key, email) VALUES ('k2', 'c@d.com')" ); // phpcs:ignore
		$wpdb->query( 'COMMIT' ); // phpcs:ignore

		$this->run_migrate();

		$this->assertSame( $new, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		$this->assertSame( 'c@d.com', $wpdb->get_var( "SELECT email FROM `$new` WHERE session_key = 'k2'" ) ); // phpcs:ignore

		$wpdb->query( "DROP TABLE IF EXISTS `$new`" ); // phpcs:ignore
	}

	public function test_clears_shopify_pulse_cron_hooks() {
		wp_schedule_event( time() + 3600, 'daily', 'shopify_pulse_abandoned_sweep' );
		wp_schedule_event( time() + 3600, 'daily', 'shopify_pulse_catalog_pull' );
		$this->assertNotFalse( wp_next_scheduled( 'shopify_pulse_abandoned_sweep' ) );

		$this->run_migrate();

		$this->assertFalse( wp_next_scheduled( 'shopify_pulse_abandoned_sweep' ) );
		$this->assertFalse( wp_next_scheduled( 'shopify_pulse_catalog_pull' ) );
	}

	/** The stale version option must go, or maybe_upgrade() short-circuits. */
	public function test_drops_legacy_version_options() {
		update_option( 'shopify_pulse_version', '1.9.0' );
		update_option( 'wafi_connector_version', '1.0.0' );

		$this->run_migrate();

		$this->assertFalse( get_option( 'shopify_pulse_version', false ) );
		$this->assertFalse( get_option( 'wafi_connector_version', false ) );
	}

	/** Re-running must be a no-op, not a second destructive pass. */
	public function test_is_idempotent() {
		$post_id = self::factory()->post->create();
		update_option( 'shopify_pulse_settings', array( 'sid' => 'store9' ) );
		delete_option( 'aisooq_settings' );
		update_post_meta( $post_id, '_sp_order_id', '777' );

		$this->run_migrate();
		$this->run_migrate();
		$this->run_migrate();

		$this->assertSame( array( 'sid' => 'store9' ), get_option( 'aisooq_settings' ) );
		$this->assertSame( '777', get_post_meta( $post_id, '_aisooq_order_id', true ) );
	}
}
