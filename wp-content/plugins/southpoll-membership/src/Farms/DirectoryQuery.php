<?php
/**
 * Member directory query: public farms (farm active + membership active) with live filters, sort and paging.
 * Phase 3 plugs listing categories in through `sp/directory/farm_ids_with_stock` and keeps
 * `active_listing_count` / `last_listing_at` current on sp_farms.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Support\Dates;

final class DirectoryQuery {

	public const SORTS = [ 'name_asc', 'name_desc', 'active' ];

	/** "Published in" windows (farm's newest listing) → days. */
	public const SINCE = [ '24h' => 1, '3d' => 3, 'week' => 7, 'month' => 30 ];

	public const MAX_PER_PAGE = 500;

	public function __construct( private Settings $settings, private Offerings $offerings ) {}

	/**
	 * Clean request args.
	 *
	 * @param array<string, mixed> $args
	 * @return array{q:string, state:string, region:string, has_listings:bool, since:string, offerings:string[], stock:string[], sort:string, page:int, per_page:int}
	 */
	public function normalize( array $args ): array {
		$per_page = $args['per_page'] ?? $this->settings->get( 'directory_per_page', 24 );
		$per_page = 'all' === $per_page ? self::MAX_PER_PAGE : min( self::MAX_PER_PAGE, max( 1, (int) $per_page ) );
		$sort     = sanitize_key( (string) ( $args['sort'] ?? '' ) );
		$list     = static function ( $v ): array {
			if ( is_string( $v ) ) {
				$v = explode( ',', $v );
			}
			return array_values( array_filter( array_map( static fn( $x ) => sanitize_title( (string) $x ), (array) $v ) ) );
		};
		return [
			'q'            => trim( sanitize_text_field( (string) ( $args['q'] ?? '' ) ) ),
			'state'        => strtoupper( substr( sanitize_key( (string) ( $args['state'] ?? '' ) ), 0, 2 ) ),
			'region'       => sanitize_text_field( (string) ( $args['region'] ?? '' ) ),
			'has_listings' => in_array( (string) ( $args['has_listings'] ?? '' ), [ '1', 'true', 'yes', 'on' ], true ),
			'since'        => isset( self::SINCE[ (string) ( $args['since'] ?? '' ) ] ) ? (string) $args['since'] : '',
			'offerings'    => $list( $args['offerings'] ?? [] ),
			'stock'        => $list( $args['stock'] ?? [] ),
			'sort'         => in_array( $sort, self::SORTS, true ) ? $sort : (string) $this->settings->get( 'directory_sort_default', 'name_asc' ),
			'page'         => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'per_page'     => $per_page,
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{items:object[], total:int, page:int, per_page:int, pages:int, args:array}
	 */
	public function run( array $args ): array {
		global $wpdb;
		$a      = $this->normalize( $args );
		$where  = $this->where( $a );
		$order  = match ( $a['sort'] ) {
			'name_desc' => 'f.name DESC',
			'active'    => 'f.active_listing_count DESC, f.last_listing_at DESC, f.name ASC',
			default     => 'f.name ASC',
		};
		$offset = ( $a['page'] - 1 ) * $a['per_page'];
		$sql    = "SELECT f.*, m.status AS membership_status, m.ends_at AS membership_ends_at FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$where['sql']} ORDER BY {$order} LIMIT %d OFFSET %d";
		$items  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where['params'], [ $a['per_page'], $offset ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$where['sql']}", $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $a['page'],
			'per_page' => $a['per_page'],
			'pages'    => max( 1, (int) ceil( $total / $a['per_page'] ) ),
			'args'     => $a,
		];
	}

	/**
	 * Every matching farm that has coordinates (for the map view; no paging).
	 *
	 * @param array<string, mixed> $args
	 * @return object[]
	 */
	public function pins( array $args ): array {
		global $wpdb;
		$a     = $this->normalize( $args );
		$where = $this->where( $a );
		$sql   = "SELECT f.id, f.name, f.slug, f.slogan, f.city, f.state, f.region, f.owner_name, f.lat, f.lng, f.logo_id, f.active_listing_count, f.last_listing_at FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$where['sql']} AND f.lat IS NOT NULL AND f.lng IS NOT NULL ORDER BY f.name ASC LIMIT 2000";
		return $wpdb->get_results( $wpdb->prepare( $sql, $where['params'] ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * States with at least one public farm → count.
	 *
	 * @return array<string, int>
	 */
	public function states(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.state, COUNT(*) c FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$this->base_sql()} AND f.state <> '' GROUP BY f.state ORDER BY f.state", $this->base_params() ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (string) $r->state ] = (int) $r->c;
		}
		return $out;
	}

	/**
	 * Regions (settings order) → public farm count.
	 *
	 * @return array<string, int>
	 */
	public function regions(): array {
		global $wpdb;
		$counts = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.region, COUNT(*) c FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$this->base_sql()} GROUP BY f.region", $this->base_params() ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ (string) $r->region ] = (int) $r->c;
		}
		$out = [];
		foreach ( array_keys( (array) $this->settings->get( 'region_map', [] ) ) as $region ) {
			$out[ (string) $region ] = (int) ( $counts[ $region ] ?? 0 );
		}
		return $out;
	}

	public function public_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->farms()} f JOIN {$this->memberships()} m ON m.farm_id = f.id WHERE {$this->base_sql()}", $this->base_params() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------- internals */

	private function farms(): string {
		return Schema::table( 'sp_farms' );
	}

	private function memberships(): string {
		return Schema::table( 'sp_memberships' );
	}

	/** Visibility rule shared by every directory query (roadmap §3.1 lapse table). */
	private function base_sql(): string {
		return "f.status = 'active' AND m.status = 'active' AND (m.ends_at IS NULL OR m.ends_at > %s)";
	}

	/** @return string[] */
	private function base_params(): array {
		return [ Dates::now() ];
	}

	/**
	 * @param array<string, mixed> $a normalized args
	 * @return array{sql:string, params:array}
	 */
	private function where( array $a ): array {
		global $wpdb;
		$sql    = [ $this->base_sql() ];
		$params = $this->base_params();

		if ( '' !== $a['q'] ) {
			$like   = '%' . $wpdb->esc_like( $a['q'] ) . '%';
			$sql[]  = '(f.name LIKE %s OR f.slogan LIKE %s OR f.city LIKE %s OR f.owner_name LIKE %s OR f.state = %s OR f.zip LIKE %s)';
			$params = array_merge( $params, [ $like, $like, $like, $like, strtoupper( $a['q'] ), $like ] );
		}
		if ( '' !== $a['state'] ) {
			$sql[]    = 'f.state = %s';
			$params[] = $a['state'];
		}
		if ( '' !== $a['region'] ) {
			$sql[]    = 'f.region = %s';
			$params[] = $a['region'];
		}
		if ( $a['has_listings'] ) {
			$sql[] = 'f.active_listing_count > 0';
		}
		if ( '' !== $a['since'] ) {
			$sql[]    = 'f.last_listing_at >= %s';
			$params[] = Dates::add_days( Dates::now(), -self::SINCE[ $a['since'] ] );
		}
		if ( ! empty( $a['offerings'] ) ) {
			$ids = $this->offerings->farm_ids_with( $a['offerings'] );
			$this->in_clause( $sql, $params, $ids );
		}
		if ( ! empty( $a['stock'] ) ) {
			/**
			 * Farm ids with active listings in the given listing-category slugs (Phase 3). Null = filter unavailable.
			 *
			 * @param int[]|null $ids
			 * @param string[]   $slugs
			 */
			$ids = apply_filters( 'sp/directory/farm_ids_with_stock', null, $a['stock'] );
			if ( is_array( $ids ) ) {
				$this->in_clause( $sql, $params, $ids );
			}
		}
		/**
		 * @param array{sql:string[], params:array} $clauses
		 * @param array $a
		 */
		$ext = apply_filters( 'sp/directory/where', [ 'sql' => $sql, 'params' => $params ], $a );
		return [ 'sql' => implode( ' AND ', (array) $ext['sql'] ), 'params' => (array) $ext['params'] ];
	}

	/** @param int[] $ids */
	private function in_clause( array &$sql, array &$params, array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			$sql[] = '0 = 1';
			return;
		}
		$sql[]  = 'f.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$params = array_merge( $params, $ids );
	}
}
