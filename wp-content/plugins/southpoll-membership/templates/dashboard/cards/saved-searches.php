<?php
/**
 * Dashboard home card: saved searches + live feed from the first one.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array  $searches  serialized (up to 5)
 * @var int    $total
 * @var array  $feed      listing cards with html
 * @var string $feed_name
 * @var string $feed_url
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sp-card">
	<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'Saved searches', 'southpoll-membership' ); ?> <span class="sp-badge"><?php echo esc_html( (string) $total ); ?></span></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->saved_searches() ); ?>"><?php esc_html_e( 'Manage', 'southpoll-membership' ); ?></a></div>
	<ul class="sp-fav-list" style="margin-bottom:14px">
		<?php foreach ( $searches as $s ) : ?>
			<li class="sp-fav-list__item">
				<div class="sp-fav-list__body">
					<a class="sp-fav-list__name" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['name'] ); ?></a>
					<div class="sp-text-muted sp-text-small"><?php echo esc_html( $s['summary'] ); ?></div>
				</div>
				<span class="sp-badge <?php echo $s['new_count'] ? 'sp-badge--new' : 'sp-badge--muted'; ?>" title="<?php esc_attr_e( 'New since your last visit', 'southpoll-membership' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d new', 'southpoll-membership' ), (int) $s['new_count'] ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( ! empty( $feed ) ) : ?>
		<p class="sp-eyebrow" style="margin-bottom:8px"><?php echo esc_html( sprintf( /* translators: %s: search name */ __( 'Latest for “%s”', 'southpoll-membership' ), $feed_name ) ); ?></p>
		<div class="sp-listing-list sp-listing-list--compact"><?php foreach ( $feed as $item ) : ?><?php echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endforeach; ?></div>
		<p style="margin:10px 0 0"><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $feed_url ); ?>"><?php esc_html_e( 'See all matches', 'southpoll-membership' ); ?></a></p>
	<?php else : ?>
		<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: search name */ __( 'No listings match “%s” right now. You will see them here as they are published.', 'southpoll-membership' ), $feed_name ) ); ?></p>
	<?php endif; ?>
</div>
