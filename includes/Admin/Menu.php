<?php
namespace Certiva\Admin;

use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Certiva admin menu and routes its custom (non-CPT) pages.
 */
final class Menu {

	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function add_menu(): void {
		$cap = Capabilities::required();

		add_menu_page(
			__( 'Certiva', 'certiva' ),
			__( 'Certiva', 'certiva' ),
			$cap,
			'certiva',
			[ RegistrationsPage::class, 'render' ],
			'dashicons-awards',
			26
		);

		// Note: Students/Events/Templates submenu items are NOT added here —
		// registering each post type with 'show_in_menu' => 'certiva' already
		// makes WordPress core add them automatically. Adding them again here
		// would duplicate every one of those entries in the menu.

		add_submenu_page(
			'certiva',
			__( 'Registrations', 'certiva' ),
			__( 'Registrations', 'certiva' ),
			$cap,
			'certiva',
			[ RegistrationsPage::class, 'render' ]
		);

		add_submenu_page(
			'certiva',
			__( 'Import Students', 'certiva' ),
			__( 'Import Students', 'certiva' ),
			$cap,
			StudentImportPage::MENU_SLUG,
			[ StudentImportPage::class, 'render' ]
		);

		add_submenu_page(
			'certiva',
			__( 'Certiva Settings', 'certiva' ),
			__( 'Settings', 'certiva' ),
			$cap,
			'certiva-settings',
			[ SettingsPage::class, 'render' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$certiva_screens = [
			StudentPostType::POST_TYPE,
			EventPostType::POST_TYPE,
			TemplatePostType::POST_TYPE,
		];

		$is_certiva_admin_page = false !== strpos( (string) $screen->id, 'certiva' );
		$is_certiva_post_type  = in_array( $screen->post_type ?? '', $certiva_screens, true );

		if ( ! $is_certiva_admin_page && ! $is_certiva_post_type ) {
			return;
		}

		wp_enqueue_style( 'certiva-admin', CERTIVA_URL . 'assets/admin/css/admin.css', [], CERTIVA_VERSION );
		wp_enqueue_script( 'certiva-admin', CERTIVA_URL . 'assets/admin/js/admin.js', [ 'jquery' ], CERTIVA_VERSION, true );

		wp_localize_script(
			'certiva-admin',
			'certivaAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'certiva_admin_action' ),
				'i18n'    => [
					'confirmDelete' => __( 'Remove this registration? This cannot be undone.', 'certiva' ),
					'working'       => __( 'Working…', 'certiva' ),
					'error'         => __( 'Something went wrong. Please try again.', 'certiva' ),
				],
			]
		);
	}
}
