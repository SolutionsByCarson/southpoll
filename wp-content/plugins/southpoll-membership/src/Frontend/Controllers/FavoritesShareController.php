<?php
/**
 * /favorites/{token}/ — a member's publicly shared favorites list (random token, D-10). Only public farms and
 * listings are shown; nothing about the sharer beyond a first name.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class FavoritesShareController implements Controller {

	private ?object $member = null;

	private bool $resolved = false;

	public function __construct(
		private View $view,
		private Urls $urls,
		private MemberRepository $members,
		private FavoriteRepository $favorites,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmPresenter $farm_presenter,
		private ListingRepository $listings,
		private ListingService $service,
		private ListingPresenter $presenter,
		private ContextProvider $contexts
	) {}

	private function resolve( RouteMatch $match ): void {
		if ( ! $this->resolved ) {
			$this->resolved = true;
			$token          = sanitize_text_field( (string) $match->param( 'sp_token', '' ) );
			$this->member   = '' !== $token ? $this->members->find_by_share_token( $token ) : null;
		}
	}

	public function prepare( RouteMatch $match ): void {
		$this->resolve( $match );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		if ( ! $this->member ) {
			status_header( 404 );
		}
	}

	public function title( RouteMatch $match ): string {
		$this->resolve( $match );
		return $this->member ? sprintf( /* translators: %s: first name */ __( 'Favorites shared by %s', 'southpoll-membership' ), $this->sharer_name() ) : __( 'Shared favorites not found', 'southpoll-membership' );
	}

	private function sharer_name(): string {
		return trim( (string) ( $this->member->first_name ?? '' ) ) ?: __( 'a SouthPoll.com member', 'southpoll-membership' );
	}

	public function render( RouteMatch $match ): void {
		$this->resolve( $match );
		$ctx = $this->contexts->current();
		if ( ! $this->member ) {
			$this->view->display( 'favorites/not-found', [ 'urls' => $this->urls ] );
			return;
		}
		$uid   = (int) $this->member->user_id;
		$farms = [];
		foreach ( $this->farms->find_many( array_map( static fn( $r ) => (int) $r->object_id, $this->favorites->for_user( $uid, 'farm' ) ) ) as $farm ) {
			if ( $this->visibility->is_public( $farm ) ) {
				$farms[] = $farm;
			}
		}
		$farm_cards = $this->farm_presenter->cards( $farms, $ctx );
		foreach ( $farm_cards as &$c ) {
			$c['html'] = $this->view->render( 'directory/card', [ 'item' => $c, 'context' => $ctx ] );
		}
		unset( $c );
		$listings = [];
		foreach ( $this->listings->find_many( array_map( static fn( $r ) => (int) $r->object_id, $this->favorites->for_user( $uid, 'listing' ) ) ) as $l ) {
			$farm = $this->farms->find( $l->farm_id );
			if ( $farm && $this->visibility->is_public( $farm ) && $l->is_public( $this->service->sold_visible_days() ) ) {
				$listings[] = $l;
			}
		}
		$listing_cards = $this->presenter->cards( $listings, $ctx );
		foreach ( $listing_cards as &$c ) {
			$c['html'] = $this->view->render( 'classifieds/card', [ 'item' => $c, 'context' => $ctx, 'compact' => false ] );
		}
		unset( $c );
		$this->view->display( 'favorites/share', [
			'sharer'   => $this->sharer_name(),
			'farms'    => $farm_cards,
			'listings' => $listing_cards,
			'urls'     => $this->urls,
			'context'  => $ctx,
			'is_mine'  => $ctx->user_id === $uid,
			'share'    => [ 'url' => $this->urls->favorites_share( (string) $this->member->favorites_share_token ), 'title' => sprintf( /* translators: %s: name */ __( 'Favorites shared by %s on SouthPoll.com', 'southpoll-membership' ), $this->sharer_name() ) ],
		] );
	}
}
