<?php
/**
 * sp-integrations.php — Read/write plugin integration credentials on the LOCAL site (until the Phase 4 admin UI exists).
 *
 * Run:  ./wp-run.sh sp-integrations.php list                       → keys with masked values
 *       ./wp-run.sh sp-integrations.php set stripe.mode test
 *       ./wp-run.sh sp-integrations.php set stripe.test_secret_key sk_test_...
 *       ./wp-run.sh sp-integrations.php set stripe.webhook_secret whsec_...
 *       ./wp-run.sh sp-integrations.php set google.maps_browser_key AIza...
 *       ./wp-run.sh sp-integrations.php setting recaptcha_enabled 0  → plugin *settings* (non-secret), e.g. turn reCAPTCHA off locally
 *
 * Values typed on the command line may land in shell history; clear it afterwards if that matters.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;

$i   = sp_membership()->get( Integrations::class );
$s   = sp_membership()->get( Settings::class );
$cmd = $argv[2] ?? 'list';

switch ( $cmd ) {
	case 'set':
		[ $key, $value ] = [ (string) ( $argv[3] ?? '' ), (string) ( $argv[4] ?? '' ) ];
		if ( '' === $key ) { die( "Usage: set <dot.key> <value>\n" ); }
		$i->set( $key, is_numeric( $value ) && 'threshold' === substr( $key, -9 ) ? (float) $value : $value );
		echo "set {$key} = ", Integrations::masked( $value ), "\n";
		break;
	case 'setting':
		[ $key, $value ] = [ (string) ( $argv[3] ?? '' ), (string) ( $argv[4] ?? '' ) ];
		if ( '' === $key ) { die( "Usage: setting <dot.key> <value>\n" ); }
		$cast = match ( true ) { in_array( strtolower( $value ), [ 'true', 'yes', 'on' ], true ) => true, in_array( strtolower( $value ), [ 'false', 'no', 'off' ], true ) => false, is_numeric( $value ) => $value + 0, default => $value };
		$s->set( $key, $cast );
		echo "setting {$key} = ", var_export( $cast, true ), "\n";
		break;
	case 'list':
	default:
		$walk = function ( array $arr, string $prefix = '' ) use ( &$walk ) {
			foreach ( $arr as $k => $v ) {
				if ( is_array( $v ) ) { $walk( $v, $prefix . $k . '.' ); continue; }
				$masked = preg_match( '/key|secret|token/i', $k ) ? Integrations::masked( (string) $v ) : (string) $v;
				printf( "%-34s %s\n", $prefix . $k, '' === $masked ? '(empty)' : $masked );
			}
		};
		$walk( $i->all() );
		echo "\nstripe configured: ", $i->stripe_configured() ? 'yes' : 'no', " (mode ", $i->stripe_mode(), ") | recaptcha: ", $i->recaptcha_configured() ? 'yes' : 'no', " | recaptcha_enabled setting: ", var_export( $s->get( 'recaptcha_enabled' ), true ), "\n";
}
