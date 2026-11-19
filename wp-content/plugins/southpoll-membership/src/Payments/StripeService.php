<?php
/**
 * Stripe operations expressed in plugin terms: customers per farm, products/prices per tier,
 * checkout sessions, billing portal, subscriptions.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Payments;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\TierRepository;
use WP_Error;

final class StripeService {

	public function __construct(
		private StripeClient $client,
		private MembershipRepository $memberships,
		private TierRepository $tiers,
		private Logger $logger
	) {}

	public function client(): StripeClient {
		return $this->client;
	}

	/**
	 * Get or create the Stripe customer for a farm (stored on the membership row).
	 *
	 * @return string|WP_Error customer id
	 */
	public function ensure_customer( object $farm, object $membership, string $email, string $name ): string|WP_Error {
		if ( ! empty( $membership->stripe_customer_id ) ) {
			return (string) $membership->stripe_customer_id;
		}
		$res = $this->client->post( 'customers', [
			'email'    => $email,
			'name'     => $farm->name ?: $name,
			'metadata' => [ 'farm_id' => (int) $farm->id, 'site' => home_url( '/' ), 'owner_user_id' => (int) $farm->owner_user_id ],
		], 'sp_customer_farm_' . (int) $farm->id . '_' . wp_hash( (string) $farm->id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->memberships->update( (int) $membership->id, [ 'stripe_customer_id' => (string) $res['id'] ] );
		return (string) $res['id'];
	}

	/**
	 * Ensure the tier has a Stripe product and current one-time + recurring prices.
	 *
	 * @return array{product:string, price:string, recurring:string}|WP_Error
	 */
	public function ensure_tier_prices( object $tier ): array|WP_Error {
		$product = (string) $tier->stripe_product_id;
		if ( '' === $product ) {
			$res = $this->client->post( 'products', [
				'name'        => $tier->name . ' (SPGCA)',
				'description' => (string) ( $tier->description ?: 'South Poll Grass Cattle Association membership' ),
				'metadata'    => [ 'tier_id' => (int) $tier->id, 'site' => home_url( '/' ) ],
			], 'sp_product_tier_' . (int) $tier->id . '_' . wp_hash( (string) $tier->id ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$product = (string) $res['id'];
			$this->tiers->update( (int) $tier->id, [ 'stripe_product_id' => $product ] );
		}

		$one_time  = $this->ensure_price( $tier, $product, (string) $tier->stripe_price_id, false );
		if ( is_wp_error( $one_time ) ) {
			return $one_time;
		}
		$recurring = $this->ensure_price( $tier, $product, (string) $tier->stripe_recurring_price_id, true );
		if ( is_wp_error( $recurring ) ) {
			return $recurring;
		}
		return [ 'product' => $product, 'price' => $one_time, 'recurring' => $recurring ];
	}

	/**
	 * Reuse the stored price when it still matches the tier amount; otherwise create a new one and archive the old.
	 */
	private function ensure_price( object $tier, string $product, string $existing, bool $recurring ): string|WP_Error {
		if ( '' !== $existing ) {
			$current = $this->client->get( 'prices/' . $existing );
			if ( ! is_wp_error( $current ) && (int) ( $current['unit_amount'] ?? -1 ) === (int) $tier->price_cents && strtolower( (string) ( $current['currency'] ?? '' ) ) === strtolower( (string) $tier->currency ) && ! empty( $current['active'] ) ) {
				return $existing;
			}
			if ( ! is_wp_error( $current ) && ! empty( $current['active'] ) ) {
				$this->client->post( 'prices/' . $existing, [ 'active' => 'false' ] );
			}
		}
		$params = [
			'product'     => $product,
			'unit_amount' => (int) $tier->price_cents,
			'currency'    => strtolower( (string) $tier->currency ),
			'metadata'    => [ 'tier_id' => (int) $tier->id, 'kind' => $recurring ? 'recurring' : 'one_time' ],
		];
		if ( $recurring ) {
			$params['recurring'] = [ 'interval' => (string) $tier->billing_interval, 'interval_count' => (int) $tier->interval_count ];
		}
		$res = $this->client->post( 'prices', $params, 'sp_price_' . ( $recurring ? 'rec' : 'one' ) . '_' . (int) $tier->id . '_' . (int) $tier->price_cents . '_' . wp_hash( (string) $tier->updated_at ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->tiers->update( (int) $tier->id, [ $recurring ? 'stripe_recurring_price_id' : 'stripe_price_id' => (string) $res['id'] ] );
		return (string) $res['id'];
	}

	/** @return array<string, mixed>|WP_Error */
	public function create_checkout_session( array $params, string $idempotency_key ): array|WP_Error {
		return $this->client->post( 'checkout/sessions', $params, $idempotency_key );
	}

	/** @return array<string, mixed>|WP_Error */
	public function retrieve_checkout_session( string $id ): array|WP_Error {
		return $this->client->get( 'checkout/sessions/' . $id, [ 'expand' => [ 'payment_intent.latest_charge', 'subscription', 'subscription.latest_invoice' ] ] );
	}

	/** @return array<string, mixed>|WP_Error */
	public function retrieve_subscription( string $id ): array|WP_Error {
		return $this->client->get( 'subscriptions/' . $id, [ 'expand' => [ 'default_payment_method' ] ] );
	}

	/** @return array<string, mixed>|WP_Error */
	public function set_cancel_at_period_end( string $subscription_id, bool $cancel ): array|WP_Error {
		return $this->client->post( 'subscriptions/' . $subscription_id, [ 'cancel_at_period_end' => $cancel ? 'true' : 'false' ] );
	}

	/** @return array<string, mixed>|WP_Error */
	public function cancel_subscription_now( string $subscription_id ): array|WP_Error {
		return $this->client->delete( 'subscriptions/' . $subscription_id );
	}

	/** @return string|WP_Error portal URL */
	public function portal_url( string $customer_id, string $return_url ): string|WP_Error {
		$res = $this->client->post( 'billing_portal/sessions', [ 'customer' => $customer_id, 'return_url' => $return_url ] );
		return is_wp_error( $res ) ? $res : (string) $res['url'];
	}

	/** @return array<string, mixed>|WP_Error */
	public function retrieve_invoice( string $id ): array|WP_Error {
		return $this->client->get( 'invoices/' . $id, [ 'expand' => [ 'charge', 'payment_intent' ] ] );
	}

	/**
	 * Human label for a subscription's default card, e.g. "Visa •••• 4242".
	 */
	public function card_label( array $subscription ): string {
		$pm = $subscription['default_payment_method'] ?? null;
		if ( is_array( $pm ) && isset( $pm['card'] ) ) {
			return ucfirst( (string) $pm['card']['brand'] ) . ' •••• ' . (string) $pm['card']['last4'];
		}
		return '';
	}
}
