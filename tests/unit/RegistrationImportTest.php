<?php
/**
 * Tests for bulk-registering existing students for one event via CSV.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\Data\StudentEmailIndexRepository;
use Certiva\Data\Schema;
use Certiva\Import\RegistrationImporter;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\TemplatePostType;

class Certiva_Registration_Import_Test extends WP_UnitTestCase {

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

	private function write_csv( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'certiva_reg_import_test_' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	private function make_student_with_email( string $email ): int {
		$id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $id, $email );
		return $id;
	}

	private function make_event(): int {
		return self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
	}

	public function test_requires_email_column_mapped() {
		$path = $this->write_csv( "A\nvalue\n" );

		$result = RegistrationImporter::process( $path, $this->make_event(), [ 0 => RegistrationImporter::TARGET_SKIP ] );

		$this->assertWPError( $result );
		$this->assertSame( 'certiva_import_missing_mapping', $result->get_error_code() );
	}

	public function test_registers_matched_students_and_skips_unmatched() {
		$event_id = $this->make_event();
		$email    = 'known-' . wp_generate_password( 6, false ) . '@example.com';
		$this->make_student_with_email( $email );

		$path = $this->write_csv(
			"email,eligible\n" .
			"{$email},yes\n" .
			"unknown-" . wp_generate_password( 6, false ) . "@example.com,yes\n"
		);

		$results = RegistrationImporter::process( $path, $event_id, [ 0 => RegistrationImporter::TARGET_EMAIL, 1 => RegistrationImporter::TARGET_ELIGIBLE ] );

		$this->assertSame( 1, $results['registered'] );
		$this->assertSame( 0, $results['updated'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertCount( 1, $results['skipped_reasons'] );
		$this->assertStringContainsString( 'no student found', $results['skipped_reasons'][0] );
	}

	public function test_eligible_defaults_false_when_column_not_mapped() {
		$event_id = $this->make_event();
		$email    = 'default-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = $this->make_student_with_email( $email );

		$path = $this->write_csv( "email\n{$email}\n" );

		RegistrationImporter::process( $path, $event_id, [ 0 => RegistrationImporter::TARGET_EMAIL ] );

		$registration = RegistrationsRepository::find_by_student_and_event( $student_id, $event_id );
		$this->assertNotNull( $registration );
		$this->assertFalse( (bool) $registration->eligible );
	}

	public function test_reimporting_updates_existing_registration_instead_of_duplicating() {
		global $wpdb;

		$event_id   = $this->make_event();
		$email      = 'reimport-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = $this->make_student_with_email( $email );

		$path1 = $this->write_csv( "email,eligible\n{$email},no\n" );
		$first = RegistrationImporter::process( $path1, $event_id, [ 0 => RegistrationImporter::TARGET_EMAIL, 1 => RegistrationImporter::TARGET_ELIGIBLE ] );
		$this->assertSame( 1, $first['registered'] );

		$path2  = $this->write_csv( "email,eligible\n{$email},yes\n" );
		$second = RegistrationImporter::process( $path2, $event_id, [ 0 => RegistrationImporter::TARGET_EMAIL, 1 => RegistrationImporter::TARGET_ELIGIBLE ] );

		$this->assertSame( 0, $second['registered'] );
		$this->assertSame( 1, $second['updated'] );

		$table = Schema::table_registrations();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND event_id = %d", $student_id, $event_id ) );
		$this->assertSame( 1, $count, 'Re-importing must update the existing registration, not create a second one.' );

		$registration = RegistrationsRepository::find_by_student_and_event( $student_id, $event_id );
		$this->assertTrue( (bool) $registration->eligible, 'The second import\'s eligible value must take effect.' );
	}

	public function test_template_override_resolved_by_title_and_by_id() {
		$event_id    = $this->make_event();
		$template_id = self::factory()->post->create( [ 'post_type' => TemplatePostType::POST_TYPE, 'post_title' => 'Gold Template', 'post_status' => 'publish' ] );

		$email_a = 'by-title-' . wp_generate_password( 6, false ) . '@example.com';
		$email_b = 'by-id-' . wp_generate_password( 6, false ) . '@example.com';
		$student_a = $this->make_student_with_email( $email_a );
		$student_b = $this->make_student_with_email( $email_b );

		$path = $this->write_csv(
			"email,template_override\n" .
			"{$email_a},Gold Template\n" .
			"{$email_b},{$template_id}\n"
		);

		$results = RegistrationImporter::process(
			$path,
			$event_id,
			[ 0 => RegistrationImporter::TARGET_EMAIL, 1 => RegistrationImporter::TARGET_TEMPLATE_OVERRIDE ]
		);

		$this->assertSame( 2, $results['registered'] );
		$this->assertSame( [], $results['warnings'] );

		$reg_a = RegistrationsRepository::find_by_student_and_event( $student_a, $event_id );
		$reg_b = RegistrationsRepository::find_by_student_and_event( $student_b, $event_id );

		$this->assertSame( $template_id, (int) $reg_a->template_id );
		$this->assertSame( $template_id, (int) $reg_b->template_id );
	}

	public function test_unknown_template_override_warns_but_still_registers() {
		$event_id   = $this->make_event();
		$email      = 'badtemplate-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = $this->make_student_with_email( $email );

		$path = $this->write_csv( "email,template_override\n{$email},Does Not Exist\n" );

		$results = RegistrationImporter::process(
			$path,
			$event_id,
			[ 0 => RegistrationImporter::TARGET_EMAIL, 1 => RegistrationImporter::TARGET_TEMPLATE_OVERRIDE ]
		);

		$this->assertSame( 1, $results['registered'] );
		$this->assertCount( 1, $results['warnings'] );

		$registration = RegistrationsRepository::find_by_student_and_event( $student_id, $event_id );
		$this->assertNull( $registration->template_id );
	}
}
