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
 * WHY THIS SCREEN IS SHAPED AROUND CAUSES RATHER THAN ROWS. The first version
 * listed the failures newest-first and offered one lever, "Try all again". That
 * is the wrong shape for the job: forty failures are almost never forty
 * problems, they are two or three, and the operator's actual workflow is "fix
 * the credential, send back the thirty orders that broke on it, leave the other
 * ten alone". A flat list cannot express that, and an all-or-nothing retry
 * actively fights it — it spends attempts on the ten orders whose cause is
 * still unfixed. So the rollup (AI_Sooq_Order_Sync::failed_by_cause) is the
 * primary navigation here, and every retry lever below it is scoped to whatever
 * the rail and the search box currently select.
 *
 * A little over the 800-line guide, deliberately: this is one screen — markup,
 * the script that drives it and the handlers that answer it — and splitting it
 * would put the endpoint for a button in a different file from the button. The
 * comments are most of the length; the code is around five hundred lines.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Failed_Admin {

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
	const PAGE_SLUG   = 'aisooq-failed';
	const CAPABILITY  = 'manage_woocommerce';
	const NONCE       = 'aisooq_failed';
	const PER_PAGE    = 25;

	/**
	 * Query-string keys the two filters round-trip through.
	 *
	 * Named rather than spelled out at each of the six places that read or
	 * write them: the filters have to survive pagination, the search form and
	 * the AJAX calls identically, and a single typo in one of those spellings
	 * would silently widen the view back to everything under a heading still
	 * claiming it was filtered.
	 */
	const ARG_CODE   = 'code';
	const ARG_SEARCH = 's';

	/**
	 * How much of a cause's stored message a rail tile shows.
	 *
	 * The platform's messages run to 250 characters (see clamp_error()), and a
	 * tile that tall turns the rail — the thing this screen is navigated by —
	 * into a wall. The full text is still on every row below.
	 */
	const CAUSE_LABEL_CHARS = 72;

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
		add_action( 'wp_ajax_aisooq_failed_retry_selected', array( $this, 'ajax_retry_selected' ) );
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

	// ── Reading the request ─────────────────────────────────────────────────

	/**
	 * One scalar request value, sanitised, or ''.
	 *
	 * The is_scalar() check is not defensive noise: `?code[]=x` makes
	 * $_GET['code'] an array, and sanitize_text_field() on an array is a fatal
	 * on PHP 8 — a crashed admin screen from a hand-typed URL.
	 */
	private static function scalar( array $src, $key ) {
		if ( ! isset( $src[ $key ] ) || ! is_scalar( $src[ $key ] ) ) {
			return '';
		}
		return trim( sanitize_text_field( wp_unslash( (string) $src[ $key ] ) ) );
	}

	/**
	 * The two filters, off whichever superglobal carried them.
	 *
	 * The search term is clamped to the same ceiling the query layer applies,
	 * because it is echoed straight back into the search box: without this the
	 * box would show 300 characters while the results answered the first 100,
	 * and the screen would be describing a search it did not run.
	 *
	 * @param array $src $_GET on a page view, $_POST on an AJAX call.
	 * @return array{code:string,search:string} The shape failed_query_args(),
	 *         failed_count_matching() and retry_failed() all take.
	 */
	private static function read_filters( array $src ) {
		$search = self::scalar( $src, self::ARG_SEARCH );
		if ( '' !== $search ) {
			$search = function_exists( 'mb_substr' )
				? mb_substr( $search, 0, AI_Sooq_Order_Sync::SEARCH_MAX_CHARS, 'UTF-8' )
				: substr( $search, 0, AI_Sooq_Order_Sync::SEARCH_MAX_CHARS );
		}
		return array(
			'code'   => self::scalar( $src, self::ARG_CODE ),
			'search' => $search,
		);
	}

	private static function is_filtered( array $filters ) {
		return '' !== $filters['code'] || '' !== $filters['search'];
	}

	/**
	 * A URL for this screen. http_build_query() rather than add_query_arg(),
	 * because add_query_arg does NOT encode the values it is handed: a search
	 * for "a&b" would end the query string early and the next page would answer
	 * a different search, with the box still showing the original term.
	 */
	private static function page_url( array $args = array() ) {
		$query = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return admin_url( 'admin.php?' . http_build_query( $query, '', '&' ) );
	}

	/** This screen's URL with the live filters kept and $args layered on top. */
	private static function view_url( array $filters, array $args = array() ) {
		$query = array();
		if ( '' !== $filters['code'] ) {
			$query[ self::ARG_CODE ] = $filters['code'];
		}
		if ( '' !== $filters['search'] ) {
			$query[ self::ARG_SEARCH ] = $filters['search'];
		}
		return self::page_url( array_merge( $query, $args ) );
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

	/**
	 * Retry everything the screen currently selects, one cursored page a call.
	 *
	 * The filters travel with every step: the walk must describe the set the
	 * operator is looking at, and "retry all" over a cause nobody has fixed yet
	 * is exactly the blunt instrument this rebuild exists to replace.
	 */
	public function ajax_retry_all() {
		$this->guard();
		$offset  = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$filters = self::read_filters( $_POST );
		$res     = $this->sync->retry_failed( $offset, AI_Sooq_Order_Sync::MAX_BULK_RETRY, $filters );
		$this->logger->debug(
			'Bulk retry from the failed-syncs screen (offset ' . $offset . ', cause "' . $filters['code'] . '"): ' .
			$res['queued'] . ' queued, ' . $res['skipped'] . ' skipped.'
		);
		wp_send_json_success( $res );
	}

	/**
	 * Retry exactly the rows the operator ticked.
	 *
	 * retry_orders() caps itself and hands back `remaining`; the caller loops
	 * until that is empty. Without the loop a long selection would look like it
	 * had all been sent when only the first batch had.
	 */
	public function ajax_retry_selected() {
		$this->guard();
		$raw = isset( $_POST['order_ids'] ) ? (array) wp_unslash( $_POST['order_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to positive integers below.
		$ids = array();
		foreach ( $raw as $value ) {
			// Scalar-checked rather than absint()ed straight: `order_ids[][]=1`
			// puts an array in here, and casting that to int yields 1 — a real
			// order id, belonging to somebody else's order.
			if ( is_scalar( $value ) ) {
				$ids[] = absint( $value );
			}
		}
		$ids = array_values( array_filter( $ids ) );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nothing selected.', 'aisooq-connector' ) ) );
		}
		$res = $this->sync->retry_orders( $ids );
		$this->logger->debug(
			'Selected retry from the failed-syncs screen (' . count( $ids ) . ' asked): ' .
			$res['queued'] . ' queued, ' . $res['skipped'] . ' skipped, ' . count( $res['remaining'] ) . ' left for the next call.'
		);
		wp_send_json_success( $res );
	}

	// ── Reading one order ───────────────────────────────────────────────────

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

	/**
	 * Last attempt, falling back through what older orders do carry.
	 *
	 * A timestamp AND the stamp it came from, because the cell needs both: "6
	 * hours ago" is what an operator reads, the exact stamp is what they quote
	 * in a support ticket. Printing only the raw value — as this screen did —
	 * made it the one screen here that did not speak in elapsed time.
	 *
	 * @return array{ts:int,exact:string} ts is 0 when nothing is known.
	 */
	private static function last_try( $order ) {
		foreach ( array( AISOOQ_META_LAST_TRY, AISOOQ_META_SYNCED_AT ) as $key ) {
			$stamp = trim( (string) $order->get_meta( $key ) );
			if ( '' !== $stamp ) {
				return array( 'ts' => self::stamp_to_timestamp( $stamp ), 'exact' => $stamp );
			}
		}
		$created = $order->get_date_created();
		if ( ! $created ) {
			return array( 'ts' => 0, 'exact' => '' );
		}
		return array( 'ts' => (int) $created->getTimestamp(), 'exact' => $created->date( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A stored `current_time( 'mysql' )` stamp as a real unix timestamp.
	 *
	 * Both stamps this screen reads are written in the SHOP's timezone, not
	 * UTC. strtotime() would read them in the server's, which on a shared host
	 * is UTC while the shop is +06 — every "last tried" would be six hours out
	 * and the most recent ones would read as being in the future.
	 */
	private static function stamp_to_timestamp( $stamp ) {
		$ts = (int) get_gmt_from_date( $stamp, 'U' );
		return $ts > 0 ? $ts : 0;
	}

	/**
	 * What to call one bucket of the cause rollup.
	 *
	 * The query layer stores no wording of its own: `label` is whatever the
	 * platform last said, and may be empty. Falling back to the raw code is not
	 * laziness — "aisooq_http_500" is worse than a sentence and far better than
	 * a blank tile nobody can click with any idea what it selects.
	 */
	private static function cause_title( array $cause ) {
		$code = isset( $cause['code'] ) ? (string) $cause['code'] : '';
		if ( AI_Sooq_Order_Sync::CAUSE_NONE === $code ) {
			return __( 'No reason recorded', 'aisooq-connector' );
		}
		$label = isset( $cause['label'] ) ? trim( (string) $cause['label'] ) : '';
		if ( '' !== $label ) {
			return wp_html_excerpt( $label, self::CAUSE_LABEL_CHARS, '…' );
		}
		return $code;
	}

	// ── Screen ──────────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// A read-only view, already behind CAPABILITY, whose only inputs are a
		// cause, a search term and a page number — there is nothing here worth
		// forging, and a nonce would break every bookmarked filtered URL.
		$request = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = self::read_filters( $request );
		$total   = AI_Sooq_Order_Sync::failed_count( true );
		$active  = $this->settings->is_active();

		echo '<div class="wrap aisooq-bl aisooq-fo">';
		$this->render_hero();

		if ( ! $active ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'The connection is paused, so retrying is disabled. Activate it in Settings first.', 'aisooq-connector' )
				. '</p></div>';
		}

		if ( 0 === $total ) {
			echo '<div class="aisooq-kpi"><div class="aisooq-kpi__label">' . esc_html__( 'Nothing has given up', 'aisooq-connector' ) . '</div>';
			echo '<div class="aisooq-kpi__sub">' . esc_html__( 'Every order either synced or is still retrying.', 'aisooq-connector' ) . '</div></div></div>';
			return;
		}

		// Unfiltered, failed_count_matching() is documented to be failed_count(
		// true ) — and `true` means "recount, ignoring the cache", so asking it
		// again here would put a second COUNT over the whole order-meta table on
		// every view of the default screen for an answer already in $total.
		$matching = self::is_filtered( $filters ) ? AI_Sooq_Order_Sync::failed_count_matching( $filters ) : $total;
		$pages    = max( 1, (int) ceil( $matching / self::PER_PAGE ) );
		// Clamped, not just floored: a filter SHRINKS the set, so searching
		// while on page three would otherwise land the operator on a blank
		// table with no hint that their term matched anything at all.
		$page   = min( max( 1, absint( self::scalar( $request, 'paged' ) ) ), $pages );
		$orders = self::fetch_page( $filters, $page, $matching );

		$this->render_causes( AI_Sooq_Order_Sync::failed_by_cause( true ), $total, $filters );

		echo '<div class="aisooq-card">';
		$this->render_chips( $filters, $matching, $total );
		$this->render_search( $filters );
		$this->render_bulkbar( $filters, $matching, $active );
		if ( $orders ) {
			$this->render_table( $orders, $active );
		} else {
			$this->render_no_matches();
		}
		echo '</div>';

		$this->render_pagination( $page, $pages, $filters );
		echo '<p class="description">'
			. esc_html__( 'Retrying does not reset an order\'s attempt count, so an order that fails again stays on this list with a fresh reason rather than disappearing.', 'aisooq-connector' )
			. '</p>';
		$this->render_script( $filters, $active );
		echo '</div>';
	}

	private function render_hero() {
		echo '<div class="aisooq-hero"><div><h1>' . esc_html__( 'Failed syncs', 'aisooq-connector' ) . '</h1>';
		echo '<p class="aisooq-hero__sub">' . esc_html(
			sprintf(
				/* translators: %d: the number of attempts an order makes before giving up. */
				__( 'Orders that tried %d times and stopped. Nothing will push them again on its own — they are here so you can see why, fix the cause, and send them.', 'aisooq-connector' ),
				(int) AI_Sooq_Order_Sync::MAX_ATTEMPTS
			)
		) . '</p></div></div>';
	}

	/** @return WC_Order[]|array */
	private static function fetch_page( array $filters, $page, $matching ) {
		if ( ! function_exists( 'wc_get_orders' ) || $matching < 1 ) {
			return array();
		}
		return (array) wc_get_orders(
			AI_Sooq_Order_Sync::failed_query_args(
				array( 'limit' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE ),
				$filters
			)
		);
	}

	/**
	 * The cause rail — the headline of this screen and its navigation.
	 *
	 * Tiles rather than a dropdown because the counts ARE the information: an
	 * operator seeing "30 / 5 / 3" knows immediately that one fix clears three
	 * quarters of the pile, which is the decision this screen exists to support
	 * and the one a collapsed <select> hides.
	 *
	 * The search survives a cause click. The rail only owns the code dimension,
	 * and silently discarding the other filter would answer a click with a set
	 * the operator did not ask for.
	 */
	private function render_causes( array $causes, $total, array $filters ) {
		$keep = ( '' === $filters['search'] ) ? array() : array( self::ARG_SEARCH => $filters['search'] );

		echo '<h2>' . esc_html__( 'Why they stopped', 'aisooq-connector' ) . '</h2>';
		echo '<div class="aisooq-kpis aisooq-causes">';
		self::cause_tile( __( 'All failures', 'aisooq-connector' ), $total, '', self::page_url( $keep ), '' === $filters['code'] );
		foreach ( $causes as $cause ) {
			$code = isset( $cause['code'] ) ? (string) $cause['code'] : '';
			self::cause_tile(
				self::cause_title( $cause ),
				isset( $cause['count'] ) ? (int) $cause['count'] : 0,
				$code,
				self::page_url( array_merge( $keep, array( self::ARG_CODE => $code ) ) ),
				'' !== $code && $code === $filters['code']
			);
		}
		echo '</div>';
	}

	/**
	 * One tile in the rail. $code is '' on the "all" tile and CAUSE_NONE for the
	 * bucket with nothing to show.
	 *
	 * Both $label and $code are platform text, so both are escaped here rather
	 * than trusted to have been escaped by whoever assembled the rollup.
	 */
	private static function cause_tile( $label, $count, $code, $url, $is_on ) {
		echo '<a class="aisooq-kpi aisooq-cause' . ( $is_on ? ' on' : '' ) . '" href="' . esc_url( $url ) . '"'
			. ( $is_on ? ' aria-current="true"' : '' ) . '>';
		echo '<span class="aisooq-kpi__label">' . esc_html( $label ) . '</span>';
		echo '<span class="aisooq-kpi__num">' . esc_html( number_format_i18n( (int) $count ) ) . '</span>';
		if ( '' !== $code && AI_Sooq_Order_Sync::CAUSE_NONE !== $code ) {
			echo '<span class="aisooq-kpi__sub"><code class="aisooq-code">' . esc_html( $code ) . '</code></span>';
		}
		echo '</a>';
	}

	/**
	 * What is currently selected, and how to unselect it.
	 *
	 * Rendered only when something is filtered: a permanently visible bar saying
	 * "no filters" is noise, and the rail already shows which tile is lit. Each
	 * chip drops its OWN filter rather than both, because "same search, all
	 * causes" is a step an operator takes constantly and losing the typed term to
	 * get there is the kind of small hostility that makes people stop filtering
	 * at all.
	 */
	private function render_chips( array $filters, $matching, $total ) {
		if ( ! self::is_filtered( $filters ) ) {
			return;
		}
		$only_code   = ( '' === $filters['search'] ) ? array() : array( self::ARG_SEARCH => $filters['search'] );
		$only_search = ( '' === $filters['code'] ) ? array() : array( self::ARG_CODE => $filters['code'] );

		echo '<div class="aisooq-chips">';
		echo '<span class="aisooq-dim">' . esc_html(
			sprintf(
				/* translators: 1: how many orders match the current filter, 2: how many have given up in total. */
				__( 'Showing %1$s of %2$s failures', 'aisooq-connector' ),
				number_format_i18n( (int) $matching ),
				number_format_i18n( (int) $total )
			)
		) . '</span>';

		if ( '' !== $filters['code'] ) {
			// CAUSE_NONE is an internal sentinel, not something to show an operator.
			$shown = ( AI_Sooq_Order_Sync::CAUSE_NONE === $filters['code'] )
				? __( 'No reason recorded', 'aisooq-connector' )
				: $filters['code'];
			/* translators: %s: the error code the list is filtered to. */
			self::chip( sprintf( __( 'Cause: %s', 'aisooq-connector' ), $shown ), self::page_url( $only_code ) );
		}
		if ( '' !== $filters['search'] ) {
			/* translators: %s: the term the operator searched for. */
			self::chip( sprintf( __( 'Search: %s', 'aisooq-connector' ), $filters['search'] ), self::page_url( $only_search ) );
		}

		echo '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Clear filters', 'aisooq-connector' ) . '</a>';
		echo '</div>';
	}

	/**
	 * One removable filter chip: what is on, and the URL that turns it off.
	 * $text may carry the operator's own search term, so it is escaped here.
	 */
	private static function chip( $text, $off_url ) {
		echo '<span class="aisooq-chip">' . esc_html( $text );
		echo '<a class="aisooq-chip__x" href="' . esc_url( $off_url ) . '" aria-label="'
			. esc_attr__( 'Remove this filter', 'aisooq-connector' ) . '">&times;</a></span>';
	}

	/**
	 * The search box. A plain GET form, so the result is a URL the operator can
	 * bookmark — and every link below, pagination included, is built from the
	 * same two query args this form submits.
	 */
	private function render_search( array $filters ) {
		?>
		<form class="aisooq-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<?php if ( '' !== $filters['code'] ) : ?>
				<?php // Carried hidden, or searching inside a cause would quietly widen the view back to every cause. ?>
				<input type="hidden" name="<?php echo esc_attr( self::ARG_CODE ); ?>" value="<?php echo esc_attr( $filters['code'] ); ?>" />
			<?php endif; ?>
			<div class="grow">
				<label for="aisooq-fo-search"><?php esc_html_e( 'Search', 'aisooq-connector' ); ?></label>
				<input type="search" id="aisooq-fo-search" name="<?php echo esc_attr( self::ARG_SEARCH ); ?>"
					value="<?php echo esc_attr( $filters['search'] ); ?>"
					placeholder="<?php esc_attr_e( 'order number, name, phone or email…', 'aisooq-connector' ); ?>" />
			</div>
			<div><button type="submit" class="button"><?php esc_html_e( 'Search', 'aisooq-connector' ); ?></button></div>
		</form>
		<?php
	}

	/**
	 * The label on the scope button — one lever, pointed at whatever the screen
	 * currently shows. Two buttons that both say "retry" and disagree about
	 * what they cover is how an operator sends the forty orders they meant to
	 * leave alone.
	 */
	private static function bulk_label( array $filters, $matching ) {
		$n = number_format_i18n( (int) $matching );
		if ( '' !== $filters['code'] && '' === $filters['search'] ) {
			/* translators: %s: how many orders failed for the selected cause. */
			return sprintf( __( 'Retry all %s with this cause', 'aisooq-connector' ), $n );
		}
		if ( self::is_filtered( $filters ) ) {
			/* translators: %s: how many orders match the current filter. */
			return sprintf( __( 'Retry all %s matching', 'aisooq-connector' ), $n );
		}
		return __( 'Try all again', 'aisooq-connector' );
	}

	private function render_bulkbar( array $filters, $matching, $active ) {
		// Rendered at zero and rewritten by the script on every tick; the static
		// label is what a JS-less admin sees rather than an empty button.
		/* translators: %d: how many rows are ticked. */
		$selected = sprintf( __( 'Retry selected (%d)', 'aisooq-connector' ), 0 );
		?>
		<div class="aisooq-bulkbar">
			<button type="button" class="button button-primary" id="aisooq-fo-retry-selected" disabled><?php echo esc_html( $selected ); ?></button>
			<button type="button" class="button" id="aisooq-fo-retry-scope" <?php disabled( ! $active || $matching < 1 ); ?>>
				<?php echo esc_html( self::bulk_label( $filters, $matching ) ); ?>
			</button>
			<span id="aisooq-fo-msg" class="aisooq-msg" role="status" aria-live="polite"></span>
		</div>
		<?php
	}

	private function render_table( array $orders, $active ) {
		?>
		<div class="aisooq-fo-scroll">
		<table class="aisooq-tbl">
			<thead><tr>
				<th class="aisooq-fo-cb"><input type="checkbox" id="aisooq-fo-cb-all" aria-label="<?php esc_attr_e( 'Select all', 'aisooq-connector' ); ?>" <?php disabled( ! $active ); ?> /></th>
				<th><?php esc_html_e( 'Order', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Why it stopped', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Attempts', 'aisooq-connector' ); ?></th>
				<th><?php esc_html_e( 'Last tried', 'aisooq-connector' ); ?></th>
				<th class="aisooq-fo-act"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'aisooq-connector' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $orders as $order ) :
				if ( ! is_a( $order, 'WC_Order' ) ) {
					continue;
				}
				$blocker = $this->sync->retry_blocker( $order );
				// The stored code is platform text, not ours — escaped at every
				// point it reaches the page.
				$code  = trim( (string) $order->get_meta( AISOOQ_META_ERROR_CODE ) );
				$tried = self::last_try( $order );
				$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				/* translators: %s: the order number. */
				$pick = sprintf( __( 'Select order %s', 'aisooq-connector' ), $order->get_order_number() );
				?>
				<tr data-order="<?php echo esc_attr( $order->get_id() ); ?>">
					<td class="aisooq-fo-cb">
						<?php if ( '' === $blocker ) : ?>
							<input type="checkbox" class="aisooq-fo-pick" aria-label="<?php echo esc_attr( $pick ); ?>" <?php disabled( ! $active ); ?> />
						<?php endif; ?>
					</td>
					<td class="aisooq-fo-order" data-label="<?php esc_attr_e( 'Order', 'aisooq-connector' ); ?>">
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
						<div class="aisooq-dim"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></div>
					</td>
					<td class="aisooq-who" data-label="<?php esc_attr_e( 'Customer', 'aisooq-connector' ); ?>">
						<div><?php echo esc_html( '' !== $name ? $name : '—' ); ?></div>
						<?php if ( $order->get_billing_phone() ) : ?><div><code><?php echo esc_html( $order->get_billing_phone() ); ?></code></div><?php endif; ?>
					</td>
					<td class="aisooq-fo-why" data-label="<?php esc_attr_e( 'Why it stopped', 'aisooq-connector' ); ?>">
						<div><?php echo esc_html( self::reason( $order ) ); ?></div>
						<?php if ( '' !== $code ) : ?>
							<?php // The code, not the prose, is what an operator greps a log or a support thread for. ?>
							<code class="aisooq-code" title="<?php esc_attr_e( 'The error code this failure was recorded under.', 'aisooq-connector' ); ?>"><?php echo esc_html( $code ); ?></code>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Attempts', 'aisooq-connector' ); ?>"><?php echo esc_html( number_format_i18n( (int) $order->get_meta( AISOOQ_META_ATTEMPTS ) ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Last tried', 'aisooq-connector' ); ?>">
						<?php if ( $tried['ts'] > 0 ) : ?>
							<?php // Elapsed time to read, exact stamp on hover so the precision is not lost. ?>
							<span title="<?php echo esc_attr( $tried['exact'] ); ?>"><?php echo esc_html( human_time_diff( $tried['ts'] ) . ' ' . __( 'ago', 'aisooq-connector' ) ); ?></span>
						<?php else : ?>
							<span class="aisooq-dim">&mdash;</span>
						<?php endif; ?>
					</td>
					<td class="aisooq-fo-act" data-label="<?php esc_attr_e( 'Actions', 'aisooq-connector' ); ?>">
						<?php if ( '' !== $blocker ) : ?>
							<span class="aisooq-dim" title="<?php echo esc_attr( $blocker ); ?>"><?php esc_html_e( 'Cannot retry', 'aisooq-connector' ); ?></span>
						<?php else : ?>
							<button type="button" class="button aisooq-fo-retry" <?php disabled( ! $active ); ?>><?php esc_html_e( 'Try again', 'aisooq-connector' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Not the same thing as "nothing has given up", and it must not look like
	 * it: the pile is still there, this view just does not reach it.
	 */
	private function render_no_matches() {
		echo '<div class="aisooq-empty">' . esc_html__( 'Nothing here matches that filter.', 'aisooq-connector' ) . ' ';
		echo '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Clear filters', 'aisooq-connector' ) . '</a></div>';
	}

	private function render_pagination( $page, $pages, array $filters ) {
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					// Built from the filters rather than from the incoming URL,
					// so page two of a search is page two of THAT search and
					// nothing else the request happened to carry.
					'base'      => self::view_url( $filters ) . '&paged=%#%',
					'format'    => '',
					'current'   => (int) $page,
					'total'     => (int) $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div></div>';
	}

	private function render_script( array $filters, $active ) {
		?>
		<script>
		( function () {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			// Keyed exactly as read_filters() reads them, so the object can be
			// spread straight into a request body: a bulk retry that walked a
			// different set from the one on screen is the failure this whole
			// screen exists to stop making by hand.
			var filters = <?php echo wp_json_encode( array( self::ARG_CODE => $filters['code'], self::ARG_SEARCH => $filters['search'] ) ); ?>;
			var canRetry = <?php echo $active ? 'true' : 'false'; ?>;
			var msg     = document.getElementById( 'aisooq-fo-msg' );
			var selBtn  = document.getElementById( 'aisooq-fo-retry-selected' );
			var s       = <?php echo wp_json_encode( array(
				'failed'   => __( 'Request failed.', 'aisooq-connector' ),
				'working'  => __( 'Working…', 'aisooq-connector' ),
				/* translators: 1: number queued, 2: number skipped. */
				'done'     => __( '%1$d queued, %2$d could not be retried. Reloading…', 'aisooq-connector' ),
				/* translators: %d: how many rows are ticked. */
				'selected' => __( 'Retry selected (%d)', 'aisooq-connector' ),
			) ); ?>;

			function say( t, ok ) { if ( msg ) { msg.textContent = t; msg.className = 'aisooq-msg ' + ( ok ? 'is-ok' : 'is-err' ); } }
			function post( action, fields ) {
				var d = new FormData();
				d.append( 'action', action ); d.append( 'nonce', nonce );
				Object.keys( fields ).forEach( function ( k ) {
					var v = fields[ k ];
					// PHP only sees an array under a `name[]` key, and the
					// selection is always an array.
					if ( Array.isArray( v ) ) { v.forEach( function ( item ) { d.append( k + '[]', item ); } ); return; }
					d.append( k, v );
				} );
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: d } ).then( function ( r ) { return r.json(); } );
			}
			function boxes( sel ) { return Array.prototype.slice.call( document.querySelectorAll( sel ) ); }
			function picked() { return boxes( '.aisooq-fo-pick:checked' ); }

			function refresh() {
				if ( ! selBtn ) { return; }
				var n = picked().length;
				selBtn.textContent = s.selected.replace( '%d', n );
				selBtn.disabled = ! canRetry || 0 === n;
			}

			document.addEventListener( 'change', function ( e ) {
				if ( 'aisooq-fo-cb-all' === e.target.id ) {
					boxes( '.aisooq-fo-pick:not([disabled])' ).forEach( function ( b ) { b.checked = e.target.checked; } );
					refresh();
					return;
				}
				if ( e.target.classList && e.target.classList.contains( 'aisooq-fo-pick' ) ) { refresh(); }
			} );

			// ── One row ──────────────────────────────────────────────────
			document.addEventListener( 'click', function ( e ) {
				var b = e.target.closest ? e.target.closest( '.aisooq-fo-retry' ) : null;
				if ( ! b ) { return; }
				var row = b.closest( 'tr' );
				b.disabled = true;
				post( 'aisooq_failed_retry', { order_id: row.getAttribute( 'data-order' ) } ).then( function ( j ) {
					if ( j && j.success ) {
						b.replaceWith( document.createTextNode( ( j.data && j.data.message ) || '' ) );
						row.style.opacity = '.55';
						// Untick it, or the next bulk send would spend a second
						// attempt on an order already on its way.
						var box = row.querySelector( '.aisooq-fo-pick' );
						if ( box ) { box.checked = false; box.disabled = true; }
						refresh();
						return;
					}
					b.disabled = false;
					say( ( j && j.data && j.data.message ) || s.failed, false );
				} ).catch( function () { b.disabled = false; say( s.failed, false ); } );
			} );

			// ── The ticked rows ──────────────────────────────────────────
			function sendSelected( ids, queued, skipped ) {
				say( s.working, true );
				post( 'aisooq_failed_retry_selected', { order_ids: ids } ).then( function ( j ) {
					if ( ! j || ! j.success ) { refresh(); say( ( j && j.data && j.data.message ) || s.failed, false ); return; }
					queued  += j.data.queued;
					skipped += j.data.skipped;
					// retry_orders() caps each call and hands back what it did
					// not reach; stopping here would report a long selection as
					// sent when only its first batch was.
					if ( j.data.remaining && j.data.remaining.length ) { sendSelected( j.data.remaining, queued, skipped ); return; }
					say( s.done.replace( '%1$d', queued ).replace( '%2$d', skipped ), true );
					setTimeout( function () { location.reload(); }, 900 );
				} ).catch( function () { refresh(); say( s.failed, false ); } );
			}

			if ( selBtn ) {
				selBtn.addEventListener( 'click', function () {
					var ids = picked().map( function ( b ) {
						var row = b.closest( 'tr' );
						return row ? row.getAttribute( 'data-order' ) : '';
					} ).filter( Boolean );
					if ( ! ids.length ) { return; }
					selBtn.disabled = true;
					sendSelected( ids, 0, 0 );
				} );
			}

			// ── Everything this view selects ─────────────────────────────
			var scopeBtn = document.getElementById( 'aisooq-fo-retry-scope' );
			if ( scopeBtn ) {
				scopeBtn.addEventListener( 'click', function () {
					scopeBtn.disabled = true;
					var queued = 0, skipped = 0;
					// Cursored: a retried order keeps its attempt count and an
					// un-retryable one never leaves the set, so walking by
					// offset is the only way past them. The filters ride along
					// so the walk covers exactly what is on screen.
					( function step( offset ) {
						say( s.working, true );
						post( 'aisooq_failed_retry_all', Object.assign( { offset: offset }, filters ) ).then( function ( j ) {
							if ( ! j || ! j.success ) { scopeBtn.disabled = false; say( ( j && j.data && j.data.message ) || s.failed, false ); return; }
							queued += j.data.queued; skipped += j.data.skipped;
							// The cursor must have MOVED, not merely be short of
							// the total: if rows leave the set mid-walk the total
							// goes stale, a page comes back empty, and "resume
							// where we were" becomes an unbreakable loop of
							// requests against the same offset.
							if ( j.data.next_offset > offset && j.data.next_offset < j.data.total ) { step( j.data.next_offset ); return; }
							say( s.done.replace( '%1$d', queued ).replace( '%2$d', skipped ), true );
							setTimeout( function () { location.reload(); }, 900 );
						} ).catch( function () { scopeBtn.disabled = false; say( s.failed, false ); } );
					} )( 0 );
				} );
			}

			refresh();
		} )();
		</script>
		<?php
	}
}
