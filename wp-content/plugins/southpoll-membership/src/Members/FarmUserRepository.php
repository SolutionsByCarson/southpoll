<?php
/**
 * sp_farm_users: owners, account managers, and pending invitations (roadmap §3.1).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Members;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class FarmUserRepository {

	public const ROLE_OWNER   = 'owner';
	public const ROLE_MANAGER = 'manager';

	public const INVITE_TTL_DAYS = 14;

	public function __construct( private ContextProvider $contexts ) {}

	private function table(): string {
		return Schema::table( 'sp_farm_users' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Accepted attachment for a user (owner or manager). */
	public function for_user( int $user_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d AND accepted_at IS NOT NULL", $user_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * All rows for a farm (owner first, then managers, then pending invites), with user display data.
	 *
	 * @return object[]
	 */
	public function for_farm( int $farm_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d ORDER BY FIELD(role,'owner','manager'), accepted_at IS NULL, id", $farm_id ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$this->decorate( $row );
		}
		return $rows;
	}

	public function owner_row( int $farm_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d AND role = 'owner' LIMIT 1", $farm_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decorate( $row ) : null;
	}

	/** User ids attached to a farm (accepted) that want a given notification. */
	public function notify_user_ids( int $farm_id, string $flag ): array {
		global $wpdb;
		$col = 'notify_membership' === $flag ? 'notify_membership' : 'notify_listings';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$this->table()} WHERE farm_id = %d AND user_id IS NOT NULL AND accepted_at IS NOT NULL AND {$col} = 1", $farm_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Attach the owner (called when a farm is created). */
	public function add_owner( int $farm_id, int $user_id ): object {
		global $wpdb;
		$now = Dates::now();
		$wpdb->insert( $this->table(), [
			'farm_id'           => $farm_id,
			'user_id'           => $user_id,
			'role'              => self::ROLE_OWNER,
			'invite_email'      => '',
			'accepted_at'       => $now,
			'notify_listings'   => 1,
			'notify_membership' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		] );
		$this->contexts->forget( $user_id );
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Invite an email address as a manager. Returns the row (with raw token in ->raw_token) or WP_Error.
	 * Rules (D-19): an address already attached to any farm can only be moved by a site admin.
	 */
	public function invite( int $farm_id, string $email, int $invited_by ): \stdClass|WP_Error {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'sp_invalid_email', __( 'Please enter a valid email address.', 'southpoll-membership' ) );
		}
		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user ) {
			$attached = $this->for_user( (int) $existing_user->ID );
			if ( $attached ) {
				return new WP_Error( 'sp_already_attached', (int) $attached->farm_id === $farm_id
					? __( 'That person is already part of this farm.', 'southpoll-membership' )
					: __( 'That account already belongs to another farm. Ask the SPGCA office to reassign it.', 'southpoll-membership' ) );
			}
		}
		$pending = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE invite_email = %s AND accepted_at IS NULL", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $pending && (int) $pending->farm_id !== $farm_id ) {
			return new WP_Error( 'sp_pending_elsewhere', __( 'That address already has a pending invitation from another farm.', 'southpoll-membership' ) );
		}
		$raw = Str::token( 20 );
		$now = Dates::now();
		if ( $pending ) {
			$wpdb->update( $this->table(), [ 'invite_token' => Str::hash( $raw ), 'invited_at' => $now, 'updated_at' => $now ], [ 'id' => $pending->id ] );
			$row = $this->find( (int) $pending->id );
		} else {
			$wpdb->insert( $this->table(), [
				'farm_id'           => $farm_id,
				'user_id'           => null,
				'role'              => self::ROLE_MANAGER,
				'invite_email'      => $email,
				'invite_token'      => Str::hash( $raw ),
				'invited_at'        => $now,
				'notify_listings'   => 1,
				'notify_membership' => 0,
				'created_at'        => $now,
				'updated_at'        => $now,
			] );
			$row = $this->find( (int) $wpdb->insert_id );
		}
		$row->raw_token  = $raw;
		$row->invited_by = $invited_by;
		do_action( 'sp/farm_user/invited', $row );
		return $row;
	}

	/**
	 * Site-admin path (D-19 rule 2): attach an existing account to a farm as an accepted manager, moving it from
	 * another farm if needed. Owners are handled by transfer_ownership().
	 */
	public function attach_manager( int $farm_id, int $user_id ): \stdClass|WP_Error {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ) );
		}
		$existing = $this->for_user( $user_id );
		if ( $existing && self::ROLE_OWNER === $existing->role ) {
			return new WP_Error( 'sp_is_owner', __( 'That account owns a farm; transfer ownership first.', 'southpoll-membership' ) );
		}
		$now = Dates::now();
		if ( $existing ) {
			$wpdb->update( $this->table(), [ 'farm_id' => $farm_id, 'updated_at' => $now ], [ 'id' => $existing->id ] );
			$row = $this->find( (int) $existing->id );
		} else {
			$wpdb->delete( $this->table(), [ 'invite_email' => $user->user_email, 'accepted_at' => null ] );
			$wpdb->insert( $this->table(), [ 'farm_id' => $farm_id, 'user_id' => $user_id, 'role' => self::ROLE_MANAGER, 'invite_email' => '', 'accepted_at' => $now, 'notify_listings' => 1, 'notify_membership' => 0, 'created_at' => $now, 'updated_at' => $now ] );
			$row = $this->find( (int) $wpdb->insert_id );
		}
		$this->contexts->forget();
		do_action( 'sp/farm_user/attached', $row );
		return $row;
	}

	public function find_by_token( string $raw ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE invite_token = %s AND accepted_at IS NULL", Str::hash( $raw ) ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row && ! empty( $row->invited_at ) && Dates::days_until( (string) $row->invited_at ) < -self::INVITE_TTL_DAYS ) {
			return null; // expired
		}
		return $row;
	}

	/**
	 * Accept an invitation for a logged-in user. The user must not already belong to a farm.
	 */
	public function accept( object $invite, int $user_id ): true|WP_Error {
		global $wpdb;
		if ( $this->for_user( $user_id ) ) {
			return new WP_Error( 'sp_already_attached', __( 'Your account already belongs to a farm, so this invitation cannot be accepted.', 'southpoll-membership' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || strtolower( $user->user_email ) !== strtolower( (string) $invite->invite_email ) ) {
			return new WP_Error( 'sp_email_mismatch', __( 'This invitation was sent to a different email address. Log in with that address to accept it.', 'southpoll-membership' ) );
		}
		$now = Dates::now();
		$wpdb->update( $this->table(), [ 'user_id' => $user_id, 'invite_token' => null, 'accepted_at' => $now, 'updated_at' => $now ], [ 'id' => $invite->id ] );
		$this->contexts->forget( $user_id );
		do_action( 'sp/farm_user/accepted', $this->find( (int) $invite->id ) );
		return true;
	}

	/** Remove a manager (or cancel a pending invite). Owners cannot be removed here. */
	public function remove( int $id ): true|WP_Error {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ) );
		}
		if ( self::ROLE_OWNER === $row->role ) {
			return new WP_Error( 'sp_cannot_remove_owner', __( 'The farm owner cannot be removed. Contact the SPGCA office to transfer ownership.', 'southpoll-membership' ) );
		}
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
		if ( ! empty( $row->user_id ) ) {
			$this->contexts->forget( (int) $row->user_id );
		}
		do_action( 'sp/farm_user/removed', $row );
		return true;
	}

	public function set_notify( int $id, bool $listings, bool $membership ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'notify_listings' => (int) $listings, 'notify_membership' => (int) $membership, 'updated_at' => Dates::now() ], [ 'id' => $id ] );
	}

	/**
	 * Site-admin only: transfer ownership to another accepted user of the same farm or any unattached account.
	 */
	public function transfer_ownership( int $farm_id, int $new_owner_user_id, string $old_owner_becomes = self::ROLE_MANAGER ): true|WP_Error {
		global $wpdb;
		$owner = $this->owner_row( $farm_id );
		if ( ! $owner ) {
			return new WP_Error( 'sp_no_owner', 'Farm has no owner row.' );
		}
		if ( (int) $owner->user_id === $new_owner_user_id ) {
			return true;
		}
		$target = $this->for_user( $new_owner_user_id );
		if ( $target && (int) $target->farm_id !== $farm_id ) {
			return new WP_Error( 'sp_already_attached', 'The new owner belongs to another farm.' );
		}
		$now = Dates::now();
		if ( $target ) {
			$wpdb->update( $this->table(), [ 'role' => self::ROLE_OWNER, 'notify_membership' => 1, 'updated_at' => $now ], [ 'id' => $target->id ] );
		} else {
			$wpdb->insert( $this->table(), [ 'farm_id' => $farm_id, 'user_id' => $new_owner_user_id, 'role' => self::ROLE_OWNER, 'accepted_at' => $now, 'notify_listings' => 1, 'notify_membership' => 1, 'created_at' => $now, 'updated_at' => $now ] );
		}
		if ( 'buyer' === $old_owner_becomes ) {
			$wpdb->delete( $this->table(), [ 'id' => $owner->id ] );
		} else {
			$wpdb->update( $this->table(), [ 'role' => self::ROLE_MANAGER, 'updated_at' => $now ], [ 'id' => $owner->id ] );
		}
		$wpdb->update( Schema::table( 'sp_farms' ), [ 'owner_user_id' => $new_owner_user_id, 'updated_at' => $now ], [ 'id' => $farm_id ] );
		$this->contexts->forget();
		do_action( 'sp/farm/ownership_transferred', $farm_id, (int) $owner->user_id, $new_owner_user_id );
		return true;
	}

	private function decorate( object $row ): object {
		$row->user         = ! empty( $row->user_id ) ? get_userdata( (int) $row->user_id ) : null;
		$row->display_name = $row->user ? ( trim( $row->user->first_name . ' ' . $row->user->last_name ) ?: $row->user->display_name ) : '';
		$row->email        = $row->user ? $row->user->user_email : (string) $row->invite_email;
		$row->is_pending   = empty( $row->accepted_at );
		return $row;
	}
}
