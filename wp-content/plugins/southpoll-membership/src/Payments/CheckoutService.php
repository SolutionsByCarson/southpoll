<?php
/**
 * Membership checkout + fulfilment + webhook event handling.
 *
 * Flow: start() creates a pending sp_payments row and a Stripe Checkout Session → member pays on Stripe →
 * complete_session() (return URL) and/or the checkout.session.completed webhook call fulfil() exactly once.
 * Recurring renewals arrive as invoice.paid events and extend the term; failures notify.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Payments;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\MembershipActivatedEmail;
use SouthPoll\Membership\Email\Messages\PaymentFailedEmail;
use SouthPoll\Membership\Email\Messages\PaymentReceiptEmail;
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

final class CheckoutService {

	public function __construct(
		private StripeService $stripe,
		private PaymentRepository $payments,
		private MembershipRepository $memberships,
		private TierRepository $tiers,
		private StatusEngine $engine,
		private FarmRepository $farms,
		private Notifier $notifier,
		private Urls $urls,
		private Logger $logger
	) {}

	/* ------------------------------------------------------------------ start */

	/**
	 * Create a Checkout Session for the member's farm. Returns the Stripe URL to redirect to.
	 */
	public function start( MemberContext $ctx, object $tier, bool $recurring ): string|WP_Error {
		if ( ! $ctx->can( 'manage_membership' ) || ! $ctx->has_farm() ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the farm owner can purchase or renew the membership.', 'southpoll-membership' ) );
		}
		if ( ! $this->stripe->client()->configured() ) {
			return new WP_Error( 'sp_stripe_not_configured', __( 'Online payments are not configured yet. You can still tell us about an offline payment below.', 'southpoll-membership' ) );
		}
		$farm       = $ctx->farm;
		$membership = $this->memberships->ensure( (int) $farm->id );
		$user       = wp_get_current_user();
		$billing    = ! empty( $farm->billing_user_id ) ? get_userdata( (int) $farm->billing_user_id ) : null;
		$email      = $billing ? $billing->user_email : $user->user_email;

		$customer = $this->stripe->ensure_customer( $farm, $membership, $email, $user->display_name );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$prices = $this->stripe->ensure_tier_prices( $tier );
		if ( is_wp_error( $prices ) ) {
			return $prices;
		}

		$active   = $this->engine->is_active( $membership );
		$trial_at = null;
		if ( $recurring && $active && ! empty( $membership->ends_at ) ) {
			// Switching an active one-time membership to auto-renew: no charge now, first invoice at the current end date.
			$ends = Dates::utc( (string) $membership->ends_at )->getTimestamp();
			if ( $ends - time() >= 2 * DAY_IN_SECONDS ) { // Stripe requires trial_end ≥ 48h ahead
				$trial_at = $ends;
			}
		}

		$payment = $this->payments->create( [
			'membership_id' => (int) $membership->id,
			'farm_id'       => (int) $farm->id,
			'user_id'       => $user->ID,
			'tier_id'       => (int) $tier->id,
			'amount_cents'  => $trial_at ? 0 : (int) $tier->price_cents,
			'currency'      => (string) $tier->currency,
			'method'        => 'stripe',
			'status'        => 'pending',
			'recurring'     => (int) $recurring,
			'notes'         => $trial_at ? '[' . Dates::now() . '] Auto-renew setup (no charge until ' . $membership->ends_at . ')' : null,
		] );

		$success = add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $this->urls->checkout_return( 'success' ) );
		$cancel  = add_query_arg( 'payment', (int) $payment->id, $this->urls->checkout_return( 'cancel' ) );
		$meta    = [ 'payment_id' => (int) $payment->id, 'farm_id' => (int) $farm->id, 'tier_id' => (int) $tier->id, 'recurring' => $recurring ? '1' : '0', 'site' => home_url( '/' ) ];

		$params = [
			'mode'                 => $recurring ? 'subscription' : 'payment',
			'customer'             => $customer,
			'client_reference_id'  => (string) $payment->id,
			'success_url'          => $success,
			'cancel_url'           => $cancel,
			'line_items'           => [ [ 'price' => $recurring ? $prices['recurring'] : $prices['price'], 'quantity' => 1 ] ],
			'metadata'             => $meta,
			'allow_promotion_codes' => 'true',
			'billing_address_collection' => 'auto',
			'customer_update'      => [ 'address' => 'auto', 'name' => 'auto' ],
		];
		if ( $recurring ) {
			$params['subscription_data'] = [ 'metadata' => $meta ];
			if ( $trial_at ) {
				$params['subscription_data']['trial_end']  = $trial_at;
				$params['payment_method_collection']       = 'always';
			}
		} else {
			$params['payment_intent_data'] = [ 'metadata' => $meta, 'description' => sprintf( 'SPGCA %s — %s', $tier->name, $farm->name ) ];
			$params['invoice_creation']    = [ 'enabled' => 'true' ];
		}

		$session = $this->stripe->create_checkout_session( $params, 'sp_checkout_' . (int) $payment->id . '_' . wp_hash( (string) $payment->created_at ) );
		if ( is_wp_error( $session ) ) {
			$this->payments->update( (int) $payment->id, [ 'status' => 'failed', 'notes' => 'Checkout session creation failed: ' . $session->get_error_message() ] );
			return $session;
		}
		$this->payments->update( (int) $payment->id, [ 'stripe_checkout_session_id' => (string) $session['id'] ] );
		$this->logger->info( "Checkout session {$session['id']} created for farm {$farm->id} (payment {$payment->id}, " . ( $recurring ? 'recurring' : 'one-time' ) . ')' );
		return (string) $session['url'];
	}

	/* ------------------------------------------------------- return / fulfil */

	/**
	 * Called from the success return URL. Idempotent with the webhook.
	 *
	 * @return array{payment:object, result:string}|WP_Error result: fulfilled|already|pending|auto_renew
	 */
	public function complete_session( string $session_id ): array|WP_Error {
		$payment = $this->payments->find_by_session( $session_id );
		if ( ! $payment ) {
			return new WP_Error( 'sp_payment_not_found', __( 'We could not find that checkout. If you were charged, contact the office and we will sort it out.', 'southpoll-membership' ) );
		}
		if ( 'succeeded' === $payment->status ) {
			return [ 'payment' => $payment, 'result' => 'already' ];
		}
		$session = $this->stripe->retrieve_checkout_session( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return $this->fulfil_session( $payment, $session );
	}

	public function cancel_session( int $payment_id, int $user_id = 0 ): void {
		$payment = $this->payments->find( $payment_id );
		if ( $payment && $user_id > 0 && (int) $payment->user_id !== $user_id ) {
			return; // only the member who started the checkout may cancel it
		}
		if ( $payment && 'pending' === $payment->status && 'stripe' === $payment->method ) {
			$this->payments->update( $payment_id, [ 'status' => 'failed' ] );
			$this->payments->append_note( $payment, 'Checkout cancelled by member' );
		}
	}

	/**
	 * @param array<string, mixed> $session Expanded Checkout Session
	 * @return array{payment:object, result:string}|WP_Error
	 */
	public function fulfil_session( object $payment, array $session ): array|WP_Error {
		if ( 'succeeded' === $payment->status ) {
			return [ 'payment' => $payment, 'result' => 'already' ];
		}
		$status = (string) ( $session['payment_status'] ?? '' );
		if ( ! in_array( $status, [ 'paid', 'no_payment_required' ], true ) ) {
			return [ 'payment' => $payment, 'result' => 'pending' ];
		}
		// The success URL and the webhook race for the same session: only the caller that claims the row fulfils it,
		// otherwise the term would be extended twice and every receipt sent twice.
		if ( ! $this->payments->claim( (int) $payment->id ) ) {
			$fresh = $this->payments->find( (int) $payment->id );
			return [ 'payment' => $fresh ?: $payment, 'result' => $fresh && 'succeeded' === $fresh->status ? 'already' : 'pending' ];
		}
		$membership = $this->memberships->find( (int) $payment->membership_id ) ?? $this->memberships->ensure( (int) $payment->farm_id );
		$tier       = $this->tiers->find( (int) $payment->tier_id ) ?? $this->tiers->default();
		$farm       = $this->farms->find( (int) $payment->farm_id );
		if ( ! $farm || ! $tier ) {
			return new WP_Error( 'sp_fulfil_missing', 'Farm or tier missing during fulfilment.' );
		}
		$intent      = $session['payment_intent'] ?? null;
		$sub         = $session['subscription'] ?? null;
		$intent_id   = is_array( $intent ) ? (string) ( $intent['id'] ?? '' ) : (string) ( $intent ?? '' );
		$sub_id      = is_array( $sub ) ? (string) ( $sub['id'] ?? '' ) : (string) ( $sub ?? '' );
		$charge      = is_array( $intent ) && is_array( $intent['latest_charge'] ?? null ) ? $intent['latest_charge'] : null;
		$invoice     = is_array( $sub ) && is_array( $sub['latest_invoice'] ?? null ) ? $sub['latest_invoice'] : null;
		$invoice_id  = $invoice ? (string) $invoice['id'] : ( is_array( $sub ) ? (string) ( $sub['latest_invoice'] ?? '' ) : '' );
		$amount      = (int) ( $session['amount_total'] ?? 0 );
		$recurring   = (int) $payment->recurring === 1 || '' !== $sub_id;
		$now         = Dates::now();
		$is_trial    = 'no_payment_required' === $status || ( $recurring && 0 === $amount && is_array( $sub ) && 'trialing' === ( $sub['status'] ?? '' ) );

		$this->payments->update( (int) $payment->id, [
			'status'                   => 'succeeded',
			'amount_cents'             => $amount,
			'currency'                 => strtoupper( (string) ( $session['currency'] ?? $payment->currency ) ),
			'stripe_payment_intent_id' => $intent_id,
			'stripe_charge_id'         => $charge ? (string) $charge['id'] : '',
			'stripe_invoice_id'        => $invoice_id,
			'stripe_subscription_id'   => $sub_id,
			'receipt_url'              => $charge ? (string) ( $charge['receipt_url'] ?? '' ) : ( $invoice ? (string) ( $invoice['hosted_invoice_url'] ?? '' ) : '' ),
			'paid_at'                  => $now,
			'recurring'                => (int) $recurring,
		] );

		if ( $is_trial ) {
			$this->engine->set_auto_renew( $membership, true, $sub_id );
			$this->payments->update( (int) $payment->id, [ 'term_start' => $membership->starts_at, 'term_end' => $membership->ends_at ] );
			$this->logger->info( "Auto-renew enabled for farm {$farm->id} via subscription {$sub_id} (trial until {$membership->ends_at})" );
			return [ 'payment' => $this->payments->find( (int) $payment->id ), 'result' => 'auto_renew' ];
		}

		$was_active = $this->engine->is_active( $membership );
		$updated    = $this->engine->activate( (int) $farm->id, $tier, [
			'source'                 => 'stripe',
			'auto_renew'             => $recurring,
			'stripe_customer_id'     => (string) ( $session['customer'] ?? '' ),
			'stripe_subscription_id' => $sub_id,
			'price_cents'            => $amount,
			'actor'                  => (int) $payment->user_id,
			'paid_at'                => $now,
		] );
		$this->payments->update( (int) $payment->id, [ 'term_start' => $was_active ? Dates::add_days( (string) $updated->ends_at, -(int) $tier->duration_days ) : $updated->starts_at, 'term_end' => $updated->ends_at ] );
		$this->send_success_emails( $farm, $updated, $this->payments->find( (int) $payment->id ), $tier, $was_active, 'Card (Stripe)' );
		return [ 'payment' => $this->payments->find( (int) $payment->id ), 'result' => 'fulfilled' ];
	}

	/* ---------------------------------------------------------- webhook events */

	/**
	 * Dispatch a verified Stripe event. Returns a short result string for logging.
	 */
	public function handle_event( array $event ): string {
		$type   = (string) ( $event['type'] ?? '' );
		$object = (array) ( $event['data']['object'] ?? [] );
		return match ( $type ) {
			'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->on_checkout_completed( $object ),
			'checkout.session.async_payment_failed' => $this->on_checkout_failed( $object ),
			'invoice.paid'                          => $this->on_invoice_paid( $object ),
			'invoice.payment_failed'                => $this->on_invoice_failed( $object ),
			'customer.subscription.updated'         => $this->on_subscription_updated( $object ),
			'customer.subscription.deleted'         => $this->on_subscription_deleted( $object ),
			'charge.refunded'                       => $this->on_charge_refunded( $object ),
			default                                 => 'ignored',
		};
	}

	private function on_checkout_completed( array $session ): string {
		$payment = $this->payments->find_by_session( (string) ( $session['id'] ?? '' ) );
		if ( ! $payment ) {
			return 'unknown_session';
		}
		if ( 'succeeded' === $payment->status ) {
			return 'already';
		}
		// The webhook payload is not expanded; fetch the expanded session for charge/invoice details.
		$full = $this->stripe->retrieve_checkout_session( (string) $session['id'] );
		$res  = $this->fulfil_session( $payment, is_wp_error( $full ) ? $session : $full );
		return is_wp_error( $res ) ? 'error:' . $res->get_error_code() : $res['result'];
	}

	private function on_checkout_failed( array $session ): string {
		$payment = $this->payments->find_by_session( (string) ( $session['id'] ?? '' ) );
		if ( $payment && 'pending' === $payment->status ) {
			$this->payments->update( (int) $payment->id, [ 'status' => 'failed' ] );
			$this->payments->append_note( $payment, 'Asynchronous payment failed' );
		}
		return 'failed';
	}

	/** Recurring renewal (billing_reason subscription_cycle). First invoices are handled by checkout completion. */
	private function on_invoice_paid( array $invoice ): string {
		$invoice_id = (string) ( $invoice['id'] ?? '' );
		if ( '' !== $invoice_id && $this->payments->find_by_invoice( $invoice_id ) ) {
			return 'already';
		}
		$sub_id = (string) ( $invoice['subscription'] ?? ( $invoice['parent']['subscription_details']['subscription'] ?? '' ) );
		$reason = (string) ( $invoice['billing_reason'] ?? '' );
		if ( 'subscription_create' === $reason ) {
			// Covered by checkout.session.completed; just attach the invoice id if the payment lacks one.
			$m = '' !== $sub_id ? $this->memberships->find_by_stripe_subscription( $sub_id ) : null;
			if ( $m ) {
				foreach ( $this->payments->for_farm( (int) $m->farm_id, 5 ) as $p ) {
					if ( (string) $p->stripe_subscription_id === $sub_id && '' === (string) $p->stripe_invoice_id ) {
						$this->payments->update( (int) $p->id, [ 'stripe_invoice_id' => $invoice_id ] );
						break;
					}
				}
			}
			return 'first_invoice';
		}
		$membership = '' !== $sub_id ? $this->memberships->find_by_stripe_subscription( $sub_id ) : null;
		if ( ! $membership && ! empty( $invoice['customer'] ) ) {
			$membership = $this->memberships->find_by_stripe_customer( (string) $invoice['customer'] );
		}
		if ( ! $membership ) {
			$this->logger->warning( 'invoice.paid for unknown subscription/customer', [ 'invoice' => $invoice_id, 'subscription' => $sub_id ] );
			return 'unknown_membership';
		}
		$farm = $this->farms->find( (int) $membership->farm_id );
		$tier = $this->engine->tier_for( $membership );
		if ( ! $farm || ! $tier ) {
			return 'missing_farm_or_tier';
		}
		$amount     = (int) ( $invoice['amount_paid'] ?? 0 );
		$was_active = $this->engine->is_active( $membership );
		$now        = Dates::now();
		$charge_id  = (string) ( is_array( $invoice['charge'] ?? null ) ? $invoice['charge']['id'] : ( $invoice['charge'] ?? '' ) );
		$intent_id  = (string) ( is_array( $invoice['payment_intent'] ?? null ) ? $invoice['payment_intent']['id'] : ( $invoice['payment_intent'] ?? '' ) );
		$payment    = $this->payments->create( [
			'membership_id'            => (int) $membership->id,
			'farm_id'                  => (int) $farm->id,
			'user_id'                  => (int) $farm->owner_user_id,
			'tier_id'                  => (int) $tier->id,
			'amount_cents'             => $amount,
			'currency'                 => strtoupper( (string) ( $invoice['currency'] ?? $tier->currency ) ),
			'method'                   => 'stripe',
			'status'                   => 'succeeded',
			'stripe_invoice_id'        => $invoice_id,
			'stripe_subscription_id'   => $sub_id,
			'stripe_charge_id'         => $charge_id,
			'stripe_payment_intent_id' => $intent_id,
			'receipt_url'              => (string) ( $invoice['hosted_invoice_url'] ?? '' ),
			'recurring'                => 1,
			'paid_at'                  => $now,
			'notes'                    => '[' . $now . '] Automatic renewal (invoice ' . $invoice_id . ')',
		] );
		$updated = $this->engine->activate( (int) $farm->id, $tier, [ 'source' => 'stripe', 'auto_renew' => true, 'stripe_subscription_id' => $sub_id, 'price_cents' => $amount, 'actor' => 0, 'paid_at' => $now ] );
		$this->payments->update( (int) $payment->id, [ 'term_start' => $was_active ? Dates::add_days( (string) $updated->ends_at, -(int) $tier->duration_days ) : $updated->starts_at, 'term_end' => $updated->ends_at ] );
		$this->send_success_emails( $farm, $updated, $this->payments->find( (int) $payment->id ), $tier, $was_active, 'Card on file (automatic renewal)' );
		return 'renewed';
	}

	private function on_invoice_failed( array $invoice ): string {
		$sub_id     = (string) ( $invoice['subscription'] ?? ( $invoice['parent']['subscription_details']['subscription'] ?? '' ) );
		$membership = '' !== $sub_id ? $this->memberships->find_by_stripe_subscription( $sub_id ) : ( ! empty( $invoice['customer'] ) ? $this->memberships->find_by_stripe_customer( (string) $invoice['customer'] ) : null );
		if ( ! $membership ) {
			return 'unknown_membership';
		}
		$farm = $this->farms->find( (int) $membership->farm_id );
		if ( ! $farm ) {
			return 'missing_farm';
		}
		$attempt = (int) ( $invoice['attempt_count'] ?? 1 );
		$final   = empty( $invoice['next_payment_attempt'] );
		$this->notifier->farm_membership_contacts( $farm, ( new PaymentFailedEmail() )->with( [
			'farm_name'  => $farm->name,
			'ends_at'    => $membership->ends_at,
			'reason'     => '',
			'manage_url' => $this->urls->dashboard( 'manage-membership' ),
			'is_final'   => $final && ! $this->engine->is_active( $membership ),
		] ) );
		$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
			'type'      => 'payment_failed',
			'farm_name' => $farm->name,
			'summary'   => sprintf( 'Automatic renewal payment failed for %s (attempt %d%s).', $farm->name, $attempt, $final ? ', no further retries' : '' ),
			'rows'      => [ 'Farm' => $farm->name, 'Membership ends' => Dates::display( $membership->ends_at ), 'Invoice' => (string) ( $invoice['id'] ?? '' ), 'Amount due' => Str::money( (int) ( $invoice['amount_due'] ?? 0 ) ) ],
			'cta_label' => 'Open memberships',
			'cta_url'   => admin_url( 'admin.php?page=southpoll-memberships' ),
		] ) );
		$this->logger->warning( "invoice.payment_failed for farm {$farm->id} (attempt {$attempt})" );
		return 'notified';
	}

	private function on_subscription_updated( array $sub ): string {
		$membership = $this->memberships->find_by_stripe_subscription( (string) ( $sub['id'] ?? '' ) );
		if ( ! $membership ) {
			return 'unknown_subscription';
		}
		$status = (string) ( $sub['status'] ?? '' );
		$on     = in_array( $status, [ 'active', 'trialing', 'past_due' ], true ) && empty( $sub['cancel_at_period_end'] );
		if ( (bool) (int) $membership->auto_renew !== $on ) {
			$this->engine->set_auto_renew( $membership, $on );
		}
		return $on ? 'auto_renew_on' : 'auto_renew_off';
	}

	private function on_subscription_deleted( array $sub ): string {
		$membership = $this->memberships->find_by_stripe_subscription( (string) ( $sub['id'] ?? '' ) );
		if ( $membership && (int) $membership->auto_renew === 1 ) {
			$this->engine->set_auto_renew( $membership, false );
		}
		return 'auto_renew_off';
	}

	private function on_charge_refunded( array $charge ): string {
		$payment = $this->payments->find_by_intent( (string) ( $charge['payment_intent'] ?? '' ) );
		if ( ! $payment ) {
			return 'unknown_charge';
		}
		$refunded = (int) ( $charge['amount_refunded'] ?? 0 );
		$this->payments->update( (int) $payment->id, [ 'status' => $refunded >= (int) $payment->amount_cents ? 'refunded' : $payment->status ] );
		$this->payments->append_note( $payment, 'Refund of ' . Str::money( $refunded ) . ' recorded from Stripe' );
		return 'refunded';
	}

	/* ------------------------------------------------------------- auto-renew */

	/**
	 * Turn auto-renew on/off for the member's farm.
	 *
	 * @return string|WP_Error 'checkout:<url>' when a Checkout Session is needed, 'on' | 'off' otherwise
	 */
	public function set_auto_renew( MemberContext $ctx, bool $on ): string|WP_Error {
		if ( ! $ctx->can( 'manage_membership' ) || ! $ctx->membership ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the farm owner can change renewal settings.', 'southpoll-membership' ) );
		}
		$m = $ctx->membership;
		if ( $on ) {
			if ( ! empty( $m->stripe_subscription_id ) ) {
				$sub = $this->stripe->retrieve_subscription( (string) $m->stripe_subscription_id );
				if ( ! is_wp_error( $sub ) && in_array( $sub['status'] ?? '', [ 'active', 'trialing', 'past_due' ], true ) ) {
					$res = $this->stripe->set_cancel_at_period_end( (string) $m->stripe_subscription_id, false );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					$this->engine->set_auto_renew( $m, true );
					return 'on';
				}
			}
			$tier = $this->engine->tier_for( $m );
			$url  = $this->start( $ctx, $tier, true );
			return is_wp_error( $url ) ? $url : 'checkout:' . $url;
		}
		if ( ! empty( $m->stripe_subscription_id ) ) {
			$res = $this->stripe->set_cancel_at_period_end( (string) $m->stripe_subscription_id, true );
			if ( is_wp_error( $res ) && 'resource_missing' !== ( $res->get_error_data()['code'] ?? '' ) ) {
				return $res;
			}
		}
		$this->engine->set_auto_renew( $m, false );
		return 'off';
	}

	public function portal_url( MemberContext $ctx ): string|WP_Error {
		if ( ! $ctx->can( 'manage_membership' ) || empty( $ctx->membership->stripe_customer_id ) ) {
			return new WP_Error( 'sp_no_customer', __( 'There is no card on file yet.', 'southpoll-membership' ) );
		}
		return $this->stripe->portal_url( (string) $ctx->membership->stripe_customer_id, $this->urls->dashboard( 'manage-membership' ) );
	}

	/* ------------------------------------------------------------------ emails */

	public function send_success_emails( object $farm, object $membership, object $payment, object $tier, bool $was_renewal, string $method_label ): void {
		$this->notifier->farm_membership_contacts( $farm, ( new MembershipActivatedEmail() )->with( [
			'farm_name'  => $farm->name,
			'ends_at'    => $membership->ends_at,
			'is_renewal' => $was_renewal,
			'auto_renew' => (int) $membership->auto_renew === 1,
			'cta_url'    => $was_renewal ? $this->urls->dashboard( 'manage-membership' ) : $this->urls->dashboard( 'my-farm' ),
		] ) );
		if ( (int) $payment->amount_cents > 0 ) {
			$this->notifier->farm_membership_contacts( $farm, ( new PaymentReceiptEmail() )->with( [
				'farm_name'    => $farm->name,
				'amount_cents' => (int) $payment->amount_cents,
				'currency'     => (string) $payment->currency,
				'method_label' => $method_label,
				'paid_at'      => $payment->paid_at,
				'term_start'   => $payment->term_start,
				'term_end'     => $payment->term_end,
				'receipt_url'  => (string) $payment->receipt_url,
				'reference'    => (string) ( $payment->stripe_invoice_id ?: $payment->stripe_payment_intent_id ?: ( 'Payment #' . $payment->id ) ),
				'payments_url' => $this->urls->dashboard( 'manage-membership' ),
			] ) );
		}
		$owner = get_userdata( (int) $farm->owner_user_id );
		$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
			'type'      => $was_renewal ? 'renewal' : 'new_membership',
			'farm_name' => $farm->name,
			'summary'   => sprintf( '%s %s the SPGCA membership for %s (%s).', $owner ? $this->notifier->name( $owner ) : 'A member', $was_renewal ? 'renewed' : 'activated', $farm->name, $method_label ),
			'rows'      => [ 'Farm' => $farm->name, 'Owner' => $owner ? $this->notifier->name( $owner ) . ' &lt;' . esc_html( $owner->user_email ) . '&gt;' : '', 'Tier' => $tier->name, 'Amount' => Str::money( (int) $payment->amount_cents, (string) $payment->currency ), 'Paid with' => $method_label, 'Term' => Dates::display( $membership->starts_at ) . ' – ' . Dates::display( $membership->ends_at ), 'Auto-renew' => (int) $membership->auto_renew ? 'On' : 'Off', 'Breeder #' => (string) $farm->breeder_number ],
			'cta_label' => 'Open memberships',
			'cta_url'   => admin_url( 'admin.php?page=southpoll-memberships' ),
		] ) );
	}
}
