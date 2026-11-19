<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

/** membership.recurring_notice — data: farm_name, ends_at, days, amount_cents, card_label, manage_url */
final class RecurringNoticeEmail extends Message {

	/** Queued for the send window (see Notifier). */
	public function deferrable(): bool {
		return true;
	}

	public function key(): string {
		return 'membership.recurring_notice';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: farm name, 2: days */ __( 'Your SPGCA membership for %1$s renews automatically in %2$d days', 'southpoll-membership' ), (string) $this->get( 'farm_name' ), (int) $this->get( 'days', 30 ) );
	}

	public function heading(): string {
		return __( 'Upcoming automatic renewal', 'southpoll-membership' );
	}

	public function body(): string {
		$card = (string) $this->get( 'card_label', '' );
		return $this->p( sprintf( /* translators: 1: farm name, 2: date, 3: amount */ __( 'Automatic renewal is on for <strong>%1$s</strong>. On <strong>%2$s</strong> we will charge <strong>%3$s</strong>%4$s and extend your membership for another year.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( Dates::display( (string) $this->get( 'ends_at' ) ) ), Str::money( (int) $this->get( 'amount_cents', 0 ) ), $card ? ' ' . sprintf( /* translators: %s: card label */ __( 'to the card %s', 'southpoll-membership' ), esc_html( $card ) ) : '' ) )
			. $this->p( __( 'No action is needed. To update your card or turn automatic renewal off, visit My Membership.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Manage my membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'manage_url', home_url( '/' ) ) ];
	}
}
