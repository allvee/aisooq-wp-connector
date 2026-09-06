<?php
/**
 * Regression cover for defects found in the 2.9.0 architecture audit.
 *
 * Each test names the thing that was actually broken, so a future change that
 * reintroduces it fails here rather than in a merchant's store.
 *
 * @package AISooq
 */

class Test_Audit_Regressions extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}
	}

	private function settings( array $overrides = array() ) {
		update_option(
			AISOOQ_OPTION,
			array_merge(
				array(
					'active'        => 1,
					'api_base'      => 'https://api.example.test',
					'sid'           => 'store1',
					'client_id'     => 'cid',
					'client_secret' => 'csecret',
				),
				$overrides
			)
		);
		return new AI_Sooq_Settings();
	}

	// ── Product pull inverted the price model ───────────────────────────────

	/** Invoke the private AI_Sooq_Product_Sync::apply_prices(). */
	private function apply_prices( $product, array $variant ) {
		$settings = $this->settings();
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Product_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$m = new ReflectionMethod( 'AI_Sooq_Product_Sync', 'apply_prices' );
		$m->setAccessible( true );
		$m->invoke( $sync, $product, $variant );
	}

	/**
	 * The platform uses the Shopify shape: `price` is what the customer pays
	 * NOW and `compareAtPrice` is the struck-through original. The pull used to
	 * write `price` straight into regular_price, so a product on sale at 800
	 * with a regular price of 1000 came back as regular 800 — the 1000 was
	 * gone, and with the default `both` sync direction it ratcheted down again
	 * on every cron tick.
	 */
	public function test_a_discounted_product_keeps_its_regular_price_on_pull() {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '1000' );
		$product->set_sale_price( '800' );
		$product->save();

		$this->apply_prices( $product, array( 'price' => 800, 'compareAtPrice' => 1000 ) );
		$product->save();

		$fresh = wc_get_product( $product->get_id() );
		$this->assertSame( '1000', $fresh->get_regular_price() );
		$this->assertSame( '800', $fresh->get_sale_price() );
	}

	/** No compareAtPrice means the product is simply not on sale. */
	public function test_a_product_not_on_sale_has_its_sale_price_cleared() {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '1000' );
		$product->set_sale_price( '800' );
		$product->save();

		$this->apply_prices( $product, array( 'price' => 1200, 'compareAtPrice' => null ) );
		$product->save();

		$fresh = wc_get_product( $product->get_id() );
		$this->assertSame( '1200', $fresh->get_regular_price() );
		$this->assertSame( '', $fresh->get_sale_price() );
	}

	// ── Customer pull could rewrite a privileged account ────────────────────

	private function is_writable_customer( $user ) {
		$m = new ReflectionMethod( 'AI_Sooq_Customer_Sync', 'is_writable_customer' );
		$m->setAccessible( true );
		return $m->invoke( null, $user );
	}

	/**
	 * The pull matches a user by WordPress id or e-mail — both values the
	 * platform holds — and then called wp_update_user() with a new
	 * `user_email`. Whoever controls an administrator's address controls the
	 * password-reset link, so a bad row or a hostile response was a route to
	 * the whole site.
	 */
	public function test_an_administrator_is_not_writable_by_a_platform_pull() {
		$admin = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $this->is_writable_customer( $admin ) );
	}

	public function test_a_shop_manager_is_not_writable_by_a_platform_pull() {
		$user = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertFalse( $this->is_writable_customer( $user ) );
	}

	public function test_an_ordinary_customer_is_writable() {
		$user = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertTrue( $this->is_writable_customer( $user ) );
	}

	// ── Duplicate guard matched the punctuation, not the number ─────────────

	/**
	 * `_billing_phone` is stored exactly as typed, so an exact string match
	 * both missed real repeat customers and let anyone through who added a
	 * dash. Every written form of one number must resolve to the same set.
	 */
	public function test_phone_variants_span_the_written_forms_of_one_number() {
		$expected = AI_Sooq_Order_Courier::phone_variants( '01712345678' );

		foreach ( array( '01712-345678', '+8801712345678', '8801712345678', ' 01712 345678 ' ) as $written ) {
			$got = AI_Sooq_Order_Courier::phone_variants( $written );
			$this->assertNotEmpty(
				array_intersect( $expected, $got ),
				"'{$written}' should match the same number as '01712345678'."
			);
			$this->assertContains( '01712345678', $got, "'{$written}' should normalise to the national form." );
		}
	}

	public function test_phone_variants_are_empty_for_junk() {
		$this->assertSame( array(), AI_Sooq_Order_Courier::phone_variants( '' ) );
		$this->assertSame( array(), AI_Sooq_Order_Courier::phone_variants( 'not a phone' ) );
	}

	// ── Endpoint could be repointed by a shop manager ───────────────────────

	private function clean_endpoint( $value, $fallback ) {
		$m = new ReflectionMethod( 'AI_Sooq_Settings', 'clean_endpoint' );
		$m->setAccessible( true );
		return $m->invoke( null, $value, $fallback );
	}

	/**
	 * The OAuth client secret is posted to whatever this resolves to, so a
	 * plaintext scheme would put it on the wire and a URL with credentials or a
	 * query string is not an API origin.
	 */
	public function test_a_plain_http_endpoint_is_rejected() {
		$this->assertSame( 'https://api.example.test', $this->clean_endpoint( 'http://evil.example', 'https://api.example.test' ) );
	}

	public function test_an_endpoint_with_embedded_credentials_is_rejected() {
		$this->assertSame( 'https://api.example.test', $this->clean_endpoint( 'https://user:pass@evil.example', 'https://api.example.test' ) );
	}

	public function test_a_valid_https_endpoint_is_accepted_without_its_trailing_slash() {
		$this->assertSame( 'https://api.example.test', $this->clean_endpoint( 'https://api.example.test/', '' ) );
	}

	public function test_localhost_over_http_stays_usable_for_development() {
		$this->assertSame( 'http://localhost', $this->clean_endpoint( 'http://localhost', '' ) );
	}

	// ── Attribution was constructed but never registered ────────────────────

	/**
	 * AI_Sooq_Attribution was instantiated in the orchestrator and its
	 * register() was never called, so its enqueue and checkout-snapshot hooks
	 * never bound and every order synced with empty attribution.
	 */
	public function test_attribution_hooks_are_actually_bound() {
		$settings    = $this->settings();
		$attribution = new AI_Sooq_Attribution( $settings );
		$attribution->register();

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $attribution, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed', array( $attribution, 'snapshot' ) ) );
	}

	// ── The content hash that could never match ─────────────────────────────

	private function content_hash( $class, array $payload ) {
		$m = new ReflectionMethod( $class, 'content_hash' );
		$m->setAccessible( true );
		return $m->invoke( null, $payload );
	}

	/**
	 * Every push payload carries `sourceUpdatedAt => gmdate('c')`. Hashing the
	 * whole payload therefore produced a new digest on every call, so the
	 * unchanged-skip gate never once matched and every product, term and
	 * customer was re-uploaded on every trigger — burning the platform's rate
	 * limit on payloads identical to the ones already stored.
	 */
	public function test_the_content_hash_ignores_the_volatile_timestamp() {
		foreach ( array( 'AI_Sooq_Product_Sync', 'AI_Sooq_Catalog_Sync', 'AI_Sooq_Customer_Sync' ) as $class ) {
			$a = $this->content_hash( $class, array( 'externalId' => '7', 'title' => 'Same', 'sourceUpdatedAt' => '2026-01-01T00:00:00+00:00' ) );
			$b = $this->content_hash( $class, array( 'externalId' => '7', 'title' => 'Same', 'sourceUpdatedAt' => '2026-09-06T12:34:56+00:00' ) );
			$this->assertSame( $a, $b, "{$class}: identical content must hash identically." );
		}
	}

	public function test_the_content_hash_still_notices_real_changes() {
		$a = $this->content_hash( 'AI_Sooq_Product_Sync', array( 'title' => 'Before', 'sourceUpdatedAt' => 'x' ) );
		$b = $this->content_hash( 'AI_Sooq_Product_Sync', array( 'title' => 'After', 'sourceUpdatedAt' => 'x' ) );
		$this->assertNotSame( $a, $b );
	}

	// ── One paid courier lookup, not three ──────────────────────────────────

	/** Two spellings of one number must share a cache key, or each pays again. */
	public function test_phone_normalisation_collapses_the_written_forms() {
		foreach ( array( '01712-345678', '+8801712345678', '8801712345678', ' 01712 345678 ' ) as $written ) {
			$this->assertSame( '01712345678', AI_Sooq_Order_Courier::normalize_phone( $written ), $written );
		}
		$this->assertSame( '', AI_Sooq_Order_Courier::normalize_phone( 'nope' ) );
	}

	// ── A shopper who buys twice ────────────────────────────────────────────

	/**
	 * The cart row is reused per session, so a `converted` status persisted and
	 * every LATER cart from the same customer was silently never captured for
	 * recovery. Real cart activity has to reopen the row.
	 */
	public function test_a_converted_row_reopens_when_the_shopper_starts_a_new_cart() {
		global $wpdb;
		AI_Sooq_Install::create_table();
		$table = AI_Sooq_Abandoned_Sync::table_name();

		$settings = $this->settings( array( 'enable_abandoned' => 1 ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Abandoned_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$wpdb->insert(
			$table,
			array(
				'session_key' => 'sess-repeat',
				'email'       => 'again@example.test',
				'cart_json'   => '[]',
				'status'      => 'converted',
				'converted'   => 1,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		$m = new ReflectionMethod( 'AI_Sooq_Abandoned_Sync', 'row_status' );
		$m->setAccessible( true );
		$this->assertSame( 'converted', $m->invoke( $sync, 'sess-repeat' ) );

		// An operator disposition must NOT be reopened the same way.
		$wpdb->update( $table, array( 'status' => 'fake' ), array( 'session_key' => 'sess-repeat' ) );
		$this->assertSame( 'fake', $m->invoke( $sync, 'sess-repeat' ) );
	}

	// ── The beacon row a stranger could price ───────────────────────────────

	/**
	 * The beacon used to take its row key from the request body, so an
	 * anonymous caller could name any key — writing to, or overwriting, another
	 * shopper's row — and every browser without localStorage shared the single
	 * literal 'k-nostorage'. The key is now derived from the caller's own
	 * WooCommerce session, so an unauthenticated request with no session cannot
	 * write at all.
	 */
	public function test_a_beacon_row_is_keyed_by_the_session_not_the_request() {
		global $wpdb;
		AI_Sooq_Install::create_table();
		$table = AI_Sooq_Abandoned_Sync::table_name();

		$settings = $this->settings( array( 'enable_abandoned' => 1 ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Abandoned_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$m = new ReflectionMethod( 'AI_Sooq_Abandoned_Sync', 'beacon_key' );
		$m->setAccessible( true );
		$own = $m->invoke( null );
		$this->assertStringStartsWith( 'blk_', $own );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '500' );
		$product->save();

		// Two posts naming two different keys — one of them another shopper's.
		foreach ( array( 'attacker-chosen-key', 'blk_someoneElsesSession' ) as $claimed ) {
			$sync->capture_beacon(
				array(
					'key'   => $claimed,
					'email' => 'someone@example.test',
					'lines' => array( array( 'product_id' => $product->get_id(), 'qty' => 1, 'price' => 0.01 ) ),
				)
			);
			$this->assertNull(
				$wpdb->get_var( $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s", $claimed ) ),
				"A beacon must never write under a key the request named ({$claimed})."
			);
		}

		// Everything landed on the caller's own session-derived key instead.
		$this->assertSame(
			$own,
			$wpdb->get_var( $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s", $own ) )
		);
	}

	/**
	 * A beacon row's prices came from a browser, so converting one to a real
	 * order must not pin them — otherwise an anonymous caller seeds a real
	 * product at 0.01 and waits for an operator to press Convert.
	 */
	public function test_converting_a_beacon_row_uses_the_catalogue_price() {
		global $wpdb;
		AI_Sooq_Install::create_table();
		$table = AI_Sooq_Abandoned_Sync::table_name();

		$product = new WC_Product_Simple();
		$product->set_regular_price( '1000' );
		$product->set_name( 'Real Product' );
		$product->save();

		$settings = $this->settings( array( 'enable_abandoned' => 1 ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Abandoned_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$wpdb->insert(
			$table,
			array(
				'session_key' => 'blk_attackerSession',
				'email'       => 'cheap@example.test',
				'phone'       => '01712345678',
				'cart_json'   => wp_json_encode(
					array( array( 'product_id' => $product->get_id(), 'title' => 'Real Product', 'qty' => 1, 'price' => 0.01 ) )
				),
				'subtotal'    => 0.01,
				'currency'    => 'BDT',
				'status'      => 'active',
				'converted'   => 0,
				'synced'      => 0,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		$order = $sync->convert_to_wc_order( 'blk_attackerSession' );
		$this->assertNotWPError( $order );

		$order = wc_get_order( is_object( $order ) ? $order->get_id() : (int) $order );
		$this->assertGreaterThanOrEqual(
			1000,
			(float) $order->get_total(),
			'A browser-supplied price must never become the order total.'
		);
	}

	// ── The duplicate guard and the abandoned payment attempt ───────────────

	private function is_real_order( $orders ) {
		$settings = $this->settings();
		$logger   = new AI_Sooq_Logger( $settings );
		$fraud    = new AI_Sooq_Fraud( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$m = new ReflectionMethod( 'AI_Sooq_Fraud', 'any_is_a_real_order' );
		$m->setAccessible( true );
		return $m->invoke( $fraud, $orders );
	}

	private function make_pending_order( $gateway ) {
		$order = new WC_Order();
		$order->set_payment_method( $gateway );
		$order->set_status( 'pending' );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * A shopper sent to a redirect gateway who never came back leaves a
	 * `pending` husk. Counting it as a prior order locked them out of the retry
	 * that would have completed the sale.
	 */
	public function test_an_abandoned_online_payment_is_not_a_duplicate() {
		$this->assertFalse( $this->is_real_order( array( $this->make_pending_order( 'bkash' ) ) ) );
	}

	/** On a COD store, pending IS the normal state of a real order. */
	public function test_a_pending_cod_order_is_a_duplicate() {
		$this->assertTrue( $this->is_real_order( array( $this->make_pending_order( 'cod' ) ) ) );
	}

	/** Bank transfer has nothing to come back from either. */
	public function test_a_pending_bank_transfer_order_is_a_duplicate() {
		$this->assertTrue( $this->is_real_order( array( $this->make_pending_order( 'bacs' ) ) ) );
	}

	/** A real order sitting behind an abandoned attempt must still be found. */
	public function test_a_real_order_behind_an_abandoned_attempt_still_counts() {
		$orders = array( $this->make_pending_order( 'bkash' ), $this->make_pending_order( 'cod' ) );
		$this->assertTrue( $this->is_real_order( $orders ) );
	}

	// ── The abandoned-cart row a block checkout never closed ────────────────

	/**
	 * capture_beacon() keys rows `blk_<browser key>`, which is not the
	 * WooCommerce session id that mark_converted() matched — so a block
	 * checkout left its row active forever and the platform went on chasing a
	 * shopper who had already paid.
	 */
	public function test_a_beacon_row_is_closed_when_the_shopper_orders() {
		global $wpdb;
		AI_Sooq_Install::create_table();
		$table = AI_Sooq_Abandoned_Sync::table_name();

		$settings = $this->settings( array( 'enable_abandoned' => 1 ) );
		$logger   = new AI_Sooq_Logger( $settings );
		$sync     = new AI_Sooq_Abandoned_Sync( $settings, new AI_Sooq_Api_Client( $settings, $logger ), $logger );

		$wpdb->insert(
			$table,
			array(
				'session_key' => 'blk_someBrowserKey',
				'email'       => 'shopper@example.test',
				'phone'       => '01712345678',
				'cart_json'   => '[]',
				'status'      => 'active',
				'converted'   => 0,
				'synced'      => 0,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		$order = new WC_Order();
		$order->set_billing_email( 'shopper@example.test' );
		$order->set_billing_phone( '01712345678' );
		$order->save();

		$sync->mark_converted( $order->get_id() );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, converted, wc_order_id FROM {$table} WHERE session_key = %s", 'blk_someBrowserKey' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'converted', $row->status );
		$this->assertSame( 1, (int) $row->converted );
		$this->assertSame( $order->get_id(), (int) $row->wc_order_id );
	}
}
