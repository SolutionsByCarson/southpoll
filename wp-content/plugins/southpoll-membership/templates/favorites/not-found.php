<?php
/**
 * Unknown favorites share token.
 *
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php esc_html_e( 'Shared favorites not found', 'southpoll-membership' ); ?></h1></header>
<div class="sp-card sp-card--notice">
	<p><?php esc_html_e( 'That share link is no longer valid. The owner may have regenerated it.', 'southpoll-membership' ); ?></p>
	<p><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse the classifieds', 'southpoll-membership' ); ?></a> <a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->directory() ); ?>"><?php esc_html_e( 'Member directory', 'southpoll-membership' ); ?></a></p>
</div>
