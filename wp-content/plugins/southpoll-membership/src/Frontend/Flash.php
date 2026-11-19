<?php
/**
 * One-shot messages across a redirect, shown as slide-out toasts on the next page.
 * Stored in a short-lived HttpOnly cookie so it works for guests too.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend;

final class Flash {

	public const COOKIE = 'sp_flash';

	/** @var array<int, array<string, mixed>> */
	private array $pending = [];

	private bool $pulled = false;

	/** @var array<int, array<string, mixed>> */
	private array $pulled_items = [];

	/**
	 * Queue a message. $actions = [ [ 'label' => 'View favorites', 'href' => '…' ], … ].
	 *
	 * @param array<int, array{label:string, href:string}> $actions
	 */
	public function add( string $type, string $message, string $title = '', array $actions = [] ): void {
		$this->pending[] = [
			'type'    => in_array( $type, [ 'success', 'info', 'warning', 'error' ], true ) ? $type : 'info',
			'title'   => $title,
			'message' => $message,
			'actions' => array_values( array_filter( $actions, static fn( $a ) => isset( $a['label'], $a['href'] ) ) ),
		];
		$this->write();
	}

	public function success( string $message, string $title = '', array $actions = [] ): void {
		$this->add( 'success', $message, $title, $actions );
	}

	public function info( string $message, string $title = '', array $actions = [] ): void {
		$this->add( 'info', $message, $title, $actions );
	}

	public function error( string $message, string $title = '', array $actions = [] ): void {
		$this->add( 'error', $message, $title, $actions );
	}

	/**
	 * Read and clear queued messages (from a previous request).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pull(): array {
		if ( $this->pulled ) {
			return $this->pulled_items;
		}
		$this->pulled = true;
		$raw          = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$items        = [];
		if ( '' !== $raw ) {
			$decoded = json_decode( (string) base64_decode( $raw, true ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( is_array( $item ) && isset( $item['message'] ) ) {
						$items[] = [
							'type'    => sanitize_key( (string) ( $item['type'] ?? 'info' ) ),
							'title'   => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
							'message' => sanitize_text_field( (string) $item['message'] ),
							'actions' => array_values( array_map(
								static fn( $a ) => [ 'label' => sanitize_text_field( (string) ( $a['label'] ?? '' ) ), 'href' => esc_url_raw( (string) ( $a['href'] ?? '' ) ) ],
								array_filter( (array) ( $item['actions'] ?? [] ), 'is_array' )
							) ),
						];
					}
				}
			}
			$this->clear_cookie();
		}
		$this->pulled_items = $items;
		return $items;
	}

	private function write(): void {
		if ( headers_sent() ) {
			return;
		}
		$value = base64_encode( (string) wp_json_encode( $this->pending ) );
		setcookie( self::COOKIE, $value, [
			'expires'  => time() + 5 * MINUTE_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	private function clear_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, '', [
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}
}
