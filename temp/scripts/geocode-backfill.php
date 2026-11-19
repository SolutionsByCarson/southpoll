<?php
/**
 * geocode-backfill.php — geocode every active farm that has an address but no coordinates (runbook §2.5), in batches,
 * until nothing is left. Same code path as SouthPoll → Status → "Geocode farms missing coordinates" (100 per click).
 *
 *   ./wp-run.sh geocode-backfill.php [--batch=100] [--max=2000]
 *
 * Needs the Google Geocoding key on SouthPoll → Integrations. Farms whose address cannot be resolved are stamped
 * geocoded_at (retried after 7 days by the nightly backfill) so the loop always terminates.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Farms\Geocoder;

global $wpdb;
$batch = 100; $max = 2000;
foreach ( $argv as $a ) { if ( str_starts_with( $a, '--batch=' ) ) { $batch = max( 1, (int) substr( $a, 8 ) ); } if ( str_starts_with( $a, '--max=' ) ) { $max = max( 1, (int) substr( $a, 6 ) ); } }
$geo = sp_membership()->get( Geocoder::class );
if ( ! $geo->configured() ) { fwrite( STDERR, "Google Geocoding key is not configured (SouthPoll → Integrations)\n" ); exit( 1 ); }
$p = $wpdb->prefix;
if ( in_array( '--retry-failed', $argv, true ) ) {
	// Forget earlier failed attempts (e.g. made while the Geocoding API was not yet enabled on the key) so they are retried now.
	$n = $wpdb->query( "UPDATE {$p}sp_farms SET geocoded_at = NULL WHERE lat IS NULL AND geocoded_at IS NOT NULL" );
	echo "retry-failed: cleared the attempt stamp on $n farms\n";
}
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sp_farms WHERE lat IS NULL" );
echo "farms without coordinates: $before (" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sp_farms WHERE lat IS NULL AND (city <> '' OR zip <> '' OR address1 <> '') AND status = 'active'" ) . " eligible: active with an address)\n";
$total = [ 'attempted' => 0, 'geocoded' => 0 ];
$start = microtime( true );
while ( $total['attempted'] < $max ) {
	$r = $geo->backfill( $batch );
	if ( ! $r['attempted'] ) { break; }
	$total['attempted'] += $r['attempted']; $total['geocoded'] += $r['geocoded'];
	printf( "batch: %d attempted, %d geocoded (running %d/%d, %.0fs)\n", $r['attempted'], $r['geocoded'], $total['geocoded'], $total['attempted'], microtime( true ) - $start );
	if ( ! empty( $r['error'] ) ) { fwrite( STDERR, "stopped: the Geocoding service answered {$r['error']} — fix the key / enable the Geocoding API in Google Cloud, then run again (farms were not stamped, nothing to retry)\n" ); exit( 2 ); }
}
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sp_farms WHERE lat IS NULL" );
printf( "done: %d/%d geocoded; farms still without coordinates: %d (%d of them have no usable address and are shown without a map pin)\n", $total['geocoded'], $total['attempted'], $after, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sp_farms WHERE lat IS NULL AND city = '' AND zip = '' AND address1 = ''" ) );
foreach ( $wpdb->get_results( "SELECT id, name, address1, city, state, zip FROM {$p}sp_farms WHERE lat IS NULL AND (city <> '' OR zip <> '' OR address1 <> '') AND status = 'active' AND geocoded_at IS NOT NULL ORDER BY id LIMIT 40" ) as $f ) { echo "  ! unresolved: #{$f->id} {$f->name} — " . trim( "{$f->address1}, {$f->city}, {$f->state} {$f->zip}", ', ' ) . "\n"; }
