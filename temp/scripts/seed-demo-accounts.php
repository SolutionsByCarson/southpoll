<?php
/**
 * seed-demo-accounts.php — Two ready-to-use member logins for Local:
 *
 *   buyer@example.test  Taylor Brooks  verified buyer with favorite farms + listings and three saved searches (one default)
 *   owner@example.test  Sam Rivera     owner of "Rivera Family Farm": active membership, logo / banner / gallery, 6 listings
 *
 * Run: ./wp-run.sh seed-demo-accounts.php [--password=…] [--days-left=10]
 *                                                           creates both and prints the credentials ONCE (the password is
 *                                                           never written to disk; omit --password for a random one).
 *                                                           The owner's membership is paid by check, NOT recurring, and
 *                                                           expires in --days-left days (default 10) so the countdown
 *                                                           notice and the renewal workflow are visible.
 *      ./wp-run.sh seed-demo-accounts.php --delete           removes both accounts and everything attached to them
 *
 * Favorites point at the demo farms from seed-demo-data.php when present (any public farms otherwise).
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/demo-lib.php';

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Routing\Urls;

global $wpdb;
$p        = $wpdb->prefix;
$delete   = in_array( '--delete', $argv, true );
$password = '';
foreach ( $argv as $arg ) { if ( str_starts_with( (string) $arg, '--password=' ) ) { $password = substr( (string) $arg, 11 ); } }
$accounts = [ 'buyer' => 'buyer@example.test', 'owner' => 'owner@example.test' ];
$urls     = sp_demo( Urls::class );
$farms    = sp_demo( FarmRepository::class );
$fu_repo  = sp_demo( FarmUserRepository::class );

if ( $delete ) {
	foreach ( $accounts as $kind => $email ) {
		$u = get_user_by( 'email', $email );
		if ( ! $u ) { echo "- {$email}: not found\n"; continue; }
		$fu = $fu_repo->for_user( (int) $u->ID );
		if ( $fu && 'owner' === (string) $fu->role && $farms->find( (int) $fu->farm_id ) ) {
			$r = sp_demo_delete_farm( (int) $fu->farm_id, (int) $u->ID );
			echo "- farm #{$fu->farm_id} removed ({$r['listings']} listings, {$r['media']} images)\n";
		}
		sp_demo_delete_user( (int) $u->ID );
		echo "- {$email} removed\n";
	}
	exit( 0 );
}
foreach ( $accounts as $email ) { if ( get_user_by( 'email', $email ) ) { die( "{$email} already exists; run with --delete first to recreate.\n" ); } }
if ( '' === $password ) { $password = wp_generate_password( 14, false ); }
mt_srand( 20261020 );

/* ------------------------------------------------------------------ owner */
$owner = sp_demo_create_user( [ 'login' => 'demo-owner', 'email' => $accounts['owner'], 'password' => $password, 'first' => 'Sam', 'last' => 'Rivera', 'phone' => '417-555-0150', 'address1' => '3310 Sycamore Rd', 'city' => 'Marshfield', 'state' => 'MO', 'zip' => '65706', 'days_ago' => 410 ] );
if ( is_wp_error( $owner ) ) { die( 'owner: ' . $owner->get_error_message() . "\n" ); }
$farm = sp_demo_create_farm( $owner, [ 'name' => 'Rivera Family Farm', 'slogan' => 'Grass-fed South Polls in the Ozarks', 'first' => 'Sam', 'last' => 'Rivera', 'address1' => '3310 Sycamore Rd', 'city' => 'Marshfield', 'state' => 'MO', 'zip' => '65706', 'lat' => 37.3387, 'lng' => -92.9071, 'phone' => '417-555-0150', 'website' => 'https://example.com/rivera-family-farm', 'breeder' => 'SP-2041', 'hours' => true, 'gallery' => 4, 'days_ago' => 400 ], 21 );
if ( is_wp_error( $farm ) ) { die( 'farm: ' . $farm->get_error_message() . "\n" ); }
$fid = (int) $farm->id;
// Expiring soon and NOT recurring: shows the countdown notice and the manual renewal workflow (pay online / claim a check).
$days_left = 10;
foreach ( $argv as $arg ) { if ( str_starts_with( (string) $arg, '--days-left=' ) ) { $days_left = max( 1, (int) substr( (string) $arg, 12 ) ); } }
sp_demo_membership( $fid, $owner, [ 'days_left' => $days_left, 'source' => 'offline', 'auto' => false, 'prev_years' => 2 ] );
$lots  = sp_demo_lots();
$plan  = [ [ 'bulls', 0, 2 ], [ 'bulls', 1, 9 ], [ 'cows', 0, 5 ], [ 'heifers', 2, 14 ], [ 'steers', 0, 20 ], [ 'steers', 3, 31 ] ];
$count = 0;
foreach ( $plan as $k => [ $cat, $idx, $days_ago ] ) {
	$res = sp_demo_listing( $fid, $owner, $cat, $lots[ $cat ][ $idx ], 40 + $k, [ 'days_ago' => $days_ago ] );
	if ( is_wp_error( $res ) ) { echo "  ! listing: {$res->get_error_message()}\n"; } else { $count++; }
}
$res = sp_demo_listing( $fid, $owner, 'semen', $lots['semen'][0], 47, [ 'action' => 'draft' ] );
if ( ! is_wp_error( $res ) ) { $count++; }
sp_demo( \SouthPoll\Membership\Listings\ListingService::class )->refresh_farm_stats( $fid );
$farm = $farms->find( $fid );
echo "+ owner {$accounts['owner']} → Rivera Family Farm (#{$fid}), membership paid by check, expiring in {$days_left} days, auto-renew OFF, {$count} listings (one draft)\n";

