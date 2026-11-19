<?php
/**
 * lib/demo-lib.php — shared helpers for the demo seeders (seed-demo-data.php, seed-demo-accounts.php).
 *
 * Local-only tooling. Fictional farms and listings are built from COPIES (downscaled) of images already in the media
 * library — the old store logos / banners and the old classified photos — attached to the demo farm, so deleting the
 * farm deletes only the copies and never the originals. Include from a wp-run script with require_once.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Farms\FarmEditor;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Listings\ListingEditor;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Support\Dates;

require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'pre_wp_mail', '__return_true' ); // seeders never send mail

/** @template T @param class-string<T> $class @return T */
function sp_demo( string $class ): object {
	return sp_membership()->get( $class );
}

/* ------------------------------------------------------------------ media */

/** Usable library images: store logos / banners and old classified photo sets (grouped by their old listing). */
function sp_demo_media(): array {
	static $m = null;
	if ( null !== $m ) { return $m; }
	global $wpdb;
	$usable = static function ( int $att, int $min_w ): bool {
		$f = get_attached_file( $att );
		if ( ! $f || ! file_exists( $f ) || ! in_array( get_post_mime_type( $att ), [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) { return false; }
		$meta = wp_get_attachment_metadata( $att );
		return (int) ( $meta['width'] ?? 0 ) >= $min_w;
	};
	$named = []; $other = []; $banners = [];
	foreach ( $wpdb->get_results( "SELECT m.meta_key, m.meta_value AS att, a.guid FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} s ON s.ID = m.post_id AND s.post_type = 'store' JOIN {$wpdb->posts} a ON a.ID = m.meta_value AND a.post_type = 'attachment' WHERE m.meta_key IN ('logo_id','banner_id') ORDER BY a.ID DESC" ) as $r ) {
		$att = (int) $r->att;
		if ( ! $usable( $att, 200 ) ) { continue; }
		if ( 'logo_id' === $r->meta_key ) { if ( false !== stripos( basename( (string) $r->guid ), 'logo' ) ) { $named[] = $att; } else { $other[] = $att; } } else { $banners[] = $att; }
	}
	$groups = [];
	foreach ( $wpdb->get_results( "SELECT a.ID, a.post_parent, pp.post_title FROM {$wpdb->posts} a JOIN {$wpdb->posts} pp ON pp.ID = a.post_parent AND pp.post_type = 'rtcl_listing' WHERE a.post_type = 'attachment' AND a.post_mime_type LIKE 'image/%' ORDER BY a.post_parent DESC, a.ID ASC" ) as $r ) {
		if ( ! $usable( (int) $r->ID, 600 ) ) { continue; }
		$groups[ (int) $r->post_parent ]['title'] = (string) $r->post_title;
		$groups[ (int) $r->post_parent ]['ids'][] = (int) $r->ID;
	}
	$kind_of = static function ( string $title ): string {
		$t = strtolower( $title );
		if ( preg_match( '/semen|straw|embryo/', $t ) ) { return 'semen'; }
		if ( preg_match( '/bull/', $t ) ) { return 'bulls'; }
		if ( preg_match( '/steer|beef|finish|freezer|calf|calves|wean|feeder/', $t ) ) { return 'steers'; }
		if ( preg_match( '/heifer/', $t ) ) { return 'heifers'; }
		if ( preg_match( '/cow|pair|bred|female/', $t ) ) { return 'cows'; }
		return 'any';
	};
	$pools = [ 'bulls' => [], 'cows' => [], 'heifers' => [], 'steers' => [], 'semen' => [], 'any' => [] ];
	foreach ( $groups as $g ) { $pools[ $kind_of( $g['title'] ) ][] = $g['ids']; }
	$m = [
		'logos'   => array_values( array_unique( array_merge( $named, $other ) ) ),
		'banners' => array_values( array_unique( $banners ) ),
		'groups'  => array_values( array_map( static fn( $g ) => $g['ids'], $groups ) ),
		'pools'   => $pools,
	];
	return $m;
}

/** A photo set for a listing kind (bulls|cows|heifers|steers|semen|any); sets are not reused until exhausted. */
function sp_demo_take_group( string $kind ): array {
	static $pools = null; static $used = [];
	if ( null === $pools ) { $pools = sp_demo_media()['pools']; }
	foreach ( [ $kind, 'any', 'cows', 'bulls' ] as $k ) {
		while ( ! empty( $pools[ $k ] ) ) {
			$g = array_shift( $pools[ $k ] );
			if ( ! in_array( $g[0], $used, true ) ) { $used[] = $g[0]; return $g; }
		}
	}
	$all = sp_demo_media()['groups'];
	return $all[ mt_rand( 0, count( $all ) - 1 ) ];
}

function sp_demo_take_logo(): int {
	static $i = 0;
	$l = sp_demo_media()['logos'];
	return $l[ $i++ % count( $l ) ];
}

function sp_demo_take_banner(): int {
	static $i = 0;
	$b = sp_demo_media()['banners'];
	if ( $b && $i < count( $b ) && mt_rand( 1, 3 ) > 1 ) { return $b[ $i++ ]; }
	$all = sp_demo_media()['groups'];
	return $all[ mt_rand( 0, count( $all ) - 1 ) ][0];
}

/** Copy a library image (downscaled) into a plugin-owned attachment for a demo farm. */
function sp_demo_copy( int $att, int $farm_id, int $user_id, string $purpose, int $max = 1600 ): ?int {
	$src = get_attached_file( $att );
	if ( ! $src || ! file_exists( $src ) ) { return null; }
	$info = @getimagesize( $src ); // phpcs:ignore
	if ( ! $info ) { return null; }
	[ $w, $h ] = $info;
	$png = IMAGETYPE_PNG === $info[2];
	$ext = $png ? 'png' : 'jpg';
	$tmp = tempnam( sys_get_temp_dir(), 'spdemo' ) . '.' . $ext;
	$im  = @imagecreatefromstring( (string) file_get_contents( $src ) ); // phpcs:ignore
	if ( ! $im ) { return null; }
	$scale = min( 1, $max / max( $w, $h ) );
	if ( $scale < 1 ) {
		$nw = (int) round( $w * $scale ); $nh = (int) round( $h * $scale );
		$out = imagecreatetruecolor( $nw, $nh );
		if ( $png ) { imagealphablending( $out, false ); imagesavealpha( $out, true ); }
		imagecopyresampled( $out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $im ); $im = $out;
	}
	$png ? imagepng( $im, $tmp, 6 ) : imagejpeg( $im, $tmp, 82 );
	imagedestroy( $im );
	$id = sp_demo( MediaUploader::class )->store( [ 'name' => 'demo.' . $ext, 'type' => $png ? 'image/png' : 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ], $farm_id, $user_id, $purpose );
	if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore
	return is_wp_error( $id ) ? null : $id;
}

/* ---------------------------------------------------------------- content */

/** Listing templates per category: [ title, price dollars|null, price label, status, copy ]. */
function sp_demo_lots(): array {
	return [
		'bulls'     => [
			[ 'Registered yearling bull, calving-ease pedigree', 3200, '', 'available', 'Moderate-framed yearling out of a 12-year-old cow that has never missed. Slick-haired, docile, raised on fescue and clover with no grain. Papers transfer with the sale.' ],
			[ 'Proven 4-year-old herd sire', 4500, 'OBO', 'available', 'Four seasons of calves on the ground, all unassisted. Semen tested in the spring, sound on his feet, and easy to move with a single person. Selling only because we are keeping daughters.' ],
			[ 'Two coming-two bulls, semen tested', 2800, 'per head', 'coming_soon', 'Half brothers that have wintered together on stockpiled grass. Both will be semen tested and ready to turn out by the end of April.' ],
			[ 'Fullblood bull, gentle disposition', 3800, '', 'sold', 'Thick, deep-bodied fullblood bull that walks through the herd like a pet. Great for a first-time South Poll buyer.' ],
			[ 'Pair of fall yearling bulls', 2600, 'per head', 'available', 'Two fall-born bulls that are ready to work heifers. Both are out of cows in our top third for weaning weight on grass alone.' ],
		],
		'cows'      => [
			[ 'Six bred cows, due in March', 2600, 'per head', 'available', 'Six 4- to 7-year-old cows bred to a low-birth-weight South Poll bull. All have raised a calf every year on grass only and are checked safe in calf.' ],
			[ 'Cow/calf pairs, 3 available', 3100, 'per pair', 'pending', 'Three young cows with heifer calves at side. A deposit has been received; back-up buyers are welcome in case the sale falls through.' ],
			[ 'Mature cows, pasture exposed', 2200, 'per head', 'available', 'A set of mature cows exposed to our herd bull since May. Big-bodied, easy fleshing cows that hold condition through winter on hay alone.' ],
		],
		'heifers'   => [
			[ 'Open yearling heifers, group of 8', 1900, 'per head', 'available', 'Eight heifers from our spring calf crop, weaned on grass and kept in a single group. Slick coats, good udders behind them, ready to breed this summer.' ],
			[ 'Fullblood heifer calves, weaned', 1500, 'per head', 'coming_soon', 'Weaned fullblood heifer calves, vaccinated and bunk broke. Available once they reach 550 lb around early spring.' ],
			[ 'Bred heifers, fall calvers', 2400, 'per head', 'available', 'Bred to calve in September and October. Developed on grass with free-choice minerals, no grain, and handled on foot.' ],
			[ 'Feeder heifers, group of 20', 1350, 'per head', 'pending', 'Twenty feeder heifers out of our commercial South Poll cross cows. Sold as a group; the buyer is arranging trucking.' ],
			[ 'Heifer calves, 450 to 550 lb', 1250, 'per head', 'available', 'Heifer calves out of our top cows, all sired by registered South Poll bulls. Would make a nice start to a grass-based herd.' ],
		],
		'steers'    => [
			[ 'Grass-finished freezer beef, halves and wholes', null, 'Call for price', 'available', 'Finished on grass and legumes only, 24 to 28 months of age. Processing dates are booked monthly at a USDA plant; we deliver within 60 miles.' ],
			[ 'Finished steers ready for processing', 2100, 'per head', 'available', 'Four steers at 1,150 to 1,250 lb finished on stockpiled fescue and brassicas. Ideal for a family or a small butcher shop.' ],
			[ 'Feeder steers, 650 lb average', 1400, 'per head', 'available', 'Twelve feeder steers weaned 60 days, vaccinated twice and trained to a hot wire. Uniform set that will finish well on grass.' ],
			[ 'Weaned steer calves, lot of 12', 1150, 'per head', 'available', 'Twelve weaned steer calves, 500 to 600 lb, fence-line weaned and eating hay. Can be split into two lots.' ],
		],
		'semen'     => [
			[ 'Semen straws from our herd sire', 45, 'per straw', 'available', 'Conventional semen from a moderate-framed, high-fertility sire with daughters in production. Stored at the local AI service; shipping available in a tank.' ],
			[ 'Embryos from our foundation cow', 650, 'per embryo', 'coming_soon', 'Frozen embryos from a 14-year-old foundation cow mated to a proven calving-ease bull. Available after the spring flush.' ],
		],
	];
}

function sp_demo_captions(): array {
	return [ 'Spring pasture', 'Moving to fresh grass', 'Evening in the back paddock', 'Fall stockpile', 'The home place', 'Water and shade in July', 'Weaning day', 'Winter on hay' ];
}

function sp_demo_about( string $name, string $city, string $state, string $owner ): string {
	return '<p>' . esc_html( $name ) . ' is a family operation outside ' . esc_html( $city ) . ', ' . esc_html( $state ) . ', run by ' . esc_html( $owner ) . '. We moved to South Poll cattle because we wanted moderate cows that breed back on grass, shed their winter coat early and stay out of the pond in July.</p><p>The herd is rotationally grazed through the growing season and wintered on stockpiled fescue and hay. Calves are weaned on grass, handled on foot and sold as breeding stock, feeders or grass-finished beef depending on the year. Visitors are welcome by appointment; call ahead and we will walk the pastures with you.</p>';
}

/** Plausible values for admin-defined meta fields (text / textarea / select / bool). */
function sp_demo_fill_fields( array $fields, int $seed ): array {
	$out = [];
	foreach ( $fields as $f ) {
		$type = (string) $f->type;
		$opts = is_array( $f->options ?? null ) ? array_values( array_map( 'strval', $f->options ) ) : array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|\|/', (string) ( $f->options ?? '' ) ) ?: [] ) ) );
		$out[ (string) $f->field_key ] = match ( true ) {
			'select' === $type && $opts  => $opts[ $seed % count( $opts ) ],
			'bool' === $type             => '1',
			'textarea' === $type         => 'Rotationally grazed, no grain, handled on foot.',
			default                      => (string) ( 20 + ( $seed * 7 ) % 120 ),
		};
	}
	return $out;
}

/* --------------------------------------------------------------- accounts */

/**
 * Create a verified member account. Keys: login, email, password, first, last, phone, address1, city, state, zip, days_ago.
 */
function sp_demo_create_user( array $u ): int|WP_Error {
	$now = Dates::now();
	$uid = wp_insert_user( [
		'user_login'      => (string) $u['login'],
		'user_email'      => (string) $u['email'],
		'user_pass'       => (string) ( $u['password'] ?? wp_generate_password( 24 ) ),
		'role'            => Capabilities::ROLE_BUYER,
		'first_name'      => (string) $u['first'],
		'last_name'       => (string) $u['last'],
		'display_name'    => trim( $u['first'] . ' ' . $u['last'] ),
		'user_registered' => Dates::add_days( $now, -(int) ( $u['days_ago'] ?? 30 ) ),
	] );
	if ( is_wp_error( $uid ) ) { return $uid; }
	$members = sp_demo( MemberRepository::class );
	$m = $members->create_for_user( (int) $uid, [ 'first_name' => $u['first'], 'last_name' => $u['last'], 'phone' => (string) ( $u['phone'] ?? '' ), 'address1' => (string) ( $u['address1'] ?? '' ), 'city' => (string) ( $u['city'] ?? '' ), 'state' => (string) ( $u['state'] ?? '' ), 'zip' => (string) ( $u['zip'] ?? '' ) ] );
	$members->update( (int) $m->id, [ 'email_verified_at' => Dates::add_days( $now, -(int) ( $u['days_ago'] ?? 30 ) ), 'last_seen_at' => Dates::add_days( $now, -mt_rand( 0, 10 ) ), 'status' => 'active', 'notify_all' => 1 ] );
	return (int) $uid;
}

/** Remove an account and its plugin rows (farm rows must already be gone, see sp_demo_delete_farm). */
function sp_demo_delete_user( int $uid ): void {
	global $wpdb;
	$p = $wpdb->prefix;
	foreach ( [ 'sp_favorites', 'sp_saved_searches', 'sp_notification_queue', 'sp_farm_users' ] as $t ) { $wpdb->delete( $p . $t, [ 'user_id' => $uid ] ); }
	wp_set_current_user( 0 ); sp_demo( ContextProvider::class )->forget();
	wp_delete_user( $uid );
}

/* ------------------------------------------------------------------ farms */

/**
 * Create a complete farm for $uid. Def keys: name, slogan, first, last, address1, city, state, zip, lat, lng, phone,
 * website, breeder (optional), banner (false to skip), gallery (count), days_ago (created). Returns the farm row.
 */
function sp_demo_create_farm( int $uid, array $def, int $seed ): object {
	$farms   = sp_demo( FarmRepository::class );
	$editor  = sp_demo( FarmEditor::class );
	$fmedia  = sp_demo( FarmMediaRepository::class );
	$offers  = sp_demo( Offerings::class );
	$now     = Dates::now();
	$owner   = trim( $def['first'] . ' ' . $def['last'] );
	$breeder = (string) ( $def['breeder'] ?? 'SP-' . ( 1100 + $seed * 37 ) );
	$farm    = sp_demo( FarmService::class )->create_for_owner( $uid, (string) $def['name'], [ 'state' => (string) $def['state'], 'breeder_number' => $breeder ] );
	if ( is_wp_error( $farm ) ) { return $farm; }
	$fid  = (int) $farm->id;
	$all  = array_map( static fn( $o ) => $o->slug, $offers->all() );
	$offs = [];
	for ( $k = 0; $k < 2 + $seed % 3 && $all; $k++ ) { $offs[] = $all[ ( $seed + $k * 3 ) % count( $all ) ]; }
	$social = [];
	if ( $seed % 2 ) { $social['facebook'] = 'https://www.facebook.com/' . sanitize_title( (string) $def['name'] ); }
	if ( $seed % 3 ) { $social['instagram'] = 'https://www.instagram.com/' . str_replace( '-', '', sanitize_title( (string) $def['name'] ) ); }
	$hours = [];
	if ( $seed % 2 || ! empty( $def['hours'] ) ) { foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ] as $d ) { $hours[ $d ] = [ 'open' => '1', 'from' => '08:00', 'to' => 'sat' === $d ? '12:00' : '17:00' ]; } $hours['sun'] = [ 'open' => '', 'from' => '', 'to' => '' ]; }
	$res = $editor->save_farm( $fid, [
		'slogan' => (string) $def['slogan'], 'owner_name' => $owner, 'breeder_number' => $breeder, 'address1' => (string) $def['address1'], 'city' => (string) $def['city'], 'state' => (string) $def['state'], 'zip' => (string) $def['zip'],
		'phone' => (string) $def['phone'], 'email' => (string) ( $def['email'] ?? get_userdata( $uid )->user_email ), 'about' => (string) ( $def['about'] ?? sp_demo_about( (string) $def['name'], (string) $def['city'], (string) $def['state'], $owner ) ), 'social' => $social, 'hours' => $hours,
		'offerings' => array_values( array_unique( $offs ) ), 'contact_form_enabled' => '1', '_contact_form_submitted' => 1, 'meta' => sp_demo_fill_fields( sp_demo( FarmMetaRepository::class )->fields(), $seed ),
	], true );
	if ( is_wp_error( $res ) ) { echo "  ! {$def['name']} fields: {$res->get_error_message()}\n"; }
	if ( ! empty( $def['website'] ) ) { // saved separately: WordPress validates website hosts with a DNS lookup, which fails offline
		$res = $editor->save_farm( $fid, [ 'website' => (string) $def['website'] ], true );
		if ( is_wp_error( $res ) ) { echo "  (website skipped: {$res->get_error_message()})\n"; }
	}
	$farms->set_geo( $fid, (float) $def['lat'], (float) $def['lng'] );
	$farms->update( $fid, [ 'created_at' => Dates::add_days( $now, -(int) ( $def['days_ago'] ?? 400 + $seed * 23 ) ) ] );
	$logo = sp_demo_copy( sp_demo_take_logo(), $fid, $uid, 'logo', 800 );
	if ( $logo ) { $farms->set_image( $fid, 'logo', $logo ); }
	if ( ( $def['banner'] ?? true ) !== false ) { $banner = sp_demo_copy( sp_demo_take_banner(), $fid, $uid, 'banner', 1800 ); if ( $banner ) { $farms->set_image( $fid, 'banner', $banner ); } }
	$count = (int) ( $def['gallery'] ?? 3 );
	$src   = sp_demo_take_group( 'any' );
	while ( count( $src ) < $count ) { $src = array_merge( $src, sp_demo_take_group( 'any' ) ); }
	$caps = sp_demo_captions();
	foreach ( array_slice( $src, 0, $count ) as $gi => $att ) { $g = sp_demo_copy( $att, $fid, $uid, 'gallery' ); if ( $g ) { $fmedia->add_image( $fid, $g, $caps[ ( $seed + $gi ) % count( $caps ) ] ); } }
	sp_demo( ContextProvider::class )->forget();
	return $farms->find( $fid );
}

