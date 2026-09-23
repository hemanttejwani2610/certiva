<?php
/**
 * Tests for using a PDF (instead of an image) as a certificate template's
 * background.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\Pdf\CertificateRenderer;
use Certiva\Pdf\CertificateService;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\TemplatePostType;

class Certiva_Pdf_Background_Test extends WP_UnitTestCase {

	/** @var string[] */
	private $temp_files = [];

	public function tear_down() {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = [];
		parent::tear_down();
	}

	/**
	 * Renders a tiny, genuinely valid one-page PDF via mPDF to use as a
	 * background fixture, rather than hand-crafting PDF bytes.
	 */
	private function make_source_pdf(): string {
		$defaultConfig     = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
		$defaultFontConfig = ( new \Mpdf\Config\FontVariables() )->getDefaults();

		$mpdf = new \Mpdf\Mpdf(
			[
				'mode'    => 'utf-8',
				'format'  => [ 210, 297 ],
				'tempDir' => get_temp_dir(),
			]
		);
		$mpdf->WriteHTML( '<div style="background:#ffcc00;width:100%;height:100%;">Source PDF background</div>' );
		$binary = $mpdf->Output( '', 'S' );

		$path = tempnam( sys_get_temp_dir(), 'certiva_bg_src_' ) . '.pdf';
		file_put_contents( $path, $binary );
		$this->temp_files[] = $path;

		return $path;
	}

	private function make_pdf_attachment(): int {
		return self::factory()->attachment->create_upload_object( $this->make_source_pdf() );
	}

	public function test_pdf_attachment_uploads_as_application_pdf_mime() {
		$attachment_id = $this->make_pdf_attachment();
		$this->assertSame( 'application/pdf', get_post_mime_type( $attachment_id ) );
	}

	public function test_renderer_accepts_a_pdf_background_directly() {
		$bg_path = $this->make_source_pdf();

		$pdf = CertificateRenderer::render(
			[ 'width_mm' => 297.0, 'height_mm' => 210.0, 'orientation' => 'L' ],
			$bg_path,
			[
				[ 'key' => 'student_name', 'label' => 'Student Name', 'text' => '', 'x' => 50, 'y' => 50, 'width' => 80, 'font_size' => 20, 'font_family' => 'NotoSans', 'color' => '#000000', 'align' => 'center', 'bold' => true, 'italic' => false ],
			],
			[ 'student_name' => 'Aanya Sharma' ]
		);

		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_template_with_pdf_background_generates_a_certificate() {
		$student_id  = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Aanya Sharma' ] );
		$bg_id       = $this->make_pdf_attachment();
		$template_id = self::factory()->post->create( [ 'post_type' => TemplatePostType::POST_TYPE, 'post_status' => 'publish' ] );

		update_post_meta( $template_id, 'certiva_page_size', TemplatePostType::PAGE_SIZE_A4 );
		update_post_meta( $template_id, 'certiva_orientation', TemplatePostType::ORIENTATION_LANDSCAPE );
		update_post_meta( $template_id, 'certiva_bg_attachment_id', $bg_id );
		update_post_meta(
			$template_id,
			'certiva_fields_json',
			wp_json_encode(
				[
					[ 'key' => 'student_name', 'label' => 'Student Name', 'text' => '', 'x' => 50, 'y' => 50, 'width' => 80, 'font_size' => 20, 'font_family' => 'NotoSans', 'color' => '#000000', 'align' => 'center', 'bold' => true, 'italic' => false ],
				]
			)
		);

		$event_id = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );
		update_post_meta( $event_id, 'certiva_default_template_id', $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$result           = CertificateService::generate( $registration_id );

		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$pdf = CertificateService::get_issued_pdf( $registration_id );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf );
	}

	public function test_a_corrupt_pdf_background_fails_gracefully_not_fatally() {
		$student_id  = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		$template_id = self::factory()->post->create( [ 'post_type' => TemplatePostType::POST_TYPE, 'post_status' => 'publish' ] );

		$corrupt_path = tempnam( sys_get_temp_dir(), 'certiva_bad_pdf_' ) . '.pdf';
		file_put_contents( $corrupt_path, 'this is not a real pdf file' );
		$this->temp_files[] = $corrupt_path;
		$bg_id = self::factory()->attachment->create_upload_object( $corrupt_path );

		update_post_meta( $template_id, 'certiva_page_size', TemplatePostType::PAGE_SIZE_A4 );
		update_post_meta( $template_id, 'certiva_orientation', TemplatePostType::ORIENTATION_LANDSCAPE );
		update_post_meta( $template_id, 'certiva_bg_attachment_id', $bg_id );
		update_post_meta(
			$template_id,
			'certiva_fields_json',
			wp_json_encode( [ [ 'key' => 'student_name', 'label' => 'Student Name', 'text' => '', 'x' => 50, 'y' => 50, 'width' => 80, 'font_size' => 20, 'font_family' => 'NotoSans', 'color' => '#000000', 'align' => 'center', 'bold' => false, 'italic' => false ] ] )
		);

		$event_id = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );
		update_post_meta( $event_id, 'certiva_default_template_id', $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$result           = CertificateService::generate( $registration_id );

		$this->assertWPError( $result, 'A corrupt PDF background must fail as a WP_Error, not an uncaught fatal.' );
		$this->assertSame( 'certiva_render_failed', $result->get_error_code() );
	}
}
