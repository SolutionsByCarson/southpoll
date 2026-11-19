<?php
/**
 * Listing that is no longer public (expired, withdrawn, sold window closed, or farm membership inactive). Never a 404.
 *
 * @var \SouthPoll\Membership\Listings\Listing $listing
 * @var object|null $farm
 * @var bool   $farm_public
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var \SouthPoll\Membership\Members\MemberContext $context
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header">
	<p class="sp-eyebrow"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></p>
	<h1 class="sp-page__title"><?php esc_html_e( 'This listing is no longer available', 'southpoll-membership' ); ?></h1>
</header>
<div class="sp-card sp-card--notice">
	<p><?php echo esc_html( sprintf( /* translators: %s: title */ __( '“%s” has been sold, withdrawn, or has expired.', 'southpoll-membership' ), $listing->title ) ); ?><?php if ( $farm && $farm_public ) : ?> <?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'You can still reach %s through their farm page.', 'southpoll-membership' ), (string) $farm->name ) ); ?><?php endif; ?></p>
	<p>
		<?php if ( $farm && $farm_public ) : ?><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->farm( (string) $farm->slug ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'Visit %s', 'southpoll-membership' ), (string) $farm->name ) ); ?></a> <?php endif; ?>
		<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse the classifieds', 'southpoll-membership' ); ?></a>
	</p>
</div>
