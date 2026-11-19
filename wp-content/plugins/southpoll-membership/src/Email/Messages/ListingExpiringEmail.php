<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** listing.expiring — data: title, farm_name, days, expires_at, renew_url, listing_url */
final class ListingExpiringEmail extends Message {

	/** Queued for the send window (see Notifier). */
	public function deferrable(): bool {
		return true;
	}

	public function key(): string {
		return 'listing.expiring';
	}

	public function subject(): string {
		$days = (int) $this->get( 'days', 7 );
		return $days <= 1
			? sprintf( /* translators: %s: title */ __( 'Your listing expires tomorrow: %s', 'southpoll-membership' ), (string) $this->get( 'title' ) )
			: sprintf( /* translators: 1: title, 2: days */ __( 'Your listing expires in %2$d days: %1$s', 'southpoll-membership' ), (string) $this->get( 'title' ), $days );
	}

	public function heading(): string {
		return __( 'Renew your listing?', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: title, 2: date */ __( '<strong>%1$s</strong> comes off the classifieds on <strong>%2$s</strong>. Still available? Renew it for another 60 days — you will get a chance to update the price, photos and details first.', 'southpoll-membership' ), esc_html( (string) $this->get( 'title' ) ), esc_html( Dates::display( (string) $this->get( 'expires_at' ) ) ) ) )
			. $this->p( __( 'Already sold? Mark it sold from My Listings so buyers know, and it will stay visible for two weeks as a sold listing.', 'southpoll-membership' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Review & renew', 'southpoll-membership' ), 'url' => (string) $this->get( 'renew_url' ) ];
	}
}
