<?php
/**
 * sp_payments data access: Stripe payments, offline claims, admin-recorded payments, migrated history.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Payments;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;

final class PaymentRepository {

	public const COLUMNS = [ 'membership_id', 'farm_id', 'user_id', 'tier_id', 'amount_cents', 'currency', 'method', 'status', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_invoice_id', 'stripe_charge_id', 'stripe_subscription_id', 'recurring', 'receipt_url', 'term_start', 'term_end', 'paid_at', 'verified_by', 'legacy_payment_id', 'notes' ];

	private function table(): string {
		return Schema::table( 'sp_payments' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_session( string $session_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_checkout_session_id = %s", $session_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_invoice( string $invoice_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_invoice_id = %s", $invoice_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_intent( string $intent_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_payment_intent_id = %s", $intent_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): object {
		global $wpdb;
		$row = array_intersect_key( $data, array_flip( self::COLUMNS ) );
		$row = array_merge( [ 'amount_cents' => 0, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending' ], $row );
		$row['created_at'] = Dates::now();
		$row['updated_at'] = $row['created_at'];
		$wpdb->insert( $this->table(), $row );
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data = array_intersect_key( $data, array_flip( self::COLUMNS ) );
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	/**
	 * Atomically take a payment row for fulfilment: the browser return URL and the Stripe webhook both try to fulfil the
	 * same checkout within milliseconds; only the caller that flips the row to `processing` continues.
	 */
	public function claim( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'processing', updated_at = %s WHERE id = %d AND status NOT IN ('succeeded', 'processing')", Dates::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function append_note( object $payment, string $note ): void {
		$existing = trim( (string) ( $payment->notes ?? '' ) );
		$line     = '[' . Dates::now() . '] ' . sanitize_text_field( $note );
		$this->update( (int) $payment->id, [ 'notes' => '' === $existing ? $line : $existing . "\n" . $line ] );
	}

	/** Payment history for a farm (newest first). @return object[] */
	public function for_farm( int $farm_id, int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d ORDER BY COALESCE(paid_at, created_at) DESC, id DESC LIMIT %d", $farm_id, $limit ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Pending offline claims (check/cash/other) for staff verification. @return object[] */
	public function pending_claims(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE status = 'pending' AND method IN ('check','cash','other') ORDER BY created_at ASC" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function has_pending_claim( int $farm_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE farm_id = %d AND status = 'pending' AND method IN ('check','cash','other')", $farm_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function method_label( string $method ): string {
		return match ( $method ) {
			'stripe'   => __( 'Card (Stripe)', 'southpoll-membership' ),
			'check'    => __( 'Check', 'southpoll-membership' ),
			'cash'     => __( 'Cash', 'southpoll-membership' ),
			'migrated' => __( 'Previous system', 'southpoll-membership' ),
			'comp'     => __( 'Complimentary', 'southpoll-membership' ),
			default    => ucfirst( $method ),
		};
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'pending'   => __( 'Pending', 'southpoll-membership' ),
			'succeeded' => __( 'Paid', 'southpoll-membership' ),
			'failed'    => __( 'Failed', 'southpoll-membership' ),
			'refunded'  => __( 'Refunded', 'southpoll-membership' ),
			'rejected'  => __( 'Rejected', 'southpoll-membership' ),
			default     => ucfirst( $status ),
		};
	}
}
