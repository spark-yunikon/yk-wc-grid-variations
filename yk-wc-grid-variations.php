<?php
/**
 * Plugin Name:  YK WC Grid Variations
 * Plugin URI:   https://yunikon.ch/
 * Description:  Custom product card display for WooCommerce archive / category pages. Interactive swatches, size pills, quantity stepper, and AJAX add-to-cart.
 * Version:      1.4.0
 * Author:       Yunikon GmbH
 * Author URI:   https://yunikon.ch/
 * Text Domain:  yk-wc-grid-variations
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'YK_WCGV_VERSION', '1.4.0' );
define( 'YK_WCGV_DIR', plugin_dir_path( __FILE__ ) );
define( 'YK_WCGV_URL', plugin_dir_url( __FILE__ ) );

const YK_WCGV_COLOR_KEYS = [ 'color', 'colour', 'farbe' ];
const YK_WCGV_SIZE_KEYS  = [ 'size', 'größe', 'grösse', 'grosse', 'groesse', 'taille' ];

// ── WooCommerce HPOS compatibility ───────────────────────────────────────────

add_action( 'before_woocommerce_init', 'yk_wcgv_declare_hpos_compatibility' );
function yk_wcgv_declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}

// ── Internationalisation ───────────────────────────────────────────────────────

add_action( 'init', 'yk_wcgv_load_textdomain' );
function yk_wcgv_load_textdomain(): void {
	load_plugin_textdomain(
		'yk-wc-grid-variations',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}

// ── Assets ─────────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'yk_wcgv_enqueue_assets' );
function yk_wcgv_enqueue_assets(): void {
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return;
	}

	if ( is_woocommerce() ) {
		wp_enqueue_style(
			'yk-wcgv',
			YK_WCGV_URL . 'assets/css/yk-wcgv.css',
			[],
			YK_WCGV_VERSION
		);

		wp_enqueue_script(
			'yk-wcgv',
			YK_WCGV_URL . 'assets/js/yk-wcgv.js',
			[ 'jquery' ],
			YK_WCGV_VERSION,
			true
		);
	}

	$settings = yk_wcgv_get_settings();
	if ( is_product() && $settings['enable_product_page'] === '1' ) {
		wp_enqueue_style(
			'yk-wcgv-product',
			YK_WCGV_URL . 'assets/css/yk-wcgv-product.css',
			[ 'yk-wcgv' ],
			YK_WCGV_VERSION
		);

		wp_enqueue_script(
			'yk-wcgv-product',
			YK_WCGV_URL . 'assets/js/yk-wcgv-product.js',
			[ 'jquery' ],
			YK_WCGV_VERSION,
			true
		);
	}
}

// Inject colour map for the current product so swatches render with the correct bg colour.
add_action( 'wp_footer', 'yk_wcgv_inject_product_page_data', 1 );
function yk_wcgv_inject_product_page_data(): void {
	$settings = yk_wcgv_get_settings();
	if ( $settings['enable_product_page'] !== '1' ) {
		return;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	if ( ! wp_script_is( 'yk-wcgv-product', 'enqueued' ) ) {
		return;
	}

	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$colors = [];

	if ( 'variable' === $product->get_type() ) {
		foreach ( $product->get_attributes() as $attr_key => $attribute ) {
			$slug  = strtolower( str_replace( 'pa_', '', $attr_key ) );
			$label = strtolower( wc_attribute_label( $attr_key, $product ) );

			if ( ! yk_wcgv_attr_matches( $slug, $label, YK_WCGV_COLOR_KEYS ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attr_key, [ 'fields' => 'all' ] );
				foreach ( $terms as $term ) {
					$colors[ $term->slug ] = yk_wcgv_resolve_color( $term->slug, $term->name );
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$slug_key            = sanitize_title( $option );
					$colors[ $slug_key ] = yk_wcgv_resolve_color( $slug_key, $option );
				}
			}
		}
	}

	wp_add_inline_script(
		'yk-wcgv-product',
		'window.ykWcgvProduct = ' . wp_json_encode( [ 'colors' => $colors ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
}

// Force WooCommerce to use its legacy-template (PHP loop) on archive pages rather
// than the blockified product-collection block, which bypasses our hooks entirely.
//
// KNOWN LIMITATION: this plugin renders product cards through the classic
// `wc_get_template_part( 'content', 'product' )` path. The blockified Product
// Collection block renders its grid in JS/markup that never calls that hook, so
// our `.yk-card` template, swatches, pills, qty stepper and AJAX add-to-cart would
// not apply. We force the legacy template here so the cards work on shop, category
// and tag archives. Consequence: shop/category archives must NOT be built with the
// Product Collection block in the Site Editor — keep the classic/template grid.
// Re-test this override on each major WooCommerce upgrade.
add_filter( 'option_wc_blocks_use_blockified_product_grid_block_as_template', fn() => 'no' );

// ── Template override ──────────────────────────────────────────────────────────

add_filter( 'wc_get_template_part', 'yk_wcgv_override_template_part', 10, 3 );
function yk_wcgv_override_template_part( string $template, string $slug, string $name ): string {
	if ( 'content' !== $slug || 'product' !== $name ) {
		return $template;
	}
	if ( ! function_exists( 'is_woocommerce' ) || ! ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
		return $template;
	}
	$plugin_template = YK_WCGV_DIR . 'woocommerce/content-product.php';
	return file_exists( $plugin_template ) ? $plugin_template : $template;
}

// ── Variation data — collect IDs during loop, inject before JS runs ────────────

// Remove WooCommerce's default loop wrappers — our template renders its own structure.
add_action( 'wp', function() {
	remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
	remove_action( 'woocommerce_after_shop_loop_item',  'woocommerce_template_loop_product_link_close', 5 );
	remove_action( 'woocommerce_after_shop_loop_item',  'woocommerce_template_loop_add_to_cart', 10 );
} );

// Accumulate product IDs as the WooCommerce loop renders each card.
add_action( 'woocommerce_before_shop_loop_item', 'yk_wcgv_collect_product_id' );
function yk_wcgv_collect_product_id(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}
	if ( ! isset( $GLOBALS['yk_wcgv_product_ids'] ) ) {
		$GLOBALS['yk_wcgv_product_ids'] = [];
	}
	$GLOBALS['yk_wcgv_product_ids'][] = $product->get_id();
}

// Inject the compiled data as an inline script (position 'before') so it is
// defined before yk-wcgv.js executes. Priority 1 fires before wp_print_footer_scripts() at 20.
add_action( 'wp_footer', 'yk_wcgv_inject_data', 1 );
function yk_wcgv_inject_data(): void {
	if ( empty( $GLOBALS['yk_wcgv_product_ids'] ) ) {
		return;
	}

	$payload = yk_wcgv_build_product_data( $GLOBALS['yk_wcgv_product_ids'] );

	wp_add_inline_script(
		'yk-wcgv',
		'window.ykWcgv = ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
}

/**
 * Build the JSON payload passed to the JS layer for all products in the loop.
 *
 * @param  int[] $product_ids Parent product IDs collected during the loop.
 * @return array
 */
