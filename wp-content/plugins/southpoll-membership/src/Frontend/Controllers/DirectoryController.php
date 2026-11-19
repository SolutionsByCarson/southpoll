<?php
/**
 * /members/ — the member directory (also rendered by the [member-directory] shortcode).
 * The first page is rendered server-side for SEO and instant paint; Alpine (spDirectory) takes over for live
 * filtering, paging and the map view through the /sp/v1/farms endpoints.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\DirectoryQuery;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Rest\FarmsController;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class DirectoryController implements Controller {

	public function __construct(
		private View $view,
		private Urls $urls,
		private DirectoryQuery $query,
		private FarmPresenter $presenter,
		private FarmsController $rest,
		private ContextProvider $contexts,
		private Settings $settings,
		private Integrations $integrations
	) {}

	public function title( RouteMatch $match ): string {
		return (string) ( $match->spec['title'] ?? __( 'Member Directory', 'southpoll-membership' ) );
	}

	public function render( RouteMatch $match ): void {
		echo $this->render_directory( [ 'page' => $match->page() ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output
	}

	/**
	 * @param array<string, mixed> $atts page, per_page, view, heading, intro (shortcode attributes)
	 */
	public function render_directory( array $atts = [] ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters in the query string
		$get  = wp_unslash( $_GET );
		$args = [];
		foreach ( FarmsController::PARAMS as $p ) {
			if ( isset( $get[ $p ] ) ) {
				$args[ $p ] = $get[ $p ];
			}
		}
		$view = isset( $get['view'] ) && 'map' === $get['view'] ? 'map' : ( ( $atts['view'] ?? 'list' ) === 'map' ? 'map' : 'list' );
		// phpcs:enable
		if ( ! empty( $atts['page'] ) && empty( $args['page'] ) ) {
			$args['page'] = (int) $atts['page'];
		}
		if ( ! empty( $atts['per_page'] ) ) {
			$args['per_page'] = $atts['per_page'];
		}
		$ctx    = $this->contexts->current();
		$result = $this->query->run( $args );
		$items  = $this->presenter->cards( $result['items'], $ctx );
		foreach ( $items as &$item ) {
			$item['html'] = $this->view->render( 'directory/card', [ 'item' => $item, 'context' => $ctx ] );
		}
		unset( $item );
		$options  = $this->rest->filter_options();
		$defaults = [ 'q' => '', 'state' => '', 'region' => '', 'has_listings' => '', 'since' => '', 'offerings' => [], 'stock' => [], 'sort' => (string) $this->settings->get( 'directory_sort_default', 'name_asc' ) ];
		$values   = $result['args'];
		$values['has_listings'] = $values['has_listings'] ? '1' : '';
		unset( $values['page'], $values['per_page'] );

		return $this->view->render( 'directory/index', [
			'heading'  => (string) ( $atts['heading'] ?? __( 'Member Directory', 'southpoll-membership' ) ),
			'intro'    => (string) ( $atts['intro'] ?? '' ),
			'items'    => $items,
			'result'   => $result,
			'options'  => $options,
			'context'  => $ctx,
			'urls'     => $this->urls,
			'config'   => [
				'endpoint'     => 'farms',
				'pinsEndpoint' => 'farms/pins',
				'defaults'     => $defaults,
				'values'       => array_intersect_key( $values, $defaults ),
				'view'         => $view,
				'page'         => $result['page'],
				'pages'        => $result['pages'],
				'total'        => $result['total'],
				'perPage'      => $result['per_page'],
				'initial'      => $items,
				'mapsEnabled'  => $this->integrations->maps_configured(),
				'loginUrl'     => $this->urls->login( $this->urls->directory() ),
			],
			'maps_enabled' => $this->integrations->maps_configured(),
		] );
	}
}
