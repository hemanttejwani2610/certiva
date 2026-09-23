<?php
namespace Certiva\Admin;

use Certiva\Data\RegistrationsRepository;
use Certiva\PostTypes\StudentPostType;
use Certiva\PostTypes\EventPostType;
use Certiva\PostTypes\TemplatePostType;
use Certiva\PostTypes\CollegeTaxonomy;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Registrations" admin screen: register a student for an event, and
 * see/manage every registration and its certificate status.
 */
final class RegistrationsPage {

	public static function register(): void {
		add_action( 'admin_post_certiva_add_registration', [ __CLASS__, 'handle_add' ] );
	}

	public static function handle_add(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'certiva' ), 403 );
		}

		check_admin_referer( 'certiva_add_registration' );

		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$event_id   = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$eligible   = ! empty( $_POST['eligible'] );
		$template_override = isset( $_POST['template_override'] ) ? absint( $_POST['template_override'] ) : 0;

		$redirect = admin_url( 'admin.php?page=certiva' );

		if ( $student_id <= 0 || StudentPostType::POST_TYPE !== get_post_type( $student_id )
			|| $event_id <= 0 || EventPostType::POST_TYPE !== get_post_type( $event_id ) ) {
			wp_safe_redirect( add_query_arg( 'certiva_notice', 'invalid', $redirect ) );
			exit;
		}

		if ( $template_override > 0 && TemplatePostType::POST_TYPE !== get_post_type( $template_override ) ) {
			$template_override = 0;
		}

		$result = RegistrationsRepository::create( $student_id, $event_id, $template_override ?: null, $eligible );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'certiva_notice', 'duplicate', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'certiva_notice', 'added', $redirect ) );
		exit;
	}

	public static function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'certiva' ), 403 );
		}

		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$filter_event = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$per_page = 20;
		$filters  = array_filter(
			[
				'event_id' => $filter_event,
				'status'   => $filter_status,
			]
		);

		$registrations = RegistrationsRepository::get_all( $per_page, $paged, $filters );
		$total         = RegistrationsRepository::count_all( $filters );

		$student_ids = wp_list_pluck( $registrations, 'student_id' );
		$event_ids   = wp_list_pluck( $registrations, 'event_id' );
		if ( $student_ids ) {
			_prime_post_caches( array_unique( array_merge( $student_ids, $event_ids ) ), false, false );
		}

		$events = get_posts(
			[
				'post_type'      => EventPostType::POST_TYPE,
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$templates = get_posts(
			[
				'post_type'      => TemplatePostType::POST_TYPE,
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$colleges = get_terms(
			[
				'taxonomy'   => CollegeTaxonomy::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);
		$colleges = is_wp_error( $colleges ) ? [] : $colleges;

		require CERTIVA_DIR . 'templates/admin/registrations-page.php';
	}
}
