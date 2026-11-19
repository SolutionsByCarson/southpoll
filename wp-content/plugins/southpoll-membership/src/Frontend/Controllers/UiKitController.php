<?php
/**
 * Staff-only component showcase at /members/ui-kit/ for visual QA of the shared UI kit.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class UiKitController implements Controller {

	public function __construct( private View $view, private Urls $urls, private DashboardController $dashboard ) {}

	public function title( RouteMatch $match ): string {
		return __( 'UI Kit', 'southpoll-membership' );
	}

	public function render( RouteMatch $match ): void {
		$context = $this->dashboard->require_login( $match );
		if ( ! $context->is_staff() ) {
			wp_safe_redirect( $this->urls->dashboard() );
			exit;
		}
		$content = $this->view->render( 'ui-kit/index', [ 'urls' => $this->urls ] );
		$this->view->display( 'dashboard/layout', [
			'match'      => $match,
			'context'    => $context,
			'urls'       => $this->urls,
			'nav'        => $this->dashboard->nav_items( $match, $context ),
			'page_title' => $this->title( $match ),
			'content'    => $content,
			'user'       => wp_get_current_user(),
		] );
	}
}
