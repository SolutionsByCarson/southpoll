<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

/**
 * Generic staff notification. data: type (new_account|new_membership|renewal|expired|claim_submitted|payment_failed|cancelled),
 * summary (one line), rows (label => value), cta_label, cta_url
 */
final class AdminNoticeEmail extends Message {

	private const TITLES = [
		'new_account'     => 'New account created',
		'new_membership'  => 'New membership activated',
		'renewal'         => 'Membership renewed',
		'expired'         => 'Membership expired',
		'claim_submitted' => 'Offline payment claim to verify',
		'payment_failed'  => 'Membership payment failed',
		'cancelled'       => 'Membership cancelled',
		'inquiry'         => 'Farm inquiry (copy)',
		'listing_inquiry' => 'Listing inquiry (copy)',
		'new_listing'     => 'New classified listing published',
	];

	public function key(): string {
		return 'admin.' . (string) $this->get( 'type', 'notice' );
	}

	public function subject(): string {
		$title = self::TITLES[ (string) $this->get( 'type' ) ] ?? 'Membership notice';
		$who   = (string) $this->get( 'farm_name', $this->get( 'person_name', '' ) );
		return '[SPGCA] ' . $title . ( $who ? ': ' . $who : '' );
	}

	public function heading(): string {
		return self::TITLES[ (string) $this->get( 'type' ) ] ?? 'Membership notice';
	}

	public function body(): string {
		$out = '';
		if ( $this->get( 'summary' ) ) {
			$out .= $this->p( (string) $this->get( 'summary' ) );
		}
		$rows = (array) $this->get( 'rows', [] );
		if ( ! empty( $rows ) ) {
			$out .= $this->table( $rows );
		}
		if ( 'claim_submitted' === $this->get( 'type' ) && $this->get( 'reject_url' ) ) {
			$out .= $this->p( '<a href="' . esc_url( (string) $this->get( 'reject_url' ) ) . '" style="color:#a71000;">' . __( 'Reject this claim', 'southpoll-membership' ) . '</a> · ' . __( 'or approve it with the button below once the payment is confirmed.', 'southpoll-membership' ) );
		}
		return $out;
	}

	public function cta(): ?array {
		return $this->get( 'cta_url' ) ? [ 'label' => (string) $this->get( 'cta_label', __( 'Open in admin', 'southpoll-membership' ) ), 'url' => (string) $this->get( 'cta_url' ) ] : null;
	}

	public function footer_note(): string {
		return __( 'Sent to the SPGCA membership admin addresses configured in SouthPoll → Settings.', 'southpoll-membership' );
	}
}
