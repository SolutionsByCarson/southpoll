<?php
/**
 * Standalone member dashboard document: sidebar + topbar + content. No theme header/footer.
 * Override: {child-theme}/southpoll-membership/dashboard/layout.php
 *
 * @var \SouthPoll\Membership\Routing\RouteMatch     $match
 * @var \SouthPoll\Membership\Members\MemberContext  $context
 * @var \SouthPoll\Membership\Routing\Urls           $urls
 * @var array                                        $nav
 * @var string                                       $page_title
 * @var string                                       $content
 * @var \WP_User                                     $user
 */

defined( 'ABSPATH' ) || exit;

$sp_icons = [
	'home'   => '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
	'star'   => '<path d="m12 3 2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.4 6.3 20.5l1.2-6.4L2.8 9.7l6.4-.8z"/>',
	'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.5-4.5"/>',
	'barn'   => '<path d="M3 10 12 3l9 7v11H3z"/><path d="M9 21v-7h6v7"/>',
	'tag'    => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
	'card'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
	'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
	'help'   => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7"/><circle cx="12" cy="17" r=".6"/>',
	'grid'   => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
	'logout' => '<path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5"/><path d="m15 8 5 4-5 4"/><path d="M20 12H9"/>',
];
$sp_icon  = static function ( string $name ) use ( $sp_icons ): string {
	$path = $sp_icons[ $name ] ?? $sp_icons['grid'];
	return '<svg class="sp-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};
$sp_logo  = SP_PLUGIN_URL . 'assets/img/southpoll-horizontal-logo.png';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'sp-dashboard sp-scope' ); ?>>
<div class="sp-dash" x-data="{ navOpen: false }" :class="{ 'sp-dash--nav-open': navOpen }">
	<a class="sp-skip-link" href="#sp-main"><?php esc_html_e( 'Skip to content', 'southpoll-membership' ); ?></a>

	<aside class="sp-dash__sidebar" aria-label="<?php esc_attr_e( 'Member area navigation', 'southpoll-membership' ); ?>">
		<div class="sp-dash__brand">
			<a href="<?php echo esc_url( $urls->dashboard() ); ?>" class="sp-dash__logo">
				<img src="<?php echo esc_url( $sp_logo ); ?>" alt="<?php esc_attr_e( 'South Poll Grass Cattle Association', 'southpoll-membership' ); ?>" width="220" height="48">
			</a>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sp-dash__back">&larr; <?php esc_html_e( 'Return to SouthPoll.com', 'southpoll-membership' ); ?></a>
		</div>
		<nav class="sp-dash__nav">
			<ul>
				<?php foreach ( $nav as $item ) : ?>
					<li>
						<a href="<?php echo esc_url( $item['href'] ); ?>" class="sp-dash__link<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>"<?php echo ! empty( $item['active'] ) ? ' aria-current="page"' : ''; ?>>
							<?php echo $sp_icon( (string) $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php echo esc_html( $item['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<div class="sp-dash__sidebar-foot">
			<span class="sp-badge sp-badge--role"><?php echo esc_html( ucfirst( $context->is_staff() ? 'staff' : $context->role() ) ); ?></span>
		</div>
	</aside>

	<div class="sp-dash__overlay" x-show="navOpen" x-transition.opacity @click="navOpen = false" style="display:none"></div>

	<div class="sp-dash__main">
		<header class="sp-dash__topbar">
			<button type="button" class="sp-dash__menu-btn" @click="navOpen = !navOpen" :aria-expanded="navOpen" aria-controls="sp-dash-nav" aria-label="<?php esc_attr_e( 'Toggle navigation', 'southpoll-membership' ); ?>">
				<span></span><span></span><span></span>
			</button>
			<h1 class="sp-dash__title"><?php echo esc_html( $page_title ); ?></h1>
			<div class="sp-dash__user">
				<span class="sp-dash__user-name"><?php echo esc_html( $user->display_name ); ?></span>
				<span class="sp-dash__user-email"><?php echo esc_html( $user->user_email ); ?></span>
			</div>
		</header>
		<main id="sp-main" class="sp-dash__content">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section templates escape their own output ?>
		</main>
		<footer class="sp-dash__footer">
			<span>&copy; <?php echo esc_html( (string) wp_date( 'Y' ) ); ?> <?php esc_html_e( 'South Poll Grass Cattle Association', 'southpoll-membership' ); ?></span>
			<a href="<?php echo esc_url( $urls->help() ); ?>"><?php esc_html_e( 'Help Center', 'southpoll-membership' ); ?></a>
		</footer>
	</div>
</div>
<?php sp_membership()->get( \SouthPoll\Membership\Feedback\FeedbackButton::class )->display(); // S8: floating feedback button (prints nothing while off) ?>
<?php wp_footer(); ?>
</body>
</html>
