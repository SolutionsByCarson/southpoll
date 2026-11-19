<?php
/**
 * Third-party credentials (Stripe, Google, reCAPTCHA, email). Stored in a non-autoloaded option.
 * Values are never logged; use masked() when displaying.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Integrations {

	public const OPTION = 'sp_integrations';

	private ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'stripe'    => [
				'mode'                 => 'test',
				'test_publishable_key' => '',
				'test_secret_key'      => '',
				'live_publishable_key' => '',
				'live_secret_key'      => '',
				'webhook_secret'       => '',
			],
			'google'    => [
				'maps_browser_key' => '',
				'geocoding_key'    => '',
			],
			'recaptcha' => [
				'site_key'   => '',
				'secret_key' => '',
				'threshold'  => 0.5,
			],
			'email'     => [
				'from_name'  => '',
				'from_email' => '',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	public function get( string $key, mixed $default = null ): mixed {
		$value = $this->all();
		foreach ( explode( '.', $key ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	public function set( string $key, mixed $value ): void {
		$all  = $this->all();
		$ref  = &$all;
		$path = explode( '.', $key );
		$last = array_pop( $path );
		foreach ( $path as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = [];
			}
			$ref = &$ref[ $segment ];
		}
		$ref[ $last ] = $value;
		$this->persist( $all );
	}

	/**
	 * Replace the whole store (used by the seeder and the admin form).
	 *
	 * @param array<string, mixed> $values
	 */
	public function replace( array $values ): void {
		$this->persist( array_replace_recursive( self::defaults(), $values ) );
	}

	/** Stripe helpers. */
	public function stripe_mode(): string {
		return 'live' === $this->get( 'stripe.mode' ) ? 'live' : 'test';
	}

	public function stripe_secret_key(): string {
		return (string) $this->get( 'stripe.' . $this->stripe_mode() . '_secret_key', '' );
	}

	public function stripe_publishable_key(): string {
		return (string) $this->get( 'stripe.' . $this->stripe_mode() . '_publishable_key', '' );
	}

	public function stripe_configured(): bool {
		return '' !== $this->stripe_secret_key() && '' !== $this->stripe_publishable_key();
	}

	public function recaptcha_configured(): bool {
		return '' !== (string) $this->get( 'recaptcha.site_key', '' ) && '' !== (string) $this->get( 'recaptcha.secret_key', '' );
	}

	public function maps_configured(): bool {
		return '' !== (string) $this->get( 'google.maps_browser_key', '' );
	}

	/**
	 * Masked representation for display: keeps the first 7 and last 4 characters of long secrets.
	 */
	public static function masked( string $value ): string {
		$len = strlen( $value );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 12 ) {
			return str_repeat( '•', $len );
		}
		return substr( $value, 0, 7 ) . str_repeat( '•', max( 4, $len - 11 ) ) . substr( $value, -4 );
	}

	private function persist( array $all ): void {
		update_option( self::OPTION, $all, false );
		$this->cache = $all;
	}
}
