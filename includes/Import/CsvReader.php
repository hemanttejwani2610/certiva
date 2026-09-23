<?php
namespace Certiva\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared CSV header/preview reading, used by both StudentImporter and
 * RegistrationImporter so their mapping screens behave identically.
 */
final class CsvReader {

	/**
	 * Reads the header row and a small preview of data rows, without
	 * loading (or processing) the whole file.
	 *
	 * @return array{headers: string[], preview: string[][], total_data_rows: int}|\WP_Error
	 */
	public static function read_headers_and_preview( string $path, int $preview_rows = 5 ) {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return new \WP_Error( 'certiva_import_unreadable', __( 'The uploaded file could not be read.', 'certiva' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'certiva_import_empty', __( 'The file appears to be empty.', 'certiva' ) );
		}
		$headers = self::strip_bom_from_first_cell( $headers );
		$headers = array_map( 'trim', $headers );

		$preview = [];
		$total   = 0;
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue; // Blank line.
			}
			$total++;
			if ( count( $preview ) < $preview_rows ) {
				$preview[] = $row;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [
			'headers'         => $headers,
			'preview'         => $preview,
			'total_data_rows' => $total,
		];
	}

	private static function strip_bom_from_first_cell( array $headers ): array {
		if ( isset( $headers[0] ) ) {
			$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		}
		return $headers;
	}
}
