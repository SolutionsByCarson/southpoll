<?php
/**
 * lib/migrate-lib.php — shared helpers for the Phase 5 migration scripts (migrate-*.php).
 *
 * Every script: `./wp-run.sh migrate-xxx.php [--dry-run] [--limit=N]`. Dry runs change nothing and print what would
 * happen; real runs write a Markdown report to temp/migration/reports/ and record every row they create in the
 * `sp_migration_log` option so migrate-reset.php can undo a Local run. Nothing here ever sends mail.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Support\Str;

add_filter( 'pre_wp_mail', '__return_true' );
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
ini_set( 'memory_limit', '2048M' );
set_time_limit( 0 );

const SP_MIG_LOG_OPTION = 'sp_migration_log';

/** @return array{dry:bool, limit:int, flags:string[]} */
function sp_mig_opts(): array {
	global $argv;
	$o = [ 'dry' => false, 'limit' => 0, 'flags' => [] ];
	foreach ( (array) $argv as $a ) {
		if ( '--dry-run' === $a ) { $o['dry'] = true; }
		elseif ( str_starts_with( (string) $a, '--limit=' ) ) { $o['limit'] = (int) substr( (string) $a, 8 ); }
		elseif ( str_starts_with( (string) $a, '--' ) ) { $o['flags'][] = substr( (string) $a, 2 ); }
	}
	return $o;
}

function sp_mig_flag( string $flag ): bool {
	return in_array( $flag, sp_mig_opts()['flags'], true );
}

/** Collect report lines (Markdown) and echo as we go. */
final class SpMigReport {
	public array $lines = [];
	public array $counts = [];
	public function __construct( public string $name, public bool $dry ) {
		$this->h( ( $dry ? 'DRY RUN — ' : '' ) . $name . ' — ' . gmdate( 'Y-m-d H:i' ) . ' UTC' );
	}
	public function h( string $t ): void { $this->line( "\n## " . $t ); }
	public function line( string $t ): void { $this->lines[] = $t; echo $t, "\n"; }
	public function count( string $k, int $n = 1 ): void { $this->counts[ $k ] = ( $this->counts[ $k ] ?? 0 ) + $n; }
	public function flag( string $k, string $t ): void { $this->counts[ 'flag:' . $k ] = ( $this->counts[ 'flag:' . $k ] ?? 0 ) + 1; $this->lines[] = "- [$k] $t"; if ( ( $this->counts[ 'flag:' . $k ] ) <= 25 ) { echo "  ! [$k] $t\n"; } }
	public function finish(): string {
		$this->h( 'Totals' );
		ksort( $this->counts );
		foreach ( $this->counts as $k => $n ) { $this->line( "- $k: $n" ); }
		$dir  = dirname( __DIR__, 2 ) . '/migration/reports';
		if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
		$file = $dir . '/' . sanitize_title( $this->name ) . ( $this->dry ? '-dry' : '' ) . '-' . gmdate( 'Ymd-His' ) . '.md';
		file_put_contents( $file, "# " . $this->name . "\n" . implode( "\n", $this->lines ) . "\n" );
		echo "\nReport: $file\n";
		return $file;
	}
}

/* -------------------------------------------------------------- undo log */

function sp_mig_log_add( string $kind, int $id ): void {
	$log = (array) get_option( SP_MIG_LOG_OPTION, [] );
	$log[ $kind ][] = $id;
	update_option( SP_MIG_LOG_OPTION, $log, false );
}

/** Batch-friendly: buffer ids and flush once. */
function sp_mig_log_flush( array $pending ): void {
	if ( ! $pending ) { return; }
	$log = (array) get_option( SP_MIG_LOG_OPTION, [] );
	foreach ( $pending as $kind => $ids ) { $log[ $kind ] = array_values( array_unique( array_merge( (array) ( $log[ $kind ] ?? [] ), array_map( 'intval', $ids ) ) ) ); }
	update_option( SP_MIG_LOG_OPTION, $log, false );
}

function sp_mig_log(): array {
	return (array) get_option( SP_MIG_LOG_OPTION, [] );
}

/** Flush the pending undo ids on shutdown too (by reference), so a crash mid-run never loses what was already created. Flushing is idempotent. */
function sp_mig_autoflush( array &$pending ): void {
	register_shutdown_function( static function () use ( &$pending ): void { sp_mig_log_flush( array_filter( $pending ) ); } );
}

/** Fit a value into a varchar column (multibyte-safe). */
function sp_mig_fit( string $v, int $len ): string {
	return mb_substr( trim( $v ), 0, $len );
}

/* ------------------------------------------------------------- lookups */

/** @return array<string, string> lower-case state name => code */
function sp_mig_state_names(): array {
	static $m = null;
	if ( null === $m ) { $m = []; foreach ( Str::us_states() as $code => $name ) { $m[ strtolower( $name ) ] = $code; } }
	return $m;
}

