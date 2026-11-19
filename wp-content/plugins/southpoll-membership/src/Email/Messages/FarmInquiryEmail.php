<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/** farm.inquiry — data: farm_name, farm_url, sender_name, sender_email, sender_phone, message, sender_user, my_farm_url */
final class FarmInquiryEmail extends Message {

	public function key(): string {
		return 'farm.inquiry';
	}

	public function subject(): string {
		return sprintf( /* translators: 1: sender, 2: farm name */ __( 'New message for %2$s from %1$s via SouthPoll.com', 'southpoll-membership' ), (string) $this->get( 'sender_name' ), (string) $this->get( 'farm_name' ) );
	}

	public function heading(): string {
		return __( 'You have a new message', 'southpoll-membership' );
	}

	public function preheader(): string {
		return wp_trim_words( (string) $this->get( 'message' ), 18 );
	}

	public function body(): string {
		$user = $this->get( 'sender_user' );
		$rows = array_filter( [
			__( 'From', 'southpoll-membership' )  => esc_html( (string) $this->get( 'sender_name' ) ) . ( $user instanceof \WP_User ? ' <span style="color:#6f6f6f;font-weight:400">(' . esc_html__( 'SouthPoll.com account', 'southpoll-membership' ) . ')</span>' : '' ),
			__( 'Email', 'southpoll-membership' ) => '<a href="mailto:' . esc_attr( (string) $this->get( 'sender_email' ) ) . '" style="color:#8d183c">' . esc_html( (string) $this->get( 'sender_email' ) ) . '</a>',
			__( 'Phone', 'southpoll-membership' ) => esc_html( (string) $this->get( 'sender_phone' ) ),
		] );
		return $this->p( sprintf( /* translators: %s: farm name */ __( 'Someone used the contact form on the <strong>%s</strong> page at SouthPoll.com. Reply to this email to answer them directly.', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) )
			. $this->table( $rows )
			. '<div style="margin:0 0 16px;padding:14px 16px;background:#f7f7f7;border-left:4px solid #b2892e;font-size:15px;line-height:1.6;color:#272727;white-space:pre-wrap;">' . esc_html( (string) $this->get( 'message' ) ) . '</div>';
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Reply by email', 'southpoll-membership' ), 'url' => 'mailto:' . (string) $this->get( 'sender_email' ) . '?subject=' . rawurlencode( 'Re: your message to ' . (string) $this->get( 'farm_name' ) ) ];
	}

	public function reply_to(): string {
		return (string) $this->get( 'sender_email' );
	}

	public function footer_note(): string {
		return sprintf( /* translators: %s: My Farm URL */ __( 'You received this because you are a contact for this farm. Farm owners can turn the contact form off under My Farm: %s', 'southpoll-membership' ), (string) $this->get( 'my_farm_url' ) );
	}
}
