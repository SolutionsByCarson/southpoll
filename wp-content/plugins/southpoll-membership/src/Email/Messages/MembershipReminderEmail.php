<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** membership.reminder — data: farm_name, ends_at, days (before expiry), renew_url */
final class MembershipReminderEmail extends Message {

	/** Queued for the send window (see Notifier). */
	public function deferrable(): bool {
		return true;
	}

	public function key(): string {
		return 'membership.reminder';
	}

	public function subject(): string {
		$days = (int) $this->get( 'days', 0 );
		return $days <= 1
			? sprintf( /* translators: %s: farm name */ __( 'Your SPGCA membership for %s expires tomorrow', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) )
			: sprintf( /* translators: 1: farm name, 2: days */ __( 'Your SPGCA membership for %1$s expires in %2$d days', 'southpoll-membership' ), (string) $this->get( 'farm_name' ), $days );
	}

	public function heading(): string {
		return __( 'Time to renew', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: farm name, 2: date */ __( 'The SPGCA membership for <strong>%1$s</strong> ends on <strong>%2$s</strong>. Renew now to keep your farm page in the directory and your classified listings live without interruption.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( Dates::display( (string) $this->get( 'ends_at' ) ) ) ) )
			. $this->p( __( 'Renewing early never costs you days: your new year is added to the end of your current term.', 'southpoll-membership' ) )
			. $this->p( __( 'Paying by check or cash instead? You can let us know from the same page and the office will confirm your payment.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Renew my membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'renew_url', home_url( '/' ) ) ];
	}
}
