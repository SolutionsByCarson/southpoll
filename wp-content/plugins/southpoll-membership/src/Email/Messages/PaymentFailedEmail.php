<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** membership.payment_failed — data: farm_name, ends_at, reason, manage_url, is_final */
final class PaymentFailedEmail extends Message {

	public function key(): string {
		return 'membership.payment_failed';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: farm name */ __( 'Action needed: SPGCA membership payment for %s failed', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return __( 'We could not process your renewal', 'southpoll-membership' );
	}

	public function body(): string {
		$out = $this->p( sprintf( /* translators: %s: farm name */ __( 'The automatic renewal payment for <strong>%s</strong> did not go through.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) );
		if ( $this->get( 'reason' ) ) {
			$out .= $this->p( sprintf( /* translators: %s: reason */ __( 'The card issuer said: %s', 'southpoll-membership' ), esc_html( (string) $this->get( 'reason' ) ) ) );
		}
		$out .= $this->p( $this->get( 'is_final' )
			? __( 'Your membership has now expired. Renew with a working card or let the office know about an offline payment to restore your farm page and listings.', 'southpoll-membership' )
			: sprintf( /* translators: %s: date */ __( 'We will retry automatically. Your membership stays active until %s; please update your payment method before then.', 'southpoll-membership' ), esc_html( Dates::display( (string) $this->get( 'ends_at' ) ) ) ) );
		return $out;
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Update payment method', 'southpoll-membership' ), 'url' => (string) $this->get( 'manage_url', home_url( '/' ) ) ];
	}
}
