<?php
/**
 * lib/spam-classify.php — spam-account classifier shared by purge-spam-users.php (and the one-off profiling scripts).
 *
 * Candidates: accounts registered after the 2025-03-11 member import with NO engagement of any kind (no membership row,
 * farm, store, breeder number, listing, order, favorite, saved search, inquiry, or plugin login). Among those, an account is
 * SPAM when its NAME looks machine-generated (random case changes, capitals inside a word, no vowels, 15+ letter tokens,
 * spam words / URLs, non-Latin script, digit runs). A random-looking *login* alone is not enough: real members use farm
 * names and numbers as logins. Accounts with a city / state on file and only one name signal go to a review list instead.
 */
function sp_spam_candidates_rows( $wpdb, string $after = '2025-03-12' ): array {
	$p = $wpdb->prefix;
	return $wpdb->get_results( $wpdb->prepare( "
SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'first_name' LIMIT 1) first_name,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'last_name' LIMIT 1) last_name,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = '_rtcl_phone' LIMIT 1) rtcl_phone,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = '_billing_city' LIMIT 1) billing_city,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = '_rtcl_location' LIMIT 1) rtcl_location,
  (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'southpoll_breeder_number' LIMIT 1) breeder_number,
  EXISTS(SELECT 1 FROM {$wpdb->usermeta} st WHERE st.user_id = u.ID AND st.meta_key = 'session_tokens') has_session
FROM {$wpdb->users} u
WHERE u.user_registered > %s
  AND u.user_email NOT LIKE '%%@example.test'
  AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} c WHERE c.user_id = u.ID AND c.meta_key = '{$p}capabilities' AND (c.meta_value LIKE '%%administrator%%' OR c.meta_value LIKE '%%editor%%'))
  AND NOT EXISTS (SELECT 1 FROM {$p}rtcl_membership rm WHERE rm.user_id = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$p}sp_farm_users fu WHERE fu.user_id = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.meta_key = 'store_owner_id' AND sm.meta_value = u.ID)
  -- a REAL breeder number is 3-6 digits (SPGCA registry); bots fill the field with random letters, so only digits count as engagement
  AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} bm WHERE bm.user_id = u.ID AND bm.meta_key = 'southpoll_breeder_number' AND bm.meta_value REGEXP '^[[:space:]]*[0-9]{3,6}[[:space:]]*$')
  AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} lp WHERE lp.post_author = u.ID AND lp.post_type IN ('rtcl_listing','sp_listing','rtcl_payment'))
  AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} om WHERE om.meta_key = 'customer_id' AND om.meta_value = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$p}sp_favorites fv WHERE fv.user_id = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$p}sp_saved_searches ss WHERE ss.user_id = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$p}sp_inquiries iq WHERE iq.sender_user_id = u.ID)
  AND NOT EXISTS (SELECT 1 FROM {$p}sp_members m WHERE m.user_id = u.ID AND m.last_seen_at IS NOT NULL)
ORDER BY u.ID", $after ) ) ?: [];
}

/** @return array{spam:bool, review:bool, flags:string[]} */
function sp_spam_verdict( object $r ): array {
	$flags = [];
	$name  = trim( html_entity_decode( "{$r->first_name} {$r->last_name}" ) );
	$disp  = html_entity_decode( (string) $r->display_name );
	foreach ( [ $name, is_email( $disp ) ? '' : $disp ] as $s ) {
		if ( '' === $s ) {
			continue;
		}
		if ( preg_match( '~https?://|www\.|\bclaim\b|bitcoin|crypto|casino|\biphone\b|\bprize\b|\bwinner\b|\bbonus\b|\bfree\b~iu', $s ) ) {
			$flags[] = 'spamword';
		}
		if ( preg_match( '/\p{Cyrillic}|\p{Han}|\p{Arabic}|\p{Hangul}|\p{Thai}/u', $s ) ) {
			$flags[] = 'script';
		}
		if ( preg_match( '/\d{3,}/', $s ) ) {
			$flags[] = 'digits';
		}
		foreach ( preg_split( '/[\s\-\'.&;\/,]+/', $s ) as $tok ) {
			if ( '' === $tok || $tok === strtoupper( $tok ) ) {
				continue; // ALL CAPS is a data-entry style, not spam
			}
			$switches = preg_match_all( '/[a-z][A-Z]/', $tok );
			if ( $switches >= 2 || ( 1 === $switches && strlen( $tok ) >= 8 && ! preg_match( '/^(Mc|Mac|De|Di|La|Le|Van|Von|O|D|Fitz|St)[A-Z]/', $tok ) ) ) {
				$flags[] = 'random-case';
			}
			if ( preg_match_all( '/(?<!^)[A-Z]/', $tok ) >= 3 ) {
				$flags[] = 'caps-inside'; // "JGVVUibu", "ESOITWTrhne"
			}
			if ( strlen( $tok ) >= 5 && ! preg_match( '/[aeiouy]/i', $tok ) ) {
				$flags[] = 'novowel';
			}
			if ( strlen( $tok ) >= 15 ) {
				$flags[] = 'long-token';
			}
		}
	}
	$bn = trim( (string) ( $r->breeder_number ?? '' ) );
	if ( '' !== $bn && ! preg_match( '/^\d{1,6}$/', $bn ) && ( preg_match( '/^[A-Za-z]{8,}$/', $bn ) || preg_match( '/\d{8,}/', $bn ) ) ) {
		$flags[] = 'breeder-junk'; // random letters or a phone number where a 3-6 digit registry number belongs
	}
	$flags        = array_values( array_unique( $flags ) );
	$l            = (string) $r->user_login;
	$login_random = ! is_email( $l ) && ( ( ! preg_match( '/^[a-z]+([._\-]?[a-z0-9]+)*$/i', $l ) && ! preg_match( "/^[\\w .\\-']+$/", $l ) ) || preg_match_all( '/[a-z][A-Z]/', $l ) >= 2 );
	$has_place    = '' !== (string) $r->billing_city || '' !== (string) $r->rtcl_location;
	$spam         = count( $flags ) >= 2 || ( 1 === count( $flags ) && 'digits' !== $flags[0] && ( $login_random || ! $has_place ) );
	$review       = ! $spam && ( count( $flags ) >= 1 || ( $login_random && ! $has_place && ! $r->has_session ) );
	if ( $login_random ) {
		$flags[] = 'login-random';
	}
	return [ 'spam' => $spam, 'review' => $review, 'flags' => $flags ];
}

function sp_spam_line( object $r ): string {
	$name = trim( html_entity_decode( "{$r->first_name} {$r->last_name}" ) );
	return sprintf( '#%d | login %s | name %s | %s | reg %s | phone %s | city %s | loc %s | session %s', $r->ID, $r->user_login, $name ?: '(none)', $r->user_email, substr( (string) $r->user_registered, 0, 10 ), (string) $r->rtcl_phone ?: '-', (string) $r->billing_city ?: '-', (string) $r->rtcl_location ? 'set' : '-', $r->has_session ? 'y' : 'n' );
}
