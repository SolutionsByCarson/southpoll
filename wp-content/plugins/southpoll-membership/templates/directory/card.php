<?php
/**
 * One farm card (directory feed, favorites lists, REST `html`).
 *
 * @var array $item  FarmPresenter::card() data
 * @var \SouthPoll\Membership\Members\MemberContext $context
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_light_label = $item['active_listings'] > 0
	? sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) $item['active_listings'], 'southpoll-membership' ), (int) $item['active_listings'] )
	: __( 'No active listings', 'southpoll-membership' );
?>
<article class="sp-farm-card" data-farm-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
	<a class="sp-farm-card__media" href="<?php echo esc_url( $item['url'] ); ?>" aria-label="<?php echo esc_attr( $item['name'] ); ?>">
		<?php if ( $item['logo'] ) : ?>
			<img src="<?php echo esc_url( $item['logo'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: farm */ __( '%s logo', 'southpoll-membership' ), $item['name'] ) ); ?>" loading="lazy" width="400" height="300">
		<?php else : ?>
			<span class="sp-farm-card__initials" aria-hidden="true"><?php echo esc_html( $item['initials'] ); ?></span>
		<?php endif; ?>
		<?php if ( $item['new_listing'] ) : ?>
			<span class="sp-badge sp-badge--new sp-badge--float"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></span>
		<?php endif; ?>
	</a>
	<div class="sp-farm-card__body">
		<h3 class="sp-farm-card__name"><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a></h3>
		<div class="sp-farm-card__meta">
			<?php if ( $item['owner_name'] ) : ?><span class="sp-farm-card__owner"><?php echo esc_html( $item['owner_name'] ); ?></span><?php endif; ?>
			<?php if ( $item['location'] ) : ?><span class="sp-farm-card__location"><?php echo Icons::svg( 'pin', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $item['location'] ); ?></span><?php endif; ?>
		</div>
		<?php if ( ! empty( $item['offerings'] ) ) : ?>
			<div class="sp-farm-card__pills">
				<?php foreach ( array_slice( $item['offerings'], 0, 4 ) as $o ) : ?><span class="sp-pill"><?php echo esc_html( $o['name'] ); ?></span><?php endforeach; ?>
				<?php if ( count( $item['offerings'] ) > 4 ) : ?><span class="sp-pill">+<?php echo esc_html( (string) ( count( $item['offerings'] ) - 4 ) ); ?></span><?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="sp-farm-card__foot">
			<span class="sp-farm-card__light"><span class="sp-light sp-light--<?php echo esc_attr( $item['light'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $sp_light_label ); ?></span>
			<button type="button" class="sp-fav<?php echo $item['favorited'] ? ' is-active' : ''; ?>" data-sp-favorite="farm" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" aria-pressed="<?php echo $item['favorited'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $item['favorited'] ? __( 'Remove from favorites', 'southpoll-membership' ) : __( 'Add to favorites', 'southpoll-membership' ) ); ?>" title="<?php echo esc_attr( $item['favorited'] ? __( 'Remove from favorites', 'southpoll-membership' ) : __( 'Add to favorites', 'southpoll-membership' ) ); ?>"><?php echo Icons::svg( 'star', 18, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</div>
	</div>
</article>
