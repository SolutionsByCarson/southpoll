<?php
/**
 * One listing row card (classifieds feed, farm page, favorites, REST `html`).
 *
 * @var array $item     ListingPresenter::card() data
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var bool  $compact  Smaller variant (farm page, dashboard cards, related listings)
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$compact  = ! empty( $compact );
$sp_badge = 'available' !== $item['status'] ? '<span class="sp-badge ' . esc_attr( $item['status_class'] ) . '">' . esc_html( $item['status_label'] ) . '</span>' : '';
?>
<article class="sp-listing-card<?php echo $compact ? ' sp-listing-card--compact' : ''; ?><?php echo $item['is_public'] ? '' : ' is-inactive'; ?>" data-listing-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
	<a class="sp-listing-card__media" href="<?php echo esc_url( $item['url'] ); ?>" aria-label="<?php echo esc_attr( $item['title'] ); ?>">
		<?php if ( $item['image'] ) : ?>
			<img src="<?php echo esc_url( $item['image'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy" width="640" height="480">
		<?php else : ?>
			<span class="sp-listing-card__placeholder" aria-hidden="true"><?php echo Icons::svg( 'tag', 36 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<?php endif; ?>
		<?php if ( $item['is_new'] && $item['is_public'] ) : ?><span class="sp-badge sp-badge--new sp-badge--float"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></span><?php endif; ?>
		<?php if ( ! $item['is_public'] ) : ?><span class="sp-badge sp-badge--muted sp-badge--float"><?php echo esc_html( $item['status_label'] ); ?></span><?php endif; ?>
	</a>
	<div class="sp-listing-card__body">
		<div class="sp-listing-card__top">
			<h3 class="sp-listing-card__title"><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h3>
			<div class="sp-listing-card__price"><?php echo esc_html( $item['price'] ); ?></div>
		</div>
		<div class="sp-listing-card__meta">
			<?php if ( $item['published_ago'] ) : ?><span><?php echo esc_html( $item['published_ago'] ); ?></span><?php endif; ?>
			<?php if ( $item['farm'] ) : ?><span><?php echo Icons::svg( 'barn', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php if ( $item['seller'] ) : ?><?php echo esc_html( $item['seller'] ); ?> &middot; <?php endif; ?><a href="<?php echo esc_url( $item['farm']['url'] ); ?>"><?php echo esc_html( $item['farm']['name'] ); ?></a><?php if ( $item['farm']['location'] ) : ?> &middot; <?php echo esc_html( $item['farm']['location'] ); ?><?php endif; ?></span><?php endif; ?>
			<?php echo $sp_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php if ( ! empty( $item['categories'] ) ) : ?>
			<div class="sp-listing-card__pills"><?php foreach ( $item['categories'] as $c ) : ?><a class="sp-pill sp-pill--link" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a><?php endforeach; ?></div>
		<?php endif; ?>
		<?php if ( ! $compact && $item['summary'] ) : ?><p class="sp-listing-card__summary"><?php echo esc_html( $item['summary'] ); ?></p><?php endif; ?>
		<div class="sp-listing-card__foot">
			<span class="sp-listing-card__views" title="<?php esc_attr_e( 'Views', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'user', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( sprintf( /* translators: %d: views */ _n( '%d view', '%d views', (int) $item['view_count'], 'southpoll-membership' ), (int) $item['view_count'] ) ); ?></span>
			<span class="sp-cluster" style="gap:6px">
				<a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $item['url'] ); ?>"><?php esc_html_e( 'View', 'southpoll-membership' ); ?></a>
				<button type="button" class="sp-fav<?php echo $item['favorited'] ? ' is-active' : ''; ?>" data-sp-favorite="listing" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" aria-pressed="<?php echo $item['favorited'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $item['favorited'] ? __( 'Remove from favorites', 'southpoll-membership' ) : __( 'Add to favorites', 'southpoll-membership' ) ); ?>" title="<?php echo esc_attr( $item['favorited'] ? __( 'Remove from favorites', 'southpoll-membership' ) : __( 'Add to favorites', 'southpoll-membership' ) ); ?>"><?php echo Icons::svg( 'star', 18, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</span>
		</div>
	</div>
</article>
