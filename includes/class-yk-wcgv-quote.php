<?php
/**
 * Price on request: a "Request a quote" e-mail link in place of add-to-cart for products
 * (and variations) that have no price.
 *
 * WooCommerce treats an empty price as "not purchasable" and simply renders nothing — no
 * price, no button — so a shopper landing on such a product has no way forward. This class
 * fills that gap without touching the cart: the link is a plain mailto: with the subject and
 * body prefilled (product name, item number, quantity, URL).
 *
 * Where it shows up:
 *   - Archive card (content-product.php) — the template asks needs_quote() and renders the
 *     link; yk-wcgv.js swaps button ↔ link when a priceless variation resolves.
 *   - Single product page, simple product — render_single() after WooCommerce's (empty)
 *     add-to-cart slot.
 *   - Single product page, variable product — a hidden link inside the variation form,
 *     revealed by yk-wcgv-quote.js on show_variation when the variation needs a quote.
 *
 * @package YK_WC_Grid_Variations
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Quote {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_empty_price_html', [ __CLASS__, 'empty_price_html' ], 10, 2 );
		add_filter( 'woocommerce_variable_empty_price_html', [ __CLASS__, 'empty_price_html' ], 10, 2 );
		add_filter( 'woocommerce_variation_is_visible', [ __CLASS__, 'variation_is_visible' ], 10, 4 );
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'available_variation' ], 10, 3 );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_single' ], 31 );
		add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'render_variable_single' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * Whether the feature is switched on in the plugin settings.
	 */
	public static function enabled(): bool {
		$settings = yk_wcgv_get_settings();

		return '1' === ( $settings['quote_enabled'] ?? '1' );
	}

	/**
	 * True when a product (or a single variation) should offer a quote instead of the cart.
	 *
	 * A variable parent returns its lowest variation price, so it only counts as priceless
	 * when every visible variation is. Partly priceless products are resolved per variation
	 * on the client.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function needs_quote( $product ): bool {
		if ( ! self::enabled() || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		// Bundles, grouped and external products have their own buttons and pricing rules.
		$needs = in_array( $product->get_type(), [ 'simple', 'variable', 'variation' ], true )
			&& '' === (string) $product->get_price();

		/**
		 * Filters whether a product shows "Request a quote" instead of add-to-cart.
		 *
		 * @param bool       $needs   Default: the product has no price.
		 * @param WC_Product $product Product or variation.
		 */
		return (bool) apply_filters( 'yk_wcgv_needs_quote', $needs, $product );
	}

	/**
	 * Recipient of quote requests: the setting, else the WooCommerce sender address, else the
	 * site admin address.
	 */
	public static function email(): string {
		$settings = yk_wcgv_get_settings();
		$email    = sanitize_email( (string) ( $settings['quote_email'] ?? '' ) );

		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
		}
		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}

		/**
		 * Filters the address quote requests are sent to.
		 *
		 * @param string $email Recipient address.
		 */
		return sanitize_email( (string) apply_filters( 'yk_wcgv_quote_email', $email ) );
	}

	/**
	 * Subject and body templates. The single source for PHP and for yk-wcgv-quote.js, so
	 * the no-JS link and the JS-built link always read the same.
	 *
	 * Placeholders: {name}, {attributes}, {sku}, {qty}, {url}. A body line whose
	 * placeholder comes out empty is dropped: {sku} when there is no item number,
	 * {attributes} when nothing was chosen. {attributes} expands to one "Label: value" line
	 * per variation attribute — only the JS knows the selection, so the server-rendered
	 * fallback link never carries it.
	 */
	public static function templates(): array {
		return [
			/* translators: {name} is replaced with the product name. Keep the placeholder as is. */
			'subject' => __( 'Quote request: {name}', 'yk-wc-grid-variations' ),
			/* translators: E-mail body. Keep {name}, {attributes}, {sku}, {qty} and {url} as they are, and keep each one on its own line. {attributes} becomes lines like "Colour: black". */
			'body'    => __( "Hello,\n\nPlease send me a quote for the following product:\n\nProduct: {name}\n{attributes}\nItem no.: {sku}\nQuantity: {qty}\nLink: {url}\n\nKind regards", 'yk-wc-grid-variations' ),
		];
	}

	/**
	 * Prefilled mailto: link for one product.
	 *
	 * Print it with esc_attr(), not esc_url(): esc_url() strips %0D/%0A (header-injection
	 * guard), which flattens the body onto one line. Every part here is built by us — the
	 * address goes through sanitize_email() and the rest through rawurlencode().
	 *
	 * @param WC_Product $product Product or variation.
	 * @param int        $qty     Quantity to ask for.
	 */
	public static function mailto( $product, int $qty = 1 ): string {
		$email = self::email();

		if ( '' === $email || ! is_a( $product, 'WC_Product' ) ) {
			return '';
		}

		$tokens = [
			'{name}'       => $product->get_name(),
			'{attributes}' => '',
			'{sku}'        => (string) $product->get_sku(),
			'{qty}'        => (string) max( 1, $qty ),
			'{url}'        => $product->get_permalink(),
		];

		$templates = self::templates();

		// Drop every line whose placeholder is empty ({attributes} always is here).
		$body = implode( "\n", array_filter(
			explode( "\n", $templates['body'] ),
			static function ( $line ) use ( $tokens ) {
				foreach ( [ '{attributes}', '{sku}' ] as $key ) {
					if ( '' === $tokens[ $key ] && false !== strpos( $line, $key ) ) {
						return false;
					}
				}
				return true;
			}
		) );

		$subject = strtr( $templates['subject'], $tokens );
		$body    = str_replace( "\n", "\r\n", strtr( $body, $tokens ) );

		return 'mailto:' . $email . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
	}

	/**
	 * Data attributes the JS reads to rebuild the link with the current quantity and the
	 * resolved variation's item number.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function link_attributes( $product ): string {
		return sprintf(
			'href="%1$s" data-product-id="%2$s" data-name="%3$s" data-sku="%4$s" data-url="%5$s"',
			esc_attr( self::mailto( $product ) ),
			esc_attr( (string) $product->get_id() ),
			esc_attr( $product->get_name() ),
			esc_attr( (string) $product->get_sku() ),
			esc_url( $product->get_permalink() )
		);
	}

	/**
	 * "Price on request" wherever WooCommerce would print an empty price.
	 *
	 * @param string     $html    Empty price markup.
	 * @param WC_Product $product Product.
	 */
	public static function empty_price_html( $html, $product ): string {
		if ( ! self::needs_quote( $product ) ) {
			return (string) $html;
		}

		return '<span class="yk-price-on-request">' . esc_html__( 'Price on request', 'yk-wc-grid-variations' ) . '</span>';
	}

	/**
	 * Keep published, priceless variations in the single product variation form.
	 *
	 * WooCommerce hides a variation without a price, so choosing that combination ends in
	 * "no products matched" and the shopper never learns it can be quoted. Kept visible, it
	 * resolves as not purchasable (is_purchasable() is still false, so the cart rejects it),
	 * and yk-wcgv-quote.js swaps the button for the quote link.
	 *
	 * Front end only: the admin and REST keep WooCommerce's own answer.
	 *
	 * @param bool                 $visible      WooCommerce's answer.
	 * @param int                  $variation_id Variation ID.
	 * @param int                  $parent_id    Parent ID.
	 * @param WC_Product_Variation $variation    Variation.
	 */
	public static function variation_is_visible( $visible, $variation_id, $parent_id, $variation ): bool {
		if ( $visible || ( is_admin() && ! wp_doing_ajax() ) ) {
			return (bool) $visible;
		}

		return 'publish' === get_post_status( $variation_id ) && self::needs_quote( $variation );
	}

	/**
	 * Flag each variation for the single product page script.
	 *
	 * @param array                $data      Variation data for the form.
	 * @param WC_Product_Variable  $product   Parent.
	 * @param WC_Product_Variation $variation Variation.
	 */
	public static function available_variation( $data, $product, $variation ): array {
		$data = (array) $data;
		$data['yk_needs_quote'] = self::needs_quote( $variation );

		return $data;
	}

	/**
	 * Quote link for a simple product without a price.
	 *
	 * Priority 31, right after WooCommerce's add-to-cart slot (30), which renders nothing
	 * for a product that is not purchasable.
	 */
	public static function render_single(): void {
		global $product;

		if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variable' ) || ! self::needs_quote( $product ) ) {
			return;
		}

		self::print_single_link( $product, false );
	}

	/**
	 * Hidden quote link inside the variation form; the script reveals it per variation.
	 */
	public static function render_variable_single(): void {
		global $product;

		if ( ! self::enabled() || ! is_a( $product, 'WC_Product' ) || ! $product->is_type( 'variable' ) ) {
			return;
		}

		self::print_single_link( $product, true );
	}

	/**
	 * @param WC_Product $product Product.
	 * @param bool       $hidden  Start hidden (variable products).
	 */
	private static function print_single_link( $product, bool $hidden ): void {
		if ( '' === self::email() ) {
			return;
		}
		?>
		<div class="yk-sp-quote"<?php echo $hidden ? ' hidden' : ''; ?>>
			<a class="yk-quote button alt wp-element-button" <?php echo self::link_attributes( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_attributes(). ?>>
				<?php esc_html_e( 'Request a quote', 'yk-wc-grid-variations' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * The link script: rebuilds the mailto with the live quantity / variation on click.
	 */
	public static function enqueue(): void {
		if ( ! self::enabled() || ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() ) {
			return;
		}

		wp_enqueue_script(
			'yk-wcgv-quote',
			YK_WCGV_URL . 'assets/js/yk-wcgv-quote.js',
			[ 'jquery' ],
			yk_wcgv_asset_version( 'assets/js/yk-wcgv-quote.js' ),
			true
		);

		$config = array_merge( [ 'email' => self::email() ], self::templates() );

		wp_add_inline_script(
			'yk-wcgv-quote',
			'window.ykWcgvQuote = ' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}
}
