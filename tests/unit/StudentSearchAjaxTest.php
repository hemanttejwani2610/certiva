<?php
/**
 * Tests for the type-ahead student search used on the Registrations screen.
 */

require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';

use Certiva\Data\StudentEmailIndexRepository;
use Certiva\PostTypes\StudentPostType;

class Certiva_Student_Search_Ajax_Test extends WP_Ajax_UnitTestCase {

	private function make_student( string $name, string $email ): int {
		$id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_title' => $name, 'post_status' => 'publish' ] );
		update_post_meta( $id, 'certiva_email', $email );
		StudentEmailIndexRepository::upsert( $id, $email );
		return $id;
	}

	private function call_search( string $term ) {
		$_POST['action'] = 'certiva_search_students';
		$_POST['nonce']  = wp_create_nonce( 'certiva_admin_action' );
		$_POST['term']   = $term;

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
}
