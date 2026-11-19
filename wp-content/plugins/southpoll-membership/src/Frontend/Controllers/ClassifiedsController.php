<?php
/**
 * /classifieds/ (archive), /classifieds/{category}/ (category archive with SEO title/description), /search/{token}/
 * (shared saved search). Also rendered by the [classifieds] shortcode. SSR first page + Alpine (spClassifieds) for
 * live filters, map view, paging and the save-search button.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Rest\ListingsController;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class ClassifiedsController implements Controller {

	private ?object $category = null;

	private ?object $shared = null;

	private bool $resolved = false;

	public function __construct(
		private View $view,
		private Urls $urls,
		private ListingQuery $query,
		private ListingPresenter $presenter,
		private ListingTypes $types,
		private SavedSearchRepository $searches,
		private SavedSearchService $search_service,
		private ContextProvider $contexts,
		private Settings $settings,
		private Integrations $integrations
	) {}

	private function resolve( RouteMatch $match ): void {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;
		if ( $match->is( 'category' ) ) {
			$this->category = $this->types->category( (string) $match->param( 'sp_category', '' ) );
		} elseif ( $match->is( 'search-share' ) ) {
			$this->shared = $this->searches->find_by_token( sanitize_text_field( (string) $match->param( 'sp_token', '' ) ) );
		}
	}

	/** Runs before the theme header (Router::theme_template): SEO description + 404 status. */
	public function prepare( RouteMatch $match ): void {
		$this->resolve( $match );
		if ( $match->is( 'category' ) ) {
			if ( ! $this->category ) {
				status_header( 404 );
				add_filter( 'wp_robots', 'wp_robots_no_robots' );
				return;
			}
			$desc = (string) $this->category->seo_description ?: (string) $this->category->description;
			if ( '' !== $desc ) {
				add_filter( 'wpseo_metadesc', static fn() => $desc, 99 );
				add_filter( 'wpseo_opengraph_desc', static fn() => $desc, 99 );
				if ( ! defined( 'WPSEO_VERSION' ) ) {
					add_action( 'wp_head', static function () use ( $desc ): void {
						echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
					}, 1 );
				}
			}
		} elseif ( $match->is( 'search-share' ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			if ( ! $this->shared ) {
				status_header( 404 );
			}
		}
	}

	public function title( RouteMatch $match ): string {
		$this->resolve( $match );
		if ( $match->is( 'category' ) ) {
			if ( ! $this->category ) {
				return __( 'Category not found', 'southpoll-membership' );
			}
			return '' !== (string) $this->category->seo_title ? (string) $this->category->seo_title : sprintf( /* translators: %s: category */ __( '%s – Classifieds', 'southpoll-membership' ), (string) $this->category->name );
		}
		if ( $match->is( 'search-share' ) ) {
			return $this->shared ? sprintf( /* translators: %s: search name */ __( 'Shared search: %s', 'southpoll-membership' ), (string) $this->shared->name ) : __( 'Shared search not found', 'southpoll-membership' );
		}
		return (string) ( $match->spec['title'] ?? __( 'Classifieds', 'southpoll-membership' ) );
	}

	public function render( RouteMatch $match ): void {
		$this->resolve( $match );
		if ( $match->is( 'category' ) && ! $this->category ) {
			$this->view->display( 'classifieds/not-found', [ 'urls' => $this->urls, 'what' => 'category' ] );
			return;
		}
		if ( $match->is( 'search-share' ) ) {
			if ( ! $this->shared ) {
				$this->view->display( 'classifieds/not-found', [ 'urls' => $this->urls, 'what' => 'search' ] );
				return;
			}
			echo $this->render_archive( [ 'page' => $match->page(), 'shared' => $this->shared ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo $this->render_archive( [ 'page' => $match->page(), 'category' => $this->category, 'route' => $match->name ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string, mixed> $atts page, per_page, view, heading, intro, category (object), shared (saved search row), route
	 */
	public function render_archive( array $atts = [] ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters in the query string
		$get  = wp_unslash( $_GET );
		$args = [];
		foreach ( ListingsController::PARAMS as $p ) {
			if ( isset( $get[ $p ] ) ) {
				$args[ $p ] = $get[ $p ];
			}
		}
		$view     = ( isset( $get['view'] ) && 'map' === $get['view'] ) || ( ( $atts['view'] ?? '' ) === 'map' ) ? 'map' : 'list';
		$saved_id = isset( $get['saved'] ) ? (int) $get['saved'] : 0;
		$show_all = isset( $get['all'] );
		// phpcs:enable
		$ctx      = $this->contexts->current();
		$category = $atts['category'] ?? null;
		$shared   = $atts['shared'] ?? null;
		$notice   = null;
		$filtered = count( array_intersect_key( $args, array_flip( [ 'q', 'cats', 'state', 'region', 'farms', 'since' ] ) ) ) > 0;

		if ( $category ) {
			$args['cats'] = [ $category->slug ];
		}
		if ( $shared ) {
			$args   = array_merge( $shared->params, array_intersect_key( $args, array_flip( [ 'page', 'per_page', 'sort' ] ) ) );
			$notice = [ 'type' => 'shared', 'name' => (string) $shared->name, 'summary' => $this->search_service->describe( $shared->params ) ];
		} elseif ( $saved_id && $ctx->is_logged_in() ) {
			$saved = $this->searches->find_for_user( $ctx->user_id, $saved_id );
			if ( $saved ) {
				$this->searches->touch_viewed( $saved_id );
				$notice = [ 'type' => 'saved', 'id' => $saved_id, 'name' => (string) $saved->name, 'summary' => $this->search_service->describe( $saved->params ) ];
			}
		}
		if ( ! empty( $atts['page'] ) && empty( $args['page'] ) ) {
			$args['page'] = (int) $atts['page'];
		}
		if ( ! empty( $atts['per_page'] ) ) {
			$args['per_page'] = $atts['per_page'];
		}

		$result = $this->query->run( $args );
		$items  = $this->presenter->cards( $result['items'], $ctx );
		foreach ( $items as &$item ) {
			$item['html'] = $this->view->render( 'classifieds/card', [ 'item' => $item, 'context' => $ctx, 'compact' => false ] );
		}
		unset( $item );
		$options  = $this->query->filter_options();
		$facets   = $this->query->facets( $result['args'] );
		$saved_list = $ctx->is_logged_in() && $ctx->can( 'use_saved_searches' ) ? array_map( fn( $s ) => $this->search_service->serialize( $s, false ), $this->searches->for_user( $ctx->user_id ) ) : [];
		$defaults = [ 'q' => '', 'cats' => [], 'state' => '', 'region' => '', 'farms' => [], 'since' => '', 'sort' => (string) $this->settings->get( 'classifieds_sort_default', 'newest' ) ];
		$values   = array_intersect_key( $result['args'], $defaults );
		$values['farms'] = array_map( 'strval', $values['farms'] );

		return $this->view->render( 'classifieds/index', [
			'heading'      => (string) ( $atts['heading'] ?? ( $category ? (string) $category->name : __( 'Classifieds', 'southpoll-membership' ) ) ),
			'intro'        => (string) ( $atts['intro'] ?? ( $category ? (string) $category->description : '' ) ),
			'category'     => $category,
			'categories'   => $this->types->categories(),
			'items'        => $items,
			'result'       => $result,
			'options'      => $options,
			'facets'       => $facets,
			'saved_searches' => $saved_list,
			'context'      => $ctx,
			'urls'         => $this->urls,
			'notice'       => $notice,
			'maps_enabled' => $this->integrations->maps_configured(),
			'config'       => [
				'endpoint'       => 'listings',
				'pinsEndpoint'   => 'listings/pins',
				'defaults'       => $defaults,
				'values'         => $values,
				'facets'         => $facets,
				'savedSearches'  => array_map( static fn( $s ) => [ 'id' => $s['id'], 'name' => $s['name'], 'summary' => $s['summary'], 'params' => $s['params'] ], $saved_list ),
				'savedId'        => $notice && 'saved' === $notice['type'] ? (int) ( $notice['id'] ?? 0 ) : 0,
				'notice'         => $notice ? [
					'type'    => $notice['type'],
					'title'   => 'saved' === $notice['type'] ? sprintf( /* translators: %s: name */ __( 'Saved search: %s', 'southpoll-membership' ), $notice['name'] ) : sprintf( /* translators: %s: name */ __( 'Shared search: %s', 'southpoll-membership' ), $notice['name'] ),
					'message' => 'saved' === $notice['type'] ? $notice['summary'] : $notice['summary'] . ' — ' . __( 'Like it? Save it to your own account with "Save this search".', 'southpoll-membership' ),
					'href'    => 'saved' === $notice['type'] ? $this->urls->saved_searches() : '',
					'label'   => __( 'Manage saved searches', 'southpoll-membership' ),
				] : null,
				'view'           => $view,
				'page'           => $result['page'],
				'pages'          => $result['pages'],
				'total'          => $result['total'],
				'perPage'        => $result['per_page'],
				'initial'        => $items,
				'mapsEnabled'    => $this->integrations->maps_configured(),
				'lockedCategory' => $category ? $category->slug : '',
				'canSave'        => $ctx->can( 'use_saved_searches' ),
				'isLoggedIn'     => $ctx->is_logged_in(),
				'savedId'        => $saved_id,
				'farmsOptions'   => $options['farms'],
				'loginUrl'       => $this->urls->login( $this->urls->classifieds() ),
				'classifiedsUrl' => $this->urls->classifieds(),
			],
		] );
	}
}
