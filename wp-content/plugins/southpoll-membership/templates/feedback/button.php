<?php
/**
 * Floating feedback button + the modal form template it opens (S8). Printed by Feedback\FeedbackButton on the login
 * page and every member-dashboard page while Settings → Launch & feedback → feedback is on.
 *
 * @var string $label
 * @var bool   $logged_in
 * @var string $name
 * @var string $email
 * @var bool   $recaptcha   guest form needs a reCAPTCHA v3 token
 * @var int    $max_mb
 * @var int    $max_chars
 */

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;
?>
<button type="button" class="sp-feedback-fab sp-scope" id="sp-feedback-fab" data-sp-feedback-open aria-haspopup="dialog" aria-controls="sp-feedback-dialog"><?php echo Icons::svg( 'mail', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $label ); ?></span></button>
<template id="sp-feedback-template" data-logged-in="<?php echo $logged_in ? '1' : '0'; ?>" data-recaptcha="<?php echo $recaptcha ? '1' : '0'; ?>" data-recaptcha-action="feedback" data-max-mb="<?php echo (int) $max_mb; ?>" data-title="<?php echo esc_attr( $label ); ?>" data-sent-title="<?php esc_attr_e( 'Thank you!', 'southpoll-membership' ); ?>" data-sent-text="<?php esc_attr_e( 'Your message is on its way to the team. We read every one.', 'southpoll-membership' ); ?>" data-send="<?php esc_attr_e( 'Send feedback', 'southpoll-membership' ); ?>" data-sending="<?php esc_attr_e( 'Sending…', 'southpoll-membership' ); ?>" data-cancel="<?php esc_attr_e( 'Cancel', 'southpoll-membership' ); ?>" data-done="<?php esc_attr_e( 'Done', 'southpoll-membership' ); ?>" data-fix="<?php esc_attr_e( 'Please check the highlighted field.', 'southpoll-membership' ); ?>">
	<form class="sp-form sp-feedback-form" novalidate>
		<?php echo Honeypot::fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<input type="hidden" name="<?php echo esc_attr( Recaptcha::FIELD ); ?>" value="">
		<input type="hidden" name="elapsed" value="0">
		<p class="sp-text-small sp-text-muted sp-feedback-form__intro"><?php esc_html_e( 'Found a problem, something confusing, or an idea? Tell us below. The page you are on and your browser are sent along automatically.', 'southpoll-membership' ); ?></p>
		<?php if ( ! $logged_in ) : ?>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field" data-field="name"><label class="sp-label" for="sp-fb-name"><?php esc_html_e( 'Your name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-fb-name" class="sp-input" name="name" value="<?php echo esc_attr( $name ); ?>" autocomplete="name" required></div>
				<div class="sp-field" data-field="email"><label class="sp-label" for="sp-fb-email"><?php esc_html_e( 'Your e-mail', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-fb-email" class="sp-input" type="email" name="email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" required></div>
			</div>
		<?php else : ?>
			<p class="sp-text-small sp-feedback-form__who"><?php echo esc_html( sprintf( /* translators: 1: name, 2: e-mail */ __( 'Sending as %1$s (%2$s).', 'southpoll-membership' ), $name, $email ) ); ?></p>
			<input type="hidden" name="name" value="<?php echo esc_attr( $name ); ?>">
		<?php endif; ?>
		<div class="sp-field" data-field="message"><label class="sp-label" for="sp-fb-message"><?php esc_html_e( 'What happened, or what would you like to see?', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><textarea id="sp-fb-message" class="sp-textarea" name="message" rows="5" maxlength="<?php echo (int) $max_chars; ?>" required placeholder="<?php esc_attr_e( 'e.g. I pressed Publish on my listing and nothing happened…', 'southpoll-membership' ); ?>"></textarea></div>
		<?php if ( $logged_in ) : ?>
			<div class="sp-field" data-field="screenshot">
				<span class="sp-label"><?php esc_html_e( 'Screenshot (optional)', 'southpoll-membership' ); ?></span>
				<div class="sp-cluster">
					<label class="sp-btn sp-btn--outline sp-btn--sm"><?php echo Icons::svg( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Choose image', 'southpoll-membership' ); ?><input type="file" name="screenshot" accept="image/*" class="sp-sr-only"></label>
					<span class="sp-text-small sp-text-muted" data-screenshot-name><?php echo esc_html( sprintf( /* translators: %d: MB */ __( 'JPG, PNG or WebP up to %d MB.', 'southpoll-membership' ), (int) $max_mb ) ); ?></span>
				</div>
			</div>
		<?php endif; ?>
		<div class="sp-alert sp-alert--error" role="alert" data-form-error hidden></div>
	</form>
</template>
