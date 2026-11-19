<?php
/**
 * Favorites (verified accounts).
 *
 *   GET    /sp/v1/favorites?type=farm|listing
 *   POST   /sp/v1/favorites/toggle        { type, id }
 *   PATCH  /sp/v1/favorites/{id}          { notify }
 *   DELETE /sp/v1/favorites/{id}
 *   POST   /sp/v1/favorites/notify-all    { type, notify }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FavoritesController extends BaseController {

	public function __construct(
		private ContextProvider $contexts,
		private FavoriteRepository $favorites,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmPresenter $presenter,
		private Urls $urls
	) {}

	public function register_routes(): void {
		$perm = [ $this, 'can_use' ];
		$this->route( '/favorites', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/favorites/toggle', [ [ 'methods' => 'POST', 'callback' => [ $this, 'toggle' ], 'permission_callback' => $perm ] ] );
		$this->route( '/favorites/notify-all', [ [ 'methods' => 'POST', 'callback' => [ $this, 'notify_all' ], 'permission_callback' => $perm ] ] );
		$this->route( '/favorites/share-token', [ [ 'methods' => 'POST', 'callback' => [ $this, 'share_token' ], 'permission_callback' => $perm ] ] );
		$this->route( '/favorites/(?P<id>\d+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ],
		] );
	}

	public function can_use(): bool|WP_Error {
		$login = self::require_login();
		if ( true !== $login ) {
			return $login;
		}
		if ( ! $this->contexts->current()->can( 'use_favorites' ) ) {
			return new WP_Error( 'sp_verify_required', __( 'Please verify your email address to use favorites.', 'southpoll-membership' ), [ 'status' => 403, 'verify' => true ] );
		}
		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$type = FavoriteRepository::type( (string) $request->get_param( 'type' ) );
		$ctx  = $this->contexts->current();
		$rows = $this->favorites->for_user( $ctx->user_id, $type );
		if ( 'farm' === $type ) {
			$farms = $this->farms->find_many( array_map( static fn( $r ) => (int) $r->object_id, $rows ) );
			$items = $this->presenter->cards( array_values( $farms ), $ctx );
			$by_id = [];
			foreach ( $rows as $r ) {
				$by_id[ (int) $r->object_id ] = $r;
			}
			foreach ( $items as &$item ) {
				$item['notify']   = (bool) ( $by_id[ $item['id'] ]->notify ?? false );
				$item['active']   = isset( $farms[ $item['id'] ] ) && $this->visibility->is_public( $farms[ $item['id'] ] );
				$item['added_at'] = (string) ( $by_id[ $item['id'] ]->created_at ?? '' );
			}
			unset( $item );
		} else {
			/**
			 * Listing favorites are serialised by Phase 3.
			 *
			 * @param array  $items
			 * @param object[] $rows
			 */
			$items = (array) apply_filters( 'sp/favorites/listing_items', [], $rows, $ctx );
		}
		return $this->ok( [ 'items' => array_values( $items ), 'total' => count( $rows ), 'type' => $type ] );
	}

	public function toggle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'favorites', 240 );
		if ( true !== $limit ) {
			return $limit;
		}
		$type = FavoriteRepository::type( (string) $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );
		$ctx  = $this->contexts->current();
		if ( 'farm' === $type ) {
			$farm = $this->farms->find( $id );
			if ( ! $farm || ( ! $this->visibility->is_public( $farm ) && ! $this->favorites->find_for( $ctx->user_id, 'farm', $id ) ) ) {
				return $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
			}
		} elseif ( ! apply_filters( 'sp/favorites/listing_exists', false, $id ) ) {
			return $this->fail( 'sp_not_found', __( 'Listing not found.', 'southpoll-membership' ), 404 );
		}
		$result = $this->favorites->toggle( $ctx->user_id, $type, $id );
		$row    = $result['row'];
		$notify = $row ? (bool) $row->notify : false;
		return $this->ok( [
			'ok'          => true,
			'added'       => $result['added'],
			'favorite_id' => $row ? (int) $row->id : 0,
			'notify'      => $notify,
			'message'     => $result['added']
				? ( 'farm' === $type ? __( 'Farm added to your favorites.', 'southpoll-membership' ) : __( 'Listing added to your favorites.', 'southpoll-membership' ) )
				: __( 'Removed from your favorites.', 'southpoll-membership' ),
			'note'        => $result['added'] ? ( 'farm' === $type
				? ( $notify ? __( 'You will get an email when they publish a new listing.', 'southpoll-membership' ) : __( 'Want an email when they publish a new listing? Turn it on in My Favorites.', 'southpoll-membership' ) )
				: ( $notify ? __( 'You will get an email when its status changes.', 'southpoll-membership' ) : __( 'Want an email when its status changes? Turn it on in My Favorites.', 'southpoll-membership' ) ) ) : '',
			'favorites_url' => $this->urls->dashboard( 'my-favorites' ),
		] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $request->has_param( 'notify' ) ) {
			$this->favorites->set_notify( (int) $row->id, rest_sanitize_boolean( $request->get_param( 'notify' ) ) );
		}
		$fresh = $this->favorites->find( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'favorite_id' => (int) $fresh->id, 'notify' => (bool) $fresh->notify, 'message' => (bool) $fresh->notify ? __( 'Email alerts on.', 'southpoll-membership' ) : __( 'Email alerts off.', 'southpoll-membership' ) ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->favorites->remove( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Removed from your favorites.', 'southpoll-membership' ) ] );
	}

	public function notify_all( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = $this->contexts->current();
		$type  = FavoriteRepository::type( (string) $request->get_param( 'type' ) );
		$on    = rest_sanitize_boolean( $request->get_param( 'notify' ) );
		$count = $this->favorites->set_notify_all( $ctx->user_id, $type, $on );
		return $this->ok( [ 'ok' => true, 'updated' => $count, 'message' => $on ? __( 'Email alerts turned on for all.', 'southpoll-membership' ) : __( 'Email alerts turned off for all.', 'southpoll-membership' ) ] );
	}

	/** Public share link for the user's favorites list (token regenerable). */
	public function share_token( WP_REST_Request $request ): WP_REST_Response {
		$ctx     = $this->contexts->current();
		$members = sp_membership()->get( \SouthPoll\Membership\Members\MemberRepository::class );
		$member  = $members->find_by_user( $ctx->user_id ) ?? $members->create_for_user( $ctx->user_id );
		$token   = rest_sanitize_boolean( $request->get_param( 'regenerate' ) ) ? $members->regenerate_share_token( (int) $member->id ) : $members->ensure_share_token( (int) $member->id );
		return $this->ok( [ 'ok' => true, 'share_url' => $this->urls->favorites_share( $token ), 'message' => rest_sanitize_boolean( $request->get_param( 'regenerate' ) ) ? __( 'New share link created. The old link no longer works.', 'southpoll-membership' ) : '' ] );
	}

	private function own( int $id ): \stdClass|WP_Error {
		$row = $this->favorites->find( $id );
		if ( ! $row || (int) $row->user_id !== $this->contexts->current()->user_id ) {
			return $this->fail( 'sp_not_found', __( 'Favorite not found.', 'southpoll-membership' ), 404 );
		}
		return $row;
	}
}
