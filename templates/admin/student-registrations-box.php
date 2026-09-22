<?php
/**
 * Meta box on the Student edit screen: lists this student's registrations
 * and certificate status.
 *
 * @var \WP_Post $post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Data\RegistrationsRepository;
use Certiva\PostTypes\EventPostType;

$registrations = RegistrationsRepository::get_for_student( $post->ID );
?>
<div class="certiva-student-registrations">
	<?php if ( empty( $registrations ) ) : ?>
		<p><?php esc_html_e( 'This student is not registered for any events yet.', 'certiva' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Event', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Type', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Eligible', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Status', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Certificate ID', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'certiva' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $registrations as $registration ) : ?>
					<?php
					$event_type = get_post_meta( $registration->event_id, 'certiva_event_type', true );
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
						<td><a href="<?php echo esc_url( get_edit_post_link( $registration->event_id ) ); ?>"><?php echo esc_html( get_the_title( $registration->event_id ) ); ?></a></td>
						<td><?php echo esc_html( EventPostType::types()[ $event_type ] ?? $event_type ); ?></td>
						<td><label><input type="checkbox" class="certiva-toggle-eligible" <?php checked( (bool) $registration->eligible ); ?> /></label></td>
						<td>
							<?php echo RegistrationsRepository::STATUS_GENERATED === $registration->status
								? '<span class="certiva-badge certiva-badge-success">' . esc_html__( 'Generated', 'certiva' ) . '</span>'
								: '<span class="certiva-badge">' . esc_html__( 'Not generated', 'certiva' ) . '</span>'; ?>
						</td>
						<td><code><?php echo esc_html( $registration->certificate_id ?: '—' ); ?></code></td>
						<td class="certiva-actions">
							<a href="<?php echo esc_url( $preview_url ); ?>" class="button button-small" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'certiva' ); ?></a>
							<?php if ( RegistrationsRepository::STATUS_GENERATED !== $registration->status ) : ?>
								<button type="button" class="button button-small button-primary certiva-generate"><?php esc_html_e( 'Generate', 'certiva' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-small certiva-regenerate"><?php esc_html_e( 'Regenerate', 'certiva' ); ?></button>
								<a href="<?php echo esc_url( $download_url ); ?>" class="button button-small"><?php esc_html_e( 'Download', 'certiva' ); ?></a>
								<button type="button" class="button button-small certiva-resend"><?php esc_html_e( 'Resend', 'certiva' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: URL to the Registrations admin page */
			esc_html__( 'To register this student for another event, use the %s screen.', 'certiva' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=certiva' ) ) . '">' . esc_html__( 'Registrations', 'certiva' ) . '</a>'
		);
		?>
	</p>
</div>
