<?php
/**
 * Variation data collection and JS payload building.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Data {

	public static function init(): void {
		// Accumulate product IDs as the WooCommerce loop renders each card.
		add_action( 'woocommerce_before_shop_loop_item', [ __CLASS__, 'collect_product_id' ] );
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
		if ( ! isset( $GLOBALS['yk_wcgv_product_ids'] ) ) {
			$GLOBALS['yk_wcgv_product_ids'] = [];
		}
		$GLOBALS['yk_wcgv_product_ids'][] = $product->get_id();
	}

	/**
	 * Build the JSON payload passed to the JS layer for all products in the loop.
	 *
	 * @param  int[] $product_ids Parent product IDs collected during the loop.
	 * @return array
	 */
	public static function build_product_data( array $product_ids ): array {
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
				// Image pool per product: most variations of a product share one image
				// (often the parent's), and the URL + srcset strings are by far the
				// heaviest part of the payload. Variations reference a pool index instead
				// of repeating ~560 bytes of image data each.
				$images       = [];
				$image_lookup = [];  // attachment ID => pool index
				$variations   = [];

				foreach ( $product->get_available_variations() as $var_data ) {
					$var_id    = (int) $var_data['variation_id'];
					$variation = wc_get_product( $var_id );
					if ( ! $variation ) {
						continue;
					}

					$image_id = (int) ( $variation->get_image_id() ?: $product->get_image_id() );
					$img      = null;

					if ( $image_id > 0 ) {
						if ( ! isset( $image_lookup[ $image_id ] ) ) {
							$url = (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

							if ( '' !== $url ) {
								$image_lookup[ $image_id ] = count( $images );
								$images[] = [
									'url'    => $url,
									'srcset' => (string) wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' ),
									'sizes'  => (string) wp_get_attachment_image_sizes( $image_id, 'woocommerce_thumbnail' ),
								];
							}
						}

						// Still unset means the attachment is gone — treat as "no image".
						$img = $image_lookup[ $image_id ] ?? null;
					}

					$max_qty = $variation->get_max_purchase_quantity();

					$variations[] = [
						'variation_id' => $var_id,
						'attributes'   => $var_data['attributes'],
						'img'          => $img,
						'is_in_stock'  => (bool) $var_data['is_in_stock'],
						'max_qty'      => $max_qty > 0 ? $max_qty : 9999,
					];
				}

				$product_data[ $product_id ] = [
					'type'       => 'variable',
					'attributes' => self::build_attributes( $product ),
					'images'     => $images,
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
	 * @param  WC_Product $product Variable product.
	 * @return array
	 */
	private static function build_attributes( $product ): array {
		$attributes = [];

		foreach ( $product->get_attributes() as $attr_key => $attribute ) {
			// Only attributes the shop owner ticked "Used for variations".
			if ( ! $attribute->get_variation() ) {
				continue;
			}

			$terms         = [];
			$raw_options   = [];
			$has_term_meta = false;

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attr_key, [ 'fields' => 'all' ] );

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
					$option['swatch'] = yk_wcgv_get_swatch( $attr_key, $term->slug );
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
	 * Colour map (slug => hex) for a single variable product, used by the
	 * single-product-page swatches.
	 *
	 * @param  WC_Product $product Product being displayed.
	 * @return array<string,string>
	 */
	public static function build_product_page_colors( $product ): array {
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
