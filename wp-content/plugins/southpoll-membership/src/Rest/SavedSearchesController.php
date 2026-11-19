<?php
/**
 * Saved searches (verified accounts).
 *
 *   GET    /sp/v1/saved-searches
 *   POST   /sp/v1/saved-searches               { params: {...}, name?, notify? }
 *   PATCH  /sp/v1/saved-searches/{id}          { name?, notify? }
 *   DELETE /sp/v1/saved-searches/{id}
 *   POST   /sp/v1/saved-searches/notify-all    { notify }
 *   POST   /sp/v1/saved-searches/{id}/viewed
 *   POST   /sp/v1/saved-searches/{id}/share    → { share_url }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SavedSearchesController extends BaseController {

	public function __construct( private ContextProvider $contexts, private SavedSearchRepository $searches, private SavedSearchService $service, private Urls $urls ) {}

	public function register_routes(): void {
		$perm = [ $this, 'can_use' ];
		$this->route( '/saved-searches', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/saved-searches/notify-all', [ [ 'methods' => 'POST', 'callback' => [ $this, 'notify_all' ], 'permission_callback' => $perm ] ] );
		$this->route( '/saved-searches/(?P<id>\d+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/saved-searches/(?P<id>\d+)/viewed', [ [ 'methods' => 'POST', 'callback' => [ $this, 'viewed' ], 'permission_callback' => $perm ] ] );
		$this->route( '/saved-searches/(?P<id>\d+)/share', [ [ 'methods' => 'POST', 'callback' => [ $this, 'share' ], 'permission_callback' => $perm ] ] );
	}

	public function can_use(): bool|WP_Error {
		$login = self::require_login();
		if ( true !== $login ) {
			return $login;
		}
		if ( ! $this->contexts->current()->can( 'use_saved_searches' ) ) {
			return new WP_Error( 'sp_verify_required', __( 'Please verify your email address to save searches.', 'southpoll-membership' ), [ 'status' => 403, 'verify' => true ] );
		}
		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'items' => $this->all() ] );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'saved_search_create', 60 );
		if ( true !== $limit ) {
			return $limit;
		}
		$params = $request->get_param( 'params' );
		$notify = $request->has_param( 'notify' ) ? rest_sanitize_boolean( $request->get_param( 'notify' ) ) : null;
		$row    = $this->service->save( $this->contexts->current(), is_array( $params ) ? $params : [], (string) $request->get_param( 'name' ), $notify );
		if ( is_wp_error( $row ) ) {
			return $this->fail( $row->get_error_code(), $row->get_error_message(), 422 );
		}
		$item = $this->service->serialize( $row, false );
		return $this->ok( [
			'ok'            => true,
			'already_saved' => (bool) ( $row->already_saved ?? false ),
			'item'          => $item,
			'message'       => ! empty( $row->already_saved ) ? __( 'You already saved this search.', 'southpoll-membership' ) : __( 'Search saved.', 'southpoll-membership' ),
			'note'          => $item['notify'] ? __( 'You will get an email when a new listing matches.', 'southpoll-membership' ) : __( 'Want an email when new listings match? Turn it on under Saved Searches.', 'southpoll-membership' ),
			'searches_url'  => $this->urls->saved_searches(),
		] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$ctx  = $this->contexts->current();
		$data = [];
		if ( $request->has_param( 'name' ) ) {
			$data['name'] = (string) $request->get_param( 'name' );
		}
		if ( $request->has_param( 'notify' ) ) {
			$data['notify'] = rest_sanitize_boolean( $request->get_param( 'notify' ) );
		}
		if ( $data ) {
			$this->searches->update( (int) $row->id, $data );
		}
		$fresh = $this->searches->find( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'item' => $this->service->serialize( $fresh, false ), 'items' => $this->all(), 'message' => __( 'Saved.', 'southpoll-membership' ) ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->searches->delete( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'items' => $this->all(), 'message' => __( 'Saved search removed.', 'southpoll-membership' ) ] );
	}

	public function notify_all( WP_REST_Request $request ): WP_REST_Response {
		$on = rest_sanitize_boolean( $request->get_param( 'notify' ) );
		$n  = $this->searches->set_notify_all( $this->contexts->current()->user_id, $on );
		return $this->ok( [ 'ok' => true, 'updated' => $n, 'items' => $this->all(), 'message' => $on ? __( 'Email alerts turned on for all searches.', 'southpoll-membership' ) : __( 'Email alerts turned off for all searches.', 'southpoll-membership' ) ] );
	}

	public function viewed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->searches->touch_viewed( (int) $row->id );
		return $this->ok( [ 'ok' => true ] );
	}

	public function share( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$token = rest_sanitize_boolean( $request->get_param( 'regenerate' ) ) ? $this->searches->regenerate_share_token( (int) $row->id ) : $this->searches->ensure_share_token( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'share_url' => $this->urls->search_share( $token ), 'title' => (string) $row->name ] );
	}

	private function own( int $id ): \stdClass|WP_Error {
		$row = $this->searches->find_for_user( $this->contexts->current()->user_id, $id );
		return $row ?: $this->fail( 'sp_not_found', __( 'Saved search not found.', 'southpoll-membership' ), 404 );
	}

	/** @return array<int, array<string, mixed>> */
	private function all(): array {
		return array_map( fn( $s ) => $this->service->serialize( $s ), $this->searches->for_user( $this->contexts->current()->user_id ) );
	}
}
