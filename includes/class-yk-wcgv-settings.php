<?php
/**
 * Plugin settings: defaults, admin page, sanitisation.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Settings {

	/**
	 * Option holding one-off migration flags. Separate from 'yk_wcgv_settings' so the
	 * settings option keeps its own schema.
	 */
	const MIGRATIONS_OPTION = 'yk_wcgv_migrations';

	public static function init(): void {
		// Runs on the front end too: a leftover visible_attrs list would keep hiding
		// attributes there until someone opened wp-admin.
		add_action( 'init', [ __CLASS__, 'maybe_migrate' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	/**
	 * Returns plugin settings merged with defaults.
	 * Defaults mirror the original hardcoded behaviour (everything on, all attrs shown).
	 */
	public static function get(): array {
		return wp_parse_args( (array) get_option( 'yk_wcgv_settings', [] ), [
			'show_sale_badge'    => '1',
			'show_sku'           => '1',
			'show_variations'    => '1',
			// DEPRECATED since STEP 3 — attributes are now discovered from the product's
			// variation attributes. The key is kept (never removed) for back-compat and is
			// emptied once by maybe_migrate(); nothing reads it any more.
			'visible_attrs'      => [],
			'show_qty_stepper'   => '1',
			'enable_product_page' => '1',
			// Title of the theme.json style variation whose brand CSS should apply, or ''
			// for none. WordPress records the active variation nowhere, so it must be
			// chosen here — see YK_WCGV_Template::variant_body_class().
			'style_variant'      => '',
			// Archive paging: 'pagination' (WooCommerce's own links) or 'infinite'.
			'pagination_mode'    => 'pagination',
			// How far before the sentinel enters the viewport the next page is fetched.
			'prefetch_distance'  => 400,
		] );
	}

	/** Modes a site (or a category) may choose from. */
	const PAGINATION_MODES = [ 'pagination', 'infinite' ];

	/**
	 * The paging mode for the archive being rendered.
	 *
	 * Two layers: the global setting, overridden by a per-category choice unless that is
	 * left on 'inherit'. Other client projects can override either through the filter.
	 *
	 * @param  array $context Archive context passed to the filter (taxonomy, term_id, …).
	 * @return string 'pagination'|'infinite'
	 */
	public static function pagination_mode( array $context = [] ): string {
		$settings = self::get();
		$mode     = in_array( $settings['pagination_mode'], self::PAGINATION_MODES, true )
			? $settings['pagination_mode']
			: 'pagination';

		$context = wp_parse_args( $context, self::archive_context() );

		if ( ! empty( $context['term_id'] ) && 'product_cat' === ( $context['taxonomy'] ?? '' ) ) {
			$per_term = get_term_meta( (int) $context['term_id'], YK_WCGV_META_PAGINATION_MODE, true );
			if ( in_array( $per_term, self::PAGINATION_MODES, true ) ) {
				$mode = $per_term;
			}
		}

		/**
		 * Filters the paging mode for one archive.
		 *
		 * @param string $mode    'pagination' or 'infinite'.
		 * @param array  $context is_shop, taxonomy, term_id, term_slug.
		 */
		$mode = (string) apply_filters( 'yk_wcgv_pagination_mode', $mode, $context );

		return in_array( $mode, self::PAGINATION_MODES, true ) ? $mode : 'pagination';
	}

	/**
	 * Pixels before the sentinel at which the next page starts loading.
	 *
	 * @return int
	 */
	public static function prefetch_distance(): int {
		$settings = self::get();

		/**
		 * Filters the prefetch distance in pixels.
		 *
		 * @param int $distance Pixels.
		 */
		$distance = (int) apply_filters( 'yk_wcgv_prefetch_distance', (int) $settings['prefetch_distance'] );

		return max( 0, min( 5000, $distance ) );
	}

	/**
	 * Titles of the style variations the active theme ships (theme.json + styles/*.json).
	 *
	 * This is the only place a variation title can be read from: once the Site Editor
	 * applies one it copies the file's settings/styles into the user global styles and drops
	 * the title, so the *active* variation cannot be detected. The settings dropdown turns
	 * that into an explicit choice instead.
	 *
	 * @return string[] Variation titles, in the order the theme lists them.
	 */
	public static function style_variations(): array {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' )
			|| ! method_exists( 'WP_Theme_JSON_Resolver', 'get_style_variations' ) ) {
			return [];
		}

		$titles = [];

		foreach ( (array) WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			$title = is_array( $variation ) ? ( $variation['title'] ?? '' ) : '';
			if ( '' !== $title ) {
				$titles[] = (string) $title;
			}
		}

		return array_values( array_unique( $titles ) );
	}

	/**
	 * What the front end is currently showing, for the filters above.
	 *
	 * @return array
	 */
	public static function archive_context(): array {
		$context = [
			'is_shop'   => function_exists( 'is_shop' ) ? is_shop() : false,
			'taxonomy'  => '',
			'term_id'   => 0,
			'term_slug' => '',
		];

		$queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( $queried instanceof WP_Term ) {
			$context['taxonomy']  = $queried->taxonomy;
			$context['term_id']   = (int) $queried->term_id;
			$context['term_slug'] = $queried->slug;
		}

		return $context;
	}

	/**
	 * One-time cleanup of the retired 'visible_attrs' whitelist.
	 *
	 * A stored list from 2.x would silently hide every attribute added later, which looks
	 * like a ghost bug. We empty the list — the key itself stays — and archive the previous
	 * value inside the migrations option so nothing is lost.
	 */
	public static function maybe_migrate(): void {
		$migrations = (array) get_option( self::MIGRATIONS_OPTION, [] );

		if ( ! empty( $migrations['visible_attrs_cleared'] ) ) {
			return;
		}

		$settings = (array) get_option( 'yk_wcgv_settings', [] );
		$previous = isset( $settings['visible_attrs'] ) && is_array( $settings['visible_attrs'] )
			? array_values( $settings['visible_attrs'] )
			: [];

		if ( ! empty( $previous ) ) {
			$settings['visible_attrs'] = [];
			update_option( 'yk_wcgv_settings', $settings );
		}

		$migrations['visible_attrs_cleared']  = '1';
		$migrations['visible_attrs_previous'] = $previous;
		update_option( self::MIGRATIONS_OPTION, $migrations );
	}

	public static function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'YK Grid Variations', 'yk-wc-grid-variations' ),
			__( 'YK Grid Variations', 'yk-wc-grid-variations' ),
			'manage_woocommerce',
			'yk-wc-grid-variations',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'yk_wcgv_settings_group',
			'yk_wcgv_settings',
			[ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ]
		);
	}

	/**
	 * Whitelist-style sanitisation of the settings array.
	 *
	 * @param  mixed $raw Raw option value from the settings form.
	 * @return array
	 */
	public static function sanitize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [];

		$clean['show_sale_badge']     = ! empty( $raw['show_sale_badge'] )     ? '1' : '0';
		$clean['show_sku']            = ! empty( $raw['show_sku'] )            ? '1' : '0';
		$clean['show_variations']     = ! empty( $raw['show_variations'] )     ? '1' : '0';
		$clean['show_qty_stepper']    = ! empty( $raw['show_qty_stepper'] )    ? '1' : '0';
		$clean['enable_product_page'] = ! empty( $raw['enable_product_page'] ) ? '1' : '0';

		$mode = isset( $raw['pagination_mode'] ) ? sanitize_key( $raw['pagination_mode'] ) : 'pagination';
		$clean['pagination_mode'] = in_array( $mode, self::PAGINATION_MODES, true ) ? $mode : 'pagination';

		$distance = isset( $raw['prefetch_distance'] ) ? absint( $raw['prefetch_distance'] ) : 400;
		$clean['prefetch_distance'] = max( 0, min( 5000, $distance ) );

		// Stored as the human-readable title so it still matches after a theme update renames
		// nothing but reorders its variations. Not whitelisted against the current theme's
		// titles on purpose: switching themes back and forth would otherwise wipe the choice.
		// The value only ever reaches the page through sanitize_html_class( sanitize_title() ).
		$clean['style_variant'] = isset( $raw['style_variant'] )
			? sanitize_text_field( wp_unslash( $raw['style_variant'] ) )
			: '';

		// DEPRECATED since STEP 3: the UI is gone, so nothing posts this any more. The key is
		// still written (empty) so the option shape stays stable for older code paths.
		$clean['visible_attrs'] = [];

		return $clean;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'YK Grid Variations', 'yk-wc-grid-variations' ); ?></h1>
			<p style="color:#666;margin-top:0;"><?php esc_html_e( 'Controls what is displayed on WooCommerce category, archive, and single product pages.', 'yk-wc-grid-variations' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'yk_wcgv_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Product Cards', 'yk-wc-grid-variations' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Applies to archive and category pages.', 'yk-wc-grid-variations' ); ?></p>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Sale badge', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yk_wcgv_settings[show_sale_badge]" value="1"
									<?php checked( $settings['show_sale_badge'], '1' ); ?>>
								<?php esc_html_e( 'Show SALE badge on discounted products', 'yk-wc-grid-variations' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'SKU', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yk_wcgv_settings[show_sku]" value="1"
									<?php checked( $settings['show_sku'], '1' ); ?>>
								<?php esc_html_e( 'Display item number / SKU beneath the product title', 'yk-wc-grid-variations' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Variation swatches &amp; pills', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yk_wcgv_settings[show_variations]" value="1"
									<?php checked( $settings['show_variations'], '1' ); ?>>
								<?php esc_html_e( 'Show variation swatches and pills on category pages', 'yk-wc-grid-variations' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Every attribute marked "Used for variations" on the product is shown automatically, in the order set there.', 'yk-wc-grid-variations' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Quantity stepper', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yk_wcgv_settings[show_qty_stepper]" value="1"
									<?php checked( $settings['show_qty_stepper'], '1' ); ?>>
								<?php esc_html_e( 'Show +/− quantity stepper on product cards', 'yk-wc-grid-variations' ); ?>
							</label>
						</td>
					</tr>

				</table>

				<h2 class="title"><?php esc_html_e( 'Archive paging', 'yk-wc-grid-variations' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Individual categories can override this on their own edit screen (Products → Categories).', 'yk-wc-grid-variations' ); ?>
				</p>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<select name="yk_wcgv_settings[pagination_mode]">
								<option value="pagination" <?php selected( $settings['pagination_mode'], 'pagination' ); ?>>
									<?php esc_html_e( 'Pagination (WooCommerce links)', 'yk-wc-grid-variations' ); ?>
								</option>
								<option value="infinite" <?php selected( $settings['pagination_mode'], 'infinite' ); ?>>
									<?php esc_html_e( 'Infinite scroll', 'yk-wc-grid-variations' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Prefetch distance', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<input type="number" min="0" max="5000" step="50" class="small-text"
								name="yk_wcgv_settings[prefetch_distance]"
								value="<?php echo esc_attr( $settings['prefetch_distance'] ); ?>"> px
							<p class="description">
								<?php esc_html_e( 'How far before the end of the list the next page starts loading. Raise it on slower servers so shoppers never see a spinner.', 'yk-wc-grid-variations' ); ?>
							</p>
						</td>
					</tr>

				</table>

				<h2 class="title"><?php esc_html_e( 'Theme style variation', 'yk-wc-grid-variations' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'WordPress does not record which style variation is active, so it has to be selected here. The choice only adds a body class — it does not switch the variation itself.', 'yk-wc-grid-variations' ); ?>
				</p>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Active variation', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<?php $variations = self::style_variations(); ?>
							<?php if ( empty( $variations ) ) : ?>
								<p><em><?php esc_html_e( 'The active theme ships no style variations.', 'yk-wc-grid-variations' ); ?></em></p>
								<?php // Carry the stored value through the save, so switching to a theme without ?>
								<?php // variations (or back again) does not silently wipe the choice. ?>
								<input type="hidden" name="yk_wcgv_settings[style_variant]"
									value="<?php echo esc_attr( $settings['style_variant'] ); ?>">
							<?php else : ?>
								<select name="yk_wcgv_settings[style_variant]">
									<option value="" <?php selected( $settings['style_variant'], '' ); ?>>
										<?php esc_html_e( '— None —', 'yk-wc-grid-variations' ); ?>
									</option>
									<?php foreach ( $variations as $variation ) : ?>
										<option value="<?php echo esc_attr( $variation ); ?>" <?php selected( $settings['style_variant'], $variation ); ?>>
											<?php echo esc_html( $variation ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									if ( '' !== $settings['style_variant'] ) {
										printf(
											/* translators: %s: generated CSS body class, e.g. yk-variant-shop-attack */
											esc_html__( 'Adds %s to the body element, so the plugin stylesheet can override its --yk-* design tokens for this variation.', 'yk-wc-grid-variations' ),
											'<code>body.yk-variant-' . esc_html( sanitize_html_class( sanitize_title( $settings['style_variant'] ) ) ) . '</code>'
										);
									} else {
										esc_html_e( 'Adds a body.yk-variant-* class so the plugin stylesheet can override its --yk-* design tokens for this variation.', 'yk-wc-grid-variations' );
									}
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

				</table>

				<h2 class="title"><?php esc_html_e( 'Single Product Page', 'yk-wc-grid-variations' ); ?></h2>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Enhanced variation UI', 'yk-wc-grid-variations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yk_wcgv_settings[enable_product_page]" value="1"
									<?php checked( $settings['enable_product_page'], '1' ); ?>>
								<?php esc_html_e( 'Replace variation dropdowns with swatches/pills and add +/− quantity stepper', 'yk-wc-grid-variations' ); ?>
							</label>
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

/**
 * Backwards-compatible wrapper — woocommerce/content-product.php calls this
 * through function_exists().
 */
function yk_wcgv_get_settings(): array {
	return YK_WCGV_Settings::get();
}
