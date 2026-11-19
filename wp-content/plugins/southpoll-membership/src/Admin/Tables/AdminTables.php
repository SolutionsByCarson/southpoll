<?php
/**
 * Server-side queries behind the admin tables (users, farms, listings, memberships, payments): search across every
 * displayed field, sortable columns, paging. Built to stay fast on 10k+ users (indexed joins, LIMIT/OFFSET, one
 * COUNT per page). Exports reuse the same queries with per_page = all.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Tables;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Support\Dates;

final class AdminTables {

	public const MAX = 5000;

	/* ------------------------------------------------------------------ users */

	/**
	 * @param array<string, mixed> $args q, role(all|buyer|owner|manager|staff|unverified|suspended), farm_id, sort, dir, page, per_page
	 * @return array{items:object[], total:int, page:int, per_page:int}
	 */
	public function users( array $args ): array {
		global $wpdb;
		$p       = $this->paging( $args, [ 'name', 'email', 'role', 'farm', 'membership', 'created', 'last_seen' ], 'created', 'DESC' );
		$members = Schema::table( 'sp_members' );
		$fu      = Schema::table( 'sp_farm_users' );
		$farms   = Schema::table( 'sp_farms' );
		$ms      = Schema::table( 'sp_memberships' );
		$capkey  = $wpdb->prefix . 'capabilities';
		$from    = "FROM {$wpdb->users} u
			LEFT JOIN {$members} m ON m.user_id = u.ID
			LEFT JOIN {$fu} fu ON fu.user_id = u.ID AND fu.accepted_at IS NOT NULL
			LEFT JOIN {$farms} f ON f.id = fu.farm_id
			LEFT JOIN {$ms} ms ON ms.farm_id = f.id
			LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s";
		$params  = [ $capkey ];
		$role_sql = "CASE WHEN fu.role = 'owner' THEN 'owner' WHEN fu.role = 'manager' THEN 'manager' WHEN (um.meta_value LIKE '%administrator%' OR um.meta_value LIKE '%editor%') THEN 'staff' ELSE 'buyer' END";
		$where   = [ '1 = 1' ];
		$q       = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s OR m.first_name LIKE %s OR m.last_name LIKE %s OR CONCAT(m.first_name, " ", m.last_name) LIKE %s OR m.phone LIKE %s OR f.name LIKE %s OR f.breeder_number LIKE %s OR m.city LIKE %s OR m.state = %s)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, $like, $like, $like, $like, $like, strtoupper( $q ) ] );
		}
		$role = sanitize_key( (string) ( $args['role'] ?? 'all' ) );
		if ( in_array( $role, [ 'buyer', 'owner', 'manager', 'staff' ], true ) ) {
			$where[]  = "({$role_sql}) = %s";
			$params[] = $role;
		} elseif ( 'unverified' === $role ) {
			$where[] = 'm.email_verified_at IS NULL AND m.id IS NOT NULL';
		} elseif ( 'suspended' === $role ) {
			$where[] = "m.status = 'suspended'";
		} elseif ( 'members' === $role ) {
			$where[] = 'm.id IS NOT NULL';
		}
		if ( ! empty( $args['farm_id'] ) ) {
			$where[]  = 'f.id = %d';
			$params[] = (int) $args['farm_id'];
		}
		$order = match ( $p['sort'] ) {
			'name'       => "COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), u.display_name) {$p['dir']}",
			'email'      => "u.user_email {$p['dir']}",
			'role'       => "({$role_sql}) {$p['dir']}",
			'farm'       => "f.name {$p['dir']}",
			'membership' => "ms.status {$p['dir']}, ms.ends_at {$p['dir']}",
			'last_seen'  => "m.last_seen_at {$p['dir']}",
			default      => "u.user_registered {$p['dir']}",
		};
		$sql   = "SELECT u.ID AS id, u.user_email AS email, u.display_name, u.user_registered AS created_at, u.user_login,
			m.id AS member_id, m.first_name, m.last_name, m.phone, m.address1, m.city, m.state, m.zip, m.status AS member_status, m.email_verified_at, m.last_seen_at, m.notify_all,
			fu.role AS farm_role, f.id AS farm_id, f.name AS farm_name, f.slug AS farm_slug, f.breeder_number,
			ms.status AS membership_status, ms.ends_at AS membership_ends_at, ({$role_sql}) AS role
			{$from} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}, u.ID DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $p['per_page'], $p['offset'] ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ), $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$r->name              = trim( (string) $r->first_name . ' ' . (string) $r->last_name ) ?: (string) $r->display_name;
			$r->verified          = ! empty( $r->email_verified_at );
			$r->suspended         = 'suspended' === (string) $r->member_status;
			$r->membership_status = $this->effective( $r->membership_status, $r->membership_ends_at );
		}
		return [ 'items' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'] ];
	}

	/* ------------------------------------------------------------------ farms */

	/**
	 * @param array<string, mixed> $args q, status, membership, state, region, sort, dir, page, per_page
	 */
	public function farms( array $args ): array {
		global $wpdb;
		$p      = $this->paging( $args, [ 'name', 'owner', 'state', 'membership', 'ends_at', 'listings', 'created', 'status' ], 'name', 'ASC' );
		$farms  = Schema::table( 'sp_farms' );
		$ms     = Schema::table( 'sp_memberships' );
		$from   = "FROM {$farms} f LEFT JOIN {$ms} ms ON ms.farm_id = f.id LEFT JOIN {$wpdb->users} o ON o.ID = f.owner_user_id";
		$where  = [ '1 = 1' ];
		$params = [];
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(f.name LIKE %s OR f.owner_name LIKE %s OR f.email LIKE %s OR o.user_email LIKE %s OR f.city LIKE %s OR f.breeder_number LIKE %s OR f.slug LIKE %s OR f.state = %s)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, $like, $like, $like, strtoupper( $q ) ] );
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( in_array( $status, [ 'active', 'inactive' ], true ) ) {
			$where[]  = 'f.status = %s';
			$params[] = $status;
		}
		$now = Dates::now();
		$mem = sanitize_key( (string) ( $args['membership'] ?? '' ) );
		switch ( $mem ) {
			case 'active':
				$where[]  = "ms.status = 'active' AND (ms.ends_at IS NULL OR ms.ends_at > %s)";
				$params[] = $now;
				break;
			case 'expiring':
				$where[]  = "ms.status = 'active' AND ms.ends_at > %s AND ms.ends_at <= %s";
				$params[] = $now;
				$params[] = Dates::add_days( $now, 30 );
				break;
			case 'expired':
				$where[]  = "(ms.status = 'expired' OR (ms.status = 'active' AND ms.ends_at <= %s))";
				$params[] = $now;
				break;
			case 'pending':
			case 'cancelled':
				$where[]  = 'ms.status = %s';
				$params[] = $mem;
				break;
			case 'none':
				$where[] = "(ms.id IS NULL OR ms.status = 'none')";
				break;
		}
		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'f.state = %s';
			$params[] = strtoupper( substr( sanitize_key( (string) $args['state'] ), 0, 2 ) );
		}
		if ( ! empty( $args['region'] ) ) {
			$where[]  = 'f.region = %s';
			$params[] = sanitize_text_field( (string) $args['region'] );
		}
		$order = match ( $p['sort'] ) {
			'owner'      => "f.owner_name {$p['dir']}",
			'state'      => "f.state {$p['dir']}, f.city {$p['dir']}",
			'membership' => "ms.status {$p['dir']}, ms.ends_at {$p['dir']}",
			'ends_at'    => "ms.ends_at {$p['dir']}",
			'listings'   => "f.active_listing_count {$p['dir']}, f.last_listing_at {$p['dir']}",
			'created'    => "f.created_at {$p['dir']}",
			'status'     => "f.status {$p['dir']}",
			default      => "f.name {$p['dir']}",
		};
		$sql   = "SELECT f.*, o.user_email AS owner_email, o.display_name AS owner_display, ms.status AS membership_status, ms.ends_at AS membership_ends_at, ms.starts_at AS membership_starts_at, ms.auto_renew, ms.source AS membership_source, ms.tier_id
			{$from} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}, f.id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $p['per_page'], $p['offset'] ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ), $params ) : "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$r->membership_status = $this->effective( $r->membership_status, $r->membership_ends_at );
		}
		return [ 'items' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'] ];
	}

	/* --------------------------------------------------------------- listings */

	/**
	 * @param array<string, mixed> $args q, visibility(live|draft|expired), status, farm_id, category, sort, dir, page, per_page
	 * @return array{ids:int[], total:int, page:int, per_page:int}
	 */
	public function listings( array $args ): array {
		global $wpdb;
		$p      = $this->paging( $args, [ 'title', 'farm', 'status', 'price', 'published', 'expires', 'views', 'modified' ], 'modified', 'DESC' );
		$farms  = Schema::table( 'sp_farms' );
		$from   = "FROM {$wpdb->posts} po
			JOIN {$wpdb->postmeta} mf ON mf.post_id = po.ID AND mf.meta_key = '_sp_farm_id'
			LEFT JOIN {$farms} f ON f.id = mf.meta_value
			LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = po.ID AND ms.meta_key = '_sp_status'
			LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = po.ID AND mp.meta_key = '_sp_price'
			LEFT JOIN {$wpdb->postmeta} mpub ON mpub.post_id = po.ID AND mpub.meta_key = '_sp_published_at'
			LEFT JOIN {$wpdb->postmeta} mexp ON mexp.post_id = po.ID AND mexp.meta_key = '_sp_expires_at'
			LEFT JOIN {$wpdb->postmeta} mv ON mv.post_id = po.ID AND mv.meta_key = '_sp_view_count'";
		$where  = [ 'po.post_type = %s', "po.post_status IN ('publish','draft','sp_expired')" ];
		$params = [ ListingTypes::POST_TYPE ];
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(po.post_title LIKE %s OR po.post_excerpt LIKE %s OR f.name LIKE %s OR f.owner_name LIKE %s OR po.ID = %d)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, (int) $q ] );
		}
		$vis = sanitize_key( (string) ( $args['visibility'] ?? '' ) );
		if ( 'live' === $vis ) {
			$where[] = "po.post_status = 'publish'";
		} elseif ( 'draft' === $vis ) {
			$where[] = "po.post_status = 'draft'";
		} elseif ( 'expired' === $vis ) {
			$where[] = "po.post_status = 'sp_expired'";
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( in_array( $status, ListingTypes::STATUSES, true ) ) {
			$where[]  = 'ms.meta_value = %s';
			$params[] = $status;
		}
		if ( ! empty( $args['farm_id'] ) ) {
			$where[]  = 'mf.meta_value = %d';
			$params[] = (int) $args['farm_id'];
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = "po.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s)";
			$params[] = ListingTypes::TAXONOMY;
			$params[] = sanitize_title( (string) $args['category'] );
		}
		$order = match ( $p['sort'] ) {
			'title'     => "po.post_title {$p['dir']}",
			'farm'      => "f.name {$p['dir']}",
			'status'    => "po.post_status {$p['dir']}, ms.meta_value {$p['dir']}",
			'price'     => "CAST(mp.meta_value AS UNSIGNED) {$p['dir']}",
			'published' => "mpub.meta_value {$p['dir']}",
			'expires'   => "mexp.meta_value {$p['dir']}",
			'views'     => "CAST(mv.meta_value AS UNSIGNED) {$p['dir']}",
			default     => "po.post_modified_gmt {$p['dir']}",
		};
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT po.ID {$from} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}, po.ID DESC LIMIT %d OFFSET %d", array_merge( $params, [ $p['per_page'], $p['offset'] ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ), $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'ids' => array_map( 'intval', $ids ), 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'] ];
	}

	/* ------------------------------------------------------------ memberships */

	/**
	 * @param array<string, mixed> $args q, status, source, auto_renew, sort, dir, page, per_page
	 */
	public function memberships( array $args ): array {
		global $wpdb;
		$p      = $this->paging( $args, [ 'farm', 'owner', 'status', 'starts_at', 'ends_at', 'source', 'auto_renew', 'price' ], 'ends_at', 'ASC' );
		$farms  = Schema::table( 'sp_farms' );
		$ms     = Schema::table( 'sp_memberships' );
		$tiers  = Schema::table( 'sp_membership_tiers' );
		$from   = "FROM {$ms} ms JOIN {$farms} f ON f.id = ms.farm_id LEFT JOIN {$wpdb->users} o ON o.ID = f.owner_user_id LEFT JOIN {$tiers} t ON t.id = ms.tier_id";
		$where  = [ '1 = 1' ];
		$params = [];
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(f.name LIKE %s OR f.owner_name LIKE %s OR o.user_email LIKE %s OR f.breeder_number LIKE %s OR ms.stripe_customer_id LIKE %s OR ms.stripe_subscription_id LIKE %s OR ms.notes LIKE %s)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, $like, $like, $like ] );
		}
		$now    = Dates::now();
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		switch ( $status ) {
			case 'active':
				$where[]  = "ms.status = 'active' AND (ms.ends_at IS NULL OR ms.ends_at > %s)";
				$params[] = $now;
				break;
			case 'expiring':
				$where[]  = "ms.status = 'active' AND ms.ends_at > %s AND ms.ends_at <= %s";
				$params[] = $now;
				$params[] = Dates::add_days( $now, 30 );
				break;
			case 'expired':
				$where[]  = "(ms.status = 'expired' OR (ms.status = 'active' AND ms.ends_at <= %s))";
				$params[] = $now;
				break;
			case 'pending':
			case 'cancelled':
			case 'none':
				$where[]  = 'ms.status = %s';
				$params[] = $status;
				break;
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'ms.source = %s';
			$params[] = sanitize_key( (string) $args['source'] );
		}
		if ( isset( $args['auto_renew'] ) && '' !== (string) $args['auto_renew'] ) {
			$where[]  = 'ms.auto_renew = %d';
			$params[] = (int) (bool) $args['auto_renew'];
		}
		$order = match ( $p['sort'] ) {
			'farm'       => "f.name {$p['dir']}",
			'owner'      => "f.owner_name {$p['dir']}",
			'status'     => "ms.status {$p['dir']}, ms.ends_at {$p['dir']}",
			'starts_at'  => "ms.starts_at {$p['dir']}",
			'source'     => "ms.source {$p['dir']}",
			'auto_renew' => "ms.auto_renew {$p['dir']}",
			'price'      => "ms.price_cents_paid {$p['dir']}",
			default      => "ms.ends_at IS NULL, ms.ends_at {$p['dir']}",
		};
		$sql   = "SELECT ms.*, f.name AS farm_name, f.slug AS farm_slug, f.owner_name, f.breeder_number, f.owner_user_id, o.user_email AS owner_email, t.name AS tier_name {$from} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}, ms.id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $p['per_page'], $p['offset'] ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ), $params ) : "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$r->effective_status = $this->effective( $r->status, $r->ends_at );
			$r->days_left        = $r->ends_at ? Dates::days_until( (string) $r->ends_at ) : null;
		}
		return [ 'items' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'] ];
	}

	/* --------------------------------------------------------------- payments */

	/**
	 * @param array<string, mixed> $args q, status, method, from, to, farm_id, sort, dir, page, per_page
	 */
	public function payments( array $args ): array {
		global $wpdb;
		$p      = $this->paging( $args, [ 'paid_at', 'amount', 'farm', 'status', 'method', 'created' ], 'created', 'DESC' );
		$farms  = Schema::table( 'sp_farms' );
		$pay    = Schema::table( 'sp_payments' );
		$from   = "FROM {$pay} pm LEFT JOIN {$farms} f ON f.id = pm.farm_id";
		$where  = [ '1 = 1' ];
		$params = [];
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(f.name LIKE %s OR f.owner_name LIKE %s OR pm.notes LIKE %s OR pm.stripe_payment_intent_id LIKE %s OR pm.stripe_invoice_id LIKE %s OR pm.stripe_charge_id LIKE %s OR pm.stripe_checkout_session_id LIKE %s OR pm.id = %d)';
			$params  = array_merge( $params, [ $like, $like, $like, $like, $like, $like, $like, (int) $q ] );
		}
		foreach ( [ 'status', 'method' ] as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[]  = "pm.{$k} = %s";
				$params[] = sanitize_key( (string) $args[ $k ] );
			}
		}
		if ( ! empty( $args['farm_id'] ) ) {
			$where[]  = 'pm.farm_id = %d';
			$params[] = (int) $args['farm_id'];
		}
		if ( ! empty( $args['from'] ) && strtotime( (string) $args['from'] ) ) {
			$where[]  = 'COALESCE(pm.paid_at, pm.created_at) >= %s';
			$params[] = gmdate( Dates::MYSQL, (int) strtotime( (string) $args['from'] ) );
		}
		if ( ! empty( $args['to'] ) && strtotime( (string) $args['to'] ) ) {
			$where[]  = 'COALESCE(pm.paid_at, pm.created_at) <= %s';
			$params[] = gmdate( Dates::MYSQL, (int) strtotime( (string) $args['to'] . ' 23:59:59' ) );
		}
		$order = match ( $p['sort'] ) {
			'paid_at' => "pm.paid_at {$p['dir']}",
			'amount'  => "pm.amount_cents {$p['dir']}",
			'farm'    => "f.name {$p['dir']}",
			'status'  => "pm.status {$p['dir']}",
			'method'  => "pm.method {$p['dir']}",
			default   => "pm.created_at {$p['dir']}",
		};
		$sql   = "SELECT pm.*, f.name AS farm_name, f.slug AS farm_slug, f.owner_name {$from} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}, pm.id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $p['per_page'], $p['offset'] ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ), $params ) : "SELECT COUNT(*) {$from} WHERE " . implode( ' AND ', $where ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum   = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COALESCE(SUM(pm.amount_cents),0) {$from} WHERE " . implode( ' AND ', $where ) . " AND pm.status = 'succeeded'", $params ) : "SELECT COALESCE(SUM(pm.amount_cents),0) {$from} WHERE " . implode( ' AND ', $where ) . " AND pm.status = 'succeeded'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'items' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'], 'sum_cents' => $sum ];
	}

	/* ---------------------------------------------------------------- helpers */

	/** @return array{page:int, per_page:int, offset:int, sort:string, dir:string} */
	private function paging( array $args, array $sortable, string $default_sort, string $default_dir ): array {
		$raw      = $args['per_page'] ?? 25;
		$per_page = 'all' === $raw ? self::MAX : min( self::MAX, max( 1, (int) $raw ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sort     = sanitize_key( (string) ( $args['sort'] ?? '' ) );
		$dir      = strtoupper( (string) ( $args['dir'] ?? '' ) );
		return [
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
			'sort'     => in_array( $sort, $sortable, true ) ? $sort : $default_sort,
			'dir'      => in_array( $dir, [ 'ASC', 'DESC' ], true ) ? $dir : $default_dir,
		];
	}

	private function effective( ?string $status, ?string $ends_at ): string {
		$status = (string) ( $status ?: 'none' );
		if ( 'active' === $status && Dates::is_past( $ends_at ) ) {
			return 'expired';
		}
		return $status;
	}
}
