<?php
namespace Certiva\Admin;

use Certiva\Data\Schema;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\PostTypes\StudentPostType;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Type-ahead student search for the Registrations screen.
 *
 * Sites bulk-importing students (see StudentImporter) can easily have far
 * more students than is sane to load into a single <select>, so the admin
 * types a name or email and gets a short, indexed-query result set instead
 * of every student being fetched and rendered up front. An optional college
 * filter narrows the search further — useful once several colleges' worth
 * of students share similar names.
 */
final class StudentSearchAjax {

	private const RESULT_LIMIT    = 20;
	private const CANDIDATE_LIMIT = 60; // Fetched before an optional college filter narrows it down to RESULT_LIMIT.
	private const MIN_TERM_LENGTH = 2;

	public static function register(): void {
		add_action( 'wp_ajax_certiva_search_students', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		check_ajax_referer( 'certiva_admin_action', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'certiva' ) ], 403 );
			return;
		}

		$term      = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$college_id = isset( $_POST['college_id'] ) ? absint( $_POST['college_id'] ) : 0;

		if ( mb_strlen( $term ) < self::MIN_TERM_LENGTH && $college_id <= 0 ) {
			wp_send_json_success( [ 'results' => [] ] );
			return;
		}

		$ids = mb_strlen( $term ) >= self::MIN_TERM_LENGTH
			? self::find_matching_student_ids( $term )
			: self::find_students_in_college( $college_id );

		if ( $college_id > 0 && mb_strlen( $term ) >= self::MIN_TERM_LENGTH ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $college_id ) {
						return has_term( $college_id, CollegeTaxonomy::TAXONOMY, $id );
					}
				)
			);
		}

		$ids = array_slice( $ids, 0, self::RESULT_LIMIT );

		if ( empty( $ids ) ) {
			wp_send_json_success( [ 'results' => [] ] );
			return;
		}

		_prime_post_caches( $ids, false, false );

		$results = [];
		foreach ( $ids as $id ) {
			if ( StudentPostType::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}

			$title = get_the_title( $id );
			$email = get_post_meta( $id, 'certiva_email', true );

			$results[] = [
				'id'    => $id,
				'label' => $email ? sprintf( '%1$s — %2$s', $title, $email ) : $title,
			];
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * Matches by title (via WP_Query's search) and by email (via the
	 * student-email index table, which is indexed for exactly this lookup),
	 * merging the two result sets. Fetches a wider candidate set than the
	 * final result limit so an optional college filter still has enough to
	 * work with.
	 *
	 * @return int[]
	 */
	private static function find_matching_student_ids( string $term ): array {
		global $wpdb;

		$title_query = new \WP_Query(
			[
				'post_type'      => StudentPostType::POST_TYPE,
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => self::CANDIDATE_LIMIT,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		$ids = $title_query->posts;

		$table = Schema::table_student_emails();
		$like  = '%' . $wpdb->esc_like( strtolower( $term ) ) . '%';

		$email_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT student_id FROM {$table} WHERE email LIKE %s LIMIT %d", $like, self::CANDIDATE_LIMIT )
		);

		return array_unique( array_merge( $ids, array_map( 'absint', $email_ids ) ) );
	}

	/**
	 * Lists students in a college without any text search — lets an admin
	 * browse a college's roster by picking it from the filter alone.
	 *
	 * @return int[]
	 */
	private static function find_students_in_college( int $college_id ): array {
		if ( $college_id <= 0 || ! term_exists( $college_id, CollegeTaxonomy::TAXONOMY ) ) {
			return [];
		}

		$query = new \WP_Query(
			[
				'post_type'      => StudentPostType::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::RESULT_LIMIT,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => CollegeTaxonomy::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $college_id,
					],
				],
			]
		);

		return $query->posts;
	}
}
