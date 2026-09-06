<?php
/**
 * Product + variation push (Phase 2b): WooCommerce → platform.
 *
 * Builds a full product payload — simple or variable (options + variations),
 * images, brand + category external refs, SEO — and upserts it to
 * /connect/products, async and hash-gated. Stock is intentionally not sent
 * (WooCommerce stays the stock source).
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Product_Sync {

	const HASH_META     = '_aisooq_prod_hash';

	/** Products requested per pull tick. */
	const PULL_PAGE = 50;

	/** Consecutive rate-limit deferrals for one product. */
	const DEFER_META = '_aisooq_prod_rate_deferrals';

	/** Ceiling on those deferrals, so a throttled platform cannot loop forever. */
	const MAX_RATE_DEFERRALS = 50;
	const PLATFORM_META = '_aisooq_platform_id';

	private static $brand_tax = array( 'product_brand', 'pwb-brand', 'pa_brand', 'yith_product_brand' );

	/** True while applying a pulled change, so the product hooks don't echo it back. */
	private static $suppress = false;

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

	/**
	 * Hash of the payload's CONTENT, ignoring fields that change every call.
	 *
	 * `sourceUpdatedAt` is stamped with the current time, so hashing the whole
	 * payload produced a different digest on every run and the unchanged-skip
	 * gate below could never match. The effect was that every product was
	 * re-uploaded on every trigger — burning the platform's rate limit on
	 * payloads identical to the ones already stored.
	 *
	 * @param array $payload
	 * @return string
	 */
	private static function content_hash( array $payload ) {
		unset( $payload['sourceUpdatedAt'] );
		return md5( (string) wp_json_encode( $payload ) );
	}

	public function register() {
		if ( ! $this->settings->get( 'enable_product_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'woocommerce_new_product', array( $this, 'on_product' ), 20, 1 );
			add_action( 'woocommerce_update_product', array( $this, 'on_product' ), 20, 1 );
			add_action( AISOOQ_PRODUCT_SYNC_ACTION, array( $this, 'handle_product' ), 10, 1 );
			// Deletion is a change too. Nothing propagated it, so a product
			// removed here stayed live and buyable on the platform forever.
			// `before_delete_post` — not `deleted_post` — because the platform
			// id lives in post meta, which is gone by the time the row is.
			add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );
			add_action( AISOOQ_PRODUCT_DELETE_ACTION, array( $this, 'handle_delete' ), 10, 1 );
		}
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( AISOOQ_CATALOG_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	public function on_product( $product_id ) {
		if ( self::$suppress ) {
			return;
		}
		$product_id = (int) $product_id;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( AISOOQ_PRODUCT_SYNC_ACTION, array( $product_id ), AISOOQ_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( AISOOQ_PRODUCT_SYNC_ACTION, array( $product_id ), AISOOQ_AS_GROUP );
		} else {
			$this->push_product( $product_id );
		}
	}

	public function handle_product( $product_id ) {
		$this->push_product( (int) $product_id );
	}

	/**
	 * Manual backfill: enqueue the most recent products for a push (the "Sync
	 * products" button). Returns how many were queued. No-op unless product sync
	 * is enabled with a push direction.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill( $limit = 200 ) {
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( ! $this->settings->get( 'enable_product_sync' ) || ( 'push' !== $dir && 'both' !== $dir ) ) {
			return 0;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$limit = max( 1, (int) $limit );

		// Take the products that have NEVER reached the platform first.
		//
		// Always querying the newest N meant a store with more products than
		// one batch could never finish: every press of "Sync products" re-walked
		// the same newest 200 and everything older stayed invisible forever.
		// Selecting on the absence of the platform-id meta makes repeated
		// presses converge — each one picks up the next unsynced batch.
		$ids = wc_get_products( array(
			'limit'      => $limit,
			'status'     => array( 'publish', 'private' ),
			'orderby'    => 'date',
			'order'      => 'DESC',
			'return'     => 'ids',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => self::PLATFORM_META,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		// Everything has synced at least once — fall back to the newest batch so
		// the button still means "push these again". The unchanged-hash gate
		// makes that cheap for anything that really is unchanged.
		if ( empty( $ids ) ) {
			$ids = wc_get_products( array(
				'limit'   => $limit,
				'status'  => array( 'publish', 'private' ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			) );
		}
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$this->on_product( (int) $id );
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' products.' );
		return $n;
	}

	/**
	 * A product is about to be deleted for good — capture its platform id now
	 * and queue the removal, because the meta will not exist a moment later.
	 *
	 * @param int $post_id
	 */
	public function on_delete( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$platform_id = (int) get_post_meta( $post_id, self::PLATFORM_META, true );
		if ( ! $platform_id ) {
			return; // never reached the platform, nothing to remove
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( AISOOQ_PRODUCT_DELETE_ACTION, array( $platform_id ), AISOOQ_AS_GROUP );
		} else {
			$this->handle_delete( $platform_id );
		}
	}

	/**
	 * @param int $platform_id
	 */
	public function handle_delete( $platform_id ) {
		$platform_id = (int) $platform_id;
		if ( ! $platform_id ) {
			return;
		}
		$res = $this->api->request( 'DELETE', '/connect/products/' . $platform_id );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product ' . $platform_id . ' delete failed on the platform: ' . $res->get_error_message() );
			return;
		}
		$this->logger->debug( 'Product ' . $platform_id . ' removed from the platform.' );
	}

	public function push_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$payload = $this->build_payload( $product );
		if ( null === $payload ) {
			return; // nothing to sync
		}

		$hash = self::content_hash( $payload );
		if ( get_post_meta( $product_id, self::HASH_META, true ) === $hash ) {
			return;
		}
		$res = $this->api->post( '/connect/products', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product ' . $product_id . ' push failed: ' . $res->get_error_message() );
			// A rate limit is not a bad payload — the same push succeeds once the
			// window opens. Dropping it here lost the edit permanently, because
			// the hash is only stamped on success and nothing re-triggers a
			// product whose content has not changed again. Re-queue it, bounded,
			// so a busy hour does not silently strand catalogue updates.
			$data = $res->get_error_data();
			if ( 'aisooq_rate_limited' === $res->get_error_code()
				&& is_array( $data ) && ! empty( $data['retry_after'] )
				&& function_exists( 'as_schedule_single_action' ) ) {
				$deferrals = (int) get_post_meta( $product_id, self::DEFER_META, true ) + 1;
				if ( $deferrals <= self::MAX_RATE_DEFERRALS ) {
					update_post_meta( $product_id, self::DEFER_META, $deferrals );
					$wait = (int) $data['retry_after'];
					$wait += wp_rand( 0, max( 1, (int) round( $wait * 0.2 ) ) );
					as_schedule_single_action( time() + $wait, AISOOQ_PRODUCT_SYNC_ACTION, array( $product_id ), AISOOQ_AS_GROUP );
					$this->logger->debug( 'Product ' . $product_id . ' rate limited; re-queued in ' . $wait . 's.' );
				} else {
					$this->logger->error( 'Product ' . $product_id . ' rate limited past ' . self::MAX_RATE_DEFERRALS . ' deferrals; giving up.' );
				}
			}
			return;
		}
		delete_post_meta( $product_id, self::DEFER_META );
		update_post_meta( $product_id, self::HASH_META, $hash );
		if ( ! empty( $res['id'] ) ) {
			update_post_meta( $product_id, self::PLATFORM_META, (int) $res['id'] );
		}
		$this->logger->debug( 'Product ' . $product_id . ' synced (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) . ').' );
	}

	/**
	 * Force-sync ONE product now (the per-product Sync button). Ignores the
	 * unchanged-hash skip — the operator explicitly asked for this product —
	 * stamps the platform id, and returns a result the caller can render.
	 *
	 * @param int $product_id
	 * @return array{ok:bool,id?:string,message:string}
	 */
	public function sync_one( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'ok' => false, 'message' => __( 'WooCommerce not available.', 'aisooq-connector' ) );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'ok' => false, 'message' => __( 'Product not found.', 'aisooq-connector' ) );
		}
		$payload = $this->build_payload( $product );
		if ( null === $payload ) {
			return array( 'ok' => false, 'message' => __( 'Nothing to sync on this product.', 'aisooq-connector' ) );
		}
		$res = $this->api->post( '/connect/products', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product ' . $product_id . ' manual sync failed: ' . $res->get_error_message() );
			return array( 'ok' => false, 'message' => $res->get_error_message() );
		}
		update_post_meta( $product_id, self::HASH_META, self::content_hash( $payload ) );
		if ( ! empty( $res['id'] ) ) {
			update_post_meta( $product_id, self::PLATFORM_META, (int) $res['id'] );
		}
		return array(
			'ok'      => true,
			'id'      => isset( $res['id'] ) ? (string) $res['id'] : '',
			'message' => __( 'Synced.', 'aisooq-connector' ),
		);
	}

	/**
	 * Build the /connect/products payload for a product, or null when there's
	 * nothing to sync (no variants). Shared by push_product() + sync_one().
	 *
	 * @param WC_Product $product
	 * @return array|null
	 */
	private function build_payload( $product ) {
		$product_id = $product->get_id();
		list( $options, $variants ) = $product->is_type( 'variable' )
			? $this->variable( $product )
			: array( array(), array( $this->simple_variant( $product ) ) );

		if ( empty( $variants ) ) {
			return null;
		}

		$payload = array(
			'externalSource'  => 'woocommerce',
			'externalId'      => (string) $product_id,
			'title'           => $product->get_name(),
			'handle'          => $product->get_slug(),
			'descriptionHtml' => $product->get_description() ? $product->get_description() : null,
			'status'          => $product->get_status(),
			'productType'     => $product->get_type(),
			'tags'            => wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) ),
			'variants'        => $variants,
			'sourceUpdatedAt' => gmdate( 'c' ),
		);
		if ( ! empty( $options ) ) {
			$payload['options'] = $options;
		}
		$payload = array_merge( $payload, AI_Sooq_Seo::get_post_seo( $product_id ) );

		$images = $this->images( $product );
		if ( ! empty( $images ) ) {
			$payload['images'] = $images;
		}
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$payload['categoryExternalIds'] = array_map( 'strval', $cats );
		}
		$brand = $this->brand_term( $product_id );
		if ( $brand ) {
			$payload['brandExternalId'] = (string) $brand;
		}

		return $this->prune( $payload );
	}

	/**
	 * Return a product/variant SKU, generating + persisting a unique one on the
	 * WooCommerce product when it's missing — so the SKU exists in WooCommerce
	 * AND flows to the platform, where products are mapped by SKU. Gated by the
	 * `auto_sku` setting. Written via post meta (not $product->save()) so it
	 * doesn't re-fire the product-sync save hook.
	 */
	private function ensure_sku( $product ) {
		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku || ! $this->settings->get( 'auto_sku' ) ) {
			return $sku;
		}
		$base = 'SP-' . $product->get_id();
		$sku  = $base;
		$i    = 1;
		while ( function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $sku ) ) {
			if ( $i > 50 ) {
				$sku = $base . '-' . wp_generate_password( 6, false, false );
				break;
			}
			$sku = $base . '-' . $i;
			$i++;
		}
		// The postmeta write is deliberate — going through $product->save()
		// here would re-fire the product-sync save hook from inside a push. But
		// WooCommerce keeps a denormalised copy of the SKU in
		// wc_product_meta_lookup, and writing the meta directly leaves that copy
		// stale: wc_get_product_id_by_sku() (which this very loop uses to test
		// uniqueness) reads the lookup table, so the generated SKU stayed
		// invisible to it and admin SKU search never found the product.
		update_post_meta( $product->get_id(), '_sku', $sku );
		if ( class_exists( 'WC_Data_Store' ) ) {
			try {
				WC_Data_Store::load( 'product' )->update_lookup_table( $product->get_id(), 'wc_product_meta_lookup' );
			} catch ( \Exception $e ) {
				$this->logger->error( 'SKU lookup-table refresh failed for product ' . $product->get_id() . ': ' . $e->getMessage() );
			}
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product->get_id() );
		}
		$this->logger->debug( 'Generated SKU ' . $sku . ' for product ' . $product->get_id() );
		return $sku;
	}

	private function simple_variant( $product ) {
		return array(
			'optionValues'   => array(),
			'sku'            => $this->ensure_sku( $product ),
			'price'          => (float) $product->get_price(),
			'compareAtPrice' => $this->compare_at( $product ),
			'weight'         => $product->get_weight() ? (float) $product->get_weight() : null,
			'weightUnit'     => $this->weight_unit(),
		);
	}

	/**
	 * @param WC_Product_Variable $product
	 * @return array{0:array,1:array} [options, variants]
	 */
	private function variable( $product ) {
		$options   = array();
		$attr_keys = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$slug_to_name = array();
			if ( $attr->is_taxonomy() ) {
				$terms  = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'all' ) );
				$values = array();
				foreach ( $terms as $t ) {
					$values[]              = $t->name;
					$slug_to_name[ $t->slug ] = $t->name;
				}
			} else {
				$values = $attr->get_options();
			}
			$options[]   = array( 'name' => wc_attribute_label( $attr->get_name() ), 'values' => array_values( $values ) );
			$attr_keys[] = array( 'key' => 'attribute_' . sanitize_title( $attr->get_name() ), 'map' => $slug_to_name );
		}

		$variants = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$vattrs = $v->get_attributes();
			$combo  = array();
			foreach ( $attr_keys as $ak ) {
				$val = isset( $vattrs[ $ak['key'] ] ) ? $vattrs[ $ak['key'] ] : '';
				if ( '' !== $val && isset( $ak['map'][ $val ] ) ) {
					$val = $ak['map'][ $val ]; // taxonomy slug → display name
				}
				$combo[] = $val;
			}
			$variants[] = array(
				'optionValues'   => $combo,
				'sku'            => $this->ensure_sku( $v ),
				'price'          => (float) $v->get_price(),
				'compareAtPrice' => $this->compare_at( $v ),
				'weight'         => $v->get_weight() ? (float) $v->get_weight() : null,
				'weightUnit'     => $this->weight_unit(),
			);
		}
		return array( $options, $variants );
	}

	private function compare_at( $product ) {
		$regular = $product->get_regular_price();
		$price   = $product->get_price();
		return ( '' !== $regular && (float) $regular > (float) $price ) ? (float) $regular : null;
	}

	private function weight_unit() {
		$u = get_option( 'woocommerce_weight_unit', 'kg' );
		return in_array( $u, array( 'g', 'kg', 'lb', 'oz' ), true ) ? $u : null;
	}

	private function images( $product ) {
		$images = array();
		$fid    = $product->get_image_id();
		if ( $fid ) {
			$url = wp_get_attachment_url( $fid );
			if ( $url ) {
				$images[] = array( 'src' => $url, 'position' => 0, 'alt' => (string) get_post_meta( $fid, '_wp_attachment_image_alt', true ) );
			}
		}
		$pos = 1;
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			$url = wp_get_attachment_url( $gid );
			if ( $url ) {
				$images[] = array( 'src' => $url, 'position' => $pos++, 'alt' => (string) get_post_meta( $gid, '_wp_attachment_image_alt', true ) );
			}
		}
		return $images;
	}

	private function brand_term( $product_id ) {
		foreach ( self::$brand_tax as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return (int) $terms[0];
			}
		}
		return 0;
	}

	/** Drop null/'' leaves so the payload stays lean (keeps 0 and arrays). */
	private function prune( $payload ) {
		foreach ( $payload as $k => $v ) {
			if ( null === $v || '' === $v ) {
				unset( $payload[ $k ] );
			}
		}
		return $payload;
	}

	// ── Pull (platform → WooCommerce) ───────────────────────────────────────

	public function pull() {
		$dir = $this->settings->get( 'product_sync_dir', 'both' );
		if ( ( 'pull' !== $dir && 'both' !== $dir ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$cursor = get_option( 'aisooq_prod_pull_cursor', '' );
		$res    = $this->api->get(
			'/connect/products?limit=' . self::PULL_PAGE . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' )
		);
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Product pull failed: ' . $res->get_error_message() );
			return;
		}
		$rows = isset( $res['products'] ) && is_array( $res['products'] ) ? $res['products'] : array();
		$max  = $cursor;
		foreach ( $rows as $p ) {
			$this->apply_product( $p );
			if ( ! empty( $p['updatedAt'] ) && $p['updatedAt'] > $max ) {
				$max = $p['updatedAt'];
			}
		}

		if ( $max && $max !== $cursor ) {
			update_option( 'aisooq_prod_pull_cursor', $max, false );
			return;
		}

		// The cursor did not move, and the page came back FULL.
		//
		// `updatedSince` is inclusive, so a batch of products sharing one
		// timestamp — a bulk edit, an import, a scripted price change — fills
		// the page with rows whose updatedAt equals the cursor. Nothing
		// advances, the same page is re-applied on every tick forever, and no
		// product past that timestamp is ever pulled again. Step over the tie
		// by one second so the pull can make progress.
		if ( count( $rows ) >= self::PULL_PAGE ) {
			$ts = $cursor ? strtotime( (string) $cursor ) : 0;
			if ( $ts ) {
				$next = gmdate( 'c', $ts + 1 );
				$this->logger->error(
					'Product pull cursor stalled at ' . $cursor . ' (a full page shares that timestamp); advancing to ' . $next . '.'
				);
				update_option( 'aisooq_prod_pull_cursor', $next, false );
			}
		}
	}

	private function apply_product( $p ) {
		$platform_id      = isset( $p['id'] ) ? (int) $p['id'] : 0;
		$platform_updated = isset( $p['updatedAt'] ) ? (string) $p['updatedAt'] : '';
		$variants         = isset( $p['variants'] ) && is_array( $p['variants'] ) ? $p['variants'] : array();

		$wc_id = 0;
		if ( ! empty( $p['externalId'] ) && ( isset( $p['externalSource'] ) && 'woocommerce' === $p['externalSource'] ) ) {
			if ( wc_get_product( (int) $p['externalId'] ) ) {
				$wc_id = (int) $p['externalId'];
			}
		}
		if ( ! $wc_id && ! empty( $p['handle'] ) ) {
			$post = get_page_by_path( $p['handle'], OBJECT, 'product' );
			if ( $post ) {
				$wc_id = (int) $post->ID;
			}
		}
		if ( ! $wc_id ) {
			foreach ( $variants as $v ) {
				if ( ! empty( $v['sku'] ) ) {
					$id = wc_get_product_id_by_sku( $v['sku'] );
					if ( $id ) {
						$vp    = wc_get_product( $id );
						$wc_id = ( $vp && $vp->get_parent_id() ) ? $vp->get_parent_id() : $id;
						break;
					}
				}
			}
		}

		// Last-write-wins.
		if ( $wc_id ) {
			$last = get_post_meta( $wc_id, '_aisooq_prod_platform_updated', true );
			if ( $last && $platform_updated && $platform_updated <= $last ) {
				return;
			}
		}

		self::$suppress = true;
		if ( $wc_id ) {
			$product = wc_get_product( $wc_id );
			if ( $product ) {
				if ( ! empty( $p['title'] ) ) {
					$product->set_name( $p['title'] );
				}
				if ( isset( $p['descriptionHtml'] ) ) {
					$product->set_description( $p['descriptionHtml'] );
				}
				if ( ! empty( $p['status'] ) ) {
					$product->set_status( $this->wc_status( $p['status'] ) );
				}
				if ( $product->is_type( 'simple' ) && 1 === count( $variants ) ) {
					$this->apply_prices( $product, $variants[0] );
				}
				$product->save();
				if ( $product->is_type( 'variable' ) ) {
					$this->apply_variation_prices( $product, $variants );
				}
				$this->stamp_product( $wc_id, $platform_id, $platform_updated, $p );
			}
		} elseif ( count( $variants ) <= 1 ) {
			$product = new WC_Product_Simple();
			$product->set_name( ! empty( $p['title'] ) ? $p['title'] : 'Product' );
			if ( ! empty( $p['handle'] ) ) {
				$product->set_slug( $p['handle'] );
			}
			if ( isset( $p['descriptionHtml'] ) ) {
				$product->set_description( $p['descriptionHtml'] );
			}
			$product->set_status( $this->wc_status( isset( $p['status'] ) ? $p['status'] : 'draft' ) );
			if ( ! empty( $variants ) ) {
				$this->apply_prices( $product, $variants[0] );
				if ( ! empty( $variants[0]['sku'] ) ) {
					$product->set_sku( $variants[0]['sku'] );
				}
			}
			$new_id = $product->save();
			if ( $new_id ) {
				$this->stamp_product( $new_id, $platform_id, $platform_updated, $p );
			}
		} else {
			$this->logger->debug( 'Skipped creating multi-variant product ' . $platform_id . ' from platform (create the variable product manually first).' );
		}
		self::$suppress = false;
	}

	/**
	 * Apply one platform variant's pricing to a WooCommerce product/variation.
	 *
	 * The platform uses the Shopify shape, which is the INVERSE of WooCommerce:
	 * its `price` is what the customer pays right now (the sale price during a
	 * sale) and `compareAtPrice` is the struck-through original. push() already
	 * encodes it that way — see simple_variant() and compare_at().
	 *
	 * Writing `price` straight into regular_price therefore destroyed data: a
	 * product on sale at 800 with a regular price of 1000 came back as a
	 * regular price of 800, the 1000 was lost, and because the default sync
	 * direction is `both` the markdown ratcheted again on every cron tick.
	 *
	 * @param WC_Product $product Product or variation to price.
	 * @param array      $variant Platform variant row.
	 */
	private function apply_prices( $product, array $variant ) {
		if ( ! isset( $variant['price'] ) ) {
			return;
		}
		$price   = (float) $variant['price'];
		$compare = isset( $variant['compareAtPrice'] ) && null !== $variant['compareAtPrice']
			? (float) $variant['compareAtPrice']
			: 0.0;

		if ( $compare > $price ) {
			// On sale: compareAtPrice is the real regular price.
			$product->set_regular_price( (string) $compare );
			$product->set_sale_price( (string) $price );
		} else {
			// Not on sale. Clear any stale sale price, or the product would
			// keep an old discount that the platform no longer knows about.
			$product->set_regular_price( (string) $price );
			$product->set_sale_price( '' );
		}
	}

	private function apply_variation_prices( $product, $variants ) {
		$by_sku = array();
		foreach ( $product->get_children() as $vid ) {
			$vp = wc_get_product( $vid );
			if ( $vp && $vp->get_sku() ) {
				$by_sku[ $vp->get_sku() ] = $vp;
			}
		}
		foreach ( $variants as $v ) {
			if ( ! empty( $v['sku'] ) && isset( $by_sku[ $v['sku'] ] ) && isset( $v['price'] ) ) {
				$this->apply_prices( $by_sku[ $v['sku'] ], $v );
				$by_sku[ $v['sku'] ]->save();
			}
		}
	}

	private function stamp_product( $wc_id, $platform_id, $platform_updated, $p ) {
		AI_Sooq_Seo::set_post_seo(
			$wc_id,
			isset( $p['seoTitle'] ) ? $p['seoTitle'] : '',
			isset( $p['seoDescription'] ) ? $p['seoDescription'] : ''
		);
		$this->apply_taxonomy( $wc_id, $p );
		update_post_meta( $wc_id, self::PLATFORM_META, $platform_id );
		update_post_meta( $wc_id, '_aisooq_prod_platform_updated', $platform_updated );
	}

	/**
	 * Assign categories + brand on a pulled product. The platform returns the
	 * terms' external ids, which ARE the WooCommerce term ids they synced from,
	 * so map them straight to product_cat / the brand taxonomy (only ids that
	 * still resolve to a real term are kept).
	 */
	private function apply_taxonomy( $wc_id, $p ) {
		if ( ! empty( $p['categoryExternalIds'] ) && is_array( $p['categoryExternalIds'] ) ) {
			$ids = array();
			foreach ( $p['categoryExternalIds'] as $ext ) {
				$term = get_term( (int) $ext, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $ext;
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $wc_id, $ids, 'product_cat' );
			}
		}

		if ( ! empty( $p['brandExternalId'] ) ) {
			foreach ( self::$brand_tax as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$term = get_term( (int) $p['brandExternalId'], $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					wp_set_object_terms( $wc_id, array( (int) $p['brandExternalId'] ), $tax );
					break;
				}
			}
		}
	}

	private function wc_status( $status ) {
		return ( 'active' === $status || 'publish' === $status ) ? 'publish' : 'draft';
	}
}
