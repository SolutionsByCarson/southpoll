<?php
/**
 * Builds MemberContext objects from the database with a per-request cache.
 *
 * Uses $wpdb directly for now; repositories introduced in Phases 1–2 will replace the raw queries.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Members;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;

final class ContextProvider {

	/** @var array<int, MemberContext> */
	private array $cache = [];

	public function __construct( private Settings $settings ) {}

	public function current(): MemberContext {
		return $this->for_user( get_current_user_id() );
	}

	public function for_user( int $user_id ): MemberContext {
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}
		$window = (int) $this->settings->get( 'membership_expiring_window_days', 30 );
		if ( $user_id <= 0 ) {
			return $this->cache[ $user_id ] = new MemberContext( 0, null, null, null, null, $window );
		}

		global $wpdb;
		$members     = Schema::table( 'sp_members' );
		$farm_users  = Schema::table( 'sp_farm_users' );
		$farms       = Schema::table( 'sp_farms' );
		$memberships = Schema::table( 'sp_memberships' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$member     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$members} WHERE user_id = %d", $user_id ) );
		$farm_user  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$farm_users} WHERE user_id = %d AND accepted_at IS NOT NULL", $user_id ) );
		$farm       = null;
		$membership = null;
		if ( $farm_user ) {
			$farm = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$farms} WHERE id = %d", (int) $farm_user->farm_id ) );
			if ( $farm ) {
				$membership = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE farm_id = %d", (int) $farm->id ) );
			} else {
				$farm_user = null;
			}
		}
		// phpcs:enable

		return $this->cache[ $user_id ] = new MemberContext( $user_id, $member ?: null, $farm_user ?: null, $farm ?: null, $membership ?: null, $window );
	}

	/**
	 * Drop cached contexts (after a write that changes membership or farm state).
	 */
	public function forget( int $user_id = 0 ): void {
		if ( $user_id > 0 ) {
			unset( $this->cache[ $user_id ] );
		} else {
			$this->cache = [];
		}
	}
}
