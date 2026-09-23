<?php
namespace Certiva\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The certiva_college taxonomy: a controlled, reusable list of college
 * names attached to students.
 *
 * A taxonomy was chosen over a plain text field so admins pick from
 * existing colleges instead of retyping (and drifting) the same name, and
 * over a dedicated post type because a college here is just a name — no
 * extra fields of its own. The edit-screen UI is a single-select dropdown
 * (replacing WordPress's default tag/checklist box) so a student has at
 * most one college, with an inline "add a new one" option.
 */
final class CollegeTaxonomy {

	public const TAXONOMY = 'certiva_college';

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'save_post_' . StudentPostType::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_filter_dropdown' ], 10, 2 );
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			StudentPostType::POST_TYPE,
			[
				'labels'            => [
					'name'          => __( 'Colleges', 'certiva' ),
					'singular_name' => __( 'College', 'certiva' ),
					'search_items'  => __( 'Search Colleges', 'certiva' ),
					'all_items'     => __( 'All Colleges', 'certiva' ),
					'edit_item'     => __( 'Edit College', 'certiva' ),
					'update_item'   => __( 'Update College', 'certiva' ),
					'add_new_item'  => __( 'Add New College', 'certiva' ),
					'new_item_name' => __( 'New College Name', 'certiva' ),
					'menu_name'     => __( 'Colleges', 'certiva' ),
				],
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'meta_box_cb'       => [ __CLASS__, 'render_meta_box' ],
			]
		);
	}

	/**
	 * Replaces WordPress's default tag-style/checklist metabox with a
	 * single-select dropdown, since a student belongs to at most one
	 * college.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);
		$terms = is_wp_error( $terms ) ? [] : $terms;

		$current    = wp_get_post_terms( $post->ID, self::TAXONOMY, [ 'fields' => 'ids' ] );
		$current_id = ! is_wp_error( $current ) && ! empty( $current ) ? (int) $current[0] : 0;

		wp_nonce_field( 'certiva_save_college_' . $post->ID, 'certiva_college_nonce' );
		?>
		<select name="certiva_college_id" id="certiva_college_id" style="width:100%;">
			<option value="0"><?php esc_html_e( '— None —', 'certiva' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $current_id, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<p>
			<label for="certiva_college_new"><?php esc_html_e( 'Or add a new college:', 'certiva' ); ?></label>
			<input type="text" id="certiva_college_new" name="certiva_college_new" class="widefat" placeholder="<?php esc_attr_e( 'Type a new college name…', 'certiva' ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Typing a new name here creates it and uses it instead of the dropdown selection.', 'certiva' ); ?></p>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['certiva_college_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['certiva_college_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'certiva_save_college_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$new_name = isset( $_POST['certiva_college_new'] ) ? sanitize_text_field( wp_unslash( $_POST['certiva_college_new'] ) ) : '';

		$term_id = 0;
		if ( '' !== $new_name ) {
			$term_id = self::get_or_create_term_id( $new_name );
		} elseif ( isset( $_POST['certiva_college_id'] ) ) {
			$term_id = absint( $_POST['certiva_college_id'] );
		}

		if ( $term_id > 0 && term_exists( $term_id, self::TAXONOMY ) ) {
			wp_set_object_terms( $post_id, [ $term_id ], self::TAXONOMY, false );
		} else {
			wp_set_object_terms( $post_id, [], self::TAXONOMY, false );
		}
	}

	/**
	 * Finds an existing college term by name (case-insensitive) or creates
	 * one. Used by both the meta box's "add a new college" field and the
	 * CSV student importer.
	 */
	public static function get_or_create_term_id( string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}

		$existing = get_term_by( 'name', $name, self::TAXONOMY );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$inserted = wp_insert_term( $name, self::TAXONOMY );
		if ( is_wp_error( $inserted ) ) {
			// A race with another request may have just created the same
			// term; term_exists() catches that specific "already exists" case.
			$existing_id = term_exists( $name, self::TAXONOMY );
			return is_array( $existing_id ) ? (int) $existing_id['term_id'] : 0;
		}

		return (int) $inserted['term_id'];
	}

	/**
	 * The college name assigned to a student, or '' if none.
	 */
	public static function get_college_name( int $student_id ): string {
		$terms = wp_get_post_terms( $student_id, self::TAXONOMY, [ 'fields' => 'names' ] );
		return is_wp_error( $terms ) || empty( $terms ) ? '' : (string) $terms[0];
	}

	/**
	 * Renders the "Filter by college" dropdown above the Students list.
	 * Filtering itself is automatic: 'query_var' => true above registers
	 * a public query var that WP_Query (and so the list table) already
	 * understands, the same mechanism the built-in Category filter uses.
	 */
	public static function render_filter_dropdown( string $post_type, string $which ): void {
		if ( 'top' !== $which || StudentPostType::POST_TYPE !== $post_type ) {
			return;
		}

		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		$selected = isset( $_GET[ self::TAXONOMY ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAXONOMY ] ) ) : '';
		?>
		<label for="certiva-college-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by college', 'certiva' ); ?></label>
		<select name="<?php echo esc_attr( self::TAXONOMY ); ?>" id="certiva-college-filter">
			<option value=""><?php esc_html_e( 'All colleges', 'certiva' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
