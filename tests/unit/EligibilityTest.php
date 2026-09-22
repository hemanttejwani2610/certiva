<?php
/**
 * Tests for certificate eligibility, generation, and regeneration.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\Pdf\CertificateService;

class Certiva_Eligibility_Test extends WP_UnitTestCase {

	private function make_student(): int {
		return self::factory()->post->create(
			[
				'post_type'  => StudentPostType::POST_TYPE,
				'post_title' => 'Aanya Sharma',
				'post_status' => 'publish',
			]
		);
	}

	private function make_event( string $availability = EventPostType::AVAILABILITY_DISABLED, int $template_id = 0 ): int {
		$event_id = self::factory()->post->create(
			[
				'post_type'   => EventPostType::POST_TYPE,
				'post_title'  => 'Certified WordPress Developer Exam',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $event_id, 'certiva_event_type', EventPostType::TYPE_EXAM );
		update_post_meta( $event_id, 'certiva_certificate_availability', $availability );
		update_post_meta( $event_id, 'certiva_default_template_id', $template_id );

		return $event_id;
	}

	private function make_template(): int {
		$template_id = self::factory()->post->create(
			[
				'post_type'   => TemplatePostType::POST_TYPE,
				'post_title'  => 'Default Template',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $template_id, 'certiva_page_size', TemplatePostType::PAGE_SIZE_A4 );
		update_post_meta( $template_id, 'certiva_orientation', TemplatePostType::ORIENTATION_LANDSCAPE );
		update_post_meta(
			$template_id,
			'certiva_fields_json',
			wp_json_encode(
				[
					[ 'key' => 'student_name', 'label' => 'Student Name', 'text' => '', 'x' => 50, 'y' => 50, 'width' => 80, 'font_size' => 20, 'font_family' => 'NotoSans', 'color' => '#000000', 'align' => 'center', 'bold' => true, 'italic' => false ],
				]
			)
		);

		return $template_id;
	}

	/**
	 * Registering a student for an exam must not, by itself, make them
	 * eligible for a certificate — eligibility is an explicit opt-in.
	 */
	public function test_new_registration_is_not_eligible_by_default() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event( EventPostType::AVAILABILITY_ENABLED );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id );
		$registration     = RegistrationsRepository::get( $registration_id );

		$this->assertFalse( CertificateService::is_eligible( $registration ) );
	}

	public function test_event_disabled_blocks_eligibility_even_if_registration_marked_eligible() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event( EventPostType::AVAILABILITY_DISABLED );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$registration     = RegistrationsRepository::get( $registration_id );

		$this->assertTrue( (bool) $registration->eligible );
		$this->assertFalse( CertificateService::is_eligible( $registration ), 'Event-level availability must gate eligibility even when the registration is marked eligible.' );
	}

	public function test_eligible_registration_requires_both_gates() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event( EventPostType::AVAILABILITY_ENABLED );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		$registration     = RegistrationsRepository::get( $registration_id );

		$this->assertTrue( CertificateService::is_eligible( $registration ) );
	}

	public function test_generate_fails_when_not_eligible() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event( EventPostType::AVAILABILITY_ENABLED ); // registration not eligible

		$registration_id = RegistrationsRepository::create( $student_id, $event_id );

		$result = CertificateService::generate( $registration_id );

		$this->assertWPError( $result );
		$this->assertSame( 'certiva_not_eligible', $result->get_error_code() );
	}

	public function test_generate_succeeds_when_eligible_and_is_idempotent() {
		$student_id  = $this->make_student();
		$template_id = $this->make_template();
		$event_id    = $this->make_event( EventPostType::AVAILABILITY_ENABLED, $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );

		$result = CertificateService::generate( $registration_id );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$registration = RegistrationsRepository::get( $registration_id );
		$this->assertSame( RegistrationsRepository::STATUS_GENERATED, $registration->status );
		$this->assertNotEmpty( $registration->certificate_id );

		$first_certificate_id = $registration->certificate_id;

		// Calling generate() again must be a safe no-op: same ID, no duplicate row.
		$second_result = CertificateService::generate( $registration_id );
		$this->assertTrue( $second_result );

		$registration_again = RegistrationsRepository::get( $registration_id );
		$this->assertSame( $first_certificate_id, $registration_again->certificate_id );

		global $wpdb;
		$table = \Certiva\Data\Schema::table_registrations();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND event_id = %d", $student_id, $event_id ) );
		$this->assertSame( 1, $count );
	}

	public function test_regenerate_preserves_certificate_id_and_does_not_duplicate_registration() {
		$student_id  = $this->make_student();
		$template_id = $this->make_template();
		$event_id    = $this->make_event( EventPostType::AVAILABILITY_ENABLED, $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		CertificateService::generate( $registration_id );

		$before = RegistrationsRepository::get( $registration_id );

		// Change the student's name — regeneration should pick up new data
		// but must not touch the certificate id or create a new registration.
		wp_update_post( [ 'ID' => $student_id, 'post_title' => 'Aanya Sharma (Updated)' ] );

		$result = CertificateService::regenerate( $registration_id );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = RegistrationsRepository::get( $registration_id );

		$this->assertSame( $before->certificate_id, $after->certificate_id );
		$this->assertSame( $before->id, $after->id );
		$this->assertNotNull( $after->regenerated_at );

		global $wpdb;
		$table = \Certiva\Data\Schema::table_registrations();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND event_id = %d", $student_id, $event_id ) );
		$this->assertSame( 1, $count );
	}

	public function test_editing_data_does_not_change_an_already_issued_certificate_until_regenerated() {
		$student_id  = $this->make_student();
		$template_id = $this->make_template();
		$event_id    = $this->make_event( EventPostType::AVAILABILITY_ENABLED, $template_id );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );
		CertificateService::generate( $registration_id );

		$issued = RegistrationsRepository::get( $registration_id );
		$original_pdf_path = $issued->pdf_path;

		wp_update_post( [ 'ID' => $event_id, 'post_title' => 'Renamed Event' ] );

		$unchanged = RegistrationsRepository::get( $registration_id );
		$this->assertSame( $original_pdf_path, $unchanged->pdf_path, 'Editing event data must not silently alter an already-issued certificate file.' );
		$this->assertNull( $unchanged->regenerated_at );
	}
}
