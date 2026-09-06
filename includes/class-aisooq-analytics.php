<?php
/**
 * Analytics forwarding to POST /pixel/events (public, tenant-scoped — the
 * platform fans each event out to the store's Meta CAPI / TikTok / GA4).
 *
 *  - Purchase + CompleteRegistration are sent SERVER-SIDE (reliable, and the
 *    platform dedupes Purchase on order_id / eventId "order-<id>").
 *  - PageView / ViewContent / AddToCart / InitiateCheckout are sent from the
 *    browser through a same-site AJAX proxy (avoids cross-origin issues; lets
 *    the server derive the real client IP/UA).
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Analytics {

	const NONCE = 'aisooq_pixel';

	const ALLOWED = array(
		'PageView', 'ViewContent', 'Search', 'AddToCart', 'AddToWishlist',
		'InitiateCheckout', 'AddPaymentInfo', 'Purchase', 'CompleteRegistration',
		'Lead', 'Contact', 'Subscribe',
	);

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
		if ( ! $this->settings->get( 'enable_analytics' ) ) {
			return;
		}
		// A COD order fires NEITHER of the first two hooks: `payment_complete`
		// is for gateways that actually take money, and a COD order commonly
		// lives its whole life in `processing` and is never marked completed.
		// On a COD-heavy store that silently dropped most Purchase events.
		// AISOOQ_META_PIXEL_SENT still guarantees exactly one send per order.
		add_action( 'woocommerce_payment_complete', array( $this, 'track_purchase' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_purchase' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'track_purchase' ), 20, 1 );
		add_action( 'user_register', array( $this, 'track_registration' ), 20, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_aisooq_track', array( $this, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_aisooq_track', array( $this, 'ajax_track' ) );
	}

	/** Server-side Purchase — deduped by the platform on order_id. */
	public function track_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( AISOOQ_META_PIXEL_SENT ) ) {
			return;
		}

		$content_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product && $product->get_sku() ) {
				$content_ids[] = $product->get_sku();
			} elseif ( is_callable( array( $item, 'get_product_id' ) ) ) {
				$content_ids[] = (string) $item->get_product_id();
			}
		}

		$event = array(
			'eventName'      => 'Purchase',
			'eventId'        => 'order-' . $order->get_id(),
			'eventTime'      => gmdate( 'c' ),
			// `get_checkout_order_received_url()` carries `?key=wc_order_…`, the
			// token that grants guest access to the order. This URL is fanned
			// out to Meta / TikTok / GA4, so the key is stripped first.
			'eventSourceUrl' => remove_query_arg( array( 'key', 'order' ), $order->get_checkout_order_received_url() ),
			'custom'         => array(
				'currency'    => $order->get_currency(),
				'value'       => (float) $order->get_total(),
				'content_ids' => $content_ids,
				'order_id'    => (string) $order->get_id(),
			),
		);
		$user = array_filter(
			array(
				'email'     => $order->get_billing_email(),
				'phone'     => $order->get_billing_phone(),
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
			)
		);
		/**
		 * Customer identifiers attached to the server-side Purchase event.
		 *
		 * These leave the store for third-party ad platforms, so a site with a
		 * consent manager (or any GDPR obligation) needs a way to drop or hash
		 * them per order. Return an empty array to send the event without any
		 * customer identity.
		 *
		 * @param array    $user  email / phone / firstName / lastName.
		 * @param WC_Order $order The order being reported.
		 */
		$user = (array) apply_filters( 'aisooq_analytics_purchase_user', $user, $order );
		// Only attach `user` when non-empty — an empty PHP array serializes as
		// `[]`, not `{}`, which the server's nested-object validation rejects.
		if ( ! empty( $user ) ) {
			$event['user'] = $user;
		}

		$res = $this->api->public_post( '/pixel/events', array( 'events' => array( $event ) ) );
		if ( ! is_wp_error( $res ) ) {
			$order->update_meta_data( AISOOQ_META_PIXEL_SENT, current_time( 'mysql' ) );
			$order->save();
			$this->logger->debug( 'Purchase pixel sent for order ' . $order->get_id() );
		} else {
			$this->logger->error( 'Purchase pixel failed for order ' . $order->get_id() . ': ' . $res->get_error_message() );
		}
	}

	public function track_registration( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$this->api->public_post(
			'/pixel/events',
			array(
				'events' => array(
					array(
						'eventName' => 'CompleteRegistration',
						'eventId'   => 'reg-' . $user_id,
						'eventTime' => gmdate( 'c' ),
						'user'      => array( 'email' => $user->user_email, 'externalId' => (string) $user_id ),
					),
				),
			)
		);
	}

	public function enqueue_scripts() {
		if ( is_admin() ) {
			return;
		}
		wp_register_script(
			'aisooq-pixel',
			AISOOQ_URL . 'assets/js/aisooq-pixel.js',
			array(),
			AISOOQ_VERSION,
			true
		);

		$page = array(
			'type'       => $this->page_type(),
			'url'        => home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) ),
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'contentIds' => array(),
			'value'      => 0,
		);

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				$page['contentIds'] = array( $product->get_sku() ? $product->get_sku() : (string) $product->get_id() );
				$page['value']      = (float) $product->get_price();
			}
		}

		wp_localize_script(
			'aisooq-pixel',
			'aiSooqPixel',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'page'    => $page,
			)
		);
		wp_enqueue_script( 'aisooq-pixel' );
	}

	private function page_type() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		// WooCommerce reports the order-received and order-pay screens as
		// is_checkout(), so treating that as "checkout" fired InitiateCheckout
		// on the THANK-YOU page — inflating the merchant's funnel with a step
		// that happens after the purchase, and doing it again on every refresh.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return 'thankyou';
		}
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return 'other';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
			return 'listing';
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return 'search';
		}
		return 'other';
	}

	/** Same-site proxy: browser → admin-ajax → /pixel/events. */
	public function ajax_track() {
		// The underlying pixel endpoint is public + tenant-scoped and events
		// carry no privileged action, so a nonce failure (common when the page
		// HTML is served from a full-page cache with a stale nonce) must not
		// silently drop analytics. Verify best-effort, then rate-limit by IP.
		check_ajax_referer( self::NONCE, 'nonce', false );
		if ( $this->rate_limited() ) {
			wp_send_json_error( array( 'message' => 'rate limited' ), 429 );
		}

		$name = isset( $_POST['eventName'] ) ? sanitize_text_field( wp_unslash( $_POST['eventName'] ) ) : '';
		if ( ! in_array( $name, self::ALLOWED, true ) || 'Purchase' === $name ) {
			// Purchase is server-side only — never accept it from the browser.
			wp_send_json_error( array( 'message' => 'invalid event' ), 400 );
		}

		// Every field below is attacker-shaped: this endpoint is nopriv, so a
		// caller can post `url[]=x` and hand an ARRAY to a function typed for a
		// string. On PHP 8 that is a fatal TypeError, not a notice, so each
		// value is proven scalar before it is used.
		$event = array(
			'eventName'      => $name,
			'eventTime'      => gmdate( 'c' ),
			'eventSourceUrl' => isset( $_POST['url'] ) && is_scalar( $_POST['url'] )
				? esc_url_raw( wp_unslash( $_POST['url'] ) )
				: '',
		);
		if ( ! empty( $_POST['eventId'] ) && is_scalar( $_POST['eventId'] ) ) {
			$event['eventId'] = substr( sanitize_text_field( wp_unslash( $_POST['eventId'] ) ), 0, 64 );
		}
		if ( ! empty( $_POST['custom'] ) && is_scalar( $_POST['custom'] ) ) {
			$custom = json_decode( wp_unslash( $_POST['custom'] ), true );
			if ( is_array( $custom ) ) {
				$event['custom'] = $this->sanitize_custom( $custom );
			}
		}
		if ( is_user_logged_in() ) {
			$u    = wp_get_current_user();
			$user = array( 'email' => $u->user_email, 'externalId' => (string) $u->ID );
			/**
			 * Identity attached to a browser-side event for a logged-in visitor.
			 *
			 * This forwards the visitor's real address to third-party ad
			 * platforms on ordinary page views, so a store with a consent
			 * manager needs a way to suppress it. Return an empty array to
			 * send the event anonymously.
			 *
			 * @param array   $user Identity fields.
			 * @param WP_User $u    The logged-in visitor.
			 */
			$user = (array) apply_filters( 'aisooq_analytics_event_user', $user, $u );
			if ( ! empty( $user ) ) {
				$event['user'] = $user;
			}
		}

		// A browser is waiting on this response, so it uses the short timeout.
		$res = $this->api->interactive_post( '/pixel/events', array( 'events' => array( $event ) ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'queued' => isset( $res['queued'] ) ? (int) $res['queued'] : 0 ) );
	}

	/** Crude per-IP throttle for the public pixel proxy (120 events/min). */
	private function rate_limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:.]/', '', wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'aisooq_px_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 120 ) {
			return true;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return false;
	}

	private function sanitize_custom( array $custom ) {
		$out = array();
		foreach ( $custom as $k => $v ) {
			$key = sanitize_key( $k );
			if ( is_array( $v ) ) {
				// Only scalars survive: a nested array would fatal strval() on
				// PHP 8, and the pixel schema has no use for deeper structure.
				$out[ $key ] = array_values(
					array_map( 'sanitize_text_field', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) )
				);
			} elseif ( is_numeric( $v ) ) {
				$out[ $key ] = 0 + $v;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}
}
