<?php
/**
 * sp_members data access (1:1 profile for every account).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Members;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

final class MemberRepository {

	public function __construct( private Settings $settings, private ContextProvider $contexts ) {}

	private function table(): string {
		return Schema::table( 'sp_members' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_user( int $user_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d", $user_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_share_token( string $token ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE favorites_share_token = %s", $token ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create the profile row for a WP user (idempotent: returns the existing row if present).
	 *
	 * @param array<string, mixed> $data first_name, last_name, phone, address1, city, state, zip, verified(bool)
	 */
	public function create_for_user( int $user_id, array $data = [] ): object {
		$existing = $this->find_by_user( $user_id );
		if ( $existing ) {
			return $existing;
		}
		global $wpdb;
		$now  = Dates::now();
		$user = get_userdata( $user_id );
		$row  = [
			'user_id'                  => $user_id,
			'first_name'               => sanitize_text_field( (string) ( $data['first_name'] ?? ( $user ? $user->first_name : '' ) ) ),
			'last_name'                => sanitize_text_field( (string) ( $data['last_name'] ?? ( $user ? $user->last_name : '' ) ) ),
			'phone'                    => Str::phone( (string) ( $data['phone'] ?? '' ) ),
			'address1'                 => sanitize_text_field( (string) ( $data['address1'] ?? '' ) ),
			'city'                     => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'state'                    => Str::state( (string) ( $data['state'] ?? '' ) ),
			'zip'                      => sanitize_text_field( (string) ( $data['zip'] ?? '' ) ),
			'status'                   => 'active',
			'email_verified_at'        => ! empty( $data['verified'] ) ? $now : null,
			'notify_all'               => 1,
			'default_notify_favorites' => (int) (bool) $this->settings->get( 'default_notify_favorites', false ),
			'default_notify_searches'  => (int) (bool) $this->settings->get( 'default_notify_searches', false ),
			'created_at'               => $now,
			'updated_at'               => $now,
		];
		$wpdb->insert( $this->table(), $row );
		$this->contexts->forget( $user_id );
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * @param array<string, mixed> $data Whitelisted columns only.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'first_name', 'last_name', 'phone', 'address1', 'city', 'state', 'zip', 'status', 'email_verified_at', 'verification_token', 'verification_sent_at', 'pending_email', 'notify_all', 'default_notify_favorites', 'default_notify_searches', 'favorites_share_token', 'last_seen_at' ];
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		$ok                 = false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
		$row                = $this->find( $id );
		if ( $row ) {
			$this->contexts->forget( (int) $row->user_id );
		}
		return $ok;
	}

	public function touch_last_seen( int $user_id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'last_seen_at' => Dates::now() ], [ 'user_id' => $user_id ] );
	}

	/* ----------------------------------------------------- verification */

	/** Create a fresh verification token; returns the raw token for the email link. */
	public function issue_verification_token( int $member_id ): string {
		$raw = Str::token( 24 );
		$this->update( $member_id, [ 'verification_token' => Str::hash( $raw ), 'verification_sent_at' => Dates::now() ] );
		return $raw;
	}

	/** Verify by raw token; returns the member row on success. */
	public function verify( int $user_id, string $raw ): ?object {
		$member = $this->find_by_user( $user_id );
		if ( ! $member || empty( $member->verification_token ) || ! hash_equals( (string) $member->verification_token, Str::hash( $raw ) ) ) {
			return null;
		}
		$data = [ 'email_verified_at' => Dates::now(), 'verification_token' => null, 'verification_sent_at' => null ];
		if ( '' !== (string) $member->pending_email ) {
			wp_update_user( [ 'ID' => $user_id, 'user_email' => $member->pending_email ] );
			$data['pending_email'] = '';
		}
		$this->update( (int) $member->id, $data );
		return $this->find( (int) $member->id );
	}

	public function can_resend_verification( object $member, int $cooldown_seconds = 120 ): bool {
		if ( empty( $member->verification_sent_at ) ) {
			return true;
		}
		return ( time() - Dates::utc( (string) $member->verification_sent_at )->getTimestamp() ) >= $cooldown_seconds;
	}

	/* ----------------------------------------------------- share token */

	public function ensure_share_token( int $member_id ): string {
		$member = $this->find( $member_id );
		if ( $member && ! empty( $member->favorites_share_token ) ) {
			return (string) $member->favorites_share_token;
		}
		return $this->regenerate_share_token( $member_id );
	}

	public function regenerate_share_token( int $member_id ): string {
		$token = Str::token( 12 );
		$this->update( $member_id, [ 'favorites_share_token' => $token ] );
		return $token;
	}

	/* ----------------------------------------------------- display */

	public function display_name( ?object $member, ?\WP_User $user = null ): string {
		$name = trim( (string) ( $member->first_name ?? '' ) . ' ' . (string) ( $member->last_name ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		return $user ? $user->display_name : '';
	}
}
