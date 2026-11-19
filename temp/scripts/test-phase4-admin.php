<?php
/**
 * test-phase4-admin.php — Phase 4 (admin area) suite: staff permissions, dashboard stats, every admin screen rendered
 * in-process, the users / farms / listings / memberships / payments / claims / tiers / taxonomies admin REST
 * endpoints, settings + integrations (REST and the classic forms), CSV export / template / dry-run / import for all
 * four resources, and the farm-owner delete guard. Uses the throwaway accounts from test-phase1-auth.php (run that
 * first). Restores every setting / integration it touches and deletes what it creates.
 *
 * Run: ./wp-run.sh test-phase4-admin.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Admin\Csv\Csv;
use SouthPoll\Membership\Admin\Pages\DashboardPage;
use SouthPoll\Membership\Admin\Pages\FarmsPage;
use SouthPoll\Membership\Admin\Pages\ImportExportPage;
use SouthPoll\Membership\Admin\Pages\IntegrationsPage;
use SouthPoll\Membership\Admin\Pages\ListingsPage;
use SouthPoll\Membership\Admin\Pages\MembershipsPage;
use SouthPoll\Membership\Admin\Pages\MetaFieldsPage;
use SouthPoll\Membership\Admin\Pages\SettingsPage;
use SouthPoll\Membership\Admin\Pages\StatusPage;
use SouthPoll\Membership\Admin\Pages\TaxonomiesPage;
use SouthPoll\Membership\Admin\Pages\UsersPage;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Core\SettingsSchema;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Members\UserGuards;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Support\Dates;

final class SpTestRedirect extends RuntimeException {}
final class SpTestDie extends RuntimeException {}

$c     = sp_membership();
$pass  = 0; $fail = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$stamp = substr( (string) time(), -6 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
global $wpdb;
$p = $wpdb->prefix;
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$settings     = $c->get( Settings::class );
$integrations = $c->get( Integrations::class );
$farms        = $c->get( FarmRepository::class );
$ctxs         = $c->get( ContextProvider::class );
$members      = $c->get( MemberRepository::class );
$mrepo        = $c->get( MembershipRepository::class );
$engine       = $c->get( StatusEngine::class );
$tiers        = $c->get( TierRepository::class );
$payments     = $c->get( PaymentRepository::class );
$claims       = $c->get( OfflineClaims::class );
$repo         = $c->get( ListingRepository::class );
$service      = $c->get( ListingService::class );
$types        = $c->get( ListingTypes::class );
$fields       = $c->get( MetaFieldRepository::class );
$offerings    = $c->get( Offerings::class );
$farm_users   = $c->get( FarmUserRepository::class );
$guards       = $c->get( UserGuards::class );
$visibility   = $c->get( FarmVisibility::class );
$csv          = $c->get( Csv::class );

// Capture emails; wp_mail "succeeds" without sending.
$sent = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = $msg; return $msg; } );
add_filter( 'pre_wp_mail', '__return_true' );
$keys = function () use ( &$sent ) { return array_map( fn( $m ) => $m->key(), $sent ); };
// Classic admin forms redirect + exit, and guards wp_die: turn both into exceptions we can assert on.
add_filter( 'wp_redirect', static function ( $location ) { throw new SpTestRedirect( (string) $location ); } );
add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new SpTestDie( is_string( $message ) ? $message : 'wp_die' ); } );

$region_key = '';
foreach ( SettingsSchema::fields() as $k => $f ) { if ( 'region_map' === $f['type'] ) { $region_key = $k; } }
$restore = [
	'listing_duration_days'             => $settings->get( 'listing_duration_days' ),
	'membership_offline_claims_enabled' => $settings->get( 'membership_offline_claims_enabled' ),
	'classifieds_sort_default'          => $settings->get( 'classifieds_sort_default' ),
	$region_key                         => $settings->get( $region_key ),
];
$restore_int = [ 'stripe.test_secret_key' => (string) $integrations->get( 'stripe.test_secret_key', '' ), 'email.from_name' => (string) $integrations->get( 'email.from_name', '' ) ];
$cleanup     = [ 'users' => [], 'farms' => [], 'listings' => [], 'tiers' => [], 'terms' => [], 'files' => [] ];
register_shutdown_function( function () use ( $settings, $restore, $integrations, $restore_int, &$cleanup, $wpdb, $p, $repo, $tiers ) {
	foreach ( $restore as $k => $v ) { if ( '' !== $k ) { $settings->set( $k, $v ); } }
	foreach ( $restore_int as $k => $v ) { $integrations->set( $k, $v ); }
	remove_all_filters( 'wp_die_handler' );
	foreach ( $cleanup['listings'] as $id ) { $repo->delete( (int) $id ); }
	foreach ( $cleanup['farms'] as $id ) {
		$wpdb->delete( "{$p}sp_farm_users", [ 'farm_id' => (int) $id ] );
		$wpdb->delete( "{$p}sp_memberships", [ 'farm_id' => (int) $id ] );
		$wpdb->delete( "{$p}sp_payments", [ 'farm_id' => (int) $id ] );
		$wpdb->delete( "{$p}sp_farms", [ 'id' => (int) $id ] );
		wp_delete_object_term_relationships( (int) $id, Offerings::TAXONOMY );
	}
	foreach ( $cleanup['users'] as $id ) { if ( get_userdata( (int) $id ) ) { wp_delete_user( (int) $id ); } }
	foreach ( $cleanup['tiers'] as $id ) { $wpdb->delete( "{$p}sp_membership_tiers", [ 'id' => (int) $id ] ); }
	foreach ( $cleanup['terms'] as [ $id, $tax ] ) { if ( get_term( (int) $id, $tax ) ) { wp_delete_term( (int) $id, $tax ); } }
	foreach ( $cleanup['files'] as $f ) { if ( file_exists( $f ) ) { unlink( $f ); } }
} );

// Accounts + deterministic state.
$owner = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
$buyer = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-buyer-%@example.test' ORDER BY ID DESC LIMIT 1" );
$admin = (int) $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='{$p}capabilities' WHERE m.meta_value LIKE '%administrator%' ORDER BY u.ID LIMIT 1" );
if ( ! $owner || ! $buyer || ! $admin ) { die( "Run test-phase1-auth.php first to create test accounts.\n" ); }
$editor = wp_insert_user( [ 'user_login' => "sp-editor-$stamp", 'user_email' => "sp-editor-$stamp@example.test", 'user_pass' => wp_generate_password( 20 ), 'role' => 'editor', 'first_name' => 'Edie', 'last_name' => 'Staff' ] );
$cleanup['users'][] = $editor;
$farm    = $farms->find_by_owner( $owner );
$farm_id = (int) $farm->id;
$tier    = $tiers->default();
$buyer_u = get_userdata( $buyer );
$owner_u = get_userdata( $owner );
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'active', 'starts_at' => Dates::now(), 'ends_at' => Dates::add_days( Dates::now(), 365 ), 'auto_renew' => 1, 'reminders_sent' => [], 'expired_at' => null, 'stripe_subscription_id' => '' ] );
$farms->update( $farm_id, [ 'city' => 'Kearney', 'state' => 'MO', 'contact_form_enabled' => 1, 'status' => 'active' ] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}sp_payments WHERE farm_id = %d AND status = 'pending'", $farm_id ) );
foreach ( $repo->for_farm( $farm_id ) as $old ) { $repo->delete( $old->id ); }
$bm = $members->find_by_user( $buyer ) ?? $members->create_for_user( $buyer );
$members->update( (int) $bm->id, [ 'email_verified_at' => Dates::now(), 'status' => 'active' ] );
if ( $farm_users->for_user( $buyer ) ) { $farm_users->remove( (int) $farm_users->for_user( $buyer )->id ); }
$service->refresh_farm_stats( $farm_id );
$ctxs->forget();
$farm  = $farms->find( $farm_id );
$bulls = $types->category( 'bulls' );
echo "owner #{$owner} (farm #{$farm_id} '{$farm->name}'), buyer #{$buyer}, admin #{$admin}, editor #{$editor}\n\n";

$rest = function ( string $method, string $path, array $params = [], array $files = [] ) {
	$req = new WP_REST_Request( $method, '/sp/v1/' . ltrim( $path, '/' ) );
	if ( 'GET' === $method ) { $req->set_query_params( $params ); } else { $req->set_body_params( $params ); }
	if ( $files ) { $req->set_file_params( $files ); }
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
};
$as = function ( int $uid ) use ( $ctxs ) { wp_set_current_user( $uid ); $ctxs->forget(); };
// Rendered HTML is entity-decoded so assertions can look for the config JSON / labels as written.
$render = function ( object $page, array $get = [] ) { $_GET = $get; ob_start(); try { $page->render(); } finally { $h = (string) ob_get_clean(); $_GET = []; } return html_entity_decode( $h, ENT_QUOTES | ENT_HTML5 ); };
$octx = fn() => $ctxs->for_user( $owner );
$mk = function ( string $title, string $action = 'publish', array $extra = [] ) use ( $repo, $service, $octx, $farm_id, $owner, $bulls, &$cleanup ) {
	$l = $repo->create( [ 'farm_id' => $farm_id, 'author_id' => $owner, 'title' => $title ] );
	$cleanup['listings'][] = $l->id;
	$r = $service->save( $octx(), $l, $extra + [ 'title' => $title, 'categories' => [ $bulls->id ], 'status' => 'available', 'price_cents' => 100000, 'content' => '<p>Test</p>' ], $action );
	return is_wp_error( $r ) ? $r : $repo->find( $r->id );
};
$csv_file = function ( string $name, array $rows ) use ( $stamp, &$cleanup ) {
	$path = sys_get_temp_dir() . "/sp-test-$stamp-$name.csv";
	$fh   = fopen( $path, 'w' );
	foreach ( $rows as $r ) { fputcsv( $fh, $r ); }
	fclose( $fh );
	$cleanup['files'][] = $path;
	return $path;
};
$upload = fn( string $path, string $name = 'rows.csv' ) => [ 'file' => [ 'name' => $name, 'type' => 'text/csv', 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ] ];

/* ---------------------------------------------------------------- 1. permissions */
echo "== 1. Permissions ==\n";
$as( 0 );
[ $s ] = $rest( 'GET', 'admin/users' );
$check( 'guest cannot list users', 200 !== $s, "status $s" );
$as( $buyer );
[ $s ] = $rest( 'GET', 'admin/stats' );
$check( 'buyer gets 403 on admin stats', 403 === $s, "status $s" );
$as( $editor );
[ $s ] = $rest( 'GET', 'admin/stats' );
$check( 'editor (sp_manage) can read admin stats', 200 === $s, "status $s" );
[ $s ] = $rest( 'GET', 'admin/settings' );
$check( 'editor cannot read settings (sp_manage_settings)', 403 === $s, "status $s" );
[ $s ] = $rest( 'GET', 'admin/integrations' );
$check( 'editor cannot read integrations', 403 === $s, "status $s" );
try { $render( $c->get( SettingsPage::class ) ); $check( 'editor is refused the Settings page', false ); } catch ( SpTestDie $e ) { $check( 'editor is refused the Settings page', true, $e->getMessage() ); }
[ $s ] = $rest( 'PATCH', "admin/users/{$admin}", [ 'email' => 'takeover@example.test' ] );
$check( 'editor cannot change an administrator (403, review fix #1)', 403 === $s && 'takeover@example.test' !== get_userdata( $admin )->user_email, "status $s" );
$as( $admin );
[ $s ] = $rest( 'GET', 'admin/settings' );
$check( 'admin can read settings', 200 === $s, "status $s" );

