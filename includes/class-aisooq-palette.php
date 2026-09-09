<?php
/**
 * Every colour the plugin paints with, in one place.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * assets/css/aisooq-admin.css is a token system: 51 custom properties and no
 * raw hex below the token block. That was only ever half the plugin. Three
 * screens print their own inline <style> and could not reach those tokens, so
 * they carried a second, hand-maintained palette in literal hex — which is the
 * duplication the stylesheet exists to prevent, just moved somewhere nobody
 * looks. This is the other half: the values live here once, and each inline
 * block emits the subset it needs as custom properties of its own.
 *
 * WHY THE INLINE BLOCKS CANNOT SIMPLY USE THE STYLESHEET'S TOKENS
 * --------------------------------------------------------------
 * Two independent reasons, either one fatal:
 *
 *   1. The sheet is not on the page. AI_Sooq_Settings::enqueue_admin_assets()
 *      enqueues it only on hooks containing "aisooq"; the WooCommerce orders
 *      list, the order-edit screen and #adminmenu are none of those.
 *   2. Even where it IS enqueued, its token block is scoped to
 *      `.wrap.aisooq, .wrap.aisooq-ab, .wrap.aisooq-bl`. There is no `:root`
 *      rule anywhere in that file. Custom properties only inherit DOWN the
 *      tree, so nothing outside those wrappers ever sees them.
 *
 * A bare `var(--pri)` in one of those blocks is therefore invalid at
 * computed-value time: the declaration is dropped, the property falls back to
 * its inherited or initial value, and the colour DISAPPEARS on a live
 * merchant's screen. That is strictly worse than the hardcoded hex it replaced,
 * and it fails silently — nobody would attribute a grey sync icon to a colour
 * refactor. Hence the rule every caller here follows: a block that uses
 * `var(--aisooq-x)` must also emit `--aisooq-x` on an ancestor of everything it
 * styles, in the same block. tests/test-palette-drift.php enforces exactly that.
 *
 * WHY THE EMITTED PROPERTIES ARE PREFIXED AND THE SHEET'S ARE NOT
 * --------------------------------------------------------------
 * The sheet can afford `--pri` because it declares it on `.wrap.aisooq*` — its
 * own markup, nobody else's. These blocks seed `body`, because the courier
 * modal is appended to `document.body` by JS and has no other common ancestor
 * with the table cells. Declaring `--fg`, `--line` or `--muted` on the admin
 * `body` would hand every other plugin's stylesheet our values for names they
 * may well be reading from `:root` — a real collision on a shared wp-admin
 * page. `--aisooq-*` cannot collide with anything. The name after the prefix is
 * still the token's own name, and the drift test ties the two together.
 *
 * WHY A DEDICATED CLASS RATHER THAN A CONSTANT ON ONE OF THE THREE SCREENS
 * -----------------------------------------------------------------------
 * A constant on, say, AI_Sooq_Order_Courier would make the orders column and
 * the settings menu icon depend on the courier screen for their colours — a
 * dependency in the wrong direction between three siblings that share nothing
 * else. This class depends on nothing, not even WordPress, which is also what
 * lets the drift test read it without booting a screen.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Palette {

	/** Prefix for every custom property this class emits. See the header. */
	const PREFIX = '--aisooq-';

	/**
	 * Mirrors of tokens in assets/css/aisooq-admin.css.
	 *
	 * Keys are the token's own name without the leading `--`, and the values
	 * MUST equal the stylesheet's. These are two copies of one decision, so
	 * tests/test-palette-drift.php parses the sheet's token block and fails if
	 * they disagree; without that this file would just be a third place for the
	 * palette to rot.
	 *
	 * Mapped by VALUE, not by role, wherever the two differ. `#f0f0f1` is used
	 * below as a divider, and the sheet's divider token is `--line` (#eff3f4) —
	 * but `--track` is the token that holds #f0f0f1, so `track` is the honest
	 * mapping. Renaming a colour is allowed here; changing one is not.
	 */
	const TOKENS = array(
		'pri-tint' => '#e9f0f8', // Pale navy bed — the parcels-sent pill.
		'ok-wash'  => '#e8f3ec', // Pale green bed — the delivered pill.
		'track'    => '#f0f0f1', // Empty half of the ratio bar; also the
								 // neutral pill bed and every 1px divider in
								 // the courier block (see the note above).
		'sunk-2'   => '#f6f7f7', // Recessed surface — the breakdown table head.
		'card'     => '#ffffff', // Floating surface — the history modal.
		'hl'       => '#FDC137', // AI Sooq gold — the active admin-menu glyph.
	);

	/**
	 * WordPress core's own palette. THESE ARE NOT AI SOOQ'S COLOURS.
	 *
	 * Every entry below is wp-admin's, chosen so that controls sitting inside
	 * WordPress's furniture — a cell in the orders table, a glyph in the admin
	 * sidebar — read as part of that furniture rather than as a plugin graft.
	 * They deliberately have no token in aisooq-admin.css and must not be
	 * forced onto one: `--pri` is navy, not wp-admin's link blue, and `--muted`
	 * is #536471, not wp-admin's grey. Pointing these at our tokens would
	 * recolour half of WordPress's orders table the day the brand moves.
	 *
	 * Where a value happens to coincide with one of ours (#f0f6fc is also
	 * `--info-wash`, #ffffff is also `--bg`) that is two palettes landing on
	 * the same pixel, not a mapping. Keep them here.
	 */
	const WP = array(
		'wp-link'       => '#2271b1', // wp-admin link / primary action.
		'wp-link-hover' => '#135e96', // ...and its hover.
		'wp-link-wash'  => '#f0f6fc', // The pale bed wp-admin puts under it.
		'wp-muted'      => '#646970', // wp-admin secondary text.
		'wp-fg'         => '#1d2327', // wp-admin body text.
		'wp-grey-40'    => '#8c8f94', // wp-admin's dimmest legible grey.
		'wp-border'     => '#dcdcde', // wp-admin control border.
		'wp-menu-ink'   => '#ffffff', // Resting glyph on the dark sidebar. Not
									  // `--pri-fg` ("ink on navy") and not
									  // `--bg` ("page surface"); neither
									  // describes wp-admin's own sidebar, and
									  // borrowing one would tie the sidebar to
									  // a token that can move without us.
	);

	/**
	 * AI Sooq's, but with no counterpart in the stylesheet.
	 *
	 * The courier column ships colour families the shared sheet never needed:
	 * duotone chips whose ink is muted well below `--ok` / `--err` so four
	 * figures can sit side by side without shouting, and the two flat segments
	 * of the ratio bar. They are named by what they are rather than by which
	 * rule uses them, so a second component can reach for one. Not drift-tested
	 * — there is nothing to drift from until the sheet grows an equivalent.
	 */
	const LOCAL = array(
		'chip-blue-ink'   => '#2c5c8f', // On `pri-tint`.
		'chip-green-ink'  => '#2f6b45', // On `ok-wash`.
		'chip-red-bed'    => '#f7ece9', // Warmer than `--err-wash`, on purpose:
		'chip-red-ink'    => '#964a3f', // a returned parcel is a fact, not an
										// error, and the pill sits beside two
										// neutral ones.
		'chip-violet-bed' => '#efeafa', // "Orders placed here before" is a
		'chip-violet-ink' => '#55389c', // different kind of fact from the three
										// courier figures, so it gets a hue of
										// its own rather than reusing one.
		'bar-ok'          => '#45805a', // Delivered segment of the ratio bar.
		'bar-err'         => '#a85a4e', // Returned segment, continuing from it.
		'bar-ink'         => '#ffffff', // The figure printed ON the fill. White
										// clears 4.5:1 only because the fill is
										// pinned to RATIO_FILL_LIGHT; see the
										// constant's docblock before touching.
		'num-ok'          => '#00844a', // Delivered numerals; the synced mark.
		'num-err'         => '#b32d2e', // Returned numerals.
		'note-warn'       => '#996800', // Amber prose — "this figure is real,
										// it just came from a narrower source".
	);

	/**
	 * The hex for a palette name, from whichever group holds it.
	 *
	 * @param string $name Palette key, e.g. `track` or `wp-link`.
	 * @return string Hex colour, or '' when the name is unknown.
	 */
	public static function value( $name ) {
		foreach ( array( self::TOKENS, self::WP, self::LOCAL ) as $group ) {
			if ( isset( $group[ $name ] ) ) {
				return $group[ $name ];
			}
		}
		return '';
	}

	/** Every palette name, across all three groups. */
	public static function names() {
		return array_keys( array_merge( self::TOKENS, self::WP, self::LOCAL ) );
	}

	/**
	 * A custom-property declaration block, ready to print inside <style>.
	 *
	 * @param array  $names    Palette keys this style block uses.
	 * @param string $selector What to declare them on. Must be an ancestor of
	 *                         EVERYTHING the block styles — see the header.
	 * @return string e.g. `body{--aisooq-track:#f0f0f1;}`, or '' if nothing
	 *                resolved.
	 */
	public static function vars( array $names, $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return '';
		}

		$decls = '';
		foreach ( $names as $name ) {
			$hex = self::hex( self::value( $name ) );
			if ( '' === $hex ) {
				/*
				 * An unknown name is a typo at the call site, and the cost of
				 * shipping it is the exact failure this class was written to
				 * prevent: the rule using var() drops out and the colour
				 * vanishes. Say so loudly on a developer's install; the drift
				 * test catches it long before anyone else's.
				 */
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong(
						__METHOD__,
						esc_html( sprintf( 'Unknown palette colour "%s".', (string) $name ) ),
						defined( 'AISOOQ_VERSION' ) ? AISOOQ_VERSION : '0.0.0'
					);
				}
				continue;
			}
			$decls .= self::PREFIX . $name . ':' . $hex . ';';
		}

		return '' === $decls ? '' : $selector . '{' . $decls . '}';
	}

	/**
	 * Escaping for a CSS value context: an allow-list, not a filter.
	 *
	 * esc_attr() and friends are the wrong tool inside <style> — `;`, `}` and
	 * `url(` are not HTML-special, so they would pass through untouched and a
	 * bad value could close the declaration and open a new rule. Nothing but a
	 * literal hex colour can come out of here.
	 *
	 * @param string $value Candidate colour.
	 * @return string The value if it is a hex colour, '' otherwise.
	 */
	private static function hex( $value ) {
		$value = (string) $value;
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ? $value : '';
	}
}
