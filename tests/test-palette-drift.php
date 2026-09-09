<?php
/**
 * The PHP palette and the stylesheet must not drift apart.
 *
 * AI_Sooq_Palette exists because three screens print inline <style> and cannot
 * reach assets/css/aisooq-admin.css — see that class's header. The price of
 * that is real: a handful of colours are now written down twice, once as a CSS
 * custom property and once as a PHP constant, and two copies of one decision
 * rot. Without this file the refactor that introduced the palette would only
 * have moved the duplication somewhere less visible than the stylesheet.
 *
 * So this asserts the four things that make the arrangement safe:
 *
 *   1. every mirrored value still equals the stylesheet's;
 *   2. no inline block carries a hardcoded colour any more;
 *   3. every `var(--aisooq-*)` an inline block uses is seeded by that same
 *      block — the one failure mode that is WORSE than a hardcoded hex,
 *      because an unseeded custom property is invalid at computed-value time
 *      and the colour silently disappears on a live merchant's screen;
 *   4. and nothing is seeded that is not used, so the lists stay honest.
 *
 * Deliberately has NO WooCommerce guard, for the reason test-packaging.php
 * gives: none of this has anything to do with WooCommerce, and a test that
 * skips is a test that stays green while the thing it guards walks back in.
 *
 * It reads source files rather than rendering the screens on purpose. The two
 * order blocks only print behind a get_current_screen() check, and a test that
 * has to fake a screen is a test that quietly stops asserting the day the
 * screen id changes.
 *
 * @package AISooq
 */

class Test_Palette_Drift extends WP_UnitTestCase {

	/** Where the stylesheet's token block starts. */
	const TOKEN_BLOCK_MARKER = '/* ── Design tokens';

	/**
	 * Each inline <style> block: the file holding it, and the palette names the
	 * block says it seeds.
	 *
	 * @return array<string,array>
	 */
	private static function blocks() {
		return array(
			'courier column'    => array(
				'file'  => AISOOQ_DIR . 'includes/class-aisooq-order-courier.php',
				'seeds' => AI_Sooq_Order_Courier::STYLE_COLOURS,
			),
			'sync column'       => array(
				'file'  => AISOOQ_DIR . 'includes/class-aisooq-orders-column.php',
				'seeds' => AI_Sooq_Orders_Column::STYLE_COLOURS,
			),
			'admin menu glyph'  => array(
				'file'  => AISOOQ_DIR . 'includes/class-aisooq-settings.php',
				'seeds' => AI_Sooq_Settings::MENU_COLOURS,
			),
		);
	}

