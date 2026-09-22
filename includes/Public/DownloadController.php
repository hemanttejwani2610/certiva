<?php
namespace Certiva\Public;

use Certiva\Data\DownloadTokensRepository;
use Certiva\Pdf\CertificateService;
use Certiva\Data\RegistrationsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a certificate PDF to a visitor holding a valid, unexpired,
 * registration-scoped download token from an emailed link.
 *
 * There is deliberately no nonce here: the link is emailed and may be
 * opened days later, by a logged-out visitor, on a different device or
 * browser session than the one that submitted the request — exactly the
 * scenario WordPress core itself handles the same way for password-reset
 * links (a signed, expiring key instead of a session-bound nonce).
 */
final class DownloadController {

	public const ACTION = 'certiva_download';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		$registration_id = isset( $_GET['rid'] ) ? absint( $_GET['rid'] ) : 0;
		$token           = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( $registration_id <= 0 || '' === $token ) {
			self::deny();
		}

		$token_row = DownloadTokensRepository::validate( $token, $registration_id );
		if ( ! $token_row ) {
			self::deny();
		}

		$pdf = CertificateService::get_issued_pdf( $registration_id );
		if ( is_wp_error( $pdf ) ) {
			self::deny();
		}

		DownloadTokensRepository::record_use( (int) $token_row->id );

		$registration = RegistrationsRepository::get( $registration_id );
		$filename     = 'Certificate-' . sanitize_file_name( (string) $registration->certificate_id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function deny(): void {
		wp_die(
			esc_html__( 'This download link is invalid or has expired. Please request a new one.', 'certiva' ),
			esc_html__( 'Link Expired', 'certiva' ),
			[ 'response' => 410 ]
		);
	}
}
