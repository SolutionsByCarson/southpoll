<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** farm_user.invited — data: farm_name, inviter_name, accept_url */
final class FarmUserInviteEmail extends Message {

	public function key(): string {
		return 'farm_user.invited';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: farm name */ __( 'You have been invited to help manage %s on SouthPoll.com', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return __( 'You are invited', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: inviter, 2: farm name */ __( '<strong>%1$s</strong> has invited you to be an account manager for <strong>%2$s</strong> on SouthPoll.com. Account managers can update the farm page and create or edit classified listings.', 'southpoll-membership' ), esc_html( (string) $this->get( 'inviter_name' ) ), esc_html( (string) $this->get( 'farm_name' ) ) ) )
			. $this->p( __( 'Accept with the button below. If you do not have a SouthPoll.com account yet you can create one in the same step; just use this email address. The invitation expires in 14 days.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Accept invitation', 'southpoll-membership' ), 'url' => (string) $this->get( 'accept_url' ) ];
	}

	public function footer_note(): string {
		return __( 'If you were not expecting this, you can ignore it; nothing happens until you accept.', 'southpoll-membership' );
	}
}
