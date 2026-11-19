<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** listing.renewed — data: title, url, expires_at */
final class ListingRenewedEmail extends Message {

	public function key(): string {
		return 'listing.renewed';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: title */ __( 'Listing renewed: %s', 'southpoll-membership' ), (string) $this->get( 'title' ) );
	}

	public function heading(): string {
		return __( 'Listing renewed', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: title, 2: date */ __( '<strong>%1$s</strong> now runs until <strong>%2$s</strong>.', 'southpoll-membership' ), esc_html( (string) $this->get( 'title' ) ), esc_html( Dates::display( (string) $this->get( 'expires_at' ) ) ) ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'View listing', 'southpoll-membership' ), 'url' => (string) $this->get( 'url' ) ];
	}
}
