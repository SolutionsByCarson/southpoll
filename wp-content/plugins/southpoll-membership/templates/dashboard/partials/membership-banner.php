<?php
/**
 * Membership status banner (dashboard home, my farm, my membership).
 *
 * @var array $membership  From MembershipPresenter::summary()
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;

$sp_state = (string) $membership['state'];
?>
<?php if ( 'buyer' === $sp_state ) : ?>
	<div class="sp-banner sp-banner--membership">
		<div>
			<strong><?php esc_html_e( 'Ready to list your cattle and join the member directory?', 'southpoll-membership' ); ?></strong>
			<div><?php echo esc_html( sprintf( /* translators: %s: price */ __( 'An SPGCA membership is %s per year and includes your own farm page, classified listings, and member benefits.', 'southpoll-membership' ), (string) $membership['price'] ) ); ?></div>
		</div>
		<a class="sp-btn sp-btn--secondary" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'Become a member', 'southpoll-membership' ); ?></a>
	</div>
<?php elseif ( 'active' === $sp_state && $membership['countdown'] ) : ?>
	<div class="sp-banner sp-banner--membership">
		<div>
			<strong><?php echo esc_html( (int) $membership['days'] <= 1 ? __( 'Your membership expires tomorrow.', 'southpoll-membership' ) : sprintf( /* translators: %d: days */ __( 'Your membership expires in %d days.', 'southpoll-membership' ), (int) $membership['days'] ) ); ?></strong>
			<div><?php echo esc_html( $membership['auto_renew'] ? sprintf( /* translators: %s: date */ __( 'Automatic renewal will run on %s.', 'southpoll-membership' ), (string) $membership['ends_display'] ) : sprintf( /* translators: %s: date */ __( 'Renew now to keep your farm page and listings live after %s.', 'southpoll-membership' ), (string) $membership['ends_display'] ) ); ?></div>
		</div>
		<?php if ( ! $membership['auto_renew'] && $context->is_owner() ) : ?><a class="sp-btn sp-btn--secondary" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'Renew now', 'southpoll-membership' ); ?></a><?php endif; ?>
	</div>
<?php elseif ( 'active' === $sp_state && $membership['expiring'] ) : ?>
	<div class="sp-banner sp-banner--light">
		<div>
			<strong><?php echo esc_html( sprintf( /* translators: 1: days, 2: date */ __( 'Your membership expires in %1$d days (%2$s).', 'southpoll-membership' ), (int) $membership['days'], (string) $membership['ends_display'] ) ); ?></strong>
			<div><?php echo esc_html( $membership['auto_renew'] ? __( 'Automatic renewal is on; no action needed.', 'southpoll-membership' ) : __( 'Renew early and the new year is added to the end of your current term.', 'southpoll-membership' ) ); ?></div>
		</div>
		<?php if ( $context->is_owner() ) : ?><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php echo esc_html( $membership['auto_renew'] ? __( 'Manage membership', 'southpoll-membership' ) : __( 'Renew or set up auto-renew', 'southpoll-membership' ) ); ?></a><?php endif; ?>
	</div>
<?php elseif ( 'active' === $sp_state ) : ?>
	<div class="sp-banner sp-banner--light">
		<div>
			<strong><?php echo esc_html( sprintf( /* translators: 1: farm name, 2: date */ __( '%1$s is an active SPGCA member through %2$s.', 'southpoll-membership' ), (string) $membership['farm_name'], (string) $membership['ends_display'] ) ); ?></strong>
			<div><?php echo esc_html( $membership['auto_renew'] ? __( 'Automatic renewal is on.', 'southpoll-membership' ) : __( 'We will remind you before it expires.', 'southpoll-membership' ) ); ?></div>
		</div>
		<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'My membership', 'southpoll-membership' ); ?></a>
	</div>
<?php elseif ( 'pending' === $sp_state || ( 'none' === $sp_state && $membership['pending_claim'] ) ) : ?>
	<div class="sp-banner sp-banner--light">
		<div>
			<strong><?php esc_html_e( 'Your offline payment is awaiting verification by the SPGCA office.', 'southpoll-membership' ); ?></strong>
			<div><?php esc_html_e( 'You will get an email as soon as it is confirmed. Your farm page stays private until then.', 'southpoll-membership' ); ?></div>
		</div>
		<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'View details', 'southpoll-membership' ); ?></a>
	</div>
<?php elseif ( 'none' === $sp_state ) : ?>
	<div class="sp-banner sp-banner--membership">
		<div>
			<strong><?php esc_html_e( 'Finish your membership to unlock your farm page and listings.', 'southpoll-membership' ); ?></strong>
			<div><?php echo esc_html( sprintf( /* translators: %s: price */ __( '%s per year. Pay online in a minute, or tell us you paid by check or cash.', 'southpoll-membership' ), (string) $membership['price'] ) ); ?></div>
		</div>
		<?php if ( $context->is_owner() ) : ?><a class="sp-btn sp-btn--secondary" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'Complete membership', 'southpoll-membership' ); ?></a><?php endif; ?>
	</div>
<?php else : ?>
	<div class="sp-banner sp-banner--membership">
		<div>
			<strong><?php echo esc_html( 'cancelled' === $sp_state ? __( 'Your membership was cancelled.', 'southpoll-membership' ) : sprintf( /* translators: %s: date */ __( 'Your membership expired on %s.', 'southpoll-membership' ), (string) $membership['ends_display'] ) ); ?></strong>
			<div><?php esc_html_e( 'Your farm page is hidden and listings are paused. Nothing was deleted; renew to bring everything back.', 'southpoll-membership' ); ?></div>
		</div>
		<?php if ( $context->is_owner() ) : ?><a class="sp-btn sp-btn--secondary" href="<?php echo esc_url( $membership['manage_url'] ); ?>"><?php esc_html_e( 'Renew membership', 'southpoll-membership' ); ?></a><?php endif; ?>
	</div>
<?php endif; ?>
