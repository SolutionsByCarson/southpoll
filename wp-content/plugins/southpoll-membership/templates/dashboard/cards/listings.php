<?php
/**
 * Dashboard home card: the farm's listings.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array $summary  limit, active, remaining
 * @var array $items    live listing cards (up to 5)
 * @var int   $drafts
 * @var int   $expired
 * @var bool  $can_publish
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sp-card">
	<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'My listings', 'southpoll-membership' ); ?></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->my_listings() ); ?>"><?php esc_html_e( 'Manage', 'southpoll-membership' ); ?></a></div>
	<div class="sp-cluster" style="margin-bottom:12px">
		<span class="sp-count-tab"><span class="sp-count-tab__n" data-countup="<?php echo esc_attr( (string) $summary['active'] ); ?>">0</span> <span class="sp-text-small sp-text-muted"><?php esc_html_e( 'active', 'southpoll-membership' ); ?></span></span>
		<span class="sp-count-tab"><span class="sp-count-tab__n" data-countup="<?php echo esc_attr( (string) $summary['remaining'] ); ?>">0</span> <span class="sp-text-small sp-text-muted"><?php echo esc_html( sprintf( /* translators: %d: limit */ __( 'remaining of %d', 'southpoll-membership' ), (int) $summary['limit'] ) ); ?></span></span>
		<?php if ( $drafts ) : ?><span class="sp-badge sp-badge--muted"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d draft', '%d drafts', $drafts, 'southpoll-membership' ), $drafts ) ); ?></span><?php endif; ?>
		<?php if ( $expired ) : ?><span class="sp-badge sp-badge--warning"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d expired', '%d expired', $expired, 'southpoll-membership' ), $expired ) ); ?></span><?php endif; ?>
	</div>
	<?php if ( empty( $items ) ) : ?>
		<div class="sp-empty"><?php echo esc_html( $can_publish ? __( 'No live listings. Post your first one in a couple of minutes.', 'southpoll-membership' ) : __( 'Listings go live once the farm membership is active. You can draft them now.', 'southpoll-membership' ) ); ?><br><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $urls->edit_listing( 0, [ 'id' => 'new' ] ) ); ?>"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></a></div>
	<?php else : ?>
		<ul class="sp-fav-list">
			<?php foreach ( $items as $item ) : ?>
				<li class="sp-fav-list__item">
					<a class="sp-fav-list__logo" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php if ( $item['image'] ) : ?><img src="<?php echo esc_url( $item['image'] ); ?>" alt=""><?php endif; ?></a>
					<div class="sp-fav-list__body">
						<a class="sp-fav-list__name" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						<div class="sp-text-muted sp-text-small"><?php echo esc_html( $item['price'] ); ?> &middot; <?php echo esc_html( $item['status_label'] ); ?> &middot; <?php echo esc_html( sprintf( /* translators: %d: views */ _n( '%d view', '%d views', (int) $item['view_count'], 'southpoll-membership' ), (int) $item['view_count'] ) ); ?><?php if ( null !== $item['days_left'] ) : ?> &middot; <?php echo esc_html( sprintf( /* translators: %d: days */ __( '%d days left', 'southpoll-membership' ), (int) $item['days_left'] ) ); ?><?php endif; ?></div>
					</div>
					<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $item['url'] ); ?>"><?php esc_html_e( 'View', 'southpoll-membership' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<p style="margin:12px 0 0"><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $urls->edit_listing( 0, [ 'id' => 'new' ] ) ); ?>"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></a></p>
	<?php endif; ?>
</div>