/**
 * Give the farm a membership. Keys: days_left (int; negative = lapsed, use sp_demo_expire() after adding listings),
 * source (stripe|offline|claim), auto (bool), prev_years (int). 'claim' submits an offline claim instead (pending).
 */
function sp_demo_membership( int $farm_id, int $uid, array $m ): void {
	$mrepo = sp_demo( MembershipRepository::class );
	$pay   = sp_demo( PaymentRepository::class );
	$tier  = sp_demo( TierRepository::class )->default();
	$now   = Dates::now();
	wp_set_current_user( $uid ); sp_demo( ContextProvider::class )->forget();
	if ( 'claim' === $m['source'] ) {
		$claim = sp_demo( OfflineClaims::class )->submit( sp_demo( ContextProvider::class )->for_user( $uid ), $tier, [ 'method' => 'check', 'note' => 'Mailed a check on Monday', 'paid_on' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ) ] );
		if ( is_wp_error( $claim ) ) { echo "  ! claim: {$claim->get_error_message()}\n"; }
		return;
	}
	$days_left = (int) $m['days_left'];
	$source    = (string) $m['source'];
	$auto      = ! empty( $m['auto'] );
	$paid_at   = Dates::add_days( $now, ( $days_left < 0 ? 5 : $days_left ) - (int) $tier->duration_days ); // lapsed farms activate briefly so listings can publish
	$ms        = $mrepo->ensure( $farm_id );
	for ( $y = (int) ( $m['prev_years'] ?? 1 ); $y >= 1; $y-- ) {
		$ts = Dates::add_days( $paid_at, -365 * $y );
		$pay->create( [ 'membership_id' => (int) $ms->id, 'farm_id' => $farm_id, 'user_id' => $uid, 'tier_id' => (int) $tier->id, 'amount_cents' => (int) $tier->price_cents, 'currency' => (string) $tier->currency, 'method' => 'stripe' === $source ? 'stripe' : ( $y % 2 ? 'check' : 'cash' ), 'status' => 'succeeded', 'recurring' => (int) ( 'stripe' === $source && $auto ), 'paid_at' => $ts, 'term_start' => $ts, 'term_end' => Dates::add_days( $ts, 365 ), 'stripe_payment_intent_id' => 'stripe' === $source ? 'pi_demo_' . $farm_id . '_' . $y : '', 'notes' => 'Demo data: previous term' ] );
	}
	sp_demo( StatusEngine::class )->activate( $farm_id, $tier, [ 'source' => $source, 'auto_renew' => $auto, 'paid_at' => $paid_at, 'actor' => $uid, 'note' => 'Demo data', 'stripe_customer_id' => 'stripe' === $source ? 'cus_demo_' . $farm_id : '', 'stripe_subscription_id' => 'stripe' === $source && $auto ? 'sub_demo_' . $farm_id : '' ] );
	$pay->create( [ 'membership_id' => (int) $ms->id, 'farm_id' => $farm_id, 'user_id' => $uid, 'tier_id' => (int) $tier->id, 'amount_cents' => (int) $tier->price_cents, 'currency' => (string) $tier->currency, 'method' => 'stripe' === $source ? 'stripe' : 'check', 'status' => 'succeeded', 'recurring' => (int) ( 'stripe' === $source && $auto ), 'paid_at' => $paid_at, 'term_start' => $paid_at, 'term_end' => Dates::add_days( $paid_at, (int) $tier->duration_days ), 'stripe_payment_intent_id' => 'stripe' === $source ? 'pi_demo_' . $farm_id . '_0' : '', 'notes' => 'Demo data: current term' ] );
	if ( $days_left > 0 && $days_left <= 30 ) { $mrepo->update( (int) $ms->id, [ 'reminders_sent' => [ 'before_30' ] ] ); }
	sp_demo( ContextProvider::class )->forget();
}

