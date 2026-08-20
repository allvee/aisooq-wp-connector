<?php
/**
 * The duplicate-order guard.
 *
 * This is the one gate that turns a real shopper away using only local data,
 * so the cases that matter are the ones where it must NOT fire: outside the
 * window, on a different buyer, and — the one that would trap somebody with no
 * way out — on their own cancelled or failed attempt.
 *
 * Driven through `screen_classic()`, the hook WooCommerce actually calls, so
 * these test the behaviour a shopper meets rather than a private helper.
 *
 * @package AISooq
 */

class Test_Fraud_Duplicate extends WP_UnitTestCase {

	/** @var AI_Sooq_Fraud */
	private $fraud;

	private function configure( array $overrides = array() ) {
		update_option( AISOOQ_OPTION, array_merge( array(
			'active'                 => 1,
			'api_base'               => 'https://api.example.test',
			'sid'                    => 'store1',
			'client_id'              => 'cid',
			'client_secret'          => 'csecret',
			// The platform layers stay off: this suite is about the local gate,
			// and an HTTP call here would be a network dependency in a unit test.
			'enable_fraud'           => 0,
			'courier_min_ratio'      => 0,
			'dup_order_block'        => 1,
			'dup_order_window_hours' => 24,
		), $overrides ) );

		$settings    = new AI_Sooq_Settings();
		$logger      = new AI_Sooq_Logger( $settings );
		$this->fraud = new AI_Sooq_Fraud( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );
	}

