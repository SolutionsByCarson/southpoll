<?php
/**
 * migrate-memberships.php — T5.3: wp_rtcl_membership → sp_memberships (status from expiry_date, source "migrated") and
 * rtcl_payment orders → sp_payments (Stripe ids kept; "store" gateway = pre-paid / honor system).
 *
 *   ./wp-run.sh migrate-memberships.php --dry-run | [--limit=N] [--include-abandoned]
 * Run migrate-users.php and migrate-farms.php first (every member needs a farm).
 *
 * Assumptions: auto-renew is OFF for everyone (no RTCL order was ever created by a Stripe subscription — Q-01 still open);
 * the Stripe customer id is kept so a member can be re-attached to a subscription later.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Support\Dates;

global $wpdb;
$p       = $wpdb->prefix;
$o       = sp_mig_opts();
$r       = new SpMigReport( 'migrate-memberships', $o['dry'] );
$mrepo   = sp_membership()->get( MembershipRepository::class );
$pay     = sp_membership()->get( PaymentRepository::class );
$tier    = sp_membership()->get( TierRepository::class )->default();
$now     = Dates::now();
$pending = [ 'memberships' => [], 'payments' => [] ];
sp_mig_autoflush( $pending );
$done    = 0;
$status_map = [ 'rtcl-completed' => 'succeeded', 'rtcl-failed' => 'failed', 'rtcl-refunded' => 'refunded', 'rtcl-cancelled' => 'failed', 'rtcl-created' => 'pending', 'rtcl-on-hold' => 'pending', 'rtcl-processing' => 'pending' ];

$r->h( 'Memberships' );
foreach ( $wpdb->get_results( "SELECT * FROM {$p}rtcl_membership ORDER BY id" ) as $row ) {
	if ( $o['limit'] && $done >= $o['limit'] ) { break; }
	$done++;
	$uid  = (int) $row->user_id;
	$farm = sp_mig_farm_for_user( $uid );
	if ( ! $farm ) { $r->flag( 'no-farm', "membership #{$row->id} user #$uid has no farm (run migrate-farms.php)" ); if ( ! $o['dry'] ) { continue; } } // dry run keeps counting without a farm
	$fid     = $farm ? (int) $farm->id : 0;
	$expiry  = (string) $row->expiry_date;
	$active  = strtotime( $expiry ) > time();
	$since   = (string) $row->member_since;
	$cust    = trim( (string) get_user_meta( $uid, 'wp__stripe_customer_id', true ) );
	// Orders: the membership's recorded order ids plus any order whose customer is this user.
	$ids = array_map( 'intval', (array) maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$p}rtcl_membership_meta WHERE membership_id = %d AND meta_key = '_rtcl_order_ids'", $row->id ) ) ) );
	$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'customer_id' AND meta_value = %d", $uid ) ) ) ) ) );
	sort( $ids );
	$orders = [];
	foreach ( $ids as $oid ) {
		$post = get_post( $oid );
		if ( ! $post || 'rtcl_payment' !== $post->post_type ) { continue; }
		$st = $status_map[ $post->post_status ] ?? 'pending';
		if ( 'pending' === $st && ! sp_mig_flag( 'include-abandoned' ) ) { $r->count( 'order-abandoned-skipped' ); continue; }
		$om  = fn( string $k ) => (string) get_post_meta( $oid, $k, true );
		$gw  = $om( '_payment_method' );
		$orders[] = [
			'legacy_payment_id'          => $oid,
			'amount_cents'               => (int) round( (float) $om( 'amount' ) * 100 ),
			'currency'                   => strtoupper( $om( '_order_currency' ) ?: 'USD' ),
			'method'                     => 'stripe' === $gw ? 'stripe' : 'other',
			'status'                     => $st,
			'paid_at'                    => $om( 'date_paid' ) ?: get_gmt_from_date( $post->post_date ),
			'stripe_customer_id'         => $om( '_stripe_customer_id' ),
			'stripe_payment_intent_id'   => $om( '_stripe_intent_id' ),
			'stripe_charge_id'           => str_starts_with( $om( 'transaction_id' ), 'ch_' ) ? $om( 'transaction_id' ) : '',
			'pricing'                    => get_the_title( (int) $om( '_pricing_id' ) ) ?: '',
			'fee'                        => $om( '_stripe_fee' ),
			'net'                        => $om( '_stripe_net' ),
			'gateway'                    => $gw,
		];
	}
	$succeeded = array_values( array_filter( $orders, fn( $x ) => 'succeeded' === $x['status'] ) );
	$last      = $succeeded ? end( $succeeded ) : null;
	$r->count( $active ? 'active' : 'expired' );
	$r->count( 'orders-' . count( $orders ) );
	if ( ! $succeeded ) { $r->flag( 'no-completed-order', "membership #{$row->id} user #$uid (expires $expiry)" ); }
	if ( $o['dry'] ) { continue; }
	$ms = $mrepo->ensure( $fid );
	if ( str_contains( (string) $ms->notes, 'Migrated from Classified Listing membership' ) ) { $r->count( 'already-migrated' ); continue; }
	$mrepo->update( (int) $ms->id, [
		'tier_id'            => (int) $tier->id,
		'status'             => $active ? 'active' : 'expired',
		'starts_at'          => $since ?: $last['paid_at'] ?? $now,
		'ends_at'            => $expiry,
		'auto_renew'         => 0,
		'source'             => 'migrated',
		'price_cents_paid'   => $last ? (int) $last['amount_cents'] : null,
		'stripe_customer_id' => $cust,
		'activated_at'       => $since ?: $now,
		'expired_at'         => $active ? null : $expiry,
		'reminders_sent'     => $active && Dates::days_until( $expiry ) <= 30 ? [ 'before_30' ] : [],
		'notes'              => '[' . $now . "] Migrated from Classified Listing membership #{$row->id} (member since $since, " . count( $succeeded ) . ' completed order(s); ads ' . (int) $row->posted_ads . '/' . (int) $row->ads . ')',
	] );
	$pending['memberships'][] = (int) $ms->id;
	foreach ( $orders as $ord ) {
		if ( $pay->find_by_intent( (string) $ord['stripe_payment_intent_id'] ) && '' !== $ord['stripe_payment_intent_id'] ) { $r->count( 'payment-already-migrated' ); continue; }
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sp_payments WHERE legacy_payment_id = %d", $ord['legacy_payment_id'] ) ) ) { $r->count( 'payment-already-migrated' ); continue; }
		$row_p = $pay->create( [
			'membership_id'            => (int) $ms->id,
			'farm_id'                  => $fid,
			'user_id'                  => $uid,
			'tier_id'                  => (int) $tier->id,
			'amount_cents'             => $ord['amount_cents'],
			'currency'                 => $ord['currency'],
			'method'                   => $ord['method'],
			'status'                   => $ord['status'],
			'recurring'                => 0,
			'paid_at'                  => $ord['paid_at'],
			'term_start'               => $ord['paid_at'],
			'term_end'                 => Dates::add_days( (string) $ord['paid_at'], (int) $tier->duration_days ),
			'stripe_customer_id'       => $ord['stripe_customer_id'],
			'stripe_payment_intent_id' => $ord['stripe_payment_intent_id'],
			'stripe_charge_id'         => $ord['stripe_charge_id'],
			'legacy_payment_id'        => $ord['legacy_payment_id'],
			'notes'                    => "Migrated RTCL order #{$ord['legacy_payment_id']} ({$ord['pricing']}, gateway {$ord['gateway']}" . ( 'store' === $ord['gateway'] ? ' = pre-paid / honor system' : '' ) . ')' . ( $ord['fee'] ? "; Stripe fee {$ord['fee']}, net {$ord['net']}" : '' ),
		] );
		$pending['payments'][] = (int) $row_p->id;
		$r->count( 'payment-' . $ord['status'] );
	}
}
sp_mig_log_flush( $pending );
$r->count( 'memberships-processed', $done );
$r->finish();
