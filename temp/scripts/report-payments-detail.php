<?php
/**
 * report-payments-detail.php — Stripe subscription id types, pre-paid ("store" gateway) payment origins,
 * renewal patterns, membership term math.
 * Run: ./wp-run.sh report-payments-detail.php
 */
global $wpdb;
$p = $wpdb->prefix;

echo "## rtcl_subscriptions.sub_id prefix distribution (Stripe object type)\n\n";
foreach ( $wpdb->get_results( "SELECT SUBSTRING(sub_id,1,4) pre, COUNT(*) c FROM {$p}rtcl_subscriptions GROUP BY pre" ) as $r ) { echo "- '{$r->pre}…': {$r->c}\n"; }
echo "occurrence distribution: ", json_encode( $wpdb->get_results( "SELECT occurrence, COUNT(*) c FROM {$p}rtcl_subscriptions GROUP BY occurrence", ARRAY_A ) ), "\n";
echo "subscriptions per user (>1): ", $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT user_id FROM {$p}rtcl_subscriptions GROUP BY user_id HAVING COUNT(*)>1) x" ), "\n";
echo "subscription users with membership row: ", $wpdb->get_var( "SELECT COUNT(DISTINCT s.user_id) FROM {$p}rtcl_subscriptions s JOIN {$p}rtcl_membership m ON m.user_id=s.user_id" ), " / ", $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$p}rtcl_subscriptions" ), "\n";
echo "subscription_meta 'cc' sample keys: "; $cc = maybe_unserialize( $wpdb->get_var( "SELECT meta_value FROM {$p}rtcl_subscription_meta WHERE meta_key='cc' LIMIT 1" ) ); echo is_array( $cc ) ? implode( ', ', array_keys( $cc ) ) : gettype( $cc ), "\n";

echo "\n## Payments by gateway × pricing × year\n\n";
foreach ( $wpdb->get_results( "SELECT YEAR(po.post_date) yr, gw.meta_value gateway, pr.meta_value pricing_id, po.post_status, COUNT(*) c, SUM(CAST(am.meta_value AS DECIMAL(10,2))) total FROM {$p}posts po LEFT JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' LEFT JOIN {$p}postmeta pr ON pr.post_id=po.ID AND pr.meta_key='_pricing_id' LEFT JOIN {$p}postmeta am ON am.post_id=po.ID AND am.meta_key='amount' WHERE po.post_type='rtcl_payment' GROUP BY yr, gateway, pricing_id, po.post_status ORDER BY yr, gateway" ) as $r ) { echo "- {$r->yr} | {$r->gateway} | pricing {$r->pricing_id} | {$r->post_status}: {$r->c} orders, \${$r->total}\n"; }

echo "\n## 'store' gateway (pre-paid) payments — who created them?\n\n";
echo "post_author distribution: ", json_encode( $wpdb->get_results( "SELECT po.post_author, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='store' WHERE po.post_type='rtcl_payment' GROUP BY po.post_author ORDER BY c DESC LIMIT 8", ARRAY_A ) ), "\n";
echo "customer_id == post_author? ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='store' JOIN {$p}postmeta cu ON cu.post_id=po.ID AND cu.meta_key='customer_id' WHERE po.post_type='rtcl_payment' AND cu.meta_value=po.post_author" ), " of 377\n";
echo "customer_ip_address distinct for store gateway: ", $wpdb->get_var( "SELECT COUNT(DISTINCT ip.meta_value) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='store' JOIN {$p}postmeta ip ON ip.post_id=po.ID AND ip.meta_key='customer_ip_address' WHERE po.post_type='rtcl_payment'" ), "\n";
echo "store-gateway payments per month:\n"; foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(po.post_date,'%Y-%m') ym, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='store' WHERE po.post_type='rtcl_payment' GROUP BY ym ORDER BY ym" ) as $r ) { echo "  - {$r->ym}: {$r->c}\n"; }
echo "stripe payments per month:\n"; foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(po.post_date,'%Y-%m') ym, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' GROUP BY ym ORDER BY ym" ) as $r ) { echo "  - {$r->ym}: {$r->c}\n"; }