/** Lapsed farm: backdate the term so it ended $days_ago days ago, then expire it (hides the farm + its listings). */
function sp_demo_expire( int $farm_id, int $days_ago ): void {
	global $wpdb;
	$mrepo = sp_demo( MembershipRepository::class );
	$tier  = sp_demo( TierRepository::class )->default();
	$now   = Dates::now();
	$real  = Dates::add_days( $now, -$days_ago - (int) $tier->duration_days );
	$mrepo->update( (int) $mrepo->for_farm( $farm_id )->id, [ 'starts_at' => $real, 'ends_at' => Dates::add_days( $now, -$days_ago ), 'activated_at' => $real ] );
	$wpdb->update( "{$wpdb->prefix}sp_payments", [ 'paid_at' => $real, 'term_start' => $real, 'term_end' => Dates::add_days( $now, -$days_ago ) ], [ 'farm_id' => $farm_id, 'notes' => 'Demo data: current term' ] );
	sp_demo( StatusEngine::class )->expire( $mrepo->for_farm( $farm_id ), 'Demo data: term ended' );
	sp_demo( ContextProvider::class )->forget();
}

/* --------------------------------------------------------------- listings */

/**
 * One listing with a featured photo + gallery. $lot = [ title, price|null, label, status, copy ].
 * Opts: action (publish|draft), days_ago (published), expire (bool), photos (int, default 4), category (slug).
 */
