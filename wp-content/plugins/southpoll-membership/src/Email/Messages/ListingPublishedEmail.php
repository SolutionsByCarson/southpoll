<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** listing.published — data: title, farm_name, url, expires_at, price, manage_url, first */
final class ListingPublishedEmail extends Message {

	public function key(): string {
		return 'listing.published';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: title */ $this->get( 'first', true ) ? __( 'Your listing is live: %s', 'southpoll-membership' ) : __( 'Your listing is live again: %s', 'southpoll-membership' ), (string) $this->get( 'title' ) );
	}

	public function heading(): string {
		return __( 'Listing published', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: title, 2: farm */ __( '<strong>%1$s</strong> from <strong>%2$s</strong> is now showing in the SouthPoll.com classifieds.', 'southpoll-membership' ), esc_html( (string) $this->get( 'title' ) ), esc_html( (string) $this->get( 'farm_name' ) ) ) )
			. $this->table( array_filter( [ __( 'Price', 'southpoll-membership' ) => esc_html( (string) $this->get( 'price' ) ), __( 'Runs until', 'southpoll-membership' ) => $this->get( 'expires_at' ) ? esc_html( Dates::display( (string) $this->get( 'expires_at' ) ) ) : '' ] ) )
			. $this->p( __( 'Listings run for 60 days. We will email you a week before it expires so you can renew it with one click, and you can mark it sold, pause it, or edit it any time from My Listings.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'View listing', 'southpoll-membership' ), 'url' => (string) $this->get( 'url' ) ];
	}
}
