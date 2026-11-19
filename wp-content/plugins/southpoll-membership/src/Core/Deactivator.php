<?php
/**
 * Deactivation routine. Never removes data; see uninstall.php for that.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Deactivator {

	public static function deactivate(): void {
		Cron::unschedule();
		flush_rewrite_rules( false );

		/**
		 * Fires after deactivation tasks complete.
		 */
		do_action( 'sp/deactivated' );
	}
}
