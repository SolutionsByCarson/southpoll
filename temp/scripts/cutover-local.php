<?php
/**
 * cutover-local.php — the database side of the cutover (runbook §2.6 + §3), applied to THIS site (Local, later staging).
 *
 *   ./wp-run.sh cutover-local.php --dry-run     report what would change
 *   ./wp-run.sh cutover-local.php               apply (writes an undo record to the sp_cutover_log option)
 *   ./wp-run.sh cutover-local.php --undo        put everything back from that record
 *
 * Steps (each one idempotent, each one logged for --undo):
 *   1. Builder pages 71 (Classifieds) and 49 (Members): the old [rtcl_listings …] / [member_grid] shortcodes become
 *      [classifieds] / [member-directory] in post_content and in the Cornerstone data (the plugin already renders these two
 *      host pages itself; the swap keeps the builder content honest if someone edits the page).
 *   2. Old Classified Listing pages (My Account #337, Checkout #336, Submit a Listing #646) → draft (their URLs 301 via the
 *      redirect table; drafting keeps them out of search, sitemaps and menus).
 *   3. Menus: mobile "My Account" (#900, pointed at the old My Account page) → /members/login/; a "Member Login" item is added
 *      under "Members" in both menus.
 *   4. Setting legacy_redirects_enabled → on (Routing\Redirects starts 301-ing old URLs).
 *   5. The malformed add-listing rule in the 301 Redirects plugin (wp_redirects #1 → page 646) is removed.
 *   6. Customizer CSS: the `.rtcl-listing-sidebar-info-wrap` rule is dropped.
 *   7. Stripe integration: mode = test; a live publishable key sitting in the *test* slot is cleared (test keys are entered
 *      on SouthPoll → Integrations by hand — never copied from the old plugin's options).
 *   8. Rewrite rules flushed; the Classified Listing plugin family must already be inactive (checked, not changed).
 * File-side changes (child theme, mu-plugins) are done separately — see temp/migration/06-staging-push.md.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;

global $wpdb;
$dry  = in_array( '--dry-run', $argv, true );
$undo = in_array( '--undo', $argv, true );
$opt  = 'sp_cutover_log';
$log  = (array) get_option( $opt, [] );
$settings     = sp_membership()->get( Settings::class );
$integrations = sp_membership()->get( Integrations::class );
$say = fn( string $s ) => print( ( $dry ? '[dry] ' : '' ) . $s . "\n" );

$swaps = [
	71 => [ 'from' => "[rtcl_listings view='list' orderby='data' order='DESC']", 'to' => '[classifieds]' ],
	49 => [ 'from' => '[member_grid]', 'to' => '[member-directory]' ],
	51 => [ 'from' => '/website-faq/', 'to' => '/members/login/?action=register' ], // Join page: "Join Today Via Online" → plugin registration
];
$draft_pages = [ 337 => 'My Account', 336 => 'Checkout', 646 => 'Submit a Listing' ];
$menus       = [ 3 => [ 'parent' => 527, 'label' => 'primary' ], 2 => [ 'parent' => 128, 'label' => 'mobile' ] ];
$login_url   = home_url( '/members/login/' );
$css_rule    = "/\\n*\\.rtcl-listing-sidebar-info-wrap\\s*\\{[^}]*\\}\\n*/";

