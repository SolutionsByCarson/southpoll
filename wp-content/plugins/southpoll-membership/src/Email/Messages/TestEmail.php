<?php
/**
 * Test email sent from the admin status page.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

final class TestEmail extends Message {

	public function key(): string {
		return 'system.test';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: site name */ __( '[%s] Test email from SouthPoll Membership', 'southpoll-membership' ), get_bloginfo( 'name' ) );
	}

	public function heading(): string {
		return __( 'Email delivery works', 'southpoll-membership' );
	}

	public function preheader(): string {
		return __( 'This is a test message from the SouthPoll Membership plugin.', 'southpoll-membership' );
	}

	public function body(): string {
		$sent_by = (string) $this->get( 'sent_by', '' );
		return $this->p( __( 'If you can read this, the SouthPoll Membership plugin can send branded HTML email through this site\'s mail configuration.', 'southpoll-membership' ) )
			. $this->table( [
				__( 'Sent at (UTC)', 'southpoll-membership' ) => current_time( 'mysql', true ),
				__( 'Sent by', 'southpoll-membership' )       => $sent_by,
				__( 'Plugin version', 'southpoll-membership' ) => SP_VERSION,
			] );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Open the member dashboard', 'southpoll-membership' ), 'url' => (string) $this->get( 'dashboard_url', home_url( '/' ) ) ];
	}

	public function footer_note(): string {
		return __( 'You received this because a site administrator pressed "Send test email".', 'southpoll-membership' );
	}
}
