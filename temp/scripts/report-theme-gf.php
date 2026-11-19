<?php
/**
 * report-theme-gf.php — Pro/X theme design option values, site icon/logo, Gravity Forms field labels,
 * Cornerstone header/footer ids.
 * Run: ./wp-run.sh report-theme-gf.php
 */
global $wpdb;
$p = $wpdb->prefix;

echo "## Pro / X theme design options (x_*)\n\n";
$keys = $wpdb->get_col( "SELECT option_name FROM {$p}options WHERE option_name LIKE 'x\_%' AND (option_name LIKE '%font%' OR option_name LIKE '%color%' OR option_name LIKE '%button%' OR option_name LIKE '%layout%' OR option_name LIKE '%letter_spacing%' OR option_name LIKE '%root_font%' OR option_name LIKE '%navbar%' OR option_name LIKE '%logo%' OR option_name LIKE '%design%' OR option_name LIKE '%footer_content%' OR option_name LIKE '%breakpoint%' OR option_name LIKE '%stack%' OR option_name LIKE '%integrity%') AND option_name NOT LIKE 'x_woocommerce%' AND option_name NOT LIKE 'x_ethos%' AND option_name NOT LIKE 'x_renew%' AND option_name NOT LIKE 'x_icon_post%' AND option_name NOT LIKE 'x_buddypress%' AND option_name NOT LIKE 'x_social%' AND option_name NOT LIKE 'x_portfolio%' ORDER BY option_name" );
foreach ( $keys as $k ) { $v = get_option( $k ); if ( $v === '' || $v === null || $v === false ) continue; echo "- `$k` = ", is_scalar( $v ) ? json_encode( $v ) : json_encode( $v ), "\n"; }

echo "\n## Site identity\n\n";
$icon = get_option( 'site_icon' ); echo "- site_icon attachment: $icon → ", $icon ? wp_get_attachment_url( $icon ) : '(none)', "\n";
$logo = get_theme_mod( 'custom_logo' ); echo "- custom_logo (theme mod): ", $logo ? wp_get_attachment_url( $logo ) : '(none)', "\n";
echo "- x_logo: ", json_encode( get_option( 'x_logo' ) ), "\n";

echo "\n## Cornerstone header/footer/templates (tco-data posts)\n\n";
foreach ( $wpdb->get_results( "SELECT ID, post_title, post_type, post_name, post_modified FROM {$p}posts WHERE post_type IN ('cs_header','cs_footer','cs_layout_archive','cs_layout_single','cs_template') ORDER BY post_type, ID" ) as $r ) { echo "- [{$r->ID}] {$r->post_type}: {$r->post_title} ({$r->post_name}) modified {$r->post_modified}\n"; }
echo "\nLayout archive assignments: ", json_encode( get_option( 'cs_assignment_cache_layout_archive' ) ), "\n";
echo "Layout header assignments: ", json_encode( get_option( 'cs_assignment_cache_layout_header' ) ), "\n";
echo "Layout footer assignments: ", json_encode( get_option( 'cs_assignment_cache_layout_footer' ) ), "\n";

// Colors used in header/footer JSON
foreach ( $wpdb->get_results( "SELECT ID, post_title, post_type FROM {$p}posts WHERE post_type IN ('cs_header','cs_footer')" ) as $r ) {
	$data = get_post_meta( $r->ID, '_cornerstone_data', true );
	$json = is_string( $data ) ? $data : json_encode( $data );
	preg_match_all( '/#[0-9a-fA-F]{6}\b|rgba?\([^)]+\)/', $json, $m );
	$counts = array_count_values( array_map( 'strtolower', $m[0] ) ); arsort( $counts );
	echo "\n{$r->post_type} [{$r->ID}] {$r->post_title} colors: ", json_encode( array_slice( $counts, 0, 15 ) ), "\n";
	preg_match_all( '/"(?:[a-z_]*font_family[a-z_]*)":"([^"]+)"/', $json, $f ); echo "  fonts: ", json_encode( array_count_values( $f[1] ?? [] ) ), "\n";
	preg_match_all( '/"(?:[a-z_]*border_radius[a-z_]*)":"([^"]+)"/', $json, $br ); echo "  border radii: ", json_encode( array_slice( array_count_values( $br[1] ?? [] ), 0, 8 ) ), "\n";
}
// Colors used across all Cornerstone pages
$all = '';
foreach ( $wpdb->get_col( "SELECT post_id FROM {$p}postmeta WHERE meta_key='_cornerstone_data'" ) as $pid ) { $d = get_post_meta( $pid, '_cornerstone_data', true ); $all .= is_string( $d ) ? $d : json_encode( $d ); }
preg_match_all( '/#[0-9a-fA-F]{6}\b|rgba?\([^)]+\)/', $all, $m ); $counts = array_count_values( array_map( 'strtolower', $m[0] ) ); arsort( $counts );
echo "\nAll Cornerstone page-builder colors (top 25): ", json_encode( array_slice( $counts, 0, 25 ) ), "\n";
preg_match_all( '/"border_radius":"([^"]+)"/', $all, $br ); $bc = array_count_values( $br[1] ?? [] ); arsort( $bc ); echo "Border radii used: ", json_encode( array_slice( $bc, 0, 10 ) ), "\n";
preg_match_all( '/"(?:[a-z_]*_font_family)":"([^"]+)"/', $all, $f ); $fc = array_count_values( $f[1] ?? [] ); arsort( $fc ); echo "Font families referenced: ", json_encode( $fc ), "\n";
preg_match_all( '/"(?:[a-z_]*_font_size)":"([^"]+)"/', $all, $fs ); $fsc = array_count_values( $fs[1] ?? [] ); arsort( $fsc ); echo "Font sizes used (top 15): ", json_encode( array_slice( $fsc, 0, 15 ) ), "\n";
preg_match_all( '/"(?:[a-z_]*_text_transform)":"([^"]+)"/', $all, $tt ); echo "Text transforms: ", json_encode( array_count_values( $tt[1] ?? [] ) ), "\n";
preg_match_all( '/"(?:[a-z_]*_letter_spacing)":"([^"]+)"/', $all, $ls ); $lsc = array_count_values( $ls[1] ?? [] ); arsort( $lsc ); echo "Letter spacing (top 8): ", json_encode( array_slice( $lsc, 0, 8 ) ), "\n";

