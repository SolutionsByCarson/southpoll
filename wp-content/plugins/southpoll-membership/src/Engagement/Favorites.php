<?php
/**
 * Favorites glue: the "Favorites" card on the dashboard home (favorite farms + favorite listings).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Urls;

final class Favorites implements Service {

	public function __construct( private FavoriteRepository $favorites, private FarmRepository $farms, private FarmPresenter $presenter, private View $view, private Urls $urls ) {}

	public function register(): void {
		add_filter( 'sp/dashboard/home_cards', [ $this, 'home_card' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $cards
	 * @return array<string, string>
	 */
	public function home_card( array $cards, MemberContext $context ): array {
		$farm_rows    = $this->favorites->for_user( $context->user_id, 'farm' );
		$listing_rows = $this->favorites->for_user( $context->user_id, 'listing' );
		if ( empty( $farm_rows ) && empty( $listing_rows ) ) {
			return $cards;
		}
		$farms = $this->farms->find_many( array_map( static fn( $r ) => (int) $r->object_id, array_slice( $farm_rows, 0, 5 ) ) );
		$items = $this->presenter->cards( array_values( $farms ), $context );
		$by_id = [];
		foreach ( $farm_rows as $r ) {
			$by_id[ (int) $r->object_id ] = $r;
		}
		foreach ( $items as &$item ) {
			$item['notify'] = (bool) ( $by_id[ $item['id'] ]->notify ?? false );
		}
		unset( $item );
		/** @var array<int, array<string, mixed>> $listings Phase 3 serialises listing favorites (ListingVisibility). */
		$listings = (array) apply_filters( 'sp/favorites/listing_items', [], array_slice( $listing_rows, 0, 5 ), $context );
		$cards['favorites'] = $this->view->render( 'dashboard/cards/favorite-farms', [
			'items'          => $items,
			'listings'       => array_values( $listings ),
			'total'          => count( $farm_rows ),
			'total_listings' => count( $listing_rows ),
			'inactive'       => count( array_filter( $listings, static fn( $l ) => empty( $l['active'] ) ) ),
			'context'        => $context,
			'urls'           => $this->urls,
		] );
		return $cards;
	}
}
