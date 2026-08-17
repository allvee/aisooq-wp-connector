<?php
/**
 * Order mapper against real WooCommerce orders — the money contract the
 * platform ingest depends on (COD pending rule, fee/discount handling,
 * authoritative totals). Skipped when WooCommerce isn't installed.
 *
 * @package AISooq
 */

class Test_Order_Mapper extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
	}

	private function make_product( $price = 1000 ) {
		$p = new WC_Product_Simple();
		$p->set_regular_price( (string) $price );
		$p->set_price( (string) $price );
		$p->set_name( 'Test Perfume' );
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	private function make_cod_order( $status = 'processing', $qty = 1, $price = 1000 ) {
		$order = new WC_Order();
		$order->add_product( $this->make_product( $price ), $qty );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	public function test_cod_processing_maps_to_pending_on_live_push() {
		$order   = $this->make_cod_order( 'processing' );
		$payload = AI_Sooq_Order_Mapper::map( $order, false );
		$this->assertSame( 'pending', $payload['financialStatus'] );
		$this->assertSame( 'cod', $payload['paymentGateway'] );
	}

	public function test_cod_processing_keeps_source_status_on_backfill() {
		$order   = $this->make_cod_order( 'processing' );
		$payload = AI_Sooq_Order_Mapper::map( $order, true );
		// is_paid() is true for processing → mirrored verbatim (paid) on backfill.
		$this->assertSame( 'paid', $payload['financialStatus'] );
	}

	/**
	 * The platform stamps its own createdAt when the push carries no date, so a
	 * backfill of past orders would otherwise all land on the install date.
	 * placedAt must be the order's real creation instant, expressed in UTC.
	 */
	public function test_placed_at_is_the_orders_creation_time_in_utc() {
		$order = $this->make_cod_order( 'processing' );
		$order->set_date_created( '2026-07-02T14:31:05+06:00' ); // Dhaka wall clock
		$order->save();

		$payload = AI_Sooq_Order_Mapper::map( wc_get_order( $order->get_id() ), true );

		$this->assertArrayHasKey( 'placedAt', $payload );
		// Converted, not relabelled: 14:31 +06:00 is 08:31Z.
		$this->assertSame( '2026-07-02T08:31:05Z', $payload['placedAt'] );
	}

	public function test_placed_at_round_trips_back_to_the_same_local_time() {
		$order = $this->make_cod_order( 'processing' );
		$order->set_date_created( '2026-07-02T14:31:05+06:00' );
		$order->save();

		$payload = AI_Sooq_Order_Mapper::map( wc_get_order( $order->get_id() ), true );
		$back    = new DateTime( $payload['placedAt'] );
		$back->setTimezone( new DateTimeZone( 'Asia/Dhaka' ) );

		$this->assertSame( '2026-07-02 14:31:05', $back->format( 'Y-m-d H:i:s' ) );
	}

	public function test_authoritative_total_and_line_reconstruction() {
		$order   = $this->make_cod_order( 'processing', 2, 500 ); // subtotal 1000
		$payload = AI_Sooq_Order_Mapper::map( $order, false );

		$this->assertEqualsWithDelta( (float) $order->get_total(), $payload['orderTotal'], 0.01 );

		$subtotal = 0.0;
		foreach ( $payload['lineItems'] as $l ) {
			$subtotal += $l['price'] * $l['quantity'] - ( $l['totalDiscount'] ?? 0 );
		}
		$shipping = 0.0;
		foreach ( $payload['shippingLines'] ?? array() as $s ) {
			$shipping += $s['price'];
		}
		$recon = round( $subtotal + $shipping + $payload['totalTax'], 2 );
		$this->assertEqualsWithDelta( $payload['orderTotal'], $recon, 0.01 );
	}

	public function test_positive_fee_becomes_a_line_item() {
		$order = $this->make_cod_order( 'processing', 1, 1000 );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'COD fee' );
		$fee->set_total( '20' );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->save();

		$payload = AI_Sooq_Order_Mapper::map( wc_get_order( $order->get_id() ), false );

		$titles = wp_list_pluck( $payload['lineItems'], 'title' );
		$this->assertContains( 'COD fee', $titles );
		$this->assertEqualsWithDelta( (float) $order->get_total(), $payload['orderTotal'], 0.01 );
	}

	public function test_full_refund_reports_refunded_amount() {
		$order = $this->make_cod_order( 'completed', 1, 1000 );
		wc_create_refund( array(
			'order_id' => $order->get_id(),
			'amount'   => (float) $order->get_total(),
		) );

		$payload = AI_Sooq_Order_Mapper::map( wc_get_order( $order->get_id() ), false );
		$this->assertArrayHasKey( 'refundedAmount', $payload );
		$this->assertGreaterThan( 0, $payload['refundedAmount'] );
	}
}
