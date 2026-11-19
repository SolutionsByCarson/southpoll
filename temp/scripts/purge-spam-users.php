<?php
/**
 * purge-spam-users.php — find and remove spam registrations (machine-generated names, nothing attached to the account).
 *
 *   ./wp-run.sh purge-spam-users.php                 dry run: prints the SPAM set, the REVIEW list and the KEEP list
 *   ./wp-run.sh purge-spam-users.php --delete        deletes the SPAM set (after writing a CSV of every deleted row to app/backups/)
 *   ./wp-run.sh purge-spam-users.php --delete --ids=12,34   deletes only these candidate ids (e.g. after reviewing the REVIEW list)
 *   ./wp-run.sh purge-spam-users.php --quiet         dry run, counts only
 *
 * Classifier: lib/spam-classify.php. Only accounts with zero engagement are ever candidates (see that file). Deletion goes
 * through wp_delete_user() so the plugin's UserGuards clean up the member profile; nothing is reassigned (the accounts own
 * no content by definition). Take a database backup first (backup-db.php).
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/spam-classify.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$delete = in_array( '--delete', $argv, true );
$quiet  = in_array( '--quiet', $argv, true );
$only   = [];
foreach ( $argv as $a ) { if ( str_starts_with( $a, '--ids=' ) ) { $only = array_map( 'intval', explode( ',', substr( $a, 6 ) ) ); } }

$rows = sp_spam_candidates_rows( $wpdb );
$spam = []; $review = []; $keep = [];
foreach ( $rows as $r ) {
	$v = sp_spam_verdict( $r );
	if ( $only ) {
		if ( in_array( (int) $r->ID, $only, true ) ) { $spam[] = [ $r, $v ]; }
		continue;
	}
	if ( $v['spam'] ) { $spam[] = [ $r, $v ]; } elseif ( $v['review'] ) { $review[] = [ $r, $v ]; } else { $keep[] = $r; }
}
printf( "%s — users %d, zero-engagement candidates registered after the import: %d → SPAM %d, REVIEW %d, KEEP %d\n", $delete ? 'DELETE RUN' : 'DRY RUN', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), count( $rows ), count( $spam ), count( $review ), count( $keep ) );
$months = []; foreach ( $spam as [ $r ] ) { $m = substr( (string) $r->user_registered, 0, 7 ); $months[ $m ] = ( $months[ $m ] ?? 0 ) + 1; } ksort( $months );
echo 'SPAM by registration month: ' . implode( ', ', array_map( fn( $m, $n ) => "$m=$n", array_keys( $months ), $months ) ) . "\n";

if ( ! $quiet && ! $delete ) {
	echo "\n## REVIEW — not deleted; pass --delete --ids=… for any that are spam (" . count( $review ) . ")\n";
	foreach ( $review as [ $r, $v ] ) { echo '  ' . sp_spam_line( $r ) . ' [' . implode( ',', $v['flags'] ) . "]\n"; }
	echo "\n## KEEP — zero engagement but a plausible name (" . count( $keep ) . ")\n";
	foreach ( $keep as $r ) { echo '  ' . sp_spam_line( $r ) . "\n"; }
	echo "\n## SPAM — would be deleted (" . count( $spam ) . "); first 60 shown, full list in the CSV written by --delete\n";
	foreach ( array_slice( $spam, 0, 60 ) as [ $r, $v ] ) { echo '  ' . sp_spam_line( $r ) . ' [' . implode( ',', $v['flags'] ) . "]\n"; }
	exit( 0 );
}
if ( ! $delete ) { exit( 0 ); }

/* ---------------------------------------------------------------- delete */
$dir = dirname( ABSPATH ) . '/backups';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0750, true ); }
$csv = $dir . '/' . gmdate( 'Ymd-His' ) . '-deleted-spam-users.csv';
$fh  = fopen( $csv, 'w' );
fputcsv( $fh, [ 'ID', 'user_login', 'user_email', 'display_name', 'first_name', 'last_name', 'user_registered', 'rtcl_phone', 'flags' ] );
$done = 0; $failed = 0;
foreach ( $spam as [ $r, $v ] ) {
	fputcsv( $fh, [ $r->ID, $r->user_login, $r->user_email, $r->display_name, $r->first_name, $r->last_name, $r->user_registered, $r->rtcl_phone, implode( '|', $v['flags'] ) ] );
	if ( wp_delete_user( (int) $r->ID ) ) { $done++; } else { $failed++; echo "  ! could not delete #{$r->ID}\n"; }
	if ( 0 === $done % 500 ) { echo "  … $done deleted\n"; }
}
fclose( $fh );
// Belt and braces: profile rows the guards may have missed.
$orphans = (int) $wpdb->query( "DELETE m FROM {$wpdb->prefix}sp_members m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL" );
printf( "deleted %d users (%d failed); %d orphaned profile rows removed; record: %s; users now %d\n", $done, $failed, $orphans, $csv, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
sp_membership()->get( SouthPoll\Membership\Members\ContextProvider::class )->forget();
