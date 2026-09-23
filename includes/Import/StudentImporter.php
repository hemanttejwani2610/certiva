<?php
namespace Certiva\Import;

use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\StudentPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses a student CSV file and imports rows as certiva_student posts.
 *
 * Kept separate from the admin screen that drives it: this class only
 * knows about files, column mappings, and student records — no HTTP,
 * no $_POST, no nonces.
 */
final class StudentImporter {

	public const TARGET_FULL_NAME   = 'full_name';
	public const TARGET_EMAIL       = 'email';
	public const TARGET_STUDENT_ID  = 'student_code';
	public const TARGET_COLLEGE     = 'college';
	public const TARGET_EXTRA       = 'extra';
	public const TARGET_SKIP        = 'skip';

	/**
	 * Safety cap on the number of data rows processed in a single import.
	 * Larger files should be split — this keeps a single request from
	 * running unbounded.
	 */
	public const MAX_ROWS = 5000;

	public static function target_labels(): array {
		return [
			self::TARGET_SKIP       => __( '— Do not import —', 'certiva' ),
			self::TARGET_FULL_NAME  => __( 'Full Name', 'certiva' ),
			self::TARGET_EMAIL      => __( 'Email Address', 'certiva' ),
			self::TARGET_STUDENT_ID => __( 'Student ID', 'certiva' ),
			self::TARGET_COLLEGE    => __( 'College', 'certiva' ),
			self::TARGET_EXTRA      => __( 'Extra Placeholder Field', 'certiva' ),
		];
	}

	/**
	 * Reads the header row and a small preview of data rows, for the mapping
	 * screen. Does not process the whole file.
	 *
	 * @return array{headers: string[], preview: string[][], total_data_rows: int}|\WP_Error
	 */
	public static function read_headers_and_preview( string $path, int $preview_rows = 5 ) {
		return CsvReader::read_headers_and_preview( $path, $preview_rows );
	}

	/**
	 * Processes the full file according to the given column mapping.
	 *
	 * @param string $path             Absolute path to the CSV file.
	 * @param array  $column_targets   Indexed by CSV column position => one of the TARGET_* constants.
	 * @param array  $column_labels    Indexed by CSV column position => label to use when target is TARGET_EXTRA.
	 * @param bool   $update_existing  If true, a row whose email matches an existing student updates that
	 *                                 student instead of creating a duplicate.
	 *
	 * @return array{created:int, updated:int, skipped:int, skipped_reasons:string[], total:int}|\WP_Error
	 */
	public static function process( string $path, array $column_targets, array $column_labels, bool $update_existing ) {
		$full_name_col = array_search( self::TARGET_FULL_NAME, $column_targets, true );
		$email_col     = array_search( self::TARGET_EMAIL, $column_targets, true );

		if ( false === $full_name_col || false === $email_col ) {
			return new \WP_Error( 'certiva_import_missing_mapping', __( 'You must map a column to both Full Name and Email Address.', 'certiva' ) );
		}

		$student_id_col = array_search( self::TARGET_STUDENT_ID, $column_targets, true );
		$college_col    = array_search( self::TARGET_COLLEGE, $column_targets, true );

		$extra_cols = [];
		foreach ( $column_targets as $col => $target ) {
			if ( self::TARGET_EXTRA === $target ) {
				$extra_cols[ $col ] = ! empty( $column_labels[ $col ] ) ? $column_labels[ $col ] : ( 'Column ' . ( (int) $col + 1 ) );
			}
		}

		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return new \WP_Error( 'certiva_import_unreadable', __( 'The uploaded file could not be read.', 'certiva' ) );
		}

		fgetcsv( $handle ); // Skip header row.

		$results = [
			'created'         => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'skipped_reasons' => [],
			'total'           => 0,
		];

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$row_number = 1; // Header was row 1.
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row_number++;

			if ( 1 === count( $row ) && null === $row[0] ) {
				continue; // Blank line.
			}

			$results['total']++;

