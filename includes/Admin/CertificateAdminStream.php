<?php
namespace Certiva\Admin;

use Certiva\Pdf\CertificateService;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams certificate PDFs to logged-in admins: a live preview (using real
 * registration data, no eligibility required) and the issued download.
 */
final class CertificateAdminStream {

	public static function register(): void {
		add_action( 'admin_post_certiva_admin_preview', [ __CLASS__, 'preview' ] );
		add_action( 'admin_post_certiva_admin_download', [ __CLASS__, 'download' ] );
	}

	public static function preview(): void {
		$registration_id = isset( $_GET['rid'] ) ? absint( $_GET['rid'] ) : 0;

		check_admin_referer( 'certiva_admin_preview_' . $registration_id );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'certiva' ), 403 );
		}

		$pdf = CertificateService::preview( $registration_id );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), 400 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="certificate-preview.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function download(): void {
		$registration_id = isset( $_GET['rid'] ) ? absint( $_GET['rid'] ) : 0;

		check_admin_referer( 'certiva_admin_download_' . $registration_id );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'certiva' ), 403 );
		}

		$pdf = CertificateService::get_issued_pdf( $registration_id );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), 400 );
		}

		$registration = \Certiva\Data\RegistrationsRepository::get( $registration_id );
		$filename     = 'Certificate-' . sanitize_file_name( (string) $registration->certificate_id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
