<?php
/**
 * Tests that a student cannot be registered for the same event twice.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;

class Certiva_Duplicate_Registration_Test extends WP_UnitTestCase {

	private function make_student(): int {
		return self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
	}

	private function make_event(): int {
		return self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
	}

	public function test_first_registration_succeeds() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event();

		$result = RegistrationsRepository::create( $student_id, $event_id );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_duplicate_registration_is_rejected() {
		$student_id = $this->make_student();
		$event_id   = $this->make_event();

		$first  = RegistrationsRepository::create( $student_id, $event_id );
		$second = RegistrationsRepository::create( $student_id, $event_id );

		$this->assertIsInt( $first );
		$this->assertWPError( $second );
		$this->assertSame( 'certiva_duplicate_registration', $second->get_error_code() );
	}

	public function test_duplicate_is_rejected_even_at_the_database_layer() {
		// Bypasses the application-level find_by_student_and_event() pre-check
		// by inserting directly, to prove the DB unique key is what actually
		// prevents the duplicate — not just application logic.
		global $wpdb;

		$student_id = $this->make_student();
		$event_id   = $this->make_event();

		$table = \Certiva\Data\Schema::table_registrations();
		$now   = current_time( 'mysql' );

		$first = $wpdb->insert(
			$table,
			[
				'student_id' => $student_id,
				'event_id'   => $event_id,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$this->assertNotFalse( $first );

		// The duplicate insert is expected to fail — suppress wpdb's default
		// error output so the test transcript doesn't look like a crash.
		$suppress_state = $wpdb->suppress_errors( true );
		$second         = $wpdb->insert(
			$table,
			[
				'student_id' => $student_id,
				'event_id'   => $event_id,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$wpdb->suppress_errors( $suppress_state );

		$this->assertFalse( $second, 'The unique key on (student_id, event_id) must reject a second insert.' );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND event_id = %d", $student_id, $event_id ) );
		$this->assertSame( 1, $count );
	}

	public function test_same_student_can_register_for_a_different_event() {
		$student_id = $this->make_student();
		$event_a    = $this->make_event();
		$event_b    = $this->make_event();

		$first  = RegistrationsRepository::create( $student_id, $event_a );
		$second = RegistrationsRepository::create( $student_id, $event_b );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
	}
}