/* ------------------------------------------------------------------ buyer */
$buyer = sp_demo_create_user( [ 'login' => 'demo-buyer', 'email' => $accounts['buyer'], 'password' => $password, 'first' => 'Taylor', 'last' => 'Brooks', 'phone' => '417-555-0160', 'address1' => '1420 E Cherry St', 'city' => 'Springfield', 'state' => 'MO', 'zip' => '65802', 'days_ago' => 90 ] );
if ( is_wp_error( $buyer ) ) { die( 'buyer: ' . $buyer->get_error_message() . "\n" ); }
$favs   = sp_demo( FavoriteRepository::class );
$public = $wpdb->get_col( $wpdb->prepare( "SELECT f.id FROM {$p}sp_farms f JOIN {$p}sp_memberships m ON m.farm_id = f.id JOIN {$wpdb->users} u ON u.ID = f.owner_user_id WHERE f.status = 'active' AND m.status = 'active' AND f.id <> %d AND (m.ends_at IS NULL OR m.ends_at > %s) ORDER BY (u.user_email LIKE 'sp-demo-%%') DESC, f.name LIMIT 3", $fid, \SouthPoll\Membership\Support\Dates::now() ) );
$public[] = $fid; // the demo owner's farm too
foreach ( array_values( array_unique( array_map( 'intval', $public ) ) ) as $i => $farm_id ) { $favs->add( $buyer, 'farm', $farm_id, 0 === $i % 2 ); }
$latest = sp_demo( ListingQuery::class )->run( [ 'per_page' => 4, 'sort' => 'newest' ] )['items'];
foreach ( $latest as $i => $l ) { $favs->add( $buyer, 'listing', (int) $l->id, 0 === $i ); }
wp_set_current_user( $buyer ); sp_demo( ContextProvider::class )->forget();
$ctx     = sp_demo( ContextProvider::class )->for_user( $buyer );
$svc     = sp_demo( SavedSearchService::class );
$regions = array_keys( (array) sp_demo( Settings::class )->get( 'region_map', [] ) );
$region  = '';
foreach ( (array) sp_demo( Settings::class )->get( 'region_map', [] ) as $name => $states ) { if ( in_array( 'TN', (array) $states, true ) ) { $region = (string) $name; } }
$region  = $region ?: (string) ( $regions[0] ?? '' );
$searches = [
	[ [ 'cats' => [ 'bulls' ], 'state' => 'MO' ], 'Missouri bulls', true ],
	[ array_filter( [ 'cats' => [ 'cows' ], 'region' => $region ] ), $region ? "Cows in the $region" : 'Cows', true ],
	[ [ 'q' => 'grass-finished' ], 'Grass-finished beef', false ],
];
$made = 0;
foreach ( $searches as [ $params, $name, $notify ] ) {
	$row = $svc->save( $ctx, $params, $name, $notify );
	if ( is_wp_error( $row ) ) { echo "  ! saved search {$name}: {$row->get_error_message()}\n"; continue; }
	$made++;
}
wp_set_current_user( 0 ); sp_demo( ContextProvider::class )->forget();
echo "+ buyer {$accounts['buyer']} → " . count( array_unique( $public ) ) . ' favorite farms, ' . count( $latest ) . " favorite listings, {$made} saved searches\n";

echo "\nCREDENTIALS (not stored anywhere else)\n";
printf( "  %-22s %-14s %s\n", 'email', 'login', 'password' );
printf( "  %-22s %-14s %s\n", $accounts['buyer'], 'demo-buyer', $password );
printf( "  %-22s %-14s %s\n", $accounts['owner'], 'demo-owner', $password );
echo "\nLogin:        " . $urls->login() . "\nDashboard:    " . $urls->dashboard() . "\nFarm page:    " . $urls->farm( (string) $farm->slug ) . "\nMy Farm:      " . $urls->my_farm() . "\nMy Listings:  " . $urls->my_listings() . "\nFavorites:    " . $urls->my_favorites() . "\nSaved search: " . $urls->saved_searches() . "\n";
