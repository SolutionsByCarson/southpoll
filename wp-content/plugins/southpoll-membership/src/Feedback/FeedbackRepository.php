<?php
/**
 * sp_feedback rows: soft-launch feedback / problem reports from the floating button (S8).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Feedback;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;

final class FeedbackRepository {

	public const STATUSES = [ 'new', 'seen', 'closed' ];
	private const MAX     = 500;

	private function table(): string {
		return Schema::table( 'sp_feedback' );
	}

	public function insert( array $data ): object {
		global $wpdb;
		$now = Dates::now();
		$wpdb->insert( $this->table(), [
			'user_id'       => $data['user_id'] ?: null,
			'name'          => substr( (string) ( $data['name'] ?? '' ), 0, 190 ),
			'email'         => substr( (string) ( $data['email'] ?? '' ), 0, 190 ),
			'page_url'      => substr( (string) ( $data['page_url'] ?? '' ), 0, 2000 ),
			'user_agent'    => substr( (string) ( $data['user_agent'] ?? '' ), 0, 500 ),
			'message'       => (string) ( $data['message'] ?? '' ),
			'screenshot_id' => ! empty( $data['screenshot_id'] ) ? (int) $data['screenshot_id'] : null,
			'status'        => 'new',
			'ip_hash'       => (string) ( $data['ip_hash'] ?? '' ),
			'notes'         => null,
			'created_at'    => $now,
			'updated_at'    => $now,
		] );
		return $this->find( (int) $wpdb->insert_id );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public function set_status( int $id, string $status, ?string $notes = null ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$data = [ 'status' => $status, 'updated_at' => Dates::now() ];
		if ( null !== $notes ) {
			$data['notes'] = $notes;
		}
		return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table(), [ 'id' => $id ] );
	}

	/** @return array<string, int> status => count (+ 'all') */
	public function counts(): array {
		global $wpdb;
		$out = [ 'all' => 0 ];
		foreach ( self::STATUSES as $s ) {
			$out[ $s ] = 0;
		}
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$this->table()} GROUP BY status" ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (string) $r->status ] = (int) $r->n;
			$out['all']                += (int) $r->n;
		}
		return $out;
	}

	/**
	 * Admin table query.
	 *
	 * @param array<string, mixed> $args q, status, sort (created|status|name), dir, page, per_page
	 * @return array{rows:object[], total:int, page:int, per_page:int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$raw      = $args['per_page'] ?? 25;
		$per_page = 'all' === $raw ? self::MAX : min( self::MAX, max( 1, (int) $raw ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sort     = sanitize_key( (string) ( $args['sort'] ?? 'created' ) );
		$dir      = 'ASC' === strtoupper( (string) ( $args['dir'] ?? '' ) ) ? 'ASC' : 'DESC';
		$where    = [ '1=1' ];
		$params   = [];
		$q        = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR message LIKE %s OR page_url LIKE %s OR id = %d)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, (int) $q ] );
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$order = match ( $sort ) {
			'status' => "status {$dir}, created_at DESC",
			'name'   => "name {$dir}, created_at DESC",
			default  => "created_at {$dir}",
		};
		$sql   = "FROM {$this->table()} WHERE " . implode( ' AND ', $where );
		$rows  = $wpdb->get_results( $params ? $wpdb->prepare( "SELECT * {$sql} ORDER BY {$order}, id {$dir} LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) ) : $wpdb->prepare( "SELECT * {$sql} ORDER BY {$order}, id {$dir} LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) {$sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
	}

	/** Every row, oldest first (CSV export). @return \Generator<object> */
	public function all( string $status = '' ): \Generator {
		global $wpdb;
		$sql = in_array( $status, self::STATUSES, true ) ? $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY id ASC", $status ) : "SELECT * FROM {$this->table()} ORDER BY id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $sql ) ?: [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			yield $row;
		}
	}
}
