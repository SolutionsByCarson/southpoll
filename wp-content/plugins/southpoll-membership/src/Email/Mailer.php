<?php
/**
 * Sends Messages through wp_mail with the branded template and the optional From override.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;

final class Mailer implements Service {

	private bool $sending = false;

	public function __construct(
		private Settings $settings,
		private Integrations $integrations,
		private Template $template,
		private Logger $logger
	) {}

	public function register(): void {
		add_filter( 'wp_mail_from', [ $this, 'from_email' ], 20 );
		add_filter( 'wp_mail_from_name', [ $this, 'from_name' ], 20 );
		add_action( 'wp_mail_failed', [ $this, 'on_failed' ] );
	}

	/**
	 * Send a message. Returns false when there is no recipient or wp_mail reports failure.
	 */
	public function send( Message $message ): bool {
		/**
		 * Short-circuit or alter a message before sending. Return false to suppress.
		 *
		 * @param Message|false $message
		 */
		$message = apply_filters( 'sp/email/before_send', $message );
		if ( ! $message instanceof Message ) {
			return false;
		}
		$to = $message->recipients();
		if ( empty( $to ) ) {
			$this->logger->warning( 'Email skipped: no recipient', [ 'key' => $message->key() ] );
			return false;
		}

		$subject = $message->subject();
		$html    = $this->template->render( $message );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( '' !== $message->reply_to() && is_email( $message->reply_to() ) ) {
			$headers[] = 'Reply-To: ' . $message->reply_to();
		}

		$this->sending = true;
		try {
			$sent = wp_mail( $to, $subject, $html, $headers, $message->attachments() );
		} finally {
			$this->sending = false;
		}

		$mask = static fn( string $e ): string => preg_replace_callback( '/^(.)[^@]*(@.*)$/', static fn( $m ) => $m[1] . '***' . $m[2], $e ) ?? $e;
		$this->logger->log( $sent ? 'info' : 'error', ( $sent ? 'Email sent' : 'Email failed' ) . ': ' . $message->key(), [ 'to' => array_map( $mask, (array) $to ), 'subject' => $subject ] );

		/**
		 * @param Message $message
		 * @param bool    $sent
		 */
		do_action( 'sp/email/sent', $message, $sent );

		return $sent;
	}

	/**
	 * Apply the From override only while this plugin is sending, and only when enabled in settings.
	 */
	public function from_email( string $email ): string {
		if ( ! $this->sending || ! $this->settings->get( 'email_from_override', false ) ) {
			return $email;
		}
		$override = (string) $this->integrations->get( 'email.from_email', '' );
		return is_email( $override ) ? $override : $email;
	}

	public function from_name( string $name ): string {
		if ( ! $this->sending ) {
			return $name;
		}
		$override = (string) $this->integrations->get( 'email.from_name', '' );
		return '' !== $override ? $override : ( $name ?: get_bloginfo( 'name' ) );
	}

	public function on_failed( \WP_Error $error ): void {
		if ( $this->sending ) {
			$this->logger->error( 'wp_mail failed: ' . $error->get_error_message() );
		}
	}
}
