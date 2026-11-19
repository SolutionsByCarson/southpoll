<?php
/**
 * Base class for every email the plugin sends. Subclasses supply key, subject, heading and body;
 * the Template wraps them in the branded HTML layout.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email;

abstract class Message {

	/** @var string[] */
	protected array $to = [];

	/** @var array<string, mixed> */
	protected array $data = [];

	/** Unique key, e.g. "membership.activated" — used for preferences, logging, and filters. */
	abstract public function key(): string;

	abstract public function subject(): string;

	abstract public function heading(): string;

	/** HTML body (paragraphs, lists). Use p() for consistently styled paragraphs. */
	abstract public function body(): string;

	/** Hidden preview text shown by mail clients. */
	public function preheader(): string {
		return '';
	}

	/**
	 * Primary call-to-action button.
	 *
	 * @return array{label:string, url:string}|null
	 */
	public function cta(): ?array {
		return null;
	}

	/** Small note above the footer (e.g. why the recipient got this email). */
	public function footer_note(): string {
		return '';
	}

	/** Whether the recipient may turn this email off in their preferences. Membership/invoice emails: false. */
	public function configurable(): bool {
		return false;
	}

	public function reply_to(): string {
		return '';
	}

	/** @return string[] */
	public function attachments(): array {
		return [];
	}

	/**
	 * @param string|string[] $to
	 */
	public function to( string|array $to ): static {
		$list     = array_filter( array_map( 'trim', (array) $to ) );
		$this->to = array_values( array_unique( array_filter( $list, 'is_email' ) ) );
		return $this;
	}

	/** @return string[] */
	public function recipients(): array {
		return $this->to;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function with( array $data ): static {
		$this->data = array_merge( $this->data, $data );
		return $this;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	/** All template data (used to queue a message and rebuild it later). @return array<string, mixed> */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Messages that may wait in the notification queue for the configured send window (reminders, notices).
	 * Transactional mail (verification, password reset, receipts, invites) stays immediate.
	 */
	public function deferrable(): bool {
		return false;
	}

	/* ------------------------------------------------------------ helpers */

	protected function p( string $html ): string {
		return '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3c3c3c;">' . wp_kses_post( $html ) . '</p>';
	}

	/**
	 * @param array<string, string> $rows label => value
	 */
	protected function table( array $rows ): string {
		$out = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px;">';
		foreach ( $rows as $label => $value ) {
			$out .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #eeeeee;color:#6f6f6f;width:40%;">' . esc_html( (string) $label ) . '</td><td style="padding:8px 10px;border-bottom:1px solid #eeeeee;color:#272727;font-weight:600;">' . wp_kses_post( (string) $value ) . '</td></tr>';
		}
		return $out . '</table>';
	}
}
