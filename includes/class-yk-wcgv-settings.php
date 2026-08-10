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
		] );
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
								<?php esc_html_e( 'Display Art.-Nr. / SKU beneath the product title', 'yk-wc-grid-variations' ); ?>
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

/**
 * Backwards-compatible wrapper for the settings sanitise callback.
 *
 * @param  mixed $raw Raw option value.
 * @return array
 */
function yk_wcgv_sanitize_settings( $raw ): array {
	return YK_WCGV_Settings::sanitize( $raw );
}
