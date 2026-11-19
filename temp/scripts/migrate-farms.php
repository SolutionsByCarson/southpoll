<?php
/**
 * migrate-farms.php — T5.2: `store` posts → sp_farms (+ owner rows), then a minimal farm for every member with a
 * membership row but no store (the directory must list every member). Addresses are parsed into street / city /
 * state / zip and the uncertain ones are listed for review. Logos and banners are adopted in place (tagged, sizes rebuilt).
 *
 *   ./wp-run.sh migrate-farms.php --dry-run | [--limit=N] [--no-media]
 * Run migrate-users.php first.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Support\Str;

global $wpdb;
$p        = $wpdb->prefix;
$o        = sp_mig_opts();
$r        = new SpMigReport( 'migrate-farms', $o['dry'] );
$farms    = sp_membership()->get( FarmRepository::class );
$service  = sp_membership()->get( FarmService::class );
$members  = sp_membership()->get( MemberRepository::class );
$pending  = [ 'farms' => [], 'attachments' => [] ];
sp_mig_autoflush( $pending );
$done     = 0;

$social_map = static function ( mixed $raw ): array {
	$arr = (array) maybe_unserialize( (string) $raw );
	$out = [];
	foreach ( $arr as $k => $v ) {
		$k = strtolower( (string) $k ); $v = trim( (string) $v );
		if ( '' === $v ) { continue; }
		$key = match ( true ) { str_contains( $k, 'face' ) => 'facebook', str_contains( $k, 'insta' ) => 'instagram', str_contains( $k, 'you' ) => 'youtube', str_contains( $k, 'twit' ) || 'x' === $k => 'x', str_contains( $k, 'tik' ) => 'tiktok', str_contains( $k, 'linked' ) => 'linkedin', default => 'other' };
		if ( ! preg_match( '#^https?://#i', $v ) ) { $v = 'https://' . ltrim( $v, '/' ); }
		$out[ $key ] = $v;
	}
	return $out;
};

/** Create (or report) one farm. Returns the farm id (0 on dry run / failure). */
$make_farm = function ( int $uid, string $name, array $fields, array $addr, int $store_id, string $origin ) use ( $o, $r, $farms, $service, $members, &$pending ): int {
	global $wpdb;
	$u = get_userdata( $uid );
	if ( ! $u ) { $r->flag( 'owner-missing', "$origin: user #$uid does not exist" ); return 0; }
	if ( $have = sp_mig_farm_for_user( $uid ) ) {
		$r->count( 'owner-already-has-farm' );
		if ( $store_id && ! $o['dry'] && empty( $have->legacy_store_id ) ) { $wpdb->update( "{$wpdb->prefix}sp_farms", [ 'legacy_store_id' => $store_id ], [ 'id' => (int) $have->id ] ); } // so /store/{id}/ still redirects to it
		return (int) $have->id;
	}
	if ( $o['dry'] ) { $r->count( 'would-create' ); return 0; }
	$farm = $service->create_for_owner( $uid, $name, [ 'state' => $addr['state'], 'breeder_number' => trim( (string) get_user_meta( $uid, 'southpoll_breeder_number', true ) ) ] );
	if ( is_wp_error( $farm ) ) { $r->flag( 'create-failed', "$origin: {$farm->get_error_message()}" ); return 0; }
	$fid = (int) $farm->id;
	$farms->update( $fid, $fields + [ 'address1' => $addr['address1'], 'city' => $addr['city'], 'state' => $addr['state'], 'zip' => $addr['zip'], 'contact_form_enabled' => 1 ] );
	if ( $store_id ) {
		$slug = $farms->unique_slug( (string) ( get_post_field( 'post_name', $store_id ) ?: $name ), $fid );
		$wpdb->update( "{$wpdb->prefix}sp_farms", [ 'slug' => $slug, 'legacy_store_id' => $store_id, 'created_at' => get_post_field( 'post_date_gmt', $store_id ) ?: current_time( 'mysql', true ) ], [ 'id' => $fid ] );
	}
	// Member state follows the farm address when the farm has one.
	if ( '' !== $addr['state'] ) { $m = $members->find_by_user( $uid ); if ( $m && ( '' === (string) $m->state || 'CA' === (string) $m->state ) ) { $members->update( (int) $m->id, [ 'state' => $addr['state'], 'city' => (string) $m->city ?: $addr['city'], 'zip' => (string) $m->zip ?: $addr['zip'] ] ); } }
	$pending['farms'][] = $fid;
	$r->count( 'created-' . $origin );
	return $fid;
};

