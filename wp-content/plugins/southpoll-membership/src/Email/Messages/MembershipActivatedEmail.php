<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;

/** membership.activated (new) / membership.renewed (extension) — data: farm_name, ends_at, is_renewal, auto_renew, dashboard_url */
final class MembershipActivatedEmail extends Message {

	public function key(): string {
		return $this->get( 'is_renewal' ) ? 'membership.renewed' : 'membership.activated';
	}

	public function subject(): string {
		return $this->get( 'is_renewal' )
			? sprintf( /* translators: %s: farm name */ __( 'Your SPGCA membership for %s has been renewed', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) )
			: sprintf( /* translators: %s: farm name */ __( 'Your SPGCA membership for %s is active', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return $this->get( 'is_renewal' ) ? __( 'Membership renewed', 'southpoll-membership' ) : __( 'Welcome, member!', 'southpoll-membership' );
	}

	public function body(): string {
		$ends = Dates::display( (string) $this->get( 'ends_at' ) );
		$out  = $this->p( $this->get( 'is_renewal' )
			? sprintf( /* translators: 1: farm name, 2: date */ __( 'Thank you! The membership for <strong>%1$s</strong> now runs through <strong>%2$s</strong>.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( $ends ) )
			: sprintf( /* translators: 1: farm name, 2: date */ __( 'Your South Poll Grass Cattle Association membership for <strong>%1$s</strong> is active through <strong>%2$s</strong>. Your farm page is now in the member directory and you can publish classified listings.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ), esc_html( $ends ) ) );
		$out .= $this->p( $this->get( 'auto_renew' )
			? __( 'Automatic renewal is on, so your membership will renew itself before it expires. You can change that any time from My Membership.', 'southpoll-membership' )
			: __( 'We will email you before your membership expires so you can renew in a couple of clicks.', 'southpoll-membership' ) );
		if ( ! $this->get( 'is_renewal' ) ) {
			$out .= $this->p( __( 'Next steps: add your logo, photos and herd details on the My Farm page, then post your first listing from My Listings.', 'southpoll-membership' ) );
		}
		return $out;
	}

	public function cta(): ?array {
		return [ 'label' => $this->get( 'is_renewal' ) ? __( 'View my membership', 'southpoll-membership' ) : __( 'Set up my farm page', 'southpoll-membership' ), 'url' => (string) $this->get( 'cta_url', home_url( '/' ) ) ];
	}
}
