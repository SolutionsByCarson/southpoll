<?php
/**
 * report-inventory.php — Dump a Markdown inventory of the South Poll site's live data.
 * Run: ./wp-run.sh report-inventory.php > ../db-inventory-raw.md
 * Secrets (keys/tokens/passwords) are masked.
 */
global $wpdb;
$p = $wpdb->prefix;

function h( $t ) { echo "\n\n## $t\n\n"; }
function mask_secrets( $v ) {
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			if ( is_string( $k ) && preg_match( '/key|secret|password|token|pass|webhook/i', $k ) && ! preg_match( '/_enable|enable_|^title$|keyword|_key_label/i', $k ) ) {
				$v[ $k ] = ( $x === '' || $x === null ) ? '(empty)' : '***masked(' . strlen( (string) $x ) . ')***';
			} else {
				$v[ $k ] = mask_secrets( $x );
			}
		}
	}
	return $v;
}
function dump( $v ) { echo "```\n", print_r( mask_secrets( $v ), true ), "\n```\n"; }
function table( $rows, $cols = null ) {
	if ( empty( $rows ) ) { echo "_(none)_\n"; return; }
	$rows = array_map( function ( $r ) { return (array) $r; }, $rows );
	$cols = $cols ?: array_keys( $rows[0] );
	echo '| ', implode( ' | ', $cols ), " |\n|", str_repeat( '---|', count( $cols ) ), "\n";
	foreach ( $rows as $r ) {
		echo '| ', implode( ' | ', array_map( function ( $c ) use ( $r ) {
			$x = $r[ $c ] ?? '';
			$x = is_scalar( $x ) ? (string) $x : json_encode( $x );
			return str_replace( [ "|", "\n", "\r" ], [ '\|', ' ', '' ], mb_strimwidth( $x, 0, 160, '…' ) );
		}, $cols ) ), " |\n";
	}
}
function meta_flat( $id ) { return array_map( function ( $v ) { return count( $v ) === 1 ? maybe_unserialize( $v[0] ) : array_map( 'maybe_unserialize', $v ); }, get_post_meta( $id ) ); }

echo "# South Poll — Live DB Inventory (generated ", date( 'Y-m-d H:i' ), ")\n";

h( 'Site basics' );
foreach ( [ 'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email', 'template', 'stylesheet', 'permalink_structure', 'timezone_string', 'gmt_offset', 'date_format', 'users_can_register', 'default_role', 'page_on_front', 'page_for_posts', 'show_on_front', 'rtcl_version', 'rtcl_db_version', 'rtcl_pro_version', 'rtcl_store_version', 'rtcl_store_db_version', 'x_stack', 'x_product_validation_key' ] as $o ) {
	$v = get_option( $o );
	if ( preg_match( '/key/', $o ) ) { $v = $v ? '***masked***' : ''; }
	echo "- **$o**: ", json_encode( $v ), "\n";
}
echo "- **active_plugins**:\n"; foreach ( (array) get_option( 'active_plugins' ) as $pl ) { echo "  - $pl\n"; }

h( 'Users by role' );
$counts = count_users(); echo "Total users: {$counts['total_users']}\n\n";
table( array_map( function ( $r, $c ) { return [ 'role' => $r, 'count' => $c ]; }, array_keys( $counts['avail_roles'] ), $counts['avail_roles'] ) );
h( 'User meta keys (top 60 by usage)' );
table( $wpdb->get_results( "SELECT meta_key, COUNT(*) c FROM {$p}usermeta WHERE meta_key NOT LIKE '%session_tokens%' GROUP BY meta_key ORDER BY c DESC LIMIT 60" ) );
h( 'User registrations per year' );
table( $wpdb->get_results( "SELECT YEAR(user_registered) yr, COUNT(*) c FROM {$p}users GROUP BY yr ORDER BY yr" ) );

h( 'Posts by type / status' );
table( $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) c FROM {$p}posts GROUP BY post_type, post_status ORDER BY post_type, post_status" ) );

