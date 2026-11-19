<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** account.password_reset */
final class PasswordResetEmail extends Message {

	public function key(): string {
		return 'account.password_reset';
	}

	public function subject(): string {
		return __( 'Reset your SouthPoll.com password', 'southpoll-membership' );
	}

	public function heading(): string {
		return __( 'Reset your password', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( __( 'Someone (hopefully you) asked to reset the password for your SouthPoll.com account. Use the button below to choose a new password. The link works for 24 hours.', 'southpoll-membership' ) )
			. $this->p( __( 'If the button does not work, copy this link into your browser:', 'southpoll-membership' ) . '<br><a href="' . esc_url( (string) $this->get( 'reset_url' ) ) . '" style="color:#8d183c;word-break:break-all;">' . esc_html( (string) $this->get( 'reset_url' ) ) . '</a>' );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Choose a new password', 'southpoll-membership' ), 'url' => (string) $this->get( 'reset_url' ) ];
	}

	public function footer_note(): string {
		return __( 'If you did not request a password reset, no action is needed; your password will not change.', 'southpoll-membership' );
	}
}
