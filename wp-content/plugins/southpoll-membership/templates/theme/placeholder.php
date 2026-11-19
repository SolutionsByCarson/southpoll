<?php
/**
 * Temporary content for routes not yet built.
 *
 * @var \SouthPoll\Membership\Routing\RouteMatch $match
 * @var string $heading
 * @var int    $phase
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header">
	<h1 class="sp-page__title"><?php echo esc_html( $heading ); ?></h1>
</header>
<div class="sp-card sp-card--notice">
	<p class="sp-text-muted">
		<?php
		echo esc_html( sprintf(
			/* translators: 1: route name, 2: build phase number */
			__( 'The "%1$s" screen is scheduled for build phase %2$d. This placeholder confirms routing, theme chrome, and assets are wired up.', 'southpoll-membership' ),
			$match->name,
			$phase
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
	<p>
		<a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->dashboard() ); ?>"><?php esc_html_e( 'Member Dashboard', 'southpoll-membership' ); ?></a>
		<a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></a>
	</p>
</div>
