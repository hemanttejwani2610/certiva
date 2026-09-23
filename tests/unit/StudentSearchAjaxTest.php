<?php
/**
 * Tests for the type-ahead student search used on the Registrations screen.
 */

require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';

use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\StudentPostType;

class Certiva_Student_Search_Ajax_Test extends WP_Ajax_UnitTestCase {

	private function make_student( string $name, string $email ): int {
		$id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => $name, 'post_status' => 'publish' ] );
		update_post_meta( $id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $id, $email );
		return $id;
	}

	private function call_search( string $term, int $college_id = 0 ) {
		$_POST['action']     = 'certiva_search_students';
		$_POST['nonce']      = wp_create_nonce( 'certiva_admin_action' );
		$_POST['term']       = $term;
		$_POST['college_id'] = $college_id;

		try {
			$this->_handleAjax( 'certiva_search_students' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	public function test_search_by_name_matches() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$suffix = wp_generate_password( 6, false );
		$this->make_student( 'Aanya Sharma ' . $suffix, 'aanya-' . strtolower( $suffix ) . '@example.com' );

		$response = $this->call_search( 'Aanya Sharma ' . $suffix );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data']['results'] );
		$this->assertStringContainsString( 'Aanya Sharma', $response['data']['results'][0]['label'] );
	}

	public function test_search_by_email_matches() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$suffix = strtolower( wp_generate_password( 8, false ) );
		$email  = 'findme-' . $suffix . '@example.com';
		$this->make_student( 'Some Student', $email );

		$response = $this->call_search( $suffix );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data']['results'] );
		$this->assertStringContainsString( $email, $response['data']['results'][0]['label'] );
	}

	public function test_short_terms_return_no_results_without_querying() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->call_search( 'a' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( [], $response['data']['results'] );
	}

	public function test_non_privileged_user_is_rejected() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->call_search( 'anything' );

		$this->assertFalse( $response['success'] );
	}

	/**
	 * Sanity check that both same-named students match without a college
	 * filter — the counterpart to the filtered assertion below. Split into
	 * its own test because WP_Ajax_UnitTestCase's _handleAjax() does not
	 * cleanly support being called more than once per test method (its
	 * output buffering concatenates across calls).
	 */
	public function test_without_college_filter_both_same_named_students_match() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$suffix = wp_generate_password( 6, false );
		$name   = 'Riley Chen ' . $suffix;

		$this->make_student( $name, 'riley-a-' . strtolower( $suffix ) . '@example.com' );
		$this->make_student( $name, 'riley-b-' . strtolower( $suffix ) . '@example.com' );

		$response = $this->call_search( $name );

		$this->assertCount( 2, $response['data']['results'] );
	}

	public function test_college_filter_narrows_a_text_search() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$suffix = wp_generate_password( 6, false );
		$name   = 'Riley Chen ' . $suffix;

		$in_college = $this->make_student( $name, 'riley-a-' . strtolower( $suffix ) . '@example.com' );
		$this->make_student( $name, 'riley-b-' . strtolower( $suffix ) . '@example.com' );

		$college_id = CollegeTaxonomy::get_or_create_term_id( 'Filter Test College' );
		wp_set_object_terms( $in_college, [ $college_id ], CollegeTaxonomy::TAXONOMY, false );

		$filtered = $this->call_search( $name, $college_id );

		$this->assertCount( 1, $filtered['data']['results'] );
		$this->assertSame( $in_college, $filtered['data']['results'][0]['id'] );
	}

	public function test_college_filter_alone_browses_that_colleges_roster() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$college_id = CollegeTaxonomy::get_or_create_term_id( 'Browse Test College' );
		$member     = $this->make_student( 'Browse Member', 'browse-member-' . wp_generate_password( 6, false ) . '@example.com' );
		wp_set_object_terms( $member, [ $college_id ], CollegeTaxonomy::TAXONOMY, false );
		$outsider = $this->make_student( 'Not In College', 'not-in-college-' . wp_generate_password( 6, false ) . '@example.com' );

		// No text term at all — picking a college alone should list its students.
		$response = $this->call_search( '', $college_id );

		$this->assertTrue( $response['success'] );
		$ids = wp_list_pluck( $response['data']['results'], 'id' );
		$this->assertContains( $member, $ids );
		$this->assertNotContains( $outsider, $ids );
	}
}
