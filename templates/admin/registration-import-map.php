<?php
/**
 * Step 2: map CSV columns for bulk-registering students to one event.
 *
 * @var string $import_id
 * @var string $event_title
 * @var array{headers: string[], preview: string[][], total_data_rows: int} $preview
 * @var array  $target_labels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Import\RegistrationImporter;

$guess_target = static function ( string $header ): string {
	$h = strtolower( trim( $header ) );

	if ( false !== strpos( $h, 'email' ) ) {
		return RegistrationImporter::TARGET_EMAIL;
	}
	if ( preg_match( '/(eligible|pass|status)/', $h ) ) {
		return RegistrationImporter::TARGET_ELIGIBLE;
	}
	if ( preg_match( '/template/', $h ) ) {
		return RegistrationImporter::TARGET_TEMPLATE_OVERRIDE;
	}

	return RegistrationImporter::TARGET_SKIP;
};
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Bulk Register — Map Columns', 'certiva' ); ?></h1>

	<p>
		<?php
		printf(
			/* translators: 1: number of data rows, 2: event title */
			esc_html__( 'Found %1$d data row(s). These will be registered for: %2$s', 'certiva' ),
			(int) $preview['total_data_rows'],
			'<strong>' . esc_html( $event_title ) . '</strong>'
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'certiva_bulk_register_process_' . $import_id ); ?>
		<input type="hidden" name="action" value="certiva_bulk_register_process" />
		<input type="hidden" name="import_id" value="<?php echo esc_attr( $import_id ); ?>" />

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'CSV Column', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Sample Values', 'certiva' ); ?></th>
					<th><?php esc_html_e( 'Maps To', 'certiva' ); ?></th>
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
					$samples        = array_filter( $samples, static fn( $v ) => '' !== $v );
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
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( __( 'Register Students', 'certiva' ) ); ?>
	</form>
</div>
