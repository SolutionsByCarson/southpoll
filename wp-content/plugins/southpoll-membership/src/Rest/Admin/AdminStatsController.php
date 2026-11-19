<?php
/**
 * GET /sp/v1/admin/stats — counts and recent activity for the admin dashboard (staff only).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Engagement\NotificationQueue;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use WP_REST_Request;
use WP_REST_Response;

final class AdminStatsController extends BaseController {

	public function __construct( private NotificationQueue $queue, private PaymentRepository $payments, private Urls $urls ) {}

	public function register_routes(): void {
		$this->route( '/admin/stats', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => [ self::class, 'require_staff' ] ] ] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->stats() );
	}

	/** @return array<string, mixed> */
	public function stats(): array {
		global $wpdb;
		$now     = Dates::now();
		$members = Schema::table( 'sp_members' );
		$fu      = Schema::table( 'sp_farm_users' );
		$farms   = Schema::table( 'sp_farms' );
		$ms      = Schema::table( 'sp_memberships' );
		$pay     = Schema::table( 'sp_payments' );
		$fav     = Schema::table( 'sp_favorites' );
		$ss      = Schema::table( 'sp_saved_searches' );
		$inq     = Schema::table( 'sp_inquiries' );
		$v       = fn( string $sql, ...$args ) => (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = [
			'users_total'        => $v( "SELECT COUNT(*) FROM {$wpdb->users}" ),
			'accounts'           => $v( "SELECT COUNT(*) FROM {$members}" ),
			'owners'             => $v( "SELECT COUNT(*) FROM {$fu} WHERE role = 'owner' AND accepted_at IS NOT NULL" ),
			'managers'           => $v( "SELECT COUNT(*) FROM {$fu} WHERE role = 'manager' AND accepted_at IS NOT NULL" ),
			'unverified'         => $v( "SELECT COUNT(*) FROM {$members} WHERE email_verified_at IS NULL" ),
			'suspended'          => $v( "SELECT COUNT(*) FROM {$members} WHERE status = 'suspended'" ),
			'farms_total'        => $v( "SELECT COUNT(*) FROM {$farms}" ),
			'farms_public'       => $v( "SELECT COUNT(*) FROM {$farms} f JOIN {$ms} m ON m.farm_id = f.id WHERE f.status = 'active' AND m.status = 'active' AND (m.ends_at IS NULL OR m.ends_at > %s)", $now ),
			'memberships_active' => $v( "SELECT COUNT(*) FROM {$ms} WHERE status = 'active' AND (ends_at IS NULL OR ends_at > %s)", $now ),
			'memberships_expiring' => $v( "SELECT COUNT(*) FROM {$ms} WHERE status = 'active' AND ends_at > %s AND ends_at <= %s", $now, Dates::add_days( $now, 30 ) ),
			'memberships_expired'  => $v( "SELECT COUNT(*) FROM {$ms} WHERE status = 'expired' OR (status = 'active' AND ends_at <= %s)", $now ),
			'memberships_pending'  => $v( "SELECT COUNT(*) FROM {$ms} WHERE status = 'pending'" ),
			'memberships_none'     => $v( "SELECT COUNT(*) FROM {$ms} WHERE status = 'none'" ),
			'auto_renew'           => $v( "SELECT COUNT(*) FROM {$ms} WHERE auto_renew = 1 AND status = 'active'" ),
			'listings_live'        => $v( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", ListingTypes::POST_TYPE ),
			'listings_draft'       => $v( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'draft'", ListingTypes::POST_TYPE ),
			'listings_expired'     => $v( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'sp_expired'", ListingTypes::POST_TYPE ),
			'listings_7d'          => $v( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sp_published_at' AND pm.meta_value >= %s AND p.post_type = %s", Dates::add_days( $now, -7 ), ListingTypes::POST_TYPE ),
			'inquiries_30d'        => $v( "SELECT COUNT(*) FROM {$inq} WHERE created_at >= %s", Dates::add_days( $now, -30 ) ),
			'favorites'            => $v( "SELECT COUNT(*) FROM {$fav}" ),
			'saved_searches'       => $v( "SELECT COUNT(*) FROM {$ss}" ),
			'alerts_pending'       => $this->queue->count_pending(),
			'claims_pending'       => count( $this->payments->pending_claims() ),
			'revenue_30d_cents'    => $v( "SELECT COALESCE(SUM(amount_cents),0) FROM {$pay} WHERE status = 'succeeded' AND paid_at >= %s", Dates::add_days( $now, -30 ) ),
			'revenue_ytd_cents'    => $v( "SELECT COALESCE(SUM(amount_cents),0) FROM {$pay} WHERE status = 'succeeded' AND paid_at >= %s", gmdate( 'Y' ) . '-01-01 00:00:00' ),
			'payments_30d'         => $v( "SELECT COUNT(*) FROM {$pay} WHERE status = 'succeeded' AND paid_at >= %s", Dates::add_days( $now, -30 ) ),
		];
		$recent_listings    = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, p.post_name, mp.meta_value AS published_at, f.name AS farm_name FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_sp_published_at' JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' LEFT JOIN {$farms} f ON f.id = mf.meta_value WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY mp.meta_value DESC LIMIT 6", ListingTypes::POST_TYPE ) ) ?: [];
		$recent_memberships = $wpdb->get_results( "SELECT m.farm_id, m.activated_at, m.source, m.price_cents_paid, m.ends_at, f.name AS farm_name, f.slug AS farm_slug FROM {$ms} m JOIN {$farms} f ON f.id = m.farm_id WHERE m.activated_at IS NOT NULL ORDER BY m.activated_at DESC LIMIT 6" ) ?: [];
		$recent_accounts    = $wpdb->get_results( "SELECT u.ID, u.user_email, u.user_registered, m.first_name, m.last_name, m.email_verified_at FROM {$wpdb->users} u JOIN {$members} m ON m.user_id = u.ID ORDER BY u.user_registered DESC LIMIT 6" ) ?: [];
		// phpcs:enable
		return [
			'counts'  => $counts,
			'recent'  => [
				'listings'    => array_map( fn( $r ) => [ 'id' => (int) $r->ID, 'title' => (string) $r->post_title, 'url' => $this->urls->listing( (string) $r->post_name ), 'farm' => (string) $r->farm_name, 'date' => Dates::display( (string) $r->published_at ) ], $recent_listings ),
				'memberships' => array_map( fn( $r ) => [ 'farm_id' => (int) $r->farm_id, 'farm' => (string) $r->farm_name, 'url' => $this->urls->farm( (string) $r->farm_slug ), 'date' => Dates::display( (string) $r->activated_at ), 'source' => (string) $r->source, 'amount' => (int) $r->price_cents_paid, 'ends' => Dates::display( (string) $r->ends_at ) ], $recent_memberships ),
				'accounts'    => array_map( fn( $r ) => [ 'id' => (int) $r->ID, 'name' => trim( (string) $r->first_name . ' ' . (string) $r->last_name ), 'email' => (string) $r->user_email, 'date' => Dates::display( (string) $r->user_registered ), 'verified' => ! empty( $r->email_verified_at ) ], $recent_accounts ),
				'claims'      => array_map( fn( $c ) => [ 'id' => (int) $c->id, 'farm_id' => (int) $c->farm_id, 'amount' => (int) $c->amount_cents, 'method' => (string) $c->method, 'created' => Dates::display_datetime( (string) $c->created_at ) ], $this->payments->pending_claims() ),
			],
			'generated_at' => $now,
		];
	}
}
