<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** admin.broadcast — staff-written message to a farm's membership contacts (bulk action). data: subject, body, farm_name */
final class AdminBroadcastEmail extends Message {

	public function key(): string {
		return 'admin.broadcast';
	}

	public function subject(): string {
		return (string) $this->get( 'subject', __( 'A message from the South Poll Grass Cattle Association', 'southpoll-membership' ) );
	}

	public function heading(): string {
		return (string) $this->get( 'subject', __( 'A message from SPGCA', 'southpoll-membership' ) );
	}

	public function body(): string {
		$out = '';
		foreach ( preg_split( '/\r\n\r\n|\n\n|\r\r/', (string) $this->get( 'body' ) ) ?: [] as $para ) {
			if ( '' !== trim( $para ) ) {
				$out .= $this->p( nl2br( esc_html( trim( $para ) ) ) );
			}
		}
		return $out;
	}

	public function cta(): ?array {
		return $this->get( 'cta_url' ) ? [ 'label' => (string) $this->get( 'cta_label', __( 'Open my dashboard', 'southpoll-membership' ) ), 'url' => (string) $this->get( 'cta_url' ) ] : null;
	}

	public function footer_note(): string {
		return sprintf( /* translators: %s: farm */ __( 'Sent to the membership contacts of %s by the SPGCA office.', 'southpoll-membership' ), (string) $this->get( 'farm_name' ) );
	}
}