function yk_wcgv_build_product_data( array $product_ids ): array {
	$product_data = [];

	foreach ( array_unique( $product_ids ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}

		$type = $product->get_type();

		if ( 'bundle' === $type ) {
			$product_data[ $product_id ] = [ 'type' => 'bundle' ];
			continue;
		}

		if ( 'variable' === $type ) {
			$color_attr = '';
			$size_attr  = '';

			foreach ( $product->get_attributes() as $attr_key => $attribute ) {
				$slug  = strtolower( str_replace( 'pa_', '', $attr_key ) );
				$label = strtolower( wc_attribute_label( $attr_key, $product ) );

				if ( ! $color_attr && yk_wcgv_attr_matches( $slug, $label, YK_WCGV_COLOR_KEYS ) ) {
					$color_attr = 'attribute_' . $attr_key;
				}

				if ( ! $size_attr && yk_wcgv_attr_matches( $slug, $label, YK_WCGV_SIZE_KEYS ) ) {
					$size_attr = 'attribute_' . $attr_key;
				}

				if ( $color_attr && $size_attr ) {
					break;
				}
			}

			$variations = [];
			foreach ( $product->get_available_variations() as $var_data ) {
				$var_id    = (int) $var_data['variation_id'];
				$variation = wc_get_product( $var_id );
				if ( ! $variation ) {
					continue;
				}

				$image_id  = $variation->get_image_id() ?: $product->get_image_id();
				$image_url = $image_id
					? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
					: '';
				$srcset    = $image_id
					? (string) wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' )
					: '';
				$sizes_str = $image_id
					? (string) wp_get_attachment_image_sizes( $image_id, 'woocommerce_thumbnail' )
					: '';

				$max_qty = $variation->get_max_purchase_quantity();

				$variations[] = [
					'variation_id' => $var_id,
					'attributes'   => $var_data['attributes'],
					'image_url'    => $image_url,
					'image_srcset' => $srcset,
					'image_sizes'  => $sizes_str,
					'is_in_stock'  => (bool) $var_data['is_in_stock'],
					'max_qty'      => $max_qty > 0 ? $max_qty : 9999,
				];
			}

			$product_data[ $product_id ] = [
				'type'       => 'variable',
				'color_attr' => $color_attr,
				'size_attr'  => $size_attr,
				'variations' => $variations,
			];
			continue;
		}

		// Simple / external / downloadable products.
		$max_qty = $product->get_max_purchase_quantity();
		$product_data[ $product_id ] = [
			'type'        => $type,
			'is_in_stock' => (bool) $product->is_in_stock(),
			'max_qty'     => $max_qty > 0 ? $max_qty : 9999,
		];
	}

	return [
		'ajax_url' => add_query_arg( 'wc-ajax', '%%endpoint%%', home_url( '/' ) ),
		'lang'     => (string) apply_filters( 'wpml_current_language', '' ),
		'products' => $product_data,
		'i18n'     => yk_wcgv_i18n(),
	];
}

