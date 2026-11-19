<?php
/**
 * Local-only outgoing-mail guard (never pushed: listed in .wpe-push-ignore, and inert unless the site URL ends in .local).
 * Blocks every wp_mail() whose recipients are real people; allows @example.test test accounts and the developer address,
 * so the dev copy of the production database cannot send reminders, digests or receipts to members.
 */
add_filter( 'pre_wp_mail', function ( $short, array $atts ) {
	if ( ! str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '.local' ) ) {
		return $short;
	}
	$allowed = [ 'solutionsbycarson.com', 'example.test' ];
	foreach ( (array) ( $atts['to'] ?? [] ) as $to ) {
		foreach ( preg_split( '/[,;]\s*/', (string) $to ) as $addr ) {
			$domain = strtolower( substr( strrchr( trim( $addr, " <>\"'" ), '@' ) ?: '', 1 ) );
			if ( ! in_array( $domain, $allowed, true ) ) {
				error_log( 'local-mail-guard: blocked mail to ' . $addr . ' (' . ( $atts['subject'] ?? '' ) . ')' );
				return false;
			}
		}
	}
	return $short;
}, 1, 2 );
