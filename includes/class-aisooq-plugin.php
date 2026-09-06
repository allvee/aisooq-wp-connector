<?php
/**
 * Orchestrator: wires the components and registers their hooks. One singleton,
 * constructed on `plugins_loaded` once WooCommerce is present.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Plugin {

	/** @var AI_Sooq_Plugin|null */
	private static $instance = null;

	private $initialized = false;

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Logger */
	private $logger;
	/** @var AI_Sooq_Api_Client */
	private $api;
	/** @var AI_Sooq_Attribution */
	private $attribution;
	/** @var AI_Sooq_Order_Sync */
	private $order_sync;
	/** @var AI_Sooq_Abandoned_Sync */
	private $abandoned_sync;
	/** @var AI_Sooq_Order_Courier */
	private $order_courier;
	/** @var AI_Sooq_Abandoned_Admin */
	private $abandoned_admin;
	/** @var AI_Sooq_Block_Beacon */
	private $block_beacon;
	/** @var AI_Sooq_Analytics */
	private $analytics;
	/** @var AI_Sooq_Fraud */
	private $fraud;
	/** @var AI_Sooq_Customer_Sync */
	private $customer_sync;
	/** @var AI_Sooq_Catalog_Sync */
	private $catalog_sync;
	/** @var AI_Sooq_Product_Sync */
	private $product_sync;
	/** @var AI_Sooq_Seo_Sync */
	private $seo_sync;
	/** @var AI_Sooq_Status_Poller */
	private $poller;
	/** @var AI_Sooq_Privacy */
	private $privacy;
	/** @var AI_Sooq_Blocklist */
	private $blocklist;
	/** @var AI_Sooq_Blocklist_Admin */
	private $blocklist_admin;
	/** @var AI_Sooq_Failed_Admin */
	private $failed_admin;

	private $orders_column;

	private $products_column;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		$this->settings       = new AI_Sooq_Settings();
		$this->logger         = new AI_Sooq_Logger( $this->settings );
		$this->api            = new AI_Sooq_Api_Client( $this->settings, $this->logger );
		$this->attribution    = new AI_Sooq_Attribution( $this->settings );
		$this->order_sync     = new AI_Sooq_Order_Sync( $this->settings, $this->api, $this->logger );
		$this->abandoned_sync  = new AI_Sooq_Abandoned_Sync( $this->settings, $this->api, $this->logger );
		$this->order_courier   = new AI_Sooq_Order_Courier( $this->settings, $this->api, $this->logger );
		$this->abandoned_admin = new AI_Sooq_Abandoned_Admin( $this->settings, $this->abandoned_sync, $this->logger );
		$this->block_beacon    = new AI_Sooq_Block_Beacon( $this->settings, $this->abandoned_sync, $this->logger );
		$this->analytics      = new AI_Sooq_Analytics( $this->settings, $this->api, $this->logger );
		$this->fraud          = new AI_Sooq_Fraud( $this->settings, $this->api, $this->logger );
		$this->customer_sync  = new AI_Sooq_Customer_Sync( $this->settings, $this->api, $this->logger );
		$this->catalog_sync   = new AI_Sooq_Catalog_Sync( $this->settings, $this->api, $this->logger );
		$this->product_sync   = new AI_Sooq_Product_Sync( $this->settings, $this->api, $this->logger );
		$this->seo_sync       = new AI_Sooq_Seo_Sync( $this->settings, $this->api, $this->logger );
		$this->poller         = new AI_Sooq_Status_Poller( $this->settings, $this->api, $this->logger );
		$this->privacy        = new AI_Sooq_Privacy();
		$this->blocklist       = new AI_Sooq_Blocklist();
		$this->blocklist_admin = new AI_Sooq_Blocklist_Admin( $this->logger );
		$this->failed_admin    = new AI_Sooq_Failed_Admin( $this->settings, $this->order_sync, $this->logger );
		$this->orders_column  = new AI_Sooq_Orders_Column( $this->settings, $this->logger );
		$this->products_column = new AI_Sooq_Products_Column( $this->settings, $this->logger );

		// The settings screen (with Verify / Activate / Sync) is ALWAYS wired so
		// the operator can re-activate a paused connection. The sync/ingest
		// components only hook when the connection is Active — flipping the
		// master switch off fully pauses order/abandoned/analytics/fraud/poll.
		$this->settings->register();
		add_action( 'admin_notices', array( 'AI_Sooq_Install', 'admin_notices' ) );
		// Registered unconditionally: a data-subject request must be honourable
		// even while the connection is paused — the captured PII is still here.
		$this->privacy->register();
		// Always registered. The block list is LOCAL and still refuses
		// checkouts while the connection is paused, so it must stay visible and
		// manageable — a list you cannot see is worse than no list.
		$this->blocklist->register();
		$this->blocklist_admin->register();
		// Always registered. An order that gave up is still stranded while the
		// connection is paused, and that is exactly when an operator comes
		// looking — the screen itself refuses to retry until it is active.
		$this->failed_admin->register();
		// The abandoned-carts worklist + Resync screen is ALWAYS registered so the
		// operator can review captured carts even while the connection is paused
		// (Resync itself is gated on an active connection inside the handler).
		$this->abandoned_admin->register();
		$this->order_courier->register();

		if ( $this->settings->is_active() ) {
			// Attribution was constructed but never registered, so its
			// enqueue + checkout-snapshot hooks never bound and every order
			// synced with empty UTM/click-id attribution.
			$this->attribution->register();
			$this->order_sync->register();
			$this->abandoned_sync->register();
			$this->block_beacon->register();
			$this->analytics->register();
			$this->fraud->register();
			$this->customer_sync->register();
			$this->catalog_sync->register();
			$this->product_sync->register();
			$this->seo_sync->register();
			$this->poller->register();
			$this->orders_column->register();
			$this->products_column->register();
		}

		// Self-heal cron schedules after a plugin update (activation may not run).
		AI_Sooq_Install::schedule_crons();
	}

	/** @return AI_Sooq_Api_Client */
	public function api() {
		return $this->api;
	}

	/** @return AI_Sooq_Settings */
	public function settings() {
		return $this->settings;
	}

	/** @return AI_Sooq_Order_Courier */
	public function order_courier() {
		return $this->order_courier;
	}

	/** @return AI_Sooq_Order_Sync */
	public function order_sync() {
		return $this->order_sync;
	}

	/** @return AI_Sooq_Product_Sync */
	public function product_sync() {
		return $this->product_sync;
	}

	/** @return AI_Sooq_Customer_Sync */
	public function customer_sync() {
		return $this->customer_sync;
	}

	/** @return AI_Sooq_Catalog_Sync */
	public function catalog_sync() {
		return $this->catalog_sync;
	}
}