h( 'rtcl tables (row counts)' );
foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$p}rtcl%'" ) as $t ) { echo "- `$t`: ", $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), " rows\n"; }
h( 'rtcl_membership — status breakdown' );
$now = current_time( 'mysql' );
echo "- total: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership" ), "\n";
echo "- active flag = 1: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE active=1" ), "\n";
echo "- expiry_date > now: ", $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE expiry_date > %s", $now ) ), "\n";
echo "- expiry_date <= now: ", $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE expiry_date <= %s", $now ) ), "\n";
echo "\nExpiry by month:\n"; table( $wpdb->get_results( "SELECT DATE_FORMAT(expiry_date,'%Y-%m') ym, COUNT(*) c FROM {$p}rtcl_membership GROUP BY ym ORDER BY ym" ) );
echo "\nmember_since by year:\n"; table( $wpdb->get_results( "SELECT YEAR(member_since) yr, COUNT(*) c FROM {$p}rtcl_membership GROUP BY yr ORDER BY yr" ) );
echo "\nSample rows:\n"; table( $wpdb->get_results( "SELECT id, user_id, ads, posted_ads, expiry_date, member_since, active FROM {$p}rtcl_membership ORDER BY id DESC LIMIT 8" ) );
h( 'rtcl_membership_meta keys' );
table( $wpdb->get_results( "SELECT meta_key, COUNT(*) c FROM {$p}rtcl_membership_meta GROUP BY meta_key ORDER BY c DESC" ) );
echo "\nSample meta values (first 5 distinct per key):\n";
foreach ( $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$p}rtcl_membership_meta" ) as $k ) { echo "- **$k**: "; dump( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$p}rtcl_membership_meta WHERE meta_key=%s LIMIT 5", $k ) ) ); }

h( 'Taxonomies — rtcl_category (hierarchy, listing counts)' );
$terms = get_terms( [ 'taxonomy' => 'rtcl_category', 'hide_empty' => false ] );
if ( ! is_wp_error( $terms ) ) {
	$byParent = []; foreach ( $terms as $t ) { $byParent[ $t->parent ][] = $t; }
	$walk = function ( $pid, $d ) use ( &$walk, &$byParent ) { if ( empty( $byParent[ $pid ] ) ) return; foreach ( $byParent[ $pid ] as $t ) { echo str_repeat( '  ', $d ), "- [{$t->term_id}] {$t->name} (slug: {$t->slug}, count: {$t->count})\n"; $walk( $t->term_id, $d + 1 ); } };
	$walk( 0, 0 );
}
h( 'Taxonomies — rtcl_location (top level + totals)' );
echo "- total locations: ", wp_count_terms( [ 'taxonomy' => 'rtcl_location', 'hide_empty' => false ] ), "\n";
table( array_map( function ( $t ) { return [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ]; }, (array) get_terms( [ 'taxonomy' => 'rtcl_location', 'hide_empty' => false, 'parent' => 0 ] ) ) );
h( 'Taxonomies — store_category' );
table( array_map( function ( $t ) { return [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count ]; }, (array) get_terms( [ 'taxonomy' => 'store_category', 'hide_empty' => false ] ) ) );
h( 'Taxonomies — rtcl_tag count' ); echo wp_count_terms( [ 'taxonomy' => 'rtcl_tag', 'hide_empty' => false ] ), "\n";

h( 'rtcl_pricing (membership / listing pricing products)' );
foreach ( get_posts( [ 'post_type' => 'rtcl_pricing', 'post_status' => 'any', 'numberposts' => -1 ] ) as $pr ) { echo "\n### [{$pr->ID}] {$pr->post_title} ({$pr->post_status})\n"; dump( meta_flat( $pr->ID ) ); }

h( 'Custom Field Groups (rtcl_cfg) and Fields (rtcl_cf)' );
foreach ( get_posts( [ 'post_type' => 'rtcl_cfg', 'post_status' => 'any', 'numberposts' => -1 ] ) as $g ) {
	echo "\n### Group [{$g->ID}] {$g->post_title} ({$g->post_status})\n"; dump( meta_flat( $g->ID ) );
	$cats = wp_get_object_terms( $g->ID, 'rtcl_category', [ 'fields' => 'names' ] );
	echo "Categories: ", is_wp_error( $cats ) ? '(err)' : implode( ', ', $cats ), "\n";
	$fields = get_posts( [ 'post_type' => 'rtcl_cf', 'post_status' => 'any', 'numberposts' => -1, 'post_parent' => $g->ID, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
	foreach ( $fields as $f ) { $m = meta_flat( $f->ID ); echo "- **[{$f->ID}] {$f->post_title}** "; dump( $m ); }
}
echo "\nOrphan rtcl_cf (no parent group): ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_type='rtcl_cf' AND post_parent=0" ), "\n";

h( 'Form Builder forms (rtcl form builder, if any)' );
foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$p}rtcl_form%'" ) as $t ) { echo "- `$t`\n"; table( $wpdb->get_results( "SELECT * FROM `$t` LIMIT 5" ) ); }

