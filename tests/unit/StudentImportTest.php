<?php
/**
 * Tests for the CSV student importer: column mapping, row validation,
 * and update-by-email deduplication.
 */

use Certiva\Import\StudentImporter;
use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\StudentPostType;

class Certiva_Student_Import_Test extends WP_UnitTestCase {

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
		$path = tempnam( sys_get_temp_dir(), 'certiva_import_test_' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	private function default_mapping(): array {
		return [
			0 => StudentImporter::TARGET_FULL_NAME,
			1 => StudentImporter::TARGET_EMAIL,
			2 => StudentImporter::TARGET_STUDENT_ID,
			3 => StudentImporter::TARGET_EXTRA,
		];
	}

	public function test_read_headers_and_preview() {
		$path = $this->write_csv(
			"Full Name,Email,Student ID,Course\n" .
			"Aanya Sharma,aanya@example.com,S-001,WordPress 101\n" .
			"Rahul Verma,rahul@example.com,S-002,WordPress 101\n"
		);

		$result = StudentImporter::read_headers_and_preview( $path, 5 );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'Full Name', 'Email', 'Student ID', 'Course' ], $result['headers'] );
		$this->assertSame( 2, $result['total_data_rows'] );
		$this->assertCount( 2, $result['preview'] );
	}

	public function test_import_creates_students_with_mapped_fields() {
		$path = $this->write_csv(
			"Full Name,Email,Student ID,Course\n" .
			"Aanya Sharma,aanya-" . wp_generate_password( 6, false ) . "@example.com,S-001,WordPress 101\n" .
			"Rahul Verma,rahul-" . wp_generate_password( 6, false ) . "@example.com,S-002,WordPress 101\n"
		);

		$results = StudentImporter::process( $path, $this->default_mapping(), [ 3 => 'Course' ], false );

		$this->assertIsArray( $results );
		$this->assertSame( 2, $results['created'] );
		$this->assertSame( 0, $results['updated'] );
		$this->assertSame( 0, $results['skipped'] );
		$this->assertSame( 2, $results['total'] );

		$students = get_posts( [ 'post_type' => StudentPostType::POST_TYPE, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$this->assertCount( 2, $students );

		$aanya = $students[0];
		$this->assertSame( 'Aanya Sharma', $aanya->post_title );
		$this->assertSame( 'S-001', get_post_meta( $aanya->ID, 'certiva_student_code', true ) );

		$extra = get_post_meta( $aanya->ID, 'certiva_extra_fields', true );
		$this->assertSame( [ [ 'label' => 'Course', 'value' => 'WordPress 101' ] ], $extra );
	}

	public function test_import_skips_rows_missing_required_fields() {
		$path = $this->write_csv(
			"Full Name,Email,Student ID,Course\n" .
			",missing-name@example.com,S-001,WordPress 101\n" .
			"No Email,,S-002,WordPress 101\n" .
			"Bad Email,not-an-email,S-003,WordPress 101\n"
		);

		$results = StudentImporter::process( $path, $this->default_mapping(), [ 3 => 'Course' ], false );

		$this->assertSame( 0, $results['created'] );
		$this->assertSame( 3, $results['skipped'] );
		$this->assertCount( 3, $results['skipped_reasons'] );
	}

	public function test_import_requires_full_name_and_email_columns_mapped() {
		$path = $this->write_csv( "A,B\n1,2\n" );

		$mapping = [ 0 => StudentImporter::TARGET_SKIP, 1 => StudentImporter::TARGET_SKIP ];
		$result  = StudentImporter::process( $path, $mapping, [], false );

		$this->assertWPError( $result );
		$this->assertSame( 'certiva_import_missing_mapping', $result->get_error_code() );
	}

	public function test_update_existing_by_email_merges_instead_of_duplicating() {
		$email = 'update-me-' . wp_generate_password( 6, false ) . '@example.com';

		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => 'Old Name', 'post_status' => 'publish' ] );
		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );
		update_post_meta( $student_id, 'certiva_extra_fields', [ [ 'label' => 'Existing', 'value' => 'Kept' ] ] );

		$path = $this->write_csv( "Full Name,Email,Student ID,Course\nNew Name,{$email},S-999,Updated Course\n" );

		$results = StudentImporter::process( $path, $this->default_mapping(), [ 3 => 'Course' ], true );

		$this->assertSame( 0, $results['created'] );
		$this->assertSame( 1, $results['updated'] );

		$students = get_posts( [ 'post_type' => StudentPostType::POST_TYPE, 'posts_per_page' => -1 ] );
		$this->assertCount( 1, $students, 'Updating by email must not create a second student.' );

		$updated = get_post( $student_id );
		$this->assertSame( 'New Name', $updated->post_title );
		$this->assertSame( 'S-999', get_post_meta( $student_id, 'certiva_student_code', true ) );

		$extra = get_post_meta( $student_id, 'certiva_extra_fields', true );
		$labels = wp_list_pluck( $extra, 'value', 'label' );
		$this->assertSame( 'Kept', $labels['Existing'], 'Fields not present in the CSV must be preserved on update.' );
		$this->assertSame( 'Updated Course', $labels['Course'], 'Fields present in the CSV must be updated on update.' );
	}

	public function test_without_update_existing_a_matching_email_creates_a_duplicate() {
		$email = 'dup-' . wp_generate_password( 6, false ) . '@example.com';

		self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => 'Original', 'post_status' => 'publish' ] );
		$first_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => 'Original Two', 'post_status' => 'publish' ] );
		update_post_meta( $first_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $first_id, $email );

		$path = $this->write_csv( "Full Name,Email,Student ID,Course\nDuplicate Import,{$email},S-1,X\n" );

		$results = StudentImporter::process( $path, $this->default_mapping(), [ 3 => 'Course' ], false );

		$this->assertSame( 1, $results['created'] );
		$this->assertSame( 0, $results['updated'] );

		$matches = StudentEmailIndexRepository::get_student_ids_for_email( $email );
		$this->assertGreaterThanOrEqual( 2, count( $matches ), 'With update_existing disabled, a second student sharing the email must be created, not merged.' );
	}

	public function test_college_column_creates_and_assigns_the_term() {
		$email = 'college-import-' . wp_generate_password( 6, false ) . '@example.com';
		$path  = $this->write_csv( "Full Name,Email,College\nAanya Sharma,{$email},Riverside College\n" );

		$mapping = [ 0 => StudentImporter::TARGET_FULL_NAME, 1 => StudentImporter::TARGET_EMAIL, 2 => StudentImporter::TARGET_COLLEGE ];
		$results = StudentImporter::process( $path, $mapping, [], false );

		$this->assertSame( 1, $results['created'] );

		$student_ids = StudentEmailIndexRepository::get_student_ids_for_email( $email );
		$this->assertNotEmpty( $student_ids );
		$this->assertSame( 'Riverside College', CollegeTaxonomy::get_college_name( (int) $student_ids[0] ) );
	}

	public function test_reimport_with_update_existing_reassigns_college() {
		$email      = 'college-reimport-' . wp_generate_password( 6, false ) . '@example.com';
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => 'Existing', 'post_status' => 'publish' ] );
		update_post_meta( $student_id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $student_id, $email );
		wp_set_object_terms( $student_id, [ CollegeTaxonomy::get_or_create_term_id( 'Old College' ) ], CollegeTaxonomy::TAXONOMY, false );

		$path    = $this->write_csv( "Full Name,Email,College\nExisting,{$email},New College\n" );
		$mapping = [ 0 => StudentImporter::TARGET_FULL_NAME, 1 => StudentImporter::TARGET_EMAIL, 2 => StudentImporter::TARGET_COLLEGE ];

		$results = StudentImporter::process( $path, $mapping, [], true );

		$this->assertSame( 1, $results['updated'] );
		$this->assertSame( 'New College', CollegeTaxonomy::get_college_name( $student_id ) );
	}
}
