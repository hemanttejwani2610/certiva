<?php
namespace Certiva\Admin;

use Certiva\Pdf\CertificateRenderer;
use Certiva\Pdf\FieldDefinitions;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX preview for the template designer: renders a real PDF from the
 * designer's current (possibly unsaved) state and sample data, so admins
 * can check the layout before saving.
 */
final class TemplateAjax {

	public static function register(): void {
		add_action( 'wp_ajax_certiva_preview_template', [ __CLASS__, 'preview' ] );
	}

	public static function preview(): void {
		check_ajax_referer( 'certiva_preview_template', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'certiva' ) ], 403 );
			return;
		}

		$page_size   = isset( $_POST['page_size'] ) ? sanitize_text_field( wp_unslash( $_POST['page_size'] ) ) : TemplatePostType::PAGE_SIZE_A4;
		if ( ! array_key_exists( $page_size, TemplatePostType::page_sizes() ) ) {
			$page_size = TemplatePostType::PAGE_SIZE_A4;
		}

		$orientation = isset( $_POST['orientation'] ) ? sanitize_text_field( wp_unslash( $_POST['orientation'] ) ) : TemplatePostType::ORIENTATION_LANDSCAPE;
		if ( ! array_key_exists( $orientation, TemplatePostType::orientations() ) ) {
			$orientation = TemplatePostType::ORIENTATION_LANDSCAPE;
		}

		$bg_id = isset( $_POST['bg_attachment_id'] ) ? absint( $_POST['bg_attachment_id'] ) : 0;
		if ( $bg_id > 0 && 'attachment' !== get_post_type( $bg_id ) ) {
			$bg_id = 0;
		}
		$bg_path = $bg_id ? get_attached_file( $bg_id ) : '';

		$fields_json = isset( $_POST['fields_json'] ) ? wp_unslash( $_POST['fields_json'] ) : '[]';
		$fields      = FieldDefinitions::sanitize_json( $fields_json );

		if ( empty( $fields ) ) {
			wp_send_json_error( [ 'message' => __( 'Add at least one field first.', 'certiva' ) ], 400 );
			return;
		}

		[ $width_mm, $height_mm ] = TemplatePostType::dimensions_mm( $page_size, $orientation );

		$values = FieldDefinitions::sample_values();
		foreach ( $fields as $field ) {
			if ( str_starts_with( $field['key'], FieldDefinitions::EXTRA_PREFIX ) && ! isset( $values[ $field['key'] ] ) ) {
				$values[ $field['key'] ] = __( 'Sample Value', 'certiva' );
			}
		}

		try {
			$pdf = CertificateRenderer::render(
				[
					'width_mm'    => $width_mm,
					'height_mm'   => $height_mm,
					'orientation' => $orientation,
				],
				$bg_path ?: '',
				$fields,
				$values
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'Could not render the preview.', 'certiva' ) ], 500 );
			return;
		}

		wp_send_json_success( [ 'pdf_base64' => base64_encode( $pdf ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