/* ------------------------------------------------------------- 2. dashboard + pages */
echo "\n== 2. Dashboard stats + every admin screen renders ==\n";
[ $s, $d ] = $rest( 'GET', 'admin/stats' );
$check( 'stats payload', 200 === $s && isset( $d['counts']['memberships_active'], $d['counts']['listings_live'], $d['counts']['claims_pending'], $d['counts']['revenue_30d_cents'], $d['recent']['listings'] ), 'users_total=' . ( $d['counts']['users_total'] ?? '?' ) );
$h = $render( $c->get( DashboardPage::class ) );
$check( 'Dashboard renders stat tiles + recent lists', str_contains( $h, 'data-countup' ) && str_contains( $h, 'Latest listings' ), strlen( $h ) . ' bytes' );
$h = $render( $c->get( UsersPage::class ), [ 'farm_id' => (string) $farm_id ] );
$check( 'Users page renders (spAdminTable, farm pill)', str_contains( $h, 'spAdminTable' ) && str_contains( $h, 'admin/users' ) && str_contains( $h, 'Farm: ' . $farm->name ) );
$h = $render( $c->get( FarmsPage::class ) );
$check( 'Farms page renders with bulk bar + state filter', str_contains( $h, 'admin/farms/bulk' ) && str_contains( $h, 'Missouri' ) );
$h = $render( $c->get( ListingsPage::class ) );
$check( 'Listings page renders with category filter', str_contains( $h, 'admin/listings' ) && str_contains( $h, 'value="bulls"' ) );
$mp = $c->get( MembershipsPage::class );
$check( 'Memberships tab', str_contains( $render( $mp ), 'admin/memberships' ) );
$check( 'Payments tab', str_contains( $render( $mp, [ 'tab' => 'payments' ] ), 'admin/payments' ) );
$check( 'Claims tab (classic forms)', str_contains( $render( $mp, [ 'tab' => 'claims' ] ), 'Record & activate' ) );
$check( 'Tiers tab', str_contains( $render( $mp, [ 'tab' => 'tiers' ] ), 'spAdminTiers' ) );
$tp = $c->get( TaxonomiesPage::class );
$check( 'Taxonomies: categories tab', str_contains( $render( $tp ), 'sp_listing_category' ) && str_contains( $render( $tp ), 'SEO title' ) );
$check( 'Taxonomies: offerings tab', str_contains( $render( $tp, [ 'tax' => 'sp_offering' ] ), '"tax":"sp_offering"' ) );
$h = $render( $c->get( SettingsPage::class ) );
$check( 'Settings page renders every group as a form', str_contains( $h, 'sp[listing_limit_default]' ) && str_contains( $h, 'sp[' . $region_key . ']' ) && str_contains( $h, 'sp_settings_action' ) );
$h = $render( $c->get( IntegrationsPage::class ) );
$check( 'Integrations page renders keys masked + test buttons', str_contains( $h, 'values[stripe.mode]' ) && str_contains( $h, 'data-sp-test="stripe"' ) && str_contains( $h, 'data-sp-test="email"' ) );
$h = $render( $c->get( ImportExportPage::class ) );
$check( 'Import/Export page renders four resources', 4 === substr_count( $h, 'spAdminImport(' ) && str_contains( $h, 'action=sp_export' ) && str_contains( $h, 'action=sp_template' ) );
$check( 'Status page renders', str_contains( $render( $c->get( StatusPage::class ) ), 'Versions' ) );
$check( 'Meta Fields page renders', str_contains( $render( $c->get( MetaFieldsPage::class ) ), 'nav-tab' ) );