/* ------------------------------------------------------------------ undo */
if ( $undo ) {
	if ( ! $log ) { echo "nothing to undo (no $opt record)\n"; exit( 0 ); }
	foreach ( (array) ( $log['pages'] ?? [] ) as $id => $old ) {
		$wpdb->update( $wpdb->posts, [ 'post_content' => $old['content'] ], [ 'ID' => (int) $id ] );
		if ( null !== $old['cs'] ) { update_post_meta( (int) $id, '_cornerstone_data', wp_slash( $old['cs'] ) ); }
		clean_post_cache( (int) $id ); echo "page #$id content restored\n";
	}
	foreach ( (array) ( $log['statuses'] ?? [] ) as $id => $status ) { $wpdb->update( $wpdb->posts, [ 'post_status' => $status ], [ 'ID' => (int) $id ] ); clean_post_cache( (int) $id ); echo "page #$id status → $status\n"; }
	foreach ( (array) ( $log['menu_added'] ?? [] ) as $item ) { wp_delete_post( (int) $item, true ); echo "menu item #$item removed\n"; }
	if ( ! empty( $log['menu_900'] ) ) { $m = $log['menu_900']; wp_update_nav_menu_item( 2, 900, [ 'menu-item-object-id' => 337, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-title' => $m['title'], 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $m['parent'], 'menu-item-position' => (int) $m['position'] ] ); echo "menu item #900 → page 337 again\n"; }
	if ( array_key_exists( 'redirects_enabled', $log ) ) { $settings->set( 'legacy_redirects_enabled', (bool) $log['redirects_enabled'] ); echo "legacy_redirects_enabled → " . var_export( (bool) $log['redirects_enabled'], true ) . "\n"; }
	if ( ! empty( $log['eps_rule'] ) ) { $wpdb->insert( "{$wpdb->prefix}redirects", $log['eps_rule'] ); echo "301 Redirects rule restored\n"; }
	if ( array_key_exists( 'custom_css', $log ) ) { wp_update_custom_css_post( (string) $log['custom_css'] ); echo "customizer CSS restored\n"; }
	if ( ! empty( $log['stripe'] ) ) { foreach ( $log['stripe'] as $k => $v ) { $integrations->set( $k, $v ); } echo "stripe integration keys restored\n"; }
	delete_option( $opt );
	flush_rewrite_rules( false );
	echo "undo complete\n";
	exit( 0 );
}

/* ------------------------------------------------------------------ pre-flight */
echo ( $dry ? "## DRY RUN — " : "## " ) . "cutover-local — " . gmdate( 'Y-m-d H:i' ) . " UTC\n";
$active = (array) get_option( 'active_plugins', [] );
$rtcl   = array_filter( $active, fn( $p ) => str_starts_with( $p, 'classified-listing' ) );
if ( $rtcl ) { fwrite( STDERR, "Classified Listing plugins are still active: " . implode( ', ', $rtcl ) . " — deactivate them first (plugins-toggle.php deactivate)\n" ); exit( 1 ); }
if ( ! in_array( 'southpoll-membership/southpoll-membership.php', $active, true ) ) { fwrite( STDERR, "SouthPoll Membership is not active\n" ); exit( 1 ); }
if ( ! $dry && $log ) { echo "already applied (undo record exists); re-applying only what still differs\n"; }
$log = $log ?: [];

/* 1. builder page shortcodes */
foreach ( $swaps as $id => $sw ) {
	$p  = get_post( $id );
	$cs = get_post_meta( $id, '_cornerstone_data', true );
	$cs = is_string( $cs ) ? $cs : null;
	$in_content = $p && str_contains( $p->post_content, $sw['from'] );
	$in_cs      = null !== $cs && str_contains( $cs, $sw['from'] );
	if ( ! $in_content && ! $in_cs ) { $say( "page #$id: nothing to swap (already {$sw['to']}?)" ); continue; }
	$say( "page #$id '{$p->post_title}': {$sw['from']} → {$sw['to']} (" . ( $in_content ? 'content' : '' ) . ( $in_cs ? ' cornerstone' : '' ) . ')' );
	if ( $dry ) { continue; }
	$log['pages'][ $id ] ??= [ 'content' => $p->post_content, 'cs' => $cs ];
	$wpdb->update( $wpdb->posts, [ 'post_content' => str_replace( $sw['from'], $sw['to'], $p->post_content ) ], [ 'ID' => $id ] );
	if ( $in_cs ) { update_post_meta( $id, '_cornerstone_data', wp_slash( str_replace( $sw['from'], $sw['to'], $cs ) ) ); }
	clean_post_cache( $id );
}

/* 2. old plugin pages → draft */
foreach ( $draft_pages as $id => $title ) {
	$st = get_post_status( $id );
	if ( ! $st ) { $say( "page #$id ($title): missing, skipped" ); continue; }
	if ( 'draft' === $st ) { $say( "page #$id ($title): already draft" ); continue; }
	$say( "page #$id ($title): $st → draft" );
	if ( $dry ) { continue; }
	$log['statuses'][ $id ] ??= $st;
	$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $id ] ); clean_post_cache( $id );
}

/* 3. menus */
$item900 = get_post( 900 );
if ( $item900 && 'nav_menu_item' === $item900->post_type ) {
	$type = get_post_meta( 900, '_menu_item_type', true ); $url = get_post_meta( 900, '_menu_item_url', true );
	if ( 'custom' === $type && $url === $login_url ) { $say( 'menu item #900 (mobile My Account): already /members/login/' ); }
	else {
		$say( "menu item #900 (mobile My Account): page 337 → $login_url" );
		if ( ! $dry ) {
			$log['menu_900'] ??= [ 'title' => $item900->post_title, 'parent' => (int) get_post_meta( 900, '_menu_item_menu_item_parent', true ), 'position' => (int) $item900->menu_order ];
			wp_update_nav_menu_item( 2, 900, [ 'menu-item-type' => 'custom', 'menu-item-url' => $login_url, 'menu-item-title' => $item900->post_title ?: 'My Account', 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) get_post_meta( 900, '_menu_item_menu_item_parent', true ), 'menu-item-position' => (int) $item900->menu_order ] );
		}
	}
}
foreach ( $menus as $menu_id => $m ) {
	$exists = array_filter( wp_get_nav_menu_items( $menu_id ) ?: [], fn( $it ) => (int) $it->menu_item_parent === $m['parent'] && $it->url === $login_url );
	if ( $exists ) { $say( "menu '{$m['label']}': Member Login item already present" ); continue; }
	$say( "menu '{$m['label']}': add 'Member Login' → $login_url under item #{$m['parent']}" );
	if ( $dry ) { continue; }
	$new = wp_update_nav_menu_item( $menu_id, 0, [ 'menu-item-title' => 'Member Login', 'menu-item-url' => $login_url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $m['parent'], 'menu-item-position' => 1 ] );
	if ( is_int( $new ) && $new > 0 ) { $log['menu_added'][] = $new; } else { echo "  ! could not add menu item: " . ( is_wp_error( $new ) ? $new->get_error_message() : 'unknown' ) . "\n"; }
}