h( 'rtcl_listing — postmeta keys by usage' );
table( $wpdb->get_results( "SELECT pm.meta_key, COUNT(*) c FROM {$p}postmeta pm JOIN {$p}posts po ON po.ID=pm.post_id WHERE po.post_type='rtcl_listing' GROUP BY pm.meta_key ORDER BY c DESC LIMIT 80" ) );
h( 'rtcl_listing — by status, by category, by month' );
table( $wpdb->get_results( "SELECT post_status, COUNT(*) c FROM {$p}posts WHERE post_type='rtcl_listing' GROUP BY post_status" ) );
echo "\nBy category (published):\n"; table( $wpdb->get_results( "SELECT t.name, COUNT(*) c FROM {$p}posts po JOIN {$p}term_relationships tr ON tr.object_id=po.ID JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$p}terms t ON t.term_id=tt.term_id WHERE po.post_type='rtcl_listing' AND tt.taxonomy='rtcl_category' AND po.post_status='publish' GROUP BY t.name ORDER BY c DESC" ) );
echo "\nCreated per month:\n"; table( $wpdb->get_results( "SELECT DATE_FORMAT(post_date,'%Y-%m') ym, COUNT(*) c FROM {$p}posts WHERE post_type='rtcl_listing' GROUP BY ym ORDER BY ym" ) );
echo "\nAd type (ad_type meta):\n"; table( $wpdb->get_results( "SELECT meta_value, COUNT(*) c FROM {$p}postmeta WHERE meta_key='ad_type' GROUP BY meta_value" ) );
echo "\nPrice type (price_type meta):\n"; table( $wpdb->get_results( "SELECT meta_value, COUNT(*) c FROM {$p}postmeta WHERE meta_key='price_type' GROUP BY meta_value" ) );
echo "\nListing pricing (_rtcl_listing_pricing):\n"; table( $wpdb->get_results( "SELECT meta_value, COUNT(*) c FROM {$p}postmeta WHERE meta_key='_rtcl_listing_pricing' GROUP BY meta_value" ) );
echo "\nListings with gallery images: ", $wpdb->get_var( "SELECT COUNT(DISTINCT post_parent) FROM {$p}posts WHERE post_type='attachment' AND post_parent IN (SELECT ID FROM {$p}posts WHERE post_type='rtcl_listing')" ), " ; total listing attachments: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_type='attachment' AND post_parent IN (SELECT ID FROM {$p}posts WHERE post_type='rtcl_listing')" ), "\n";
echo "\nSample listing titles (latest 10 published):\n"; table( $wpdb->get_results( "SELECT ID, post_title, post_date, post_author FROM {$p}posts WHERE post_type='rtcl_listing' AND post_status='publish' ORDER BY post_date DESC LIMIT 10" ) );
echo "\nDistinct owners with published listings: ", $wpdb->get_var( "SELECT COUNT(DISTINCT post_author) FROM {$p}posts WHERE post_type='rtcl_listing' AND post_status='publish'" ), "\n";
echo "\nSample custom-field values on a recent listing:\n";
$lid = $wpdb->get_var( "SELECT ID FROM {$p}posts WHERE post_type='rtcl_listing' AND post_status='publish' ORDER BY post_date DESC LIMIT 1" );
if ( $lid ) { $m = meta_flat( $lid ); unset( $m['_rtcl_embedding'] ); dump( $m ); echo "Categories: ", implode( ', ', wp_get_object_terms( $lid, 'rtcl_category', [ 'fields' => 'names' ] ) ), " | Locations: ", implode( ', ', wp_get_object_terms( $lid, 'rtcl_location', [ 'fields' => 'names' ] ) ), "\n"; }

