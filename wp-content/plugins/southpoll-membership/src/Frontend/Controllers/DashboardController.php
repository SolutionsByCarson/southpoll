<?php
/**
 * Member dashboard shell (standalone layout) and the logout endpoint.
 * Section content is a placeholder until each Phase 1–3 task fills it in via `sp/dashboard/section`.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Core\Container;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\Sections\AccountDetails;
use SouthPoll\Membership\Frontend\Sections\CheckoutReturn;
use SouthPoll\Membership\Frontend\Sections\DashboardHome;
use SouthPoll\Membership\Frontend\Sections\HelpCenter;
use SouthPoll\Membership\Frontend\Sections\EditListing;
use SouthPoll\Membership\Frontend\Sections\ManageMembership;
use SouthPoll\Membership\Frontend\Sections\MyFarm;
use SouthPoll\Membership\Frontend\Sections\MyFavorites;
use SouthPoll\Membership\Frontend\Sections\MyListings;
use SouthPoll\Membership\Frontend\Sections\SavedSearches;
use SouthPoll\Membership\Frontend\Sections\Section;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class DashboardController implements Controller {

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private ContextProvider $contexts,
		private Container $container
	) {}

	/**
	 * Route name → Section class. Later phases register more via the filter.
	 *
	 * @return array<string, class-string<Section>>
	 */
	private function sections(): array {
		$sections = [
			'dashboard'         => DashboardHome::class,
			'account-details'   => AccountDetails::class,
			'my-farm'           => MyFarm::class,
			'my-listings'       => MyListings::class,
			'edit-listing'      => EditListing::class,
			'my-favorites'      => MyFavorites::class,
			'saved-searches'    => SavedSearches::class,
			'manage-membership' => ManageMembership::class,
			'checkout-return'   => CheckoutReturn::class,
			'help'              => HelpCenter::class,
		];
		return (array) apply_filters( 'sp/dashboard/sections', $sections );
	}

	public function title( RouteMatch $match ): string {
		return (string) ( $match->spec['title'] ?? __( 'Dashboard', 'southpoll-membership' ) );
	}

	public function render( RouteMatch $match ): void {
		if ( 'logout' === $match->name ) {
			$this->logout();
			return;
		}
		$context = $this->require_login( $match );

		$content  = null;
		$sections = $this->sections();
		if ( isset( $sections[ $match->name ] ) ) {
			$section = $this->container->get( $sections[ $match->name ] );
			if ( $section instanceof Section ) {
				if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
					$section->handle( $match, $context );
					$this->contexts->forget( $context->user_id );
					$context = $this->contexts->for_user( $context->user_id );
				}
				$content = $section->render( $match, $context );
			}
		}

		/**
		 * Section content. Return HTML for the given dashboard route, or null for the placeholder.
		 *
		 * @param string|null   $content
		 * @param RouteMatch    $match
		 * @param MemberContext $context
		 */
		$content = apply_filters( 'sp/dashboard/section', $content, $match, $context );
		if ( null === $content ) {
			$content = $this->view->render( 'dashboard/placeholder', [
				'match'   => $match,
				'context' => $context,
				'urls'    => $this->urls,
			] );
		}

		$this->view->display( 'dashboard/layout', [
			'match'      => $match,
			'context'    => $context,
			'urls'       => $this->urls,
			'nav'        => $this->nav_items( $match, $context ),
			'page_title' => $this->title( $match ),
			'content'    => $content,
			'user'       => wp_get_current_user(),
		] );
	}

	/**
	 * Redirect guests to the login page with a notice (spec: "/members/* redirects here with a notification").
	 */
	public function require_login( RouteMatch $match ): MemberContext {
		if ( ! is_user_logged_in() ) {
			$this->flash->info( __( 'You need to be logged in to view that page.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->login( $this->urls->current() ) );
			exit;
		}
		return $this->contexts->current();
	}

	/**
	 * Sidebar navigation. Visibility follows the member's role (roadmap §3.1).
	 *
	 * @return array<int, array{key:string, label:string, href:string, icon:string, active:bool}>
	 */
	public function nav_items( RouteMatch $match, MemberContext $context ): array {
		$items = [
			[ 'key' => 'dashboard', 'label' => __( 'Dashboard', 'southpoll-membership' ), 'href' => $this->urls->dashboard(), 'icon' => 'home', 'show' => true ],
			[ 'key' => 'my-favorites', 'label' => __( 'My Favorites', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'my-favorites' ), 'icon' => 'star', 'show' => true ],
			[ 'key' => 'saved-searches', 'label' => __( 'Saved Searches', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'saved-searches' ), 'icon' => 'search', 'show' => true ],
			[ 'key' => 'my-farm', 'label' => __( 'My Farm', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'my-farm' ), 'icon' => 'barn', 'show' => $context->can( 'view_farm_pages' ) ],
			[ 'key' => 'my-listings', 'label' => __( 'My Listings', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'my-listings' ), 'icon' => 'tag', 'show' => $context->can( 'view_farm_pages' ) ],
			[ 'key' => 'manage-membership', 'label' => __( 'My Membership', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'manage-membership' ), 'icon' => 'card', 'show' => true ],
			[ 'key' => 'account-details', 'label' => __( 'Account Details', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'account-details' ), 'icon' => 'user', 'show' => true ],
			[ 'key' => 'help', 'label' => __( 'Help Center', 'southpoll-membership' ), 'href' => $this->urls->help(), 'icon' => 'help', 'show' => true ],
			[ 'key' => 'ui-kit', 'label' => __( 'UI Kit (staff)', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'ui-kit' ), 'icon' => 'grid', 'show' => $context->is_staff() ],
			[ 'key' => 'logout', 'label' => __( 'Log Out', 'southpoll-membership' ), 'href' => $this->urls->logout(), 'icon' => 'logout', 'show' => true ],
		];
		$active = 'edit-listing' === $match->name ? 'my-listings' : ( 'checkout-return' === $match->name ? 'manage-membership' : $match->name );
		$out    = [];
		foreach ( $items as $item ) {
			if ( ! $item['show'] ) {
				continue;
			}
			unset( $item['show'] );
			$item['active'] = $item['key'] === $active;
			$out[]          = $item;
		}
		/**
		 * @param array         $out
		 * @param MemberContext $context
		 */
		return (array) apply_filters( 'sp/dashboard/nav', $out, $context );
	}

	private function logout(): void {
		$nonce = isset( $_GET['sp_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'sp_logout' ) ) {
			wp_logout();
			$this->flash->success( __( 'You have been logged out.', 'southpoll-membership' ) );
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