/* 4. legacy redirects on */
if ( $settings->get( 'legacy_redirects_enabled', false ) ) { $say( 'legacy_redirects_enabled: already on' ); }
else { $say( 'legacy_redirects_enabled: off → on' ); if ( ! $dry ) { $log['redirects_enabled'] ??= false; $settings->set( 'legacy_redirects_enabled', true ); } }

/* 5. 301 Redirects plugin rule */
$eps = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}redirects WHERE url_from LIKE 'my-account/https:%' LIMIT 1", ARRAY_A );
if ( ! $eps ) { $say( '301 Redirects plugin: malformed add-listing rule already gone' ); }
else { $say( "301 Redirects plugin: remove rule #{$eps['id']} '{$eps['url_from']}' → post {$eps['url_to']} (hit {$eps['count']} times; the plugin's redirect table covers /my-account/… now)" ); if ( ! $dry ) { $log['eps_rule'] ??= $eps; $wpdb->delete( "{$wpdb->prefix}redirects", [ 'id' => (int) $eps['id'] ] ); } }

/* 6. customizer css */
$css = (string) wp_get_custom_css();
if ( ! preg_match( $css_rule, $css ) ) { $say( 'customizer CSS: no .rtcl rule left' ); }
else { $say( 'customizer CSS: drop the .rtcl-listing-sidebar-info-wrap rule' ); if ( ! $dry ) { $log['custom_css'] ??= $css; wp_update_custom_css_post( trim( (string) preg_replace( $css_rule, "\n", $css ) ) . "\n" ); } }

/* 7. stripe mode + stray live key in the test slot */
$mode = $integrations->get( 'stripe.mode' );
$tpk  = (string) $integrations->get( 'stripe.test_publishable_key', '' );
if ( 'test' !== $mode ) { $say( "stripe.mode: $mode → test" ); if ( ! $dry ) { $log['stripe']['stripe.mode'] ??= $mode; $integrations->set( 'stripe.mode', 'test' ); } } else { $say( 'stripe.mode: test (ok)' ); }
if ( str_starts_with( $tpk, 'pk_live' ) ) { $say( 'stripe.test_publishable_key: holds a LIVE key → cleared (enter pk_test… on Integrations)' ); if ( ! $dry ) { $log['stripe']['stripe.test_publishable_key'] ??= $tpk; $integrations->set( 'stripe.test_publishable_key', '' ); } }
elseif ( '' === $tpk ) { $say( 'stripe.test_publishable_key: empty — enter the sandbox keys on SouthPoll → Integrations' ); } else { $say( 'stripe.test_publishable_key: set' ); }
$say( 'stripe.test_secret_key: ' . ( '' === (string) $integrations->get( 'stripe.test_secret_key', '' ) ? 'empty — needed for sandbox checkout' : 'set' ) );
$say( 'stripe.webhook_secret: ' . ( '' === (string) $integrations->get( 'stripe.webhook_secret', '' ) ? 'empty — add the endpoint ' . home_url( '/sp-webhook/stripe/' ) . ' in the Stripe sandbox and paste its signing secret' : 'set' ) );

/* 8. persist + flush */
if ( ! $dry ) {
	update_option( $opt, $log, false );
	flush_rewrite_rules( false );
	echo "undo record saved in option $opt; rewrite rules flushed\n";
}
echo "done\n";
