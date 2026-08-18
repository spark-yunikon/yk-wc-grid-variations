<?php
/**
 * Text domain loading and the JS-facing string table.
 *
 * Source strings are English, matching the YK Starter theme's `frost` domain, and the
 * shipped `languages/*.po` / `*.mo` supply German and French. Before 2.1.0 the shopper-facing
 * strings were German literals and no `languages/` directory existed at all, so nothing was
 * translatable on a non-German site — see CHANGELOG 2.1.0.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_I18n {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
	}

	/**
	 * Load the plugin text domain, with a fallback for environments where
	 * load_plugin_textdomain() silently fails.
	 *
	 * The theme carries the same belt-and-suspenders loader (yk-theme/functions.php, `init`
	 * priority 1): on some WP 6.7+ setups the documented call returns without loading even
	 * though the .mo file is present and readable. The direct load_textdomain() below is a
	 * no-op whenever the first call worked, so it costs one is_textdomain_loaded() check.
	 *
	 * determine_locale() is what WordPress itself resolves the .mo path from, and WPML has
	 * already switched the locale by `init`, so the fallback picks the right file per
	 * language.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'yk-wc-grid-variations',
			false,
			dirname( plugin_basename( YK_WCGV_FILE ) ) . '/languages'
		);

		if ( is_textdomain_loaded( 'yk-wc-grid-variations' ) ) {
			return;
		}

		$mofile = YK_WCGV_DIR . 'languages/yk-wc-grid-variations-' . determine_locale() . '.mo';

		if ( is_readable( $mofile ) ) {
			load_textdomain( 'yk-wc-grid-variations', $mofile );
		}
	}

	/**
	 * Translatable JS-facing strings — the single source for the data payload.
	 *
	 * Called from YK_WCGV_Assets after `init`, so the text domain is always loaded by the
	 * time these run through __(). Do not call this at file scope.
	 */
	public static function strings(): array {
		return [
			'added'            => __( 'Added',                    'yk-wc-grid-variations' ),
			'error'            => __( 'Error. Please try again.', 'yk-wc-grid-variations' ),
			// Attribute-agnostic since 2.0.0: cards can show any variation attribute
			// (Farbe, Grösse, Frequenz, …), so the message must not name specific ones.
			'select_variation' => __( 'Please select all options.', 'yk-wc-grid-variations' ),
			// Infinite scroll.
			'loading'          => __( 'Loading products …',       'yk-wc-grid-variations' ),
			'all_loaded'       => __( 'All products loaded.',     'yk-wc-grid-variations' ),
			'load_error'       => __( 'Loading failed.',          'yk-wc-grid-variations' ),
			'retry'            => __( 'Try again',                'yk-wc-grid-variations' ),
			// Single product page: the only way to clear a selection, because this theme
			// keeps WooCommerce's own "Clear" link display:none in every state.
			'reset_selection'  => __( 'Reset selection',          'yk-wc-grid-variations' ),
		];
	}
}

/**
 * Backwards-compatible wrapper — kept because templates and third-party code
 * call this global function.
 */
function yk_wcgv_i18n(): array {
	return YK_WCGV_I18n::strings();
}
