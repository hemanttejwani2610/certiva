<?php
namespace Certiva\Admin;

use Certiva\Import\StudentImporter;
use Certiva\PostTypes\StudentPostType;
use Certiva\Support\Capabilities;
use Certiva\Support\PrivateStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Import Students" admin screen: upload a CSV, map its columns to
 * student fields, then import. A thin controller over StudentImporter.
 */
final class StudentImportPage {

	public const MENU_SLUG = 'certiva-import-students';

	private const TRANSIENT_PREFIX = 'certiva_import_';
	private const RESULT_PREFIX    = 'certiva_import_result_';
	private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB.

	public static function register(): void {
		add_action( 'admin_post_certiva_import_upload', [ __CLASS__, 'handle_upload' ] );
		add_action( 'admin_post_certiva_import_process', [ __CLASS__, 'handle_process' ] );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_list_screen_button' ], 10, 2 );
	}

	/**
	 * Adds an "Import CSV" button above the Students list table.
	 */
	public static function render_list_screen_button( string $post_type, string $which ): void {
		if ( 'top' !== $which || StudentPostType::POST_TYPE !== $post_type || ! Capabilities::current_user_can_manage() ) {
			return;
		}

		printf(
			'<a href="%s" class="button" style="margin-left:6px;">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Import CSV', 'certiva' )
		);
	}

	public static function handle_upload(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'certiva' ), 403 );
		}

		check_admin_referer( 'certiva_import_upload' );

		$redirect_base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== ( $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'upload_failed', $redirect_base ) );
			exit;
		}

		$tmp_name = $_FILES['csv_file']['tmp_name'];
		$size     = (int) $_FILES['csv_file']['size'];
		$name     = sanitize_file_name( (string) $_FILES['csv_file']['name'] );
		$ext      = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'not_csv', $redirect_base ) );
			exit;
		}

		if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'too_large', $redirect_base ) );
			exit;
		}

		if ( ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'upload_failed', $redirect_base ) );
			exit;
		}

		PrivateStorage::ensure_protected();

		// Lowercase hex only: this value round-trips through sanitize_key()
		// (used when reading it back from $_GET/$_POST), which lowercases
		// its input — a mixed-case ID would silently fail to match.
		$import_id = bin2hex( random_bytes( 16 ) );
		$dest      = trailingslashit( PrivateStorage::imports_dir() ) . $import_id . '.csv';

		if ( ! move_uploaded_file( $tmp_name, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'upload_failed', $redirect_base ) );
			exit;
		}

		set_transient(
			self::TRANSIENT_PREFIX . $import_id,
			[
				'path'    => $dest,
				'user_id' => get_current_user_id(),
			],
			30 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::MENU_SLUG,
					'step'      => 'map',
					'import_id' => $import_id,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_process(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'certiva' ), 403 );
		}

		$import_id = isset( $_POST['import_id'] ) ? sanitize_key( wp_unslash( $_POST['import_id'] ) ) : '';
		check_admin_referer( 'certiva_import_process_' . $import_id );

		$import = self::get_pending_import( $import_id );
		$redirect_base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( ! $import ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', 'expired', $redirect_base ) );
			exit;
		}

		$targets = isset( $_POST['column_target'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['column_target'] ) ) : [];
		$labels  = isset( $_POST['column_label'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['column_label'] ) ) : [];
		$update_existing = ! empty( $_POST['update_existing'] );

		$allowed_targets = array_keys( StudentImporter::target_labels() );
		foreach ( $targets as $i => $target ) {
			if ( ! in_array( $target, $allowed_targets, true ) ) {
				$targets[ $i ] = StudentImporter::TARGET_SKIP;
			}
		}

		$results = StudentImporter::process( $import['path'], $targets, $labels, $update_existing );

		delete_transient( self::TRANSIENT_PREFIX . $import_id );
		if ( file_exists( $import['path'] ) ) {
			wp_delete_file( $import['path'] );
		}

		if ( is_wp_error( $results ) ) {
			wp_safe_redirect( add_query_arg( 'certiva_import_error', $results->get_error_code(), $redirect_base ) );
			exit;
		}

		$result_key = self::RESULT_PREFIX . wp_generate_password( 16, false, false );
		set_transient( $result_key, $results, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'result' => $result_key ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function get_pending_import( string $import_id ): ?array {
		if ( '' === $import_id ) {
			return null;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $import_id );
		if ( ! is_array( $data ) || empty( $data['path'] ) || ! file_exists( $data['path'] ) ) {
			return null;
		}

		if ( (int) $data['user_id'] !== get_current_user_id() ) {
			return null;
		}

		return $data;
	}

	public static function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'certiva' ), 403 );
		}

		if ( ! empty( $_GET['result'] ) ) {
			$result_key = sanitize_text_field( wp_unslash( $_GET['result'] ) );
			$results    = 0 === strpos( $result_key, self::RESULT_PREFIX ) ? get_transient( $result_key ) : false;
			if ( is_array( $results ) ) {
				delete_transient( $result_key );
				require CERTIVA_DIR . 'templates/admin/student-import-results.php';
				return;
			}
		}

		$import_id = isset( $_GET['import_id'] ) ? sanitize_key( wp_unslash( $_GET['import_id'] ) ) : '';
		$step      = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		if ( 'map' === $step && $import_id ) {
			$import = self::get_pending_import( $import_id );
			if ( $import ) {
				$preview = StudentImporter::read_headers_and_preview( $import['path'] );
				if ( ! is_wp_error( $preview ) ) {
					$target_labels = StudentImporter::target_labels();
					require CERTIVA_DIR . 'templates/admin/student-import-map.php';
					return;
				}
			}
		}

		require CERTIVA_DIR . 'templates/admin/student-import-upload.php';
	}
}