function sp_mig_state_code( string $raw ): string {
	$raw = trim( $raw, " ,." );
	if ( '' === $raw ) { return ''; }
	if ( preg_match( '/^[A-Za-z]{2}$/', $raw ) ) { $c = strtoupper( $raw ); return isset( Str::us_states()[ $c ] ) ? $c : ''; }
	return sp_mig_state_names()[ strtolower( $raw ) ] ?? '';
}

/** rtcl_location term (id or serialized) → state code. */
/** Term lookups by SQL: the RTCL taxonomies are not registered while the legacy plugins are inactive, so get_term() would fail. */
function sp_mig_term( int $term_id, string $taxonomy ): ?object {
	global $wpdb;
	static $cache = [];
	$key = "$taxonomy:$term_id";
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s", $term_id, $taxonomy ) ) ?: null;
	}
	return $cache[ $key ];
}
/** @return object[] term rows (term_id, name, slug) attached to an object in a taxonomy, by SQL. */
function sp_mig_object_terms( int $object_id, string $taxonomy ): array {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.name", $object_id, $taxonomy ) ) ?: [];
}

function sp_mig_location_state( mixed $raw ): string {
	$ids = is_array( $raw ) ? $raw : ( is_numeric( $raw ) ? [ (int) $raw ] : (array) maybe_unserialize( (string) $raw ) );
	foreach ( (array) $ids as $id ) {
		$t = is_numeric( $id ) ? sp_mig_term( (int) $id, 'rtcl_location' ) : null;
		if ( $t ) { $c = sp_mig_state_code( (string) $t->name ); if ( $c ) { return $c; } }
	}
	return '';
}

/** Old rtcl_category slug → new listing category slug (S5 classes: bulls / cows / heifers / steers / semen). */
function sp_mig_category_map(): array {
	return [ 'bulls' => 'bulls', 'cows' => 'cows', 'heifers' => 'heifers', 'steers' => 'steers', 'semen' => 'semen', 'pairs' => 'cows', 'calves' => 'steers' ];
}

/**
 * Parse a free-text farm address into address1 / city / state / zip with a confidence level.
 *
 * @return array{address1:string, city:string, state:string, zip:string, confidence:string, raw:string}
 */
