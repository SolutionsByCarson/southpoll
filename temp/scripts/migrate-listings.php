<?php
/**
 * migrate-listings.php — T5.4: rtcl_listing → sp_listing (category map, price / price type, seller + business name,
 * images adopted in place, expiry carried over, sold flag), old slugs preserved, listing stats → sp_stats_daily.
 *
 *   ./wp-run.sh migrate-listings.php --dry-run | [--limit=N] [--no-media]
 * Run migrate-farms.php first (listings attach to the owner's farm).
 *
 * Category map (Q-17): Bulls→bulls, Cows/Heifers→females, Steers→feeders (assumption, flagged), Semen→semen.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Support\Dates;

global $wpdb;
$p       = $wpdb->prefix;
$o       = sp_mig_opts();
$r       = new SpMigReport( 'migrate-listings', $o['dry'] );
$lrepo   = sp_membership()->get( ListingRepository::class );
$lsvc    = sp_membership()->get( ListingService::class );
$types   = sp_membership()->get( ListingTypes::class );
$cat_map = sp_mig_category_map();
$pending = [ 'listings' => [], 'attachments' => [] ];
sp_mig_autoflush( $pending );
$done    = 0; $farms_touched = [];
$now     = Dates::now();

$r->h( 'Listings' );
$posts = $wpdb->get_results( "SELECT ID, post_author, post_title, post_name, post_content, post_excerpt, post_status, post_date, post_date_gmt, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = 'rtcl_listing' AND post_status IN ('publish','rtcl-expired') ORDER BY ID" );
foreach ( $posts as $post ) {
	if ( $o['limit'] && $done >= $o['limit'] ) { break; }
	$done++;
	$oid  = (int) $post->ID;
	$meta = fn( string $k ) => (string) get_post_meta( $oid, $k, true );
	if ( sp_mig_listing_by_legacy( $oid ) ) { $r->count( 'already-migrated' ); continue; }
	$uid  = (int) $post->post_author;
	$farm = sp_mig_farm_for_user( $uid );
	if ( ! $farm ) { $r->flag( 'no-farm', "listing #$oid \"{$post->post_title}\" owner #$uid has no farm — skipped" ); if ( ! $o['dry'] ) { continue; } } // dry run keeps counting without a farm
	$fid = $farm ? (int) $farm->id : 0;
	// Category.
	$terms   = sp_mig_object_terms( $oid, 'rtcl_category' ); // by SQL: taxonomy is unregistered while RTCL is inactive
	$old_cat = $terms ? (string) $terms[0]->slug : '';
	$new_cat = $cat_map[ $old_cat ] ?? '';
	$term    = $new_cat ? $types->category( $new_cat ) : null;
	if ( ! $term ) { $r->flag( 'category-unmapped', "listing #$oid category \"$old_cat\" → no new category; left uncategorised" ); }
	if ( 'steers' === $old_cat ) { $r->flag( 'category-steers', "listing #$oid \"{$post->post_title}\" Steers → Feeders (review: finishers?)" ); }
	// Price.
	$pricing = $meta( '_rtcl_listing_pricing' ) ?: 'price';
	$price   = '' !== $meta( 'price' ) ? (float) $meta( 'price' ) : null;
	$label   = '';
	if ( 'disabled' === $pricing || null === $price ) { $price = null; $label = ''; }
	elseif ( 'range' === $pricing && '' !== $meta( '_rtcl_max_price' ) ) { $label = 'to $' . number_format( (float) $meta( '_rtcl_max_price' ), 0 ); }
	if ( 'negotiable' === $meta( 'price_type' ) ) { $label = trim( $label . ' negotiable' ); }
	// Status / dates.
	$sold     = '1' === $meta( '_rtcl_mark_as_sold' ) || 'yes' === $meta( '_rtcl_mark_as_sold' );
	$expiry   = $meta( 'expiry_date' ) ?: null;
	$expired  = 'rtcl-expired' === $post->post_status || ( $expiry && strtotime( $expiry ) < time() );
	$published = $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : get_gmt_from_date( $post->post_date );
	$seller   = trim( $meta( 'text_m4c7c84b' ) );
	$business = trim( $meta( 'text_m4c3zlip' ) );
	$images   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment' AND post_mime_type LIKE 'image/%%' ORDER BY menu_order, ID", $oid ) ) );
	$thumb    = (int) $meta( '_thumbnail_id' );
	if ( $thumb && ! in_array( $thumb, $images, true ) ) { array_unshift( $images, $thumb ); }
	$r->count( $expired ? 'expired' : ( $sold ? 'sold' : 'live' ) );
	$r->count( 'cat-' . ( $new_cat ?: 'none' ) );
	if ( $o['dry'] ) { continue; }
	$listing = $lrepo->create( [ 'farm_id' => $fid, 'author_id' => $uid, 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'excerpt' => (string) $post->post_excerpt, 'status' => $sold ? 'sold' : 'available', 'price_cents' => null === $price ? null : (int) round( $price * 100 ), 'price_label' => $label ] );
	$lid = (int) $listing->id;
	// Keep the old slug so /classifieds/listing/{old-slug}/ is the same address.
	$slug = wp_unique_post_slug( (string) $post->post_name, $lid, 'publish', ListingTypes::POST_TYPE, 0 );
	$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $lid ] );
	$gallery = [];
	$feat    = 0;
	foreach ( $images as $i => $att ) {
		if ( ! sp_mig_adopt_attachment( $att, $fid, 'listing', $lid, false ) ) { $r->flag( 'media-missing', "listing #$oid attachment #$att has no file" ); continue; }
		$pending['attachments'][] = $att;
		if ( ! $feat ) { $feat = $att; } else { $gallery[] = [ 'key' => 'm' . $i, 'type' => 'image', 'id' => $att, 'url' => '', 'caption' => '' ]; }
	}
	$lrepo->update( $lid, [
		'categories'   => $term ? [ (int) $term->id ] : [],
		'featured_id'  => $feat ?: null,
		'gallery'      => $gallery,
		'published_at' => $published,
		'post_date'    => $published,
		'expires_at'   => $expiry ?: Dates::add_days( $published, (int) $lsvc->duration_days() ),
		'sold_at'      => $sold ? ( $post->post_modified_gmt ?: $now ) : null,
		'post_status'  => $expired ? ListingTypes::STATUS_EXPIRED : 'publish',
		'hidden_reason' => $expired ? 'expired' : '',
	] );
	update_post_meta( $lid, '_sp_view_count', (int) $meta( '_views' ) );
	update_post_meta( $lid, '_sp_legacy_id', $oid );
	update_post_meta( $lid, '_sp_legacy_seller', $seller );
	update_post_meta( $lid, '_sp_legacy_business', $business );
	update_post_meta( $lid, '_sp_legacy_contact', wp_json_encode( [ 'phone' => $meta( 'phone' ), 'email' => $meta( 'email' ) ] ) );
	$old_slugs = array_values( array_unique( array_filter( array_merge( [ (string) $post->post_name ], (array) get_post_meta( $oid, '_wp_old_slug' ) ) ) ) );
	update_post_meta( $lid, '_sp_old_slugs', wp_json_encode( array_values( array_diff( $old_slugs, [ $slug ] ) ) ) );
	clean_post_cache( $lid );
	// Stats → sp_stats_daily.
	$n = 0;
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT stat_date, stat_key, stat_count FROM {$p}rtcl_listing_stats WHERE listing_id = %d", $oid ) ) as $st ) {
		$metric = match ( (string) $st->stat_key ) { 'view' => 'listing_view', 'reveal' => 'contact_reveal', 'phone_click' => 'phone_click', default => 'legacy_' . sanitize_key( (string) $st->stat_key ) };
		$wpdb->replace( "{$p}sp_stats_daily", [ 'day' => $st->stat_date, 'metric' => $metric, 'object_type' => 'listing', 'object_id' => $lid, 'value' => (int) $st->stat_count ] );
		$n++;
	}
	$r->count( 'stats-rows', $n );
	$pending['listings'][] = $lid;
	$farms_touched[ $fid ] = true;
	if ( $seller && '' === trim( (string) $farm->owner_name ) ) { $wpdb->update( "{$p}sp_farms", [ 'owner_name' => sanitize_text_field( $seller ) ], [ 'id' => $fid ] ); $r->count( 'farm-owner-name-from-seller' ); }
}
foreach ( array_keys( $farms_touched ) as $fid ) { $lsvc->refresh_farm_stats( (int) $fid ); }
sp_mig_log_flush( $pending );
$r->count( 'listings-processed', $done );
$r->finish();
