<?php
/**
 * Public classifieds query: visible listings of public farms with live filters (text, categories, state/region, farms,
 * publish window), sort, paging, map pins (grouped by farm) and filter options. Also used to test whether a listing
 * matches a saved search (restrict with `ids`).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Support\Dates;

final class ListingQuery {

	public const SORTS = [ 'newest', 'oldest', 'price_asc', 'price_desc', 'title' ];
	public const SINCE = [ '24h' => 1, '3d' => 3, 'week' => 7, 'month' => 30 ];
	public const MAX_PER_PAGE = 200;

	/** Keys a saved search may store. */
	public const SAVED_KEYS = [ 'q', 'cats', 'state', 'region', 'farms', 'since', 'sort' ];

	public function __construct( private Settings $settings, private ListingRepository $listings, private ListingTypes $types ) {}

	/**
	 * @param array<string, mixed> $args
	 * @return array{q:string, cats:string[], state:string, region:string, farms:int[], since:string, sort:string, page:int, per_page:int, ids:int[]}
	 */
	public function normalize( array $args ): array {
		$per_page = $args['per_page'] ?? $this->settings->get( 'classifieds_per_page', 20 );
		$per_page = 'all' === $per_page ? self::MAX_PER_PAGE : min( self::MAX_PER_PAGE, max( 1, (int) $per_page ) );
		$sort     = sanitize_key( (string) ( $args['sort'] ?? '' ) );
		$slugs    = static function ( $v ): array {
			if ( is_string( $v ) ) {
				$v = explode( ',', $v );
			}
			return array_values( array_unique( array_filter( array_map( static fn( $x ) => sanitize_title( (string) $x ), (array) $v ) ) ) );
		};
		$ints     = static function ( $v ): array {
			if ( is_string( $v ) ) {
				$v = explode( ',', $v );
			}
			return array_values( array_unique( array_filter( array_map( 'intval', (array) $v ) ) ) );
		};
		return [
			'q'        => trim( sanitize_text_field( (string) ( $args['q'] ?? '' ) ) ),
			'cats'     => $slugs( $args['cats'] ?? $args['categories'] ?? [] ),
			'state'    => strtoupper( substr( sanitize_key( (string) ( $args['state'] ?? '' ) ), 0, 2 ) ),
			'region'   => sanitize_text_field( (string) ( $args['region'] ?? '' ) ),
			'farms'    => $ints( $args['farms'] ?? $args['farm'] ?? [] ),
			'since'    => isset( self::SINCE[ (string) ( $args['since'] ?? '' ) ] ) ? (string) $args['since'] : '',
			'sort'     => in_array( $sort, self::SORTS, true ) ? $sort : (string) $this->settings->get( 'classifieds_sort_default', 'newest' ),
			'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'per_page' => $per_page,
			'ids'      => $ints( $args['ids'] ?? [] ),
		];
	}

	/** Saved-search params (only the filter keys, defaults dropped). @return array<string, mixed> */
	public static function saved_params( array $normalized ): array {
		$out = [];
		foreach ( self::SAVED_KEYS as $k ) {
			$v = $normalized[ $k ] ?? '';
			if ( '' === $v || [] === $v || ( 'sort' === $k && 'newest' === $v ) ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * @return array{items:Listing[], total:int, page:int, per_page:int, pages:int, args:array}
	 */
	public function run( array $args ): array {
		global $wpdb;
		$a     = $this->normalize( $args );
		$where = $this->where( $a );
		$order = match ( $a['sort'] ) {
			'oldest'     => 'COALESCE(mpub.meta_value, p.post_date_gmt) ASC, p.ID ASC',
			'price_asc'  => "CASE WHEN mp.meta_value IS NULL OR mp.meta_value = '' THEN 1 ELSE 0 END, CAST(mp.meta_value AS UNSIGNED) ASC, p.ID DESC",
			'price_desc' => 'CAST(mp.meta_value AS UNSIGNED) DESC, p.ID DESC',
			'title'      => 'p.post_title ASC, p.ID DESC',
			default      => 'COALESCE(mpub.meta_value, p.post_date_gmt) DESC, p.ID DESC',
		};
		$offset = ( $a['page'] - 1 ) * $a['per_page'];
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID {$this->from()} WHERE {$where['sql']} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $where['params'], [ $a['per_page'], $offset ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$this->from()} WHERE {$where['sql']}", $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [
			'items'    => $this->listings->find_many( array_map( 'intval', $ids ) ),
			'total'    => $total,
			'page'     => $a['page'],
			'per_page' => $a['per_page'],
			'pages'    => max( 1, (int) ceil( $total / $a['per_page'] ) ),
			'args'     => $a,
		];
	}

	public function count( array $args ): int {
		global $wpdb;
		$where = $this->where( $this->normalize( $args ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$this->from()} WHERE {$where['sql']}", $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Does one visible listing satisfy these filters (saved-search matching)? */
	public function matches( int $listing_id, array $params ): bool {
		$params          = array_intersect_key( $params, array_flip( self::SAVED_KEYS ) );
		$params['ids']   = [ $listing_id ];
		$params['since'] = '';
		return $this->count( $params ) > 0;
	}

	/** Listings published after $since matching the filters (saved-search "new" counts). */
	public function count_since( array $params, string $since ): int {
		global $wpdb;
		$a     = $this->normalize( array_intersect_key( $params, array_flip( self::SAVED_KEYS ) ) );
		$where = $this->where( $a );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$this->from()} WHERE {$where['sql']} AND COALESCE(mpub.meta_value, p.post_date_gmt) > %s", array_merge( $where['params'], [ $since ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Map pins: one per farm with matching listings (lat/lng exactness applied by the presenter).
	 *
	 * @return object[] id, name, slug, city, state, lat, lng, logo_id, active_listing_count, last_listing_at, listings
	 */
	public function pins( array $args ): array {
		global $wpdb;
		$where = $this->where( $this->normalize( $args ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.name, f.slug, f.slogan, f.city, f.state, f.region, f.owner_name, f.lat, f.lng, f.logo_id, f.active_listing_count, f.last_listing_at, COUNT(*) AS listings {$this->from()} WHERE {$where['sql']} AND f.lat IS NOT NULL AND f.lng IS NOT NULL GROUP BY f.id ORDER BY f.name LIMIT 2000", $where['params'] ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Filter options: categories, states, regions, farms (with live listings), windows, sorts.
	 *
	 * @return array<string, mixed>
	 */
	public function filter_options(): array {
		global $wpdb;
		$base   = $this->where( $this->normalize( [] ) );
		$from   = $this->from();
		$states = [];
		$names  = \SouthPoll\Membership\Support\Str::us_states();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.state, COUNT(*) c {$from} WHERE {$base['sql']} AND f.state <> '' GROUP BY f.state ORDER BY f.state", $base['params'] ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$states[] = [ 'value' => (string) $r->state, 'label' => $names[ $r->state ] ?? (string) $r->state, 'count' => (int) $r->c ];
		}
		$region_counts = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.region, COUNT(*) c {$from} WHERE {$base['sql']} GROUP BY f.region", $base['params'] ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$region_counts[ (string) $r->region ] = (int) $r->c;
		}
		$regions = [];
		foreach ( array_keys( (array) $this->settings->get( 'region_map', [] ) ) as $region ) {
			$regions[] = [ 'value' => (string) $region, 'label' => (string) $region, 'count' => (int) ( $region_counts[ $region ] ?? 0 ) ];
		}
		$farms = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.name, f.state, COUNT(*) c {$from} WHERE {$base['sql']} GROUP BY f.id ORDER BY f.name", $base['params'] ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$farms[] = [ 'value' => (int) $r->id, 'label' => (string) $r->name . ( $r->state ? ' (' . $r->state . ')' : '' ), 'count' => (int) $r->c ];
		}
		$cat_counts = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.slug, COUNT(DISTINCT p.ID) c {$from} JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE {$base['sql']} GROUP BY t.slug", array_merge( [ ListingTypes::TAXONOMY ], $base['params'] ) ) ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cat_counts[ (string) $r->slug ] = (int) $r->c;
		}
		return [
			'categories' => array_map( static fn( $c ) => [ 'value' => $c->slug, 'label' => $c->name, 'count' => (int) ( $cat_counts[ $c->slug ] ?? 0 ) ], $this->types->categories() ),
			'states'     => $states,
			'regions'    => $regions,
			'farms'      => $farms,
			'since'      => [
				[ 'value' => '24h', 'label' => __( 'Last 24 hours', 'southpoll-membership' ) ],
				[ 'value' => '3d', 'label' => __( 'Last 3 days', 'southpoll-membership' ) ],
				[ 'value' => 'week', 'label' => __( 'Last week', 'southpoll-membership' ) ],
				[ 'value' => 'month', 'label' => __( 'Last month', 'southpoll-membership' ) ],
			],
			'sorts'      => [
				[ 'value' => 'newest', 'label' => __( 'Newest first', 'southpoll-membership' ) ],
				[ 'value' => 'oldest', 'label' => __( 'Oldest first', 'southpoll-membership' ) ],
				[ 'value' => 'price_asc', 'label' => __( 'Price: low to high', 'southpoll-membership' ) ],
				[ 'value' => 'price_desc', 'label' => __( 'Price: high to low', 'southpoll-membership' ) ],
				[ 'value' => 'title', 'label' => __( 'Title A–Z', 'southpoll-membership' ) ],
			],
			'total'      => $this->count( [] ),
		];
	}

	/**
	 * Option counts for the current filter set. Each dimension ignores its own selection, so picking a category
	 * narrows the state / region / farm counts (and vice-versa) the way the results will actually narrow.
	 *
	 * @param array<string, mixed> $args raw or normalized filter args
	 * @return array{categories:array, states:array, regions:array, farms:array, total:int}
	 */
	public function facets( array $args ): array {
		global $wpdb;
		$a       = $this->normalize( $args );
		$from    = $this->from();
		$names   = \SouthPoll\Membership\Support\Str::us_states();
		$without = function ( string $key ) use ( $a ): array {
			$b         = $a;
			$b[ $key ] = is_array( $a[ $key ] ?? null ) ? [] : '';
			return $this->where( $b );
		};
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$w      = $without( 'state' );
		$states = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.state, COUNT(*) c {$from} WHERE {$w['sql']} AND f.state <> '' GROUP BY f.state ORDER BY f.state", $w['params'] ) ) ?: [] as $r ) {
			$states[] = [ 'value' => (string) $r->state, 'label' => $names[ $r->state ] ?? (string) $r->state, 'count' => (int) $r->c ];
		}
		$w             = $without( 'region' );
		$region_counts = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.region, COUNT(*) c {$from} WHERE {$w['sql']} GROUP BY f.region", $w['params'] ) ) ?: [] as $r ) {
			$region_counts[ (string) $r->region ] = (int) $r->c;
		}
		$regions = [];
		foreach ( array_keys( (array) $this->settings->get( 'region_map', [] ) ) as $region ) {
			$regions[] = [ 'value' => (string) $region, 'label' => (string) $region, 'count' => (int) ( $region_counts[ $region ] ?? 0 ) ];
		}
		$w     = $without( 'farms' );
		$farms = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.name, f.state, COUNT(*) c {$from} WHERE {$w['sql']} GROUP BY f.id ORDER BY f.name", $w['params'] ) ) ?: [] as $r ) {
			$farms[] = [ 'value' => (int) $r->id, 'label' => (string) $r->name . ( $r->state ? ' (' . $r->state . ')' : '' ), 'count' => (int) $r->c ];
		}
		$w          = $without( 'cats' );
		$cat_counts = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.slug, COUNT(DISTINCT p.ID) c {$from} JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE {$w['sql']} GROUP BY t.slug", array_merge( [ ListingTypes::TAXONOMY ], $w['params'] ) ) ) ?: [] as $r ) {
			$cat_counts[ (string) $r->slug ] = (int) $r->c;
		}
		// phpcs:enable
		return [
			'categories' => array_map( static fn( $c ) => [ 'value' => $c->slug, 'label' => $c->name, 'count' => (int) ( $cat_counts[ $c->slug ] ?? 0 ) ], $this->types->categories() ),
			'states'     => $states,
			'regions'    => $regions,
			'farms'      => $farms,
			'total'      => $this->count( $args ),
		];
	}

	/* ---------------------------------------------------------------- internals */

	private function from(): string {
		global $wpdb;
		$farms       = Schema::table( 'sp_farms' );
		$memberships = Schema::table( 'sp_memberships' );
		return "FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id'
			JOIN {$farms} f ON f.id = mf.meta_value
			JOIN {$memberships} m ON m.farm_id = f.id
			LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_sp_status'
			LEFT JOIN {$wpdb->postmeta} msold ON msold.post_id = p.ID AND msold.meta_key = '_sp_sold_at'
			LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_sp_price'
			LEFT JOIN {$wpdb->postmeta} mpub ON mpub.post_id = p.ID AND mpub.meta_key = '_sp_published_at'
			LEFT JOIN {$wpdb->postmeta} mexp ON mexp.post_id = p.ID AND mexp.meta_key = '_sp_expires_at'";
	}

	/** @return array{sql:string, params:array} */
	private function where( array $a ): array {
		global $wpdb;
		$now       = Dates::now();
		$sold_days = (int) $this->settings->get( 'listing_sold_visible_days', 14 );
		$sql       = [
			'p.post_type = %s',
			"p.post_status = 'publish'",
			"f.status = 'active'",
			"m.status = 'active'",
			'(m.ends_at IS NULL OR m.ends_at > %s)',
			"(mexp.meta_value IS NULL OR mexp.meta_value = '' OR mexp.meta_value > %s)",
			"(ms.meta_value IS NULL OR ms.meta_value NOT IN ('sold','inactive') OR (ms.meta_value = 'sold' AND msold.meta_value >= %s))",
		];
		$params    = [ ListingTypes::POST_TYPE, $now, $now, Dates::add_days( $now, -$sold_days ) ];
		if ( '' !== $a['q'] ) {
			$like   = '%' . $wpdb->esc_like( $a['q'] ) . '%';
			$sql[]  = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR f.name LIKE %s OR f.owner_name LIKE %s)';
			$params = array_merge( $params, [ $like, $like, $like, $like, $like ] );
		}
		if ( ! empty( $a['cats'] ) ) {
			$ph     = implode( ',', array_fill( 0, count( $a['cats'] ), '%s' ) );
			$sql[]  = "p.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug IN ({$ph}))";
			$params = array_merge( $params, [ ListingTypes::TAXONOMY ], $a['cats'] );
		}
		if ( '' !== $a['state'] ) {
			$sql[]    = 'f.state = %s';
			$params[] = $a['state'];
		}
		if ( '' !== $a['region'] ) {
			$sql[]    = 'f.region = %s';
			$params[] = $a['region'];
		}
		if ( ! empty( $a['farms'] ) ) {
			$sql[]  = 'f.id IN (' . implode( ',', array_fill( 0, count( $a['farms'] ), '%d' ) ) . ')';
			$params = array_merge( $params, $a['farms'] );
		}
		if ( '' !== $a['since'] ) {
			$sql[]    = 'COALESCE(mpub.meta_value, p.post_date_gmt) >= %s';
			$params[] = Dates::add_days( $now, -self::SINCE[ $a['since'] ] );
		}
		if ( ! empty( $a['ids'] ) ) {
			$sql[]  = 'p.ID IN (' . implode( ',', array_fill( 0, count( $a['ids'] ), '%d' ) ) . ')';
			$params = array_merge( $params, $a['ids'] );
		}
		$ext = apply_filters( 'sp/classifieds/where', [ 'sql' => $sql, 'params' => $params ], $a );
		return [ 'sql' => implode( ' AND ', (array) $ext['sql'] ), 'params' => (array) $ext['params'] ];
	}
}
