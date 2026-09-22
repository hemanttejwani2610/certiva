<?php
namespace Certiva\PostTypes;

use Certiva\Data\StudentEmailIndexRepository;
use Certiva\Data\RegistrationsRepository;
use Certiva\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The certiva_student custom post type: a person who can be registered
 * for events and issued certificates.
 */
final class StudentPostType {

	public const POST_TYPE = 'certiva_student';

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ] );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
	}

	public static function register_post_type(): void {
		$labels = [
			'name'               => __( 'Students', 'certiva' ),
			'singular_name'      => __( 'Student', 'certiva' ),
			'add_new'            => __( 'Add New Student', 'certiva' ),
			'add_new_item'       => __( 'Add New Student', 'certiva' ),
			'edit_item'          => __( 'Edit Student', 'certiva' ),
			'new_item'           => __( 'New Student', 'certiva' ),
			'view_item'          => __( 'View Student', 'certiva' ),
			'search_items'       => __( 'Search Students', 'certiva' ),
			'not_found'          => __( 'No students found.', 'certiva' ),
			'not_found_in_trash' => __( 'No students found in Trash.', 'certiva' ),
			'all_items'          => __( 'Students', 'certiva' ),
			'menu_name'          => __( 'Students', 'certiva' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'certiva',
				'show_in_rest'    => false,
				'supports'        => [ 'title' ],
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	public static function add_meta_boxes(): void {
		add_meta_box(
			'certiva-student-details',
			__( 'Student Details', 'certiva' ),
			[ __CLASS__, 'render_details_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'certiva-student-registrations',
			__( 'Event Registrations & Certificates', 'certiva' ),
			[ __CLASS__, 'render_registrations_box' ],
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	public static function render_details_box( \WP_Post $post ): void {
		$email        = get_post_meta( $post->ID, 'certiva_email', true );
		$student_code = get_post_meta( $post->ID, 'certiva_student_code', true );
		$extra_fields = get_post_meta( $post->ID, 'certiva_extra_fields', true );
		$extra_fields = is_array( $extra_fields ) ? $extra_fields : [];

		wp_nonce_field( 'certiva_save_student_' . $post->ID, 'certiva_student_meta_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_email"><?php esc_html_e( 'Email Address', 'certiva' ); ?> <span class="description">(<?php esc_html_e( 'required', 'certiva' ); ?>)</span></label></th>
				<td>
					<input type="email" id="certiva_email" name="certiva_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" required />
					<p class="description"><?php esc_html_e( 'Used to look up and deliver certificates. Certiva normalizes this for matching (trimmed, lower-case).', 'certiva' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="certiva_student_code"><?php esc_html_e( 'Student ID', 'certiva' ); ?></label></th>
				<td>
					<input type="text" id="certiva_student_code" name="certiva_student_code" class="regular-text" value="<?php echo esc_attr( $student_code ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Available as a certificate placeholder.', 'certiva' ); ?></p>
				</td>
			</tr>
		</table>

		<h4><?php esc_html_e( 'Additional Certificate Placeholders', 'certiva' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Optional key/value pairs (e.g. "Course", "Grade") available to certificate templates as extra placeholders.', 'certiva' ); ?></p>
		<table class="widefat certiva-extra-fields" id="certiva-extra-fields-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Value', 'certiva' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $extra_fields as $i => $field ) : ?>
					<tr>
						<td><input type="text" name="certiva_extra_label[]" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>" class="regular-text" /></td>
						<td><input type="text" name="certiva_extra_value[]" value="<?php echo esc_attr( $field['value'] ?? '' ); ?>" class="regular-text" /></td>
						<td><button type="button" class="button certiva-remove-row"><?php esc_html_e( 'Remove', 'certiva' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="certiva-add-extra-field"><?php esc_html_e( '+ Add Field', 'certiva' ); ?></button></p>
		<template id="certiva-extra-field-row-template">
			<tr>
				<td><input type="text" name="certiva_extra_label[]" value="" class="regular-text" /></td>
				<td><input type="text" name="certiva_extra_value[]" value="" class="regular-text" /></td>
				<td><button type="button" class="button certiva-remove-row"><?php esc_html_e( 'Remove', 'certiva' ); ?></button></td>
			</tr>
		</template>
		<?php
	}

	public static function render_registrations_box( \WP_Post $post ): void {
		require CERTIVA_DIR . 'templates/admin/student-registrations-box.php';
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['certiva_student_meta_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['certiva_student_meta_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'certiva_save_student_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$email = isset( $_POST['certiva_email'] ) ? sanitize_email( wp_unslash( $_POST['certiva_email'] ) ) : '';
		if ( '' !== $email && is_email( $email ) ) {
			update_post_meta( $post_id, 'certiva_email', $email );
			StudentEmailIndexRepository::upsert( $post_id, $email );
		} else {
			delete_post_meta( $post_id, 'certiva_email' );
			StudentEmailIndexRepository::delete( $post_id );
		}

		$student_code = isset( $_POST['certiva_student_code'] )
			? sanitize_text_field( wp_unslash( $_POST['certiva_student_code'] ) )
			: '';
		update_post_meta( $post_id, 'certiva_student_code', $student_code );

		$labels = isset( $_POST['certiva_extra_label'] ) ? (array) wp_unslash( $_POST['certiva_extra_label'] ) : [];
		$values = isset( $_POST['certiva_extra_value'] ) ? (array) wp_unslash( $_POST['certiva_extra_value'] ) : [];

		$extra_fields = [];
		foreach ( $labels as $i => $label ) {
			$label = sanitize_text_field( $label );
			$value = sanitize_text_field( $values[ $i ] ?? '' );
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$extra_fields[] = [ 'label' => $label, 'value' => $value ];
		}
		update_post_meta( $post_id, 'certiva_extra_fields', $extra_fields );
	}

	public static function on_delete( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		StudentEmailIndexRepository::delete( $post_id );
		RegistrationsRepository::delete_for_student( $post_id );
	}

	public static function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['certiva_email']         = __( 'Email', 'certiva' );
				$new['certiva_student_code']  = __( 'Student ID', 'certiva' );
				$new['certiva_registrations'] = __( 'Registrations', 'certiva' );
			}
		}
		return $new;
	}

	public static function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'certiva_email':
				echo esc_html( (string) get_post_meta( $post_id, 'certiva_email', true ) );
				break;
			case 'certiva_student_code':
				echo esc_html( (string) get_post_meta( $post_id, 'certiva_student_code', true ) );
				break;
			case 'certiva_registrations':
				echo esc_html( (string) count( RegistrationsRepository::get_for_student( $post_id ) ) );
				break;
		}
	}
}
