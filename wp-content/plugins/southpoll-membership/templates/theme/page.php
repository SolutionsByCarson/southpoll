<?php
/**
 * Wrapper for theme-chrome routes: active theme header + plugin content + theme footer.
 * Override: {child-theme}/southpoll-membership/theme/page.php
 *
 * @package SouthPoll\Membership
 */

defined( 'ABSPATH' ) || exit;

$sp_router = sp_membership()->get( \SouthPoll\Membership\Routing\Router::class );
$sp_match  = $sp_router->current();

get_header();
?>
<div class="sp-scope sp-page<?php echo $sp_match ? ' sp-page--' . esc_attr( $sp_match->name ) : ''; ?>">
	<div class="sp-container">
		<?php $sp_router->render_current_content(); ?>
	</div>
</div>
<?php
get_footer();
