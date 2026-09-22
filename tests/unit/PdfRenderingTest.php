<?php
/**
 * Tests that certificate PDFs actually render, including with embedded
 * Hindi (Devanagari) text via the Lohit Devanagari font.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\Pdf\CertificateService;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\TemplatePostType;

class Certiva_Pdf_Rendering_Test extends WP_UnitTestCase {

	private function make_template_with_fields( array $fields ): int {
		$template_id = self::factory()->post->create( [ 'post_type' => TemplatePostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $template_id, 'certiva_page_size', TemplatePostType::PAGE_SIZE_A4 );
		update_post_meta( $template_id, 'certiva_orientation', TemplatePostType::ORIENTATION_LANDSCAPE );
		update_post_meta( $template_id, 'certiva_fields_json', wp_json_encode( $fields ) );

		return $template_id;
	}

	private function base_field( array $overrides = [] ): array {
		return array_merge(
			[
				'key'         => 'student_name',
				'label'       => 'Student Name',
				'text'        => '',
				'x'           => 50,
				'y'           => 50,
				'width'       => 80,
				'font_size'   => 18,
				'font_family' => 'NotoSans',
				'color'       => '#000000',
				'align'       => 'center',
				'bold'        => false,
				'italic'      => false,
			],
			$overrides
		);
	}

	public function test_generated_certificate_is_a_valid_pdf() {
		$student_id  = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Aanya Sharma' ] );
		$template_id = $this->make_template_with_fields( [ $this->base_field() ] );
		$event_id    = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );
		update_post_meta( $event_id, 'certiva_default_template_id', $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$result           = CertificateService::generate( $registration_id );

		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$pdf = CertificateService::get_issued_pdf( $registration_id );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_certificate_with_hindi_static_text_renders_without_error() {
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Aanya Sharma' ] );

		$fields = [
			$this->base_field(),
			$this->base_field(
				[
					'key'         => 'static',
					'label'       => 'Hindi heading',
					'text'        => 'यह प्रमाणपत्र आन्या शर्मा को प्रदान किया जाता है। धन्यवाद।',
					'x'           => 50,
					'y'           => 70,
					'font_family' => 'LohitDevanagari',
				]
			),
		];

		$template_id = $this->make_template_with_fields( $fields );
		$event_id    = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );
		update_post_meta( $event_id, 'certiva_default_template_id', $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$result           = CertificateService::generate( $registration_id );

		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$pdf = CertificateService::get_issued_pdf( $registration_id );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf );
	}
}
