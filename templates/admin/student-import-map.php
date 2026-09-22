<?php
/**
 * Step 2: map CSV columns to student fields.
 *
 * @var string $import_id
 * @var array{headers: string[], preview: string[][], total_data_rows: int} $preview
 * @var array  $target_labels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Import\StudentImporter;

/**
 * Best-guess default mapping from a CSV header name, purely to save admins
 * a click on the common case — they can change any of these.
 */
$guess_target = static function ( string $header ): string {
	$h = strtolower( trim( $header ) );

	if ( false !== strpos( $h, 'email' ) ) {
		return StudentImporter::TARGET_EMAIL;
	}
	if ( preg_match( '/(full[_\s-]?name|^name$|student[_\s-]?name)/', $h ) ) {
		return StudentImporter::TARGET_FULL_NAME;
	}
	if ( preg_match( '/(student[_\s-]?id|roll[_\s-]?no|roll[_\s-]?number|enrollment)/', $h ) ) {
		return StudentImporter::TARGET_STUDENT_ID;
	}

	return StudentImporter::TARGET_SKIP;
};
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Import Students — Map Columns', 'certiva' ); ?></h1>

	<p>
		<?php
		printf(
			/* translators: %d: number of data rows detected in the file */
			esc_html__( 'Found %d data row(s). Map each column below, then import.', 'certiva' ),
			(int) $preview['total_data_rows']
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'certiva_import_process_' . $import_id ); ?>
		<input type="hidden" name="action" value="certiva_import_process" />
		<input type="hidden" name="import_id" value="<?php echo esc_attr( $import_id ); ?>" />

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'CSV Column', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Sample Values', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Maps To', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Placeholder Label (if "Extra Placeholder Field")', 'certiva' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $preview['headers'] as $index => $header ) : ?>
					<?php
					$samples = array_map(
						static function ( $row ) use ( $index ) {
							return isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
						},
						$preview['preview']
					);
					$samples = array_filter( $samples, static fn( $v ) => '' !== $v );
					$default_target = $guess_target( $header );
					?>
					<tr>
						<td><strong><?php echo esc_html( $header ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', array_slice( $samples, 0, 3 ) ) ); ?></td>
						<td>
							<select name="column_target[<?php echo esc_attr( (string) $index ); ?>]">
								<?php foreach ( $target_labels as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $default_target, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="text" name="column_label[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( $header ); ?>" class="regular-text" />
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<label>
				<input type="checkbox" name="update_existing" value="1" checked="checked" />
				<?php esc_html_e( 'If a row\'s email matches an existing student, update that student instead of creating a duplicate.', 'certiva' ); ?>
			</label>
		</p>

		<?php submit_button( __( 'Import Students', 'certiva' ) ); ?>
	</form>
</div>
