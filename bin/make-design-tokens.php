<?php
/**
 * Regenerate design-tokens.json from the stylesheet's token block.
 *
 * design-tokens.json is a THIRD copy of the palette — after the stylesheet and
 * AI_Sooq_Palette — and tests/test-palette-drift.php fails the build when it
 * disagrees with the sheet. Until now it was hand-maintained, which meant the
 * instruction in DESIGN.md ("regenerate design-tokens.json after touching the
 * token block") named a step nobody could actually perform; the only way to do
 * it was to edit JSON by hand and hope. This is that step.
 *
 * Usage:  php bin/make-design-tokens.php          # write design-tokens.json
 *         php bin/make-design-tokens.php --check  # exit 1 if it is stale
 *
 * The grouping below is curated on purpose rather than derived from the token
 * names. A flat dump would technically satisfy the drift test and would be
 * useless to the design tools this file exists for: "is this colour a surface
 * or an ink" is the question an importer needs answered, and only a human
 * knows. Anything not listed is exported under `other`, which is the signal to
 * come and place it.
 *
 * @package AISooq
 */

$root = dirname( __DIR__ );
$css  = (string) file_get_contents( $root . '/assets/css/aisooq-admin.css' );

/* The same block the drift test reads: from the marker to the first `}` that
   starts a line. Parsed identically so the two cannot disagree about scope. */
$start = strpos( $css, '/* ── Design tokens' );
if ( false === $start ) {
	fwrite( STDERR, "Could not find the token block in assets/css/aisooq-admin.css\n" );
	exit( 1 );
}
$end   = strpos( $css, "\n}", $start );
$block = substr( $css, $start, false === $end ? null : $end - $start );

$tokens = array();
if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $m, PREG_SET_ORDER ) ) {
	foreach ( $m as $hit ) {
		$tokens[ $hit[1] ] = trim( $hit[2] );
	}
}

/**
 * Which group each token belongs in, and in what order.
 *
 * `--wp-admin-theme-color*` is deliberately absent: those three are not our
 * palette, they are an override we push INTO WordPress's own controls, and
 * exporting them would invite a design tool to treat wp-admin's accent as a
 * token of ours.
 */
$groups = array(
	'color.accentRamp'   => array( '--pri-100', '--pri-200', '--pri-300', '--pri-400', '--pri-500', '--pri-600', '--pri-700', '--pri-800', '--pri-900' ),
	'color.neutralRamp'  => array( '--n-100', '--n-200', '--n-300', '--n-400', '--n-500', '--n-600', '--n-700', '--n-800', '--n-900' ),
	'color.brand'        => array( '--pri', '--pri-d', '--pri-dd', '--pri-solid', '--pri-fg', '--pri-wash', '--pri-tint', '--hl', '--hl-d', '--hl-fg' ),
	'color.status'       => array( '--ok', '--warn', '--err', '--info' ),
	'color.statusSurface' => array( '--ok-wash', '--warn-wash', '--err-wash', '--info-wash', '--ok-edge', '--warn-edge', '--err-edge' ),
	'color.surface'      => array( '--bg', '--card', '--wash', '--sunk', '--sunk-2', '--track', '--line', '--edge' ),
	'color.text'         => array( '--muted', '--fg' ),
	'typography'         => array( '--text-xs', '--text-sm', '--text', '--text-lg', '--text-xl', '--text-2xl', '--lh-tight', '--lh' ),
	'space'              => array( '--sp-1', '--sp-2', '--sp-3', '--sp-4', '--sp-5', '--sp-6', '--sp-8' ),
	'radius'             => array( '--radius', '--radius-sm', '--radius-md', '--pill' ),
	'elevation'          => array( '--sh-sm', '--sh-md', '--sh-lg', '--sh-elev' ),
);

$skip = array( '--wp-admin-theme-color', '--wp-admin-theme-color-darker-10', '--wp-admin-theme-color-darker-20' );

$out = array(
	'$schema'     => 'https://design-tokens.org/draft',
	'name'        => 'AI Sooq — WordPress admin',
	'description' => 'Source of truth: assets/css/aisooq-admin.css. Regenerate with bin/make-design-tokens.php — do not hand-edit.',
);

$placed = array();
foreach ( $groups as $path => $names ) {
	$bucket = array();
	foreach ( $names as $name ) {
		if ( ! isset( $tokens[ $name ] ) ) {
			fwrite( STDERR, "warning: {$path} lists {$name}, which the stylesheet no longer declares.\n" );
			continue;
		}
		$bucket[ $name ] = $tokens[ $name ];
		$placed[ $name ] = true;
	}
	if ( ! $bucket ) {
		continue;
	}
	$parts = explode( '.', $path );
	if ( 1 === count( $parts ) ) {
		$out[ $parts[0] ] = $bucket;
	} else {
		$out[ $parts[0] ][ $parts[1] ] = $bucket;
	}
}

// Anything new in the sheet that nobody has filed yet.
$other = array();
foreach ( $tokens as $name => $value ) {
	if ( ! isset( $placed[ $name ] ) && ! in_array( $name, $skip, true ) ) {
		$other[ $name ] = $value;
	}
}
if ( $other ) {
	$out['other'] = $other;
	fwrite( STDERR, 'note: ' . count( $other ) . " token(s) exported under `other` — add them to \$groups in this script.\n" );
}

