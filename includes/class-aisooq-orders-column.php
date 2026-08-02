<?php
/**
 * "AI Sooq" column in the WooCommerce orders list. Shows a green
 * "Synced" badge once an order carries the platform id, otherwise a per-order
 * "Sync" button that force-pushes just that order. Works on both the legacy
 * (posts) orders screen and the HPOS (`wc-orders`) screen.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Orders_Column {

	const COL   = 'aisooq_sync';
	const NONCE = 'aisooq_order_col';

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register() {
		// Legacy (posts) orders table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 20, 2 );
		// HPOS (custom-table) orders table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos' ), 20, 2 );
		// Click handler (inline, only on the orders screen) + the AJAX endpoint.
		add_action( 'admin_footer', array( $this, 'footer_js' ) );
		add_action( 'wp_ajax_aisooq_order_sync', array( $this, 'ajax_sync_order' ) );
	}

	/** Insert the column right after the order Status column (fallback: append). */
	public function add_column( $columns ) {
		$label = __( 'AI Sooq', 'aisooq-connector' );
		$out   = array();
		foreach ( $columns as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'order_status' === $key ) {
				$out[ self::COL ] = $label;
			}
		}
		if ( ! isset( $out[ self::COL ] ) ) {
			$out[ self::COL ] = $label;
		}
		return $out;
	}

	/** Legacy screen passes ($column, $post_id). */
	public function render_legacy( $column, $post_id ) {
		if ( self::COL === $column ) {
			echo $this->cell( wc_get_order( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- cell() escapes.
		}
	}

	/** HPOS screen passes ($column, $order). */
	public function render_hpos( $column, $order ) {
		if ( self::COL === $column ) {
			echo $this->cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- cell() escapes.
		}
	}

	/** Synced badge, or the per-order Sync button. */
	private function cell( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$platform_id = (string) $order->get_meta( AISOOQ_META_ID );
		if ( '' !== $platform_id ) {
			$at    = (string) $order->get_meta( AISOOQ_META_SYNCED_AT );
			$title = trim( sprintf( 'Platform #%s %s', $platform_id, $at ? '· ' . $at : '' ) );
			return '<span class="aisooq-order-synced" style="color:#00844a;font-weight:600;white-space:nowrap;" title="' . esc_attr( $title ) . '">&#10003; ' . esc_html__( 'Synced', 'aisooq-connector' ) . '</span>';
		}
		return '<button type="button" class="button button-small aisooq-sync-order" data-order="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( 'Sync', 'aisooq-connector' ) . '</button>';
	}

	/** Inline click handler — only printed on the orders list screens. */
	public function footer_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$nonce   = wp_create_nonce( self::NONCE );
		$syncing = esc_js( __( 'Syncing…', 'aisooq-connector' ) );
		$synced  = esc_js( __( 'Synced', 'aisooq-connector' ) );
		$failed  = esc_js( __( 'Sync failed', 'aisooq-connector' ) );
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.addEventListener( 'click', function ( e ) {
				var b = e.target && e.target.classList && e.target.classList.contains( 'aisooq-sync-order' ) ? e.target : null;
				if ( ! b ) { return; }
				e.preventDefault();
				b.disabled = true;
				var orig = b.textContent;
				b.textContent = '<?php echo $syncing; // phpcs:ignore ?>';
				var data = new FormData();
				data.append( 'action', 'aisooq_order_sync' );
				data.append( 'nonce', nonce );
				data.append( 'order_id', b.getAttribute( 'data-order' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) {
							var s = document.createElement( 'span' );
							s.className = 'aisooq-order-synced';
							s.style.cssText = 'color:#00844a;font-weight:600;white-space:nowrap';
							s.innerHTML = '✓ <?php echo $synced; // phpcs:ignore ?>';
							b.parentNode.replaceChild( s, b );
						} else {
							b.disabled = false;
							b.textContent = orig;
							window.alert( ( j && j.data && j.data.message ) || '<?php echo $failed; // phpcs:ignore ?>' );
						}
					} )
					.catch( function () { b.disabled = false; b.textContent = orig; window.alert( '<?php echo $failed; // phpcs:ignore ?>' ); } );
			} );
		} )();
		</script>
		<?php
	}

	/** Force-sync a single order (per-order Sync button). */
	public function ajax_sync_order() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'aisooq-connector' ) ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order.', 'aisooq-connector' ) ) );
		}
		$res = AI_Sooq_Plugin::instance()->order_sync()->sync_one( $order_id );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['message'] ) );
		}
		wp_send_json_success( array( 'message' => $res['message'], 'id' => isset( $res['id'] ) ? $res['id'] : '' ) );
	}
}
