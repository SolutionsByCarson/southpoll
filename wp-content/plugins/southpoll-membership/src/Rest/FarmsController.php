<?php
/**
 * Public directory endpoints.
 *
 *   GET /sp/v1/farms            live-filtered farm cards (q, state, region, has_listings, since, offerings[], stock[], sort, page, per_page)
 *   GET /sp/v1/farms/pins       map pins for the same filters (no paging)
 *   GET /sp/v1/farms/filters    filter options (states/regions with counts, offerings, stock categories, windows, sorts)
 *   GET /sp/v1/farms/{slug}     one farm card (public farms only)
 *
 * Card HTML is rendered server-side (templates/directory/card.php) so the SSR page and the live feed share one template.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Farms\DirectoryQuery;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Support\Str;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FarmsController extends BaseController {

	public const PARAMS = [ 'q', 'state', 'region', 'has_listings', 'since', 'offerings', 'stock', 'sort', 'page', 'per_page' ];

	public function __construct(
		private DirectoryQuery $query,
		private FarmPresenter $presenter,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private Offerings $offerings,
		private ContextProvider $contexts,
		private View $view
	) {}

	public function register_routes(): void {
		$this->route( '/farms', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
		$this->route( '/farms/pins', [ [ 'methods' => 'GET', 'callback' => [ $this, 'pins' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
		$this->route( '/farms/filters', [ [ 'methods' => 'GET', 'callback' => [ $this, 'filters' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
		$this->route( '/farms/(?P<slug>[a-z0-9-]+)', [ [ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
	}

	/** @return array<string, mixed> */
	private function args( WP_REST_Request $request ): array {
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
			$item['html'] = $this->view->render( 'directory/card', [ 'item' => $item, 'context' => $ctx ] );
		}
		unset( $item );
		return $this->list( $items, $result['total'], $result['page'], $result['per_page'], [ 'args' => $result['args'], 'exact' => $this->presenter->exact_coordinates( $ctx ) ] );
	}

	public function pins( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = $this->contexts->current();
		$exact = $this->presenter->exact_coordinates( $ctx );
		$items = $this->presenter->pins( $this->query->pins( $this->args( $request ) ), $exact, $ctx );
		return $this->ok( [ 'items' => $items, 'total' => count( $items ), 'exact' => $exact ] );
	}

	public function filters( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->filter_options() );
	}

	/** @return array<string, mixed> shared with the SSR directory page */
	public function filter_options(): array {
		$names  = Str::us_states();
		$states = [];
		foreach ( $this->query->states() as $abbr => $count ) {
			$states[] = [ 'value' => $abbr, 'label' => $names[ $abbr ] ?? $abbr, 'count' => $count ];
		}
		$regions = [];
		foreach ( $this->query->regions() as $region => $count ) {
			$regions[] = [ 'value' => $region, 'label' => $region, 'count' => $count ];
		}
		/**
		 * Listing-category options for the "Stock available" filter (Phase 3). [ ['value'=>slug,'label'=>name], … ]
		 *
		 * @param array $options
		 */
		$stock = (array) apply_filters( 'sp/directory/stock_options', [] );
		return [
			'states'    => $states,
			'regions'   => $regions,
			'offerings' => array_map( static fn( $o ) => [ 'value' => $o->slug, 'label' => $o->name, 'count' => $o->count ], $this->offerings->all() ),
			'stock'     => array_values( $stock ),
			'since'     => [
				[ 'value' => '24h', 'label' => __( 'Last 24 hours', 'southpoll-membership' ) ],
				[ 'value' => '3d', 'label' => __( 'Last 3 days', 'southpoll-membership' ) ],
				[ 'value' => 'week', 'label' => __( 'Last week', 'southpoll-membership' ) ],
				[ 'value' => 'month', 'label' => __( 'Last month', 'southpoll-membership' ) ],
			],
			'sorts'     => [
				[ 'value' => 'name_asc', 'label' => __( 'Name A–Z', 'southpoll-membership' ) ],
				[ 'value' => 'name_desc', 'label' => __( 'Name Z–A', 'southpoll-membership' ) ],
				[ 'value' => 'active', 'label' => __( 'Most active', 'southpoll-membership' ) ],
			],
			'total'     => $this->query->public_count(),
		];
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$farm = $this->farms->find_by_slug( sanitize_title( (string) $request['slug'] ) );
		$ctx  = $this->contexts->current();
		if ( ! $farm || ( ! $this->visibility->is_public( $farm ) && ! $this->visibility->can_preview( $farm, $ctx ) ) ) {
			return $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
		}
		$card         = $this->presenter->card( $farm, $ctx );
		$card['html'] = $this->view->render( 'directory/card', [ 'item' => $card, 'context' => $ctx ] );
		return $this->ok( $card );
	}
}
