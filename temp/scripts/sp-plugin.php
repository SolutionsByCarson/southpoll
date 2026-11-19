<?php
/**
 * sp-plugin.php — Local test harness for the SouthPoll Membership plugin.
 *
 * Run:  ./wp-run.sh sp-plugin.php activate      activate the plugin (runs Activator)
 *       ./wp-run.sh sp-plugin.php deactivate
 *       ./wp-run.sh sp-plugin.php status        versions, tables, row counts, cron, roles, settings summary
 *       ./wp-run.sh sp-plugin.php migrate       force Migrator::migrate()
 *       ./wp-run.sh sp-plugin.php context <uid> dump MemberContext permissions for a user id
 *       ./wp-run.sh sp-plugin.php lint          php -l every plugin file
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$basename = 'southpoll-membership/southpoll-membership.php';
$action   = $argv[2] ?? 'status';

switch ( $action ) {
	case 'lint':
		$dir = WP_PLUGIN_DIR . '/southpoll-membership';
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		$bad = 0;
		foreach ( $it as $f ) {
			if ( 'php' !== $f->getExtension() ) { continue; }
			$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $f->getPathname() ) . ' 2>&1' );
			if ( false === strpos( (string) $out, 'No syntax errors' ) ) { $bad++; echo $out, "\n"; }
		}
		echo $bad ? "$bad file(s) with syntax errors\n" : "All plugin PHP files pass php -l\n";
		break;

	case 'activate':
		if ( is_plugin_active( $basename ) ) { echo "Already active.\n"; break; }
		$r = activate_plugin( $basename, '', false, false );
		echo is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() . "\n" : "Activated.\n";
		break;

	case 'deactivate':
		deactivate_plugins( $basename, false, false );
		echo "Deactivated.\n";
		break;

	case 'migrate':
		sp_membership()->get( \SouthPoll\Membership\Core\Migrator::class )->migrate();
		echo "Migrated. db version = ", get_option( 'sp_db_version' ), "\n";
		break;

	case 'context':
		$uid = (int) ( $argv[3] ?? 0 );
		$ctx = sp_membership()->get( \SouthPoll\Membership\Members\ContextProvider::class )->for_user( $uid );
		echo "user {$uid}: role=", $ctx->role(), " staff=", var_export( $ctx->is_staff(), true ), " verified=", var_export( $ctx->is_verified(), true ), " membership=", $ctx->membership_status(), " days_remaining=", var_export( $ctx->days_remaining(), true ), "\n";
		foreach ( [ 'view_dashboard', 'view_contact_info', 'use_favorites', 'edit_farm', 'manage_listings', 'publish_listing', 'manage_farm_users', 'manage_membership' ] as $a ) { echo "  - $a: ", $ctx->can( $a ) ? 'yes' : 'no', "\n"; }
		break;

	case 'status':
	default:
		global $wpdb;
		echo "active: ", is_plugin_active( $basename ) ? 'yes' : 'no', "\n";
		echo "SP_VERSION: ", defined( 'SP_VERSION' ) ? SP_VERSION : '(not loaded)', " | sp_db_version option: ", var_export( get_option( 'sp_db_version' ), true ), " | sp_db_error: ", var_export( get_option( 'sp_db_error' ), true ), "\n";
		if ( class_exists( \SouthPoll\Membership\Core\Schema::class ) ) {
			foreach ( \SouthPoll\Membership\Core\Schema::table_names() as $t ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
				echo "- {$t}: ", $exists ? $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ) . ' rows' : 'MISSING', "\n";
			}
			echo "cron: "; foreach ( \SouthPoll\Membership\Core\Cron::next_runs() as $h => $ts ) { echo $h, '=', $ts ? date( 'Y-m-d H:i', $ts ) : 'none', ' '; } echo "\n";
			echo "roles: sp_buyer=", get_role( 'sp_buyer' ) ? 'yes' : 'no', " | admin sp_manage=", get_role( 'administrator' )->has_cap( 'sp_manage' ) ? 'yes' : 'no', " | editor sp_manage=", get_role( 'editor' )->has_cap( 'sp_manage' ) ? 'yes' : 'no', " | editor sp_manage_settings=", get_role( 'editor' )->has_cap( 'sp_manage_settings' ) ? 'yes' : 'no', "\n";
			$s = sp_membership()->get( \SouthPoll\Membership\Core\Settings::class );
			echo "settings: listing_limit=", $s->get( 'listing_limit_default' ), " duration=", $s->get( 'listing_duration_days' ), " admin=", $s->get( 'admin_contact.email' ), " region(MO)=", $s->region_for_state( 'MO' ), " region(VA)=", $s->region_for_state( 'VA' ), "\n";
			$i = sp_membership()->get( \SouthPoll\Membership\Core\Integrations::class );
			echo "integrations: stripe=", $i->stripe_mode(), '/', $i->stripe_configured() ? 'configured' : 'missing', " recaptcha=", $i->recaptcha_configured() ? 'configured' : 'missing', " maps=", $i->maps_configured() ? 'configured' : 'missing', " from=", $i->get( 'email.from_email' ), "\n";
			$tier = $wpdb->get_row( "SELECT name, price_cents, duration_days, is_default, active FROM {$wpdb->prefix}sp_membership_tiers ORDER BY id LIMIT 1", ARRAY_A );
			echo "default tier: ", json_encode( $tier ), "\n";
			echo "farm meta fields: ", json_encode( $wpdb->get_col( "SELECT field_key FROM {$wpdb->prefix}sp_meta_fields WHERE scope='farm' ORDER BY sort_order" ) ), "\n";
		}
		break;
}
