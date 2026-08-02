<?php
/**
 * Visitor attribution: loads the front-end tracker (assets/js/aisooq-attr.js),
 * then snapshots its cookies onto the order when checkout completes, so a rich
 * first-touch / last-touch / browser-time blob rides along to the platform.
 *
 * The blob is stored on the order as `_aisooq_attribution` (JSON) and read by the
 * order mapper. WooCommerce's own `_wc_order_attribution_*` meta is used as a
 * fallback when the JS cookies are absent.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Attribution {

	const META = '_aisooq_attribution';

	/** @var AI_Sooq_Settings */
	private $settings;

	public function __construct( AI_Sooq_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'snapshot' ), 5, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'snapshot_obj' ), 5, 1 );
	}

	public function enqueue() {
		if ( is_admin() ) {
			return;
		}
		// Header (not footer) so first-touch is recorded on the very first load.
		wp_enqueue_script(
			'aisooq-attr',
			AISOOQ_URL . 'assets/js/aisooq-attr.js',
			array(),
			AISOOQ_VERSION,
			false
		);
	}

	public function snapshot( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$blob = $this->build_blob();
			if ( ! empty( $blob ) ) {
				$order->update_meta_data( self::META, wp_json_encode( $blob ) );
				$order->save();
			}
		} catch ( \Throwable $e ) {
			// Attribution is best-effort — never let it break the order.
			error_log( 'AI Sooq attribution snapshot error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	public function snapshot_obj( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			$this->snapshot( $order->get_id() );
		}
	}

	/** Assemble the rich attribution blob from the tracker cookies + server. */
	public function build_blob() {
		$blob = array(
			'first_touch'  => $this->cookie_json( 'aisooq_first' ),
			'last_touch'   => $this->cookie_json( 'aisooq_last' ),
			'browser_time' => $this->cookie_json( 'aisooq_bt' ),
			'visit_count'  => absint( self::cookie_raw( 'aisooq_vc' ) ),
			'device'       => sanitize_text_field( self::cookie_raw( 'aisooq_dev' ) ),
			'language'     => sanitize_text_field( self::cookie_raw( 'aisooq_lang' ) ),
			'ip'           => $this->client_ip(),
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '',
			'captured_at'  => current_time( 'c' ),
		);
		// Drop empties so the payload stays lean.
		return array_filter(
			$blob,
			function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			}
		);
	}

	/** Read this order's stored attribution blob (or empty array). */
	public static function get( WC_Order $order ) {
		$raw = $order->get_meta( self::META );
		if ( ! $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Read a tracker cookie, falling back to the names the tracker wrote under
	 * the plugin's previous versions.
	 *
	 * A visitor who landed before the update is still carrying `sp_*` (or
	 * `wafi_*`) cookies with their first touch in them. Without this fallback
	 * the rename would silently reset first-touch attribution and the visit
	 * counter for every in-flight session. The legacy names can be dropped once
	 * the longest cookie lifetime (DAYS in the tracker) has rolled over.
	 *
	 * @param string $name Current cookie name, e.g. `aisooq_first`.
	 * @return string Raw cookie value, or '' when none of the names are set.
	 */
	private static function cookie_raw( $name ) {
		$suffix  = substr( $name, strlen( 'aisooq_' ) );
		$lookups = array( $name, 'sp_' . $suffix, 'wafi_' . $suffix );
		foreach ( $lookups as $key ) {
			if ( ! empty( $_COOKIE[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return wp_unslash( $_COOKIE[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
			}
		}
		return '';
	}

	private function cookie_json( $name ) {
		$raw = self::cookie_raw( $name );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $this->clean( $decoded, 0 ) : array();
	}

	/** Recursively sanitize an untrusted decoded cookie (bounded depth/size). */
	private function clean( $val, $depth ) {
		if ( $depth > 4 ) {
			return null;
		}
		if ( is_array( $val ) ) {
			$out = array();
			$i   = 0;
			foreach ( $val as $k => $v ) {
				if ( $i++ >= 30 ) {
					break;
				}
				$out[ sanitize_key( $k ) ] = $this->clean( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) {
			return $val;
		}
		return sanitize_text_field( substr( (string) $val, 0, 512 ) );
	}

	private function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}
}
