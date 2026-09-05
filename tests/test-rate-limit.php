<?php
/**
 * How the connector behaves when the platform says 429.
 *
 * Before this, a rate limit was indistinguishable from a broken order: it came
 * back as a generic `aisooq_http_429`, so the order push counted it against
 * MAX_ATTEMPTS and stacked its own exponential backoff on top. A busy hour
 * could therefore permanently abandon orders that were never wrong, and the
 * plugin would retry on a schedule unrelated to the window the platform had
 * actually opened.
 *
 * @package AISooq
 */

class Test_Rate_Limit extends WP_UnitTestCase {

	private $client;

	public function set_up() {
		parent::set_up();
		update_option( AISOOQ_OPTION, array(
			'active'          => 1,
			'api_base'        => 'https://api.example.test',
			'storefront_base' => 'https://shop.example.test',
			'sid'             => 'store1',
			'client_id'       => 'cid',
			'client_secret'   => 'csecret',
		) );
		$settings = new AI_Sooq_Settings();
		// Settings memoises `all()` for the request and the plugin booted
		// before this fixture wrote the option; same invalidation the other
		// fixtures do, or `send()` answers "Missing Store SID" forever.
		$cache = new ReflectionProperty( 'AI_Sooq_Settings', 'cache' );
		$cache->setAccessible( true );
		$cache->setValue( AI_Sooq_Plugin::instance()->settings(), null );

		$this->client = new AI_Sooq_Api_Client( $settings, new AI_Sooq_Logger( $settings ) );
	}

	/** Stub every outbound request with one status/body/header triple. */
	private function stub( $status, $body, $headers = array() ) {
		add_filter( 'pre_http_request', function () use ( $status, $body, $headers ) {
			return array(
				'headers'  => $headers,
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $status, 'message' => 'Too Many Requests' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}, 10, 3 );
	}

	public function test_a_429_is_its_own_error_code_carrying_the_servers_wait() {
		$this->stub( 429, array( 'message' => 'Too many requests' ), array( 'retry-after' => '42' ) );

		$res = $this->client->public_post( '/connect/orders', array( 'externalId' => '1' ) );

		$this->assertWPError( $res );
		// Not `aisooq_http_429`: the caller has to be able to tell a rate limit
		// apart from a real failure without string-matching a message.
		$this->assertSame( 'aisooq_rate_limited', $res->get_error_code() );
		$data = $res->get_error_data();
		$this->assertSame( 42, $data['retry_after'], 'The server`s own Retry-After must be used verbatim.' );
	}

	public function test_a_429_without_the_header_falls_back_to_the_throttler_window() {
		$this->stub( 429, array( 'message' => 'Too many requests' ) );

		$res  = $this->client->public_post( '/connect/orders', array( 'externalId' => '1' ) );
		$data = $res->get_error_data();

		// A proxy in front of the API may drop the header; 60s matches the
		// platform's own throttler window rather than retrying immediately.
		$this->assertSame( 60, $data['retry_after'] );
	}

	public function test_an_absurd_retry_after_is_capped() {
		$this->stub( 429, array( 'message' => 'nope' ), array( 'retry-after' => '999999' ) );

		$res  = $this->client->public_post( '/connect/orders', array( 'externalId' => '1' ) );
		$data = $res->get_error_data();

		// A mis-set header must not park an order for eleven days.
		$this->assertSame( 900, $data['retry_after'] );
	}

	public function test_the_servers_own_message_survives() {
		// The courier lookup is an operator pressing a button, and ITS 429 says
		// the upstream courier API is rate limiting — not this platform. That
		// names the real culprit, so it must reach the person reading it.
		$this->stub( 429, array( 'message' => 'bdcourier rate limited' ), array( 'retry-after' => '30' ) );

		$res = $this->client->public_post( '/connect/courier', array() );

		$this->assertStringContainsString( 'bdcourier rate limited', $res->get_error_message() );
	}
}
