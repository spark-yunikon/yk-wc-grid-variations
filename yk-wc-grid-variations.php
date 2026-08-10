<?php
/**
 * Plugin Name:  YK WC Grid Variations
 * Plugin URI:   https://yunikon.ch/
 * Description:  Custom product card display for WooCommerce archive / category pages. Interactive swatches, size pills, quantity stepper, and AJAX add-to-cart.
 * Version:      2.0.0
 * Author:       Yunikon GmbH
 * Author URI:   https://yunikon.ch/
 * Text Domain:  yk-wc-grid-variations
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'YK_WCGV_VERSION', '2.0.0' );
define( 'YK_WCGV_FILE', __FILE__ );
define( 'YK_WCGV_DIR', plugin_dir_path( __FILE__ ) );
define( 'YK_WCGV_URL', plugin_dir_url( __FILE__ ) );

// Global constants — woocommerce/content-product.php references these directly,
// so they must stay global and be defined before any template is loaded.
const YK_WCGV_COLOR_KEYS = [ 'color', 'colour', 'farbe' ];
const YK_WCGV_SIZE_KEYS  = [ 'size', 'größe', 'grösse', 'grosse', 'groesse', 'taille' ];

// ── Includes (helpers first — the classes below rely on them) ──────────────────

require_once YK_WCGV_DIR . 'includes/functions-helpers.php';
require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-i18n.php';
require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-settings.php';
require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-data.php';
require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-assets.php';
require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-template.php';

// Admin-only: term swatch fields. The front-end reads swatches through
// yk_wcgv_get_swatch() in functions-helpers.php, so nothing here is needed there.
if ( is_admin() ) {
	require_once YK_WCGV_DIR . 'includes/class-yk-wcgv-term-meta.php';
}

// ── Hook registration entry point ─────────────────────────────────────────────

YK_WCGV_I18n::init();
YK_WCGV_Settings::init();
YK_WCGV_Data::init();
YK_WCGV_Assets::init();
YK_WCGV_Template::init();

if ( is_admin() ) {
	YK_WCGV_Term_Meta::init();
}

// ── WooCommerce HPOS compatibility ───────────────────────────────────────────

// Stays in the main plugin file: declare_compatibility() must receive this file's path.
add_action( 'before_woocommerce_init', 'yk_wcgv_declare_hpos_compatibility' );
function yk_wcgv_declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}
