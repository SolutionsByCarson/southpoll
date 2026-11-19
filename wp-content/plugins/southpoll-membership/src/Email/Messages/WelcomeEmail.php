<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** account.welcome — sent right after registration. Includes the verification link when one is required. */
final class WelcomeEmail extends Message {

	public function key(): string {
		return 'account.welcome';
	}

	public function subject(): string {
		return __( 'Welcome to SouthPoll.com', 'southpoll-membership' );
	}

	public function heading(): string {
		return __( 'Welcome to South Poll', 'southpoll-membership' );
	}

	public function preheader(): string {
		return __( 'Your SouthPoll.com account is ready.', 'southpoll-membership' );
	}

	public function body(): string {
		$name = (string) $this->get( 'recipient_name', '' );
		$out  = $this->p( $name ? sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'southpoll-membership' ), esc_html( $name ) ) : __( 'Hello,', 'southpoll-membership' ) );
		$out .= $this->p( __( 'Your account on SouthPoll.com has been created. From your dashboard you can browse the member directory, save favorite farms and listings, and set up saved searches for the cattle you are looking for.', 'southpoll-membership' ) );
		if ( $this->get( 'verify_url' ) ) {
			$out .= $this->p( __( 'Please confirm your email address using the button below. Contact details for farms stay hidden until your address is verified.', 'southpoll-membership' ) );
		}
		if ( $this->get( 'is_member_signup' ) ) {
			$out .= $this->p( __( 'You chose a paid membership. Once your payment is complete your farm page and classified listings unlock automatically.', 'southpoll-membership' ) );
		}
		return $out;
	}

	public function cta(): ?array {
		if ( $this->get( 'verify_url' ) ) {
			return [ 'label' => __( 'Verify my email', 'southpoll-membership' ), 'url' => (string) $this->get( 'verify_url' ) ];
		}
		return [ 'label' => __( 'Go to my dashboard', 'southpoll-membership' ), 'url' => (string) $this->get( 'dashboard_url', home_url( '/' ) ) ];
	}

	public function footer_note(): string {
		return __( 'You are receiving this because an account was created with this email address on SouthPoll.com. If that was not you, reply to let us know.', 'southpoll-membership' );
	}
}
