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

	/**
	 * Synced badge + Resync, or the first-time Sync button.
	 *
	 * A synced order used to be a dead end: the badge was all you got, so the
	 * only way to push a corrected address or an edited line was to wait for
	 * the next status change to trigger a re-push. The server has always
	 * force-synced (`sync_one` ignores prior state and the platform upserts on
	 * externalId), so this exposes what the endpoint already did.
	 */
	private function cell( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$id          = (string) $order->get_id();
		$platform_id = (string) $order->get_meta( AISOOQ_META_ID );

		if ( '' !== $platform_id ) {
			$at    = (string) $order->get_meta( AISOOQ_META_SYNCED_AT );
			$title = trim( sprintf( 'Platform #%s %s', $platform_id, $at ? '· ' . $at : '' ) );
			/*
			 * Icons, at the same size as the courier column's, because this cell
			 * sits beside it and two neighbouring columns of differently-shaped
			 * controls read as two different kinds of thing. The words are not
			 * lost — every one of them survives in `aria-label` and `title`,
			 * which is also where the platform id and sync time live.
			 *
			 * `role="img"` on the state mark: a bare <span aria-label> is not
			 * announced by screen readers without one.
			 */
			return '<span class="aisooq-order-cell">'
				. '<span class="aisooq-order-synced" role="img" title="' . esc_attr( $title ) . '" aria-label="'
				. esc_attr__( 'Synced', 'aisooq-connector' ) . '">'
				. '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>'
				. '<button type="button" class="button-link aisooq-sync-order aisooq-order-icon is-resync" data-order="'
				. esc_attr( $id ) . '" title="'
				. esc_attr__( 'Push this order again — use after editing the address or items', 'aisooq-connector' )
				. '" aria-label="' . esc_attr__( 'Resync', 'aisooq-connector' ) . '">'
				. '<span class="dashicons dashicons-update" aria-hidden="true"></span></button>'
				. '</span>';
		}

		return '<span class="aisooq-order-cell">'
			. '<button type="button" class="button-link aisooq-sync-order aisooq-order-icon" data-order="'
			. esc_attr( $id ) . '" title="'
			. esc_attr__( 'Push this order to AI Sooq now', 'aisooq-connector' )
			. '" aria-label="' . esc_attr__( 'Sync', 'aisooq-connector' ) . '">'
			. '<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span></button>'
			. '</span>';
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
		$resync  = esc_js( __( 'Resync', 'aisooq-connector' ) );
		$failed  = esc_js( __( 'Sync failed', 'aisooq-connector' ) );
		?>
		<style>
			.aisooq-order-cell{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
			/* Same box as the courier column's icons, so the two columns read as
			   one row of controls rather than two unrelated ones. Two classes
			   deep because `.wp-core-ui .button-link` underlines its
			   link-buttons and outranks a single-class rule. */
			.aisooq-order-cell .aisooq-order-icon{display:inline-flex;align-items:center;justify-content:center;
				width:24px;min-width:24px;height:24px;padding:0;border-radius:4px;flex:0 0 auto;
				color:#2271b1;text-decoration:none;cursor:pointer;touch-action:manipulation;
				transition:background-color .18s cubic-bezier(.32,.72,0,1),color .18s cubic-bezier(.32,.72,0,1)}
			.aisooq-order-cell .aisooq-order-icon:hover:not([disabled]){background:#f0f6fc;color:#135e96}
			.aisooq-order-cell .aisooq-order-icon:focus-visible{outline:2px solid #2271b1;outline-offset:1px}
			.aisooq-order-cell .aisooq-order-icon .dashicons{width:16px;height:16px;font-size:16px;line-height:16px}
			.aisooq-order-cell .aisooq-order-icon[disabled]{opacity:.55;cursor:default}
			/* Resync is the quieter of the two: the order is already through, so
			   it should not compete with the un-synced state beside it. */
			.aisooq-order-cell .aisooq-order-icon.is-resync{color:#646970}
			.aisooq-order-cell .aisooq-order-icon.is-resync:hover:not([disabled]){color:#2271b1}
			.aisooq-order-synced{display:inline-flex;align-items:center;justify-content:center;
				width:24px;height:24px;color:#00844a;flex:0 0 auto}
			.aisooq-order-synced .dashicons{width:18px;height:18px;font-size:18px;line-height:18px}
			/* In flight: the arrows turn, so a slow push looks like work rather
			   than a dead button. */
			.aisooq-order-icon.is-busy .dashicons{animation:aisooq-spin 1s linear infinite}
			@keyframes aisooq-spin{to{transform:rotate(360deg)}}
			@media (prefers-reduced-motion:reduce){
				.aisooq-order-cell .aisooq-order-icon{transition:none}
				.aisooq-order-icon.is-busy .dashicons{animation:none;opacity:.6}
			}
			/* Below 782px WordPress stacks the orders table into cards and the
			   cell gets the full row — and 24px is well under a usable tap
			   target, which WCAG 2.5.5 puts at 44px. */
			@media (max-width:782px),(pointer:coarse){
				.aisooq-order-cell{gap:6px}
				.aisooq-order-cell .aisooq-order-icon{width:40px;min-width:40px;height:40px}
				.aisooq-order-cell .aisooq-order-icon .dashicons{width:20px;height:20px;font-size:20px;line-height:20px}
				/* The synced mark is a state, not a control — nobody taps it, so
				   it keeps its small box. A touch target belongs on things you
				   press, and inflating this one only widened the column. */
				.aisooq-order-synced .dashicons{width:20px;height:20px;font-size:20px;line-height:20px}
			}
		</style>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.addEventListener( 'click', function ( e ) {
				// closest(), not the target itself: on touch the press often
				// lands on a node inside the button.
				var b = e.target && e.target.closest ? e.target.closest( '.aisooq-sync-order' ) : null;
				if ( ! b || b.disabled ) { return; }
				e.preventDefault();
				b.disabled = true;
				// The label lives in aria-label now, not in text — writing to
				// textContent would delete the icon element inside the button.
				var icon = b.querySelector( '.dashicons' );
				var orig = { label: b.getAttribute( 'aria-label' ), title: b.getAttribute( 'title' ), icon: icon ? icon.className : '' };
				b.classList.add( 'is-busy' );
				b.setAttribute( 'aria-label', '<?php echo $syncing; // phpcs:ignore ?>' );
				b.setAttribute( 'title', '<?php echo $syncing; // phpcs:ignore ?>' );
				if ( icon ) { icon.className = 'dashicons dashicons-update'; }
				// A failed push must leave the control exactly as it was, or the
				// row starts claiming a state the platform never reached.
				function restore() {
					b.disabled = false;
					b.classList.remove( 'is-busy' );
					if ( orig.label ) { b.setAttribute( 'aria-label', orig.label ); }
					if ( orig.title ) { b.setAttribute( 'title', orig.title ); }
					if ( icon && orig.icon ) { icon.className = orig.icon; }
				}
				var data = new FormData();
				data.append( 'action', 'aisooq_order_sync' );
				data.append( 'nonce', nonce );
				data.append( 'order_id', b.getAttribute( 'data-order' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						b.classList.remove( 'is-busy' );
						if ( j && j.success ) {
							// Leave the button in place and turn it into Resync,
							// so a corrected order can be pushed again without a
							// page reload — replacing it with a badge is what
							// made a synced order a dead end in the first place.
							var cell = b.parentNode;
							if ( ! cell.querySelector( '.aisooq-order-synced' ) ) {
								var s = document.createElement( 'span' );
								s.className = 'aisooq-order-synced';
								s.setAttribute( 'role', 'img' );
								s.setAttribute( 'aria-label', '<?php echo $synced; // phpcs:ignore ?>' );
								s.innerHTML = '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
								cell.insertBefore( s, b );
							}
							b.classList.add( 'is-resync' );
							b.setAttribute( 'aria-label', '<?php echo $resync; // phpcs:ignore ?>' );
							b.setAttribute( 'title', '<?php echo $resync; // phpcs:ignore ?>' );
							if ( icon ) { icon.className = 'dashicons dashicons-update'; }
							b.disabled = false;
						} else {
							restore();
							window.alert( ( j && j.data && j.data.message ) || '<?php echo $failed; // phpcs:ignore ?>' );
						}
					} )
					.catch( function () { restore(); window.alert( '<?php echo $failed; // phpcs:ignore ?>' ); } );
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
