<?php
/**
 * Shared helper functions.
 *
 * These stay as plain global functions on purpose: woocommerce/content-product.php
 * calls them through function_exists() checks.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

// Term meta keys for per-term swatch overrides. Kept as global constants so the
// admin UI (YK_WCGV_Term_Meta) and the front-end lookup below cannot drift apart.
const YK_WCGV_META_SWATCH_COLOR    = 'yk_wcgv_swatch_color';
const YK_WCGV_META_SWATCH_IMAGE_ID = 'yk_wcgv_swatch_image_id';

// Fallback hex returned by yk_wcgv_resolve_color() when nothing matches.
const YK_WCGV_COLOR_FALLBACK = '#cccccc';

// Registered via add_image_size() in YK_WCGV_Assets. Front-end swatches are 30–40px,
// so 96×96 covers 2× displays; the admin term list keeps using 'thumbnail'.
const YK_WCGV_SWATCH_IMAGE_SIZE = 'yk_wcgv_swatch';

/**
 * True if $slug or $label contains any of the given $keys (substring match).
 */
function yk_wcgv_attr_matches( string $slug, string $label, array $keys ): bool {
	foreach ( $keys as $k ) {
		if ( false !== strpos( $slug, $k ) || false !== strpos( $label, $k ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Resolve a colour attribute slug/label to a hex value.
 *
 * Falls back to interpreting the slug as a raw hex string, then to a neutral grey.
 */
function yk_wcgv_resolve_color( string $slug, string $label ): string {
	static $map = [
		// English
		'red'          => '#e53e3e',
		'blue'         => '#3182ce',
		'green'        => '#38a169',
		'yellow'       => '#d69e2e',
		'orange'       => '#dd6b20',
		'purple'       => '#805ad5',
		'pink'         => '#ed64a6',
		'black'        => '#1a1a1a',
		'white'        => '#ffffff',
		'grey'         => '#718096',
		'gray'         => '#718096',
		'brown'        => '#8b5e3c',
		'beige'        => '#f5ead4',
		'navy'         => '#1a365d',
		'turquoise'    => '#38b2ac',
		'silver'       => '#a0aec0',
		'gold'         => '#d4af37',
		'cream'        => '#fffdd0',
		'olive'        => '#808000',
		'teal'         => '#2c7a7b',
		'coral'        => '#ff6b6b',
		'mint'         => '#98e4b8',
		'indigo'       => '#4b0082',
		'magenta'      => '#c026d3',
		'khaki'        => '#c3b091',
		'lilac'        => '#c8a2c8',
		'maroon'       => '#800000',
		'ivory'        => '#fffff0',
		'salmon'       => '#fa8072',
		'charcoal'     => '#36454f',
		// German
		'rot'          => '#e53e3e',
		'blau'         => '#3182ce',
		'grün'         => '#38a169',
		'gruen'        => '#38a169',
		'gelb'         => '#d69e2e',
		'lila'         => '#805ad5',
		'violett'      => '#805ad5',
		'rosa'         => '#ffb7c5',
		'schwarz'      => '#1a1a1a',
		'weiss'        => '#ffffff',
		'weiß'         => '#ffffff',
		'grau'         => '#718096',
		'braun'        => '#8b5e3c',
		'marine'       => '#1a365d',
		'silber'       => '#a0aec0',
		'türkis'       => '#38b2ac',
		'turkis'       => '#38b2ac',
		'dunkelblau'   => '#1a365d',
		'dunkel-blau'  => '#1a365d',
		'hellblau'     => '#90cdf4',
		'hell-blau'    => '#90cdf4',
		'dunkelgrau'   => '#4a5568',
		'dunkel-grau'  => '#4a5568',
		'hellgrau'     => '#e2e8f0',
		'hell-grau'    => '#e2e8f0',
		'dunkelgrün'   => '#276749',
		'dunkelrot'    => '#9b2335',
		'bordeaux'     => '#7c0a02',
		'petrol'       => '#2c7a7b',
		// French
		'rouge'        => '#e53e3e',
		'bleu'         => '#3182ce',
		'vert'         => '#38a169',
		'jaune'        => '#d69e2e',
		'noir'         => '#1a1a1a',
		'blanc'        => '#ffffff',
		'gris'         => '#718096',
		'marron'       => '#8b5e3c',
		'rose'         => '#ed64a6',
		'violet'       => '#805ad5',
		'orange'       => '#dd6b20',
	];

	$key = strtolower( trim( $slug ) );
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}

	$key = strtolower( trim( $label ) );
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}

	$hex = ltrim( $slug, '#' );
	if ( preg_match( '/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $hex ) ) {
		return '#' . $hex;
	}

	return YK_WCGV_COLOR_FALLBACK;
}

// ── Swatch term meta: sanitising, lookup, luminance ───────────────────────────

/**
 * Validate a single hex colour. Returns '' when the value is not a valid hex colour.
 *
 * sanitize_hex_color() lives in wp-includes/formatting.php, but it has historically
 * been an admin-side helper — the regex fallback keeps this usable on the front end.
 *
 * @param  mixed $color Raw colour value.
 * @return string Normalised '#rrggbb' / '#rgb', or '' when invalid.
 */
function yk_wcgv_sanitize_hex_color( $color ): string {
	$color = trim( (string) $color );
	if ( '' === $color ) {
		return '';
	}
	if ( '#' !== substr( $color, 0, 1 ) ) {
		$color = '#' . $color;
	}

	if ( function_exists( 'sanitize_hex_color' ) ) {
		$clean = sanitize_hex_color( $color );
		return is_string( $clean ) ? strtolower( $clean ) : '';
	}

	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? strtolower( $color ) : '';
}

/**
 * Parse the stored swatch colour meta into 0–2 validated hex colours.
 *
 * Accepts a single colour ('#000000') or a two-tone pair ('#000000,#ffcc00').
 * Invalid fragments are dropped; anything past the second colour is ignored.
 *
 * @param  mixed $raw Raw meta value.
 * @return string[] Zero, one or two hex colours.
 */
function yk_wcgv_parse_swatch_colors( $raw ): array {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return [];
	}

	$colors = [];
	foreach ( explode( ',', $raw ) as $part ) {
		$hex = yk_wcgv_sanitize_hex_color( $part );
		if ( '' === $hex ) {
			continue;
		}
		$colors[] = $hex;
		if ( 2 === count( $colors ) ) {
			break; // Two-tone maximum — extra colours are discarded.
		}
	}

	return $colors;
}

/**
 * sanitize_callback for the YK_WCGV_META_SWATCH_COLOR term meta.
 *
 * @param  mixed $raw Raw meta value.
 * @return string Canonical '#hex' / '#hex,#hex', or '' when nothing was valid.
 */
function yk_wcgv_sanitize_swatch_color_meta( $raw ): string {
	return implode( ',', yk_wcgv_parse_swatch_colors( $raw ) );
}

/**
 * sanitize_callback for the YK_WCGV_META_SWATCH_IMAGE_ID term meta.
 *
 * @param  mixed $raw Raw meta value.
 * @return int Attachment ID, or 0 when it is not an attachment.
 */
function yk_wcgv_sanitize_swatch_image_meta( $raw ): int {
	$id = absint( $raw );
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return 0;
	}
	return $id;
}

/**
 * True when a term carries an explicit, still-valid swatch override.
 *
 * Reads the raw term meta on purpose. Do NOT use yk_wcgv_get_swatch() for this: it
 * falls back to the colour-name map, so a term merely *named* like a colour (e.g. a
 * "Rot-Band" frequency option) would look like a configured swatch.
 *
 * @param  int $term_id Term to inspect.
 * @return bool
 */
function yk_wcgv_term_has_swatch_meta( int $term_id ): bool {
	if ( $term_id <= 0 ) {
		return false;
	}

	if ( '' !== yk_wcgv_sanitize_swatch_color_meta( get_term_meta( $term_id, YK_WCGV_META_SWATCH_COLOR, true ) ) ) {
		return true;
	}

	// A deleted attachment does not count as a configured swatch.
	return yk_wcgv_sanitize_swatch_image_meta( get_term_meta( $term_id, YK_WCGV_META_SWATCH_IMAGE_ID, true ) ) > 0;
}

/**
 * WCAG relative luminance test — true when the colour reads as "light".
 *
 * Threshold is 0.5 on the 0–1 luminance scale.
 *
 * @param  string $hex Hex colour ('#rgb' or '#rrggbb').
 * @return bool
 */
function yk_wcgv_is_light_color( string $hex ): bool {
	$hex = ltrim( yk_wcgv_sanitize_hex_color( $hex ), '#' );
	if ( '' === $hex ) {
		return true;
	}
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	$channels = [
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	];

	$linear = [];
	foreach ( $channels as $value ) {
		$c        = $value / 255;
		$linear[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	$luminance = ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );

	return $luminance > 0.5;
}

/**
 * Resolve how a single attribute term should be rendered as a swatch.
 *
 * Priority: term meta image > term meta hex > yk_wcgv_resolve_color() name map > none.
 *
 * `type` is the render mode. `color` / `color2` are populated whenever a colour could
 * be resolved — including for `type => 'image'`, where they are a companion hint (for
 * example border/contrast decisions), not the swatch fill. On `type => 'none'` callers
 * should fall back to yk_wcgv_resolve_color(), which yields YK_WCGV_COLOR_FALLBACK.
 *
 * Results are memoised per request: the same term is looked up once per archive page
 * even though it appears on many cards.
 *
 * @param  string $taxonomy   Attribute taxonomy, e.g. 'pa_farbe'. For custom (non-taxonomy)
 *                            attributes pass the attribute key — the term lookup simply
 *                            misses and the colour-name map decides.
 * @param  string $term_slug  Term slug (taxonomy) or raw option string (custom attribute).
 * @param  string $image_size Registered image size for the swatch image URL.
 * @return array{type:string,image:string,color:string,color2:string,is_light:bool}
 */
function yk_wcgv_get_swatch( string $taxonomy, string $term_slug, string $image_size = YK_WCGV_SWATCH_IMAGE_SIZE ): array {
	static $cache = [];

	$cache_key = $taxonomy . '|' . $term_slug . '|' . $image_size;
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$swatch = [
		'type'     => 'none',
		'image'    => '',
		'color'    => '',
		'color2'   => '',
		'is_light' => true,
	];

	$term = null;
	if ( $taxonomy && $term_slug && taxonomy_exists( $taxonomy ) ) {
		// TODO(v2 STEP5): when WPML is active and this is a translated term, fall back
		// to the source term's meta — term meta is not copied to translations.
		$found = get_term_by( 'slug', $term_slug, $taxonomy );
		if ( $found && ! is_wp_error( $found ) ) {
			$term = $found;
		}
	}

	$colors = [];

	if ( $term ) {
		$image_id = (int) get_term_meta( $term->term_id, YK_WCGV_META_SWATCH_IMAGE_ID, true );
		if ( $image_id > 0 ) {
			// Returns false when the attachment was deleted — then we fall through to colours.
			// When the requested size has not been generated yet, core falls back to the
			// full-size URL, so the swatch still renders (see README: regenerate thumbnails).
			$image_url = wp_get_attachment_image_url( $image_id, $image_size );
			if ( $image_url ) {
				$swatch['type']  = 'image';
				$swatch['image'] = (string) $image_url;
			}
		}

		$colors = yk_wcgv_parse_swatch_colors( get_term_meta( $term->term_id, YK_WCGV_META_SWATCH_COLOR, true ) );
	}

	if ( empty( $colors ) ) {
		$named = yk_wcgv_resolve_color( $term_slug, $term ? $term->name : $term_slug );
		if ( YK_WCGV_COLOR_FALLBACK !== $named ) {
			$colors = [ $named ];
		}
	}

	if ( ! empty( $colors ) ) {
		$swatch['color']    = $colors[0];
		$swatch['color2']   = $colors[1] ?? '';
		$swatch['is_light'] = yk_wcgv_is_light_color( $colors[0] );

		if ( 'image' !== $swatch['type'] ) {
			$swatch['type'] = '' !== $swatch['color2'] ? 'gradient' : 'color';
		}
	}

	$cache[ $cache_key ] = $swatch;

	return $swatch;
}
