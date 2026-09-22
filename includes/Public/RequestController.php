<?php
namespace Certiva\Public;

use Certiva\Data\RegistrationsRepository;
use Certiva\Data\StudentEmailIndexRepository;
use Certiva\Email\Mailer;
use Certiva\Pdf\CertificateService;
use Certiva\Security\BotGuard;
use Certiva\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the public certificate-request form submission.
 *
 * The response is always the same neutral message regardless of whether the
 * email matches a student, has eligible certificates, or nothing at all —
 * the only paths that differ are a technical form error (bad nonce) or a
 * malformed email address, neither of which discloses account existence.
 * Suspected bot submissions and rate-limited requests are silently treated
 * as success so a bot cannot learn to route around the safeguards.
 */
final class RequestController {

	public const ACTION = 'certiva_request_certificates';
	public const NONCE_ACTION = 'certiva_request_certificates';
	public const NONCE_FIELD  = 'certiva_request_nonce';

	private const FLASH_PREFIX = 'certiva_flash_';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		$redirect_to = isset( $_POST['certiva_redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['certiva_redirect_to'] ) ) : home_url( '/' );
		if ( ! self::is_safe_local_url( $redirect_to ) ) {
			$redirect_to = home_url( '/' );
		}

		$response = self::determine_response( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified inside determine_response().

		self::redirect_with_flash( $redirect_to, $response['type'], $response['message'] );
	}

	/**
	 * Pure decision logic for a submission: validates the request and, if it
	 * passes, looks up and emails any eligible certificates — but always
	 * returns the same neutral "success" response regardless of whether a
	 * match was found, so the caller (and ultimately the visitor) cannot
	 * distinguish "no such email" from "email exists but nothing eligible"
	 * from "email exists and mail was sent". Only a technical form error
	 * (bad nonce) or a malformed email address produce a different response,
	 * and neither of those discloses account existence.
	 *
	 * Side-effect free with respect to HTTP (no redirect/exit) so it can be
	 * unit tested directly.
	 *
	 * @return array{type: string, message: string}
	 */
	public static function determine_response( array $request ): array {
		$neutral_message = __( "If certificates are available for this email address, we'll send you a download link.", 'certiva' );

		$nonce = isset( $request[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $request[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return [ 'type' => 'error', 'message' => __( 'Something went wrong. Please refresh the page and try again.', 'certiva' ) ];
		}

		if ( BotGuard::looks_automated() ) {
			// Pretend success; do no real work.
			return [ 'type' => 'success', 'message' => $neutral_message ];
		}

		$email = isset( $request['certiva_email'] ) ? sanitize_email( wp_unslash( $request['certiva_email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return [ 'type' => 'error', 'message' => __( 'Please enter a valid email address.', 'certiva' ) ];
		}

		$normalized = StudentEmailIndexRepository::normalize( $email );

		if ( ! RateLimiter::allow_request( $normalized ) ) {
			// Do not disclose rate limiting either — same neutral message, no email sent.
			return [ 'type' => 'success', 'message' => $neutral_message ];
		}

		self::process( $normalized );

		return [ 'type' => 'success', 'message' => $neutral_message ];
	}

	private static function process( string $normalized_email ): void {
		$student_ids = StudentEmailIndexRepository::get_student_ids_for_email( $normalized_email );
		if ( empty( $student_ids ) ) {
			return;
		}

		$registrations = RegistrationsRepository::get_for_students( $student_ids );
		if ( empty( $registrations ) ) {
			return;
		}

		$eligible = array_values(
			array_filter(
				$registrations,
				static function ( $registration ) {
					return RegistrationsRepository::STATUS_GENERATED === $registration->status
						&& CertificateService::is_eligible( $registration );
				}
			)
		);

		if ( empty( $eligible ) ) {
			return;
		}

		Mailer::send_available_certificates_email( $normalized_email, $eligible );
	}

	private static function redirect_with_flash( string $redirect_to, string $type, string $message ): void {
		$key = self::FLASH_PREFIX . wp_generate_password( 20, false, false );
		set_transient( $key, [ 'type' => $type, 'message' => $message ], 5 * MINUTE_IN_SECONDS );

		$url = add_query_arg( 'certiva_notice', $key, $redirect_to );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Reads and deletes a one-time flash message set by redirect_with_flash().
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function consume_flash(): ?array {
		if ( empty( $_GET['certiva_notice'] ) ) {
			return null;
		}

		$key = sanitize_text_field( wp_unslash( $_GET['certiva_notice'] ) );
		if ( 0 !== strpos( $key, self::FLASH_PREFIX ) ) {
			return null;
		}

		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return null;
		}

		delete_transient( $key );

		return $flash;
	}

	private static function is_safe_local_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
