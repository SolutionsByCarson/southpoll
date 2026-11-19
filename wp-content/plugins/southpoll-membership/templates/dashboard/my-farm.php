<?php
/**
 * My Farm: editor + users manager (owner) or manager card.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var object $farm
 * @var array  $membership
 * @var array  $users
 * @var object|null $owner
 * @var string $editor
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<?php $view->display( 'dashboard/partials/membership-banner', compact( 'membership', 'context', 'urls' ) ); ?>

<?php echo $editor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- editor partial escapes its own output ?>

<?php if ( $context->is_owner() ) : ?>
	<div id="users"></div>
	<?php $view->display( 'dashboard/partials/farm-users', [ 'users' => $users, 'context' => $context, 'farm' => $farm ] ); ?>
<?php elseif ( $owner ) : ?>
	<div class="sp-card sp-card--notice">
		<h3 class="sp-h3"><?php esc_html_e( 'You are an account manager', 'southpoll-membership' ); ?></h3>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1: owner name, 2: farm */ __( '%1$s owns <strong>%2$s</strong> and handles membership, billing, and who else can help. Need to add someone? Forward the request to the owner.', 'southpoll-membership' ), esc_html( (string) $owner->display_name ), esc_html( (string) $farm->name ) ) ); ?></p>
		<a class="sp-btn sp-btn--outline" href="mailto:<?php echo esc_attr( (string) $owner->email ); ?>?subject=<?php echo rawurlencode( sprintf( 'SouthPoll.com: add a user to %s', (string) $farm->name ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: owner email */ __( 'Email %s', 'southpoll-membership' ), (string) $owner->email ) ); ?></a>
	</div>
<?php endif; ?>
