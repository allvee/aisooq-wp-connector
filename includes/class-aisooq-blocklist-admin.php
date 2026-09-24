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

	/**
	 * @var AI_Sooq_Settings Not for reading settings — this screen has none of
	 * its own. It is what the shared app bar reads to draw the connection pill,
	 * which every AI Sooq screen shows.
	 */
	private $settings;

	public function __construct( AI_Sooq_Logger $logger, AI_Sooq_Settings $settings ) {
		$this->logger   = $logger;
		$this->settings = $settings;
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

		$ready = $this->tables_ready();
		$page  = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$summary = $ready ? AI_Sooq_Blocklist::log_summary( 7 ) : array();
		$log     = ( $ready && 'log' === $tab )
			? AI_Sooq_Blocklist::log_entries( array( 'per_page' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'search' => $search ) )
			: array( 'rows' => array(), 'total' => 0 );
		$list    = ( $ready && 'list' === $tab )
			? AI_Sooq_Blocklist::entries( array( 'per_page' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'search' => $search ) )
			: array( 'rows' => array(), 'total' => 0 );

		AI_Sooq_Admin_Shell::open( array(
			'slug'     => self::PAGE_SLUG,
			'legacy'   => 'aisooq-bl',
			'title'    => __( 'Blocked checkouts', 'aisooq-connector' ),
			'settings' => $this->settings,
			'nav'      => function () use ( $tab, $search ) {
				$this->render_nav( $tab, $search );
			},
		) );

		echo '<h2 class="aisooq-tabtitle">' . esc_html__( 'Blocked checkouts', 'aisooq-connector' ) . '</h2>';

		if ( ! $ready ) {
			AI_Sooq_Admin_Shell::note(
				'warning-circle',
				__( 'The block list table is missing. Deactivate and reactivate the plugin to create it.', 'aisooq-connector' ),
				'err'
			);
			AI_Sooq_Admin_Shell::close( array( 'slug' => self::PAGE_SLUG ) );
			return;
		}

		if ( 'log' === $tab ) {
			$this->render_summary( $summary );
			$this->render_log( $log, $page, $search );
		} else {
			$this->render_add_form();
			$this->render_list( $list, $page, $search );
		}

		$this->render_script();
		AI_Sooq_Admin_Shell::close( array( 'slug' => self::PAGE_SLUG ) );
	}

	/**
	 * The sidebar's own nav: this screen's two halves.
	 *
	 * Links rather than tabs because each reloads the page with its own query
	 * string — they are navigation, and dressing them as a tablist would
	 * promise keyboard behaviour (arrow keys, roving focus) that is not there.
	 */
	private function render_nav( $tab, $search ) {
		$keep = ( '' === $search ) ? array() : array( 's' => $search );

		AI_Sooq_Admin_Shell::link_nav(
			array(
				array(
					'url'    => add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'log' ) + $keep, admin_url( 'admin.php' ) ),
					'icon'   => 'shield-slash',
					'label'  => __( 'Refused checkouts', 'aisooq-connector' ),
					'active' => 'log' === $tab,
				),
				array(
					'url'    => add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'list' ) + $keep, admin_url( 'admin.php' ) ),
					'icon'   => 'key',
					'label'  => __( 'Your list', 'aisooq-connector' ),
					'active' => 'list' === $tab,
				),
			),
			__( 'Blocked checkouts sections', 'aisooq-connector' )
		);
	}

	/** Five figures: how much was refused in the last week, and by what. */
	private function render_summary( array $k ) {
		$tiles = array(
			'total'     => array( 'chart-bar',      __( 'Refused, 7 days', 'aisooq-connector' ), __( 'Every refusal in the last seven days', 'aisooq-connector' ) ),
			'manual'    => array( 'key',            __( 'By your list', 'aisooq-connector' ),    __( 'Matched an entry you added yourself', 'aisooq-connector' ) ),
			'duplicate' => array( 'copy',           __( 'Duplicates', 'aisooq-connector' ),      __( 'A repeat order inside the configured window', 'aisooq-connector' ) ),
			'fraud'     => array( 'shield-check',   __( 'Fraud screen', 'aisooq-connector' ),    __( 'Name, address, phone or IP checks', 'aisooq-connector' ) ),
			'courier'   => array( 'truck',          __( 'Courier history', 'aisooq-connector' ), __( 'Delivery-success rate below your gate', 'aisooq-connector' ) ),
		);

		$stats = array();
		foreach ( $tiles as $key => $conf ) {
			$stats[] = array(
				'icon'  => $conf[0],
				'label' => $conf[1],
				'value' => number_format_i18n( (int) ( isset( $k[ $key ] ) ? $k[ $key ] : 0 ) ),
				'sub'   => $conf[2],
				'tone'  => '',
			);
		}
		AI_Sooq_Admin_Shell::stats( $stats );
	}

	/** The compose row: one new block-list entry. */
	private function render_add_form() {
		?>
		<div class="aisooq-card">
			<span class="aisooq-card__label">
				<?php echo AI_Sooq_Icons::svg( 'plus-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Add an entry', 'aisooq-connector' ); ?>
			</span>

			<div class="aisooq-pair">
				<div class="aisooq-field">
					<label class="h" for="aisooq-b-type"><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></label>
					<select id="aisooq-b-type">
						<option value="phone"><?php esc_html_e( 'Mobile number', 'aisooq-connector' ); ?></option>
						<option value="ip"><?php esc_html_e( 'IP address or range', 'aisooq-connector' ); ?></option>
						<option value="email"><?php esc_html_e( 'Email', 'aisooq-connector' ); ?></option>
					</select>
				</div>
				<div class="aisooq-field">
					<label class="h" for="aisooq-b-value"><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></label>
					<input type="text" id="aisooq-b-value" class="code" placeholder="01712345678 / 203.0.113.4 / 203.0.113.0/24" />
				</div>
			</div>

			<div class="aisooq-pair">
				<div class="aisooq-field">
					<label class="h" for="aisooq-b-mode"><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></label>
					<select id="aisooq-b-mode">
						<option value="block"><?php esc_html_e( 'Always block', 'aisooq-connector' ); ?></option>
						<option value="allow"><?php esc_html_e( 'Always allow', 'aisooq-connector' ); ?></option>
					</select>
				</div>
				<div class="aisooq-field">
					<label class="h" for="aisooq-b-days">
						<?php esc_html_e( 'Expires in (days)', 'aisooq-connector' ); ?>
						<?php echo AI_Sooq_Settings_Fields::hint( __( '0 means the entry never expires.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<input type="number" id="aisooq-b-days" min="0" step="1" value="0" />
				</div>
			</div>

			<div class="aisooq-field">
				<label class="h" for="aisooq-b-reason"><?php esc_html_e( 'Note (only you see this)', 'aisooq-connector' ); ?></label>
				<input type="text" id="aisooq-b-reason" />
			</div>

			<div class="aisooq-rowline">
				<button type="button" class="aisooq-btn aisooq-btn--primary" id="aisooq-b-add">
					<?php echo AI_Sooq_Icons::svg( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Save', 'aisooq-connector' ); ?>
				</button>
				<span id="aisooq-b-msg" class="aisooq-msg" role="status" aria-live="polite"></span>
			</div>

			<p class="description"><?php esc_html_e( 'Always allow wins over every other check.', 'aisooq-connector' ); ?></p>
		</div>
		<?php
	}

	/** The entries you added yourself. */
	private function render_list( array $list, $page, $search ) {
		$pages = (int) ceil( max( 1, $list['total'] ) / self::PER_PAGE );
		?>
		<div class="aisooq-card aisooq-card--rows">
			<?php
			$this->render_toolbar(
				__( 'Your list entries', 'aisooq-connector' ),
				(int) $list['total'],
				'list',
				$search,
				__( 'Search value or note…', 'aisooq-connector' )
			);
			?>

			<?php if ( empty( $list['rows'] ) ) : ?>
				<?php
				AI_Sooq_Admin_Shell::empty_state(
					'key',
					__( 'No entries yet', 'aisooq-connector' ),
					__( 'Anything you always block or always allow will appear here.', 'aisooq-connector' )
				);
				?>
			<?php else : ?>
				<div class="aisooq-tablewrap">
					<table class="aisooq-table">
						<thead><tr>
							<th><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Note', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Times hit', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'aisooq-connector' ); ?></th>
							<th class="aisooq-table__actions"><?php esc_html_e( 'Manage', 'aisooq-connector' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $list['rows'] as $row ) : ?>
							<tr>
								<td>
									<?php if ( 'allow' === $row->mode ) : ?>
										<span class="aisooq-tag aisooq-tag--ok"><?php esc_html_e( 'Allow', 'aisooq-connector' ); ?></span>
									<?php else : ?>
										<span class="aisooq-tag aisooq-tag--err"><?php esc_html_e( 'Block', 'aisooq-connector' ); ?></span>
									<?php endif; ?>
								</td>
								<td><span class="aisooq-tag aisooq-tag--neutral"><?php echo esc_html( ucfirst( $row->type ) ); ?></span></td>
								<td><code><?php echo esc_html( $row->value ); ?></code></td>
								<td class="aisooq-dim"><?php echo esc_html( (string) $row->reason ); ?></td>
								<td class="aisooq-table__num"><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
								<td>
									<?php if ( $row->expires_at ) : ?>
										<span class="aisooq-tag aisooq-tag--warn" title="<?php echo esc_attr( $row->expires_at ); ?>"><?php echo esc_html( human_time_diff( strtotime( $row->expires_at . ' UTC' ) ) ); ?></span>
									<?php else : ?>
										<span class="aisooq-dim"><?php esc_html_e( 'Never', 'aisooq-connector' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="aisooq-table__actions">
									<button type="button" class="aisooq-iconbtn aisooq-iconbtn--err aisooq-b-remove" data-id="<?php echo esc_attr( (int) $row->id ); ?>"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the blocked value, e.g. a phone number. */ __( 'Remove %s', 'aisooq-connector' ), $row->value ) ); ?>">
										<?php echo AI_Sooq_Icons::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php $this->render_pager( $page, $pages ); ?>
		</div>
		<?php
	}

	/** Every checkout the plugin refused. */
	private function render_log( array $log, $page, $search ) {
		$pages = (int) ceil( max( 1, $log['total'] ) / self::PER_PAGE );
		?>
		<div class="aisooq-card aisooq-card--rows">
			<?php
			$this->render_toolbar(
				__( 'Refused checkouts record', 'aisooq-connector' ),
				(int) $log['total'],
				'log',
				$search,
				__( 'Search number, email…', 'aisooq-connector' )
			);
			?>

			<?php if ( empty( $log['rows'] ) ) : ?>
				<?php
				AI_Sooq_Admin_Shell::empty_state(
					'shield-check',
					__( 'Nothing has been refused', 'aisooq-connector' ),
					__( 'Every checkout so far has passed your screening rules.', 'aisooq-connector' )
				);
				?>
			<?php else : ?>
				<div class="aisooq-tablewrap">
					<table class="aisooq-table">
						<thead><tr>
							<th><?php esc_html_e( 'When', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Refused by', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'aisooq-connector' ); ?></th>
							<th class="aisooq-table__actions"><?php esc_html_e( 'Decide', 'aisooq-connector' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $log['rows'] as $row ) : ?>
							<tr>
								<td>
									<span class="aisooq-table__num" title="<?php echo esc_attr( $row->created_at ); ?>">
										<?php
										printf(
											/* translators: %s: a human-readable interval, e.g. "3 hours". */
											esc_html__( '%s ago', 'aisooq-connector' ),
											esc_html( human_time_diff( strtotime( $row->created_at . ' UTC' ) ) )
										);
										?>
									</span>
								</td>
								<td><span class="aisooq-tag aisooq-tag--neutral"><?php echo esc_html( self::gate_label( $row->gate ) ); ?></span></td>
								<td>
									<div class="aisooq-stack">
										<?php if ( $row->name ) : ?><span class="aisooq-stack__lead"><?php echo esc_html( $row->name ); ?></span><?php endif; ?>
										<?php if ( $row->phone ) : ?><span><code><?php echo esc_html( $row->phone ); ?></code></span><?php endif; ?>
										<?php if ( $row->email ) : ?>
											<span class="aisooq-stack__meta"><?php echo AI_Sooq_Icons::svg( 'envelope-simple', array( 'size' => 13 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $row->email ); ?></span>
										<?php endif; ?>
										<?php if ( $row->ip ) : ?>
											<span class="aisooq-stack__meta"><?php echo AI_Sooq_Icons::svg( 'globe', array( 'size' => 13 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $row->ip ); ?></span>
										<?php endif; ?>
									</div>
								</td>
								<td class="aisooq-dim"><?php echo esc_html( (string) $row->reason ); ?></td>
								<td class="aisooq-table__actions">
									<span class="aisooq-rowactions">
										<?php if ( $row->phone ) : ?>
											<button type="button" class="aisooq-iconbtn aisooq-iconbtn--ok aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="allow"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: a phone number. */ __( 'Always allow %s', 'aisooq-connector' ), $row->phone ) ); ?>">
												<?php echo AI_Sooq_Icons::svg( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</button>
											<button type="button" class="aisooq-iconbtn aisooq-iconbtn--err aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="block"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: a phone number. */ __( 'Always block %s', 'aisooq-connector' ), $row->phone ) ); ?>">
												<?php echo AI_Sooq_Icons::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</button>
										<?php endif; ?>
										<?php if ( $row->ip ) : ?>
											<button type="button" class="aisooq-iconbtn aisooq-b-quick" data-type="ip" data-value="<?php echo esc_attr( $row->ip ); ?>" data-mode="block"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: an IP address. */ __( 'Block IP %s', 'aisooq-connector' ), $row->ip ) ); ?>">
												<?php echo AI_Sooq_Icons::svg( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</button>
										<?php endif; ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php $this->render_pager( $page, $pages ); ?>
		</div>
		<?php
	}

	/** The heading and search that sit above each of this screen's two tables. */
	private function render_toolbar( $title, $total, $tab, $search, $placeholder ) {
		$id = 'aisooq-bl-search-' . $tab;
		?>
		<div class="aisooq-toolbar">
			<h3 class="aisooq-toolbar__title">
				<?php echo esc_html( $title ); ?>
				<span class="aisooq-toolbar__count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</h3>
			<form method="get" class="aisooq-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<span class="aisooq-search__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'magnifying-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $placeholder ); ?></label>
				<input type="search" id="<?php echo esc_attr( $id ); ?>" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
			</form>
		</div>
		<?php
	}

	/** WordPress does the arithmetic; the stylesheet does the looks. */
	private function render_pager( $page, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="aisooq-pager tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => (int) $page,
					'total'     => (int) $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div>';
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
