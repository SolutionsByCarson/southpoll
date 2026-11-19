<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/**
 * Base for optional alert emails (favorites + saved searches). All are `configurable` and carry a one-click
 * unsubscribe link. Shared data: recipient_name, title, price, farm, location, url, summary, unsubscribe_url.
 */
abstract class AlertEmail extends Message {

	public function configurable(): bool {
		return true;
	}

	protected function listing_block(): string {
		$rows = array_filter( [
			__( 'Price', 'southpoll-membership' )    => esc_html( (string) $this->get( 'price' ) ),
			__( 'Farm', 'southpoll-membership' )     => esc_html( (string) $this->get( 'farm' ) ),
			__( 'Location', 'southpoll-membership' ) => esc_html( (string) $this->get( 'location' ) ),
		] );
		return '<p style="margin:0 0 6px;font-size:17px;font-weight:700;color:#272727;"><a href="' . esc_url( (string) $this->get( 'url' ) ) . '" style="color:#8d183c;text-decoration:none;">' . esc_html( (string) $this->get( 'title' ) ) . '</a></p>'
			. $this->table( $rows )
			. ( $this->get( 'summary' ) ? $this->p( esc_html( (string) $this->get( 'summary' ) ) ) : '' );
	}

	public function footer_note(): string {
		return sprintf( /* translators: %s: unsubscribe URL */ __( 'You asked for this alert. Turn it off with one click: %s — or manage all alerts under Account Details.', 'southpoll-membership' ), (string) $this->get( 'unsubscribe_url' ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'View listing', 'southpoll-membership' ), 'url' => (string) $this->get( 'url' ) ];
	}
}