	/**
	 * Place an order for a buyer, optionally aged and in a given status.
	 *
	 * @param string $phone
	 * @param string $email
	 * @param int    $hours_ago
	 * @param string $status
	 * @return WC_Order
	 */
	private function make_order( $phone, $email = '', $hours_ago = 0, $status = 'processing' ) {
		$order = new WC_Order();
		$order->set_billing_phone( $phone );
		if ( '' !== $email ) {
			$order->set_billing_email( $email );
		}
		if ( $hours_ago > 0 ) {
			$order->set_date_created( time() - ( $hours_ago * HOUR_IN_SECONDS ) );
		}
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/** Run the classic-checkout gate and return the errors it raised. */
	private function screen( $phone, $email = '' ) {
		$errors = new WP_Error();
		$this->fraud->screen_classic(
			array(
				'billing_first_name' => 'Karim',
				'billing_last_name'  => 'Rahman',
				'billing_phone'      => $phone,
				'billing_email'      => $email,
				'billing_address_1'  => 'House 4, Road 7, Dhanmondi',
			),
			$errors
		);
		return $errors;
	}

	private function assertBlocked( WP_Error $errors, $message = '' ) {
		$this->assertContains( 'aisooq_duplicate', $errors->get_error_codes(), $message ?: 'expected a duplicate block' );
	}

	private function assertAllowed( WP_Error $errors, $message = '' ) {
		$this->assertNotContains( 'aisooq_duplicate', $errors->get_error_codes(), $message ?: 'expected the checkout to pass' );
	}

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not available.' );
		}
	}

	// ── Off ────────────────────────────────────────────────────────────────

	public function test_allows_when_the_guard_is_off() {
		$this->configure( array( 'dup_order_block' => 0 ) );
		$this->make_order( '01711000000' );
		$this->assertAllowed( $this->screen( '01711000000' ) );
	}

	// ── The block it exists for ────────────────────────────────────────────

	public function test_blocks_a_repeat_order_from_the_same_phone_inside_the_window() {
		$this->configure();
		$this->make_order( '01711000000', '', 2 );
		$this->assertBlocked( $this->screen( '01711000000' ) );
	}

	public function test_blocks_on_email_even_when_the_phone_differs() {
		// The whole trick this guard stops: same buyer, new number.
		$this->configure();
		$this->make_order( '01711000000', 'karim@example.com', 1 );
		$this->assertBlocked( $this->screen( '01999999999', 'karim@example.com' ) );
	}

	public function test_blocks_on_phone_even_when_the_email_differs() {
		$this->configure();
		$this->make_order( '01711000000', 'first@example.com', 1 );
		$this->assertBlocked( $this->screen( '01711000000', 'second@example.com' ) );
	}

	// ── The cases where turning someone away would be wrong ────────────────

	public function test_allows_a_first_time_buyer() {
		$this->configure();
		$this->assertAllowed( $this->screen( '01711000000' ) );
	}

	public function test_allows_a_different_buyer() {
		$this->configure();
		$this->make_order( '01711000000' );
		$this->assertAllowed( $this->screen( '01822000000' ) );
	}

	public function test_allows_once_the_window_has_passed() {
		$this->configure( array( 'dup_order_window_hours' => 24 ) );
		$this->make_order( '01711000000', '', 25 );
		$this->assertAllowed( $this->screen( '01711000000' ) );
	}

	/**
	 * The trap case. A shopper whose payment failed retries within minutes; if
	 * their own failed attempt counts as the duplicate they can never get
	 * through, and the store loses the sale to its own fraud setting.
	 *
	 * @dataProvider non_sale_statuses
	 */
	public function test_allows_a_retry_after_a_non_sale( $status ) {
		$this->configure();
		$this->make_order( '01711000000', '', 1, $status );
		$this->assertAllowed( $this->screen( '01711000000' ), "status {$status} must not block a retry" );
	}

	public function non_sale_statuses() {
		return array( array( 'cancelled' ), array( 'failed' ), array( 'refunded' ) );
	}

	public function test_allows_when_there_is_no_contact_to_match_on() {
		$this->configure();
		$this->make_order( '01711000000' );
		$this->assertAllowed( $this->screen( '', '' ) );
	}

	// ── The window is respected, and the message says what it is ───────────

	public function test_a_wider_window_catches_an_older_order() {
		$this->configure( array( 'dup_order_window_hours' => 72 ) );
		$this->make_order( '01711000000', '', 48 );
		$this->assertBlocked( $this->screen( '01711000000' ) );
	}

	public function test_message_substitutes_the_configured_window() {
		$this->configure( array(
			'dup_order_window_hours' => 6,
			'msg_duplicate'          => 'Already ordered — wait {hours} hours.',
		) );
		$this->make_order( '01711000000', '', 1 );
		$errors = $this->screen( '01711000000' );
		$this->assertBlocked( $errors );
		$this->assertSame( 'Already ordered — wait 6 hours.', $errors->get_error_message( 'aisooq_duplicate' ) );
	}

	/**
	 * A stored window of 0 must not mean "no window" (never block) or "infinite
	 * window" (block forever) — both read as the setting being broken. The
	 * runtime clamp falls back to 24h, and this asserts it in behaviour rather
	 * than by reaching into the sanitiser, which lives inside the $_POST save
	 * path and cannot be called with a plain array.
	 */
	public function test_a_zero_window_falls_back_to_the_default_day() {
		$this->configure( array( 'dup_order_window_hours' => 0 ) );
		$this->make_order( '01711000000', '', 2 );
		$this->assertBlocked( $this->screen( '01711000000' ), '2h ago is inside the fallback 24h window' );
	}

	public function test_a_zero_window_still_expires_after_a_day() {
		$this->configure( array( 'dup_order_window_hours' => 0 ) );
		$this->make_order( '01711000000', '', 30 );
		$this->assertAllowed( $this->screen( '01711000000' ), '30h ago is outside the fallback 24h window' );
	}

	/** An absurd stored value is capped at a week, not honoured literally. */
	public function test_an_oversized_window_is_capped_at_a_week() {
		$this->configure( array( 'dup_order_window_hours' => 100000 ) );
		$this->make_order( '01711000000', '', 24 * 8 );
		$this->assertAllowed( $this->screen( '01711000000' ), 'eight days ago is beyond the 168h cap' );
	}
}
