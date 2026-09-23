<?php
namespace Certiva\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema, defaults, and sanitization for a certificate template's
 * positioned/styled text fields.
 *
 * Each field is an associative array with keys:
 *   key        string  One of the standard placeholder keys, "extra:<label>"
 *                       for a student's custom placeholder, or "static" for
 *                       admin-typed literal text.
 *   label      string  Human-readable label shown in the designer UI.
 *   text       string  Literal text to render (only used when key === 'static').
 *   x, y       float   Position as a percentage (0-100) of the page width/height,
 *                       measured from the top-left corner.
 *   width      float   Text box width as a percentage (0-100) of the page width.
 *   font_size  int     Point size.
 *   font_family string One of the registered font families.
 *   color      string  Hex color, e.g. "#111111".
 *   align      string  left|center|right.
 *   bold       bool
 *   italic     bool
 */
final class FieldDefinitions {

	public const KEY_STUDENT_NAME   = 'student_name';
	public const KEY_COLLEGE        = 'college';
	public const KEY_EVENT_TITLE    = 'event_title';
	public const KEY_EVENT_DATE     = 'event_date';
	public const KEY_CERTIFICATE_ID = 'certificate_id';
	public const KEY_ISSUE_DATE     = 'issue_date';
	public const KEY_STATIC         = 'static';
	public const EXTRA_PREFIX       = 'extra:';

	public static function standard_keys(): array {
		return [
			self::KEY_STUDENT_NAME   => __( 'Student Name', 'certiva' ),
			self::KEY_COLLEGE        => __( 'College', 'certiva' ),
			self::KEY_EVENT_TITLE    => __( 'Event Title', 'certiva' ),
			self::KEY_EVENT_DATE     => __( 'Event Date', 'certiva' ),
			self::KEY_CERTIFICATE_ID => __( 'Certificate ID', 'certiva' ),
			self::KEY_ISSUE_DATE     => __( 'Issue Date', 'certiva' ),
		];
	}

	public static function font_families(): array {
		return [
			'NotoSans'       => __( 'Noto Sans (Latin/Unicode)', 'certiva' ),
			'LohitDevanagari' => __( 'Lohit Devanagari (Hindi)', 'certiva' ),
		];
	}

	public static function alignments(): array {
		return [ 'left', 'center', 'right' ];
	}

	/**
	 * Options offered in the admin "Add field" dropdown.
	 */
	public static function presets(): array {
		$presets = [];
		foreach ( self::standard_keys() as $key => $label ) {
			$presets[] = [ 'key' => $key, 'label' => $label ];
		}
		$presets[] = [ 'key' => self::EXTRA_PREFIX, 'label' => __( 'Custom Placeholder (extra field)…', 'certiva' ) ];
		$presets[] = [ 'key' => self::KEY_STATIC, 'label' => __( 'Static Text…', 'certiva' ) ];

		return $presets;
	}

	/**
	 * Sample placeholder values used when previewing a template with no
	 * real registration attached (e.g. from the template designer).
	 */
	public static function sample_values(): array {
		return [
			self::KEY_STUDENT_NAME   => __( 'Aanya Sharma', 'certiva' ),
			self::KEY_COLLEGE        => __( 'Sample College of Technology', 'certiva' ),
			self::KEY_EVENT_TITLE    => __( 'Certified WordPress Developer Exam', 'certiva' ),
			self::KEY_EVENT_DATE     => date_i18n( get_option( 'date_format' ) ),
			self::KEY_CERTIFICATE_ID => 'CERTIVA-SAMPLE01',
			self::KEY_ISSUE_DATE     => date_i18n( get_option( 'date_format' ) ),
		];
	}

