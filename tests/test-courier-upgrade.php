<?php
/**
 * The 2.0.0 → 2.1.0 schema upgrade for the abandoned-cart table.
 *
 * Real installs have live carts in this table, so adding the courier columns
 * has to be additive: `dbDelta` must ALTER the existing table rather than the
 * plugin quietly assuming a fresh one. If this regresses, a merchant loses
 * their captured carts on update — the worst possible outcome for a change
 * whose whole point is not losing data.
 *
 * @package AISooq
 */

class Test_Courier_Upgrade extends WP_UnitTestCase {

	private function table() {
		return AI_Sooq_Abandoned_Sync::table_name();
	}

	private function columns() {
		global $wpdb;
		return (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . $this->table() ); // phpcs:ignore WordPress.DB
	}

	/** Recreate the pre-2.1.0 table: everything except the courier_* columns. */
	private function make_legacy_table() {
		global $wpdb;
		$table = $this->table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE {$table} (
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
			)"
		);
	}

	private function seed_legacy_cart( $key = 'sess_legacy' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$this->table(),
			array(
				'session_key'   => $key,
				'customer_name' => 'Karim Rahman',
				'phone'         => '+8801712345678',
				'cart_json'     => wp_json_encode( array( array( 'title' => 'Imperial Oud', 'qty' => 2, 'price' => 1500 ) ) ),
				'subtotal'      => 3000,
				'status'        => 'active',
				'synced'        => 1,
				'created_at'    => '2026-07-30 08:00:00',
				'updated_at'    => '2026-07-31 08:00:00',
			)
		);
		return $key;
	}

	public function test_legacy_table_has_no_courier_columns() {
		$this->make_legacy_table();
		$this->assertNotContains( 'courier_ratio', $this->columns(), 'Fixture sanity: this is the old schema.' );
	}

	public function test_upgrade_adds_the_courier_columns() {
		$this->make_legacy_table();

		AI_Sooq_Install::create_table();

		$cols = $this->columns();
		foreach ( array( 'courier_ratio', 'courier_parcels', 'courier_json', 'courier_checked_at' ) as $c ) {
			$this->assertContains( $c, $cols, "Upgrade must add {$c}." );
		}
	}

	public function test_upgrade_keeps_existing_carts() {
		$this->make_legacy_table();
		$key = $this->seed_legacy_cart();

		AI_Sooq_Install::create_table();

		$sync = $this->make_sync();
		$row  = $sync->get_row( $key );
		$this->assertNotNull( $row, 'An upgrade must never drop captured carts.' );
		$this->assertSame( 'Karim Rahman', $row->customer_name );
		$this->assertSame( '3000.0000', $row->subtotal );
		$this->assertSame( '2026-07-31 08:00:00', $row->updated_at );
	}

	public function test_carts_carried_through_the_upgrade_start_unchecked() {
		$this->make_legacy_table();
		$key = $this->seed_legacy_cart();
		AI_Sooq_Install::create_table();

		$sync = $this->make_sync();
		$this->assertNull(
			$sync->courier_snapshot( $sync->get_row( $key ) ),
			'A cart that predates the feature has no result to show — it must offer a check.'
		);
	}

	public function test_an_upgraded_table_can_store_a_lookup() {
		$this->make_legacy_table();
		$key = $this->seed_legacy_cart();
		AI_Sooq_Install::create_table();

		$sync = $this->make_sync();
		$this->assertTrue( $sync->save_courier( $key, '+8801712345678', array(
			'successRatio'    => 91.5,
			'totalParcel'     => 12,
			'successParcel'   => 11,
			'cancelledParcel' => 1,
			'couriers'        => array(
				array( 'slug' => 'redx', 'name' => 'RedX', 'total' => 12, 'success' => 11, 'cancelled' => 1, 'ratio' => 91.67 ),
			),
		) ) );

		$snap = $sync->courier_snapshot( $sync->get_row( $key ) );
		$this->assertSame( 91.5, $snap['ratio'] );
		$this->assertSame( 'RedX', $snap['couriers'][0]['name'] );
	}

	public function test_create_table_is_idempotent() {
		AI_Sooq_Install::create_table();
		$key = $this->seed_legacy_cart( 'sess_idem' );

		AI_Sooq_Install::create_table();
		AI_Sooq_Install::create_table();

		$this->assertNotNull( $this->make_sync()->get_row( 'sess_idem' ) );
		$this->assertContains( 'courier_ratio', $this->columns() );
	}

	public function test_version_bump_triggers_the_upgrade_on_files_replaced() {
		$this->make_legacy_table();
		// What an update-in-place looks like: new files, old stored version.
		update_option( 'aisooq_version', '2.0.0', false );

		AI_Sooq_Install::maybe_upgrade();

		$this->assertContains( 'courier_ratio', $this->columns() );
		$this->assertSame( AISOOQ_VERSION, get_option( 'aisooq_version' ) );
	}

	private function make_sync() {
		$settings = new AI_Sooq_Settings();
		$logger   = new AI_Sooq_Logger( $settings );
		return new AI_Sooq_Abandoned_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );
	}
}
