<?php
namespace Certiva\Security;

use Certiva\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed rate limiting for the public certificate-request form.
 *
 * Limits are tracked per client IP and per normalized email address so a
 * single actor cannot hammer the form, without needing a database table.
 */
final class RateLimiter {

	/**
	 * Returns the requesting client's IP address.
	 *
	 * Deliberately trusts only REMOTE_ADDR by default to avoid the classic
	 * X-Forwarded-For spoofing bypass; sites behind a trusted proxy can
	 * override via the certiva_client_ip filter.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'certiva_client_ip', $ip );
	}

	/**
	 * Increments and checks a named counter. Returns true if the action
	 * should be ALLOWED to proceed (under the limit).
	 */
	private static function check_and_increment( string $key, int $max, int $window_seconds ): bool {
		$transient_key = 'certiva_rl_' . $key;
		$count         = (int) get_transient( $transient_key );

		if ( $count >= $max ) {
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $transient_key, 1, $window_seconds );
		} else {
			set_transient( $transient_key, $count + 1, $window_seconds );
		}

		return true;
	}

	/**
	 * Checks both the per-IP and per-email limits for a certificate request.
	 * Both counters are always incremented together so the check itself
	 * can't be used to distinguish "email exists" from "email doesn't".
	 */
	public static function allow_request( string $normalized_email ): bool {
		$ip_max    = (int) SettingsPage::get( 'rate_limit_ip_max' );
		$ip_window = (int) SettingsPage::get( 'rate_limit_ip_window_min' ) * MINUTE_IN_SECONDS;

		$email_max    = (int) SettingsPage::get( 'rate_limit_email_max' );
		$email_window = (int) SettingsPage::get( 'rate_limit_email_window_min' ) * MINUTE_IN_SECONDS;

		$ip_key    = 'ip_' . md5( self::client_ip() );
		$email_key = 'email_' . hash( 'sha256', $normalized_email );

		$ip_ok    = self::check_and_increment( $ip_key, $ip_max, $ip_window );
		$email_ok = self::check_and_increment( $email_key, $email_max, $email_window );

		return $ip_ok && $email_ok;
	}
}
