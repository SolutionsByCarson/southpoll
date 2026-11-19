<?php
/**
 * sp_saved_searches: a user's saved classifieds filters, one optional default, per-search notify flag, share token.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

final class SavedSearchRepository {

	public function __construct( private MemberRepository $members ) {}

	private function table(): string {
		return Schema::table( 'sp_saved_searches' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode( $row ) : null;
	}

	public function find_for_user( int $user_id, int $id ): ?object {
		$row = $this->find( $id );
		return $row && (int) $row->user_id === $user_id ? $row : null;
	}

	public function find_by_token( string $token ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE share_token = %s", $token ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode( $row ) : null;
	}

	/** @return object[] default first, then newest */
	public function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY is_default DESC, created_at DESC, id DESC", $user_id ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$this->decode( $r );
		}
		return $rows;
	}

	public function count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function default_for_user( int $user_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d AND is_default = 1 LIMIT 1", $user_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode( $row ) : null;
	}

	/** Same filters already saved by this user? */
	public function find_identical( int $user_id, array $params ): ?object {
		$needle = self::normalize_params( $params );
		foreach ( $this->for_user( $user_id ) as $row ) {
			if ( self::normalize_params( $row->params ) === $needle ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $params ListingQuery saved params
	 */
	public function create( int $user_id, string $name, array $params, ?bool $notify = null ): object {
		global $wpdb;
		if ( null === $notify ) {
			$member = $this->members->find_by_user( $user_id );
			$notify = $member ? (bool) $member->default_notify_searches : false;
		}
		$now = Dates::now();
		$wpdb->insert( $this->table(), [
			'user_id'        => $user_id,
			'name'           => mb_substr( sanitize_text_field( $name ), 0, 190 ),
			'params'         => wp_json_encode( self::normalize_params( $params ) ),
			'is_default'     => 0,
			'notify'         => (int) $notify,
			'last_viewed_at' => $now,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		$row = $this->find( (int) $wpdb->insert_id );
		do_action( 'sp/saved_search/created', $row );
		return $row;
	}

	/** @param array<string, mixed> $data name, params, notify */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$row = [];
		if ( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ) {
			$row['name'] = mb_substr( sanitize_text_field( (string) $data['name'] ), 0, 190 );
		}
		if ( isset( $data['params'] ) && is_array( $data['params'] ) ) {
			$row['params'] = wp_json_encode( self::normalize_params( $data['params'] ) );
		}
		if ( array_key_exists( 'notify', $data ) ) {
			$row['notify'] = (int) (bool) $data['notify'];
		}
		if ( empty( $row ) ) {
			return false;
		}
		$row['updated_at'] = Dates::now();
		return false !== $wpdb->update( $this->table(), $row, [ 'id' => $id ] );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
		do_action( 'sp/saved_search/deleted', $row );
		return true;
	}

	/** Make one search the default (0 clears). */
	public function set_default( int $user_id, int $id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'is_default' => 0 ], [ 'user_id' => $user_id ] );
		if ( $id > 0 ) {
			$wpdb->update( $this->table(), [ 'is_default' => 1, 'updated_at' => Dates::now() ], [ 'id' => $id, 'user_id' => $user_id ] );
		}
	}

	public function set_notify_all( int $user_id, bool $notify ): int {
		global $wpdb;
		return (int) $wpdb->update( $this->table(), [ 'notify' => (int) $notify ], [ 'user_id' => $user_id ] );
	}

	public function ensure_share_token( int $id ): string {
		$row = $this->find( $id );
		if ( $row && ! empty( $row->share_token ) ) {
			return (string) $row->share_token;
		}
		return $this->regenerate_share_token( $id );
	}

	public function regenerate_share_token( int $id ): string {
		global $wpdb;
		$token = Str::token( 10 );
		$wpdb->update( $this->table(), [ 'share_token' => $token, 'updated_at' => Dates::now() ], [ 'id' => $id ] );
		return $token;
	}

	public function touch_viewed( int $id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'last_viewed_at' => Dates::now() ], [ 'id' => $id ] );
	}

	public function mark_notified( int $id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'last_notified_at' => Dates::now() ], [ 'id' => $id ] );
	}

	/** Every search with alerts on (alert pipeline). @return object[] */
	public function notify_enabled(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE notify = 1 ORDER BY user_id, id" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$this->decode( $r );
		}
		return $rows;
	}

	/** Keep only the saveable keys, sorted, with list values sorted (so identical searches compare equal). */
	public static function normalize_params( mixed $params ): array {
		if ( is_string( $params ) ) {
			$params = json_decode( $params, true );
		}
		$out = [];
		foreach ( ListingQuery::SAVED_KEYS as $k ) {
			if ( ! isset( $params[ $k ] ) || '' === $params[ $k ] || [] === $params[ $k ] ) {
				continue;
			}
			$v = $params[ $k ];
			if ( in_array( $k, [ 'cats', 'farms' ], true ) ) {
				$v = is_string( $v ) ? explode( ',', $v ) : (array) $v;
				$v = array_values( array_unique( array_filter( array_map( 'farms' === $k ? 'intval' : 'sanitize_title', $v ) ) ) );
				sort( $v );
				if ( empty( $v ) ) {
					continue;
				}
			} elseif ( 'state' === $k ) {
				$v = strtoupper( substr( sanitize_key( (string) $v ), 0, 2 ) );
			} elseif ( 'since' === $k ) {
				$v = isset( ListingQuery::SINCE[ (string) $v ] ) ? (string) $v : '';
			} elseif ( 'sort' === $k ) {
				$v = in_array( (string) $v, ListingQuery::SORTS, true ) ? (string) $v : '';
			} else {
				$v = trim( sanitize_text_field( (string) $v ) );
			}
			if ( '' === $v ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	private function decode( object $row ): object {
		$row->params = self::normalize_params( $row->params );
		return $row;
	}
}
