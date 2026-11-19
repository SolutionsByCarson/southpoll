<?php
/**
 * report-forms-subs.php — Decode the RTCL form-builder listing form, inspect subscriptions,
 * listing stats keys, and roles/capabilities.
 * Run: ./wp-run.sh report-forms-subs.php
 */
global $wpdb;
$p = $wpdb->prefix;

echo "## RTCL Form Builder — form #1 fields (decoded)\n\n";
$form = $wpdb->get_row( "SELECT * FROM {$p}rtcl_forms WHERE id=1", ARRAY_A );
$fields = json_decode( $form['fields'], true ) ?: [];
$sections = json_decode( $form['sections'], true ) ?: [];
echo "Title: {$form['title']} | slug: {$form['slug']} | status: {$form['status']} | type: {$form['type']} | default: {$form['default']}\n\n";
echo "### Sections (order of containers)\n";
foreach ( $sections as $s ) {
	echo "- Section '{$s['title']}' (uuid {$s['uuid']})\n";
	foreach ( (array) ( $s['containers'] ?? [] ) as $c ) {
		echo "  - container cols=", json_encode( $c['column'] ?? '' ), " fields: ", implode( ', ', array_map( function ( $f ) { return is_array( $f ) ? ( $f['uuid'] ?? json_encode( $f ) ) : $f; }, (array) ( $c['fields'] ?? [] ) ) ), "\n";
	}
}
echo "\n### Fields\n";
foreach ( $fields as $uuid => $f ) {
	$keep = array_intersect_key( $f, array_flip( [ 'element', 'name', 'label', 'placeholder', 'required', 'listable', 'searchable', 'filterable', 'options', 'validation', 'order', 'description', 'help_text', 'field_type', 'type', 'default', 'is_default', 'conditional', 'logics' ] ) );
	echo "- **{$uuid}** ", json_encode( $keep, JSON_UNESCAPED_SLASHES ), "\n";
}
echo "\n### Form settings\n```\n", print_r( json_decode( $form['settings'], true ), true ), "\n```\n";
echo "\n### Single layout (keys only)\n", $form['single_layout'] ? substr( $form['single_layout'], 0, 1500 ) : '(empty)', "\n";

echo "\n\n## rtcl_subscriptions\n\n";
echo "```\n"; foreach ( $wpdb->get_results( "DESCRIBE {$p}rtcl_subscriptions" ) as $c ) { echo "{$c->Field} {$c->Type}\n"; } echo "```\n";
foreach ( $wpdb->get_results( "SELECT status, gateway_id, COUNT(*) c FROM {$p}rtcl_subscriptions GROUP BY status, gateway_id" ) as $r ) { echo "- status={$r->status} gateway={$r->gateway_id}: {$r->c}\n"; }
echo "\nPer year created:\n"; foreach ( $wpdb->get_results( "SELECT YEAR(created_at) yr, COUNT(*) c FROM {$p}rtcl_subscriptions GROUP BY yr" ) as $r ) { echo "- {$r->yr}: {$r->c}\n"; }
echo "\nSample (latest 5, ids masked):\n```\n";
foreach ( $wpdb->get_results( "SELECT * FROM {$p}rtcl_subscriptions ORDER BY id DESC LIMIT 5", ARRAY_A ) as $r ) { foreach ( $r as $k => $v ) { if ( preg_match( '/sub_id|customer|secret|token/i', $k ) ) { $r[ $k ] = $v ? '***' : ''; } } echo json_encode( $r ), "\n"; }
echo "```\n";
echo "\nSubscription meta keys:\n"; foreach ( $wpdb->get_results( "SELECT meta_key, COUNT(*) c FROM {$p}rtcl_subscription_meta GROUP BY meta_key ORDER BY c DESC" ) as $r ) { echo "- {$r->meta_key}: {$r->c}\n"; }
echo "\nExpiry spread (expiry_at by month):\n"; foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(expiry_at,'%Y-%m') ym, COUNT(*) c FROM {$p}rtcl_subscriptions GROUP BY ym ORDER BY ym" ) as $r ) { echo "- {$r->ym}: {$r->c}\n"; }

echo "\n\n## rtcl_listing_stats — stat keys\n\n";
foreach ( $wpdb->get_results( "SELECT stat_key, COUNT(*) rows_, SUM(stat_count) total, MIN(stat_date) first_, MAX(stat_date) last_ FROM {$p}rtcl_listing_stats GROUP BY stat_key" ) as $r ) { echo "- {$r->stat_key}: {$r->rows_} rows, total {$r->total}, {$r->first_} → {$r->last_}\n"; }

echo "\n\n## Roles & capabilities (rtcl-related)\n\n";
foreach ( wp_roles()->roles as $slug => $role ) {
	$caps = array_keys( array_filter( $role['capabilities'] ) );
	$rt = array_filter( $caps, function ( $c ) { return preg_match( '/rtcl|listing|store|member/i', $c ); } );
	echo "- **{$slug}** ({$role['name']}): ", count( $caps ), " caps; rtcl-ish: ", implode( ', ', $rt ) ?: '(none)', "\n";
}

echo "\n\n## rtcl_manager users / store managers\n\n";
foreach ( get_users( [ 'role' => 'rtcl_manager', 'fields' => [ 'ID', 'user_registered' ] ] ) as $u ) { echo "- user #{$u->ID} registered {$u->user_registered}, _rtcl_store_id=", get_user_meta( $u->ID, '_rtcl_store_id', true ), "\n"; }
echo "Stores with _rtcl_manager_ids: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='_rtcl_manager_ids'" ), "\n";

