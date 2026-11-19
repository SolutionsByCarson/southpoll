<?php
/**
 * Farm users (owner-only): invite, resend, remove, notification toggles.
 *
 *   GET    /sp/v1/farm-users
 *   POST   /sp/v1/farm-users/invite        { email }
 *   POST   /sp/v1/farm-users/{id}/resend
 *   PATCH  /sp/v1/farm-users/{id}          { notify_listings, notify_membership }
 *   DELETE /sp/v1/farm-users/{id}
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Email\Messages\FarmUserChangedEmail;
use SouthPoll\Membership\Email\Messages\FarmUserInviteEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FarmUsersController extends BaseController {

	public function __construct( private ContextProvider $contexts, private FarmUserRepository $farm_users, private Notifier $notifier, private Urls $urls ) {}

	public function register_routes(): void {
		$this->route( '/farm-users', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => [ $this, 'owner_only' ] ],
		] );
		$this->route( '/farm-users/invite', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'invite' ], 'permission_callback' => [ $this, 'owner_only' ], 'args' => [ 'email' => [ 'required' => true, 'type' => 'string' ] ] ],
		] );
		$this->route( '/farm-users/(?P<id>\d+)/resend', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'resend' ], 'permission_callback' => [ $this, 'owner_only' ] ],
		] );
		$this->route( '/farm-users/(?P<id>\d+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => [ $this, 'owner_only' ] ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => [ $this, 'owner_only' ] ],
		] );
	}

	public function owner_only(): bool|WP_Error {
		$login = self::require_login();
		if ( true !== $login ) {
			return $login;
		}
		$ctx = $this->contexts->current();
		if ( ! $ctx->can( 'manage_farm_users' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the farm owner with an active membership can manage users.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'items' => $this->serialize_all() ] );
	}

	public function invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'farm_invite', 20 );
		if ( true !== $limit ) {
			return $limit;
		}
		$ctx = $this->contexts->current();
		$row = $this->farm_users->invite( $ctx->farm_id(), (string) $request->get_param( 'email' ), $ctx->user_id );
		if ( is_wp_error( $row ) ) {
			return $this->fail( $row->get_error_code(), $row->get_error_message(), 422 );
		}
		$this->send_invite( $row, $ctx );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Invitation sent.', 'southpoll-membership' ), 'items' => $this->serialize_all() ] );
	}

	public function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own_row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! empty( $row->accepted_at ) ) {
			return $this->fail( 'sp_already_accepted', __( 'That person has already joined.', 'southpoll-membership' ), 422 );
		}
		$ctx   = $this->contexts->current();
		$fresh = $this->farm_users->invite( $ctx->farm_id(), (string) $row->invite_email, $ctx->user_id );
		if ( is_wp_error( $fresh ) ) {
			return $this->fail( $fresh->get_error_code(), $fresh->get_error_message(), 422 );
		}
		$this->send_invite( $fresh, $ctx );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Invitation re-sent.', 'southpoll-membership' ), 'items' => $this->serialize_all() ] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own_row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$listings   = $request->has_param( 'notify_listings' ) ? rest_sanitize_boolean( $request->get_param( 'notify_listings' ) ) : (bool) $row->notify_listings;
		$membership = $request->has_param( 'notify_membership' ) ? rest_sanitize_boolean( $request->get_param( 'notify_membership' ) ) : (bool) $row->notify_membership;
		$this->farm_users->set_notify( (int) $row->id, $listings, $membership );
		return $this->ok( [ 'ok' => true, 'items' => $this->serialize_all() ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own_row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$ctx    = $this->contexts->current();
		$result = $this->farm_users->remove( (int) $row->id );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result->get_error_code(), $result->get_error_message(), 422 );
		}
		if ( ! empty( $row->user_id ) ) {
			$this->notifier->user( (int) $row->user_id, ( new FarmUserChangedEmail() )->with( [ 'event' => 'removed', 'farm_name' => $ctx->farm->name, 'dashboard_url' => $this->urls->dashboard() ] ) );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Removed.', 'southpoll-membership' ), 'items' => $this->serialize_all() ] );
	}

	/* ---------------------------------------------------------------- helpers */

	private function own_row( int $id ): \stdClass|WP_Error {
		$row = $this->farm_users->find( $id );
		$ctx = $this->contexts->current();
		if ( ! $row || (int) $row->farm_id !== $ctx->farm_id() ) {
			return $this->fail( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ), 404 );
		}
		return $row;
	}

	private function send_invite( object $row, $ctx ): void {
		$inviter = wp_get_current_user();
		$msg     = ( new FarmUserInviteEmail() )->to( (string) $row->invite_email )->with( [
			'farm_name'    => $ctx->farm->name,
			'inviter_name' => $this->notifier->name( $inviter ),
			'accept_url'   => $this->urls->invite( (string) $row->raw_token ),
		] );
		sp_membership()->get( \SouthPoll\Membership\Email\Mailer::class )->send( $msg );
	}

	/** @return array<int, array<string, mixed>> */
	private function serialize_all(): array {
		$ctx = $this->contexts->current();
		return array_map( fn( $r ) => [
			'id'                => (int) $r->id,
			'role'              => $r->role,
			'name'              => $r->display_name,
			'email'             => $r->email,
			'pending'           => $r->is_pending,
			'invited_at'        => $r->invited_at,
			'notify_listings'   => (bool) $r->notify_listings,
			'notify_membership' => (bool) $r->notify_membership,
			'is_me'             => (int) ( $r->user_id ?? 0 ) === $ctx->user_id,
		], $this->farm_users->for_farm( $ctx->farm_id() ) );
	}
}
