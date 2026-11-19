<?php
/**
 * seed-demo-data.php — Populate Local with example farms and classified listings that look real: 14 fictional farms
 * across the South Poll states with owner accounts (sp-demo-*@example.test), memberships in every state the admin
 * screens show (active, auto-renew, expiring, expired, pending claim), payment history, farm logos / banners / galleries
 * (3–5 photos each) and ~50 listings with photos. Images are COPIES (downscaled) of logos, banners and cattle photos
 * already in the media library (old store logos/banners and old classified photos), attached to the demo farms, so
 * --delete removes only the copies and never touches the original media. Shared helpers: lib/demo-lib.php.
 *
 * Run: ./wp-run.sh seed-demo-data.php            → create (refuses if demo accounts already exist)
 *      ./wp-run.sh seed-demo-data.php --delete   → remove every demo account, farm, listing, payment and copied image
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/demo-lib.php';

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Routing\Urls;

global $wpdb;
$delete     = in_array( '--delete', $argv, true );
$demo_users = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-demo-%@example.test' ORDER BY ID" ) );
$farm_users = sp_demo( FarmUserRepository::class );
$farms      = sp_demo( FarmRepository::class );
$urls       = sp_demo( Urls::class );

if ( $delete ) {
	$n_farms = 0; $n_listings = 0; $n_media = 0;
	foreach ( $demo_users as $uid ) {
		$fu = $farm_users->for_user( $uid );
		if ( $fu && 'owner' === (string) $fu->role && $farms->find( (int) $fu->farm_id ) ) {
			$r = sp_demo_delete_farm( (int) $fu->farm_id, $uid );
			$n_farms++; $n_listings += $r['listings']; $n_media += $r['media'];
		}
		sp_demo_delete_user( $uid );
	}
	echo 'Deleted ' . count( $demo_users ) . " demo account(s), {$n_farms} farm(s), {$n_listings} listing(s), {$n_media} copied image(s).\n";
	exit( 0 );
}
if ( $demo_users ) { die( count( $demo_users ) . " demo account(s) already exist; run with --delete first to recreate.\n" ); }

mt_srand( 20261019 ); // reproducible variety
$lib = sp_demo_media();
if ( count( $lib['groups'] ) < 10 || count( $lib['logos'] ) < 5 ) { die( 'Not enough library images (' . count( $lib['groups'] ) . ' photo sets, ' . count( $lib['logos'] ) . " logos).\n" ); }
echo 'Library: ' . count( $lib['logos'] ) . ' logos, ' . count( $lib['banners'] ) . ' banners, ' . count( $lib['groups'] ) . ' photo sets (bulls ' . count( $lib['pools']['bulls'] ) . ', cows ' . count( $lib['pools']['cows'] ) . ', heifers ' . count( $lib['pools']['heifers'] ) . ', steers ' . count( $lib['pools']['steers'] ) . ")\n";

// name, slogan, first, last, address, city, state, zip, lat, lng, phone, website, days_left (null = pending claim, negative = expired), source, auto-renew
$farm_defs = [
	[ 'Lazy J Grass Ranch', 'Grass genetics since 2009', 'Jim', 'Hollis', '18120 NE 168th St', 'Kearney', 'MO', '64060', 39.3639, -94.3622, '816-555-0142', 'https://example.com/lazyjgrass', 300, 'stripe', true ],
	[ 'Ozark Prairie Cattle Co.', 'Slick-haired cattle for fescue country', 'Dale', 'Whitmore', '4411 S Farm Rd 125', 'Ozark', 'MO', '65721', 37.0209, -93.2060, '417-555-0177', '', 250, 'offline', false ],
	[ 'Buffalo River Grassfed', 'Finished on grass, nothing else', 'Lena', 'Ortiz', '2890 Push Mountain Rd', 'Mountain Home', 'AR', '72653', 36.3353, -92.3852, '870-555-0113', 'https://example.com/buffaloriver', 210, 'stripe', true ],
	[ 'Flint Hills Heritage Farm', 'Tallgrass prairie, moderate cows', 'Ruth Ann', 'Baker', '1365 Ks-177', 'Council Grove', 'KS', '66846', 38.6611, -96.4917, '620-555-0156', '', 170, 'offline', false ],
	[ 'Red River South Polls', 'Heat-tolerant cattle for the south', 'Travis', 'Dunlap', '7750 E 2050 Rd', 'Hugo', 'OK', '74743', 34.0107, -95.5097, '580-555-0128', 'https://example.com/redriversouthpolls', 140, 'stripe', true ],
	[ 'Cross Timbers Ranch', 'Low-input cattle on native range', 'Marcus', 'Reyes', '9120 FM 1188', 'Stephenville', 'TX', '76401', 32.2207, -98.2023, '254-555-0191', '', 110, 'offline', false ],
	[ 'Cedar Creek Cattle', 'Rotational grazing in middle Tennessee', 'Beth', 'Caldwell', '2210 Cedar Forest Rd', 'Lebanon', 'TN', '37087', 36.2081, -86.2911, '615-555-0104', 'https://example.com/cedarcreekcattle', 80, 'stripe', true ],
	[ 'Green River Grazing', 'Bred heifers and bulls that work', 'Owen', 'Pruitt', '1540 Greensburg Rd', 'Campbellsville', 'KY', '42718', 37.3434, -85.3419, '270-555-0167', '', 55, 'offline', false ],
	[ 'Nemaha Valley Farm', 'Cold-hardy South Polls on the plains', 'Karl', 'Jensen', '71220 648 Ave', 'Pawnee City', 'NE', '68420', 40.1111, -96.1539, '402-555-0139', 'https://example.com/nemahavalley', 12, 'stripe', false ],
	[ 'Middle River Pastures', 'Grass-fed beef for central Iowa', 'Anna', 'Sorensen', '2102 Hogback Bridge Rd', 'Winterset', 'IA', '50273', 41.3308, -94.0138, '515-555-0185', '', 330, 'stripe', true ],
	[ 'Cullman County Grassfed', 'Fescue-free cattle in the deep south', 'Rodney', 'Pike', '611 County Rd 1435', 'Cullman', 'AL', '35055', 34.1748, -86.8436, '256-555-0121', 'https://example.com/cullmangrassfed', 275, 'offline', false ],
	[ 'Pine Straw Cattle Co.', 'Easy-keeping cows under the pines', 'Delia', 'Thompson', '4480 Pine Tree Blvd', 'Thomasville', 'GA', '31792', 30.8366, -83.9788, '229-555-0148', '', 95, 'stripe', true ],
	[ 'Natchez Trace Farms', 'Registered South Polls since 2012', 'Harlan', 'Beasley', '1177 Attala Rd 4213', 'Kosciusko', 'MS', '39090', 33.0579, -89.5873, '662-555-0109', '', -20, 'offline', false ],
	[ 'Big Bluestem Ranch', 'New to South Polls, old hands at grass', 'Nate', 'Coleman', '2660 S 230th St', 'Pittsburg', 'KS', '66762', 37.4109, -94.7049, '620-555-0173', 'https://example.com/bigbluestem', null, 'claim', false ],
];

$total_listings = 0;
foreach ( $farm_defs as $i => [ $name, $slogan, $first, $last, $addr, $city, $state, $zip, $lat, $lng, $phone, $site, $days_left, $source, $auto ] ) {
	$uid = sp_demo_create_user( [ 'login' => 'sp-demo-' . sanitize_title( $name ), 'email' => 'sp-demo-' . sanitize_title( $name ) . '@example.test', 'first' => $first, 'last' => $last, 'phone' => $phone, 'address1' => $addr, 'city' => $city, 'state' => $state, 'zip' => $zip, 'days_ago' => 400 + $i * 23 ] );
	if ( is_wp_error( $uid ) ) { echo "! {$name}: {$uid->get_error_message()}\n"; continue; }
	$farm = sp_demo_create_farm( $uid, [ 'name' => $name, 'slogan' => $slogan, 'first' => $first, 'last' => $last, 'address1' => $addr, 'city' => $city, 'state' => $state, 'zip' => $zip, 'lat' => $lat, 'lng' => $lng, 'phone' => $phone, 'website' => $site, 'banner' => $i % 4 !== 3, 'gallery' => 3 + $i % 3, 'days_ago' => 400 + $i * 23 ], $i );
	if ( is_wp_error( $farm ) ) { echo "! {$name}: {$farm->get_error_message()}\n"; continue; }
	$fid = (int) $farm->id;
	sp_demo_membership( $fid, $uid, [ 'days_left' => $days_left, 'source' => $source, 'auto' => $auto, 'prev_years' => 1 + $i % 3 ] );
	if ( 'claim' === $source ) {
		$n = is_wp_error( sp_demo_listing( $fid, $uid, 'heifers', sp_demo_lots()['heifers'][2], $i, [ 'action' => 'draft' ] ) ) ? 0 : 1;
	} else {
		$n = sp_demo_listings( $fid, $uid, 2 + ( $i * 3 ) % 5, $i );
		if ( $days_left < 0 ) { sp_demo_expire( $fid, -$days_left ); }
	}
	$total_listings += $n;
	$state_label = 'claim' === $source ? 'pending claim' : ( $days_left < 0 ? 'expired' : ( $days_left <= 30 ? "expiring in {$days_left} days" : "active, {$days_left} days left" . ( $auto ? ', auto-renew' : '' ) ) );
	echo "+ {$name} (#{$fid}, {$city}, {$state}) — {$state_label}, {$n} listing(s) → " . $urls->farm( (string) $farms->find( $fid )->slug ) . "\n";
}
wp_set_current_user( 0 );
echo "\nCreated " . count( $farm_defs ) . " demo farms, {$total_listings} listings.\n";
echo 'Directory: ' . $urls->directory() . "\nClassifieds: " . $urls->classifieds() . "\nAdmin: " . admin_url( 'admin.php?page=southpoll-farms' ) . "\n";
