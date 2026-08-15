<?php
/**
 * Per-category paging override on product_cat terms.
 *
 * ADMIN ONLY, and product_cat ONLY. Deliberately a separate class from
 * class-yk-wcgv-term-meta.php: that one hooks every pa_* attribute taxonomy for swatches,
 * this one hooks product_cat for paging. Keeping them apart is what stops a paging field
 * from appearing on an attribute screen, or a swatch field on a category screen.
 *
 * The front end reads the value through YK_WCGV_Settings::pagination_mode().
 *
 * @package YK_WC_Grid_Variations
 */

defined( 'ABSPATH' ) || exit;

class YK_WCGV_Category_Meta {

	const TAXONOMY = 'product_cat';

	/** Stored values. 'inherit' means "follow the global setting" and is the default. */
	const MODES = [ 'inherit', 'pagination', 'infinite' ];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ], 20 );

		add_action( self::TAXONOMY . '_add_form_fields', [ __CLASS__, 'render_add_field' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ __CLASS__, 'render_edit_field' ], 10, 2 );

		add_action( 'created_' . self::TAXONOMY, [ __CLASS__, 'save' ] );
		add_action( 'edited_' . self::TAXONOMY, [ __CLASS__, 'save' ] );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ __CLASS__, 'add_column' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ __CLASS__, 'render_column' ], 10, 3 );
	}

	public static function register_meta(): void {
		register_term_meta( self::TAXONOMY, YK_WCGV_META_PAGINATION_MODE, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
	}

	/**
	 * @param  mixed $value Raw value.
	 * @return string One of MODES; anything else becomes 'inherit'.
	 */
	public static function sanitize( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::MODES, true ) ? $value : 'inherit';
	}

	/**
	 * Stored mode for a term, defaulting to inherit.
	 *
	 * @param  int $term_id Category.
	 * @return string
	 */
	public static function get_mode( int $term_id ): string {
		return self::sanitize( get_term_meta( $term_id, YK_WCGV_META_PAGINATION_MODE, true ) );
	}

	// ── Fields ───────────────────────────────────────────────────────────────

	/**
	 * @param string $taxonomy Current taxonomy.
	 */
	public static function render_add_field( $taxonomy ): void {
		?>
		<div class="form-field">
			<label for="yk_wcgv_pagination_mode"><?php esc_html_e( 'Paging', 'yk-wc-grid-variations' ); ?></label>
			<?php self::render_select( 'inherit' ); ?>
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Current taxonomy.
	 */
	public static function render_edit_field( $term, $taxonomy ): void {
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="yk_wcgv_pagination_mode"><?php esc_html_e( 'Paging', 'yk-wc-grid-variations' ); ?></label>
			</th>
			<td><?php self::render_select( self::get_mode( (int) $term->term_id ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * @param string $current Stored mode.
	 */
	private static function render_select( string $current ): void {
		$global = YK_WCGV_Settings::pagination_mode( [ 'taxonomy' => '', 'term_id' => 0 ] );
		?>
		<select name="yk_wcgv_pagination_mode" id="yk_wcgv_pagination_mode">
			<option value="inherit" <?php selected( $current, 'inherit' ); ?>>
				<?php
				printf(
					/* translators: %s: the global paging mode */
					esc_html__( 'Inherit global setting (%s)', 'yk-wc-grid-variations' ),
					'infinite' === $global ? esc_html__( 'infinite scroll', 'yk-wc-grid-variations' ) : esc_html__( 'pagination', 'yk-wc-grid-variations' )
				);
				?>
			</option>
			<option value="pagination" <?php selected( $current, 'pagination' ); ?>>
				<?php esc_html_e( 'Pagination', 'yk-wc-grid-variations' ); ?>
			</option>
			<option value="infinite" <?php selected( $current, 'infinite' ); ?>>
				<?php esc_html_e( 'Infinite scroll', 'yk-wc-grid-variations' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Applies to this category archive only.', 'yk-wc-grid-variations' ); ?>
		</p>
		<?php
	}

	/**
	 * Persist the choice.
	 *
	 * WordPress verifies its own term nonce in edit-tags.php / term.php before these hooks
	 * run; the isset() guard keeps programmatic term creation from writing anything.
	 *
	 * @param int $term_id Term being saved.
	 */
	public static function save( $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		if ( ! isset( $_POST['yk_wcgv_pagination_mode'] ) ) {
			return;
		}

		$mode = self::sanitize( wp_unslash( $_POST['yk_wcgv_pagination_mode'] ) );

		if ( 'inherit' === $mode ) {
			delete_term_meta( (int) $term_id, YK_WCGV_META_PAGINATION_MODE );
			return;
		}

		update_term_meta( (int) $term_id, YK_WCGV_META_PAGINATION_MODE, $mode );
	}

	// ── List column ──────────────────────────────────────────────────────────

	/**
	 * @param  array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ): array {
		$columns = (array) $columns;
		$columns['yk_wcgv_paging'] = __( 'Paging', 'yk-wc-grid-variations' );
		return $columns;
	}

	/**
	 * @param  string $content     Existing markup.
	 * @param  string $column_name Column being rendered.
	 * @param  int    $term_id     Term ID.
	 * @return string
	 */
	public static function render_column( $content, $column_name, $term_id ): string {
		if ( 'yk_wcgv_paging' !== $column_name ) {
			return (string) $content;
		}

		$mode = self::get_mode( (int) $term_id );

		if ( 'inherit' === $mode ) {
			return '<span style="color:#888;">' . esc_html__( 'inherit', 'yk-wc-grid-variations' ) . '</span>';
		}

		return 'infinite' === $mode
			? esc_html__( 'Infinite scroll', 'yk-wc-grid-variations' )
			: esc_html__( 'Pagination', 'yk-wc-grid-variations' );
	}
}