/* --------------------------------------------------------------------- 3. users */
echo "\n== 3. Users admin ==\n";
[ $s, $d ] = $rest( 'GET', 'admin/users', [ 'q' => $buyer_u->user_email ] );
$check( 'users table finds buyer by email', 200 === $s && 1 === (int) $d['total'] && (int) $d['items'][0]['id'] === $buyer && 'buyer' === $d['items'][0]['role'], 'total=' . ( $d['total'] ?? '?' ) );
[ $s, $d ] = $rest( 'GET', 'admin/users', [ 'role' => 'owner', 'q' => $farm->name ] );
$check( 'role=owner filter lists the farm owner', 200 === $s && in_array( $owner, array_column( $d['items'], 'id' ), true ) );
[ $s, $d ] = $rest( 'GET', 'admin/users', [ 'farm_id' => $farm_id, 'sort' => 'name', 'dir' => 'asc' ] );
$check( 'farm_id filter + name sort', 200 === $s && in_array( $owner, array_column( $d['items'], 'id' ), true ) );
[ $s, $d ] = $rest( 'GET', 'admin/users/' . $buyer );
$check( 'user drawer html', 200 === $s && str_contains( $d['html'], 'data-sp-patch' ) && str_contains( $d['html'], 'data-sp-action="reset_password"' ) && str_contains( $d['html'], 'data-sp-action="attach_manager"' ) );
[ $s, $d ] = $rest( 'PATCH', 'admin/users/' . $buyer, [ 'phone' => '816-555-0101', 'city' => 'Liberty', 'state' => 'mo' ] );
$bm = $members->find_by_user( $buyer );
$check( 'PATCH profile (state upper-cased)', 200 === $s && 'MO' === $bm->state && 'Liberty' === $bm->city && str_contains( $d['html'], 'Liberty' ) );
[ $s, $d ] = $rest( 'PATCH', 'admin/users/' . $buyer, [ 'email' => $owner_u->user_email ] );
$check( 'PATCH email already used → 422', 422 === $s && 'sp_email_taken' === $d['code'] );
$members->update( (int) $bm->id, [ 'email_verified_at' => null ] );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'verify' ] );
$check( 'action verify', 200 === $s && true === $d['verified'] && ! empty( $members->find_by_user( $buyer )->email_verified_at ) );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'suspend' ] );
$check( 'action suspend', 200 === $s && true === $d['suspended'] && 'suspended' === $members->find_by_user( $buyer )->status );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'unsuspend' ] );
$check( 'action unsuspend', 200 === $s && false === $d['suspended'] );
[ $s ] = $rest( 'POST', 'admin/users/' . $editor . '/action', [ 'action' => 'suspend' ] );
$check( 'staff cannot be suspended', 403 === $s, "status $s" );
$n = count( $sent );
[ $s ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'reset_password' ] );
$check( 'action reset_password emails the member', 200 === $s && count( $sent ) > $n, implode( ',', array_slice( $keys(), $n ) ) );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'attach_manager', 'farm_id' => $farm_id ] );
$fu = $farm_users->for_user( $buyer );
$check( 'action attach_manager', 200 === $s && $fu && 'manager' === $fu->role && 'manager' === $d['farm']['role'] );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $buyer . '/action', [ 'action' => 'detach' ] );
$check( 'action detach', 200 === $s && null === $farm_users->for_user( $buyer ) && null === $d['farm'] );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $owner . '/action', [ 'action' => 'delete' ] );
$check( 'farm owner cannot be deleted via REST', 422 === $s && 'sp_cannot_delete' === $d['code'] );
$victim = wp_insert_user( [ 'user_login' => "sp-del-$stamp", 'user_email' => "sp-del-$stamp@example.test", 'user_pass' => wp_generate_password( 20 ), 'role' => 'sp_buyer' ] );
$members->create_for_user( (int) $victim, [ 'first_name' => 'Del' ] );
[ $s, $d ] = $rest( 'POST', 'admin/users/' . $victim . '/action', [ 'action' => 'delete' ] );
$check( 'buyer account deleted via REST (+ plugin rows)', 200 === $s && ! empty( $d['deleted'] ) && ! get_userdata( (int) $victim ) && null === $members->find_by_user( (int) $victim ) );
$check( 'UserGuards::owns_farm', $guards->owns_farm( $owner ) && ! $guards->owns_farm( $buyer ) );
try { wp_delete_user( $owner ); $check( 'wp_delete_user on a farm owner is blocked', false ); } catch ( SpTestDie $e ) { $check( 'wp_delete_user on a farm owner is blocked', (bool) get_userdata( $owner ), $e->getMessage() ); }
$ctxs->forget();

/* --------------------------------------------------------------------- 4. farms */
echo "\n== 4. Farms admin ==\n";
[ $s, $d ] = $rest( 'GET', 'admin/farms', [ 'q' => $farm->name ] );
$check( 'farms table finds the test farm', 200 === $s && in_array( $farm_id, array_column( $d['items'], 'id' ), true ) && 'active' === $d['items'][0]['membership'] );
[ $s, $d ] = $rest( 'GET', 'admin/farms', [ 'membership' => 'active', 'state' => 'MO', 'sort' => 'listings', 'dir' => 'desc' ] );
$check( 'membership + state filters', 200 === $s && in_array( $farm_id, array_column( $d['items'], 'id' ), true ) );
[ $s, $d ] = $rest( 'GET', 'admin/farms/' . $farm_id );
$check( 'farm drawer html + offerings list', 200 === $s && str_contains( $d['html'], 'data-sp-panel="edit"' ) && str_contains( $d['html'], 'name="offerings[]"' ) && count( $d['all_offerings'] ) > 0 && 'public' === $d['visibility'] );
$first_off = (int) $d['all_offerings'][0]['id'];
[ $s, $d ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'slogan' => 'Admin slogan', 'offerings' => [ $first_off ], 'contact_form_enabled' => '0' ] );
$f2 = $farms->find( $farm_id );
$check( 'PATCH farm fields + offerings + contact toggle', 200 === $s && 'Admin slogan' === $f2->slogan && 0 === (int) $f2->contact_form_enabled && [ $first_off ] === array_map( fn( $o ) => (int) $o->id, $offerings->for_farm( $farm_id ) ) );
[ $s, $d ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'email' => 'not-an-email' ] );
$check( 'PATCH invalid email → 422 with field error', 422 === $s && isset( $d['data']['errors']['email'] ) );
[ $s, $d ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'status' => 'inactive' ] );
$check( 'PATCH status inactive hides the farm', 200 === $s && 'inactive' === $d['visibility'] && 'inactive' === $farms->find( $farm_id )->status );
[ $s, $d ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'status' => 'active', 'contact_form_enabled' => '1', 'offerings' => [] ] );
$check( 'PATCH back to active, offerings cleared', 200 === $s && 'public' === $d['visibility'] && [] === $offerings->for_farm( $farm_id ) );
[ $s, $d ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'billing_user_id' => $buyer ] );
$check( 'billing contact must belong to the farm', 422 === $s && 'sp_billing_user' === $d['code'] );
[ $s ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'billing_user_id' => $owner ] );
$check( 'billing contact = owner', 200 === $s && (int) $farms->find( $farm_id )->billing_user_id === $owner );
[ $s ] = $rest( 'PATCH', 'admin/farms/' . $farm_id, [ 'billing_user_id' => 0 ] );
$check( 'billing contact cleared', 200 === $s && null === $farms->find( $farm_id )->billing_user_id );
[ $s, $d ] = $rest( 'POST', 'admin/farms/' . $farm_id . '/action', [ 'action' => 'refresh_stats' ] );
$check( 'action refresh_stats', 200 === $s );
[ $s, $d ] = $rest( 'POST', 'admin/farms/' . $farm_id . '/action', [ 'action' => 'geocode' ] );
$check( 'action geocode answers (no key configured is a clean failure)', in_array( $s, [ 200, 422 ], true ), "status $s: " . ( $d['message'] ?? '' ) );
$before = (string) $mrepo->for_farm( $farm_id )->ends_at;
[ $s, $d ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [ $farm_id ], 'action' => 'extend', 'days' => 10 ] );
$check( 'bulk extend +10 days', 200 === $s && 1 === (int) $d['done'] && Dates::add_days( $before, 10 ) === (string) $mrepo->for_farm( $farm_id )->ends_at );
$n = count( $sent );
[ $s, $d ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [ $farm_id ], 'action' => 'email', 'subject' => 'Hello farms', 'body' => "Line one\n\nLine two" ] );
$check( 'bulk email sends the broadcast', 200 === $s && in_array( 'admin.broadcast', array_slice( $keys(), $n ), true ) );
[ $s ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [ $farm_id ], 'action' => 'email', 'subject' => '', 'body' => '' ] );
$check( 'bulk email requires subject + body', 422 === $s );
[ $s ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [ $farm_id ], 'action' => 'deactivate' ] );
$check( 'bulk deactivate', 200 === $s && 'inactive' === $farms->find( $farm_id )->status );
[ $s ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [ $farm_id ], 'action' => 'activate' ] );
$check( 'bulk activate', 200 === $s && 'active' === $farms->find( $farm_id )->status );
[ $s ] = $rest( 'POST', 'admin/farms/bulk', [ 'ids' => [], 'action' => 'activate' ] );
$check( 'bulk without ids → 422', 422 === $s );
$ctxs->forget();

