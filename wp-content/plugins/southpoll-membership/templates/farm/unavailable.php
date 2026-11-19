<?php
/**
 * Farm whose membership is not active (guests and other members). Minimal, no contact info, never a 404.
 *
 * @var object $farm
 * @var string $state
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var \SouthPoll\Membership\Members\MemberContext $context
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header">
	<p class="sp-eyebrow"><?php esc_html_e( 'Member directory', 'southpoll-membership' ); ?></p>
	<h1 class="sp-page__title"><?php echo esc_html( (string) $farm->name ); ?></h1>
</header>
<div class="sp-card sp-card--notice">
	<p><?php esc_html_e( 'This farm’s SPGCA membership is not active right now, so its page and listings are not available.', 'southpoll-membership' ); ?></p>
	<p><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->directory() ); ?>"><?php esc_html_e( 'Browse member farms', 'southpoll-membership' ); ?></a> <a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></a></p>
</div>
