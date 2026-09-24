<?php
/**
 * The chrome every AI Sooq screen wears: app bar, sidebar, footer.
 *
 * WHY THIS IS NOT IN ANY ONE SCREEN
 * ---------------------------------
 * Four screens share it — Settings, Blocked, Failed syncs and Abandoned carts.
 * Each of those classes already carries its own data access, AJAX endpoints and
 * table rendering; adding an application shell to one of them would have made
 * the other three depend on it for their frame, which is a dependency in the
 * wrong direction between siblings that share nothing else.
 *
 * It began life as the settings screen's chrome alone. The moment the other
 * three adopted the design it stopped being "the settings view" and was renamed
 * rather than copied, because four copies of a header is how four headers start
 * disagreeing about what the connection pill says.
 *
 * So the split is by REASON TO CHANGE, not by size. Everything here is
 * presentation: it is handed plain arrays and prints markup. It reads no
 * options, makes no HTTP calls, and touches no globals beyond the translation
 * functions — which is what makes it safe to render twice, in any order, and
 * what lets a test assert its output without booting a connection.
 *
 * Every method ECHOES rather than returns. These are page fragments measured in
 * kilobytes and always printed immediately; returning them would only add a
 * concatenation and invite a caller to forget to escape.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Admin_Shell {

	/**
	 * Every AI Sooq screen, in menu order.
	 *
	 * ONE list, because each screen has to draw links to the other three and
	 * four hand-kept copies of that is how a renamed screen keeps its old name
	 * on three pages. Keyed by page slug so a caller can say "everything but
	 * me" without matching on a label.
	 */
	public static function screens() {
		return array(
			AI_Sooq_Settings::PAGE_SLUG        => array( 'icon' => 'gear',                'label' => __( 'Settings', 'aisooq-connector' ) ),
			AI_Sooq_Blocklist_Admin::PAGE_SLUG => array( 'icon' => 'shield-slash',        'label' => __( 'Blocked', 'aisooq-connector' ) ),
			AI_Sooq_Failed_Admin::PAGE_SLUG    => array( 'icon' => 'warning-circle',      'label' => __( 'Failed syncs', 'aisooq-connector' ) ),
			AI_Sooq_Abandoned_Admin::PAGE_SLUG => array( 'icon' => 'shopping-cart-simple', 'label' => __( 'Abandoned carts', 'aisooq-connector' ) ),
		);
	}

	/**
	 * The other three screens, as sidebar links.
	 *
	 * @param string $current The slug of the screen asking. Omitted from the result.
	 */
	public static function other_screens( $current ) {
		$out = array();
		foreach ( self::screens() as $slug => $screen ) {
			if ( $slug === $current ) {
				continue;
			}
			$out[] = array(
				'url'   => admin_url( 'admin.php?page=' . $slug ),
				'icon'  => $screen['icon'],
				'label' => $screen['label'],
			);
		}
		return $out;
	}

	/**
	 * What the app bar's connection pill says.
	 *
	 * Three states, and the order matters: "paused" beats "connected", because
	 * a store that is verified but switched off is not syncing, and leading
	 * with the green word is how an operator spends an afternoon wondering
	 * where their orders went.
	 *
	 * Lives here rather than on the settings screen because all four screens
	 * show this pill, and a second copy would be a second opinion.
	 */
	public static function state( AI_Sooq_Settings $settings ) {
		$status = $settings->status();

		if ( ! $settings->is_active() ) {
			return array(
				'state_class' => 'is-warn',
				'state_label' => __( 'Paused', 'aisooq-connector' ),
				'state_title' => __( 'Syncing is switched off on the Connection tab. Settings are kept.', 'aisooq-connector' ),
			);
		}

		if ( ! empty( $status['ok'] ) ) {
			return array(
				'state_class' => '',
				'state_label' => __( 'Connected', 'aisooq-connector' ),
				'state_title' => sprintf(
					/* translators: 1: store SID, 2: when the connection was last verified. */
					__( 'SID %1$s · Last verified %2$s', 'aisooq-connector' ),
					isset( $status['sid'] ) ? $status['sid'] : '?',
					isset( $status['time'] ) ? $status['time'] : '—'
				),
			);
		}

		return array(
			'state_class' => 'is-err',
			'state_label' => __( 'Not verified', 'aisooq-connector' ),
			'state_title' => __( 'Save your credentials, then press Verify.', 'aisooq-connector' ),
		);
	}

	/**
	 * Open a screen: everything from `.wrap` down to the start of the main
	 * column. Pair with close().
	 *
	 * Printing the frame as an open/close pair rather than wrapping a callback
	 * is deliberate. Every one of these screens builds its body by echoing in
	 * a long procedural run — that is how they were already written — and a
	 * callback would have meant restructuring four classes to buy nothing.
	 *
	 * @param array $args {
	 *     @type string             $slug     Current screen's page slug.
	 *     @type string             $title    Accessible page title (the <h1>).
	 *     @type AI_Sooq_Settings   $settings For the connection pill.
	 *     @type array              $sync     Sync buttons, or [] for none.
	 *     @type bool               $verify   Whether to offer the Verify button.
	 *     @type callable|null      $nav      Prints the sidebar's own nav, if any.
	 * }
	 */
	public static function open( array $args ) {
		$slug = isset( $args['slug'] ) ? $args['slug'] : '';
		/*
		 * `legacy` keeps a screen's OLD wrapper class alongside the new ones.
		 * The abandoned-carts screen still draws its cart modal, row action
		 * menu and courier cell from rules scoped to `.wrap.aisooq-ab` in
		 * aisooq-admin.css; dropping that class would have left those three
		 * components unstyled the moment the shell was adopted. The shell's own
		 * rules are `.wrap.aisooq.aisooq-app .x` and so outrank them wherever
		 * the two overlap. Remove a legacy class only once its components have
		 * been ported, not before.
		 */
		$classes = trim( 'wrap aisooq aisooq-app ' . ( isset( $args['legacy'] ) ? $args['legacy'] : '' ) );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<?php
			/*
			 * wp-admin needs an <h1> in `.wrap` — it is where it splices admin
			 * notices in, and a screen with no level-one heading is a real
			 * navigation failure for a screen-reader user. The app bar carries
			 * the visible identity, so this one is for those two jobs only.
			 */
			?>
			<h1 class="screen-reader-text"><?php echo esc_html( isset( $args['title'] ) ? $args['title'] : '' ); ?></h1>

			<?php
			self::app_bar( array(
				'name'   => __( 'AI Sooq', 'aisooq-connector' ),
				'sync'   => isset( $args['sync'] ) ? $args['sync'] : array(),
				'verify' => ! empty( $args['verify'] ),
			) + self::state( $args['settings'] ) );
			?>

			<div class="aisooq-body">
				<aside class="aisooq-side">
					<?php
					if ( isset( $args['nav'] ) && is_callable( $args['nav'] ) ) {
						call_user_func( $args['nav'] );
					}
					self::screen_links( self::other_screens( $slug ) );
					?>
				</aside>

				<main class="aisooq-main">
		<?php
	}

	/** Close a screen opened with open(). */
	public static function close( array $args ) {
		$slug = isset( $args['slug'] ) ? $args['slug'] : '';
		?>
					<?php self::main_links( self::other_screens( $slug ) ); ?>
					<?php self::footer( AISOOQ_VERSION ); ?>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * A sidebar nav of links — the sibling screens, and on a list screen its
	 * own filters. Separate from the settings screen's tablist because these
	 * navigate away or reload; they are links, and calling them tabs would
	 * promise a widget that is not there.
	 *
	 * @param array  $items array( url, icon, label, count?, active? ).
	 * @param string $label Accessible name for the nav landmark.
	 * @param string $class Extra class on the <nav>.
	 */
	public static function link_nav( array $items, $label, $class = '' ) {
		if ( ! $items ) {
			return;
		}
		?>
		<nav class="aisooq-nav aisooq-nav--links <?php echo esc_attr( $class ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<a class="aisooq-navitem<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $item['url'] ); ?>"
					<?php echo ! empty( $item['active'] ) ? ' aria-current="page"' : ''; ?>>
					<?php echo AI_Sooq_Icons::svg( $item['icon'], array( 'size' => 20 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $item['label'] ); ?></span>
					<?php if ( isset( $item['count'] ) ) : ?>
						<span class="aisooq-navitem__count"><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * The bar across the top: identity, connection state, and the two things
	 * you press that are not "save".
	 *
	 * @param array $args {
	 *     @type string $name        Product name shown beside the mark.
	 *     @type string $state_class One of `is-ok`, `is-warn`, `is-err`.
	 *     @type string $state_label Short state word, e.g. "Connected".
	 *     @type string $state_title The detail behind it — SID, last verified.
	 *     @type array  $sync        entity slug => button label.
	 * }
	 */
	public static function app_bar( array $args ) {
		$name        = isset( $args['name'] ) ? $args['name'] : '';
		$state_class = isset( $args['state_class'] ) ? $args['state_class'] : '';
		$state_label = isset( $args['state_label'] ) ? $args['state_label'] : '';
		$state_title = isset( $args['state_title'] ) ? $args['state_title'] : '';
		$sync        = isset( $args['sync'] ) && is_array( $args['sync'] ) ? $args['sync'] : array();
		$args        = $args + array( 'verify' => false );
		?>
		<header class="aisooq-appbar">
			<div class="aisooq-appbar__brand">
				<span class="aisooq-appbar__mark"><?php echo AI_Sooq_Icons::svg( 'chats', array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from a fixed path table. ?></span>
				<span class="aisooq-appbar__name"><?php echo esc_html( $name ); ?></span>
			</div>

			<span class="aisooq-state <?php echo esc_attr( $state_class ); ?>" title="<?php echo esc_attr( $state_title ); ?>">
				<span class="aisooq-state__dot" aria-hidden="true"></span>
				<?php echo esc_html( $state_label ); ?>
			</span>

			<?php
			/*
			 * Verify and the sync group belong to the settings screen: they
			 * post to its AJAX endpoints with its nonce, and a Verify button on
			 * the Blocked list is an action with nothing to do with the page it
			 * sits on. The bar's identity half is the part every screen shares.
			 */
			?>
			<?php if ( ! empty( $args['verify'] ) ) : ?>
				<button type="button" id="aisooq-test-connection" class="aisooq-btn aisooq-btn--secondary">
					<?php echo AI_Sooq_Icons::svg( 'plugs-connected' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Verify', 'aisooq-connector' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $sync ) : ?>
				<?php
				/*
				 * A group label rather than a visible "Sync:" caption: the
				 * arrows glyph carries that meaning for a sighted operator, and
				 * without the group name a screen reader announces four bare
				 * nouns ("Orders", "Products"…) with nothing saying what
				 * pressing one does.
				 */
				?>
				<div class="aisooq-syncgroup" role="group" aria-label="<?php esc_attr_e( 'Sync now', 'aisooq-connector' ); ?>">
					<span class="aisooq-syncgroup__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'arrows-clockwise' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php foreach ( $sync as $entity => $label ) : ?>
						<button type="button" class="aisooq-btn aisooq-btn--ghost aisooq-sync" data-entity="<?php echo esc_attr( $entity ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php
			// role=status + aria-live: the result of Verify/Sync is written here
			// by script, and without this a screen-reader user gets no
			// announcement at all — the button appears to do nothing. `polite`
			// so it waits for a pause rather than interrupting.
			?>
			<span id="aisooq-test-result" class="aisooq-appbar__result" role="status" aria-live="polite" aria-atomic="true"></span>
		</header>
		<?php
	}

	/**
	 * The sticky "you have unsaved work" bar.
	 *
	 * Hidden in the markup and revealed by script, because a bar that claims
	 * there are unsaved changes on a freshly loaded page is worse than no bar.
	 * Its Save is deliberately NOT a second submit button — see the comment on
	 * the button itself.
	 */
	public static function unsaved_bar() {
		?>
		<div class="aisooq-unsaved" id="aisooq-unsaved" role="status" hidden>
			<span class="aisooq-unsaved__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'warning-circle', array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="aisooq-unsaved__msg"><?php esc_html_e( 'Unsaved changes', 'aisooq-connector' ); ?></span>
			<button type="button" class="aisooq-btn aisooq-btn--secondary" id="aisooq-discard"><?php esc_html_e( 'Discard', 'aisooq-connector' ); ?></button>
			<?php
			/*
			 * A PROXY, not a submit. The form must carry exactly one control
			 * named `aisooq_save` — two would post the value twice and, more to
			 * the point, tests/test-settings-page.php asserts the count because
			 * a second one is how a form quietly starts double-saving. This
			 * one clicks the real button at the bottom of the form.
			 */
			?>
			<button type="button" class="aisooq-btn aisooq-btn--primary" id="aisooq-save-proxy"><?php esc_html_e( 'Save', 'aisooq-connector' ); ?></button>
		</div>
		<?php
	}

	/** The search field. One nav, so one of these. */
	public static function search( $id ) {
		?>
		<div class="aisooq-search">
			<span class="aisooq-search__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'magnifying-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Find a setting', 'aisooq-connector' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $id ); ?>" class="aisooq-find" placeholder="<?php esc_attr_e( 'Search settings', 'aisooq-connector' ); ?>" autocomplete="off" />
		</div>
		<?php
	}

	/**
	 * The navigation: search, the six sections, and the sibling screens.
	 *
	 * ONE nav, not one per breakpoint. The obvious way to build the design's
	 * two shapes — a 220px column on a desktop, a row of swipeable pills on a
	 * phone — is to render both and hide one. That was the first attempt and it
	 * is an accessibility bug: `display:none` takes the column out of the focus
	 * order as well as the layout, so on a phone there was no keyboard-reachable
	 * way to change tab at all. Duplicating it instead means two elements
	 * claiming `aria-controls` over the same panels and two competing
	 * `aria-selected` states, which is an authoring error whichever one is
	 * visible.
	 *
	 * So this renders once and `aisooq-app.css` reflows it: the same
	 * buttons, the same ARIA, the same tab order, laid out as a column or as a
	 * scrolling row. Nothing needs to be kept in step because there is only one
	 * of everything.
	 *
	 * @param array $sections key => array( icon, label ), from AI_Sooq_Settings.
	 * @param array $links    array( url, icon, label ) for the other screens.
	 */
	public static function settings_nav( array $sections ) {
		self::search( 'aisooq-find' );
		?>
		<nav class="aisooq-nav" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'aisooq-connector' ); ?>">
			<?php foreach ( $sections as $key => $sec ) : ?>
				<button type="button" class="aisooq-navitem aisooq-tab" id="aisooq-tab-<?php echo esc_attr( $key ); ?>" data-tab="<?php echo esc_attr( $key ); ?>"
					role="tab" aria-controls="aisooq-panel-<?php echo esc_attr( $key ); ?>" aria-selected="false" tabindex="-1">
					<?php echo AI_Sooq_Icons::svg( $sec['icon'], array( 'size' => 20 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $sec['label'] ); ?></span>
					<span class="aisooq-navitem__count aisooq-tab__count" hidden></span>
				</button>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/** The other screens, at the foot of the sidebar. */
	public static function screen_links( array $links ) {
		?>
		<nav class="aisooq-side__links" aria-label="<?php esc_attr_e( 'Other AI Sooq screens', 'aisooq-connector' ); ?>">
			<?php foreach ( $links as $link ) : ?>
				<a class="aisooq-sidelink" href="<?php echo esc_url( $link['url'] ); ?>">
					<?php echo AI_Sooq_Icons::svg( $link['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $link['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/** The sibling-screen links again, at the foot of the column on a phone. */
	public static function main_links( array $links ) {
		?>
		<div class="aisooq-main__links">
			<?php foreach ( $links as $link ) : ?>
				<a class="aisooq-sidelink aisooq-sidelink--pill" href="<?php echo esc_url( $link['url'] ); ?>">
					<?php echo AI_Sooq_Icons::svg( $link['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $link['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The store strip: who this site is connected to, at a glance.
	 *
	 * @param string $name  Store name, or the SID when it has no name yet.
	 * @param array  $meta  array( icon, label, value ) rows. Already filtered
	 *                      to the ones that have a value.
	 */
	public static function store_bar( $name, array $meta ) {
		$initial = '' !== (string) $name ? mb_strtoupper( mb_substr( (string) $name, 0, 1 ) ) : 'S';
		?>
		<div class="aisooq-storebar">
			<div class="aisooq-storebar__id">
				<span class="aisooq-storebar__avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
				<span class="aisooq-storebar__name"><?php echo esc_html( $name ); ?></span>
			</div>
			<?php foreach ( $meta as $row ) : ?>
				<span class="aisooq-storebar__meta" title="<?php echo esc_attr( $row['label'] ); ?>">
					<?php echo AI_Sooq_Icons::svg( $row['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $row['value'] ); ?></span>
				</span>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The six figures.
	 *
	 * @param array $stats array( icon, label, value, sub, tone ).
	 */
	public static function stats( array $stats ) {
		?>
		<div class="aisooq-stats">
			<?php foreach ( $stats as $st ) : ?>
				<div class="aisooq-stat <?php echo esc_attr( isset( $st['tone'] ) ? $st['tone'] : '' ); ?>" title="<?php echo esc_attr( isset( $st['sub'] ) ? $st['sub'] : '' ); ?>">
					<span class="aisooq-stat__label">
						<?php echo AI_Sooq_Icons::svg( $st['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $st['label'] ); ?>
					</span>
					<span class="aisooq-stat__num"><?php echo esc_html( $st['value'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Setup guide and permissions, side by side.
	 *
	 * Both are <details>/<summary> rather than a button plus script, so they
	 * open and close with no JavaScript at all — the progress figure is the
	 * kind of thing an operator checks on a page whose scripts failed.
	 *
	 * @param array $steps array( label, done, tab ) — `tab` may be '' for a
	 *                     step that is not a place you can go.
	 * @param int   $granted Number of OAuth scopes, 0 when disconnected.
	 * @param array $perms   resource => array of action names.
	 */
	public static function disclosures( array $steps, $granted, array $perms ) {
		$total = count( $steps );
		$done  = 0;
		foreach ( $steps as $step ) {
			$done += ! empty( $step['done'] ) ? 1 : 0;
		}
		$pct = $total > 0 ? round( ( $done / $total ) * 100 ) : 0;
		?>
		<div class="aisooq-discs">
			<details class="aisooq-disc">
				<summary class="aisooq-disc__head">
					<span class="aisooq-disc__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'rocket-launch', array( 'size' => 17 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="aisooq-disc__label"><?php esc_html_e( 'Setup guide', 'aisooq-connector' ); ?></span>
					<span class="aisooq-disc__count"><?php
						printf(
							/* translators: 1: completed steps, 2: total steps. */
							esc_html__( '%1$d/%2$d', 'aisooq-connector' ),
							(int) $done,
							(int) $total
						);
					?></span>
					<span class="aisooq-progress" aria-hidden="true"><span class="aisooq-progress__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span></span>
				</summary>
				<div class="aisooq-disc__body">
					<?php foreach ( $steps as $step ) : ?>
						<?php
						$icon = ! empty( $step['done'] ) ? 'check-circle-fill' : 'circle';
						$cls  = ! empty( $step['done'] ) ? 'aisooq-step' : 'aisooq-step is-todo';
						?>
						<?php if ( ! empty( $step['tab'] ) ) : ?>
							<button type="button" class="<?php echo esc_attr( $cls ); ?>" data-goto="<?php echo esc_attr( $step['tab'] ); ?>">
								<span class="aisooq-step__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( $icon, array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<?php echo esc_html( $step['label'] ); ?>
							</button>
						<?php else : ?>
							<span class="<?php echo esc_attr( $cls ); ?>">
								<span class="aisooq-step__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( $icon, array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<?php echo esc_html( $step['label'] ); ?>
							</span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</details>

			<?php if ( $perms ) : ?>
				<details class="aisooq-disc">
					<summary class="aisooq-disc__head">
						<span class="aisooq-disc__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'key', array( 'size' => 17 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="aisooq-disc__label"><?php esc_html_e( 'Permissions', 'aisooq-connector' ); ?></span>
						<span class="aisooq-tag aisooq-tag--accent"><?php echo esc_html( number_format_i18n( $granted ) ); ?></span>
					</summary>
					<div class="aisooq-disc__body">
						<div class="aisooq-perms">
							<?php foreach ( $perms as $resource => $actions ) : ?>
								<div class="aisooq-perm">
									<span class="aisooq-perm__name">
										<?php echo AI_Sooq_Icons::svg( self::perm_icon( $resource ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php echo esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $resource ) ) ); ?>
									</span>
									<span class="aisooq-perm__acts">
										<?php foreach ( $actions as $action ) : ?>
											<span class="aisooq-tag <?php echo esc_attr( self::scope_tag( $action ) ); ?>"><?php echo esc_html( $action ); ?></span>
										<?php endforeach; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Which tag a scope wears.
	 *
	 * Write is the one that can change a merchant's data, so it is the one that
	 * carries colour. Read is outlined and everything else is neutral — a grid
	 * where every chip shouts is a grid nobody reads.
	 */
	private static function scope_tag( $action ) {
		if ( 'write' === $action ) {
			return 'aisooq-tag--accent';
		}
		return 'read' === $action ? 'aisooq-tag--outline' : 'aisooq-tag--neutral';
	}

	/** Glyph for a permission resource group. */
	private static function perm_icon( $resource ) {
		$map = array(
			'orders'      => 'shopping-cart',
			'products'    => 'bag',
			'customers'   => 'users',
			'categories'  => 'folder',
			'collections' => 'folders',
			'brands'      => 'tag',
			'inventory'   => 'package',
			'analytics'   => 'chart-bar',
			'fraud'       => 'shield-check',
			'courier'     => 'truck',
			'shipping'    => 'truck',
			'costs'       => 'coins',
			'discounts'   => 'percent',
			'returns'     => 'arrow-u-up-left',
			'reviews'     => 'star',
			'support'     => 'lifebuoy',
			'webhooks'    => 'webhooks-logo',
			'qa'          => 'question',
		);
		return isset( $map[ $resource ] ) ? $map[ $resource ] : 'database';
	}

	/**
	 * A notice that belongs to the page rather than to wp-admin's notice stack.
	 *
	 * WordPress's own `.notice` is hoisted to just under the <h1> by its admin
	 * script, which on these screens means "above the app bar" — detached from
	 * whatever it is talking about. This one stays where it is printed.
	 *
	 * @param string $icon A bundled glyph name.
	 * @param string $text The message. Plain text; no markup is accepted.
	 * @param string $tone '', 'warn' or 'err'.
	 */
	public static function note( $icon, $text, $tone = '' ) {
		$class = 'aisooq-note' . ( '' !== $tone ? ' aisooq-note--' . $tone : '' );
		?>
		<div class="<?php echo esc_attr( $class ); ?>" role="status">
			<?php echo AI_Sooq_Icons::svg( $icon, array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * What a table says when it has nothing to show.
	 *
	 * Always a sentence about why it is empty, never the bare word "None".
	 * "No refused checkouts" and "screening is switched off" look identical in
	 * an empty table and mean opposite things.
	 */
	public static function empty_state( $icon, $title, $text ) {
		?>
		<div class="aisooq-empty">
			<div class="aisooq-empty__icon"><?php echo AI_Sooq_Icons::svg( $icon, array( 'size' => 28 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<p class="aisooq-empty__title"><?php echo esc_html( $title ); ?></p>
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * A date-range control: a button showing the current range, and a popover
	 * with preset shortcuts beside a two-month calendar.
	 *
	 * WHY NOT TWO `<input type="date">`
	 * ---------------------------------
	 * That is what this replaced, and it was two separate questions pretending
	 * to be one. The browser's picker opens on whichever month the field
	 * happens to hold, shows no relationship between the two fields, and offers
	 * no way to say "last 30 days" — which is what an operator actually wants
	 * nine times out of ten. It also renders differently in every browser, so
	 * the screen's most-used control was the one piece of it nobody had
	 * designed.
	 *
	 * THE INPUTS SURVIVE, HIDDEN
	 * --------------------------
	 * `from` and `to` are still real inputs with their original ids, because
	 * the screen's own script reads `.value` off them and listens for `change`.
	 * The picker writes to them and dispatches that same event, so nothing
	 * downstream had to know this control was replaced — and a future screen
	 * can adopt it the same way.
	 *
	 * WHY THE VOCABULARY IS PASSED IN RATHER THAN BUILT IN JAVASCRIPT
	 * --------------------------------------------------------------
	 * `toLocaleDateString` follows the BROWSER's locale, and this plugin's
	 * operators run a Bangla admin in a browser set to English more often than
	 * not. Month and weekday names come from `date_i18n()` so the calendar
	 * speaks whatever WordPress is speaking, and `start_of_week` decides which
	 * column Monday goes in.
	 *
	 * @param array $args {
	 *     @type string $from_id  Id for the hidden start input.
	 *     @type string $to_id    Id for the hidden end input.
	 *     @type string $from     Current start, 'Y-m-d' or ''.
	 *     @type string $to       Current end, 'Y-m-d' or ''.
	 *     @type string $label    Accessible name for the control.
	 * }
	 */
	public static function date_range( array $args ) {
		$from_id = isset( $args['from_id'] ) ? $args['from_id'] : 'aisooq-from';
		$to_id   = isset( $args['to_id'] ) ? $args['to_id'] : 'aisooq-to';
		$from    = isset( $args['from'] ) ? (string) $args['from'] : '';
		$to      = isset( $args['to'] ) ? (string) $args['to'] : '';
		$label   = isset( $args['label'] ) ? $args['label'] : __( 'Date range', 'aisooq-connector' );

		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = date_i18n( 'F', mktime( 0, 0, 0, $m, 1, 2001 ) );
		}
		/*
		 * Weekday initials start from Sunday and are ROTATED in the script by
		 * `start_of_week`, rather than being built rotated here. Two places
		 * doing half a rotation each is how a calendar ends up with Tuesday
		 * over the Monday column.
		 */
		$days = array();
		for ( $d = 0; $d < 7; $d++ ) {
			// 2001-01-07 was a Sunday.
			$days[] = date_i18n( 'D', mktime( 0, 0, 0, 1, 7 + $d, 2001 ) );
		}

		$config = array(
			'fromId'      => $from_id,
			'toId'        => $to_id,
			'months'      => $months,
			'days'        => $days,
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			// Today per the SITE's timezone, not the browser's. A shop in Dhaka
			// looked at from London must still call the same day "today".
			'today'       => current_time( 'Y-m-d' ),
			'presets'     => self::date_presets(),
			'i18n'        => array(
				'anyDate'  => __( 'Any date', 'aisooq-connector' ),
				'apply'    => __( 'Update', 'aisooq-connector' ),
				'cancel'   => __( 'Cancel', 'aisooq-connector' ),
				'prev'     => __( 'Previous month', 'aisooq-connector' ),
				'next'     => __( 'Next month', 'aisooq-connector' ),
				'start'    => __( 'Start date', 'aisooq-connector' ),
				'end'      => __( 'End date', 'aisooq-connector' ),
				'selected' => __( 'Selected range', 'aisooq-connector' ),
			),
		);
		?>
		<div class="aisooq-dr" data-aisooq-daterange="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php // Real inputs, hidden — the screen's script still owns them. ?>
			<input type="hidden" id="<?php echo esc_attr( $from_id ); ?>" value="<?php echo esc_attr( $from ); ?>" />
			<input type="hidden" id="<?php echo esc_attr( $to_id ); ?>" value="<?php echo esc_attr( $to ); ?>" />

			<button type="button" class="aisooq-dr__trigger" aria-haspopup="dialog" aria-expanded="false">
				<?php echo AI_Sooq_Icons::svg( 'calendar-blank' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
				<span class="aisooq-dr__label"><?php echo esc_html( __( 'Any date', 'aisooq-connector' ) ); ?></span>
				<?php echo AI_Sooq_Icons::svg( 'caret-down', array( 'size' => 14 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
		<?php
	}

	/**
	 * The preset shortcuts, as offsets rather than dates.
	 *
	 * Offsets because the popover is built once and may be open across
	 * midnight; "last 7 days" resolved in PHP would then quietly mean the seven
	 * days up to yesterday.
	 *
	 * `days => null` is the open-ended one: it clears both bounds.
	 */
	private static function date_presets() {
		return array(
			array( 'key' => 'today',     'label' => __( 'Today', 'aisooq-connector' ),         'days' => 0 ),
			array( 'key' => 'yesterday', 'label' => __( 'Yesterday', 'aisooq-connector' ),     'days' => -1 ),
			array( 'key' => 'last7',     'label' => __( 'Last 7 days', 'aisooq-connector' ),   'days' => 7 ),
			array( 'key' => 'last30',    'label' => __( 'Last 30 days', 'aisooq-connector' ),  'days' => 30 ),
			array( 'key' => 'thismonth', 'label' => __( 'This month', 'aisooq-connector' ),    'days' => 'thismonth' ),
			array( 'key' => 'lastmonth', 'label' => __( 'Last month', 'aisooq-connector' ),    'days' => 'lastmonth' ),
			array( 'key' => 'all',       'label' => __( 'All time', 'aisooq-connector' ),      'days' => null ),
		);
	}

	/** Credit and version, the way every WordPress screen ends. */
	public static function footer( $version ) {
		?>
		<footer class="aisooq-footer">
			<span><?php
			printf(
				/* translators: %s: a link reading "WordPress". */
				esc_html__( 'Thank you for creating with %s.', 'aisooq-connector' ),
				'<a href="https://wordpress.org/">WordPress</a>'
			);
			?></span>
			<span><?php
			printf(
				/* translators: %s: the plugin's version number. */
				esc_html__( 'AI Sooq Connector %s', 'aisooq-connector' ),
				esc_html( $version )
			);
			?></span>
		</footer>
		<?php
	}
}
