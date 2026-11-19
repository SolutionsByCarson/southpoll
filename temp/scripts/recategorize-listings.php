<?php
/**
 * recategorize-listings.php — convert the listing classes (and the matching farm offerings) to Nathan's list (S5, 2026-11-18):
 *
 *   Bulls · Cows · Heifers · Steers · Semen        (was Females · Bulls · Finishers · Feeders · Calves · Semen)
 *
 * Listing categories (`sp_listing_category`):
 *   - Feeders  → renamed "Steers" (slug steers, term id kept, so listings stay attached)
 *   - Cows / Heifers created when missing (order + SEO term meta set)
 *   - Females  → Cows or Heifers: the old-site category of a migrated listing wins (rtcl cows → Cows, heifers → Cows/Heifers),
 *                otherwise title keywords (heifer → Heifers; cow / pair / bred / female → Cows; else Cows + "review" flag)
 *   - Finishers → Steers; Calves → by keyword (heifer → Heifers, steer / calf → Steers, cow → Cows; default Steers)
 *   - Females / Finishers / Calves deleted once empty; order Bulls 0, Cows 1, Heifers 2, Steers 3, Semen 4
 * Farm offerings (`sp_offering`, same vocabulary on the directory filter):
 *   - Feeders → renamed Steers; Females → Cows + Heifers (both); Finishers → Steers; Calves → Steers + Heifers; old terms deleted
 * Saved searches (`sp_saved_searches.params.cats`) and exact redirects for the retired archive URLs
 * (`/classifieds/females/` → cows, feeders / finishers / calves → steers) are rewritten / added.
 * Also re-run `migrate-redirects.php` afterwards so the legacy `/listing-category/…/` rows point at cows / heifers / steers.
 *
 * Idempotent; `--dry-run` prints the plan (incl. the review list) and changes nothing. Writes a report to
 * temp/migration/reports/recategorize-<stamp>.md on a real run.
 *
 * Run:  ./wp-run.sh recategorize-listings.php [--dry-run]
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Listings\ListingTypes;

$dry = in_array( '--dry-run', $argv ?? [], true );
global $wpdb;
$p   = $wpdb->prefix;
$out = [];
$say = function ( string $line ) use ( &$out ): void { echo $line, "\n"; $out[] = $line; };

ListingTypes::register_types();
Offerings::register_taxonomy();
$ltax = ListingTypes::TAXONOMY;
$otax = Offerings::TAXONOMY;

$term = static fn( string $tax, string $slug ) => get_term_by( 'slug', $slug, $tax ) ?: null;
$seo  = static function ( int $term_id, string $name, int $order ): void {
	update_term_meta( $term_id, 'sp_order', $order );
	if ( '' === (string) get_term_meta( $term_id, 'sp_seo_title', true ) ) {
		update_term_meta( $term_id, 'sp_seo_title', sprintf( '%s for sale – South Poll cattle classifieds', $name ) );
	}
	if ( '' === (string) get_term_meta( $term_id, 'sp_seo_description', true ) ) {
		update_term_meta( $term_id, 'sp_seo_description', sprintf( 'Browse South Poll %s offered by SPGCA member farms. Contact sellers directly through SouthPoll.com.', strtolower( $name ) ) );
	}
};
$ensure = function ( string $tax, string $name, int $order ) use ( $term, $seo, $say, $dry ): ?int {
	$slug = sanitize_title( $name );
	$t    = $term( $tax, $slug );
	if ( $t ) {
		if ( $t->name !== $name ) { $say( "- $tax: rename #{$t->term_id} \"{$t->name}\" → \"$name\"" ); if ( ! $dry ) { wp_update_term( $t->term_id, $tax, [ 'name' => $name ] ); } }
		if ( ! $dry && $tax === ListingTypes::TAXONOMY ) { $seo( (int) $t->term_id, $name, $order ); } elseif ( ! $dry ) { update_term_meta( (int) $t->term_id, 'sp_order', $order ); }
		return (int) $t->term_id;
	}
	$say( "- $tax: create \"$name\" ($slug)" );
	if ( $dry ) { return null; }
	$r = wp_insert_term( $name, $tax, [ 'slug' => $slug ] );
	if ( is_wp_error( $r ) ) { $say( "  ERROR: " . $r->get_error_message() ); exit( 1 ); }
	if ( $tax === ListingTypes::TAXONOMY ) { $seo( (int) $r['term_id'], $name, $order ); } else { update_term_meta( (int) $r['term_id'], 'sp_order', $order ); }
	return (int) $r['term_id'];
};
$rename = function ( string $tax, string $from_slug, string $to_name ) use ( $term, $say, $dry ): void {
	$f = $term( $tax, $from_slug );
	$t = $term( $tax, sanitize_title( $to_name ) );
	if ( ! $f || $t ) { return; } // nothing to rename, or the target already exists (then the move step handles the rows)
	$say( "- $tax: rename #{$f->term_id} \"{$f->name}\" ($from_slug) → \"$to_name\" (" . sanitize_title( $to_name ) . '), id kept' );
	if ( ! $dry ) { wp_update_term( $f->term_id, $tax, [ 'name' => $to_name, 'slug' => sanitize_title( $to_name ) ] ); }
};
$old_cat = function ( int $listing_id ) use ( $wpdb, $p ): string {
	$legacy = (int) get_post_meta( $listing_id, '_sp_legacy_id', true );
	if ( ! $legacy ) { return ''; }
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT t.slug FROM {$p}term_relationships tr JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='rtcl_category' JOIN {$p}terms t ON t.term_id=tt.term_id WHERE tr.object_id=%d LIMIT 1", $legacy ) );
};
$by_keyword = static function ( string $title, string $default ): array {
	$t = strtolower( $title );
	if ( preg_match( '/heifer/', $t ) ) { return [ 'heifers', 'keyword' ]; }
	if ( preg_match( '/steer|freezer|finish|beef|calf|calves|wean/', $t ) ) { return [ 'steers', 'keyword' ]; }
	if ( preg_match( '/cow|pair|bred|female|mama|dam/', $t ) ) { return [ 'cows', 'keyword' ]; }
	return [ $default, 'review' ];
};

$say( '# Listing classes (' . ( $dry ? 'DRY RUN' : 'live' ) . ') — ' . gmdate( 'Y-m-d H:i' ) . ' UTC' );
$say( '' );
$say( '## 1. Terms' );
$rename( $ltax, 'feeders', 'Steers' );
$rename( $otax, 'feeders', 'Steers' );
$ids = [];
foreach ( [ 'Bulls', 'Cows', 'Heifers', 'Steers', 'Semen' ] as $i => $name ) {
	$ids[ sanitize_title( $name ) ] = $ensure( $ltax, $name, $i );
	$ensure( $otax, $name, $i );
}

$say( '' );
$say( '## 2. Listings' );
$moves  = [];
$review = [];
$old    = [ 'females', 'finishers', 'calves' ];
foreach ( $old as $slug ) {
	$t = $term( $ltax, $slug );
	if ( ! $t ) { continue; }
	// SQL, not get_posts(): `sp_expired` is exclude_from_search, so 'post_status' => 'any' would skip expired listings.
	$posts = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$p}term_relationships tr JOIN {$p}posts po ON po.ID = tr.object_id AND po.post_type = 'sp_listing' WHERE tr.term_taxonomy_id = %d", $t->term_taxonomy_id ) ) );
	foreach ( $posts as $pid ) {
		$title = (string) get_the_title( $pid );
		$oc    = $old_cat( (int) $pid );
		if ( 'females' === $slug ) {
			if ( 'cows' === $oc ) { [ $to, $why ] = [ 'cows', 'old category cows' ]; }
			elseif ( 'heifers' === $oc ) { [ $to, $why ] = [ 'heifers', 'old category heifers' ]; }
			elseif ( 'pairs' === $oc ) { [ $to, $why ] = [ 'cows', 'old category pairs' ]; }
			elseif ( preg_match( '/heifer/i', $title ) ) { [ $to, $why ] = [ 'heifers', 'keyword' ]; }
			elseif ( preg_match( '/cow|pair|bred|female|mama|dam\b/i', $title ) ) { [ $to, $why ] = [ 'cows', 'keyword' ]; }
			else { [ $to, $why ] = [ 'cows', 'review' ]; } // a Female is a cow or a heifer, never a steer
		} elseif ( 'finishers' === $slug ) {
			[ $to, $why ] = [ 'steers', 'finishers → steers' ];
		} else {
			[ $to, $why ] = $by_keyword( $title, 'steers' );
			if ( 'review' === $why ) { $why = 'calves → steers (default)'; }
		}
		$moves[] = [ (int) $pid, $slug, $to, $why, $title ];
		if ( 'review' === $why ) { $review[] = [ (int) $pid, $title, $to ]; }
	}
}
$counts = [];
foreach ( $moves as [ $pid, $from, $to, $why ] ) { $counts[ "$from → $to" ] = ( $counts[ "$from → $to" ] ?? 0 ) + 1; }
foreach ( $counts as $k => $n ) { $say( "- $k: $n" ); }
if ( ! $moves ) { $say( '- nothing to move' ); }
if ( ! $dry ) {
	foreach ( $moves as [ $pid, $from, $to ] ) {
		$current = wp_get_object_terms( $pid, $ltax, [ 'fields' => 'ids' ] );
		$from_id = (int) $term( $ltax, $from )->term_id;
		$new     = array_values( array_unique( array_merge( array_diff( array_map( 'intval', $current ), [ $from_id ] ), [ (int) $ids[ $to ] ] ) ) );
		wp_set_object_terms( $pid, $new, $ltax );
	}
}
$say( '' );
$say( '### Review (category guessed from the title — confirm with the farm or the office)' );
foreach ( $review as [ $pid, $title, $to ] ) { $say( "- #$pid → $to: " . $title ); }
if ( ! $review ) { $say( '- none' ); }

$say( '' );
$say( '## 3. Farm offerings' );
$omap = [ 'females' => [ 'cows', 'heifers' ], 'finishers' => [ 'steers' ], 'calves' => [ 'steers', 'heifers' ] ];
foreach ( $omap as $from => $targets ) {
	$f = $term( $otax, $from );
	if ( ! $f ) { continue; }
	$farm_ids = $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$p}term_relationships tr JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy=%s AND tt.term_id=%d", $otax, $f->term_id ) );
	$say( "- $from → " . implode( ' + ', $targets ) . ': ' . count( $farm_ids ) . ' farm(s)' );
	if ( $dry ) { continue; }
	foreach ( $farm_ids as $fid ) {
		$cur = array_map( 'intval', wp_get_object_terms( (int) $fid, $otax, [ 'fields' => 'ids' ] ) );
		$add = array_map( static fn( $s ) => (int) get_term_by( 'slug', $s, $otax )->term_id, $targets );
		wp_set_object_terms( (int) $fid, array_values( array_unique( array_merge( array_diff( $cur, [ (int) $f->term_id ] ), $add ) ) ), $otax );
	}
}

$say( '' );
$say( '## 4. Retire empty old terms' );
foreach ( [ $ltax => $old, $otax => array_keys( $omap ) ] as $tax => $slugs ) {
	foreach ( $slugs as $slug ) {
		$t = $term( $tax, $slug );
		if ( ! $t ) { continue; }
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}term_relationships WHERE term_taxonomy_id=%d", $t->term_taxonomy_id ) );
		if ( $dry ) { $say( "- $tax \"{$t->name}\": " . ( $left ? "would still hold $left row(s) after the moves above (deleted only when empty)" : 'delete' ) ); continue; }
		if ( $left ) { $say( "- $tax \"{$t->name}\" kept: $left row(s) still attached" ); continue; }
		wp_delete_term( $t->term_id, $tax );
		$say( "- $tax \"{$t->name}\" deleted" );
	}
}

$say( '' );
$say( '## 5. Saved searches + redirects' );
$slug_map = [ 'females' => 'cows', 'feeders' => 'steers', 'finishers' => 'steers', 'calves' => 'steers' ];
$n = 0;
foreach ( $wpdb->get_results( "SELECT id, params FROM {$p}sp_saved_searches" ) ?: [] as $row ) {
	$params = json_decode( (string) $row->params, true );
	if ( empty( $params['cats'] ) || ! is_array( $params['cats'] ) ) { continue; }
	$cats = array_values( array_unique( array_map( static fn( $c ) => $slug_map[ $c ] ?? $c, $params['cats'] ) ) );
	if ( $cats === array_values( $params['cats'] ) ) { continue; }
	$n++;
	if ( ! $dry ) { $params['cats'] = $cats; $wpdb->update( "{$p}sp_saved_searches", [ 'params' => wp_json_encode( $params ) ], [ 'id' => $row->id ] ); }
}
$say( "- saved searches rewritten: $n" );
$redir = sp_membership()->get( \SouthPoll\Membership\Routing\Redirects::class );
$urls  = sp_membership()->get( \SouthPoll\Membership\Routing\Urls::class );
$base  = rtrim( (string) wp_parse_url( $urls->classifieds(), PHP_URL_PATH ), '/' );
foreach ( $slug_map as $from => $to ) {
	// Served by the `retired-category-pattern` rule in Routing\Redirects (code, so migrate-redirects.php rebuilds cannot lose it).
	$hit = $redir->resolve( "$base/$from/" );
	$say( "- redirect $base/$from/ → " . ( $hit ? $hit['to'] . " ({$hit['rule']})" : 'NONE — check Routing\\Redirects' ) );
}

if ( ! $dry ) {
	clean_term_cache( [], $ltax ); clean_term_cache( [], $otax );
	wp_cache_flush();
	sp_membership()->get( ListingTypes::class )->categories(); // warm
	$say( '' );
	$say( '## After' );
	foreach ( get_terms( [ 'taxonomy' => $ltax, 'hide_empty' => false ] ) as $t ) { $say( sprintf( '- listing %s (%s) #%d count=%d order=%s', $t->name, $t->slug, $t->term_id, $t->count, (string) get_term_meta( $t->term_id, 'sp_order', true ) ) ); }
	foreach ( get_terms( [ 'taxonomy' => $otax, 'hide_empty' => false ] ) as $t ) { $say( sprintf( '- offering %s (%s) #%d count=%d', $t->name, $t->slug, $t->term_id, $t->count ) ); }
	$dir = dirname( __DIR__ ) . '/migration/reports';
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
	file_put_contents( $dir . '/recategorize-' . gmdate( 'Ymd-His' ) . '.md', implode( "\n", $out ) . "\n" );
	$say( '' );
	$say( 'Next: ./wp-run.sh migrate-redirects.php   (rebuild the legacy /listing-category/ rows)' );
}
