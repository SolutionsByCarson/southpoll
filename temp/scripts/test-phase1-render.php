<?php
/**
 * test-phase1-render.php — Render the member dashboard sections, the admin memberships page, and the Phase 1 REST
 * endpoints from the CLI under specific users (no browser session needed). Uses the throwaway accounts created by
 * test-phase1-auth.php; run that first.
 *
 * Run: ./wp-run.sh test-phase1-render.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Admin\Pages\MembershipsPage;
use SouthPoll\Membership\Frontend\Controllers\DashboardController;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Routes;

$c     = sp_membership();
$pass  = 0; $fail = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };

global $wpdb;
$owner = $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
$buyer = $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-buyer-%@example.test' ORDER BY ID DESC LIMIT 1" );
$admin = $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='{$wpdb->prefix}capabilities' WHERE m.meta_value LIKE '%administrator%' ORDER BY u.ID LIMIT 1" );
if ( ! $owner || ! $buyer ) { die( "Run test-phase1-auth.php first to create test accounts.\n" ); }
echo "owner #{$owner}, buyer #{$buyer}, admin #{$admin}\n\n";

// Deterministic state for the owner's farm: membership "none", no pending claims (other suites change this).
$farm_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sp_farms WHERE owner_user_id = %d", $owner ) );
$mrepo   = $c->get( \SouthPoll\Membership\Memberships\MembershipRepository::class );
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'none', 'starts_at' => null, 'ends_at' => null, 'auto_renew' => 0, 'reminders_sent' => [], 'expired_at' => null ] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sp_payments WHERE farm_id = %d AND status = 'pending'", $farm_id ) );

$render_section = function ( int $uid, string $route ) use ( $c ): string {
	wp_set_current_user( $uid );
	$c->get( ContextProvider::class )->forget();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/members/' . $route . '/';
	$controller = $c->get( DashboardController::class );
	ob_start();
	try { $controller->render( new RouteMatch( $route, Routes::get( $route ) ) ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage(); }
	return (string) ob_get_clean();
};

echo "== Dashboard sections as OWNER (membership: none) ==\n";
$h = $render_section( (int) $owner, 'dashboard' );
$check( 'home renders with membership banner + farm card', str_contains( $h, 'sp-banner' ) && str_contains( $h, 'Finish your membership' ) && str_contains( $h, 'My farm' ), strlen( $h ) . ' bytes' );
$h = $render_section( (int) $owner, 'account-details' );
$check( 'account details: 4 plugin forms (profile, email, password, preferences)', substr_count( $h, 'name="sp_nonce"' ) === 4 && str_contains( $h, 'value="profile"' ) && str_contains( $h, 'value="password"' ) && str_contains( $h, 'value="preferences"' ), substr_count( $h, 'name="sp_nonce"' ) . ' nonce fields' );
$h = $render_section( (int) $owner, 'my-farm' );
$check( 'my farm: users manager present for owner', str_contains( $h, 'spFarmUsers' ) && str_contains( $h, 'Invite an account manager' ) );
$h = $render_section( (int) $owner, 'manage-membership' );
$check( 'manage membership: status card + activation card + claim form', str_contains( $h, 'Membership status' ) && str_contains( $h, 'sp-mm__buy' ) && str_contains( $h, 'value="claim"' ), strlen( $h ) . ' bytes' );
$check( 'manage membership: Stripe buttons shown only when configured', str_contains( $h, 'Pay online now' ) === $c->get( \SouthPoll\Membership\Payments\StripeClient::class )->configured() );

// T6.7 cancel card: "cancel" = stop automatic renewal; with auto-renew already off the button is disabled and the note says so.
$engine_r = $c->get( \SouthPoll\Membership\Memberships\StatusEngine::class ); $mrepo_r = $c->get( \SouthPoll\Membership\Memberships\MembershipRepository::class );
$farm_r   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sp_farms WHERE owner_user_id = %d", (int) $owner ) );
$engine_r->activate( $farm_r, $c->get( \SouthPoll\Membership\Memberships\TierRepository::class )->default(), [ 'source' => 'offline', 'actor' => 8, 'auto_renew' => false ] ); $c->get( ContextProvider::class )->forget();
$h = $render_section( (int) $owner, 'manage-membership' );
$check( 'cancel card (auto-renew off): disabled button + "nothing to cancel" note with the end date', str_contains( $h, 'There is nothing to cancel' ) && str_contains( $h, 'NOT set to renew automatically' ) && preg_match( '/<button[^>]*sp-btn--danger[^>]*\bdisabled\b/', $h ) && ! str_contains( $h, 'value="cancel"' ), strlen( $h ) . ' bytes' );
$engine_r->set_auto_renew( $mrepo_r->for_farm( $farm_r ), true ); $c->get( ContextProvider::class )->forget();
$h = $render_section( (int) $owner, 'manage-membership' );
$check( 'cancel card (auto-renew on): confirm form posting sp_action=cancel', str_contains( $h, 'value="cancel"' ) && str_contains( $h, 'SP.confirm' ) && str_contains( $h, 'stop automatic renewal' ) && ! str_contains( $h, 'There is nothing to cancel' ) );
$engine_r->set_auto_renew( $mrepo_r->for_farm( $farm_r ), false );
$mrepo_r->update( (int) $mrepo_r->for_farm( $farm_r )->id, [ 'status' => 'none', 'ends_at' => null, 'activated_at' => null ] ); $c->get( ContextProvider::class )->forget(); // back to "none" for the checks below

echo "\n== Dashboard sections as BUYER ==\n";
$h = $render_section( (int) $buyer, 'dashboard' );
$check( 'buyer home shows become-a-member banner', str_contains( $h, 'Become a member' ) );
$h = $render_section( (int) $buyer, 'manage-membership' );
$check( 'buyer membership page shows farm-name step', str_contains( $h, 'value="create_farm"' ) );

echo "\n== REST via rest_do_request ==\n";
wp_set_current_user( (int) $owner );
$c->get( ContextProvider::class )->forget();
$res = rest_do_request( new WP_REST_Request( 'GET', '/sp/v1/account/me' ) );
$check( 'GET account/me as owner', 200 === $res->get_status() && 'owner' === ( $res->get_data()['role'] ?? '' ), json_encode( $res->get_data() ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/sp/v1/farm-users' ) );
$check( 'GET farm-users refused while membership inactive (403)', 403 === $res->get_status(), (string) $res->get_status() );
$res = rest_do_request( new WP_REST_Request( 'GET', '/sp/v1/ping' ) );
$check( 'GET ping reports owner', 'owner' === ( $res->get_data()['role'] ?? '' ) );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/sp/v1/account/me' ) );
$check( 'GET account/me as guest → 401', 401 === $res->get_status() );

echo "\n== Admin memberships page as ADMIN ==\n";
wp_set_current_user( (int) $admin );
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$_GET["tab"] = "claims"; ob_start(); $c->get( MembershipsPage::class )->render(); $h = (string) ob_get_clean(); unset( $_GET["tab"] );
$check( 'claims page renders stats + record form', str_contains( $h, 'sp-stat__value' ) && str_contains( $h, 'Record' ), strlen( $h ) . ' bytes' );

// A throwaway editor (staff via sp_manage) with no farm — the site admin may own a farm of their own.
$stamp  = substr( (string) time(), -6 );
$editor = wp_insert_user( [ 'user_login' => "sp-editor-render-$stamp", 'user_email' => "sp-editor-render-$stamp@example.test", 'user_pass' => wp_generate_password( 20 ), 'role' => 'editor' ] );
$h = $render_section( (int) $editor, 'my-farm' );
$check( 'my farm as staff without a farm shows the notice instead of a fatal', str_contains( $h, 'No farm on this account' ) && str_contains( $h, 'southpoll-farms' ), strlen( $h ) . ' bytes' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_set_current_user( 0 );
wp_delete_user( (int) $editor );

echo "\n== S6: login-page release notice ==\n";
$settings_r   = $c->get( \SouthPoll\Membership\Core\Settings::class );
$notice_keep  = [ 'launch_notice_enabled' => $settings_r->get( 'launch_notice_enabled' ), 'launch_notice_until' => $settings_r->get( 'launch_notice_until' ), 'launch_notice_link_url' => $settings_r->get( 'launch_notice_link_url' ), 'launch_notice_link_label' => $settings_r->get( 'launch_notice_link_label' ) ];
$render_login = function () use ( $c ): string {
	wp_set_current_user( 0 ); $c->get( ContextProvider::class )->forget();
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/members/login/'; $_GET = [];
	ob_start();
	try { $c->get( \SouthPoll\Membership\Frontend\Controllers\AuthController::class )->render( new RouteMatch( 'login', Routes::get( 'login' ) ) ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage(); }
	return (string) ob_get_clean();
};
$settings_r->update( [ 'launch_notice_enabled' => true, 'launch_notice_until' => '', 'launch_notice_link_url' => 'https://southpoll.com/whats-new/', 'launch_notice_link_label' => 'What is new' ] );
$h = $render_login();
$check( 'notice on: heading, text with line break, link', str_contains( $h, 'sp-launch-notice' ) && str_contains( $h, (string) $settings_r->get( 'launch_notice_title' ) ) && str_contains( $h, '<br />' ) && str_contains( $h, 'href="https://southpoll.com/whats-new/">What is new</a>' ), strlen( $h ) . ' bytes' );
$settings_r->update( [ 'launch_notice_until' => wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ] );
$check( 'notice hides itself after the end date', ! str_contains( $render_login(), 'sp-launch-notice' ) );
$settings_r->update( [ 'launch_notice_until' => wp_date( 'Y-m-d' ) ] );
$check( 'notice still shows on the end date itself', str_contains( $render_login(), 'sp-launch-notice' ) );
$settings_r->update( [ 'launch_notice_enabled' => false, 'launch_notice_until' => '' ] );
$check( 'notice off: nothing rendered', ! str_contains( $render_login(), 'sp-launch-notice' ) );
$check( 'schema: textarea / url / date sanitised', [ 'a', null ] === \SouthPoll\Membership\Core\SettingsSchema::sanitize( 'launch_notice_text', " a \x00" ) && null === \SouthPoll\Membership\Core\SettingsSchema::sanitize( 'launch_notice_link_url', 'not a url' )[0] && [ '2026-12-31', null ] === \SouthPoll\Membership\Core\SettingsSchema::sanitize( 'launch_notice_until', '2026-12-31' ) && null === \SouthPoll\Membership\Core\SettingsSchema::sanitize( 'launch_notice_until', '2026-13-45' )[0] );
$settings_r->update( $notice_keep );

echo "\n== S7: dashboard welcome popup ==\n";
$popup_keep = [ 'launch_popup_enabled' => $settings_r->get( 'launch_popup_enabled' ), 'launch_popup_version' => $settings_r->get( 'launch_popup_version' ) ];
$meta_key   = \SouthPoll\Membership\Rest\AccountController::dismiss_meta_key( 'launch_popup' );
delete_user_meta( (int) $owner, $meta_key );
$settings_r->update( [ 'launch_popup_enabled' => true, 'launch_popup_version' => 'test-' . time() ] );
$h = $render_section( (int) $owner, 'dashboard' );
$check( 'popup payload embedded when enabled and not yet seen', str_contains( $h, 'id="sp-launch-popup"' ) && str_contains( $h, (string) $settings_r->get( 'launch_popup_version' ) ) && str_contains( $h, (string) $settings_r->get( 'launch_popup_title' ) ) );
wp_set_current_user( (int) $owner );
$req = new WP_REST_Request( 'POST', '/sp/v1/account/dismiss' ); $req->set_body_params( [ 'key' => 'launch_popup', 'value' => (string) $settings_r->get( 'launch_popup_version' ) ] );
$res = rest_do_request( $req );
$check( 'POST account/dismiss stores the version', 200 === $res->get_status() && (string) $settings_r->get( 'launch_popup_version' ) === (string) get_user_meta( (int) $owner, $meta_key, true ), (string) $res->get_status() );
$check( 'popup not embedded again after dismissal', ! str_contains( $render_section( (int) $owner, 'dashboard' ), 'id="sp-launch-popup"' ) );
$settings_r->update( [ 'launch_popup_version' => 'test-bump-' . time() ] );
$check( 'new version → popup shows again', str_contains( $render_section( (int) $owner, 'dashboard' ), 'id="sp-launch-popup"' ) );
$settings_r->update( [ 'launch_popup_enabled' => false ] );
$check( 'popup off → nothing embedded', ! str_contains( $render_section( (int) $owner, 'dashboard' ), 'id="sp-launch-popup"' ) );
$req = new WP_REST_Request( 'POST', '/sp/v1/account/dismiss' ); $req->set_body_params( [ 'key' => '' ] );
$check( 'dismiss without a key → 422', 422 === rest_do_request( $req )->get_status() );
delete_user_meta( (int) $owner, $meta_key );
$settings_r->update( $popup_keep );

echo "\n== S8: feedback button + submissions ==\n";
$fb_keep = [ 'feedback_enabled' => $settings_r->get( 'feedback_enabled' ), 'feedback_emails' => $settings_r->get( 'feedback_emails' ), 'recaptcha_enabled' => $settings_r->get( 'recaptcha_enabled' ) ];
$settings_r->update( [ 'recaptcha_enabled' => false ] ); // guest checks below post no captcha token
$fb_sent = [];
$fb_hook = function ( $msg ) use ( &$fb_sent ) { if ( 'admin.feedback' === $msg->key() ) { $fb_sent[] = [ 'to' => $msg->recipients(), 'subject' => $msg->subject(), 'reply' => $msg->reply_to() ]; return false; } return $msg; };
add_filter( 'sp/email/before_send', $fb_hook );
$fb_repo = $c->get( \SouthPoll\Membership\Feedback\FeedbackRepository::class );
$settings_r->update( [ 'feedback_enabled' => false ] );
$check( 'button absent while feedback is off', ! str_contains( $render_login(), 'sp-feedback-fab' ) && ! str_contains( $render_section( (int) $owner, 'dashboard' ), 'sp-feedback-fab' ) );
$settings_r->update( [ 'feedback_enabled' => true, 'feedback_emails' => [ 'fb-team@example.test' ] ] );
$h = $render_login();
$check( 'login page: button + guest form (name/e-mail fields, honeypot, no upload)', str_contains( $h, 'sp-feedback-fab' ) && str_contains( $h, 'id="sp-feedback-template"' ) && str_contains( $h, 'name="name"' ) && str_contains( $h, 'name="email"' ) && str_contains( $h, \SouthPoll\Membership\Auth\Honeypot::FIELD ) && ! str_contains( $h, 'name="screenshot"' ) );
$h = $render_section( (int) $owner, 'dashboard' );
$check( 'dashboard: button + member form (prefilled sender, upload field)', str_contains( $h, 'sp-feedback-fab' ) && str_contains( $h, 'Sending as' ) && str_contains( $h, 'name="screenshot"' ) );
wp_set_current_user( (int) $owner ); $c->get( ContextProvider::class )->forget();
$fb_post = function ( array $body ) { $r = new WP_REST_Request( 'POST', '/sp/v1/feedback' ); $r->set_body_params( $body ); return rest_do_request( $r ); };
$res = $fb_post( [ 'message' => 'The publish button did nothing on my listing (render-suite test)', 'page_url' => home_url( '/members/my-listings/' ), 'user_agent' => 'SuiteBrowser/1.0', 'elapsed' => 1 ] );
$fb_id = (int) ( $res->get_data()['id'] ?? 0 );
$fb_row = $fb_id ? $fb_repo->find( $fb_id ) : null;
$check( 'member POST feedback → 200, row stored with account name/e-mail, page + browser', 200 === $res->get_status() && $fb_row && (int) $fb_row->user_id === (int) $owner && '' !== $fb_row->email && str_contains( (string) $fb_row->page_url, 'my-listings' ) && 'SuiteBrowser/1.0' === $fb_row->user_agent && 'new' === $fb_row->status, (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$check( 'feedback e-mail to the configured list, reply-to = sender', 1 === count( $fb_sent ) && [ 'fb-team@example.test' ] === $fb_sent[0]['to'] && str_contains( $fb_sent[0]['subject'], "#{$fb_id}" ) && $fb_sent[0]['reply'] === (string) $fb_row->email, wp_json_encode( $fb_sent ) );
$res = $fb_post( [ 'message' => 'x' ] );
$check( 'too-short message → 422 with field error', 422 === $res->get_status() && isset( $res->get_data()['data']['errors']['message'] ), (string) $res->get_status() );
wp_set_current_user( 0 ); $c->get( ContextProvider::class )->forget();
$res = $fb_post( [ 'name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Cannot log in, the page just reloads', 'elapsed' => 10, \SouthPoll\Membership\Auth\Honeypot::FIELD => 'http://spam' ] );
$check( 'guest honeypot filled → 400', 400 === $res->get_status(), (string) $res->get_status() );
$res = $fb_post( [ 'name' => '', 'email' => 'not-an-email', 'message' => 'Cannot log in, the page just reloads', 'elapsed' => 10 ] );
$check( 'guest without name / bad e-mail → 422 (name + email errors)', 422 === $res->get_status() && isset( $res->get_data()['data']['errors']['name'], $res->get_data()['data']['errors']['email'] ), (string) $res->get_status() );
$res = $fb_post( [ 'name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Cannot log in, the page just reloads', 'elapsed' => 1 ] );
$check( 'guest too fast → 400', 400 === $res->get_status(), (string) $res->get_status() );
$res = $fb_post( [ 'name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Cannot log in, the page just reloads', 'elapsed' => 10 ] );
$fb_guest = (int) ( $res->get_data()['id'] ?? 0 );
$check( 'guest valid → 200, stored without user id', 200 === $res->get_status() && $fb_guest && 0 === (int) $fb_repo->find( $fb_guest )->user_id && 2 === count( $fb_sent ), (string) $res->get_status() );
$up = new WP_REST_Request( 'POST', '/sp/v1/feedback/upload' );
$check( 'guest cannot upload a screenshot (401)', 401 === rest_do_request( $up )->get_status() );
$settings_r->update( [ 'feedback_enabled' => false ] );
$res = $fb_post( [ 'name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Cannot log in, the page just reloads', 'elapsed' => 10 ] );
$check( 'feedback off → 403', 403 === $res->get_status(), (string) $res->get_status() );
foreach ( [ $fb_id, $fb_guest ] as $del ) { if ( $del ) { $fb_repo->delete( $del ); } }
remove_filter( 'sp/email/before_send', $fb_hook );
$settings_r->update( $fb_keep );

echo "\n== S10: Help Center ==\n";
$h = $render_section( (int) $owner, 'help' );
$check( 'owner: Help Center renders all eight sections with live values', str_contains( $h, 'id="help-account"' ) && str_contains( $h, 'id="help-contact"' ) && 8 === substr_count( $h, 'class="sp-card sp-help__section"' ) && str_contains( $h, 'runs for ' . (int) $settings_r->get( 'listing_duration_days', 60 ) . ' days' ) && str_contains( $h, (string) $settings_r->get( 'admin_contact.email' ) ) && str_contains( $h, 'sp-help-item' ) && ! str_contains( $h, 'Sample stat' ), strlen( $h ) . ' bytes' );
$check( 'round 1: "pay online" answer explains that paying early extends the current term', str_contains( $h, 'paying early never costs you time' ) );
$h = $render_section( (int) $buyer, 'help' );
$check( 'buyer: Help Center renders with the buyer note', str_contains( $h, 'id="help-favorites"' ) && str_contains( $h, 'Buyer accounts can browse' ) );

echo "\n== Summary: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