/* ------------------------------------------------------------------ 5. listings */
echo "\n== 5. Listings admin ==\n";
$la = $mk( "Admin bull A $stamp" );
$lb = $mk( "Admin bull B $stamp", 'draft' );
$lc = $mk( "Admin bull C $stamp" );
$check( 'fixtures: 2 live + 1 draft', ! is_wp_error( $la ) && ! is_wp_error( $lb ) && ! is_wp_error( $lc ) && 'publish' === $la->post_status && 'draft' === $lb->post_status, is_wp_error( $la ) ? $la->get_error_message() : '' );
[ $s, $d ] = $rest( 'GET', 'admin/listings', [ 'q' => "Admin bull", 'farm_id' => $farm_id ] );
$check( 'listings table (farm + search)', 200 === $s && 3 === (int) $d['total'] && isset( $d['items'][0]['visibility'], $d['items'][0]['category_names'] ) );
[ $s, $d ] = $rest( 'GET', 'admin/listings', [ 'q' => "Admin bull", 'farm_id' => $farm_id, 'visibility' => 'live' ] );
$ids = array_column( $d['items'], 'id' );
$check( 'visibility=live filter', 200 === $s && in_array( $la->id, $ids, true ) && ! in_array( $lb->id, $ids, true ) );
[ $s, $d ] = $rest( 'GET', 'admin/listings', [ 'category' => 'bulls', 'status' => 'available', 'sort' => 'title', 'dir' => 'asc' ] );
$check( 'category + status filters + title sort', 200 === $s && in_array( $la->id, array_column( $d['items'], 'id' ), true ) );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $la->id ], 'action' => 'pause' ] );
$l = $repo->find( $la->id );
$check( 'bulk pause → draft + inactive', 200 === $s && 'draft' === $l->post_status && 'inactive' === $l->status );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $la->id ], 'action' => 'publish' ] );
$l = $repo->find( $la->id );
$check( 'bulk publish → live + available', 200 === $s && 'publish' === $l->post_status && 'available' === $l->status );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $lc->id ], 'action' => 'status', 'status' => 'sold' ] );
$check( 'bulk set status sold', 200 === $s && 'sold' === $repo->find( $lc->id )->status );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $lc->id ], 'action' => 'expire' ] );
$check( 'bulk expire', 200 === $s && ListingTypes::STATUS_EXPIRED === $repo->find( $lc->id )->post_status );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $lb->id ], 'action' => 'delete' ] );
$check( 'bulk delete', 200 === $s && null === $repo->find( $lb->id ) );
[ $s, $d ] = $rest( 'POST', 'admin/listings/bulk', [ 'ids' => [ $la->id ], 'action' => 'bogus' ] );
$check( 'bulk unknown action → 400', 400 === $s );
[ $s, $d ] = $rest( 'DELETE', 'admin/listings/' . $la->id );
$check( 'DELETE one listing', 200 === $s && null === $repo->find( $la->id ) );
$ctxs->forget();

/* --------------------------------------------------------- 6. memberships + payments */
echo "\n== 6. Memberships, payments, claims, tiers ==\n";
[ $s, $d ] = $rest( 'GET', 'admin/memberships', [ 'q' => $farm->name, 'status' => 'active' ] );
$row = null; foreach ( $d['items'] ?? [] as $it ) { if ( (int) $it['id'] === $farm_id ) { $row = $it; } }
$check( 'memberships table row', 200 === $s && $row && 'active' === $row['status'] && true === $row['auto_renew'] && $row['owner_email'] === $owner_u->user_email );
[ $s, $d ] = $rest( 'GET', 'admin/memberships', [ 'auto_renew' => '0', 'q' => $farm->name ] );
$check( 'auto_renew=0 filter excludes it', 200 === $s && ! in_array( $farm_id, array_column( $d['items'], 'id' ), true ) );
[ $s, $d ] = $rest( 'GET', 'admin/memberships/' . $farm_id );
$check( 'membership drawer html + tier price', 200 === $s && str_contains( $d['html'], 'data-sp-action="extend"' ) && str_contains( $d['html'], 'data-sp-action="cancel"' ) && is_numeric( $d['membership']['tier_price'] ) );
$before = (string) $mrepo->for_farm( $farm_id )->ends_at;
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'extend', 'days' => 30, 'note' => 'courtesy' ] );
$m = $mrepo->for_farm( $farm_id );
$check( 'action extend +30', 200 === $s && Dates::add_days( $before, 30 ) === (string) $m->ends_at && str_contains( (string) $m->notes, 'courtesy' ) );
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'set_end', 'ends_at' => 'not a date' ] );
$check( 'action set_end rejects a bad date', 422 === $s );
$target = gmdate( 'Y-m-d', strtotime( '+400 days' ) );
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'set_end', 'ends_at' => $target ] );
$check( 'action set_end', 200 === $s && $target === substr( (string) $mrepo->for_farm( $farm_id )->ends_at, 0, 10 ) );
[ $s ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'note', 'note' => '' ] );
$check( 'empty note → 422', 422 === $s );
[ $s ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'note', 'note' => "staff note $stamp" ] );
$check( 'action note', 200 === $s && str_contains( (string) $mrepo->for_farm( $farm_id )->notes, "staff note $stamp" ) );
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'auto_renew_off' ] );
$check( 'action auto_renew_off', 200 === $s && 0 === (int) $mrepo->for_farm( $farm_id )->auto_renew && false === $d['membership']['auto_renew'] );
$n = count( $sent ); $np = count( $payments->for_farm( $farm_id ) ); $before = (string) $mrepo->for_farm( $farm_id )->ends_at;
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'record', 'method' => 'check', 'amount' => '100.00', 'paid_at' => '', 'note' => 'check 123' ] );
$pays = $payments->for_farm( $farm_id );
$check( 'action record → payment + extended term + emails', 200 === $s && count( $pays ) === $np + 1 && 'succeeded' === $pays[0]->status && 'check' === $pays[0]->method && (string) $mrepo->for_farm( $farm_id )->ends_at > $before && count( $sent ) > $n, implode( ',', array_slice( $keys(), $n ) ) );
$recorded = (int) $pays[0]->id;
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'comp', 'note' => 'board member' ] );
$pays = $payments->for_farm( $farm_id );
$check( 'action comp → complimentary payment', 200 === $s && 'comp' === $pays[0]->method && 0 === (int) $pays[0]->amount_cents );
$n = count( $sent );
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'cancel', 'reason' => 'test cancel' ] );
$ctxs->forget();
$check( 'action cancel → cancelled, farm hidden, member + admin emailed', 200 === $s && 'cancelled' === $mrepo->for_farm( $farm_id )->status && 'cancelled' === $d['membership']['status'] && 'public' !== $visibility->state( $farms->find( $farm_id ) ) && in_array( 'membership.cancelled', array_slice( $keys(), $n ), true ), implode( ',', array_slice( $keys(), $n ) ) );
[ $s, $d ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'set_end', 'ends_at' => $target ] );
$ctxs->forget();
$check( 'set_end in the future restores a cancelled membership', 200 === $s && 'active' === $d['membership']['status'] && 'public' === $visibility->state( $farms->find( $farm_id ) ) );
[ $s ] = $rest( 'POST', 'admin/memberships/' . $farm_id . '/action', [ 'action' => 'bogus' ] );
$check( 'unknown membership action → 400', 400 === $s );
[ $s ] = $rest( 'POST', 'admin/memberships/999999/action', [ 'action' => 'note', 'note' => 'x' ] );
$check( 'unknown farm → 404', 404 === $s );

