<?php
/**
 * Bulk-registration results summary.
 *
 * @var array{registered:int, updated:int, skipped:int, skipped_reasons:string[], warnings:string[], total:int, event_id:int} $results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Admin\RegistrationImportPage;
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Bulk Registration Complete', 'certiva' ); ?></h1>

	<div class="notice notice-success"><p>
		<?php
		printf(
			/* translators: 1: number newly registered, 2: number updated, 3: number skipped, 4: total rows, 5: event title */
			esc_html__( '%1$d student(s) newly registered, %2$d existing registration(s) updated, %3$d skipped, out of %4$d row(s) — for %5$s.', 'certiva' ),
			(int) $results['registered'],
			(int) $results['updated'],
			(int) $results['skipped'],
			(int) $results['total'],
			esc_html( get_the_title( (int) $results['event_id'] ) )
		);
		?>
	</p></div>

	<?php if ( ! empty( $results['warnings'] ) ) : ?>
		<h2><?php esc_html_e( 'Warnings', 'certiva' ); ?></h2>
		<ul style="list-style:disc;margin-left:20px;">
			<?php foreach ( $results['warnings'] as $warning ) : ?>
				<li><?php echo esc_html( $warning ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $results['skipped_reasons'] ) ) : ?>
		<h2><?php esc_html_e( 'Skipped Rows', 'certiva' ); ?></h2>
		<ul style="list-style:disc;margin-left:20px;">
			<?php foreach ( $results['skipped_reasons'] as $reason ) : ?>
				<li><?php echo esc_html( $reason ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=certiva&event_id=' . (int) $results['event_id'] ) ); ?>"><?php esc_html_e( 'View Registrations', 'certiva' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . RegistrationImportPage::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Bulk Register Another File', 'certiva' ); ?></a>
	</p>
</div>
