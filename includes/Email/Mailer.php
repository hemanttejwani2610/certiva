<?php
namespace Certiva\Email;

use Certiva\Admin\SettingsPage;
use Certiva\Data\DownloadTokensRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends certificate-related email. Never attaches PDFs — every certificate
 * is delivered as a secure, time-limited, single-registration download link.
 */
final class Mailer {

	/**
	 * @param object[] $registrations Registration rows (must already be
	 *                                 filtered to eligible + generated).
	 * @return true|\WP_Error
	 */
	public static function send_available_certificates_email( string $to_email, array $registrations ) {
		if ( empty( $registrations ) ) {
			return true;
		}

		$items = self::build_items( $registrations );

		ob_start();
		require CERTIVA_DIR . 'templates/emails/available-certificates.php';
		$body = ob_get_clean();

		return self::send(
			$to_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your certificates from %s', 'certiva' ),
				get_bloginfo( 'name' )
			),
			$body
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function send_single_certificate_email( object $registration, string $to_email ) {
		$items = self::build_items( [ $registration ] );

		ob_start();
		require CERTIVA_DIR . 'templates/emails/available-certificates.php';
		$body = ob_get_clean();

		return self::send(
			$to_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your certificate from %s', 'certiva' ),
				get_bloginfo( 'name' )
			),
			$body
		);
	}

	/**
	 * @return array<int, array{title: string, event: string, url: string}>
	 */
	private static function build_items( array $registrations ): array {
		$ttl_seconds = (int) SettingsPage::get( 'download_link_expiry_hours' ) * HOUR_IN_SECONDS;
		$items       = [];

		foreach ( $registrations as $registration ) {
			$token = DownloadTokensRepository::create( (int) $registration->id, $ttl_seconds );

			$url = add_query_arg(
				[
					'action' => 'certiva_download',
					'rid'    => (int) $registration->id,
					'token'  => $token,
				],
				admin_url( 'admin-post.php' )
			);

			$items[] = [
				'event' => get_the_title( (int) $registration->event_id ),
				'url'   => $url,
			];
		}

		return $items;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function send( string $to_email, string $subject, string $html_body ) {
		$from_name    = (string) SettingsPage::get( 'email_from_name' );
		$from_address = (string) SettingsPage::get( 'email_from_address' );

		$set_from = static function ( $original_email ) use ( $from_address ) {
			return $from_address ?: $original_email;
		};
		$set_from_name = static function ( $original_name ) use ( $from_name ) {
			return $from_name ?: $original_name;
		};

		add_filter( 'wp_mail_from', $set_from );
		add_filter( 'wp_mail_from_name', $set_from_name );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( $to_email, $subject, $html_body, $headers );

		remove_filter( 'wp_mail_from', $set_from );
		remove_filter( 'wp_mail_from_name', $set_from_name );

		if ( ! $sent ) {
			return new \WP_Error( 'certiva_mail_failed', __( 'The email could not be sent.', 'certiva' ) );
		}

		return true;
	}
}
