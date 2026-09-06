<?php
/**
 * Does an EXISTING install get the new tables when it updates?
 *
 * maybe_upgrade() is the only thing that runs when a plugin is updated in
 * place — WordPress does not fire the activation hook. If it does not create
 * the blocklist tables, the whole feature is dead on every upgrading store
 * while looking perfectly healthy: the screen renders, the form submits, and
 * nothing is ever blocked.
 *
 * Activation is the easy path and the one everybody tests. This is the path
 * almost every real store actually takes.
 *
 * @package AISooq
 */
class Test_Upgrade_Path extends WP_UnitTestCase {
	public function test_an_existing_install_gains_the_new_tables_on_upgrade() {
		global $wpdb;

		// WP_UnitTestCase rewrites CREATE TABLE -> CREATE TEMPORARY TABLE, which
		// SHOW TABLES cannot see. Drop those filters so this exercises real DDL.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$block = AI_Sooq_Blocklist::table_name();
		$log   = AI_Sooq_Blocklist::log_table_name();
		$wpdb->query( "DROP TABLE IF EXISTS `$block`" );
		$wpdb->query( "DROP TABLE IF EXISTS `$log`" );
		$wpdb->query( 'COMMIT' );

		// An install sitting on the previous release.
		update_option( 'aisooq_version', '2.9.1' );
		delete_option( 'aisooq_table_missing' );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $block ) ), 'precondition: table absent' );

		AI_Sooq_Install::maybe_upgrade();

		$this->assertSame( $block, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $block ) ), 'blocklist table must be created' );
		$this->assertSame( $log, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ), 'block log table must be created' );
		$this->assertSame( AISOOQ_VERSION, get_option( 'aisooq_version' ), 'version must be stamped' );
		$this->assertFalse( get_option( 'aisooq_table_missing' ), 'no table should be reported missing' );

		// And the feature actually works immediately after the upgrade.
		AI_Sooq_Blocklist::flush_caches();
		$this->assertTrue( AI_Sooq_Blocklist::add( 'phone', '01712345678', 'block', 'post-upgrade' ) );
		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '01712345678', '', '' )['decision'] );

		$wpdb->query( "DROP TABLE IF EXISTS `$block`" );
		$wpdb->query( "DROP TABLE IF EXISTS `$log`" );
	}
}