	/**
	 * A sensible starting layout for a brand-new template.
	 */
	public static function defaults(): array {
		return [
			self::field( self::KEY_STUDENT_NAME, __( 'Student Name', 'certiva' ), 50, 45, 80, 30, 'NotoSans', '#1a1a1a', 'center', true, false ),
			self::field( self::KEY_EVENT_TITLE, __( 'Event Title', 'certiva' ), 50, 58, 80, 16, 'NotoSans', '#333333', 'center', false, false ),
			self::field( self::KEY_EVENT_DATE, __( 'Event Date', 'certiva' ), 50, 66, 80, 12, 'NotoSans', '#555555', 'center', false, false ),
			self::field( self::KEY_CERTIFICATE_ID, __( 'Certificate ID', 'certiva' ), 15, 92, 40, 9, 'NotoSans', '#777777', 'left', false, false ),
			self::field( self::KEY_ISSUE_DATE, __( 'Issue Date', 'certiva' ), 85, 92, 40, 9, 'NotoSans', '#777777', 'right', false, false ),
		];
	}

	private static function field( string $key, string $label, float $x, float $y, float $width, int $font_size, string $font_family, string $color, string $align, bool $bold, bool $italic, string $text = '' ): array {
		return [
			'key'         => $key,
			'label'       => $label,
			'text'        => $text,
			'x'           => $x,
			'y'           => $y,
			'width'       => $width,
			'font_size'   => $font_size,
			'font_family' => $font_family,
			'color'       => $color,
			'align'       => $align,
			'bold'        => $bold,
			'italic'      => $italic,
		];
	}

	/**
	 * Validates and clamps a decoded field array. Returns null if unusable.
	 */
	public static function sanitize_field( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$key = isset( $raw['key'] ) ? (string) $raw['key'] : '';

		$is_standard = array_key_exists( $key, self::standard_keys() );
		$is_extra    = str_starts_with( $key, self::EXTRA_PREFIX ) && strlen( $key ) > strlen( self::EXTRA_PREFIX );
		$is_static   = self::KEY_STATIC === $key;

		if ( ! $is_standard && ! $is_extra && ! $is_static ) {
			return null;
		}

		if ( $is_extra ) {
			$label_part = sanitize_text_field( substr( $key, strlen( self::EXTRA_PREFIX ) ) );
			$key        = self::EXTRA_PREFIX . $label_part;
		}

		$font_family = isset( $raw['font_family'] ) ? (string) $raw['font_family'] : 'NotoSans';
		if ( ! array_key_exists( $font_family, self::font_families() ) ) {
			$font_family = 'NotoSans';
		}

		$align = isset( $raw['align'] ) ? (string) $raw['align'] : 'left';
		if ( ! in_array( $align, self::alignments(), true ) ) {
			$align = 'left';
		}

		$color = isset( $raw['color'] ) ? (string) $raw['color'] : '#000000';
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			$color = '#000000';
		}

		return [
			'key'         => $key,
			'label'       => isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '',
			'text'        => $is_static && isset( $raw['text'] ) ? sanitize_text_field( (string) $raw['text'] ) : '',
			'x'           => self::clamp( $raw['x'] ?? 0, 0, 100 ),
			'y'           => self::clamp( $raw['y'] ?? 0, 0, 100 ),
			'width'       => self::clamp( $raw['width'] ?? 50, 1, 100 ),
			'font_size'   => (int) self::clamp( $raw['font_size'] ?? 12, 6, 200 ),
			'font_family' => $font_family,
			'color'       => $color,
			'align'       => $align,
			'bold'        => ! empty( $raw['bold'] ),
			'italic'      => ! empty( $raw['italic'] ),
		];
	}

	/**
	 * Decodes and sanitizes a JSON-encoded array of fields.
	 */
	public static function sanitize_json( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$clean = [];
		foreach ( $decoded as $raw_field ) {
			$field = self::sanitize_field( $raw_field );
			if ( null !== $field ) {
				$clean[] = $field;
			}
		}

		return $clean;
	}

	private static function clamp( $value, float $min, float $max ): float {
		$value = is_numeric( $value ) ? (float) $value : $min;
		return max( $min, min( $max, $value ) );
	}
}
