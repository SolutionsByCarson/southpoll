<?php
/**
 * report-listing-classes.php — read-only: listing categories + offerings terms with counts, and for every migrated listing the
 * old RTCL category (from the legacy post's term relationships) so the S5 recategorisation can be planned / verified.
 *
 * Run: ./wp-run.sh report-listing-classes.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
global $wpdb;
$p = $wpdb->prefix;

echo "## sp_listing_category terms\n\n";
foreach ( $wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.count, (SELECT meta_value FROM {$p}termmeta m WHERE m.term_id=t.term_id AND m.meta_key='sp_order') AS ord FROM {$p}terms t JOIN {$p}term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy='sp_listing_category' ORDER BY ord+0, t.name" ) as $r ) {
	printf( "- #%d %s (%s) count=%d order=%s\n", $r->term_id, $r->name, $r->slug, $r->count, (string) $r->ord );
}
echo "\n## offerings taxonomy terms\n\n";
$tax = $wpdb->get_var( "SELECT taxonomy FROM {$p}term_taxonomy WHERE taxonomy LIKE 'sp_offer%' LIMIT 1" );
echo "taxonomy: " . (string) $tax . "\n";
if ( $tax ) {
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.count FROM {$p}terms t JOIN {$p}term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s ORDER BY t.name", $tax ) ) as $r ) {
		printf( "- #%d %s (%s) count=%d\n", $r->term_id, $r->name, $r->slug, $r->count );
	}
}
echo "\n## listings by new category × old RTCL category (migrated only) \n\n";
$rows = $wpdb->get_results( "
	SELECT nt.slug AS new_cat, COALESCE(ot.slug,'(none)') AS old_cat, COUNT(DISTINCT l.ID) AS n
	FROM {$p}posts l
	JOIN {$p}postmeta lm ON lm.post_id=l.ID AND lm.meta_key='_sp_legacy_id'
	LEFT JOIN {$p}term_relationships nr ON nr.object_id=l.ID
	LEFT JOIN {$p}term_taxonomy ntt ON ntt.term_taxonomy_id=nr.term_taxonomy_id AND ntt.taxonomy='sp_listing_category'
	LEFT JOIN {$p}terms nt ON nt.term_id=ntt.term_id
	LEFT JOIN {$p}term_relationships orr ON orr.object_id=lm.meta_value+0
	LEFT JOIN {$p}term_taxonomy ott ON ott.term_taxonomy_id=orr.term_taxonomy_id AND ott.taxonomy='rtcl_category'
	LEFT JOIN {$p}terms ot ON ot.term_id=ott.term_id
	WHERE l.post_type='sp_listing' AND nt.slug IS NOT NULL
	GROUP BY nt.slug, ot.slug ORDER BY nt.slug, n DESC" );
foreach ( $rows as $r ) { printf( "- %s ← %s: %d\n", $r->new_cat, $r->old_cat, $r->n ); }
echo "\n## non-migrated listings by category\n\n";
foreach ( $wpdb->get_results( "
	SELECT nt.slug AS new_cat, l.post_status, COUNT(*) n FROM {$p}posts l
	JOIN {$p}term_relationships nr ON nr.object_id=l.ID JOIN {$p}term_taxonomy ntt ON ntt.term_taxonomy_id=nr.term_taxonomy_id AND ntt.taxonomy='sp_listing_category' JOIN {$p}terms nt ON nt.term_id=ntt.term_id
	WHERE l.post_type='sp_listing' AND NOT EXISTS (SELECT 1 FROM {$p}postmeta m WHERE m.post_id=l.ID AND m.meta_key='_sp_legacy_id')
	GROUP BY nt.slug, l.post_status ORDER BY nt.slug" ) as $r ) { printf( "- %s [%s]: %d\n", $r->new_cat, $r->post_status, $r->n ); }
echo "\n## females with unknown old category — titles\n\n";
foreach ( $wpdb->get_results( "
	SELECT l.ID, l.post_title, l.post_status FROM {$p}posts l
	JOIN {$p}term_relationships nr ON nr.object_id=l.ID JOIN {$p}term_taxonomy ntt ON ntt.term_taxonomy_id=nr.term_taxonomy_id AND ntt.taxonomy='sp_listing_category' JOIN {$p}terms nt ON nt.term_id=ntt.term_id AND nt.slug='females'
	WHERE l.post_type='sp_listing' AND NOT EXISTS (
		SELECT 1 FROM {$p}postmeta lm JOIN {$p}term_relationships orr ON orr.object_id=lm.meta_value+0 JOIN {$p}term_taxonomy ott ON ott.term_taxonomy_id=orr.term_taxonomy_id AND ott.taxonomy='rtcl_category'
		WHERE lm.post_id=l.ID AND lm.meta_key='_sp_legacy_id')
	ORDER BY l.ID" ) as $r ) { printf( "- #%d [%s] %s\n", $r->ID, $r->post_status, $r->post_title ); }
