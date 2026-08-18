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

// Product IDs are collected by YK_WCGV_Data::collect_product_id() via woocommerce_before_shop_loop_item (line below).
$sku        = $product->get_sku();
$is_bundle  = ( 'bundle' === $product->get_type() );
$is_on_sale = $product->is_on_sale();

$show_vars = ( $settings['show_variations'] ?? '1' ) === '1';

// Every attribute marked "Used for variations", in back-office order — built by the very
// same method that produces the JS payload, so markup and payload cannot drift apart.
$yk_attributes = [];
if ( $show_vars && 'variable' === $product->get_type() && class_exists( 'YK_WCGV_Data' ) ) {
	$yk_attributes = YK_WCGV_Data::build_attributes( $product );
}

/**
 * Inline background for one swatch option.
 *
 * `swatch` is optional: it exists only on options of a style="swatch" attribute, and its
 * `color` field is a contrast hint when the type is 'image'. Falls back to the colour-name
 * map, then to the neutral default.
 *
 * Every branch uses background longhands (never the `background:` shorthand) — the same
 * rule as yk-wcgv-product.js and the admin previews. The shorthand resets background-color
 * to transparent, so a future rule painting a colour underneath a gradient or image would
 * behave differently here than on the single product page.
 */
$yk_swatch_background = static function ( array $option ): string {
	$swatch = $option['swatch'] ?? null;

	if ( is_array( $swatch ) ) {
		if ( 'image' === $swatch['type'] && ! empty( $swatch['image'] ) ) {
			return 'background-image:url(' . esc_url( $swatch['image'] ) . ');background-size:cover;background-position:center;';
		}
		if ( 'gradient' === $swatch['type'] && ! empty( $swatch['color2'] ) ) {
			return 'background-image:linear-gradient(135deg,' . esc_attr( $swatch['color'] ) . ' 0 50%,' . esc_attr( $swatch['color2'] ) . ' 50% 100%);';
		}
		if ( ! empty( $swatch['color'] ) ) {
			return 'background-color:' . esc_attr( $swatch['color'] ) . ';';
		}
	}

	$fallback = function_exists( 'yk_wcgv_resolve_color' )
		? yk_wcgv_resolve_color( (string) $option['value'], (string) $option['label'] )
		: '#cccccc';

	return 'background-color:' . esc_attr( $fallback ) . ';';
};

/**
 * Extra classes for one swatch option.
 *
 * `is-light` comes from the payload's WCAG relative luminance, so light fills get a visible
 * edge instead of dissolving into the white card. Image swatches get `has-image` and the
 * same edge unconditionally: their `is_light` describes the *hint* colour, not the picture,
 * and an uploaded image can be light at the rim whatever the hint says.
 */
$yk_swatch_classes = static function ( array $option ): string {
	$swatch = $option['swatch'] ?? null;

	if ( ! is_array( $swatch ) ) {
		return '';
	}
	if ( 'image' === $swatch['type'] && ! empty( $swatch['image'] ) ) {
		return ' has-image';
	}
	return ! empty( $swatch['is_light'] ) ? ' is-light' : '';
};
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

		<?php
		// 2 + 3. One group per variation attribute, in back-office order. Swatch groups keep
		// the .yk-swatches/.yk-swatch hooks, pill groups keep .yk-sizes/.yk-size, so existing
		// theme CSS keeps working no matter which attribute now renders in them.
		foreach ( $yk_attributes as $yk_attr ) :
			$yk_options = $yk_attr['options'];
			if ( empty( $yk_options ) ) {
				continue;
			}

			// A style of 'swatch' always ships swatch data; the isset() keeps a malformed
			// payload from rendering blank chips instead of readable pills.
			$yk_is_swatch = ( 'swatch' === $yk_attr['style'] ) && isset( $yk_options[0]['swatch'] );
			?>
			<div
				class="<?php echo $yk_is_swatch ? 'yk-swatches' : 'yk-sizes'; ?>"
				role="group"
				data-attribute="<?php echo esc_attr( $yk_attr['name'] ); ?>"
				aria-label="<?php echo esc_attr( $yk_attr['label'] ); ?>"
			>
				<?php foreach ( $yk_options as $yk_idx => $yk_option ) : ?>
					<?php if ( $yk_is_swatch ) : ?>
						<span
							class="yk-swatch<?php echo 0 === $yk_idx ? ' is-active' : ''; ?><?php echo esc_attr( $yk_swatch_classes( $yk_option ) ); ?>"
							data-value="<?php echo esc_attr( $yk_option['value'] ); ?>"
							data-label="<?php echo esc_attr( $yk_option['label'] ); ?>"
							style="<?php echo $yk_swatch_background( $yk_option ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped per value inside the closure. ?>"
							title="<?php echo esc_attr( $yk_option['label'] ); ?>"
							aria-label="<?php echo esc_attr( $yk_option['label'] ); ?>"
						></span>
					<?php else : ?>
						<button
							type="button"
							class="yk-size<?php echo 0 === $yk_idx ? ' is-active' : ''; ?>"
							data-value="<?php echo esc_attr( $yk_option['value'] ); ?>"
						>
							<?php echo esc_html( $yk_option['label'] ); ?>
						</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<?php // 4. Product title — 2-line clamp ?>
		<a class="yk-card__title-link" href="<?php echo esc_url( $permalink ); ?>">
			<h2 class="woocommerce-loop-product__title yk-card__title">
				<?php echo esc_html( $product->get_name() ); ?>
			</h2>
		</a>
<?php // 5. Price ?>
		<div class="yk-price">
			<?php
			// The value gets its own span so the JS can swap in a variation's price without
			// touching the tax suffix next to it. WooCommerce's own price_html already ends
			// with that suffix for screen readers; the visible one is the span below.
			?>
			<span class="yk-price__value"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<span class="yk-price__tax"><?php esc_html_e( 'incl. VAT', 'yk-wc-grid-variations' ); ?></span>
		</div>

		<?php // 6. SKU / Art.-Nr. ?>
		<?php if ( $sku && ( $settings['show_sku'] ?? '1' ) === '1' ) : ?>
			<p class="yk-card__sku">
				<?php esc_html_e( 'Item no.', 'yk-wc-grid-variations' ); ?>&nbsp;<strong><?php echo esc_html( $sku ); ?></strong>
			</p>
		<?php endif; ?>

		<?php // 7 + 8. Quantity stepper + Add to Cart (side by side) / Zum Produkt link (bundles) ?>
		<?php if ( $is_bundle ) : ?>
			<a
				href="<?php echo esc_url( $permalink ); ?>"
				class="yk-btn yk-btn--outline"
			>
				<?php esc_html_e( 'View product', 'yk-wc-grid-variations' ); ?>
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
					<?php esc_html_e( 'Add to cart', 'yk-wc-grid-variations' ); ?>
				</button>
			</div>
		<?php endif; ?>

	</div><?php // .yk-card__body ?>

	<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</li>