$out['breakpoints'] = array(
	'admin'   => '782px',
	'shell'   => '720px',
	'single'  => '600px',
	'phone'   => '400px',
);

$json = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
$dest = $root . '/design-tokens.json';

/* ── The preview page ──────────────────────────────────────────────────────
 * design-preview.html is a THIRD consumer of the same block, and until now a
 * third hand-maintained copy of it: it carried its own `:root` and its own
 * swatch grid with the hex and the contrast ratio typed in. Its own lede said
 * "nothing here is hand-typed", which had stopped being true.
 *
 * It is generated INTO the file rather than fetched at runtime because the
 * whole point of that page is that it opens straight off disk with no build
 * step and no server, and `file://` will not let it fetch the JSON.
 */

/** Relative luminance, per WCAG 2.x. */
function aisooq_luminance( $hex ) {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$out = 0.0;
	foreach ( array( array( 0, 0.2126 ), array( 2, 0.7152 ), array( 4, 0.0722 ) ) as $part ) {
		$c    = hexdec( substr( $hex, $part[0], 2 ) ) / 255;
		$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		$out += $c * $part[1];
	}
	return $out;
}

/** Contrast ratio between two hex colours. */
function aisooq_contrast( $a, $b ) {
	$la = aisooq_luminance( $a );
	$lb = aisooq_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/** One swatch figure, with its ratio against white computed rather than typed. */
function aisooq_swatch( $name, $value ) {
	$note = '';
	if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
		$r     = aisooq_contrast( $value, '#ffffff' );
		$cls   = $r >= 4.5 ? 'ok' : ( $r >= 3 ? 'mid' : 'no' );
		$label = $r >= 4.5 ? 'AA text' : ( $r >= 3 ? 'UI / large only' : 'background only' );
		$note  = sprintf( '<span class="r %s">%.2f:1 %s</span>', $cls, $r, $label );
	}
	return sprintf(
		'<figure class="sw"><div class="chip" style="background:%s"></div>' . "\n" . '    <code>%s</code><span class="val">%s</span>%s</figure>',
		htmlspecialchars( $value, ENT_QUOTES ),
		htmlspecialchars( $name, ENT_QUOTES ),
		htmlspecialchars( $value, ENT_QUOTES ),
		$note
	);
}

$groups_for_preview = array(
	'Accent ramp'     => 'color.accentRamp',
	'Neutral ramp'    => 'color.neutralRamp',
	'Brand'           => 'color.brand',
	'Status ink'      => 'color.status',
	'Status surfaces' => 'color.statusSurface',
	'Surfaces'        => 'color.surface',
	'Text'            => 'color.text',
);

$root_css = ":root {\n";
foreach ( $tokens as $name => $value ) {
	$root_css .= "\t{$name}: {$value};\n";
}
$root_css .= '}';

$swatches = '';
foreach ( $groups_for_preview as $heading => $path ) {
	if ( ! isset( $groups[ $path ] ) ) {
		continue;
	}
	$figures = array();
	foreach ( $groups[ $path ] as $name ) {
		if ( isset( $tokens[ $name ] ) ) {
			$figures[] = aisooq_swatch( $name, $tokens[ $name ] );
		}
	}
	if ( $figures ) {
		$swatches .= "<h2>{$heading}</h2><div class=\"grid\">" . implode( "\n", $figures ) . "</div>\n";
	}
}

$preview_path = $root . '/design-preview.html';
$preview      = (string) file_get_contents( $preview_path );

/*
 * preg_replace_callback rather than preg_replace: a replacement STRING is
 * scanned for `$1`-style backreferences, and `rgba(20, 23, 28, .03)` is one
 * stray character away from being mangled by that. A callback's return value
 * is taken literally.
 */
$preview_new = preg_replace_callback(
	'/(\/\* @generated:tokens.*?\*\/\n).*?(\n\/\* @end:tokens \*\/)/s',
	function ( $m ) use ( $root_css ) {
		return $m[1] . $root_css . $m[2];
	},
	$preview,
	1
);
$preview_new = preg_replace_callback(
	'/(<!-- @generated:swatches[^>]*-->\n).*?(<!-- @end:swatches -->)/s',
	function ( $m ) use ( $swatches ) {
		return $m[1] . $swatches . $m[2];
	},
	$preview_new,
	1
);

if ( in_array( '--check', $argv, true ) ) {
	$stale   = array();
	$current = file_exists( $dest ) ? (string) file_get_contents( $dest ) : '';
	if ( $current !== $json ) {
		$stale[] = 'design-tokens.json';
	}
	if ( $preview_new !== $preview ) {
		$stale[] = 'design-preview.html';
	}
	if ( $stale ) {
		fwrite( STDERR, implode( ' and ', $stale ) . " stale — run: php bin/make-design-tokens.php\n" );
		exit( 1 );
	}
	echo "design-tokens.json and design-preview.html are up to date.\n";
	exit( 0 );
}

file_put_contents( $dest, $json );
file_put_contents( $preview_path, $preview_new );
echo 'Wrote design-tokens.json and design-preview.html (' . count( $tokens ) . " tokens read).\n";
