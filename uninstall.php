<?php
/**
 * Uninstall handler for Certiva.
 *
 * By default, uninstalling the plugin leaves all data in place — students,
 * events, templates, registrations, and generated certificate files are
 * kept in case the plugin is reinstalled. Data is only deleted if an admin
 * explicitly checked "Permanently delete all Certiva data" on the Certiva
 * Settings screen before deleting the plugin.
 *
 * This file is intentionally self-contained (no dependency on the plugin's
 * class autoloader) since it can run in a minimal WordPress uninstall
 * context.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$certiva_settings = get_option( 'certiva_settings', [] );

if ( empty( $certiva_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Delete posts (students, events, templates) and their meta/attachments references.
foreach ( [ 'certiva_student', 'certiva_event', 'certiva_template' ] as $post_type ) {
	$post_ids = get_posts(
		[
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'suppress_filters' => true,
		]
	);

	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

// Drop custom tables.
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'certiva_download_tokens' );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'certiva_registrations' );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'certiva_student_emails' );

// Delete generated certificate files.
$uploads   = wp_upload_dir();
$base_dir  = trailingslashit( $uploads['basedir'] ) . 'certiva-private';

if ( is_dir( $base_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $base_dir, true );
	}
}

// Delete options.
delete_option( 'certiva_settings' );
delete_option( 'certiva_db_version' );

// Unschedule cron, in case deactivation was skipped.
$timestamp = wp_next_scheduled( 'certiva_cleanup_tokens' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'certiva_cleanup_tokens' );
}
