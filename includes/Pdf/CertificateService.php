<?php
namespace Certiva\Pdf;

use Certiva\Data\RegistrationsRepository;
use Certiva\Data\DownloadTokensRepository;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Support\IdGenerator;
use Certiva\Support\PrivateStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates certificate eligibility, generation, and regeneration.
 *
 * Eligibility is a two-level gate: the event must have certificates enabled
 * AND the individual registration must be explicitly marked eligible. A
 * registration's eligible flag defaults to false and is never set
 * automatically by registering a student for an event — this is what
 * guarantees, for example, that registering a student for an exam does not
 * by itself mean they passed.
 *
 * A certificate's ID is generated once and never changes. "Generate" issues
 * it for the first time; "Regenerate" re-renders the PDF from current
 * student/event/template data but keeps the same ID and the same
 * registration row — it never creates a duplicate.
 */
final class CertificateService {

	/**
	 * Whether a registration currently passes both eligibility gates.
	 */
	public static function is_eligible( object $registration ): bool {
		if ( empty( $registration->eligible ) ) {
			return false;
		}

		return EventPostType::is_available( (int) $registration->event_id );
	}

	public static function get_effective_template_id( object $registration ): int {
		if ( ! empty( $registration->template_id ) ) {
			return (int) $registration->template_id;
		}

		return (int) get_post_meta( (int) $registration->event_id, 'certiva_default_template_id', true );
	}

