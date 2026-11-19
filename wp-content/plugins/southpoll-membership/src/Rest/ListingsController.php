<?php
/**
 * Public classifieds endpoints.
 *
 *   GET  /sp/v1/listings            live-filtered listing cards (q, cats[], state, region, farms[], since, sort, page, per_page)
 *   GET  /sp/v1/listings/pins       map pins (one per farm with matching listings)
 *   GET  /sp/v1/listings/filters    filter options
 *   POST /sp/v1/listings/{id}/view  count a view (sent by the listing page script so cached pages still count)
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Members\ContextProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ListingsController extends BaseController {

	public const PARAMS = [ 'q', 'cats', 'state', 'region', 'farms', 'since', 'sort', 'page', 'per_page' ];

	public function __construct(
		private ListingQuery $query,
		private ListingPresenter $presenter,
		private ListingRepository $listings,
		private FarmPresenter $farm_presenter,
		private ContextProvider $contexts,
		private View $view
	) {}

	public function register_routes(): void {
		$public = [ self::class, 'allow_public' ];
		$this->route( '/listings', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $public ] ] );
		$this->route( '/listings/pins', [ [ 'methods' => 'GET', 'callback' => [ $this, 'pins' ], 'permission_callback' => $public ] ] );
		$this->route( '/listings/filters', [ [ 'methods' => 'GET', 'callback' => [ $this, 'filters' ], 'permission_callback' => $public ] ] );
		$this->route( '/listings/(?P<id>\d+)/view', [ [ 'methods' => 'POST', 'callback' => [ $this, 'view' ], 'permission_callback' => $public ] ] );
	}

	/** @return array<string, mixed> */
	public function args( WP_REST_Request $request ): array {
		$args = [];
		foreach ( self::PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		return $args;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->query->run( $this->args( $request ) );
		$ctx    = $this->contexts->current();
		$items  = $this->presenter->cards( $result['items'], $ctx );
		foreach ( $items as &$item ) {
			$item['html'] = $this->view->render( 'classifieds/card', [ 'item' => $item, 'context' => $ctx, 'compact' => false ] );
		}
		unset( $item );
		return $this->list( $items, $result['total'], $result['page'], $result['per_page'], [ 'args' => $result['args'], 'saved_params' => ListingQuery::saved_params( $result['args'] ), 'facets' => $this->query->facets( $result['args'] ) ] );
	}

	public function pins( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = $this->contexts->current();
		$exact = $this->farm_presenter->exact_coordinates( $ctx );
		$args  = $this->args( $request );
		$items = $this->farm_presenter->pins( $this->query->pins( $args ), $exact, $ctx );
		// Listing-centric info windows (T6.11): attach up to N matching listings per farm pin (same filters as the archive).
		if ( $items ) {
			$per_pin = max( 1, (int) apply_filters( 'sp/map/listings_per_pin', 5 ) );
			$ids     = array_map( static fn( array $p ) => (int) $p['id'], $items );
			$found   = $this->query->run( [ 'farms' => $ids, 'per_page' => min( 500, count( $ids ) * $per_pin * 2 ) ] + $args )['items'];
			$by_farm = [];
			foreach ( $this->presenter->cards( $found, $ctx ) as $card ) {
				$fid = (int) ( $card['farm']['id'] ?? 0 );
				if ( $fid && count( $by_farm[ $fid ] ?? [] ) < $per_pin ) {
					$by_farm[ $fid ][] = [ 'id' => $card['id'], 'title' => $card['title'], 'url' => $card['url'], 'price' => $card['price'], 'image' => $card['image'], 'is_new' => (bool) $card['is_new'], 'categories' => array_column( (array) $card['categories'], 'name' ) ];
				}
			}
			foreach ( $items as &$pin ) {
				$pin['listing_items'] = $by_farm[ (int) $pin['id'] ] ?? [];
			}
			unset( $pin );
		}
		return $this->ok( [ 'items' => $items, 'total' => count( $items ), 'exact' => $exact ] );
	}

	public function filters( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->query->filter_options() );
	}

	public function view( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'listing_view', 120, 10 * MINUTE_IN_SECONDS );
		if ( true !== $limit ) {
			return $limit;
		}
		$listing = $this->listings->find( (int) $request['id'] );
		if ( ! $listing || 'publish' !== $listing->post_status ) {
			return $this->fail( 'sp_not_found', __( 'Listing not found.', 'southpoll-membership' ), 404 );
		}
		$ctx = $this->contexts->current();
		if ( $ctx->has_farm() && $ctx->farm_id() === $listing->farm_id ) {
			return $this->ok( [ 'ok' => true, 'views' => $listing->view_count, 'counted' => false ] ); // sellers looking at their own ads don't count
		}
		$views = $this->listings->increment_views( $listing->id );
		do_action( 'sp/listing/viewed', $listing, $ctx );
		return $this->ok( [ 'ok' => true, 'views' => $views, 'counted' => true ] );
	}
}
