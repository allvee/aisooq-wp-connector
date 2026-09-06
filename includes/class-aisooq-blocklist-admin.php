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

	const PARENT_SLUG = 'aisooq';
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
		echo '<div class="wrap aisooq-bl">';
		echo '<div class="aisooq-hero"><div><h1>' . esc_html__( 'Blocked checkouts', 'aisooq-connector' ) . '</h1>';
		echo '<p class="aisooq-hero__sub">' . esc_html__( 'Every checkout this plugin refused, and the list you control. A number climbing here that turns out to be a real customer is the signal to loosen a threshold or add an always-allow entry.', 'aisooq-connector' ) . '</p></div></div>';

		if ( ! $this->tables_ready() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'The block list table is missing. Deactivate and reactivate the plugin to create it.', 'aisooq-connector' )
				. '</p></div></div>';
			return;
		}

		$page   = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$summary = AI_Sooq_Blocklist::log_summary( 7 );
		$log     = AI_Sooq_Blocklist::log_entries(
			array( 'per_page' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'search' => $search )
		);
		$list = AI_Sooq_Blocklist::entries( array( 'per_page' => 200 ) );

		$this->render_summary( $summary );
		$this->render_add_form();
		$this->render_list( $list );
		$this->render_log( $log, $page, $search );
		$this->render_script();
		echo '</div>';
	}

	private function render_summary( array $k ) {
		?>
		<div class="aisooq-kpis">
			<?php
			$tiles = array(
				'total'     => __( 'Refused, last 7 days', 'aisooq-connector' ),
				'manual'    => __( 'By your list', 'aisooq-connector' ),
				'duplicate' => __( 'Duplicate orders', 'aisooq-connector' ),
				'fraud'     => __( 'Fraud screen', 'aisooq-connector' ),
				'courier'   => __( 'Courier history', 'aisooq-connector' ),
			);
			foreach ( $tiles as $key => $label ) :
				?>
				<div class="aisooq-kpi">
					<div class="aisooq-kpi__label"><?php echo esc_html( $label ); ?></div>
					<div class="aisooq-kpi__num"><?php echo esc_html( number_format_i18n( (int) ( $k[ $key ] ?? 0 ) ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_add_form() {
		?>
		<h2><?php esc_html_e( 'Add an entry', 'aisooq-connector' ); ?></h2>
		<div class="aisooq-compose">
			<label>
				<span class="h"><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></span>
				<select id="aisooq-b-type">
					<option value="phone"><?php esc_html_e( 'Mobile number', 'aisooq-connector' ); ?></option>
					<option value="ip"><?php esc_html_e( 'IP address or range', 'aisooq-connector' ); ?></option>
					<option value="email"><?php esc_html_e( 'Email', 'aisooq-connector' ); ?></option>
				</select>
			</label>
			<label class="grow">
				<span class="h"><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></span>
				<input type="text" id="aisooq-b-value" placeholder="01712345678 / 203.0.113.4 / 203.0.113.0/24" />
			</label>
			<label>
				<span class="h"><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></span>
				<select id="aisooq-b-mode">
					<option value="block"><?php esc_html_e( 'Always block', 'aisooq-connector' ); ?></option>
					<option value="allow"><?php esc_html_e( 'Always allow', 'aisooq-connector' ); ?></option>
				</select>
			</label>
			<label>
				<span class="h"><?php esc_html_e( 'Expires in (days)', 'aisooq-connector' ); ?></span>
				<input type="number" id="aisooq-b-days" min="0" step="1" value="0" class="small-text" />
			</label>
			<label class="grow">
				<span class="h"><?php esc_html_e( 'Note (only you see this)', 'aisooq-connector' ); ?></span>
				<input type="text" id="aisooq-b-reason" />
			</label>
			<button type="button" class="button button-primary" id="aisooq-b-add"><?php esc_html_e( 'Add', 'aisooq-connector' ); ?></button>
			<span id="aisooq-b-msg" class="aisooq-msg" role="status" aria-live="polite"></span>
		</div>
		<p class="description">
			<?php esc_html_e( 'Always allow wins over every other check, including the platform\'s — it is how you rescue a real customer the automatic layers keep rejecting. 0 days means the entry never expires.', 'aisooq-connector' ); ?>
		</p>
		<?php
	}

	private function render_list( array $list ) {
		?>
		<h2><?php esc_html_e( 'Your list', 'aisooq-connector' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Action', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Match on', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Value', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Note', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Times hit', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'aisooq-connector' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $list['rows'] ) ) : ?>
				<tr><td colspan="7" class="aisooq-empty"><?php esc_html_e( 'Nothing on your list yet.', 'aisooq-connector' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $list['rows'] as $row ) : ?>
					<tr>
						<td><span class="aisooq-mode <?php echo esc_attr( $row->mode ); ?>">
							<?php echo esc_html( 'allow' === $row->mode ? __( 'Allow', 'aisooq-connector' ) : __( 'Block', 'aisooq-connector' ) ); ?>
						</span></td>
						<td><?php echo esc_html( $row->type ); ?></td>
						<td><code><?php echo esc_html( $row->value ); ?></code></td>
						<td><?php echo esc_html( (string) $row->reason ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
						<td><?php echo esc_html( $row->expires_at ? $row->expires_at : __( 'Never', 'aisooq-connector' ) ); ?></td>
						<td><button type="button" class="button-link aisooq-b-remove" data-id="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Remove', 'aisooq-connector' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_log( array $log, $page, $search ) {
		$pages = (int) ceil( max( 1, $log['total'] ) / self::PER_PAGE );
		?>
		<h2><?php esc_html_e( 'Refused checkouts', 'aisooq-connector' ); ?></h2>
		<form method="get" class="aisooq-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search number, email, IP or name', 'aisooq-connector' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'aisooq-connector' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Refused by', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Decide', 'aisooq-connector' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $log['rows'] ) ) : ?>
				<tr><td colspan="5" class="aisooq-empty"><?php esc_html_e( 'No refused checkouts recorded yet.', 'aisooq-connector' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $log['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( self::gate_label( $row->gate ) ); ?></td>
						<td class="aisooq-who">
							<?php if ( $row->name ) : ?><div><?php echo esc_html( $row->name ); ?></div><?php endif; ?>
							<?php if ( $row->phone ) : ?><div><code><?php echo esc_html( $row->phone ); ?></code></div><?php endif; ?>
							<?php if ( $row->email ) : ?><div><small><?php echo esc_html( $row->email ); ?></small></div><?php endif; ?>
							<?php if ( $row->ip ) : ?><div><small><?php echo esc_html( $row->ip ); ?></small></div><?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row->reason ); ?></td>
						<td class="aisooq-decide">
							<?php if ( $row->phone ) : ?>
								<button type="button" class="button-link aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="allow"><?php esc_html_e( 'Always allow', 'aisooq-connector' ); ?></button>
								&middot;
								<button type="button" class="button-link aisooq-b-quick" data-type="phone" data-value="<?php echo esc_attr( $row->phone ); ?>" data-mode="block"><?php esc_html_e( 'Always block', 'aisooq-connector' ); ?></button>
							<?php endif; ?>
							<?php if ( $row->ip ) : ?>
								<br /><button type="button" class="button-link aisooq-b-quick" data-type="ip" data-value="<?php echo esc_attr( $row->ip ); ?>" data-mode="block"><?php esc_html_e( 'Block this IP', 'aisooq-connector' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
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