function sp_demo_listing( int $farm_id, int $uid, string $cat, array $lot, int $seed, array $opts = [] ): object {
	$lrepo  = sp_demo( ListingRepository::class );
	$lsvc   = sp_demo( ListingService::class );
	$types  = sp_demo( ListingTypes::class );
	$fields = sp_demo( MetaFieldRepository::class );
	$ctxs   = sp_demo( ContextProvider::class );
	wp_set_current_user( $uid ); $ctxs->forget();
	$ctx  = $ctxs->for_user( $uid );
	$term = $types->category( $cat );
	if ( ! $term ) { return new WP_Error( 'sp_demo_cat', "Unknown category $cat" ); }
	[ $title, $price, $label, $status, $copy ] = $lot;
	$draft = $lsvc->create_draft( $ctx, [ 'title' => $title ] );
	if ( is_wp_error( $draft ) ) { return $draft; }
	$photos  = sp_demo_take_group( $cat );
	$want    = (int) ( $opts['photos'] ?? 4 );
	$feat    = sp_demo_copy( $photos[0], $farm_id, $uid, 'listing' );
	$gallery = [];
	if ( $feat ) { update_post_meta( $feat, '_sp_listing_id', $draft->id ); }
	$caps = sp_demo_captions();
	foreach ( array_slice( $photos, 1, max( 0, $want - 1 ) ) as $gi => $att ) {
		$g = sp_demo_copy( $att, $farm_id, $uid, 'listing' );
		if ( $g ) { update_post_meta( $g, '_sp_listing_id', $draft->id ); $gallery[] = [ 'key' => 'g' . $gi, 'type' => 'image', 'id' => $g, 'url' => '', 'caption' => $gi ? '' : $caps[ $seed % count( $caps ) ] ]; }
	}
	$lrepo->update( $draft->id, [ 'gallery' => $gallery, 'featured_id' => $feat ] );
	$meta = [];
	foreach ( $fields->groups_for_categories( [ (int) $term->id ] ) as $group ) { $meta += sp_demo_fill_fields( $fields->fields_for_group( (int) $group->id ), $seed ); }
	$action = ( $opts['action'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish';
	$res    = sp_demo( ListingEditor::class )->save( $ctx, $lrepo->find( $draft->id ), [ 'title' => $title, 'excerpt' => '', 'content' => '<p>' . $copy . '</p>', 'price' => null === $price ? '' : (string) $price, 'price_label' => $label, 'categories' => [ (int) $term->id ], 'status' => 'draft' === $action ? 'inactive' : $status, 'meta' => $meta ], $action );
	if ( is_wp_error( $res ) ) { return $res; }
	$days_ago = (int) ( $opts['days_ago'] ?? 0 );
	if ( 'publish' === $action ) {
		$pub = Dates::add_days( Dates::now(), -$days_ago );
		$lrepo->update( $res->id, [ 'published_at' => $pub, 'post_date' => $pub, 'expires_at' => Dates::add_days( $pub, (int) $lsvc->duration_days() ) ] );
		if ( 'sold' === $status ) { $lrepo->update( $res->id, [ 'sold_at' => Dates::add_days( Dates::now(), -( 1 + $seed % 9 ) ) ] ); }
	}
	if ( ! empty( $opts['expire'] ) ) { $lsvc->expire( $lrepo->find( $res->id ) ); }
	update_post_meta( $res->id, '_sp_view_count', mt_rand( 4, 260 ) );
	return $lrepo->find( $res->id );
}

/** $count varied listings for a farm (categories/lots chosen from $seed); every 9th is a draft, every 11th expired. */
function sp_demo_listings( int $farm_id, int $uid, int $count, int $seed ): int {
	$lots   = sp_demo_lots();
	$slots  = array_keys( $lots );
	$picked = [];
	$n      = 0;
	for ( $k = 0; $k < $count; $k++ ) {
		$cat = $slots[ ( $seed + $k * 2 ) % count( $slots ) ];
		$lot = $lots[ $cat ][ ( $seed + $k ) % count( $lots[ $cat ] ) ];
		if ( isset( $picked[ $cat . $lot[0] ] ) ) { continue; }
		$picked[ $cat . $lot[0] ] = true;
		$res = sp_demo_listing( $farm_id, $uid, $cat, $lot, $seed + $k, [ 'action' => ( $seed + $k ) % 9 === 8 ? 'draft' : 'publish', 'days_ago' => ( $seed * 7 + $k * 11 ) % 46, 'expire' => ( $seed + $k ) % 11 === 10 ] );
		if ( is_wp_error( $res ) ) { echo "  ! {$lot[0]}: {$res->get_error_message()}\n"; continue; }
		$n++;
	}
	sp_demo( ListingService::class )->refresh_farm_stats( $farm_id );
	return $n;
}

/* ---------------------------------------------------------------- delete */

/** Remove a demo farm: listings (+ their photos), gallery, logo/banner, memberships, payments, favorites, rows. */
function sp_demo_delete_farm( int $farm_id, int $uid ): array {
	global $wpdb;
	$p     = $wpdb->prefix;
	$lrepo = sp_demo( ListingRepository::class );
	$lsvc  = sp_demo( ListingService::class );
	$ctxs  = sp_demo( ContextProvider::class );
	$media = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_sp_farm_id' AND meta_value = %d", $farm_id ) );
	wp_set_current_user( $uid ); $ctxs->forget();
	$ctx = $ctxs->for_user( $uid );
	$n   = 0;
	foreach ( $lrepo->for_farm( $farm_id ) as $l ) { $lsvc->delete( $ctx, $l ); $n++; }
	foreach ( sp_demo( FarmMediaRepository::class )->for_farm( $farm_id ) as $row ) { sp_demo( FarmMediaRepository::class )->delete( (int) $row->id ); }
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sp_farm_id' AND meta_value = %d", $farm_id ) ) as $att ) { wp_delete_attachment( (int) $att, true ); }
	wp_delete_object_term_relationships( $farm_id, Offerings::TAXONOMY );
	foreach ( [ 'sp_farm_media', 'sp_farm_meta', 'sp_farm_users', 'sp_memberships', 'sp_payments', 'sp_inquiries' ] as $t ) { $wpdb->delete( $p . $t, [ 'farm_id' => $farm_id ] ); }
	$wpdb->delete( "{$p}sp_favorites", [ 'object_type' => 'farm', 'object_id' => $farm_id ] );
	$wpdb->delete( "{$p}sp_farms", [ 'id' => $farm_id ] );
	wp_set_current_user( 0 ); $ctxs->forget();
	return [ 'listings' => $n, 'media' => $media ];
}
