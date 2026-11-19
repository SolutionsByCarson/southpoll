<?php
/**
 * File logger: uploads/sp-logs/sp-YYYY-MM-DD.log, protected from web access.
 * Debug-level lines are written only when the "debug" setting is on; errors always.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Logger {

	public const DIR = 'sp-logs';

	private const LEVELS = [ 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 ];

	public function __construct( private Settings $settings ) {}

	/**
	 * Log directory: uploads/<DIR>-<random token>. The token (stored once in an option) keeps the path unguessable on
	 * hosts whose static file serving ignores .htaccess (nginx on WP Engine); the log itself never holds secrets, but it
	 * does hold recipient addresses and farm addresses.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR . '-' . self::token();
	}

	private static function token(): string {
		$token = (string) get_option( 'sp_log_dir_token', '' );
		if ( ! preg_match( '/^[a-z0-9]{24}$/', $token ) ) {
			$token = strtolower( wp_generate_password( 24, false, false ) );
			update_option( 'sp_log_dir_token', $token, false );
		}
		return $token;
	}

	/**
	 * Create the log directory with .htaccess + index.php guards. Safe to call repeatedly.
	 */
	public static function ensure_log_dir(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	public function log( string $level, string $message, array $context = [] ): void {
		$level = isset( self::LEVELS[ $level ] ) ? $level : 'info';
		if ( 'debug' === $level && ! $this->settings->get( 'debug', false ) ) {
			return;
		}
		$line = sprintf(
			"[%s] %s: %s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? ' ' . wp_json_encode( $this->redact( $context ) ) : ''
		);
		$dir  = self::ensure_log_dir();
		$file = $dir . '/sp-' . gmdate( 'Y-m-d' ) . '.log';
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

		if ( 'error' === $level && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SouthPoll Membership: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		/**
		 * @param string $level
		 * @param string $message
		 * @param array  $context
		 */
		do_action( 'sp/log', $level, $message, $context );
	}

	/**
	 * Strip anything that looks like a secret before it reaches the log file.
	 */
	private function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = $this->redact( $value );
			} elseif ( is_string( $key ) && preg_match( '/key|secret|token|password|authorization/i', $key ) ) {
				$context[ $key ] = '[redacted]';
			}
		}
		return $context;
	}

	/**
	 * Most recent log lines for the admin status page.
	 *
	 * @return string[]
	 */
	public static function tail( int $lines = 50 ): array {
		$files = glob( self::dir() . '/sp-*.log' ) ?: [];
		if ( empty( $files ) ) {
			return [];
		}
		rsort( $files );
		$content = (array) file( $files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return array_slice( $content, -$lines );
	}
}
