<?php
/**
 * Everything the plugin needs to know about one user in one object: profile row, farm attachment,
 * farm, membership, and the permissions derived from them (roadmap §3 and §3.1).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Members;

use SouthPoll\Membership\Core\Capabilities;

final class MemberContext {

	public const ROLE_GUEST   = 'guest';
	public const ROLE_BUYER   = 'buyer';
	public const ROLE_OWNER   = 'owner';
	public const ROLE_MANAGER = 'manager';

	public function __construct(
		public readonly int $user_id,
		public readonly ?object $member,
		public readonly ?object $farm_user,
		public readonly ?object $farm,
		public readonly ?object $membership,
		private readonly int $expiring_window_days = 30,
		private readonly string $now = ''
	) {}

	public function is_logged_in(): bool {
		return $this->user_id > 0;
	}

	public function is_staff(): bool {
		return $this->is_logged_in() && Capabilities::is_staff( $this->user_id );
	}

	public function is_suspended(): bool {
		return null !== $this->member && 'suspended' === ( $this->member->status ?? '' );
	}

	public function is_verified(): bool {
		if ( $this->is_staff() ) {
			return true;
		}
		return null !== $this->member && ! empty( $this->member->email_verified_at );
	}

	/**
	 * guest | buyer | owner | manager (staff are reported as buyer+staff flags; use is_staff()).
	 */
	public function role(): string {
		if ( ! $this->is_logged_in() ) {
			return self::ROLE_GUEST;
		}
		if ( null !== $this->farm_user && null !== $this->farm ) {
			return 'owner' === $this->farm_user->role ? self::ROLE_OWNER : self::ROLE_MANAGER;
		}
		return self::ROLE_BUYER;
	}

	public function is_owner(): bool {
		return self::ROLE_OWNER === $this->role();
	}

	public function is_manager(): bool {
		return self::ROLE_MANAGER === $this->role();
	}

	public function has_farm(): bool {
		return null !== $this->farm;
	}

	public function farm_id(): int {
		return (int) ( $this->farm->id ?? 0 );
	}

	/**
	 * Membership status with the derived "expired" check applied (ends_at in the past).
	 * One of: none | pending | active | expired | cancelled.
	 */
	public function membership_status(): string {
		if ( null === $this->membership ) {
			return 'none';
		}
		$status = (string) ( $this->membership->status ?? 'none' );
		if ( 'active' === $status && ! empty( $this->membership->ends_at ) && $this->membership->ends_at <= $this->now() ) {
			return 'expired';
		}
		return $status;
	}

	public function farm_active(): bool {
		return 'active' === $this->membership_status();
	}

	public function days_remaining(): ?int {
		if ( null === $this->membership || empty( $this->membership->ends_at ) ) {
			return null;
		}
		$diff = strtotime( $this->membership->ends_at . ' UTC' ) - strtotime( $this->now() . ' UTC' );
		return (int) max( 0, (int) ceil( $diff / DAY_IN_SECONDS ) );
	}

	public function is_expiring(): bool {
		$days = $this->days_remaining();
		return $this->farm_active() && null !== $days && $days <= $this->expiring_window_days;
	}

	/**
	 * Permission matrix (roadmap §3.1). Staff can do everything.
	 */
	public function can( string $action ): bool {
		if ( $this->is_staff() ) {
			return true;
		}
		if ( ! $this->is_logged_in() || $this->is_suspended() ) {
			return false;
		}
		$owner   = $this->is_owner();
		$manager = $this->is_manager();
		$active  = $this->farm_active();

		return match ( $action ) {
			'view_dashboard'      => true,
			'view_contact_info'   => $this->is_verified(),
			'use_favorites'       => $this->is_verified(),
			'use_saved_searches'  => $this->is_verified(),
			'view_farm_pages'     => $owner || ( $manager && $active ),   // My Farm / My Listings screens
			'edit_farm'           => $owner || ( $manager && $active ),
			'manage_listings'     => $owner || ( $manager && $active ),   // drafts, edits, toggles
			'publish_listing'     => ( $owner || $manager ) && $active,
			'manage_farm_users'   => $owner && $active,
			'manage_membership'   => $owner,
			'view_membership'     => $owner || $manager,
			default               => (bool) apply_filters( 'sp/member_can', false, $action, $this ),
		};
	}

	private function now(): string {
		return '' !== $this->now ? $this->now : current_time( 'mysql', true );
	}
}
