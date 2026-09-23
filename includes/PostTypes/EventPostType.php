<?php
namespace Certiva\PostTypes;

use Certiva\Data\RegistrationsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The certiva_event custom post type: an exam, seminar, conference, or
 * workshop that students can be registered for.
 */
final class EventPostType {

	public const POST_TYPE = 'certiva_event';

	public const TYPE_EXAM       = 'exam';
	public const TYPE_SEMINAR    = 'seminar';
	public const TYPE_CONFERENCE = 'conference';
	public const TYPE_WORKSHOP   = 'workshop';

	public const AVAILABILITY_ENABLED  = 'enabled';
	public const AVAILABILITY_DISABLED = 'disabled';

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ] );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
	}

	public static function types(): array {
		return [
			self::TYPE_EXAM       => __( 'Exam', 'certiva' ),
			self::TYPE_SEMINAR    => __( 'Seminar', 'certiva' ),
			self::TYPE_CONFERENCE => __( 'Conference', 'certiva' ),
			self::TYPE_WORKSHOP   => __( 'Workshop', 'certiva' ),
		];
	}

	public static function register_post_type(): void {
		$labels = [
			'name'          => __( 'Events', 'certiva' ),
			'singular_name' => __( 'Event', 'certiva' ),
			'add_new_item'  => __( 'Add New Event', 'certiva' ),
			'edit_item'     => __( 'Edit Event', 'certiva' ),
			'new_item'      => __( 'New Event', 'certiva' ),
			'search_items'  => __( 'Search Events', 'certiva' ),
			'not_found'     => __( 'No events found.', 'certiva' ),
			'all_items'     => __( 'Events', 'certiva' ),
			'menu_name'     => __( 'Events', 'certiva' ),
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
			'certiva-event-details',
			__( 'Event Details', 'certiva' ),
			[ __CLASS__, 'render_details_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_details_box( \WP_Post $post ): void {
		$type          = get_post_meta( $post->ID, 'certiva_event_type', true ) ?: self::TYPE_EXAM;
		$date          = get_post_meta( $post->ID, 'certiva_event_date', true );
		$location      = get_post_meta( $post->ID, 'certiva_event_location', true );
		$availability  = get_post_meta( $post->ID, 'certiva_certificate_availability', true ) ?: self::AVAILABILITY_DISABLED;
		$template_id   = (int) get_post_meta( $post->ID, 'certiva_default_template_id', true );

		$templates = get_posts(
			[
				'post_type'      => TemplatePostType::POST_TYPE,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		wp_nonce_field( 'certiva_save_event_' . $post->ID, 'certiva_event_meta_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_event_type"><?php esc_html_e( 'Event Type', 'certiva' ); ?></label></th>
				<td>
					<select id="certiva_event_type" name="certiva_event_type">
						<?php foreach ( self::types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="certiva_event_date"><?php esc_html_e( 'Event Date', 'certiva' ); ?></label></th>
				<td><input type="date" id="certiva_event_date" name="certiva_event_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="certiva_event_location"><?php esc_html_e( 'Location', 'certiva' ); ?></label></th>
				<td><input type="text" id="certiva_event_location" name="certiva_event_location" class="regular-text" value="<?php echo esc_attr( $location ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'certiva' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="certiva_default_template_id"><?php esc_html_e( 'Default Certificate Template', 'certiva' ); ?></label></th>
				<td>
					<select id="certiva_default_template_id" name="certiva_default_template_id">
						<option value="0"><?php esc_html_e( '— None —', 'certiva' ); ?></option>
						<?php foreach ( $templates as $template ) : ?>
							<option value="<?php echo esc_attr( (string) $template->ID ); ?>" <?php selected( $template_id, $template->ID ); ?>><?php echo esc_html( $template->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used unless overridden on an individual registration.', 'certiva' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="certiva_certificate_availability"><?php esc_html_e( 'Certificate Availability', 'certiva' ); ?></label></th>
				<td>
					<select id="certiva_certificate_availability" name="certiva_certificate_availability">
						<option value="<?php echo esc_attr( self::AVAILABILITY_DISABLED ); ?>" <?php selected( $availability, self::AVAILABILITY_DISABLED ); ?>><?php esc_html_e( 'Disabled — no certificates for this event yet', 'certiva' ); ?></option>
						<option value="<?php echo esc_attr( self::AVAILABILITY_ENABLED ); ?>" <?php selected( $availability, self::AVAILABILITY_ENABLED ); ?>><?php esc_html_e( 'Enabled — certificates may be issued', 'certiva' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'This is one of two gates a certificate must pass before it can be generated or downloaded — the other is the "Eligible" flag on the individual registration. Enabling this does not by itself make any student eligible (e.g. an exam registration is not automatically a pass).', 'certiva' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['certiva_event_meta_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['certiva_event_meta_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'certiva_save_event_' . $post_id ) ) {
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

		$type = isset( $_POST['certiva_event_type'] ) ? sanitize_key( wp_unslash( $_POST['certiva_event_type'] ) ) : self::TYPE_EXAM;
		if ( ! array_key_exists( $type, self::types() ) ) {
			$type = self::TYPE_EXAM;
		}
		update_post_meta( $post_id, 'certiva_event_type', $type );

		$date = isset( $_POST['certiva_event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['certiva_event_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		} else {
			[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
			if ( ! checkdate( $m, $d, $y ) ) {
				$date = '';
			}
		}
		update_post_meta( $post_id, 'certiva_event_date', $date );

		$location = isset( $_POST['certiva_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['certiva_event_location'] ) ) : '';
		update_post_meta( $post_id, 'certiva_event_location', $location );

		$template_id = isset( $_POST['certiva_default_template_id'] ) ? absint( $_POST['certiva_default_template_id'] ) : 0;
		if ( $template_id > 0 && TemplatePostType::POST_TYPE !== get_post_type( $template_id ) ) {
			$template_id = 0;
		}
		update_post_meta( $post_id, 'certiva_default_template_id', $template_id );

		$availability = isset( $_POST['certiva_certificate_availability'] ) ? sanitize_key( wp_unslash( $_POST['certiva_certificate_availability'] ) ) : self::AVAILABILITY_DISABLED;
		if ( ! in_array( $availability, [ self::AVAILABILITY_ENABLED, self::AVAILABILITY_DISABLED ], true ) ) {
			$availability = self::AVAILABILITY_DISABLED;
		}
		update_post_meta( $post_id, 'certiva_certificate_availability', $availability );
	}

	public static function on_delete( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		RegistrationsRepository::delete_for_event( $post_id );
	}

	public static function is_available( int $event_id ): bool {
		return self::AVAILABILITY_ENABLED === get_post_meta( $event_id, 'certiva_certificate_availability', true );
	}

	public static function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['certiva_event_type']         = __( 'Type', 'certiva' );
				$new['certiva_event_date']         = __( 'Date', 'certiva' );
				$new['certiva_event_location']     = __( 'Location', 'certiva' );
				$new['certiva_availability']       = __( 'Certificates', 'certiva' );
				$new['certiva_event_registrations'] = __( 'Registered', 'certiva' );
			}
		}
		return $new;
	}

	public static function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'certiva_event_type':
				$type = get_post_meta( $post_id, 'certiva_event_type', true );
				echo esc_html( self::types()[ $type ] ?? $type );
				break;
			case 'certiva_event_date':
				echo esc_html( get_post_meta( $post_id, 'certiva_event_date', true ) );
				break;
			case 'certiva_event_location':
				echo esc_html( get_post_meta( $post_id, 'certiva_event_location', true ) );
				break;
			case 'certiva_availability':
				echo self::is_available( $post_id )
					? '<span style="color:#2271b1;font-weight:600;">' . esc_html__( 'Enabled', 'certiva' ) . '</span>'
					: '<span style="color:#646970;">' . esc_html__( 'Disabled', 'certiva' ) . '</span>';
				break;
			case 'certiva_event_registrations':
				echo esc_html( (string) count( RegistrationsRepository::get_for_event( $post_id ) ) );
				break;
		}
	}
}
