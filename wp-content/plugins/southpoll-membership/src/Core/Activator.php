<?php
/**
 * Activation routine.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Activator {

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( SP_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'SouthPoll Membership requires PHP 8.1 or newer.', 'southpoll-membership' ),
				esc_html__( 'Plugin activation error', 'southpoll-membership' ),
				[ 'back_link' => true ]
			);
		}

		$plugin = Plugin::instance();

		$plugin->get( Migrator::class )->migrate();
		Capabilities::install_roles();
		Cron::schedule();
		Logger::ensure_log_dir();
		Plugin::request_rewrite_flush();

		update_option( 'sp_activated_at', current_time( 'mysql', true ), false );

		/**
		 * Fires after activation tasks complete.
		 */
		do_action( 'sp/activated' );
	}
}
