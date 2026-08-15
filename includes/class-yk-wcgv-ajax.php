<?php
/**
 * AJAX endpoint that serves one more page of product cards.
 *
 * Returns markup + payload only — never a whole rendered page. Fetching the next page's
 * HTML and parsing it out would render header, menu, footer and widgets for nothing.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Ajax {

	const ACTION = 'yk_wcgv_load_page';
	const NONCE  = 'yk_wcgv_infinite';

	/**
	 * Products per page, as observed on the front end.
	 *
	 * apply_filters( 'loop_shop_per_page', … ) does not give the same answer inside
	 * admin-ajax as it does on an archive — themes commonly register that filter behind
	 * an ! is_admin() check. On this shop the front end shows 16 and admin-ajax computed
	 * 10, which would have made page 2 start in the middle of page 1. So the value is
	 * recorded from the real loop and read back here; it is server-derived either way,
	 * never taken from the request.
	 */
	const PER_PAGE_OPTION = 'yk_wcgv_loop_per_page';

	/**
	 * Query variables a client may influence. Everything else — post_type, post_status,
	 * posts_per_page, meta_query, fields — is decided here, never by the request.
	 *
	 * The filter_* / query_type_* layered-nav pairs are matched by prefix below.
	 */
	const ALLOWED_VARS = [ 'product_cat', 'product_tag', 'paged', 'orderby', 's', 'min_price', 'max_price', 'yk_taxonomy', 'yk_term' ];

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'woocommerce_before_shop_loop', [ __CLASS__, 'remember_per_page' ] );
	}

	/**
	 * Record how many products the real archive loop shows per page.
	 *
	 * @see self::PER_PAGE_OPTION
	 */
	public static function remember_per_page(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		global $wp_query;

		$per_page = isset( $wp_query ) ? (int) $wp_query->get( 'posts_per_page' ) : 0;
		if ( $per_page <= 0 ) {
			return;
		}

		if ( (int) get_option( self::PER_PAGE_OPTION ) !== $per_page ) {
			update_option( self::PER_PAGE_OPTION, $per_page, false );
		}
	}

	/**
	 * Serve one page of cards.
	 */
	public static function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		// This response depends on the shopper's session (stock, prices, layered nav) and
		// must never be stored by a page cache plugin or a CDN. The shop's own cart page is
		// proof that something on this stack caches aggressively.
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

		$request = self::sanitize_request();
		$paged   = max( 1, (int) $request['paged'] );

		// WooCommerce builds its tax/meta queries from $_GET (layered nav, price filter,
		// visibility). Feed it the sanitised whitelist, then restore the superglobal.
		$original_get = $_GET;
		$_GET         = self::query_vars_for_wc( $request ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$query = self::run_query( $request, $paged );
			$html  = self::render_loop( $query );

			$product_ids = self::collected_ids();
			$payload     = YK_WCGV_Data::build_product_data( $product_ids );

			wp_send_json_success( [
				'html'         => $html,
				'products'     => $payload['products'],
				'current_page' => $paged,
				'max_pages'    => (int) $query->max_num_pages,
				'has_more'     => $paged < (int) $query->max_num_pages,
			] );
		} finally {
			$_GET = $original_get;
		}
	}

	// ── Request handling ─────────────────────────────────────────────────────

	/**
	 * Whitelist + sanitise. Anything not listed here is dropped on the floor.
	 *
	 * @return array
	 */
	private static function sanitize_request(): array {
		$out = [
			'product_cat' => '',
			'product_tag' => '',
			'paged'       => 1,
			'orderby'     => '',
			's'           => '',
			'min_price'   => '',
			'max_price'   => '',
			'yk_taxonomy' => '',
			'yk_term'     => '',
			'layered'     => [],
		];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		foreach ( self::ALLOWED_VARS as $var ) {
			if ( ! isset( $_POST[ $var ] ) ) {
				continue;
			}
			$value = wp_unslash( $_POST[ $var ] );

			switch ( $var ) {
				case 'paged':
					$out[ $var ] = absint( $value );
					break;
				case 's':
					$out[ $var ] = sanitize_text_field( $value );
					break;
				case 'min_price':
				case 'max_price':
					$out[ $var ] = is_numeric( $value ) ? (string) floatval( $value ) : '';
					break;
				case 'product_cat':
				case 'product_tag':
				case 'yk_term':
					// Comma separated slugs are legal in WooCommerce archives.
					$slugs       = array_filter( array_map( 'sanitize_title', explode( ',', (string) $value ) ) );
					$out[ $var ] = implode( ',', $slugs );
					break;
				case 'orderby':
					$out[ $var ] = sanitize_key( $value );
					break;
				case 'yk_taxonomy':
					$out[ $var ] = sanitize_key( $value );
					break;
			}
		}

		// Layered navigation: filter_pa_farbe=rot,blau plus query_type_pa_farbe=or|and.
		foreach ( $_POST as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
				$clean_key = sanitize_key( $key );
				$raw       = wp_unslash( $value );

				if ( 0 === strpos( $clean_key, 'query_type_' ) ) {
					$clean = in_array( $raw, [ 'or', 'and' ], true ) ? $raw : 'and';
				} else {
					$clean = implode( ',', array_filter( array_map( 'sanitize_title', explode( ',', (string) $raw ) ) ) );
				}

				if ( '' !== $clean ) {
					$out['layered'][ $clean_key ] = $clean;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// An attribute archive is only accepted for a real, public product taxonomy.
		if ( $out['yk_taxonomy'] && ! self::is_supported_taxonomy( $out['yk_taxonomy'] ) ) {
			$out['yk_taxonomy'] = '';
			$out['yk_term']     = '';
		}

		return $out;
	}

	/**
	 * @param  string $taxonomy Candidate taxonomy.
	 * @return bool
	 */
	private static function is_supported_taxonomy( string $taxonomy ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) {
			return true;
		}

		return function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $taxonomy );
	}

	/**
	 * The subset of the request WooCommerce's own query helpers read out of $_GET.
	 *
	 * @param  array $request Sanitised request.
	 * @return array
	 */
	private static function query_vars_for_wc( array $request ): array {
		$vars = [];

		foreach ( [ 'min_price', 'max_price', 'orderby', 's' ] as $key ) {
			if ( '' !== $request[ $key ] ) {
				$vars[ $key ] = $request[ $key ];
			}
		}

		foreach ( $request['layered'] as $key => $value ) {
			$vars[ $key ] = $value;
		}

		return $vars;
	}

	/**
	 * Run the product query for the requested page.
	 *
	 * @param  array $request Sanitised request.
	 * @param  int   $paged   Page number.
	 * @return WP_Query
	 */
	private static function run_query( array $request, int $paged ): WP_Query {
		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'paged'               => $paged,
			'posts_per_page'      => self::per_page(),
			'tax_query'           => [],  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		];

		if ( '' !== $request['s'] ) {
			$args['s'] = $request['s'];
		}

		// WooCommerce contributes product visibility, layered nav and the price filter.
		if ( function_exists( 'WC' ) && WC()->query ) {
			$args['tax_query']  = WC()->query->get_tax_query();  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['meta_query'] = WC()->query->get_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

			$ordering = WC()->query->get_catalog_ordering_args( $request['orderby'] ? $request['orderby'] : '' );
			foreach ( [ 'orderby', 'order', 'meta_key' ] as $key ) {
				if ( ! empty( $ordering[ $key ] ) ) {
					$args[ $key ] = $ordering[ $key ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				}
			}
		}

		foreach ( [ 'product_cat' => $request['product_cat'], 'product_tag' => $request['product_tag'] ] as $taxonomy => $slugs ) {
			if ( '' === $slugs ) {
				continue;
			}
			$args['tax_query'][] = [
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => explode( ',', $slugs ),
			];
		}

		if ( $request['yk_taxonomy'] && $request['yk_term'] ) {
			$args['tax_query'][] = [
				'taxonomy' => $request['yk_taxonomy'],
				'field'    => 'slug',
				'terms'    => explode( ',', $request['yk_term'] ),
			];
		}

		return new WP_Query( $args );
	}

	/**
	 * Products per page — the shop's own setting, never a client value.
	 *
	 * @return int
	 */
	private static function per_page(): int {
		$remembered = (int) get_option( self::PER_PAGE_OPTION );
		if ( $remembered > 0 ) {
			return $remembered;
		}

		$per_page = (int) apply_filters( 'loop_shop_per_page', get_option( 'posts_per_page' ) );

		return $per_page > 0 ? $per_page : 12;
	}

	// ── Rendering ────────────────────────────────────────────────────────────

	/**
	 * Render the cards exactly as the archive template does.
	 *
	 * wc_setup_loop() + wc_get_template_part() is the same path a normal page takes, so the
	 * markup is identical — including the loop hooks our card template fires. The template
	 * override checks is_shop()/is_product_category(), which are all false in an AJAX
	 * request, hence the explicit AJAX flag.
	 *
	 * @param  WP_Query $query Products for this page.
	 * @return string
	 */
	private static function render_loop( WP_Query $query ): string {
		if ( ! $query->have_posts() ) {
			return '';
		}

		self::reset_collected_ids();
		YK_WCGV_Template::set_ajax_rendering( true );

		wc_setup_loop( [
			'is_shortcode' => false,
			'is_paginated' => true,
			'total'        => (int) $query->found_posts,
			'total_pages'  => (int) $query->max_num_pages,
			'per_page'     => self::per_page(),
			'current_page' => max( 1, (int) $query->get( 'paged' ) ),
		] );

		// Our card template renders its own link wrapper and add-to-cart button, so the
		// WooCommerce defaults must be off here too. On a normal page that happens on
		// woocommerce_before_shop_loop; in AJAX the template filter's safety net does it,
		// and this call makes it explicit rather than incidental.
		YK_WCGV_Template::remove_default_loop_hooks();

		ob_start();

		while ( $query->have_posts() ) {
			$query->the_post();
			wc_get_template_part( 'content', 'product' );
		}

		$html = (string) ob_get_clean();

		wp_reset_postdata();
		wc_reset_loop();
		YK_WCGV_Template::restore_default_loop_hooks();
		YK_WCGV_Template::set_ajax_rendering( false );

		return $html;
	}

	/**
	 * The card template collects IDs into the shared global as it renders; take them for
	 * this response and leave the global empty for whatever runs next.
	 *
	 * @return int[]
	 */
	private static function collected_ids(): array {
		$ids = isset( $GLOBALS['yk_wcgv_product_ids'] ) ? (array) $GLOBALS['yk_wcgv_product_ids'] : [];
		self::reset_collected_ids();

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private static function reset_collected_ids(): void {
		$GLOBALS['yk_wcgv_product_ids'] = [];
	}
}
