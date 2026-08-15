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
		add_action( 'wp_footer', [ __CLASS__, 'inject_infinite_config' ], 1 );
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
				yk_wcgv_asset_version( 'assets/css/yk-wcgv.css' )
			);

			wp_enqueue_script(
				'yk-wcgv',
				YK_WCGV_URL . 'assets/js/yk-wcgv.js',
				[ 'jquery' ],
				yk_wcgv_asset_version( 'assets/js/yk-wcgv.js' ),
				true
			);
		}

		if ( self::is_infinite_archive() ) {
			wp_enqueue_script(
				'yk-wcgv-infinite',
				YK_WCGV_URL . 'assets/js/yk-wcgv-infinite.js',
				[ 'yk-wcgv' ],
				yk_wcgv_asset_version( 'assets/js/yk-wcgv-infinite.js' ),
				// In the footer AND deferred: the observer must never compete with the
				// first paint. Page 1 has to render exactly as fast as in pagination mode.
				[ 'strategy' => 'defer', 'in_footer' => true ]
			);
		}

		$settings = yk_wcgv_get_settings();
		if ( is_product() && $settings['enable_product_page'] === '1' ) {
			wp_enqueue_style(
				'yk-wcgv-product',
				YK_WCGV_URL . 'assets/css/yk-wcgv-product.css',
				[ 'yk-wcgv' ],
				yk_wcgv_asset_version( 'assets/css/yk-wcgv-product.css' )
			);

			wp_enqueue_script(
				'yk-wcgv-product',
				YK_WCGV_URL . 'assets/js/yk-wcgv-product.js',
				[ 'jquery' ],
				yk_wcgv_asset_version( 'assets/js/yk-wcgv-product.js' ),
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

		$payload = [
			// Every variation attribute with its style and swatches — the source the JS renders from.
			'attributes' => YK_WCGV_Data::build_product_page_attributes( $product ),
			// Legacy slug => hex map, kept for backwards compatibility only.
			'colors'     => YK_WCGV_Data::build_product_page_colors( $product ),
			// Same source as the archive payload, so a string is translated in one place.
			'i18n'       => yk_wcgv_i18n(),
		];

		wp_add_inline_script(
			'yk-wcgv-product',
			'window.ykWcgvProduct = ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * True on a product archive whose effective paging mode is infinite scroll.
	 *
	 * @return bool
	 */
	public static function is_infinite_archive(): bool {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return false;
		}
		if ( ! ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
			return false;
		}

		return 'infinite' === YK_WCGV_Settings::pagination_mode();
	}

	/**
	 * Config for yk-wcgv-infinite.js: endpoint, nonce, where we are in the archive, and
	 * the query the next page has to repeat (filters and sorting included).
	 */
	public static function inject_infinite_config(): void {
		if ( ! wp_script_is( 'yk-wcgv-infinite', 'enqueued' ) ) {
			return;
		}

		global $wp_query;

		$context = YK_WCGV_Settings::archive_context();
		$query   = [];

		if ( 'product_cat' === $context['taxonomy'] || 'product_tag' === $context['taxonomy'] ) {
			$query[ $context['taxonomy'] ] = $context['term_slug'];
		} elseif ( $context['taxonomy'] ) {
			// Attribute archives (/frequenzband/v51-…/) — passed as an explicit pair the
			// endpoint validates against the product attribute taxonomies.
			$query['yk_taxonomy'] = $context['taxonomy'];
			$query['yk_term']     = $context['term_slug'];
		}

		// Sorting, search and layered navigation as they are right now, so page 2 matches
		// page 1. The endpoint whitelists and sanitises every one of these again.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'orderby', 's', 'min_price', 'max_price' ] as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		foreach ( $_GET as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
				$query[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$config = [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'action'      => YK_WCGV_Ajax::ACTION,
			'nonce'       => wp_create_nonce( YK_WCGV_Ajax::NONCE ),
			'prefetch'    => YK_WCGV_Settings::prefetch_distance(),
			'currentPage' => max( 1, (int) get_query_var( 'paged' ) ),
			'maxPages'    => isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 1,
			'query'       => $query,
			'i18n'        => yk_wcgv_i18n(),
		];

		wp_add_inline_script(
			'yk-wcgv-infinite',
			'window.ykWcgvInfinite = ' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
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

		// Everything in here is wrapped, without exception. This runs on wp_footer at
		// priority 1; wp_print_footer_scripts() runs at 20. A fatal here therefore takes
		// out EVERY script on the page — ours, WooCommerce's add-to-cart-variation, the
		// theme's — and the product page silently stops working. Losing our own payload is
		// a degraded card; losing the footer is a broken page.
		try {
			$payload = yk_wcgv_build_product_data( $GLOBALS['yk_wcgv_product_ids'] );

			wp_add_inline_script(
				'yk-wcgv',
				'window.ykWcgv = ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
				'before'
			);
		} catch ( Throwable $e ) {
			// Throwable, not Exception: a TypeError from bad product data is an Error.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'YK WCGV: payload build failed — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
