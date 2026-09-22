<?php
/**
 * Tests for secure download token creation, scope, and expiry.
 */

use Certiva\Data\DownloadTokensRepository;
use Certiva\Data\RegistrationsRepository;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\StudentPostType;

class Certiva_Download_Token_Test extends WP_UnitTestCase {

	private function make_registration(): int {
		$student_id = self::factory()->post->create( [ 'post_type' => StudentPostType::POST_TYPE, 'post_status' => 'publish' ] );
		$event_id   = self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE, 'post_status' => 'publish' ] );

		return RegistrationsRepository::create( $student_id, $event_id );
	}

	public function test_valid_token_validates_against_its_own_registration() {
		$registration_id = $this->make_registration();
		$token            = DownloadTokensRepository::create( $registration_id, HOUR_IN_SECONDS );

		$row = DownloadTokensRepository::validate( $token, $registration_id );

		$this->assertNotNull( $row );
	}

	public function test_token_is_scoped_to_its_own_registration_only() {
		$registration_a = $this->make_registration();
		$registration_b = $this->make_registration();

		$token = DownloadTokensRepository::create( $registration_a, HOUR_IN_SECONDS );

		$this->assertNull( DownloadTokensRepository::validate( $token, $registration_b ), 'A token issued for one registration must not validate against a different one.' );
		$this->assertNotNull( DownloadTokensRepository::validate( $token, $registration_a ) );
	}

	public function test_garbage_token_does_not_validate() {
		$registration_id = $this->make_registration();
		DownloadTokensRepository::create( $registration_id, HOUR_IN_SECONDS );

		$this->assertNull( DownloadTokensRepository::validate( 'not-a-real-token', $registration_id ) );
	}

	public function test_expired_token_does_not_validate() {
		$registration_id = $this->make_registration();

		// Create with a negative TTL so it is already expired.
		$token = DownloadTokensRepository::create( $registration_id, -1 * HOUR_IN_SECONDS );

		$this->assertNull( DownloadTokensRepository::validate( $token, $registration_id ) );
	}

	public function test_only_a_hash_is_ever_stored_not_the_raw_token() {
		global $wpdb;

		$registration_id = $this->make_registration();
		$token            = DownloadTokensRepository::create( $registration_id, HOUR_IN_SECONDS );

		$table = \Certiva\Data\Schema::table_download_tokens();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE registration_id = %d", $registration_id ) );

		$this->assertNotSame( $token, $row->token_hash );
		$this->assertSame( hash( 'sha256', $token ), $row->token_hash );
	}

	public function test_purge_expired_removes_only_expired_tokens() {
		global $wpdb;

		$registration_id = $this->make_registration();

		$valid_token   = DownloadTokensRepository::create( $registration_id, HOUR_IN_SECONDS );
		$expired_token = DownloadTokensRepository::create( $registration_id, -1 * HOUR_IN_SECONDS );

		// purge_expired() only removes tokens expired more than a day ago
		// (a short grace window); backdate the expired one further to be sure.
		$table = \Certiva\Data\Schema::table_download_tokens();
		$wpdb->update(
			$table,
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ],
			[ 'token_hash' => hash( 'sha256', $expired_token ) ]
		);

		DownloadTokensRepository::purge_expired();

		$this->assertNotNull( DownloadTokensRepository::validate( $valid_token, $registration_id ) );
		$this->assertNull( DownloadTokensRepository::validate( $expired_token, $registration_id ) );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE registration_id = %d", $registration_id ) );
		$this->assertSame( 1, $count );
	}
}