h( 'store (farm pages) — status, postmeta keys, addresses' );
table( $wpdb->get_results( "SELECT post_status, COUNT(*) c FROM {$p}posts WHERE post_type='store' GROUP BY post_status" ) );
table( $wpdb->get_results( "SELECT pm.meta_key, COUNT(*) c FROM {$p}postmeta pm JOIN {$p}posts po ON po.ID=pm.post_id WHERE po.post_type='store' GROUP BY pm.meta_key ORDER BY c DESC" ) );
echo "\nStores with logo: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='logo_id' AND meta_value<>'' AND meta_value<>'0'" ), " | with banner: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='banner_id' AND meta_value<>'' AND meta_value<>'0'" ), " | with website: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='website' AND meta_value<>''" ), " | with non-empty details: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_type='store' AND TRIM(post_content)<>''" ), " | with phone: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='phone' AND meta_value<>''" ), " | with email: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='email' AND meta_value<>''" ), " | with social: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='social_media' AND meta_value<>'' AND meta_value NOT LIKE 'a:0:%'" ), "\n";
echo "\nSample address formats (20 random):\n"; table( $wpdb->get_results( "SELECT meta_value address FROM {$p}postmeta WHERE meta_key='address' AND meta_value<>'' ORDER BY RAND() LIMIT 20" ) );
echo "\nStores per owner (owners with >1 store):\n"; table( $wpdb->get_results( "SELECT meta_value owner, COUNT(*) c FROM {$p}postmeta WHERE meta_key='store_owner_id' GROUP BY meta_value HAVING c>1" ) );
echo "\nStores whose owner has no membership row: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta pm WHERE pm.meta_key='store_owner_id' AND pm.meta_value NOT IN (SELECT user_id FROM {$p}rtcl_membership)" ), "\n";
echo "Membership rows whose user has no store: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership m WHERE m.user_id NOT IN (SELECT meta_value FROM {$p}postmeta WHERE meta_key='store_owner_id')" ), "\n";
echo "Active memberships (expiry>now) whose user has no store: ", $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}rtcl_membership m WHERE m.expiry_date > %s AND m.user_id NOT IN (SELECT meta_value FROM {$p}postmeta WHERE meta_key='store_owner_id')", $now ) ), "\n";
echo "Users with breeder number: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}usermeta WHERE meta_key='southpoll_breeder_number' AND meta_value<>''" ), "\n";
echo "Stores created per year:\n"; table( $wpdb->get_results( "SELECT YEAR(post_date) yr, COUNT(*) c FROM {$p}posts WHERE post_type='store' GROUP BY yr" ) );

h( 'rtcl_payment (orders)' );
table( $wpdb->get_results( "SELECT post_status, COUNT(*) c FROM {$p}posts WHERE post_type='rtcl_payment' GROUP BY post_status" ) );
echo "\nBy gateway:\n"; table( $wpdb->get_results( "SELECT pm.meta_value gateway, po.post_status, COUNT(*) c FROM {$p}posts po LEFT JOIN {$p}postmeta pm ON pm.post_id=po.ID AND pm.meta_key='_payment_method' WHERE po.post_type='rtcl_payment' GROUP BY gateway, po.post_status ORDER BY gateway" ) );
echo "\nBy payment_type:\n"; table( $wpdb->get_results( "SELECT pm.meta_value payment_type, COUNT(*) c FROM {$p}posts po LEFT JOIN {$p}postmeta pm ON pm.post_id=po.ID AND pm.meta_key='payment_type' WHERE po.post_type='rtcl_payment' GROUP BY payment_type" ) );
echo "\nCompleted revenue per year (amount meta):\n"; table( $wpdb->get_results( "SELECT YEAR(po.post_date) yr, COUNT(*) orders, SUM(CAST(pm.meta_value AS DECIMAL(10,2))) total FROM {$p}posts po JOIN {$p}postmeta pm ON pm.post_id=po.ID AND pm.meta_key='amount' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' GROUP BY yr" ) );
echo "\nPayment meta keys:\n"; table( $wpdb->get_results( "SELECT pm.meta_key, COUNT(*) c FROM {$p}postmeta pm JOIN {$p}posts po ON po.ID=pm.post_id WHERE po.post_type='rtcl_payment' GROUP BY pm.meta_key ORDER BY c DESC LIMIT 40" ) );
echo "\nLatest 5 payments:\n"; table( $wpdb->get_results( "SELECT po.ID, po.post_date, po.post_status, (SELECT meta_value FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='amount') amount, (SELECT meta_value FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='_payment_method') gw, (SELECT meta_value FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='payment_type') ptype FROM {$p}posts po WHERE po.post_type='rtcl_payment' ORDER BY po.post_date DESC LIMIT 5" ) );

