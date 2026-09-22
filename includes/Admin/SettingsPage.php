<?php
namespace Certiva\Admin;

use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certiva settings: download link lifetime, rate limits, outgoing mail
 * identity, and the explicit uninstall data-deletion opt-in.
 */
final class SettingsPage {

	public const OPTION = 'certiva_settings';

	public static function register(): void {
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function defaults(): array {
		return [
			'download_link_expiry_hours'  => 48,
			'rate_limit_ip_max'           => 5,
			'rate_limit_ip_window_min'    => 10,
			'rate_limit_email_max'        => 3,
			'rate_limit_email_window_min' => 30,
			'email_from_name'             => get_bloginfo( 'name' ),
			'email_from_address'          => get_option( 'admin_email' ),
			'delete_data_on_uninstall'    => false,
		];
	}

	public static function get( string $key ) {
		$options = wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
		return $options[ $key ] ?? null;
	}

	public static function register_settings(): void {
		register_setting(
			'certiva_settings_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : [];

		$clean = [
			'download_link_expiry_hours'  => max( 1, min( 720, absint( $input['download_link_expiry_hours'] ?? $defaults['download_link_expiry_hours'] ) ) ),
			'rate_limit_ip_max'           => max( 1, min( 1000, absint( $input['rate_limit_ip_max'] ?? $defaults['rate_limit_ip_max'] ) ) ),
			'rate_limit_ip_window_min'    => max( 1, min( 1440, absint( $input['rate_limit_ip_window_min'] ?? $defaults['rate_limit_ip_window_min'] ) ) ),
			'rate_limit_email_max'        => max( 1, min( 1000, absint( $input['rate_limit_email_max'] ?? $defaults['rate_limit_email_max'] ) ) ),
			'rate_limit_email_window_min' => max( 1, min( 1440, absint( $input['rate_limit_email_window_min'] ?? $defaults['rate_limit_email_window_min'] ) ) ),
			'email_from_name'             => sanitize_text_field( $input['email_from_name'] ?? $defaults['email_from_name'] ),
			'email_from_address'          => is_email( $input['email_from_address'] ?? '' ) ? sanitize_email( $input['email_from_address'] ) : $defaults['email_from_address'],
			'delete_data_on_uninstall'    => ! empty( $input['delete_data_on_uninstall'] ),
		];

		return $clean;
	}

	public static function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'certiva' ), 403 );
		}

		$options = wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );

		require CERTIVA_DIR . 'templates/admin/settings-page.php';
	}
}
