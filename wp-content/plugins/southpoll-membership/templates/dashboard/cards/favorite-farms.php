<?php
/**
 * Dashboard home card: favorites (farms + listings).
 *
 * @var array $items           favorite farm cards (+ notify)
 * @var array $listings        favorite listing cards (+ notify, active)
 * @var int   $total           farm favorites
 * @var int   $total_listings  listing favorites
 * @var int   $inactive        inactive listing favorites
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$listings       = $listings ?? [];
$total_listings = $total_listings ?? 0;
$inactive       = $inactive ?? 0;
$active_list    = array_values( array_filter( $listings, static fn( $l ) => ! empty( $l['active'] ) ) );
?>
<div class="sp-card">
	<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'Favorites', 'southpoll-membership' ); ?> <span class="sp-badge"><?php echo esc_html( (string) ( $total + $total_listings ) ); ?></span></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->my_favorites() ); ?>"><?php esc_html_e( 'View all', 'southpoll-membership' ); ?></a></div>

	<?php if ( $total_listings > 0 ) : ?>
		<p class="sp-eyebrow" style="margin-bottom:6px"><?php esc_html_e( 'Listings', 'southpoll-membership' ); ?></p>
		<?php if ( empty( $active_list ) ) : ?>
			<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Your %d favorite listing is no longer active.', 'Your %d favorite listings are no longer active.', $total_listings, 'southpoll-membership' ), $total_listings ) ); ?> <a href="<?php echo esc_url( $urls->my_favorites() ); ?>"><?php esc_html_e( 'View inactive favorites', 'southpoll-membership' ); ?></a> &middot; <a href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse classifieds', 'southpoll-membership' ); ?></a></p>
		<?php else : ?>
			<ul class="sp-fav-list" style="margin-bottom:12px">
				<?php foreach ( $active_list as $item ) : ?>
					<li class="sp-fav-list__item" data-fav-row="l<?php echo esc_attr( (string) $item['id'] ); ?>">
						<a class="sp-fav-list__logo" href="<?php echo esc_url( $item['url'] ); ?>"><?php if ( $item['image'] ) : ?><img src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy"><?php endif; ?></a>
						<div class="sp-fav-list__body"><a class="sp-fav-list__name" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a><div class="sp-text-muted sp-text-small"><?php echo esc_html( $item['price'] ); ?><?php if ( $item['farm'] ) : ?> &middot; <?php echo esc_html( $item['farm']['name'] ); ?><?php endif; ?></div></div>
						<button type="button" class="sp-fav is-active" data-sp-favorite="listing" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" data-remove-row="1" aria-pressed="true" aria-label="<?php esc_attr_e( 'Remove from favorites', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'star', 18, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $inactive > 0 ) : ?><p class="sp-text-muted sp-text-small"><a href="<?php echo esc_url( $urls->my_favorites() ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d inactive favorite listing', '%d inactive favorite listings', $inactive, 'southpoll-membership' ), $inactive ) ); ?></a></p><?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $items ) ) : ?>
		<p class="sp-eyebrow" style="margin-bottom:6px"><?php esc_html_e( 'Farms', 'southpoll-membership' ); ?></p>
		<ul class="sp-fav-list">
			<?php foreach ( $items as $item ) : ?>
				<li class="sp-fav-list__item" data-fav-row="<?php echo esc_attr( (string) $item['id'] ); ?>">
					<a class="sp-fav-list__logo" href="<?php echo esc_url( $item['url'] ); ?>"><?php if ( $item['logo'] ) : ?><img src="<?php echo esc_url( $item['logo'] ); ?>" alt="" loading="lazy"><?php else : ?><span><?php echo esc_html( $item['initials'] ); ?></span><?php endif; ?></a>
					<div class="sp-fav-list__body">
						<a class="sp-fav-list__name" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
						<div class="sp-text-muted sp-text-small"><span class="sp-light sp-light--<?php echo esc_attr( $item['light'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $item['active_listings'] > 0 ? sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) $item['active_listings'], 'southpoll-membership' ), (int) $item['active_listings'] ) : __( 'No active listings', 'southpoll-membership' ) ); ?><?php echo $item['location'] ? ' · ' . esc_html( $item['location'] ) : ''; ?></div>
					</div>
					<button type="button" class="sp-fav is-active" data-sp-favorite="farm" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" data-remove-row="1" aria-pressed="true" aria-label="<?php esc_attr_e( 'Remove from favorites', 'southpoll-membership' ); ?>" title="<?php esc_attr_e( 'Remove from favorites', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'star', 18, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
