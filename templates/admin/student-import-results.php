<?php
/**
 * Import results summary.
 *
 * @var array{created:int, updated:int, skipped:int, skipped_reasons:string[], total:int} $results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\PostTypes\StudentPostType;
?>
<div class="wrap certiva-wrap">
	<h1><?php esc_html_e( 'Import Complete', 'certiva' ); ?></h1>

	<div class="notice notice-success"><p>
		<?php
		printf(
			/* translators: 1: number created, 2: number updated, 3: number skipped, 4: total rows */
			esc_html__( '%1$d student(s) created, %2$d updated, %3$d skipped, out of %4$d row(s) processed.', 'certiva' ),
			(int) $results['created'],
			(int) $results['updated'],
			(int) $results['skipped'],
			(int) $results['total']
		);
		?>
	</p></div>

	<?php if ( ! empty( $results['skipped_reasons'] ) ) : ?>
		<h2><?php esc_html_e( 'Skipped Rows', 'certiva' ); ?></h2>
		<ul style="list-style:disc;margin-left:20px;">
			<?php foreach ( $results['skipped_reasons'] as $reason ) : ?>
				<li><?php echo esc_html( $reason ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . StudentPostType::POST_TYPE ) ); ?>"><?php esc_html_e( 'View Students', 'certiva' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Certiva\Admin\StudentImportPage::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Import Another File', 'certiva' ); ?></a>
	</p>
</div>