echo "\n\n## Users: membership vs. no membership vs. breeder number\n\n";
echo "- users total: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}users" ), "\n";
echo "- users with membership row: ", $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$p}rtcl_membership" ), "\n";
echo "- users with breeder # but NO membership row: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}usermeta um WHERE um.meta_key='southpoll_breeder_number' AND um.meta_value<>'' AND um.user_id NOT IN (SELECT user_id FROM {$p}rtcl_membership)" ), "\n";
echo "- users with NO breeder # and NO membership: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}users u WHERE u.ID NOT IN (SELECT user_id FROM {$p}rtcl_membership) AND u.ID NOT IN (SELECT user_id FROM {$p}usermeta WHERE meta_key='southpoll_breeder_number' AND meta_value<>'')" ), "\n";
echo "- users who have ever logged in (session_tokens meta present): ", $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$p}usermeta WHERE meta_key='session_tokens'" ), "\n";
echo "- users with wp__stripe_customer_id: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}usermeta WHERE meta_key='wp__stripe_customer_id' AND meta_value<>''" ), "\n";
echo "- registered 2025 by month:\n"; foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(user_registered,'%Y-%m') ym, COUNT(*) c FROM {$p}users GROUP BY ym ORDER BY ym" ) as $r ) { echo "  - {$r->ym}: {$r->c}\n"; }
echo "- sample user_login patterns (10 random, masked):\n"; foreach ( $wpdb->get_col( "SELECT user_login FROM {$p}users ORDER BY RAND() LIMIT 10" ) as $l ) { echo "  - ", preg_replace( '/^(.{2}).*(.{2})$/', '$1***$2', $l ), " (", strlen( $l ), " chars, ", ( filter_var( $l, FILTER_VALIDATE_EMAIL ) ? 'email-style' : 'name-style' ), ")\n"; }
echo "- users whose user_login is an email: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}users WHERE user_login LIKE '%@%'" ), "\n";
echo "- users with empty user_email: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}users WHERE user_email='' OR user_email IS NULL" ), "\n";
echo "- users with placeholder-looking emails (noemail/none/example): ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}users WHERE user_email LIKE '%noemail%' OR user_email LIKE '%example.%' OR user_email LIKE 'none@%'" ), "\n";
echo "- duplicate emails: ", $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT user_email FROM {$p}users GROUP BY user_email HAVING COUNT(*)>1) x" ), "\n";

echo "\n\n## Listings: owner membership status cross-check\n\n";
echo "- published listings whose owner membership is expired: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}rtcl_membership m ON m.user_id=po.post_author WHERE po.post_type='rtcl_listing' AND po.post_status='publish' AND m.expiry_date <= NOW()" ), "\n";
echo "- published listings whose owner has no membership row: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po WHERE po.post_type='rtcl_listing' AND po.post_status='publish' AND po.post_author NOT IN (SELECT user_id FROM {$p}rtcl_membership)" ), "\n";
echo "- listings marked sold: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='_rtcl_mark_as_sold' AND meta_value='1'" ), "\n";
echo "- listing expiry_date spread:\n"; foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(meta_value,'%Y-%m') ym, COUNT(*) c FROM {$p}postmeta WHERE meta_key='expiry_date' GROUP BY ym ORDER BY ym" ) as $r ) { echo "  - {$r->ym}: {$r->c}\n"; }
echo "- images meta distribution:\n"; foreach ( $wpdb->get_results( "SELECT meta_value n, COUNT(*) c FROM {$p}postmeta WHERE meta_key='images' GROUP BY n ORDER BY n+0" ) as $r ) { echo "  - {$r->n} images: {$r->c}\n"; }
echo "- price distribution (published): min/avg/max = ", json_encode( $wpdb->get_row( "SELECT MIN(CAST(pm.meta_value AS DECIMAL(12,2))) mn, AVG(CAST(pm.meta_value AS DECIMAL(12,2))) av, MAX(CAST(pm.meta_value AS DECIMAL(12,2))) mx FROM {$p}postmeta pm JOIN {$p}posts po ON po.ID=pm.post_id WHERE pm.meta_key='price' AND po.post_type='rtcl_listing' AND po.post_status='publish' AND pm.meta_value<>''", ARRAY_A ) ), "\n";
echo "- text_m4c3zlip (Business Name) non-empty: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='text_m4c3zlip' AND meta_value<>''" ), " | text_m4c7c84b non-empty: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='text_m4c7c84b' AND meta_value<>''" ), "\n";
echo "- sample text_m4c7c84b values: ", json_encode( $wpdb->get_col( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='text_m4c7c84b' AND meta_value<>'' LIMIT 5" ) ), "\n";
echo "- listing video urls non-empty: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key='_rtcl_video_urls' AND meta_value<>'' AND meta_value NOT LIKE 'a:0:%'" ), "\n";

echo "\n\n## Store: social_media + oh_hours sample\n\n";
echo "```\n", print_r( maybe_unserialize( $wpdb->get_var( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='social_media' AND meta_value<>'' AND meta_value NOT LIKE 'a:0:%' LIMIT 1" ) ), true ), "\n```\n";
echo "store post_content lengths (non-empty): min/avg/max = ", json_encode( $wpdb->get_row( "SELECT MIN(LENGTH(post_content)) mn, AVG(LENGTH(post_content)) av, MAX(LENGTH(post_content)) mx FROM {$p}posts WHERE post_type='store' AND TRIM(post_content)<>''", ARRAY_A ) ), "\n";
echo "store slogan sample: ", json_encode( $wpdb->get_col( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='slogan' AND meta_value<>'' ORDER BY RAND() LIMIT 5" ) ), "\n";
echo "store_category assignments: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}term_relationships tr JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy='store_category'" ), "\n";
