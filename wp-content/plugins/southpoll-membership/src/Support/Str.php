<?php
/**
 * String helpers.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Support;

final class Str {

	/** Random URL-safe token (hex). */
	public static function token( int $bytes = 32 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/** One-way hash for storing tokens. */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	public static function money( int $cents, string $currency = 'USD' ): string {
		$amount = $cents / 100;
		$symbol = 'USD' === strtoupper( $currency ) ? '$' : strtoupper( $currency ) . ' ';
		return $symbol . number_format_i18n( $amount, ( $cents % 100 ) ? 2 : 0 );
	}

	public static function phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 10 === strlen( $digits ) ) {
			return sprintf( '%s-%s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) );
		}
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			return sprintf( '%s-%s-%s', substr( $digits, 1, 3 ), substr( $digits, 4, 3 ), substr( $digits, 7 ) );
		}
		return trim( $raw );
	}

	public static function state( string $raw ): string {
		$s = strtoupper( trim( $raw ) );
		return preg_match( '/^[A-Z]{2}$/', $s ) ? $s : '';
	}

	/** @return array<string, string> */
	public static function us_states(): array {
		return [
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
			'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
			'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan',
			'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
			'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
			'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee',
			'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
		];
	}
}
