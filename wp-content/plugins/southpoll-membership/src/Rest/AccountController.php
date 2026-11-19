<?php
/**
 * Account endpoints: resend verification, current context.
 *
 *   GET  /sp/v1/account/me
 *   POST /sp/v1/account/resend-verification
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Auth\AccountService;
use SouthPoll\Membership\Members\ContextProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AccountController extends BaseController {

	public function __construct( private ContextProvider $contexts, private AccountService $accounts ) {}

	public function register_routes(): void {
		$this->route( '/account/me', [ [ 'methods' => 'GET', 'callback' => [ $this, 'me' ], 'permission_callback' => [ self::class, 'require_login' ] ] ] );
		$this->route( '/account/resend-verification', [ [ 'methods' => 'POST', 'callback' => [ $this, 'resend' ], 'permission_callback' => [ self::class, 'require_login' ] ] ] );
		$this->route( '/account/preferences', [ [ 'methods' => 'PATCH', 'callback' => [ $this, 'preferences' ], 'permission_callback' => [ self::class, 'require_login' ] ] ] );
		$this->route( '/account/dismiss', [ [ 'methods' => 'POST', 'callback' => [ $this, 'dismiss' ], 'permission_callback' => [ self::class, 'require_login' ] ] ] );
	}

	/** Inline toggles on My Favorites / Saved Searches: notify_all, default_notify_favorites, default_notify_searches. */
	public function preferences( WP_REST_Request $request ): WP_REST_Response {
		$ctx     = $this->contexts->current();
		$members = sp_membership()->get( \SouthPoll\Membership\Members\MemberRepository::class );
		$member  = $members->find_by_user( $ctx->user_id ) ?? $members->create_for_user( $ctx->user_id );
		$data    = [];
		foreach ( [ 'notify_all', 'default_notify_favorites', 'default_notify_searches' ] as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = (int) rest_sanitize_boolean( $request->get_param( $key ) );
			}
		}
		if ( $data ) {
			$members->update( (int) $member->id, $data );
		}
		$fresh = $members->find( (int) $member->id );
		return $this->ok( [ 'ok' => true, 'notify_all' => (bool) $fresh->notify_all, 'default_notify_favorites' => (bool) $fresh->default_notify_favorites, 'default_notify_searches' => (bool) $fresh->default_notify_searches, 'message' => __( 'Preference saved.', 'southpoll-membership' ) ] );
	}

	/**
	 * POST /account/dismiss {key, value} — remember that the current user dismissed something (S7 launch popup: key
	 * `launch_popup`, value = the popup version). Stored as user meta `sp_dismissed_<key>`.
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key = sanitize_key( (string) $request->get_param( 'key' ) );
		if ( '' === $key || strlen( $key ) > 40 ) {
			return $this->fail( 'sp_bad_key', __( 'Unknown item.', 'southpoll-membership' ), 422 );
		}
		$value = sanitize_text_field( (string) ( $request->get_param( 'value' ) ?? '1' ) );
		update_user_meta( get_current_user_id(), self::dismiss_meta_key( $key ), '' === $value ? '1' : substr( $value, 0, 60 ) );
		return $this->ok( [ 'dismissed' => $key, 'value' => $value ] );
	}

	public static function dismiss_meta_key( string $key ): string {
		return 'sp_dismissed_' . sanitize_key( $key );
	}

	public function me( WP_REST_Request $request ): WP_REST_Response {
		$ctx = $this->contexts->current();
		return $this->ok( [
			'user_id'    => $ctx->user_id,
			'role'       => $ctx->is_staff() ? 'staff' : $ctx->role(),
			'verified'   => $ctx->is_verified(),
			'farm_id'    => $ctx->farm_id(),
			'membership' => $ctx->membership_status(),
			'days'       => $ctx->days_remaining(),
		] );
	}

	public function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'resend_verification', 5 );
		if ( true !== $limit ) {
			return $limit;
		}
		$ctx    = $this->contexts->current();
		$result = $this->accounts->send_verification( $ctx->user_id, '' !== (string) ( $ctx->member->pending_email ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result->get_error_code(), $result->get_error_message(), 429 );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Verification email sent.', 'southpoll-membership' ) ] );
	}
}
