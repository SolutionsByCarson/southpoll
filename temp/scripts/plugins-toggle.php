<?php
/**
 * plugins-toggle.php — Deactivate or reactivate the Classified Listing plugin family on the LOCAL site.
 *
 * Run:  ./wp-run.sh plugins-toggle.php deactivate
 *       ./wp-run.sh plugins-toggle.php activate
 *       ./wp-run.sh plugins-toggle.php status
 *
 * Order matters: store depends on pro, pro depends on core. Deactivation runs store → pro → core;
 * activation runs core → pro → store. Uses WordPress' own deactivate_plugins()/activate_plugin() so
 * each plugin's (de)activation hooks fire (e.g. core clears its cron events).
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$family = [
	'classified-listing-store/classified-listing-store.php',
	'classified-listing-pro/classified-listing-pro.php',
	'classified-listing/classified-listing.php',
];
$action = $argv[2] ?? 'status';

$status = function () use ( $family ) {
	foreach ( $family as $pl ) {
		printf( "- %-55s %s\n", $pl, is_plugin_active( $pl ) ? 'ACTIVE' : 'inactive' );
	}
};

echo "Before:\n"; $status();

if ( 'deactivate' === $action ) {
	deactivate_plugins( $family, false, false );
	echo "\nDeactivated.\n";
} elseif ( 'activate' === $action ) {
	foreach ( array_reverse( $family ) as $pl ) {
		$r = activate_plugin( $pl, '', false, false );
		if ( is_wp_error( $r ) ) { echo "ERROR activating $pl: ", $r->get_error_message(), "\n"; }
	}
	echo "\nActivated.\n";
}

echo "\nAfter:\n"; $status();
echo "\nactive_plugins option now has ", count( (array) get_option( 'active_plugins' ) ), " entries.\n";
