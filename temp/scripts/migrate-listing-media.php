<?php
/**
 * migrate-listing-media.php — re-run only the photo step of migrate-listings.php for listings whose old-site photos were
 * missing on disk when they were migrated (DB-only pull: the files arrived later). For every migrated listing (`_sp_legacy_id`)
 * the old listing's image attachments are compared with the new listing's featured image + gallery; images that are now on
 * disk are adopted (tagged `_sp_listing_id`, sizes rebuilt) and appended to the gallery in the old order; the old featured
 * image becomes the featured image when the listing has none. Idempotent.
 *
 * Run:  ./wp-run.sh migrate-listing-media.php [--dry-run]
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Listings\ListingRepository;

global $wpdb;
$dry  = in_array( '--dry-run', $argv ?? [], true );
$repo = sp_membership()->get( ListingRepository::class );
$rows = $wpdb->get_results( "SELECT p.ID AS new_id, pm.meta_value AS old_id FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sp_legacy_id' WHERE p.post_type = 'sp_listing'" );
$c    = [ 'listings' => 0, 'images-checked' => 0, 'already-attached' => 0, 'adopted' => 0, 'featured-set' => 0, 'still-missing-file' => 0, 'listings-touched' => 0 ];
$miss = [];
echo '# Listing media re-adopt (' . ( $dry ? 'DRY RUN' : 'live' ) . ") — " . gmdate( 'Y-m-d H:i' ) . " UTC\n\n";
foreach ( $rows as $row ) {
	$c['listings']++;
	$new = $repo->find( (int) $row->new_id );
	if ( ! $new ) { continue; }
	$old_images = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment' AND post_mime_type LIKE 'image/%%' ORDER BY menu_order, ID", (int) $row->old_id ) ) );
	$old_thumb  = (int) get_post_meta( (int) $row->old_id, '_thumbnail_id', true );
	if ( $old_thumb && ! in_array( $old_thumb, $old_images, true ) ) { array_unshift( $old_images, $old_thumb ); }
	$have    = array_filter( array_merge( [ (int) $new->featured_id ], array_map( static fn( $g ) => (int) ( $g['id'] ?? 0 ), $new->gallery ) ) );
	$gallery = $new->gallery;
	$feat    = (int) $new->featured_id;
	$touched = false;
	foreach ( $old_images as $i => $att ) {
		$c['images-checked']++;
		if ( in_array( $att, $have, true ) ) { $c['already-attached']++; continue; }
		if ( ! sp_mig_adopt_attachment( $att, (int) $new->farm_id, 'listing', (int) $new->id, $dry ) ) { $c['still-missing-file']++; $miss[ (int) $row->old_id ] = ( $miss[ (int) $row->old_id ] ?? 0 ) + 1; continue; }
		$c['adopted']++;
		$touched = true;
		if ( ! $feat ) { $feat = $att; $c['featured-set']++; continue; }
		$gallery[] = [ 'key' => 'r' . $att, 'type' => 'image', 'id' => $att, 'url' => '', 'caption' => '' ];
	}
	if ( $touched ) {
		$c['listings-touched']++;
		if ( ! $dry ) { $repo->update( (int) $new->id, [ 'featured_id' => $feat ?: null, 'gallery' => $gallery ] ); }
	}
}
foreach ( $c as $k => $n ) { echo "- $k: $n\n"; }
if ( $miss ) { echo "\nOld listings whose files are still absent (old id → images): " . implode( ', ', array_map( static fn( $k, $v ) => "#$k→$v", array_keys( $miss ), $miss ) ) . "\n"; }
