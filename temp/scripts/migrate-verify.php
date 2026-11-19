<?php
/**
 * migrate-verify.php — T5.6 parity checklist: old counts vs new counts, spot checks, review lists. Read-only.
 *   ./wp-run.sh migrate-verify.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;

global $wpdb;
$p   = $wpdb->prefix;
$r   = new SpMigReport( 'migrate-verify', false );
$v   = fn( string $sql ) => (int) $wpdb->get_var( $sql );
$pair = function ( string $label, int $old, int $new, bool $exact = true ) use ( $r ) { $ok = $exact ? $old === $new : $new >= $old; $r->line( sprintf( '- %s %-52s old %6d → new %6d', $ok ? 'OK  ' : 'DIFF', $label, $old, $new ) ); $r->count( $ok ? 'ok' : 'diff' ); };

$r->h( 'Counts' );
$pair( 'users with a member profile (non-staff)', $v( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id = u.ID AND m.meta_key = '{$p}capabilities' AND (m.meta_value LIKE '%administrator%' OR m.meta_value LIKE '%editor%'))" ), $v( "SELECT COUNT(*) FROM {$p}sp_members" ), false );
$pair( 'stores → farms with legacy_store_id', $v( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'store' AND post_status = 'publish'" ), $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE legacy_store_id IS NOT NULL AND legacy_store_id > 0" ) );
$pair( 'membership rows → migrated memberships', $v( "SELECT COUNT(*) FROM {$p}rtcl_membership" ), $v( "SELECT COUNT(*) FROM {$p}sp_memberships WHERE source = 'migrated'" ) );
$pair( 'unexpired memberships → active', $v( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE expiry_date > NOW()" ), $v( "SELECT COUNT(*) FROM {$p}sp_memberships WHERE source = 'migrated' AND status = 'active'" ) );
// Orders are migrated through a membership: a completed order whose customer has no membership row (test orders, deleted users) is an orphan.
$order_join = "FROM {$wpdb->posts} po JOIN {$wpdb->postmeta} c ON c.post_id = po.ID AND c.meta_key = 'customer_id' LEFT JOIN {$wpdb->postmeta} a ON a.post_id = po.ID AND a.meta_key = 'amount' WHERE po.post_type = 'rtcl_payment' AND po.post_status = 'rtcl-completed'";
$has_member = "AND c.meta_value IN (SELECT user_id FROM {$p}rtcl_membership)";
$no_member  = "AND c.meta_value NOT IN (SELECT user_id FROM {$p}rtcl_membership)";
$pair( 'completed orders (with a membership) → succeeded payments', $v( "SELECT COUNT(*) $order_join $has_member" ), $v( "SELECT COUNT(*) FROM {$p}sp_payments WHERE legacy_payment_id IS NOT NULL AND status = 'succeeded'" ) );
$r->line( '  (orphan completed orders without a membership row, not migrated: ' . $v( "SELECT COUNT(*) $order_join $no_member" ) . ', $' . number_format( (float) $wpdb->get_var( "SELECT COALESCE(SUM(a.meta_value),0) $order_join $no_member" ), 2 ) . ')' );
$pair( 'completed order revenue (cents, with a membership)', (int) round( (float) $wpdb->get_var( "SELECT COALESCE(SUM(a.meta_value),0) $order_join $has_member" ) * 100 ), $v( "SELECT COALESCE(SUM(amount_cents),0) FROM {$p}sp_payments WHERE legacy_payment_id IS NOT NULL AND status = 'succeeded'" ) );
$pair( 'listings (publish + expired) → migrated listings', $v( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'rtcl_listing' AND post_status IN ('publish','rtcl-expired')" ), $v( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sp_legacy_id'" ) );
$pair( 'listing images → adopted attachments', $v( "SELECT COUNT(*) FROM {$wpdb->posts} a JOIN {$wpdb->posts} l ON l.ID = a.post_parent AND l.post_type = 'rtcl_listing' AND l.post_status IN ('publish','rtcl-expired') WHERE a.post_type = 'attachment' AND a.post_mime_type LIKE 'image/%'" ), $v( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sp_legacy_media'" ) - $v( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->postmeta} pp ON pp.post_id = pm.post_id AND pp.meta_key = '_sp_purpose' AND pp.meta_value IN ('logo','banner') WHERE pm.meta_key = '_sp_legacy_media'" ), false );
$pair( 'listing stats rows → sp_stats_daily', $v( "SELECT COUNT(*) FROM {$p}rtcl_listing_stats s JOIN {$wpdb->posts} l ON l.ID = s.listing_id AND l.post_status IN ('publish','rtcl-expired')" ), $v( "SELECT COUNT(*) FROM {$p}sp_stats_daily WHERE object_type = 'listing'" ), false );
// Exact paths in the redirect map (written by migrate-redirects.php): rows with a target, not patterns, distinct after the resolver's normalisation.
$map_paths = [];
if ( file_exists( $mf = dirname( __DIR__ ) . '/migration/redirect-map.csv' ) && ( $fh = fopen( $mf, 'r' ) ) ) {
	$head = fgetcsv( $fh );
	while ( $head && ( $c = fgetcsv( $fh ) ) !== false ) { $row = array_combine( $head, array_pad( $c, count( $head ), '' ) ); if ( '' !== $row['new_url'] && 'pattern' !== $row['pattern_or_example'] ) { $map_paths[ '/' . trim( strtolower( rawurldecode( $row['path'] ) ), '/' ) . '/' ] = 1; } }
	fclose( $fh );
}
$pair( 'exact paths in redirect-map.csv → sp_redirects', count( $map_paths ), $v( "SELECT COUNT(*) FROM {$p}sp_redirects" ) );

$r->h( 'Spot checks' );
$farms = sp_membership()->get( FarmRepository::class ); $vis = sp_membership()->get( FarmVisibility::class ); $mrepo = sp_membership()->get( MembershipRepository::class ); $lrepo = sp_membership()->get( ListingRepository::class );
foreach ( $wpdb->get_results( "SELECT f.* FROM {$p}sp_farms f WHERE f.legacy_store_id > 0 ORDER BY RAND() LIMIT 5" ) as $f ) {
	$m = $mrepo->for_farm( (int) $f->id );
	$r->line( sprintf( '- farm #%d "%s" (store %d) %s, %s · membership %s ends %s · %d active listings · logo %s banner %s', $f->id, $f->name, $f->legacy_store_id, $f->city ?: '(no city)', $f->state ?: '(no state)', $m ? $m->status : 'none', $m ? substr( (string) $m->ends_at, 0, 10 ) : '-', $f->active_listing_count, $f->logo_id ? 'yes' : 'no', $f->banner_id ? 'yes' : 'no' ) );
}
foreach ( $wpdb->get_results( "SELECT p.ID, p.post_title, p.post_status, pm.meta_value AS old_id FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sp_legacy_id' ORDER BY RAND() LIMIT 5" ) as $l ) {
	$x = $lrepo->find( (int) $l->ID );
	$r->line( sprintf( '- listing #%d (old %d) "%s" %s · %s · %s · cats %s · %d photos · views %d', $l->ID, $l->old_id, $l->post_title, $l->post_status, $x ? $x->status : '?', $x ? $x->price_display() : '', $x ? implode( ',', array_map( fn( $c ) => $c->slug, $x->categories ) ) : '', $x ? count( $x->gallery ) + ( $x->featured_id ? 1 : 0 ) : 0, $x ? $x->view_count : 0 ) );
}

$r->h( 'Review lists' );
$r->line( '- farms with no state: ' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE state = '' OR state IS NULL" ) . ' (' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE (state = '' OR state IS NULL) AND legacy_store_id > 0" ) . ' had a store page; the rest are minimal farms for members who never gave an address)' );
$r->line( '- farms with no city: ' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE city = '' OR city IS NULL" ) . ' (' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE (city = '' OR city IS NULL) AND legacy_store_id > 0" ) . ' had a store page)' );
$r->line( '- farm names / slogans still holding HTML entities: ' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE name LIKE '%&amp;%' OR name LIKE '%&#%' OR slogan LIKE '%&amp;%' OR slogan LIKE '%&#%'" ) );
$r->line( '- farms without coordinates (geocode after keys are in): ' . $v( "SELECT COUNT(*) FROM {$p}sp_farms WHERE lat IS NULL" ) );
$r->line( '- public farms (active + active membership): ' . $v( "SELECT COUNT(*) FROM {$p}sp_farms f JOIN {$p}sp_memberships m ON m.farm_id = f.id WHERE f.status = 'active' AND m.status = 'active' AND (m.ends_at IS NULL OR m.ends_at > UTC_TIMESTAMP())" ) );
$r->line( '- expired members whose listings were hidden: ' . $v( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->postmeta} f ON f.post_id = pm.post_id AND f.meta_key = '_sp_farm_id' JOIN {$p}sp_memberships m ON m.farm_id = f.meta_value AND m.status <> 'active' WHERE pm.meta_key = '_sp_legacy_id'" ) );
$r->line( '- duplicate emails: ' . $v( "SELECT COUNT(*) FROM (SELECT LOWER(user_email) e FROM {$wpdb->users} GROUP BY e HAVING COUNT(*) > 1) d" ) );
$r->line( '- users still role subscriber: ' . $v( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '{$p}capabilities' AND meta_value LIKE '%subscriber%'" ) );
$r->finish();
