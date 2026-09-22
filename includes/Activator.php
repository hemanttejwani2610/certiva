<?php
namespace Certiva;

use Certiva\Data\Schema;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Support\PrivateStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		Schema::maybe_upgrade();

		StudentPostType::register_post_type();
		EventPostType::register_post_type();
		TemplatePostType::register_post_type();
		flush_rewrite_rules();

		PrivateStorage::ensure_protected();

		if ( ! wp_next_scheduled( Plugin::CRON_CLEANUP_TOKENS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Plugin::CRON_CLEANUP_TOKENS );
		}
	}
}