			if ( $results['total'] > self::MAX_ROWS ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: skipped — exceeded the %d row import limit for a single file.', 'certiva' ),
					$row_number,
					self::MAX_ROWS
				);
				continue;
			}

			$full_name = isset( $row[ $full_name_col ] ) ? sanitize_text_field( trim( (string) $row[ $full_name_col ] ) ) : '';
			$email     = isset( $row[ $email_col ] ) ? sanitize_email( trim( (string) $row[ $email_col ] ) ) : '';

			if ( '' === $full_name ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf( /* translators: %d: row number */ __( 'Row %d: skipped — missing full name.', 'certiva' ), $row_number );
				continue;
			}

			if ( '' === $email || ! is_email( $email ) ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf( /* translators: %d: row number */ __( 'Row %d: skipped — missing or invalid email address.', 'certiva' ), $row_number );
				continue;
			}

			$student_code = false !== $student_id_col && isset( $row[ $student_id_col ] )
				? sanitize_text_field( trim( (string) $row[ $student_id_col ] ) )
				: null;

			$college = false !== $college_col && isset( $row[ $college_col ] )
				? sanitize_text_field( trim( (string) $row[ $college_col ] ) )
				: '';

			$extra_fields = [];
			foreach ( $extra_cols as $col => $label ) {
				$value = isset( $row[ $col ] ) ? sanitize_text_field( trim( (string) $row[ $col ] ) ) : '';
				if ( '' === $value ) {
					continue;
				}
				$extra_fields[] = [ 'label' => sanitize_text_field( $label ), 'value' => $value ];
			}

			$outcome = self::upsert_student( $full_name, $email, $student_code, $college, $extra_fields, $update_existing );

			$results[ $outcome ]++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $results;
	}

	/**
	 * @return string 'created' or 'updated'
	 */
	private static function upsert_student( string $full_name, string $email, ?string $student_code, string $college, array $extra_fields, bool $update_existing ): string {
		$normalized    = StudentEmailIndexRepository::normalize( $email );
		$existing_ids  = $update_existing ? StudentEmailIndexRepository::get_student_ids_for_email( $normalized ) : [];
		$existing_id   = ! empty( $existing_ids ) ? (int) $existing_ids[0] : 0;

		if ( $existing_id > 0 ) {
			wp_update_post(
				[
					'ID'         => $existing_id,
					'post_title' => $full_name,
				]
			);
			$student_id = $existing_id;
			$outcome    = 'updated';
		} else {
			$student_id = wp_insert_post(
				[
					'post_type'   => StudentPostType::POST_TYPE,
					'post_title'  => $full_name,
					'post_status' => 'publish',
				],
				true
			);
			if ( is_wp_error( $student_id ) ) {
				return 'skipped';
			}
			$outcome = 'created';
		}

		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );

		if ( null !== $student_code ) {
			update_post_meta( $student_id, 'certiva_student_code', $student_code );
		}

		if ( '' !== $college ) {
			$term_id = CollegeTaxonomy::get_or_create_term_id( $college );
			if ( $term_id > 0 ) {
				wp_set_object_terms( $student_id, [ $term_id ], CollegeTaxonomy::TAXONOMY, false );
			}
		}

		if ( ! empty( $extra_fields ) ) {
			$current = get_post_meta( $student_id, 'certiva_extra_fields', true );
			$current = is_array( $current ) ? $current : [];
			update_post_meta( $student_id, 'certiva_extra_fields', self::merge_extra_fields( $current, $extra_fields ) );
		}

		return $outcome;
	}

	/**
	 * Merges imported extra fields into a student's existing ones, matching
	 * by label (case-insensitive) so a re-import updates values without
	 * discarding fields that were added manually and aren't in this CSV.
	 */
	private static function merge_extra_fields( array $existing, array $incoming ): array {
		foreach ( $incoming as $new_field ) {
			$matched = false;
			foreach ( $existing as $i => $field ) {
				if ( isset( $field['label'] ) && 0 === strcasecmp( $field['label'], $new_field['label'] ) ) {
					$existing[ $i ] = $new_field;
					$matched        = true;
					break;
				}
			}
			if ( ! $matched ) {
				$existing[] = $new_field;
			}
		}

		return $existing;
	}
}