	/**
	 * Builds the placeholder => value map for a registration using live data.
	 */
	public static function build_values( object $registration ): array {
		$student_id = (int) $registration->student_id;
		$event_id   = (int) $registration->event_id;

		$event_date_raw = get_post_meta( $event_id, 'certiva_event_date', true );
		$event_date     = $event_date_raw ? date_i18n( get_option( 'date_format' ), strtotime( $event_date_raw ) ) : '';

		$issue_source = $registration->issued_at ?: current_time( 'mysql' );
		$issue_date   = date_i18n( get_option( 'date_format' ), strtotime( $issue_source ) );

		$values = [
			FieldDefinitions::KEY_STUDENT_NAME   => get_the_title( $student_id ),
			FieldDefinitions::KEY_COLLEGE        => CollegeTaxonomy::get_college_name( $student_id ),
			FieldDefinitions::KEY_EVENT_TITLE    => get_the_title( $event_id ),
			FieldDefinitions::KEY_EVENT_DATE     => $event_date,
			FieldDefinitions::KEY_CERTIFICATE_ID => (string) $registration->certificate_id,
			FieldDefinitions::KEY_ISSUE_DATE     => $issue_date,
		];

		$extra_fields = get_post_meta( $student_id, 'certiva_extra_fields', true );
		if ( is_array( $extra_fields ) ) {
			foreach ( $extra_fields as $extra ) {
				$label = (string) ( $extra['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$values[ FieldDefinitions::EXTRA_PREFIX . $label ] = (string) ( $extra['value'] ?? '' );
			}
		}

		return $values;
	}

	private static function template_page_and_fields( int $template_id ): ?array {
		if ( $template_id <= 0 || TemplatePostType::POST_TYPE !== get_post_type( $template_id ) ) {
			return null;
		}

		$page_size   = get_post_meta( $template_id, 'certiva_page_size', true ) ?: TemplatePostType::PAGE_SIZE_A4;
		$orientation = get_post_meta( $template_id, 'certiva_orientation', true ) ?: TemplatePostType::ORIENTATION_LANDSCAPE;
		[ $width_mm, $height_mm ] = TemplatePostType::dimensions_mm( $page_size, $orientation );

		$bg_id   = (int) get_post_meta( $template_id, 'certiva_bg_attachment_id', true );
		$bg_path = $bg_id ? get_attached_file( $bg_id ) : '';

		$fields_json = get_post_meta( $template_id, 'certiva_fields_json', true );
		$fields      = is_string( $fields_json ) ? FieldDefinitions::sanitize_json( $fields_json ) : [];

		return [
			'page'   => [
				'width_mm'    => $width_mm,
				'height_mm'   => $height_mm,
				'orientation' => $orientation,
			],
			'bg_path' => $bg_path ?: '',
			'fields'  => $fields,
		];
	}

	/**
	 * Calls the renderer, converting a render-time exception (e.g. a
	 * corrupt or encrypted PDF background that mPDF's PDF importer can't
	 * parse) into a WP_Error instead of an uncaught fatal.
	 *
	 * @return string|\WP_Error PDF binary, or WP_Error.
	 */
	private static function render_or_error( array $template, array $values ) {
		try {
			return CertificateRenderer::render( $template['page'], $template['bg_path'], $template['fields'], $values );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'certiva_render_failed',
				sprintf(
					/* translators: %s: underlying error message */
					__( 'Could not render the certificate PDF: %s', 'certiva' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Renders a preview PDF using a registration's real data, without
	 * requiring eligibility and without saving anything. Used by the admin
	 * "Preview" action so staff can check a certificate before releasing it.
	 *
	 * @return string|\WP_Error PDF binary, or WP_Error.
	 */
	public static function preview( int $registration_id ) {
		$registration = RegistrationsRepository::get( $registration_id );
		if ( ! $registration ) {
			return new \WP_Error( 'certiva_not_found', __( 'Registration not found.', 'certiva' ) );
		}

		$template_id = self::get_effective_template_id( $registration );
		$template    = self::template_page_and_fields( $template_id );
		if ( ! $template ) {
			return new \WP_Error( 'certiva_no_template', __( 'No certificate template is assigned to this event or registration.', 'certiva' ) );
		}

		$values = self::build_values( $registration );
		if ( empty( $registration->certificate_id ) ) {
			$values[ FieldDefinitions::KEY_CERTIFICATE_ID ] = __( '(will be assigned on generation)', 'certiva' );
		}

		return self::render_or_error( $template, $values );
	}

	/**
	 * Issues a certificate for the first time. Idempotent: if one has
	 * already been generated, returns success without changing anything —
	 * use regenerate() to re-render with current data.
	 *
	 * @return true|\WP_Error
	 */
	public static function generate( int $registration_id ) {
		$registration = RegistrationsRepository::get( $registration_id );
		if ( ! $registration ) {
			return new \WP_Error( 'certiva_not_found', __( 'Registration not found.', 'certiva' ) );
		}

		if ( RegistrationsRepository::STATUS_GENERATED === $registration->status && PrivateStorage::exists_relative( (string) $registration->pdf_path ) ) {
			return true;
		}

		if ( ! self::is_eligible( $registration ) ) {
			return new \WP_Error( 'certiva_not_eligible', __( 'This registration is not eligible for a certificate. Enable certificates for the event and mark the registration eligible first.', 'certiva' ) );
		}

		$template_id = self::get_effective_template_id( $registration );
		$template    = self::template_page_and_fields( $template_id );
		if ( ! $template ) {
			return new \WP_Error( 'certiva_no_template', __( 'No certificate template is assigned to this event or registration.', 'certiva' ) );
		}

		$certificate_id = $registration->certificate_id ?: IdGenerator::unique_certificate_id();
		if ( empty( $registration->certificate_id ) ) {
			RegistrationsRepository::set_certificate_id( $registration_id, $certificate_id );
			$registration->certificate_id = $certificate_id;
		}

		$values = self::build_values( $registration );
		$pdf    = self::render_or_error( $template, $values );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}

		$relative_path = PrivateStorage::relative_path_for( $registration_id, IdGenerator::random_filename_token() );
		if ( ! PrivateStorage::save_relative( $relative_path, $pdf ) ) {
			return new \WP_Error( 'certiva_save_failed', __( 'Could not save the generated certificate file.', 'certiva' ) );
		}

		RegistrationsRepository::mark_generated( $registration_id, $relative_path );

		do_action( 'certiva_certificate_generated', $registration_id );

		return true;
	}

	/**
	 * Re-renders the PDF from current data, keeping the same certificate ID
	 * and registration row. Requires that a certificate was already issued.
	 *
	 * @return true|\WP_Error
	 */
	public static function regenerate( int $registration_id ) {
		$registration = RegistrationsRepository::get( $registration_id );
		if ( ! $registration ) {
			return new \WP_Error( 'certiva_not_found', __( 'Registration not found.', 'certiva' ) );
		}

		if ( empty( $registration->certificate_id ) ) {
			return new \WP_Error( 'certiva_not_generated', __( 'This certificate has not been generated yet.', 'certiva' ) );
		}

		if ( ! self::is_eligible( $registration ) ) {
			return new \WP_Error( 'certiva_not_eligible', __( 'This registration is no longer eligible for a certificate.', 'certiva' ) );
		}

		$template_id = self::get_effective_template_id( $registration );
		$template    = self::template_page_and_fields( $template_id );
		if ( ! $template ) {
			return new \WP_Error( 'certiva_no_template', __( 'No certificate template is assigned to this event or registration.', 'certiva' ) );
		}

		$values = self::build_values( $registration );
		$pdf    = self::render_or_error( $template, $values );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}

		$old_path      = (string) $registration->pdf_path;
		$relative_path = PrivateStorage::relative_path_for( $registration_id, IdGenerator::random_filename_token() );
		if ( ! PrivateStorage::save_relative( $relative_path, $pdf ) ) {
			return new \WP_Error( 'certiva_save_failed', __( 'Could not save the regenerated certificate file.', 'certiva' ) );
		}

		RegistrationsRepository::mark_regenerated( $registration_id, $relative_path );

		if ( $old_path && $old_path !== $relative_path ) {
			PrivateStorage::delete_relative( $old_path );
		}

		do_action( 'certiva_certificate_regenerated', $registration_id );

		return true;
	}

	/**
	 * Returns the already-generated PDF binary for a registration, re-checking
	 * eligibility live (in case it was revoked after issuance or after a
	 * download token was sent).
	 *
	 * @return string|\WP_Error
	 */
	public static function get_issued_pdf( int $registration_id ) {
		$registration = RegistrationsRepository::get( $registration_id );
		if ( ! $registration ) {
			return new \WP_Error( 'certiva_not_found', __( 'Certificate not found.', 'certiva' ) );
		}

		if ( ! self::is_eligible( $registration ) || RegistrationsRepository::STATUS_GENERATED !== $registration->status ) {
			return new \WP_Error( 'certiva_not_available', __( 'This certificate is not currently available.', 'certiva' ) );
		}

		if ( ! PrivateStorage::exists_relative( (string) $registration->pdf_path ) ) {
			return new \WP_Error( 'certiva_missing_file', __( 'The certificate file is missing. Please ask an administrator to regenerate it.', 'certiva' ) );
		}

		$binary = file_get_contents( PrivateStorage::absolute_path( (string) $registration->pdf_path ) );

		return false === $binary ? new \WP_Error( 'certiva_read_failed', __( 'Could not read the certificate file.', 'certiva' ) ) : $binary;
	}
}
