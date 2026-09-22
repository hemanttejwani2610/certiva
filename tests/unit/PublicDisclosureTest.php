<?php
/**
 * Tests that the public certificate-request flow never discloses whether an
 * email address is registered, whether it has any registrations, or whether
 * those registrations are eligible — the response text must be identical
 * across all of those cases.
 */

use Certiva\Public\RequestController;
use Certiva\Data\RegistrationsRepository;
use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;
use Certiva\Pdf\CertificateService;

class Certiva_Public_Disclosure_Test extends WP_UnitTestCase {

	private $mail_calls = [];

	public function set_up() {
		parent::set_up();
		$this->mail_calls = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		unset( $_POST );
		parent::tear_down();
	}

	public function capture_mail( $null, $atts ) {
		$this->mail_calls[] = $atts;
		return true; // Short-circuit actual sending.
	}

	private function valid_request( string $email ): array {
		$_POST = [
			RequestController::NONCE_FIELD => wp_create_nonce( RequestController::NONCE_ACTION ),
			'certiva_email'                => $email,
			'certiva_ts'                   => time() - 10,
			'certiva_website'              => '',
		];
		return $_POST;
	}

	private function make_eligible_certificate( string $email ): int {
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );

		$event_id = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );

		$registration_id = RegistrationsRepository::create( $student_id, $event_id, null, true );

		// Mark as generated directly (bypassing PDF rendering, which is
		// covered by the eligibility/PDF tests) so is_eligible()+status
		// checks in the request flow see a real, generated certificate.
		RegistrationsRepository::mark_generated( $registration_id, 'fake/path.pdf' );

		return $registration_id;
	}

	public function test_nonexistent_email_gives_neutral_success_message_and_sends_no_mail() {
		$response = RequestController::determine_response( $this->valid_request( 'nobody-' . wp_generate_password( 8, false ) . '@example.com' ) );

		$this->assertSame( 'success', $response['type'] );
		$this->assertStringContainsString( "we'll send you a download link", $response['message'] );
		$this->assertCount( 0, $this->mail_calls );
	}

	public function test_existing_email_with_no_registrations_gives_identical_message() {
		$email = 'registered-but-nothing-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );

		$response = RequestController::determine_response( $this->valid_request( $email ) );

		$baseline = RequestController::determine_response( $this->valid_request( 'nobody-else-' . wp_generate_password( 8, false ) . '@example.com' ) );

		$this->assertSame( $baseline['message'], $response['message'] );
		$this->assertSame( $baseline['type'], $response['type'] );
		$this->assertCount( 0, $this->mail_calls );
	}

	public function test_existing_email_with_ineligible_registration_gives_identical_message() {
		$email      = 'ineligible-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );

		$event_id = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $event_id, 'certiva_certificate_availability', EventPostType::AVAILABILITY_ENABLED );
		// Registered, but NOT marked eligible (e.g. exam not yet passed) and not generated.
		RegistrationsRepository::create( $student_id, $event_id );

		$response = RequestController::determine_response( $this->valid_request( $email ) );
		$baseline = RequestController::determine_response( $this->valid_request( 'nobody-at-all-' . wp_generate_password( 8, false ) . '@example.com' ) );

		$this->assertSame( $baseline['message'], $response['message'] );
		$this->assertCount( 0, $this->mail_calls );
	}

	public function test_existing_email_with_eligible_certificate_gives_identical_message_but_does_send_mail() {
		$email = 'eligible-' . wp_generate_password( 6, false ) . '@example.com';
		$this->make_eligible_certificate( $email );

		$response = RequestController::determine_response( $this->valid_request( $email ) );
		$baseline = RequestController::determine_response( $this->valid_request( 'nobody-whatsoever-' . wp_generate_password( 8, false ) . '@example.com' ) );

		$this->assertSame( $baseline['message'], $response['message'], 'The visible response must not differ even though this email actually has a certificate.' );
		$this->assertSame( 'success', $response['type'] );

		// Internally, mail should have been sent for the eligible case
		// (proving the non-disclosure is about the RESPONSE, not that the
		// feature silently does nothing).
		$this->assertCount( 1, $this->mail_calls );
	}

	public function test_invalid_email_format_gives_a_distinct_validation_message() {
		$response = RequestController::determine_response( $this->valid_request( 'not-an-email' ) );

		$this->assertSame( 'error', $response['type'] );
		$this->assertStringContainsString( 'valid email', $response['message'] );
	}

	public function test_bad_nonce_gives_a_distinct_technical_error_not_the_neutral_message() {
		$request = $this->valid_request( 'someone@example.com' );
		$request[ RequestController::NONCE_FIELD ] = 'invalid-nonce';

		$response = RequestController::determine_response( $request );

		$this->assertSame( 'error', $response['type'] );
		$this->assertCount( 0, $this->mail_calls );
	}

	public function test_honeypot_filled_pretends_success_and_does_no_work() {
		$email   = 'honeypot-target-' . wp_generate_password( 6, false ) . '@example.com';
		$this->make_eligible_certificate( $email );

		$request = $this->valid_request( $email );
		$request['certiva_website'] = 'http://spam.example/'; // Bot filled the honeypot.
		$_POST = $request; // BotGuard reads the honeypot from the superglobal.

		$response = RequestController::determine_response( $request );

		$this->assertSame( 'success', $response['type'] );
		$this->assertCount( 0, $this->mail_calls, 'A honeypot-triggered submission must not actually send mail even for a real, eligible email.' );
	}

	public function test_too_fast_submission_is_treated_as_a_bot() {
		$request = $this->valid_request( 'someone-else@example.com' );
		$request['certiva_ts'] = time(); // Submitted with zero elapsed time.
		$_POST = $request; // BotGuard reads the timestamp from the superglobal.

		$response = RequestController::determine_response( $request );

		$this->assertSame( 'success', $response['type'] );
		$this->assertCount( 0, $this->mail_calls );
	}
}
