<?php
/**
 * HTTP client for the AI Sooq platform. Owns the OAuth client_credentials token
 * lifecycle (mint, cache, refresh, single retry on 401) and attaches the
 * required X-Store-Sid tenant header to every call.
 *
 * Auth model (see platform runbook woocommerce-connector.md):
 *   - Mint a `wat_` bearer via POST {base}/api/v1/oauth/token (1h TTL, no
 *     refresh token). Cached in a transient until ~60s before expiry.
 *   - Every request carries `Authorization: Bearer wat_…` + `X-Store-Sid`.
 *   - The public pixel endpoint needs only `X-Store-Sid` (public_post()).
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Api_Client {

	/** Background work — Action Scheduler jobs, crons, admin buttons. */
	const TIMEOUT = 20;

	/**
	 * Calls made while a shopper waits.
	 *
	 * The fraud screen and the courier gate run inside checkout validation, one
	 * after another, and the pixel proxy runs on ordinary page views. At the
	 * background timeout a single stalled platform could hold a checkout for
	 * more than a minute before failing open — by which point the shopper has
	 * long since given up, which is the outcome fail-open exists to prevent.
	 */
	const TIMEOUT_INTERACTIVE = 5;

	/** Used when a 429 carries no `Retry-After` — matches the platform's 60s window. */
	const RATE_LIMIT_FALLBACK = 60;

	/** Ceiling on a server-supplied wait, so a bad header cannot park an order for a day. */
	const RATE_LIMIT_MAX_WAIT = 900;
	/** @var AI_Sooq_Settings */
	private $settings;

	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/** Admin API host (OAuth + /connect/*). */
	private function admin_base() {
		return $this->settings->get_api_base() . '/api/v1';
	}

	/**
	 * Storefront API host (/pixel/*, /fraud/*). These live on the client-api
	 * service — a different host than the admin API. Falls back to the admin
	 * base for single-host deployments where the operator left it blank.
	 */
	private function storefront_base() {
		$sf = $this->settings->get_storefront_base();
		return ( '' !== $sf ? $sf : $this->settings->get_api_base() ) . '/api/v1';
	}

	/**
	 * Return a valid access token, minting one if the cache is empty/expired.
	 *
	 * @param bool $force Bypass the cache (used after a 401).
	 * @return string|WP_Error
	 */
	public function get_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( AISOOQ_TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error( 'aisooq_not_configured', __( 'Connector is not configured (API base, SID, client id/secret required).', 'aisooq-connector' ) );
		}

		$response = wp_remote_post(
			$this->admin_base() . '/oauth/token',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'X-Store-Sid'  => $this->settings->get_sid(),
				),
				'body'    => wp_json_encode(
					// No `scope`: client_credentials with scope omitted grants
					// this app's *full registered* scope set. Narrowing here (the
					// old hardcoded `orders.read orders.write`) silently stripped
					// every other permission from the token, so product/catalog/
					// customer sync 403'd with "You don't have permission to do
					// that" even though the app was registered for them. The
					// registered set — chosen by the operator at registration — is
					// the least-privilege boundary; the token honours it verbatim.
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->settings->get( 'client_id' ),
						'client_secret' => $this->settings->get( 'client_secret' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Token request failed: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// A throttled token mint is a rate limit like any other. Reporting it as
		// `aisooq_token_failed` sent it down the generic failure path, where it
		// spent one of the order's five attempts — so a busy window could still
		// permanently abandon orders despite the 429 handling in send().
		if ( 429 === $code ) {
			$this->logger->debug( 'Token mint rate limited.' );
			return $this->rate_limit_error( $response, $body );
		}

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$msg = isset( $body['message'] ) ? ( is_array( $body['message'] ) ? implode( '; ', $body['message'] ) : $body['message'] ) : 'HTTP ' . $code;
			$this->logger->error( 'Token mint rejected: ' . $msg );
			return new WP_Error( 'aisooq_token_failed', $msg );
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		// Refresh a minute early so an in-flight request never races expiry.
		set_transient( AISOOQ_TOKEN_TRANSIENT, $token, max( 60, $expires - 60 ) );
		$this->logger->debug( 'Minted access token (expires_in=' . $expires . ')' );
		return $token;
	}

	/**
	 * Core sender. `$auth` attaches the bearer token (and retries once after
	 * re-minting on a 401); unauthenticated calls send only X-Store-Sid.
	 *
	 * @param string     $base   host base (admin or storefront) incl /api/v1
	 * @param string     $method GET|POST|PATCH|DELETE
	 * @param string     $path
	 * @param array|null $body
	 * @param bool       $auth
	 * @param bool       $retry  internal — false on the retry pass
	 * @return array|WP_Error
	 */
	private function send( $base, $method, $path, $body, $auth, $retry = true, $timeout = self::TIMEOUT ) {
		if ( '' === $this->settings->get_sid() ) {
			return new WP_Error( 'aisooq_not_configured', __( 'Missing Store SID.', 'aisooq-connector' ) );
		}
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-Store-Sid'  => $this->settings->get_sid(),
		);
		if ( $auth ) {
			$token = $this->get_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => (int) $timeout,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error( $method . ' ' . $path . ' transport error: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code && $auth && $retry ) {
			// Token expired or app re-enabled — mint fresh and retry once.
			delete_transient( AISOOQ_TOKEN_TRANSIENT );
			$this->get_token( true );
			return $this->send( $base, $method, $path, $body, $auth, false, $timeout );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// Rate limited. Carry the server's OWN wait so the caller schedules
		// against the window the platform actually opened rather than guessing.
		//
		// This is not a failure of the request — nothing about it is wrong, and
		// the same call will succeed once the window opens — so it gets its own
		// error code, and the background order push neither counts it against
		// its attempt budget nor stacks exponential backoff on top.
		//
		// The MESSAGE stays the server's whenever it sent one. A 429 here is
		// not always this platform throttling us: the courier lookup is an
		// operator pressing a button, and its 429 body says `bdcourier rate
		// limited` — the UPSTREAM courier API. Replacing that with a sentence
		// about retrying would name the wrong culprit on the one path where a
		// person is reading the message, and there is no retry to describe.
		if ( 429 === $code ) {
			$this->logger->debug( $method . ' ' . $path . ' rate limited.' );
			return $this->rate_limit_error( $response, $decoded );
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $decoded ) && isset( $decoded['message'] ) )
				? ( is_array( $decoded['message'] ) ? implode( '; ', $decoded['message'] ) : $decoded['message'] )
				: 'HTTP ' . $code;
			$this->logger->error( $method . ' ' . $path . ' -> ' . $msg );
			return new WP_Error( 'aisooq_http_' . $code, $msg, array( 'status' => $code, 'body' => $decoded ) );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Turn a 429 response into the shared `aisooq_rate_limited` error.
	 *
	 * Kept in one place so the token mint and the ordinary request path cannot
	 * drift — they did, and a throttled token mint quietly burned an order's
	 * retry budget while a throttled request did not.
	 *
	 * @param array      $response Raw wp_remote_* response.
	 * @param array|null $decoded  Already-decoded body, if the caller has it.
	 * @return WP_Error
	 */
	private function rate_limit_error( $response, $decoded ) {
		// `Retry-After` may legally be an HTTP-date rather than seconds, and a
		// proxy may return the header more than once (an array). Both cast to
		// something unusable, so anything non-positive falls back to the
		// throttler's own 60s window.
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		$after = (int) $header;
		if ( $after <= 0 && is_string( $header ) && '' !== $header ) {
			// HTTP-date form: convert to a delta, ignoring anything in the past.
			$ts = strtotime( $header );
			if ( $ts ) {
				$after = max( 0, $ts - time() );
			}
		}
		if ( $after <= 0 ) {
			$after = self::RATE_LIMIT_FALLBACK;
		}
		// Cap it: a mis-set header must not park an order for a day.
		$after = min( $after, self::RATE_LIMIT_MAX_WAIT );

		// Keep the SERVER's message whenever it sent one. A 429 is not always
		// this platform throttling us — the courier lookup's 429 body says
		// `bdcourier rate limited`, the upstream provider — and that is the one
		// path where a person is reading the message.
		$server_msg = ( is_array( $decoded ) && isset( $decoded['message'] ) )
			? ( is_array( $decoded['message'] ) ? implode( '; ', $decoded['message'] ) : $decoded['message'] )
			: '';
		$msg = ( '' !== $server_msg )
			? $server_msg
			: __( 'Rate limited by the platform; the sync will retry automatically.', 'aisooq-connector' );

		return new WP_Error(
			'aisooq_rate_limited',
			$msg,
			array( 'status' => 429, 'retry_after' => $after, 'body' => $decoded )
		);
	}

	/** Authenticated request against the ADMIN host (/connect/*). */
	public function request( $method, $path, $body = null, $timeout = self::TIMEOUT ) {
		return $this->send( $this->admin_base(), $method, $path, $body, true, true, $timeout );
	}

	public function get( $path, $timeout = self::TIMEOUT ) {
		return $this->request( 'GET', $path, null, $timeout );
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * POST against the STOREFRONT host (/pixel/*, /fraud/*). Pixel ingest is
	 * public (`$auth = false`, X-Store-Sid only); fraud screening needs the
	 * OAuth token (`$auth = true`).
	 *
	 * @return array|WP_Error
	 */
	public function storefront_post( $path, $body, $auth = false, $timeout = self::TIMEOUT ) {
		return $this->send( $this->storefront_base(), 'POST', $path, $body, $auth, true, $timeout );
	}

	/**
	 * Storefront POST on the shopper's clock.
	 *
	 * Same call, short timeout — for anything running inside checkout
	 * validation or on a front-end page view.
	 *
	 * @return array|WP_Error
	 */
	public function interactive_post( $path, $body, $auth = false ) {
		return $this->storefront_post( $path, $body, $auth, self::TIMEOUT_INTERACTIVE );
	}

	/** Back-compat alias: public (unauthenticated) storefront POST. */
	public function public_post( $path, $body ) {
		return $this->storefront_post( $path, $body, false );
	}
}
