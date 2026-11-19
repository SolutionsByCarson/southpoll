<?php
/**
 * report-rtcl-urls.php — Inventory every URL / endpoint the Classified Listing plugin family exposes on southpoll.com,
 * so each can be redirected to the new plugin's equivalent at cutover.
 *
 * Run: ./wp-run.sh report-rtcl-urls.php
 * Writes: ../research/08-rtcl-urls-and-endpoints.md  and  ../research/rtcl-urls.csv
 *
 * Works with the RTCL plugins active OR inactive (reads settings + posts directly; does not call rtcl()).
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
global $wpdb;
$p   = $wpdb->prefix;
$adv = (array) get_option( 'rtcl_advanced_settings', [] );
$gen = (array) get_option( 'rtcl_general_settings', [] );
$out_md  = realpath( __DIR__ . '/../research' ) . '/08-rtcl-urls-and-endpoints.md';
$out_csv = realpath( __DIR__ . '/../research' ) . '/rtcl-urls.csv';

$md  = [];
$csv = [ [ 'group', 'path', 'pattern_or_example', 'object_type', 'object_id', 'title', 'status', 'owner_user_id', 'notes', 'new_url' ] ];
$add = function ( $group, $path, $type = '', $id = '', $title = '', $status = '', $owner = '', $notes = '', $pattern = 'example' ) use ( &$csv ) {
	$csv[] = [ $group, $path, $pattern, $type, $id, $title, $status, $owner, $notes, '' ];
};
$slug = function ( $id, $fallback ) use ( $wpdb, $p ) { $s = $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$p}posts WHERE ID=%d", $id ) ); return $s ?: $fallback; };

// ---- 1. Settings that define the URL grammar ----
$pages = [
	'myaccount'    => (int) ( $adv['myaccount'] ?? 0 ),
	'checkout'     => (int) ( $adv['checkout'] ?? 0 ),
	'listing_form' => (int) ( $adv['listing_form'] ?? 0 ),
	'listings'     => (int) ( $adv['listings'] ?? 0 ),
	'store'        => (int) ( $adv['store'] ?? 0 ),
	'compare_page' => (int) ( $adv['compare_page'] ?? 0 ),
];
$acc = '/' . $slug( $pages['myaccount'], 'my-account' ) . '/';
$chk = '/' . $slug( $pages['checkout'], 'checkout' ) . '/';
$frm = '/' . $slug( $pages['listing_form'], 'submit-a-listing' ) . '/';
$listing_base  = $adv['permalink'] ?? 'rtcl_listing';
$cat_base      = $adv['category_base'] ?? 'listing-category';
$loc_base      = $adv['location_base'] ?? 'listing-location';
$tag_base      = $adv['tag_base'] ?? 'listing-tag';
$store_base    = $adv['permalink_store'] ?? 'store';
$store_cat     = $adv['store_category_base'] ?? 'store-category';

$md[] = "# Classified Listing URLs & Endpoints (for cutover redirects)";
$md[] = "";
$md[] = "Generated " . date( 'Y-m-d H:i' ) . " by `scripts/report-rtcl-urls.php`. Paths are relative to `https://southpoll.com`. The CSV twin";
$md[] = "`rtcl-urls.csv` has one row per concrete URL/pattern with an empty `new_url` column to fill in once the new plugin's routes exist.";
$md[] = "";
$md[] = "Reference only — the new plugin's own URL design comes from the design document. This file exists so that every old URL a member,";
$md[] = "search engine, or email might still hold gets a 301 to the right new page.";
$md[] = "";
$md[] = "## 1. Settings that defined the URL grammar (`rtcl_advanced_settings`)";
$md[] = "";
$md[] = "| Setting | Value | Effect |";
$md[] = "|---|---|---|";
$md[] = "| `myaccount` page | #{$pages['myaccount']} → `{$acc}` | Account hub + all account endpoints |";
$md[] = "| `checkout` page | #{$pages['checkout']} → `{$chk}` | Checkout + payment endpoints |";
$md[] = "| `listing_form` page | #{$pages['listing_form']} → `{$frm}` | Add/edit listing form |";
$md[] = "| `listings` page | " . ( $pages['listings'] ?: '(none)' ) . " | Not set → listings archive falls back to post-type archive `/listings/` |";
$md[] = "| `store` page | " . ( $pages['store'] ?: '(none)' ) . " | Not set → stores archive falls back to `/stores/` |";
$md[] = "| `permalink` (listing base) | `{$listing_base}` | Single listing `/{$listing_base}/{slug}/` |";
$md[] = "| `category_base` | `{$cat_base}` | `/{$cat_base}/{slug}/` |";
$md[] = "| `location_base` | `{$loc_base}` | `/{$loc_base}/{slug}/` |";
$md[] = "| `tag_base` | `{$tag_base}` | `/{$tag_base}/{slug}/` |";
$md[] = "| `permalink_store` | `{$store_base}` | Farm page `/{$store_base}/{slug}/` |";
$md[] = "| `store_category_base` | `{$store_cat}` | `/{$store_cat}/{slug}/` |";
$md[] = "| `myaccount_store_endpoint` | `" . ( $adv['myaccount_store_endpoint'] ?? 'store' ) . "` | Farm editor under My Account |";
$md[] = "";

// ---- 2. Static pages ----
$md[] = "## 2. Plugin pages (WordPress pages holding RTCL shortcodes)";
$md[] = "";
$md[] = "| Path | Page ID | Shortcode / purpose | Redirect target (fill in) |";
$md[] = "|---|---|---|---|";
$static = [
	[ $acc, $pages['myaccount'], '[rtcl_my_account] — login/register (logged out) or dashboard (logged in)' ],
	[ $chk, $pages['checkout'], '[rtcl_checkout] — no direct use; endpoints below' ],
	[ $frm, $pages['listing_form'], '[rtcl_listing_form] — add listing' ],
	[ '/classifieds/', 71, "[rtcl_listings view='list'] + [classifieds_search] (Cornerstone page)" ],
	[ '/members/', 49, '[member_grid] (child-theme directory shortcode, Cornerstone page)' ],
	[ '/website-faq/', 880, 'Member how-to FAQ (links into my-account endpoints)' ],
	[ '/join-spgca/', 51, 'Join page; "Join Today Via Online" → /website-faq/' ],
];
foreach ( $static as $s ) { $md[] = "| `{$s[0]}` | {$s[1]} | {$s[2]} | |"; $add( 'page', $s[0], 'page', $s[1], get_the_title( $s[1] ), 'publish', '', $s[2] ); }
$md[] = "";

// ---- 3. Account endpoints ----
$md[] = "## 3. My Account endpoints (`{$acc}{endpoint}/`)";
$md[] = "";
$md[] = "| Path | Setting key | Purpose |";
$md[] = "|---|---|---|";
$endpoints = [
	'myaccount_listings_endpoint'         => [ 'listings', 'My Listings table (edit / mark sold / renew / delete). Paginates `/page/N/`.' ],
	'myaccount_store_endpoint'            => [ 'store', 'Farm page editor ("Farm" nav item). Renamed to `my-store` on this site.' ],
	'myaccount_payments_endpoint'         => [ 'payments', 'Payment history. Paginates `/page/N/`.' ],
	'myaccount_edit_account_endpoint'     => [ 'edit-account', 'Account details (name, email, phone, breeder number, password).' ],
	'myaccount_profile_settings_endpoint' => [ 'privacy-settings', 'Profile/privacy settings (show phone/email publicly).' ],
	'myaccount_favourites_endpoint'       => [ 'favourites', 'Favourites (feature disabled on this site).' ],
	'myaccount_lost_password_endpoint'    => [ 'lost-password', 'Lost/reset password form; reset link appends `?key=…&login=…`.' ],
	'myaccount_logout_endpoint'           => [ 'logout', 'Logout (nonce-protected).' ],
	'myaccount_chat_endpoint'             => [ 'chat', 'Chat (feature disabled).' ],
	'myaccount_verify'                    => [ 'verify', 'Email verification (feature disabled).' ],
];
foreach ( $endpoints as $key => $d ) { $ep = $adv[ $key ] ?? $d[0]; $path = $acc . $ep . '/'; $md[] = "| `{$path}` | `{$key}` | {$d[1]} |"; $add( 'account-endpoint', $path, 'endpoint', '', $ep, '', '', $d[1] ); }
$md[] = "| `{$acc}` (logged out) | — | Login + registration forms on one page |";
$md[] = "| `{$acc}?action=register` / `#register` | — | Registration deep link used in some emails |";
$md[] = "";

// ---- 4. Checkout endpoints ----
$md[] = "## 4. Checkout endpoints (`{$chk}{endpoint}/[{id}/]`)";
$md[] = "";
$md[] = "| Path | Purpose |";
$md[] = "|---|---|";
$chk_eps = [
	[ 'checkout_membership_endpoint', 'membership', 'Buy/renew membership — lists pricing options; `?option={pricing_id}` preselects (847 online, 639 pre-paid)' ],
	[ 'checkout_submission_endpoint', 'submission', 'Pay for a listing submission `/submission/{listing_id}/` (not used — free for members)' ],
	[ 'checkout_promote_endpoint', 'promote', 'Promote/renew listing `/promote/{listing_id}/` (renew email links point here)' ],
	[ 'checkout_payment_receipt_endpoint', 'payment-receipt', 'Receipt `/payment-receipt/{order_id}/` (linked from order emails and Stripe return URL)' ],
	[ 'checkout_payment_failure_endpoint', 'payment-failure', 'Failure page `/payment-failure/{order_id}/`' ],
];
foreach ( $chk_eps as $c ) { $ep = $adv[ $c[0] ] ?? $c[1]; $path = $chk . $ep . '/'; $md[] = "| `{$path}` | {$c[2]} |"; $add( 'checkout-endpoint', $path, 'endpoint', '', $ep, '', '', $c[2], 'pattern' ); }
$md[] = "";

// ---- 5. Listing form actions ----
$md[] = "## 5. Listing form URLs (`{$frm}`)";
$md[] = "";
$md[] = "| Path | Purpose |";
$md[] = "|---|---|";
$md[] = "| `{$frm}` | Add new listing |";
$md[] = "| `{$frm}edit/{listing_id}/` | Edit listing (rewrite `submit-a-listing/([^/]+)/([0-9]+)/` → `rtcl_action`, `rtcl_listing_id`) |";
$md[] = "| `{$frm}?rtcl_action=edit&rtcl_listing={id}` | Non-pretty equivalent |";
$add( 'listing-form', $frm, 'page', $pages['listing_form'], 'Submit a Listing', '', '', 'add listing' );
$add( 'listing-form', $frm . 'edit/{listing_id}/', 'endpoint', '', 'edit listing', '', '', 'pretty edit URL', 'pattern' );
$md[] = "";

// ---- 6. Archives ----
$md[] = "## 6. Archive URLs";
$md[] = "";
$md[] = "| Path | Source | Count / notes |";
$md[] = "|---|---|---|";
$md[] = "| `/listings/` (+ `/page/N/`, `/feed/`) | `rtcl_listing` post-type archive (has_archive) | all published listings |";
$add( 'archive', '/listings/', 'post_type_archive', '', 'rtcl_listing archive', '', '', 'has_archive; paginates', 'pattern' );
$md[] = "| `/stores/` (+ `/page/N/`) | `store` post-type archive | all farm pages (not linked in nav; /members/ is used instead) |";
$add( 'archive', '/stores/', 'post_type_archive', '', 'store archive', '', '', 'has_archive', 'pattern' );
foreach ( [ [ 'rtcl_category', $cat_base, 'listing category' ], [ 'rtcl_location', $loc_base, 'listing location (state)' ], [ 'rtcl_tag', $tag_base, 'listing tag' ], [ 'store_category', $store_cat, 'store category' ] ] as $t ) {
	$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.count FROM {$p}terms t JOIN {$p}term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s ORDER BY t.name", $t[0] ) );
	$md[] = "| `/{$t[1]}/{slug}/` | taxonomy `{$t[0]}` ({$t[2]}) | " . count( $terms ) . " terms — listed below |";
	foreach ( $terms as $term ) { $add( 'taxonomy-archive', "/{$t[1]}/{$term->slug}/", $t[0], $term->term_id, $term->name, '', '', "count {$term->count}" ); }
}
$md[] = "| `/{$cat_base}/{cat}/{$loc_base}/{state}/` and `/…/tag/{tag}/` | RTCL combined filter rewrites (`__cat`, `__loc`, `__tag`, `__page` query vars) | pattern only |";
$add( 'archive', "/{$cat_base}/{cat}/{$loc_base}/{state}/", 'combined-filter', '', '', '', '', 'rewrite with __cat/__loc query vars', 'pattern' );
$md[] = "";
$md[] = "### Taxonomy terms";
$md[] = "";
foreach ( [ [ 'rtcl_category', $cat_base ], [ 'store_category', $store_cat ], [ 'rtcl_tag', $tag_base ], [ 'rtcl_location', $loc_base ] ] as $t ) {
	$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.count FROM {$p}terms t JOIN {$p}term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s ORDER BY t.name", $t[0] ) );
	$md[] = "**{$t[0]}** (" . count( $terms ) . "): " . implode( ', ', array_map( function ( $x ) use ( $t ) { return "`/{$t[1]}/{$x->slug}/` ({$x->count})"; }, $terms ) );
	$md[] = "";
}

// ---- 7. Single listings ----
$listings = $wpdb->get_results( "SELECT ID, post_title, post_name, post_status, post_author, post_date FROM {$p}posts WHERE post_type='rtcl_listing' AND post_status IN ('publish','rtcl-expired','rtcl-pending','draft','private') ORDER BY ID" );
$md[] = "## 7. Single listing URLs (`/{$listing_base}/{slug}/`) — " . count( $listings ) . " listings";
$md[] = "";
$md[] = "Rewrite also serves `/{$listing_base}/{slug}/{n}/` (paged), `/embed/`, `/feed/`, `/trackback/`, `/attachment/{slug}/`, `/comment-page-N/`. Old slugs";
$md[] = "(`_wp_old_slug`) are auto-redirected by WordPress today; the new plugin's redirect map must cover them too.";
$md[] = "";
$md[] = "| ID | Path | Title | Status | Owner | Category | State | Old slugs |";
$md[] = "|---|---|---|---|---|---|---|---|";
foreach ( $listings as $l ) {
	$cats = $wpdb->get_col( $wpdb->prepare( "SELECT t.name FROM {$p}term_relationships tr JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$p}terms t ON t.term_id=tt.term_id WHERE tr.object_id=%d AND tt.taxonomy='rtcl_category'", $l->ID ) );
	$locs = $wpdb->get_col( $wpdb->prepare( "SELECT t.name FROM {$p}term_relationships tr JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$p}terms t ON t.term_id=tt.term_id WHERE tr.object_id=%d AND tt.taxonomy='rtcl_location'", $l->ID ) );
	$old  = array_filter( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$p}postmeta WHERE post_id=%d AND meta_key='_wp_old_slug'", $l->ID ) ), function ( $s ) { return $s !== 'rtcl-auto-temp' && $s !== ''; } );
	$path = "/{$listing_base}/{$l->post_name}/";
	$md[] = "| {$l->ID} | `{$path}` | " . str_replace( '|', '\|', $l->post_title ) . " | {$l->post_status} | {$l->post_author} | " . implode( ', ', $cats ) . " | " . implode( ', ', $locs ) . " | " . implode( ', ', array_map( function ( $s ) use ( $listing_base ) { return "`/{$listing_base}/{$s}/`"; }, $old ) ) . " |";
	$add( 'listing', $path, 'rtcl_listing', $l->ID, $l->post_title, $l->post_status, $l->post_author, 'category: ' . implode( '/', $cats ) . '; state: ' . implode( '/', $locs ) );
	foreach ( $old as $s ) { $add( 'listing-old-slug', "/{$listing_base}/{$s}/", 'rtcl_listing', $l->ID, $l->post_title, $l->post_status, $l->post_author, 'old slug (_wp_old_slug) of listing ' . $l->ID ); }
}
$md[] = "";

// ---- 8. Farm pages ----
$stores = $wpdb->get_results( "SELECT ID, post_title, post_name, post_status, post_date FROM {$p}posts WHERE post_type='store' ORDER BY post_title" );
$md[] = "## 8. Farm page URLs (`/{$store_base}/{slug}/`) — " . count( $stores ) . " stores";
$md[] = "";
$md[] = "Rewrite also serves `/{$store_base}/{slug}/page/N/` (listing pagination on the farm page), `/embed/`, `/feed/`, `/attachment/…`. Expired members'";
$md[] = "pages currently render an 'unavailable' notice but still resolve (HTTP 200).";
$untitled = count( array_filter( $stores, function ( $s ) { return trim( $s->post_title ) === ''; } ) );
$md[] = "";
$md[] = "**{$untitled} farm pages have no name** (empty `post_title`, slug = post ID, shown as *(untitled)* below) — members saved the farm form without a";
$md[] = "farm name; the directory shows them as blank cards. Flag for migration review.";
$md[] = "";
$md[] = "| ID | Path | Farm | Owner user | Member expiry | Old slugs |";
$md[] = "|---|---|---|---|---|---|";
foreach ( $stores as $s ) {
	$owner = (int) get_post_meta( $s->ID, 'store_owner_id', true );
	$exp   = $owner ? $wpdb->get_var( $wpdb->prepare( "SELECT expiry_date FROM {$p}rtcl_membership WHERE user_id=%d", $owner ) ) : '';
	$old   = array_filter( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$p}postmeta WHERE post_id=%d AND meta_key='_wp_old_slug'", $s->ID ) ) );
	$path  = "/{$store_base}/{$s->post_name}/";
	$title = trim( $s->post_title ) === '' ? '*(untitled)*' : str_replace( '|', '\|', $s->post_title );
	$md[] = "| {$s->ID} | `{$path}` | {$title} | {$owner} | " . ( $exp ? substr( $exp, 0, 10 ) : '—' ) . " | " . implode( ', ', array_map( function ( $x ) use ( $store_base ) { return "`/{$store_base}/{$x}/`"; }, $old ) ) . " |";
	$add( 'farm', $path, 'store', $s->ID, trim( $s->post_title ) === '' ? '(untitled)' : $s->post_title, $s->post_status, $owner, 'member expiry ' . ( $exp ? substr( $exp, 0, 10 ) : 'n/a' ) . ( trim( $s->post_title ) === '' ? '; UNTITLED farm' : '' ) );
	foreach ( $old as $x ) { $add( 'farm-old-slug', "/{$store_base}/{$x}/", 'store', $s->ID, $s->post_title, $s->post_status, $owner, 'old slug (_wp_old_slug) of store ' . $s->ID ); }
}
$md[] = "";

// ---- 9. API / machine endpoints ----
$md[] = "## 9. API and machine endpoints";
$md[] = "";
$md[] = "| Path | Purpose | Notes |";
$md[] = "|---|---|---|";
$api = [
	[ '/?rtcl-api=rtcl_gateway_stripe', 'Stripe webhook receiver (`rtcl_api_rtcl_gateway_stripe`)', 'Also `/rtcl-api/rtcl_gateway_stripe/`. Must be replaced in the Stripe Dashboard by the new plugin\'s signed webhook URL; keep a 410/redirect so stray events do not 404.' ],
	[ '/?rtcl-api=rtcl_gateway_stripe_webhook', 'Alias of the above', '' ],
	[ '/?rtcl-api=rtcl_gateway_stripe_confirm_payment_intent', 'Stripe PaymentIntent confirmation return', 'Used mid-checkout only' ],
	[ '/?rtcl-api=rtcl_gateway_paypal', 'PayPal IPN (gateway not enabled)', '' ],
	[ '/rtcl-api/{request}/', 'Pretty form of the API endpoint (`add_rewrite_endpoint(\'rtcl-api\')`)', 'Appears on every rewrite base (`/rtcl_listing/{slug}/rtcl-api/...`, `/category/.../rtcl-api/...`)' ],
	[ '/wp-json/rtcl/v1/*', 'Pro REST API (listings, auth, orders, subscriptions, push, reviews, webhooks, my, privacy, store)', 'Disabled by setting `rtcl_app_settings.allow_rest_api = no`; no mobile app in use' ],
	[ '/wp-admin/admin-ajax.php?action=rtcl_*', 'All front-end AJAX (filters, gallery upload, form builder, checkout, inline search, store save)', 'Not URLs to redirect; listed for completeness' ],
	[ '/listings/feed/', 'RSS of listings', 'rtcl_general_settings.rss_feed_number = 10' ],
];
foreach ( $api as $a ) { $md[] = "| `{$a[0]}` | {$a[1]} | {$a[2]} |"; $add( 'api', $a[0], 'api', '', $a[1], '', '', $a[2], 'pattern' ); }
$md[] = "";
// REST routes from source
$routes = [];
foreach ( array_merge( glob( WP_PLUGIN_DIR . '/classified-listing-pro/app/Api/V1/*.php' ) ?: [], glob( WP_PLUGIN_DIR . '/classified-listing-store/app/Api/V1/*.php' ) ?: [] ) as $f ) {
	$src = file_get_contents( $f );
	if ( preg_match_all( "/register_rest_route\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $src, $m ) ) { foreach ( $m[1] as $r ) { $routes[] = basename( $f, '.php' ) . ': `/wp-json/rtcl/v1' . $r . '`'; } }
}
$md[] = "REST routes registered in source (" . count( $routes ) . "):";
$md[] = "";
foreach ( array_unique( $routes ) as $r ) { $md[] = "- " . $r; }
$md[] = "";

// ---- 10. Rewrite rule patterns ----
$rr = (array) get_option( 'rewrite_rules' );
$hits = array_filter( $rr, function ( $v, $k ) { return preg_match( '/rtcl|store|listing|my-account|checkout|submit-a-listing/i', $k . ' ' . $v ); }, ARRAY_FILTER_USE_BOTH );
$md[] = "## 10. Rewrite rules cached in `rewrite_rules` (" . count( $hits ) . " RTCL-related of " . count( $rr ) . ")";
$md[] = "";
$md[] = "These are the exact regexes WordPress matched while the plugins were active. Grouped by prefix; full list in the collapsed block.";
$md[] = "";
$groups = [];
foreach ( $hits as $k => $v ) { $prefix = preg_replace( '#^/?([^/(]+).*$#', '$1', $k ); $groups[ $prefix ][] = [ $k, $v ]; }
$md[] = "| Prefix | Rules | Example |";
$md[] = "|---|---|---|";
foreach ( $groups as $g => $rules ) { $md[] = "| `{$g}` | " . count( $rules ) . " | `" . str_replace( '|', '\|', $rules[0][0] ) . "` |"; }
$md[] = "";
$md[] = "<details><summary>All " . count( $hits ) . " rules</summary>";
$md[] = "";
foreach ( $hits as $k => $v ) { $md[] = "- `" . str_replace( '|', '\|', $k ) . "` → `" . str_replace( '|', '\|', $v ) . "`"; }
$md[] = "";
$md[] = "</details>";
$md[] = "";

// ---- 11. Legacy / stale URLs from the production 404 log ----
$md[] = "## 11. Stale URLs members and bots still request (from `wp-content/southpoll-debug.log`)";
$md[] = "";
$log = WP_CONTENT_DIR . '/southpoll-debug.log';
if ( file_exists( $log ) ) {
	$counts = [];
	foreach ( file( $log ) as $line ) { if ( preg_match( '/uri=([^ |]+)/', $line, $m ) ) { $u = preg_replace( '/\?.*$/', '', $m[1] ); $counts[ $u ] = ( $counts[ $u ] ?? 0 ) + 1; } }
	arsort( $counts );
	$md[] = "| Requested path | 404 count | Likely cause / redirect suggestion |";
	$md[] = "|---|---|---|";
	foreach ( array_slice( $counts, 0, 40, true ) as $u => $c ) {
		$why = '';
		if ( str_starts_with( $u, '/website-faq/my-account' ) ) { $why = 'Relative links on the FAQ page → redirect to `' . str_replace( '/website-faq', '', $u ) . '`'; }
		elseif ( str_contains( $u, '/tel:' ) ) { $why = 'tel: link rendered relative on farm page → none (fix markup)'; }
		elseif ( str_contains( $u, '.env' ) || str_contains( $u, '.map' ) ) { $why = 'Bot probe / source map → none'; }
		elseif ( str_starts_with( $u, '/store/product' ) || $u === '/store/membership' || $u === '/store' || $u === '/store/' ) { $why = 'Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page'; }
		elseif ( str_starts_with( $u, '/my-account/store' ) ) { $why = 'Pre-rename farm editor URL → redirect to new farm editor'; }
		elseif ( str_starts_with( $u, '/my-account/my-store' ) ) { $why = 'POST 404s from the broken store save (fixed by mu-plugin)'; }
		$md[] = "| `{$u}` | {$c} | {$why} |";
		$add( 'stale-404', $u, 'log', '', '', '', '', "404 count {$c}; {$why}" );
	}
} else { $md[] = "_(log not found)_"; }
$md[] = "";

// ---- 12. Existing redirect rules ----
$md[] = "## 12. Existing 301 rules (301 Redirects plugin, `wp_redirects`)";
$md[] = "";
foreach ( $wpdb->get_results( "SELECT * FROM {$p}redirects" ) as $r ) { $md[] = "- `/{$r->url_from}` → " . ( $r->type === 'post' ? "page #{$r->url_to} (`" . get_permalink( (int) $r->url_to ) . "`)" : $r->url_to ) . " ({$r->status}, {$r->count} hits) — retire after launch"; }
$md[] = "";

// ---- 13. Email deep links ----
$md[] = "## 13. URLs embedded in RTCL emails (members may click these months later)";
$md[] = "";
$md[] = "- Listing published / expired / renewal emails → `/{$listing_base}/{slug}/` and `{$chk}promote/{id}/`";
$md[] = "- Order created / completed emails → `{$chk}payment-receipt/{order_id}/`";
$md[] = "- New registration / reset password → `{$acc}` and `{$acc}lost-password/?key=…&login=…`";
$md[] = "- Custom admin membership email → `{site_url}` only";
$md[] = "";

file_put_contents( $out_md, implode( "\n", $md ) );
$fh = fopen( $out_csv, 'w' ); foreach ( $csv as $row ) { fputcsv( $fh, $row ); } fclose( $fh );
echo "Wrote ", $out_md, " (", count( $md ), " lines)\n";
echo "Wrote ", $out_csv, " (", count( $csv ) - 1, " rows)\n";
echo "Listings: ", count( $listings ), " | Stores: ", count( $stores ), " | Rewrite rules: ", count( $hits ), "\n";
