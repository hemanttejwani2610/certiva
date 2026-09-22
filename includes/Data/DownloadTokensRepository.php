<?php
namespace Certiva\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for one-registration-scoped, time-limited download tokens.
 *
 * Only a SHA-256 hash of the token is ever persisted, mirroring how core
 * stores password-reset keys, so a database read cannot yield a usable
 * download link.
 */
final class DownloadTokensRepository {

	/**
	 * Creates a new token for a registration and returns the raw token.
	 * The raw value is never stored — only its hash.
	 */
	public static function create( int $registration_id, int $ttl_seconds ): string {
		global $wpdb;

		$raw  = wp_generate_password( 48, false, false );
		$hash = hash( 'sha256', $raw );
		$now  = current_time( 'mysql', true );

		$wpdb->insert(
			Schema::table_download_tokens(),
			[
				'token_hash'      => $hash,
				'registration_id' => $registration_id,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds ),
				'created_at'      => $now,
				'use_count'       => 0,
			],
			[ '%s', '%d', '%s', '%s', '%d' ]
		);

		return $raw;
	}

	/**
	 * Validates a raw token against a claimed registration id.
	 *
	 * Returns the token row on success, or null if the token is missing,
	 * expired, or does not match the registration.
	 */
	public static function validate( string $raw_token, int $registration_id ): ?object {
		global $wpdb;

		if ( '' === $raw_token ) {
			return null;
		}

		$hash  = hash( 'sha256', $raw_token );
		$table = Schema::table_download_tokens();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND registration_id = %d",
				$hash,
				$registration_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		if ( strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return null;
		}

		return $row;
	}

	public static function record_use( int $token_id ): void {
		global $wpdb;
		$table = Schema::table_download_tokens();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET use_count = use_count + 1, last_used_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$token_id
			)
		);
	}

	/**
	 * Deletes expired tokens. Called from a daily cron job.
	 */
	public static function purge_expired(): void {
		global $wpdb;
		$table = Schema::table_download_tokens();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	public static function delete_for_registration( int $registration_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table_download_tokens(), [ 'registration_id' => $registration_id ], [ '%d' ] );
	}
}
