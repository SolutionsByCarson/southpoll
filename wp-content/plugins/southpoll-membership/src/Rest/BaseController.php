<?php
/**
 * Shared REST helpers: route registration, permission callbacks, pagination, envelopes, rate limiting.
 *
 * Response envelopes:
 *   single:  { ...data }
 *   list:    { items: [], total: n, page: n, per_page: n, pages: n, meta: {} }
 *   error:   WP_Error → { code, message, data: { status } }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Core\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

abstract class BaseController {

	public const MAX_PER_PAGE = 500;

	abstract public function register_routes(): void;

	protected function route( string $path, array $args ): void {
		register_rest_route( Api::NAMESPACE, $path, $args );
	}

	/* ----------------------------------------------------------- permissions */

	public static function allow_public(): bool {
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function require_login(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sp_login_required', __( 'You need to be logged in to do that.', 'southpoll-membership' ), [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function require_staff(): bool|WP_Error {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You do not have permission to do that.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/* --------------------------------------------------------------- output */

	protected function ok( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	protected function fail( string $code, string $message, int $status = 400, array $data = [] ): WP_Error {
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}

	/**
	 * Standard list envelope.
	 *
	 * @param array<int, mixed> $items
	 */
	protected function list( array $items, int $total, int $page, int $per_page, array $meta = [] ): WP_REST_Response {
		$pages = $per_page > 0 ? (int) max( 1, (int) ceil( $total / $per_page ) ) : 1;
		return $this->ok( [
			'items'    => array_values( $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
			'meta'     => (object) $meta,
		] );
	}

	/* ----------------------------------------------------------- pagination */

	/**
	 * @return array{page:int, per_page:int, offset:int, q:string, sort:string, dir:string}
	 */
	protected function paging( WP_REST_Request $request, int $default_per_page = 25, array $sortable = [], string $default_sort = '' ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$raw_pp   = $request->get_param( 'per_page' );
		$per_page = ( 'all' === $raw_pp || (int) $raw_pp >= self::MAX_PER_PAGE ) ? self::MAX_PER_PAGE : (int) $raw_pp;
		if ( $per_page <= 0 ) {
			$per_page = $default_per_page;
		}
		$sort = sanitize_key( (string) $request->get_param( 'sort' ) );
		if ( ! in_array( $sort, $sortable, true ) ) {
			$sort = $default_sort;
		}
		$dir = strtolower( (string) $request->get_param( 'dir' ) ) === 'desc' ? 'DESC' : 'ASC';
		return [
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
			'q'        => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'sort'     => $sort,
			'dir'      => $dir,
		];
	}

	/* --------------------------------------------------------- rate limiting */

	/**
	 * @return true|WP_Error
	 */
	protected function rate_limit( string $bucket, int $limit, int $window_seconds = HOUR_IN_SECONDS ): bool|WP_Error {
		$key = $bucket . ':' . ( is_user_logged_in() ? 'u' . get_current_user_id() : 'ip' . $this->ip_hash() );
		if ( ! RateLimiter::hit( $key, $limit, $window_seconds ) ) {
			return $this->fail( 'sp_rate_limited', __( 'Too many requests. Please wait a bit and try again.', 'southpoll-membership' ), 429 );
		}
		return true;
	}

	protected function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}
}
