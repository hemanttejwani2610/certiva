<?php
namespace Certiva\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place to check "may manage Certiva data" so every admin action
 * (screens, meta box saves, AJAX handlers) enforces the same rule.
 */
final class Capabilities {

	/**
	 * The capability required for all Certiva admin actions.
	 * Filterable so site owners can delegate to a custom role.
	 */
	public static function required(): string {
		return (string) apply_filters( 'certiva_manage_capability', 'manage_options' );
	}

	public static function current_user_can_manage(): bool {
		return current_user_can( self::required() );
	}
}
