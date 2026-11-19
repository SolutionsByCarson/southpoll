<?php
declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

/** membership.receipt — data: farm_name, amount_cents, currency, method_label, paid_at, term_start, term_end, receipt_url, payments_url */
final class PaymentReceiptEmail extends Message {

	public function key(): string {
		return 'membership.receipt';
	}

	public function subject(): string {
		return sprintf( /* translators: %s: amount */ __( 'Receipt: %s SPGCA membership payment', 'southpoll-membership' ), Str::money( (int) $this->get( 'amount_cents', 0 ), (string) $this->get( 'currency', 'USD' ) ) );
	}

	public function heading(): string {
		return __( 'Payment received', 'southpoll-membership' );
	}

	public function body(): string {
		return $this->p( sprintf( /* translators: %s: farm name */ __( 'Thank you for your payment toward the SPGCA membership for <strong>%s</strong>. Here is your receipt:', 'southpoll-membership' ), esc_html( (string) $this->get( 'farm_name' ) ) ) )
			. $this->table( array_filter( [
				__( 'Amount', 'southpoll-membership' )       => Str::money( (int) $this->get( 'amount_cents', 0 ), (string) $this->get( 'currency', 'USD' ) ),
				__( 'Paid with', 'southpoll-membership' )    => (string) $this->get( 'method_label', '' ),
				__( 'Date', 'southpoll-membership' )         => Dates::display( (string) $this->get( 'paid_at', '' ) ),
				__( 'Membership term', 'southpoll-membership' ) => trim( Dates::display( (string) $this->get( 'term_start', '' ) ) . ' – ' . Dates::display( (string) $this->get( 'term_end', '' ) ), ' –' ),
				__( 'Reference', 'southpoll-membership' )    => (string) $this->get( 'reference', '' ),
			] ) )
			. ( $this->get( 'receipt_url' ) ? $this->p( '<a href="' . esc_url( (string) $this->get( 'receipt_url' ) ) . '" style="color:#8d183c;">' . __( 'View the card receipt from Stripe', 'southpoll-membership' ) . '</a>' ) : '' );
	}

	public function cta(): ?array {
		return [ 'label' => __( 'View payment history', 'southpoll-membership' ), 'url' => (string) $this->get( 'payments_url', home_url( '/' ) ) ];
	}

	public function footer_note(): string {
		return __( 'Keep this email for your records. Membership payments and receipts are always sent and cannot be turned off.', 'southpoll-membership' );
	}
}
