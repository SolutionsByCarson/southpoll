<?php
/**
 * Unknown listing slug (HTTP 404).
 *
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php esc_html_e( 'Listing not found', 'southpoll-membership' ); ?></h1></header>
<div class="sp-card sp-card--notice">
	<p><?php esc_html_e( 'We could not find a classified listing at that address.', 'southpoll-membership' ); ?></p>
	<p><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse the classifieds', 'southpoll-membership' ); ?></a></p>
</div>
