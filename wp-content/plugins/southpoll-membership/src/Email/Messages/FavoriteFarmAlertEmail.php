<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

/** alert.favorite_farm — AlertEmail data + farm_name, farm_url, favorites_url */
final class FavoriteFarmAlertEmail extends AlertEmail {

	public function key(): string {
		return 'alert.favorite_farm';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: farm, 2: title */ __( 'New listing from %1$s: %2$s', 'southpoll-membership' ), (string) $this->get( 'farm_name' ), (string) $this->get( 'title' ) );
	}

	public function heading(): string {
		return __( 'A farm you follow posted a listing', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: %s: farm */ __( '<strong>%s</strong>, one of your favorite farms, just published a new classified listing.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) ) . $this->listing_block();
	}
}
