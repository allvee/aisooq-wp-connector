<?php
/**
 * The settings form itself: six panels of controls.
 *
 * WHY THIS IS SEPARATE FROM AI_Sooq_Settings AND FROM THE VIEW
 * -----------------------------------------------------------
 * Three jobs, three reasons to change, three files:
 *
 *   AI_Sooq_Settings       the option schema, the save path, the AJAX
 *                          endpoints, the platform calls — changes when the
 *                          INTEGRATION changes.
 *   AI_Sooq_Admin_Shell  the shell around the form: app bar, sidebar,
 *                          overview, footer — changes when the DESIGN changes.
 *   this class             the controls — changes when a SETTING changes.
 *
 * Adding a setting should not mean opening a file that also contains an OAuth
 * token mint, and restyling a card should not mean scrolling past forty form
 * fields to find it. Before the split all three lived in one 2,100-line class.
 *
 * EVERY METHOD IS STATIC AND TAKES ITS DATA
 * -----------------------------------------
 * Nothing here reads an option, makes a request or touches a global. The
 * caller has already resolved the settings array, the WooCommerce status list
 * and the platform's fraud config, and hands them over. That is what lets a
 * test render any panel in any state — including the "platform unreachable"
 * branch, which no fixture can reach by configuring things correctly.
 *
 * THE NAMES ARE LOAD-BEARING
 * --------------------------
 * `name="aisooq[...]"` here and the keys in AI_Sooq_Settings::defaults() are
 * one contract. The form posts EVERY field on every save, so a control that
 * silently stops rendering does not merely become uneditable — the next save
 * writes its default over whatever the merchant had set.
 * tests/test-settings-page.php derives its checklist from defaults() for
 * exactly that reason; do not hand-maintain a second list.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Settings_Fields {

	/** Print every panel, in the order the nav lists them. */
	public static function render_all( array $args ) {
		self::connection( $args['settings'] );
		self::sync( $args['settings'], $args['wc_statuses'] );
		self::fraud( $args['settings'], $args['fraud'] );
		self::messages( $args['settings'] );
		self::shipping( $args['settings'], $args['ship_methods'], $args['ship_rates'] );
		self::advanced( $args['settings'] );
	}

	/**
	 * Open a panel.
	 *
	 * A panel is no longer a card — it is a COLUMN of them, because the design
	 * groups a section's settings into two or three labelled surfaces rather
	 * than one long one. The heading is inside the panel rather than above the
	 * stack so that switching tabs switches the title with it, with no script:
	 * a page whose JavaScript failed shows every panel, each under its own
	 * name, which is exactly what it should degrade to.
	 *
	 * @param string $key   Section key. Also the panel and tab id.
	 * @param string $label The section's name, shown as the page heading.
	 */
	private static function panel_open( $key, $label ) {
		printf(
			'<section class="aisooq-panel" id="aisooq-panel-%1$s" data-panel="%1$s" role="tabpanel" aria-labelledby="aisooq-tab-%1$s" tabindex="0">'
				. '<h2 class="aisooq-tabtitle">%2$s</h2>',
			esc_attr( $key ),
			esc_html( $label )
		);
	}

	private static function panel_close() {
		echo '</section>';
	}

	/**
	 * Open one card inside a panel.
	 *
	 * @param string $label Small bold caption, or '' for an unlabelled card
	 *                      (the design uses one for a lone master switch).
	 * @param string $class Extra classes — `aisooq-card--rows` for a card whose
	 *                      body is a list of full-bleed rows.
	 * @param string $attrs Raw, already-escaped attributes, e.g. from
	 *                      depends_on(). Never anything user-supplied.
	 */
	private static function card_open( $label = '', $class = '', $attrs = '' ) {
		echo '<div class="aisooq-card ' . esc_attr( $class ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped at its source.
		if ( '' !== $label ) {
			echo '<span class="aisooq-card__label">' . esc_html( $label ) . '</span>';
		}
	}

	private static function card_close() {
		echo '</div>';
	}

	/**
	 * An info glyph whose only job is to carry a `title`.
	 *
	 * The design hangs most of its explanatory prose off these rather than
	 * printing it under every field. A title alone is not reachable by
	 * keyboard, so each one is also given to assistive tech as its own label —
	 * `tabindex=0` plus `role=note` means it is focusable and announced, which
	 * is the cheapest honest version of a tooltip that does not need a widget.
	 */
	public static function hint( $text ) {
		return '<span class="aisooq-hint" role="note" tabindex="0" title="' . esc_attr( $text ) . '" aria-label="' . esc_attr( $text ) . '">'
			. AI_Sooq_Icons::svg( 'info' )
			. '</span>';
	}

	/**
	 * A field that only bites while another switch is on. Dimmed, never hidden
	 * and never disabled — an operator who came here to change this setting must
	 * still be able to find and change it, and a control that vanishes reads as
	 * a missing feature rather than an inactive one.
	 */
	private static function depends_on( $field, $note ) {
		return ' data-requires="' . esc_attr( $field ) . '" data-requires-note="' . esc_attr( $note ) . '"';
	}

	/* ── Sections ───────────────────────────────────────────────────────────
	 * Each one is the answer to a single question an operator arrived with.
	 * Splitting render_page() up this way is not cosmetic: the previous single
	 * 450-line method meant any change to one card risked the other five.
	 */

	/** "Where does this site send its data?" */
	public static function connection( $s ) {
		self::panel_open( 'connection', __( 'Connection', 'aisooq-connector' ) );

		self::card_open();
		?>
		<div class="aisooq-rowline">
			<label class="aisooq-check">
				<input type="checkbox" name="aisooq[active]" value="1" <?php checked( $s['active'] ); ?> />
				<strong><?php esc_html_e( 'Active', 'aisooq-connector' ); ?></strong>
			</label>
			<?php echo self::hint( __( 'Syncs orders, carts, analytics & fraud. Uncheck to pause all syncing without losing settings.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		self::card_close();

		self::card_open( __( 'API', 'aisooq-connector' ) );
		?>
		<div class="aisooq-pair">
			<div class="aisooq-field">
				<label class="h" for="aisooq_api_base">
					<?php esc_html_e( 'Admin URL', 'aisooq-connector' ); ?>
					<?php echo self::hint( __( 'Host only — /api/v1 is appended. Handles OAuth + /connect/*.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<input name="aisooq[api_base]" id="aisooq_api_base" type="url" class="code" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://api.admin.yourdomain.com" />
			</div>
			<div class="aisooq-field">
				<label class="h" for="aisooq_storefront_base">
					<?php esc_html_e( 'Storefront URL', 'aisooq-connector' ); ?>
					<?php echo self::hint( __( 'Handles analytics + fraud. Blank = same host as admin.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<input name="aisooq[storefront_base]" id="aisooq_storefront_base" type="url" class="code" value="<?php echo esc_attr( $s['storefront_base'] ); ?>" placeholder="https://api.yourdomain.com" />
			</div>
		</div>
		<?php
		self::card_close();

		self::card_open( __( 'Credentials', 'aisooq-connector' ) );
		?>
		<div class="aisooq-field">
			<label class="h" for="aisooq_sid"><?php esc_html_e( 'Store SID', 'aisooq-connector' ); ?></label>
			<input name="aisooq[sid]" id="aisooq_sid" type="text" class="code" value="<?php echo esc_attr( $s['sid'] ); ?>" />
		</div>
		<div class="aisooq-pair">
			<div class="aisooq-field">
				<label class="h" for="aisooq_client_id"><?php esc_html_e( 'Client ID', 'aisooq-connector' ); ?></label>
				<input name="aisooq[client_id]" id="aisooq_client_id" type="text" class="code" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="wapp_..." />
			</div>
			<div class="aisooq-field">
				<label class="h" for="aisooq_client_secret">
					<?php esc_html_e( 'Client secret', 'aisooq-connector' ); ?>
					<?php echo self::hint( __( 'Shown once when you register the app. Leave blank to keep the stored one.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<input name="aisooq[client_secret]" id="aisooq_client_secret" type="password" class="code" value="" placeholder="<?php echo '' !== $s['client_secret'] ? esc_attr__( '•••••••• stored', 'aisooq-connector' ) : 'wsk_...'; ?>" autocomplete="new-password" />
			</div>
		</div>
		<?php
		self::card_close();
		self::panel_close();
	}

	/** "What crosses between WooCommerce and the platform, and which way?" */
	public static function sync( $s, $wc_statuses ) {
		self::panel_open( 'sync', __( 'Sync', 'aisooq-connector' ) );

		self::card_open( __( 'Push', 'aisooq-connector' ) );
		?>
		<div class="aisooq-checks">
			<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_orders]" value="1" <?php checked( $s['enable_orders'] ); ?> /> <?php esc_html_e( 'Orders', 'aisooq-connector' ); ?></label>
			<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_abandoned]" value="1" <?php checked( $s['enable_abandoned'] ); ?> /> <?php esc_html_e( 'Abandoned carts', 'aisooq-connector' ); ?></label>
			<label class="aisooq-check"><input type="checkbox" name="aisooq[enable_analytics]" value="1" <?php checked( $s['enable_analytics'] ); ?> /> <?php esc_html_e( 'Analytics', 'aisooq-connector' ); ?></label>
		</div>

		<div class="aisooq-field"<?php echo self::depends_on( 'enable_orders', __( 'Orders sync is off', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<span class="h"><?php esc_html_e( 'Order statuses', 'aisooq-connector' ); ?></span>
			<div class="aisooq-chips">
				<?php foreach ( $wc_statuses as $key => $label ) : ?>
					<?php
					$slug = preg_replace( '/^wc-/', '', $key );
					$on   = in_array( $slug, (array) $s['order_statuses'], true );
					?>
					<label class="aisooq-chip <?php echo $on ? 'is-on' : ''; ?>">
						<input type="checkbox" name="aisooq[order_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $on ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="aisooq-field aisooq-field--inline"<?php echo self::depends_on( 'enable_abandoned', __( 'Abandoned-cart sync is off', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<span class="aisooq-inline">
				<label class="h" for="aisooq_idle"><?php esc_html_e( 'Cart abandoned after', 'aisooq-connector' ); ?></label>
				<input name="aisooq[abandoned_idle_min]" id="aisooq_idle" type="number" min="5" value="<?php echo esc_attr( $s['abandoned_idle_min'] ); ?>" class="small-text" />
				<?php esc_html_e( 'min', 'aisooq-connector' ); ?>
			</span>
		</div>
		<?php
		self::card_close();

		/*
		 * The four two-way entities are a table in everything but markup: one
		 * switch and one direction each, and the value of seeing them together
		 * is comparing the directions down the column. Hence a row card rather
		 * than four separate fields.
		 */
		$entities = array(
			'customer' => array(
				'label' => __( 'Customers', 'aisooq-connector' ),
				'desc'  => __( 'Matched by email/phone. Needs customers.read + customers.write.', 'aisooq-connector' ),
			),
			'category' => array(
				'label' => __( 'Categories', 'aisooq-connector' ),
				'desc'  => __( 'Product categories + hierarchy. Matched to the platform by handle/slug. Needs categories.read + categories.write.', 'aisooq-connector' ),
			),
			'brand'    => array(
				'label' => __( 'Brands', 'aisooq-connector' ),
				'desc'  => __( 'Any brand taxonomy (native WC, Perfect Brands, YITH…). Matched by handle/slug. Needs brands.read + brands.write.', 'aisooq-connector' ),
			),
			'product'  => array(
				'label' => __( 'Products', 'aisooq-connector' ),
				'desc'  => __( 'Products + variants, mapped to existing platform products by SKU/handle. On pull, a product’s categories + brand are linked too. Needs products.read + products.write.', 'aisooq-connector' ),
			),
		);
		$dirs = array(
			'both' => __( 'Two-way (last edit wins)', 'aisooq-connector' ),
			'push' => __( 'WooCommerce → Platform', 'aisooq-connector' ),
			'pull' => __( 'Platform → WooCommerce', 'aisooq-connector' ),
		);

		self::card_open( __( 'Two-way sync', 'aisooq-connector' ), 'aisooq-card--rows' );
		foreach ( $entities as $ent => $meta ) :
			$on  = "enable_{$ent}_sync";
			$dir = "{$ent}_sync_dir";
			?>
			<div class="aisooq-row-item aisooq-entity">
				<label class="aisooq-check aisooq-row-item__label">
					<input type="checkbox" name="aisooq[<?php echo esc_attr( $on ); ?>]" value="1" <?php checked( $s[ $on ] ); ?> />
					<?php echo esc_html( $meta['label'] ); ?>
					<?php echo self::hint( $meta['desc'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<select name="aisooq[<?php echo esc_attr( $dir ); ?>]" id="aisooq_<?php echo esc_attr( $dir ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: entity name */ __( '%s sync direction', 'aisooq-connector' ), $meta['label'] ) ); ?>">
					<?php foreach ( $dirs as $val => $text ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s[ $dir ], $val ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endforeach; ?>
		<div class="aisooq-row-item aisooq-checks">
			<label class="aisooq-check">
				<input type="checkbox" name="aisooq[auto_sku]" value="1" <?php checked( $s['auto_sku'] ); ?> />
				<?php esc_html_e( 'Auto-generate SKUs', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'A product/variant with no SKU gets a unique one (SP-<id>) written to WooCommerce at sync time, so the platform can map it. Turn off if you manage SKUs yourself.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</label>
			<label class="aisooq-check">
				<input type="checkbox" name="aisooq[allow_status_writeback]" value="1" <?php checked( $s['allow_status_writeback'] ); ?> />
				<?php esc_html_e( 'Platform updates order status', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'When an order is progressed on the platform, mirror that status back onto the WooCommerce order.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</label>
		</div>
		<?php
		self::card_close();
		self::panel_close();
	}

	/** "Which orders do I refuse, and which do I look at twice?" */
	public static function fraud( $s, $fraud ) {
		self::panel_open( 'fraud', __( 'Fraud & courier', 'aisooq-connector' ) );

		self::card_open();
		?>
		<div class="aisooq-rowline">
			<label class="aisooq-check">
				<input type="checkbox" name="aisooq[enable_fraud]" value="1" <?php checked( $s['enable_fraud'] ); ?> />
				<strong><?php esc_html_e( 'Screen checkouts', 'aisooq-connector' ); ?></strong>
				<?php echo self::hint( __( 'Phone/name/address, IP velocity, courier history. Fails open if the API is unreachable.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</label>
			<span class="aisooq-field aisooq-field--inlineselect"<?php echo self::depends_on( 'enable_fraud', __( 'Fraud screening is off', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<label class="h" for="aisooq_fraud_action"><?php esc_html_e( 'On detection', 'aisooq-connector' ); ?></label>
				<select name="aisooq[fraud_action]" id="aisooq_fraud_action">
					<option value="block" <?php selected( $s['fraud_action'], 'block' ); ?>><?php esc_html_e( 'Block checkout', 'aisooq-connector' ); ?></option>
					<option value="hold" <?php selected( $s['fraud_action'], 'hold' ); ?>><?php esc_html_e( 'Allow, set order On hold', 'aisooq-connector' ); ?></option>
					<option value="flag" <?php selected( $s['fraud_action'], 'flag' ); ?>><?php esc_html_e( 'Allow, add a flag note', 'aisooq-connector' ); ?></option>
				</select>
			</span>
		</div>
		<?php
		self::card_close();

		$fraud_state  = isset( $fraud['state'] ) ? $fraud['state'] : 'disconnected';
		$fraud_config = isset( $fraud['config'] ) && is_array( $fraud['config'] ) ? $fraud['config'] : null;

		if ( 'disconnected' === $fraud_state ) {
			self::card_open();
			echo '<p class="description">' . esc_html__( 'Connect the store (press Verify) to configure the screening layers.', 'aisooq-connector' ) . '</p>';
			self::card_close();
		} elseif ( 'error' === $fraud_state ) {
			self::card_open();
			?>
			<p class="description aisooq-error">
				<?php
				printf(
					/* translators: %s: the error the platform returned. */
					esc_html__( 'Could not read the screening layers from the platform: %s', 'aisooq-connector' ),
					esc_html( isset( $fraud['message'] ) ? $fraud['message'] : '' )
				);
				?>
				<br />
				<?php esc_html_e( 'Check that the OAuth app is registered with the fraud scope, then press Verify. The master switch above is still saved and still sent to the platform.', 'aisooq-connector' ); ?>
			</p>
			<?php
			self::card_close();
		} else {
			$fraud = $fraud_config;
			$fv    = function ( $key, $default ) use ( $fraud ) {
				return array_key_exists( $key, $fraud ) ? $fraud[ $key ] : $default;
			};
			$pm    = $fv( 'phoneMode', 'bd' );
			$dep   = self::depends_on( 'enable_fraud', __( 'Fraud screening is off', 'aisooq-connector' ) );

			echo '<input type="hidden" name="aisooq_fraud[_present]" value="1" />';
			echo '<div class="aisooq-cardgrid">';

			self::card_open( '', '', $dep );
			?>
			<span class="aisooq-card__label">
				<span class="aisooq-tag aisooq-tag--accent">1</span>
				<?php esc_html_e( 'Validation', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Rejects keyboard-mash names, junk addresses and malformed numbers instantly.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<label class="aisooq-check"><input type="checkbox" name="aisooq_fraud[name_validation]" value="1" <?php checked( ! empty( $fv( 'nameValidation', true ) ) ); ?> /> <?php esc_html_e( 'Block fake names', 'aisooq-connector' ); ?></label>
			<label class="aisooq-check"><input type="checkbox" name="aisooq_fraud[address_validation]" value="1" <?php checked( ! empty( $fv( 'addressValidation', true ) ) ); ?> /> <?php esc_html_e( 'Block fake addresses', 'aisooq-connector' ); ?></label>
			<div class="aisooq-field">
				<label class="screen-reader-text" for="aisooq_fraud_phone_mode"><?php esc_html_e( 'Phone number check', 'aisooq-connector' ); ?></label>
				<select name="aisooq_fraud[phone_mode]" id="aisooq_fraud_phone_mode">
					<option value="bd" <?php selected( $pm, 'bd' ); ?>><?php esc_html_e( 'Phone: Bangladesh mobile only', 'aisooq-connector' ); ?></option>
					<option value="intl" <?php selected( $pm, 'intl' ); ?>><?php esc_html_e( 'Phone: any valid number', 'aisooq-connector' ); ?></option>
					<option value="off" <?php selected( $pm, 'off' ); ?>><?php esc_html_e( 'Phone: off', 'aisooq-connector' ); ?></option>
				</select>
			</div>
			<?php
			self::card_close();

			self::card_open( '', '', $dep );
			?>
			<span class="aisooq-card__label">
				<span class="aisooq-tag aisooq-tag--accent">2</span>
				<?php esc_html_e( 'IP rate limit', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Detects spam bursts from a single IP.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<span class="aisooq-inline">
				<input name="aisooq_fraud[ip_max_attempts]" type="number" min="1" max="100" step="1" value="<?php echo esc_attr( (int) $fv( 'ipMaxAttempts', 3 ) ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Blocked attempts before an IP is auto-blocked', 'aisooq-connector' ); ?>" />
				<?php esc_html_e( 'attempts in', 'aisooq-connector' ); ?>
				<input name="aisooq_fraud[ip_window_hours]" type="number" min="1" max="168" step="1" value="<?php echo esc_attr( (int) $fv( 'ipWindowHours', 24 ) ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Window in hours', 'aisooq-connector' ); ?>" />
				<?php esc_html_e( 'h', 'aisooq-connector' ); ?>
			</span>
			<?php
			self::card_close();

			echo '</div>';
		}

		self::card_open();
		?>
		<span class="aisooq-card__label">
			<span class="aisooq-tag aisooq-tag--accent">3</span>
			<?php esc_html_e( 'Courier history', 'aisooq-connector' ); ?>
			<?php echo self::hint( __( 'BDCourier knows how many past parcels a phone number accepted and how many came back. Each lookup is billed to your store.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<div class="aisooq-field">
			<label class="screen-reader-text" for="aisooq_courier_ratio"><?php esc_html_e( 'Block checkout below a success rate', 'aisooq-connector' ); ?></label>
			<span class="aisooq-inline">
				<?php esc_html_e( 'Block below', 'aisooq-connector' ); ?>
				<input name="aisooq[courier_min_ratio]" id="aisooq_courier_ratio" type="number" min="0" max="100" step="1" value="<?php echo esc_attr( $s['courier_min_ratio'] ); ?>" class="small-text" />
				<?php esc_html_e( '% success after', 'aisooq-connector' ); ?>
				<input name="aisooq[courier_min_parcels]" type="number" min="1" step="1" value="<?php echo esc_attr( $s['courier_min_parcels'] ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Minimum parcel history before the gate applies', 'aisooq-connector' ); ?>" />
				<?php esc_html_e( 'parcels', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Set 0 to disable. Fails open if the API is unreachable.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</div>
		<label class="aisooq-check">
			<input type="checkbox" name="aisooq[auto_courier_check]" value="1" <?php checked( $s['auto_courier_check'] ); ?> />
			<?php esc_html_e( 'Auto-lookup on new orders', 'aisooq-connector' ); ?>
			<span class="aisooq-tag aisooq-tag--outline" title="<?php esc_attr_e( 'One billed lookup per order that has a phone number. The result shows in the Courier column on WooCommerce → Orders.', 'aisooq-connector' ); ?>"><?php esc_html_e( 'Billed', 'aisooq-connector' ); ?></span>
		</label>
		<p class="description aisooq-cost">
			<?php echo AI_Sooq_Icons::svg( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'One billed lookup per order that has a phone number.', 'aisooq-connector' ); ?>
		</p>
		<?php
		self::card_close();

		echo '<div class="aisooq-cardgrid">';

		self::card_open();
		?>
		<label class="aisooq-check">
			<input type="checkbox" name="aisooq[dup_order_block]" value="1" <?php checked( $s['dup_order_block'] ); ?> />
			<strong><?php esc_html_e( 'Block duplicate orders', 'aisooq-connector' ); ?></strong>
			<?php echo self::hint( __( 'Same mobile or e-mail within the window. Local check — no API call, nothing billed. Cancelled, failed and refunded orders never count.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</label>
		<div class="aisooq-field"<?php echo self::depends_on( 'dup_order_block', __( 'Duplicate-order blocking is off', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<label class="screen-reader-text" for="aisooq_dup_hours"><?php esc_html_e( 'Duplicate-order window', 'aisooq-connector' ); ?></label>
			<span class="aisooq-inline">
				<?php esc_html_e( 'Within', 'aisooq-connector' ); ?>
				<input name="aisooq[dup_order_window_hours]" id="aisooq_dup_hours" type="number" min="1" max="168" step="1" value="<?php echo esc_attr( $s['dup_order_window_hours'] ); ?>" class="small-text" />
				<?php esc_html_e( 'hours', 'aisooq-connector' ); ?>
			</span>
		</div>
		<?php
		self::card_close();

		self::card_open();
		?>
		<span class="aisooq-card__label">
			<?php esc_html_e( 'Blocked-shopper contacts', 'aisooq-connector' ); ?>
			<?php echo self::hint( __( 'Shown as Call / WhatsApp buttons in the block popup, so a genuine buyer can still reach you. Blank uses the connected store’s contact number.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<div class="aisooq-field">
			<span class="aisooq-affix">
				<span class="aisooq-affix__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<input name="aisooq[support_phone]" id="aisooq_support_phone" type="text" value="<?php echo esc_attr( $s['support_phone'] ); ?>" placeholder="<?php esc_attr_e( 'Call number, e.g. 01XXXXXXXXX', 'aisooq-connector' ); ?>" aria-label="<?php esc_attr_e( 'Support phone number', 'aisooq-connector' ); ?>" />
			</span>
			<span class="aisooq-affix">
				<span class="aisooq-affix__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'whatsapp-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<input name="aisooq[support_whatsapp]" id="aisooq_support_whatsapp" type="text" value="<?php echo esc_attr( $s['support_whatsapp'] ); ?>" placeholder="<?php esc_attr_e( 'WhatsApp, e.g. 8801XXXXXXXXX', 'aisooq-connector' ); ?>" aria-label="<?php esc_attr_e( 'Support WhatsApp number', 'aisooq-connector' ); ?>" />
			</span>
			<span class="aisooq-affix">
				<span class="aisooq-affix__icon" aria-hidden="true"><?php echo AI_Sooq_Icons::svg( 'messenger-logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<input name="aisooq[support_messenger]" id="aisooq_support_messenger" type="url" value="<?php echo esc_attr( $s['support_messenger'] ); ?>" placeholder="<?php esc_attr_e( 'Messenger, e.g. https://m.me/yourpage', 'aisooq-connector' ); ?>" aria-label="<?php esc_attr_e( 'Support Messenger link', 'aisooq-connector' ); ?>" />
			</span>
		</div>
		<?php
		self::card_close();

		echo '</div>';
		self::panel_close();
	}

	/** "What exactly does the shopper read when we turn them away?" */
	public static function messages( $s ) {
		self::panel_open( 'messages', __( 'Checkout messages', 'aisooq-connector' ) );

		$messages = array(
			array( 'msg_courier',        __( 'Courier delivery gate', 'aisooq-connector' ),        __( '{ratio} = delivery-success %, {parcels} = past parcel count.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_fraud_contact',  __( 'Details not verified', 'aisooq-connector' ),         __( 'Fraud — the shopper’s details could not be verified.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_fraud_velocity', __( 'Too many attempts', 'aisooq-connector' ),            __( 'Fraud — too many attempts from one shopper.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_fraud_generic',  __( 'Fallback', 'aisooq-connector' ),                     __( 'Fraud — other / fallback.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_duplicate',      __( 'Duplicate order', 'aisooq-connector' ),              __( '{hours} is replaced with the configured window.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_blocked',        __( 'Blocked by your list', 'aisooq-connector' ),         __( 'Shown when a checkout matches your own block list. Keep the reason vague — naming it tells an abuser which detail to change. Blank uses the built-in wording.', 'aisooq-connector' ), 'textarea' ),
			array( 'msg_help',           __( 'Popup contact prompt', 'aisooq-connector' ),         __( 'Line above the Call / WhatsApp buttons in the popup.', 'aisooq-connector' ), 'text' ),
		);

		self::card_open();
		echo '<p class="description">' . esc_html__( 'The exact text a shopper sees when checkout is blocked. Write it in Bangla, English, or both. Leave a box blank to use the built-in default.', 'aisooq-connector' ) . '</p>';
		foreach ( $messages as $msg ) :
			list( $key, $label, $help, $type ) = $msg;
			$id = 'aisooq_' . $key;
			?>
			<div class="aisooq-field">
				<label class="h" for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php echo self::hint( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<?php if ( 'textarea' === $type ) : ?>
					<textarea name="aisooq[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $id ); ?>" rows="2"><?php echo esc_textarea( $s[ $key ] ); ?></textarea>
				<?php else : ?>
					<input name="aisooq[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $id ); ?>" type="text" value="<?php echo esc_attr( $s[ $key ] ); ?>" />
				<?php endif; ?>
			</div>
		<?php endforeach;
		self::card_close();
		self::panel_close();
	}

	/** "Which platform rate does this WooCommerce shipping method mean?" */
	public static function shipping( $s, $ship_methods, $ship_rates ) {
		self::panel_open( 'shipping', __( 'Shipping', 'aisooq-connector' ) );

		if ( empty( $ship_methods ) ) {
			self::card_open();
			echo '<p class="description">' . esc_html__( 'No WooCommerce shipping methods found. Add zones + methods in WooCommerce › Settings › Shipping.', 'aisooq-connector' ) . '</p>';
			self::card_close();
			self::panel_close();
			return;
		}

		$map = (array) $s['shipping_map'];

		self::card_open( '', 'aisooq-card--rows aisooq-card--map' );
		?>
		<div class="aisooq-row-item__head">
			<span><?php esc_html_e( 'WooCommerce', 'aisooq-connector' ); ?></span>
			<span>
				<?php esc_html_e( 'Platform rate', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Mapped charges link to that rate on the platform; unmapped ones raise a reconciliation alert.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</div>
		<?php foreach ( $ship_methods as $key => $label ) : ?>
			<?php $id = 'aisooq_ship_' . sanitize_key( $key ); ?>
			<div class="aisooq-row-item">
				<label class="aisooq-row-item__label" for="<?php echo esc_attr( $id ); ?>" title="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php if ( ! empty( $ship_rates ) ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" name="aisooq[shipping_map][<?php echo esc_attr( $key ); ?>]">
						<option value="0"><?php esc_html_e( 'Not mapped', 'aisooq-connector' ); ?></option>
						<?php foreach ( $ship_rates as $r ) : $rid = isset( $r['id'] ) ? (int) $r['id'] : 0; ?>
							<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( isset( $map[ $key ] ) ? (int) $map[ $key ] : 0, $rid ); ?>>
								<?php echo esc_html( ( isset( $r['zoneName'] ) ? $r['zoneName'] . ' / ' : '' ) . ( isset( $r['name'] ) ? $r['name'] : '' ) . ( isset( $r['amount'] ) ? ' · ' . $r['amount'] : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="number" min="0" id="<?php echo esc_attr( $id ); ?>" name="aisooq[shipping_map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $map[ $key ] ) ? $map[ $key ] : '' ); ?>" placeholder="<?php esc_attr_e( 'platform rate id', 'aisooq-connector' ); ?>" class="small-text" />
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php
		self::card_close();

		if ( empty( $ship_rates ) ) {
			self::card_open();
			echo '<p class="description">' . esc_html__( 'Could not load platform rates — press Verify, or create shipping rates on the platform first. You can enter rate ids manually meanwhile.', 'aisooq-connector' ) . '</p>';
			self::card_close();
		}

		self::panel_close();
	}

	/** "Something is wrong and I need to see why." */
	/** Human wording for the update channel's state, with no I/O. */
	private static function update_state_badge() {
		$st = class_exists( 'AI_Sooq_Updater' ) ? AI_Sooq_Updater::instance()->state() : array( 'state' => 'unknown', 'latest' => '' );
		switch ( $st['state'] ) {
			case 'available':
				/* translators: %s: the version available on GitHub. */
				return array( 'warn', sprintf( __( 'Version %s is available', 'aisooq-connector' ), $st['latest'] ) );
			case 'current':
				return array( 'ok', __( 'Up to date', 'aisooq-connector' ) );
			case 'unreachable':
				return array( 'err', __( 'Could not reach GitHub last time it looked', 'aisooq-connector' ) );
			case 'off':
				return array( 'muted', __( 'Turned off', 'aisooq-connector' ) );
			case 'disabled':
				return array( 'muted', __( 'Disabled on this server', 'aisooq-connector' ) );
		}
		return array( 'muted', __( 'Not checked yet', 'aisooq-connector' ) );
	}

	public static function advanced( $s ) {
		self::panel_open( 'advanced', __( 'Advanced', 'aisooq-connector' ) );

		list( $upd_tone, $upd_text ) = self::update_state_badge();
		$tone_class = array(
			'ok'    => 'aisooq-tag--ok',
			'warn'  => 'aisooq-tag--warn',
			'err'   => 'aisooq-tag--err',
			'muted' => 'aisooq-tag--neutral',
		);

		self::card_open( '', 'aisooq-card--rows' );
		?>
		<div class="aisooq-row-item">
			<label class="aisooq-check aisooq-row-item__label">
				<input type="checkbox" name="aisooq[enable_updates]" value="1" <?php checked( $s['enable_updates'] ); ?> <?php disabled( ! current_user_can( 'update_plugins' ) ); ?> />
				<?php esc_html_e( 'Updates from GitHub', 'aisooq-connector' ); ?>
				<?php
				echo self::hint( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					current_user_can( 'update_plugins' )
						? __( 'Not on WordPress.org — without this, updates need a manual zip upload and security fixes stop reaching stores. They appear on Dashboard › Updates like any other plugin.', 'aisooq-connector' )
						: __( 'Only a user who can install plugin updates may change this.', 'aisooq-connector' )
				);
				?>
			</label>
			<span class="aisooq-tag <?php echo esc_attr( isset( $tone_class[ $upd_tone ] ) ? $tone_class[ $upd_tone ] : 'aisooq-tag--neutral' ); ?>"><?php echo esc_html( $upd_text ); ?></span>
		</div>
		<div class="aisooq-row-item">
			<label class="aisooq-check aisooq-row-item__label">
				<input type="checkbox" name="aisooq[debug_log]" value="1" <?php checked( $s['debug_log'] ); ?> />
				<?php esc_html_e( 'Debug logging', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Every request and response is written to WooCommerce › Status › Logs. Useful while setting up; noisy afterwards.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</label>
			<a class="aisooq-btn aisooq-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php esc_html_e( 'Open logs', 'aisooq-connector' ); ?></a>
		</div>
		<div class="aisooq-row-item">
			<span class="aisooq-row-item__label">
				<?php esc_html_e( 'Background queue', 'aisooq-connector' ); ?>
				<?php echo self::hint( __( 'Syncs run through Action Scheduler. A stuck queue is almost always a paused WP-Cron.', 'aisooq-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<a class="aisooq-btn aisooq-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=' . rawurlencode( AISOOQ_AS_GROUP ) ) ); ?>"><?php esc_html_e( 'Scheduled actions', 'aisooq-connector' ); ?></a>
		</div>
		<?php
		self::card_close();
		self::panel_close();
	}

}