	/** `--name => value` for every custom property in the stylesheet's token block. */
	private static function stylesheet_tokens() {
		$css   = (string) file_get_contents( AISOOQ_DIR . 'assets/css/aisooq-admin.css' );
		$start = strpos( $css, self::TOKEN_BLOCK_MARKER );
		if ( false === $start ) {
			return array();
		}
		// The block runs to the first `}` sitting at the start of a line.
		$end   = strpos( $css, "\n}", $start );
		$block = substr( $css, $start, false === $end ? null : $end - $start );

		$out = array();
		if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$out[ $hit[1] ] = trim( $hit[2] );
			}
		}
		return $out;
	}

	/**
	 * The CSS of one inline block, with comments removed.
	 *
	 * Comments are stripped because a hex or a var() inside one paints nothing,
	 * and several of these blocks explain their colour choices in prose that
	 * names the values it is arguing about.
	 */
	/**
	 * EVERY `<style>` in the file, not just the first.
	 *
	 * This was a strpos pair, which reads from the first `<style` to the first
	 * `</style>` and silently ignores anything after it. A second inline block
	 * added to one of these files would then be exempt from every check below —
	 * no hardcoded-hex check, no seeded-use check — which is precisely the state
	 * this file exists to make impossible.
	 */
	private static function style_block( $file ) {
		$src = (string) file_get_contents( $file );
		if ( ! preg_match_all( '#<style.*?</style>#s', $src, $m ) ) {
			return '';
		}
		$block = implode( "\n", $m[0] );
		// Block comments, in both CSS and the PHP that prints the seed.
		$block = preg_replace( '#/\*.*?\*/#s', ' ', $block );
		// Whole-line PHP `//` comments (the phpcs annotations).
		return (string) preg_replace( '#^\s*//.*$#m', '', (string) $block );
	}

	/** #abc and #AABBCC compare equal; nothing else is normalised. */
	private static function normalise( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $hex, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		return $hex;
	}

	/* ── 1. The mirrored values ─────────────────────────────────────────── */

	/**
	 * The whole reason this file exists: AI_Sooq_Palette::TOKENS is a copy of
	 * part of the stylesheet's token block, and a copy that nobody checks is a
	 * copy that is already wrong.
	 */
	public function test_every_mirrored_token_still_matches_the_stylesheet() {
		$css = self::stylesheet_tokens();
		$this->assertNotEmpty( $css, 'could not parse the token block out of assets/css/aisooq-admin.css' );

		foreach ( AI_Sooq_Palette::TOKENS as $name => $value ) {
			$this->assertArrayHasKey(
				'--' . $name,
				$css,
				sprintf(
					'AI_Sooq_Palette::TOKENS["%s"] claims to mirror --%s, but the stylesheet has no such token. Either the token was renamed there, or this entry belongs in ::WP or ::LOCAL.',
					$name,
					$name
				)
			);
			$this->assertSame(
				self::normalise( $css[ '--' . $name ] ),
				self::normalise( $value ),
				sprintf(
					'--%s has drifted: assets/css/aisooq-admin.css says %s, AI_Sooq_Palette says %s. These are two copies of one decision; change both or neither.',
					$name,
					$css[ '--' . $name ],
					$value
				)
			);
		}
	}

	/**
	 * The two untested groups must stay untestable for the right reason.
	 *
	 * ::WP is WordPress's palette and ::LOCAL is ours-with-no-token-yet; both
	 * are exempt from the check above only because the stylesheet has nothing
	 * to compare them to. The day it grows a token of that name the exemption
	 * is a lie, and the entry has to move into ::TOKENS to be drift-tested.
	 */
	public function test_untested_palette_groups_have_nothing_in_the_stylesheet_to_drift_from() {
		$css = self::stylesheet_tokens();

		foreach ( array( 'WP' => AI_Sooq_Palette::WP, 'LOCAL' => AI_Sooq_Palette::LOCAL ) as $group => $entries ) {
			foreach ( array_keys( $entries ) as $name ) {
				$this->assertArrayNotHasKey(
					'--' . $name,
					$css,
					sprintf(
						'The stylesheet now declares --%s, so AI_Sooq_Palette::%s["%s"] is a second copy of it that nothing checks. Move it to ::TOKENS.',
						$name,
						$group,
						$name
					)
				);
			}
		}
	}

	/** One name, one meaning, one group. */
	public function test_palette_names_are_unique_across_the_three_groups() {
		$seen = array();
		foreach ( array( 'TOKENS' => AI_Sooq_Palette::TOKENS, 'WP' => AI_Sooq_Palette::WP, 'LOCAL' => AI_Sooq_Palette::LOCAL ) as $group => $entries ) {
			foreach ( array_keys( $entries ) as $name ) {
				$this->assertArrayNotHasKey(
					$name,
					$seen,
					sprintf( '"%s" is in both ::%s and ::%s; value() would return whichever comes first.', $name, $seen[ $name ] ?? '', $group )
				);
				$seen[ $name ] = $group;
			}
		}
		$this->assertSame( array_keys( $seen ), AI_Sooq_Palette::names() );
	}

	/** Nothing but a literal hex colour may reach a <style> body. */
	public function test_every_palette_value_is_a_hex_colour() {
		foreach ( AI_Sooq_Palette::names() as $name ) {
			$this->assertMatchesRegularExpression(
				'/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/',
				AI_Sooq_Palette::value( $name ),
				sprintf( '"%s" is not a hex colour, so AI_Sooq_Palette::vars() will drop it and whatever uses it will lose its colour.', $name )
			);
		}
	}

	/* ── 2/3/4. The inline blocks ───────────────────────────────────────── */

	/**
	 * The claim the stylesheet's header makes for itself — no raw hex — now
	 * has to hold for the plugin, not just for the sheet.
	 */
	public function test_no_inline_style_block_hardcodes_a_colour() {
		foreach ( self::blocks() as $label => $block ) {
			$css = self::style_block( $block['file'] );
			$this->assertNotSame( '', trim( $css ), sprintf( 'could not find the <style> block for the %s', $label ) );

			preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $css, $m );
			$this->assertSame(
				array(),
				$m[0],
				sprintf(
					'The %s block hardcodes %s. Every colour belongs in AI_Sooq_Palette and reaches the block as var(--aisooq-*).',
					$label,
					implode( ', ', array_unique( $m[0] ) )
				)
			);
		}
	}

	/**
	 * THE IMPORTANT ONE.
	 *
	 * These blocks print on screens where assets/css/aisooq-admin.css is not
	 * enqueued, and its tokens are scoped to `.wrap.aisooq*` even where it is.
	 * A custom property a block has not seeded itself is therefore invalid at
	 * computed-value time: the declaration is dropped and the colour vanishes
	 * rather than falling back to anything. That is a silent, screen-only
	 * regression — strictly worse than the hex it replaced, and not the kind of
	 * thing a PHP test suite would otherwise ever see.
	 */
	public function test_every_inline_block_seeds_every_colour_it_paints_with() {
		foreach ( self::blocks() as $label => $block ) {
			$css = self::style_block( $block['file'] );
			preg_match_all( '/var\(\s*' . preg_quote( AI_Sooq_Palette::PREFIX, '/' ) . '([a-z0-9-]+)\s*[,)]/', $css, $m );
			$used = array_unique( $m[1] );
			$this->assertNotEmpty( $used, sprintf( 'the %s block paints with no palette colour at all — did the seed or the rules get lost?', $label ) );

			$missing = array_diff( $used, $block['seeds'] );
			$this->assertSame(
				array(),
				array_values( $missing ),
				sprintf(
					'The %s block uses %s but does not seed it. Nothing else on that screen declares it, so the rule is dropped and the colour disappears. Add it to the block\'s colour list.',
					$label,
					implode( ', ', $missing )
				)
			);
		}
	}

	/**
	 * The seed has to sit on an ANCESTOR, and that is not what the test above
	 * checks.
	 *
	 * Custom properties inherit down the tree and nowhere else, so a block can
	 * name every colour it uses and still paint nothing: seed them on
	 * `.aisooq-ordc` instead of `body` and the courier history modal — which its
	 * own script appends to `document.body`, sharing no other ancestor with the
	 * table cells — loses its background and borders. Every name-membership
	 * assertion in this file still passes while a live orders screen renders a
	 * white-on-transparent modal.
	 *
	 * A unit test cannot resolve CSS ancestry, so it pins the decision instead:
	 * the two order blocks seed `body` because their consumers include DOM that
	 * is not inside their own wrapper, and the menu block seeds the menu item
	 * itself because every consumer is a descendant of it. Narrow either and
	 * this fails, which is the point — the reasoning is in the comment, and the
	 * comment alone was previously the only thing holding it.
	 */
	public function test_each_block_seeds_a_selector_its_rules_actually_descend_from() {
		$expected = array(
			'courier column'   => 'body',
			'sync column'      => 'body',
			'admin menu glyph' => '#toplevel_page_',
		);

		foreach ( self::blocks() as $label => $block ) {
			$css = self::style_block( $block['file'] );
			$this->assertNotSame( '', $css, sprintf( 'no <style> found for the %s block', $label ) );

			// The seed is emitted at runtime by AI_Sooq_Palette::vars(), so the
			// source holds the CALL, not a literal CSS rule — read the selector
			// argument. `$sel` in the menu block is the page slug built one line
			// above; resolve it rather than matching the variable name.
			$found = preg_match(
				'/AI_Sooq_Palette::vars\(\s*[^,]+,\s*(\$[a-z_]+|\'[^\']*\')\s*\)/',
				$css,
				$m
			);
			$this->assertSame( 1, $found, sprintf( 'the %s block never calls AI_Sooq_Palette::vars() — nothing seeds its colours', $label ) );

			$selector = trim( $m[1], "'" );
			if ( '$sel' === $selector ) {
				$selector = '#toplevel_page_' . AI_Sooq_Settings::PAGE_SLUG;
			}
			$this->assertStringContainsString(
				$expected[ $label ],
				$selector,
				sprintf(
					'The %s block seeds its colours on "%s", but its rules were written to descend from "%s". '
					. 'Custom properties inherit down the tree only, so a narrower seed drops every colour in '
					. 'that block without failing any other assertion here.',
					$label,
					$selector,
					$expected[ $label ]
				)
			);
		}
	}

	/** A seeded colour nobody paints with is a list going stale. */
	public function test_no_inline_block_seeds_a_colour_it_never_uses() {
		foreach ( self::blocks() as $label => $block ) {
			$css = self::style_block( $block['file'] );
			preg_match_all( '/var\(\s*' . preg_quote( AI_Sooq_Palette::PREFIX, '/' ) . '([a-z0-9-]+)\s*[,)]/', $css, $m );

			$unused = array_diff( $block['seeds'], array_unique( $m[1] ) );
			$this->assertSame(
				array(),
				array_values( $unused ),
				sprintf( 'The %s block seeds %s and never uses it.', $label, implode( ', ', $unused ) )
			);
		}
	}

	/** Every name a block seeds has to exist in the palette. */
	public function test_every_seeded_name_resolves() {
		foreach ( self::blocks() as $label => $block ) {
			foreach ( $block['seeds'] as $name ) {
				$this->assertNotSame(
					'',
					AI_Sooq_Palette::value( $name ),
					sprintf( 'the %s block seeds "%s", which is not in AI_Sooq_Palette', $label, $name )
				);
			}
		}
	}

	/* ── The emitter ────────────────────────────────────────────────────── */

	public function test_vars_declares_each_requested_colour_on_the_given_selector() {
		$css = AI_Sooq_Palette::vars( array( 'track', 'wp-link' ), 'body' );

		$this->assertSame(
			'body{--aisooq-track:' . AI_Sooq_Palette::value( 'track' ) . ';--aisooq-wp-link:' . AI_Sooq_Palette::value( 'wp-link' ) . ';}',
			$css
		);
	}

	public function test_vars_returns_nothing_when_there_is_nothing_to_declare() {
		$this->assertSame( '', AI_Sooq_Palette::vars( array(), 'body' ) );
		$this->assertSame( '', AI_Sooq_Palette::vars( array( 'track' ), '  ' ) );
	}

	/**
	 * An unknown name must be loud, not quietly skipped: the block that asked
	 * for it is about to render a rule with no colour in it.
	 */
	public function test_vars_complains_about_a_colour_that_is_not_in_the_palette() {
		$this->setExpectedIncorrectUsage( 'AI_Sooq_Palette::vars' );

		$this->assertSame( '', AI_Sooq_Palette::vars( array( 'no-such-colour' ), 'body' ) );
	}

	/**
	 * esc_attr() would not have saved us here — `;` and `}` are not
	 * HTML-special, so a bad value could close the declaration and open a rule
	 * of its own. The emitter allow-lists instead, and this is that promise.
	 */
	public function test_vars_emits_nothing_that_is_not_a_hex_colour() {
		$css = AI_Sooq_Palette::vars( AI_Sooq_Palette::names(), 'body' );

		preg_match_all( '/:([^;]+);/', $css, $m );
		foreach ( $m[1] as $value ) {
			$this->assertMatchesRegularExpression( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value );
		}
	}

	/* ── The third copy ─────────────────────────────────────────────────── */

	/**
	 * design-tokens.json names the stylesheet as its source of truth, which
	 * makes it a third copy with the same failure mode as the first two. It
	 * omits the three --wp-admin-theme-color-* overrides on purpose, so this
	 * only checks one direction: everything it does list must still be right.
	 */
	public function test_the_exported_design_tokens_still_match_the_stylesheet() {
		$css  = self::stylesheet_tokens();
		$json = json_decode( (string) file_get_contents( AISOOQ_DIR . 'design-tokens.json' ), true );
		$this->assertIsArray( $json, 'design-tokens.json is not valid JSON' );

		foreach ( self::flatten_tokens( $json ) as $name => $value ) {
			$this->assertArrayHasKey( $name, $css, sprintf( 'design-tokens.json exports %s, which the stylesheet no longer declares.', $name ) );
			$this->assertSame(
				self::normalise( $css[ $name ] ),
				self::normalise( $value ),
				sprintf( '%s has drifted between assets/css/aisooq-admin.css and design-tokens.json.', $name )
			);
		}
	}

	/** Every `--name => value` pair anywhere in the exported token tree. */
	private static function flatten_tokens( array $tree ) {
		$out = array();
		foreach ( $tree as $key => $value ) {
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::flatten_tokens( $value ) );
			} elseif ( is_string( $key ) && 0 === strpos( $key, '--' ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
