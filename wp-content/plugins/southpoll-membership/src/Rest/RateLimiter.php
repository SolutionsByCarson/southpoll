<?php
/**
 * Fixed-window rate limiter on transients (object cache on WP Engine, options table locally).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

final class RateLimiter {

	/**
	 * Register a hit and report whether it is within the limit.
	 */
	public static function hit( string $key, int $limit, int $window_seconds ): bool {
		$tkey  = 'sp_rl_' . md5( $key );
		$state = get_transient( $tkey );
		$now   = time();
		if ( ! is_array( $state ) || ( $state['reset'] ?? 0 ) <= $now ) {
			$state = [ 'count' => 0, 'reset' => $now + $window_seconds ];
		}
		$state['count']++;
		set_transient( $tkey, $state, max( 1, $state['reset'] - $now ) );
		return $state['count'] <= $limit;
	}

	public static function remaining( string $key, int $limit ): int {
		$state = get_transient( 'sp_rl_' . md5( $key ) );
		return is_array( $state ) ? max( 0, $limit - (int) $state['count'] ) : $limit;
	}

	public static function reset( string $key ): void {
		delete_transient( 'sp_rl_' . md5( $key ) );
	}
}
