<?php
/**
 * Date helpers. All plugin timestamps are stored as UTC "Y-m-d H:i:s" strings.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Dates {

	public const MYSQL = 'Y-m-d H:i:s';

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function utc( string $mysql ): DateTimeImmutable {
		return new DateTimeImmutable( $mysql, new DateTimeZone( 'UTC' ) );
	}

	public static function add_days( string $mysql, int $days ): string {
		return self::utc( $mysql )->modify( sprintf( '%+d days', $days ) )->format( self::MYSQL );
	}

	/** Whole days from now until $mysql (negative when in the past). */
	public static function days_until( string $mysql, ?string $from = null ): int {
		$to   = self::utc( $mysql )->getTimestamp();
		$base = self::utc( $from ?? self::now() )->getTimestamp();
		return (int) ceil( ( $to - $base ) / DAY_IN_SECONDS );
	}

	public static function is_past( ?string $mysql, ?string $now = null ): bool {
		return null !== $mysql && '' !== $mysql && $mysql <= ( $now ?? self::now() );
	}

	/** Site-local display, e.g. "October 18, 2026". */
	public static function display( ?string $mysql, string $format = '' ): string {
		if ( null === $mysql || '' === $mysql || str_starts_with( $mysql, '0000' ) ) {
			return '';
		}
		return wp_date( $format ?: (string) get_option( 'date_format', 'F j, Y' ), self::utc( $mysql )->getTimestamp() );
	}

	public static function display_datetime( ?string $mysql ): string {
		return self::display( $mysql, (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
	}
}
