<?php
/**
 * sp_favorites: farms and listings a user has starred, with a per-favorite notify flag.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Support\Dates;

final class FavoriteRepository {

	public const TYPES = [ 'farm', 'listing' ];

	public function __construct( private MemberRepository $members ) {}

	private function table(): string {
		return Schema::table( 'sp_favorites' );
	}

	public static function type( string $type ): string {
		return in_array( $type, self::TYPES, true ) ? $type : 'listing';
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_for( int $user_id, string $type, int $object_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d AND object_type = %s AND object_id = %d", $user_id, self::type( $type ), $object_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return object[] newest first */
	public function for_user( int $user_id, string $type ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d AND object_type = %s ORDER BY created_at DESC, id DESC", $user_id, self::type( $type ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int, int> object_id => favorite id */
	public function ids_for_user( int $user_id, string $type ): array {
		$out = [];
		foreach ( $this->for_user( $user_id, $type ) as $row ) {
			$out[ (int) $row->object_id ] = (int) $row->id;
		}
		return $out;
	}

	public function count( int $user_id, string $type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d AND object_type = %s", $user_id, self::type( $type ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Add (idempotent). Notify defaults to the member's "always subscribe" preference. */
	public function add( int $user_id, string $type, int $object_id, ?bool $notify = null ): object {
		global $wpdb;
		$existing = $this->find_for( $user_id, $type, $object_id );
		if ( $existing ) {
			return $existing;
		}
		if ( null === $notify ) {
			$member = $this->members->find_by_user( $user_id );
			$notify = $member ? (bool) $member->default_notify_favorites : false;
		}
		$wpdb->insert( $this->table(), [ 'user_id' => $user_id, 'object_type' => self::type( $type ), 'object_id' => $object_id, 'notify' => (int) $notify, 'created_at' => Dates::now() ] );
		$row = $this->find( (int) $wpdb->insert_id );
		do_action( 'sp/favorite/added', $row );
		return $row;
	}

	public function remove( int $id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
		do_action( 'sp/favorite/removed', $row );
		return true;
	}

	/** @return array{added:bool, row:object|null} */
	public function toggle( int $user_id, string $type, int $object_id ): array {
		$existing = $this->find_for( $user_id, $type, $object_id );
		if ( $existing ) {
			$this->remove( (int) $existing->id );
			return [ 'added' => false, 'row' => null ];
		}
		return [ 'added' => true, 'row' => $this->add( $user_id, $type, $object_id ) ];
	}

	public function set_notify( int $id, bool $notify ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'notify' => (int) $notify ], [ 'id' => $id ] );
	}

	/** Bulk toggle for one user and type; returns rows affected. */
	public function set_notify_all( int $user_id, string $type, bool $notify ): int {
		global $wpdb;
		return (int) $wpdb->update( $this->table(), [ 'notify' => (int) $notify ], [ 'user_id' => $user_id, 'object_type' => self::type( $type ) ] );
	}

	/** User ids that want alerts for an object (Phase 3 alert pipeline). @return int[] */
	public function users_watching( string $type, int $object_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$this->table()} WHERE object_type = %s AND object_id = %d AND notify = 1", self::type( $type ), $object_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function count_for_object( string $type, int $object_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE object_type = %s AND object_id = %d", self::type( $type ), $object_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
