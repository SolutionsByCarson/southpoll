<?php
/**
 * test-cleanup.php — Remove throwaway accounts created by the test scripts (any user whose email ends in @example.test),
 * together with their plugin rows (members, farm users, farms, memberships, payments, favorites, saved searches, inquiries,
 * farm media/meta, offerings relationships, notification queue), their farms' classified listings, and the media-library
 * attachments the plugin uploaded for those farms/listings.
 *
 * Run: ./wp-run.sh test-cleanup.php            (dry run: lists what would be removed)
 *      ./wp-run.sh test-cleanup.php --yes      (delete)
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$p     = $wpdb->prefix;
$do    = in_array( '--yes', $argv, true );
$users = $wpdb->get_results( "SELECT ID, user_email FROM {$p}users WHERE user_email LIKE '%@example.test'" );
echo count( $users ), " test user(s)", $do ? '' : ' (dry run; pass --yes to delete)', "\n";
foreach ( $users as $u ) {
	$uid   = (int) $u->ID;
	$farms = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}sp_farms WHERE owner_user_id = %d", $uid ) );
	echo "- #{$uid} {$u->user_email}", $farms ? ' farms: ' . implode( ',', $farms ) : '', "\n";
	foreach ( $farms as $fid ) {
		$listings    = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$p}postmeta WHERE meta_key = '_sp_farm_id' AND meta_value = %d", (int) $fid ) );
		$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$p}postmeta WHERE meta_key = '_sp_farm_id' AND meta_value = %d AND post_id IN (SELECT ID FROM {$p}posts WHERE post_type = 'attachment')", (int) $fid ) );
		$listings    = array_values( array_diff( array_map( 'intval', $listings ), array_map( 'intval', $attachments ) ) );
		if ( $listings ) { echo "    listings: " . implode( ',', $listings ) . "\n"; }
		if ( $attachments ) { echo "    attachments: " . implode( ',', $attachments ) . "\n"; }
		if ( ! $do ) { continue; }
		foreach ( $listings as $lid ) {
			$wpdb->delete( $p . 'sp_favorites', [ 'object_type' => 'listing', 'object_id' => (int) $lid ] );
			wp_delete_post( (int) $lid, true );
		}
		foreach ( $attachments as $aid ) {
			wp_delete_attachment( (int) $aid, true );
		}
		foreach ( [ 'sp_farm_users', 'sp_farm_media', 'sp_farm_meta', 'sp_memberships', 'sp_payments', 'sp_inquiries' ] as $t ) {
			$wpdb->delete( $p . $t, [ 'farm_id' => (int) $fid ] );
		}
		$wpdb->delete( $p . 'sp_favorites', [ 'object_type' => 'farm', 'object_id' => (int) $fid ] );
		wp_delete_object_term_relationships( (int) $fid, 'sp_offering' );
		$wpdb->delete( $p . 'sp_farms', [ 'id' => (int) $fid ] );
	}
	if ( ! $do ) { continue; }
	foreach ( [ 'sp_members', 'sp_farm_users', 'sp_favorites', 'sp_saved_searches', 'sp_notification_queue' ] as $t ) {
		$wpdb->delete( $p . $t, [ 'user_id' => $uid ] );
	}
	$wpdb->delete( $p . 'sp_inquiries', [ 'sender_user_id' => $uid ] );
	wp_delete_user( $uid );
}
if ( $do ) {
	$n = $wpdb->query( "DELETE FROM {$p}sp_farm_users WHERE invite_email LIKE '%@example.test' AND accepted_at IS NULL" );
	$m = $wpdb->query( "DELETE FROM {$p}sp_inquiries WHERE sender_email LIKE '%@example.test'" );
	$q = $wpdb->query( "DELETE FROM {$p}sp_notification_queue WHERE recipient LIKE '%@example.test'" );
	foreach ( [ 'sp_offering', 'sp_listing_category' ] as $tax ) {
		$tt = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'tt_ids' ] );
		if ( ! is_wp_error( $tt ) && $tt ) { wp_update_term_count_now( $tt, $tax ); }
	}
	echo "Deleted. Orphan invites removed: {$n}; test inquiries removed: {$m}; queued alerts removed: {$q}\n";
} else {
	echo "Done (nothing changed).\n";
}
