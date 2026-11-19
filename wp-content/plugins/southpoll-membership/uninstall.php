<?php
/**
 * Uninstall handler. Data is only removed when the "delete_data_on_uninstall" setting is enabled,
 * so an accidental "Delete" in the plugins screen cannot wipe membership records.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sp_settings = get_option( 'sp_settings', [] );
if ( empty( $sp_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Core/Service.php'; // Capabilities implements it; no autoloader runs during uninstall
require_once __DIR__ . '/src/Core/Schema.php';
require_once __DIR__ . '/src/Core/Capabilities.php';

global $wpdb;
foreach ( \SouthPoll\Membership\Core\Schema::table_names() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( [ 'sp_settings', 'sp_integrations', 'sp_db_version', 'sp_db_error', 'sp_flush_rewrite' ] as $option ) {
	delete_option( $option );
}

\SouthPoll\Membership\Core\Capabilities::remove_roles();

wp_clear_scheduled_hook( 'sp_hourly' );
wp_clear_scheduled_hook( 'sp_registry_digest' );
delete_option( 'sp_log_dir_token' );
wp_clear_scheduled_hook( 'sp_daily' );
wp_clear_scheduled_hook( 'sp_queue' );
