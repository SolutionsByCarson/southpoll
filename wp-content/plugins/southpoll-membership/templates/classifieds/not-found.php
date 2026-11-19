<?php
/**
 * Unknown category or shared-search token.
 *
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var string $what category|search
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php echo 'search' === $what ? esc_html__( 'Shared search not found', 'southpoll-membership' ) : esc_html__( 'Category not found', 'southpoll-membership' ); ?></h1></header>
<div class="sp-card sp-card--notice">
	<p><?php echo 'search' === $what ? esc_html__( 'That shared search link is no longer valid. The search may have been removed or the link regenerated.', 'southpoll-membership' ) : esc_html__( 'We could not find a classifieds category at that address.', 'southpoll-membership' ); ?></p>
	<p><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse all classifieds', 'southpoll-membership' ); ?></a></p>
</div>
