<?php
/**
 * Choose a new password (from the reset email link).
 *
 * @var bool        $invalid
 * @var string|null $error
 * @var string      $key
 * @var string      $login
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php esc_html_e( 'Choose a new password', 'southpoll-membership' ); ?></h1></header>
<?php if ( $invalid ) : ?>
	<div class="sp-card" style="max-width:520px">
		<div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'This reset link cannot be used', 'southpoll-membership' ); ?></span><?php echo esc_html( (string) $error ); ?></div>
		<a class="sp-btn sp-btn--primary" href="<?php echo esc_url( add_query_arg( 'action', 'lost', $urls->login() ) ); ?>"><?php esc_html_e( 'Request a new link', 'southpoll-membership' ); ?></a>
	</div>
<?php else : ?>
	<form method="post" class="sp-form sp-card" style="max-width:520px">
		<?php wp_nonce_field( 'sp_reset', 'sp_nonce' ); ?>
		<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
		<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
		<?php if ( $error ) : ?><div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'The password was not changed', 'southpoll-membership' ); ?></span><?php echo esc_html( (string) $error ); ?></div><?php endif; ?>
		<div class="sp-field"><label class="sp-label" for="sp-new-pass"><?php esc_html_e( 'New password', 'southpoll-membership' ); ?></label><input id="sp-new-pass" class="sp-input" type="password" name="password" autocomplete="new-password" minlength="8" required><div class="sp-help"><?php esc_html_e( 'At least 8 characters.', 'southpoll-membership' ); ?></div></div>
		<div class="sp-field"><label class="sp-label" for="sp-new-pass2"><?php esc_html_e( 'Confirm new password', 'southpoll-membership' ); ?></label><input id="sp-new-pass2" class="sp-input" type="password" name="password_confirm" autocomplete="new-password" required></div>
		<div class="sp-form__actions"><button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Save new password', 'southpoll-membership' ); ?></button></div>
	</form>
<?php endif; ?>
