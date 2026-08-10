<?php
/**
 * Per-term swatch overrides (colour / two-tone colour / image) for WooCommerce
 * global attribute taxonomies.
 *
 * ADMIN ONLY. This file is required from the bootstrap behind is_admin() — it renders
 * and saves the term fields. Front-end reads go through yk_wcgv_get_swatch() in
 * includes/functions-helpers.php, which is always loaded.
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Term_Meta {

	/**
	 * Register hooks.
	 *
	 * pa_* taxonomies do not exist yet at plugin load — WooCommerce registers them on
	 * init (priority 5), firing 'woocommerce_after_register_taxonomy' right after. Walking
	 * wc_get_attribute_taxonomies() any earlier yields nothing and the fields never render.
	 * The init/20 fallback covers setups where that WooCommerce action is unavailable.
	 */
	public static function init(): void {
		add_action( 'woocommerce_after_register_taxonomy', [ __CLASS__, 'register_taxonomy_hooks' ] );
		add_action( 'init', [ __CLASS__, 'register_taxonomy_hooks' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	/**
	 * Attach meta registration, form fields, save handlers and list columns to every
	 * WooCommerce global attribute taxonomy. Runs at most once per request.
	 */
	public static function register_taxonomy_hooks(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		$done = true;

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = 'pa_' . $attribute->attribute_name;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			// show_in_rest is false on purpose: these are admin-side settings only.
			register_term_meta( $taxonomy, YK_WCGV_META_SWATCH_COLOR, [
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'yk_wcgv_sanitize_swatch_color_meta',
			] );

			register_term_meta( $taxonomy, YK_WCGV_META_SWATCH_IMAGE_ID, [
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'yk_wcgv_sanitize_swatch_image_meta',
			] );

			add_action( $taxonomy . '_add_form_fields', [ __CLASS__, 'render_add_fields' ] );
			add_action( $taxonomy . '_edit_form_fields', [ __CLASS__, 'render_edit_fields' ], 10, 2 );

			add_action( 'created_' . $taxonomy, [ __CLASS__, 'save_term_fields' ] );
			add_action( 'edited_' . $taxonomy, [ __CLASS__, 'save_term_fields' ] );

			add_filter( 'manage_edit-' . $taxonomy . '_columns', [ __CLASS__, 'add_preview_column' ] );
			add_filter( 'manage_' . $taxonomy . '_custom_column', [ __CLASS__, 'render_preview_column' ], 10, 3 );
		}
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	/**
	 * Load the media frame and admin script on attribute term screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_admin_assets( $hook_suffix ): void {
		if ( 'edit-tags.php' !== $hook_suffix && 'term.php' !== $hook_suffix ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 0 !== strpos( (string) $screen->taxonomy, 'pa_' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'yk-wcgv-admin',
			YK_WCGV_URL . 'assets/js/yk-wcgv-admin.js',
			[ 'jquery', 'media-editor' ],
			YK_WCGV_VERSION,
			true
		);

		wp_localize_script( 'yk-wcgv-admin', 'ykWcgvAdmin', [
			'frameTitle'  => __( 'Select swatch image', 'yk-wc-grid-variations' ),
			'frameButton' => __( 'Use this image', 'yk-wc-grid-variations' ),
		] );
	}

	// ── Form fields ──────────────────────────────────────────────────────────

	/**
	 * "Add new term" form (edit-tags.php).
	 *
	 * @param string $taxonomy Current taxonomy.
	 */
	public static function render_add_fields( $taxonomy ): void {
		?>
		<div class="form-field">
			<label for="yk_wcgv_swatch_color"><?php esc_html_e( 'Swatch colour', 'yk-wc-grid-variations' ); ?></label>
			<?php self::render_color_controls( '' ); ?>
		</div>
		<div class="form-field">
			<label><?php esc_html_e( 'Swatch image', 'yk-wc-grid-variations' ); ?></label>
			<?php self::render_image_controls( 0 ); ?>
		</div>
		<?php
	}

	/**
	 * "Edit term" form (term.php) — rendered inside the settings table.
	 *
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Current taxonomy.
	 */
	public static function render_edit_fields( $term, $taxonomy ): void {
		$color    = (string) get_term_meta( $term->term_id, YK_WCGV_META_SWATCH_COLOR, true );
		$image_id = (int) get_term_meta( $term->term_id, YK_WCGV_META_SWATCH_IMAGE_ID, true );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="yk_wcgv_swatch_color"><?php esc_html_e( 'Swatch colour', 'yk-wc-grid-variations' ); ?></label>
			</th>
			<td><?php self::render_color_controls( $color ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Swatch image', 'yk-wc-grid-variations' ); ?></th>
			<td><?php self::render_image_controls( $image_id ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Text field (source of truth) + two native colour pickers + live preview.
	 *
	 * Only the text input is submitted; the colour pickers are UI helpers that write
	 * into it, which is what makes the two-tone "#000000,#ffcc00" syntax possible.
	 *
	 * @param string $value Stored meta value.
	 */
	private static function render_color_controls( string $value ): void {
		$colors = yk_wcgv_parse_swatch_colors( $value );
		$first  = $colors[0] ?? '';
		$second = $colors[1] ?? '';
		?>
		<span class="yk-wcgv-swatch-field" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
			<input
				type="text"
				name="yk_wcgv_swatch_color"
				id="yk_wcgv_swatch_color"
				class="regular-text"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="#000000,#ffcc00"
				data-yk-swatch-text
				style="max-width:220px;"
			>
			<input
				type="color"
				value="<?php echo esc_attr( $first ? $first : '#000000' ); ?>"
				data-yk-swatch-color="0"
				aria-label="<?php esc_attr_e( 'Primary colour', 'yk-wc-grid-variations' ); ?>"
				style="width:40px;height:30px;padding:0;border:1px solid #8c8f94;background:none;"
			>
			<input
				type="color"
				value="<?php echo esc_attr( $second ? $second : '#ffffff' ); ?>"
				data-yk-swatch-color="1"
				aria-label="<?php esc_attr_e( 'Second colour (two-tone)', 'yk-wc-grid-variations' ); ?>"
				style="width:40px;height:30px;padding:0;border:1px solid #8c8f94;background:none;"
			>
			<button type="button" class="button-link" data-yk-swatch-clear-second>
				<?php esc_html_e( 'Single colour', 'yk-wc-grid-variations' ); ?>
			</button>
			<span
				data-yk-swatch-preview
				style="width:28px;height:28px;border-radius:50%;border:1px solid #ccd0d4;display:inline-block;background:<?php echo esc_attr( self::preview_background( $colors ) ); ?>;"
			></span>
		</span>
		<p class="description">
			<?php esc_html_e( 'Single colour (#000000) or two-tone, comma separated (#000000,#ffcc00). Leave empty to fall back to the built-in colour-name map.', 'yk-wc-grid-variations' ); ?>
		</p>
		<?php
	}

	/**
	 * Attachment picker + thumbnail preview.
	 *
	 * @param int $image_id Stored attachment ID.
	 */
	private static function render_image_controls( int $image_id ): void {
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		// A deleted attachment yields false — show the field as empty rather than broken.
		$has_image = (bool) $image_url;
		?>
		<span class="yk-wcgv-swatch-image-field" style="display:inline-flex;align-items:center;gap:8px;">
			<input type="hidden" name="yk_wcgv_swatch_image_id" value="<?php echo esc_attr( $has_image ? $image_id : 0 ); ?>" data-yk-swatch-image-input>
			<img
				src="<?php echo esc_url( $image_url ? $image_url : '' ); ?>"
				alt=""
				data-yk-swatch-image-preview
				style="width:40px;height:40px;object-fit:cover;border-radius:50%;border:1px solid #ccd0d4;<?php echo $has_image ? '' : 'display:none;'; ?>"
			>
			<button type="button" class="button" data-yk-swatch-image-select>
				<?php esc_html_e( 'Select image', 'yk-wc-grid-variations' ); ?>
			</button>
			<button type="button" class="button-link" data-yk-swatch-image-remove style="<?php echo $has_image ? '' : 'display:none;'; ?>">
				<?php esc_html_e( 'Remove', 'yk-wc-grid-variations' ); ?>
			</button>
		</span>
		<p class="description">
			<?php esc_html_e( 'An image takes precedence over the colour above.', 'yk-wc-grid-variations' ); ?>
		</p>
		<?php
	}

	/**
	 * CSS background value for the preview dot.
	 *
	 * @param  string[] $colors Zero, one or two hex colours.
	 * @return string
	 */
	private static function preview_background( array $colors ): string {
		if ( empty( $colors ) ) {
			return 'transparent';
		}
		if ( isset( $colors[1] ) ) {
			return 'linear-gradient(135deg,' . $colors[0] . ' 0 50%,' . $colors[1] . ' 50% 100%)';
		}
		return $colors[0];
	}

	// ── Save ─────────────────────────────────────────────────────────────────

	/**
	 * Persist the swatch meta on term create/update.
	 *
	 * Nonce: WordPress verifies its own term add/edit nonce in edit-tags.php / term.php
	 * before wp_insert_term() / wp_update_term() fire these hooks, so we ride that flow
	 * rather than adding a competing nonce. The isset() guards below keep programmatic
	 * term creation (imports, CLI) from wiping stored values.
	 *
	 * @param int $term_id Term being saved.
	 */
	public static function save_term_fields( $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		$term_id = absint( $term_id );
		if ( ! $term_id ) {
			return;
		}

		// TODO(v2 STEP6): invalidate product transient

		if ( isset( $_POST['yk_wcgv_swatch_color'] ) ) {
			$raw   = sanitize_text_field( wp_unslash( $_POST['yk_wcgv_swatch_color'] ) );
			$clean = yk_wcgv_sanitize_swatch_color_meta( $raw );

			if ( '' === trim( $raw ) ) {
				delete_term_meta( $term_id, YK_WCGV_META_SWATCH_COLOR );
			} elseif ( '' !== $clean ) {
				update_term_meta( $term_id, YK_WCGV_META_SWATCH_COLOR, $clean );
			}
			// A non-empty but invalid value is ignored — the stored value stays as it was.
		}

		if ( isset( $_POST['yk_wcgv_swatch_image_id'] ) ) {
			$image_id = yk_wcgv_sanitize_swatch_image_meta( wp_unslash( $_POST['yk_wcgv_swatch_image_id'] ) );

			if ( $image_id > 0 ) {
				update_term_meta( $term_id, YK_WCGV_META_SWATCH_IMAGE_ID, $image_id );
			} else {
				delete_term_meta( $term_id, YK_WCGV_META_SWATCH_IMAGE_ID );
			}
		}
	}

	// ── Term list column ─────────────────────────────────────────────────────

	/**
	 * @param  array $columns Existing list-table columns.
	 * @return array
	 */
	public static function add_preview_column( $columns ): array {
		$columns = (array) $columns;
		$columns['yk_wcgv_swatch'] = __( 'Swatch', 'yk-wc-grid-variations' );
		return $columns;
	}

	/**
	 * @param  string $content     Existing column markup.
	 * @param  string $column_name Column being rendered.
	 * @param  int    $term_id     Term ID.
	 * @return string
	 */
	public static function render_preview_column( $content, $column_name, $term_id ): string {
		if ( 'yk_wcgv_swatch' !== $column_name ) {
			return (string) $content;
		}

		$term = get_term( absint( $term_id ) );
		if ( ! $term || is_wp_error( $term ) ) {
			return (string) $content;
		}

		$swatch = yk_wcgv_get_swatch( $term->taxonomy, $term->slug );

		if ( 'image' === $swatch['type'] ) {
			return sprintf(
				'<img src="%s" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:50%%;border:1px solid #ccd0d4;">',
				esc_url( $swatch['image'] )
			);
		}

		if ( 'gradient' === $swatch['type'] || 'color' === $swatch['type'] ) {
			$colors     = array_filter( [ $swatch['color'], $swatch['color2'] ] );
			$background = self::preview_background( array_values( $colors ) );

			return sprintf(
				'<span title="%s" style="width:32px;height:32px;border-radius:50%%;border:1px solid #ccd0d4;display:inline-block;background:%s;"></span>',
				esc_attr( implode( ', ', $colors ) ),
				esc_attr( $background )
			);
		}

		return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'No swatch set', 'yk-wc-grid-variations' ) . '</span>';
	}
}
