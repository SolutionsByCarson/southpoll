<?php
/**
 * /members/saved-searches/ — list, default, notify toggles (inline, bulk, default), new-listing counts, share, remove.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class SavedSearches implements Section {

	public function __construct( private View $view, private Urls $urls, private SavedSearchRepository $searches, private SavedSearchService $service, private MemberRepository $members ) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$member = $this->members->find_by_user( $context->user_id ) ?? $this->members->create_for_user( $context->user_id );
		$items  = array_map( fn( $s ) => $this->service->serialize( $s ), $this->searches->for_user( $context->user_id ) );
		return $this->view->render( 'dashboard/saved-searches', [
			'context'  => $context,
			'urls'     => $this->urls,
			'items'    => $items,
			'verified' => $context->can( 'use_saved_searches' ),
			'prefs'    => [ 'default_notify_searches' => (bool) $member->default_notify_searches, 'notify_all' => (bool) $member->notify_all ],
		] );
	}
}
