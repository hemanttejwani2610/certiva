<?php
namespace Certiva;

use Certiva\Data\Schema;
use Certiva\Data\DownloadTokensRepository;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Admin\Menu;
use Certiva\Admin\SettingsPage;
use Certiva\Admin\RegistrationsPage;
use Certiva\Admin\RegistrationActions;
use Certiva\Admin\CertificateAdminStream;
use Certiva\Admin\TemplateAjax;
use Certiva\Admin\StudentImportPage;
use Certiva\Shortcode\RequestShortcode;
use Certiva\Public\RequestController;
use Certiva\Public\DownloadController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up every Certiva component. Individual component register()
 * methods only attach hooks — no queries or option reads happen here.
 */
final class Plugin {

	public const CRON_CLEANUP_TOKENS = 'certiva_cleanup_tokens';

	public static function register(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'load_textdomain' ] );

		if ( is_admin() ) {
			add_action( 'init', [ Schema::class, 'maybe_upgrade' ], 1 );
		}

		StudentPostType::register();
		EventPostType::register();
		TemplatePostType::register();

		Menu::register();
		SettingsPage::register();
		RegistrationsPage::register();
		RegistrationActions::register();
		CertificateAdminStream::register();
		TemplateAjax::register();
		StudentImportPage::register();

		RequestShortcode::register();
		RequestController::register();
		DownloadController::register();

		add_action( self::CRON_CLEANUP_TOKENS, [ DownloadTokensRepository::class, 'purge_expired' ] );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'certiva', false, dirname( CERTIVA_BASENAME ) . '/languages' );
	}
}
