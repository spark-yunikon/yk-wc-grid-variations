<?php
/**
 * Variation data collection and JS payload building.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Data {

	/**
	 * Bump when the cached entry shape changes. The version sits in the transient key, so
	 * old entries are simply never read again — no migration, no manual flush.
	 */
	const CACHE_VERSION = 1;

	/** Transient lifetime. Stock and product edits invalidate earlier via the hooks below. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	const CACHE_PREFIX = 'yk_wcgv_v';

	/** Per-request memo: "productId|lang" => entry. Also what stops the template and the
	 *  payload builder from computing the same product twice. */
	private static $entries = [];

	/** Product IDs whose caches this request already primed. */
	private static $primed = [];

	public static function init(): void {
		// Accumulate product IDs as the WooCommerce loop renders each card.
		add_action( 'woocommerce_before_shop_loop_item', [ __CLASS__, 'collect_product_id' ] );

		// Prime every cache the loop is about to need, in a handful of queries, before the
		// first card template runs. Without this the template would fault them in one
		// product (and one variation, and one term) at a time.
		add_action( 'woocommerce_before_shop_loop', [ __CLASS__, 'prime_main_query' ] );

		// Cache invalidation.
		add_action( 'woocommerce_update_product', [ __CLASS__, 'flush_product' ] );
		add_action( 'woocommerce_delete_product', [ __CLASS__, 'flush_product' ] );
		add_action( 'woocommerce_trash_product', [ __CLASS__, 'flush_product' ] );
		add_action( 'save_post_product_variation', [ __CLASS__, 'flush_variation' ] );
		add_action( 'woocommerce_variation_set_stock', [ __CLASS__, 'flush_stock' ] );
		add_action( 'woocommerce_product_set_stock', [ __CLASS__, 'flush_stock' ] );
		add_action( 'edited_term', [ __CLASS__, 'flush_term' ], 10, 3 );
		add_action( 'delete_term', [ __CLASS__, 'flush_term' ], 10, 3 );

		// Prices live in the payload since STEP 8, so anything that moves a price has to
		// invalidate too — a stale price is a real business problem, not a cosmetic one.
		add_action( 'woocommerce_product_object_updated_props', [ __CLASS__, 'flush_updated_props' ], 10, 2 );

		// Daily cron that starts and ends scheduled sales. Entries also carry a TTL that
		// never reaches past the next sale boundary (see cache_ttl()), so a sale flips over
		// even if this hook never fires.
		add_action( 'wc_scheduled_sales', [ __CLASS__, 'flush_all' ] );

		// Currency, decimals and tax settings change every rendered price.
		add_action( 'woocommerce_settings_saved', [ __CLASS__, 'flush_all' ] );
		foreach ( [ 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_decimal_sep', 'woocommerce_price_thousand_sep', 'woocommerce_price_num_decimals', 'woocommerce_price_display_suffix', 'woocommerce_tax_display_shop', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax' ] as $option ) {
			add_action( 'update_option_' . $option, [ __CLASS__, 'flush_all' ] );
		}
	}

	/**
	 * A product was saved with changed properties.
	 *
	 * woocommerce_update_product covers most saves, but price and stock writes can go
	 * through the data store alone (bulk edits, scheduled sales, REST) and only surface
	 * here. Variations flush their parent, which is what the cache is keyed on.
	 *
	 * @param WC_Product $product       Saved product or variation.
	 * @param array      $updated_props Property names that changed.
	 */
	public static function flush_updated_props( $product, $updated_props = [] ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		self::flush_product( $parent_id ? $parent_id : $product->get_id() );
	}

	/**
	 * Collect the ID of the product currently being rendered by the loop.
	 *
	 * NOTE: the collected IDs live in $GLOBALS['yk_wcgv_product_ids'] on purpose —
	 * they are shared between the loop hook and the wp_footer injection.
	 */
	public static function collect_product_id(): void {
		global $product;
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		// Never collect a variation. A variation ID stored in upsell_ids/crosssell_ids is
		// rendered by WooCommerce through the ordinary shop loop, and WC_Product_Variation
		// is not a parent product: its get_attributes() returns plain strings instead of
		// WC_Product_Attribute objects, which used to fatal further down the line.
		if ( $product->is_type( 'variation' ) || 'product_variation' === get_post_type( $product->get_id() ) ) {
			return;
		}

		if ( ! isset( $GLOBALS['yk_wcgv_product_ids'] ) ) {
			$GLOBALS['yk_wcgv_product_ids'] = [];
		}
		$GLOBALS['yk_wcgv_product_ids'][] = $product->get_id();
	}

	/**
	 * True when a product's get_attributes() really returns WC_Product_Attribute objects.
	 *
	 * WC_Product_Variation returns `[ 'attribute_pa_farbe' => 'schwarz' ]` — strings. Any
	 * code calling ->get_variation() or ->is_taxonomy() on those dies with a fatal, and in
	 * the footer that takes every script on the page with it.
	 *
	 * @param  mixed $attribute One entry of get_attributes().
	 * @return bool
	 */
	private static function is_attribute_object( $attribute ): bool {
		return is_object( $attribute ) && method_exists( $attribute, 'get_variation' ) && method_exists( $attribute, 'is_taxonomy' );
	}

	/**
	 * Log once, and only while debugging.
	 *
	 * The guards above are hit per product per request; on a busy shop with bad upsell data
	 * that would fill the log in production for no benefit.
	 *
	 * @param string $message What was skipped.
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'YK WCGV: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Build the JSON payload passed to the JS layer for all products in the loop.
	 *
	 * @param  int[] $product_ids Parent product IDs collected during the loop.
	 * @return array
	 */
	public static function build_product_data( array $product_ids ): array {
		$product_ids  = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		$product_data = [];

		self::prime( $product_ids );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$product_data[ $product_id ] = self::get_product_entry( $product );
		}

		return [
			'ajax_url' => add_query_arg( 'wc-ajax', '%%endpoint%%', home_url( '/' ) ),
			'lang'     => self::current_language(),
			'products' => $product_data,
			'i18n'     => yk_wcgv_i18n(),
		];
	}

	// ── Per-product entry: request memo → transient → build ───────────────────

	/**
	 * The payload entry for one product, from the request memo, the transient, or freshly
	 * built (and then stored).
	 *
	 * Single entry point on purpose: the card template asks for the attributes through
	 * build_attributes() and the footer payload asks for the whole entry, and both land
	 * here — so a product is computed once per request, not twice.
	 *
	 * @param  WC_Product $product Product to describe.
	 * @return array
	 */
	public static function get_product_entry( $product ): array {
		$product_id = $product->get_id();
		$lang       = self::current_language();
		$memo_key   = $product_id . '|' . $lang;

		if ( isset( self::$entries[ $memo_key ] ) ) {
			return self::$entries[ $memo_key ];
		}

		$cached = get_transient( self::cache_key( $product_id, $lang ) );
		if ( is_array( $cached ) ) {
			self::$entries[ $memo_key ] = $cached;
			return $cached;
		}

		$entry    = self::build_product_entry( $product );
		$children = $product->is_type( 'variable' ) ? $product->get_visible_children() : [];

		set_transient( self::cache_key( $product_id, $lang ), $entry, self::cache_ttl( $product, $children ) );
		self::$entries[ $memo_key ] = $entry;

		return $entry;
	}

	/**
	 * Compute one product's payload entry. Everything expensive lives here.
	 *
	 * @param  WC_Product $product Product to describe.
	 * @return array
	 */
	private static function build_product_entry( $product ): array {
		$type = $product->get_type();

		if ( 'bundle' === $type ) {
			return [ 'type' => 'bundle' ];
		}

		if ( 'variable' === $type ) {
			return array_merge(
				[
					'type'       => 'variable',
					'attributes' => self::compute_attributes( $product ),
				],
				self::build_variations( $product )
			);
		}

		// Simple / external / downloadable products.
		$max_qty = $product->get_max_purchase_quantity();

		return [
			'type'        => $type,
			'is_in_stock' => (bool) $product->is_in_stock(),
			'max_qty'     => $max_qty > 0 ? $max_qty : 9999,
		];
	}

	/**
	 * Variations + the per-product image pool.
	 *
	 * Deliberately does NOT use get_available_variations(): that builds price HTML,
	 * availability HTML and a full image array (six sizes, alt, caption…) for every single
	 * variation, which is where the bulk of the old query and time cost came from. We read
	 * the five fields the front end actually uses, from caches primed in prime().
	 *
	 * Image entries carry the URL only. srcset/sizes were ~80% of the payload and buy
	 * nothing here: the swap happens inside a fixed-size card slot, so one src is enough.
	 *
	 * Prices use the same pooling trick as images: measured on the five baseline archives,
	 * 149 variations produced only 44 distinct price strings (70% repeats) at ~232 bytes
	 * each. SKUs are NOT pooled — all 149 were unique, so a pool plus indices would add
	 * bytes (1,908 → 2,355) instead of saving them.
	 *
	 * @param  WC_Product $product Variable product.
	 * @return array{images:array,prices:array,variations:array}
	 */
	private static function build_variations( $product ): array {
		$images       = [];
		$image_lookup = [];  // attachment ID => pool index
		$prices       = [];
		$price_lookup = [];  // price markup => pool index
		$variations   = [];

		$parent_image_id = (int) $product->get_image_id();
		$children        = $product->is_type( 'variable' ) ? $product->get_visible_children() : [];

		foreach ( $children as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$image_id = (int) ( $variation->get_image_id() ?: $parent_image_id );
			$img      = null;

			if ( $image_id > 0 ) {
				if ( ! array_key_exists( $image_id, $image_lookup ) ) {
					$url = (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

					// Empty means the attachment is gone — remember the miss so we do not
					// look it up again for every other variation using the same image.
					$image_lookup[ $image_id ] = null;

					if ( '' !== $url ) {
						$image_lookup[ $image_id ] = count( $images );
						$images[] = [ 'url' => $url ];
					}
				}

				$img = $image_lookup[ $image_id ];
			}

			$max_qty = $variation->get_max_purchase_quantity();

			$price_html  = self::variation_price_html( $variation );
			$price_index = null;

			if ( '' !== $price_html ) {
				if ( ! isset( $price_lookup[ $price_html ] ) ) {
					$price_lookup[ $price_html ] = count( $prices );
					$prices[] = $price_html;
				}
				$price_index = $price_lookup[ $price_html ];
			}

			$variations[] = [
				'variation_id' => (int) $variation_id,
				'attributes'   => $variation->get_variation_attributes(),
				'img'          => $img,
				'is_in_stock'  => (bool) $variation->is_in_stock(),
				'max_qty'      => $max_qty > 0 ? $max_qty : 9999,
				// Unique per variation in practice, so a plain string beats a pool index.
				'sku'          => (string) $variation->get_sku(),
				// Index into prices[], or null when the variation has no price.
				'price'        => $price_index,
			];
		}

		return [
			'images'     => $images,
			'prices'     => $prices,
			'variations' => $variations,
		];
	}

	/**
	 * Price markup for one variation, in the card's own format.
	 *
	 * Built from wc_get_price_to_display() rather than $variation->get_price_html(): that
	 * method also emits a screen-reader price-range sentence meant for a parent product.
	 * Tax display, currency symbol, decimals and thousand separators still come from the
	 * WooCommerce settings, because these are the functions that apply them.
	 *
	 * The price suffix IS included. The card's own .yk-price__tax span is hidden by CSS
	 * (yk-wcgv.css), so the visible "inkl. MwSt." a shopper sees on a card comes from
	 * WooCommerce's suffix inside the price markup — leave it out and the suffix vanishes
	 * the moment a variation is picked.
	 *
	 * @param  WC_Product_Variation $variation Variation.
	 * @return string Empty when the variation has no price at all.
	 */
	private static function variation_price_html( $variation ): string {
		if ( '' === $variation->get_price() ) {
			return '';
		}

		$display = wc_get_price_to_display( $variation );

		if ( $variation->is_on_sale() && '' !== $variation->get_regular_price() ) {
			$regular = wc_get_price_to_display( $variation, [ 'price' => $variation->get_regular_price() ] );

			// WooCommerce's own sale markup: <del> the old price, <ins> the new one.
			$html = wc_format_sale_price( wc_price( $regular ), wc_price( $display ) );
		} else {
			$html = wc_price( $display );
		}

		// get_price_suffix() is a product method, not a global function.
		return $html . $variation->get_price_suffix( $display );
	}

	/**
	 * How long this product's entry may be cached.
	 *
	 * Normally the full TTL, but never past the next scheduled sale boundary: a sale that
	 * starts at 00:00 must not keep showing yesterday's price until the transient happens
	 * to expire. Both the parent and every variation are considered.
	 *
	 * @param  WC_Product $product  Parent product.
	 * @param  int[]      $children Variation IDs already loaded.
	 * @return int Seconds.
	 */
	private static function cache_ttl( $product, array $children ): int {
		$ttl  = self::CACHE_TTL;
		$now  = time();
		$dates = [];

		foreach ( array_merge( [ $product ], array_map( 'wc_get_product', $children ) ) as $item ) {
			if ( ! $item ) {
				continue;
			}
			foreach ( [ $item->get_date_on_sale_from(), $item->get_date_on_sale_to() ] as $date ) {
				if ( $date ) {
					$dates[] = $date->getTimestamp();
				}
			}
		}

		foreach ( $dates as $timestamp ) {
			if ( $timestamp > $now ) {
				// +60s so the entry is rebuilt just after the boundary, not just before it.
				$ttl = min( $ttl, ( $timestamp - $now ) + 60 );
			}
		}

		return max( 60, (int) $ttl );
	}

	// ── Cache keys and invalidation ──────────────────────────────────────────

	private static function current_language(): string {
		return (string) apply_filters( 'wpml_current_language', '' );
	}

	/**
	 * Transient key. The language is part of it because labels, term names and even which
	 * terms exist differ per WPML language.
	 */
	private static function cache_key( int $product_id, string $lang ): string {
		return self::CACHE_PREFIX . self::CACHE_VERSION . '_' . $product_id . '_' . ( '' === $lang ? 'x' : $lang );
	}

	/**
	 * Drop every language variant of one product.
	 *
	 * @param int|WC_Product $product_id Product ID (or object, as some hooks pass).
	 */
	public static function flush_product( $product_id ): void {
		$product_id = is_object( $product_id ) && method_exists( $product_id, 'get_id' )
			? $product_id->get_id()
			: (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		self::delete_transients_like( self::CACHE_PREFIX . self::CACHE_VERSION . '_' . $product_id . '_%' );
	}

	/**
	 * A variation changed — the parent's entry is what we cache.
	 *
	 * @param int $variation_id Saved variation.
	 */
	public static function flush_variation( $variation_id ): void {
		$parent_id = (int) wp_get_post_parent_id( (int) $variation_id );
		if ( $parent_id ) {
			self::flush_product( $parent_id );
		}
	}

	/**
	 * Stock changed on a product or a variation.
	 *
	 * @param WC_Product $product Product whose stock was set.
	 */
	public static function flush_stock( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		self::flush_product( $parent_id ? $parent_id : $product->get_id() );
	}

	/**
	 * An attribute term (or its meta) changed. There is no cheap term → products map, and
	 * a colour term can appear on hundreds of products, so this drops the whole set. Term
	 * edits are rare admin actions; a cold cache afterwards is the right trade.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function flush_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		if ( is_string( $taxonomy ) && 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return;
		}

		self::flush_all();
	}

	/** Drop every cached entry, all products, all languages. */
	public static function flush_all(): void {
		self::$entries = [];
		self::delete_transients_like( self::CACHE_PREFIX . self::CACHE_VERSION . '_%' );
	}

	/**
	 * Delete transients whose name matches a LIKE pattern, value and timeout alike.
	 *
	 * @param string $like Pattern without the _transient_ prefix.
	 */
	private static function delete_transients_like( string $like ): void {
		global $wpdb;

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . $like,
				$wpdb->esc_like( '_transient_timeout_' ) . $like
			)
		);

		foreach ( $names as $name ) {
			$transient = preg_replace( '/^_transient_(timeout_)?/', '', $name );
			delete_transient( $transient );
		}
	}

	// ── Batch priming ────────────────────────────────────────────────────────

	/**
	 * Prime the caches for the products the main query is about to render.
	 *
	 * Runs before the first card template so the template's own build_attributes() call
	 * already finds everything in cache.
	 */
	public static function prime_main_query(): void {
		global $wp_query;

		if ( ! isset( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
			return;
		}

		$ids = [];
		foreach ( $wp_query->posts as $post ) {
			$ids[] = is_object( $post ) ? (int) $post->ID : (int) $post;
		}

		self::prime( $ids );
	}

	/**
	 * Load, in a handful of queries, everything the per-product build would otherwise
	 * fault in one row at a time.
	 *
	 * Order matters: transients first, so products that are already cached never trigger
	 * the (much heavier) variation and attachment priming at all.
	 *
	 * @param int[] $product_ids Products about to be rendered.
	 */
	public static function prime( array $product_ids ): void {
		$product_ids = array_values( array_diff( array_filter( array_map( 'intval', $product_ids ) ), self::$primed ) );
		if ( empty( $product_ids ) ) {
			return;
		}

		self::$primed = array_merge( self::$primed, $product_ids );
		$lang         = self::current_language();

		// 1. One query for every product's cached entry.
		$keys = [];
		foreach ( $product_ids as $id ) {
			$keys[] = self::cache_key( $id, $lang );
		}
		self::prime_transients( $keys );

		// 2. Which products still need building?
		$cold = [];
		foreach ( $product_ids as $id ) {
			if ( ! is_array( get_transient( self::cache_key( $id, $lang ) ) ) ) {
				$cold[] = $id;
			}
		}
		if ( empty( $cold ) ) {
			return;
		}

		// 3. Parent posts and their meta. Term relationships are primed separately below:
		//    _prime_post_caches( …, true, … ) would prime EVERY taxonomy registered for
		//    products — this shop has 40+ pa_* attributes — at one query per taxonomy.
		_prime_post_caches( $cold, false, true );

		// 3b. Object terms for the variation attributes these products actually use, in a
		//     single query, written into the same cache group get_the_terms() reads. WC's
		//     attribute ordering still applies, it just no longer hits the database.
		self::prime_attribute_terms( $cold );

		// 4. All variations of all cold products in one go.
		$children = [];
		foreach ( $cold as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_type( 'variable' ) ) {
				$children = array_merge( $children, $product->get_visible_children() );
			}
		}
		if ( ! empty( $children ) ) {
			$children = array_map( 'intval', $children );
			_prime_post_caches( $children, false, true );
		}

		// 5. Every attachment referenced by a parent or a variation, in one go. Reading
		//    _thumbnail_id is free now that the meta caches above are warm.
		$attachment_ids = [];
		foreach ( array_merge( $cold, $children ) as $id ) {
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$attachment_ids[ $thumb ] = true;
			}
		}
		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( array_keys( $attachment_ids ), false, true );
		}

		// 6. Attribute terms: load them once per product/attribute (answered by the object
		//    term cache primed in step 3) and keep them, so compute_attributes() reuses the
		//    objects instead of querying again. Their meta is primed in a single query —
		//    otherwise every yk_wcgv_term_has_swatch_meta() call is its own.
		$term_ids = [];
		foreach ( $cold as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			foreach ( $product->get_attributes() as $attr_key => $attribute ) {
				if ( ! self::is_attribute_object( $attribute ) ) {
					continue;
				}
				if ( ! $attribute->get_variation() || ! $attribute->is_taxonomy() ) {
					continue;
				}
				foreach ( self::get_product_terms( $id, $attr_key ) as $term ) {
					$term_ids[ (int) $term->term_id ] = true;
				}
			}
		}
		if ( ! empty( $term_ids ) ) {
			update_termmeta_cache( array_keys( $term_ids ) );
		}
	}

	/**
	 * Fill the object-term cache for the variation attribute taxonomies of a set of
	 * products, using one query for all of them.
	 *
	 * WordPress primes term relationships per taxonomy, so letting it do the work costs one
	 * query for each of the shop's 40+ attribute taxonomies even though a given product
	 * uses two. This writes the same `{$taxonomy}_relationships` cache entries that
	 * get_the_terms() reads, scoped to what is actually needed.
	 *
	 * @param int[] $product_ids Products about to be built.
	 */
	private static function prime_attribute_terms( array $product_ids ): void {
		// The taxonomies WooCommerce and this plugin actually read, nothing else.
		// WC_Product_Factory::get_product() runs _prime_post_caches() with term caching on,
		// and WordPress primes one taxonomy per query — priming these up front from a single
		// query leaves it nothing to do. Measured on dev (7 products): no priming 52
		// queries, priming all 64 product taxonomies 41, priming just these 23.
		$taxonomies = [ 'product_type', 'product_visibility', 'product_cat', 'product_tag', 'product_shipping_class' ];

		// Attribute taxonomies straight from the meta primed a moment ago, so this does not
		// need product objects (which is what we are trying to make cheap).
		foreach ( $product_ids as $id ) {
			$attributes = get_post_meta( $id, '_product_attributes', true );
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $attr_key => $attribute ) {
				if ( ! empty( $attribute['is_taxonomy'] ) ) {
					$taxonomies[] = $attr_key;
				}
			}
		}

		$taxonomies = array_values( array_unique( array_filter( $taxonomies, 'taxonomy_exists' ) ) );
		if ( empty( $taxonomies ) ) {
			return;
		}

		$terms = wp_get_object_terms( $product_ids, $taxonomies, [ 'fields' => 'all_with_object_id' ] );

		if ( is_wp_error( $terms ) ) {
			return;
		}

		$map = [];
		foreach ( $terms as $term ) {
			$map[ (int) $term->object_id ][ $term->taxonomy ][] = (int) $term->term_id;
		}

		foreach ( $product_ids as $id ) {
			foreach ( $taxonomies as $taxonomy ) {
				// Empty arrays matter too: they stop get_the_terms() from querying for a
				// product that simply has no term in that taxonomy.
				wp_cache_add( $id, $map[ $id ][ $taxonomy ] ?? [], $taxonomy . '_relationships' );
			}
		}
	}

	/**
	 * Attribute terms of one product, memoised per request.
	 *
	 * wc_get_product_terms() applies the attribute's configured ordering, which reads term
	 * meta and can query; without this memo it runs twice per attribute (priming, then
	 * building).
	 *
	 * @param  int    $product_id Product.
	 * @param  string $attr_key   Attribute taxonomy, e.g. 'pa_farbe'.
	 * @return WP_Term[]
	 */
	private static function get_product_terms( int $product_id, string $attr_key ): array {
		static $memo = [];

		$key = $product_id . '|' . $attr_key;
		if ( ! isset( $memo[ $key ] ) ) {
			$terms        = wc_get_product_terms( $product_id, $attr_key, [ 'fields' => 'all' ] );
			$memo[ $key ] = is_array( $terms ) ? $terms : [];
		}

		return $memo[ $key ];
	}

	/**
	 * Warm the options cache for a batch of transient keys with one query.
	 *
	 * get_transient() reads two options per key and, since transients are not autoloaded,
	 * each miss is its own query — 16 products would cost up to 64. Misses are recorded in
	 * the `notoptions` cache so they stay free too.
	 *
	 * @param string[] $keys Transient names.
	 */
	private static function prime_transients( array $keys ): void {
		global $wpdb;

		if ( wp_using_ext_object_cache() || empty( $keys ) ) {
			return;  // A persistent object cache already answers these without SQL.
		}

		$names = [];
		foreach ( $keys as $key ) {
			$names[] = '_transient_' . $key;
			$names[] = '_transient_timeout_' . $key;
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )", $names ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$found = [];
		foreach ( $rows as $row ) {
			wp_cache_set( $row->option_name, $row->option_value, 'options' );
			$found[ $row->option_name ] = true;
		}

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( ! is_array( $notoptions ) ) {
			$notoptions = [];
		}
		foreach ( $names as $name ) {
			if ( ! isset( $found[ $name ] ) ) {
				$notoptions[ $name ] = true;
			}
		}
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}

	/**
	 * Build the list of selectable attributes for one variable product.
	 *
	 * Every attribute flagged "Used for variations" in the back office is included, in
	 * back-office order — there is no colour/size hardcoding any more, so an antenna's
	 * "Frequenz" attribute shows up like any other. Attributes that are not used for
	 * variations are skipped: they are descriptive only and have no variation to select.
	 *
	 * Shape:
	 *   [
	 *     'name'    => 'attribute_pa_farbe',  // matches the keys in variations[].attributes
	 *     'label'   => 'Farbe',
	 *     'style'   => 'swatch' | 'pill',
	 *     'options' => [
	 *       [
	 *         'value'  => 'schwarz',          // EXACT value stored on the variation
	 *         'label'  => 'Schwarz',
	 *         'swatch' => [ 'type', 'color', 'color2', 'image', 'is_light' ],
	 *       ],
	 *     ],
	 *   ]
	 *
	 * The 'swatch' key exists ONLY on options of a `style => 'swatch'` attribute. Pills do
	 * not render swatch data, so carrying it would be pure payload weight — the renderer
	 * must treat 'swatch' as optional.
	 *
	 * NOTE for the renderer (STEP 4): `swatch['color']` is populated even when
	 * `swatch['type'] === 'image'`. In that case it is a contrast/border hint derived from
	 * the term meta or the colour-name map — it is NOT the swatch fill. When the type is
	 * 'image', paint the image; when it is 'none', fall back to yk_wcgv_resolve_color().
	 *
	 * Public because woocommerce/content-product.php renders the very same array — the
	 * markup and the JS payload must never drift apart.
	 *
	 * Goes through the cached entry, so the card template and the footer payload share one
	 * computation per product per request (and hit the transient on later page views).
	 *
	 * @param  WC_Product $product Variable product.
	 * @return array
	 */
	public static function build_attributes( $product ): array {
		$entry = self::get_product_entry( $product );

		return isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : [];
	}

	/**
	 * The actual attribute computation. Called once per product, from build_product_entry().
	 *
	 * @param  WC_Product $product Variable product.
	 * @return array
	 */
	private static function compute_attributes( $product ): array {
		$attributes = [];

		foreach ( $product->get_attributes() as $attr_key => $attribute ) {
			// A variation (or anything else whose get_attributes() yields strings) has no
			// attribute objects to read — skip the product rather than fatal on it.
			if ( ! self::is_attribute_object( $attribute ) ) {
				self::debug_log( sprintf( 'product %d: attribute "%s" is not a WC_Product_Attribute (%s) — skipping attributes for this product.', $product->get_id(), (string) $attr_key, gettype( $attribute ) ) );
				return [];
			}

			// Only attributes the shop owner ticked "Used for variations".
			if ( ! $attribute->get_variation() ) {
				continue;
			}

			$terms         = [];
			$raw_options   = [];
			$has_term_meta = false;

			if ( $attribute->is_taxonomy() ) {
				$terms = self::get_product_terms( $product->get_id(), $attr_key );

				foreach ( $terms as $term ) {
					// Raw meta check — see yk_wcgv_term_has_swatch_meta() for why the
					// swatch helper must not decide this.
					if ( yk_wcgv_term_has_swatch_meta( $term->term_id ) ) {
						$has_term_meta = true;
						break;
					}
				}
			} else {
				$raw_options = $attribute->get_options();
			}

			if ( empty( $terms ) && empty( $raw_options ) ) {
				continue;
			}

			// Style is decided before the options are built: pills skip the swatch lookup
			// entirely, which saves both payload bytes and a term query per option.
			$style        = self::determine_attribute_style( $attr_key, $product, $has_term_meta );
			$with_swatch  = ( 'swatch' === $style );
			$options      = [];

			foreach ( $terms as $term ) {
				// Taxonomy variations store the term slug.
				$option = [
					'value' => $term->slug,
					'label' => $term->name,
				];
				if ( $with_swatch ) {
					// Hand the loaded term over — otherwise the helper looks it up by slug,
					// one query per term.
					$option['swatch'] = yk_wcgv_get_swatch( $attr_key, $term->slug, YK_WCGV_SWATCH_IMAGE_SIZE, $term );
				}
				$options[] = $option;
			}

			// Custom (non-taxonomy) attributes. As of the 2026-08 site scan, 0 of 208
			// products use a custom attribute for variations — this branch is defensive,
			// kept for the day one is added. WooCommerce stores the RAW option string on
			// the variation, so sanitize_title() here would break matching against
			// variations[].attributes and add-to-cart would silently fail.
			foreach ( $raw_options as $raw_option ) {
				$raw_option = (string) $raw_option;

				$option = [
					'value' => $raw_option,
					'label' => $raw_option,
				];
				if ( $with_swatch ) {
					$option['swatch'] = yk_wcgv_get_swatch( $attr_key, $raw_option );
				}
				$options[] = $option;
			}

			$attributes[] = [
				'name'    => 'attribute_' . $attr_key,
				'label'   => wc_attribute_label( $attr_key, $product ),
				'style'   => $style,
				'options' => $options,
			];
		}

		return $attributes;
	}

	/**
	 * Decide whether an attribute renders as colour swatches or as pills.
	 *
	 * Order is fixed:
	 *   1. any term of this attribute has explicit swatch term meta  → 'swatch'
	 *   2. the attribute slug/label matches YK_WCGV_COLOR_KEYS       → 'swatch'
	 *   3. everything else                                           → 'pill'
	 *
	 * Custom (non-taxonomy) attributes have no terms, so they start at rule 2.
	 *
	 * @param  string     $attr_key      Attribute key, e.g. 'pa_farbe'.
	 * @param  WC_Product $product       Product being rendered.
	 * @param  bool       $has_term_meta Result of the raw term meta scan.
	 * @return string 'swatch'|'pill'
	 */
	private static function determine_attribute_style( string $attr_key, $product, bool $has_term_meta ): string {
		if ( $has_term_meta ) {
			return 'swatch';
		}

		$slug  = strtolower( str_replace( 'pa_', '', $attr_key ) );
		$label = strtolower( wc_attribute_label( $attr_key, $product ) );

		if ( yk_wcgv_attr_matches( $slug, $label, YK_WCGV_COLOR_KEYS ) ) {
			return 'swatch';
		}

		return 'pill';
	}

	/**
	 * Attribute map for the single product page, keyed for direct lookup by the JS.
	 *
	 * Same data as build_attributes() — same style decision, same swatch objects — only
	 * reshaped: attributes keyed by `attribute_*` name (what the variation <select> carries
	 * in data-attribute_name) and options keyed by their value. Both screens therefore
	 * agree on what is a swatch and what is a pill.
	 *
	 * As on archives, `swatch` is present only on options of a style => 'swatch' attribute.
	 *
	 *   [ 'attribute_pa_farbe' => [
	 *       'label'   => 'Farbe',
	 *       'style'   => 'swatch',
	 *       'options' => [ 'schwarz' => [ 'label' => 'Schwarz', 'swatch' => [...] ] ],
	 *   ] ]
	 *
	 * @param  WC_Product $product Product being displayed.
	 * @return array
	 */
	public static function build_product_page_attributes( $product ): array {
		$attributes = [];

		foreach ( self::build_attributes( $product ) as $attribute ) {
			$options = [];

			foreach ( $attribute['options'] as $option ) {
				$entry = [ 'label' => $option['label'] ];

				if ( isset( $option['swatch'] ) ) {
					$entry['swatch'] = $option['swatch'];
				}

				$options[ (string) $option['value'] ] = $entry;
			}

			$attributes[ $attribute['name'] ] = [
				'label'   => $attribute['label'],
				'style'   => $attribute['style'],
				'options' => $options,
			];
		}

		return $attributes;
	}

	/**
	 * Colour map (slug => hex) for a single variable product.
	 *
	 * DEPRECATED as the source for rendering — kept for backwards compatibility (older
	 * customisations may read window.ykWcgvProduct.colors). New code reads `attributes`,
	 * which also covers two-tone and image swatches that a flat slug => hex map cannot
	 * express.
	 *
	 * @param  WC_Product $product Product being displayed.
	 * @return array<string,string>
	 */
	public static function build_product_page_colors( $product ): array {
		$colors = [];

		if ( 'variable' === $product->get_type() ) {
			foreach ( $product->get_attributes() as $attr_key => $attribute ) {
				if ( ! self::is_attribute_object( $attribute ) ) {
					continue;
				}

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

		return $colors;
	}
}

/**
 * Backwards-compatible wrapper — kept because templates and third-party code
 * call this global function.
 *
 * @param  int[] $product_ids Parent product IDs collected during the loop.
 * @return array
 */
function yk_wcgv_build_product_data( array $product_ids ): array {
	return YK_WCGV_Data::build_product_data( $product_ids );
}
