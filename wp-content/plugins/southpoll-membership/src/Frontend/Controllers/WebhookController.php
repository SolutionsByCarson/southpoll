<?php
/**
 * Stripe webhook receiver: /sp-webhook/stripe/  (POST, signed with the Integrations webhook secret).
 * Deduplicates by event id, dispatches to CheckoutService, always answers JSON.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Payments\CheckoutService;
use SouthPoll\Membership\Payments\StripeClient;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Support\Dates;

final class WebhookController implements Controller {

	public function __construct( private Integrations $integrations, private CheckoutService $checkout, private Logger $logger ) {}

	public function title( RouteMatch $match ): string {
		return '';
	}

	public function render( RouteMatch $match ): void {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->respond( 405, [ 'ok' => false, 'error' => 'POST only' ] );
		}
		$payload = (string) file_get_contents( 'php://input' );
		$sig     = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$secret  = (string) $this->integrations->get( 'stripe.webhook_secret', '' );

		if ( '' === $secret ) {
			$this->logger->error( 'Stripe webhook received but no webhook secret is configured.' );
			$this->respond( 400, [ 'ok' => false, 'error' => 'Webhook secret not configured' ] );
		}
		if ( ! StripeClient::verify_signature( $payload, $sig, $secret ) ) {
			$this->logger->warning( 'Stripe webhook signature verification failed.' );
			$this->respond( 400, [ 'ok' => false, 'error' => 'Invalid signature' ] );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			$this->respond( 400, [ 'ok' => false, 'error' => 'Invalid payload' ] );
		}
		$result = $this->process( $event, $payload );
		$this->respond( 200, [ 'ok' => true, 'id' => $event['id'], 'type' => $event['type'], 'result' => $result ] );
	}

	/**
	 * Record + dispatch (public so tests can call it without HTTP).
	 */
	public function process( array $event, string $payload = '' ): string {
		global $wpdb;
		$table    = Schema::table( 'sp_stripe_events' );
		// Mode mismatch (test event on a live site or vice versa) is checked before the event is recorded, so the same
		// event can still be processed once the keys/mode are corrected.
		$livemode = ! empty( $event['livemode'] );
		if ( $livemode !== ( 'live' === $this->integrations->stripe_mode() ) ) {
			$this->logger->warning( 'Stripe webhook mode mismatch', [ 'event_livemode' => $livemode, 'plugin_mode' => $this->integrations->stripe_mode(), 'type' => $event['type'] ] );
			return 'mode_mismatch';
		}
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (event_id, type, payload_hash, processed_at) VALUES (%s, %s, %s, %s)", (string) $event['id'], (string) $event['type'], hash( 'sha256', $payload ?: wp_json_encode( $event ) ), Dates::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === (int) $inserted ) {
			return 'duplicate';
		}
		try {
			$result = $this->checkout->handle_event( $event );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Webhook handler threw: ' . $e->getMessage(), [ 'type' => $event['type'], 'id' => $event['id'] ] );
			$wpdb->delete( $table, [ 'event_id' => (string) $event['id'] ] ); // allow Stripe's retry to reprocess
			$result = 'error';
		}
		$this->logger->info( "Stripe webhook {$event['type']} → {$result}", [ 'id' => $event['id'] ] );
		return $result;
	}

	private function respond( int $status, array $body ): never {
		status_header( $status );
		echo wp_json_encode( $body );
		exit;
	}
}