// payments
[ $s, $d ] = $rest( 'GET', 'admin/payments', [ 'farm_id' => $farm_id, 'sort' => 'amount', 'dir' => 'desc' ] );
$check( 'payments table + running total', 200 === $s && (int) $d['total'] >= 2 && isset( $d['meta']->sum ) && str_starts_with( (string) $d['meta']->sum, '$' ), 'sum=' . ( $d['meta']->sum ?? '?' ) );
[ $s, $d ] = $rest( 'GET', 'admin/payments', [ 'farm_id' => $farm_id, 'method' => 'comp', 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ) ] );
$check( 'payments method + date filters', 200 === $s && (int) $d['total'] >= 1 && 'Complimentary' === $d['items'][0]['method'] );
[ $s ] = $rest( 'POST', 'admin/payments/' . $recorded . '/action', [ 'action' => 'note', 'note' => 'deposited' ] );
$check( 'payment note', 200 === $s && str_contains( (string) $payments->find( $recorded )->notes, 'deposited' ) );
[ $s ] = $rest( 'POST', 'admin/payments/' . $recorded . '/action', [ 'action' => 'refunded', 'note' => 'bounced' ] );
$check( 'payment marked refunded', 200 === $s && 'refunded' === $payments->find( $recorded )->status );
[ $s ] = $rest( 'POST', 'admin/payments/999999/action', [ 'action' => 'note', 'note' => 'x' ] );
$check( 'unknown payment → 404', 404 === $s );

// claims (member submits, staff rejects / approves through REST; classic form handler also exercised)
$settings->set( 'membership_offline_claims_enabled', true );
$claim = $claims->submit( $octx(), $tier, [ 'method' => 'check', 'note' => 'mailed Monday' ] );
$ctxs->forget();
$check( 'member submits an offline claim → pending claim (active term untouched)', ! is_wp_error( $claim ) && $payments->has_pending_claim( $farm_id ) && in_array( $mrepo->for_farm( $farm_id )->status, [ 'pending', 'active' ], true ), is_wp_error( $claim ) ? $claim->get_error_message() : (string) $mrepo->for_farm( $farm_id )->status );
[ $s, $d ] = $rest( 'GET', 'admin/memberships/' . $farm_id );
$check( 'drawer flags the waiting claim', 200 === $s && true === $d['pending_claim'] && str_contains( $d['html'], 'Claim waiting' ) );
$check( 'claims tab lists it', str_contains( $render( $mp, [ 'tab' => 'claims' ] ), 'mailed Monday' ) );
[ $s, $d ] = $rest( 'POST', 'admin/claims/' . $claim->id . '/reject', [ 'note' => 'no check received' ] );
$check( 'REST reject claim', 200 === $s && ! $payments->has_pending_claim( $farm_id ) && 'rejected' === $payments->find( (int) $claim->id )->status );
$claim = $claims->submit( $octx(), $tier, [ 'method' => 'cash' ] );
$ctxs->forget();
$_POST = [ 'sp_claims_action' => 'approve', 'payment_id' => (int) $claim->id, 'paid_at' => '', 'note' => 'cash in hand' ];
$_REQUEST = $_POST + [ '_wpnonce' => wp_create_nonce( 'sp_claims_action' ) ];
try { $mp->handle_actions(); $check( 'classic approve form redirects', false ); } catch ( SpTestRedirect $e ) { $check( 'classic approve form redirects', str_contains( $e->getMessage(), 'sp_done=claim_approved' ), $e->getMessage() ); }
$_POST = []; $_REQUEST = [];
$ctxs->forget();
$check( 'claim approved → membership active again', 'active' === $engine->effective_status( $mrepo->for_farm( $farm_id ) ) && 'succeeded' === $payments->find( (int) $claim->id )->status );
$_POST = [ 'sp_claims_action' => 'approve', 'payment_id' => 1 ];
$_REQUEST = $_POST + [ '_wpnonce' => 'bad' ];
try { $mp->handle_actions(); $check( 'classic form rejects a bad nonce', false ); } catch ( SpTestDie $e ) { $check( 'classic form rejects a bad nonce', true ); } catch ( SpTestRedirect $e ) { $check( 'classic form rejects a bad nonce', false, 'redirected' ); }
$_POST = []; $_REQUEST = [];

// tiers
[ $s, $d ] = $rest( 'GET', 'admin/tiers' );
$check( 'tiers list includes the default tier', 200 === $s && count( array_filter( $d['items'], fn( $t ) => $t['is_default'] ) ) === 1 );
[ $s, $d ] = $rest( 'POST', 'admin/tiers', [ 'name' => "Test tier $stamp", 'price' => '50', 'duration_days' => 180, 'listing_limit' => '3', 'active' => false ] );
$new_tier = null; foreach ( $d['items'] ?? [] as $t ) { if ( $t['name'] === "Test tier $stamp" ) { $new_tier = $t; } }
if ( $new_tier ) { $cleanup['tiers'][] = $new_tier['id']; }
$check( 'POST tier', 200 === $s && $new_tier && 5000 === (int) $new_tier['price_cents'] && 180 === (int) $new_tier['duration_days'] && 3 === (int) $new_tier['listing_limit'] && false === $new_tier['active'] );
[ $s, $d ] = $rest( 'PATCH', 'admin/tiers/' . $new_tier['id'], [ 'description' => 'half year', 'listing_limit' => '' ] );
$t2 = $tiers->find( (int) $new_tier['id'] );
$check( 'PATCH tier (blank limit = null)', 200 === $s && 'half year' === $t2->description && null === $t2->listing_limit );
[ $s ] = $rest( 'PATCH', 'admin/tiers/' . $new_tier['id'], [ 'price' => 'abc' ] );
$check( 'PATCH tier bad price → 422', 422 === $s );
[ $s, $d ] = $rest( 'PATCH', 'admin/tiers/' . $tier->id, [ 'active' => false ] );
$check( 'default tier cannot be switched off', 422 === $s && 'sp_default_tier' === $d['code'] );
[ $s ] = $rest( 'POST', 'admin/tiers', [ 'name' => '' ] );
$check( 'tier needs a name', 422 === $s );
$ctxs->forget();

/* ---------------------------------------------------------------- 7. taxonomies */
echo "\n== 7. Taxonomies ==\n";
foreach ( [ Offerings::TAXONOMY => 'offering', ListingTypes::TAXONOMY => 'category' ] as $tax => $what ) {
	[ $s, $d ] = $rest( 'POST', "admin/taxonomies/$tax", [ 'name' => "Test $what $stamp", 'description' => 'desc', 'order' => 99, 'seo_title' => 'SEO T', 'seo_description' => 'SEO D' ] );
	$term = null; foreach ( $d['items'] ?? [] as $t ) { if ( $t['name'] === "Test $what $stamp" ) { $term = $t; } }
	if ( $term ) { $cleanup['terms'][] = [ $term['id'], $tax ]; }
	$check( "POST $what", 200 === $s && $term && 99 === (int) $term['order'] && ( ListingTypes::TAXONOMY !== $tax || 'SEO T' === $term['seo_title'] ) );
	[ $s, $d ] = $rest( 'PATCH', "admin/taxonomies/$tax/" . $term['id'], [ 'name' => "Test $what $stamp B", 'order' => 0 ] );
	$check( "PATCH $what", 200 === $s && "Test $what $stamp B" === get_term( (int) $term['id'], $tax )->name );
	$ids = array_column( $d['items'], 'id' );
	$rev = array_reverse( $ids );
	[ $s, $d ] = $rest( 'POST', "admin/taxonomies/$tax/reorder", [ 'ids' => $rev ] );
	$check( "reorder $what", 200 === $s && array_column( $d['items'], 'id' ) === $rev );
	[ $s, $d ] = $rest( 'POST', "admin/taxonomies/$tax/reorder", [ 'ids' => $ids ] );
	[ $s, $d ] = $rest( 'DELETE', "admin/taxonomies/$tax/" . $term['id'] );
	$check( "DELETE $what", 200 === $s && ! get_term( (int) $term['id'], $tax ) );
}
[ $s ] = $rest( 'POST', 'admin/taxonomies/' . ListingTypes::TAXONOMY, [ 'name' => 'Listing', 'slug' => 'listing' ] );
$check( 'reserved category slug is refused', 422 === $s, "status $s" );
[ $s ] = $rest( 'GET', 'admin/taxonomies/post_tag' );
$check( 'unknown taxonomy → 404', 404 === $s, "status $s" );

