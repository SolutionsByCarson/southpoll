<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** membership.expired — data: farm_name, ends_at, days_after (0, 7, 30 …), renew_url */
final class MembershipExpiredEmail extends Message {

	/** Queued for the send window (see Notifier). */
	public function deferrable(): bool {
		return true;
	}

	public function key(): string {
		return 'membership.expired';
	}

	public function subject(): string {
		$days = (int) $this->get( 'days_after', 0 );
		return $days > 0
			? sprintf( /* translators: %s: farm name */ __( 'Reminder: the SPGCA membership for %s has lapsed', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) )
			: sprintf( /* translators: %s: farm name */ __( 'The SPGCA membership for %s has expired', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return __( 'Your membership has expired', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: farm name, 2: date */ __( 'The SPGCA membership for <strong>%1$s</strong> ended on <strong>%2$s</strong>. Your farm page has been removed from the member directory and your classified listings are paused.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( Dates::display( (string) $this->get( 'ends_at' ) ) ) ) )
			. $this->p( __( 'Nothing has been deleted. Renew and everything comes back exactly as you left it, with whatever time your listings had remaining.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Renew my membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'renew_url', home_url( '/' ) ) ];
	}
}
