<?php
/**
 * My Farm / My Listings for an account that has no farm of its own (staff). Members without a farm are redirected
 * before this renders.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls           $urls
 * @var string                                       $screen  farm|listings
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sp-card sp-card--notice">
	<h3 class="sp-h3"><?php echo 'listings' === $screen ? esc_html__( 'No listings on this account', 'southpoll-membership' ) : esc_html__( 'No farm on this account', 'southpoll-membership' ); ?></h3>
	<?php if ( $context->is_staff() ) : ?>
		<p><?php esc_html_e( 'You are signed in with a staff account, which does not have a farm page of its own. Member farms and their listings are managed from the SouthPoll admin screens; to see the member experience, sign in with a member account.', 'southpoll-membership' ); ?></p>
		<div class="sp-cluster">
			<a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=southpoll-farms' ) ); ?>"><?php esc_html_e( 'Manage farms', 'southpoll-membership' ); ?></a>
			<a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=southpoll-listings' ) ); ?>"><?php esc_html_e( 'Manage listings', 'southpoll-membership' ); ?></a>
			<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->directory() ); ?>"><?php esc_html_e( 'Member directory', 'southpoll-membership' ); ?></a>
		</div>
	<?php else : ?>
		<p><?php esc_html_e( 'Join as a member farm to get a farm page and classified listings.', 'southpoll-membership' ); ?></p>
		<a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $urls->dashboard( 'manage-membership' ) ); ?>"><?php esc_html_e( 'My Membership', 'southpoll-membership' ); ?></a>
	<?php endif; ?>
</div>
