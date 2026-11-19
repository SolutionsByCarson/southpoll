<?php
/**
 * Route table. Patterns use {base} placeholders resolved from the "routes" setting.
 *
 * kind:
 *   theme      — rendered inside the active theme's header/footer via a host page (is_singular stays true,
 *                so the Pro/Cornerstone header assigned to "singular" pages still appears).
 *   standalone — the plugin outputs the whole document (member dashboard shell).
 *   raw        — handled on parse_request and exits (webhooks, logout).
 *
 * Order matters: more specific patterns are listed before the ones they would otherwise shadow.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

use SouthPoll\Membership\Frontend\Controllers\AuthController;
use SouthPoll\Membership\Frontend\Controllers\ClassifiedsController;
use SouthPoll\Membership\Frontend\Controllers\DashboardController;
use SouthPoll\Membership\Frontend\Controllers\DirectoryController;
use SouthPoll\Membership\Frontend\Controllers\FarmPageController;
use SouthPoll\Membership\Frontend\Controllers\FavoritesShareController;
use SouthPoll\Membership\Frontend\Controllers\ListingPageController;
use SouthPoll\Membership\Frontend\Controllers\UiKitController;
use SouthPoll\Membership\Frontend\Controllers\UnsubscribeController;
use SouthPoll\Membership\Frontend\Controllers\WebhookController;

final class Routes {

	public const KIND_THEME      = 'theme';
	public const KIND_STANDALONE = 'standalone';
	public const KIND_RAW        = 'raw';

	/** Query vars the router registers. */
	public const QUERY_VARS = [ 'sp_route', 'sp_slug', 'sp_category', 'sp_token', 'sp_page', 'sp_result' ];

	/**
	 * @return array<string, array{pattern:string, kind:string, controller:string, params?:string[], host?:string, title?:string, auth?:bool}>
	 */
	public static function all(): array {
		$routes = [
			// Machine endpoints.
			'webhook-stripe'   => [ 'pattern' => 'sp-webhook/stripe', 'kind' => self::KIND_RAW, 'controller' => WebhookController::class ],
			'logout'           => [ 'pattern' => '{members}/logout', 'kind' => self::KIND_RAW, 'controller' => DashboardController::class ],

			// Auth pages (theme chrome, host = login page or directory page).
			'login'            => [ 'pattern' => '{members}/login', 'kind' => self::KIND_THEME, 'host' => 'login', 'controller' => AuthController::class, 'title' => 'Member Login' ],
			'verify'           => [ 'pattern' => '{members}/verify', 'kind' => self::KIND_THEME, 'host' => 'login', 'controller' => AuthController::class, 'title' => 'Verify Your Email' ],
			'reset-password'   => [ 'pattern' => '{members}/reset-password', 'kind' => self::KIND_THEME, 'host' => 'login', 'controller' => AuthController::class, 'title' => 'Reset Password' ],
			'invite'           => [ 'pattern' => '{members}/invite', 'kind' => self::KIND_THEME, 'host' => 'login', 'controller' => AuthController::class, 'title' => 'Accept Invitation' ],
			'unsubscribe'      => [ 'pattern' => '{members}/unsubscribe', 'kind' => self::KIND_THEME, 'host' => 'login', 'controller' => UnsubscribeController::class, 'title' => 'Email alerts' ],

			// Member dashboard (standalone shell; login required).
			'dashboard'         => [ 'pattern' => '{members}/dashboard', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Dashboard', 'auth' => true ],
			'my-favorites'      => [ 'pattern' => '{members}/my-favorites', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'My Favorites', 'auth' => true ],
			'saved-searches'    => [ 'pattern' => '{members}/saved-searches', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Saved Searches', 'auth' => true ],
			'my-farm'           => [ 'pattern' => '{members}/my-farm', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'My Farm', 'auth' => true ],
			'my-listings'       => [ 'pattern' => '{members}/my-listings', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'My Listings', 'auth' => true ],
			'edit-listing'      => [ 'pattern' => '{members}/edit-listing', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Edit Listing', 'auth' => true ],
			'account-details'   => [ 'pattern' => '{members}/account-details', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Account Details', 'auth' => true ],
			'manage-membership' => [ 'pattern' => '{members}/manage-membership', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'My Membership', 'auth' => true ],
			'help'              => [ 'pattern' => '{members}/help', 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Help Center', 'auth' => true ],
			'checkout-return'   => [ 'pattern' => '{members}/checkout/([a-z]+)', 'params' => [ 'sp_result' ], 'kind' => self::KIND_STANDALONE, 'controller' => DashboardController::class, 'title' => 'Checkout', 'auth' => true ],
			'ui-kit'            => [ 'pattern' => '{members}/ui-kit', 'kind' => self::KIND_STANDALONE, 'controller' => UiKitController::class, 'title' => 'UI Kit', 'auth' => true ],

			// Directory + farms.
			'directory-paged'   => [ 'pattern' => '{members}/page/([0-9]+)', 'params' => [ 'sp_page' ], 'kind' => self::KIND_THEME, 'host' => 'directory', 'controller' => DirectoryController::class, 'title' => 'Member Directory', 'alias' => 'directory' ],
			'directory'         => [ 'pattern' => '{members}', 'kind' => self::KIND_THEME, 'host' => 'directory', 'controller' => DirectoryController::class, 'title' => 'Member Directory' ],
			'farm'              => [ 'pattern' => '{farms}/([^/]+)', 'params' => [ 'sp_slug' ], 'kind' => self::KIND_THEME, 'host' => 'directory', 'controller' => FarmPageController::class, 'title' => 'Farm' ],

			// Classifieds.
			'listing'           => [ 'pattern' => '{classifieds}/listing/([^/]+)', 'params' => [ 'sp_slug' ], 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ListingPageController::class, 'title' => 'Listing' ],
			'classifieds-paged' => [ 'pattern' => '{classifieds}/page/([0-9]+)', 'params' => [ 'sp_page' ], 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ClassifiedsController::class, 'title' => 'Classifieds', 'alias' => 'classifieds' ],
			'category-paged'    => [ 'pattern' => '{classifieds}/([^/]+)/page/([0-9]+)', 'params' => [ 'sp_category', 'sp_page' ], 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ClassifiedsController::class, 'title' => 'Classifieds', 'alias' => 'category' ],
			'category'          => [ 'pattern' => '{classifieds}/([^/]+)', 'params' => [ 'sp_category' ], 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ClassifiedsController::class, 'title' => 'Classifieds' ],
			'classifieds'       => [ 'pattern' => '{classifieds}', 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ClassifiedsController::class, 'title' => 'Classifieds' ],

			// Public share pages.
			'favorites-share'   => [ 'pattern' => '{favorites}/([^/]+)', 'params' => [ 'sp_token' ], 'kind' => self::KIND_THEME, 'host' => 'directory', 'controller' => FavoritesShareController::class, 'title' => 'Shared Favorites' ],
			'search-share'      => [ 'pattern' => '{search}/([^/]+)', 'params' => [ 'sp_token' ], 'kind' => self::KIND_THEME, 'host' => 'classifieds', 'controller' => ClassifiedsController::class, 'title' => 'Shared Search' ],
		];

		/**
		 * @param array $routes Route table.
		 */
		return (array) apply_filters( 'sp/routes', $routes );
	}

	public static function get( string $name ): ?array {
		return self::all()[ $name ] ?? null;
	}
}
