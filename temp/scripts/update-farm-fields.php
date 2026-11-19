<?php
/**
 * update-farm-fields.php — bring the farm page fields (sp_meta_fields, scope farm) in line with Nathan's list (S4, 2026-11-18):
 *
 *   Primary Forage Type (forage_type) · Cover Crops (cover_crops) · Supplemental Feed (feeding_program) · Hay Type (hay_type, new)
 *   · Grazing Style (grazing_system) · Additional Info (feeding_notes, textarea)
 *
 * Existing keys keep their key (and therefore every farm's stored value) and are only relabelled / re-ordered; `hay_type` is
 * created when missing; the old Cool Season / Warm Seasons booleans are set inactive (values kept, hidden from the editor and
 * the farm page). The source of truth is `Core\Seeder::DEFAULT_FARM_FIELDS` / `RETIRED_FARM_FIELDS`, so fresh installs and
 * this script agree. Idempotent — safe to run again on staging / production after a push.
 *
 * Run:  ./wp-run.sh update-farm-fields.php [--dry-run]
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Seeder;

$dry   = in_array( '--dry-run', $argv ?? [], true );
$c     = sp_membership();
$repo  = $c->get( MetaFieldRepository::class );
$all   = fn() => $repo->for_scope( 'farm', false );
$table = function ( array $rows ): void {
	printf( "| %-4s | %-16s | %-22s | %-8s | %-6s | %-5s |\n", 'id', 'key', 'label', 'type', 'active', 'order' );
	printf( "|------|------------------|------------------------|----------|--------|-------|\n" );
	foreach ( $rows as $f ) {
		printf( "| %-4d | %-16s | %-22s | %-8s | %-6s | %-5d |\n", (int) $f->id, (string) $f->field_key, (string) $f->label, (string) $f->type, (int) $f->active ? 'yes' : 'no', (int) $f->sort_order );
	}
};

echo "# Farm fields update (" . ( $dry ? 'DRY RUN' : 'live' ) . ") — " . gmdate( 'Y-m-d H:i' ) . " UTC\n\n## Before\n\n";
$table( $all() );

$changes = [];
$order   = [];
foreach ( Seeder::DEFAULT_FARM_FIELDS as [ $key, $label, $type ] ) {
	$f = $repo->find_by_key( 'farm', $key );
	if ( ! $f ) {
		$changes[] = "create `{$key}` \"{$label}\" ({$type})";
		if ( ! $dry ) {
			$f = $repo->create( 'farm', [ 'field_key' => $key, 'label' => $label, 'type' => $type ] );
			if ( is_wp_error( $f ) ) { echo "ERROR creating {$key}: " . $f->get_error_message() . "\n"; exit( 1 ); }
		}
	} else {
		$diff = [];
		if ( (string) $f->label !== $label ) { $diff['label'] = $label; }
		if ( (string) $f->type !== $type ) { $diff['type'] = $type; }
		if ( ! (int) $f->active ) { $diff['active'] = 1; }
		if ( $diff ) {
			$changes[] = "update `{$key}`: " . implode( ', ', array_map( fn( $k, $v ) => "$k \"{$f->$k}\" → \"$v\"", array_keys( $diff ), $diff ) );
			if ( ! $dry ) { $repo->update( (int) $f->id, $diff ); }
		}
	}
	if ( $f && ! is_wp_error( $f ) ) { $order[] = (int) $f->id; }
}
foreach ( Seeder::RETIRED_FARM_FIELDS as $key ) {
	$f = $repo->find_by_key( 'farm', $key );
	if ( $f && (int) $f->active ) {
		$changes[] = "retire `{$key}` \"{$f->label}\" (active → no; stored values kept)";
		if ( ! $dry ) { $repo->update( (int) $f->id, [ 'active' => 0 ] ); }
	}
}
// Order: Nathan's list first, then any staff-added fields in their current order, retired ones last.
foreach ( $all() as $f ) {
	if ( ! in_array( (int) $f->id, $order, true ) && ! in_array( (string) $f->field_key, Seeder::RETIRED_FARM_FIELDS, true ) ) { $order[] = (int) $f->id; }
}
foreach ( $all() as $f ) {
	if ( ! in_array( (int) $f->id, $order, true ) ) { $order[] = (int) $f->id; }
}
$current = array_map( fn( $f ) => (int) $f->id, $all() );
if ( $current !== $order ) {
	$changes[] = 'reorder → ' . implode( ', ', array_map( fn( $id ) => (string) ( $repo->find( $id )->field_key ?? $id ), $order ) );
	if ( ! $dry ) { $repo->reorder( 'farm', $order ); }
}

echo "\n## Changes" . ( $dry ? ' (would apply)' : ' applied' ) . "\n\n";
echo $changes ? '- ' . implode( "\n- ", $changes ) . "\n" : "- none (already up to date)\n";
if ( ! $dry ) {
	echo "\n## After\n\n";
	$table( $all() );
	global $wpdb;
	$t = $wpdb->prefix . 'sp_farm_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE field_key IN ('cool_season','warm_seasons')" ); // phpcs:ignore
		echo "\nRetired-field values still stored (hidden): {$n}\n";
	}
}
