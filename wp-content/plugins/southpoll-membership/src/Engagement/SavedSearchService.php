<?php
/**
 * Saved searches: naming, URLs, "new listings" counts, saving from the classifieds page, and the dashboard card.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class SavedSearchService implements Service {

	public function __construct(
		private SavedSearchRepository $searches,
		private ListingQuery $query,
		private ListingTypes $types,
		private ListingPresenter $presenter,
		private FarmRepository $farms,
		private View $view,
		private Urls $urls
	) {}

	public function register(): void {
		add_filter( 'sp/dashboard/home_cards', [ $this, 'home_card' ], 20, 2 );
	}

	/** Human summary of the filters, e.g. "Bulls, Females · Missouri · Barber Farms · last week". */
	public function describe( array $params ): string {
		$params = SavedSearchRepository::normalize_params( $params );
		$parts  = [];
		if ( ! empty( $params['q'] ) ) {
			$parts[] = '“' . $params['q'] . '”';
		}
		if ( ! empty( $params['cats'] ) ) {
			$names = [];
			foreach ( $this->types->categories() as $c ) {
				if ( in_array( $c->slug, $params['cats'], true ) ) {
					$names[] = $c->name;
				}
			}
			$parts[] = implode( ', ', $names ?: $params['cats'] );
		}
		if ( ! empty( $params['state'] ) ) {
			$parts[] = Str::us_states()[ $params['state'] ] ?? $params['state'];
		}
		if ( ! empty( $params['region'] ) ) {
			$parts[] = $params['region'];
		}
		if ( ! empty( $params['farms'] ) ) {
			$farms   = $this->farms->find_many( $params['farms'] );
			$parts[] = implode( ', ', array_map( static fn( $f ) => (string) $f->name, $farms ) ) ?: __( 'selected farms', 'southpoll-membership' );
		}
		if ( ! empty( $params['since'] ) ) {
			$parts[] = match ( $params['since'] ) {
				'24h'   => __( 'last 24 hours', 'southpoll-membership' ),
				'3d'    => __( 'last 3 days', 'southpoll-membership' ),
				'week'  => __( 'last week', 'southpoll-membership' ),
				'month' => __( 'last month', 'southpoll-membership' ),
				default => '',
			};
		}
		return implode( ' · ', array_filter( $parts ) ) ?: __( 'All classifieds', 'southpoll-membership' );
	}

	public function url( array $params, array $extra = [] ): string {
		$params = SavedSearchRepository::normalize_params( $params );
		$query  = [];
		foreach ( $params as $k => $v ) {
			$query[ $k ] = is_array( $v ) ? implode( ',', $v ) : $v;
		}
		return $this->urls->classifieds( $query + $extra );
	}

	/** @return array{since_viewed:int, last_week:int} */
	public function counts( object $search ): array {
		$since = (string) ( $search->last_viewed_at ?: $search->created_at );
		return [
			'since_viewed' => $this->query->count_since( $search->params, $since ),
			'last_week'    => $this->query->count_since( $search->params, Dates::add_days( Dates::now(), -7 ) ),
		];
	}

	/** Save from the classifieds page (dedupes identical filters). */
	public function save( MemberContext $ctx, array $params, string $name = '', ?bool $notify = null ): \stdClass|WP_Error {
		if ( ! $ctx->can( 'use_saved_searches' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'Please verify your email address to save searches.', 'southpoll-membership' ) );
		}
		$params = SavedSearchRepository::normalize_params( $params );
		if ( empty( $params ) ) {
			return new WP_Error( 'sp_empty', __( 'Add at least one filter or search term before saving.', 'southpoll-membership' ) );
		}
		if ( $this->searches->count( $ctx->user_id ) >= 50 ) {
			return new WP_Error( 'sp_too_many', __( 'You can keep up to 50 saved searches. Remove one first.', 'southpoll-membership' ) );
		}
		$existing = $this->searches->find_identical( $ctx->user_id, $params );
		if ( $existing ) {
			if ( '' !== trim( $name ) ) {
				$this->searches->update( (int) $existing->id, [ 'name' => $name ] );
			}
			$row               = $this->searches->find( (int) $existing->id );
			$row->already_saved = true;
			return $row;
		}
		$row                = $this->searches->create( $ctx->user_id, '' !== trim( $name ) ? $name : $this->describe( $params ), $params, $notify );
		$row->already_saved = false;
		return $row;
	}

	/** @return array<string, mixed> */
	public function serialize( object $search, bool $with_counts = true ): array {
		$counts = $with_counts ? $this->counts( $search ) : [ 'since_viewed' => 0, 'last_week' => 0 ];
		return [
			'id'           => (int) $search->id,
			'name'         => (string) $search->name,
			'summary'      => $this->describe( $search->params ),
			'params'       => $search->params,
			'url'          => $this->url( $search->params, [ 'saved' => (int) $search->id ] ),
			'notify'       => (bool) $search->notify,
			'new_count'    => $counts['since_viewed'],
			'week_count'   => $counts['last_week'],
			'share_url'    => $search->share_token ? $this->urls->search_share( (string) $search->share_token ) : '',
			'created_at'   => (string) $search->created_at,
			'created_date' => Dates::display( (string) $search->created_at ),
			'last_viewed'  => (string) ( $search->last_viewed_at ?? '' ),
		];
	}

	/** @param array<string, string> $cards */
	public function home_card( array $cards, MemberContext $ctx ): array {
		$rows = $this->searches->for_user( $ctx->user_id );
		if ( empty( $rows ) ) {
			return $cards;
		}
		$searches = array_map( fn( $s ) => $this->serialize( $s ), array_slice( $rows, 0, 5 ) );
		$feed     = [];
		$lead     = $rows[0];
		$result   = $this->query->run( $lead->params + [ 'per_page' => 5, 'sort' => 'newest' ] );
		foreach ( $this->presenter->cards( $result['items'], $ctx ) as $card ) {
			$card['html'] = $this->view->render( 'classifieds/card', [ 'item' => $card, 'context' => $ctx, 'compact' => true ] );
			$feed[]       = $card;
		}
		$cards['saved_searches'] = $this->view->render( 'dashboard/cards/saved-searches', [
			'context'   => $ctx,
			'urls'      => $this->urls,
			'searches'  => $searches,
			'total'     => count( $rows ),
			'feed'      => $feed,
			'feed_name' => (string) $lead->name,
			'feed_url'  => $this->url( $lead->params, [ 'saved' => (int) $lead->id ] ),
		] );
		return $cards;
	}
}
