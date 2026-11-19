<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** listing.expired — data: title, farm_name, renew_url, listings_url */
final class ListingExpiredEmail extends Message {

	/** Queued for the send window (see Notifier). */
	public function deferrable(): bool {
		return true;
	}

	public function key(): string {
		return 'listing.expired';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: title */ __( 'Your listing has expired: %s', 'southpoll-membership' ), (string) $this->get( 'title' ) );
	}

	public function heading(): string {
		return __( 'Listing expired', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: %s: title */ __( '<strong>%s</strong> reached the end of its 60-day run and is no longer showing in the classifieds. Nothing has been deleted: renew it to put it back, or leave it as a record.', 'southpoll-membership' ), esc_html( (string) $this->get( 'title' ) ) ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Renew listing', 'southpoll-membership' ), 'url' => (string) $this->get( 'renew_url' ) ];
	}
}
