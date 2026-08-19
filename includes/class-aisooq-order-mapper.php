<?php
/**
 * Maps a WC_Order into the platform `POST /connect/orders` payload
 * (IngestOrderDto). Lines are pushed FREE-TEXT (title/sku/price, no variantId)
 * so ingestion never touches platform inventory — the platform mirrors Woo.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Order_Mapper {

	/**
	 * @param WC_Order $order
	 * @param bool     $is_backfill When true (Sync-now / past orders) mirror the
	 *                 WooCommerce status verbatim; when false (a live order) a
	 *                 COD order still in "processing" is reported as unpaid,
	 *                 since cash-on-delivery isn't collected until delivery.
	 * @return array
	 */
	public static function map( WC_Order $order, $is_backfill = false ) {
		$status = $order->get_status(); // no "wc-" prefix
		$is_cod = 'cod' === $order->get_payment_method();

		if ( 'refunded' === $status ) {
			$financial = 'refunded';
		} elseif ( ! $is_backfill && $is_cod && 'processing' === $status ) {
			// Live COD order in processing: payment is collected on delivery, so
			// it's pending, not paid. Backfilled/past orders are mirrored as-is.
			$financial = 'pending';
		} elseif ( $order->is_paid() || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$financial = 'paid';
		} else {
			$financial = 'pending';
		}

		// Product lines + order fees. Positive fees (COD fee, gift wrap) become
		// extra line items; negative fees (gift card, store credit, smart-coupon)
		// become an order-level discount — so the mirrored total still
		// reconstructs on the platform without an OrderFee model.
		$lines        = self::line_items( $order );
		$fee_discount = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			$amt = round( (float) $fee->get_total(), 2 ); // ex-tax; can be negative
			if ( $amt < 0 ) {
				$fee_discount += -$amt;
			} elseif ( $amt > 0 ) {
				$lines[] = array(
					'title'    => $fee->get_name() ? $fee->get_name() : __( 'Fee', 'aisooq-connector' ),
					'quantity' => 1,
					'price'    => $amt,
				);
			}
		}
		// Guarantee at least one line. Tax + shipping travel in their own fields
		// and the negative-fee discount is applied separately, so this residual
		// carries only the remaining amount (gross of that discount).
		if ( empty( $lines ) ) {
			$residual = (float) $order->get_total() - (float) $order->get_total_tax() - (float) $order->get_shipping_total() + $fee_discount;
			$lines[]  = array(
				'title'    => __( 'WooCommerce order', 'aisooq-connector' ),
				'quantity' => 1,
				'price'    => (float) max( 0, round( $residual, 2 ) ),
			);
		}

		$payload = array(
			'externalSource'   => 'woocommerce',
			'externalId'       => (string) $order->get_id(),
			'currency'         => $order->get_currency(),
			'email'            => $order->get_billing_email() ?: null,
			'phone'            => $order->get_billing_phone() ?: null,
			'financialStatus'  => $financial,
			'fulfillmentStatus' => ( 'completed' === $status ) ? 'fulfilled' : 'unfulfilled',
			'wcStatus'         => $status,
			'paymentGateway'   => substr( (string) ( $order->get_payment_method() ?: 'external' ), 0, 32 ),
			'lineItems'        => $lines,
			'totalTax'         => (float) $order->get_total_tax(),
			// Authoritative WooCommerce aggregates. The platform re-derives the
			// total from the lines above; it uses orderTotal purely as a checksum
			// and raises a reconciliation alert when the two disagree (a dropped
			// fee, a platform-only discount, tax drift), never overriding it.
			'orderTotal'         => (float) $order->get_total(),
			'orderSubtotal'      => (float) $order->get_subtotal(),
			'orderDiscountTotal' => (float) $order->get_total_discount(),
			'note'             => $order->get_customer_note() ?: null,
		);

		// When the shopper actually placed the order, as a UTC instant.
		//
		// Without this the platform can only stamp the moment the push arrived,
		// which dates an entire backfill of past orders to the hour the plugin
		// was installed — every sales report, cohort and date filter over there
		// then collapses onto that one afternoon.
		//
		// `get_date_created()` returns a WC_DateTime in the SITE's timezone;
		// `setTimezone( UTC )` before formatting converts the instant rather
		// than relabelling it, so a Dhaka shop's 18:06 goes out as 12:06Z and
		// comes back as 18:06 Dhaka — not 18:06Z / midnight local.
		$created = $order->get_date_created();
		if ( $created ) {
			$utc = clone $created;
			$utc->setTimezone( new DateTimeZone( 'UTC' ) );
			$payload['placedAt'] = $utc->format( 'Y-m-d\TH:i:s\Z' );
		}

		// Negative-fee discount (gift card / store credit) as an order-level
		// manual discount — the connector suppresses platform auto-discounts, so
		// this is the only discount stacked on the mirror.
		if ( $fee_discount > 0 ) {
			$payload['discount'] = array( 'amount' => round( $fee_discount, 2 ), 'type' => 'amount' );
		}
		// Cumulative refunded amount → the platform books the delta and mirrors a
		// partial refund as partially_refunded (full as refunded).
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded > 0 ) {
			$payload['refundedAmount'] = round( $refunded, 2 );
		}

		$shipping = self::address( $order, 'shipping' );
		if ( null === $shipping ) {
			$shipping = self::address( $order, 'billing' );
		}
		if ( null !== $shipping ) {
			$payload['shippingAddress'] = $shipping;
		}
		$billing = self::address( $order, 'billing' );
		if ( null !== $billing ) {
			$payload['billingAddress'] = $billing;
		}

		$shipping_lines = self::shipping_lines( $order );
		if ( ! empty( $shipping_lines ) ) {
			$payload['shippingLines'] = $shipping_lines;
		}

		$blob        = AI_Sooq_Attribution::get( $order );
		$attribution = self::attribution( $order, $blob );

		// Derived from that attribution, so it has to come after it. Was
		// hardcoded to manual/woocommerce for every order — see origin().
		$origin                  = self::origin( $order, $attribution );
		$payload['channel']      = $origin['channel'];
		$payload['sourceName']   = $origin['sourceName'];

		if ( ! empty( $attribution ) ) {
			$payload['attribution'] = $attribution;
		}
		// Rich attribution (first + last touch, traffic source, browser time,
		// device, visit count) that doesn't fit the flat UTM columns.
		if ( ! empty( $blob ) ) {
			$payload['attributionExtra'] = $blob;
		}

		// Cart fingerprint (stamped at checkout by the abandoned-sync converter)
		// so the platform can close the abandoned checkout this order recovered.
		$fingerprint = (string) $order->get_meta( '_aisooq_cart_fingerprint' );
		if ( '' !== $fingerprint ) {
			$payload['cartFingerprint'] = $fingerprint;
		}

		/**
		 * Filter the mirrored-order payload before it is pushed.
		 *
		 * @param array    $payload
		 * @param WC_Order $order
		 */
		$payload = apply_filters( 'aisooq_order_payload', $payload, $order );

		/**
		 * Deprecated alias kept for sites that hooked the filter under the
		 * plugin's previous names. Runs after the current filter so a site that
		 * has migrated its code wins. Slated for removal in 3.0.
		 *
		 * @deprecated 2.0.0 Use `aisooq_order_payload`.
		 */
		if ( has_filter( 'shopify_pulse_order_payload' ) ) {
			$payload = apply_filters( 'shopify_pulse_order_payload', $payload, $order );
		}
		if ( has_filter( 'wafi_connector_order_payload' ) ) {
			$payload = apply_filters( 'wafi_connector_order_payload', $payload, $order );
		}

		return $payload;
	}

	private static function line_items( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$qty      = (int) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal(); // pre-discount line total
			$total    = (float) $item->get_total();     // post-discount line total
			$product  = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$sku      = $product ? $product->get_sku() : '';
			// Keep enough precision that price*qty reconstructs the line
			// subtotal — a 2-dp unit price drifts by up to a cent per line when
			// the subtotal doesn't divide evenly by quantity.
			$unit     = $qty > 0 ? round( $subtotal / $qty, 6 ) : $subtotal;

			$lines[] = array(
				'title'         => $item->get_name(),
				'sku'           => $sku ? $sku : null,
				'quantity'      => max( 1, $qty ),
				'price'         => (float) $unit,
				'totalDiscount' => (float) max( 0, round( $subtotal - $total, 2 ) ),
			);
		}
		// The "at least one line" guarantee lives in map(), after fees are folded
		// in (an itemless order may still carry fees) — return product lines only.
		return $lines;
	}

	/**
	 * One platform shipping line per WooCommerce shipping method, carrying the
	 * method's identity so the platform can map it to a shipping rate/zone (or
	 * reconcile later) instead of a hardcoded label. `code` encodes the WC
	 * method + instance (e.g. "flat_rate:3"), which the platform connector can
	 * resolve to a ShippingRate; unmatched, it stays a faithful mirror line.
	 * `price` is the ex-tax method total (shipping tax stays in totalTax), so
	 * the platform's sum(shippingLines.price) still equals get_shipping_total().
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private static function shipping_lines( WC_Order $order ) {
		$map   = self::shipping_map();
		$lines = array();
		foreach ( $order->get_shipping_methods() as $item ) {
			/** @var WC_Order_Item_Shipping $item */
			$method_id   = is_callable( array( $item, 'get_method_id' ) ) ? (string) $item->get_method_id() : '';
			$instance_id = is_callable( array( $item, 'get_instance_id' ) ) ? (string) $item->get_instance_id() : '';
			$title       = $item->get_name() ? $item->get_name() : __( 'Shipping', 'aisooq-connector' );

			$code = '' !== $method_id
				? $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' )
				: 'woocommerce';

			$line = array(
				'title'  => (string) $title,
				'code'   => substr( $code, 0, 64 ),
				'source' => 'woocommerce',
				'price'  => (float) $item->get_total(), // ex-tax
			);
			// If the operator mapped this WC method to a platform shipping rate,
			// tag it so the platform links the delivery charge to that rate
			// (else the platform raises an unmapped-shipping reconcile alert).
			$rate_id = isset( $map[ $code ] ) ? (int) $map[ $code ] : 0;
			if ( $rate_id > 0 ) {
				$line['shippingRateId'] = $rate_id;
			}

			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * The operator's WooCommerce-method → platform-shipping-rate map, keyed by
	 * "<method_id>:<instance_id>" (the shipping line `code`). Stored in the
	 * connector settings option.
	 *
	 * @return array<string,int>
	 */
	private static function shipping_map() {
		$opt = get_option( AISOOQ_OPTION, array() );
		return ( is_array( $opt ) && isset( $opt['shipping_map'] ) && is_array( $opt['shipping_map'] ) )
			? $opt['shipping_map']
			: array();
	}

	/**
	 * @param WC_Order $order
	 * @param string   $type shipping|billing
	 * @return array|null null when there is no usable street line.
	 */
	private static function address( WC_Order $order, $type ) {
		$g = function ( $field ) use ( $order, $type ) {
			$method = "get_{$type}_{$field}";
			return is_callable( array( $order, $method ) ) ? (string) $order->{$method}() : '';
		};

		$address1 = $g( 'address_1' );
		if ( '' === trim( $address1 ) ) {
			return null;
		}
		$name = trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) );

		return array(
			'name'     => $name,
			'company'  => $g( 'company' ),
			'phone'    => $order->get_billing_phone(),
			'email'    => $order->get_billing_email(),
			'address1' => $address1,
			'address2' => $g( 'address_2' ),
			'city'     => $g( 'city' ),
			'province' => $g( 'state' ),
			'country'  => $g( 'country' ),
			'zip'      => $g( 'postcode' ),
		);
	}


	/**
	 * Which lane the order actually arrived on, and the specific origin.
	 *
	 * Both used to be hardcoded (`manual` / `woocommerce`), so every order this
	 * plugin pushed looked hand-written on the platform no matter where the
	 * buyer came from. That is not just a reporting nicety: the platform's
	 * bdcourier OrderCreated handler skips `manual`, so mislabelled orders
	 * silently opted out of the delivery-risk lookup, and the orders list could
	 * not tell a Messenger sale from a counter sale.
	 *
	 * Everything needed is already on the order — WooCommerce's own
	 * order-attribution meta (WC 8.5+), which this class already reads for the
	 * flat UTM columns. `channel` stays coarse (which lane) and `sourceName`
	 * carries the specific origin, matching the platform's own split.
	 *
	 * @param WC_Order $order
	 * @param array    $attribution flat attribution already computed
	 * @return array{channel:string,sourceName:string}
	 */
	private static function origin( WC_Order $order, $attribution = array() ) {
		$created_via = strtolower( (string) $order->get_created_via() );
		$type        = strtolower( (string) $order->get_meta( '_wc_order_attribution_source_type' ) );
		$utm         = strtolower( (string) ( isset( $attribution['utmSource'] ) ? $attribution['utmSource'] : '' ) );
		$referrer    = strtolower( (string) ( isset( $attribution['referrer'] ) ? $attribution['referrer'] : '' ) );
		$haystack    = $utm . ' ' . $referrer;

		// Social first: a Facebook/Instagram referral is a social sale even
		// though it technically checked out on the web storefront.
		$social = array(
			'messenger' => array( 'messenger', 'm.me' ),
			'facebook'  => array( 'facebook', 'fb.com', 'fbclid' ),
			'instagram' => array( 'instagram', 'ig.me' ),
			'whatsapp'  => array( 'whatsapp', 'wa.me' ),
			'tiktok'    => array( 'tiktok' ),
			'telegram'  => array( 'telegram', 't.me' ),
		);
		foreach ( $social as $name => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					return array( 'channel' => 'social', 'sourceName' => $name );
				}
			}
		}

		// Written by a human in wp-admin, or over the phone into the shop.
		if ( 'admin' === $created_via || 'admin' === $type ) {
			return array( 'channel' => 'manual', 'sourceName' => 'woocommerce-admin' );
		}

		if ( 'pos' === $created_via ) {
			return array( 'channel' => 'pos', 'sourceName' => 'woocommerce-pos' );
		}

		// Anything else is a shopper who checked out on the storefront. Keep the
		// specific origin when attribution knows it (organic, referral, a named
		// utm_source); fall back to the plugin name.
		$source = '';
		if ( '' !== $utm ) {
			$source = $utm;
		} elseif ( '' !== $type && 'typein' !== $type ) {
			$source = $type;
		}
		return array(
			'channel'    => 'web',
			'sourceName' => substr( '' !== $source ? $source : 'woocommerce', 0, 128 ),
		);
	}

	/**
	 * Flat UTM/referrer attribution for the platform's AttributionDto columns.
	 * Prefers the tracker's LAST-touch cookie; falls back per-field to
	 * WooCommerce's own order-attribution meta.
	 *
	 * @param WC_Order $order
	 * @param array    $blob   the rich attribution blob (may be empty)
	 * @return array
	 */
	private static function attribution( WC_Order $order, $blob = array() ) {
		$out  = array();
		$last = ( isset( $blob['last_touch'] ) && is_array( $blob['last_touch'] ) ) ? $blob['last_touch'] : array();
		$cap  = function ( $key, $val ) {
			$limit = ( 0 === strpos( $key, 'utm' ) ) ? 128 : 1024;
			return substr( (string) $val, 0, $limit );
		};

		$from_blob = array(
			'utmSource'   => 'utm_source',
			'utmMedium'   => 'utm_medium',
			'utmCampaign' => 'utm_campaign',
			'utmTerm'     => 'utm_term',
			'utmContent'  => 'utm_content',
			'referrer'    => 'referrer',
			'landingPath' => 'landing_path',
		);
		foreach ( $from_blob as $dto => $bk ) {
			if ( ! empty( $last[ $bk ] ) ) {
				$out[ $dto ] = $cap( $dto, $last[ $bk ] );
			}
		}

		$from_wc = array(
			'utmSource'   => '_wc_order_attribution_utm_source',
			'utmMedium'   => '_wc_order_attribution_utm_medium',
			'utmCampaign' => '_wc_order_attribution_utm_campaign',
			'utmTerm'     => '_wc_order_attribution_utm_term',
			'utmContent'  => '_wc_order_attribution_utm_content',
			'referrer'    => '_wc_order_attribution_referrer',
			'landingPath' => '_wc_order_attribution_session_entry',
		);
		foreach ( $from_wc as $dto => $meta ) {
			if ( empty( $out[ $dto ] ) ) {
				$val = $order->get_meta( $meta );
				if ( '' !== (string) $val ) {
					$out[ $dto ] = $cap( $dto, $val );
				}
			}
		}
		return $out;
	}
}
