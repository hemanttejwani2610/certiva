<?php
/**
 * Tests for the certiva_college taxonomy: registration, the single-select
 * save behaviour, and its use as a certificate placeholder.
 */

use Certiva\Data\RegistrationsRepository;
use Certiva\Pdf\CertificateService;
use Certiva\Pdf\FieldDefinitions;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;

class Certiva_College_Taxonomy_Test extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	private function make_student(): int {
		return self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
	}

	public function test_taxonomy_is_registered_for_students() {
		$this->assertTrue( taxonomy_exists( CollegeTaxonomy::TAXONOMY ) );
		$this->assertTrue( is_object_in_taxonomy( StudentPostType::POST_TYPE, CollegeTaxonomy::TAXONOMY ) );
	}

	public function test_typing_a_new_college_creates_and_assigns_it() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$student_id = $this->make_student();

		$_POST = [
			'certiva_college_nonce' => wp_create_nonce( 'certiva_save_college_' . $student_id ),
			'certiva_college_new'   => 'Aurora Institute of Technology',
		];

		wp_update_post( [ 'ID' => $student_id, 'post_title' => 'Whatever' ] );

		$this->assertSame( 'Aurora Institute of Technology', CollegeTaxonomy::get_college_name( $student_id ) );
		$this->assertNotNull( get_term_by( 'name', 'Aurora Institute of Technology', CollegeTaxonomy::TAXONOMY ) );
	}

	public function test_selecting_an_existing_college_by_id_assigns_it() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$student_id = $this->make_student();
		$term       = wp_insert_term( 'Existing College', CollegeTaxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$_POST = [
			'certiva_college_nonce' => wp_create_nonce( 'certiva_save_college_' . $student_id ),
			'certiva_college_id'    => $term['term_id'],
		];

		wp_update_post( [ 'ID' => $student_id, 'post_title' => 'Whatever' ] );

		$this->assertSame( 'Existing College', CollegeTaxonomy::get_college_name( $student_id ) );
	}

	public function test_get_or_create_term_id_reuses_existing_term_case_insensitively() {
		$first  = CollegeTaxonomy::get_or_create_term_id( 'Northgate University' );
		$second = CollegeTaxonomy::get_or_create_term_id( 'northgate university' );

		$this->assertSame( $first, $second );
	}

	public function test_student_with_no_college_has_empty_name() {
		$student_id = $this->make_student();
		$this->assertSame( '', CollegeTaxonomy::get_college_name( $student_id ) );
	}

	public function test_college_is_available_as_a_certificate_placeholder() {
		$student_id = $this->make_student();
		wp_set_object_terms( $student_id, [ CollegeTaxonomy::get_or_create_term_id( 'Placeholder College' ) ], CollegeTaxonomy::TAXONOMY, false );

		$event_id = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );
		$registration_id = RegistrationsRepository::create( $student_id, $event_id );
		$registration     = RegistrationsRepository::get( $registration_id );

		$values = CertificateService::build_values( $registration );

		$this->assertSame( 'Placeholder College', $values[ FieldDefinitions::KEY_COLLEGE ] );
	}
}
