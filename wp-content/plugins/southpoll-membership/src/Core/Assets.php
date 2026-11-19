<?php
/**
 * Registers and enqueues plugin CSS/JS. Front-end assets load only on plugin routes (or when a
 * plugin shortcode is rendered); admin assets only on plugin screens.
 *
 * Load order (all deferred): sp-core → sp-public → alpine-collapse → alpine-focus → alpine. Alpine must come
 * last so components registered on `alpine:init` in sp-core / sp-public are available.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Routing\Router;
use SouthPoll\Membership\Routing\Routes;
use SouthPoll\Membership\Routing\Urls;

final class Assets implements Service {

	private bool $frontend_requested = false;

	public function __construct(
		private Settings $settings,
		private Integrations $integrations,
		private Urls $urls,
		private Router $router,
		private Flash $flash
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ], 20 );
		add_action( 'wp_print_styles', [ $this, 'strip_theme_styles' ], 100 );
	}

	/**
	 * Standalone screens (member dashboard, login) print their own <html> but still run wp_head(), which pulls in the
	 * theme's stylesheets; those restyle buttons and set overflow rules that break the layout. Drop every stylesheet
	 * served from the themes directory on those screens. Scripts are left alone (the listing editor needs WP core ones).
	 */
	public function strip_theme_styles(): void {
		$match = $this->router->current();
		if ( null === $match || Routes::KIND_STANDALONE !== $match->kind() ) {
			return;
		}
		$styles = wp_styles();
		foreach ( (array) $styles->queue as $handle ) {
			$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
			if ( str_contains( $src, '/wp-content/themes/' ) || str_starts_with( (string) $handle, 'x-' ) || str_starts_with( (string) $handle, 'cs-' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	private function version(): string {
		return defined( 'WP_DEBUG' ) && WP_DEBUG ? (string) time() : SP_VERSION;
	}

	public function register_assets(): void {
		$v   = $this->version();
		$css = SP_PLUGIN_URL . 'assets/css/';
		$js  = SP_PLUGIN_URL . 'assets/js/';
		$vd  = SP_PLUGIN_URL . 'assets/vendor/';

		wp_register_style( 'sp-tokens', $css . 'sp-tokens.css', [], $v );
		wp_register_style( 'sp-ui', $css . 'sp-ui.css', [ 'sp-tokens' ], $v );
		wp_register_style( 'sp-public', $css . 'sp-public.css', [ 'sp-ui' ], $v );
		wp_register_style( 'sp-dashboard', $css . 'sp-dashboard.css', [ 'sp-ui' ], $v );
		wp_register_style( 'sp-admin', $css . 'sp-admin.css', [ 'sp-ui' ], $v );

		$defer = [ 'strategy' => 'defer', 'in_footer' => true ];
		wp_register_script( 'sp-core', $js . 'sp-core.js', [], $v, $defer );
		wp_register_script( 'sp-public', $js . 'sp-public.js', [ 'sp-core' ], $v, $defer );
		wp_register_script( 'sp-classifieds', $js . 'sp-classifieds.js', [ 'sp-public' ], $v, $defer );
		// Alpine must print after every script that registers components on alpine:init (front end: public + classifieds; admin: sp-admin).
		wp_register_script( 'sp-alpine-collapse', $vd . 'alpine-collapse.min.js', is_admin() ? [ 'sp-core', 'sp-admin' ] : [ 'sp-core', 'sp-public', 'sp-classifieds' ], '3.14.8', $defer );
		wp_register_script( 'sp-alpine-focus', $vd . 'alpine-focus.min.js', [ 'sp-alpine-collapse' ], '3.14.8', $defer );
		wp_register_script( 'sp-alpine', $vd . 'alpine.min.js', [ 'sp-alpine-focus' ], '3.14.8', $defer );
		wp_register_script( 'sp-dashboard', $js . 'sp-dashboard.js', [ 'sp-core' ], $v, $defer );
		wp_register_script( 'sp-admin', $js . 'sp-admin.js', [ 'sp-core' ], $v, $defer );

		wp_add_inline_script( 'sp-core', 'window.SP_CONFIG = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Allow shortcodes/blocks rendered outside plugin routes to pull the front-end bundle in.
	 */
	public function request_frontend(): void {
		$this->frontend_requested = true;
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue_frontend();
		}
	}

	public function enqueue_frontend(): void {
		$match = $this->router->current();
		if ( null === $match && ! $this->frontend_requested ) {
			return;
		}
		wp_enqueue_style( 'sp-ui' );
		wp_enqueue_style( 'sp-public' );
		wp_enqueue_script( 'sp-alpine' );
		if ( null !== $match && Routes::KIND_STANDALONE === $match->kind() ) {
			wp_enqueue_style( 'sp-dashboard' );
			wp_enqueue_script( 'sp-dashboard' );
		}
	}

	public function enqueue_admin( string $hook ): void {
		if ( ! str_contains( $hook, 'southpoll' ) ) {
			return;
		}
		wp_enqueue_style( 'sp-admin' );
		wp_enqueue_script( 'sp-alpine' );
		wp_enqueue_script( 'sp-admin' );
	}

	/**
	 * Runtime configuration for the JS bundle. Public values only.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$user   = wp_get_current_user();
		$center = (array) $this->settings->get( 'map_default_center', [] );
		return [
			'version'        => SP_VERSION,
			'restUrl'        => esc_url_raw( rest_url( 'sp/v1/' ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'homeUrl'        => esc_url_raw( home_url( '/' ) ),
			'isLoggedIn'     => is_user_logged_in(),
			'userName'       => $user->exists() ? $user->display_name : '',
			'urls'           => [
				'login'         => esc_url_raw( $this->urls->login() ),
				'register'      => esc_url_raw( $this->urls->register() ),
				'dashboard'     => esc_url_raw( $this->urls->dashboard() ),
				'favorites'     => esc_url_raw( $this->urls->dashboard( 'my-favorites' ) ),
				'savedSearches' => esc_url_raw( $this->urls->dashboard( 'saved-searches' ) ),
				'directory'     => esc_url_raw( $this->urls->directory() ),
				'classifieds'   => esc_url_raw( $this->urls->classifieds() ),
				'myFarm'        => esc_url_raw( $this->urls->my_farm() ),
				'account'       => esc_url_raw( $this->urls->dashboard( 'account-details' ) ),
			],
			'recaptchaSiteKey' => (string) $this->integrations->get( 'recaptcha.site_key', '' ),
			'mapsKey'          => (string) $this->integrations->get( 'google.maps_browser_key', '' ),
			'maps'             => [
				'key'    => (string) $this->integrations->get( 'google.maps_browser_key', '' ),
				'center' => [ 'lat' => (float) ( $center['lat'] ?? 37.8 ), 'lng' => (float) ( $center['lng'] ?? -92.5 ) ],
				'zoom'   => (int) ( $center['zoom'] ?? 5 ),
			],
			'pagination'       => [
				'default' => (int) $this->settings->get( 'pagination_default', 25 ),
				'options' => (array) $this->settings->get( 'pagination_options', [ 10, 25, 50, 100, 'all' ] ),
			],
			'flash'            => $this->flash->pull(),
			'i18n'             => [
				'close'          => __( 'Close', 'southpoll-membership' ),
				'copied'         => __( 'Link copied to your clipboard.', 'southpoll-membership' ),
				'copyFailed'     => __( 'Could not copy the link. Select it and copy manually.', 'southpoll-membership' ),
				'share'          => __( 'Share', 'southpoll-membership' ),
				'shareEmail'     => __( 'Email', 'southpoll-membership' ),
				'shareText'      => __( 'Text message', 'southpoll-membership' ),
				'shareFacebook'  => __( 'Facebook', 'southpoll-membership' ),
				'shareX'         => __( 'X (Twitter)', 'southpoll-membership' ),
				'shareCopy'      => __( 'Copy link', 'southpoll-membership' ),
				'loading'        => __( 'Loading…', 'southpoll-membership' ),
				'noResults'      => __( 'No results found.', 'southpoll-membership' ),
				'error'          => __( 'Something went wrong. Please try again.', 'southpoll-membership' ),
				'confirm'        => __( 'Confirm', 'southpoll-membership' ),
				'cancel'         => __( 'Cancel', 'southpoll-membership' ),
				'loginRequired'  => __( 'This feature requires an account.', 'southpoll-membership' ),
				'createAccount'  => __( 'Create an account', 'southpoll-membership' ),
				'logIn'          => __( 'Log in', 'southpoll-membership' ),
				'showing'        => __( 'Showing %1$s–%2$s of %3$s', 'southpoll-membership' ),
				'perPage'        => __( 'per page', 'southpoll-membership' ),
				'all'            => __( 'All', 'southpoll-membership' ),
				'search'         => __( 'Search', 'southpoll-membership' ),
				'reset'          => __( 'Reset', 'southpoll-membership' ),
				'favoritesTitle' => __( 'Favorites', 'southpoll-membership' ),
				'favoritesGuest' => __( 'Create a free account to save favorites and get alerts when farms publish new listings.', 'southpoll-membership' ),
				'favoritesVerify' => __( 'Verify your email address to use favorites.', 'southpoll-membership' ),
				'myFavorites'    => __( 'My Favorites', 'southpoll-membership' ),
				'verifyNow'      => __( 'Resend verification', 'southpoll-membership' ),
				'favorite'       => __( 'Favorite', 'southpoll-membership' ),
				'favorited'      => __( 'Favorited', 'southpoll-membership' ),
				'addFavorite'    => __( 'Add to favorites', 'southpoll-membership' ),
				'removeFavorite' => __( 'Remove from favorites', 'southpoll-membership' ),
				'mapsMissing'    => __( 'The map is not available right now.', 'southpoll-membership' ),
				'farmsOnMap'     => __( 'farms', 'southpoll-membership' ),
				'viewFarm'       => __( 'View farm page', 'southpoll-membership' ),
				'activeListings' => __( 'active listings', 'southpoll-membership' ),
				'activeListing'  => __( 'active listing', 'southpoll-membership' ),
				'matchingListings' => __( 'matching listings', 'southpoll-membership' ),
				'matchingListing'  => __( 'matching listing', 'southpoll-membership' ),
				'newListing'     => __( 'New listing', 'southpoll-membership' ),
				'seeListings'    => __( 'See the listings', 'southpoll-membership' ),
				'newBadge'       => __( 'New', 'southpoll-membership' ),
				'more'           => __( 'more', 'southpoll-membership' ),
				'savedSearchApplied' => __( 'Saved search applied', 'southpoll-membership' ),
				'prev'           => __( 'Previous', 'southpoll-membership' ),
				'next'           => __( 'Next', 'southpoll-membership' ),
				'of'             => __( 'of', 'southpoll-membership' ),
				'removeItem'     => __( 'Remove this item?', 'southpoll-membership' ),
				'remove'         => __( 'Remove', 'southpoll-membership' ),
				'tooLarge'       => __( 'That image is too large.', 'southpoll-membership' ),
				'notImage'       => __( 'Please choose an image file.', 'southpoll-membership' ),
				'sending'        => __( 'Sending…', 'southpoll-membership' ),
				'save'           => __( 'Save', 'southpoll-membership' ),
				'rename'         => __( 'Rename search', 'southpoll-membership' ),
				'gotIt'          => __( 'Got it', 'southpoll-membership' ),
				'sendFeedback'   => __( 'Send feedback', 'southpoll-membership' ),
				'searchName'     => __( 'Name', 'southpoll-membership' ),
				'savedSearches'  => __( 'Saved Searches', 'southpoll-membership' ),
				'savedSearchesTitle' => __( 'Saved searches', 'southpoll-membership' ),
				'savedSearchGuest' => __( 'Create a free account to save searches and get an email when new listings match.', 'southpoll-membership' ),
				'saveSearchEmpty' => __( 'Add a search term or a filter first, then save.', 'southpoll-membership' ),
				'removeSearch'   => __( 'Remove this saved search?', 'southpoll-membership' ),
				'alertsOn'       => __( 'Email alerts on.', 'southpoll-membership' ),
				'alertsOff'      => __( 'Email alerts off.', 'southpoll-membership' ),
				'shareSearchText' => __( 'Take a look at this South Poll classifieds search:', 'southpoll-membership' ),
				'shareFavorites' => __( 'Share my favorites list', 'southpoll-membership' ),
				'myFavoritesShareTitle' => __( 'My favorite South Poll farms and listings', 'southpoll-membership' ),
				'newLink'        => __( 'Create a new link (the old one stops working)', 'southpoll-membership' ),
				'deleteListing'  => __( 'Delete this listing?', 'southpoll-membership' ),
				'deleteListingNote' => __( 'This removes the listing and its photos for good.', 'southpoll-membership' ),
				'renewListing'   => __( 'Renew this listing?', 'southpoll-membership' ),
				'renewListingNote' => __( 'It will run for another 60 days from today. Review the details afterwards if anything changed.', 'southpoll-membership' ),
				'renew'          => __( 'Renew', 'southpoll-membership' ),
				'editCopy'       => __( 'Edit the copy', 'southpoll-membership' ),
				'galleryFull'    => __( 'The gallery is full. Remove an item to add another.', 'southpoll-membership' ),
			],
		];
	}
}
