<?php
namespace Certiva\PostTypes;

use Certiva\Pdf\FieldDefinitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The certiva_template custom post type: a reusable certificate design
 * (background image, page setup, positioned/styled text fields).
 */
final class TemplatePostType {

	public const POST_TYPE = 'certiva_template';

	public const PAGE_SIZE_A4     = 'A4';
	public const PAGE_SIZE_LETTER = 'Letter';
	public const PAGE_SIZE_LEGAL  = 'Legal';

	public const ORIENTATION_LANDSCAPE = 'L';
	public const ORIENTATION_PORTRAIT  = 'P';

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function page_sizes(): array {
		return [
			self::PAGE_SIZE_A4     => __( 'A4', 'certiva' ),
			self::PAGE_SIZE_LETTER => __( 'Letter', 'certiva' ),
			self::PAGE_SIZE_LEGAL  => __( 'Legal', 'certiva' ),
		];
	}

	public static function orientations(): array {
		return [
			self::ORIENTATION_LANDSCAPE => __( 'Landscape', 'certiva' ),
			self::ORIENTATION_PORTRAIT  => __( 'Portrait', 'certiva' ),
		];
	}

	/**
	 * Page dimensions in millimetres for a given size + orientation.
	 *
	 * @return array{0: float, 1: float} [width, height]
	 */
	public static function dimensions_mm( string $page_size, string $orientation ): array {
		$sizes = [
			self::PAGE_SIZE_A4     => [ 210.0, 297.0 ],
			self::PAGE_SIZE_LETTER => [ 215.9, 279.4 ],
			self::PAGE_SIZE_LEGAL  => [ 215.9, 355.6 ],
		];

		[ $w, $h ] = $sizes[ $page_size ] ?? $sizes[ self::PAGE_SIZE_A4 ];

		return self::ORIENTATION_LANDSCAPE === $orientation ? [ $h, $w ] : [ $w, $h ];
	}

