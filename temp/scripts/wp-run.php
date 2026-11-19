<?php
/**
 * wp-run.php — Bootstrap WordPress from the CLI and run a PHP script against it.
 *
 * Usage (via wp-run.sh):   ./wp-run.sh <script.php> [args...]
 * Direct:                  php -c <php.ini> wp-run.php <script.php> [args...]
 *
 * The script receives $argv (index 1 = script path, 2+ = args) and a fully
 * loaded WordPress ($wpdb, rtcl(), rtclStore(), get_option, etc.).
 */
if ( PHP_SAPI !== 'cli' ) { die( "CLI only\n" ); }
if ( empty( $argv[1] ) || ! file_exists( $argv[1] ) ) { fwrite( STDERR, "Usage: wp-run.php <script.php> [args]\n" ); exit( 1 ); }

$public = realpath( __DIR__ . '/../..' );
$_SERVER['HTTP_HOST']       = 'south-poll-grass-cattle-association.local';
$_SERVER['SERVER_NAME']     = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['HTTPS']           = 'off';

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING );
ini_set( 'display_errors', '1' );
ini_set( 'memory_limit', '1024M' );
set_time_limit( 0 );

define( 'WP_USE_THEMES', false );
define( 'SP_WP_RUN', true );

require $public . '/wp-load.php';

// WordPress disables display_errors when WP_DEBUG is off; re-enable for CLI runs and report fatals.
ini_set( 'display_errors', '1' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING );
register_shutdown_function( static function (): void {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
		fwrite( STDERR, "\n[FATAL] {$e['message']} in {$e['file']}:{$e['line']}\n" );
	}
} );
// Something in the loaded stack swallows uncaught throwables and exits 0; report them loudly and fail the run instead.
set_exception_handler( static function ( \Throwable $t ): void {
	fwrite( STDERR, "\n[UNCAUGHT] " . get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine() . "\n" . $t->getTraceAsString() . "\n" );
	exit( 255 );
} );

$script = $argv[1];
require $script;
