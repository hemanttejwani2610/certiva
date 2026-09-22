<?php
namespace Certiva\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades Certiva's custom database tables.
 */
final class Schema {

	public const DB_VERSION_OPTION = 'certiva_db_version';
	public const DB_VERSION        = 3;

	public static function table_registrations(): string {
		global $wpdb;
		return $wpdb->prefix . 'certiva_registrations';
	}

	public static function table_student_emails(): string {
		global $wpdb;
		return $wpdb->prefix . 'certiva_student_emails';
	}

	public static function table_download_tokens(): string {
		global $wpdb;
		return $wpdb->prefix . 'certiva_download_tokens';
	}

	/**
	 * Runs on activation and on version bumps. Safe to call repeatedly.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		self::install();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$registrations = self::table_registrations();
		$student_emails = self::table_student_emails();
		$download_tokens = self::table_download_tokens();

		$sql = "CREATE TABLE {$registrations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			template_id bigint(20) unsigned DEFAULT NULL,
			eligible tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			certificate_id varchar(64) DEFAULT NULL,
			pdf_path varchar(255) DEFAULT NULL,
			issued_at datetime DEFAULT NULL,
			regenerated_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY student_event (student_id, event_id),
			UNIQUE KEY certificate_id (certificate_id),
			KEY event_id (event_id),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$student_emails} (
			student_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			PRIMARY KEY  (student_id),
			KEY email (email)
		) {$charset_collate};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$download_tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			registration_id bigint(20) unsigned NOT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			use_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY registration_id (registration_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Drops all Certiva tables. Only ever called from uninstall.php, and only
	 * when the admin explicitly opted in to deleting data on uninstall.
	 */
	public static function drop_all(): void {
		global $wpdb;

		// Table names are hardcoded/prefixed, not user input — no prepare() needed.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_download_tokens() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_registrations() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_student_emails() );

		delete_option( self::DB_VERSION_OPTION );
	}
}
