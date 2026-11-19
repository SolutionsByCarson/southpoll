<?php
/**
 * /members/my-favorites/ — farms / listings toggle, active vs inactive counts, live search, remove, notify toggles
 * (inline, bulk, default), share each, share-my-list modal.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class MyFavorites implements Section {

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private FavoriteRepository $favorites,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmPresenter $farm_presenter,
		private MemberRepository $members
	) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$member = $this->members->find_by_user( $context->user_id ) ?? $this->members->create_for_user( $context->user_id );
		$rows   = $this->favorites->for_user( $context->user_id, 'farm' );
		$farms  = $this->farms->find_many( array_map( static fn( $r ) => (int) $r->object_id, $rows ) );
		$cards  = $this->farm_presenter->cards( array_values( $farms ), $context );
		$by_id  = [];
		foreach ( $rows as $r ) {
			$by_id[ (int) $r->object_id ] = $r;
		}
		foreach ( $cards as &$card ) {
			$card['notify']   = (bool) ( $by_id[ $card['id'] ]->notify ?? false );
			$card['active']   = isset( $farms[ $card['id'] ] ) && $this->visibility->is_public( $farms[ $card['id'] ] );
			$card['added_at'] = (string) ( $by_id[ $card['id'] ]->created_at ?? '' );
			$card['html']     = $this->view->render( 'directory/card', [ 'item' => $card, 'context' => $context ] );
		}
		unset( $card );
		$listing_rows = $this->favorites->for_user( $context->user_id, 'listing' );
		$listings     = (array) apply_filters( 'sp/favorites/listing_items', [], $listing_rows, $context );
		return $this->view->render( 'dashboard/my-favorites', [
			'context'   => $context,
			'urls'      => $this->urls,
			'farms'     => array_values( $cards ),
			'listings'  => array_values( $listings ),
			'verified'  => $context->can( 'use_favorites' ),
			'prefs'     => [ 'default_notify_favorites' => (bool) $member->default_notify_favorites, 'notify_all' => (bool) $member->notify_all ],
			'share_url' => $member->favorites_share_token ? $this->urls->favorites_share( (string) $member->favorites_share_token ) : '',
		] );
	}
}
