<?php
namespace Certiva\Import;

use Certiva\Data\RegistrationsRepository;
use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\TemplatePostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk-registers existing students for a single, pre-selected event from a
 * CSV file — e.g. uploading a pass list to register and mark eligible a
 * whole batch of students after grading an exam.
 *
 * This never creates student records: a row whose email doesn't match an
 * existing student is skipped with a reason. Use StudentImporter first to
 * bring in students you haven't created yet.
 *
 * A row whose (student, event) pair is already registered updates that
 * existing registration's eligibility/template override rather than
 * erroring or creating a duplicate — consistent with how every other part
 * of Certiva treats "already registered" as safe to re-apply.
 */
final class RegistrationImporter {

	public const TARGET_SKIP               = 'skip';
	public const TARGET_EMAIL              = 'email';
	public const TARGET_ELIGIBLE           = 'eligible';
	public const TARGET_TEMPLATE_OVERRIDE  = 'template_override';

	public const MAX_ROWS = 5000;

	public static function target_labels(): array {
		return [
			self::TARGET_SKIP              => __( '— Do not use —', 'certiva' ),
			self::TARGET_EMAIL             => __( 'Student Email (required)', 'certiva' ),
			self::TARGET_ELIGIBLE          => __( 'Eligible (yes/no)', 'certiva' ),
			self::TARGET_TEMPLATE_OVERRIDE => __( 'Template Override (name or ID)', 'certiva' ),
		];
	}

	/**
	 * @return array{headers: string[], preview: string[][], total_data_rows: int}|\WP_Error
	 */
	public static function read_headers_and_preview( string $path, int $preview_rows = 5 ) {
		return CsvReader::read_headers_and_preview( $path, $preview_rows );
	}

	/**
	 * @param array $column_targets Indexed by CSV column position => one of the TARGET_* constants.
	 *
	 * @return array{registered:int, updated:int, skipped:int, skipped_reasons:string[], warnings:string[], total:int}|\WP_Error
	 */
	public static function process( string $path, int $event_id, array $column_targets ) {
		$email_col = array_search( self::TARGET_EMAIL, $column_targets, true );
		if ( false === $email_col ) {
			return new \WP_Error( 'certiva_import_missing_mapping', __( 'You must map a column to Student Email.', 'certiva' ) );
		}

		$eligible_col = array_search( self::TARGET_ELIGIBLE, $column_targets, true );
		$template_col = array_search( self::TARGET_TEMPLATE_OVERRIDE, $column_targets, true );

		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return new \WP_Error( 'certiva_import_unreadable', __( 'The uploaded file could not be read.', 'certiva' ) );
		}

		fgetcsv( $handle ); // Skip header row.

		$template_map = self::build_template_lookup();

		$results = [
			'registered'      => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'skipped_reasons' => [],
			'warnings'        => [],
			'total'           => 0,
		];

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$row_number = 1;
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row_number++;

			if ( 1 === count( $row ) && null === $row[0] ) {
				continue; // Blank line.
			}

			$results['total']++;

			if ( $results['total'] > self::MAX_ROWS ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf(
					/* translators: 1: row number, 2: row limit */
					__( 'Row %1$d: skipped — exceeded the %2$d row import limit for a single file.', 'certiva' ),
					$row_number,
					self::MAX_ROWS
				);
				continue;
			}

			$email = isset( $row[ $email_col ] ) ? sanitize_email( trim( (string) $row[ $email_col ] ) ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf( /* translators: %d: row number */ __( 'Row %d: skipped — missing or invalid email address.', 'certiva' ), $row_number );
				continue;
			}

			$normalized  = StudentEmailIndexRepository::normalize( $email );
			$student_ids = StudentEmailIndexRepository::get_student_ids_for_email( $normalized );

			if ( empty( $student_ids ) ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf(
					/* translators: 1: row number, 2: email address */
					__( 'Row %1$d: skipped — no student found with email %2$s. Import the student first.', 'certiva' ),
					$row_number,
					$email
				);
				continue;
			}

			$student_id = (int) $student_ids[0];

			$eligible = false;
			if ( false !== $eligible_col && isset( $row[ $eligible_col ] ) ) {
				$eligible = self::parse_bool( (string) $row[ $eligible_col ] );
			}

			$template_override = null;
			if ( false !== $template_col && isset( $row[ $template_col ] ) ) {
				$raw = trim( (string) $row[ $template_col ] );
				if ( '' !== $raw ) {
					$template_override = self::resolve_template_id( $raw, $template_map );
					if ( null === $template_override ) {
						$results['warnings'][] = sprintf(
							/* translators: 1: row number, 2: template name/id from the CSV */
							__( 'Row %1$d: template "%2$s" not found — used the event\'s default template instead.', 'certiva' ),
							$row_number,
							$raw
						);
					}
				}
			}

			$existing = RegistrationsRepository::find_by_student_and_event( $student_id, $event_id );

			if ( $existing ) {
				RegistrationsRepository::update_eligibility( (int) $existing->id, $eligible );
				if ( null !== $template_override ) {
					RegistrationsRepository::update_template_override( (int) $existing->id, $template_override );
				}
				$results['updated']++;
				continue;
			}

			$new_id = RegistrationsRepository::create( $student_id, $event_id, $template_override, $eligible );
			if ( is_wp_error( $new_id ) ) {
				$results['skipped']++;
				$results['skipped_reasons'][] = sprintf(
					/* translators: 1: row number, 2: error message */
					__( 'Row %1$d: skipped — %2$s', 'certiva' ),
					$row_number,
					$new_id->get_error_message()
				);
				continue;
			}

			$results['registered']++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $results;
	}

	private static function parse_bool( string $value ): bool {
		$normalized = strtolower( trim( $value ) );
		return in_array( $normalized, [ '1', 'true', 'yes', 'y', 'eligible' ], true );
	}

	/**
	 * @return array<string, int> lowercased template title => template ID
	 */
	private static function build_template_lookup(): array {
		$templates = get_posts(
			[
				'post_type'      => TemplatePostType::POST_TYPE,
				'posts_per_page' => 500,
				'post_status'    => 'publish',
			]
		);

		$map = [];
		foreach ( $templates as $template ) {
			$map[ strtolower( $template->post_title ) ] = $template->ID;
		}

		return $map;
	}

	private static function resolve_template_id( string $raw, array $template_map ): ?int {
		if ( ctype_digit( $raw ) ) {
			$id = (int) $raw;
			return TemplatePostType::POST_TYPE === get_post_type( $id ) ? $id : null;
		}

		return $template_map[ strtolower( $raw ) ] ?? null;
	}
}
