<?php
/**
 * migrate-users.php — T5.1: every WordPress user → sp_members profile; subscribers → sp_buyer; rtcl_manager → editor (staff).
 *
 *   ./wp-run.sh migrate-users.php --dry-run        report only
 *   ./wp-run.sh migrate-users.php [--limit=N]      write profiles + roles (re-runnable: existing profiles are updated, not duplicated)
 *
 * Rules (recorded in 07-open-questions.md as the migration assumptions):
 *   - Name: WP first/last → _billing_first/last → split display name.  Phone: _rtcl_phone → _billing_phone.
 *   - Address: _billing_address_1/_rtcl_address, _billing_city, _billing_postcode/_rtcl_zipcode.
 *   - State: _rtcl_location term → _billing_state, except a bare "CA" billing state with no other evidence is treated as the
 *     form default and left blank (flagged). migrate-farms.php later overrides member state with the parsed farm address.
 *   - Verified: users with an RTCL membership row or who have ever logged in (session_tokens); everyone else must verify.
 *   - Staff (administrator / editor) get no member profile and keep their role.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }
require_once __DIR__ . '/lib/migrate-lib.php';

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

global $wpdb;
$p       = $wpdb->prefix;
$o       = sp_mig_opts();
$r       = new SpMigReport( 'migrate-users', $o['dry'] );
$members = sp_membership()->get( MemberRepository::class );
$member_uids = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$p}rtcl_membership" ) ) );
$logged_in   = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'" ) ) );
$seen_email  = [];
$pending     = [ 'members' => [], 'users' => [] ];
sp_mig_autoflush( $pending );
$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$r->line( "users: $total, membership rows: " . count( $member_uids ) . ', ever logged in: ' . count( $logged_in ) );
$r->h( 'Flags' );
$last = 0; $done = 0;
while ( true ) {
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT 500", $last ) ) );
	if ( ! $ids ) { break; }
	foreach ( $ids as $uid ) {
		$last = $uid;
		if ( $o['limit'] && $done >= $o['limit'] ) { break 2; }
		$u = get_userdata( $uid );
		if ( ! $u ) { continue; }
		$done++;
		$email = strtolower( (string) $u->user_email );
		if ( ! is_email( $email ) ) { $r->flag( 'bad-email', "#{$uid} {$u->user_login}: '{$u->user_email}'" ); }
		if ( isset( $seen_email[ $email ] ) ) { $r->flag( 'duplicate-email', "#{$uid} shares {$email} with #{$seen_email[ $email ]}" ); } else { $seen_email[ $email ] = $uid; }
		if ( sp_mig_is_staff_user( $u ) ) { $r->count( 'staff-skipped' ); continue; }
		$name  = sp_mig_user_name( $u );
		$meta  = fn( string $k ) => trim( (string) get_user_meta( $uid, $k, true ) );
		$state = sp_mig_location_state( get_user_meta( $uid, '_rtcl_location', true ) );
		$bill  = sp_mig_state_code( $meta( '_billing_state' ) );
		if ( '' === $state ) {
			if ( 'CA' === $bill && '' === $meta( '_billing_city' ) ) { $r->count( 'state-ca-default-ignored' ); } else { $state = $bill; }
		}
		$data = [
			'first_name' => sp_mig_fit( $name['first'], 100 ),
			'last_name'  => sp_mig_fit( $name['last'], 100 ),
			'phone'      => sp_mig_fit( Str::phone( $meta( '_rtcl_phone' ) ?: $meta( '_billing_phone' ) ), 40 ),
			'address1'   => sp_mig_fit( $meta( '_billing_address_1' ) ?: $meta( '_rtcl_address' ), 190 ),
			'city'       => sp_mig_fit( $meta( '_billing_city' ), 100 ),
			'state'      => $state,
			'zip'        => sp_mig_fit( $meta( '_billing_postcode' ) ?: $meta( '_rtcl_zipcode' ), 20 ),
		];
		$verified = isset( $member_uids[ $uid ] ) || isset( $logged_in[ $uid ] );
		if ( '' === $name['first'] && '' === $name['last'] ) { $r->flag( 'no-name', "#{$uid} {$u->user_login} ({$email})" ); }
		if ( preg_match( '~https?://|www\.|\bclaim\b.*\bfree\b~iu', $name['first'] . ' ' . $name['last'] ) ) { $r->flag( 'spam-name', "#{$uid} {$u->user_login} ({$email}): " . mb_substr( $name['display'], 0, 60 ) . '…' ); }
		$r->count( $verified ? 'verified' : 'unverified' );
		$r->count( isset( $member_uids[ $uid ] ) ? 'with-membership' : ( '' !== $meta( 'southpoll_breeder_number' ) ? 'registry-contact' : 'other-account' ) );
		if ( $o['dry'] ) { continue; }
		$existing = $members->find_by_user( $uid );
		if ( $existing ) {
			// Fill blanks only: an existing profile (staff / accounts created in the new plugin) keeps what it has.
			$fill = array_filter( $data, fn( $v, $k ) => '' !== $v && '' === (string) ( $existing->$k ?? '' ), ARRAY_FILTER_USE_BOTH );
			$members->update( (int) $existing->id, $fill + [ 'email_verified_at' => $verified ? ( $existing->email_verified_at ?: Dates::now() ) : $existing->email_verified_at ] );
			$r->count( 'profile-updated' );
		} else {
			try { $m = $members->create_for_user( $uid, $data + [ 'verified' => $verified ] ); } catch ( Throwable $e ) { $m = null; } // typed return throws when the insert fails
			if ( ! $m ) { $r->flag( 'profile-failed', "#{$uid} {$u->user_login}: " . ( $wpdb->last_error ?: 'insert returned nothing' ) ); continue; }
			$pending['members'][] = (int) $m->id;
			$r->count( 'profile-created' );
		}
		if ( in_array( 'subscriber', (array) $u->roles, true ) && ! in_array( Capabilities::ROLE_BUYER, (array) $u->roles, true ) ) {
			$u->set_role( Capabilities::ROLE_BUYER );
			update_user_meta( $uid, 'sp_migrated_role', 'subscriber' );
			$r->count( 'role-subscriber-to-buyer' );
		} elseif ( in_array( 'rtcl_manager', (array) $u->roles, true ) ) {
			$u->set_role( 'editor' );
			update_user_meta( $uid, 'sp_migrated_role', 'rtcl_manager' );
			$r->count( 'role-manager-to-editor' );
		}
		update_user_meta( $uid, 'sp_migrated', 1 );
		$pending['users'][] = $uid;
		if ( count( $pending['members'] ) >= 500 ) { sp_mig_log_flush( $pending ); $pending = [ 'members' => [], 'users' => [] ]; }
	}
}
sp_mig_log_flush( $pending );
$r->count( 'users-processed', $done );
$r->finish();
