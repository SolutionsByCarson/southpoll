<?php
/**
 * report-renewals.php — Detect Stripe auto-renewal orders (recurring charges) vs. manual checkouts.
 * Run: ./wp-run.sh report-renewals.php
 */
global $wpdb;
$p = $wpdb->prefix;

echo "## transaction_id prefix distribution (stripe payments)\n\n";
foreach ( $wpdb->get_results( "SELECT SUBSTRING(t.meta_value,1,3) pre, po.post_status, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta t ON t.post_id=po.ID AND t.meta_key='transaction_id' JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' GROUP BY pre, po.post_status" ) as $r ) { echo "- '{$r->pre}…' {$r->post_status}: {$r->c}\n"; }
echo "\nstripe payments WITHOUT _stripe_intent_id (likely recurring/invoice-created): ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' AND po.ID NOT IN (SELECT post_id FROM {$p}postmeta WHERE meta_key='_stripe_intent_id')" ), "\n";
echo "stripe payments WITHOUT customer_ip_address: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.ID NOT IN (SELECT post_id FROM {$p}postmeta WHERE meta_key='customer_ip_address' AND meta_value<>'')" ), "\n";
echo "stripe payments with empty/0.0.0.0 ip: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' JOIN {$p}postmeta ip ON ip.post_id=po.ID AND ip.meta_key='customer_ip_address' WHERE po.post_type='rtcl_payment' AND (ip.meta_value='' OR ip.meta_value='0.0.0.0' OR ip.meta_value='127.0.0.1')" ), "\n";

echo "\n## Users with >=2 completed Stripe payments (renewals)\n\n";
$rows = $wpdb->get_results( "SELECT cu.meta_value uid, COUNT(*) c, MIN(po.post_date) first_, MAX(po.post_date) last_ FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' JOIN {$p}postmeta cu ON cu.post_id=po.ID AND cu.meta_key='customer_id' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' GROUP BY uid HAVING c>=2 ORDER BY c DESC" );
echo "count: ", count( $rows ), "\n";
echo "sample intervals (days between first and last):\n"; foreach ( array_slice( $rows, 0, 12 ) as $r ) { echo "- user {$r->uid}: {$r->c} payments, ", round( ( strtotime( $r->last_ ) - strtotime( $r->first_ ) ) / 86400 ), " days apart ({$r->first_} → {$r->last_})\n"; }
$gaps = []; foreach ( $rows as $r ) { $gaps[] = round( ( strtotime( $r->last_ ) - strtotime( $r->first_ ) ) / 86400 ); } sort( $gaps ); echo "gap distribution (days): min ", min( $gaps ), ", median ", $gaps[ intdiv( count( $gaps ), 2 ) ], ", max ", max( $gaps ), "\n";
echo "gaps within 360-370 days (true annual renewals): ", count( array_filter( $gaps, function ( $g ) { return $g >= 355 && $g <= 375; } ) ), "\n";

echo "\n## Stripe payments: time-of-day pattern (auto-renewals cluster at same hour)\n\n";
foreach ( $wpdb->get_results( "SELECT HOUR(po.post_date) h, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' AND po.post_date >= '2025-12-01' GROUP BY h ORDER BY h" ) as $r ) { echo "- {$r->h}:00 → {$r->c}\n"; }

echo "\n## Payments created via webhook (post_author=0 or no billing name)\n\n";
echo "post_author distribution for stripe payments: ", json_encode( $wpdb->get_results( "SELECT po.post_author, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' GROUP BY po.post_author ORDER BY c DESC LIMIT 6", ARRAY_A ) ), "\n";
echo "stripe payments missing _billing_first_name: ", $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.ID NOT IN (SELECT post_id FROM {$p}postmeta WHERE meta_key='_billing_first_name')" ), "\n";
echo "\nlatest 12 completed stripe payments (date, amount, has_intent, ip?):\n";
foreach ( $wpdb->get_results( "SELECT po.ID, po.post_date, (SELECT meta_value FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='amount') amount, (SELECT COUNT(*) FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='_stripe_intent_id') has_intent, (SELECT meta_value FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='customer_ip_address') ip, (SELECT SUBSTRING(meta_value,1,3) FROM {$p}postmeta WHERE post_id=po.ID AND meta_key='transaction_id') tx FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-completed' ORDER BY po.post_date DESC LIMIT 12" ) as $r ) { echo "- #{$r->ID} {$r->post_date} \${$r->amount} intent={$r->has_intent} ip=", $r->ip ? 'yes' : 'no', " tx={$r->tx}…\n"; }

echo "\n## rtcl-created (abandoned) stripe orders by month\n\n";
foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(po.post_date,'%Y-%m') ym, COUNT(*) c FROM {$p}posts po JOIN {$p}postmeta gw ON gw.post_id=po.ID AND gw.meta_key='_payment_method' AND gw.meta_value='stripe' WHERE po.post_type='rtcl_payment' AND po.post_status='rtcl-created' GROUP BY ym ORDER BY ym" ) as $r ) { echo "- {$r->ym}: {$r->c}\n"; }
