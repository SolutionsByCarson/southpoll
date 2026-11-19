<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** account.verify — verification link (resend, or after an email address change). */
final class VerifyEmail extends Message {

	public function key(): string {
		return 'account.verify';
	}

	public function subject(): string {
		return __( 'Verify your email for SouthPoll.com', 'southpoll-membership' );
	}

	public function heading(): string {
		return __( 'Confirm your email address', 'southpoll-membership' );
	}

	public function body(): string {
		$out = $this->p( __( 'Click the button below to confirm this email address for your SouthPoll.com account.', 'southpoll-membership' ) );
		if ( $this->get( 'is_change' ) ) {
			$out .= $this->p( __( 'You asked to change the email on your account. Your login email switches to this address as soon as you confirm.', 'southpoll-membership' ) );
		}
		$out .= $this->p( __( 'If the button does not work, copy this link into your browser:', 'southpoll-membership' ) . '<br><a href="' . esc_url( (string) $this->get( 'verify_url' ) ) . '" style="color:#8d183c;word-break:break-all;">' . esc_html( (string) $this->get( 'verify_url' ) ) . '</a>' );
		return $out;
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Verify my email', 'southpoll-membership' ), 'url' => (string) $this->get( 'verify_url' ) ];
	}

	public function footer_note(): string {
		return __( 'If you did not request this, you can ignore this email.', 'southpoll-membership' );
	}
}
