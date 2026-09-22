<?php
/**
 * Registrations admin screen.
 *
 * @var object[] $registrations
 * @var int      $total
 * @var int      $per_page
 * @var int      $paged
 * @var \WP_Post[] $students
 * @var \WP_Post[] $events
 * @var \WP_Post[] $templates
 * @var int      $filter_event
 * @var string   $filter_status
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\PostTypes\EventPostType;
use Certiva\Data\RegistrationsRepository;
use Certiva\Pdf\CertificateService;

$notice = isset( $_GET['certiva_notice'] ) ? sanitize_key( wp_unslash( $_GET['certiva_notice'] ) ) : '';
$notice_messages = [
	'added'     => [ 'success', __( 'Registration added.', 'certiva' ) ],
	'duplicate' => [ 'error', __( 'That student is already registered for that event.', 'certiva' ) ],
	'invalid'   => [ 'error', __( 'Please choose a valid student and event.', 'certiva' ) ],
];
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Certiva — Registrations', 'certiva' ); ?></h1>

	<?php if ( $notice && isset( $notice_messages[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice_messages[ $notice ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice_messages[ $notice ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Register a Student for an Event', 'certiva' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certiva-add-registration-form">
		<?php wp_nonce_field( 'certiva_add_registration' ); ?>
		<input type="hidden" name="action" value="certiva_add_registration" />
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva-student-search"><?php esc_html_e( 'Student', 'certiva' ); ?></label></th>
				<td>
					<input type="search" id="certiva-student-search" class="regular-text" placeholder="<?php esc_attr_e( 'Type to filter…', 'certiva' ); ?>" data-filter-target="student_id" />
					<br />
					<select name="student_id" id="student_id" required style="min-width:320px;">
						<option value=""><?php esc_html_e( '— Select a student —', 'certiva' ); ?></option>
						<?php foreach ( $students as $student ) : ?>
							<option value="<?php echo esc_attr( (string) $student->ID ); ?>">
								<?php echo esc_html( $student->post_title . ' — ' . get_post_meta( $student->ID, 'certiva_email', true ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="event_id"><?php esc_html_e( 'Event', 'certiva' ); ?></label></th>
				<td>
					<select name="event_id" id="event_id" required style="min-width:320px;">
						<option value=""><?php esc_html_e( '— Select an event —', 'certiva' ); ?></option>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->ID ); ?>"><?php echo esc_html( $event->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="template_override"><?php esc_html_e( 'Template Override', 'certiva' ); ?></label></th>
				<td>
					<select name="template_override" id="template_override" style="min-width:320px;">
						<option value=""><?php esc_html_e( '— Use event default —', 'certiva' ); ?></option>
						<?php foreach ( $templates as $template ) : ?>
							<option value="<?php echo esc_attr( (string) $template->ID ); ?>"><?php echo esc_html( $template->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Eligible', 'certiva' ); ?></th>
				<td>
					<label><input type="checkbox" name="eligible" value="1" /> <?php esc_html_e( 'Mark eligible for a certificate now', 'certiva' ); ?></label>
					<p class="description"><?php esc_html_e( 'Leave unchecked for exams/events where eligibility (e.g. passing) is decided later.', 'certiva' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Add Registration', 'certiva' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'All Registrations', 'certiva' ); ?></h2>

	<form method="get" class="certiva-filters">
		<input type="hidden" name="page" value="certiva" />
		<select name="event_id">
			<option value="0"><?php esc_html_e( 'All events', 'certiva' ); ?></option>
			<?php foreach ( $events as $event ) : ?>
				<option value="<?php echo esc_attr( (string) $event->ID ); ?>" <?php selected( $filter_event, $event->ID ); ?>><?php echo esc_html( $event->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'certiva' ); ?></option>
			<option value="pending" <?php selected( $filter_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'certiva' ); ?></option>
			<option value="generated" <?php selected( $filter_status, 'generated' ); ?>><?php esc_html_e( 'Generated', 'certiva' ); ?></option>
		</select>
		<?php submit_button( __( 'Filter', 'certiva' ), '', '', false ); ?>
	</form>

	<table class="widefat striped certiva-registrations-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Student', 'certiva' ); ?></th>
				<th><?php esc_html_e( 'Event', 'certiva' ); ?></th>
				<th><?php esc_html_e( 'Eligible', 'certiva' ); ?></th>
				<th><?php esc_html_e( 'Certificate Status', 'certiva' ); ?></th>
				<th><?php esc_html_e( 'Certificate ID', 'certiva' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'certiva' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $registrations ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No registrations found.', 'certiva' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $registrations as $registration ) : ?>
				<?php
				$available = EventPostType::is_available( (int) $registration->event_id );
				$preview_url = wp_nonce_url(
					add_query_arg(
						[
							'action' => 'certiva_admin_preview',
							'rid'    => $registration->id,
						],
						admin_url( 'admin-post.php' )
					),
					'certiva_admin_preview_' . $registration->id
				);
				$download_url = wp_nonce_url(
					add_query_arg(
						[
							'action' => 'certiva_admin_download',
							'rid'    => $registration->id,
						],
						admin_url( 'admin-post.php' )
					),
					'certiva_admin_download_' . $registration->id
				);
				?>
				<tr data-registration-id="<?php echo esc_attr( (string) $registration->id ); ?>">
					<td><a href="<?php echo esc_url( get_edit_post_link( $registration->student_id ) ); ?>"><?php echo esc_html( get_the_title( $registration->student_id ) ); ?></a></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $registration->event_id ) ); ?>"><?php echo esc_html( get_the_title( $registration->event_id ) ); ?></a></td>
					<td>
						<label>
							<input type="checkbox" class="certiva-toggle-eligible" <?php checked( (bool) $registration->eligible ); ?> />
							<?php echo $available ? '' : '<span class="description">' . esc_html__( '(event certificates disabled)', 'certiva' ) . '</span>'; ?>
						</label>
					</td>
					<td>
						<?php if ( RegistrationsRepository::STATUS_GENERATED === $registration->status ) : ?>
							<span class="certiva-badge certiva-badge-success"><?php esc_html_e( 'Generated', 'certiva' ); ?></span>
							<?php if ( $registration->regenerated_at ) : ?>
								<br /><small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Regenerated %s', 'certiva' ), mysql2date( 'M j, Y g:ia', $registration->regenerated_at ) ) ); ?></small>
							<?php endif; ?>
						<?php else : ?>
							<span class="certiva-badge"><?php esc_html_e( 'Not generated', 'certiva' ); ?></span>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $registration->certificate_id ?: '—' ); ?></code></td>
					<td class="certiva-actions">
						<a href="<?php echo esc_url( $preview_url ); ?>" class="button button-small" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'certiva' ); ?></a>
						<?php if ( RegistrationsRepository::STATUS_GENERATED !== $registration->status ) : ?>
							<button type="button" class="button button-small button-primary certiva-generate"><?php esc_html_e( 'Generate', 'certiva' ); ?></button>
						<?php else : ?>
							<button type="button" class="button button-small certiva-regenerate"><?php esc_html_e( 'Regenerate', 'certiva' ); ?></button>
							<a href="<?php echo esc_url( $download_url ); ?>" class="button button-small"><?php esc_html_e( 'Download', 'certiva' ); ?></a>
							<button type="button" class="button button-small certiva-resend"><?php esc_html_e( 'Resend Email', 'certiva' ); ?></button>
						<?php endif; ?>
						<button type="button" class="button button-small button-link-delete certiva-delete"><?php esc_html_e( 'Remove', 'certiva' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $total / $per_page );
	if ( $total_pages > 1 ) :
		?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					[
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
					]
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
