<?php
/**
 * Minimal Stripe REST client over wp_remote_request (no SDK). Form-encoded requests, JSON responses,
 * idempotency keys on writes, webhook signature verification. A transport callable can be injected for tests.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Payments;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use WP_Error;

final class StripeClient {

	public const API_BASE    = 'https://api.stripe.com/v1/';
	public const API_VERSION = '2024-06-20';

	/** @var callable|null fn(string $method, string $url, array $headers, string $body): array{status:int, body:string}|WP_Error */
	private $transport = null;

	public function __construct( private Integrations $integrations, private Logger $logger ) {}

	public function set_transport( ?callable $transport ): void {
		$this->transport = $transport;
	}

	public function configured(): bool {
		return '' !== $this->integrations->stripe_secret_key();
	}

	public function mode(): string {
		return $this->integrations->stripe_mode();
	}

	/** @return array<string, mixed>|WP_Error */
	public function get( string $path, array $params = [] ): array|WP_Error {
		return $this->request( 'GET', $path, $params );
	}

	/** @return array<string, mixed>|WP_Error */
	public function post( string $path, array $params = [], ?string $idempotency_key = null ): array|WP_Error {
		return $this->request( 'POST', $path, $params, $idempotency_key );
	}

	/** @return array<string, mixed>|WP_Error */
	public function delete( string $path, array $params = [] ): array|WP_Error {
		return $this->request( 'DELETE', $path, $params );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, array $params, ?string $idempotency_key = null ): array|WP_Error {
		$key = $this->integrations->stripe_secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'sp_stripe_not_configured', __( 'Online payments are not configured yet. Please contact the office.', 'southpoll-membership' ) );
		}
		$url     = self::API_BASE . ltrim( $path, '/' );
		$body    = '';
		$headers = [
			'Authorization'  => 'Bearer ' . $key,
			'Stripe-Version' => self::API_VERSION,
			'Accept'         => 'application/json',
			'User-Agent'     => 'SouthPollMembership/' . SP_VERSION . ' (WordPress)',
		];
		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( ! empty( $params ) ) {
				$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $params, '', '&' );
			}
		} else {
			$body                    = http_build_query( $params, '', '&' );
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			if ( null !== $idempotency_key ) {
				$headers['Idempotency-Key'] = substr( $idempotency_key, 0, 255 );
			}
		}

		if ( null !== $this->transport ) {
			$raw = ( $this->transport )( $method, $url, $headers, $body );
		} else {
			$response = wp_remote_request( $url, [ 'method' => $method, 'headers' => $headers, 'body' => $body ?: null, 'timeout' => 30 ] );
			if ( is_wp_error( $response ) ) {
				$this->logger->error( 'Stripe request failed: ' . $response->get_error_message(), [ 'path' => $path ] );
				return new WP_Error( 'sp_stripe_unreachable', __( 'We could not reach the payment provider. Please try again in a moment.', 'southpoll-membership' ) );
			}
			$raw = [ 'status' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
		}
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$data = json_decode( (string) ( $raw['body'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			$this->logger->error( 'Stripe returned non-JSON', [ 'path' => $path, 'status' => $raw['status'] ?? 0 ] );
			return new WP_Error( 'sp_stripe_bad_response', __( 'Unexpected response from the payment provider.', 'southpoll-membership' ) );
		}
		if ( (int) ( $raw['status'] ?? 0 ) >= 400 || isset( $data['error'] ) ) {
			$err = (array) ( $data['error'] ?? [] );
			$this->logger->error( 'Stripe API error', [ 'path' => $path, 'status' => $raw['status'] ?? 0, 'type' => $err['type'] ?? '', 'code' => $err['code'] ?? '', 'message' => $err['message'] ?? '' ] );
			return new WP_Error( 'sp_stripe_error', (string) ( $err['message'] ?? __( 'The payment provider rejected the request.', 'southpoll-membership' ) ), [ 'type' => $err['type'] ?? '', 'code' => $err['code'] ?? '', 'status' => $raw['status'] ?? 0 ] );
		}
		return $data;
	}

	/**
	 * Verify a Stripe-Signature header against the raw payload.
	 */
	public static function verify_signature( string $payload, string $sig_header, string $secret, int $tolerance = 300 ): bool {
		if ( '' === $secret || '' === $sig_header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = [];
		foreach ( explode( ',', $sig_header ) as $part ) {
			[ $k, $v ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( 't' === $k ) {
				$timestamp = (int) $v;
			} elseif ( 'v1' === $k ) {
				$signatures[] = $v;
			}
		}
		if ( $timestamp <= 0 || empty( $signatures ) || abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/** Build a signature header (used by tests and the Stripe CLI emulation). */
	public static function sign( string $payload, string $secret, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}
}
