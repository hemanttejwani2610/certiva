<?php
/**
 * HTML email body listing available certificates with secure download links.
 *
 * @var array $items Each: ['event' => string, 'url' => string].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_name = get_bloginfo( 'name' );
$hours     = (int) \Certiva\Admin\SettingsPage::get( 'download_link_expiry_hours' );
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8" /></head>
<body style="font-family:sans-serif;color:#1d2327;line-height:1.5;">
	<p><?php echo esc_html__( 'Hello,', 'certiva' ); ?></p>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: site name */
				__( 'The following certificate(s) are available for download from %s:', 'certiva' ),
				$site_name
			)
		);
		?>
	</p>
	<ul>
		<?php foreach ( $items as $item ) : ?>
			<li style="margin-bottom:10px;">
				<strong><?php echo esc_html( $item['event'] ); ?></strong><br />
				<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html__( 'Download certificate', 'certiva' ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of hours */
				__( 'Each link is valid for %d hours and works for that certificate only. If a link expires, simply request it again.', 'certiva' ),
				$hours
			)
		);
		?>
	</p>
	<p><?php echo esc_html__( "If you did not request this, you can safely ignore this email.", 'certiva' ); ?></p>
</body>
</html>
