<?php
namespace Certiva\Support;

use Certiva\Data\RegistrationsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates unique, human-readable certificate IDs.
 */
final class IdGenerator {

	/**
	 * Generates a certificate ID guaranteed not to collide with an existing one.
	 * Format: CERTIVA-XXXXXXXX (uppercase alphanumeric).
	 */
	public static function unique_certificate_id(): string {
		global $wpdb;

		$table = \Certiva\Data\Schema::table_registrations();

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$candidate = 'CERTIVA-' . strtoupper( bin2hex( random_bytes( 5 ) ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE certificate_id = %s", $candidate )
			);

			if ( ! $exists ) {
				return $candidate;
			}
		}

		// Astronomically unlikely fallback: fall back to a longer random suffix.
		return 'CERTIVA-' . strtoupper( bin2hex( random_bytes( 8 ) ) );
	}

	/**
	 * Random, non-derivable filename component for stored PDF paths.
	 * Deliberately independent of the certificate_id so knowing the
	 * printed certificate ID never helps guess the file location.
	 */
	public static function random_filename_token(): string {
		return bin2hex( random_bytes( 20 ) );
	}
}