function sp_mig_parse_address( string $raw ): array {
	$out = [ 'address1' => '', 'city' => '', 'state' => '', 'zip' => '', 'confidence' => 'none', 'raw' => $raw ];
	$s   = trim( preg_replace( '/\s+/', ' ', str_replace( [ "\r", "\n" ], ', ', $raw ) ) );
	$s   = preg_replace( '/\s*,\s*/', ', ', $s );
	$s   = preg_replace( '/,?\s*(USA?|United States)\.?$/i', '', $s );
	if ( '' === $s ) { return $out; }
	// ZIP (anywhere, prefer the last). A 5-digit number at the very start followed by words is a house number, not a ZIP.
	if ( preg_match_all( '/\b(\d{5})(?:-\d{4})?\b/', $s, $zm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( array_reverse( $zm[1] ) as $cand ) {
			if ( 0 === $cand[1] && preg_match( '/^\d{5}(?:-\d{4})?\s+[A-Za-z]/', $s ) ) { continue; }
			$out['zip'] = $cand[0];
			$s = trim( preg_replace( '/\s*\b' . preg_quote( $cand[0], '/' ) . '(?:-\d{4})?\b\s*/', ' ', $s ) );
			break;
		}
	}
	// State: full name, an upper-case 2-letter code (prefer the last), or a mixed-case / dotted code at the very end ("Vernon Al", "Munford Al.", "Mayo, fl").
	$names = sp_mig_state_names();
	$found = null;
	foreach ( $names as $name => $code ) {
		if ( preg_match( '/(?:^|[\s,])(' . preg_quote( $name, '/' ) . ')\.?(?:[\s,]|$)/i', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[1][1];
			if ( null === $found || $pos > $found[1] ) { $found = [ $code, $pos, strlen( $m[1][0] ) ]; }
		}
	}
	if ( null === $found && preg_match_all( '/(?:^|[\s,])([A-Z]{2})\.?(?:[\s,]|$)/', $s, $cm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $cm[1] as $c ) { if ( isset( Str::us_states()[ $c[0] ] ) && ( null === $found || $c[1] > $found[1] ) ) { $found = [ $c[0], $c[1], 2 ]; } }
	}
	if ( null === $found && preg_match( '/(?:^|[\s,])([A-Za-z]{2})\.?[\s,.]*$/', $s, $em, PREG_OFFSET_CAPTURE ) ) {
		$code = strtoupper( $em[1][0] );
		if ( isset( Str::us_states()[ $code ] ) && ! in_array( $code, [ 'CT', 'MT', 'NE' ], true ) ) { $found = [ $code, $em[1][1], 2 ]; } // Ct / Mt / NE are usually "Court" / "Mount" / "northeast"
	}
	if ( $found ) {
		$out['state'] = $found[0];
		$s = trim( substr( $s, 0, $found[1] ) . ' ' . substr( $s, $found[1] + $found[2] ), " ,." );
		$s = trim( preg_replace( '/\s*,\s*$/', '', $s ) );
	}
	// City / street.
	$parts = array_values( array_filter( array_map( 'trim', explode( ',', $s ) ) ) );
	if ( count( $parts ) >= 2 ) {
		$city = array_pop( $parts );
		if ( preg_match( '/\d/', $city ) && ! preg_match( '/\d/', end( $parts ) ?: '' ) ) { // "City, 123 Street" (reversed) — swap
			$tmp = $city; $city = array_pop( $parts ); $parts[] = $tmp;
		}
		$out['city']     = $city;
		$out['address1'] = implode( ', ', $parts );
	} elseif ( 1 === count( $parts ) ) {
		$one = $parts[0];
		if ( preg_match( '/^(.*?\b(?:Rd|Road|St|Street|Dr|Drive|Ln|Lane|Hwy|Highway|Blvd|Ave|Avenue|Ct|Court|Cir|Circle|Trl|Trail|Way|Pike|Rte|Route|Loop|Pkwy|Parkway|Box \d+|CR \d+|FM \d+|County Rd \d+|County Road \d+)\.?(?:\s+\d+)?)\s+(.+)$/i', $one, $pm ) && ! preg_match( '/^\d+$/', trim( $pm[2] ) ) ) {
			$out['address1'] = trim( $pm[1] );
			$out['city']     = trim( $pm[2] );
		} elseif ( preg_match( '/\d/', $one ) ) {
			$out['address1'] = $one;
		} else {
			$out['city'] = $one;
		}
	}
	if ( '' !== (string) $out['city'] && preg_match( '/^\d+$/', (string) $out['city'] ) ) { $out['address1'] = trim( $out['address1'] . ' ' . $out['city'] ); $out['city'] = ''; } // "County Road 3440"
	$out['city'] = preg_replace( '/\s+\d+$/', '', (string) $out['city'] );
	$out['confidence'] = $out['state'] && $out['city'] && $out['zip'] ? 'high' : ( $out['state'] && $out['city'] ? 'medium' : ( $out['state'] ? 'low' : 'none' ) );
	return $out;
}

/** Best-effort name for a user: WP first/last → billing first/last → display name. @return array{first:string,last:string,display:string} */
function sp_mig_user_name( WP_User $u ): array {
	$first = trim( wp_specialchars_decode( (string) ( $u->first_name ?: get_user_meta( $u->ID, '_billing_first_name', true ) ), ENT_QUOTES ) );
	$last  = trim( wp_specialchars_decode( (string) ( $u->last_name ?: get_user_meta( $u->ID, '_billing_last_name', true ) ), ENT_QUOTES ) );
	if ( '' === $first && '' === $last && str_contains( (string) $u->display_name, ' ' ) && ! str_contains( (string) $u->display_name, '@' ) ) {
		[ $first, $last ] = array_pad( explode( ' ', (string) $u->display_name, 2 ), 2, '' );
	}
	return [ 'first' => $first, 'last' => $last, 'display' => trim( $first . ' ' . $last ) ?: (string) $u->display_name ];
}

/** Regenerate attachment sizes so the plugin's sp-* sizes exist (plugin sizes are only built for tagged attachments). */
function sp_mig_adopt_attachment( int $att, int $farm_id, string $purpose, int $listing_id = 0, bool $dry = false ): bool {
	$file = get_attached_file( $att );
	if ( ! $file || ! file_exists( $file ) || ! wp_attachment_is_image( $att ) ) { return false; }
	if ( $dry ) { return true; }
	update_post_meta( $att, '_sp_farm_id', $farm_id );
	update_post_meta( $att, '_sp_purpose', $purpose );
	if ( $listing_id ) { update_post_meta( $att, '_sp_listing_id', $listing_id ); }
	update_post_meta( $att, '_sp_legacy_media', 1 ); // was in the library before migration; reset only untags it
	if ( ! sp_mig_flag( 'no-media' ) ) {
		$meta = wp_generate_attachment_metadata( $att, $file );
		if ( $meta ) { wp_update_attachment_metadata( $att, $meta ); }
	}
	return true;
}

function sp_mig_farm_by_legacy( int $store_id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sp_farms WHERE legacy_store_id = %d", $store_id ) ) ?: null;
}

function sp_mig_farm_for_user( int $uid ): ?object {
	global $wpdb;
	$fid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT farm_id FROM {$wpdb->prefix}sp_farm_users WHERE user_id = %d AND role = 'owner' AND accepted_at IS NOT NULL LIMIT 1", $uid ) );
	return $fid ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sp_farms WHERE id = %d", $fid ) ) : null;
}

function sp_mig_listing_by_legacy( int $old_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sp_legacy_id' AND meta_value = %d LIMIT 1", $old_id ) );
}

function sp_mig_is_staff_user( WP_User $u ): bool {
	return (bool) array_intersect( [ 'administrator', 'editor' ], (array) $u->roles );
}
