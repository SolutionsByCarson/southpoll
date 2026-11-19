<?php
/**
 * sync-registry-terms.php — S14: make the website's membership terms follow the SPGCA registry export (the office's
 * MemAddressExp CSVs: memidord = breeder number, regname, farm_name, first_name, last_name, address, phone1, email,
 * mem_sts AA/JR, member_num, exp_date m/d/Y — calendar-year terms).
 *
 * For every CSV row:
 *   1. match an account / farm — e-mail (user e-mail, then farm contact e-mail) → breeder number (farm.breeder_number or the
 *      user meta southpoll_breeder_number) → unique first+last name → unique farm name;
 *   2. make sure the account has a farm (a minimal farm is created for an owner-less account);
 *   3. set the membership term: ends_at = exp_date 23:59:59 site time, starts_at = Jan 1 of the expiry year when empty,
 *      status active / expired by date, tier = default when empty, source = registry, note "Registry export <file> <date>";
 *      auto-renew, Stripe ids and price are never touched. Rows already at that end date are left alone (idempotent);
 *   4. write the breeder number (memidord) to the farm when it has none; JR rows get farm meta membership_class = junior.
 * Unmatched rows go to temp/migration/reports/registry-unmatched-<stamp>.csv for the office. With --create-missing, unmatched
 * rows that have an e-mail get a minimal account (role sp_buyer, unverified, no e-mail sent) + farm + membership.
 *
 * No membership events are fired (the registry digest would otherwise list every synced member as ADD); the digest picks up
 * changes from here on. Report: temp/migration/reports/registry-sync-<stamp>.md.
 *
 * Run:  ./wp-run.sh sync-registry-terms.php [--file=…]… [--dry-run] [--create-missing] [--limit=N]
 *       (default files: temp/migration/registry/MemAddressExp(32).csv and MemAddressExp(33) (1).csv)
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Support\Dates;

global $wpdb;
$p     = $wpdb->prefix;
$args  = $argv ?? [];
$dry   = in_array( '--dry-run', $args, true );
$make  = in_array( '--create-missing', $args, true );
$limit = 0;
$files = [];
foreach ( $args as $a ) {
	if ( str_starts_with( $a, '--file=' ) ) { $files[] = substr( $a, 7 ); }
	if ( str_starts_with( $a, '--limit=' ) ) { $limit = (int) substr( $a, 8 ); }
}
if ( ! $files ) {
	$files = [ dirname( __DIR__ ) . '/migration/registry/MemAddressExp(32).csv', dirname( __DIR__ ) . '/migration/registry/MemAddressExp(33) (1).csv' ];
}
$c      = sp_membership();
$farms  = $c->get( FarmRepository::class );
$fsvc   = $c->get( FarmService::class );
$mrepo  = $c->get( MembershipRepository::class );
$member = $c->get( MemberRepository::class );
$tier   = $c->get( TierRepository::class )->default();
$tz     = wp_timezone();
$stamp  = gmdate( 'Ymd-His' );
$dir    = dirname( __DIR__ ) . '/migration/reports';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
$out    = [];
$say    = function ( string $l ) use ( &$out ): void { echo $l, "\n"; $out[] = $l; };
$counts = [];
$count  = function ( string $k, int $n = 1 ) use ( &$counts ): void { $counts[ $k ] = ( $counts[ $k ] ?? 0 ) + $n; };
$now    = Dates::now();

$say( '# Registry term sync (' . ( $dry ? 'DRY RUN' : 'live' ) . ( $make ? ', --create-missing' : '' ) . ") — $stamp UTC" );
$say( '' );
$say( 'Default tier: ' . ( $tier ? "#{$tier->id} {$tier->name}" : '**none — stop**' ) . ' · site time zone ' . $tz->getName() );
if ( ! $tier ) { exit( 1 ); }

/* --------------------------------------------------------------- 1. read CSVs */
$rows = [];
foreach ( $files as $file ) {
	if ( ! is_readable( $file ) ) { $say( "- **cannot read** $file" ); continue; }
	$fh   = fopen( $file, 'r' );
	$head = null;
	$n    = 0;
	while ( ( $r = fgetcsv( $fh ) ) !== false ) {
		if ( null === $head ) { $head = array_map( static fn( $h ) => strtolower( trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $r ); continue; }
		if ( count( array_filter( $r, static fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) { continue; }
		$row = array_combine( $head, array_pad( array_map( 'trim', $r ), count( $head ), '' ) );
		if ( ! $row ) { continue; }
		$row['_file'] = basename( $file );
		$row['_line'] = $n + 2;
		$rows[]       = $row;
		$n++;
	}
	fclose( $fh );
	$say( "- read $n rows from " . basename( $file ) );
}
if ( $limit ) { $rows = array_slice( $rows, 0, $limit ); }
$say( '- rows to process: ' . count( $rows ) );

/* ----------------------------------------------------------- 2. Local indexes */
$norm_email = static fn( string $e ): string => strtolower( trim( $e ) );
$norm_name  = static fn( string $s ): string => strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]/i', ' ', $s ) ) ) );
$digits     = static fn( string $s ): string => preg_replace( '/\D+/', '', $s );

$by_email = [];
foreach ( $wpdb->get_results( "SELECT ID, user_email FROM {$wpdb->users}" ) as $u ) { $by_email[ $norm_email( (string) $u->user_email ) ] = (int) $u->ID; }
$farm_rows = $wpdb->get_results( "SELECT id, owner_user_id, name, email, breeder_number FROM {$p}sp_farms" );
$farm_by_email = []; $farm_by_breeder = []; $farm_by_name = []; $farm_by_owner = [];
foreach ( $farm_rows as $f ) {
	$fe = $norm_email( (string) $f->email );
	if ( '' !== $fe && ! isset( $farm_by_email[ $fe ] ) ) { $farm_by_email[ $fe ] = (int) $f->id; }
	$bn = $digits( (string) $f->breeder_number );
	if ( '' !== $bn ) { $farm_by_breeder[ $bn ][] = (int) $f->id; }
	$fn = $norm_name( (string) $f->name );
	if ( '' !== $fn ) { $farm_by_name[ $fn ][] = (int) $f->id; }
	$farm_by_owner[ (int) $f->owner_user_id ] = (int) $f->id;
}
$user_by_breeder = [];
foreach ( $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'southpoll_breeder_number' AND meta_value <> ''" ) as $m ) {
	$bn = $digits( (string) $m->meta_value );
	if ( '' !== $bn && strlen( $bn ) <= 6 ) { $user_by_breeder[ $bn ][] = (int) $m->user_id; }
}
$user_by_person = [];
foreach ( $wpdb->get_results( "SELECT user_id, first_name, last_name FROM {$p}sp_members" ) as $m ) {
	$k = $norm_name( $m->first_name . ' ' . $m->last_name );
	if ( '' !== trim( (string) $m->first_name ) && '' !== trim( (string) $m->last_name ) ) { $user_by_person[ $k ][] = (int) $m->user_id; }
}
$staff_ids = array_map( 'intval', get_users( [ 'role__in' => [ 'administrator', 'editor' ], 'fields' => 'ID' ] ) );

/* ----------------------------------------------------- 3. match + apply */
$site_dt = static function ( string $ymd, string $time ) use ( $tz ): string {
	return ( new DateTimeImmutable( "$ymd $time", $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
};
$unmatched = [];
$review    = [];
$changes   = [];
$flips     = [ 'to-active' => 0, 'to-expired' => 0 ];
$before    = $mrepo->counts_by_status();
$seen_farm = [];

/** Match one CSV row to [user id, farm id, how] without writing anything. */
$resolve = function ( array $row ) use ( $norm_email, $norm_name, $digits, $by_email, $farm_by_email, $farm_by_breeder, $user_by_breeder, $user_by_person, $farm_by_name ): array {
	$email = $norm_email( (string) ( $row['email'] ?? '' ) );
	$bn    = $digits( (string) ( $row['memidord'] ?? '' ) );
	$pk    = $norm_name( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
	$fk    = $norm_name( (string) ( $row['farm_name'] ?? '' ) ?: (string) ( $row['regname'] ?? '' ) );
	if ( '' !== $email && isset( $by_email[ $email ] ) ) { return [ $by_email[ $email ], 0, 'email' ]; }
	if ( '' !== $email && isset( $farm_by_email[ $email ] ) ) { return [ 0, $farm_by_email[ $email ], 'farm-email' ]; }
	if ( '' !== $bn && isset( $farm_by_breeder[ $bn ] ) && 1 === count( array_unique( $farm_by_breeder[ $bn ] ) ) ) { return [ 0, $farm_by_breeder[ $bn ][0], 'breeder-farm' ]; }
	if ( '' !== $bn && isset( $user_by_breeder[ $bn ] ) && 1 === count( array_unique( $user_by_breeder[ $bn ] ) ) ) { return [ $user_by_breeder[ $bn ][0], 0, 'breeder-user' ]; }
	if ( '' !== trim( $pk ) && isset( $user_by_person[ $pk ] ) && 1 === count( array_unique( $user_by_person[ $pk ] ) ) ) { return [ $user_by_person[ $pk ][0], 0, 'person-name' ]; }
	if ( '' !== $fk && isset( $farm_by_name[ $fk ] ) && 1 === count( array_unique( $farm_by_name[ $fk ] ) ) ) { return [ 0, $farm_by_name[ $fk ][0], 'farm-name' ]; }
	return [ 0, 0, '' ];
};

// Pre-pass: when several registry rows land on one farm (spouses, partners), only the row with the latest expiry date is
// applied — otherwise two rows with different years would overwrite each other on every run.
$latest = [];
foreach ( $rows as $i => $row ) {
	[ $u, $f ] = $resolve( $row );
	if ( ! $u && ! $f ) { continue; }
	$key = $f ? "f:$f" : ( isset( $farm_by_owner[ $u ] ) ? 'f:' . $farm_by_owner[ $u ] : "u:$u" );
	$d   = DateTimeImmutable::createFromFormat( '!m/d/Y', (string) ( $row['exp_date'] ?? '' ), $tz ) ?: DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $row['exp_date'] ?? '' ), $tz );
	$ymd = $d ? $d->format( 'Y-m-d' ) : '';
	if ( ! isset( $latest[ $key ] ) || $ymd > $latest[ $key ]['ymd'] ) { $latest[ $key ] = [ 'ymd' => $ymd, 'i' => $i ]; }
}
foreach ( $rows as $i => $row ) {
	[ $u, $f ] = $resolve( $row );
	if ( ! $u && ! $f ) { continue; }
	$key = $f ? "f:$f" : ( isset( $farm_by_owner[ $u ] ) ? 'f:' . $farm_by_owner[ $u ] : "u:$u" );
	if ( isset( $latest[ $key ] ) && $latest[ $key ]['i'] !== $i ) { $rows[ $i ]['_skip'] = $latest[ $key ]['ymd']; }
}

foreach ( $rows as $row ) {
	$email   = $norm_email( (string) ( $row['email'] ?? '' ) );
	$bn      = $digits( (string) ( $row['memidord'] ?? '' ) );
	$first   = (string) ( $row['first_name'] ?? '' );
	$last    = (string) ( $row['last_name'] ?? '' );
	$fname   = (string) ( $row['farm_name'] ?? '' ) ?: (string) ( $row['regname'] ?? '' );
	$exp_raw = (string) ( $row['exp_date'] ?? '' );
	$junior  = 'JR' === strtoupper( (string) ( $row['mem_sts'] ?? '' ) );
	$label   = "#{$bn} " . trim( "$first $last" ) . ( $fname ? " / $fname" : '' );
	$exp     = DateTimeImmutable::createFromFormat( '!m/d/Y', $exp_raw, $tz ) ?: DateTimeImmutable::createFromFormat( '!Y-m-d', $exp_raw, $tz );
	if ( ! $exp ) { $count( 'bad-exp-date' ); $unmatched[] = $row + [ '_reason' => 'bad exp_date' ]; continue; }
	$ends_at   = $site_dt( $exp->format( 'Y-m-d' ), '23:59:59' );
	$starts_at = $site_dt( $exp->format( 'Y' ) . '-01-01', '00:00:00' );

	// --- match (same rules as the pre-pass; rows that lost the "latest date wins" rule for their farm were marked _skip)
	if ( ! empty( $row['_skip'] ) ) { $count( 'duplicate-row-for-farm' ); $review[] = "$label → duplicate registry row for the same farm, earlier/equal date ignored (farm keeps {$row['_skip']})"; continue; }
	[ $uid, $fid, $how ] = $resolve( $row );

	if ( $uid && in_array( $uid, $staff_ids, true ) ) { $count( 'skipped-staff-account' ); $unmatched[] = $row + [ '_reason' => "matches staff account #$uid" ]; continue; }
	if ( ! $uid && ! $fid ) {
		if ( $make && '' !== $email && is_email( $email ) ) {
			$count( 'create-missing' );
			if ( $dry ) { $changes[] = [ $label, '(new account + farm)', '—', $exp->format( 'Y-m-d' ), 'create' ]; continue; }
			$login = sanitize_user( strstr( $email, '@', true ) ?: $email, true ) ?: 'member';
			$base  = $login; $i = 1;
			while ( username_exists( $login ) ) { $login = $base . $i++; }
			$uid = wp_insert_user( [ 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( "$first $last" ) ?: $login, 'role' => Capabilities::ROLE_BUYER ] );
			if ( is_wp_error( $uid ) ) { $count( 'create-failed' ); $unmatched[] = $row + [ '_reason' => 'create failed: ' . $uid->get_error_message() ]; continue; }
			$uid = (int) $uid;
			update_user_meta( $uid, 'southpoll_breeder_number', $bn );
			update_user_meta( $uid, 'sp_registry_created', $stamp );
			$member->create_for_user( $uid, [ 'first_name' => $first, 'last_name' => $last, 'phone' => (string) ( $row['phone1'] ?? '' ), 'city' => (string) ( $row['city'] ?? '' ), 'state' => strtoupper( (string) ( $row['areacd'] ?? '' ) ), 'zip' => (string) ( $row['pcode'] ?? '' ) ] );
			$how = 'created';
		} else {
			$count( 'unmatched' );
			$unmatched[] = $row + [ '_reason' => '' === $email ? 'no e-mail, no other match' : 'no account with this e-mail / breeder number / name' ];
			continue;
		}
	}
	$count( 'matched-' . $how );

	// --- farm
	if ( ! $fid ) {
		$fid = $farm_by_owner[ $uid ] ?? 0;
		if ( ! $fid ) {
			$count( 'farm-created' );
			if ( $dry ) { $virtual_farm = (object) [ 'id' => 0, 'name' => '(new minimal farm) ' . ( $fname ?: trim( "$first $last" ) . ' Farm' ), 'breeder_number' => $bn ]; }
			else { $farm = $fsvc->create_for_owner( $uid, $fname ?: trim( "$first $last" ) . ' Farm', [ 'state' => strtoupper( (string) ( $row['areacd'] ?? '' ) ), 'breeder_number' => $bn ] ); }
			if ( $dry ) { $farm = $virtual_farm; }
			if ( is_wp_error( $farm ) ) { $count( 'farm-create-failed' ); $unmatched[] = $row + [ '_reason' => 'farm create failed: ' . $farm->get_error_message() ]; continue; }
			$fid = (int) $farm->id;
			if ( ! $dry ) {
				$farms->update( $fid, array_filter( [ 'address1' => (string) ( $row['addr1'] ?? '' ), 'city' => (string) ( $row['city'] ?? '' ), 'state' => strtoupper( (string) ( $row['areacd'] ?? '' ) ), 'zip' => (string) ( $row['pcode'] ?? '' ), 'phone' => (string) ( $row['phone1'] ?? '' ), 'contact_form_enabled' => 1 ], static fn( $v ) => '' !== $v && null !== $v ) );
				$farm_by_owner[ $uid ] = $fid;
			}
		}
	}
	if ( $fid ) {
		if ( isset( $seen_farm[ $fid ] ) ) {
			// Two registry rows (spouses, farm partners) on one farm: the latest expiry date wins, earlier ones are ignored.
			$count( 'duplicate-row-for-farm' );
			if ( $seen_farm[ $fid ] >= $ends_at ) { $review[] = "$label → duplicate registry row for farm #$fid, earlier/equal date ignored (farm keeps " . substr( $seen_farm[ $fid ], 0, 10 ) . ')'; continue; }
			$review[] = "$label → duplicate registry row for farm #$fid, later date " . $exp->format( 'Y-m-d' ) . ' applied';
		}
		$seen_farm[ $fid ] = $ends_at;
		$farm = $farms->find( $fid );
	}
	if ( empty( $farm ) ) { $count( 'farm-missing' ); continue; }
	if ( in_array( $how, [ 'person-name', 'farm-name', 'breeder-farm', 'breeder-user' ], true ) ) { $review[] = "$label → farm #{$fid} \"{$farm->name}\" matched by $how"; }

	// --- breeder number + junior flag
	if ( '' !== $bn && '' === $digits( (string) $farm->breeder_number ) ) {
		$count( 'breeder-number-written' );
		if ( ! $dry ) { $wpdb->update( "{$p}sp_farms", [ 'breeder_number' => $bn ], [ 'id' => $fid ] ); }
	}
	if ( $junior ) {
		$count( 'junior' );
		if ( ! $dry ) { $wpdb->replace( "{$p}sp_farm_meta", [ 'farm_id' => $fid, 'field_key' => 'membership_class', 'value' => 'junior' ] ); }
	}

	// --- membership term
	$m          = $mrepo->for_farm( $fid );
	$old_ends   = $m ? (string) $m->ends_at : '';
	$old_status = $m ? (string) $m->status : 'none';
	$new_status = $ends_at > $now ? 'active' : 'expired';
	if ( $m && $old_ends === $ends_at && $old_status === $new_status && 'registry' === (string) $m->source ) { $count( 'unchanged' ); continue; }
	$count( 'term-set' );
	if ( 'active' === $new_status && 'active' !== $old_status ) { $flips['to-active']++; }
	if ( 'expired' === $new_status && 'active' === $old_status ) { $flips['to-expired']++; }
	$changes[] = [ $label, (string) $farm->name, $old_ends ? Dates::display( $old_ends ) . " ($old_status)" : "— ($old_status)", $exp->format( 'Y-m-d' ) . " ($new_status)", $how ];
	if ( $dry ) { continue; }
	$m    = $mrepo->ensure( $fid );
	$note = trim( (string) $m->notes . "\n" . gmdate( 'Y-m-d' ) . " Registry export {$row['_file']}: term end set to " . $exp->format( 'Y-m-d' ) . ( $junior ? ' (junior)' : '' ) );
	$data = [
		'ends_at'   => $ends_at,
		'status'    => $new_status,
		'source'    => 'registry',
		'notes'     => $note,
		'tier_id'   => (int) $m->tier_id ?: (int) $tier->id,
		'starts_at' => ( empty( $m->starts_at ) || (string) $m->starts_at > $ends_at ) ? $starts_at : (string) $m->starts_at,
	];
	if ( 'active' === $new_status ) {
		$data['expired_at'] = null;
		if ( empty( $m->activated_at ) ) { $data['activated_at'] = $now; }
	} else {
		$data['expired_at'] = $m->expired_at ?: $ends_at;
	}
	$mrepo->update( (int) $m->id, $data );
	if ( 'active' !== $new_status && 'active' === $old_status ) {
		$c->get( \SouthPoll\Membership\Listings\ListingService::class )->hide_for_farm( $farm, 'registry' );
	} elseif ( 'active' === $new_status && 'active' !== $old_status ) {
		$count( 'listings-restored', $c->get( \SouthPoll\Membership\Listings\ListingService::class )->restore_for_farm( $farm ) );
	}
}

/* --------------------------------------------------------------- 4. report */
$say( '' );
$say( '## Counts' );
ksort( $counts );
foreach ( $counts as $k => $n ) { $say( "- $k: $n" ); }
$say( "- status flips: → active {$flips['to-active']}, → expired {$flips['to-expired']}" );
$say( '' );
$say( '## Membership status counts (website)' );
$after = $dry ? $before : $mrepo->counts_by_status();
$say( '| status | before | after |' ); $say( '|---|---|---|' );
foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $k ) { $say( sprintf( '| %s | %d | %d |', $k, (int) ( $before[ $k ] ?? 0 ), (int) ( $after[ $k ] ?? 0 ) ) ); }
$say( '' );
$say( '## Changes' . ( $dry ? ' (would apply)' : '' ) . ' — ' . count( $changes ) );
$say( '| registry row | farm | before | after | matched by |' ); $say( '|---|---|---|---|---|' );
foreach ( array_slice( $changes, 0, 400 ) as [ $a, $b, $c1, $d, $e ] ) { $say( '| ' . str_replace( '|', '/', $a ) . ' | ' . str_replace( '|', '/', $b ) . " | $c1 | $d | $e |" ); }
if ( count( $changes ) > 400 ) { $say( '| … | | | | |' ); }
$say( '' );
$say( '## Review — matches made by name / breeder number and duplicate rows (' . count( $review ) . ')' );
foreach ( $review as $line ) { $say( '- ' . $line ); }
if ( ! $review ) { $say( '- none' ); }
$say( '' );
$say( '## Unmatched — ' . count( $unmatched ) . ( $unmatched ? " (CSV: registry-unmatched-$stamp.csv)" : '' ) );
foreach ( array_slice( $unmatched, 0, 200 ) as $u ) { $say( '- #' . ( $u['memidord'] ?? '' ) . ' ' . trim( ( $u['first_name'] ?? '' ) . ' ' . ( $u['last_name'] ?? '' ) ) . ( ! empty( $u['farm_name'] ) ? " / {$u['farm_name']}" : '' ) . ' · ' . ( $u['email'] ?: 'no e-mail' ) . ' · ' . ( $u['mem_sts'] ?? '' ) . ' · exp ' . ( $u['exp_date'] ?? '' ) . ' — ' . $u['_reason'] ); }
if ( $unmatched ) {
	$fh = fopen( "$dir/registry-unmatched-$stamp.csv", 'w' );
	fputcsv( $fh, [ 'memidord', 'regname', 'farm_name', 'first_name', 'last_name', 'addr1', 'city', 'areacd', 'pcode', 'phone1', 'email', 'mem_sts', 'member_num', 'exp_date', 'source_file', 'reason' ] );
	foreach ( $unmatched as $u ) { fputcsv( $fh, [ $u['memidord'] ?? '', $u['regname'] ?? '', $u['farm_name'] ?? '', $u['first_name'] ?? '', $u['last_name'] ?? '', $u['addr1'] ?? '', $u['city'] ?? '', $u['areacd'] ?? '', $u['pcode'] ?? '', $u['phone1'] ?? '', $u['email'] ?? '', $u['mem_sts'] ?? '', $u['member_num'] ?? '', $u['exp_date'] ?? '', $u['_file'] ?? '', $u['_reason'] ] ); }
	fclose( $fh );
}
file_put_contents( "$dir/registry-sync-$stamp" . ( $dry ? '-dry' : '' ) . '.md', implode( "\n", $out ) . "\n" );
echo "\nReport: $dir/registry-sync-$stamp" . ( $dry ? '-dry' : '' ) . ".md\n";
