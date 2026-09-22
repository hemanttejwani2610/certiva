<?php
namespace Certiva\Shortcode;

use Certiva\Public\RequestController;
use Certiva\Security\BotGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [certiva_certificate_request] shortcode: a responsive, accessible
 * form with a single required email field.
 */
final class RequestShortcode {

	public const TAG = 'certiva_certificate_request';

	public static function register(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ] );
	}

	public static function maybe_enqueue_assets(): void {
		if ( is_singular() && has_shortcode( get_post()->post_content ?? '', self::TAG ) ) {
			wp_enqueue_style( 'certiva-public', CERTIVA_URL . 'assets/public/css/public.css', [], CERTIVA_VERSION );
		}
	}

	public static function render( $atts = [] ): string {
		wp_enqueue_style( 'certiva-public', CERTIVA_URL . 'assets/public/css/public.css', [], CERTIVA_VERSION );

		$flash          = RequestController::consume_flash();
		$current_url    = self::current_url_without_notice();
		$form_action    = admin_url( 'admin-post.php' );
		$nonce_field    = self::nonce_field();

		ob_start();
		require CERTIVA_DIR . 'templates/public/request-form.php';
		return ob_get_clean();
	}

	private static function nonce_field(): string {
		ob_start();
		wp_nonce_field( RequestController::NONCE_ACTION, RequestController::NONCE_FIELD );
		return ob_get_clean();
	}

	private static function current_url_without_notice(): string {
		$url = home_url( add_query_arg( [] ) );
		return remove_query_arg( 'certiva_notice', $url );
	}
}
