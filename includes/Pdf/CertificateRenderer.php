<?php
namespace Certiva\Pdf;

use Certiva\PostTypes\TemplatePostType;
use Certiva\Support\PrivateStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a certificate PDF from a template's page setup, background image,
 * and positioned/styled fields, using mPDF.
 *
 * mPDF was chosen over TCPDF/FPDF specifically because it supports OpenType
 * Layout (OTL) shaping, which is required for Devanagari (Hindi) conjuncts
 * and reordering to render correctly — TCPDF/FPDF only place glyphs
 * one-by-one and would produce broken Hindi text. Fonts are embedded
 * (subset) in the output PDF so recipients don't need the fonts installed.
 *
 * The Hindi font is specifically Lohit Devanagari rather than a newer Noto
 * Sans Devanagari build: mPDF's OTL/GSUB parser could not reliably parse
 * the GSUB/GPOS tables in current Noto Devanagari releases (it hit
 * unsupported lookup structures and ran away reading garbage coverage
 * ranges), while Lohit Devanagari's simpler tables — the same font family
 * mPDF bundles for other Indic scripts — parse and shape correctly.
 */
final class CertificateRenderer {

	private const FONT_MAP = [
		'NotoSans'        => 'notosanscertiva',
		'LohitDevanagari' => 'lohitdevanagaricertiva',
	];

	/**
	 * @param array{width_mm: float, height_mm: float, orientation: string} $page
	 * @param string                                                        $bg_path Absolute filesystem path to the background image, or ''.
	 * @param array                                                         $fields  Sanitized field definitions (see FieldDefinitions).
	 * @param array<string, string>                                         $values  key => already-plain-text value (will be escaped for HTML here).
	 */
	public static function render( array $page, string $bg_path, array $fields, array $values ): string {
		PrivateStorage::ensure_protected();

		$mpdf = self::make_instance( $page );

		$html = self::build_html( $page, $bg_path, $fields, $values );

		$mpdf->WriteHTML( $html );

		return $mpdf->Output( '', 'S' );
	}

	private static function make_instance( array $page ): \Mpdf\Mpdf {
		$default_config = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
		$font_dirs      = $default_config['fontDir'];

		$default_font_config = ( new \Mpdf\Config\FontVariables() )->getDefaults();
		$font_data            = $default_font_config['fontdata'];

		$tmp_dir = PrivateStorage::tmp_dir();
		wp_mkdir_p( $tmp_dir );

		return new \Mpdf\Mpdf(
			[
				'mode'          => 'utf-8',
				'format'        => [ $page['width_mm'], $page['height_mm'] ],
				'margin_left'   => 0,
				'margin_right'  => 0,
				'margin_top'    => 0,
				'margin_bottom' => 0,
				'margin_header' => 0,
				'margin_footer' => 0,
				'tempDir'       => $tmp_dir,
				'fontDir'       => array_merge( $font_dirs, [ CERTIVA_DIR . 'includes/Pdf/fonts' ] ),
				'fontdata'      => $font_data + [
					// No useOTL: Latin text needs no complex shaping, and this
					// avoids a GPOS lookup format some Noto Sans releases ship
					// that mPDF's font subsetter cannot parse.
					'notosanscertiva'        => [
						'R' => 'NotoSans-Regular.ttf',
						'B' => 'NotoSans-Bold.ttf',
					],
					// useOTL is required here: Devanagari conjuncts and
					// reordering only render correctly with OpenType Layout
					// shaping enabled. Lohit Devanagari has one weight only.
					'lohitdevanagaricertiva' => [
						'R'      => 'LohitDevanagari-Regular.ttf',
						'useOTL' => 0xFF,
					],
				],
				'default_font'  => 'notosanscertiva',
			]
		);
	}

	private static function build_html( array $page, string $bg_path, array $fields, array $values ): string {
		$width  = (float) $page['width_mm'];
		$height = (float) $page['height_mm'];

		$html = '<html><head><style>
			@page { margin: 0; }
			html, body { margin: 0; padding: 0; }
			.certiva-bg { position: fixed; top: 0; left: 0; width: ' . $width . 'mm; height: ' . $height . 'mm; z-index: 0; }
			.certiva-field { position: absolute; z-index: 1; white-space: pre-wrap; }
		</style></head><body>';

		if ( $bg_path && file_exists( $bg_path ) ) {
			$html .= '<div class="certiva-bg"><img src="' . esc_attr( $bg_path ) . '" style="width:100%;height:100%;" /></div>';
		}

		foreach ( $fields as $field ) {
			$text = self::resolve_text( $field, $values );
			if ( '' === $text ) {
				continue;
			}

			$left      = ( (float) $field['x'] / 100 ) * $width;
			$top       = ( (float) $field['y'] / 100 ) * $height;
			$box_width = ( (float) $field['width'] / 100 ) * $width;
			$font      = self::FONT_MAP[ $field['font_family'] ] ?? self::FONT_MAP['NotoSans'];

			$style = sprintf(
				'left:%smm;top:%smm;width:%smm;font-family:%s;font-size:%dpt;color:%s;text-align:%s;font-weight:%s;font-style:%s;',
				self::num( $left ),
				self::num( $top ),
				self::num( $box_width ),
				esc_attr( $font ),
				(int) $field['font_size'],
				esc_attr( $field['color'] ),
				esc_attr( $field['align'] ),
				! empty( $field['bold'] ) ? 'bold' : 'normal',
				! empty( $field['italic'] ) ? 'italic' : 'normal'
			);

			$html .= '<div class="certiva-field" style="' . $style . '">' . nl2br( esc_html( $text ) ) . '</div>';
		}

		$html .= '</body></html>';

		return $html;
	}

	private static function resolve_text( array $field, array $values ): string {
		if ( FieldDefinitions::KEY_STATIC === $field['key'] ) {
			return (string) ( $field['text'] ?? '' );
		}

		return (string) ( $values[ $field['key'] ] ?? '' );
	}

	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' ) ?: '0';
	}
}
