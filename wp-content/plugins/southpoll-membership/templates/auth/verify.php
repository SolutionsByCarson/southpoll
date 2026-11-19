<?php
/**
 * Shown only when a verification link is invalid/expired (success redirects with a toast).
 *
 * @var bool $ok
 * @var bool $logged_in
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php esc_html_e( 'Email verification', 'southpoll-membership' ); ?></h1></header>
<div class="sp-card" style="max-width:560px">
	<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'This verification link is not valid. It may have expired, already been used, or belong to a different account.', 'southpoll-membership' ); ?></div>
	<?php if ( $logged_in ) : ?>
		<p><?php esc_html_e( 'You can request a fresh verification email from your account details page.', 'southpoll-membership' ); ?></p>
		<a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Account details', 'southpoll-membership' ); ?></a>
	<?php else : ?>
		<p><?php esc_html_e( 'Log in and request a fresh verification email from your account details page.', 'southpoll-membership' ); ?></p>
		<a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->login( $urls->dashboard( 'account-details' ) ) ); ?>"><?php esc_html_e( 'Log in', 'southpoll-membership' ); ?></a>
	<?php endif; ?>
</div>
