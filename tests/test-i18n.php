<?php
/**
 * The translation template must not go stale.
 *
 * A .pot that is missing strings is worse than none: a translator works through
 * it, ships a locale, and the untemplated strings silently stay English forever
 * with nothing anywhere saying why.
 *
 * This is deliberately a PHPUnit test rather than a CI job that regenerates the
 * template and diffs it. That job would need wp-cli on the runner AND on the
 * maintainer's machine, pinned to the same version — 2.11 and 2.12 emit
 * different bodies for this plugin — and with file:line references it would
 * turn red on any commit that merely shifts a line. This asserts the thing that
 * actually matters (every source string is in the template) on all four PHP
 * versions, with no extra tooling.
 *
 * Regenerate with `bin/make-pot.sh` (or `composer i18n`).
 *
 * @package AISooq
 */

class Test_I18n extends WP_UnitTestCase {

	const DOMAIN = 'aisooq-connector';

	private static function pot_path() {
		return AISOOQ_DIR . 'languages/aisooq-connector.pot';
	}

	/** Every msgid in the template, unescaped. */
	private static function pot_msgids() {
		$out  = array();
		$pot  = (string) file_get_contents( self::pot_path() );
		// msgid "..." optionally continued by further quoted lines.
		if ( preg_match_all( '/^msgid(_plural)?\s+((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $pot, $m ) ) {
			foreach ( $m[2] as $raw ) {
				$value = '';
				if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts ) ) {
					foreach ( $parts[1] as $chunk ) {
						$value .= stripcslashes( $chunk );
					}
				}
				if ( '' !== $value ) {
					$out[ $value ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Single-quoted literals passed as the first argument of a gettext call.
	 *
	 * Deliberately conservative: it only reads literals it can be certain about,
	 * because a false positive here fails the build for a string that is fine.
	 * A string built by concatenation or held in a variable is not extractable
	 * by wp-cli either, and is covered by its own test below.
	 *
	 * @return array<string,string> string => "file:line"
	 */
	private static function source_strings() {
		$found = array();
		$files = array_merge(
			(array) glob( AISOOQ_DIR . 'includes/*.php' ),
			array( AISOOQ_DIR . 'aisooq-connector.php' )
		);
		$fn = '(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)';

		foreach ( $files as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $i => $line ) {
				if ( ! preg_match_all( "/\b{$fn}\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $line, $m ) ) {
					continue;
				}
				foreach ( $m[1] as $raw ) {
					$value = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $raw );
					if ( '' === $value ) {
						continue;
					}
					$found[ $value ] = basename( $file ) . ':' . ( $i + 1 );
				}
			}
		}
		return $found;
	}

	public function test_the_template_exists_and_is_for_this_plugin() {
		$this->assertFileExists( self::pot_path(), 'Run bin/make-pot.sh to generate it.' );
		$pot = (string) file_get_contents( self::pot_path() );
		$this->assertStringContainsString( '"X-Domain: ' . self::DOMAIN . '\\n"', $pot );
	}

	/**
	 * The headers that change on every run are blanked on purpose, so that
	 * regenerating an unchanged tree produces a byte-identical file. Without
	 * that, every regeneration is a diff and nobody can tell a real change from
	 * a timestamp.
	 */
	public function test_the_template_is_deterministic() {
		$pot = (string) file_get_contents( self::pot_path() );
		$this->assertStringContainsString( '"POT-Creation-Date: \\n"', $pot, 'POT-Creation-Date must be blank.' );
		$this->assertStringContainsString( '"X-Generator: \\n"', $pot, 'X-Generator must be blank.' );
	}

	/** The whole point: no translatable string may be missing from the template. */
	public function test_every_translatable_string_is_in_the_template() {
		$pot     = self::pot_msgids();
		$this->assertNotEmpty( $pot, 'precondition: the template has strings' );

		$missing = array();
		foreach ( self::source_strings() as $string => $where ) {
			if ( ! isset( $pot[ $string ] ) ) {
				$missing[] = $where . '  "' . $string . '"';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"These strings are translatable in the source but absent from languages/aisooq-connector.pot.\n"
			. "Regenerate it with bin/make-pot.sh and commit the result.\n  "
			. implode( "\n  ", $missing )
		);
	}

	/**
	 * A variable or a concatenation passed to a gettext function cannot be
	 * extracted by any tool, so it silently never reaches a translator. The
	 * string has to be a literal.
	 */
	public function test_no_gettext_call_takes_a_variable_or_concatenation() {
		$offenders = array();
		$files     = array_merge(
			(array) glob( AISOOQ_DIR . 'includes/*.php' ),
			array( AISOOQ_DIR . 'aisooq-connector.php' )
		);
		$fn = '(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)';

		foreach ( $files as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $i => $line ) {
				// First argument starting with $ is a variable; a literal
				// followed by . before the comma is a concatenation.
				if ( preg_match( "/\b{$fn}\(\s*\\\$/", $line )
					|| preg_match( "/\b{$fn}\(\s*'[^']*'\s*\./", $line ) ) {
					$offenders[] = basename( $file ) . ':' . ( $i + 1 ) . '  ' . trim( $line );
				}
			}
		}
		$this->assertSame( array(), $offenders, "A gettext call must take a literal string:\n  " . implode( "\n  ", $offenders ) );
	}

	/** The text domain must be ours everywhere, or the string never loads. */
	public function test_no_foreign_text_domain_is_used() {
		$wrong = array();
		$files = array_merge(
			(array) glob( AISOOQ_DIR . 'includes/*.php' ),
			array( AISOOQ_DIR . 'aisooq-connector.php' )
		);
		$fn = '(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)';

		foreach ( $files as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $i => $line ) {
				if ( preg_match_all( "/\b{$fn}\(\s*'(?:[^'\\\\]|\\\\.)*'\s*,\s*'([^']+)'/", $line, $m ) ) {
					foreach ( $m[1] as $domain ) {
						if ( self::DOMAIN !== $domain ) {
							$wrong[] = basename( $file ) . ':' . ( $i + 1 ) . '  uses "' . $domain . '"';
						}
					}
				}
			}
		}
		$this->assertSame( array(), $wrong, "Wrong text domain:\n  " . implode( "\n  ", $wrong ) );
	}
}