h( 'All rtcl_* options (secrets masked)' );
foreach ( $wpdb->get_col( "SELECT option_name FROM {$p}options WHERE option_name LIKE 'rtcl%' ORDER BY option_name" ) as $on ) { echo "\n### $on\n"; dump( get_option( $on ) ); }

h( 'Pages (all) + shortcode usage' );
$pages = $wpdb->get_results( "SELECT ID, post_title, post_name, post_status, post_content, post_parent FROM {$p}posts WHERE post_type='page' ORDER BY post_title" );
table( array_map( function ( $pg ) { preg_match_all( '/\[([a-zA-Z0-9_\-]+)/', $pg->post_content, $m ); $sc = array_unique( $m[1] ?? [] ); $tpl = get_post_meta( $pg->ID, '_wp_page_template', true ); $cs = get_post_meta( $pg->ID, '_cornerstone_data', true ) ? 'yes' : 'no'; return [ 'ID' => $pg->ID, 'title' => $pg->post_title, 'slug' => $pg->post_name, 'status' => $pg->post_status, 'parent' => $pg->post_parent, 'template' => $tpl, 'cornerstone' => $cs, 'shortcodes' => implode( ',', $sc ), 'len' => strlen( $pg->post_content ) ]; }, $pages ) );

h( 'Nav menus' );
foreach ( wp_get_nav_menus() as $menu ) {
	$locs = array_keys( array_filter( get_nav_menu_locations(), function ( $v ) use ( $menu ) { return $v == $menu->term_id; } ) );
	echo "\n### {$menu->name} (id {$menu->term_id}, locations: ", implode( ',', $locs ), ")\n";
	foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $it ) { echo str_repeat( '  ', $it->menu_item_parent ? 1 : 0 ), "- {$it->title} → {$it->url} ({$it->object}", $it->object_id ? " #{$it->object_id}" : '', ")\n"; }
}

h( 'Gravity Forms' );
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}gf_form'" ) ) { table( $wpdb->get_results( "SELECT id, title, is_active, is_trash, date_created, (SELECT COUNT(*) FROM {$p}gf_entry e WHERE e.form_id=f.id) entries FROM {$p}gf_form f" ) ); }

h( 'Cron events (rtcl_* and sp_*)' );
foreach ( _get_cron_array() as $ts => $hooks ) { foreach ( $hooks as $hook => $ev ) { if ( preg_match( '/rtcl|southpoll|sp_/', $hook ) ) { foreach ( $ev as $e ) { echo "- ", date( 'Y-m-d H:i', $ts ), " $hook (", $e['schedule'] ?? 'single', ")\n"; } } } }

h( 'Theme: Cornerstone / Pro design options (colors, fonts, custom CSS)' );
foreach ( $wpdb->get_col( "SELECT option_name FROM {$p}options WHERE option_name LIKE 'cs_%' OR option_name LIKE 'x_%' OR option_name LIKE 'theme_mods_%' OR option_name LIKE 'cornerstone%' ORDER BY option_name" ) as $on ) { $v = get_option( $on ); echo "- **$on** (", strlen( maybe_serialize( $v ) ), " bytes)\n"; }
foreach ( [ 'cornerstone_color_items', 'cornerstone_font_items', 'cornerstone_font_config', 'cs_v1_custom_css', 'cs_v1_custom_js', 'cs_option_data', 'cs_global_parameter_data', 'cs_global_parameter_json', 'cs_color_items', 'x_custom_css' ] as $on ) {
	$v = get_option( $on );
	if ( $v ) { echo "\n### $on\n"; if ( is_string( $v ) && ( $d = json_decode( $v, true ) ) !== null ) { dump( $d ); } else { dump( $v ); } }
}
$tm = get_option( 'theme_mods_pro-child' ); if ( $tm ) { echo "\n### theme_mods_pro-child\n"; dump( $tm ); }
$tm = get_option( 'theme_mods_pro' ); if ( $tm ) { echo "\n### theme_mods_pro (parent)\n"; dump( $tm ); }

h( 'Widgets / sidebars' );
dump( get_option( 'sidebars_widgets' ) );

h( 'Popup Maker popups' );
table( $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$p}posts WHERE post_type='popup'" ) );

h( 'Redirects (301 redirects plugin)' );
foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$p}redirects%'" ) as $t ) { echo "- `$t`: ", $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), " rows\n"; table( $wpdb->get_results( "SELECT * FROM `$t` LIMIT 30" ) ); }
