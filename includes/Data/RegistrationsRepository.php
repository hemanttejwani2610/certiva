<?php
namespace Certiva\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the certiva_registrations table.
 *
 * A registration links one student to one event. The (student_id, event_id)
 * pair is unique at the database level, so duplicate registrations are
 * rejected even under concurrent requests, not just by an application-level
 * check.
 */
final class RegistrationsRepository {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_GENERATED = 'generated';

	/**
	 * @return int|\WP_Error New registration id, or WP_Error( 'duplicate_registration' ).
	 */
	public static function create( int $student_id, int $event_id, ?int $template_override_id = null, bool $eligible = false ) {
		global $wpdb;

		$existing = self::find_by_student_and_event( $student_id, $event_id );
		if ( $existing ) {
			return new \WP_Error(
				'certiva_duplicate_registration',
				__( 'This student is already registered for that event.', 'certiva' )
			);
		}

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			Schema::table_registrations(),
			[
				'student_id'  => $student_id,
				'event_id'    => $event_id,
				'template_id' => $template_override_id ?: null,
				'eligible'    => $eligible ? 1 : 0,
				'status'      => self::STATUS_PENDING,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'certiva_duplicate_registration',
				__( 'This student is already registered for that event.', 'certiva' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	public static function find_by_student_and_event( int $student_id, int $event_id ): ?object {
		global $wpdb;

		$table = Schema::table_registrations();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE student_id = %d AND event_id = %d",
				$student_id,
				$event_id
			)
		);

		return $row ?: null;
	}

	public static function get( int $registration_id ): ?object {
		global $wpdb;
		$table = Schema::table_registrations();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $registration_id )
		);

		return $row ?: null;
	}

	/**
	 * @return object[]
	 */
	public static function get_for_student( int $student_id ): array {
		global $wpdb;
		$table = Schema::table_registrations();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE student_id = %d ORDER BY created_at DESC",
				$student_id
			)
		);
	}

	/**
	 * @return object[]
	 */
	public static function get_for_students( array $student_ids ): array {
		global $wpdb;

		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( empty( $student_ids ) ) {
			return [];
		}

		$table        = Schema::table_registrations();
		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );

		// Table name is fixed/prefixed; placeholders are all %d and bound via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE student_id IN ({$placeholders})",
				...$student_ids
			)
		);
	}

	/**
	 * @return object[]
	 */
	public static function get_for_event( int $event_id ): array {
		global $wpdb;
		$table = Schema::table_registrations();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC",
				$event_id
			)
		);
	}

	/**
	 * @return object[]
	 */
	public static function get_all( int $per_page = 50, int $paged = 1, array $filters = [] ): array {
		global $wpdb;
		$table = Schema::table_registrations();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'event_id = %d';
			$params[] = absint( $filters['event_id'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $filters['status'] );
		}

		$offset       = max( 0, ( $paged - 1 ) * $per_page );
		$where_sql    = implode( ' AND ', $where );
		$params[]     = $per_page;
		$params[]     = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$params
			)
		);
	}

	public static function count_all( array $filters = [] ): int {
		global $wpdb;
		$table = Schema::table_registrations();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'event_id = %d';
			$params[] = absint( $filters['event_id'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $filters['status'] );
		}

		$where_sql = implode( ' AND ', $where );

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params )
		);
	}

	public static function update_eligibility( int $registration_id, bool $eligible ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table_registrations(),
			[
				'eligible'   => $eligible ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $registration_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	public static function update_template_override( int $registration_id, ?int $template_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table_registrations(),
			[
				'template_id' => $template_id ?: null,
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $registration_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	public static function set_certificate_id( int $registration_id, string $certificate_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table_registrations(),
			[
				'certificate_id' => $certificate_id,
				'updated_at'     => current_time( 'mysql' ),
			],
			[ 'id' => $registration_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function mark_generated( int $registration_id, string $pdf_path ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		return false !== $wpdb->update(
			Schema::table_registrations(),
			[
				'status'     => self::STATUS_GENERATED,
				'pdf_path'   => $pdf_path,
				'issued_at'  => $now,
				'updated_at' => $now,
			],
			[ 'id' => $registration_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function mark_regenerated( int $registration_id, string $pdf_path ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		return false !== $wpdb->update(
			Schema::table_registrations(),
			[
				'status'         => self::STATUS_GENERATED,
				'pdf_path'       => $pdf_path,
				'regenerated_at' => $now,
				'updated_at'     => $now,
			],
			[ 'id' => $registration_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function delete( int $registration_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Schema::table_registrations(), [ 'id' => $registration_id ], [ '%d' ] );
	}

	public static function delete_for_student( int $student_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table_registrations(), [ 'student_id' => $student_id ], [ '%d' ] );
	}

	public static function delete_for_event( int $event_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table_registrations(), [ 'event_id' => $event_id ], [ '%d' ] );
	}
}
