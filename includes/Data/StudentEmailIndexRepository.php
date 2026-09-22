<?php
namespace Certiva\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Denormalized student_id => normalized email index.
 *
 * Kept in sync with the certiva_student post type's email meta so the
 * public certificate-request flow can look up students by email in a
 * single indexed query instead of scanning wp_postmeta.
 */
final class StudentEmailIndexRepository {

	/**
	 * Normalizes an email address for lookup/storage: trimmed, lowercased.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	public static function upsert( int $student_id, string $email ): void {
		global $wpdb;

		$normalized = self::normalize( $email );

		if ( '' === $normalized || ! is_email( $normalized ) ) {
			self::delete( $student_id );
			return;
		}

		$table = Schema::table_student_emails();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (student_id, email) VALUES (%d, %s)
				ON DUPLICATE KEY UPDATE email = VALUES(email)",
				$student_id,
				$normalized
			)
		);
	}

	public static function delete( int $student_id ): void {
		global $wpdb;
		$table = Schema::table_student_emails();
		$wpdb->delete( $table, [ 'student_id' => $student_id ], [ '%d' ] );
	}

	/**
	 * Returns an array of student post IDs matching a normalized email.
	 *
	 * @return int[]
	 */
	public static function get_student_ids_for_email( string $email ): array {
		global $wpdb;

		$normalized = self::normalize( $email );

		if ( '' === $normalized ) {
			return [];
		}

		$table = Schema::table_student_emails();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT student_id FROM {$table} WHERE email = %s", $normalized )
		);

		return array_map( 'absint', $ids );
	}
}
