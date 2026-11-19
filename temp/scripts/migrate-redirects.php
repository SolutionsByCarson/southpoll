<?php
/**
 * migrate-redirects.php — T5.5: fill `new_url` for every row of research/rtcl-urls.csv, write
 * temp/migration/redirect-map.csv, and load the exact rows into sp_redirects (table used by Routing\Redirects).
 *
 *   ./wp-run.sh migrate-redirects.php --dry-run    compute + write the CSV only
 *   ./wp-run.sh migrate-redirects.php              also (re)load sp_redirects
 *   ./wp-run.sh migrate-redirects.php --test       resolve every old path through Routing\Redirects and check the target exists
 * Run after migrate-farms.php and migrate-listings.php (targets come from legacy ids).
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Routing\Redirects;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;

global $wpdb;
$p     = $wpdb->prefix;
$o     = sp_mig_opts();
$r     = new SpMigReport( 'migrate-redirects', $o['dry'] );
$urls  = sp_membership()->get( Urls::class );
$redir = sp_membership()->get( Redirects::class );
$src   = dirname( __DIR__ ) . '/research/rtcl-urls.csv';
$out   = dirname( __DIR__ ) . '/migration/redirect-map.csv';
$cat_map = sp_mig_category_map();
$fh    = fopen( $src, 'r' );
$head  = fgetcsv( $fh );
$rows  = [];
while ( ( $row = fgetcsv( $fh ) ) !== false ) { $rows[] = array_combine( $head, array_pad( $row, count( $head ), '' ) ); }
fclose( $fh );

$static = [
	'/my-account/' => $urls->dashboard(), '/my-account/listings/' => $urls->my_listings(), '/my-account/my-store/' => $urls->my_farm(), '/my-account/favourites/' => $urls->my_favorites(),
	'/my-account/edit-account/' => $urls->dashboard( 'account-details' ), '/my-account/payments/' => $urls->dashboard( 'manage-membership' ), '/my-account/lost-password/' => $urls->reset_password(),
	'/my-account/logout/' => $urls->logout(), '/my-account/chat/' => $urls->dashboard(), '/my-account/subscriptions/' => $urls->dashboard( 'manage-membership' ), '/my-account/membership/' => $urls->dashboard( 'manage-membership' ),
	'/checkout/' => $urls->dashboard( 'manage-membership' ), '/listings/' => $urls->classifieds(), '/stores/' => $urls->directory(), '/listing-form/' => $urls->edit_listing(), '/post-an-ad/' => $urls->edit_listing(), '/register/' => $urls->register(), '/login/' => $urls->login(),
];
$state_slugs = []; foreach ( Str::us_states() as $code => $name ) { $state_slugs[ sanitize_title( $name ) ] = $code; }

$target = function ( array $row ) use ( $urls, $static, $cat_map, $state_slugs, $wpdb, $p ): array {
	$path  = (string) $row['path'];
	$group = (string) $row['group'];
	$lower = strtolower( $path );
	static $static_sorted = null;
	if ( null === $static_sorted ) { $static_sorted = $static; uksort( $static_sorted, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) ); } // longest prefix first: /my-account/my-store/ before /my-account/
	foreach ( $static_sorted as $from => $to ) { if ( str_starts_with( $lower, $from ) ) { return [ $to, 'static' ]; } }
	switch ( $group ) {
		case 'farm':
		case 'farm-old-slug':
			$farm = sp_mig_farm_by_legacy( (int) $row['object_id'] );
			return $farm ? [ $urls->farm( (string) $farm->slug ), 'farm' ] : [ $urls->directory(), 'farm-missing' ];
		case 'listing':
		case 'listing-old-slug':
			$lid = sp_mig_listing_by_legacy( (int) $row['object_id'] );
			$post = $lid ? get_post( $lid ) : null;
			if ( $post && 'publish' === $post->post_status ) { return [ $urls->listing( $post->post_name ), 'listing' ]; }
			$farm = $post ? $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM {$p}sp_farms WHERE id = %d", (int) get_post_meta( $lid, '_sp_farm_id', true ) ) ) : null;
			return $farm ? [ $urls->farm( (string) $farm->slug ), 'listing-expired→farm' ] : [ $urls->classifieds(), 'listing-missing' ];
		case 'taxonomy-archive':
			if ( preg_match( '#^/listing-category/([^/]+)/#', $lower, $m ) ) { return isset( $cat_map[ $m[1] ] ) ? [ $urls->category( $cat_map[ $m[1] ] ), 'category' ] : [ $urls->classifieds(), 'category-unmapped' ]; }
			if ( preg_match( '#^/listing-location/([^/]+)/#', $lower, $m ) ) { return isset( $state_slugs[ $m[1] ] ) ? [ $urls->classifieds( [ 'state' => $state_slugs[ $m[1] ] ] ), 'location' ] : [ $urls->classifieds(), 'location-unmapped' ]; }
			if ( str_starts_with( $lower, '/store-category/' ) ) { return [ $urls->directory(), 'store-category' ]; }
			return [ $urls->classifieds(), 'taxonomy-other' ];
		case 'archive':
			return [ str_contains( $lower, 'store' ) ? $urls->directory() : $urls->classifieds(), 'archive' ];
		case 'stale-404':
			$inner = preg_replace( '#^/website-faq#', '', $lower );
			foreach ( $static as $from => $to ) { if ( str_starts_with( $inner, $from ) ) { return [ $to, 'stale-404' ]; } }
			return [ $urls->home( 'website-faq/' ), 'stale-404-faq' ];
		case 'api':
			return [ '', 'api-none' ];
		case 'page':
			// Old plugin pages are covered by the static map above (/my-account/, /checkout/). The other pages in the inventory
			// (Classifieds, Members, Website FAQ, Join) stay live as the plugin's host pages / content pages: no redirect.
			if ( str_starts_with( $lower, '/submit-a-listing/' ) ) { return [ $urls->edit_listing(), 'page-listing-form' ]; }
			$pg = (int) $row['object_id'] ? get_post( (int) $row['object_id'] ) : null;
			if ( $pg && 'publish' === $pg->post_status ) { return [ '', 'page-kept' ]; }
			return [ $urls->dashboard(), 'page' ];
		case 'listing-form':
			return [ $urls->edit_listing(), 'listing-form' ];
		default:
			return [ $urls->dashboard(), $group ];
	}
};

$r->h( 'Mapping' );
$fh = fopen( $out, 'w' );
fputcsv( $fh, array_merge( $head, [ 'rule' ] ) );
$loaded = 0; $skipped = 0; $dupes = 0;
$first  = []; // normalised path → target of the first row that claimed it (CSV order: real objects before stale-404 rows)
$norm   = static fn( string $path ): string => '/' . trim( strtolower( rawurldecode( $path ) ), '/' ) . '/'; // same normalisation as Routing\Redirects::resolve()
if ( ! $o['dry'] && ! sp_mig_flag( 'test' ) ) { $wpdb->query( "DELETE FROM {$p}sp_redirects" ); }
foreach ( $rows as $row ) {
	[ $to, $rule ] = $target( $row );
	if ( preg_match( '#/rtcl-auto-temp-\d+/?$#', (string) $row['path'] ) ) { [ $to, $rule ] = [ '', 'skip-temp-slug' ]; } // RTCL placeholder slug shared by several listings — never a real link
	$row['new_url'] = $to;
	fputcsv( $fh, array_merge( array_values( $row ), [ $rule ] ) );
	$r->count( 'rule-' . $rule );
	if ( '' === $to ) { $skipped++; continue; }
	if ( 'pattern' === $row['pattern_or_example'] ) { continue; } // patterns are handled in Routing\Redirects
	$from = $norm( (string) $row['path'] );
	if ( $norm( (string) wp_parse_url( $to, PHP_URL_PATH ) ) === $from ) { $r->count( 'self-redirect-skipped' ); continue; } // never redirect a path to itself
	if ( isset( $first[ $from ] ) ) { $dupes++; continue; } // first row wins (a farm beats a logged 404 for the same path)
	$first[ $from ] = $to;
	if ( ! $o['dry'] && ! sp_mig_flag( 'test' ) ) {
		$wpdb->replace( "{$p}sp_redirects", [ 'from_path' => $from, 'to_url' => $to, 'status' => 301, 'created_at' => current_time( 'mysql', true ) ] );
		$loaded++;
	}
}
fclose( $fh );
$r->line( 'rows: ' . count( $rows ) . ', distinct exact paths: ' . count( $first ) . ", loaded into sp_redirects: $loaded, duplicate paths skipped: $dupes, no target (api / temp slug): $skipped" );
$r->line( "map written: $out" );

if ( sp_mig_flag( 'test' ) ) {
	$r->h( 'Resolver test' );
	$ok = 0; $bad = 0;
	foreach ( $rows as $row ) {
		if ( 'pattern' === $row['pattern_or_example'] ) { continue; }
		$hit  = $redir->resolve( (string) $row['path'] );
		$want = $first[ $norm( (string) $row['path'] ) ] ?? ''; // what the table holds for this path (first row wins; temp slugs / api rows have none)
		if ( '' === $want ) { continue; }
		if ( ! $hit || $hit['to'] !== $want ) { $bad++; $r->flag( 'resolve-mismatch', "{$row['path']} → " . ( $hit['to'] ?? 'null' ) . " (want $want)" ); continue; }
		// Target must exist: farm slugs / listing slugs are checked against the tables.
		$path = (string) wp_parse_url( $want, PHP_URL_PATH );
		if ( preg_match( '#/farms/([^/]+)/#', $path, $m ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}sp_farms WHERE slug = %s", $m[1] ) ) ) { $bad++; $r->flag( 'target-missing', "$want" ); continue; }
		if ( preg_match( '#/classifieds/listing/([^/]+)/#', $path, $m ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sp_listing' AND post_name = %s AND post_status = 'publish'", $m[1] ) ) ) { $bad++; $r->flag( 'target-missing', "$want" ); continue; }
		$ok++;
	}
	foreach ( [ '/rtcl_listing/does-not-exist/', '/store/99999/', '/listing-category/cows/', '/listing-category/heifers/', '/listing-category/steers/', '/classifieds/females/', '/classifieds/feeders/', '/listing-location/missouri/', '/my-account/my-store/', '/checkout/membership/' ] as $probe ) { $hit = $redir->resolve( $probe ); $r->line( "- pattern $probe → " . ( $hit ? $hit['to'] . " ({$hit['rule']})" : 'none' ) ); }
	$r->line( "resolved ok: $ok, problems: $bad" );
}
$r->finish();
