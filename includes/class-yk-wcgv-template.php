<?php
/**
 * Template override, WooCommerce loop hook cleanup and body classes.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Template {

	public static function init(): void {
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
		add_filter( 'option_wc_blocks_use_blockified_product_grid_block_as_template', [ __CLASS__, 'force_classic_product_grid' ] );

		add_filter( 'wc_get_template_part', [ __CLASS__, 'override_template_part' ], 10, 3 );
		add_action( 'wp', [ __CLASS__, 'remove_default_loop_hooks' ] );
		add_filter( 'body_class', [ __CLASS__, 'variant_body_class' ] );
	}

	/**
	 * @return string
	 */
	public static function force_classic_product_grid(): string {
		return 'no';
	}

	public static function override_template_part( string $template, string $slug, string $name ): string {
		if ( 'content' !== $slug || 'product' !== $name ) {
			return $template;
		}
		if ( ! function_exists( 'is_woocommerce' ) || ! ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
			return $template;
		}
		$plugin_template = YK_WCGV_DIR . 'woocommerce/content-product.php';
		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	/**
	 * Remove WooCommerce's default loop wrappers — our template renders its own structure.
	 *
	 * TODO(v2 STEP4): scope to archive pages only.
	 * These removals are unconditional and therefore global: related products, upsells,
	 * cross-sells and [products] shortcode grids lose their product link wrapper and
	 * default add-to-cart button too, even though those loops render the stock
	 * WooCommerce card. Left as-is here because this step is a pure refactor.
	 */
	public static function remove_default_loop_hooks(): void {
		remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
		remove_action( 'woocommerce_after_shop_loop_item',  'woocommerce_template_loop_product_link_close', 5 );
		remove_action( 'woocommerce_after_shop_loop_item',  'woocommerce_template_loop_add_to_cart', 10 );
	}

	/**
	 * Add a body class for the active global-styles variation, so CSS can override
	 * the --yk-* design tokens per style variant.
	 */
	public static function variant_body_class( array $classes ): array {
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
}
