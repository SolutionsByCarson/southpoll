<?php
/**
 * Placeholder dashboard section.
 *
 * @var \SouthPoll\Membership\Routing\RouteMatch    $match
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls          $urls
 */

defined( 'ABSPATH' ) || exit;

$sp_phase = match ( $match->name ) {
	'dashboard', 'account-details', 'manage-membership', 'checkout-return' => 1,
	'my-farm'                                                              => 2,
	default                                                                => 3,
};
?>
<div class="sp-grid sp-grid--stats">
	<div class="sp-stat">
		<span class="sp-stat__value" data-countup="12">0</span>
		<span class="sp-stat__label"><?php esc_html_e( 'Sample stat', 'southpoll-membership' ); ?></span>
	</div>
	<div class="sp-stat">
		<span class="sp-stat__value" data-countup="482">0</span>
		<span class="sp-stat__label"><?php esc_html_e( 'Another stat', 'southpoll-membership' ); ?></span>
	</div>
	<div class="sp-stat sp-stat--accent">
		<span class="sp-stat__value" data-countup="7">0</span>
		<span class="sp-stat__label"><?php esc_html_e( 'Accent stat', 'southpoll-membership' ); ?></span>
	</div>
</div>
<div class="sp-card">
	<h2 class="sp-card__title"><?php echo esc_html( (string) ( $match->spec['title'] ?? $match->name ) ); ?></h2>
	<p class="sp-text-muted">
		<?php
		echo esc_html( sprintf(
			/* translators: 1: route name, 2: phase */
			__( 'The "%1$s" section is scheduled for build phase %2$d. Your current role is "%3$s" and your membership status is "%4$s".', 'southpoll-membership' ),
			$match->name,
			$sp_phase,
			$context->is_staff() ? 'staff' : $context->role(),
			$context->membership_status()
		) );
		?>
	</p>
	<?php if ( ! empty( $match->params ) ) : ?>
		<dl class="sp-dl">
			<?php foreach ( $match->params as $key => $value ) : ?>
				<dt><?php echo esc_html( (string) $key ); ?></dt>
				<dd><code><?php echo esc_html( (string) $value ); ?></code></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</div>
