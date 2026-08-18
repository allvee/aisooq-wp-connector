<?php
/**
 * Courier delivery history on orders, checked automatically at placement.
 *
 * The abandoned-cart worklist makes an operator press "Check ratio" per row,
 * which is right there: those are browsing sessions, most never convert, and
 * every lookup is billed. A placed order is different — someone has committed
 * to a COD parcel, and the decision the shop makes next (dispatch, or call
 * first) wants the delivery history in front of them without a click. So this
 * checks once, on placement, in the background.
 *
 * Same persistence contract as the cart worklist: stored on the order, kept
 * until someone presses Recheck, and a "no history" answer is stored too so an
 * unknown number isn't re-queried — and re-charged — on every screen load.
 *
 * Off by default. Turning on a per-order billed lookup without being asked is
 * not a decision a plugin gets to make for a merchant.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Order_Courier {

	const META_RATIO   = '_aisooq_courier_ratio';
	const META_PARCELS = '_aisooq_courier_parcels';
	const META_JSON    = '_aisooq_courier_json';
	const META_CHECKED = '_aisooq_courier_checked_at';
	const META_PHONE   = '_aisooq_courier_phone';
	const ACTION       = 'aisooq_order_courier_check';
	const NONCE        = 'aisooq_order_courier';

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

	public function register() {
		// Queue rather than check inline: the lookup is a network round-trip to
		// the platform and then to BDCourier, and nothing about it should be on
		// the path between a shopper pressing "place order" and seeing the
		// confirmation page.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( self::ACTION, array( $this, 'run_check' ), 10, 1 );

		add_action( 'wp_ajax_aisooq_order_courier_recheck', array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_aisooq_order_courier_detail', array( $this, 'ajax_detail' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// A column on the orders list: the whole point is seeing risk across the
		// day's orders at a glance, not one order at a time.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );

		add_action( 'admin_footer', array( $this, 'assets' ) );
	}

	/** Automatic checking is opt-in — each lookup costs the merchant money. */
	public function is_enabled() {
		return $this->settings->is_active()
			&& ! empty( $this->settings->get( 'auto_courier_check' ) );
	}

	public function on_order_created( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->schedule( $order->get_id() );
		}
	}

	public function on_new_order( $order_id ) {
		$this->schedule( (int) $order_id );
	}

	private function schedule( $order_id ) {
		if ( ! $order_id || ! $this->is_enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || '' === trim( (string) $order->get_billing_phone() ) ) {
			return;
		}
		// Already answered for this exact number — don't buy the same fact twice.
		if ( $this->snapshot( $order ) ) {
			return;
		}
		$args = array( 'order_id' => $order_id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ACTION, $args, AISOOQ_AS_GROUP ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, $args, AISOOQ_AS_GROUP );
		} else {
			// No Action Scheduler (WooCommerce always ships it, but a hard
			// dependency on it here would silently drop the feature).
			$this->run_check( $order_id );
		}
	}

	/**
	 * Perform the lookup and store it.
	 *
	 * @param int|array $args Order id, or the Action Scheduler args array.
	 * @return bool
	 */
	public function run_check( $args ) {
		$order_id = is_array( $args ) ? (int) ( $args['order_id'] ?? 0 ) : (int) $args;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return false;
		}

		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return false;
		}

		$res = $this->api->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( sprintf( 'Courier check for order %d failed: %s', $order_id, $res->get_error_message() ) );
			return false;
		}

		$this->save( $order, $phone, is_array( $res ) ? $res : array() );
		return true;
	}

	/** Persist a lookup onto the order. Mirrors AI_Sooq_Abandoned_Sync::save_courier. */
	public function save( WC_Order $order, $phone, array $payload ) {
		$ratio   = isset( $payload['successRatio'] ) && is_numeric( $payload['successRatio'] ) ? (float) $payload['successRatio'] : null;
		$parcels = isset( $payload['totalParcel'] ) && is_numeric( $payload['totalParcel'] ) ? (int) $payload['totalParcel'] : null;

		$detail = array(
			'success'   => isset( $payload['successParcel'] ) && is_numeric( $payload['successParcel'] ) ? (int) $payload['successParcel'] : null,
			'cancelled' => isset( $payload['cancelledParcel'] ) && is_numeric( $payload['cancelledParcel'] ) ? (int) $payload['cancelledParcel'] : null,
			// Which upstream answered. Absent on a normal BDCourier reply; set
			// when the platform fell back because BDCourier was down. Kept
			// because the two are not interchangeable evidence — a ratio from
			// one courier's own parcels, or from this shop's own deliveries,
			// supports a different decision from a national one, and an
			// operator who is not told cannot know which they are looking at.
			'source'    => isset( $payload['source'] ) && is_string( $payload['source'] )
				? substr( $payload['source'], 0, 40 )
				: null,
			'couriers'  => array(),
		);
		if ( ! empty( $payload['couriers'] ) && is_array( $payload['couriers'] ) ) {
			foreach ( $payload['couriers'] as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$detail['couriers'][] = array(
					'slug'      => isset( $c['slug'] ) ? substr( (string) $c['slug'], 0, 40 ) : '',
					'name'      => isset( $c['name'] ) ? substr( (string) $c['name'], 0, 60 ) : '',
					'total'     => isset( $c['total'] ) ? (int) $c['total'] : 0,
					'success'   => isset( $c['success'] ) ? (int) $c['success'] : 0,
					'cancelled' => isset( $c['cancelled'] ) ? (int) $c['cancelled'] : 0,
					'ratio'     => isset( $c['ratio'] ) && is_numeric( $c['ratio'] ) ? round( (float) $c['ratio'], 2 ) : null,
				);
			}
		}

		$order->update_meta_data( self::META_RATIO, null === $ratio ? '' : $ratio );
		$order->update_meta_data( self::META_PARCELS, null === $parcels ? '' : $parcels );
		// JSON_UNESCAPED_UNICODE: meta values are unslashed on save, which eats
		// \uXXXX escapes and turns them into literal text.
		$order->update_meta_data( self::META_JSON, wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$order->update_meta_data( self::META_CHECKED, gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( self::META_PHONE, $phone );
		$order->save();

		return true;
	}

	/**
	 * Read a stored lookup back, or null when there isn't a usable one.
	 *
	 * Discarded when the order's phone has changed since — an operator can edit
	 * a billing number, and a ratio fetched for the old one does not describe
	 * the new one.
	 */
	public function snapshot( WC_Order $order ) {
		$checked = (string) $order->get_meta( self::META_CHECKED );
		if ( '' === $checked ) {
			return null;
		}
		$for = (string) $order->get_meta( self::META_PHONE );
		if ( '' !== $for && $for !== trim( (string) $order->get_billing_phone() ) ) {
			return null;
		}

		$raw    = $order->get_meta( self::META_JSON );
		$detail = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$detail = is_array( $detail ) ? $detail : array();

		$couriers = array();
		foreach ( ( $detail['couriers'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$couriers[] = array(
				'slug'      => (string) ( $c['slug'] ?? '' ),
				'name'      => (string) ( $c['name'] ?? '' ),
				'total'     => (int) ( $c['total'] ?? 0 ),
				'success'   => (int) ( $c['success'] ?? 0 ),
				'cancelled' => (int) ( $c['cancelled'] ?? 0 ),
				'ratio'     => isset( $c['ratio'] ) && is_numeric( $c['ratio'] ) ? (float) $c['ratio'] : null,
			);
		}

		$ratio   = $order->get_meta( self::META_RATIO );
		$parcels = $order->get_meta( self::META_PARCELS );

		return array(
			'ratio'      => ( '' === $ratio || null === $ratio ) ? null : (float) $ratio,
			'parcels'    => ( '' === $parcels || null === $parcels ) ? null : (int) $parcels,
			'success'    => isset( $detail['success'] ) && null !== $detail['success'] ? (int) $detail['success'] : null,
			'cancelled'  => isset( $detail['cancelled'] ) && null !== $detail['cancelled'] ? (int) $detail['cancelled'] : null,
			'source'     => isset( $detail['source'] ) && is_string( $detail['source'] ) && '' !== $detail['source']
				? $detail['source']
				: null,
			'couriers'   => $couriers,
			'checked_at' => $checked,
		);
	}

	/**
	 * Courier brand marks, mirroring the platform console's `courier-chip.tsx`
	 * so the same parcel looks the same in both places.
	 *
	 * `logo` is a file under assets/img/couriers/. Where we have no artwork the
	 * monogram box stands in — a coloured tile with the courier's initials,
	 * which is still recognisable at a glance in a list and never renders as a
	 * broken image. Upgrading a courier from monogram to real logo is dropping
	 * the file in and adding `logo` here.
	 *
	 * BDCourier returns lowercase slugs; keep the keys lowercase.
	 */
	const COURIER_BRAND = array(
		'steadfast' => array( 'label' => 'Steadfast', 'mono' => 'SF', 'bg' => '#e7f0fb', 'fg' => '#14539a', 'logo' => 'steadfast.svg' ),
		'pathao'    => array( 'label' => 'Pathao',    'mono' => 'P',  'bg' => '#fdeaef', 'fg' => '#b21f45', 'logo' => 'pathao.svg' ),
		'redx'      => array( 'label' => 'RedX',      'mono' => 'RX', 'bg' => '#fdeaea', 'fg' => '#b32d2e', 'logo' => 'redx.svg' ),
		'paperfly'  => array( 'label' => 'Paperfly',  'mono' => 'Pf', 'bg' => '#e8f4fd', 'fg' => '#12628f', 'logo' => 'paperfly.svg' ),
		'ecourier'  => array( 'label' => 'eCourier',  'mono' => 'eC', 'bg' => '#e6f6ee', 'fg' => '#00844a', 'logo' => 'ecourier.svg' ),
		'sundarban' => array( 'label' => 'Sundarban', 'mono' => 'Sb', 'bg' => '#e5f5f4', 'fg' => '#0f6f6a', 'logo' => 'sundarban.jpg' ),
		'carrybee'  => array( 'label' => 'CarryBee',  'mono' => 'CB', 'bg' => '#fdf3e0', 'fg' => '#8a5a00', 'logo' => 'carrybee.png' ),
		'parceldex' => array( 'label' => 'ParcelDex', 'mono' => 'Px', 'bg' => '#ecebfb', 'fg' => '#3f38a8', 'logo' => '' ),
	);

	/**
	 * Brand record for a slug. An unknown courier — BDCourier adds them without
	 * telling anyone — falls back to a neutral tile with its first letter and a
	 * tidied-up name, so it appears sensibly instead of blank.
	 */
	public static function brand_of( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( isset( self::COURIER_BRAND[ $slug ] ) ) {
			return self::COURIER_BRAND[ $slug ];
		}
		return array(
			'label' => '' === $slug ? __( 'Unknown courier', 'aisooq-connector' ) : ucfirst( $slug ),
			'mono'  => '' === $slug ? '?' : strtoupper( substr( $slug, 0, 1 ) ),
			'bg'    => '#f0f0f1',
			'fg'    => '#646970',
			'logo'  => '',
		);
	}

	/**
	 * The courier's logo, or its monogram tile when we ship no artwork for it.
	 *
	 * @param string $slug BDCourier courier slug.
	 * @param string $name Name as the API gave it, used when the slug is unknown.
	 */
	public static function courier_mark( $slug, $name = '' ) {
		$b     = self::brand_of( $slug );
		$label = '' !== trim( (string) $name ) ? trim( (string) $name ) : $b['label'];

		// Guarded on the constants so the mark still renders (as a monogram) in
		// a unit test, where the plugin bootstrap has not run.
		if ( '' !== $b['logo'] && defined( 'AISOOQ_URL' ) && defined( 'AISOOQ_DIR' ) ) {
			$file = AISOOQ_DIR . 'assets/img/couriers/' . $b['logo'];
			if ( file_exists( $file ) ) {
				return sprintf(
					'<span class="aisooq-cmark has-logo"><img src="%s" alt="%s" loading="lazy" decoding="async" /></span>',
					esc_url( AISOOQ_URL . 'assets/img/couriers/' . $b['logo'] ),
					esc_attr( $label )
				);
			}
		}

		return sprintf(
			'<span class="aisooq-cmark" style="background:%s;color:%s" title="%s" aria-hidden="true">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['fg'] ),
			esc_attr( $label ),
			esc_html( $b['mono'] )
		);
	}

	/** Risk band for the badge — same thresholds the platform's gate uses. */
	public static function tone( $ratio ) {
		if ( null === $ratio ) {
			return 'muted';
		}
		return $ratio >= 80 ? 'ok' : ( $ratio >= 60 ? 'warn' : 'err' );
	}

	/**
	 * The delivery-success ratio as a filled bar with the figure on it.
	 *
	 * A bar rather than a bare number because this value is read in a hurry,
	 * one row at a time, to answer "do I trust this COD order?" — a length is
	 * comparable at a glance in a way that 72% next to 68% is not.
	 *
	 * Colour runs continuously from red at 0 to green at 100 (hue 0→120), not
	 * in three steps: the three-tone badge put 79% and 61% in the same bucket
	 * while 80% and 79% looked unrelated, which is exactly backwards for a
	 * threshold operators tune by feel. The tone class is still emitted so the
	 * existing ok/warn/err styling and any CSS overrides keep working.
	 *
	 * Below ~32% the fill is too short to hold the label, so the figure moves
	 * outside it — inside it would either overflow the fill or be clipped, and
	 * a low ratio is precisely the case you must be able to read.
	 */
	public static function ratio_bar( $ratio, $parcels = null, $success = null, $cancelled = null ) {
		$pct  = max( 0, min( 100, (float) $ratio ) );
		$hue  = (int) round( $pct * 1.2 );
		$fill = sprintf( 'hsl(%d 62%% 38%%)', $hue );
		$pos  = $pct >= 32 ? 'inside' : 'outside';
		$tone = self::tone( $ratio );

		/*
		 * When the delivered/returned split is known the bar shows it directly:
		 * green for what landed, red for what came back, and the track showing
		 * through for anything still moving. One length then answers both "how
		 * often does this number take delivery" and "how much of the rest
		 * actually bounced" — a 70% with 30% red is a different parcel from a
		 * 70% with nothing red and three still in transit.
		 *
		 * Colour is never the only carrier: the figure stays on the bar, the
		 * counts sit in their own labelled pills beside it, and the aria-label
		 * spells the whole thing out.
		 */
		$segmented = null !== $success && null !== $cancelled && $parcels > 0;
		$okPct     = $segmented ? max( 0, min( 100, ( (int) $success / (int) $parcels ) * 100 ) ) : 0;
		$errPct    = $segmented ? max( 0, min( 100 - $okPct, ( (int) $cancelled / (int) $parcels ) * 100 ) ) : 0;
		// Round the right end only when the two segments actually reach it;
		// otherwise the red would look finished while parcels are still moving.
		$filled = $okPct + $errPct;
		// Where the figure can be read. Centred and white while it sits over a
		// segment; once most of the bar is bare track it moves right and turns
		// dark, so a low ratio — the one you most need to read — stays legible.
		if ( $segmented ) {
			$pos = $filled >= 55 ? 'inside' : 'outside';
		}

		if ( $segmented ) {
			$label = sprintf(
				/* translators: 1: success percentage, 2: delivered count, 3: returned count, 4: total parcels */
				__( 'Courier delivery success %1$s%% — %2$s delivered, %3$s returned, of %4$s parcels', 'aisooq-connector' ),
				round( $pct ),
				$success,
				$cancelled,
				$parcels
			);
		} elseif ( null === $parcels ) {
			/* translators: %s: delivery success percentage */
			$label = sprintf( __( 'Courier delivery success %s%%', 'aisooq-connector' ), round( $pct ) );
		} else {
			$label = sprintf(
				/* translators: 1: success percentage, 2: number of past parcels */
				__( 'Courier delivery success %1$s%% over %2$s parcels', 'aisooq-connector' ),
				round( $pct ),
				$parcels
			);
		}

		ob_start();
		?>
		<div class="aisooq-ratio <?php echo esc_attr( $tone . ' ' . $pos ); ?>">
			<div class="aisooq-ratio-track" role="progressbar"
				aria-valuemin="0" aria-valuemax="100"
				aria-valuenow="<?php echo esc_attr( round( $pct ) ); ?>"
				aria-label="<?php echo esc_attr( $label ); ?>">
				<?php if ( $segmented ) : ?>
					<div class="aisooq-ratio-fill is-ok<?php echo $errPct <= 0 && $filled >= 99.5 ? ' is-full' : ''; ?>" style="width:<?php echo esc_attr( $okPct ); ?>%"></div>
					<?php if ( $errPct > 0 ) : ?>
						<div class="aisooq-ratio-fill is-err<?php echo $filled >= 99.5 ? ' is-full' : ''; ?>" style="left:<?php echo esc_attr( $okPct ); ?>%;width:<?php echo esc_attr( $errPct ); ?>%"></div>
					<?php endif; ?>
				<?php else : ?>
					<div class="aisooq-ratio-fill" style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $fill ); ?>"></div>
				<?php endif; ?>
				<span class="aisooq-ratio-val"><?php echo esc_html( round( $pct ) . '%' ); ?></span>
			</div>
			<?php
			/*
			 * The parcel total is only spelled out when the caller has no other
			 * way to show it. Once the figures are on their own line above, a
			 * "25 parcels" beside the bar repeats the first pill and takes width
			 * the bar could have used — which is exactly what it did in the real
			 * orders list.
			 */
			?>
			<?php if ( null !== $parcels && ! $segmented ) : ?>
				<span class="aisooq-ratio-meta">
					<?php
					printf(
						/* translators: %s: number of parcels */
						esc_html( _n( '%s parcel', '%s parcels', (int) $parcels, 'aisooq-connector' ) ),
						esc_html( $parcels )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Order screen ────────────────────────────────────────────────────── */

	public function add_meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'aisooq_order_courier',
				__( 'Courier history', 'aisooq-connector' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		echo $this->cell( $order, true ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( self::NONCE, 'aisooq_courier_nonce' );
	}

	/**
	 * The courier cell, rendered server-side and returned verbatim by the AJAX
	 * recheck — so what appears after a check is what a reload renders.
	 */
	public function cell( WC_Order $order, $detailed = false ) {
		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return '<span class="aisooq-dim">' . esc_html__( 'No phone on this order', 'aisooq-connector' ) . '</span>';
		}

		$snap   = $this->snapshot( $order );
		$active = $this->settings->is_active();
		$why    = $active
			? __( 'Look up this number\'s courier delivery history', 'aisooq-connector' )
			: __( 'Connection is paused — activate it in AI Sooq → Settings', 'aisooq-connector' );

		ob_start();
		?>
		<div class="aisooq-ordc" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
			<?php if ( null === $snap ) : ?>
				<button type="button" class="button button-small aisooq-ordc-check" title="<?php echo esc_attr( $why ); ?>" <?php disabled( ! $active ); ?>>
					<?php esc_html_e( 'Check courier history', 'aisooq-connector' ); ?>
				</button>
				<?php if ( ! $active ) : ?>
					<span class="aisooq-ordc-why"><?php esc_html_e( 'connection paused', 'aisooq-connector' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<?php
				$tone    = self::tone( $snap['ratio'] );
				$checked = $snap['checked_at'] ? human_time_diff( strtotime( $snap['checked_at'] . ' UTC' ) ) : '';
				?>
				<?php
				/*
				 * The tally, above the bar: parcels sent | delivered | returned |
				 * past orders at THIS shop.
				 *
				 * The bar answers "how often does this number take delivery"; it
				 * cannot say how much evidence is behind it. 100% over one parcel
				 * and 100% over forty are the same bar and a completely different
				 * decision, so the counts have to be on the row, not one click
				 * away in a panel nobody opens while scanning.
				 *
				 * The fourth figure is deliberately a different kind of number —
				 * how this customer has behaved HERE, which the national ratio
				 * knows nothing about. A 55% number who has taken four parcels
				 * from you and refused none is not a 55% risk to you.
				 */
				$mine = $this->customer_order_count( $order );
				?>
				<div class="aisooq-ordc-row">
				<div class="aisooq-ordc-counts">
					<?php if ( null !== $snap['parcels'] ) : ?>
						<span class="aisooq-pill total" title="<?php esc_attr_e( 'Parcels this number has been sent, across all couriers', 'aisooq-connector' ); ?>">
							<?php echo esc_html( number_format_i18n( $snap['parcels'] ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( null !== $snap['success'] ) : ?>
						<span class="aisooq-pill ok" title="<?php esc_attr_e( 'Delivered', 'aisooq-connector' ); ?>">
							<?php echo esc_html( number_format_i18n( $snap['success'] ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( null !== $snap['cancelled'] ) : ?>
						<span class="aisooq-pill err" title="<?php esc_attr_e( 'Returned / refused', 'aisooq-connector' ); ?>">
							<?php echo esc_html( number_format_i18n( $snap['cancelled'] ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $mine ) : ?>
						<span class="aisooq-pill mine" title="<?php esc_attr_e( 'Orders this customer has placed at this shop before', 'aisooq-connector' ); ?>">
							<?php
							printf(
								/* translators: %s: number of past orders at this shop */
								esc_html( _n( '%s order', '%s orders', (int) $mine, 'aisooq-connector' ) ),
								esc_html( number_format_i18n( $mine ) )
							);
							?>
						</span>
					<?php elseif ( 0 === $mine ) : ?>
						<span class="aisooq-pill first" title="<?php esc_attr_e( 'No previous order at this shop', 'aisooq-connector' ); ?>">
							<?php esc_html_e( 'first', 'aisooq-connector' ); ?>
						</span>
					<?php endif; ?>
				</div>

					<?php if ( null === $snap['ratio'] ) : ?>
						<span class="aisooq-dim aisooq-ordc-nohist" title="<?php esc_attr_e( 'No BDCourier history for this number, or BDCourier is not configured for this store.', 'aisooq-connector' ); ?>">
							<?php esc_html_e( 'No history', 'aisooq-connector' ); ?>
						</span>
					<?php else : ?>
						<?php
						// Delivered/returned are passed so the bar can show the
						// split in place rather than only the headline figure.
						echo self::ratio_bar( $snap['ratio'], $snap['parcels'], $snap['success'], $snap['cancelled'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- ratio_bar() escapes.
						?>
					<?php endif; ?>

					<?php
					/*
					 * Both controls are icons on their own row under the bar.
					 * As text they were the widest things in the column while
					 * being the least-used controls in it, and the figures are
					 * what the column exists to show. Icon-only demands a name
					 * for anyone not seeing it, so the words survive in
					 * aria-label and title — which is also where the "why" copy
					 * explains a paused connection.
					 */
					?>
					<div class="aisooq-ordc-actions">
						<?php if ( ! $detailed ) : ?>
							<button type="button" class="button-link aisooq-ordc-eye aisooq-ordc-icon"
								title="<?php esc_attr_e( 'See the full courier history and this customer\'s past orders', 'aisooq-connector' ); ?>"
								aria-label="<?php esc_attr_e( 'See the full courier history', 'aisooq-connector' ); ?>">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							</button>
						<?php endif; ?>
						<button type="button" class="button-link aisooq-ordc-check aisooq-ordc-icon"
							title="<?php echo esc_attr( $why ); ?>"
							aria-label="<?php esc_attr_e( 'Recheck courier history', 'aisooq-connector' ); ?>"
							<?php disabled( ! $active ); ?>>
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
						</button>
						<?php if ( ! $active ) : ?>
							<span class="aisooq-ordc-why"><?php esc_html_e( 'connection paused', 'aisooq-connector' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $detailed && $snap['couriers'] ) : ?>
					<div class="aisooq-ordc-scroll">
					<table class="aisooq-ordc-tbl">
						<thead><tr>
							<th><?php esc_html_e( 'Courier', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Sent', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Got', 'aisooq-connector' ); ?></th>
							<th><?php esc_html_e( 'Back', 'aisooq-connector' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $snap['couriers'] as $c ) : ?>
							<?php $label = '' !== $c['name'] ? $c['name'] : self::brand_of( $c['slug'] )['label']; ?>
							<tr>
								<td class="aisooq-ordc-courier">
									<?php
									// The logo does the recognising; the name is
									// there for a screen reader and for a courier
									// we ship no artwork for.
									echo self::courier_mark( $c['slug'], $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- courier_mark() escapes.
									?>
									<span class="aisooq-ordc-cname"><?php echo esc_html( $label ); ?></span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td>
								<td class="aisooq-num-ok"><?php echo esc_html( number_format_i18n( $c['success'] ) ); ?></td>
								<td class="aisooq-num-err"><?php echo esc_html( number_format_i18n( $c['cancelled'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>

				<?php
				$hist = $detailed ? $this->customer_history( $order ) : null;
				if ( $hist && $hist['total'] > 0 ) :
					?>
					<div class="aisooq-ordc-hist">
						<strong><?php echo esc_html( sprintf( _n( '%s past order here', '%s past orders here', $hist['total'], 'aisooq-connector' ), number_format_i18n( $hist['total'] ) ) ); ?></strong>
						<span class="aisooq-num-ok"><?php echo esc_html( sprintf( __( '%s kept', 'aisooq-connector' ), number_format_i18n( $hist['completed'] ) ) ); ?></span>
						<span class="aisooq-num-err"><?php echo esc_html( sprintf( __( '%s cancelled', 'aisooq-connector' ), number_format_i18n( $hist['cancelled'] ) ) ); ?></span>
						<?php if ( $hist['spent'] > 0 ) : ?>
							<span class="aisooq-dim"><?php echo wp_kses_post( wc_price( $hist['spent'] ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php elseif ( $detailed && null !== $hist ) : ?>
					<div class="aisooq-ordc-hist aisooq-dim">
						<?php esc_html_e( 'First order from this customer.', 'aisooq-connector' ); ?>
					</div>
				<?php endif; ?>

				<?php
				/*
				 * Say so when the figures did NOT come from the national lookup.
				 * BDCourier goes down; the platform then falls back to Steadfast's
				 * own fraud check, and failing that to this shop's own delivery
				 * outcomes. Both are narrower — a 100% built from three of your
				 * own parcels is not the same evidence as 100% over forty across
				 * every courier — and an operator who is not told cannot know
				 * which they are looking at.
				 */
				$src = $snap['source'];
				if ( $src ) :
					$note = 'steadfast_fraud_check' === $src
						? __( 'From Steadfast only — the national lookup was unavailable.', 'aisooq-connector' )
						: ( 'platform_orders' === $src
							? __( 'From your own deliveries — the national lookup was unavailable.', 'aisooq-connector' )
							: '' );
					?>
					<?php if ( '' !== $note ) : ?>
						<div class="aisooq-ordc-src" title="<?php echo esc_attr( $note ); ?>">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php if ( $detailed ) : ?>
								<?php echo esc_html( $note ); ?>
							<?php else : ?>
								<?php
								echo esc_html(
									'steadfast_fraud_check' === $src
										? __( 'Steadfast only', 'aisooq-connector' )
										: __( 'Your orders only', 'aisooq-connector' )
								);
								?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $detailed && $checked ) : ?>
					<div class="aisooq-dim aisooq-ordc-when">
						<?php
						/* translators: %s: human-readable time difference */
						echo esc_html( sprintf( __( 'Checked %s ago', 'aisooq-connector' ), $checked ) );
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ── Orders list column ──────────────────────────────────────────────── */

	public function add_column( $columns ) {
		$label = __( 'Courier', 'aisooq-connector' );
		$out   = array();
		foreach ( $columns as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'order_status' === $key ) {
				$out['aisooq_courier'] = $label;
			}
		}
		if ( ! isset( $out['aisooq_courier'] ) ) {
			$out['aisooq_courier'] = $label;
		}
		return $out;
	}

	public function render_column_legacy( $column, $post_id ) {
		if ( 'aisooq_courier' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				echo $this->cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	public function render_column_hpos( $column, $order ) {
		if ( 'aisooq_courier' === $column && $order instanceof WC_Order ) {
			echo $this->cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Styles + the one click handler, printed only on screens that show a cell.
	 * Inline because it is small enough that a separate request would cost more
	 * than it saves.
	 */
	public function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? $screen->id : '';
		if ( ! in_array( $id, array( 'edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
			return;
		}
		?>
		<style>
			.aisooq-ordc-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
			/* ── The tally row ──────────────────────────────────────────────
			   sent | delivered | returned | past orders here, then the eye.
			   Tabular figures so the columns of numbers line up down the list
			   instead of jittering row to row. */
			/* Three stacked rows: the figures, the bar they describe, then the
			   controls. Reading order matches decision order — what happened,
			   how it looks as a quantity, what you can do about it — and the
			   bar gets the column's full width instead of competing with
			   buttons for it. */
			.aisooq-ordc-row{display:flex;flex-direction:column;align-items:stretch;gap:5px;min-width:0}
			/* The figures stay on ONE line. Wrapping them mid-set turns four
			   related numbers into two unrelated pairs. */
			.aisooq-ordc-counts{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;min-width:0}
			.aisooq-pill{flex:0 0 auto}
			.aisooq-ordc-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
			.aisooq-pill{position:relative;display:inline-flex;align-items:center;justify-content:center;
				min-width:18px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:600;
				line-height:17px;font-variant-numeric:tabular-nums;white-space:nowrap}
			/* A hairline between the figures. Without it three adjacent numbers
			   read as one, and "16 16 0" is genuinely ambiguous at a glance.
			   Drawn in the gap to the pill's left — the pill is position:relative
			   so this lands beside it, not inside it. */
			.aisooq-pill + .aisooq-pill::before{content:":";position:absolute;left:-6px;top:50%;
				transform:translateY(-50%);color:#8c8f94;font-weight:600;line-height:1}
			.aisooq-pill.total{background:#e9f0f8;color:#2c5c8f}
			.aisooq-pill.ok{background:#e8f3ec;color:#2f6b45}
			.aisooq-pill.err{background:#f7ece9;color:#964a3f}
			/* Past orders HERE is a different kind of fact from the three courier
			   figures beside it, so it gets its own hue rather than reusing one. */
			.aisooq-pill.mine{background:#efeafa;color:#55389c}
			.aisooq-pill.first{background:#f0f0f1;color:#646970;font-weight:500}
			/* ── Courier brand mark ────────────────────────────────────────── */
			.aisooq-cmark{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;
				width:34px;height:20px;border-radius:4px;font-size:10px;font-weight:700;overflow:hidden}
			.aisooq-cmark.has-logo{background:#fff;border:1px solid #e0e0e0;padding:1px}
			.aisooq-cmark img{max-width:100%;max-height:100%;object-fit:contain;display:block}
			.aisooq-ordc-courier{display:flex;align-items:center;gap:6px}
			.aisooq-ordc-cname{overflow:hidden;text-overflow:ellipsis}
			/* The ratio bar. min-width keeps it readable in the orders-list
			   column; flex:1 lets it use the width of a metabox. */
			.aisooq-ratio{display:flex;align-items:center;gap:6px;width:100%;min-width:56px;max-width:168px}
			.aisooq-ratio-track{position:relative;flex:1 1 auto;height:16px;border-radius:999px;background:#f0f0f1;
				border:1px solid #dcdcde;overflow:hidden;min-width:56px}
			/* Custom easing rather than a stock `ease`: the fill settles instead
			   of arriving, which reads as a value being measured. */
			.aisooq-ratio-fill{position:absolute;top:0;bottom:0;left:0;
				transition:width .35s cubic-bezier(.32,.72,0,1)}
			/* Delivered from the left, returned continuing straight on from it,
			   the track showing through for anything still in transit. */
			.aisooq-ratio-fill.is-ok{background:#45805a;border-radius:999px 0 0 999px}
			.aisooq-ratio-fill.is-err{background:#a85a4e}
			/* Nothing still moving: the segments reach the end, so round it off.
			   Set from PHP rather than :last-of-type, which would round the red
			   even when parcels are still in transit and the bar is unfinished. */
			.aisooq-ratio-fill.is-full{border-radius:999px}
			.aisooq-ratio-fill.is-err.is-full{border-radius:0 999px 999px 0}
			@media (prefers-reduced-motion:reduce){
				.aisooq-ratio-fill{transition:none}
			}
			.aisooq-ratio-val{position:absolute;top:0;line-height:16px;font-size:10px;font-weight:700;font-variant-numeric:tabular-nums}
			/* Wide enough to sit on the fill: white on the colour. Too narrow:
			   outside it, dark on the track, so a low ratio stays legible. */
			.aisooq-ratio.inside .aisooq-ratio-val{right:auto;left:0;width:100%;text-align:center;color:#fff;
				text-shadow:0 1px 1px rgba(0,0,0,.28)}
			.aisooq-ratio.outside .aisooq-ratio-val{right:6px;color:#1d2327}
			.aisooq-ratio-meta{font-size:11px;color:#646970;white-space:nowrap}
			.aisooq-ordc-why{font-size:11px;color:#996800;font-style:italic}
			/* Amber, not red: the number is real and usable, it is just drawn
			   from a narrower source than usual. */
			.aisooq-ordc-src{display:flex;align-items:center;gap:4px;margin-top:3px;
				font-size:11px;color:#996800;line-height:1.35}
			.aisooq-ordc-src .dashicons{width:14px;height:14px;font-size:14px;line-height:14px;flex:0 0 auto}
			.aisooq-ordc-when{font-size:11px;margin-top:3px;color:#646970}
			/* The per-courier breakdown can exceed a narrow metabox, so it
			   scrolls on its own rather than stretching the whole panel. */
			.aisooq-ordc-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-top:8px}
			.aisooq-ordc-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:260px}
			.aisooq-ordc-tbl th,.aisooq-ordc-tbl td{padding:4px 6px;border-bottom:1px solid #f0f0f1;text-align:left;white-space:nowrap}
			.aisooq-ordc-tbl th{font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#646970;background:#f6f7f7}
			.aisooq-ordc-tbl td:not(:first-child),.aisooq-ordc-tbl th:not(:first-child){text-align:right}
			/* Delivered vs returned, told by colour as well as position — the
			   two columns are adjacent integers and the eye needs a cue about
			   which way is good. */
			.aisooq-ordc-tbl td.aisooq-num-ok{color:#00844a;font-weight:600}
			.aisooq-ordc-tbl td.aisooq-num-err{color:#b32d2e;font-weight:600}
			/* Recheck + eye share one icon-button shape so the pair reads as a
			   set. touch-action kills the 300ms double-tap delay on the phones
			   this list is mostly read on. */
			/* Two class names deep on purpose: WP's `.wp-core-ui .button-link`
			   underlines its link-buttons, and a single-class rule loses to it —
			   which drew a stray underline under the icon. */
			.aisooq-ordc .aisooq-ordc-icon{display:inline-flex;align-items:center;justify-content:center;
				width:24px;min-width:24px;height:24px;padding:0;border-radius:4px;flex:0 0 auto;
				color:#2271b1;text-decoration:none;cursor:pointer;touch-action:manipulation;
				transition:background-color .18s cubic-bezier(.32,.72,0,1),color .18s cubic-bezier(.32,.72,0,1)}
			.aisooq-ordc .aisooq-ordc-icon:hover:not([disabled]){background:#f0f6fc;color:#135e96}
			.aisooq-ordc .aisooq-ordc-icon:focus-visible{outline:2px solid #2271b1;outline-offset:1px}
			.aisooq-ordc .aisooq-ordc-icon .dashicons{width:16px;height:16px;font-size:16px;line-height:16px}
			.aisooq-ordc-check[disabled]{opacity:.45;cursor:not-allowed;color:#646970}
			@media (prefers-reduced-motion:reduce){
				.aisooq-ordc .aisooq-ordc-icon{transition:none}
			}
			/* Eye: icon-only, so it needs an explicit box rather than WP's
			   text-button padding, or the glyph sits off-centre. It sits on the
			   tally row now and reads as an affordance beside the figures, not
			   as a second grey button competing with Recheck — hence button-link
			   rather than .button. */
			.aisooq-ordc-hist{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-top:8px;
				padding-top:8px;border-top:1px solid #f0f0f1;font-size:12px}
			/* Popup. A plain overlay rather than <dialog>, which Safari only got
			   in 15.4 — a merchant on an older iPad would get no popup at all. */
			.aisooq-modal{position:fixed;inset:0;z-index:100050;display:flex;align-items:center;
				justify-content:center;padding:16px;background:rgba(0,0,0,.5)}
			.aisooq-modal-box{background:#fff;border-radius:8px;max-width:520px;width:100%;
				max-height:85vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.25)}
			.aisooq-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;
				padding:12px 16px;border-bottom:1px solid #dcdcde;position:sticky;top:0;background:#fff}
			.aisooq-modal-head h2{margin:0;font-size:14px;line-height:1.4}
			.aisooq-modal-x{background:none;border:0;cursor:pointer;font-size:20px;line-height:1;
				padding:4px 8px;color:#646970;min-height:32px;min-width:32px}
			.aisooq-modal-body{padding:16px}
			/* On a phone it becomes a bottom sheet — a centred dialog with a
			   scrolling table is unusable one-handed. */
			@media (max-width:600px){
				.aisooq-modal{align-items:flex-end;padding:0}
				.aisooq-modal-box{max-width:none;border-radius:12px 12px 0 0;max-height:92vh}
				.aisooq-modal-x{min-height:40px;min-width:40px;font-size:24px}
			}
			.aisooq-dim{color:#646970}
			/* Touch + narrow screens. WP admin drops to a single column at 782px
			   and the orders table becomes stacked cards, so the bar has to own
			   the row width and the button needs a real tap target (WCAG 2.5.5
			   asks 44px; WP's own small button is 26px high). */
			@media (max-width:782px){
				.aisooq-ratio{max-width:200px}
				.aisooq-ratio-track{height:20px;min-width:44px}
				.aisooq-ratio-val{line-height:22px;font-size:12px}
				.aisooq-ordc-head{gap:10px}
				.aisooq-ordc-tbl th,.aisooq-ordc-tbl td{padding:8px 6px}
				/* The tally is the part a phone user reads first, so it gets
				   bigger figures — and both icons get a real tap target: 26px
				   is well under the 44px WCAG 2.5.5 asks for on touch. */
				.aisooq-ordc-row{gap:6px}
				/* Tighter than desktop, not looser: four figures, two icons and
				   a bar have to share a phone's width, and the gap between
				   pills was costing more room than the pills themselves. */
				.aisooq-ordc-counts{gap:9px}
				.aisooq-pill{font-size:13px;line-height:22px;padding:1px 7px;min-width:24px}
				.aisooq-pill + .aisooq-pill::before{left:-5px;height:14px}
				.aisooq-ordc .aisooq-ordc-icon{width:40px;min-width:40px;height:40px}
				.aisooq-ordc .aisooq-ordc-icon .dashicons{width:20px;height:20px;font-size:20px;line-height:20px}
			}
			/* WP stacks the orders table into cards below 782px and hands each
			   cell the full row width. Without this the tally wraps mid-figure
			   and the column label collides with the pills. */
			@media (max-width:600px){
				.aisooq-ordc{width:100%}
				/* Still capped on a phone: a bar only has to be long enough
				   to compare at a glance, and past that it is width taken from
				   the rest of the card. */
				.aisooq-ratio{max-width:200px}
				.aisooq-cmark{width:38px;height:22px}
			}
			/* Smallest phones: let the figures wrap onto a second line rather
			   than shrink below readable, and keep the eye reachable. */
			@media (max-width:400px){
				.aisooq-ordc-counts{gap:10px}
				.aisooq-pill{font-size:12px;padding:1px 7px}
				.aisooq-ordc-check.button{width:100%;text-align:center}
			}
			@media (pointer:coarse){
				.aisooq-ordc-check.button{min-height:36px}
				.aisooq-ordc .aisooq-ordc-icon{width:40px;min-width:40px;height:40px}
			}
		</style>
		<script>
		( function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var busy  = <?php echo wp_json_encode( __( 'Checking…', 'aisooq-connector' ) ); ?>;
			var failed = <?php echo wp_json_encode( __( 'Check failed', 'aisooq-connector' ) ); ?>;
			var loading = <?php echo wp_json_encode( __( 'Loading…', 'aisooq-connector' ) ); ?>;
			var closeLbl = <?php echo wp_json_encode( __( 'Close', 'aisooq-connector' ) ); ?>;

			var modal = null;
			function closeModal() {
				if ( ! modal ) { return; }
				modal.remove();
				modal = null;
				document.removeEventListener( 'keydown', onKey );
			}
			function onKey( e ) { if ( 'Escape' === e.key ) { closeModal(); } }
			function openModal( title, html ) {
				closeModal();
				modal = document.createElement( 'div' );
				modal.className = 'aisooq-modal';
				modal.setAttribute( 'role', 'dialog' );
				modal.setAttribute( 'aria-modal', 'true' );
				var box = document.createElement( 'div' );
				box.className = 'aisooq-modal-box';
				var head = document.createElement( 'div' );
				head.className = 'aisooq-modal-head';
				var h = document.createElement( 'h2' );
				h.textContent = title;
				var x = document.createElement( 'button' );
				x.type = 'button';
				x.className = 'aisooq-modal-x';
				x.setAttribute( 'aria-label', closeLbl );
				x.innerHTML = '&times;';
				x.addEventListener( 'click', closeModal );
				head.appendChild( h );
				head.appendChild( x );
				var body = document.createElement( 'div' );
				body.className = 'aisooq-modal-body';
				body.innerHTML = html;
				box.appendChild( head );
				box.appendChild( body );
				modal.appendChild( box );
				// Backdrop closes; a click inside must not.
				modal.addEventListener( 'click', function ( ev ) { if ( ev.target === modal ) { closeModal(); } } );
				document.body.appendChild( modal );
				document.addEventListener( 'keydown', onKey );
				x.focus();
			}

			// Eye: show what is already stored. No lookup, so no charge.
			document.addEventListener( 'click', function ( e ) {
				var eye = e.target.closest ? e.target.closest( '.aisooq-ordc-eye' ) : null;
				if ( ! eye ) { return; }
				e.preventDefault();
				var wrap = eye.closest( '.aisooq-ordc' );
				if ( ! wrap ) { return; }
				openModal( loading, '' );
				var body = new FormData();
				body.append( 'action', 'aisooq_order_courier_detail' );
				body.append( 'nonce', nonce );
				body.append( 'order_id', wrap.dataset.order );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) { openModal( j.data.title, j.data.html ); }
						else { openModal( failed, '' ); }
					} )
					.catch( function () { openModal( failed, '' ); } );
			} );

			// Delegated: the orders list redraws rows on filter, and a bound
			// handler would be lost on every redraw.
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.aisooq-ordc-check' );
				if ( ! btn || btn.disabled ) { return; }
				e.preventDefault();
				var wrap = btn.closest( '.aisooq-ordc' );
				if ( ! wrap ) { return; }

				var original = btn.innerHTML;
				btn.disabled = true;
				btn.textContent = busy;

				var body = new FormData();
				body.append( 'action', 'aisooq_order_courier_recheck' );
				body.append( 'nonce', nonce );
				body.append( 'order_id', wrap.dataset.order );
				body.append( 'detailed', wrap.closest( '#aisooq_order_courier' ) ? '1' : '' );

				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success && j.data.html && wrap.parentNode ) {
							var tmp = document.createElement( 'div' );
							tmp.innerHTML = j.data.html;
							var fresh = tmp.firstElementChild;
							if ( fresh ) { wrap.parentNode.replaceChild( fresh, wrap ); return; }
						}
						btn.disabled = false;
						btn.innerHTML = original;
						window.alert( ( j && j.data && j.data.message ) || failed );
					} )
					.catch( function () {
						btn.disabled = false;
						btn.innerHTML = original;
						window.alert( failed );
					} );
			} );
		}() );
		</script>
		<?php
	}

	/* ── AJAX ────────────────────────────────────────────────────────────── */

	public function ajax_recheck() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Connection is paused. Activate it first.', 'aisooq-connector' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'aisooq-connector' ) ) );
		}

		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'No phone on this order.', 'aisooq-connector' ) ) );
		}

		$res = $this->api->get( '/connect/courier?phone=' . rawurlencode( $phone ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$this->save( $order, $phone, is_array( $res ) ? $res : array() );

		$fresh = wc_get_order( $order_id );
		if ( ! $fresh || null === $this->snapshot( $fresh ) ) {
			wp_send_json_error( array( 'message' => __( 'Looked it up, but the result could not be saved.', 'aisooq-connector' ) ) );
		}

		$detailed = ! empty( $_POST['detailed'] );
		wp_send_json_success( array( 'html' => $this->cell( $fresh, $detailed ) ) );
	}

	/**
	 * The stored detail, for the eye button's popup.
	 *
	 * Deliberately does NOT look anything up: every BDCourier call is billed,
	 * and opening a panel to read a number you already paid for must not buy it
	 * again. Recheck is the only thing that spends money, and it is a separate,
	 * explicit press.
	 */
	public function ajax_detail() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aisooq-connector' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'aisooq-connector' ) ) );
		}
		wp_send_json_success(
			array(
				'html'  => $this->cell( $order, true ),
				/* translators: %s: order number */
				'title' => sprintf( __( 'Order #%s — courier history', 'aisooq-connector' ), $order->get_order_number() ),
			)
		);
	}

	/**
	 * How this customer has behaved at THIS shop, alongside their behaviour
	 * across the courier network.
	 *
	 * The BDCourier ratio describes a phone number nationally; it says nothing
	 * about whether this particular shop has been paid by this particular
	 * person before. A 55% national ratio reads very differently when the
	 * customer has taken four parcels from you and refused none — which is
	 * exactly the call an operator is making when they look at a COD order.
	 *
	 * Matched on billing e-mail first (stable, and what WooCommerce indexes),
	 * falling back to the phone, so guest checkouts still resolve. The current
	 * order is excluded — it is not part of its own history.
	 */
	/** Are orders stored in WooCommerce's own tables (HPOS) rather than posts? */
	private static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Order ids carrying this billing phone, on the legacy post store.
	 *
	 * Direct SQL because that store rejects both `billing_phone` and
	 * `meta_query`, and there is no other way to ask it the question. Bounded
	 * and prepared; the phone is the only input.
	 */
	private static function order_ids_by_phone( $phone, $exclude_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = '_billing_phone'
				    AND pm.meta_value = %s
				    AND p.post_type = 'shop_order'
				    AND p.ID <> %d
				  ORDER BY p.post_date DESC
				  LIMIT 100",
				$phone,
				(int) $exclude_id
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many past orders this customer has here — the number alone.
	 *
	 * Split out from customer_history() because the orders LIST now shows it on
	 * every row, and hydrating up to 100 WC_Order objects per row to arrive at
	 * one integer would be the single most expensive thing on that screen.
	 * `return => 'ids'` keeps the same query and the same HPOS/legacy handling
	 * without instantiating anything.
	 *
	 * Memoised per request: the list renders one cell per row, and a shop where
	 * the same customer has several pending orders would otherwise repeat an
	 * identical query for each of them.
	 *
	 * @return int|null null when the order carries neither e-mail nor phone.
	 */
	public function customer_order_count( WC_Order $order ) {
		static $memo = array();

		$args = $this->history_query_args( $order, 'ids' );
		if ( null === $args ) {
			return null;
		}
		// Keyed on what actually varies the answer. The current order is
		// excluded from its own history, so it belongs in the key too.
		$key = md5( wp_json_encode( $args ) );
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		if ( array() === $args ) {
			$memo[ $key ] = 0;
			return 0;
		}

		$ids          = wc_get_orders( $args );
		$memo[ $key ] = is_array( $ids ) ? count( $ids ) : 0;
		return $memo[ $key ];
	}

	/**
	 * The `wc_get_orders` arguments that mean "this customer's other orders".
	 *
	 * Shared by the count and the full history so the two can never disagree
	 * about who the customer is — they were written apart once and the list
	 * would have said "2 orders" next to a panel listing three.
	 *
	 * @param string $return 'objects' or 'ids'.
	 * @return array|null Empty array = definitively no past orders (skip the
	 *                    query); null = no identifier to match on at all.
	 */
	private function history_query_args( WC_Order $order, $return = 'objects' ) {
		$email = trim( (string) $order->get_billing_email() );
		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $email && '' === $phone ) {
			return null;
		}

		$args = array(
			'limit'   => 100,
			'return'  => $return,
			'exclude' => array( $order->get_id() ),
			'status'  => array_keys( wc_get_order_statuses() ),
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( '' !== $email ) {
			$args['billing_email'] = $email;
			return $args;
		}

		// Phone-only is the common case here, not an edge: Bangladeshi
		// checkouts frequently collect no e-mail at all.
		//
		// How to ask depends on where orders live. HPOS understands
		// `billing_phone`; the legacy post store does NOT, and does not accept
		// `meta_query` either — it answers a _doing_it_wrong notice and then
		// returns EVERY order, which would report a first-time buyer as a
		// regular. So that store is queried by id, resolved from postmeta first.
		if ( self::hpos_enabled() ) {
			$args['billing_phone'] = $phone;
			return $args;
		}

		$ids = self::order_ids_by_phone( $phone, $order->get_id() );
		if ( ! $ids ) {
			return array();
		}
		$args['include'] = $ids;
		return $args;
	}

	public function customer_history( WC_Order $order ) {
		$args = $this->history_query_args( $order, 'objects' );
		if ( null === $args ) {
			return null;
		}
		if ( array() === $args ) {
			return array( 'total' => 0, 'completed' => 0, 'cancelled' => 0, 'spent' => 0.0 );
		}

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) || ! $orders ) {
			return array( 'total' => 0, 'completed' => 0, 'cancelled' => 0, 'spent' => 0.0 );
		}

		$out = array( 'total' => 0, 'completed' => 0, 'cancelled' => 0, 'spent' => 0.0 );
		foreach ( $orders as $o ) {
			if ( ! is_a( $o, 'WC_Order' ) ) {
				continue;
			}
			$out['total']++;
			$status = $o->get_status();
			if ( in_array( $status, array( 'completed', 'processing' ), true ) ) {
				$out['completed']++;
				$out['spent'] += (float) $o->get_total();
			} elseif ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				$out['cancelled']++;
			}
		}
		return $out;
	}
}