// ── Style-variant body class ───────────────────────────────────────────────────

add_filter( 'body_class', 'yk_wcgv_variant_body_class' );
function yk_wcgv_variant_body_class( array $classes ): array {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return $classes;
	}
	$user_data = WP_Theme_JSON_Resolver::get_user_data();
	if ( ! $user_data ) {
		return $classes;
	}
	$raw   = $user_data->get_raw_data();
	$title = $raw['title'] ?? '';
	if ( ! $title ) {
		return $classes;
	}
	$classes[] = 'yk-variant-' . sanitize_html_class( sanitize_title( $title ) );
	return $classes;
}

// ── Shared helpers ─────────────────────────────────────────────────────────────

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
 * Translatable JS-facing strings — single source for both the data payload
 * and WPML string registration.
 */
function yk_wcgv_i18n(): array {
	return [
		'added'            => __( 'Hinzugefügt',                     'yk-wc-grid-variations' ),
		'error'            => __( 'Fehler. Bitte erneut versuchen.', 'yk-wc-grid-variations' ),
		'select_variation' => __( 'Bitte Farbe und Grösse wählen.',  'yk-wc-grid-variations' ),
	];
}

// ── Color resolution helper ────────────────────────────────────────────────────

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

	return '#cccccc';
}

// ── WPML string registration ───────────────────────────────────────────────────

add_action( 'init', 'yk_wcgv_register_wpml_strings', 20 );
function yk_wcgv_register_wpml_strings(): void {
	if ( ! function_exists( 'icl_register_string' ) ) {
		return;
	}

	$i18n    = yk_wcgv_i18n();
	$context = 'yk-wc-grid-variations';

	$strings = [
		'sale_badge'          => 'SALE',
		'art_nr'              => 'Art.-Nr.',
		'available_colours'   => 'Available colours',
		'available_sizes'     => 'Available sizes',
		'decrease_quantity'   => 'Decrease quantity',
		'increase_quantity'   => 'Increase quantity',
		'quantity'            => 'Quantity',
		'incl_tax'            => 'inkl. MwSt.',
		'zum_produkt'         => 'Zum Produkt',
		'in_den_warenkorb'    => 'In den Warenkorb',
		'add_to_cart_aria'    => 'Add %s to cart',
		'js_added'            => $i18n['added'],
		'js_error'            => $i18n['error'],
		'js_select_variation' => $i18n['select_variation'],
	];

	foreach ( $strings as $name => $value ) {
		icl_register_string( $context, $name, $value );
	}
}

// ── Settings ───────────────────────────────────────────────────────────────────

/**
 * Returns plugin settings merged with defaults.
 * Defaults mirror the original hardcoded behaviour (everything on, all attrs shown).
 */
function yk_wcgv_get_settings(): array {
	return wp_parse_args( (array) get_option( 'yk_wcgv_settings', [] ), [
		'show_sale_badge'    => '1',
		'show_sku'           => '1',
		'show_variations'    => '1',
		'visible_attrs'      => [],  // empty = all attributes; filled = only those pa_* slugs
		'show_qty_stepper'   => '1',
		'enable_product_page' => '1',
	] );
}

add_action( 'admin_menu', 'yk_wcgv_add_menu_page' );
function yk_wcgv_add_menu_page(): void {
	add_submenu_page(
		'woocommerce',
		__( 'YK Grid Variations', 'yk-wc-grid-variations' ),
		__( 'YK Grid Variations', 'yk-wc-grid-variations' ),
		'manage_woocommerce',
		'yk-wc-grid-variations',
		'yk_wcgv_settings_page_html'
	);
}

add_action( 'admin_init', 'yk_wcgv_register_settings' );
function yk_wcgv_register_settings(): void {
	register_setting(
		'yk_wcgv_settings_group',
		'yk_wcgv_settings',
		[ 'sanitize_callback' => 'yk_wcgv_sanitize_settings' ]
	);
}

