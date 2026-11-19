<?php
/**
 * test-phase1-cron.php — Membership cron: expiry, before/after reminders, recurring notice, idempotency.
 * Uses the test owner's farm (run test-phase1-auth.php first). Manipulates end dates instead of the clock.
 *
 * Run: ./wp-run.sh test-phase1-cron.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Memberships\MembershipCron;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Support\Dates;

$c     = sp_membership();
$pass  = 0; $fail = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$sent  = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = $msg->key() . ( $msg->get( 'days' ) !== null ? '(' . $msg->get( 'days' ) . 'd)' : '' ) . ( $msg->get( 'days_after' ) !== null ? '(+' . $msg->get( 'days_after' ) . 'd)' : '' ); return false; } );

global $wpdb;
$owner_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
$farm_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sp_farms WHERE owner_user_id = %d", $owner_id ) );
if ( ! $farm_id ) { die( "Run test-phase1-auth.php first.\n" ); }

$cron   = $c->get( MembershipCron::class );
$queue  = $c->get( SouthPoll\Membership\Engagement\AlertDispatcher::class );
$settings_svc = $c->get( SouthPoll\Membership\Core\Settings::class );
$saved_window = (string) $settings_svc->get( 'email_window_start', '' );
$settings_svc->set( 'email_window_start', '' ); // send immediately: the checks below read what the mailer sends right after a run
$wpdb->delete( $wpdb->prefix . 'sp_notification_queue', [ 'email_key' => 'message', 'user_id' => $owner_id ] ); // stale notices from an aborted run
// Park everything else that is waiting in the queue (the site's own cron may have queued notices for other farms) so the drains below only send this farm's mail.
$parked = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}sp_notification_queue WHERE sent_at IS NULL" ) );
if ( $parked ) { $wpdb->query( "UPDATE {$wpdb->prefix}sp_notification_queue SET send_after = '2099-01-01 00:00:00' WHERE id IN (" . implode( ',', $parked ) . ')' ); }
$unpark = function () use ( $wpdb, $parked ) { if ( $parked ) { $wpdb->query( "UPDATE {$wpdb->prefix}sp_notification_queue SET send_after = UTC_TIMESTAMP() WHERE id IN (" . implode( ',', $parked ) . ") AND send_after = '2099-01-01 00:00:00'" ); } };
$run    = function ( ?string $at = null ) use ( $cron, $queue ) { $s = $cron->run( $at ); $queue->process(); return $s; }; // reminders are queued (T6.9): drain after every run
$mrepo  = $c->get( MembershipRepository::class );
$engine = $c->get( StatusEngine::class );
$tier   = $c->get( TierRepository::class )->default();
$now    = Dates::now();

// Isolate: only this farm should be affected, so park every other membership far in the future / mark reminders sent.
$others = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, ends_at, reminders_sent FROM {$wpdb->prefix}sp_memberships WHERE farm_id <> %d", $farm_id ) );
foreach ( $others as $o ) { $wpdb->update( $wpdb->prefix . 'sp_memberships', [ 'status' => 'none' ], [ 'id' => $o->id ] ); }
$restore_others = function () use ( $wpdb, $others ) { foreach ( $others as $o ) { $wpdb->update( $wpdb->prefix . 'sp_memberships', [ 'status' => $o->status, 'ends_at' => $o->ends_at, 'reminders_sent' => $o->reminders_sent ], [ 'id' => $o->id ] ); } };

$engine->activate( $farm_id, $tier, [ 'source' => 'offline', 'actor' => 8, 'auto_renew' => false ] );
$m = $mrepo->for_farm( $farm_id );
$set_end = function ( int $days_from_now, string $status = 'active' ) use ( $mrepo, $m, $now ) { $mrepo->update( (int) $m->id, [ 'status' => $status, 'ends_at' => Dates::add_days( $now, $days_from_now ), 'starts_at' => Dates::add_days( $now, $days_from_now - 365 ), 'reminders_sent' => [], 'expired_at' => null ] ); };

echo "== 1. Quiet run: 200 days left → nothing ==\n";
$set_end( 200 ); $sent = [];
$s = $run( $now );
$check( 'no emails, no expiries', 0 === array_sum( $s ) && empty( $sent ), json_encode( $s ) );

echo "\n== 2. 25 days left → one 30-day reminder, then idempotent ==\n";
$set_end( 25 ); $sent = [];
$s = $run( $now );
$check( 'one reminder sent', 1 === $s['reminders'] && [ 'membership.reminder(25d)' ] === $sent, implode( ',', $sent ) );
$sent = []; $s = $run( $now );
$check( 'second run sends nothing', 0 === $s['reminders'] && empty( $sent ) );
$check( 'reminders_sent records before_30', in_array( 'before_30', $mrepo->reminders_sent( $mrepo->for_farm( $farm_id ) ), true ) );

echo "\n== 3. Same membership reaches 6 days → 7-day reminder; 1 day → final reminder ==\n";
$mrepo->update( (int) $m->id, [ 'ends_at' => Dates::add_days( $now, 6 ) ] ); $sent = [];
$run( $now );
$check( '7-day reminder', [ 'membership.reminder(6d)' ] === $sent, implode( ',', $sent ) );
$mrepo->update( (int) $m->id, [ 'ends_at' => Dates::add_days( $now, 1 ) ] ); $sent = [];
$run( $now );
$check( '1-day reminder', [ 'membership.reminder(1d)' ] === $sent, implode( ',', $sent ) );

echo "\n== 4. Fresh membership already inside all windows → a single (most urgent) reminder ==\n";
$set_end( 3 ); $sent = [];
$run( $now );
$check( 'only one email, all thresholds marked', 1 === count( $sent ) && count( array_intersect( [ 'before_30', 'before_7' ], $mrepo->reminders_sent( $mrepo->for_farm( $farm_id ) ) ) ) === 2, implode( ',', $sent ) );

echo "\n== 5. Recurring membership → one notice at 30 days, no plain reminders ==\n";
$set_end( 20 ); $mrepo->update( (int) $m->id, [ 'auto_renew' => 1 ] ); $sent = [];
$run( $now ); $run( $now );
$check( 'recurring notice once', [ 'membership.recurring_notice(20d)' ] === $sent, implode( ',', $sent ) );
$mrepo->update( (int) $m->id, [ 'auto_renew' => 0 ] );

echo "\n== 6. Expiry → status expired, member + admin emails, lapsed action ==\n";
$lapsed = 0; add_action( 'sp/membership/lapsed', function () use ( &$lapsed ) { $lapsed++; } );
$set_end( -1 ); $sent = [];
$s = $run( $now );
$mm = $mrepo->for_farm( $farm_id );
$check( 'expired', 1 === $s['expired'] && 'expired' === $mm->status && ! empty( $mm->expired_at ) && 1 === $lapsed );
$check( 'expired emails: member (+0d) + admin', in_array( 'membership.expired(+0d)', $sent, true ) && in_array( 'admin.expired', $sent, true ), implode( ',', $sent ) );
$sent = []; $run( $now );
$check( 'no repeat on next run', empty( $sent ) );

echo "\n== 7. Post-expiry prompts at +7 and +30, none after +37 ==\n";
$mrepo->update( (int) $m->id, [ 'ends_at' => Dates::add_days( $now, -8 ) ] ); $sent = [];
$run( $now ); $run( $now );
$check( '+7 prompt once', [ 'membership.expired(+7d)' ] === $sent, implode( ',', $sent ) );
$mrepo->update( (int) $m->id, [ 'ends_at' => Dates::add_days( $now, -31 ) ] ); $sent = [];
$run( $now );
$check( '+30 prompt', [ 'membership.expired(+30d)' ] === $sent, implode( ',', $sent ) );
$mrepo->update( (int) $m->id, [ 'ends_at' => Dates::add_days( $now, -60 ), 'reminders_sent' => [] ] ); $sent = [];
$run( $now );
$check( 'old expiries are left alone', empty( $sent ) );

echo "\n== 8. Renewal after expiry resets reminders and restores ==\n";
$restored = 0; add_action( 'sp/membership/restored', function () use ( &$restored ) { $restored++; } );
$engine->activate( $farm_id, $tier, [ 'source' => 'offline', 'actor' => 8 ] );
$mm = $mrepo->for_farm( $farm_id );
$check( 'active again, fresh 365-day term from now, reminders cleared', 'active' === $mm->status && abs( Dates::days_until( $mm->ends_at ) - 365 ) <= 1 && [] === $mrepo->reminders_sent( $mm ) && 1 === $restored );

echo "\n== 9. Send window (T6.9) + registry digest (T6.8) ==\n";
$notifier = $c->get( SouthPoll\Membership\Email\Notifier::class );
$settings_svc->set( 'email_window_start', '07:00' ); $settings_svc->set( 'email_window_end', '20:00' );
$tz   = wp_timezone();
$at   = fn( string $t ) => new DateTimeImmutable( 'today ' . $t, $tz );
$utc  = fn( DateTimeImmutable $d ) => $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$check( 'send window: 03:00 → today 07:00', $notifier->send_after( $at( '03:00' ) ) === $utc( $at( '07:00' ) ), $notifier->send_after( $at( '03:00' ) ) );
$check( 'send window: 12:00 → now', abs( strtotime( $notifier->send_after( $at( '12:00' ) ) . ' UTC' ) - time() ) < 5 );
$check( 'send window: 22:00 → tomorrow 07:00', $notifier->send_after( $at( '22:00' ) ) === $utc( $at( '07:00' )->modify( '+1 day' ) ) );
$set_end( 25 ); $sent = [];
$s = $cron->run( $now );
$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_notification_queue WHERE email_key = 'message' AND sent_at IS NULL" );
$check( 'reminder is queued, not sent, while a window is set', 1 === $s['reminders'] && empty( $sent ) && $pending >= 1, "pending=$pending sent=" . implode( ',', $sent ) );
$wpdb->query( "UPDATE {$wpdb->prefix}sp_notification_queue SET send_after = '2000-01-01 00:00:00' WHERE email_key = 'message' AND sent_at IS NULL" );
$queue->process();
$check( 'queue worker rebuilds the message and sends it', [ 'membership.reminder(25d)' ] === $sent, implode( ',', $sent ) );
$settings_svc->set( 'email_window_start', '' );

$digest    = $c->get( SouthPoll\Membership\Memberships\RegistryDigest::class );
$since     = Dates::add_days( Dates::now(), -1 );
$names     = fn( array $rows ) => array_column( $rows, 'farm' );
$farm_name = (string) $c->get( SouthPoll\Membership\Farms\FarmRepository::class )->find( $farm_id )->name;
$lists     = $digest->build( $since, Dates::now() );
$check( 'digest: farm reactivated in §8 is in ADD', in_array( $farm_name, $names( $lists['add'] ), true ), implode( '|', $names( $lists['add'] ) ) );
$check( 'digest: a lapse followed by a renewal nets out (not in REMOVE)', ! in_array( $farm_name, $names( $lists['remove'] ), true ) );
$engine->expire( $mrepo->for_farm( $farm_id ), 'digest test' );
$lists = $digest->build( $since, Dates::now() );
$check( 'digest: after expiry the farm moves to REMOVE', in_array( $farm_name, $names( $lists['remove'] ), true ) && ! in_array( $farm_name, $names( $lists['add'] ), true ) );
$saved_digest = (array) $settings_svc->get( 'registry_digest_emails', [] ); $settings_svc->set( 'registry_digest_emails', [ 'registry-test@example.test' ] );
$saved_enabled = $settings_svc->get( 'registry_digest_enabled', true ); $settings_svc->set( 'registry_digest_enabled', true ); // the admin suite's settings form may have left it off
$saved_last   = get_option( SouthPoll\Membership\Memberships\RegistryDigest::OPTION_LAST, '' ); update_option( SouthPoll\Membership\Memberships\RegistryDigest::OPTION_LAST, $since, false );
$captured = null; $captured_obj = null;
// This suite's own before_send filter (priority 10) suppresses every send; capture the digest before it and hand the object back after it.
$grab = function ( $m ) use ( &$captured, &$captured_obj ) { if ( $m instanceof SouthPoll\Membership\Email\Message && 'admin.registry_digest' === $m->key() ) { $captured_obj = $m; $captured = [ 'to' => $m->recipients(), 'subject' => $m->subject(), 'att' => $m->attachments(), 'body' => $m->body() ]; } return $m; };
$give = function ( $m ) use ( &$captured_obj ) { return ( false === $m && $captured_obj ) ? $captured_obj : $m; };
add_filter( 'sp/email/before_send', $grab, 5 ); add_filter( 'sp/email/before_send', $give, 20 );
add_filter( 'pre_wp_mail', '__return_true' ); // Local has no mail transport
$r = $digest->run( true );
remove_filter( 'pre_wp_mail', '__return_true' ); remove_filter( 'sp/email/before_send', $grab, 5 ); remove_filter( 'sp/email/before_send', $give, 20 );
$check( 'digest run: sent to the configured address with a CSV attached and both lists in the body', $r['sent'] && $captured && [ 'registry-test@example.test' ] === $captured['to'] && 1 === count( $captured['att'] ) && str_contains( $captured['body'], 'REMOVE from the registry' ) && str_contains( $captured['body'], $farm_name ), json_encode( $r ) );
$check( 'digest run: subject carries the counts', str_contains( (string) ( $captured['subject'] ?? '' ), 'remove ' . $r['remove'] ), (string) ( $captured['subject'] ?? '' ) );
$check( 'digest run: window advanced, next run scheduled on a configured day', get_option( SouthPoll\Membership\Memberships\RegistryDigest::OPTION_LAST ) === $r['until'] && wp_next_scheduled( SouthPoll\Membership\Core\Cron::DIGEST ) && in_array( strtolower( wp_date( 'D', (int) wp_next_scheduled( SouthPoll\Membership\Core\Cron::DIGEST ) ) ), $digest->days(), true ) );
$saved_days = $settings_svc->get( 'registry_digest_days' );
$settings_svc->set( 'registry_digest_days', 'mon,thu' );
$check( 'digest slot math: Tuesday 09:00 → Thursday 08:00 (mon,thu @ 08:00)', 'Thu 08:00' === $digest->next_slot( new DateTimeImmutable( 'next tuesday 09:00', $tz ) )->format( 'D H:i' ), $digest->next_slot( new DateTimeImmutable( 'next tuesday 09:00', $tz ) )->format( 'D H:i' ) );
$settings_svc->set( 'registry_digest_days', 'mon,tue,wed,thu,fri,sat,sun' );
$check( 'S9 digest slot math: daily → Tuesday 09:00 runs Wednesday 08:00, Tuesday 07:00 runs the same day', 'Wed 08:00' === $digest->next_slot( new DateTimeImmutable( 'next tuesday 09:00', $tz ) )->format( 'D H:i' ) && 'Tue 08:00' === $digest->next_slot( new DateTimeImmutable( 'next tuesday 07:00', $tz ) )->format( 'D H:i' ) );
$settings_svc->set( 'registry_digest_days', $saved_days );
$check( 'S9 defaults: daily at 08:00, quiet days skipped', 'mon,tue,wed,thu,fri,sat,sun' === \SouthPoll\Membership\Core\Settings::defaults()['registry_digest_days'] && false === \SouthPoll\Membership\Core\Settings::defaults()['registry_digest_send_empty'] && '08:00' === \SouthPoll\Membership\Core\Settings::defaults()['registry_digest_time'] );
$settings_svc->set( 'registry_digest_emails', $saved_digest ); $settings_svc->set( 'registry_digest_enabled', (bool) $saved_enabled ); update_option( SouthPoll\Membership\Memberships\RegistryDigest::OPTION_LAST, $saved_last, false );
$engine->activate( $farm_id, $tier, [ 'source' => 'offline', 'actor' => 8 ] ); // leave the farm active for the later suites

$restore_others();
$unpark();
$settings_svc->set( 'email_window_start', $saved_window );
echo "\n== Summary: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
