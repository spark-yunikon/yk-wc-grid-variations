<?php
/**
 * Product card template for archive/category loops.
 *
 * Served via the wc_get_template_part filter in yk-wc-grid-variations.php.
 * Replaces woocommerce/templates/content-product.php on shop/archive/category pages.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! is_a( $product, 'WC_Product' ) || ! $product->is_visible() ) {
	return;
}

$product_id = $product->get_id();
$permalink  = $product->get_permalink();
$settings   = function_exists( 'yk_wcgv_get_settings' ) ? yk_wcgv_get_settings() : [];

// Product IDs are collected by yk_wcgv_collect_product_id() via woocommerce_before_shop_loop_item (line below).
$sku        = $product->get_sku();
$is_bundle  = ( 'bundle' === $product->get_type() );
$is_on_sale = $product->is_on_sale();

// Detect color / size attributes by slug or label.
$color_values = [];
$size_values  = [];

$show_vars    = ( $settings['show_variations'] ?? '1' ) === '1';
$visible_attrs = $settings['visible_attrs'] ?? [];

foreach ( $product->get_attributes() as $attr_key => $attribute ) {
	$slug  = strtolower( str_replace( 'pa_', '', $attr_key ) );
	$label = strtolower( wc_attribute_label( $attr_key, $product ) );

	if ( ! empty( $visible_attrs ) && ! in_array( $attr_key, $visible_attrs, true ) ) {
		continue;
	}

	$is_color = function_exists( 'yk_wcgv_attr_matches' ) && yk_wcgv_attr_matches( $slug, $label, YK_WCGV_COLOR_KEYS );
	$is_size  = function_exists( 'yk_wcgv_attr_matches' ) && yk_wcgv_attr_matches( $slug, $label, YK_WCGV_SIZE_KEYS );

	if ( ! $is_color && ! $is_size ) {
		continue;
	}

	if ( $attribute->is_taxonomy() ) {
		$terms = wc_get_product_terms( $product_id, $attr_key, [ 'fields' => 'all' ] );
		foreach ( $terms as $term ) {
			$entry = [ 'label' => $term->name, 'value' => $term->slug ];
			if ( $is_color ) {
				$color_values[] = $entry;
			}
			if ( $is_size ) {
				$size_values[] = $entry;
			}
		}
	} else {
		foreach ( $attribute->get_options() as $option ) {
			$entry = [ 'label' => $option, 'value' => sanitize_title( $option ) ];
			if ( $is_color ) {
				$color_values[] = $entry;
			}
			if ( $is_size ) {
				$size_values[] = $entry;
			}
		}
	}
}
?>
<?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
<li <?php wc_product_class( 'yk-card', $product ); ?>>

	<?php // 1. Featured image (square, object-fit: contain) + 2. SALE badge ?>
	<a class="yk-card__img-link" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
		<div class="yk-card__img-wrap">
			<?php if ( $is_on_sale && ( $settings['show_sale_badge'] ?? '1' ) === '1' ) : ?>
				<span class="yk-badge-sale"><?php esc_html_e( 'SALE', 'yk-wc-grid-variations' ); ?></span>
			<?php endif; ?>
			<?php echo $product->get_image( 'woocommerce_thumbnail', [ 'class' => 'yk-card__img', 'loading' => 'lazy' ] ); ?>
		</div>
	</a>

	<div class="yk-card__body">

		<?php // 2. Color swatches ?>
		<?php if ( $show_vars && ! empty( $color_values ) ) : ?>
			<div class="yk-swatches" aria-label="<?php esc_attr_e( 'Available colours', 'yk-wc-grid-variations' ); ?>">
				<?php foreach ( $color_values as $idx => $swatch ) :
					$css_color = function_exists( 'yk_wcgv_resolve_color' )
						? yk_wcgv_resolve_color( $swatch['value'], $swatch['label'] )
						: '#cccccc';
				?>
					<span
						class="yk-swatch<?php echo 0 === $idx ? ' is-active' : ''; ?>"
						data-value="<?php echo esc_attr( $swatch['value'] ); ?>"
						data-label="<?php echo esc_attr( $swatch['label'] ); ?>"
						style="background-color:<?php echo esc_attr( $css_color ); ?>;"
						title="<?php echo esc_attr( $swatch['label'] ); ?>"
						aria-label="<?php echo esc_attr( $swatch['label'] ); ?>"
					></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php // 3. Size pills ?>
		<?php if ( $show_vars && ! empty( $size_values ) ) : ?>
			<div class="yk-sizes" aria-label="<?php esc_attr_e( 'Available sizes', 'yk-wc-grid-variations' ); ?>">
				<?php foreach ( $size_values as $idx => $size ) : ?>
					<button
						type="button"
						class="yk-size<?php echo 0 === $idx ? ' is-active' : ''; ?>"
						data-value="<?php echo esc_attr( $size['value'] ); ?>"
					>
						<?php echo esc_html( $size['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php // 4. Product title — 2-line clamp ?>
		<a class="yk-card__title-link" href="<?php echo esc_url( $permalink ); ?>">
			<h2 class="woocommerce-loop-product__title yk-card__title">
				<?php echo esc_html( $product->get_name() ); ?>
			</h2>
		</a>
<?php // 5. Price ?>
		<div class="yk-price">
			<?php echo wp_kses_post( $product->get_price_html() ); ?>
			<span class="yk-price__tax"><?php esc_html_e( 'inkl. MwSt.', 'yk-wc-grid-variations' ); ?></span>
		</div>

		<?php // 6. SKU / Art.-Nr. ?>
		<?php if ( $sku && ( $settings['show_sku'] ?? '1' ) === '1' ) : ?>
			<p class="yk-card__sku">
				<?php esc_html_e( 'Art.-Nr.', 'yk-wc-grid-variations' ); ?>&nbsp;<strong><?php echo esc_html( $sku ); ?></strong>
			</p>
		<?php endif; ?>

		<?php // 7 + 8. Quantity stepper + Add to Cart (side by side) / Zum Produkt link (bundles) ?>
		<?php if ( $is_bundle ) : ?>
			<a
				href="<?php echo esc_url( $permalink ); ?>"
				class="yk-btn yk-btn--outline"
			>
				<?php esc_html_e( 'Zum Produkt', 'yk-wc-grid-variations' ); ?>
			</a>
		<?php else : ?>
			<?php $show_qty = ( $settings['show_qty_stepper'] ?? '1' ) === '1'; ?>
			<div class="yk-cart-row<?php echo $show_qty ? '' : ' yk-cart-row--no-stepper'; ?>">
				<?php if ( $show_qty ) : ?>
				<div class="yk-qty-wrap">
					<button type="button" class="yk-qty-minus" aria-label="<?php esc_attr_e( 'Decrease quantity', 'yk-wc-grid-variations' ); ?>">
						<svg class="yk-qty-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
							<path d="M12 20 L22 4 L2 4 Z" fill="currentColor"/>
						</svg>
					</button>
					<input
						type="number"
						class="yk-qty-input"
						value="1"
						min="1"
						step="1"
						readonly
						aria-label="<?php esc_attr_e( 'Quantity', 'yk-wc-grid-variations' ); ?>"
					/>
					<button type="button" class="yk-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'yk-wc-grid-variations' ); ?>">
						<svg class="yk-qty-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
							<path d="M12 4 L22 20 L2 20 Z" fill="currentColor"/>
						</svg>
					</button>
				</div>
				<?php endif; ?>
				<button
					type="button"
					class="yk-add-to-cart yk-btn yk-btn--primary"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'Add %s to cart', 'yk-wc-grid-variations' ), $product->get_name() ) ); ?>"
				>
					<?php esc_html_e( 'In den Warenkorb', 'yk-wc-grid-variations' ); ?>
				</button>
			</div>
		<?php endif; ?>

	</div><?php // .yk-card__body ?>

	<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</li>
