<?php
/**
 * Sent when the owner cancels from Manage Membership: automatic renewal is now off, the paid term runs to its end date.
 * Data: farm_name, ends_at, ends_display, manage_url.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

final class MembershipAutoRenewOffEmail extends Message {

	public function key(): string {
		return 'membership.auto_renew_off';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: farm name, 2: date */ __( 'Your SPGCA membership for %1$s will end on %2$s', 'southpoll-membership' ), (string) $this->get( 'farm_name' ), (string) $this->get( 'ends_display' ) );
	}

	public function heading(): string {
		return __( 'Cancellation confirmed — automatic renewal is off', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: farm name, 2: date */ __( 'As requested, the SPGCA membership for <strong>%1$s</strong> will <strong>not renew</strong>. It stays fully active — farm page, listings, member benefits — until <strong>%2$s</strong> and then expires. Nothing more will be charged.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( (string) $this->get( 'ends_display' ) ) ) )
			. $this->p( __( 'Changed your mind? Turn automatic renewal back on, or renew when the reminders arrive, from My Membership. Your farm page and listings are kept (just hidden) if the membership lapses, so rejoining later picks up where you left off.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'My Membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'manage_url', home_url( '/' ) ) ];
	}
}