/* ------------------------------------------------------- 1. store posts */
$r->h( 'Stores' );
$stores = $wpdb->get_results( "SELECT ID, post_title, post_name, post_content, post_date FROM {$wpdb->posts} WHERE post_type = 'store' AND post_status = 'publish' ORDER BY ID" );
foreach ( $stores as $s ) {
	if ( $o['limit'] && $done >= $o['limit'] ) { break; }
	$done++;
	$sid   = (int) $s->ID;
	$uid   = (int) get_post_meta( $sid, 'store_owner_id', true );
	$u     = $uid ? get_userdata( $uid ) : null;
	$meta  = fn( string $k ) => trim( (string) get_post_meta( $sid, $k, true ) );
	$name  = trim( wp_specialchars_decode( (string) $s->post_title, ENT_QUOTES ) ); // WP stores "&amp;" in titles; the farms table holds plain text
	if ( '' === $name || '(untitled)' === strtolower( $name ) || 'untitled' === strtolower( $name ) ) {
		$company = $u ? trim( (string) get_user_meta( $uid, '_billing_company', true ) ) : '';
		$nm      = $u ? sp_mig_user_name( $u ) : [ 'display' => '' ];
		$name    = $company ?: ( $nm['display'] ? $nm['display'] . ' Farm' : 'Farm #' . $sid );
		$r->flag( 'untitled-farm', "store #$sid → named \"$name\" (owner #$uid)" );
	}
	if ( sp_mig_farm_by_legacy( $sid ) ) { $r->count( 'store-already-migrated' ); continue; }
	$addr = sp_mig_parse_address( $meta( 'address' ) );
	if ( '' === $addr['state'] && $u ) {
		$bill = sp_mig_state_code( (string) get_user_meta( $uid, '_billing_state', true ) );
		if ( 'CA' === $bill && '' === (string) get_user_meta( $uid, '_billing_city', true ) ) { $bill = ''; $r->count( 'state-ca-default-ignored' ); } // same rule as migrate-users
		$addr['state'] = sp_mig_location_state( get_user_meta( $uid, '_rtcl_location', true ) ) ?: $bill;
	}
	if ( '' !== $meta( 'address' ) && in_array( $addr['confidence'], [ 'none', 'low' ], true ) ) { $r->flag( 'address-review', "store #$sid \"$name\": \"{$meta( 'address' )}\" → " . json_encode( array_intersect_key( $addr, array_flip( [ 'address1', 'city', 'state', 'zip' ] ) ) ) ); }
	$r->count( 'address-' . $addr['confidence'] );
	$website = $meta( 'website' );
	$fields  = [
		'slogan'     => mb_substr( wp_specialchars_decode( $meta( 'slogan' ), ENT_QUOTES ), 0, 255 ),
		'about'      => wp_kses_post( (string) $s->post_content ),
		'phone'      => Str::phone( $meta( 'phone' ) ),
		'email'      => is_email( $meta( 'email' ) ) ? $meta( 'email' ) : ( $u ? $u->user_email : '' ),
		'website'    => $website ? ( preg_match( '#^https?://#i', $website ) ? $website : 'https://' . $website ) : '',
		'social'     => $social_map( get_post_meta( $sid, 'social_media', true ) ),
		'owner_name' => $u ? sp_mig_user_name( $u )['display'] : '',
	];
	$fid = $make_farm( $uid, $name, $fields, $addr, $sid, 'store' );
	if ( ! $fid && ! $o['dry'] ) { continue; }
	foreach ( [ 'logo_id' => 'logo', 'banner_id' => 'banner' ] as $k => $purpose ) {
		$att = (int) get_post_meta( $sid, $k, true );
		if ( ! $att ) { continue; }
		if ( sp_mig_adopt_attachment( $att, $fid, $purpose, 0, $o['dry'] ) ) { if ( ! $o['dry'] ) { $farms->set_image( $fid, $purpose, $att ); $pending['attachments'][] = $att; } $r->count( $purpose . '-adopted' ); }
		else { $r->flag( 'media-missing', "store #$sid $purpose attachment #$att has no file" ); }
	}
}
$r->count( 'stores-seen', $done );

/* --------------------------------- 2. members with a membership but no store */
$r->h( 'Members without a farm page (minimal farm created)' );
$rows = $wpdb->get_results( "SELECT m.user_id, m.expiry_date FROM {$p}rtcl_membership m LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key = 'store_owner_id' AND pm.meta_value = m.user_id LEFT JOIN {$wpdb->posts} s ON s.ID = pm.post_id AND s.post_type = 'store' AND s.post_status = 'publish' WHERE s.ID IS NULL ORDER BY m.user_id" );
$n = 0;
foreach ( $rows as $row ) {
	if ( $o['limit'] && $n >= $o['limit'] ) { break; }
	$uid = (int) $row->user_id;
	$u   = get_userdata( $uid );
	if ( ! $u ) { $r->flag( 'owner-missing', "membership for missing user #$uid" ); continue; }
	$n++;
	$nm      = sp_mig_user_name( $u );
	$company = trim( (string) get_user_meta( $uid, '_billing_company', true ) );
	$name    = $company ?: ( $nm['display'] ? $nm['display'] . ' Farm' : 'Member #' . $uid );
	$m       = $members->find_by_user( $uid );
	$addr    = [ 'address1' => '', 'city' => $m ? (string) $m->city : '', 'state' => $m ? (string) $m->state : sp_mig_location_state( get_user_meta( $uid, '_rtcl_location', true ) ), 'zip' => $m ? (string) $m->zip : '', 'confidence' => 'member' ];
	$fields  = [ 'phone' => $m ? (string) $m->phone : '', 'email' => $u->user_email, 'owner_name' => $nm['display'] ];
	$make_farm( $uid, $name, $fields, $addr, 0, 'minimal' );
	$r->count( strtotime( (string) $row->expiry_date ) > time() ? 'minimal-for-active-member' : 'minimal-for-expired-member' );
}
sp_mig_log_flush( $pending );
$r->finish();
