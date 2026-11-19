<?php
/**
 * flush-rewrites.php — Re-register the plugin's rewrite rules and flush WordPress's rule cache (needed after a new route
 * is added; migrations now request this automatically, this is the manual fallback).
 *
 * Run: ./wp-run.sh flush-rewrites.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

$router = sp_membership()->get( \SouthPoll\Membership\Routing\Router::class );
$router->add_rewrite_rules();
flush_rewrite_rules( false );
update_option( 'sp_flush_rewrite', 'no', false );
$rules = get_option( 'rewrite_rules' );
$ours  = is_array( $rules ) ? count( array_filter( array_keys( $rules ), static fn( $k ) => str_contains( (string) ( $rules[ $k ] ?? '' ), 'sp_route=' ) ) ) : 0;
echo "Rewrite rules flushed. Plugin rules now registered: {$ours}\n";
