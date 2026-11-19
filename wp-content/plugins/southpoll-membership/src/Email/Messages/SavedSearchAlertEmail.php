<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

/** alert.saved_search — AlertEmail data + search_name, search_summary, search_url, searches_url */
final class SavedSearchAlertEmail extends AlertEmail {

	public function key(): string {
		return 'alert.saved_search';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: search name, 2: title */ __( 'New match for "%1$s": %2$s', 'southpoll-membership' ), (string) $this->get( 'search_name' ), (string) $this->get( 'title' ) );
	}

	public function heading(): string {
		return __( 'New listing matches your saved search', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: 1: search name, 2: filters */ __( 'A listing matching your saved search <strong>%1$s</strong> (%2$s) was just published.', 'southpoll-membership' ), esc_html( (string) $this->get( 'search_name' ) ), esc_html( (string) $this->get( 'search_summary' ) ) ) )
			. $this->listing_block()
			. $this->p( '<a href="' . esc_url( (string) $this->get( 'search_url' ) ) . '" style="color:#8d183c">' . esc_html__( 'See everything that matches this search', 'southpoll-membership' ) . ' &rarr;</a>' );
	}
}
