<?php
/**
 * The dead letter office: orders that stopped trying.
 *
 * An order that fails MAX_ATTEMPTS times is never pushed again unless somebody
 * intervenes — and until this screen existed, nobody could. The dashboard
 * counted them, and that was all: no list, no reason, no way to try again. A
 * merchant's only signal that an order never reached the platform was noticing
 * it missing over there, which is not a signal, it is an accident.
 *
 * The reason is the point. "3 failed" tells an operator something is wrong and
 * nothing about what; "Rate limited by the platform" and "Missing Store SID"
 * are different emergencies with different fixes, and one of them is not an
 * emergency at all.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Failed_Admin {

	const PARENT_SLUG = 'aisooq';
	const PAGE_SLUG   = 'aisooq-failed';
	const CAPABILITY  = 'manage_woocommerce';
	const NONCE       = 'aisooq_failed';
	const PER_PAGE    = 25;

	/** @var AI_Sooq_Settings */
	private $settings;

	/** @var AI_Sooq_Order_Sync */
	private $sync;

	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Order_Sync $sync, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->sync     = $sync;
		$this->logger   = $logger;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_aisooq_failed_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_aisooq_failed_retry_all', array( $this, 'ajax_retry_all' ) );
	}

	public function add_menu() {
		$count = AI_Sooq_Order_Sync::failed_count();
		$label = __( 'Failed syncs', 'aisooq-connector' );
		// The bubble is the whole point of the menu entry: an operator should
		// not have to open a screen to find out whether anything is wrong.
		$title = '<span class="dashicons dashicons-warning" style="font-size:17px;width:17px;height:17px;vertical-align:-3px;"></span> ' . $label;
		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
		}
		add_submenu_page( self::PARENT_SLUG, $label, $title, self::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	// ── Guards ──────────────────────────────────────────────────────────────

	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'aisooq-connector' ) ) );
		}
	}

	// ── Actions ─────────────────────────────────────────────────────────────

	public function ajax_retry() {
		$this->guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order.', 'aisooq-connector' ) ) );
		}
		$res = $this->sync->retry( $order_id );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['message'] ) );
		}
		$this->logger->debug( 'Order ' . $order_id . ' retried by hand from the failed-syncs screen.' );
		wp_send_json_success( array( 'message' => $res['message'], 'queued' => ! empty( $res['queued'] ) ) );
	}

	public function ajax_retry_all() {
		$this->guard();
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$res    = $this->sync->retry_failed( $offset );
		wp_send_json_success( $res );
	}

	// ── Screen ──────────────────────────────────────────────────────────────

	/**
	 * The reason this order stopped, in the operator's terms.
	 *
	 * An order that gave up before this release carries none of the new meta,
	 * and will never fail again (nothing reschedules it past the ceiling), so
	 * it would show a permanently blank cell. Say so instead.
	 */
	private static function reason( $order ) {
		$why = trim( (string) $order->get_meta( AISOOQ_META_ERROR ) );
		if ( '' !== $why ) {
			return $why;
		}
		return __( 'Not recorded — this order gave up before the update that started keeping reasons.', 'aisooq-connector' );
	}

	/** Last attempt, falling back through what older orders do carry. */
	private static function last_try( $order ) {
		$when = trim( (string) $order->get_meta( AISOOQ_META_LAST_TRY ) );
		if ( '' !== $when ) {
			return $when;
		}
		$synced = trim( (string) $order->get_meta( AISOOQ_META_SYNCED_AT ) );
		if ( '' !== $synced ) {
			return $synced;
		}
		$created = $order->get_date_created();
		return $created ? $created->date( 'Y-m-d H:i' ) : '';
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$page   = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total  = AI_Sooq_Order_Sync::failed_count( true );
		$orders = array();

		if ( function_exists( 'wc_get_orders' ) && $total > 0 ) {
			$orders = (array) wc_get_orders(
				AI_Sooq_Order_Sync::failed_query_args(
					array( 'limit' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE )
				)
			);
		}

		echo '<div class="wrap aisooq-bl aisooq-fo">';
		echo '<div class="aisooq-hero"><div><h1>' . esc_html__( 'Failed syncs', 'aisooq-connector' ) . '</h1>';
		echo '<p class="aisooq-hero__sub">' . esc_html(
			sprintf(
				/* translators: %d: the number of attempts an order makes before giving up. */
				__( 'Orders that tried %d times and stopped. Nothing will push them again on its own — they are here so you can see why, fix the cause, and send them.', 'aisooq-connector' ),
				(int) AI_Sooq_Order_Sync::MAX_ATTEMPTS
			)
		) . '</p></div></div>';

		if ( ! $this->settings->is_active() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'The connection is paused, so retrying is disabled. Activate it in Settings first.', 'aisooq-connector' )
				. '</p></div>';
		}

		if ( 0 === $total ) {
			echo '<div class="aisooq-kpi"><div class="aisooq-kpi__label">' . esc_html__( 'Nothing has given up', 'aisooq-connector' ) . '</div>';
			echo '<div class="aisooq-kpi__sub">' . esc_html__( 'Every order either synced or is still retrying.', 'aisooq-connector' ) . '</div></div></div>';
			return;
		}

		$this->render_toolbar( $total );
		$this->render_table( $orders );
		$this->render_pagination( $page, $total );
		$this->render_script();
		echo '</div>';
	}

	private function render_toolbar( $total ) {
		?>
		<div class="aisooq-compose">
			<div class="grow">
				<span class="h"><?php esc_html_e( 'Given up', 'aisooq-connector' ); ?></span>
				<strong class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
			</div>
			<button type="button" class="button button-primary" id="aisooq-retry-all" <?php disabled( ! $this->settings->is_active() ); ?>>
				<?php esc_html_e( 'Try all again', 'aisooq-connector' ); ?>
			</button>
			<span id="aisooq-fo-msg" class="aisooq-msg" role="status" aria-live="polite"></span>
		</div>
		<p class="description">
			<?php esc_html_e( 'Retrying does not reset an order\'s attempt count, so an order that fails again stays on this list with a fresh reason rather than disappearing.', 'aisooq-connector' ); ?>
		</p>
		<?php
	}

	private function render_table( array $orders ) {
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Order', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Why it stopped', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Attempts', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Last tried', 'aisooq-connector' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $orders as $order ) :
				if ( ! is_a( $order, 'WC_Order' ) ) {
					continue;
				}
				$blocker = $this->sync->retry_blocker( $order );
				?>
				<tr data-order="<?php echo esc_attr( $order->get_id() ); ?>">
					<td>
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
						<div><small><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small></div>
					</td>
					<td class="aisooq-who">
						<div><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></div>
						<?php if ( $order->get_billing_phone() ) : ?><div><code><?php echo esc_html( $order->get_billing_phone() ); ?></code></div><?php endif; ?>
					</td>
					<td><?php echo esc_html( self::reason( $order ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $order->get_meta( AISOOQ_META_ATTEMPTS ) ) ); ?></td>
					<td><?php echo esc_html( self::last_try( $order ) ); ?></td>
					<td>
						<?php if ( '' !== $blocker ) : ?>
							<span class="aisooq-dim" title="<?php echo esc_attr( $blocker ); ?>"><?php esc_html_e( 'Cannot retry', 'aisooq-connector' ); ?></span>
						<?php else : ?>
							<button type="button" class="button aisooq-fo-retry"><?php esc_html_e( 'Try again', 'aisooq-connector' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_pagination( $page, $total ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => (int) $page,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div></div>';
	}

	private function render_script() {
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var msg   = document.getElementById( 'aisooq-fo-msg' );
			var s     = <?php echo wp_json_encode( array(
				'failed'  => __( 'Request failed.', 'aisooq-connector' ),
				'working' => __( 'Working…', 'aisooq-connector' ),
				/* translators: 1: number queued, 2: number skipped. */
				'done'    => __( '%1$d queued, %2$d could not be retried. Reloading…', 'aisooq-connector' ),
			) ); ?>;

			function say( t, ok ) { if ( msg ) { msg.textContent = t; msg.className = 'aisooq-msg ' + ( ok ? 'is-ok' : 'is-err' ); } }
			function post( action, fields ) {
				var d = new FormData();
				d.append( 'action', action ); d.append( 'nonce', nonce );
				Object.keys( fields ).forEach( function ( k ) { d.append( k, fields[ k ] ); } );
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: d } ).then( function ( r ) { return r.json(); } );
			}

			document.addEventListener( 'click', function ( e ) {
				var b = e.target.closest ? e.target.closest( '.aisooq-fo-retry' ) : null;
				if ( ! b ) { return; }
				var row = b.closest( 'tr' );
				b.disabled = true;
				post( 'aisooq_failed_retry', { order_id: row.getAttribute( 'data-order' ) } ).then( function ( j ) {
					if ( j && j.success ) {
						b.replaceWith( document.createTextNode( ( j.data && j.data.message ) || '' ) );
						row.style.opacity = '.55';
						return;
					}
					b.disabled = false;
					say( ( j && j.data && j.data.message ) || s.failed, false );
				} ).catch( function () { b.disabled = false; say( s.failed, false ); } );
			} );

			var all = document.getElementById( 'aisooq-retry-all' );
			if ( all ) {
				all.addEventListener( 'click', function () {
					all.disabled = true;
					var queued = 0, skipped = 0;
					// Cursored: a retried order keeps its attempt count and an
					// un-retryable one never leaves the set, so walking by
					// offset is the only way past them.
					( function step( offset ) {
						say( s.working, true );
						post( 'aisooq_failed_retry_all', { offset: offset } ).then( function ( j ) {
							if ( ! j || ! j.success ) { all.disabled = false; say( ( j && j.data && j.data.message ) || s.failed, false ); return; }
							queued += j.data.queued; skipped += j.data.skipped;
							if ( j.data.next_offset < j.data.total ) { step( j.data.next_offset ); return; }
							say( s.done.replace( '%1$d', queued ).replace( '%2$d', skipped ), true );
							setTimeout( function () { location.reload(); }, 900 );
						} ).catch( function () { all.disabled = false; say( s.failed, false ); } );
					} )( 0 );
				} );
			}
		} )();
		</script>
		<?php
	}
}
