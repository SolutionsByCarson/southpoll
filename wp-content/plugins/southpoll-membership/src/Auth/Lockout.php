<?php
/**
 * Login lockout: N failed attempts per IP or per account within the window → temporary block.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Auth;

use SouthPoll\Membership\Core\Settings;

final class Lockout {

	public function __construct( private Settings $settings ) {}

	private function keys( string $login ): array {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return [
			'sp_lock_ip_' . md5( $ip ),
			'sp_lock_user_' . md5( strtolower( trim( $login ) ) ),
		];
	}

	public function is_locked( string $login ): bool {
		$limit = (int) $this->settings->get( 'login_lockout_attempts', 6 );
		foreach ( $this->keys( $login ) as $key ) {
			if ( (int) get_transient( $key ) >= $limit ) {
				return true;
			}
		}
		return false;
	}

	public function record_failure( string $login ): void {
		$window = (int) $this->settings->get( 'login_lockout_minutes', 15 ) * MINUTE_IN_SECONDS;
		foreach ( $this->keys( $login ) as $key ) {
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, $window );
		}
	}

	public function clear( string $login ): void {
		foreach ( $this->keys( $login ) as $key ) {
			delete_transient( $key );
		}
	}

	public function minutes(): int {
		return (int) $this->settings->get( 'login_lockout_minutes', 15 );
	}
}
