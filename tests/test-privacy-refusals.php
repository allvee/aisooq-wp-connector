<?php
/**
 * A data-subject request has to include the refused-checkout log.
 *
 * That table holds a name, e-mail, phone and IP for every checkout the fraud
 * gate turned away, and it was never registered with WordPress's exporter or
 * eraser — so a request came back looking complete while silently omitting the
 * one record of a person being refused service.
 *
 * @package AISooq
 */

class Test_Privacy_Refusals extends WP_UnitTestCase {

	/** @var AI_Sooq_Privacy */
	private $privacy;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
		AI_Sooq_Install::create_table();
		AI_Sooq_Blocklist::flush_caches();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AI_Sooq_Blocklist::table_name() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . AI_Sooq_Blocklist::log_table_name() ); // phpcs:ignore WordPress.DB

		$this->privacy = new AI_Sooq_Privacy();
	}

	private function log_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AI_Sooq_Blocklist::log_table_name() ); // phpcs:ignore WordPress.DB
	}

	public function test_both_new_handlers_are_registered_with_wordpress() {
		$this->assertArrayHasKey( 'aisooq-connector-refusals', $this->privacy->register_exporter( array() ) );
		$this->assertArrayHasKey( 'aisooq-connector-refusals', $this->privacy->register_eraser( array() ) );
	}

	public function test_a_refusal_is_exported_by_email() {
		AI_Sooq_Blocklist::log( 'fraud', 'Courier refusal ratio 88%', array( 'email' => 'risky@example.test', 'ip' => '203.0.113.7', 'name' => 'Risky Buyer' ) );
		AI_Sooq_Blocklist::log( 'fraud', 'Someone else', array( 'email' => 'other@example.test' ) );

		$out = $this->privacy->export_refusals( 'risky@example.test', 1 );

		$this->assertCount( 1, $out['data'], 'only this person' );
		$this->assertTrue( $out['done'] );
		$values = wp_list_pluck( $out['data'][0]['data'], 'value' );
		$this->assertContains( '203.0.113.7', $values );
		$this->assertContains( 'Risky Buyer', $values );
	}

	/**
	 * In this market a refusal is very often recorded against a phone alone.
	 * Matching the request's e-mail only would leave most of a person's refusals
	 * behind, so the registered customer's billing phone is matched too — raw OR
	 * normalised, since '+8801712…' and '01712…' are the same person.
	 */
	public function test_a_refusal_logged_only_against_the_customers_phone_is_found() {
		$user = self::factory()->user->create( array( 'user_email' => 'phoneonly@example.test' ) );
		update_user_meta( $user, 'billing_phone', '+8801712345678' );

		AI_Sooq_Blocklist::log( 'duplicate', 'Duplicate order within 10 minutes', array( 'phone' => AI_Sooq_Blocklist::normalize( 'phone', '+8801712345678' ) ) );

		$out = $this->privacy->export_refusals( 'phoneonly@example.test', 1 );
		$this->assertCount( 1, $out['data'] );
	}

	public function test_erasing_removes_the_persons_refusals_and_nobody_elses() {
		AI_Sooq_Blocklist::log( 'fraud', 'r1', array( 'email' => 'gone@example.test' ) );
		AI_Sooq_Blocklist::log( 'fraud', 'r2', array( 'email' => 'gone@example.test' ) );
		AI_Sooq_Blocklist::log( 'fraud', 'r3', array( 'email' => 'stays@example.test' ) );

		$res = $this->privacy->erase_refusals( 'gone@example.test', 1 );

		$this->assertTrue( $res['items_removed'] );
		$this->assertSame( 1, $this->log_count(), 'the other person\'s refusal survives' );
		$this->assertSame( array(), $this->privacy->export_refusals( 'gone@example.test', 1 )['data'] );
	}

	/**
	 * The block list is the store's fraud control. Deleting an entry because the
	 * person it blocks asked would lift the block for exactly the customer it was
	 * added to stop — so it is exported, kept, and the operator is told.
	 */
	public function test_a_block_list_entry_is_exported_but_kept_and_the_operator_is_told() {
		$this->assertTrue( AI_Sooq_Blocklist::add( 'email', 'Blocked@Example.test', 'block', 'repeat fake orders' ) );

		$export = $this->privacy->export_refusals( 'blocked@example.test', 1 );
		$groups = wp_list_pluck( $export['data'], 'group_id' );
		$this->assertContains( 'aisooq-block-list', $groups, 'exported — matched on the stored normalised value, not the raw one it was typed as' );

		$res = $this->privacy->erase_refusals( 'blocked@example.test', 1 );
		$this->assertTrue( $res['items_retained'] );
		$this->assertNotEmpty( $res['messages'] );

		AI_Sooq_Blocklist::flush_caches();
		$this->assertSame( 'block', AI_Sooq_Blocklist::decide( '', 'blocked@example.test', '' )['decision'], 'and the block still works' );
	}

	public function test_a_person_with_nothing_on_record_gets_a_clean_empty_answer() {
		$out = $this->privacy->export_refusals( 'nobody@example.test', 1 );
		$this->assertSame( array(), $out['data'] );
		$this->assertTrue( $out['done'] );

		$res = $this->privacy->erase_refusals( 'nobody@example.test', 1 );
		$this->assertFalse( $res['items_removed'] );
		$this->assertFalse( $res['items_retained'] );
	}
}