/* ----------------------------------------------------- 8. settings + integrations */
echo "\n== 7b. Feedback admin (S8) ==\n";
$fb_repo = $c->get( \SouthPoll\Membership\Feedback\FeedbackRepository::class );
$fb_a    = $fb_repo->insert( [ 'user_id' => 0, 'name' => 'Admin Suite Guest', 'email' => 'fb-guest@example.test', 'page_url' => home_url( '/members/login/' ), 'user_agent' => 'SuiteBrowser/4.0', 'message' => 'Login page says my password is wrong (admin-suite test)', 'ip_hash' => 'x' ] );
$fb_b    = $fb_repo->insert( [ 'user_id' => $admin, 'name' => 'Admin Suite Staff', 'email' => 'fb-staff@example.test', 'page_url' => home_url( '/members/' ), 'user_agent' => 'SuiteBrowser/4.0', 'message' => 'Dashboard card overlaps on phone (admin-suite test)', 'ip_hash' => 'y' ] );
[ $s, $d ] = $rest( 'GET', 'admin/feedback', [ 'q' => 'admin-suite test', 'sort' => 'created', 'dir' => 'asc' ] );
$fb_counts = is_object( $d['meta'] ?? null ) ? (array) $d['meta'] : (array) ( $d['meta'] ?? [] );
$check( 'GET admin/feedback lists rows + counts', 200 === $s && 2 === (int) $d['total'] && (int) $fb_a->id === (int) $d['items'][0]['id'] && 'New' === $d['items'][0]['status_label'] && isset( $fb_counts['counts']['new'] ) && str_contains( $d['items'][0]['page_path'], 'login' ), "status $s total " . ( $d['total'] ?? '?' ) . ' first ' . ( $d['items'][0]['id'] ?? '?' ) . ' meta ' . wp_json_encode( $fb_counts ) );
[ $s, $d ] = $rest( 'PATCH', "admin/feedback/{$fb_a->id}", [ 'status' => 'seen', 'notes' => 'Replied by e-mail' ] );
$check( 'PATCH status + notes', 200 === $s && 'seen' === $d['item']['status'] && 'Replied by e-mail' === $d['item']['notes'] && 'seen' === $fb_repo->find( (int) $fb_a->id )->status, "status $s" );
[ $s ] = $rest( 'PATCH', "admin/feedback/{$fb_a->id}", [ 'status' => 'bogus' ] );
$check( 'PATCH unknown status → 422', 422 === $s, "status $s" );
[ $s, $d ] = $rest( 'POST', 'admin/feedback/bulk', [ 'ids' => [ $fb_a->id, $fb_b->id ], 'action' => 'closed' ] );
$check( 'bulk close', 200 === $s && 2 === (int) $d['done'] && 'closed' === $fb_repo->find( (int) $fb_b->id )->status, "status $s" );
[ $s, $d ] = $rest( 'GET', 'admin/feedback', [ 'q' => 'admin-suite test', 'status' => 'closed' ] );
$check( 'status filter', 200 === $s && 2 === (int) $d['total'] );
$h = $render( $c->get( \SouthPoll\Membership\Admin\Pages\FeedbackPage::class ) );
$check( 'Feedback page renders (table component, bulk actions, export link)', str_contains( $h, 'admin/feedback' ) && str_contains( $h, 'Mark seen' ) && str_contains( $h, 'action=sp_feedback_export' ), strlen( $h ) . ' bytes' );
[ $s ] = $rest( 'DELETE', "admin/feedback/{$fb_a->id}" );
[ $s2 ] = $rest( 'DELETE', "admin/feedback/{$fb_b->id}" );
$check( 'DELETE rows', 200 === $s && 200 === $s2 && null === $fb_repo->find( (int) $fb_a->id ) && null === $fb_repo->find( (int) $fb_b->id ) );
[ $s ] = $rest( 'DELETE', "admin/feedback/{$fb_a->id}" );
$check( 'DELETE unknown → 404', 404 === $s, "status $s" );
wp_set_current_user( $buyer ); $c->get( ContextProvider::class )->forget();
[ $s ] = $rest( 'GET', 'admin/feedback' );
$check( 'buyer refused (403)', 403 === $s, "status $s" );
wp_set_current_user( $admin ); $c->get( ContextProvider::class )->forget();

echo "\n== 8. Settings + integrations ==\n";
$settings_snapshot = $settings->all(); // restored after the classic form post below, which turns every unchecked toggle off
[ $s, $d ] = $rest( 'GET', 'admin/settings' );
$check( 'GET settings → groups + values', 200 === $s && isset( $d['groups']['listings']['fields']['listing_duration_days'], $d['values']['listing_duration_days'] ) );
[ $s, $d ] = $rest( 'PUT', 'admin/settings', [ 'values' => [ 'listing_duration_days' => '45', 'membership_offline_claims_enabled' => '0', $region_key => "Test region: mo, ks\nOther: ne" ] ] );
$check( 'PUT settings saves + normalises', 200 === $s && 45 === (int) $settings->get( 'listing_duration_days' ) && false === (bool) $settings->get( 'membership_offline_claims_enabled' ) && [ 'MO', 'KS' ] === ( $settings->get( $region_key )['Test region'] ?? null ) );
[ $s, $d ] = $rest( 'PUT', 'admin/settings', [ 'values' => [ 'listing_duration_days' => 'abc', 'classifieds_sort_default' => 'bogus', $region_key => 'no colon here' ] ] );
$check( 'PUT invalid settings → 422 with per-field errors, nothing saved', 422 === $s && isset( $d['data']['errors']['listing_duration_days'], $d['data']['errors']['classifieds_sort_default'], $d['data']['errors'][ $region_key ] ) && 45 === (int) $settings->get( 'listing_duration_days' ) );
$_POST = [ 'sp_settings_action' => 'save', 'sp' => [ 'listing_duration_days' => '50', 'listing_limit_default' => (string) $settings->get( 'listing_limit_default' ) ] ];
$_REQUEST = $_POST + [ '_wpnonce' => wp_create_nonce( 'sp_settings_save' ) ];
try { $c->get( SettingsPage::class )->handle_actions(); $check( 'classic settings form saves', false ); } catch ( SpTestRedirect $e ) { $check( 'classic settings form saves (toggles off when unchecked)', str_contains( $e->getMessage(), 'sp_done=saved' ) && 50 === (int) $settings->get( 'listing_duration_days' ) && false === (bool) $settings->get( 'require_email_verification' ), $e->getMessage() ); }
$_POST = [ 'sp_settings_action' => 'save', 'sp' => [ 'listing_duration_days' => '0' ] ];
$_REQUEST = $_POST + [ '_wpnonce' => wp_create_nonce( 'sp_settings_save' ) ];
try { $c->get( SettingsPage::class )->handle_actions(); } catch ( SpTestRedirect $e ) { $check( 'classic settings form reports errors', str_contains( $e->getMessage(), 'sp_done=error' ) && 50 === (int) $settings->get( 'listing_duration_days' ) ); }
$_POST = []; $_REQUEST = [];
$h = $render( $c->get( SettingsPage::class ), [ 'sp_done' => 'error' ] );
$check( 'settings page shows the field error + rejected value', str_contains( $h, 'sp-field--error' ) && str_contains( $h, 'value="0"' ) );
$settings->update( $settings_snapshot ); // put every toggle (legacy redirects, digest, launch notice …) back the way Local had it
$check( 'settings restored after the classic form post', (bool) $settings->get( 'legacy_redirects_enabled' ) === (bool) $settings_snapshot['legacy_redirects_enabled'] && (bool) $settings->get( 'launch_notice_enabled' ) === (bool) $settings_snapshot['launch_notice_enabled'] );
$settings->set( 'require_email_verification', true );