function yk_wcgv_sanitize_settings( $raw ): array {
	$raw   = is_array( $raw ) ? $raw : [];
	$clean = [];

	$clean['show_sale_badge']     = ! empty( $raw['show_sale_badge'] )     ? '1' : '0';
	$clean['show_sku']            = ! empty( $raw['show_sku'] )            ? '1' : '0';
	$clean['show_variations']     = ! empty( $raw['show_variations'] )     ? '1' : '0';
	$clean['show_qty_stepper']    = ! empty( $raw['show_qty_stepper'] )    ? '1' : '0';
	$clean['enable_product_page'] = ! empty( $raw['enable_product_page'] ) ? '1' : '0';

	$clean['visible_attrs'] = [];
	if ( ! empty( $raw['visible_attrs'] ) && is_array( $raw['visible_attrs'] ) ) {
		foreach ( $raw['visible_attrs'] as $attr ) {
			$clean['visible_attrs'][] = sanitize_key( $attr );
		}
	}

	return $clean;
}

function yk_wcgv_settings_page_html(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$settings   = yk_wcgv_get_settings();
	$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : [];
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'YK Grid Variations', 'yk-wc-grid-variations' ); ?></h1>
		<p style="color:#666;margin-top:0;"><?php esc_html_e( 'Controls what is displayed on WooCommerce category, archive, and single product pages.', 'yk-wc-grid-variations' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'yk_wcgv_settings_group' ); ?>

			<h2 class="title"><?php esc_html_e( 'Product Cards', 'yk-wc-grid-variations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Applies to archive and category pages.', 'yk-wc-grid-variations' ); ?></p>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Sale badge', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="yk_wcgv_settings[show_sale_badge]" value="1"
								<?php checked( $settings['show_sale_badge'], '1' ); ?>>
							<?php esc_html_e( 'Show SALE badge on discounted products', 'yk-wc-grid-variations' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'SKU', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="yk_wcgv_settings[show_sku]" value="1"
								<?php checked( $settings['show_sku'], '1' ); ?>>
							<?php esc_html_e( 'Display Art.-Nr. / SKU beneath the product title', 'yk-wc-grid-variations' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Variation swatches &amp; pills', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="yk_wcgv_settings[show_variations]" value="1"
								id="yk-wcgv-show-variations"
								<?php checked( $settings['show_variations'], '1' ); ?>>
							<?php esc_html_e( 'Show colour swatches and size pills on category pages', 'yk-wc-grid-variations' ); ?>
						</label>
					</td>
				</tr>

				<?php if ( ! empty( $attributes ) ) : ?>
				<tr id="yk-wcgv-attrs-row" <?php echo $settings['show_variations'] !== '1' ? 'style="opacity:0.4;pointer-events:none;"' : ''; ?>>
					<th scope="row"><?php esc_html_e( 'Visible attributes', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Visible attributes', 'yk-wc-grid-variations' ); ?></legend>
							<p class="description" style="margin-bottom:0.75rem;">
								<?php esc_html_e( 'Choose which product attributes appear as swatches or pills. Leave all unchecked to show every attribute.', 'yk-wc-grid-variations' ); ?>
							</p>
							<?php foreach ( $attributes as $attr ) :
								$tax     = 'pa_' . $attr->attribute_name;
								$checked = empty( $settings['visible_attrs'] ) || in_array( $tax, $settings['visible_attrs'], true );
							?>
								<label style="display:block;margin-bottom:6px;">
									<input
										type="checkbox"
										name="yk_wcgv_settings[visible_attrs][]"
										value="<?php echo esc_attr( $tax ); ?>"
										<?php checked( $checked ); ?>
									>
									<strong><?php echo esc_html( $attr->attribute_label ); ?></strong>
									<span style="color:#888;font-size:0.8125em;margin-left:4px;"><?php echo esc_html( $tax ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Quantity stepper', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="yk_wcgv_settings[show_qty_stepper]" value="1"
								<?php checked( $settings['show_qty_stepper'], '1' ); ?>>
							<?php esc_html_e( 'Show +/− quantity stepper on product cards', 'yk-wc-grid-variations' ); ?>
						</label>
					</td>
				</tr>

			</table>

			<h2 class="title"><?php esc_html_e( 'Single Product Page', 'yk-wc-grid-variations' ); ?></h2>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Enhanced variation UI', 'yk-wc-grid-variations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="yk_wcgv_settings[enable_product_page]" value="1"
								<?php checked( $settings['enable_product_page'], '1' ); ?>>
							<?php esc_html_e( 'Replace variation dropdowns with swatches/pills and add +/− quantity stepper', 'yk-wc-grid-variations' ); ?>
						</label>
					</td>
				</tr>

			</table>

			<?php submit_button(); ?>
		</form>
	</div>

	<script>
	( function() {
		var toggle = document.getElementById( 'yk-wcgv-show-variations' );
		var row    = document.getElementById( 'yk-wcgv-attrs-row' );
		if ( ! toggle || ! row ) return;
		toggle.addEventListener( 'change', function() {
			row.style.opacity       = toggle.checked ? '1'    : '0.4';
			row.style.pointerEvents = toggle.checked ? 'auto' : 'none';
		} );
	} )();
	</script>
	<?php
}
