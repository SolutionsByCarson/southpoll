<?php
/**
 * Farm page: active listings section.
 *
 * @var array  $items  listing cards with html (compact)
 * @var object $farm
 * @var int    $total
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sp-listing-list sp-listing-list--compact">
	<?php foreach ( $items as $item ) : ?><?php echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endforeach; ?>
</div>
<?php if ( $total > count( $items ) ) : ?>
	<p style="margin-top:12px"><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $urls->classifieds( [ 'farms' => (int) $farm->id ] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'See all %d listings', 'southpoll-membership' ), $total ) ); ?></a></p>
<?php endif; ?>
