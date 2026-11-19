<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/**
 * farm_user.removed (to the removed manager) / farm_user.joined (to the owner) — data: farm_name, person_name, event, dashboard_url
 */
final class FarmUserChangedEmail extends Message {

	public function key(): string {
		return 'joined' === $this->get( 'event' ) ? 'farm_user.joined' : 'farm_user.removed';
	}

	public function subject(): string {
		return 'joined' === $this->get( 'event' )
			? sprintf( /* translators: 1: person, 2: farm */ __( '%1$s joined %2$s as an account manager', 'southpoll-membership' ), (string) $this->get( 'person_name' ), (string) $this->get( 'farm_name' ) )
			: sprintf( /* translators: %s: farm */ __( 'Your access to %s on SouthPoll.com has ended', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return 'joined' === $this->get( 'event' ) ? __( 'New account manager', 'southpoll-membership' ) : __( 'Access removed', 'southpoll-membership' );
	}

	public function body(): string {
		if ( 'joined' === $this->get( 'event' ) ) {
			return $this->p( sprintf( /* translators: 1: person, 2: farm */ __( '<strong>%1$s</strong> accepted your invitation and can now help manage <strong>%2$s</strong>. You can adjust what they are notified about, or remove them, from the My Farm page.', 'southpoll-membership' ), esc_html( (string) $this->get( 'person_name' ) ), esc_html( (string) $this->get( 'farm_name' ) ) ) );
		}
		return $this->p( sprintf( /* translators: %s: farm */ __( 'The owner of <strong>%s</strong> removed you as an account manager. Your SouthPoll.com account stays active: you can still browse the directory and classifieds, save favorites and saved searches.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Go to my dashboard', 'southpoll-membership' ), 'url' => (string) $this->get( 'dashboard_url', home_url( '/' ) ) ];
	}
}