	public static function register_post_type(): void {
		$labels = [
			'name'          => __( 'Certificate Templates', 'certiva' ),
			'singular_name' => __( 'Certificate Template', 'certiva' ),
			'add_new_item'  => __( 'Add New Template', 'certiva' ),
			'edit_item'     => __( 'Edit Certificate Template', 'certiva' ),
			'new_item'      => __( 'New Template', 'certiva' ),
			'search_items'  => __( 'Search Templates', 'certiva' ),
			'not_found'     => __( 'No templates found.', 'certiva' ),
			'all_items'     => __( 'Templates', 'certiva' ),
			'menu_name'     => __( 'Templates', 'certiva' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'certiva',
				'show_in_rest'    => false,
				'supports'        => [ 'title' ],
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		global $post;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'certiva-admin',
			CERTIVA_URL . 'assets/admin/css/admin.css',
			[],
			CERTIVA_VERSION
		);

		wp_enqueue_script(
			'certiva-template-editor',
			CERTIVA_URL . 'assets/admin/js/template-editor.js',
			[ 'jquery' ],
			CERTIVA_VERSION,
			true
		);

		wp_localize_script(
			'certiva-template-editor',
			'certivaTemplateEditor',
			[
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'previewNonce'   => wp_create_nonce( 'certiva_preview_template' ),
				'templateId'     => $post->ID,
				'fieldPresets'   => FieldDefinitions::presets(),
				'i18n'           => [
					'removeField'  => __( 'Remove', 'certiva' ),
					'selectImage'  => __( 'Select Background File', 'certiva' ),
					'useImage'     => __( 'Use this file', 'certiva' ),
					'previewError' => __( 'Could not generate preview. Check that a background and page size are set.', 'certiva' ),
					'customLabel'  => __( 'Custom Field', 'certiva' ),
					/* translators: %s: PDF filename */
					'pdfSelected'  => __( 'PDF background selected: %s', 'certiva' ),
				],
			]
		);
	}

	public static function add_meta_boxes(): void {
		add_meta_box(
			'certiva-template-setup',
			__( 'Page Setup', 'certiva' ),
			[ __CLASS__, 'render_setup_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'certiva-template-designer',
			__( 'Certificate Designer', 'certiva' ),
			[ __CLASS__, 'render_designer_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_setup_box( \WP_Post $post ): void {
		$bg_id       = (int) get_post_meta( $post->ID, 'certiva_bg_attachment_id', true );
		$page_size   = get_post_meta( $post->ID, 'certiva_page_size', true ) ?: self::PAGE_SIZE_A4;
		$orientation = get_post_meta( $post->ID, 'certiva_orientation', true ) ?: self::ORIENTATION_LANDSCAPE;

		wp_nonce_field( 'certiva_save_template_' . $post->ID, 'certiva_template_meta_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_page_size"><?php esc_html_e( 'Page Size', 'certiva' ); ?></label></th>
				<td>
					<select id="certiva_page_size" name="certiva_page_size">
						<?php foreach ( self::page_sizes() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $page_size, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="certiva_orientation"><?php esc_html_e( 'Orientation', 'certiva' ); ?></label></th>
				<td>
					<select id="certiva_orientation" name="certiva_orientation">
						<?php foreach ( self::orientations() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $orientation, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Background', 'certiva' ); ?></th>
				<td>
					<input type="hidden" id="certiva_bg_attachment_id" name="certiva_bg_attachment_id" value="<?php echo esc_attr( (string) $bg_id ); ?>" />
					<div id="certiva-bg-preview" style="margin-bottom:8px;">
						<?php echo self::render_bg_preview_html( $bg_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within the method. ?>
					</div>
					<button type="button" class="button" id="certiva-select-bg"><?php esc_html_e( 'Select Background File', 'certiva' ); ?></button>
					<button type="button" class="button" id="certiva-remove-bg" <?php echo $bg_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'certiva' ); ?></button>
					<p class="description"><?php esc_html_e( 'An image (JPG/PNG) or a single-page PDF — exactly one, either type. A PDF background is stretched to fill the page, same as an image.', 'certiva' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Renders the background preview: a thumbnail for an image, or a plain
	 * filename indicator for a PDF (browsers can't thumbnail a PDF from a
	 * bare <img> the way they can an image attachment).
	 */
	private static function render_bg_preview_html( int $bg_id ): string {
		if ( ! $bg_id ) {
			return '';
		}

		if ( 'application/pdf' === get_post_mime_type( $bg_id ) ) {
			$filename = basename( (string) get_attached_file( $bg_id ) );
			return '<p>' . esc_html(
				sprintf(
					/* translators: %s: PDF filename */
					__( 'PDF background selected: %s', 'certiva' ),
					$filename
				)
			) . '</p>';
		}

		return wp_get_attachment_image( $bg_id, 'medium' );
	}

	public static function render_designer_box( \WP_Post $post ): void {
		$fields_json = get_post_meta( $post->ID, 'certiva_fields_json', true );
		if ( ! is_string( $fields_json ) || '' === $fields_json ) {
			$fields_json = wp_json_encode( FieldDefinitions::defaults() );
		}
		$bg_id       = (int) get_post_meta( $post->ID, 'certiva_bg_attachment_id', true );
		$bg_is_pdf   = $bg_id && 'application/pdf' === get_post_mime_type( $bg_id );
		$bg_url      = ( $bg_id && ! $bg_is_pdf ) ? wp_get_attachment_image_url( $bg_id, 'large' ) : '';
		$bg_pdf_url  = ( $bg_id && $bg_is_pdf ) ? wp_get_attachment_url( $bg_id ) : '';
		?>
		<p class="description"><?php esc_html_e( 'Drag fields onto the certificate. Position and styling are saved with the template.', 'certiva' ); ?></p>
		<?php if ( $bg_is_pdf ) : ?>
			<p class="description"><em><?php esc_html_e( 'The PDF is shown below using your browser\'s own PDF viewer as a rough visual guide — its alignment with the fields isn\'t pixel-perfect. Use "Preview with Sample Data" for the accurate, final result.', 'certiva' ); ?></em></p>
		<?php endif; ?>
		<div id="certiva-designer" data-bg-url="<?php echo esc_url( $bg_url ); ?>" data-bg-pdf-url="<?php echo esc_url( $bg_pdf_url ); ?>">
			<div id="certiva-designer-stage" class="certiva-designer-stage">
				<div id="certiva-designer-fields"></div>
			</div>
			<div class="certiva-designer-toolbar">
				<label>
					<?php esc_html_e( 'Add field:', 'certiva' ); ?>
					<select id="certiva-add-field-select"></select>
				</label>
				<button type="button" class="button" id="certiva-add-field-btn"><?php esc_html_e( 'Add', 'certiva' ); ?></button>
				<button type="button" class="button button-secondary" id="certiva-preview-btn"><?php esc_html_e( 'Preview with Sample Data', 'certiva' ); ?></button>
			</div>
			<div id="certiva-field-inspector" class="certiva-field-inspector" style="display:none;"></div>
		</div>
		<input type="hidden" id="certiva_fields_json" name="certiva_fields_json" value="<?php echo esc_attr( $fields_json ); ?>" />
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['certiva_template_meta_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['certiva_template_meta_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'certiva_save_template_' . $post_id ) ) {
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

		$page_size = isset( $_POST['certiva_page_size'] ) ? sanitize_text_field( wp_unslash( $_POST['certiva_page_size'] ) ) : self::PAGE_SIZE_A4;
		if ( ! array_key_exists( $page_size, self::page_sizes() ) ) {
			$page_size = self::PAGE_SIZE_A4;
		}
		update_post_meta( $post_id, 'certiva_page_size', $page_size );

		$orientation = isset( $_POST['certiva_orientation'] ) ? sanitize_text_field( wp_unslash( $_POST['certiva_orientation'] ) ) : self::ORIENTATION_LANDSCAPE;
		if ( ! array_key_exists( $orientation, self::orientations() ) ) {
			$orientation = self::ORIENTATION_LANDSCAPE;
		}
		update_post_meta( $post_id, 'certiva_orientation', $orientation );

		$bg_id = isset( $_POST['certiva_bg_attachment_id'] ) ? absint( $_POST['certiva_bg_attachment_id'] ) : 0;
		if ( $bg_id > 0 ) {
			$mime = get_post_mime_type( $bg_id );
			$is_supported = 'attachment' === get_post_type( $bg_id )
				&& ( 'application/pdf' === $mime || str_starts_with( (string) $mime, 'image/' ) );
			if ( ! $is_supported ) {
				$bg_id = 0;
			}
		}
		update_post_meta( $post_id, 'certiva_bg_attachment_id', $bg_id );

		$fields_json = isset( $_POST['certiva_fields_json'] ) ? wp_unslash( $_POST['certiva_fields_json'] ) : '[]';
		$sanitized   = FieldDefinitions::sanitize_json( $fields_json );
		update_post_meta( $post_id, 'certiva_fields_json', wp_json_encode( $sanitized ) );
	}
}
