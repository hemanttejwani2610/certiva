<?php
namespace Certiva\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem access to Certiva's private storage directory.
 *
 * Certificates are never served by a direct, guessable URL: they live
 * outside any location a visitor would request, are protected with
 * deny-all rules for Apache/IIS as defense in depth, and are always
 * streamed to the browser by PHP after a download token is validated.
 */
final class PrivateStorage {

	public static function base_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'certiva-private';
	}

	public static function tmp_dir(): string {
		return trailingslashit( self::base_dir() ) . 'tmp';
	}

	/**
	 * Where uploaded student-import CSV files are staged between the upload
	 * and mapping steps. Protected by the same deny-all rules as the rest of
	 * the private storage tree.
	 */
	public static function imports_dir(): string {
		return trailingslashit( self::base_dir() ) . 'imports';
	}

	public static function ensure_protected(): void {
		$dirs = [ self::base_dir(), self::tmp_dir(), self::imports_dir() ];

		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
			}

			$webconfig = trailingslashit( $dir ) . 'web.config';
			if ( ! file_exists( $webconfig ) ) {
				file_put_contents(
					$webconfig,
					"<?xml version=\"1.0\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n"
				);
			}

			$index = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			}
		}
	}

	/**
	 * Builds a path (relative to the private base dir) for a registration's
	 * certificate, using a random filename component unrelated to the
	 * certificate ID. Stored relative so the site can be moved without
	 * breaking existing records.
	 */
	public static function relative_path_for( int $registration_id, string $random_token ): string {
		$sub = (string) ( $registration_id % 1000 );
		return $sub . '/' . $registration_id . '-' . $random_token . '.pdf';
	}

	public static function absolute_path( string $relative_path ): string {
		return trailingslashit( self::base_dir() ) . ltrim( $relative_path, '/' );
	}

	public static function save_relative( string $relative_path, string $binary ): bool {
		$absolute = self::absolute_path( $relative_path );
		wp_mkdir_p( dirname( $absolute ) );
		return false !== file_put_contents( $absolute, $binary );
	}

	public static function delete_relative( string $relative_path ): void {
		$absolute = self::absolute_path( $relative_path );
		if ( $relative_path && file_exists( $absolute ) ) {
			wp_delete_file( $absolute );
		}
	}

	public static function exists_relative( string $relative_path ): bool {
		return '' !== $relative_path && file_exists( self::absolute_path( $relative_path ) );
	}
}
