<?php
/**
 * Discreet honeypot + minimum-fill-time check for public forms.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Auth;

final class Honeypot {

	public const FIELD = 'sp_website_url';
	public const TIME  = 'sp_form_ts';

	public const MIN_SECONDS = 2;

	/** Hidden fields to print inside a form. */
	public static function fields(): string {
		$ts = wp_hash( (string) time() ) . '.' . time();
		return '<div class="sp-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
			. '<label>Website <input type="text" name="' . self::FIELD . '" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<input type="hidden" name="' . self::TIME . '" value="' . esc_attr( $ts ) . '">';
	}

	/** True when the submission looks human. */
	public static function passes(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST[ self::FIELD ] ) ) {
			return false;
		}
		$raw = isset( $_POST[ self::TIME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TIME ] ) ) : '';
		// phpcs:enable
		if ( '' === $raw || ! str_contains( $raw, '.' ) ) {
			return false;
		}
		[ $hash, $time ] = explode( '.', $raw, 2 );
		if ( ! ctype_digit( $time ) || ! hash_equals( wp_hash( $time ), $hash ) ) {
			return false;
		}
		$age = time() - (int) $time;
		return $age >= self::MIN_SECONDS && $age <= DAY_IN_SECONDS;
	}
}
