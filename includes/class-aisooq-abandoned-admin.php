<?php
/**
 * "Abandoned carts" admin screen: a submenu under AI Sooq that turns the
 * local capture table ({@see AI_Sooq_Abandoned_Sync::table_name()}) into
 * a full operator worklist — headline analytics, a recovery funnel, AJAX
 * search + filters (status / product / date range), and per-row actions:
 * check courier ratio, convert to a WooCommerce order, cancel, mark fake,
 * view details, delete, and (re)sync.
 *
 * Data source is the LOCAL table (no platform round-trip to render). An
 * abandoned cart IS an incomplete order: cancel / fake only flip a local
 * status, delete removes the local row, and none of those touch the platform —
 * the cart was already mirrored there when it was captured. Only Resync (push)
 * and the courier-ratio lookup call the platform API.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Abandoned_Admin {

	const CAPABILITY  = 'manage_woocommerce';
	const PAGE_SLUG   = 'aisooq-abandoned';
	const PARENT_SLUG = 'aisooq-connector';
	const NONCE       = 'aisooq_abandoned';
	const PER_PAGE    = 100;

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Abandoned_Sync */
	private $abandoned;
	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Abandoned_Sync $abandoned, AI_Sooq_Logger $logger ) {
		$this->settings  = $settings;
		$this->abandoned = $abandoned;
		$this->logger    = $logger;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_aisooq_abandoned_resync', array( $this, 'ajax_resync' ) );
		add_action( 'wp_ajax_aisooq_abandoned_query', array( $this, 'ajax_query' ) );
		add_action( 'wp_ajax_aisooq_abandoned_courier', array( $this, 'ajax_courier' ) );
		add_action( 'wp_ajax_aisooq_abandoned_action', array( $this, 'ajax_action' ) );
		add_action( 'wp_ajax_aisooq_abandoned_bulk', array( $this, 'ajax_bulk' ) );
		add_action( 'wp_ajax_aisooq_abandoned_details', array( $this, 'ajax_details' ) );
	}

	public function add_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Abandoned carts', 'aisooq-connector' ),
			// Dashicon in the submenu label (WP renders menu titles with markup).
			'<span class="dashicons dashicons-cart" style="font-size:17px;width:17px;height:17px;vertical-align:-3px;"></span> ' . __( 'Abandoned carts', 'aisooq-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** True once the capture table exists (i.e. the plugin has activated once). */
	private function table_ready() {
		global $wpdb;
		$t = AI_Sooq_Abandoned_Sync::table_name();
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB
	}

	private function reachable_sql() {
		return "( ( email IS NOT NULL AND email <> '' ) OR ( phone IS NOT NULL AND phone <> '' ) )";
	}

	/**
	 * Headline analytics from cheap aggregates over the local table.
	 *
	 * @return array<string,mixed>
	 */
	private function stats() {
		global $wpdb;
		$t         = AI_Sooq_Abandoned_Sync::table_name();
		$reachable = $this->reachable_sql();

		// All analytics are scoped to contactful carts — the same population the
		// worklist shows — so the KPIs and the table always agree.
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			"SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS recovered,
				SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
				SUM(CASE WHEN status = 'fake' THEN 1 ELSE 0 END) AS fake,
				SUM(CASE WHEN status = 'active' AND synced = 1 THEN 1 ELSE 0 END) AS pushed,
				SUM(CASE WHEN status = 'active' AND synced = 0 THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS open_count,
				SUM(CASE WHEN status = 'active' THEN subtotal ELSE 0 END) AS open_value,
				SUM(CASE WHEN status = 'converted' THEN subtotal ELSE 0 END) AS recovered_value
			FROM {$t} WHERE {$reachable}",
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		$funnel = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT COALESCE(NULLIF(furthest_step, ''), 'unknown') AS step, COUNT(*) AS n
			 FROM {$t} WHERE status = 'active' AND {$reachable} GROUP BY step ORDER BY n DESC",
			ARRAY_A
		);

		$total     = (int) ( $row['total'] ?? 0 );
		$recovered = (int) ( $row['recovered'] ?? 0 );
		$open      = (int) ( $row['open_count'] ?? 0 );
		$open_val  = (float) ( $row['open_value'] ?? 0 );

		return array(
			'total'           => $total,
			'open'            => $open,
			'recovered'       => $recovered,
			'cancelled'       => (int) ( $row['cancelled'] ?? 0 ),
			'fake'            => (int) ( $row['fake'] ?? 0 ),
			'pushed'          => (int) ( $row['pushed'] ?? 0 ),
			'pending'         => (int) ( $row['pending'] ?? 0 ),
			'open_value'      => $open_val,
			'recovered_value' => (float) ( $row['recovered_value'] ?? 0 ),
			'avg_open'        => $open > 0 ? $open_val / $open : 0,
			'recovery_rate'   => $total > 0 ? $recovered / $total : 0,
			'funnel'          => is_array( $funnel ) ? $funnel : array(),
		);
	}

	/**
	 * Build the WHERE clause + prepared args for the current filter set.
	 *
	 * @param array $f status|search|product|from|to
	 * @return array{0:string,1:array}
	 */
	private function build_where( $f ) {
		$reachable = $this->reachable_sql();
		// Base requirement: a worklist entry is an incomplete order, so it must
		// have contact. No-contact rows (legacy captures) never show.
		$clauses   = array( $reachable );
		$args      = array();

		switch ( isset( $f['status'] ) ? $f['status'] : 'active' ) {
			case 'active':
				$clauses[] = "status = 'active'";
				break;
			case 'pending':
				$clauses[] = "status = 'active' AND synced = 0";
				break;
			case 'recovered':
				$clauses[] = "status = 'converted'";
				break;
			case 'cancelled':
				$clauses[] = "status = 'cancelled'";
				break;
			case 'fake':
				$clauses[] = "status = 'fake'";
				break;
			case 'all':
			default:
				break;
		}

		if ( ! empty( $f['search'] ) ) {
			global $wpdb;
			$like      = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$clauses[] = '( customer_name LIKE %s OR email LIKE %s OR phone LIKE %s OR cart_json LIKE %s )';
			$args[]    = $like;
			$args[]    = $like;
			$args[]    = $like;
			$args[]    = $like;
		}

		if ( ! empty( $f['product'] ) ) {
			// product_id is the first key of each captured line, always followed
			// by a comma, so this can't confuse 123 with 1234.
			$clauses[] = 'cart_json LIKE %s';
			$args[]    = '%"product_id":' . (int) $f['product'] . ',%';
		}

		$date_col = 'COALESCE(created_at, updated_at)';
		if ( ! empty( $f['from'] ) ) {
			$clauses[] = "{$date_col} >= %s";
			$args[]    = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) ) {
			$clauses[] = "{$date_col} <= %s";
			$args[]    = $f['to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/** Fetch a filtered page of rows. */
	private function rows( $f ) {
		global $wpdb;
		$t = AI_Sooq_Abandoned_Sync::table_name();
		list( $where, $args ) = $this->build_where( $f );
		$args[] = self::PER_PAGE;
		$sql    = "SELECT * FROM {$t} WHERE {$where} ORDER BY updated_at DESC LIMIT %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Distinct products seen across captured carts, for the product filter.
	 * Scans a bounded window of carts (worklist is GC'd to 30 days).
	 *
	 * @return array<int,string> product_id => label
	 */
	private function product_options() {
		global $wpdb;
		$t    = AI_Sooq_Abandoned_Sync::table_name();
		$rows = (array) $wpdb->get_col( "SELECT cart_json FROM {$t} ORDER BY updated_at DESC LIMIT 1000" ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $json ) {
			$lines = json_decode( (string) $json, true );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( $lines as $l ) {
				if ( empty( $l['product_id'] ) ) {
					continue;
				}
				$pid = (int) $l['product_id'];
				if ( ! isset( $out[ $pid ] ) ) {
					$out[ $pid ] = ! empty( $l['title'] ) ? (string) $l['title'] : ( '#' . $pid );
				}
			}
		}
		natcasesort( $out );
		return $out;
	}

	/** Currency-format a number using the store's WooCommerce currency symbol. */
	private function money( $n, $currency = '' ) {
		$symbol = '';
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ? $currency : null ) );
		}
		return $symbol . number_format_i18n( (float) $n, 0 );
	}

	/** Classify a row into a status label + tone for the badge. */
	private function row_status( $row ) {
		$reachable = ( ! empty( $row->email ) || ! empty( $row->phone ) );
		switch ( $row->status ) {
			case 'converted':
				return array( 'recovered', __( 'Recovered', 'aisooq-connector' ), 'ok' );
			case 'cancelled':
				return array( 'cancelled', __( 'Cancelled', 'aisooq-connector' ), 'muted' );
			case 'fake':
				return array( 'fake', __( 'Fake', 'aisooq-connector' ), 'err' );
		}
		if ( ! $reachable ) {
			return array( 'unreachable', __( 'Unreachable', 'aisooq-connector' ), 'muted' );
		}
		if ( (int) $row->synced === 1 ) {
			return array( 'pushed', __( 'Pushed', 'aisooq-connector' ), 'info' );
		}
		return array( 'pending', __( 'Pending push', 'aisooq-connector' ), 'warn' );
	}

	/** HPOS-safe order edit URL. */
	private function order_edit_url( $order_id ) {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
		}
		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}

	/** Render the <tbody> rows for a set of carts (shared by page + AJAX). */
	private function render_rows( $rows, $currency, $active ) {
		if ( empty( $rows ) ) {
			return '<tr><td colspan="9"><div class="aisooq-empty">' . esc_html__( 'No carts match this filter.', 'aisooq-connector' ) . '</div></td></tr>';
		}
		ob_start();
		foreach ( $rows as $row ) {
			$lines = json_decode( (string) $row->cart_json, true );
			$lines = is_array( $lines ) ? $lines : array();
			$count = 0;
			foreach ( $lines as $l ) {
				$count += isset( $l['qty'] ) ? (int) $l['qty'] : 1;
			}
			$first = ! empty( $lines[0]['title'] ) ? (string) $lines[0]['title'] : '';
			$addr  = json_decode( (string) $row->address_json, true );
			$addr  = is_array( $addr ) ? $addr : array();
			$addr_bits = array_filter( array(
				isset( $addr['address1'] ) ? $addr['address1'] : '',
				isset( $addr['city'] ) ? $addr['city'] : '',
				isset( $addr['province'] ) ? $addr['province'] : '',
			) );
			list( $status_key, $status_label, $status_tone ) = $this->row_status( $row );
			$is_active   = ( 'active' === $row->status );
			$reachable   = ( ! empty( $row->email ) || ! empty( $row->phone ) );
			$key_attr    = esc_attr( $row->session_key );
			?>
			<tr data-key="<?php echo $key_attr; ?>" data-status="<?php echo esc_attr( $row->status ); ?>">
				<td class="aisooq-cb-cell"><input type="checkbox" class="aisooq-cb" value="<?php echo $key_attr; ?>" aria-label="<?php esc_attr_e( 'Select cart', 'aisooq-connector' ); ?>" /></td>
				<td data-label="<?php esc_attr_e( 'Customer', 'aisooq-connector' ); ?>">
					<div class="aisooq-td-val">
						<div class="aisooq-cust"><?php echo esc_html( $row->customer_name ? $row->customer_name : __( 'Anonymous', 'aisooq-connector' ) ); ?></div>
						<div class="aisooq-contact">
							<?php if ( $row->phone ) : ?><span><span class="dashicons dashicons-phone" aria-hidden="true"></span> <?php echo esc_html( $row->phone ); ?></span><br /><?php endif; ?>
							<?php if ( $row->email ) : ?><span><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php echo esc_html( $row->email ); ?></span><?php endif; ?>
						</div>
						<?php if ( $row->phone ) : ?>
							<div class="aisooq-courier" data-phone="<?php echo esc_attr( $row->phone ); ?>">
								<button type="button" class="button-link aisooq-check-courier" <?php disabled( ! $active ); ?>><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Check ratio', 'aisooq-connector' ); ?></button>
							</div>
						<?php endif; ?>
					</div>
				</td>
				<td class="aisooq-addr" data-label="<?php esc_attr_e( 'Address', 'aisooq-connector' ); ?>">
					<span class="aisooq-td-val"><?php echo $addr_bits ? esc_html( implode( ', ', $addr_bits ) ) : '<span class="aisooq-dim">—</span>'; ?></span>
				</td>
				<td data-label="<?php esc_attr_e( 'Cart', 'aisooq-connector' ); ?>">
					<div class="aisooq-td-val">
						<div class="aisooq-mono"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $count, 'aisooq-connector' ), $count ) ); ?></div>
						<?php if ( $first ) : ?><div class="aisooq-contact"><?php echo esc_html( wp_html_excerpt( $first, 42, '…' ) ); ?></div><?php endif; ?>
					</div>
				</td>
				<td class="aisooq-mono" data-label="<?php esc_attr_e( 'Value', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $this->money( $row->subtotal, $row->currency ? $row->currency : $currency ) ); ?></span></td>
				<td data-label="<?php esc_attr_e( 'Step', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $row->furthest_step ? ucfirst( (string) $row->furthest_step ) : '—' ); ?></span></td>
				<td data-label="<?php esc_attr_e( 'Status', 'aisooq-connector' ); ?>">
					<span class="aisooq-td-val">
						<span class="aisooq-badge <?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span>
						<?php if ( 'converted' === $row->status && $row->wc_order_id ) : ?>
							<a class="aisooq-dim" href="<?php echo esc_url( $this->order_edit_url( $row->wc_order_id ) ); ?>">#<?php echo (int) $row->wc_order_id; ?> →</a>
						<?php endif; ?>
					</span>
				</td>
				<td class="aisooq-dim" data-label="<?php esc_attr_e( 'Updated', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $row->updated_at ? human_time_diff( strtotime( $row->updated_at . ' UTC' ) ) . ' ' . __( 'ago', 'aisooq-connector' ) : '—' ); ?></span></td>
				<td class="aisooq-actions-cell" data-label="<?php esc_attr_e( 'Actions', 'aisooq-connector' ); ?>">
					<div class="aisooq-menu-wrap">
						<button type="button" class="button button-small aisooq-menu-btn" aria-haspopup="true" aria-expanded="false"><?php esc_html_e( 'Actions', 'aisooq-connector' ); ?> <span class="aisooq-caret">▾</span></button>
						<div class="aisooq-menu" hidden>
							<button type="button" class="aisooq-act" data-op="details"><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Details', 'aisooq-connector' ); ?></button>
							<?php if ( $is_active ) : ?>
								<button type="button" class="aisooq-act aisooq-primary" data-op="convert"><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Convert to order', 'aisooq-connector' ); ?></button>
								<button type="button" class="aisooq-act" data-op="resync" <?php disabled( ! $active ); ?>><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Resync', 'aisooq-connector' ); ?></button>
								<button type="button" class="aisooq-act" data-op="cancel"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Cancel', 'aisooq-connector' ); ?></button>
								<button type="button" class="aisooq-act" data-op="fake"><span class="dashicons dashicons-flag"></span> <?php esc_html_e( 'Mark fake', 'aisooq-connector' ); ?></button>
							<?php elseif ( 'cancelled' === $row->status || 'fake' === $row->status ) : ?>
								<button type="button" class="aisooq-act" data-op="reopen"><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Reopen', 'aisooq-connector' ); ?></button>
							<?php endif; ?>
							<button type="button" class="aisooq-act aisooq-danger" data-op="delete"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'aisooq-connector' ); ?></button>
						</div>
					</div>
				</td>
			</tr>
			<?php
		}
		return ob_get_clean();
	}

	// ── AJAX ────────────────────────────────────────────────────────────────

	private function guard_ajax( $need_connection = false ) {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		if ( $need_connection && ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'aisooq-connector' ) ) );
		}
	}

	private function read_filters() {
		return array(
			'status'  => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active', // phpcs:ignore WordPress.Security.NonceVerification
			'search'  => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'product' => isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'from'    => isset( $_POST['from'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_POST['from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'to'      => isset( $_POST['to'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_POST['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		);
	}

	/** Filtered table rows (AJAX search / filter). */
	public function ajax_query() {
		$this->guard_ajax();
		$f        = $this->read_filters();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BDT';
		$rows     = $this->rows( $f );
		wp_send_json_success( array(
			'html'  => $this->render_rows( $rows, $currency, $this->settings->is_active() && $this->settings->get( 'enable_abandoned' ) ),
			'count' => count( $rows ),
		) );
	}

	/** Per-row courier delivery-success ratio (platform /connect/courier). */
	public function ajax_courier() {
		$this->guard_ajax( true );
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'No phone on this cart.', 'aisooq-connector' ) ) );
		}
		$res = AI_Sooq_Plugin::instance()->api()->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array(
			'successRatio' => isset( $res['successRatio'] ) ? $res['successRatio'] : null,
			'totalParcel'  => isset( $res['totalParcel'] ) ? $res['totalParcel'] : null,
		) );
	}

	/** Row action: convert | cancel | fake | reopen | delete. */
	public function ajax_action() {
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard_ajax( 'resync' === $op );
		$key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing cart reference.', 'aisooq-connector' ) ) );
		}

		switch ( $op ) {
			case 'convert':
				$res = $this->abandoned->convert_to_wc_order( $key );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				wp_send_json_success( array(
					'message'  => sprintf( __( 'Order #%d created', 'aisooq-connector' ), $res ),
					'orderId'  => (int) $res,
					'orderUrl' => $this->order_edit_url( $res ),
					'reload'   => true,
				) );
				break;
			case 'cancel':
				$this->abandoned->set_status( $key, 'cancelled' );
				wp_send_json_success( array( 'message' => __( 'Cancelled', 'aisooq-connector' ), 'reload' => true ) );
				break;
			case 'fake':
				$this->abandoned->set_status( $key, 'fake' );
				wp_send_json_success( array( 'message' => __( 'Marked fake', 'aisooq-connector' ), 'reload' => true ) );
				break;
			case 'reopen':
				$this->abandoned->set_status( $key, 'active' );
				wp_send_json_success( array( 'message' => __( 'Reopened', 'aisooq-connector' ), 'reload' => true ) );
				break;
			case 'delete':
				$this->abandoned->delete_cart( $key );
				wp_send_json_success( array( 'message' => __( 'Deleted', 'aisooq-connector' ), 'removeRow' => true ) );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'aisooq-connector' ) ) );
		}
	}

	/**
	 * Apply one action to many selected carts: resync | convert | cancel | fake
	 * | delete. Iterates so one bad row never aborts the batch; returns done /
	 * failed counts (+ created order ids for convert).
	 */
	public function ajax_bulk() {
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard_ajax( 'resync' === $op );
		if ( 'resync' === $op && ! $this->settings->get( 'enable_abandoned' ) ) {
			wp_send_json_error( array( 'message' => __( 'Abandoned-cart sync is turned off in settings.', 'aisooq-connector' ) ) );
		}
		$allowed = array( 'resync', 'convert', 'cancel', 'fake', 'delete' );
		if ( ! in_array( $op, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown bulk action.', 'aisooq-connector' ) ) );
		}
		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['keys'] ) )
			: array();
		$keys = array_values( array_filter( array_unique( $keys ) ) );
		if ( empty( $keys ) ) {
			wp_send_json_error( array( 'message' => __( 'No carts selected.', 'aisooq-connector' ) ) );
		}
		// Bound the batch so a runaway selection can't tie up the request.
		$keys = array_slice( $keys, 0, self::PER_PAGE );

		$done   = 0;
		$fail   = 0;
		$orders = array();
		foreach ( $keys as $key ) {
			switch ( $op ) {
				case 'resync':
					$this->abandoned->resync( $key ) ? $done++ : $fail++;
					break;
				case 'convert':
					$r = $this->abandoned->convert_to_wc_order( $key );
					if ( is_wp_error( $r ) ) {
						$fail++;
					} else {
						$done++;
						$orders[] = (int) $r;
					}
					break;
				case 'cancel':
					$this->abandoned->set_status( $key, 'cancelled' ) ? $done++ : $fail++;
					break;
				case 'fake':
					$this->abandoned->set_status( $key, 'fake' ) ? $done++ : $fail++;
					break;
				case 'delete':
					$this->abandoned->delete_cart( $key ) ? $done++ : $fail++;
					break;
			}
		}

		$msg = sprintf(
			/* translators: 1: succeeded count, 2: failed count */
			__( '%1$d done, %2$d skipped.', 'aisooq-connector' ),
			$done,
			$fail
		);
		wp_send_json_success( array( 'op' => $op, 'done' => $done, 'fail' => $fail, 'orders' => $orders, 'message' => $msg ) );
	}

	/** Full cart detail (modal body). */
	public function ajax_details() {
		$this->guard_ajax();
		$key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$row = $this->abandoned->get_row( $key );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Cart not found.', 'aisooq-connector' ) ) );
		}
		$currency = $row->currency ? $row->currency : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BDT' );
		$lines    = json_decode( (string) $row->cart_json, true );
		$lines    = is_array( $lines ) ? $lines : array();
		$addr     = json_decode( (string) $row->address_json, true );
		$addr     = is_array( $addr ) ? $addr : array();
		$reachable = ( ! empty( $row->email ) || ! empty( $row->phone ) );
		list( , $status_label, $status_tone ) = $this->row_status( $row );

		// Best-effort match to a real WooCommerce customer (by email).
		$wc_user = ( $row->email && function_exists( 'get_user_by' ) ) ? get_user_by( 'email', $row->email ) : false;
		$order_count = 0;
		if ( $wc_user && function_exists( 'wc_get_customer_order_count' ) ) {
			$order_count = (int) wc_get_customer_order_count( $wc_user->ID );
		}

		$item_count = 0;
		foreach ( $lines as $l ) {
			$item_count += isset( $l['qty'] ) ? (int) $l['qty'] : 1;
		}
		$cap  = $row->created_at ? human_time_diff( strtotime( $row->created_at . ' UTC' ) ) . ' ' . __( 'ago', 'aisooq-connector' ) : '—';
		$seen = $row->updated_at ? human_time_diff( strtotime( $row->updated_at . ' UTC' ) ) . ' ' . __( 'ago', 'aisooq-connector' ) : '—';

		$addr_bits = array_filter( array(
			isset( $addr['address1'] ) ? $addr['address1'] : '',
			isset( $addr['address2'] ) ? $addr['address2'] : '',
			isset( $addr['city'] ) ? $addr['city'] : '',
			isset( $addr['province'] ) ? $addr['province'] : '',
			isset( $addr['zip'] ) ? $addr['zip'] : '',
			isset( $addr['country'] ) ? $addr['country'] : '',
		) );

		ob_start();
		?>
		<div class="aisooq-dl">
			<div class="aisooq-dl-head">
				<div>
					<h3><?php echo esc_html( $row->customer_name ? $row->customer_name : __( 'Anonymous shopper', 'aisooq-connector' ) ); ?></h3>
					<div class="aisooq-dl-sub"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $item_count, 'aisooq-connector' ), $item_count ) . ' · ' . $this->money( $row->subtotal, $currency ) ); ?></div>
				</div>
				<span class="aisooq-badge <?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>

			<div class="aisooq-dl-grid">
				<div class="aisooq-dl-sec">
					<div class="aisooq-dl-label"><?php esc_html_e( 'Contact', 'aisooq-connector' ); ?></div>
					<?php if ( $row->phone ) : ?><div><span class="dashicons dashicons-phone"></span> <a href="tel:<?php echo esc_attr( $row->phone ); ?>"><?php echo esc_html( $row->phone ); ?></a></div><?php endif; ?>
					<?php if ( $row->email ) : ?><div><span class="dashicons dashicons-email"></span> <a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></div><?php endif; ?>
					<?php if ( ! $reachable ) : ?><div class="aisooq-dim"><?php esc_html_e( 'No contact captured', 'aisooq-connector' ); ?></div><?php endif; ?>
					<?php if ( $wc_user ) : ?>
						<div class="aisooq-dl-cust">
							<span class="dashicons dashicons-admin-users"></span>
							<a href="<?php echo esc_url( get_edit_user_link( $wc_user->ID ) ); ?>"><?php echo esc_html( $wc_user->display_name ); ?></a>
							<?php if ( $order_count ) : ?><span class="aisooq-dim">· <?php echo esc_html( sprintf( _n( '%d order', '%d orders', $order_count, 'aisooq-connector' ), $order_count ) ); ?></span><?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="aisooq-dl-sec">
					<div class="aisooq-dl-label"><?php esc_html_e( 'Address', 'aisooq-connector' ); ?></div>
					<?php echo $addr_bits ? esc_html( implode( ', ', $addr_bits ) ) : '<span class="aisooq-dim">—</span>'; ?>
				</div>
			</div>

			<div class="aisooq-dl-label"><?php esc_html_e( 'Cart', 'aisooq-connector' ); ?></div>
			<table class="aisooq-tbl aisooq-dl-cart">
				<tbody>
				<?php
				foreach ( $lines as $l ) :
					$qty   = isset( $l['qty'] ) ? (int) $l['qty'] : 1;
					$price = isset( $l['price'] ) ? (float) $l['price'] : 0;
					$thumb = '';
					if ( ! empty( $l['product_id'] ) && function_exists( 'wc_get_product' ) ) {
						$p = wc_get_product( (int) $l['product_id'] );
						if ( $p ) {
							$thumb = $p->get_image( array( 40, 40 ), array( 'class' => 'aisooq-thumb' ) );
						}
					}
					?>
					<tr>
						<td class="aisooq-dl-thumb"><?php echo $thumb ? $thumb : '<span class="aisooq-thumb aisooq-thumb--ph"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<div><?php echo esc_html( ! empty( $l['title'] ) ? $l['title'] : '—' ); ?></div>
							<?php if ( ! empty( $l['sku'] ) ) : ?><div class="aisooq-dim" style="font-size:11px;">SKU: <?php echo esc_html( $l['sku'] ); ?></div><?php endif; ?>
						</td>
						<td class="aisooq-mono" style="white-space:nowrap;"><?php echo esc_html( $qty . ' × ' . $this->money( $price, $currency ) ); ?></td>
						<td class="aisooq-mono" style="text-align:right;white-space:nowrap;"><?php echo esc_html( $this->money( $price * $qty, $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="3" style="text-align:right;font-weight:600;"><?php esc_html_e( 'Subtotal', 'aisooq-connector' ); ?></td>
						<td class="aisooq-mono" style="text-align:right;font-weight:600;"><?php echo esc_html( $this->money( $row->subtotal, $currency ) ); ?></td>
					</tr>
				</tfoot>
			</table>

			<div class="aisooq-dl-grid aisooq-dl-meta">
				<div><span class="aisooq-dl-label"><?php esc_html_e( 'Furthest step', 'aisooq-connector' ); ?></span><?php echo esc_html( $row->furthest_step ? ucfirst( (string) $row->furthest_step ) : '—' ); ?></div>
				<div><span class="aisooq-dl-label"><?php esc_html_e( 'Captured', 'aisooq-connector' ); ?></span><?php echo esc_html( $cap ); ?></div>
				<div><span class="aisooq-dl-label"><?php esc_html_e( 'Last activity', 'aisooq-connector' ); ?></span><?php echo esc_html( $seen ); ?></div>
				<?php if ( 'converted' === $row->status && $row->wc_order_id ) : ?>
					<div><span class="aisooq-dl-label"><?php esc_html_e( 'Order', 'aisooq-connector' ); ?></span><a href="<?php echo esc_url( $this->order_edit_url( $row->wc_order_id ) ); ?>">#<?php echo (int) $row->wc_order_id; ?></a></div>
				<?php endif; ?>
			</div>
			<div class="aisooq-dl-foot aisooq-dim"><?php echo esc_html( __( 'Cart ref', 'aisooq-connector' ) . ': ' . $row->session_key ); ?></div>
		</div>
		<?php
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	public function ajax_resync() {
		$this->guard_ajax( true );
		if ( ! $this->settings->get( 'enable_abandoned' ) ) {
			wp_send_json_error( array( 'message' => __( 'Abandoned-cart sync is turned off in settings.', 'aisooq-connector' ) ) );
		}
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'one';
		if ( 'all' === $scope ) {
			$sent = $this->abandoned->resync_pending( self::PER_PAGE );
			wp_send_json_success( array(
				'sent'    => $sent,
				/* translators: %d: number of carts */
				'message' => sprintf( _n( 'Resynced %d cart.', 'Resynced %d carts.', $sent, 'aisooq-connector' ), $sent ),
			) );
		}
		$key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing cart reference.', 'aisooq-connector' ) ) );
		}
		$ok = $this->abandoned->resync( $key );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => __( 'Resynced', 'aisooq-connector' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Could not resync — no contact captured, or the API rejected it. Check the logs.', 'aisooq-connector' ) ) );
	}

	// ── Page ────────────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$f = array(
			'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active', // phpcs:ignore WordPress.Security.NonceVerification
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'product' => isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'from'    => isset( $_GET['from'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'to'      => isset( $_GET['to'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		);
		$allowed = array( 'all', 'active', 'pending', 'recovered', 'cancelled', 'fake' );
		if ( ! in_array( $f['status'], $allowed, true ) ) {
			$f['status'] = 'active';
		}

		if ( ! $this->table_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Abandoned carts', 'aisooq-connector' ) . '</h1>';
			echo '<p>' . esc_html__( 'No capture table yet. Enable "Abandoned carts" in AI Sooq settings, then re-save to create it.', 'aisooq-connector' ) . '</p></div>';
			return;
		}

		$k        = $this->stats();
		$rows     = $this->rows( $f );
		$products = $this->product_options();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BDT';
		$active   = $this->settings->is_active() && $this->settings->get( 'enable_abandoned' );
		$max_step = 0;
		foreach ( $k['funnel'] as $ff ) {
			$max_step = max( $max_step, (int) $ff['n'] );
		}
		?>
		<div class="wrap aisooq-ab">
			<style>
				.aisooq-ab{--pri:#2271b1;--ok:#00844a;--warn:#996800;--err:#b32d2e;--info:#1d6ad4;--bd:#dcdcde;--muted:#646970}
				.aisooq-ab .aisooq-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0 4px}
				.aisooq-ab h1{margin:0;font-size:22px}
				.aisooq-ab .aisooq-sub{color:var(--muted);font-size:13px;margin:2px 0 0}
				.aisooq-dim{color:var(--muted)}.aisooq-nowrap{white-space:nowrap}
				.aisooq-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin:16px 0 18px}
				.aisooq-kpi{background:#fff;border:1px solid var(--bd);border-left:3px solid var(--pri);border-radius:10px;padding:13px 15px;display:flex;flex-direction:column;min-height:88px}
				.aisooq-kpi.ok{border-left-color:var(--ok)}.aisooq-kpi.warn{border-left-color:var(--warn)}.aisooq-kpi.muted{border-left-color:var(--muted)}.aisooq-kpi.info{border-left-color:var(--info)}.aisooq-kpi.err{border-left-color:var(--err)}
				.aisooq-kpi__label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
				.aisooq-kpi__num{font-size:26px;font-weight:700;line-height:1.1;margin-top:auto;padding-top:8px;font-variant-numeric:tabular-nums;color:#1d2327}
				.aisooq-kpi__sub{font-size:12px;color:var(--muted);margin-top:3px;min-height:15px}
				.aisooq-panel{background:#fff;border:1px solid var(--bd);border-radius:10px;margin:0 0 18px;overflow:hidden}
				.aisooq-panel__head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 15px;border-bottom:1px solid var(--bd);font-weight:600;flex-wrap:wrap}
				.aisooq-panel__body{padding:14px}
				.aisooq-funnel{display:flex;flex-direction:column;gap:8px}
				.aisooq-funnel__row{display:grid;grid-template-columns:120px 1fr 48px;align-items:center;gap:10px;font-size:13px}
				.aisooq-funnel__bar{height:10px;border-radius:999px;background:linear-gradient(90deg,#2271b1,#4a9fe0);min-width:3px}
				.aisooq-funnel__track{background:#f0f0f1;border-radius:999px;overflow:hidden}
				.aisooq-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 14px;border-bottom:1px solid var(--bd);background:#fbfbfc}
				.aisooq-toolbar label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:3px}
				.aisooq-toolbar input,.aisooq-toolbar select{min-height:30px}
				.aisooq-bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 14px;border-bottom:1px solid var(--bd);background:#fff}
				.aisooq-cb-cell{width:28px;text-align:center}
				.aisooq-filters{display:flex;gap:6px;flex-wrap:wrap}
				.aisooq-filters a{text-decoration:none;font-size:13px;padding:4px 10px;border:1px solid var(--bd);border-radius:999px;color:#1d2327;background:#fff}
				.aisooq-filters a.on{background:var(--pri);border-color:var(--pri);color:#fff}
				.aisooq-tbl{width:100%;border-collapse:collapse;background:#fff}
				.aisooq-tbl th,.aisooq-tbl td{text-align:left;padding:10px 12px;border-bottom:1px solid #f0f0f1;font-size:13px;vertical-align:top}
				.aisooq-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);background:#fbfbfc;position:sticky;top:0}
				.aisooq-tbl tbody tr{transition:background .12s}
				.aisooq-tbl tbody tr:hover{background:#f7f9fb}
				.aisooq-tbl td .aisooq-td-val{display:block}
				.aisooq-badge{display:inline-flex;align-items:center;gap:6px;font-weight:600;padding:3px 9px;border-radius:999px;font-size:12px}
				.aisooq-badge::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor}
				.aisooq-badge.ok{background:#edfaef;color:var(--ok)}.aisooq-badge.warn{background:#fcf5e6;color:var(--warn)}.aisooq-badge.err{background:#fcebea;color:var(--err)}.aisooq-badge.info{background:#e9f2fd;color:var(--info)}.aisooq-badge.muted{background:#f0f0f1;color:var(--muted)}
				.aisooq-ratio{display:inline-flex;align-items:center;gap:5px;font-weight:600;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid}
				.aisooq-ratio.g{background:#edfaef;color:var(--ok);border-color:#00844a33}.aisooq-ratio.a{background:#fcf5e6;color:var(--warn);border-color:#99680033}.aisooq-ratio.r{background:#fcebea;color:var(--err);border-color:#b32d2e33}
				.aisooq-cust{font-weight:600}
				.aisooq-contact{color:var(--muted);font-size:12px;margin-top:2px;line-height:1.5}
				.aisooq-courier{margin-top:4px}
				.aisooq-mono{font-variant-numeric:tabular-nums}
				.aisooq-actions-cell{text-align:right;white-space:nowrap}
				.aisooq-menu-wrap{position:relative;display:inline-block}
				.aisooq-menu-btn .aisooq-caret{font-size:10px;opacity:.7}
				.aisooq-menu{position:absolute;right:0;top:calc(100% + 4px);z-index:50;min-width:180px;background:#fff;border:1px solid var(--bd);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.14);padding:5px;display:flex;flex-direction:column;gap:1px}
				.aisooq-menu[hidden]{display:none}
				.aisooq-menu .aisooq-act{display:flex;align-items:center;gap:8px;width:100%;text-align:left;background:none;border:0;border-radius:5px;padding:7px 9px;font-size:13px;color:#1d2327;cursor:pointer}
				.aisooq-menu .aisooq-act:hover{background:#f0f6fc}
				.aisooq-menu .aisooq-act[disabled]{opacity:.4;cursor:not-allowed}
				.aisooq-menu .aisooq-act .dashicons{font-size:16px;width:16px;height:16px;color:var(--muted)}
				.aisooq-menu .aisooq-primary{color:var(--pri);font-weight:600}.aisooq-menu .aisooq-primary .dashicons{color:var(--pri)}
				.aisooq-menu .aisooq-danger{color:var(--err)}.aisooq-menu .aisooq-danger .dashicons{color:var(--err)}
				.aisooq-empty{padding:40px 16px;text-align:center;color:var(--muted)}
				.aisooq-ab .aisooq-msg{font-size:12px}
				.aisooq-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)}
				.aisooq-modal{background:#fff;border-radius:12px;max-width:640px;width:100%;max-height:88vh;overflow:auto;padding:22px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)}
				.aisooq-modal .aisooq-x{position:absolute;top:12px;right:14px;cursor:pointer;font-size:22px;line-height:1;color:var(--muted);background:none;border:0}
				.aisooq-dl h3{margin:0;font-size:17px}
				.aisooq-dl-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #f0f0f1}
				.aisooq-dl-sub{color:var(--muted);font-size:12px;margin-top:3px;font-variant-numeric:tabular-nums}
				.aisooq-dl-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 14px}
				.aisooq-dl-sec{min-width:0}
				.aisooq-dl-sec>div{margin-top:3px;font-size:13px;word-break:break-word}
				.aisooq-dl-sec .dashicons{font-size:15px;width:15px;height:15px;color:var(--muted);vertical-align:-3px}
				.aisooq-dl-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);display:block;margin-bottom:2px}
				.aisooq-dl-cust{margin-top:6px !important;padding-top:6px;border-top:1px dashed #e5e5e5}
				.aisooq-dl-cart{margin:6px 0 4px}
				.aisooq-dl-cart td{padding:7px 8px;vertical-align:middle}
				.aisooq-dl-cart tfoot td{border-top:1px solid var(--bd);border-bottom:0}
				.aisooq-dl-thumb{width:48px}
				.aisooq-thumb{width:40px;height:40px;object-fit:cover;border-radius:8px;display:block;background:#f0f0f1}
				.aisooq-thumb--ph{border:1px solid var(--bd)}
				.aisooq-dl-meta{grid-template-columns:repeat(2,1fr);gap:10px 14px;background:#fbfbfc;border:1px solid var(--bd);border-radius:8px;padding:12px 14px;margin-top:6px;font-size:13px}
				.aisooq-dl-meta>div{min-width:0}
				.aisooq-dl-foot{margin-top:12px;font-size:11px;word-break:break-all}
				@media(max-width:560px){.aisooq-dl-grid,.aisooq-dl-meta{grid-template-columns:1fr}}
				.aisooq-contact .dashicons{font-size:13px;width:13px;height:13px;vertical-align:-2px;opacity:.7}
				/* Accessibility + interaction polish (data-dense dashboard). */
				.aisooq-ab .aisooq-filters a,.aisooq-ab .aisooq-menu-btn,.aisooq-ab .aisooq-act,.aisooq-ab .aisooq-cb,.aisooq-ab .button,.aisooq-ab .aisooq-check-courier{cursor:pointer}
				.aisooq-ab a:focus-visible,.aisooq-ab button:focus-visible,.aisooq-ab input:focus-visible,.aisooq-ab select:focus-visible,.aisooq-ab .aisooq-filters a:focus-visible{outline:2px solid var(--pri);outline-offset:1px;border-radius:4px}
				.aisooq-ab .aisooq-cb:focus-visible{outline:2px solid var(--pri);outline-offset:2px}
				@media(prefers-reduced-motion:reduce){.aisooq-ab *{transition:none !important;animation:none !important}}
				@media(max-width:860px){
					.aisooq-funnel__row{grid-template-columns:90px 1fr 40px}
					.aisooq-toolbar{gap:8px}.aisooq-toolbar>div{flex:1 1 140px}.aisooq-toolbar input,.aisooq-toolbar select{width:100%}
					.aisooq-tbl thead{display:none}
					.aisooq-tbl,.aisooq-tbl tbody{display:block;width:100%}
					.aisooq-tbl tr{display:block;border:1px solid var(--bd);border-radius:8px;margin:0 0 10px;padding:8px 10px}
					.aisooq-tbl td{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border:0;padding:6px 0;text-align:right}
					.aisooq-tbl td::before{content:attr(data-label);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);text-align:left;flex:0 0 auto}
					.aisooq-tbl td .aisooq-td-val{text-align:right}
					.aisooq-tbl td.aisooq-cb-cell{justify-content:flex-start}.aisooq-tbl td.aisooq-cb-cell::before{content:none}
					.aisooq-tbl td.aisooq-actions-cell{justify-content:flex-end}.aisooq-tbl td.aisooq-actions-cell::before{content:none}
					.aisooq-menu{right:0;left:auto}
				}
				@media(max-width:480px){.aisooq-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
			</style>

			<div class="aisooq-top">
				<div>
					<h1><?php esc_html_e( 'Abandoned carts', 'aisooq-connector' ); ?></h1>
					<p class="aisooq-sub"><?php esc_html_e( 'Incomplete orders captured on this store and mirrored to AI Sooq. Convert to a WooCommerce order, check courier ratio, or dispose locally — cancel / fake / delete stay on this site.', 'aisooq-connector' ); ?></p>
				</div>
				<div>
					<button type="button" id="aisooq-resync-all" class="button button-primary" <?php disabled( ! $active || $k['pending'] < 1 ); ?>>
						<?php
						/* translators: %d: number of carts awaiting push */
						echo esc_html( sprintf( __( 'Resync all pending (%d)', 'aisooq-connector' ), $k['pending'] ) );
						?>
					</button>
					<span id="aisooq-resync-msg" class="aisooq-msg" style="margin-left:8px;" role="status" aria-live="polite"></span>
				</div>
			</div>

			<?php if ( ! $active ) : ?>
				<div class="notice notice-warning inline" style="margin:8px 0;"><p><?php esc_html_e( 'Abandoned-cart sync is paused or disabled. Resync + courier check need an active connection; the rest of the worklist still works.', 'aisooq-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="aisooq-kpis">
				<div class="aisooq-kpi"><div class="aisooq-kpi__label"><?php esc_html_e( 'Total', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['total'] ) ); ?></div></div>
				<div class="aisooq-kpi warn"><div class="aisooq-kpi__label"><?php esc_html_e( 'Open', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['open'] ) ); ?></div><div class="aisooq-kpi__sub"><?php esc_html_e( 'incomplete orders', 'aisooq-connector' ); ?></div></div>
				<div class="aisooq-kpi info"><div class="aisooq-kpi__label"><?php esc_html_e( 'Pushed', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['pushed'] ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( '%s pending', 'aisooq-connector' ), number_format_i18n( $k['pending'] ) ) ); ?></div></div>
				<div class="aisooq-kpi ok"><div class="aisooq-kpi__label"><?php esc_html_e( 'Recovered', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['recovered'] ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( '%s rate', 'aisooq-connector' ), number_format_i18n( $k['recovery_rate'] * 100, 1 ) . '%' ) ); ?></div></div>
				<div class="aisooq-kpi err"><div class="aisooq-kpi__label"><?php esc_html_e( 'Cancelled / Fake', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['cancelled'] + $k['fake'] ) ); ?></div></div>
				<div class="aisooq-kpi"><div class="aisooq-kpi__label"><?php esc_html_e( 'Open value', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num aisooq-mono"><?php echo esc_html( $this->money( $k['open_value'], $currency ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( 'avg %s', 'aisooq-connector' ), $this->money( $k['avg_open'], $currency ) ) ); ?></div></div>
			</div>

			<?php if ( ! empty( $k['funnel'] ) ) : ?>
			<div class="aisooq-panel">
				<div class="aisooq-panel__head"><?php esc_html_e( 'Where open carts drop off', 'aisooq-connector' ); ?></div>
				<div class="aisooq-panel__body">
					<div class="aisooq-funnel">
						<?php foreach ( $k['funnel'] as $ff ) : $n = (int) $ff['n']; $pct = $max_step > 0 ? round( $n / $max_step * 100 ) : 0; ?>
							<div class="aisooq-funnel__row">
								<span><?php echo esc_html( ucfirst( (string) $ff['step'] ) ); ?></span>
								<span class="aisooq-funnel__track"><span class="aisooq-funnel__bar" style="width:<?php echo esc_attr( max( 3, $pct ) ); ?>%"></span></span>
								<span class="aisooq-mono" style="text-align:right;"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<div class="aisooq-panel">
				<div class="aisooq-panel__head">
					<span class="aisooq-filters">
						<?php
						$labels = array(
							'active'      => __( 'Active', 'aisooq-connector' ),
							'pending'     => __( 'Pending', 'aisooq-connector' ),
							'recovered'   => __( 'Recovered', 'aisooq-connector' ),
							'cancelled'   => __( 'Cancelled', 'aisooq-connector' ),
							'fake'        => __( 'Fake', 'aisooq-connector' ),
							'all'         => __( 'All', 'aisooq-connector' ),
						);
						foreach ( $labels as $key => $label ) :
							$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'status' => $key ), admin_url( 'admin.php' ) );
							?>
							<a class="<?php echo $f['status'] === $key ? 'on' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</span>
					<span id="aisooq-count" class="aisooq-dim" aria-live="polite"></span>
				</div>

				<div class="aisooq-toolbar">
					<div><label for="aisooq-search"><?php esc_html_e( 'Search', 'aisooq-connector' ); ?></label>
						<input type="search" id="aisooq-search" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'name, phone, email, product…', 'aisooq-connector' ); ?>" style="min-width:220px;" /></div>
					<div><label for="aisooq-product"><?php esc_html_e( 'Product', 'aisooq-connector' ); ?></label>
						<select id="aisooq-product">
							<option value="0"><?php esc_html_e( 'Any product', 'aisooq-connector' ); ?></option>
							<?php foreach ( $products as $pid => $label ) : ?>
								<option value="<?php echo (int) $pid; ?>" <?php selected( $f['product'], $pid ); ?>><?php echo esc_html( wp_html_excerpt( $label, 48, '…' ) ); ?></option>
							<?php endforeach; ?>
						</select></div>
					<div><label for="aisooq-from"><?php esc_html_e( 'From', 'aisooq-connector' ); ?></label>
						<input type="date" id="aisooq-from" value="<?php echo esc_attr( $f['from'] ); ?>" /></div>
					<div><label for="aisooq-to"><?php esc_html_e( 'To', 'aisooq-connector' ); ?></label>
						<input type="date" id="aisooq-to" value="<?php echo esc_attr( $f['to'] ); ?>" /></div>
					<div><button type="button" class="button" id="aisooq-clear"><?php esc_html_e( 'Clear', 'aisooq-connector' ); ?></button></div>
					<div><span class="spinner" id="aisooq-spin" style="float:none;margin:0;"></span></div>
				</div>

				<div class="aisooq-bulkbar">
					<select id="aisooq-bulk-op">
						<option value=""><?php esc_html_e( 'Bulk actions', 'aisooq-connector' ); ?></option>
						<option value="resync"><?php esc_html_e( 'Resync', 'aisooq-connector' ); ?></option>
						<option value="convert"><?php esc_html_e( 'Convert to order', 'aisooq-connector' ); ?></option>
						<option value="cancel"><?php esc_html_e( 'Cancel', 'aisooq-connector' ); ?></option>
						<option value="fake"><?php esc_html_e( 'Mark fake', 'aisooq-connector' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'aisooq-connector' ); ?></option>
					</select>
					<button type="button" class="button" id="aisooq-bulk-apply"><?php esc_html_e( 'Apply', 'aisooq-connector' ); ?></button>
					<span id="aisooq-bulk-count" class="aisooq-dim"></span>
					<span id="aisooq-bulk-msg" class="aisooq-msg" role="status" aria-live="polite"></span>
				</div>

				<div style="overflow-x:auto;">
					<table class="aisooq-tbl">
						<thead>
							<tr>
								<th class="aisooq-cb-cell"><input type="checkbox" id="aisooq-cb-all" aria-label="<?php esc_attr_e( 'Select all', 'aisooq-connector' ); ?>" /></th>
								<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Address', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Cart', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Step', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Status', 'aisooq-connector' ); ?></th>
								<th><?php esc_html_e( 'Updated', 'aisooq-connector' ); ?></th>
								<th style="text-align:right;"><?php esc_html_e( 'Actions', 'aisooq-connector' ); ?></th>
							</tr>
						</thead>
						<tbody id="aisooq-rows">
							<?php echo $this->render_rows( $rows, $currency, $active ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</tbody>
					</table>
				</div>
			</div>

			<div id="aisooq-modal-root"></div>
		</div>

		<script>
		( function () {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var strings = <?php echo wp_json_encode( array(
				'syncing'    => __( 'Resyncing…', 'aisooq-connector' ),
				'failed'     => __( 'Failed', 'aisooq-connector' ),
				'confirmDel' => __( 'Delete this cart from the worklist? (Does not affect the platform.)', 'aisooq-connector' ),
				'confirmFake'=> __( 'Mark this cart as fake?', 'aisooq-connector' ),
				'working'    => __( 'Working…', 'aisooq-connector' ),
				'count'      => __( '%d shown', 'aisooq-connector' ),
				'selected'   => __( '%d selected', 'aisooq-connector' ),
				'pickOp'     => __( 'Choose a bulk action first.', 'aisooq-connector' ),
				'pickRows'   => __( 'Select at least one cart.', 'aisooq-connector' ),
				'confirmBulk'=> __( 'Apply "%1$s" to %2$d selected cart(s)?', 'aisooq-connector' ),
				'detailsTitle'=> __( 'Cart details', 'aisooq-connector' ),
				'close'      => __( 'Close', 'aisooq-connector' ),
				'noRatio'    => __( 'No data', 'aisooq-connector' ),
				'noRatioHint'=> __( 'No BDCourier history for this number, or BDCourier is not configured for this store on the platform.', 'aisooq-connector' ),
			) ); ?>;
			function post( data ) {
				data.append( 'nonce', nonce );
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) { return r.json(); } );
			}
			function fd( action, extra ) {
				var d = new FormData();
				d.append( 'action', action );
				for ( var k in ( extra || {} ) ) { d.append( k, extra[ k ] ); }
				return d;
			}

			// ── AJAX search + filters ──────────────────────────────────────
			var search = document.getElementById( 'aisooq-search' );
			var product = document.getElementById( 'aisooq-product' );
			var from = document.getElementById( 'aisooq-from' );
			var to = document.getElementById( 'aisooq-to' );
			var spin = document.getElementById( 'aisooq-spin' );
			var rowsBody = document.getElementById( 'aisooq-rows' );
			var countEl = document.getElementById( 'aisooq-count' );
			var cbAll = document.getElementById( 'aisooq-cb-all' );
				var bulkCount = document.getElementById( 'aisooq-bulk-count' );
				var statusFilter = <?php echo wp_json_encode( $f['status'] ); ?>;
				function selectedKeys() { return Array.prototype.map.call( rowsBody.querySelectorAll( '.aisooq-cb:checked' ), function ( c ) { return c.value; } ); }
				function updateBulkCount() { if ( bulkCount ) { bulkCount.textContent = strings.selected.replace( '%d', selectedKeys().length ); } }
			var t = null;
			function runQuery() {
				spin.classList.add( 'is-active' );
				post( fd( 'aisooq_abandoned_query', {
					status: statusFilter,
					search: search.value,
					product: product.value,
					from: from.value,
					to: to.value
				} ) ).then( function ( j ) {
					spin.classList.remove( 'is-active' );
					if ( j && j.success ) {
						rowsBody.innerHTML = j.data.html;
						countEl.textContent = strings.count.replace( '%d', j.data.count );
						if ( cbAll ) { cbAll.checked = false; }
						bindRows();
						updateBulkCount();
					}
				} ).catch( function () { spin.classList.remove( 'is-active' ); } );
			}
			function debounced() { clearTimeout( t ); t = setTimeout( runQuery, 300 ); }
			if ( search ) { search.addEventListener( 'input', debounced ); }
			[ product, from, to ].forEach( function ( el ) { if ( el ) { el.addEventListener( 'change', runQuery ); } } );
			var clear = document.getElementById( 'aisooq-clear' );
			if ( clear ) { clear.addEventListener( 'click', function () { search.value=''; product.value='0'; from.value=''; to.value=''; runQuery(); } ); }

			// ── Row actions (delegated) ────────────────────────────────────
			function closeMenus( except ) {
					Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-menu' ), function ( m ) {
						if ( m !== except ) { m.hidden = true; var b = m.parentNode.querySelector( '.aisooq-menu-btn' ); if ( b ) { b.setAttribute( 'aria-expanded', 'false' ); } }
					} );
				}
				function bindRows() {
					Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-cb' ), function ( c ) { c.addEventListener( 'change', updateBulkCount ); } );
					Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-menu-btn' ), function ( btn ) {
						btn.addEventListener( 'click', function ( e ) {
							e.stopPropagation();
							var menu = btn.parentNode.querySelector( '.aisooq-menu' );
							var willOpen = menu.hidden;
							closeMenus( menu );
							menu.hidden = ! willOpen;
							btn.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
						} );
					} );
				Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-check-courier' ), function ( btn ) {
					btn.addEventListener( 'click', function () {
						var wrap = btn.closest( '.aisooq-courier' );
						var phone = wrap ? wrap.getAttribute( 'data-phone' ) : '';
						btn.disabled = true; btn.textContent = '…';
						post( fd( 'aisooq_abandoned_courier', { phone: phone } ) ).then( function ( j ) {
							if ( j && j.success && j.data.successRatio !== null && j.data.successRatio !== undefined ) {
								var r = Math.round( j.data.successRatio );
								var cls = r >= 80 ? 'g' : ( r >= 60 ? 'a' : 'r' );
								wrap.innerHTML = '<span class="aisooq-ratio ' + cls + '">' + r + '%' + ( j.data.totalParcel != null ? ' · ' + j.data.totalParcel : '' ) + '</span>';
							} else if ( j && j.success ) {
								// Reached the platform, but it has no ratio (unknown number, or
								// BDCourier isn't set up for this store there — the key lives on
								// the platform, not the plugin).
								wrap.innerHTML = '<span class="aisooq-dim" title="' + strings.noRatioHint + '">' + strings.noRatio + '</span>';
							} else {
								btn.disabled = false; btn.innerHTML = '<span class="dashicons dashicons-search"></span> ' + ( ( j && j.data && j.data.message ) ? j.data.message : strings.failed );
							}
						} ).catch( function () { btn.disabled = false; btn.textContent = strings.failed; } );
					} );
				} );
				Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-act' ), function ( btn ) {
					btn.addEventListener( 'click', function () {
						var tr = btn.closest( 'tr' );
						var key = tr ? tr.getAttribute( 'data-key' ) : '';
						var op = btn.getAttribute( 'data-op' );
						if ( op === 'details' ) { closeMenus( null ); openDetails( key ); return; }
						if ( op === 'delete' && ! window.confirm( strings.confirmDel ) ) { return; }
						if ( op === 'fake' && ! window.confirm( strings.confirmFake ) ) { return; }
						if ( op === 'resync' ) {
							btn.disabled = true; var orig = btn.textContent; btn.textContent = strings.syncing;
							post( fd( 'aisooq_abandoned_resync', { scope: 'one', session_key: key } ) ).then( function ( j ) {
								btn.textContent = ( j && j.success ) ? ( '✓ ' + ( j.data.message || '' ) ) : ( ( j && j.data && j.data.message ) || strings.failed );
								if ( ! ( j && j.success ) ) { btn.disabled = false; btn.textContent = orig; alert( ( j && j.data && j.data.message ) || strings.failed ); }
							} ).catch( function () { btn.disabled = false; btn.textContent = orig; } );
							return;
						}
						btn.disabled = true; var original = btn.textContent; btn.textContent = strings.working;
						post( fd( 'aisooq_abandoned_action', { op: op, session_key: key } ) ).then( function ( j ) {
							if ( j && j.success ) {
								if ( j.data.removeRow && tr ) { tr.parentNode.removeChild( tr ); return; }
								if ( j.data.orderUrl ) { window.location = j.data.orderUrl; return; }
								if ( j.data.reload ) { runQuery(); return; }
							} else {
								btn.disabled = false; btn.textContent = original;
								alert( ( j && j.data && j.data.message ) || strings.failed );
							}
						} ).catch( function () { btn.disabled = false; btn.textContent = original; alert( strings.failed ); } );
					} );
				} );
			}
			function openDetails( key ) {
				var root = document.getElementById( 'aisooq-modal-root' );
				var xBtn = '<button class="aisooq-x" aria-label="' + strings.close + '">×</button>';
				root.innerHTML = '<div class="aisooq-modal-bg"><div class="aisooq-modal" role="dialog" aria-modal="true" aria-label="' + strings.detailsTitle + '">' + xBtn + '<p>' + strings.working + '</p></div></div>';
				var bg = root.querySelector( '.aisooq-modal-bg' );
				function onKey( e ) { if ( e.key === 'Escape' ) { close(); } }
				function close() { root.innerHTML = ''; document.removeEventListener( 'keydown', onKey ); }
				document.addEventListener( 'keydown', onKey );
				bg.addEventListener( 'click', function ( e ) { if ( e.target === bg ) { close(); } } );
				function bindX() { var x = root.querySelector( '.aisooq-x' ); if ( x ) { x.addEventListener( 'click', close ); x.focus(); } }
				bindX();
				post( fd( 'aisooq_abandoned_details', { session_key: key } ) ).then( function ( j ) {
					var box = root.querySelector( '.aisooq-modal' );
					if ( ! box ) { return; }
					box.innerHTML = xBtn + ( ( j && j.success ) ? j.data.html : '<p>' + ( ( j && j.data && j.data.message ) || strings.failed ) + '</p>' );
					bindX();
				} );
			}
			bindRows();

			// ── Resync all ─────────────────────────────────────────────────
			// Close any open row-action menu on an outside click.
				document.addEventListener( 'click', function () { closeMenus( null ); } );

				// ── Select-all + bulk actions ──────────────────────────────────
				if ( cbAll ) {
					cbAll.addEventListener( 'change', function () {
						Array.prototype.forEach.call( rowsBody.querySelectorAll( '.aisooq-cb' ), function ( c ) { c.checked = cbAll.checked; } );
						updateBulkCount();
					} );
				}
				var bulkOp = document.getElementById( 'aisooq-bulk-op' );
				var bulkApply = document.getElementById( 'aisooq-bulk-apply' );
				var bulkMsg = document.getElementById( 'aisooq-bulk-msg' );
				if ( bulkApply ) {
					bulkApply.addEventListener( 'click', function () {
						var op = bulkOp.value;
						if ( ! op ) { window.alert( strings.pickOp ); return; }
						var keys = selectedKeys();
						if ( ! keys.length ) { window.alert( strings.pickRows ); return; }
						var label = bulkOp.options[ bulkOp.selectedIndex ].text;
						if ( ! window.confirm( strings.confirmBulk.replace( '%1$s', label ).replace( '%2$d', keys.length ) ) ) { return; }
						bulkApply.disabled = true; bulkMsg.textContent = strings.working; bulkMsg.style.color = '#555';
						var d = fd( 'aisooq_abandoned_bulk', { op: op } );
						keys.forEach( function ( k ) { d.append( 'keys[]', k ); } );
						post( d ).then( function ( j ) {
							bulkApply.disabled = false;
							bulkMsg.textContent = ( j && j.data && j.data.message ) ? j.data.message : strings.failed;
							bulkMsg.style.color = ( j && j.success ) ? '#146c43' : '#b32d2e';
							if ( j && j.success ) { bulkOp.value = ''; runQuery(); }
						} ).catch( function () { bulkApply.disabled = false; bulkMsg.textContent = strings.failed; bulkMsg.style.color = '#b32d2e'; } );
					} );
				}
				updateBulkCount();

				var all = document.getElementById( 'aisooq-resync-all' );
			var msg = document.getElementById( 'aisooq-resync-msg' );
			if ( all ) {
				all.addEventListener( 'click', function () {
					all.disabled = true; msg.textContent = strings.syncing; msg.style.color = '#555';
					post( fd( 'aisooq_abandoned_resync', { scope: 'all' } ) ).then( function ( j ) {
						msg.textContent = ( j && j.data && j.data.message ) ? j.data.message : strings.failed;
						msg.style.color = ( j && j.success ) ? '#146c43' : '#b32d2e';
						if ( j && j.success ) { setTimeout( function () { location.reload(); }, 900 ); }
					} ).catch( function () { all.disabled = false; msg.textContent = strings.failed; msg.style.color = '#b32d2e'; } );
				} );
			}
		} )();
		</script>
		<?php
	}
}
