<?php
/**
 * Offline (check/cash) payment claims: member submits → staff verifies → membership activates.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Payments;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\OfflineClaimEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class OfflineClaims {

	public function __construct(
		private PaymentRepository $payments,
		private MembershipRepository $memberships,
		private TierRepository $tiers,
		private StatusEngine $engine,
		private FarmRepository $farms,
		private Notifier $notifier,
		private Urls $urls,
		private Settings $settings,
		private CheckoutService $checkout,
		private Logger $logger
	) {}

	/**
	 * Member says "I paid by check/cash".
	 *
	 * @param array{method:string, amount_cents?:int, paid_on?:string, note?:string} $in
	 */
	public function submit( MemberContext $ctx, object $tier, array $in ): \stdClass|WP_Error {
		if ( ! $this->settings->get( 'membership_offline_claims_enabled', true ) ) {
			return new WP_Error( 'sp_claims_disabled', __( 'Offline payment claims are not accepted right now. Please pay online or contact the office.', 'southpoll-membership' ) );
		}
		if ( ! $ctx->can( 'manage_membership' ) || ! $ctx->has_farm() ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the farm owner can submit a payment claim.', 'southpoll-membership' ) );
		}
		if ( $this->payments->has_pending_claim( $ctx->farm_id() ) ) {
			return new WP_Error( 'sp_claim_exists', __( 'You already have a payment claim waiting for verification.', 'southpoll-membership' ) );
		}
		$method = in_array( $in['method'] ?? '', [ 'check', 'cash', 'other' ], true ) ? $in['method'] : 'check';
		$amount = isset( $in['amount_cents'] ) && (int) $in['amount_cents'] > 0 ? (int) $in['amount_cents'] : (int) $tier->price_cents;
		$note   = sanitize_textarea_field( (string) ( $in['note'] ?? '' ) );
		$paid   = ! empty( $in['paid_on'] ) ? sanitize_text_field( (string) $in['paid_on'] ) : '';
		$now    = Dates::now();
		$farm   = $ctx->farm;
		$m      = $this->memberships->ensure( (int) $farm->id );

		$payment = $this->payments->create( [
			'membership_id' => (int) $m->id,
			'farm_id'       => (int) $farm->id,
			'user_id'       => $ctx->user_id,
			'tier_id'       => (int) $tier->id,
			'amount_cents'  => $amount,
			'currency'      => (string) $tier->currency,
			'method'        => $method,
			'status'        => 'pending',
			'notes'         => '[' . $now . '] Claimed by member' . ( $paid ? ' — paid on ' . $paid : '' ) . ( $note ? ' — "' . $note . '"' : '' ),
		] );
		$this->engine->set_pending( (int) $farm->id, 'Offline claim #' . $payment->id . ' (' . $method . ', ' . Str::money( $amount ) . ')' );

		$this->notifier->farm_membership_contacts( $farm, ( new OfflineClaimEmail() )->with( [ 'status' => 'received', 'farm_name' => $farm->name, 'amount_cents' => $amount, 'method_label' => PaymentRepository::method_label( $method ), 'manage_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
		$owner = get_userdata( (int) $farm->owner_user_id );
		$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
			'type'       => 'claim_submitted',
			'farm_name'  => $farm->name,
			'summary'    => sprintf( '%s says the membership for %s was paid offline. Verify the payment, then approve or reject it.', $owner ? $this->notifier->name( $owner ) : 'A member', $farm->name ),
			'rows'       => array_filter( [ 'Farm' => $farm->name, 'Owner' => $owner ? $this->notifier->name( $owner ) . ' &lt;' . esc_html( $owner->user_email ) . '&gt;' : '', 'Breeder #' => (string) $farm->breeder_number, 'Method' => PaymentRepository::method_label( $method ), 'Amount' => Str::money( $amount ), 'Paid on (per member)' => $paid, 'Note' => $note ] ),
			'cta_label'  => 'Review claims',
			'cta_url'    => admin_url( 'admin.php?page=southpoll-memberships' ),
			'reject_url' => admin_url( 'admin.php?page=southpoll-memberships' ),
		] ) );
		$this->logger->info( "Offline claim #{$payment->id} submitted for farm {$farm->id}" );
		return $payment;
	}

	/** Staff approval: activates/extends the membership from the verification date (or an override). */
	public function approve( int $payment_id, int $actor, string $paid_at = '', string $note = '' ): \stdClass|WP_Error {
		$payment = $this->payments->find( $payment_id );
		if ( ! $payment || 'pending' !== $payment->status ) {
			return new WP_Error( 'sp_claim_state', 'Claim is not pending.' );
		}
		$farm = $this->farms->find( (int) $payment->farm_id );
		$tier = $this->tiers->find( (int) $payment->tier_id ) ?? $this->tiers->default();
		if ( ! $farm || ! $tier ) {
			return new WP_Error( 'sp_claim_missing', 'Farm or tier missing.' );
		}
		$when       = $paid_at && strtotime( $paid_at ) ? gmdate( Dates::MYSQL, (int) strtotime( $paid_at ) ) : Dates::now();
		$membership = $this->memberships->ensure( (int) $farm->id );
		$was_active = $this->engine->is_active( $membership );
		$updated    = $this->engine->activate( (int) $farm->id, $tier, [ 'source' => 'offline', 'auto_renew' => false, 'price_cents' => (int) $payment->amount_cents, 'actor' => $actor, 'paid_at' => $when, 'note' => 'Offline claim #' . $payment_id . ' approved' . ( $note ? ': ' . $note : '' ) ] );
		$this->payments->update( $payment_id, [ 'status' => 'succeeded', 'paid_at' => $when, 'verified_by' => $actor, 'term_start' => $was_active ? Dates::add_days( (string) $updated->ends_at, -(int) $tier->duration_days ) : $updated->starts_at, 'term_end' => $updated->ends_at ] );
		$this->payments->append_note( $this->payments->find( $payment_id ), 'Approved by staff #' . $actor . ( $note ? ': ' . $note : '' ) );
		$this->checkout->send_success_emails( $farm, $updated, $this->payments->find( $payment_id ), $tier, $was_active, PaymentRepository::method_label( (string) $payment->method ) );
		$this->logger->info( "Offline claim #{$payment_id} approved by {$actor}" );
		return $this->payments->find( $payment_id );
	}

	public function reject( int $payment_id, int $actor, string $note = '' ): \stdClass|WP_Error {
		$payment = $this->payments->find( $payment_id );
		if ( ! $payment || 'pending' !== $payment->status ) {
			return new WP_Error( 'sp_claim_state', 'Claim is not pending.' );
		}
		$this->payments->update( $payment_id, [ 'status' => 'rejected', 'verified_by' => $actor ] );
		$this->payments->append_note( $payment, 'Rejected by staff #' . $actor . ( $note ? ': ' . $note : '' ) );
		$this->engine->clear_pending( (int) $payment->farm_id, 'Offline claim #' . $payment_id . ' rejected' );
		$farm = $this->farms->find( (int) $payment->farm_id );
		if ( $farm ) {
			$this->notifier->farm_membership_contacts( $farm, ( new OfflineClaimEmail() )->with( [ 'status' => 'rejected', 'farm_name' => $farm->name, 'note' => $note, 'manage_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
		}
		$this->logger->info( "Offline claim #{$payment_id} rejected by {$actor}" );
		return $this->payments->find( $payment_id );
	}

	/**
	 * Staff: record an offline payment directly (no member claim) and activate.
	 */
	public function record( int $farm_id, string $method, int $amount_cents, string $paid_at, int $actor, string $note = '' ): \stdClass|WP_Error {
		$farm = $this->farms->find( $farm_id );
		$tier = $this->tiers->default();
		if ( ! $farm || ! $tier ) {
			return new WP_Error( 'sp_missing', 'Farm or tier missing.' );
		}
		$m       = $this->memberships->ensure( $farm_id );
		$payment = $this->payments->create( [ 'membership_id' => (int) $m->id, 'farm_id' => $farm_id, 'user_id' => (int) $farm->owner_user_id, 'tier_id' => (int) $tier->id, 'amount_cents' => $amount_cents, 'currency' => (string) $tier->currency, 'method' => in_array( $method, [ 'check', 'cash', 'other', 'comp' ], true ) ? $method : 'other', 'status' => 'pending', 'notes' => '[' . Dates::now() . '] Recorded by staff #' . $actor ] );
		return $this->approve( (int) $payment->id, $actor, $paid_at, $note );
	}
}
