<?php
/**
 * Checkout fraud screening. Runs each checkout through the platform's 4-layer
 * fraud engine (POST /fraud/screen) and, per the operator's chosen action,
 * blocks the order, holds it for review, or just flags it.
 *
 * Order of gates at checkout, first failure wins:
 *
 *   0. duplicate-order guard — LOCAL. Same buyer, same store, inside the
 *      configured window. No API call, nothing billed, and it still works
 *      while the platform is unreachable. First because it is the cheapest
 *      question, and answering it can save the billed lookup in gate 3.
 *   1. phone / name / address heuristics      ┐
 *   2. IP-velocity auto-block                 ├ platform, via /fraud/screen
 *   3. courier delivery-history gate          ┘ (3 is separately billed)
 *   4. pixel Purchase dedup (post-order, handled by the analytics path)
 *
 * Gates 1–4 fail OPEN: if the API is unreachable the checkout proceeds, so a
 * platform outage never blocks legitimate sales. Gate 0 fails open too, but
 * it does not depend on the API to answer in the first place.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Fraud {

	const SESSION_KEY = 'aisooq_fraud_verdict';

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Api_Client */
	private $api;
	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Api_Client $api, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;
	}

	public function register() {
		$fraud   = (bool) $this->settings->get( 'enable_fraud' );
		$courier = $this->courier_gate_enabled();
		$dup     = $this->duplicate_gate_enabled();
		// Nothing to enforce at checkout — stay dormant.
		if ( ! $fraud && ! $courier && ! $dup ) {
			return;
		}
		// Classic checkout: validate posted fields; block by adding an error.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'screen_classic' ), 20, 2 );
		// Block/Store-API checkout: screen the order before payment.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'screen_blocks' ), 20, 2 );
		// hold/flag actions are applied once the order exists (fraud only; the
		// courier gate is a hard block, never a post-order hold).
		if ( $fraud ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'apply_to_order' ), 5, 1 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'apply_to_order_obj' ), 5, 1 );
		}
		// Modern block popup on classic checkout + its stash-reader endpoint.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_guard' ), 20 );
		add_action( 'wp_ajax_aisooq_guard', array( $this, 'ajax_guard' ) );
		add_action( 'wp_ajax_nopriv_aisooq_guard', array( $this, 'ajax_guard' ) );

		// Live inline Layer-1 validation on the classic checkout — flag a junk
		// name/address/number under the field as the shopper types, before they
		// hit Place order (advisory; the server still screens at placement).
		if ( $fraud ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_validate' ), 21 );
			add_action( 'wp_ajax_aisooq_validate', array( $this, 'ajax_validate' ) );
			add_action( 'wp_ajax_nopriv_aisooq_validate', array( $this, 'ajax_validate' ) );
		}
	}

	/** Load the inline field-validation script on the (classic) checkout page. */
	public function enqueue_validate() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'aisooq-checkout-validate', AISOOQ_URL . 'assets/js/aisooq-checkout-validate.js', array( 'jquery' ), AISOOQ_VERSION, true );
		wp_localize_script(
			'aisooq-checkout-validate',
			'AISooqValidate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aisooq_validate' ),
			)
		);
	}

	/**
	 * AJAX: forward the in-progress checkout fields to the platform's
	 * non-recording Layer-1 preview and return the per-field verdict. Fails open
	 * (returns enabled:false) on any error — inline validation is advisory, and
	 * the authoritative block still happens server-side at order placement.
	 */
	public function ajax_validate() {
		check_ajax_referer( 'aisooq_validate', 'nonce' );
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';

		$body = array();
		if ( '' !== $name ) {
			$body['name'] = substr( $name, 0, 255 );
		}
		if ( '' !== $phone ) {
			$body['phone'] = substr( $phone, 0, 32 );
		}
		if ( '' !== $address ) {
			$body['address'] = substr( $address, 0, 500 );
		}
		$open = array( 'enabled' => false, 'allowed' => true, 'fields' => array() );
		if ( empty( $body ) ) {
			wp_send_json_success( $open );
		}

		$res = $this->api->storefront_post( '/fraud/preview', $body, true );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			wp_send_json_success( $open );
		}
		wp_send_json_success( $res );
	}

	/** Load the checkout-guard modal on the (classic) checkout page. */
	public function enqueue_guard() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'aisooq-checkout-guard', AISOOQ_URL . 'assets/js/aisooq-checkout-guard.js', array( 'jquery' ), AISOOQ_VERSION, true );
		$c    = $this->support_contact();
		$help = trim( (string) $this->settings->get( 'msg_help' ) );
		if ( '' === $help ) {
			$help = __( 'Need help completing your order? Reach us:', 'aisooq-connector' );
		}
		wp_localize_script(
			'aisooq-checkout-guard',
			'AISooqGuard',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'aisooq_guard' ),
				'phone'     => $c['phone'],
				'whatsapp'  => $c['whatsapp'],
				'messenger' => $c['messenger'],
				'i18n'      => array(
					'title'    => __( 'We can’t place this order', 'aisooq-connector' ),
					'callBtn'  => __( 'Call', 'aisooq-connector' ),
					'waBtn'    => __( 'WhatsApp us', 'aisooq-connector' ),
					'msgrBtn'  => __( 'Messenger', 'aisooq-connector' ),
					'close'    => __( 'Close', 'aisooq-connector' ),
					'help'     => $help,
				),
			)
		);
	}

	/** Return the last checkout block stashed for this session (and clear it). */
	public function ajax_guard() {
		check_ajax_referer( 'aisooq_guard', 'nonce' );
		$block = null;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$block = WC()->session->get( 'aisooq_guard_block' );
			if ( $block ) {
				WC()->session->set( 'aisooq_guard_block', null );
			}
		}
		if ( is_array( $block ) && ! empty( $block['message'] ) ) {
			wp_send_json_success( $block );
		}
		wp_send_json_error();
	}

	/** Stash a block for the checkout-guard modal to pick up. */
	private function stash_block( $type, $message ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'aisooq_guard_block', array( 'type' => $type, 'message' => wp_strip_all_tags( (string) $message ) ) );
		}
	}

	/**
	 * Resolve the support contact shown on a block: the plugin's explicit
	 * Support phone / WhatsApp settings, falling back to the connected tenant's
	 * contact number (cached from the last "Verify connection").
	 *
	 * @return array{phone:string,whatsapp:string}
	 */
	private function support_contact() {
		$phone = trim( (string) $this->settings->get( 'support_phone' ) );
		$wa    = trim( (string) $this->settings->get( 'support_whatsapp' ) );
		if ( '' === $phone || '' === $wa ) {
			$status = get_option( 'aisooq_status', array() );
			$store  = ( is_array( $status ) && isset( $status['store'] ) && is_array( $status['store'] ) ) ? $status['store'] : array();
			$tenant = ! empty( $store['contactPhone'] ) ? (string) $store['contactPhone'] : '';
			if ( '' === $phone ) {
				$phone = $tenant;
			}
			if ( '' === $wa ) {
				$wa = $tenant;
			}
		}
		$messenger = trim( (string) $this->settings->get( 'support_messenger' ) );
		return array(
			'phone'     => $phone,
			// wa.me wants digits only (country code, no +/spaces).
			'whatsapp'  => $wa ? preg_replace( '/\D+/', '', $wa ) : '',
			'messenger' => $messenger ? esc_url_raw( $messenger ) : '',
		);
	}

	/** Whether the operator has armed the courier delivery-ratio gate. */
	private function courier_gate_enabled() {
		return (int) $this->settings->get( 'courier_min_ratio' ) > 0;
	}

	/** Whether the operator has armed the duplicate-order guard. */
	private function duplicate_gate_enabled() {
		return (bool) $this->settings->get( 'dup_order_block' );
	}

	/** The configured duplicate window, clamped the same way the settings are. */
	private function duplicate_window_hours() {
		$h = (int) $this->settings->get( 'dup_order_window_hours' );
		return max( 1, min( 168, $h ? $h : 24 ) );
	}

	/**
	 * Duplicate-order guard. Blocks a second checkout from the same buyer
	 * inside the configured window.
	 *
	 * Answered from THIS store's own order table — no API call, nothing billed,
	 * and it keeps working while the platform is unreachable, which is the
	 * opposite of the other three layers and deliberately so: a burst of
	 * identical COD orders from one number is exactly the thing that arrives
	 * during an outage.
	 *
	 * Runs FIRST of all the gates, ahead of the billed BDCourier lookup: it is
	 * the cheapest question and answering it can save a paid one.
	 *
	 * Matched on e-mail AND phone, not e-mail-or-phone. `wc_get_orders` cannot
	 * OR two fields, and checking only the first one present would let the same
	 * number through by varying the e-mail — which is the whole trick this is
	 * meant to stop.
	 *
	 * Fails OPEN on any error, like everything else here.
	 *
	 * @param string $phone
	 * @param string $email
	 * @param int    $exclude_id Order being created (Store API), else 0.
	 * @return string block message, or '' to allow.
	 */
	private function duplicate_block_message( $phone, $email, $exclude_id = 0 ) {
		if ( ! $this->duplicate_gate_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}
		$phone = trim( (string) $phone );
		$email = trim( (string) $email );
		if ( '' === $phone && '' === $email ) {
			return ''; // nothing to match on
		}

		$hours = $this->duplicate_window_hours();
		try {
			$found = $this->recent_order_exists( $email, $phone, $hours, (int) $exclude_id );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Duplicate-order check failed (allowing checkout): ' . $e->getMessage() );
			return '';
		}
		if ( ! $found ) {
			return '';
		}

		$this->logger->debug(
			sprintf( 'Duplicate-order guard blocked %s within %d h.', $email ? $email : $phone, $hours )
		);
		$template = trim( (string) $this->settings->get( 'msg_duplicate' ) );
		if ( '' === $template ) {
			$template = __( 'You already have an order with us. A repeat order from the same contact is not accepted within {hours} hours — please contact us to add to or change your existing order.', 'aisooq-connector' );
		}
		return strtr( $template, array( '{hours}' => (string) $hours ) );
	}

	/**
	 * Is there an order from this buyer inside the window?
	 *
	 * Statuses that mean "this did not become a sale" are excluded. Blocking on
	 * a cancelled or failed order would trap the legitimate case the guard is
	 * most likely to meet — a shopper whose payment fell over, retrying — and
	 * they would have no way through.
	 *
	 * @return bool
	 */
	private function recent_order_exists( $email, $phone, $hours, $exclude_id ) {
		$countable = array_diff(
			array_keys( wc_get_order_statuses() ),
			array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-checkout-draft' )
		);
		$base = array(
			'limit'        => 1,
			'return'       => 'ids',
			'status'       => array_values( $countable ),
			'date_created' => '>' . ( time() - ( $hours * HOUR_IN_SECONDS ) ),
		);
		if ( $exclude_id > 0 ) {
			$base['exclude'] = array( $exclude_id );
		}

		if ( '' !== $email ) {
			$hit = wc_get_orders( array_merge( $base, array( 'billing_email' => $email ) ) );
			if ( is_array( $hit ) && $hit ) {
				return true;
			}
		}
		if ( '' === $phone ) {
			return false;
		}

		// HPOS understands `billing_phone`; the legacy post store does not, and
		// answering it there returns EVERY order — which would block every
		// checkout on the shop. Resolve ids from postmeta instead.
		if ( class_exists( 'AI_Sooq_Order_Courier' ) && ! AI_Sooq_Order_Courier::hpos_enabled() ) {
			$ids = AI_Sooq_Order_Courier::order_ids_by_phone( $phone, $exclude_id );
			if ( ! $ids ) {
				return false;
			}
			$hit = wc_get_orders( array_merge( $base, array( 'include' => $ids ) ) );
			return is_array( $hit ) && (bool) $hit;
		}

		$hit = wc_get_orders( array_merge( $base, array( 'billing_phone' => $phone ) ) );
		return is_array( $hit ) && (bool) $hit;
	}

	/**
	 * Classic checkout validation hook.
	 *
	 * @param array    $data   posted checkout fields
	 * @param WP_Error $errors
	 */
	public function screen_classic( $data, $errors ) {
		try {
		$name    = trim( ( isset( $data['billing_first_name'] ) ? $data['billing_first_name'] : '' ) . ' ' . ( isset( $data['billing_last_name'] ) ? $data['billing_last_name'] : '' ) );
		$phone   = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		$address = isset( $data['shipping_address_1'] ) && '' !== $data['shipping_address_1']
			? $data['shipping_address_1']
			: ( isset( $data['billing_address_1'] ) ? $data['billing_address_1'] : '' );

		// Duplicate-order guard FIRST: local, free, and answering it can save a
		// billed BDCourier lookup further down.
		$dup_msg = $this->duplicate_block_message(
			$phone,
			isset( $data['billing_email'] ) ? $data['billing_email'] : ''
		);
		if ( $dup_msg ) {
			$this->stash_block( 'duplicate', $dup_msg );
			$errors->add( 'aisooq_duplicate', $dup_msg );
			return;
		}

		// Layers run in ascending order and stop at the first failure, so the
		// shopper sees the lowest-numbered reason first:
		//   Layer 1 basic validation (name/address/mobile) → Layer 2 IP velocity
		//   [→ Layer 3 platform courier gate] — all via the platform fraud screen.
		if ( $this->settings->get( 'enable_fraud' ) ) {
			$verdict = $this->screen( $this->ctx( $name, $phone, $address ) );
			if ( $verdict && empty( $verdict['allowed'] ) ) {
				$action = $this->settings->get( 'fraud_action' );
				if ( 'block' === $action ) {
					$this->stash_block( 'fraud', $this->message( $verdict ) );
					$errors->add( 'aisooq_fraud', $this->message( $verdict ) );
					return;
				}
				// hold / flag: let the order be created, then act on it — but the
				// courier gate below is still a hard forbid.
				$this->stash( $verdict );
			}
		}

		// Layer 3 — courier delivery-ratio gate. LAST, so it only fires once
		// name/address/mobile + IP have passed. A hard forbid regardless of
		// fraud_action, and runs even when the full fraud screen is disabled.
		$courier_msg = $this->courier_block_message( $phone );
		if ( $courier_msg ) {
			$this->stash_block( 'courier', $courier_msg );
			$errors->add( 'aisooq_courier', $courier_msg );
			return;
		}
		} catch ( \Throwable $e ) {
			// A connector must NEVER break the merchant's checkout — fail open.
			$this->logger->error( 'Fraud/courier screen error (allowing checkout): ' . $e->getMessage() );
		}
	}

	/**
	 * Store API (block) checkout.
	 *
	 * @param WC_Order $order
	 * @param WP_REST_Request $request
	 */
	public function screen_blocks( $order, $request ) {
		if ( ! $order ) {
			return;
		}
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();

		// Duplicate-order guard FIRST: local, free, and it can save a billed
		// BDCourier lookup below. The order already exists on this path, so it
		// is excluded from its own duplicate check.
		$dup_msg = $this->duplicate_block_message(
			$order->get_billing_phone(),
			$order->get_billing_email(),
			$order->get_id()
		);
		if ( $dup_msg ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'aisooq_duplicate_order', $this->with_contact( $dup_msg ), 400 );
			}
			$order->update_status( 'failed', $dup_msg );
			return;
		}

		// Ascending order (stop at first failure): Layer 1 basic validation
		// (name/address/mobile) → Layer 2 IP velocity [→ Layer 3 platform courier]
		// via the fraud screen, THEN the plugin's Layer 3 courier gate last.
		if ( $this->settings->get( 'enable_fraud' ) ) {
			$verdict = $this->screen( $this->ctx( $name, $order->get_billing_phone(), $address ) );
			if ( $verdict && empty( $verdict['allowed'] ) ) {
				$action = $this->settings->get( 'fraud_action' );
				if ( 'block' === $action ) {
					if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
						throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
							'aisooq_fraud_blocked',
							$this->with_contact( $this->message( $verdict ) ),
							400
						);
					}
					// Fallback if the exception class is unavailable: fail the order.
					$order->update_status( 'failed', $this->message( $verdict ) );
					return;
				}
				// hold / flag: stash now, apply once the order is fully processed
				// (the courier gate below is still a hard forbid).
				$this->stash( $verdict );
			}
		}

		// Layer 3 — courier delivery-ratio gate. LAST: hard forbid before
		// payment, only after name/address/mobile + IP have passed.
		$courier_msg = $this->courier_block_message( $order->get_billing_phone() );
		if ( $courier_msg ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'aisooq_courier_blocked', $this->with_contact( $courier_msg ), 400 );
			}
			$order->update_status( 'failed', $courier_msg );
			return;
		}
	}

	/** hold/flag for classic checkout (order id). */
	public function apply_to_order( $order_id ) {
		try {
			$verdict = $this->pop();
			if ( ! $verdict ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$this->apply( $order, $verdict );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Fraud apply_to_order error (order kept): ' . $e->getMessage() );
		}
	}

	/** hold/flag for Store API checkout (order object). */
	public function apply_to_order_obj( $order ) {
		try {
			if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
				$this->apply( $order, null );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Fraud apply_to_order_obj error (order kept): ' . $e->getMessage() );
		}
	}

	/**
	 * Call the platform fraud engine. Returns the verdict array, or null on
	 * API error / when screening can't run (fail open).
	 *
	 * @param array $ctx
	 * @return array|null
	 */
	private function screen( $ctx ) {
		$res = $this->api->storefront_post( '/fraud/screen', $ctx, true );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Fraud screen unavailable (failing open): ' . $res->get_error_message() );
			return null;
		}
		return is_array( $res ) ? $res : null;
	}

	/**
	 * Apply hold/flag to an order given a verdict. When $verdict is null it is
	 * read from the session (Store API path).
	 *
	 * @param WC_Order   $order
	 * @param array|null $verdict
	 */
	private function apply( $order, $verdict ) {
		if ( null === $verdict ) {
			$verdict = $this->pop();
		}
		if ( ! $verdict || ! empty( $verdict['allowed'] ) ) {
			return;
		}
		$action = $this->settings->get( 'fraud_action' );
		$layer  = isset( $verdict['layer'] ) ? $verdict['layer'] : 'unknown';
		$reason = isset( $verdict['reason'] ) ? $verdict['reason'] : '';

		$order->update_meta_data( '_aisooq_fraud_flagged', '1' );
		$order->update_meta_data( '_aisooq_fraud_layer', $layer );
		$order->update_meta_data( '_aisooq_fraud_reason', $reason );

		$note = sprintf(
			/* translators: 1: fraud layer, 2: reason */
			__( 'AI Sooq fraud screen: flagged by layer "%1$s" (%2$s).', 'aisooq-connector' ),
			$layer,
			$reason
		);
		if ( 'hold' === $action && ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/** Build the /fraud/screen body, forwarding the SHOPPER's ip/ua. */
	private function ctx( $name, $phone, $address ) {
		$ctx = array(
			'ip'        => $this->client_ip(),
			'userAgent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '',
		);
		if ( '' !== trim( (string) $name ) ) {
			$ctx['name'] = substr( (string) $name, 0, 255 );
		}
		if ( '' !== trim( (string) $phone ) ) {
			$ctx['phone'] = substr( (string) $phone, 0, 32 );
		}
		if ( '' !== trim( (string) $address ) ) {
			$ctx['address'] = substr( (string) $address, 0, 500 );
		}
		return $ctx;
	}

	/** The operator-configured block message for this verdict's layer (falling
	 *  back to the platform message, then a built-in default). */
	private function message( $verdict ) {
		$layer = isset( $verdict['layer'] ) ? strtolower( (string) $verdict['layer'] ) : '';
		if ( in_array( $layer, array( '2', 'ip', 'velocity' ), true ) ) {
			$key = 'msg_fraud_velocity';
		} elseif ( in_array( $layer, array( '1', 'contact', 'phone', 'name', 'address', 'heuristic' ), true ) ) {
			$key = 'msg_fraud_contact';
		} else {
			$key = 'msg_fraud_generic';
		}
		$custom = trim( (string) $this->settings->get( $key ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return ! empty( $verdict['message'] )
			? $verdict['message']
			: __( 'This order could not be accepted. Please contact support.', 'aisooq-connector' );
	}

	/** Append the store's contact to a block message (Store API path, which has
	 *  no custom modal — the number rides in the message text). */
	private function with_contact( $message ) {
		$c = $this->support_contact();
		if ( '' === $c['phone'] ) {
			return $message;
		}
		/* translators: %s: store contact phone number */
		return $message . ' ' . sprintf( __( 'Contact us: %s', 'aisooq-connector' ), $c['phone'] );
	}

	/**
	 * Courier delivery-ratio gate. Asks the platform for the buyer phone's
	 * bdcourier delivery-success ratio and returns a block message when it is
	 * below the operator's threshold (once the buyer has enough parcel history).
	 *
	 * Fails OPEN at every uncertainty — gate off, no phone, API error, unknown
	 * buyer, or too little history all return '' (allow) so the gate never
	 * blocks a legitimate sale on missing data or an outage.
	 *
	 * @param string $phone
	 * @return string block message, or '' to allow.
	 */
	private function courier_block_message( $phone ) {
		$min = (int) $this->settings->get( 'courier_min_ratio' );
		if ( $min <= 0 ) {
			return ''; // gate disabled
		}
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return ''; // nothing to check yet
		}

		$res = $this->api->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			$this->logger->error(
				'Courier gate unavailable (failing open): ' .
				( is_wp_error( $res ) ? $res->get_error_message() : 'unexpected response' )
			);
			return '';
		}

		$ratio   = isset( $res['successRatio'] ) ? $res['successRatio'] : null;
		$parcels = isset( $res['totalParcel'] ) ? $res['totalParcel'] : null;
		if ( null === $ratio || null === $parcels ) {
			return ''; // unknown buyer / no bdcourier key — allow
		}

		$min_parcels = max( 1, (int) $this->settings->get( 'courier_min_parcels' ) );
		if ( (int) $parcels < $min_parcels ) {
			return ''; // too little history to judge — allow
		}
		if ( (float) $ratio >= (float) $min ) {
			return ''; // meets the threshold
		}

		$this->logger->debug(
			sprintf(
				'Courier gate blocked %s: %s%% success over %d parcels (min %d%%).',
				$phone,
				(string) $ratio,
				(int) $parcels,
				$min
			)
		);
		$template = trim( (string) $this->settings->get( 'msg_courier' ) );
		if ( '' === $template ) {
			$template = __( 'We are unable to accept this order for delivery right now (courier delivery-success rate {ratio}% over {parcels} past parcels). Please contact us to complete your purchase.', 'aisooq-connector' );
		}
		return strtr(
			$template,
			array(
				'{ratio}'   => (string) round( (float) $ratio ),
				'{parcels}' => (string) (int) $parcels,
			)
		);
	}

	private function stash( $verdict ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $verdict );
		}
	}

	private function pop() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$v = WC()->session->get( self::SESSION_KEY );
		if ( $v ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		return is_array( $v ) ? $v : null;
	}

	private function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = explode( ',', $raw );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}
}
