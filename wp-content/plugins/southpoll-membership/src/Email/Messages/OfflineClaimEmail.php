<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Str;

/**
 * membership.claim_received / membership.claim_rejected — data: farm_name, amount_cents, method_label, status (received|rejected), note, manage_url
 */
final class OfflineClaimEmail extends Message {

	public function key(): string {
		return 'rejected' === $this->get( 'status' ) ? 'membership.claim_rejected' : 'membership.claim_received';
	}

	public function subject(): string {
		return 'rejected' === $this->get( 'status' )
			? __( 'About your SPGCA membership payment', 'southpoll-membership' )
			: __( 'We received your SPGCA membership payment claim', 'southpoll-membership' );
	}

	public function heading(): string {
		return 'rejected' === $this->get( 'status' ) ? __( 'Payment not verified', 'southpoll-membership' ) : __( 'Thanks, we will verify your payment', 'southpoll-membership' );
	}

	public function body(): string {
		if ( 'rejected' === $this->get( 'status' ) ) {
			$out = $this->p( sprintf( /* translators: %s: farm name */ __( 'The office could not match an offline payment to the membership for <strong>%s</strong>, so it has not been activated.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) );
			if ( $this->get( 'note' ) ) {
				$out .= $this->p( '<em>' . esc_html( (string) $this->get( 'note' ) ) . '</em>' );
			}
			return $out . $this->p( __( 'If you believe this is a mistake, reply to this email or call the office. You can also pay online any time from My Membership.', 'southpoll-membership' ) );
		}
		return $this->p( sprintf( /* translators: 1: farm name, 2: amount, 3: method */ __( 'You told us the SPGCA membership for <strong>%1$s</strong> was paid offline (%2$s by %3$s). The office will verify it and activate your membership, usually within a few business days.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), Str::money( (int) $this->get( 'amount_cents', 0 ) ), esc_html( (string) $this->get( 'method_label', '' ) ) ) )
			. $this->p( __( 'You will get another email as soon as it is confirmed. Until then your farm page stays private and listings cannot be published.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'View my membership', 'southpoll-membership' ), 'url' => (string) $this->get( 'manage_url', home_url( '/' ) ) ];
	}
}
