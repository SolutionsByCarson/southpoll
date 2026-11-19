<?php
/**
 * test-phase1-auth.php — Exercise registration, verification, lockout, password reset, and farm-user invitations
 * against the LOCAL database. Creates throwaway accounts with @example.test addresses (safe to delete).
 *
 * Run: ./wp-run.sh test-phase1-auth.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Auth\AccountService;
use SouthPoll\Membership\Auth\Lockout;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Farms\FarmRepository;

$c        = sp_membership();
$accounts = $c->get( AccountService::class );
$members  = $c->get( MemberRepository::class );
$farms    = $c->get( FarmRepository::class );
$fusers   = $c->get( FarmUserRepository::class );
$mships   = $c->get( MembershipRepository::class );
$ctxs     = $c->get( ContextProvider::class );
$pass     = 0; $fail = 0;
$check    = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$stamp    = substr( (string) time(), -6 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Capture emails instead of sending.
$sent = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = [ 'key' => $msg->key(), 'to' => $msg->recipients(), 'subject' => $msg->subject() ]; return false; } );

echo "== 1. Register a buyer ==\n";
$buyer = $accounts->register( [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => "sp-buyer-$stamp@example.test", 'password' => 'correct-horse-9', 'password_confirm' => 'correct-horse-9', 'phone' => '8166298010', 'account_type' => 'buyer', 'agree' => '1' ] );
$check( 'buyer created', $buyer instanceof WP_User, is_wp_error( $buyer ) ? $buyer->get_error_message() : "user #{$buyer->ID}" );
$bm = $members->find_by_user( $buyer->ID );
$check( 'buyer sp_members row', (bool) $bm, $bm ? "phone={$bm->phone} verified=" . var_export( ! empty( $bm->email_verified_at ), true ) : '' );
$check( 'buyer role sp_buyer', in_array( 'sp_buyer', $buyer->roles, true ), implode( ',', $buyer->roles ) );
$check( 'welcome + admin emails queued', 2 === count( array_filter( $sent, fn( $e ) => in_array( $e['key'], [ 'account.welcome', 'admin.new_account' ], true ) ) ), implode( ', ', array_column( $sent, 'key' ) ) );
$ctx = $ctxs->for_user( $buyer->ID );
$check( 'buyer context: role buyer, unverified → no contact info', 'buyer' === $ctx->role() && ! $ctx->can( 'view_contact_info' ) && $ctx->can( 'view_dashboard' ) );

echo "\n== 2. Verify email ==\n";
$raw = $members->issue_verification_token( (int) $bm->id );
$check( 'bad token rejected', null === $accounts->verify( $buyer->ID, 'nope' ) );
$check( 'good token verifies', (bool) $accounts->verify( $buyer->ID, $raw ) );
$ctx = $ctxs->for_user( $buyer->ID );
$check( 'verified buyer can view contact info + favorites', $ctx->can( 'view_contact_info' ) && $ctx->can( 'use_favorites' ) );

echo "\n== 3. Validation errors ==\n";
$dup = $accounts->register( [ 'first_name' => '', 'last_name' => 'X', 'email' => "sp-buyer-$stamp@example.test", 'password' => 'short', 'account_type' => 'member', 'farm_name' => '', 'agree' => '' ] );
$check( 'duplicate/short/missing rejected', is_wp_error( $dup ) && count( $dup->get_error_codes() ) >= 4, is_wp_error( $dup ) ? implode( ',', $dup->get_error_codes() ) : 'no error' );

echo "\n== 4. Register a member (farm shell) ==\n";
$owner = $accounts->register( [ 'first_name' => 'Test', 'last_name' => 'Owner', 'email' => "sp-owner-$stamp@example.test", 'password' => 'correct-horse-9', 'password_confirm' => 'correct-horse-9', 'account_type' => 'member', 'farm_name' => 'Test Ranch ' . $stamp, 'state' => 'mo', 'agree' => '1' ] );
$check( 'owner created', $owner instanceof WP_User, is_wp_error( $owner ) ? $owner->get_error_message() : "user #{$owner->ID}" );
$farm = $farms->find_by_owner( $owner->ID );
$check( 'farm shell created with slug + state', $farm && str_starts_with( $farm->slug, 'test-ranch' ) && 'MO' === $farm->state, $farm ? "#{$farm->id} {$farm->slug} {$farm->state}" : 'none' );
$fu = $fusers->for_user( $owner->ID );
$check( 'owner row in sp_farm_users', $fu && 'owner' === $fu->role );
$ms = $mships->for_farm( (int) $farm->id );
$check( 'membership row status none', $ms && 'none' === $ms->status );
$ctx = $ctxs->for_user( $owner->ID );
$check( 'owner context: role owner, membership none → edit_farm yes, publish_listing no, manage_membership yes', 'owner' === $ctx->role() && $ctx->can( 'edit_farm' ) && ! $ctx->can( 'publish_listing' ) && $ctx->can( 'manage_membership' ) );
$dup_farm = $farms->create( $owner->ID, 'Test Ranch ' . $stamp );
$check( 'duplicate farm name gets unique slug', $dup_farm->slug !== $farm->slug, $dup_farm->slug );
global $wpdb; $wpdb->delete( $wpdb->prefix . 'sp_farms', [ 'id' => $dup_farm->id ] );

echo "\n== 5. Login + lockout ==\n";
$ok = $accounts->login( "sp-owner-$stamp@example.test", 'correct-horse-9' );
$check( 'login with correct password', $ok instanceof WP_User );
$lock = $c->get( Lockout::class ); $lock->clear( "sp-owner-$stamp@example.test" );
for ( $i = 0; $i < 6; $i++ ) { $accounts->login( "sp-owner-$stamp@example.test", 'wrong-password' ); }
$locked = $accounts->login( "sp-owner-$stamp@example.test", 'correct-horse-9' );
$check( 'locked out after 6 failures (even with the right password)', is_wp_error( $locked ) && 'sp_locked' === $locked->get_error_code(), is_wp_error( $locked ) ? $locked->get_error_code() : 'logged in' );
$lock->clear( "sp-owner-$stamp@example.test" );

echo "\n== 6. Password reset ==\n";
$before = count( $sent );
$accounts->request_password_reset( "sp-owner-$stamp@example.test" );
$accounts->request_password_reset( 'nobody-here@example.test' );
$check( 'reset email sent only for existing account', count( $sent ) === $before + 1 && 'account.password_reset' === end( $sent )['key'] );
$key = get_password_reset_key( $owner );
$r   = $accounts->reset_password( $key, $owner->user_login, 'new-pass-word-10', 'new-pass-word-10' );
$check( 'password reset with valid key', $r instanceof WP_User );
$check( 'login with new password', $accounts->login( "sp-owner-$stamp@example.test", 'new-pass-word-10' ) instanceof WP_User );
$check( 'reused key rejected', is_wp_error( $accounts->reset_password( $key, $owner->user_login, 'another-pass-11', 'another-pass-11' ) ) );

echo "\n== 7. Invite a manager ==\n";
$inv = $fusers->invite( (int) $farm->id, "sp-manager-$stamp@example.test", $owner->ID );
$check( 'invite created with token', ! is_wp_error( $inv ) && ! empty( $inv->raw_token ), is_wp_error( $inv ) ? $inv->get_error_message() : '' );
$again = $fusers->invite( (int) $farm->id, "sp-buyer-$stamp@example.test", $owner->ID );
$check( 'inviting an unattached existing buyer is allowed', ! is_wp_error( $again ) );
$fusers->remove( (int) $again->id );
$self = $fusers->invite( (int) $farm->id, "sp-owner-$stamp@example.test", $owner->ID );
$check( 'inviting an already-attached account is refused', is_wp_error( $self ) && 'sp_already_attached' === $self->get_error_code() );
$found = $fusers->find_by_token( $inv->raw_token );
$check( 'invite resolves by raw token', $found && (int) $found->id === (int) $inv->id );
$mgr = $accounts->register( [ 'first_name' => 'Test', 'last_name' => 'Manager', 'email' => "sp-manager-$stamp@example.test", 'password' => 'correct-horse-9', 'password_confirm' => 'correct-horse-9', 'account_type' => 'buyer', 'invite_token' => $inv->raw_token, 'agree' => '1' ] );
$check( 'manager registered via invite', $mgr instanceof WP_User );
$mrow = $fusers->for_user( $mgr->ID );
$check( 'manager attached to the farm', $mrow && (int) $mrow->farm_id === (int) $farm->id && 'manager' === $mrow->role );
$ctx = $ctxs->for_user( $mgr->ID );
$check( 'manager context: membership none → cannot edit farm yet (needs active membership)', 'manager' === $ctx->role() && ! $ctx->can( 'edit_farm' ) && ! $ctx->can( 'manage_farm_users' ) );
$wrong = $accounts->register( [ 'first_name' => 'Wrong', 'last_name' => 'Email', 'email' => "sp-wrong-$stamp@example.test", 'password' => 'correct-horse-9', 'password_confirm' => 'correct-horse-9', 'account_type' => 'buyer', 'invite_token' => $inv->raw_token, 'agree' => '1' ] );
$check( 'used token cannot be reused by another email', $wrong instanceof WP_User && ! $fusers->for_user( $wrong->ID ) );
$list = $fusers->for_farm( (int) $farm->id );
$check( 'farm user list: owner + manager', 2 === count( $list ) && 'owner' === $list[0]->role && 'manager' === $list[1]->role, implode( ', ', array_map( fn( $r ) => "{$r->role}:{$r->email}", $list ) ) );
$check( 'owner cannot be removed', is_wp_error( $fusers->remove( (int) $list[0]->id ) ) );
$check( 'manager removal works', true === $fusers->remove( (int) $list[1]->id ) && null === $fusers->for_user( $mgr->ID ) );

echo "\n== 8. Email change ==\n";
$members->update( (int) $members->find_by_user( $owner->ID )->id, [ 'verification_sent_at' => null ] ); // simulate the resend cooldown having passed
$ec = $accounts->request_email_change( $owner, "sp-owner-new-$stamp@example.test" );
$om = $members->find_by_user( $owner->ID );
$check( 'email change pending + verify email sent to new address', true === $ec && "sp-owner-new-$stamp@example.test" === $om->pending_email && end( $sent )['to'] === [ "sp-owner-new-$stamp@example.test" ] );
$raw2 = $members->issue_verification_token( (int) $om->id );
$accounts->verify( $owner->ID, $raw2 );
$check( 'verification swaps login email', get_userdata( $owner->ID )->user_email === "sp-owner-new-$stamp@example.test" );

echo "\n== Summary: $pass passed, $fail failed ==\n";
echo "Emails captured: ", count( $sent ), " → ", implode( ', ', array_unique( array_column( $sent, 'key' ) ) ), "\n";
echo "Test accounts: sp-buyer-$stamp, sp-owner-new-$stamp (farm #{$farm->id} '{$farm->name}'), sp-manager-$stamp, sp-wrong-$stamp @example.test\n";
exit( $fail ? 1 : 0 );
