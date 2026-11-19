<?php
/**
 * test-phase1-payments.php — Stripe checkout, fulfilment, webhooks, auto-renew, and offline claims, using a FAKE
 * Stripe transport (no network, no keys needed). Uses the test owner from test-phase1-auth.php.
 *
 * Run: ./wp-run.sh test-phase1-payments.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Frontend\Controllers\WebhookController;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\CheckoutService;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Payments\StripeClient;
use SouthPoll\Membership\Support\Dates;

$c      = sp_membership();
$pass   = 0; $fail = 0;
$check  = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$sent   = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = $msg->key(); return false; } );

global $wpdb;
$owner_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
if ( ! $owner_id ) { die( "Run test-phase1-auth.php first.\n" ); }
wp_set_current_user( $owner_id );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$ctxs    = $c->get( ContextProvider::class );
$ctx     = $ctxs->for_user( $owner_id );
$farm_id = $ctx->farm_id();
$tiers   = $c->get( TierRepository::class );
$tier    = $tiers->default();
$mrepo   = $c->get( MembershipRepository::class );
$prepo   = $c->get( PaymentRepository::class );
$engine  = $c->get( StatusEngine::class );
$client  = $c->get( StripeClient::class );
$integ   = $c->get( Integrations::class );

// Make the client think it is configured (test mode) without storing real keys.
$orig_mode = $integ->get( 'stripe.mode' ); $orig_test_secret = $integ->get( 'stripe.test_secret_key' ); $orig_wh = $integ->get( 'stripe.webhook_secret' );
$integ->set( 'stripe.mode', 'test' ); $integ->set( 'stripe.test_secret_key', 'sk_test_FAKE' ); $integ->set( 'stripe.webhook_secret', 'whsec_FAKE' );
$restore = function () use ( $integ, $orig_mode, $orig_test_secret, $orig_wh ) { $integ->set( 'stripe.mode', $orig_mode ); $integ->set( 'stripe.test_secret_key', $orig_test_secret ); $integ->set( 'stripe.webhook_secret', $orig_wh ); };
register_shutdown_function( $restore ); // always put the real integration values back, even if a check throws

// Deterministic start: no stale claims, Stripe ids, or fake webhook events from earlier runs.
$fake_ids  = [ 'prod_TEST1', 'price_ONE', 'price_REC' ];
$tier_orig = [ 'stripe_product_id' => in_array( $tier->stripe_product_id, $fake_ids, true ) ? '' : $tier->stripe_product_id, 'stripe_price_id' => in_array( $tier->stripe_price_id, $fake_ids, true ) ? '' : $tier->stripe_price_id, 'stripe_recurring_price_id' => in_array( $tier->stripe_recurring_price_id, $fake_ids, true ) ? '' : $tier->stripe_recurring_price_id ];
$tiers->update( (int) $tier->id, [ 'stripe_product_id' => '', 'stripe_price_id' => '', 'stripe_recurring_price_id' => '' ] );
$tier = $tiers->find( (int) $tier->id );
register_shutdown_function( function () use ( $tiers, $tier, $tier_orig ) { $tiers->update( (int) $tier->id, $tier_orig ); } );
$wpdb->query( "DELETE FROM {$wpdb->prefix}sp_stripe_events WHERE event_id LIKE 'evt\\_%'" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sp_payments WHERE farm_id = %d", $farm_id ) );
// Fake Stripe ids are global; scrub them from any farm an earlier run touched so lookups resolve to THIS farm.
$wpdb->query( "UPDATE {$wpdb->prefix}sp_memberships SET stripe_customer_id = '', stripe_subscription_id = '', auto_renew = 0 WHERE stripe_customer_id = 'cus_TEST1' OR stripe_subscription_id = 'sub_TEST1'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}sp_payments WHERE stripe_checkout_session_id LIKE 'cs\\_test\\_%' OR stripe_subscription_id = 'sub_TEST1' OR stripe_invoice_id IN ('in_FIRST','in_FIRST2','in_CYCLE1','in_FAIL') OR stripe_payment_intent_id IN ('pi_TEST1','pi_CYCLE1')" );
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'none', 'starts_at' => null, 'ends_at' => null, 'auto_renew' => 0, 'stripe_customer_id' => '', 'stripe_subscription_id' => '', 'reminders_sent' => [], 'expired_at' => null ] );
$ctx = $ctxs->for_user( $owner_id );

// ---- Fake Stripe ----
$calls = [];
$store = [ 'prices' => [] ];
$client->set_transport( function ( string $method, string $url, array $headers, string $body ) use ( &$calls, &$store ) {
	parse_str( $body, $params );
	$path    = (string) parse_url( $url, PHP_URL_PATH );
	$calls[] = $method . ' ' . $path;
	$json    = fn( array $d ) => [ 'status' => 200, 'body' => json_encode( $d ) ];
	if ( 'POST' === $method && str_ends_with( $path, '/customers' ) )          return $json( [ 'id' => 'cus_TEST1', 'email' => $params['email'] ?? '' ] );
	if ( 'POST' === $method && str_ends_with( $path, '/products' ) )           return $json( [ 'id' => 'prod_TEST1' ] );
	if ( 'POST' === $method && str_ends_with( $path, '/prices' ) )             { $id = isset( $params['recurring'] ) ? 'price_REC' : 'price_ONE'; $store['prices'][ $id ] = [ 'id' => $id, 'unit_amount' => (int) $params['unit_amount'], 'currency' => $params['currency'], 'active' => true ]; return $json( $store['prices'][ $id ] ); }
	if ( 'GET' === $method && preg_match( '#/prices/(price_\w+)$#', $path, $m ) ) return isset( $store['prices'][ $m[1] ] ) ? $json( $store['prices'][ $m[1] ] ) : [ 'status' => 404, 'body' => json_encode( [ 'error' => [ 'message' => 'No such price', 'code' => 'resource_missing' ] ] ) ];
	if ( 'POST' === $method && str_ends_with( $path, '/checkout/sessions' ) )  { $store['last_session'] = $params; return $json( [ 'id' => 'cs_test_' . substr( md5( $body ), 0, 8 ), 'url' => 'https://checkout.stripe.com/c/pay/fake' ] ); }
	if ( 'GET' === $method && preg_match( '#/checkout/sessions/(cs_\w+)$#', $path, $m ) ) {
		$p = $store['last_session'];
		if ( 'subscription' === ( $p['mode'] ?? '' ) ) {
			$trial = isset( $p['subscription_data']['trial_end'] );
			return $json( [ 'id' => $m[1], 'payment_status' => $trial ? 'no_payment_required' : 'paid', 'amount_total' => $trial ? 0 : 10000, 'currency' => 'usd', 'customer' => 'cus_TEST1', 'mode' => 'subscription', 'subscription' => [ 'id' => 'sub_TEST1', 'status' => $trial ? 'trialing' : 'active', 'latest_invoice' => [ 'id' => 'in_FIRST', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/fake' ] ] ] );
		}
		return $json( [ 'id' => $m[1], 'payment_status' => 'paid', 'amount_total' => 10000, 'currency' => 'usd', 'customer' => 'cus_TEST1', 'mode' => 'payment', 'payment_intent' => [ 'id' => 'pi_TEST1', 'latest_charge' => [ 'id' => 'ch_TEST1', 'receipt_url' => 'https://pay.stripe.com/receipts/fake' ] ] ] );
	}
	if ( 'GET' === $method && preg_match( '#/subscriptions/(sub_\w+)$#', $path ) ) return $json( [ 'id' => 'sub_TEST1', 'status' => 'active', 'cancel_at_period_end' => false, 'default_payment_method' => [ 'card' => [ 'brand' => 'visa', 'last4' => '4242' ] ] ] );
	if ( 'POST' === $method && preg_match( '#/subscriptions/(sub_\w+)$#', $path ) ) { $store['cancel_at_period_end'] = $params['cancel_at_period_end'] ?? null; return $json( [ 'id' => 'sub_TEST1', 'status' => 'active', 'cancel_at_period_end' => 'true' === ( $params['cancel_at_period_end'] ?? '' ) ] ); }
	if ( 'POST' === $method && str_ends_with( $path, '/billing_portal/sessions' ) ) return $json( [ 'url' => 'https://billing.stripe.com/p/session/fake' ] );
	return [ 'status' => 404, 'body' => json_encode( [ 'error' => [ 'message' => 'Unhandled fake route ' . $method . ' ' . $path, 'type' => 'invalid_request_error' ] ] ) ];
} );

$checkout = $c->get( CheckoutService::class );
$claims   = $c->get( OfflineClaims::class );
$webhook  = $c->get( WebhookController::class );
$wpdb->delete( $wpdb->prefix . 'sp_stripe_events', [ 'type' => 'test' ] );

echo "== 1. One-time checkout → fulfilment ==\n";
$url = $checkout->start( $ctx, $tier, false );
$check( 'checkout session URL returned', is_string( $url ) && str_contains( $url, 'checkout.stripe.com' ), is_wp_error( $url ) ? $url->get_error_message() : $url );
$check( 'customer, product, prices created once', in_array( 'POST /v1/customers', $calls, true ) && in_array( 'POST /v1/products', $calls, true ) && 2 === count( array_filter( $calls, fn( $x ) => 'POST /v1/prices' === $x ) ), implode( ' | ', $calls ) );
$check( 'session params: mode payment, success/cancel URLs, metadata', 'payment' === $store['last_session']['mode'] && str_contains( $store['last_session']['success_url'], 'session_id=' ) && (string) $farm_id === $store['last_session']['metadata']['farm_id'] );
$pending = $prepo->for_farm( $farm_id )[0];
$check( 'pending payment row saved with session id', 'pending' === $pending->status && str_starts_with( (string) $pending->stripe_checkout_session_id, 'cs_test_' ) );
$sent = [];
$res = $checkout->complete_session( (string) $pending->stripe_checkout_session_id );
$m   = $mrepo->for_farm( $farm_id );
$check( 'complete_session fulfils: membership active 365d', ! is_wp_error( $res ) && 'fulfilled' === $res['result'] && 'active' === $engine->effective_status( $m ) && abs( Dates::days_until( $m->ends_at ) - 365 ) <= 1, is_wp_error( $res ) ? $res->get_error_message() : "ends {$m->ends_at}" );
$pay = $prepo->find( (int) $pending->id );
$check( 'payment succeeded with intent/charge/receipt + term', 'succeeded' === $pay->status && 'pi_TEST1' === $pay->stripe_payment_intent_id && 'ch_TEST1' === $pay->stripe_charge_id && $pay->term_end === $m->ends_at );
$check( 'emails: activated + receipt + admin new_membership', in_array( 'membership.activated', $sent, true ) && in_array( 'membership.receipt', $sent, true ) && in_array( 'admin.new_membership', $sent, true ), implode( ',', $sent ) );
$res2 = $checkout->complete_session( (string) $pending->stripe_checkout_session_id );
$check( 'second completion is a no-op (idempotent)', 'already' === $res2['result'] );
$first_end = $m->ends_at;

echo "\n== 2. Webhook: signature, dedupe, checkout.session.completed ==\n";
$event   = [ 'id' => 'evt_1', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => [ 'object' => [ 'id' => (string) $pending->stripe_checkout_session_id ] ] ];
$payload = json_encode( $event );
$check( 'signature verifies with correct secret', StripeClient::verify_signature( $payload, StripeClient::sign( $payload, 'whsec_FAKE' ), 'whsec_FAKE' ) );
$check( 'signature rejects wrong secret / stale timestamp', ! StripeClient::verify_signature( $payload, StripeClient::sign( $payload, 'whsec_OTHER' ), 'whsec_FAKE' ) && ! StripeClient::verify_signature( $payload, StripeClient::sign( $payload, 'whsec_FAKE', time() - 3600 ), 'whsec_FAKE' ) );
$check( 'event processed (already fulfilled)', 'already' === $webhook->process( $event, $payload ) );
$check( 'same event id is a duplicate', 'duplicate' === $webhook->process( $event, $payload ) );
$live = $event; $live['id'] = 'evt_live'; $live['livemode'] = true;
$check( 'livemode mismatch is flagged (plugin in test mode)', 'mode_mismatch' === $webhook->process( $live, json_encode( $live ) ) );

echo "\n== 3. Renewal while active extends from the end date (D-27) ==\n";
$sent = [];
$ctx  = $ctxs->for_user( $owner_id );
$url  = $checkout->start( $ctx, $tier, false );
$p2   = $prepo->for_farm( $farm_id )[0];
$checkout->complete_session( (string) $p2->stripe_checkout_session_id );
$m    = $mrepo->for_farm( $farm_id );
$check( 'ends_at = previous end + 365', $m->ends_at === Dates::add_days( $first_end, 365 ), "{$first_end} → {$m->ends_at}" );
$check( 'renewal emails: renewed + receipt + admin renewal', in_array( 'membership.renewed', $sent, true ) && in_array( 'admin.renewal', $sent, true ), implode( ',', $sent ) );

echo "\n== 4. Auto-renew on an active membership (trial until end date) ==\n";
$sent = []; $ctx = $ctxs->for_user( $owner_id );
$res  = $checkout->set_auto_renew( $ctx, true );
$check( 'needs a checkout session (no subscription yet)', is_string( $res ) && str_starts_with( $res, 'checkout:' ), is_wp_error( $res ) ? $res->get_error_message() : $res );
$check( 'session mode subscription with trial_end at current end date', 'subscription' === $store['last_session']['mode'] && (int) $store['last_session']['subscription_data']['trial_end'] === Dates::utc( $m->ends_at )->getTimestamp() );
$p3   = $prepo->for_farm( $farm_id )[0];
$res  = $checkout->complete_session( (string) $p3->stripe_checkout_session_id );
$m    = $mrepo->for_farm( $farm_id );
$check( 'auto_renew on, subscription id stored, term unchanged', 'auto_renew' === $res['result'] && 1 === (int) $m->auto_renew && 'sub_TEST1' === $m->stripe_subscription_id && $m->ends_at === Dates::add_days( $first_end, 365 ) );
$ctx = $ctxs->for_user( $owner_id );
$res = $checkout->set_auto_renew( $ctx, false );
$check( 'turning off sets cancel_at_period_end=true', 'off' === $res && 'true' === $store['cancel_at_period_end'] && 0 === (int) $mrepo->for_farm( $farm_id )->auto_renew );
$ctx = $ctxs->for_user( $owner_id );
$res = $checkout->set_auto_renew( $ctx, true );
$check( 'turning back on reuses the subscription', 'on' === $res && 'false' === $store['cancel_at_period_end'] );
$portal = $checkout->portal_url( $ctxs->for_user( $owner_id ) );
$check( 'billing portal URL', is_string( $portal ) && str_contains( $portal, 'billing.stripe.com' ) );

echo "\n== 5. Recurring renewal via invoice.paid ==\n";
$sent = []; $before = $mrepo->for_farm( $farm_id )->ends_at;
$inv  = [ 'id' => 'evt_inv1', 'type' => 'invoice.paid', 'livemode' => false, 'data' => [ 'object' => [ 'id' => 'in_CYCLE1', 'subscription' => 'sub_TEST1', 'customer' => 'cus_TEST1', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 10000, 'currency' => 'usd', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/cycle', 'charge' => 'ch_CYCLE1', 'payment_intent' => 'pi_CYCLE1', 'next_payment_attempt' => null ] ] ];
$check( 'invoice.paid → renewed', 'renewed' === $webhook->process( $inv, json_encode( $inv ) ) );
$m = $mrepo->for_farm( $farm_id );
$check( 'term extended by 365 from previous end', $m->ends_at === Dates::add_days( $before, 365 ) );
$check( 'payment row with invoice id + recurring flag', (bool) $prepo->find_by_invoice( 'in_CYCLE1' ) && 1 === (int) $prepo->find_by_invoice( 'in_CYCLE1' )->recurring );
$check( 'duplicate invoice ignored', 'already' === $checkout->handle_event( $inv ) );
$check( 'first-invoice event does not double-extend', 'first_invoice' === $checkout->handle_event( [ 'type' => 'invoice.paid', 'data' => [ 'object' => [ 'id' => 'in_FIRST2', 'subscription' => 'sub_TEST1', 'billing_reason' => 'subscription_create' ] ] ] ) && $mrepo->for_farm( $farm_id )->ends_at === $m->ends_at );

echo "\n== 6. Payment failure + subscription lifecycle events ==\n";
$sent = [];
$r = $checkout->handle_event( [ 'type' => 'invoice.payment_failed', 'data' => [ 'object' => [ 'id' => 'in_FAIL', 'subscription' => 'sub_TEST1', 'customer' => 'cus_TEST1', 'attempt_count' => 2, 'next_payment_attempt' => time() + 86400, 'amount_due' => 10000 ] ] ] );
$check( 'invoice.payment_failed notifies member + admin', 'notified' === $r && in_array( 'membership.payment_failed', $sent, true ) && in_array( 'admin.payment_failed', $sent, true ), implode( ',', $sent ) );
$r = $checkout->handle_event( [ 'type' => 'customer.subscription.updated', 'data' => [ 'object' => [ 'id' => 'sub_TEST1', 'status' => 'active', 'cancel_at_period_end' => true ] ] ] );
$check( 'subscription.updated with cancel_at_period_end → auto_renew off', 'auto_renew_off' === $r && 0 === (int) $mrepo->for_farm( $farm_id )->auto_renew );
$r = $checkout->handle_event( [ 'type' => 'customer.subscription.updated', 'data' => [ 'object' => [ 'id' => 'sub_TEST1', 'status' => 'active', 'cancel_at_period_end' => false ] ] ] );
$check( 'subscription.updated reactivates auto_renew', 'auto_renew_on' === $r && 1 === (int) $mrepo->for_farm( $farm_id )->auto_renew );
$r = $checkout->handle_event( [ 'type' => 'customer.subscription.deleted', 'data' => [ 'object' => [ 'id' => 'sub_TEST1' ] ] ] );
$check( 'subscription.deleted → auto_renew off', 'auto_renew_off' === $r && 0 === (int) $mrepo->for_farm( $farm_id )->auto_renew );
$r = $checkout->handle_event( [ 'type' => 'charge.refunded', 'data' => [ 'object' => [ 'payment_intent' => 'pi_TEST1', 'amount_refunded' => 10000 ] ] ] );
$check( 'charge.refunded marks payment refunded', 'refunded' === $r && 'refunded' === $prepo->find( (int) $pending->id )->status );
$check( 'unknown event type ignored', 'ignored' === $checkout->handle_event( [ 'type' => 'product.created', 'data' => [ 'object' => [] ] ] ) );

echo "\n== 7. Offline claim: submit → approve / reject ==\n";
$sent = [];
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sp_payments WHERE farm_id = %d AND status = 'pending' AND method IN ('check','cash','other')", $farm_id ) );
$mrepo->update( (int) $m->id, [ 'status' => 'expired', 'ends_at' => Dates::add_days( Dates::now(), -10 ) ] );
$ctx   = $ctxs->for_user( $owner_id );
$claim = $claims->submit( $ctx, $tier, [ 'method' => 'check', 'amount_cents' => 10000, 'paid_on' => '2026-10-01', 'note' => 'Check #1234 at field day' ] );
$check( 'claim created, membership pending, emails sent', ! is_wp_error( $claim ) && 'pending' === $mrepo->for_farm( $farm_id )->status && in_array( 'membership.claim_received', $sent, true ) && in_array( 'admin.claim_submitted', $sent, true ), is_wp_error( $claim ) ? $claim->get_error_message() : implode( ',', $sent ) );
$dup = $claims->submit( $ctxs->for_user( $owner_id ), $tier, [ 'method' => 'cash' ] );
$check( 'second claim refused while one is pending', is_wp_error( $dup ) && 'sp_claim_exists' === $dup->get_error_code() );
$check( 'pending claims queue lists it', in_array( (int) $claim->id, array_map( fn( $p ) => (int) $p->id, $prepo->pending_claims() ), true ) );
$sent = [];
$rej  = $claims->reject( (int) $claim->id, 8, 'No check received' );
$check( 'reject → payment rejected, membership back to none, member emailed', ! is_wp_error( $rej ) && 'rejected' === $rej->status && 'none' === $mrepo->for_farm( $farm_id )->status && in_array( 'membership.claim_rejected', $sent, true ) );
$claim2 = $claims->submit( $ctxs->for_user( $owner_id ), $tier, [ 'method' => 'cash', 'amount_cents' => 10000 ] );
$sent   = [];
$app    = $claims->approve( (int) $claim2->id, 8, '2026-10-15', 'Verified against deposit' );
$m      = $mrepo->for_farm( $farm_id );
$check( 'approve → active, term starts on chosen date, source offline', ! is_wp_error( $app ) && 'active' === $engine->effective_status( $m ) && str_starts_with( (string) $m->starts_at, '2026-10-15' ) && 'offline' === $m->source && 8 === (int) $app->verified_by, "{$m->starts_at} → {$m->ends_at}" );
$check( 'approve emails: activated + receipt (check) + admin', in_array( 'membership.activated', $sent, true ) && in_array( 'membership.receipt', $sent, true ) && in_array( 'admin.new_membership', $sent, true ), implode( ',', $sent ) );
$rec = $claims->record( $farm_id, 'check', 10000, '', 8, 'Walk-in renewal' );
$check( 'staff record() extends an active membership', ! is_wp_error( $rec ) && $mrepo->for_farm( $farm_id )->ends_at === Dates::add_days( $m->ends_at, 365 ) );

echo "\n== 8. Not configured path ==\n";
$integ->set( 'stripe.test_secret_key', '' );
$client->set_transport( null );
$r = $checkout->start( $ctxs->for_user( $owner_id ), $tier, false );
$check( 'start() fails gracefully without keys', is_wp_error( $r ) && 'sp_stripe_not_configured' === $r->get_error_code() );

$restore();
$client->set_transport( null );
echo "\n== Summary: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
