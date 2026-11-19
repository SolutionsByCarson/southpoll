<?php
/**
 * Placeholder controller for every theme-wrapped route. Phases 1–3 replace each route with its real
 * controller (login, directory, farm page, classifieds, listing, share pages) via Routes::all().
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class ThemePageController implements Controller {

	public function __construct( private View $view, private Urls $urls ) {}

	public function title( RouteMatch $match ): string {
		return match ( $match->name ) {
			'farm'     => sprintf( /* translators: %s: farm slug */ __( 'Farm: %s', 'southpoll-membership' ), $match->slug() ),
			'listing'  => sprintf( /* translators: %s: listing slug */ __( 'Listing: %s', 'southpoll-membership' ), $match->slug() ),
			'category' => sprintf( /* translators: %s: category slug */ __( 'Classifieds: %s', 'southpoll-membership' ), (string) $match->param( 'sp_category', '' ) ),
			default    => (string) ( $match->spec['title'] ?? '' ),
		};
	}

	public function render( RouteMatch $match ): void {
		$phase = match ( $match->name ) {
			'login', 'verify', 'reset-password', 'invite' => 1,
			'directory', 'farm', 'favorites-share'        => 2,
			default                                        => 3,
		};
		$this->view->display( 'theme/placeholder', [
			'match'   => $match,
			'heading' => $this->title( $match ),
			'phase'   => $phase,
			'urls'    => $this->urls,
		] );
	}
}
