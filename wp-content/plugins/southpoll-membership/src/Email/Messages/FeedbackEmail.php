<?php
/**
 * Soft-launch feedback / problem report to the team (S8). Reply-to is the sender.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

final class FeedbackEmail extends Message {

	public function key(): string {
		return 'admin.feedback';
	}

	public function subject(): string {
		return sprintf( '[SPGCA feedback #%d] %s', (int) $this->get( 'id' ), wp_trim_words( (string) $this->get( 'message', '' ), 8, '…' ) );
	}

	public function heading(): string {
		return sprintf( /* translators: %d: id */ __( 'Feedback #%d', 'southpoll-membership' ), (int) $this->get( 'id' ) );
	}

	public function body(): string {
		$out  = $this->p( nl2br( esc_html( (string) $this->get( 'message', '' ) ) ) );
		$rows = [
			__( 'From', 'southpoll-membership' )       => esc_html( (string) $this->get( 'name' ) ) . ' &lt;' . esc_html( (string) $this->get( 'email' ) ) . '&gt;',
			__( 'Account', 'southpoll-membership' )    => esc_html( (string) $this->get( 'role' ) ) . ( $this->get( 'farm' ) ? ' · ' . esc_html( (string) $this->get( 'farm' ) ) : '' ),
			__( 'Page', 'southpoll-membership' )       => $this->get( 'page_url' ) ? '<a href="' . esc_url( (string) $this->get( 'page_url' ) ) . '">' . esc_html( (string) $this->get( 'page_url' ) ) . '</a>' : '—',
			__( 'Browser', 'southpoll-membership' )    => esc_html( (string) $this->get( 'user_agent' ) ) ?: '—',
			__( 'Screenshot', 'southpoll-membership' ) => $this->get( 'screenshot' ) ? '<a href="' . esc_url( (string) $this->get( 'screenshot' ) ) . '">' . __( 'Open screenshot', 'southpoll-membership' ) . '</a>' : '—',
			__( 'Received', 'southpoll-membership' )   => esc_html( (string) $this->get( 'created_at' ) ) . ' UTC',
		];
		return $out . $this->table( $rows );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Open in SouthPoll → Feedback', 'southpoll-membership' ), 'url' => (string) $this->get( 'admin_url' ) ];
	}

	public function reply_to(): string {
		return (string) $this->get( 'email', '' );
	}

	public function footer_note(): string {
		return __( 'Sent to the addresses under SouthPoll → Settings → Launch & feedback. Reply to this e-mail to answer the member directly.', 'southpoll-membership' );
	}
}
