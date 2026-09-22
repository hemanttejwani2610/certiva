<?php
namespace Certiva;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( Plugin::CRON_CLEANUP_TOKENS );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Plugin::CRON_CLEANUP_TOKENS );
		}

		flush_rewrite_rules();
	}
}