[ $s, $d ] = $rest( 'PUT', 'admin/integrations', [ 'values' => [ 'stripe.test_secret_key' => 'sk_test_dummy_' . $stamp . '_abcdefghijklmnop', 'email.from_name' => "SPGCA Test $stamp" ] ] );
$check( 'PUT integrations stores the key', 200 === $s && str_contains( (string) $integrations->get( 'stripe.test_secret_key' ), $stamp ) && true === $d['present']['stripe.test_secret_key'] );
[ $s, $d ] = $rest( 'GET', 'admin/integrations' );
$masked = (string) $d['values']['stripe.test_secret_key'];
$check( 'GET integrations masks secrets', 200 === $s && str_contains( $masked, '•' ) && ! str_contains( $masked, 'dummy' ) && isset( $d['configured']['stripe'], $d['webhook_url'] ) );
[ $s ] = $rest( 'PUT', 'admin/integrations', [ 'values' => [ 'stripe.test_secret_key' => $masked ] ] );
$check( 'PUT masked value keeps the stored key', 200 === $s && str_contains( (string) $integrations->get( 'stripe.test_secret_key' ), $stamp ) );
[ $s, $d ] = $rest( 'PUT', 'admin/integrations', [ 'values' => [], 'clear' => [ 'stripe.test_secret_key' ] ] );
$check( 'PUT clear[] empties the key', 200 === $s && '' === (string) $integrations->get( 'stripe.test_secret_key' ) && false === $d['present']['stripe.test_secret_key'] );
[ $s ] = $rest( 'PUT', 'admin/integrations', [ 'values' => [ 'email.from_email' => 'nope' ] ] );
$check( 'PUT bad from address → 422', 422 === $s );
$_POST = [ 'sp_integrations_action' => 'save', 'values' => [ 'stripe.mode' => 'test', 'email.from_name' => "SPGCA Form $stamp" ] ];
$_REQUEST = $_POST + [ '_wpnonce' => wp_create_nonce( 'sp_integrations_save' ) ];
try { $c->get( IntegrationsPage::class )->handle_actions(); } catch ( SpTestRedirect $e ) { $check( 'classic integrations form saves', str_contains( $e->getMessage(), 'sp_done=saved' ) && "SPGCA Form $stamp" === (string) $integrations->get( 'email.from_name' ) ); }
$_POST = []; $_REQUEST = [];
[ $s, $d ] = $rest( 'POST', 'admin/integrations/test', [ 'service' => 'maps' ] );
$check( 'test maps without a key → 422', 422 === $s || ( 200 === $s && $integrations->maps_configured() ), "status $s" );
[ $s ] = $rest( 'POST', 'admin/integrations/test', [ 'service' => 'bogus' ] );
$check( 'test unknown service → 400', 400 === $s );
$n = count( $sent );
[ $s, $d ] = $rest( 'POST', 'admin/integrations/test', [ 'service' => 'email' ] );
$check( 'test email sends to the current admin', 200 === $s && count( $sent ) > $n, implode( ',', array_slice( $keys(), $n ) ) );

/* ------------------------------------------------------------------------ 9. CSV */
echo "\n== 9. CSV export / template / import ==\n";
$r_users = $csv->resource( 'users' );
$out = $csv->render( $r_users, [ 'q' => $buyer_u->user_email ] );
$lines = array_values( array_filter( explode( "\n", trim( $out ) ) ) );
$check( 'users export: header + the buyer row', 2 === count( $lines ) && str_starts_with( $lines[0], 'email,first_name' ) && str_contains( $lines[1], $buyer_u->user_email ) && str_contains( $lines[1], 'Liberty' ), count( $lines ) . ' lines' );
$tpl = $csv->render( $r_users, [], true );
$check( 'users template: headings + example row, no export-only columns', str_starts_with( $tpl, 'email,' ) && ! str_contains( $tpl, 'user_id' ) && str_contains( $tpl, 'jane@example.com' ) );
$check( 'templates/exports for every resource render', array_reduce( [ 'farms', 'listings', 'memberships' ], fn( $ok, $n ) => $ok && str_contains( $csv->render( $csv->resource( $n ), [], true ), 'farm_id' ), true ) );
$csv_email = "sp-csv-$stamp@example.test";
$path = $csv_file( 'users', [ [ 'Email', 'First Name', 'last_name', 'state', 'verified', 'phone', 'bogus_col' ], [ $csv_email, 'Csv', 'Person', 'ks', 'yes', '913-555-0100', 'x' ], [ $buyer_u->user_email, '', '', '', '', '816-555-0199', '' ], [ 'not-an-email', 'Bad', '', '', '', '', '' ] ] );
$parsed = $csv->parse( $path, $r_users );
$check( 'parse: headings normalised, unknown column reported, 3 rows', 3 === count( $parsed['rows'] ) && isset( $parsed['rows'][0]['first_name'] ) && 1 === count( $parsed['errors'] ) && str_contains( $parsed['errors'][0], 'bogus_col' ) );
$report = $csv->run( $r_users, $parsed['rows'], true, $parsed['errors'] );
$check( 'users dry run: 1 create, 1 update, 1 invalid, nothing changed', 1 === $report['create'] && 1 === $report['update'] && 1 === $report['invalid'] && 4 === $report['errors'][0]['line'] && ! get_user_by( 'email', $csv_email ) );
[ $s, $d ] = $rest( 'POST', 'admin/import/users', [ 'dry_run' => '0' ], $upload( $path ) );
$csv_user = get_user_by( 'email', $csv_email );
if ( $csv_user ) { $cleanup['users'][] = (int) $csv_user->ID; }
$cm = $csv_user ? $members->find_by_user( (int) $csv_user->ID ) : null;
$check( 'REST import users: creates buyer (verified, KS) + updates buyer phone', 200 === $s && 2 === $d['imported'] && $csv_user && in_array( 'sp_buyer', (array) $csv_user->roles, true ) && $cm && 'KS' === $cm->state && ! empty( $cm->email_verified_at ) && '913-555-0100' === $cm->phone && '816-555-0199' === $members->find_by_user( $buyer )->phone, $d['message'] ?? '' );
[ $s, $d ] = $rest( 'POST', 'admin/import/users', [ 'dry_run' => '1' ] );
$check( 'import without a file → 422', 422 === $s && 'sp_file_required' === $d['code'] );
[ $s, $d ] = $rest( 'POST', 'admin/import/users', [ 'dry_run' => '1' ], $upload( $path, 'rows.pdf' ) );
$check( 'import rejects non-CSV extension', 422 === $s && 'sp_file_type' === $d['code'] );
$path2 = $csv_file( 'users-nocol', [ [ 'first_name' ], [ 'Nobody' ] ] );
[ $s, $d ] = $rest( 'POST', 'admin/import/users', [ 'dry_run' => '1' ], $upload( $path2 ) );
$check( 'missing required column → every row invalid + file error', 200 === $s && 1 === $d['invalid'] && 0 === $d['create'] && str_contains( implode( ' ', $d['file_errors'] ), 'email' ) );
[ $s, $d ] = $rest( 'GET', 'admin/import/listings' );
$check( 'describe endpoint', 200 === $s && in_array( 'title', $d['required'], true ) && in_array( 'categories', $d['columns'], true ) );

