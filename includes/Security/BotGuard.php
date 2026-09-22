<?php
namespace Certiva\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight, no-external-service bot-abuse safeguards for the public
 * certificate-request form: a honeypot field and a minimum-time-to-submit
 * check. Both fail "closed but silent" — a suspected bot is told the same
 * neutral success message as everyone else, so probing the form can't
 * distinguish a bot rejection from a real (non-)match.
 */
final class BotGuard {

	public const HONEYPOT_FIELD = 'certiva_website';
	public const TIMESTAMP_FIELD = 'certiva_ts';

	private const MIN_SECONDS_TO_SUBMIT = 3;

	public static function honeypot_field_html(): void {
		printf(
			'<div class="certiva-hp-field" aria-hidden="true"><label>%1$s<input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off" /></label></div>',
			esc_html__( 'Leave this field empty', 'certiva' ),
			esc_attr( self::HONEYPOT_FIELD )
		);
	}

	public static function timestamp_field_html(): void {
		printf(
			'<input type="hidden" name="%s" value="%d" />',
			esc_attr( self::TIMESTAMP_FIELD ),
			time()
		);
	}

	/**
	 * True if the submission looks automated (honeypot filled, or
	 * submitted implausibly fast after the form was rendered).
	 */
	public static function looks_automated(): bool {
		$honeypot = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? trim( (string) wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
		if ( '' !== $honeypot ) {
			return true;
		}

		$rendered_at = isset( $_POST[ self::TIMESTAMP_FIELD ] ) ? absint( $_POST[ self::TIMESTAMP_FIELD ] ) : 0;
		if ( 0 === $rendered_at ) {
			return true;
		}

		$elapsed = time() - $rendered_at;

		return $elapsed < self::MIN_SECONDS_TO_SUBMIT || $elapsed > DAY_IN_SECONDS;
	}
}
