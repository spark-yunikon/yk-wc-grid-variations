<?php
/**
 * Front-end asset enqueueing and inline data injection.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Assets {

	/**
	 * Register hooks.
	 *
	 * The wp_footer callbacks MUST stay at priority 1: wp_add_inline_script( …,
	 * 'before' ) has to run before wp_print_footer_scripts() (priority 20) prints
	 * the script tags, otherwise window.ykWcgv is undefined and the JS bails out.
	 * The registration order of the two priority-1 callbacks is kept as-is.
	 */
	public static function init(): void {
		add_action( 'after_setup_theme', [ __CLASS__, 'register_image_sizes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'inject_product_page_data' ], 1 );
		add_action( 'wp_footer', [ __CLASS__, 'inject_data' ], 1 );
	}

	/**
	 * Cropped size for swatch images (front-end swatches are 30–40px, so 96px covers 2×).
	 *
	 * Images uploaded before this size existed have no such file; core's image_downsize()
	 * then falls back to the full-size URL, so swatches still render — regenerating
	 * thumbnails only makes them smaller to download.
	 */
	public static function register_image_sizes(): void {
		add_image_size( YK_WCGV_SWATCH_IMAGE_SIZE, 96, 96, true );
	}

	public static function enqueue_assets(): void {
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

	/**
	 * Inject the colour map for the current product so swatches render with the
	 * correct background colour.
	 */
	public static function inject_product_page_data(): void {
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

		$colors = YK_WCGV_Data::build_product_page_colors( $product );

		wp_add_inline_script(
			'yk-wcgv-product',
			'window.ykWcgvProduct = ' . wp_json_encode( [ 'colors' => $colors ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * Inject the compiled data as an inline script (position 'before') so it is
	 * defined before yk-wcgv.js executes.
	 */
	public static function inject_data(): void {
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
}
