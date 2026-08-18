<?php
/**
 * The "AI Sooq" column in the orders list.
 *
 * The control is icon-only, which puts a specific burden on it: the words it
 * no longer shows have to survive somewhere a screen reader can reach, and the
 * class the click handler binds to has to stay attached to the button even
 * though the class that styles it changed.
 *
 * @package AISooq
 */

class Test_Orders_Column extends WP_UnitTestCase {

	/** @var AI_Sooq_Orders_Column */
	private $column;

	public function set_up() {
		parent::set_up();
		update_option(
			AISOOQ_OPTION,
			array( 'active' => 1, 'api_base' => 'https://api.example.test', 'sid' => 'store1' )
		);
		$settings     = new AI_Sooq_Settings();
		$this->column = new AI_Sooq_Orders_Column( $settings, new AI_Sooq_Logger( $settings ) );
	}

	private function render( WC_Order $order ) {
		$m = new ReflectionMethod( AI_Sooq_Orders_Column::class, 'cell' );
		$m->setAccessible( true );
		return $m->invoke( $this->column, $order );
	}

	private function unsynced_order() {
		$o = new WC_Order();
		$o->set_billing_phone( '+8801712345678' );
		$o->save();
		return $o;
	}

	private function synced_order() {
		$o = $this->unsynced_order();
		$o->update_meta_data( AISOOQ_META_ID, '9001' );
		$o->update_meta_data( AISOOQ_META_SYNCED_AT, '2026-08-18 06:00' );
		$o->save();
		return wc_get_order( $o->get_id() );
	}

	public function test_an_unsynced_order_offers_a_sync_icon() {
		$html = $this->render( $this->unsynced_order() );

		$this->assertStringContainsString( 'dashicons-cloud-upload', $html );
		// The word is gone from the surface but must not be gone from the page.
		$this->assertStringContainsString( 'aria-label="Sync"', $html );
		$this->assertStringContainsString( 'aisooq-sync-order', $html, 'the click handler binds to this class' );
		$this->assertStringContainsString( 'aisooq-order-icon', $html, 'and this one sizes it' );
	}

	public function test_a_synced_order_shows_the_state_and_still_offers_resync() {
		$html = $this->render( $this->synced_order() );

		// A synced order used to be a dead end; the resync control is the whole
		// point of the state and must not disappear behind an icon change.
		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringContainsString( 'aria-label="Synced"', $html );
		$this->assertStringContainsString( 'is-resync', $html );
		$this->assertStringContainsString( 'aria-label="Resync"', $html );
		$this->assertStringContainsString( 'dashicons-update', $html );
	}

	public function test_the_state_mark_is_announced_not_just_drawn() {
		// A bare <span aria-label> is skipped by screen readers without a role.
		$this->assertMatchesRegularExpression(
			'/class="aisooq-order-synced"[^>]*role="img"/',
			$this->render( $this->synced_order() )
		);
	}

	public function test_the_platform_id_and_sync_time_survive_in_the_tooltip() {
		// The badge used to carry them as text; icon-only, the title is the only
		// place left for an operator to see WHICH platform order this is.
		$html = $this->render( $this->synced_order() );
		$this->assertStringContainsString( 'Platform #9001', $html );
		$this->assertStringContainsString( '2026-08-18 06:00', $html );
	}

	public function test_no_visible_text_is_left_inside_the_controls() {
		foreach ( array( $this->unsynced_order(), $this->synced_order() ) as $order ) {
			$html = $this->render( $order );
			// Anything between the button tags other than the icon span would
			// re-widen the column this change exists to narrow.
			$this->assertDoesNotMatchRegularExpression(
				'/<button[^>]*>\s*(?!<span)[^<\s]/',
				$html
			);
		}
	}

	public function test_a_non_order_renders_nothing_rather_than_a_broken_control() {
		$m = new ReflectionMethod( AI_Sooq_Orders_Column::class, 'cell' );
		$m->setAccessible( true );
		$this->assertSame( '', $m->invoke( $this->column, null ) );
	}
}
