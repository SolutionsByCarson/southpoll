<?php
/**
 * migrate-reset.php — undo a Local migration run: removes the farms, memberships, payments, listings, stats, redirects and
 * member profiles recorded in the sp_migration_log option, untags adopted media (originals are never deleted), and
 * restores converted roles. Legacy RTCL data is never touched.
 *   ./wp-run.sh migrate-reset.php --yes
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

global $wpdb;
$p   = $wpdb->prefix;
$log = sp_mig_log();
echo 'log: ' . json_encode( array_map( 'count', $log ) ) . "\n";
if ( ! sp_mig_flag( 'yes' ) ) { die( "Add --yes to delete the migrated rows listed above.\n" ); }

foreach ( (array) ( $log['listings'] ?? [] ) as $lid ) {
	$wpdb->delete( "{$p}sp_stats_daily", [ 'object_type' => 'listing', 'object_id' => (int) $lid ] );
	wp_delete_post( (int) $lid, true );
}
foreach ( (array) ( $log['attachments'] ?? [] ) as $att ) { foreach ( [ '_sp_farm_id', '_sp_purpose', '_sp_listing_id', '_sp_legacy_media' ] as $k ) { delete_post_meta( (int) $att, $k ); } }
foreach ( (array) ( $log['payments'] ?? [] ) as $id ) { $wpdb->delete( "{$p}sp_payments", [ 'id' => (int) $id ] ); }
foreach ( (array) ( $log['memberships'] ?? [] ) as $id ) { $wpdb->delete( "{$p}sp_memberships", [ 'id' => (int) $id ] ); }
foreach ( (array) ( $log['farms'] ?? [] ) as $fid ) {
	foreach ( [ 'sp_farm_media', 'sp_farm_meta', 'sp_farm_users', 'sp_memberships', 'sp_payments', 'sp_inquiries' ] as $t ) { $wpdb->delete( $p . $t, [ 'farm_id' => (int) $fid ] ); }
	$wpdb->delete( "{$p}sp_favorites", [ 'object_type' => 'farm', 'object_id' => (int) $fid ] );
	wp_delete_object_term_relationships( (int) $fid, 'sp_offering' );
	$wpdb->delete( "{$p}sp_farms", [ 'id' => (int) $fid ] );
}
foreach ( (array) ( $log['members'] ?? [] ) as $id ) { $wpdb->delete( "{$p}sp_members", [ 'id' => (int) $id ] ); }
$restored = 0;
foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'sp_migrated_role'" ) as $uid ) {
	$u = get_userdata( (int) $uid );
	$old = (string) get_user_meta( (int) $uid, 'sp_migrated_role', true );
	if ( $u && $old ) { $u->set_role( $old ); $restored++; }
	delete_user_meta( (int) $uid, 'sp_migrated_role' );
	delete_user_meta( (int) $uid, 'sp_migrated' );
}
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'sp_migrated'" ); // users whose profile already existed get this flag without a role change
$wpdb->query( "DELETE FROM {$p}sp_redirects" );
delete_option( SP_MIG_LOG_OPTION );
sp_membership()->get( SouthPoll\Membership\Members\ContextProvider::class )->forget();
echo "Reset complete; roles restored: $restored\n";
