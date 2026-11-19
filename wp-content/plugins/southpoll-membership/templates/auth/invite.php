<?php
/**
 * Account-manager invitation landing page (guest view; logged-in users are auto-accepted and redirected).
 *
 * @var object|null $invite
 * @var object|null $farm
 * @var string      $token
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php esc_html_e( 'Farm invitation', 'southpoll-membership' ); ?></h1></header>
<div class="sp-card" style="max-width:600px">
	<?php if ( ! $invite || ! $farm ) : ?>
		<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'This invitation is no longer valid. It may have expired (invitations last 14 days) or been withdrawn. Ask the farm owner to send a new one.', 'southpoll-membership' ); ?></div>
		<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->login() ); ?>"><?php esc_html_e( 'Go to login', 'southpoll-membership' ); ?></a>
	<?php else : ?>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1: farm name, 2: email */ __( 'You have been invited to help manage <strong>%1$s</strong> on SouthPoll.com. The invitation was sent to <strong>%2$s</strong>; log in or create an account with that address to accept it.', 'southpoll-membership' ), esc_html( (string) $farm->name ), esc_html( (string) $invite->invite_email ) ) ); ?></p>
		<p><?php esc_html_e( 'As an account manager you can update the farm page and create or edit classified listings. The farm owner handles membership and billing.', 'southpoll-membership' ); ?></p>
		<div class="sp-form__actions">
			<a class="sp-btn sp-btn--primary" href="<?php echo esc_url( add_query_arg( [ 'invite' => $token, 'redirect_to' => rawurlencode( $urls->dashboard( 'my-farm' ) ) ], $urls->login() ) ); ?>"><?php esc_html_e( 'Log in to accept', 'southpoll-membership' ); ?></a>
			<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( add_query_arg( [ 'action' => 'register', 'invite' => $token, 'type' => 'buyer' ], $urls->login() ) ); ?>"><?php esc_html_e( 'Create an account', 'southpoll-membership' ); ?></a>
		</div>
	<?php endif; ?>
</div>
