<?php
/**
 * What the repository must contain, and must not.
 *
 * Deliberately has NO WooCommerce guard. Every other test class skips itself
 * when WC_Order is missing, which is correct for them and wrong here: these
 * assertions have nothing to do with WooCommerce, and a class that skips is a
 * class that stays green while the thing it guards walks back in.
 *
 * The zip itself is gated in bin/build-zip.sh, which is what both release
 * workflows execute. These are the repo-side half: PHPUnit runs against the
 * working tree, so it can prove a file is committed but never that it reached
 * the package.
 *
 * @package AISooq
 */

class Test_Packaging extends WP_UnitTestCase {

	/** Courier logos are the carriers' trademarks — see NOTICE.md. */
	public function test_no_courier_artwork_is_bundled() {
		$this->assertFalse(
			is_dir( AISOOQ_DIR . 'assets/img/couriers' ),
			'Courier artwork must not be bundled: the logos are the carriers\' trademarks, and shipping them in a GPL zip puts a redistribution question on every store. Couriers render as a monogram instead — see AI_Sooq_Order_Courier::COURIER_BRAND.'
		);
		$this->assertSame(
			array(),
			glob( AISOOQ_DIR . 'assets/img/couriers/*' ) ?: array()
		);
	}

	/** The plugin's own marks stay — they are ours. */
	public function test_the_plugins_own_artwork_is_still_there() {
		foreach ( array( 'logo-horizontal.svg', 'logo-reversed.svg', 'symbol.svg', 'icon-512.png' ) as $file ) {
			$this->assertFileExists( AISOOQ_DIR . 'assets/img/' . $file );
		}
	}

	/**
	 * GPL-2.0 requires the licence accompany the distribution, and the zip is
	 * the distribution.
	 */
	public function test_the_licence_and_notices_are_present() {
		$this->assertFileExists( AISOOQ_DIR . 'LICENSE' );
		$this->assertFileExists( AISOOQ_DIR . 'NOTICE.md' );

		$licence = (string) file_get_contents( AISOOQ_DIR . 'LICENSE' );
		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', $licence );
		$this->assertStringContainsString( 'Version 2, June 1991', $licence );
	}

	/**
	 * The four places a version lives must agree, or WordPress silently fails
	 * to offer the update. bin/build-zip.sh refuses to package a mismatch; this
	 * catches it at the commit that introduces it rather than at release.
	 */
	public function test_every_version_spot_agrees() {
		$main = (string) file_get_contents( AISOOQ_DIR . 'aisooq-connector.php' );

		preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $main, $header );
		$this->assertNotEmpty( $header, 'The plugin header must declare a Version.' );
		$header_version = trim( $header[1] );

		$this->assertSame( $header_version, AISOOQ_VERSION, 'AISOOQ_VERSION must match the plugin header.' );

		$readme = (string) file_get_contents( AISOOQ_DIR . 'readme.txt' );
		preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $stable );
		$this->assertNotEmpty( $stable, 'readme.txt must declare a Stable tag.' );
		$this->assertSame( $header_version, trim( $stable[1] ), 'readme.txt Stable tag must match the plugin header.' );
	}

	/**
	 * Every screen must hang off the menu that actually exists.
	 *
	 * add_submenu_page() with an unknown parent still REGISTERS the page — it
	 * is reachable by URL and its capability check works — but it never appears
	 * in the menu. So the screen tests fine, the code looks right, and the only
	 * symptom is that nobody can find it. Two screens shipped that way: the
	 * top-level slug is `aisooq-connector` and they declared `aisooq`.
	 */
	public function test_every_screen_hangs_off_the_real_menu() {
		foreach ( array( 'AI_Sooq_Blocklist_Admin', 'AI_Sooq_Failed_Admin', 'AI_Sooq_Abandoned_Admin' ) as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} should be loaded." );
			$this->assertSame(
				AI_Sooq_Settings::PAGE_SLUG,
				constant( $class . '::PARENT_SLUG' ),
				"{$class}::PARENT_SLUG must be the settings page's slug, or its menu entry silently never renders."
			);
		}
	}

	/** Each screen needs its own slug, or one shadows another. */
	public function test_every_screen_has_a_distinct_slug() {
		$slugs = array(
			AI_Sooq_Settings::PAGE_SLUG,
			AI_Sooq_Blocklist_Admin::PAGE_SLUG,
			AI_Sooq_Failed_Admin::PAGE_SLUG,
			AI_Sooq_Abandoned_Admin::PAGE_SLUG,
		);
		$this->assertSame( $slugs, array_unique( $slugs ) );
	}

	/**
	 * A hook the documentation names but the code does not fire is worse than
	 * no documentation: it silently does nothing for whoever wrote against it.
	 * README documented `aisooq_connector_order_payload`, which never existed.
	 */
	public function test_every_filter_the_readme_documents_actually_exists() {
		$readme = (string) file_get_contents( AISOOQ_DIR . 'README.md' );

		$source = '';
		foreach ( (array) glob( AISOOQ_DIR . 'includes/*.php' ) as $file ) {
			$source .= (string) file_get_contents( $file );
		}

		preg_match_all( '/`(aisooq_[a-z0-9_]+)`/', $readme, $m );
		$documented = array_unique( $m[1] );
		$this->assertNotEmpty( $documented, 'precondition: the README documents at least one hook' );

		foreach ( $documented as $hook ) {
			// Option keys and constants share the prefix; only check names the
			// README presents as hooks by showing them to apply_filters/do_action.
			if ( false === strpos( $readme, "add_filter( '" . $hook ) && false === strpos( $readme, '| `' . $hook . '`' ) ) {
				continue;
			}
			$this->assertTrue(
				false !== strpos( $source, "'" . $hook . "'" ),
				"README documents the hook `{$hook}`, but nothing in includes/ fires it."
			);
		}
	}
}
