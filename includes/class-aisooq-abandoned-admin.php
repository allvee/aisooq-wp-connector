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

	/** Cached headline aggregates for the worklist screen. */
	const STATS_TRANSIENT = 'aisooq_ab_stats';
	const STATS_TTL       = 120;

	/** Cached product filter options. */
	const PRODUCTS_TRANSIENT = 'aisooq_ab_products';
	const PRODUCTS_TTL       = 600;
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
		// These are two unindexed aggregates over the WHOLE table, run before a
		// single row of the worklist is drawn. On a busy store the table grows
		// with traffic, so the screen got slower the more successful the shop
		// was. Cache briefly and invalidate on any write, so the numbers stay
		// honest without being recomputed on every page view and every filter.
		$cached = get_transient( self::STATS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

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
			 FROM {$t} WHERE status = 'active' AND {$reachable} GROUP BY step",
			ARRAY_A
		);
		// Sort by the order a shopper actually moves through checkout, not by
		// size. Ordered by count it is not a funnel at all — it was rendering
		// "Address, Payment, Contact, Review", which is nobody's journey and is
		// why the panel read as meaningless.
		$funnel = self::order_funnel( $funnel );

		$total     = (int) ( $row['total'] ?? 0 );
		$recovered = (int) ( $row['recovered'] ?? 0 );
		$open      = (int) ( $row['open_count'] ?? 0 );
		$open_val  = (float) ( $row['open_value'] ?? 0 );

		$stats = array(
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
		set_transient( self::STATS_TRANSIENT, $stats, self::STATS_TTL );
		return $stats;
	}

	/**
	 * Drop the cached aggregates. Called after anything that changes a row, so
	 * an operator never acts on a number their own click just invalidated.
	 */
	public static function flush_stats_cache() {
		delete_transient( self::STATS_TRANSIENT );
		delete_transient( self::PRODUCTS_TRANSIENT );
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
		// Building this dropdown reads a thousand longtext blobs off disk and
		// JSON-decodes every one of them, on every render of the page, purely to
		// populate a filter most visits never touch. Cached for the same reason
		// as stats(), and invalidated by the same writes.
		$cached = get_transient( self::PRODUCTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

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
		set_transient( self::PRODUCTS_TRANSIENT, $out, self::PRODUCTS_TTL );
		return $out;
	}

	/** Currency-format a number using the store's WooCommerce currency symbol. */
	/**
	 * Checkout steps, in the order a shopper meets them.
	 *
	 * `furthest_step` records the last one a cart got to, so read down the list
	 * to see how far people get before they stop.
	 */
	public static function funnel_steps() {
		return array(
			'contact' => array(
				'label' => __( 'Stopped at contact', 'aisooq-connector' ),
				'hint'  => __( 'Started checkout, nothing else filled in', 'aisooq-connector' ),
			),
			'address' => array(
				'label' => __( 'Stopped at address', 'aisooq-connector' ),
				'hint'  => __( 'Knows where it should go — worth a call', 'aisooq-connector' ),
			),
			'payment' => array(
				'label' => __( 'Stopped at payment', 'aisooq-connector' ),
				'hint'  => __( 'One tap from ordering', 'aisooq-connector' ),
			),
			'review'  => array(
				'label' => __( 'Stopped at review', 'aisooq-connector' ),
				'hint'  => __( 'Everything filled in and still stopped', 'aisooq-connector' ),
			),
			'unknown' => array(
				'label' => __( 'Never checked out', 'aisooq-connector' ),
				'hint'  => __( 'Cart built, checkout never opened', 'aisooq-connector' ),
			),
		);
	}

	/** Reorder a funnel result set into checkout order. */
	public static function order_funnel( $rows ) {
		$rows  = is_array( $rows ) ? $rows : array();
		$order = array_keys( self::funnel_steps() );
		$by    = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['step'] ] = $r;
		}
		$out = array();
		foreach ( $order as $step ) {
			if ( isset( $by[ $step ] ) ) {
				$out[] = $by[ $step ];
				unset( $by[ $step ] );
			}
		}
		// Anything the plugin does not know about keeps its place at the end
		// rather than being dropped — a future checkout step must not vanish.
		return array_merge( $out, array_values( $by ) );
	}

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

	/** Tone class for a delivery-success percent, matching the platform's bands. */
	private function ratio_tone( $pct ) {
		return $pct >= 80 ? 'g' : ( $pct >= 60 ? 'a' : 'r' );
	}

	/**
	 * The courier cell for one cart: either an unchecked "Check ratio" button,
	 * or the saved result — headline badge, per-courier breakdown, and when it
	 * was last checked.
	 *
	 * Rendered here rather than in JS so that the cell an operator sees the
	 * instant a check returns is byte-identical to the one they see after a
	 * reload or a filter change. {@see ajax_courier()} returns this same markup.
	 *
	 * @param object $row
	 * @param bool   $active Connection is live (a lookup can actually be made).
	 * @return string
	 */
	private function courier_cell( $row, $active ) {
		if ( empty( $row->phone ) ) {
			return '';
		}
		$snap = $this->abandoned->courier_snapshot( $row );

		// A disabled button with no explanation on it reads as broken. The
		// lookup needs a live platform connection, so when the connection is
		// paused the control says so on itself rather than relying on the
		// operator connecting it to the notice at the top of the screen.
		$why = $active
			? __( 'Look up this number\'s courier delivery history (BDCourier, via the platform)', 'aisooq-connector' )
			: __( 'Connection is paused — activate it in AI Sooq → Settings to check courier history', 'aisooq-connector' );

		ob_start();
		?>
		<div class="aisooq-courier" data-phone="<?php echo esc_attr( $row->phone ); ?>">
			<?php if ( null === $snap ) : ?>
				<button type="button" class="button-link aisooq-check-courier" title="<?php echo esc_attr( $why ); ?>" <?php disabled( ! $active ); ?>><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Check ratio', 'aisooq-connector' ); ?></button>
				<?php if ( ! $active ) : ?>
					<span class="aisooq-courier-why"><?php esc_html_e( 'connection paused', 'aisooq-connector' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<?php
				$checked = $snap['checked_at'] ? human_time_diff( strtotime( $snap['checked_at'] . ' UTC' ) ) : '';
				$rows    = $snap['couriers'];
				?>
				<?php
				/*
				 * Same shape as the orders list, deliberately: an operator moves
				 * between the two screens all day and a delivery ratio that is a
				 * pill here and a segmented bar there reads as two different
				 * measurements. The bar is rendered by the orders-list class so
				 * there is one implementation of the green/red split, not two
				 * that drift.
				 */
				?>
				<div class="aisooq-courier-head">
					<?php if ( null !== $snap['parcels'] || null !== $snap['success'] || null !== $snap['cancelled'] ) : ?>
						<span class="aisooq-courier-counts">
							<?php if ( null !== $snap['parcels'] ) : ?>
								<span class="aisooq-pill total" title="<?php esc_attr_e( 'Parcels this number has been sent, across all couriers', 'aisooq-connector' ); ?>"><?php echo esc_html( number_format_i18n( $snap['parcels'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $snap['success'] ) : ?>
								<span class="aisooq-pill ok" title="<?php esc_attr_e( 'Delivered', 'aisooq-connector' ); ?>"><?php echo esc_html( number_format_i18n( $snap['success'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $snap['cancelled'] ) : ?>
								<span class="aisooq-pill err" title="<?php esc_attr_e( 'Returned / refused', 'aisooq-connector' ); ?>"><?php echo esc_html( number_format_i18n( $snap['cancelled'] ) ); ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>

					<?php if ( null === $snap['ratio'] ) : ?>
						<span class="aisooq-dim" title="<?php esc_attr_e( 'No BDCourier history for this number, or BDCourier is not configured for this store on the platform.', 'aisooq-connector' ); ?>"><?php esc_html_e( 'No data', 'aisooq-connector' ); ?></span>
					<?php else : ?>
						<?php
						echo AI_Sooq_Order_Courier::ratio_bar( // phpcs:ignore WordPress.Security.EscapeOutput -- ratio_bar() escapes.
							$snap['ratio'],
							$snap['parcels'],
							$snap['success'],
							$snap['cancelled']
						);
						?>
					<?php endif; ?>

					<div class="aisooq-courier-actions">
					<?php if ( $rows ) : ?>
						<button type="button" class="button-link aisooq-courier-toggle aisooq-icon-btn"
							aria-expanded="false"
							title="<?php esc_attr_e( 'Show the per-courier breakdown', 'aisooq-connector' ); ?>"
							aria-label="<?php esc_attr_e( 'Breakdown', 'aisooq-connector' ); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						</button>
					<?php endif; ?>
					<button type="button" class="button-link aisooq-check-courier aisooq-icon-btn"
						title="<?php echo esc_attr( $why ); ?>"
						aria-label="<?php esc_attr_e( 'Recheck courier history', 'aisooq-connector' ); ?>"
						<?php disabled( ! $active ); ?>>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
					</button>
					<?php if ( ! $active ) : ?>
						<span class="aisooq-courier-why"><?php esc_html_e( 'connection paused', 'aisooq-connector' ); ?></span>
					<?php endif; ?>
					</div>
				</div>
				<?php if ( $rows ) : ?>
					<div class="aisooq-courier-detail" hidden>
						<table class="aisooq-courier-tbl">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Courier', 'aisooq-connector' ); ?></th>
									<th><?php esc_html_e( 'Total', 'aisooq-connector' ); ?></th>
									<th><?php esc_html_e( 'Delivered', 'aisooq-connector' ); ?></th>
									<th><?php esc_html_e( 'Returned', 'aisooq-connector' ); ?></th>
									<th><?php esc_html_e( 'Rate', 'aisooq-connector' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $rows as $c ) : ?>
								<tr>
									<td><?php echo esc_html( '' !== $c['name'] ? $c['name'] : $c['slug'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( $c['total'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( $c['success'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( $c['cancelled'] ); ?></td>
									<td class="aisooq-mono">
										<?php if ( null === $c['ratio'] ) : ?>
											<span class="aisooq-dim">—</span>
										<?php else : ?>
											<span class="aisooq-ratio <?php echo esc_attr( $this->ratio_tone( $c['ratio'] ) ); ?>"><?php echo esc_html( round( $c['ratio'] ) . '%' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
							<?php if ( null !== $snap['success'] && null !== $snap['cancelled'] ) : ?>
							<tfoot>
								<tr>
									<td><?php esc_html_e( 'All couriers', 'aisooq-connector' ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( null === $snap['parcels'] ? '—' : $snap['parcels'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( $snap['success'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( $snap['cancelled'] ); ?></td>
									<td class="aisooq-mono"><?php echo esc_html( null === $snap['ratio'] ? '—' : round( $snap['ratio'] ) . '%' ); ?></td>
								</tr>
							</tfoot>
							<?php endif; ?>
						</table>
					</div>
				<?php endif; ?>
				<?php if ( $checked ) : ?>
					<div class="aisooq-dim aisooq-courier-when">
						<?php
						/* translators: %s: human-readable time difference, e.g. "3 hours" */
						echo esc_html( sprintf( __( 'Checked %s ago', 'aisooq-connector' ), $checked ) );
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
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
				<td class="aisooq-cust-cell" data-label="<?php esc_attr_e( 'Customer', 'aisooq-connector' ); ?>">
					<div class="aisooq-td-val">
						<div class="aisooq-cust"><?php echo esc_html( $row->customer_name ? $row->customer_name : __( 'Anonymous', 'aisooq-connector' ) ); ?></div>
						<div class="aisooq-contact">
							<?php if ( $row->phone ) : ?><span><span class="dashicons dashicons-phone" aria-hidden="true"></span> <?php echo esc_html( $row->phone ); ?></span><br /><?php endif; ?>
							<?php if ( $row->email ) : ?><span><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php echo esc_html( $row->email ); ?></span><?php endif; ?>
						</div>
						<?php echo $this->courier_cell( $row, $active ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</td>
				<td class="aisooq-addr" data-label="<?php esc_attr_e( 'Address', 'aisooq-connector' ); ?>">
					<span class="aisooq-td-val"><?php echo $addr_bits ? esc_html( implode( ', ', $addr_bits ) ) : '<span class="aisooq-dim">—</span>'; ?></span>
				</td>
				<td class="aisooq-cart-cell" data-label="<?php esc_attr_e( 'Cart', 'aisooq-connector' ); ?>">
					<div class="aisooq-td-val">
						<div class="aisooq-mono"><?php /* translators: %d: number of items in the cart. */ echo esc_html( sprintf( _n( '%d item', '%d items', $count, 'aisooq-connector' ), $count ) ); ?></div>
						<?php if ( $first ) : ?><div class="aisooq-contact"><?php echo esc_html( wp_html_excerpt( $first, 42, '…' ) ); ?></div><?php endif; ?>
					</div>
				</td>
				<td class="aisooq-mono aisooq-value-cell" data-label="<?php esc_attr_e( 'Value', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $this->money( $row->subtotal, $row->currency ? $row->currency : $currency ) ); ?></span></td>
				<td class="aisooq-step-cell" data-label="<?php esc_attr_e( 'Step', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $row->furthest_step ? ucfirst( (string) $row->furthest_step ) : '—' ); ?></span></td>
				<td class="aisooq-status-cell" data-label="<?php esc_attr_e( 'Status', 'aisooq-connector' ); ?>">
					<span class="aisooq-td-val">
						<span class="aisooq-badge <?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span>
						<?php if ( 'converted' === $row->status && $row->wc_order_id ) : ?>
							<a class="aisooq-dim" href="<?php echo esc_url( $this->order_edit_url( $row->wc_order_id ) ); ?>">#<?php echo (int) $row->wc_order_id; ?> →</a>
						<?php endif; ?>
					</span>
				</td>
				<td class="aisooq-dim aisooq-updated-cell" data-label="<?php esc_attr_e( 'Updated', 'aisooq-connector' ); ?>"><span class="aisooq-td-val"><?php echo esc_html( $row->updated_at ? human_time_diff( strtotime( $row->updated_at . ' UTC' ) ) . ' ' . __( 'ago', 'aisooq-connector' ) : '—' ); ?></span></td>
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

	/**
	 * Per-row courier delivery history (platform `GET /connect/courier`).
	 *
	 * Saves the answer onto the cart row before responding, then renders the
	 * cell from what was actually stored — so the screen shows exactly what a
	 * reload will show, and can't advertise a result that isn't really there.
	 *
	 * If the write doesn't stick (most likely: the schema upgrade that adds the
	 * courier columns hasn't run yet) this reports a failure rather than quietly
	 * handing back a "Check ratio" button. Silently reverting would invite the
	 * operator to click again, and every click is another billed BDCourier
	 * lookup — the exact waste this whole feature exists to stop.
	 */
	public function ajax_courier() {
		$this->guard_ajax( true );
		AI_Sooq_Abandoned_Admin::flush_stats_cache();
		$key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : '';
		$row = $this->abandoned->get_row( $key );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Cart not found.', 'aisooq-connector' ) ) );
		}
		// Always look the number up off the stored row, never off the request:
		// the phone in the DOM can be a stale render, and this call costs the
		// merchant a paid BDCourier lookup.
		$phone = (string) $row->phone;
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'No phone on this cart.', 'aisooq-connector' ) ) );
		}

		// Shared with the order-side checks: same number, same paid answer, and
		// the phone stays out of the request URL (and therefore out of every
		// access log between here and the platform).
		// Forced: this handler only runs because an operator clicked Check on a
		// row, so the stored answer is exactly what they are replacing.
		$res = class_exists( 'AI_Sooq_Order_Courier' )
			? AI_Sooq_Order_Courier::cached_lookup( AI_Sooq_Plugin::instance()->api(), $phone, true )
			: AI_Sooq_Plugin::instance()->api()->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$res = is_array( $res ) ? $res : array();

		// Persist even a "no history" answer, so a number BDCourier has never
		// seen isn't re-queried on every visit to the worklist.
		$this->abandoned->save_courier( $key, $phone, $res );

		// Read back rather than trusting the write: this is the same path a page
		// reload takes, so if the round-trip is broken we find out here.
		$fresh = $this->abandoned->get_row( $key );
		if ( ! $fresh || null === $this->abandoned->courier_snapshot( $fresh ) ) {
			$this->logger->error( 'Courier lookup for cart ' . $key . ' did not persist — run the plugin upgrade so the courier_* columns exist.' );
			$ratio = isset( $res['successRatio'] ) && is_numeric( $res['successRatio'] ) ? round( (float) $res['successRatio'] ) . '%' : __( 'no data', 'aisooq-connector' );
			wp_send_json_error( array(
				/* translators: %s: the delivery-success ratio just looked up, e.g. "76%" */
				'message' => sprintf( __( 'Looked up %s, but it could not be saved — it would be lost on reload. Deactivate and reactivate AI Sooq Connector to finish the database upgrade, then try again.', 'aisooq-connector' ), $ratio ),
			) );
		}

		wp_send_json_success( array(
			'html'         => $this->courier_cell( $fresh, $this->settings->is_active() && $this->settings->get( 'enable_abandoned' ) ),
			'successRatio' => isset( $res['successRatio'] ) ? $res['successRatio'] : null,
			'totalParcel'  => isset( $res['totalParcel'] ) ? $res['totalParcel'] : null,
		) );
	}

	/** Row action: convert | cancel | fake | reopen | delete. */
	public function ajax_action() {
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard_ajax( 'resync' === $op );
		AI_Sooq_Abandoned_Admin::flush_stats_cache();
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
					/* translators: %d: the WooCommerce order number just created. */
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
		AI_Sooq_Abandoned_Admin::flush_stats_cache();
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
							<?php /* translators: %d: number of orders. */ if ( $order_count ) : ?><span class="aisooq-dim">· <?php echo esc_html( sprintf( _n( '%d order', '%d orders', $order_count, 'aisooq-connector' ), $order_count ) ); ?></span><?php endif; ?>
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
		AI_Sooq_Abandoned_Admin::flush_stats_cache();
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
			<?php // The layout for this screen lives in assets/css/aisooq-admin.css — see the "Abandoned-carts screen" section there. ?>

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
				<div class="aisooq-kpi info"><div class="aisooq-kpi__label"><?php /* translators: %s: formatted count of carts not yet pushed. */ esc_html_e( 'Pushed', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['pushed'] ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( '%s pending', 'aisooq-connector' ), number_format_i18n( $k['pending'] ) ) ); ?></div></div>
				<div class="aisooq-kpi ok"><div class="aisooq-kpi__label"><?php /* translators: %s: recovery rate as a percentage. */ esc_html_e( 'Recovered', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['recovered'] ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( '%s rate', 'aisooq-connector' ), number_format_i18n( $k['recovery_rate'] * 100, 1 ) . '%' ) ); ?></div></div>
				<div class="aisooq-kpi err"><div class="aisooq-kpi__label"><?php esc_html_e( 'Cancelled / Fake', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $k['cancelled'] + $k['fake'] ) ); ?></div></div>
				<div class="aisooq-kpi"><div class="aisooq-kpi__label"><?php /* translators: %s: average cart value, already money-formatted. */ esc_html_e( 'Open value', 'aisooq-connector' ); ?></div><div class="aisooq-kpi__num aisooq-mono"><?php echo esc_html( $this->money( $k['open_value'], $currency ) ); ?></div><div class="aisooq-kpi__sub"><?php echo esc_html( sprintf( __( 'avg %s', 'aisooq-connector' ), $this->money( $k['avg_open'], $currency ) ) ); ?></div></div>
			</div>

			<?php
			if ( ! empty( $k['funnel'] ) ) :
				$steps    = self::funnel_steps();
				$open_tot = 0;
				foreach ( $k['funnel'] as $ff ) {
					$open_tot += (int) $ff['n'];
				}
				$worst = null;
				foreach ( $k['funnel'] as $ff ) {
					if ( null === $worst || (int) $ff['n'] > (int) $worst['n'] ) {
						$worst = $ff;
					}
				}
				?>
			<?php // Same card row as the KPIs above, in checkout order. The list of
			      // carts is the working surface of this screen; this is context,
			      // and context does not get to push the work below the fold. ?>
			<div class="aisooq-kpis aisooq-kpis--funnel">
				<?php
				foreach ( $k['funnel'] as $ff ) :
					$step = (string) $ff['step'];
					$n    = (int) $ff['n'];
					$pct  = $open_tot > 0 ? round( $n / $open_tot * 100 ) : 0;
					$meta = isset( $steps[ $step ] ) ? $steps[ $step ] : array( 'label' => ucfirst( $step ), 'hint' => '' );
					?>
					<div class="aisooq-kpi<?php echo ( $worst && $step === (string) $worst['step'] ) ? ' warn' : ''; ?>"
						title="<?php echo esc_attr( $meta['hint'] ); ?>">
						<div class="aisooq-kpi__label"><?php echo esc_html( $meta['label'] ); ?></div>
						<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( $n ) ); ?></div>
						<div class="aisooq-kpi__sub">
							<?php /* translators: %s: percentage of open carts */ ?>
							<?php echo esc_html( sprintf( __( '%s%% of open', 'aisooq-connector' ), number_format_i18n( $pct ) ) ); ?>
						</div>
					</div>
				<?php endforeach; ?>
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
					<span id="aisooq-query-error" class="aisooq-error" role="alert" hidden></span>
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
				/* translators: %d: number of rows currently listed. */
				'count'      => __( '%d shown', 'aisooq-connector' ),
				'queryFailed' => __( 'Could not refresh the list — the page may have expired. Reload and try again.', 'aisooq-connector' ),
				/* translators: %d: number of rows the operator has ticked. */
				'selected'   => __( '%d selected', 'aisooq-connector' ),
				'pickOp'     => __( 'Choose a bulk action first.', 'aisooq-connector' ),
				'pickRows'   => __( 'Select at least one cart.', 'aisooq-connector' ),
				/* translators: 1: the bulk action's label, 2: number of selected carts. */
				'confirmBulk'=> __( 'Apply "%1$s" to %2$d selected cart(s)?', 'aisooq-connector' ),
				'detailsTitle'=> __( 'Cart details', 'aisooq-connector' ),
				'close'      => __( 'Close', 'aisooq-connector' ),
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
						showQueryError( '' );
						rowsBody.innerHTML = j.data.html;
						countEl.textContent = strings.count.replace( '%d', j.data.count );
						if ( cbAll ) { cbAll.checked = false; }
						bindRows();
						updateBulkCount();
						return;
					}
					// A failed search used to do NOTHING visible: the spinner stopped
					// and the previous results stayed on screen, so an expired nonce or
					// a server error looked exactly like "no matching carts" — and the
					// operator would go on acting on rows that no longer answer the
					// filter they can see.
					showQueryError( ( j && j.data && j.data.message ) ? j.data.message : strings.queryFailed );
				} ).catch( function () {
					spin.classList.remove( 'is-active' );
					showQueryError( strings.queryFailed );
				} );
			}
			function showQueryError( msg ) {
				var box = document.getElementById( 'aisooq-query-error' );
				if ( ! box ) { return; }
				box.textContent = msg || '';
				box.hidden = ! msg;
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
					/**
					 * Bind the courier controls inside `scope`.
					 *
					 * Scoped rather than global because a check replaces only its own
					 * cell — re-binding the whole table there would stack a second
					 * listener on every other row, and the next click would fire two
					 * (paid) lookups.
					 */
					function bindCourier( scope ) {
						// Show / hide the saved per-courier breakdown.
						Array.prototype.forEach.call( scope.querySelectorAll( '.aisooq-courier-toggle' ), function ( btn ) {
							btn.addEventListener( 'click', function () {
								var wrap = btn.closest( '.aisooq-courier' );
								var box = wrap ? wrap.querySelector( '.aisooq-courier-detail' ) : null;
								if ( ! box ) { return; }
								box.hidden = ! box.hidden;
								btn.setAttribute( 'aria-expanded', box.hidden ? 'false' : 'true' );
							} );
						} );
						// Check / recheck. The server saves the result and hands back the
						// rendered cell, so what shows here is what a reload renders —
						// the result can no longer be a screen-only artefact.
						Array.prototype.forEach.call( scope.querySelectorAll( '.aisooq-check-courier' ), function ( btn ) {
							btn.addEventListener( 'click', function () {
								var wrap = btn.closest( '.aisooq-courier' );
								var tr = btn.closest( 'tr' );
								var key = tr ? tr.getAttribute( 'data-key' ) : '';
								var original = btn.innerHTML;
								btn.disabled = true; btn.textContent = '…';
								post( fd( 'aisooq_abandoned_courier', { session_key: key } ) ).then( function ( j ) {
									if ( j && j.success && j.data.html && wrap && wrap.parentNode ) {
										var tmp = document.createElement( 'div' );
										tmp.innerHTML = j.data.html;
										var fresh = tmp.firstElementChild;
										if ( fresh ) {
											wrap.parentNode.replaceChild( fresh, wrap );
											bindCourier( fresh );
											return;
										}
									}
									btn.disabled = false;
									btn.innerHTML = original;
									window.alert( ( j && j.data && j.data.message ) ? j.data.message : strings.failed );
								} ).catch( function () { btn.disabled = false; btn.innerHTML = original; window.alert( strings.failed ); } );
							} );
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
					bindCourier( rowsBody );
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
