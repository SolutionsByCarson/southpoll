<?php
/**
 * Unknown farm slug (HTTP 404).
 *
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var string $slug
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header">
	<h1 class="sp-page__title"><?php esc_html_e( 'Farm not found', 'southpoll-membership' ); ?></h1>
</header>
<div class="sp-card sp-card--notice">
	<p><?php esc_html_e( 'We could not find a member farm at that address. It may have been renamed or removed.', 'southpoll-membership' ); ?></p>
	<p><a class="sp-btn sp-btn--primary" href="<?php echo esc_url( $urls->directory( [ 'q' => str_replace( '-', ' ', $slug ) ] ) ); ?>"><?php esc_html_e( 'Search the directory', 'southpoll-membership' ); ?></a></p>
</div>
