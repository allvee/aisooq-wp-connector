<?php
/**
 * The "Blocked" screen: who this plugin turned away, and the operator's own
 * block / allow list.
 *
 * The two halves belong on one screen because they answer each other. The log
 * tells you a number was refused four times last night; the list is where you
 * decide that number is a genuine customer and should never be refused again,
 * or that it is an abuser and should never get through. Splitting them would
 * mean copying identifiers between screens by hand.
 *
 * Registered even while the connection is paused: the local list still runs at
 * checkout when the platform is unreachable, so it must stay manageable.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Blocklist_Admin {

	/**
	 * The menu this screen hangs under.
	 *
	 * Taken from the settings screen's own constant rather than repeated as
	 * a literal: the top-level slug is `aisooq-connector`, and a submenu
	 * registered under a parent that does not exist is still reachable by
	 * URL but never appears in the menu — so it looks like it works right up
	 * until someone tries to find it.
	 */
	const PARENT_SLUG = AI_Sooq_Settings::PAGE_SLUG;
	const PAGE_SLUG   = 'aisooq-blocked';
	const CAPABILITY  = 'manage_woocommerce';
	const NONCE       = 'aisooq_blocked';
	const PER_PAGE    = 50;

	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_aisooq_block_add', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_aisooq_block_remove', array( $this, 'ajax_remove' ) );
	}

	public function add_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Blocked', 'aisooq-connector' ),
			'<span class="dashicons dashicons-shield-alt" style="font-size:17px;width:17px;height:17px;vertical-align:-3px;"></span> ' . __( 'Blocked', 'aisooq-connector' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	// ── Guards ──────────────────────────────────────────────────────────────

	/** Nonce + capability, in that order, before any side effect. */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
	}

	// ── Actions ─────────────────────────────────────────────────────────────

	public function ajax_add() {
		$this->guard();

		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$value = isset( $_POST['value'] ) && is_scalar( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'block';
		$note  = isset( $_POST['reason'] ) && is_scalar( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$days  = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0;

		// A temporary block is the safer default for a judgement call made in a
		// hurry: it expires on its own instead of quietly excluding a customer
		// forever because nobody remembered to review the list.
		$expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;

		$res = AI_Sooq_Blocklist::add( $type, $value, $mode, $note, $expires );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$this->logger->debug( sprintf( 'Blocklist %s added for %s.', $mode, $type ) );
		wp_send_json_success(
			array(
				'message' => ( 'allow' === $mode )
					? __( 'Added to the always-allow list.', 'aisooq-connector' )
					: __( 'Added to the block list.', 'aisooq-connector' ),
				'reload'  => true,
			)
		);
	}

	public function ajax_remove() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing entry.', 'aisooq-connector' ) ) );
		}
		if ( ! AI_Sooq_Blocklist::remove( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not remove that entry.', 'aisooq-connector' ) ) );
		}
		wp_send_json_success( array( 'message' => __( 'Removed.', 'aisooq-connector' ), 'reload' => true ) );
	}

	// ── Screen ──────────────────────────────────────────────────────────────

	private function tables_ready() {
		global $wpdb;
		$t = AI_Sooq_Blocklist::table_name();
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); // phpcs:ignore WordPress.DB
	}

	/** Human label for a gate key. */
	private static function gate_label( $gate ) {
		$map = array(
			'manual'    => __( 'Your list', 'aisooq-connector' ),
			'duplicate' => __( 'Duplicate order', 'aisooq-connector' ),
			'fraud'     => __( 'Fraud screen', 'aisooq-connector' ),
			'courier'   => __( 'Courier history', 'aisooq-connector' ),
		);
		return isset( $map[ $gate ] ) ? $map[ $gate ] : $gate;
	}

		public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'log', 'list' ), true ) ) {
			$tab = 'log';
		}

		echo '<div class="wrap aisooq-bl aisooq-has-tabs">';
		echo '<div class="aisooq-hero"><div><h1>' . esc_html__( 'Blocked checkouts', 'aisooq-connector' ) . '</h1>';
		echo '<p class="aisooq-hero__sub">' . esc_html__( 'Every checkout this plugin refused, and the list you control.', 'aisooq-connector' ) . '</p></div></div>';

		if ( ! $this->tables_ready() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'The block list table is missing. Deactivate and reactivate the plugin to create it.', 'aisooq-connector' )
				. '</p></div></div>';
			return;
		}

		// Tabs navigation
		echo '<div class="aisooq-tabbar" style="display:flex;"><div class="aisooq-tabs">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=log' ) ) . '" class="aisooq-tab ' . ( 'log' === $tab ? 'is-active' : '' ) . '"><span class="dashicons dashicons-shield-alt"></span> ' . esc_html__( 'Refused checkouts', 'aisooq-connector' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=list' ) ) . '" class="aisooq-tab ' . ( 'list' === $tab ? 'is-active' : '' ) . '"><span class="dashicons dashicons-list-view"></span> ' . esc_html__( 'Your list', 'aisooq-connector' ) . '</a>';
		echo '</div></div>';

		$page   = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'log' === $tab ) {
			$summary = AI_Sooq_Blocklist::log_summary( 7 );
			$log     = AI_Sooq_Blocklist::log_entries(
				array( 'per_page' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'search' => $search )
			);
			$this->render_summary( $summary );
			$this->render_log( $log, $page, $search );
		} else {
			$list = AI_Sooq_Blocklist::entries(
				array( 'per_page' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'search' => $search )
			);
			$this->render_add_form();
			$this->render_list( $list, $page, $search );
		}

		$this->render_script();
		echo '</div>';
	}

	private function render_summary( array $k ) {
		?>
		<div class="aisooq-kpis aisooq-kpis--analytics" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
			<?php
			$tiles = array(
				'total'     => array( 'dashicons-chart-bar', __( 'Refused, last 7 days', 'aisooq-connector' ) ),
				'manual'    => array( 'dashicons-admin-network', __( 'By your list', 'aisooq-connector' ) ),
				'duplicate' => array( 'dashicons-admin-page', __( 'Duplicate orders', 'aisooq-connector' ) ),
				'fraud'     => array( 'dashicons-warning', __( 'Fraud screen', 'aisooq-connector' ) ),
				'courier'   => array( 'dashicons-car', __( 'Courier history', 'aisooq-connector' ) ),
			);
			foreach ( $tiles as $key => $conf ) :
				?>
				<div class="aisooq-kpi aisooq-card" style="padding:16px; box-shadow:var(--shadow-sm); border:1px solid var(--line);">
					<div class="aisooq-kpi__label" style="display:flex; align-items:center; gap:8px; font-weight:600; color:var(--muted);"><span class="dashicons <?php echo esc_attr( $conf[0] ); ?>"></span> <?php echo esc_html( $conf[1] ); ?></div>
					<div class="aisooq-kpi__num" style="font-size:28px; font-weight:800; margin-top:8px; line-height:1;"><?php echo esc_html( number_format_i18n( (int) ( $k[ $key ] ?? 0 ) ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_add_form() {
		?>
		<div class="aisooq-compose aisooq-card" style="padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm); border:1px solid var(--line); border-radius:var(--radius); background:var(--card);">
			<h2 style="margin:0 0 16px 0; font-size:16px; font-weight:700;"><span class="dashicons dashicons-plus-alt2" style="color:var(--pri); margin-right:4px;"></span> <?php esc_html_e( 'Add an entry', 'aisooq-connector' ); ?></h2>
			<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
				<label>
					<span class="h" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></span>
					<select id="aisooq-b-type">
						<option value="phone"><?php esc_html_e( 'Mobile number', 'aisooq-connector' ); ?></option>
						<option value="ip"><?php esc_html_e( 'IP address or range', 'aisooq-connector' ); ?></option>
						<option value="email"><?php esc_html_e( 'Email', 'aisooq-connector' ); ?></option>
					</select>
				</label>
				<label style="flex:1 1 200px; min-width:0;">
					<span class="h" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></span>
					<input type="text" id="aisooq-b-value" placeholder="01712345678 / 203.0.113.4 / 203.0.113.0/24" style="width:100%;" />
				</label>
				<label>
					<span class="h" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></span>
					<select id="aisooq-b-mode">
						<option value="block"><?php esc_html_e( 'Always block', 'aisooq-connector' ); ?></option>
						<option value="allow"><?php esc_html_e( 'Always allow', 'aisooq-connector' ); ?></option>
					</select>
				</label>
				<label style="max-width:120px;">
					<span class="h" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Expires in (days)', 'aisooq-connector' ); ?></span>
					<input type="number" id="aisooq-b-days" min="0" step="1" value="0" style="width:100%;" />
				</label>
				<label style="flex:1 1 200px; min-width:0;">
					<span class="h" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Note (only you see this)', 'aisooq-connector' ); ?></span>
					<input type="text" id="aisooq-b-reason" style="width:100%;" />
				</label>
				<button type="button" class="button button-primary" id="aisooq-b-add" style="margin-bottom:2px; height:38px; display:inline-flex; align-items:center; gap:6px;"><span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save', 'aisooq-connector' ); ?></button>
			</div>
			<div id="aisooq-b-msg" class="aisooq-msg" role="status" aria-live="polite" style="margin-top:12px;"></div>
			<p class="description" style="margin-top:12px; color:var(--muted);">
				<?php esc_html_e( 'Always allow wins over every other check. 0 days means the entry never expires.', 'aisooq-connector' ); ?>
			</p>
		</div>
		<?php
	}

	private function render_list( array $list, $page, $search ) {
		$pages = (int) ceil( max( 1, $list['total'] ) / self::PER_PAGE );
		?>
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
			<h2 style="margin:0; font-size:18px; font-weight:800;"><?php esc_html_e( 'Your list entries', 'aisooq-connector' ); ?> (<?php echo (int) $list['total']; ?>)</h2>
			<form method="get" class="aisooq-search aisooq-find">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="list" />
				<span class="dashicons dashicons-search"></span>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search value or note...', 'aisooq-connector' ); ?>" />
				<button type="submit" style="display:none;"></button>
			</form>
		</div>

		<table class="aisooq-tbl aisooq-card" style="box-shadow:var(--shadow-sm);">
			<thead style="background:var(--wash);"><tr>
				<th><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Note', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Times hit', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'aisooq-connector' ); ?></th>
				<th style="text-align:right;"><?php esc_html_e( 'Manage', 'aisooq-connector' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $list['rows'] ) ) : ?>
				<tr><td colspan="7" class="aisooq-empty" style="text-align:center; padding:40px; color:var(--muted);"><?php esc_html_e( 'No entries found.', 'aisooq-connector' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $list['rows'] as $row ) : ?>
					<tr>
						<td>
							<?php if ( 'allow' === $row->mode ) : ?>
								<span class="aisooq-badge ok"><span class="dashicons dashicons-yes-alt" style="margin-right:2px; font-size:14px; width:14px; height:14px;"></span> <?php esc_html_e( 'Allow', 'aisooq-connector' ); ?></span>
							<?php else : ?>
								<span class="aisooq-badge err"><span class="dashicons dashicons-dismiss" style="margin-right:2px; font-size:14px; width:14px; height:14px;"></span> <?php esc_html_e( 'Block', 'aisooq-connector' ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="aisooq-badge muted"><?php echo esc_html( ucfirst( $row->type ) ); ?></span></td>
						<td><code><?php echo esc_html( $row->value ); ?></code></td>
						<td style="color:var(--muted);"><?php echo esc_html( (string) $row->reason ); ?></td>
						<td><span style="font-weight:600;"><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></span></td>
						<td>
							<?php if ( $row->expires_at ) : ?>
								<span class="aisooq-badge warn" title="<?php echo esc_attr( $row->expires_at ); ?>"><span class="dashicons dashicons-clock" style="font-size:14px; width:14px; height:14px; margin-right:2px;"></span> <?php echo esc_html( human_time_diff( strtotime( $row->expires_at . ' UTC' ) ) ); ?></span>
							<?php else : ?>
								<span class="aisooq-dim"><?php esc_html_e( 'Never', 'aisooq-connector' ); ?></span>
							<?php endif; ?>
						</td>
						<td style="text-align:right;">
							<button type="button" class="button button-small aisooq-b-remove" data-id="<?php echo esc_attr( (int) $row->id ); ?>" title="<?php esc_attr_e( 'Remove', 'aisooq-connector' ); ?>" style="color:var(--err); border-color:transparent; background:transparent;"><span class="dashicons dashicons-trash" style="margin-top:3px;"></span></button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav" style="margin-top:20px;"><div class="tablenav-pages">
				<?php
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
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	private function render_log( array $log, $page, $search ) {
		$pages = (int) ceil( max( 1, $log['total'] ) / self::PER_PAGE );
		?>
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
			<h2 style="margin:0; font-size:18px; font-weight:800;"><?php esc_html_e( 'Refused checkouts record', 'aisooq-connector' ); ?></h2>
			<form method="get" class="aisooq-search aisooq-find">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="log" />
				<span class="dashicons dashicons-search"></span>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search number, email...', 'aisooq-connector' ); ?>" />
				<button type="submit" style="display:none;"></button>
			</form>
		</div>
		
		<table class="aisooq-tbl aisooq-card" style="box-shadow:var(--shadow-sm);">
			<thead style="background:var(--wash);"><tr>
				<th><?php esc_html_e( 'When', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Refused by', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'aisooq-connector' ); ?></th>
				<th style="min-width:160px;text-align:right;"><?php esc_html_e( 'Decide', 'aisooq-connector' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $log['rows'] ) ) : ?>
				<tr><td colspan="5" class="aisooq-empty" style="text-align:center; padding:40px; color:var(--muted);"><?php esc_html_e( 'No refused checkouts recorded yet.', 'aisooq-connector' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $log['rows'] as $row ) : ?>
					<tr>
						<td><span title="<?php echo esc_attr( $row->created_at ); ?>" style="font-weight:600;"><?php echo esc_html( human_time_diff( strtotime( $row->created_at . ' UTC' ) ) ); ?> <?php esc_html_e( 'ago', 'aisooq-connector' ); ?></span></td>
						<td><span class="aisooq-badge muted"><?php echo esc_html( self::gate_label( $row->gate ) ); ?></span></td>
						<td class="aisooq-who">
							<?php if ( $row->name ) : ?><div style="font-weight:700; color:var(--fg);"><?php echo esc_html( $row->name ); ?></div><?php endif; ?>
							<?php if ( $row->phone ) : ?><div style="margin-top:4px;"><code><?php echo esc_html( $row->phone ); ?></code></div><?php endif; ?>
							<?php if ( $row->email ) : ?><div style="margin-top:4px;"><span class="aisooq-dim"><span class="dashicons dashicons-email-alt" style="font-size:14px; width:14px; height:14px; margin-right:4px;"></span><?php echo esc_html( $row->email ); ?></span></div><?php endif; ?>
							<?php if ( $row->ip ) : ?><div style="margin-top:4px;"><span class="aisooq-dim"><span class="dashicons dashicons-admin-site-alt3" style="font-size:14px; width:14px; height:14px; margin-right:4px;"></span><?php echo esc_html( $row->ip ); ?></span></div><?php endif; ?>
						</td>
						<td><span class="aisooq-dim"><?php echo esc_html( (string) $row->reason ); ?></span></td>
						<td class="aisooq-decide" style="text-align:right;">
							<div style="display:inline-flex; gap:6px; justify-content:flex-end;">
								<?php if ( $row->phone ) : ?>
									<button type="button" class="button button-small aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="allow" title="<?php esc_attr_e( 'Always allow Phone', 'aisooq-connector' ); ?>" style="color:var(--ok); border-color:var(--ok); padding:0 8px;"><span class="dashicons dashicons-yes-alt" style="margin-top:3px;font-size:16px;"></span></button>
									<button type="button" class="button button-small aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="block" title="<?php esc_attr_e( 'Always block Phone', 'aisooq-connector' ); ?>" style="color:var(--err); border-color:var(--err); padding:0 8px;"><span class="dashicons dashicons-dismiss" style="margin-top:3px;font-size:16px;"></span></button>
								<?php endif; ?>
								<?php if ( $row->ip ) : ?>
									<button type="button" class="button button-small aisooq-b-quick" data-type="ip" data-value="<?php echo esc_attr( $row->ip ); ?>" data-mode="block" title="<?php esc_attr_e( 'Block IP', 'aisooq-connector' ); ?>" style="color:var(--muted); border-color:var(--edge); padding:0 8px; background:var(--wash);"><span class="dashicons dashicons-admin-site-alt3" style="margin-top:3px;font-size:16px;"></span></button>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav" style="margin-top:20px;"><div class="tablenav-pages">
				<?php
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
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	private function render_script() {
		?>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var msg   = document.getElementById( 'aisooq-b-msg' );
			var strings = <?php echo wp_json_encode( array( 'failed' => __( 'Request failed.', 'aisooq-connector' ), 'confirm' => __( 'Remove this entry?', 'aisooq-connector' ) ) ); ?>;

			function post( action, fields ) {
				var d = new FormData();
				d.append( 'action', action );
				d.append( 'nonce', nonce );
				Object.keys( fields ).forEach( function ( k ) { d.append( k, fields[ k ] ); } );
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: d } )
					.then( function ( r ) { return r.json(); } );
			}
			function say( text, ok ) {
				if ( ! msg ) { return; }
				msg.textContent = text;
				msg.className = 'aisooq-msg ' + ( ok ? 'is-ok' : 'is-err' );
			}
			function handle( p ) {
				return p.then( function ( j ) {
					if ( j && j.success ) {
						say( ( j.data && j.data.message ) || '', true );
						if ( j.data && j.data.reload ) { setTimeout( function () { location.reload(); }, 600 ); }
						return;
					}
					// Never silent: a failure the operator cannot see is a
					// block they think they applied and did not.
					say( ( j && j.data && j.data.message ) || strings.failed, false );
				} ).catch( function () { say( strings.failed, false ); } );
			}

			var addBtn = document.getElementById( 'aisooq-b-add' );
			if ( addBtn ) {
				addBtn.addEventListener( 'click', function () {
					addBtn.disabled = true;
					handle( post( 'aisooq_block_add', {
						type:   document.getElementById( 'aisooq-b-type' ).value,
						value:  document.getElementById( 'aisooq-b-value' ).value,
						mode:   document.getElementById( 'aisooq-b-mode' ).value,
						days:   document.getElementById( 'aisooq-b-days' ).value,
						reason: document.getElementById( 'aisooq-b-reason' ).value
					} ) ).then( function () { addBtn.disabled = false; } );
				} );
			}

			document.addEventListener( 'click', function ( e ) {
				var q = e.target.closest ? e.target.closest( '.aisooq-b-quick' ) : null;
				if ( q ) {
					q.disabled = true;
					handle( post( 'aisooq_block_add', {
						type: q.getAttribute( 'data-type' ),
						value: q.getAttribute( 'data-value' ),
						mode: q.getAttribute( 'data-mode' ),
						days: 0,
						reason: ''
					} ) );
					return;
				}
				var r = e.target.closest ? e.target.closest( '.aisooq-b-remove' ) : null;
				if ( r && window.confirm( strings.confirm ) ) {
					r.disabled = true;
					handle( post( 'aisooq_block_remove', { id: r.getAttribute( 'data-id' ) } ) );
				}
			} );
		} )();
		</script>
		<?php
	}
}
