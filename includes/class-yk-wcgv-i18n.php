<?php
/**
 * Text domain loading, JS-facing strings and WPML string registration.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_I18n {

	/**
	 * Register hooks. Priorities must stay as they are (init 20 for WPML).
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
		add_action( 'init', [ __CLASS__, 'register_wpml_strings' ], 20 );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'yk-wc-grid-variations',
			false,
			dirname( plugin_basename( YK_WCGV_FILE ) ) . '/languages'
		);
	}

	/**
	 * Translatable JS-facing strings — single source for both the data payload
	 * and WPML string registration.
	 */
	public static function strings(): array {
		return [
			'added'            => __( 'Hinzugefügt',                     'yk-wc-grid-variations' ),
			'error'            => __( 'Fehler. Bitte erneut versuchen.', 'yk-wc-grid-variations' ),
			// Attribute-agnostic since STEP 3: cards can show any variation attribute
			// (Farbe, Grösse, Frequenz, …), so the message must not name specific ones.
			'select_variation' => __( 'Bitte alle Optionen wählen.',     'yk-wc-grid-variations' ),
			// Infinite scroll (STEP 7).
			'loading'          => __( 'Produkte werden geladen …',       'yk-wc-grid-variations' ),
			'all_loaded'       => __( 'Alle Produkte geladen.',          'yk-wc-grid-variations' ),
			'load_error'       => __( 'Laden fehlgeschlagen.',           'yk-wc-grid-variations' ),
			'retry'            => __( 'Erneut versuchen',                'yk-wc-grid-variations' ),
			// Single product page: escape from a combination WooCommerce has locked down.
			// WC hides its own "Clear" link until a variation resolves, so on a dead end
			// there is no visible way out — see yk-wcgv-product.js.
			'reset_selection'  => __( 'Auswahl zurücksetzen',            'yk-wc-grid-variations' ),
		];
	}

	public static function register_wpml_strings(): void {
		if ( ! function_exists( 'icl_register_string' ) ) {
			return;
		}

		$i18n    = self::strings();
		$context = 'yk-wc-grid-variations';

		$strings = [
			'sale_badge'          => 'SALE',
			'art_nr'              => 'Art.-Nr.',
			// NOTE: the former 'available_colours' / 'available_sizes' strings are gone —
			// attribute groups now label themselves with the WooCommerce attribute label
			// (translated by WPML's taxonomy translation), because a card can render any
			// number of attributes, not just colour and size.
			'decrease_quantity'   => 'Decrease quantity',
			'increase_quantity'   => 'Increase quantity',
			'quantity'            => 'Quantity',
			'incl_tax'            => 'inkl. MwSt.',
			'zum_produkt'         => 'Zum Produkt',
			'in_den_warenkorb'    => 'In den Warenkorb',
			'add_to_cart_aria'    => 'Add %s to cart',
			'js_added'            => $i18n['added'],
			'js_error'            => $i18n['error'],
			'js_select_variation' => $i18n['select_variation'],
			'js_loading'          => $i18n['loading'],
			'js_all_loaded'       => $i18n['all_loaded'],
			'js_load_error'       => $i18n['load_error'],
			'js_retry'            => $i18n['retry'],
			'js_reset_selection'  => $i18n['reset_selection'],
		];

		foreach ( $strings as $name => $value ) {
			icl_register_string( $context, $name, $value );
		}
	}
}

/**
 * Backwards-compatible wrapper — kept because templates and third-party code
 * call this global function.
 */
function yk_wcgv_i18n(): array {
	return YK_WCGV_I18n::strings();
}
