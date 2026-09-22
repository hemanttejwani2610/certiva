<?php
/**
 * Step 1: upload a student CSV file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$error = isset( $_GET['certiva_import_error'] ) ? sanitize_key( wp_unslash( $_GET['certiva_import_error'] ) ) : '';
$error_messages = [
	'upload_failed' => __( 'The file could not be uploaded. Please try again.', 'certiva' ),
	'not_csv'       => __( 'Please upload a .csv file.', 'certiva' ),
	'too_large'     => __( 'That file is too large (5 MB maximum).', 'certiva' ),
	'expired'       => __( 'Your import session expired before it was completed. Please upload the file again.', 'certiva' ),
];
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Import Students from CSV', 'certiva' ); ?></h1>

	<?php if ( $error && isset( $error_messages[ $error ] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_messages[ $error ] ); ?></p></div>
	<?php elseif ( $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The import could not be completed. Please try again.', 'certiva' ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Upload a CSV file with one row per student. The first row must be a header row with column names — you\'ll map those columns to student fields on the next screen.', 'certiva' ); ?></p>
	<p class="description">
		<?php esc_html_e( 'Example columns: full_name, email, student_id, course, grade — extra columns beyond name/email/student ID become custom certificate placeholders.', 'certiva' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( 'certiva_import_upload' ); ?>
		<input type="hidden" name="action" value="certiva_import_upload" />
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="csv_file"><?php esc_html_e( 'CSV File', 'certiva' ); ?></label></th>
				<td><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required /></td>
			</tr>
		</table>
		<?php submit_button( __( 'Upload & Continue', 'certiva' ) ); ?>
	</form>
</div>
