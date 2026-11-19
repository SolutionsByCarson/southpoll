<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

/** alert.favorite_listing — AlertEmail data + old_status, new_status, is_public, favorites_url */
final class FavoriteListingAlertEmail extends AlertEmail {

	public function key(): string {
		return 'alert.favorite_listing';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: title, 2: status */ __( 'Update on a listing you favorited: %1$s is now %2$s', 'southpoll-membership' ), (string) $this->get( 'title' ), strtolower( (string) $this->get( 'new_status' ) ) );
	}

	public function heading(): string {
		return __( 'A favorite listing changed', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: old, 2: new */ __( 'Its status changed from <strong>%1$s</strong> to <strong>%2$s</strong>.', 'southpoll-membership' ), esc_html( (string) $this->get( 'old_status' ) ), esc_html( (string) $this->get( 'new_status' ) ) ) )
			. $this->listing_block()
			. ( $this->get( 'is_public', true ) ? '' : $this->p( __( 'The listing is no longer shown publicly; you can still see it under My Favorites.', 'southpoll-membership' ) ) );
	}

	public function cta(): ?array {
		return [ 'label' => $this->get( 'is_public', true ) ? __( 'View listing', 'southpoll-membership' ) : __( 'My Favorites', 'southpoll-membership' ), 'url' => (string) ( $this->get( 'is_public', true ) ? $this->get( 'url' ) : $this->get( 'favorites_url' ) ) ];
	}
}
