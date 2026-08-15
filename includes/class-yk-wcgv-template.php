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

		// Scoped to the archive loop only — see remove_default_loop_hooks().
		add_action( 'woocommerce_before_shop_loop', [ __CLASS__, 'remove_default_loop_hooks' ] );
		add_action( 'woocommerce_after_shop_loop', [ __CLASS__, 'restore_default_loop_hooks' ] );

		add_filter( 'body_class', [ __CLASS__, 'variant_body_class' ] );
	}

	/**
	 * Default loop callbacks our card template replaces: [ hook, callback ].
	 *
	 * The priority is not hardcoded — it is read back from has_action() when removing, so
	 * a site that re-prioritised them gets them restored exactly as they were.
	 */
	private static function default_loop_hooks(): array {
		return [
			[ 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open' ],
			[ 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close' ],
			[ 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' ],
		];
	}

	/**
	 * Callbacks this class removed, with their original priority, so they can be restored.
	 *
	 * @var array
	 */
	private static $removed_loop_hooks = [];

	/**
	 * Whether the default loop callbacks are currently removed by this class.
	 *
	 * Guards against a second removal pass overwriting the restore list with an empty one:
	 * on an archive both woocommerce_before_shop_loop and the template filter ask for the
	 * removal, and by the time the filter runs has_action() already reports false.
	 *
	 * @var bool
	 */
	private static $loop_hooks_removed = false;

	/**
	 * @return string
	 */
	public static function force_classic_product_grid(): string {
		return 'no';
	}

	/**
	 * True while YK_WCGV_Ajax renders a loop.
	 *
	 * In an AJAX request is_shop() and friends are all false, so the archive check below
	 * would hand back WooCommerce's default card and the infinite-scroll pages would look
	 * nothing like page 1.
	 *
	 * @var bool
	 */
	private static $ajax_rendering = false;

	/**
	 * @param bool $rendering Whether an AJAX loop render is in progress.
	 */
	public static function set_ajax_rendering( bool $rendering ): void {
		self::$ajax_rendering = $rendering;
	}

	public static function override_template_part( string $template, string $slug, string $name ): string {
		if ( 'content' !== $slug || 'product' !== $name ) {
			return $template;
		}
		if ( ! self::$ajax_rendering && ( ! function_exists( 'is_woocommerce' ) || ! ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) ) {
			return $template;
		}
		$plugin_template = YK_WCGV_DIR . 'woocommerce/content-product.php';
		if ( ! file_exists( $plugin_template ) ) {
			return $template;
		}

		// Safety net: our card is about to render, so the default wrappers must be off.
		// Normally woocommerce_before_shop_loop already did this; a theme whose archive
		// template does not fire that hook would otherwise get a stray <a> wrapper and a
		// duplicate add-to-cart button inside every .yk-card.
		self::remove_default_loop_hooks();

		return $plugin_template;
	}

	/**
	 * Remove WooCommerce's default loop wrappers — our card template renders its own
	 * structure (its own link wrapper and its own AJAX add-to-cart button).
	 *
	 * Scope: the archive loop only. This runs on `woocommerce_before_shop_loop` and is
	 * undone on `woocommerce_after_shop_loop`, because related products, upsells,
	 * cross-sells and [products] shortcode grids render the stock WooCommerce card
	 * through woocommerce_product_loop_start() *without* firing those two actions.
	 * Removing globally (the old `wp` hook) stripped the product link and the
	 * add-to-cart button from all of them.
	 */
	public static function remove_default_loop_hooks(): void {
		if ( self::$loop_hooks_removed ) {
			return; // Idempotent: the archive loop and the template filter both ask.
		}

		self::$removed_loop_hooks = [];

		foreach ( self::default_loop_hooks() as $hook ) {
			list( $tag, $callback ) = $hook;

			$priority = has_action( $tag, $callback );
			if ( false === $priority ) {
				continue; // The theme or another plugin already unhooked it — leave it alone.
			}

			remove_action( $tag, $callback, $priority );
			self::$removed_loop_hooks[] = [ $tag, $callback, $priority ];
		}

		self::$loop_hooks_removed = true;
	}

	/**
	 * Put back exactly what we removed, at its original priority.
	 *
	 * Only callbacks this class actually removed are restored, so a site that
	 * deliberately unhooked one of them elsewhere keeps its own behaviour.
	 */
	public static function restore_default_loop_hooks(): void {
		foreach ( self::$removed_loop_hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			add_action( $tag, $callback, $priority );
		}

		self::$removed_loop_hooks = [];
		self::$loop_hooks_removed = false;
	}

	/**
	 * Add a body class for the active global-styles variation, so CSS can override
	 * the --yk-* design tokens per style variant (e.g. body.yk-variant-shop-attack).
	 *
	 * The title comes from the plugin setting, because WordPress cannot be asked which
	 * variation is active: the Site Editor copies the chosen styles/*.json into the user
	 * global styles WITHOUT its `title`, and records the name nowhere else. Auto-detection
	 * is kept underneath only for the rare setup that writes a title into the user global
	 * styles itself; on a normal site it finds nothing.
	 *
	 * The filter still has the last word, so existing sites keep working unchanged:
	 *
	 *     add_filter( 'yk_wcgv_style_variant', fn() => 'Shop Attack' );
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function variant_body_class( array $classes ): array {
		$settings = yk_wcgv_get_settings();
		$title    = (string) ( $settings['style_variant'] ?? '' );

		if ( '' === $title && class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$user_data = WP_Theme_JSON_Resolver::get_user_data();
			if ( $user_data ) {
				$raw   = $user_data->get_raw_data();
				$title = $raw['title'] ?? '';
			}
		}

		/**
		 * Filters the style variant title used for the body class.
		 *
		 * Return the variation title exactly as it appears in the theme's styles/*.json
		 * ("Shop Attack" → body.yk-variant-shop-attack).
		 *
		 * @param string $title Title from the plugin setting, or '' when set to "None".
		 */
		$title = (string) apply_filters( 'yk_wcgv_style_variant', $title );

		if ( '' === $title ) {
			return $classes;
		}

		$classes[] = 'yk-variant-' . sanitize_html_class( sanitize_title( $title ) );
		return $classes;
	}
}