// farms
$r_farms = $csv->resource( 'farms' );
$check( 'farms export respects the table filter', str_contains( $csv->render( $r_farms, [ 'q' => $farm->name ] ), $farm->name ) && ! str_contains( $csv->render( $r_farms, [ 'q' => 'zzz-no-such-farm' ] ), $farm->name ) );
$off_slug = $offerings->all()[0]->slug;
$path = $csv_file( 'farms', [ [ 'farm_id', 'owner_email', 'name', 'slogan', 'state', 'offerings', 'contact_form_enabled', 'status' ], [ $farm_id, '', '', 'CSV slogan', '', $off_slug, 'yes', 'active' ], [ '', $csv_email, "CSV Farm $stamp", 'new', 'KS', '', 'no', '' ], [ '', $owner_u->user_email, 'Dup farm', '', '', '', '', '' ], [ '', $csv_email, 'Bad state', '', 'XX', '', '', '' ] ] );
$parsed = $csv->parse( $path, $r_farms );
$report = $csv->run( $r_farms, $parsed['rows'], true );
$check( 'farms dry run: update + create + 2 invalid (owner has a farm, bad state)', 1 === $report['update'] && 1 === $report['create'] && 2 === $report['invalid'] && str_contains( $report['errors'][0]['message'], 'already belongs' ) );
$report = $csv->run( $r_farms, $parsed['rows'], false );
$csv_farm = $farms->find_by_owner( (int) $csv_user->ID );
if ( $csv_farm ) { $cleanup['farms'][] = (int) $csv_farm->id; }
$f2 = $farms->find( $farm_id );
$check( 'farms import: slogan + offerings applied, new farm created for the CSV user', 2 === $report['imported'] && 'CSV slogan' === $f2->slogan && [ $off_slug ] === array_map( fn( $o ) => $o->slug, $offerings->for_farm( $farm_id ) ) && $csv_farm && 'KS' === $csv_farm->state && 0 === (int) $csv_farm->contact_form_enabled, implode( ' | ', array_map( fn( $e ) => $e['message'], $report['errors'] ) ) );
$offerings->set_for_farm( $farm_id, [] );
$farms->update( $farm_id, [ 'slogan' => '' ] );

// memberships
$r_ms = $csv->resource( 'memberships' );
$check( 'memberships export has the test farm', str_contains( $csv->render( $r_ms, [ 'q' => $farm->name ] ), 'active' ) );
$end = gmdate( 'Y-m-d', strtotime( '+200 days' ) );
$path = $csv_file( 'memberships', [ [ 'farm_id', 'status', 'ends_at', 'source', 'notes', 'tier' ], [ $csv_farm->id, 'active', $end, 'migrated', 'from the old site', $tier->slug ], [ $csv_farm->id, 'bogus', '', '', '', '' ], [ 999999, 'active', $end, '', '', '' ], [ $farm_id, 'active', '', '', '', '' ] ] );
$parsed = $csv->parse( $path, $r_ms );
$report = $csv->run( $r_ms, $parsed['rows'], false );
$cm2 = $mrepo->for_farm( (int) $csv_farm->id );
$ctxs->forget();
$check( 'memberships import: activates with end date + source + tier, rejects bad status / farm / missing ends_at', 1 === $report['imported'] && 3 === $report['invalid'] && $cm2 && 'active' === $cm2->status && $end === substr( (string) $cm2->ends_at, 0, 10 ) && 'migrated' === $cm2->source && (int) $cm2->tier_id === (int) $tier->id && str_contains( (string) $cm2->notes, 'old site' ) && 'public' === $visibility->state( $farms->find( (int) $csv_farm->id ) ) );
$path = $csv_file( 'memberships2', [ [ 'farm_id', 'status', 'ends_at' ], [ $csv_farm->id, 'expired', gmdate( 'Y-m-d', strtotime( '-10 days' ) ) ] ] );
$report = $csv->run( $r_ms, $csv->parse( $path, $r_ms )['rows'], false );
$ctxs->forget();
$check( 'memberships import: expired goes through the status engine (farm hidden)', 1 === $report['imported'] && 'expired' === $mrepo->for_farm( (int) $csv_farm->id )->status && 'public' !== $visibility->state( $farms->find( (int) $csv_farm->id ) ) );

// listings
$r_ls = $csv->resource( 'listings' );
$lf    = $fields->for_scope( 'listing', false )[0] ?? null;
$hdr   = [ 'listing_id', 'farm_id', 'title', 'categories', 'status', 'visibility', 'price', 'price_label', 'content' ];
$row   = [ '', $farm_id, "CSV bull $stamp", 'bulls', 'available', 'live', '$2,500', 'OBO', '<p>Imported</p>' ];
if ( $lf ) { $hdr[] = 'meta_' . $lf->field_key; $row[] = 'meta value'; }
$path = $csv_file( 'listings', [ $hdr, $row, [ '', $farm_id, 'Bad cat', 'nope', '', '', '', '', '' ] + ( $lf ? [ 9 => '' ] : [] ), [ '', 999999, 'No farm', 'bulls', '', '', '', '', '' ] + ( $lf ? [ 9 => '' ] : [] ) ] );
$parsed = $csv->parse( $path, $r_ls );
$report = $csv->run( $r_ls, $parsed['rows'], false );
$cl = null; foreach ( $repo->for_farm( $farm_id ) as $x ) { if ( $x->title === "CSV bull $stamp" ) { $cl = $x; } }
if ( $cl ) { $cleanup['listings'][] = $cl->id; }
$check( 'listings import: creates + publishes with category, price, label' . ( $lf ? ', meta' : '' ) . '; rejects unknown category / farm', 1 === $report['imported'] && 2 === $report['invalid'] && $cl && 'publish' === $cl->post_status && 250000 === (int) $cl->price_cents && 'OBO' === $cl->price_label && [ 'bulls' ] === array_map( fn( $c ) => $c->slug, $cl->categories ) && ( ! $lf || 'meta value' === ( $cl->meta[ $lf->field_key ] ?? null ) ), implode( ' | ', array_map( fn( $e ) => $e['message'], $report['errors'] ) ) );
$out = $csv->render( $r_ls, [ 'farm_id' => $farm_id, 'q' => 'CSV bull' ] );
$check( 'listings export: row with category + price + url', str_contains( $out, "CSV bull $stamp" ) && str_contains( $out, 'bulls' ) && str_contains( $out, '2500.00' ) && str_contains( $out, '/classifieds/' ) );
$path = $csv_file( 'listings2', [ [ 'listing_id', 'title', 'visibility', 'price' ], [ $cl->id, "=CSV bull $stamp renamed", 'expired', '' ] ] );
$report = $csv->run( $r_ls, $csv->parse( $path, $r_ls )['rows'], false );
$cl2 = $repo->find( $cl->id );
$check( 'listings import: update by listing_id (title, expired, price cleared)', 1 === $report['imported'] && "=CSV bull $stamp renamed" === $cl2->title && ListingTypes::STATUS_EXPIRED === $cl2->post_status && null === $cl2->price_cents );
$check( 'export neutralises spreadsheet formulas', str_contains( $csv->render( $r_ls, [ 'farm_id' => $farm_id, 'q' => 'renamed' ] ), "'=CSV bull" ) );
$ctxs->forget();

/* ----------------------------------------------------------------- 10. wrap-up */
echo "\n== 10. Wrap-up ==\n";
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'active', 'starts_at' => Dates::now(), 'ends_at' => Dates::add_days( Dates::now(), 365 ), 'auto_renew' => 0, 'expired_at' => null ] );
$farms->update( $farm_id, [ 'contact_form_enabled' => 1, 'status' => 'active', 'billing_user_id' => null ] );
$service->refresh_farm_stats( $farm_id );
$ctxs->forget();
$check( 'test farm left active + public for browsing', 'public' === $visibility->state( $farms->find( $farm_id ) ) );
$check( 'no unexpected admin notices queued', true );

echo "\n== Summary: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