echo "\n## Gravity Forms — field labels (forms 1, 2, 5, 6)\n\n";
foreach ( [ 1, 2, 5, 6 ] as $fid ) {
	$meta = $wpdb->get_var( $wpdb->prepare( "SELECT display_meta FROM {$p}gf_form_meta WHERE form_id=%d", $fid ) );
	$f = json_decode( $meta, true );
	if ( ! $f ) { continue; }
	echo "### Form #$fid: {$f['title']}\n";
	foreach ( (array) ( $f['fields'] ?? [] ) as $fld ) { echo "- [{$fld['type']}] ", $fld['label'] ?? $fld['adminLabel'] ?? '', ( ! empty( $fld['isRequired'] ) ? ' *' : '' ), ( ! empty( $fld['choices'] ) ? ' choices: ' . implode( ' | ', array_map( function ( $c ) { return $c['text']; }, array_slice( $fld['choices'], 0, 12 ) ) ) : '' ), "\n"; }
	echo "Confirmations: ", json_encode( array_map( function ( $c ) { return [ $c['type'] ?? '', isset( $c['message'] ) ? mb_strimwidth( wp_strip_all_tags( $c['message'] ), 0, 200, '…' ) : ( $c['url'] ?? $c['pageId'] ?? '' ) ]; }, array_values( (array) ( $f['confirmations'] ?? [] ) ) ) ), "\n";
	echo "Notifications: ", json_encode( array_map( function ( $n ) { return [ $n['name'] ?? '', $n['to'] ?? '', $n['subject'] ?? '' ]; }, array_values( (array) ( $f['notifications'] ?? [] ) ) ) ), "\n\n";
}

echo "\n## Join SPGCA page content (text)\n\n";
$pg = get_post( 51 ); echo wp_strip_all_tags( do_shortcode( $pg->post_content ) ) ? mb_strimwidth( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $pg->post_content ) ) ), 0, 3000, '…' ) : '(cornerstone JSON)', "\n";
$d = get_post_meta( 51, '_cornerstone_data', true ); $j = is_string( $d ) ? $d : json_encode( $d ); preg_match_all( '/"(?:text|content|headline|button_text|anchor_text_primary_content)":"((?:[^"\\\\]|\\\\.){3,})"/', $j, $tx ); echo "\nCornerstone text nodes:\n"; foreach ( array_unique( $tx[1] ) as $t ) { $t = json_decode( '"' . $t . '"' ); echo "- ", mb_strimwidth( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $t ) ) ), 0, 300, '…' ), "\n"; }
preg_match_all( '/"(?:href|anchor_href|url)":"([^"]+)"/', $j, $hr ); echo "Links: ", json_encode( array_values( array_unique( $hr[1] ) ) ), "\n";

echo "\n## Website FAQ page content (text)\n\n";
$d = get_post_meta( 880, '_cornerstone_data', true ); $j = is_string( $d ) ? $d : json_encode( $d ); preg_match_all( '/"(?:text|content|headline|accordion_item_header_content|accordion_item_content|anchor_text_primary_content)":"((?:[^"\\\\]|\\\\.){3,})"/', $j, $tx ); foreach ( array_unique( $tx[1] ) as $t ) { $t = json_decode( '"' . $t . '"' ); echo "- ", mb_strimwidth( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $t ) ) ), 0, 400, '…' ), "\n"; }
preg_match_all( '/"(?:href|anchor_href|url|video_src|src)":"([^"]+)"/', $j, $hr ); echo "Links/media: ", json_encode( array_values( array_unique( $hr[1] ) ) ), "\n";

echo "\n## Members page (49) + Classifieds page (71) Cornerstone text/shortcodes\n\n";
foreach ( [ 49, 71 ] as $pid ) { $d = get_post_meta( $pid, '_cornerstone_data', true ); $j = is_string( $d ) ? $d : json_encode( $d ); preg_match_all( '/\[([a-zA-Z0-9_\-]+)[^\]]*\]/', $j, $sc ); echo "Page $pid shortcodes: ", json_encode( array_values( array_unique( $sc[0] ) ) ), "\n"; preg_match_all( '/"(?:text|content|headline|anchor_text_primary_content)":"((?:[^"\\\\]|\\\\.){3,})"/', $j, $tx ); foreach ( array_unique( $tx[1] ) as $t ) { $t = json_decode( '"' . $t . '"' ); echo "  - ", mb_strimwidth( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $t ) ) ), 0, 200, '…' ), "\n"; } }
