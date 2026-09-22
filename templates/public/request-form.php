<?php
/**
 * [certiva_certificate_request] form markup.
 *
 * @var array{type: string, message: string}|null $flash
 * @var string $current_url
 * @var string $form_action
 * @var string $nonce_field
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certiva\Security\BotGuard;

$field_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'certiva-email-' ) : 'certiva-email-' . wp_rand();
$desc_id  = $field_id . '-desc';
?>
<div class="certiva-public-form">
	<?php if ( $flash ) : ?>
		<div class="certiva-notice <?php echo 'error' === $flash['type'] ? 'certiva-notice-error' : ''; ?>" role="status">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $form_action ); ?>" novalidate>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output, already escaped. ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( \Certiva\Public\RequestController::ACTION ); ?>" />
		<input type="hidden" name="certiva_redirect_to" value="<?php echo esc_attr( $current_url ); ?>" />
		<?php BotGuard::timestamp_field_html(); ?>
		<?php BotGuard::honeypot_field_html(); ?>

		<div class="certiva-field">
			<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Email Address', 'certiva' ); ?> <span aria-hidden="true">*</span></label>
			<input
				type="email"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="certiva_email"
				required
				aria-required="true"
				aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
				autocomplete="email"
			/>
			<p id="<?php echo esc_attr( $desc_id ); ?>" class="description">
				<?php esc_html_e( "Enter the email address you registered with. We'll email you a secure download link for any certificates available to you.", 'certiva' ); ?>
			</p>
		</div>

		<button type="submit" class="button certiva-submit"><?php esc_html_e( 'Request Certificate', 'certiva' ); ?></button>
	</form>
</div>