echo "\n## Renewal patterns\n\n";
echo "memberships with >1 order id (renewed at least once): ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership_meta WHERE meta_key='_rtcl_order_ids' AND meta_value LIKE 'a:%' AND meta_value NOT LIKE 'a:1:%'" ), "\n";
echo "membership term (expiry - member_since) in days, distribution:\n"; foreach ( $wpdb->get_results( "SELECT ROUND(DATEDIFF(expiry_date, member_since)/30) months, COUNT(*) c FROM {$p}rtcl_membership GROUP BY months ORDER BY months" ) as $r ) { echo "  - ~{$r->months} months: {$r->c}\n"; }
echo "\nusers with membership expiring in next 60 days: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)" ), "\n";
echo "expired memberships that later renewed (expiry > now but member_since 2025): ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership WHERE expiry_date > NOW() AND YEAR(member_since)=2025" ), "\n";

echo "\n## Users with membership but never logged in / vs stores\n\n";
echo "active members with a store: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership m WHERE m.expiry_date > NOW() AND m.user_id IN (SELECT meta_value FROM {$p}postmeta WHERE meta_key='store_owner_id')" ), "\n";
echo "active members with a published listing: ", $wpdb->get_var( "SELECT COUNT(DISTINCT m.user_id) FROM {$p}rtcl_membership m JOIN {$p}posts po ON po.post_author=m.user_id AND po.post_type='rtcl_listing' AND po.post_status='publish' WHERE m.expiry_date > NOW()" ), "\n";
echo "expired members with a store: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}rtcl_membership m WHERE m.expiry_date <= NOW() AND m.user_id IN (SELECT meta_value FROM {$p}postmeta WHERE meta_key='store_owner_id')" ), "\n";

echo "\n## User profile meta (rtcl) coverage among members\n\n";
foreach ( [ '_rtcl_phone', '_rtcl_address', '_rtcl_location', '_rtcl_zipcode', '_rtcl_website', '_rtcl_social', 'southpoll_breeder_number', '_billing_state', '_billing_city', '_billing_address_1', '_billing_postcode', '_billing_company' ] as $k ) { echo "- $k: ", $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}usermeta um JOIN {$p}rtcl_membership m ON m.user_id=um.user_id WHERE um.meta_key=%s AND um.meta_value<>'' AND um.meta_value NOT LIKE 'a:0:%%'", $k ) ), " of 729 members\n"; }
echo "_rtcl_location values sample: ", json_encode( $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$p}usermeta WHERE meta_key='_rtcl_location' AND meta_value<>'' LIMIT 8" ) ), "\n";
echo "_billing_state values (top 12): ", json_encode( $wpdb->get_results( "SELECT meta_value st, COUNT(*) c FROM {$p}usermeta WHERE meta_key='_billing_state' AND meta_value<>'' GROUP BY st ORDER BY c DESC LIMIT 12", ARRAY_A ) ), "\n";
echo "southpoll_breeder_number sample formats: ", json_encode( array_map( function ( $v ) { return preg_replace( '/[0-9]/', '9', preg_replace( '/[A-Za-z]/', 'A', $v ) ); }, $wpdb->get_col( "SELECT meta_value FROM {$p}usermeta WHERE meta_key='southpoll_breeder_number' AND meta_value<>'' ORDER BY RAND() LIMIT 12" ) ) ), "\n";
echo "_rtcl_user_type values: ", json_encode( $wpdb->get_results( "SELECT meta_value, COUNT(*) c FROM {$p}usermeta WHERE meta_key='_rtcl_user_type' GROUP BY meta_value", ARRAY_A ) ), "\n";

echo "\n## WebToffee import CSV headers (member import source)\n\n";
foreach ( glob( WP_CONTENT_DIR . '/webtoffee_import/*.csv' ) as $f ) { $h = fopen( $f, 'r' ); $row = fgetcsv( $h ); fclose( $h ); echo "- ", basename( $f ), " (", count( file( $f ) ), " lines): ", implode( ' | ', array_slice( (array) $row, 0, 40 ) ), "\n"; }
foreach ( glob( WP_CONTENT_DIR . '/webtoffee_export/*.csv' ) as $f ) { $h = fopen( $f, 'r' ); $row = fgetcsv( $h ); fclose( $h ); echo "- EXPORT ", basename( $f ), " (", count( file( $f ) ), " lines): ", implode( ' | ', array_slice( (array) $row, 0, 60 ) ), "\n"; }
