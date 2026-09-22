<?php
namespace Certiva\Admin;

use Certiva\Data\RegistrationsRepository;
use Certiva\Data\DownloadTokensRepository;
use Certiva\Pdf\CertificateService;
use Certiva\Email\Mailer;
use Certiva\Support\Capabilities;
use Certiva\Support\PrivateStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX operations for the Registrations screen and the student edit
 * meta box: toggle eligibility, generate/regenerate, resend, delete.
 *
 * Binary PDF streaming (preview/download) is handled separately by
 * CertificateAdminStream via admin-post.php, since AJAX responses are JSON.
 */
final class RegistrationActions {

	public static function register(): void {
		add_action( 'wp_ajax_certiva_admin_action', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		check_ajax_referer( 'certiva_admin_action', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'certiva' ) ], 403 );
			return;
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$registration_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( $registration_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid registration.', 'certiva' ) ], 400 );
			return;
		}

		$registration = RegistrationsRepository::get( $registration_id );
		if ( ! $registration ) {
			wp_send_json_error( [ 'message' => __( 'Registration not found.', 'certiva' ) ], 404 );
			return;
		}

		switch ( $op ) {
			case 'set_eligible':
				self::set_eligible( $registration_id );
				return;
			case 'generate':
				self::generate( $registration_id );
				return;
			case 'regenerate':
				self::regenerate( $registration_id );
				return;
			case 'resend':
				self::resend( $registration );
				return;
			case 'delete':
				self::delete( $registration );
				return;
			default:
				wp_send_json_error( [ 'message' => __( 'Unknown action.', 'certiva' ) ], 400 );
				return;
		}
	}

	private static function set_eligible( int $registration_id ): void {
		$eligible = ! empty( $_POST['eligible'] );
		RegistrationsRepository::update_eligibility( $registration_id, $eligible );
		wp_send_json_success( [ 'eligible' => $eligible ] );
	}

	private static function generate( int $registration_id ): void {
		$result = CertificateService::generate( $registration_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
			return;
		}
		wp_send_json_success( [ 'message' => __( 'Certificate generated.', 'certiva' ) ] );
	}

	private static function regenerate( int $registration_id ): void {
		$result = CertificateService::regenerate( $registration_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
			return;
		}
		wp_send_json_success( [ 'message' => __( 'Certificate regenerated.', 'certiva' ) ] );
	}

	private static function resend( object $registration ): void {
		if ( RegistrationsRepository::STATUS_GENERATED !== $registration->status ) {
			wp_send_json_error( [ 'message' => __( 'This certificate has not been generated yet.', 'certiva' ) ], 400 );
			return;
		}

		if ( ! CertificateService::is_eligible( $registration ) ) {
			wp_send_json_error( [ 'message' => __( 'This registration is not currently eligible.', 'certiva' ) ], 400 );
			return;
		}

		$email = get_post_meta( (int) $registration->student_id, 'certiva_email', true );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'This student does not have a valid email address on file.', 'certiva' ) ], 400 );
			return;
		}

		$sent = Mailer::send_single_certificate_email( $registration, $email );
		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( [ 'message' => $sent->get_error_message() ], 500 );
			return;
		}

		wp_send_json_success( [ 'message' => __( 'Email sent.', 'certiva' ) ] );
	}

	private static function delete( object $registration ): void {
		if ( $registration->pdf_path ) {
			PrivateStorage::delete_relative( (string) $registration->pdf_path );
		}
		DownloadTokensRepository::delete_for_registration( (int) $registration->id );
		RegistrationsRepository::delete( (int) $registration->id );

		wp_send_json_success( [ 'message' => __( 'Registration removed.', 'certiva' ) ] );
	}
}
