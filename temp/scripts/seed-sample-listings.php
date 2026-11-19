<?php
/**
 * seed-sample-listings.php — Create (or remove) a handful of sample classified listings on the newest test owner's farm
 * so the classifieds, listing pages, My Listings and favorites can be browsed on Local. Titles start with "Sample:".
 *
 * Run: ./wp-run.sh seed-sample-listings.php            → creates 6 listings (skips if samples already exist)
 *      ./wp-run.sh seed-sample-listings.php --delete   → removes every "Sample:" listing on that farm
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\ListingEditor;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Support\Dates;

global $wpdb;
$c      = sp_membership();
$owner  = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
if ( ! $owner ) { die( "No test owner found; run test-phase1-auth.php first.\n" ); }
$farms  = $c->get( FarmRepository::class );
$farm   = $farms->find_by_owner( $owner );
$repo   = $c->get( ListingRepository::class );
$svc    = $c->get( ListingService::class );
$editor = $c->get( ListingEditor::class );
$types  = $c->get( ListingTypes::class );
$ctxs   = $c->get( ContextProvider::class );
$mrepo  = $c->get( MembershipRepository::class );
$delete = in_array( '--delete', $argv, true );

wp_set_current_user( $owner );
$ctxs->forget();
$ctx = $ctxs->for_user( $owner );
$existing = array_values( array_filter( $repo->for_farm( (int) $farm->id ), fn( $l ) => str_starts_with( $l->title, 'Sample:' ) ) );

if ( $delete ) {
	foreach ( $existing as $l ) { $svc->delete( $ctx, $l ); }
	echo 'Deleted ' . count( $existing ) . " sample listing(s) from farm #{$farm->id}.\n";
	exit( 0 );
}
if ( $existing ) { die( count( $existing ) . " sample listing(s) already exist on farm #{$farm->id}; pass --delete first to recreate.\n" ); }

// Make sure the farm can publish.
$mrepo->update( (int) $mrepo->ensure( (int) $farm->id )->id, [ 'status' => 'active', 'starts_at' => Dates::now(), 'ends_at' => Dates::add_days( Dates::now(), 365 ), 'expired_at' => null ] );
if ( '' === (string) $farm->city ) { $farms->update( (int) $farm->id, [ 'city' => 'Kearney', 'state' => 'MO', 'zip' => '64060' ] ); }
if ( null === $farm->lat ) { $farms->set_geo( (int) $farm->id, 39.3639, -94.3622 ); }
$ctxs->forget(); $ctx = $ctxs->for_user( $owner );

$cats = []; foreach ( $types->categories() as $cat ) { $cats[ $cat->slug ] = $cat->id; }
$image = function ( int $r, int $g, int $b, string $label ): int|WP_Error {
	$path = tempnam( sys_get_temp_dir(), 'sp' ) . '.png';
	$im   = imagecreatetruecolor( 1200, 900 );
	imagefilledrectangle( $im, 0, 0, 1200, 900, imagecolorallocate( $im, $r, $g, $b ) );
	imagefilledellipse( $im, 600, 500, 700, 420, imagecolorallocate( $im, max( 0, $r - 40 ), max( 0, $g - 40 ), max( 0, $b - 40 ) ) );
	imagestring( $im, 5, 40, 40, $label, imagecolorallocate( $im, 255, 255, 255 ) );
	imagepng( $im, $path ); imagedestroy( $im );
	return sp_membership()->get( \SouthPoll\Membership\Farms\MediaUploader::class )->store( [ 'name' => 'sample.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ], (int) $GLOBALS['farm']->id, get_current_user_id(), 'listing' );
};

$samples = [
	[ 'Sample: Registered South Poll herd bull, 3 yrs', 'bulls', 3500, 'OBO', 'available', 'Proven calving-ease sire out of a 10-year-old cow. Gentle, grass-finished genetics, never fed grain. Semen tested, ready to work.', [ 120, 60, 40 ] ],
	[ 'Sample: Six bred heifers, due March', 'heifers', 2400, 'per head', 'available', 'Six South Poll heifers bred to a low-birth-weight bull. Rotationally grazed on fescue and clover, no hay fed until January.', [ 90, 110, 60 ] ],
	[ 'Sample: Pair of open yearling heifers', 'heifers', 1800, '', 'coming_soon', 'Two slick-haired yearlings from our best cow family. Available after weaning in early spring.', [ 80, 90, 120 ] ],
	[ 'Sample: Grass-finished freezer beef, whole or half', 'steers', null, 'Call for price', 'available', 'Finished on grass only, 24–28 months. Processing dates available monthly at the local USDA plant.', [ 140, 90, 50 ] ],
	[ 'Sample: Weaned calves, lot of 10', 'steers', 1250, 'per head', 'pending', 'Ten weaned calves, 500–600 lb, vaccinated and bunk broke. Deposit received; back-up buyers welcome.', [ 100, 100, 80 ] ],
	[ 'Sample: Straws of semen — SP Big River', 'semen', 40, 'per straw', 'available', 'Conventional semen from a moderate-framed, high-fertility sire. Stored at the local AI service; shipping available.', [ 60, 80, 110 ] ],
];
$n = 0;
foreach ( $samples as $i => [ $title, $slug, $price, $label, $status, $copy, $rgb ] ) {
	$draft = $svc->create_draft( $ctx, [ 'title' => $title ] );
	if ( is_wp_error( $draft ) ) { echo "Could not create: {$draft->get_error_message()}\n"; continue; }
	$img = $image( $rgb[0], $rgb[1], $rgb[2], $title );
	if ( ! is_wp_error( $img ) ) { update_post_meta( $img, '_sp_listing_id', $draft->id ); }
	$gallery = [];
	$extra   = $image( $rgb[2], $rgb[0], $rgb[1], 'Pasture view' );
	if ( ! is_wp_error( $extra ) ) { update_post_meta( $extra, '_sp_listing_id', $draft->id ); $gallery[] = [ 'key' => 'g' . $i, 'type' => 'image', 'id' => $extra, 'url' => '', 'caption' => 'Spring pasture' ]; }
	if ( 0 === $i ) { $gallery[] = [ 'key' => 'v' . $i, 'type' => 'video', 'id' => 0, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Walking video' ]; }
	$repo->update( $draft->id, [ 'gallery' => $gallery, 'featured_id' => is_wp_error( $img ) ? null : $img ] );
	$res = $editor->save( $ctx, $repo->find( $draft->id ), [ 'title' => $title, 'excerpt' => '', 'content' => '<p>' . $copy . '</p>', 'price' => null === $price ? '' : (string) $price, 'price_label' => $label, 'categories' => [ $cats[ $slug ] ], 'status' => $status ], 'publish' );
	if ( is_wp_error( $res ) ) { echo "Publish failed for '{$title}': {$res->get_error_message()}\n"; continue; }
	// Spread publish dates so "published X days ago" and the "new" badge vary.
	$days_ago = [ 0, 1, 3, 9, 15, 30 ][ $i ] ?? 0;
	if ( $days_ago ) { $repo->update( $res->id, [ 'published_at' => Dates::add_days( Dates::now(), -$days_ago ), 'post_date' => Dates::add_days( Dates::now(), -$days_ago ), 'expires_at' => Dates::add_days( Dates::now(), 60 - $days_ago ) ] ); }
	update_post_meta( $res->id, '_sp_view_count', wp_rand( 3, 140 ) );
	$n++;
	echo "+ {$title} → " . $c->get( \SouthPoll\Membership\Routing\Urls::class )->listing( $res->slug ) . "\n";
}
$svc->refresh_farm_stats( (int) $farm->id );
echo "Created {$n} sample listing(s) on farm #{$farm->id} ('{$farm->name}'). Browse: " . $c->get( \SouthPoll\Membership\Routing\Urls::class )->classifieds() . "\n";
