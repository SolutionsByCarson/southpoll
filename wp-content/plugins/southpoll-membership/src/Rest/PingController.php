<?php
/**
 * GET /sp/v1/ping — connectivity + auth check used by the JS bundle and tests.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Members\ContextProvider;
use WP_REST_Request;
use WP_REST_Response;

final class PingController extends BaseController {

	public function __construct( private ContextProvider $contexts ) {}

	public function register_routes(): void {
		$this->route( '/ping', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'ping' ],
			'permission_callback' => [ self::class, 'allow_public' ],
		] );
	}

	public function ping( WP_REST_Request $request ): WP_REST_Response {
		$context = $this->contexts->current();
		return $this->ok( [
			'ok'         => true,
			'version'    => SP_VERSION,
			'time'       => current_time( 'mysql', true ),
			'logged_in'  => is_user_logged_in(),
			'role'       => $context->is_staff() ? 'staff' : $context->role(),
			'membership' => $context->membership_status(),
		] );
	}
}
