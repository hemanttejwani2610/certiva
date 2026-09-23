<?php
/**
 * Step 1: choose an event and upload a CSV of student emails to register.
 *
 * @var \WP_Post[] $events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$error = isset( $_GET['certiva_import_error'] ) ? sanitize_key( wp_unslash( $_GET['certiva_import_error'] ) ) : '';
$error_messages = [
	'invalid_event' => __( 'Please choose an event.', 'certiva' ),
	'upload_failed' => __( 'The file could not be uploaded. Please try again.', 'certiva' ),
	'not_csv'       => __( 'Please upload a .csv file.', 'certiva' ),
	'too_large'     => __( 'That file is too large (5 MB maximum).', 'certiva' ),
	'expired'       => __( 'Your import session expired before it was completed. Please upload the file again.', 'certiva' ),
];
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Bulk Register Students for an Event', 'certiva' ); ?></h1>

	<?php if ( $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_messages[ $error ] ?? __( 'The import could not be completed. Please try again.', 'certiva' ) ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Register many existing students for one event at once — for example, uploading a pass list after grading an exam. This only registers students who already exist in Certiva; if a row\'s email doesn\'t match a student, that row is skipped.', 'certiva' ); ?></p>
	<p class="description">
		<?php esc_html_e( 'Example columns: email, eligible, template_override. "eligible" accepts yes/no/1/0/true/false — leave it unmapped to register everyone as not-yet-eligible.', 'certiva' ); ?>
	</p>

	<?php if ( empty( $events ) ) : ?>
		<p><em><?php esc_html_e( 'Create an event first.', 'certiva' ); ?></em></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'certiva_bulk_register_upload' ); ?>
			<input type="hidden" name="action" value="certiva_bulk_register_upload" />
			<table class="form-table" role="presentation">
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
					<th><label for="csv_file"><?php esc_html_e( 'CSV File', 'certiva' ); ?></label></th>
					<td><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload & Continue', 'certiva' ) ); ?>
		</form>
	<?php endif; ?>
</div>
