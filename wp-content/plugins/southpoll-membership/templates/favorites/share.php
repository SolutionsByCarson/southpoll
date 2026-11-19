<?php
/**
 * Public shared favorites list.
 *
 * @var string $sharer
 * @var array  $farms     directory cards (with html)
 * @var array  $listings  listing cards (with html)
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var bool   $is_mine
 * @var array  $share
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header sp-directory__header">
	<div>
		<p class="sp-eyebrow"><?php esc_html_e( 'Shared favorites', 'southpoll-membership' ); ?></p>
		<h1 class="sp-page__title"><?php echo esc_html( sprintf( /* translators: %s: name */ __( '%s’s favorites', 'southpoll-membership' ), $sharer ) ); ?></h1>
		<p class="sp-text-muted" style="margin:6px 0 0"><?php echo esc_html( sprintf( /* translators: 1: farms, 2: listings */ __( '%1$d farms · %2$d listings', 'southpoll-membership' ), count( $farms ), count( $listings ) ) ); ?></p>
	</div>
	<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" data-sp-share="<?php echo esc_url( $share['url'] ); ?>" data-sp-share-title="<?php echo esc_attr( $share['title'] ); ?>"><?php echo Icons::svg( 'share', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share', 'southpoll-membership' ); ?></button>
</header>
<?php if ( $is_mine ) : ?><div class="sp-alert sp-alert--info"><?php esc_html_e( 'This is your public favorites list. Anyone with the link can see it; regenerate the link from My Favorites to revoke it.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->my_favorites() ); ?>"><?php esc_html_e( 'My Favorites', 'southpoll-membership' ); ?></a></div><?php endif; ?>

<?php if ( empty( $farms ) && empty( $listings ) ) : ?>
	<div class="sp-empty"><?php esc_html_e( 'Nothing on this list is public right now.', 'southpoll-membership' ); ?></div>
<?php endif; ?>
<?php if ( ! empty( $farms ) ) : ?>
	<section class="sp-section"><h2 class="sp-h3"><?php esc_html_e( 'Farms', 'southpoll-membership' ); ?></h2><div class="sp-farm-grid"><?php foreach ( $farms as $f ) : ?><?php echo $f['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endforeach; ?></div></section>
<?php endif; ?>
<?php if ( ! empty( $listings ) ) : ?>
	<section class="sp-section"><h2 class="sp-h3"><?php esc_html_e( 'Listings', 'southpoll-membership' ); ?></h2><div class="sp-listing-list"><?php foreach ( $listings as $l ) : ?><?php echo $l['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endforeach; ?></div></section>
<?php endif; ?>
