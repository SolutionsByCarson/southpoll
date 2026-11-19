<?php
/**
 * Google reCAPTCHA v3 verification. Skips (and logs once) when keys are not configured so Local/dev keeps working.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Auth;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;

final class Recaptcha {

	public const FIELD = 'g-recaptcha-response';

	public function __construct( private Integrations $integrations, private Logger $logger, private Settings $settings ) {}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'recaptcha_enabled', true ) && $this->integrations->recaptcha_configured();
	}

	public function site_key(): string {
		return (string) $this->integrations->get( 'recaptcha.site_key', '' );
	}

	/**
	 * Verify the token posted with a form. Returns the score (1.0 when disabled) or null on failure.
	 */
	public function verify( string $token, string $expected_action = '' ): ?float {
		if ( ! $this->enabled() ) {
			return 1.0;
		}
		if ( '' === $token ) {
			return null;
		}
		$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => (string) $this->integrations->get( 'recaptcha.secret_key', '' ),
				'response' => $token,
				'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			],
		] );
		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'reCAPTCHA verify request failed: ' . $response->get_error_message() );
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) {
			$this->logger->info( 'reCAPTCHA rejected token', [ 'errors' => $data['error-codes'] ?? [] ] );
			return null;
		}
		if ( '' !== $expected_action && isset( $data['action'] ) && $data['action'] !== $expected_action ) {
			return null;
		}
		$score = (float) ( $data['score'] ?? 0 );
		return $score >= (float) $this->integrations->get( 'recaptcha.threshold', 0.5 ) ? $score : null;
	}

	/** Verify from the current POST. */
	public function verify_post( string $expected_action = '' ): ?float {
		$token = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $this->verify( $token, $expected_action );
	}

	/**
	 * Script tag + hidden field helper for templates. The form must have the given id and the action name.
	 */
	public function form_assets( string $action ): string {
		if ( ! $this->enabled() ) {
			return '';
		}
		$key = esc_attr( $this->site_key() );
		return '<input type="hidden" name="' . self::FIELD . '" value="" data-sp-recaptcha="' . esc_attr( $action ) . '">';
	}

	/** Enqueue Google's script once on pages that render a reCAPTCHA form. */
	public function enqueue(): void {
		if ( ! $this->enabled() || wp_script_is( 'sp-recaptcha', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( 'sp-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->site_key() ), [], null, [ 'strategy' => 'async', 'in_footer' => true ] ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$key = wp_json_encode( $this->site_key() );
		wp_add_inline_script( 'sp-recaptcha', "document.addEventListener('submit',function(e){var f=e.target,h=f.querySelector&&f.querySelector('[data-sp-recaptcha]');if(!h||h.value||!window.grecaptcha)return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute({$key},{action:h.getAttribute('data-sp-recaptcha')}).then(function(t){h.value=t;if(f.requestSubmit)f.requestSubmit();else f.submit();});});},true);", 'after' );
	}
}
