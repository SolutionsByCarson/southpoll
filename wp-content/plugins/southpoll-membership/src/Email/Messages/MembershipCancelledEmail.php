<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** membership.cancelled — data: farm_name, reason, renew_url, admin_email */
final class MembershipCancelledEmail extends Message {

	public function key(): string {
		return 'membership.cancelled';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: farm name */ __( 'The SPGCA membership for %s has been cancelled', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return __( 'Membership cancelled', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: %s: farm name */ __( 'The SPGCA membership for <strong>%s</strong> has been cancelled by the office. The farm page and classified listings are no longer shown; nothing has been deleted.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) )
			. ( $this->get( 'reason' ) ? $this->p( sprintf( /* translators: %s: reason */ __( 'Reason given: %s', 'southpoll-membership' ), esc_html( (string) $this->get( 'reason' ) ) ) ) : '' )
			. $this->p( sprintf( /* translators: %s: email */ __( 'If you believe this is a mistake, reply to this email or contact the office at %s. You can rejoin at any time from My Membership.', 'southpoll-membership' ), esc_html( (string) $this->get( 'admin_email' ) ) ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'My Membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'renew_url' ) ];
	}
}
