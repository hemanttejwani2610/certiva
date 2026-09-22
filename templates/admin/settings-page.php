<?php
/**
 * Certiva settings screen.
 *
 * @var array $options
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Admin\SettingsPage;
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Certiva Settings', 'certiva' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'certiva_settings_group' ); ?>

		<h2><?php esc_html_e( 'Secure Download Links', 'certiva' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_download_link_expiry_hours"><?php esc_html_e( 'Link expiry (hours)', 'certiva' ); ?></label></th>
				<td>
					<input type="number" min="1" max="720" id="certiva_download_link_expiry_hours" name="certiva_settings[download_link_expiry_hours]" value="<?php echo esc_attr( (string) $options['download_link_expiry_hours'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long an emailed download link stays valid. Expired links are handled gracefully — the visitor is prompted to request a new one.', 'certiva' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Rate Limiting & Abuse Protection', 'certiva' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_rate_limit_ip_max"><?php esc_html_e( 'Max requests per IP address', 'certiva' ); ?></label></th>
				<td>
					<input type="number" min="1" id="certiva_rate_limit_ip_max" name="certiva_settings[rate_limit_ip_max]" value="<?php echo esc_attr( (string) $options['rate_limit_ip_max'] ); ?>" class="small-text" />
					<?php esc_html_e( 'per', 'certiva' ); ?>
					<input type="number" min="1" name="certiva_settings[rate_limit_ip_window_min]" value="<?php echo esc_attr( (string) $options['rate_limit_ip_window_min'] ); ?>" class="small-text" />
					<?php esc_html_e( 'minutes', 'certiva' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="certiva_rate_limit_email_max"><?php esc_html_e( 'Max requests per email address', 'certiva' ); ?></label></th>
				<td>
					<input type="number" min="1" id="certiva_rate_limit_email_max" name="certiva_settings[rate_limit_email_max]" value="<?php echo esc_attr( (string) $options['rate_limit_email_max'] ); ?>" class="small-text" />
					<?php esc_html_e( 'per', 'certiva' ); ?>
					<input type="number" min="1" name="certiva_settings[rate_limit_email_window_min]" value="<?php echo esc_attr( (string) $options['rate_limit_email_window_min'] ); ?>" class="small-text" />
					<?php esc_html_e( 'minutes', 'certiva' ); ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Outgoing Email', 'certiva' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="certiva_email_from_name"><?php esc_html_e( 'From Name', 'certiva' ); ?></label></th>
				<td><input type="text" id="certiva_email_from_name" name="certiva_settings[email_from_name]" value="<?php echo esc_attr( $options['email_from_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="certiva_email_from_address"><?php esc_html_e( 'From Address', 'certiva' ); ?></label></th>
				<td><input type="email" id="certiva_email_from_address" name="certiva_settings[email_from_address]" value="<?php echo esc_attr( $options['email_from_address'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Uninstall', 'certiva' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'On plugin uninstall', 'certiva' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="certiva_settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $options['delete_data_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Permanently delete all Certiva students, events, templates, registrations, and generated certificate files when the plugin is deleted.', 'certiva' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Certiva never deletes your data on uninstall unless this is explicitly checked.', 'certiva' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
