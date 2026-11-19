<?php
/**
 * test-phase3-listings.php — Phase 3 (classifieds) suite: types/seeds, editor validation + publish, limits, status/sold
 * window, renew, rename 301 data, duplicate/delete, archive query + filters + pins, directory integration, public REST,
 * my-listings REST (incl. media), page renders (archive, category, single, unavailable, share), saved searches, listing
 * favorites, alert pipeline + unsubscribe, listing cron, membership gating, field groups, [classifieds]. Uses the
 * throwaway accounts from test-phase1-auth.php (run that first). Restores settings it touches; deletes its listings.
 *
 * Run: ./wp-run.sh test-phase3-listings.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\AlertDispatcher;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Engagement\NotificationQueue;
use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Farms\DirectoryQuery;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\Controllers\ClassifiedsController;
use SouthPoll\Membership\Frontend\Controllers\DashboardController;
use SouthPoll\Membership\Frontend\Controllers\ListingPageController;
use SouthPoll\Membership\Frontend\Controllers\UnsubscribeController;
use SouthPoll\Membership\Listings\ListingCron;
use SouthPoll\Membership\Listings\ListingEditor;
use SouthPoll\Membership\Listings\ListingLimits;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Rest\RateLimiter;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Routes;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

$c     = sp_membership();
$pass  = 0; $fail = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$stamp = substr( (string) time(), -6 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
global $wpdb;
$p = $wpdb->prefix;

$settings  = $c->get( Settings::class );
$farms     = $c->get( FarmRepository::class );
$ctxs      = $c->get( ContextProvider::class );
$members   = $c->get( MemberRepository::class );
$mrepo     = $c->get( MembershipRepository::class );
$engine    = $c->get( StatusEngine::class );
$types     = $c->get( ListingTypes::class );
$repo      = $c->get( ListingRepository::class );
$service   = $c->get( ListingService::class );
$editor    = $c->get( ListingEditor::class );
$limits    = $c->get( ListingLimits::class );
$query     = $c->get( ListingQuery::class );
$presenter = $c->get( ListingPresenter::class );
$fields    = $c->get( MetaFieldRepository::class );
$favorites = $c->get( FavoriteRepository::class );
$searches  = $c->get( SavedSearchRepository::class );
$ssvc      = $c->get( SavedSearchService::class );
$queue     = $c->get( NotificationQueue::class );
$alerts    = $c->get( AlertDispatcher::class );
$cron      = $c->get( ListingCron::class );
$lrun      = function () use ( $c, $cron ) { $s = $cron->run(); $c->get( SouthPoll\Membership\Engagement\AlertDispatcher::class )->process(); return $s; }; // listing notices are queued (T6.9): drain after each run
$saved_window = (string) $c->get( SouthPoll\Membership\Core\Settings::class )->get( 'email_window_start', '' ); $c->get( SouthPoll\Membership\Core\Settings::class )->set( 'email_window_start', '' );
$urls      = $c->get( Urls::class );

// Capture emails; let wp_mail "succeed" without sending so queue workers see a real success path.
$sent = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = $msg; return $msg; } );
add_filter( 'pre_wp_mail', '__return_true' );
$keys = function () use ( &$sent ) { return array_map( fn( $m ) => $m->key(), $sent ); };

$restore = [ 'recaptcha_enabled' => $settings->get( 'recaptcha_enabled' ), 'listing_limit_default' => $settings->get( 'listing_limit_default' ) ];
$created = [];
register_shutdown_function( function () use ( $settings, $restore ) {
	$settings->set( 'recaptcha_enabled', $restore['recaptcha_enabled'] );
	$settings->set( 'listing_limit_default', $restore['listing_limit_default'] );
} );

// Accounts + deterministic state.
$owner = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
$buyer = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-buyer-%@example.test' ORDER BY ID DESC LIMIT 1" );
$admin = (int) $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='{$p}capabilities' WHERE m.meta_value LIKE '%administrator%' ORDER BY u.ID LIMIT 1" );
if ( ! $owner || ! $buyer ) { die( "Run test-phase1-auth.php first to create test accounts.\n" ); }
$farm    = $farms->find_by_owner( $owner );
$farm_id = (int) $farm->id;
$tier    = $c->get( TierRepository::class )->default();
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'active', 'starts_at' => Dates::now(), 'ends_at' => Dates::add_days( Dates::now(), 365 ), 'auto_renew' => 0, 'reminders_sent' => [], 'expired_at' => null ] );
$farms->update( $farm_id, [ 'city' => 'Kearney', 'state' => 'MO', 'email' => "farm-$stamp@example.test", 'contact_form_enabled' => 1 ] );
$farms->set_geo( $farm_id, 39.3639, -94.3622 );
$bm = $members->find_by_user( $buyer ) ?? $members->create_for_user( $buyer );
$members->update( (int) $bm->id, [ 'email_verified_at' => Dates::now(), 'notify_all' => 1, 'default_notify_favorites' => 0, 'default_notify_searches' => 0 ] );
// Tidy any listings left by an earlier run of this suite.
foreach ( $repo->for_farm( $farm_id ) as $old ) { $repo->delete( $old->id ); }
$wpdb->delete( "{$p}sp_saved_searches", [ 'user_id' => $buyer ] );
$wpdb->delete( "{$p}sp_favorites", [ 'user_id' => $buyer ] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}sp_notification_queue WHERE user_id = %d", $buyer ) );
$service->refresh_farm_stats( $farm_id );
$ctxs->forget();
$farm = $farms->find( $farm_id );
echo "owner #{$owner} (farm #{$farm_id} '{$farm->name}'), buyer #{$buyer}, admin #{$admin}\n\n";

$cats   = []; foreach ( $types->categories() as $cat ) { $cats[ $cat->slug ] = $cat->id; }
$ctx_o  = fn() => $ctxs->for_user( $owner );
$rest   = function ( string $method, string $path, array $params = [], array $files = [] ) {
	$req = new WP_REST_Request( $method, '/sp/v1/' . ltrim( $path, '/' ) );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( $files ) { $req->set_file_params( $files ); }
	return rest_do_request( $req );
};
$as = function ( int $uid ) use ( $ctxs ) { wp_set_current_user( $uid ); $ctxs->forget(); };
$png = function ( int $w, int $h ): array {
	$path = tempnam( sys_get_temp_dir(), 'sp' ) . '.png';
	$im   = imagecreatetruecolor( $w, $h ); imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 178, 137, 46 ) ); imagepng( $im, $path ); imagedestroy( $im );
	return [ 'name' => 'listing.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ];
};
$fresh = function ( string $class ) use ( $c ) {
	$obj = $c->get( $class );
	$ref = new ReflectionClass( $obj );
	foreach ( [ 'resolved' => false, 'listing' => null, 'farm' => null, 'category' => null, 'shared' => null, 'state' => 'ok' ] as $prop => $val ) {
		if ( $ref->hasProperty( $prop ) ) { $rp = $ref->getProperty( $prop ); $rp->setAccessible( true ); $rp->setValue( $obj, $val ); }
	}
	return $obj;
};
$render_route = function ( int $uid, string $route, array $params = [], string $class = ClassifiedsController::class ) use ( $as, $fresh ): string {
	$as( $uid ); $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
	$controller = $fresh( $class );
	$match      = new RouteMatch( $route, Routes::get( $route ), $params );
	ob_start();
	try { if ( method_exists( $controller, 'prepare' ) ) { $controller->prepare( $match ); } $controller->render( $match ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
	return (string) ob_get_clean();
};
$render_section = function ( int $uid, string $route, array $get = [] ) use ( $c, $as ): string {
	$as( $uid ); $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/members/' . $route . '/'; $_GET = $get;
	ob_start();
	try { $c->get( DashboardController::class )->render( new RouteMatch( $route, Routes::get( $route ) ) ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
	$_GET = [];
	return (string) ob_get_clean();
};

echo "== 1. Types + seeds ==\n";
$check( 'sp_listing post type + taxonomy registered', post_type_exists( 'sp_listing' ) && taxonomy_exists( 'sp_listing_category' ) && get_post_status_object( 'sp_expired' ) );
$check( 'S5: five listing classes in order (Bulls, Cows, Heifers, Steers, Semen)', count( $cats ) >= 5 && 'bulls' === $types->categories()[0]->slug && isset( $cats['cows'], $cats['heifers'], $cats['steers'], $cats['semen'] ) && ! isset( $cats['females'], $cats['feeders'], $cats['finishers'], $cats['calves'] ), implode( ',', array_keys( $cats ) ) );
$reserved = wp_insert_term( 'listing', 'sp_listing_category' );
$check( 'reserved category slug "listing" refused', is_wp_error( $reserved ) );
$check( 'category SEO meta seeded', str_contains( $types->category( 'bulls' )->seo_title, 'Bulls' ) );

echo "\n== 2. Create + validate + publish ==\n";
$as( $owner );
$draft = $service->create_draft( $ctx_o() );
$created[] = $draft->id;
$check( 'draft created for the farm', $draft instanceof \SouthPoll\Membership\Listings\Listing && 'draft' === $draft->post_status && $draft->farm_id === $farm_id, "#{$draft->id}" );
$check( 'S1: never-published draft reads "Draft", not "Inactive"', $draft->is_unpublished_draft() && 'draft' === $draft->effective_status() && 'Draft' === $draft->status_label(), $draft->status_label() );
$h = $render_section( $owner, 'edit-listing', [ 'id' => (string) $draft->id ] );
$check( 'S1: draft editor has the read-only state line + hidden status, no status select', str_contains( $h, 'Draft — not visible to buyers until you press Publish' ) && str_contains( $h, 'type="hidden" name="status" value="available"' ) && ! str_contains( $h, 'id="le-status"' ) && str_contains( $h, '>Publish</button>' ) && ! str_contains( $h, 'Publish is not available yet' ), strlen( $h ) . ' bytes' );
$check( 'S1: "Save draft" keeps the draft and says so', ( $sd = $editor->save( $ctx_o(), $draft, [ 'title' => 'Draft title ' . $stamp, 'categories' => [ $cats['bulls'] ], 'status' => 'available' ], 'save' ) ) && ! is_wp_error( $sd ) && 'draft' === $sd->post_status && 'Draft' === $sd->status_label() );
$bad = $editor->save( $ctx_o(), $draft, [ 'title' => 'ab', 'categories' => [], 'price' => 'lots', 'content' => '' ], 'publish' );
$check( 'validation: title, categories, price, content', is_wp_error( $bad ) && count( array_intersect( [ 'title', 'categories', 'price', 'content' ], $bad->get_error_codes() ) ) === 4, is_wp_error( $bad ) ? implode( ',', $bad->get_error_codes() ) : 'no error' );
$sent = [];
$l1 = $editor->save( $ctx_o(), $draft, [ 'title' => 'Registered South Poll bull ' . $stamp, 'excerpt' => 'Gentle 3-year-old herd sire.', 'content' => '<p>Calving ease, <strong>grass finished</strong> genetics.</p><script>x</script>', 'price' => '$2,500.00', 'price_label' => 'OBO', 'categories' => [ $cats['bulls'] ], 'status' => 'available' ], 'publish' );
$check( 'publish ok: live, dates, slug, price, category', ! is_wp_error( $l1 ) && 'publish' === $l1->post_status && $l1->published_at && $l1->expires_at && 250000 === $l1->price_cents && 'OBO' === $l1->price_label && str_starts_with( $l1->slug, 'registered-south-poll-bull' ) && [ $cats['bulls'] ] === $l1->category_ids(), is_wp_error( $l1 ) ? $l1->get_error_message() : $l1->slug );
$check( 'expires 60 days after publish', ! is_wp_error( $l1 ) && abs( Dates::days_until( $l1->expires_at ) - 60 ) <= 1, (string) Dates::days_until( $l1->expires_at ) );
$check( 'content sanitised (script stripped)', ! str_contains( $l1->content, '<script' ) && str_contains( $l1->content, '<strong>' ) );
$check( 'price display "$2,500 OBO"', '$2,500 OBO' === $l1->price_display(), $l1->price_display() );
$check( 'published emails: farm contacts + admin new_listing', in_array( 'listing.published', $keys(), true ) && in_array( 'admin.new_listing', $keys(), true ), implode( ',', $keys() ) );
$farm = $farms->find( $farm_id );
$check( 'farm stats: 1 active listing + last_listing_at', 1 === (int) $farm->active_listing_count && ! empty( $farm->last_listing_at ) );
$check( 'editor rejects save by buyer', is_wp_error( $editor->save( $ctxs->for_user( $buyer ), $l1, [ 'title' => 'Hijack this', 'categories' => [ $cats['bulls'] ] ], 'save' ) ) );

echo "\n== 3. Limits (D-18) ==\n";
$limit_filter = fn() => 1;
add_filter( 'sp/listings/limit', $limit_filter );
$d2 = $service->create_draft( $ctx_o(), [ 'title' => 'Second ' . $stamp ] ); $created[] = $d2->id;
$res = $editor->save( $ctx_o(), $d2, [ 'title' => 'Second heifer ' . $stamp, 'content' => 'Nice heifer.', 'categories' => [ $cats['heifers'] ], 'status' => 'available' ], 'publish' );
$check( 'limit of 1 blocks a second live listing (sp_limit), draft kept', is_wp_error( $res ) && 'sp_limit' === $res->get_error_code() && 'draft' === $repo->find( $d2->id )->post_status && 'Second heifer ' . $stamp === $repo->find( $d2->id )->title, is_wp_error( $res ) ? $res->get_error_code() : 'published' );
$check( 'limits summary', [ 'limit' => 1, 'active' => 1, 'remaining' => 0 ] === $limits->summary( $farm_id ), json_encode( $limits->summary( $farm_id ) ) );
$h = $render_section( $owner, 'edit-listing', [ 'id' => (string) $d2->id ] );
$check( 'S1: limit reached → Publish disabled with the reason + My Listings link', str_contains( $h, 'Publish is not available yet' ) && str_contains( $h, 'limit of 1 active listings' ) && str_contains( $h, 'aria-describedby="le-publish-block"' ) && preg_match( '/<button[^>]*disabled[^>]*>Publish<\/button>/', $h ) && str_contains( $h, 'Go to My Listings' ), strlen( $h ) . ' bytes' );
remove_filter( 'sp/listings/limit', $limit_filter );
$l2 = $editor->save( $ctx_o(), $repo->find( $d2->id ), [ 'title' => 'Second heifer ' . $stamp, 'content' => 'Nice heifer, bred.', 'categories' => [ $cats['heifers'] ], 'status' => 'coming_soon', 'price' => '1800' ], 'publish' );
$check( 'with the default limit the second listing publishes (coming soon)', ! is_wp_error( $l2 ) && 'publish' === $l2->post_status && 'coming_soon' === $l2->status, is_wp_error( $l2 ) ? $l2->get_error_message() : $l2->status );

echo "\n== 4. Status changes, sold window, renew, rename, duplicate ==\n";
$events = [];
add_action( 'sp/listing/status_changed', function ( $l, $old, $new ) use ( &$events ) { $events[] = $old . '>' . $new; }, 10, 3 );
$l2 = $service->set_status( $ctx_o(), $l2, 'sold' );
$check( 'sold: sold_at set, still public, status_changed fired', ! is_wp_error( $l2 ) && 'sold' === $l2->status && $l2->sold_at && $l2->is_public( 14 ) && in_array( 'coming_soon>sold', $events, true ) );
$repo->update( $l2->id, [ 'sold_at' => Dates::add_days( Dates::now(), -20 ) ] );
$check( 'sold 20 days ago → no longer public (14-day window)', ! $repo->find( $l2->id )->is_public( 14 ) );
$stats = $lrun();
$check( 'cron hides sold listings past the window', $stats['sold_hidden'] >= 1 && 'draft' === $repo->find( $l2->id )->post_status && 'sold' === $repo->find( $l2->id )->hidden_reason, json_encode( $stats ) );
$sent = [];
$l2 = $service->renew( $ctx_o(), $repo->find( $l2->id ) );
$check( 'renew: live again, fresh 60-day clock, renewed_count 1, status available, published again', ! is_wp_error( $l2 ) && 'publish' === $l2->post_status && 1 === $l2->renewed_count && 'available' === $l2->status && abs( Dates::days_until( $l2->expires_at ) - 60 ) <= 1 && in_array( 'listing.published', $keys(), true ), is_wp_error( $l2 ) ? $l2->get_error_message() : $l2->expires_at );
$old_slug = $l2->slug;
$l2 = $editor->save( $ctx_o(), $l2, [ 'title' => 'Bred heifer ' . $stamp, 'content' => 'Nice heifer, bred.', 'categories' => [ $cats['heifers'] ], 'status' => 'available', 'price' => '1800' ], 'save' );
$check( 'rename regenerates slug and keeps the old one for 301s', ! is_wp_error( $l2 ) && $l2->slug !== $old_slug && in_array( $old_slug, $l2->old_slugs, true ) && $repo->find_by_old_slug( $old_slug )?->id === $l2->id, $l2->slug );
$copy = $service->duplicate( $ctx_o(), $l1 );
$check( 'duplicate: draft copy with "(copy)", same category/price', ! is_wp_error( $copy ) && 'draft' === $copy->post_status && str_ends_with( $copy->title, '(copy)' ) && $copy->price_cents === $l1->price_cents && $copy->category_ids() === $l1->category_ids() );
$check( 'delete copy', true === $service->delete( $ctx_o(), $copy ) && null === $repo->find( $copy->id ) );
$check( 'toggle live → inactive → live', ( $t = $service->toggle_active( $ctx_o(), $repo->find( $l2->id ) ) ) && 'draft' === $t->post_status && 'inactive' === $t->status && 'publish' === $service->toggle_active( $ctx_o(), $repo->find( $l2->id ) )->post_status );
$l2 = $repo->find( $l2->id );

echo "\n== 5. Archive query ==\n";
$ids = fn( array $r ) => array_map( fn( $l ) => $l->id, $r['items'] );
$r = $query->run( [] );
$check( 'both live listings in the archive, newest first', in_array( $l1->id, $ids( $r ), true ) && in_array( $l2->id, $ids( $r ), true ) && $ids( $r )[0] === $l2->id, implode( ',', $ids( $r ) ) );
$check( 'text search: title + farm name', in_array( $l1->id, $ids( $query->run( [ 'q' => 'south poll bull' ] ) ), true ) && in_array( $l1->id, $ids( $query->run( [ 'q' => (string) $farm->name ] ) ), true ) && 0 === $query->run( [ 'q' => 'zzz-nope' ] )['total'] );
$check( 'category filter', in_array( $l1->id, $ids( $query->run( [ 'cats' => 'bulls' ] ) ), true ) && ! in_array( $l2->id, $ids( $query->run( [ 'cats' => [ 'bulls' ] ] ) ), true ) );
$check( 'state / region / farms filters', in_array( $l1->id, $ids( $query->run( [ 'state' => 'MO' ] ) ), true ) && 0 === $query->run( [ 'state' => 'TX', 'farms' => [ $farm_id ] ] )['total'] && in_array( $l1->id, $ids( $query->run( [ 'region' => 'Midwest' ] ) ), true ) && in_array( $l1->id, $ids( $query->run( [ 'farms' => [ $farm_id ] ] ) ), true ) ); // scoped to the test farm: demo farms exist in other states
$check( 'since 24h + sort price asc', in_array( $l1->id, $ids( $query->run( [ 'since' => '24h' ] ) ), true ) && $ids( $query->run( [ 'sort' => 'price_asc', 'farms' => [ $farm_id ] ] ) )[0] === $l2->id );
$check( 'matches() for saved searches', $query->matches( $l1->id, [ 'cats' => [ 'bulls' ], 'state' => 'MO' ] ) && ! $query->matches( $l1->id, [ 'cats' => [ 'heifers' ] ] ) );
$opts = $query->filter_options();
$check( 'filter options: category counts, states, farms', ( array_column( $opts['categories'], 'count', 'value' )['bulls'] ?? 0 ) >= 1 && in_array( 'MO', array_column( $opts['states'], 'value' ), true ) && in_array( $farm_id, array_column( $opts['farms'], 'value' ), true ) );
$pins = $query->pins( [] );
$check( 'pins grouped by farm with listing count', count( array_filter( $pins, fn( $x ) => (int) $x->id === $farm_id && (int) $x->listings >= 2 ) ) === 1 );
$card = $presenter->card( $l1 );
$check( 'presenter card: url, price, farm, new badge, published ago', str_contains( $card['url'], '/classifieds/listing/' ) && '$2,500 OBO' === $card['price'] && $card['farm']['name'] === $farm->name && $card['is_new'] && str_contains( $card['published_ago'], 'ago' ) );

echo "\n== 6. Directory integration ==\n";
$dq = $c->get( DirectoryQuery::class );
$check( 'directory stock filter (bulls) includes the farm; (semen) excludes', in_array( $farm_id, array_map( fn( $f ) => (int) $f->id, $dq->run( [ 'stock' => [ 'bulls' ], 'q' => (string) $farm->name ] )['items'] ), true ) && ! in_array( $farm_id, array_map( fn( $f ) => (int) $f->id, $dq->run( [ 'stock' => [ 'semen' ], 'q' => (string) $farm->name ] )['items'] ), true ) ); // other farms may sell semen
$check( 'stock options exposed to the directory', in_array( 'bulls', array_column( (array) apply_filters( 'sp/directory/stock_options', [] ), 'value' ), true ) );
$fc = $c->get( FarmPresenter::class )->card( $farms->find( $farm_id ) );
$check( 'farm card: light on, 2 active, new listing badge', 'on' === $fc['light'] && 2 === $fc['active_listings'] && $fc['new_listing'] );
$farm_page = $render_route( 0, 'farm', [ 'sp_slug' => (string) $farm->slug ], \SouthPoll\Membership\Frontend\Controllers\FarmPageController::class );
$check( 'farm page shows the active listings section with cards', str_contains( $farm_page, 'Active listings' ) && str_contains( $farm_page, 'sp-listing-card' ) && str_contains( $farm_page, $l1->title ) );

echo "\n== 7. Public REST ==\n";
$as( 0 );
$res = $rest( 'GET', 'listings', [ 'q' => 'bull ' . $stamp ] );
$check( 'GET listings (guest) → card html', 200 === $res->get_status() && 1 === count( $res->get_data()['items'] ) && str_contains( $res->get_data()['items'][0]['html'], 'sp-listing-card' ) );
$check( 'GET listings/filters + pins', 200 === $rest( 'GET', 'listings/filters' )->get_status() && count( $rest( 'GET', 'listings/pins' )->get_data()['items'] ) >= 1 );
$fx = $rest( 'GET', 'listings', [ 'cats' => [ 'bulls' ], 'farms' => [ $farm_id ] ] )->get_data()['meta']->facets ?? null;
$mo = $fx ? array_values( array_filter( $fx['states'], fn( $s ) => 'MO' === $s['value'] ) ) : [];
$mo_total = (int) $rest( 'GET', 'listings', [ 'cats' => [ 'bulls' ], 'farms' => [ $farm_id ], 'state' => 'MO' ] )->get_data()['total'];
$check( 'facets: state counts follow the category + farm filters', $fx && $mo && (int) $mo[0]['count'] === $mo_total && $mo_total >= 1, json_encode( $mo ) . " vs $mo_total" );
$cat_fx = $fx ? array_column( $fx['categories'], 'count', 'value' ) : [];
$all_fx = $rest( 'GET', 'listings', [ 'farms' => [ $farm_id ] ] )->get_data()['meta']->facets['categories'] ?? [];
$check( 'facets: the category dimension ignores its own selection', $fx && ( $cat_fx['bulls'] ?? 0 ) >= 1 && array_column( $all_fx, 'count', 'value' ) === $cat_fx, json_encode( $cat_fx ) );
$before = $repo->find( $l1->id )->view_count;
$res = $rest( 'POST', "listings/{$l1->id}/view" );
$check( 'POST view counts for guests', 200 === $res->get_status() && true === $res->get_data()['counted'] && $repo->find( $l1->id )->view_count === $before + 1 );
$as( $owner );
$check( 'owner viewing own listing is not counted', false === $rest( 'POST', "listings/{$l1->id}/view" )->get_data()['counted'] );

echo "\n== 8. My Listings REST + media ==\n";
$as( $owner );
$res = $rest( 'GET', 'my-listings' );
$check( 'GET my-listings payload', 200 === $res->get_status() && count( $res->get_data()['items'] ) >= 2 && isset( $res->get_data()['summary']['remaining'] ) );
$res = $rest( 'POST', 'my-listings', [ 'title' => 'REST draft ' . $stamp ] );
$rest_id = (int) ( $res->get_data()['id'] ?? 0 ); $created[] = $rest_id;
$check( 'POST my-listings creates a draft + edit url', 200 === $res->get_status() && $rest_id > 0 && str_contains( $res->get_data()['edit_url'], 'edit-listing' ) );
$res = $rest( 'POST', "my-listings/{$rest_id}/image", [], [ 'file' => $png( 900, 700 ) ] );
$check( 'featured image upload', 200 === $res->get_status() && $repo->find( $rest_id )->featured_id > 0 && (int) get_post_meta( $repo->find( $rest_id )->featured_id, '_sp_listing_id', true ) === $rest_id, json_encode( $res->get_data()['message'] ?? $res->get_data() ) );
$res = $rest( 'POST', "my-listings/{$rest_id}/media", [], [ 'file' => $png( 800, 600 ) ] );
$check( 'gallery image added', 200 === $res->get_status() && 1 === count( $repo->find( $rest_id )->gallery ) );
$img_key = $res->get_data()['items'][0]['key'] ?? '';
$res = $rest( 'POST', "my-listings/{$rest_id}/media", [ 'video_url' => 'https://vimeo.com/123456789' ] );
$check( 'gallery video added', 200 === $res->get_status() && 2 === count( $repo->find( $rest_id )->gallery ) && 'vimeo' === ( $res->get_data()['items'][1]['provider'] ?? '' ) );
$vid_key = $res->get_data()['items'][1]['key'] ?? '';
$rest( 'POST', "my-listings/{$rest_id}/media/reorder", [ 'keys' => [ $vid_key, $img_key ] ] );
$check( 'gallery reorder', $vid_key === $repo->find( $rest_id )->gallery[0]['key'] );
$rest( 'PATCH', "my-listings/{$rest_id}/media/{$img_key}", [ 'caption' => 'Spring pasture' ] );
$check( 'caption saved', 'Spring pasture' === $repo->find( $rest_id )->gallery[1]['caption'] );
$g_att = (int) $repo->find( $rest_id )->gallery[1]['id'];
$res = $rest( 'DELETE', "my-listings/{$rest_id}/media/{$img_key}" );
$check( 'gallery item removed (+ attachment)', 200 === $res->get_status() && 1 === count( $repo->find( $rest_id )->gallery ) && null === get_post( $g_att ) );
$check( 'gallery lists featured first on the page', 'featured' === ( $presenter->gallery( $repo->find( $rest_id ) )[0]['key'] ?? '' ) );
$res = $rest( 'POST', "my-listings/{$rest_id}/toggle" );
$check( 'toggle live from REST (publish)', 200 === $res->get_status() && 'publish' === $repo->find( $rest_id )->post_status, json_encode( $res->get_data()['message'] ?? $res->get_data() ) );
$res = $rest( 'POST', "my-listings/{$rest_id}/status", [ 'status' => 'pending' ] );
$check( 'status via REST', 'pending' === $repo->find( $rest_id )->status );
$res = $rest( 'POST', "my-listings/{$rest_id}/duplicate" );
$dup_id = (int) ( $res->get_data()['id'] ?? 0 );
$check( 'duplicate via REST', $dup_id > 0 && 'draft' === $repo->find( $dup_id )->post_status );
$res = $rest( 'DELETE', "my-listings/{$dup_id}" );
$check( 'delete via REST', 200 === $res->get_status() && null === $repo->find( $dup_id ) );
$as( $buyer );
$check( 'buyer cannot use my-listings (403)', 403 === $rest( 'GET', 'my-listings' )->get_status() && 404 !== $rest( 'DELETE', "my-listings/{$l1->id}" )->get_status() );

echo "\n== 9. Page renders ==\n";
$h = $render_route( 0, 'classifieds' );
$check( 'archive (guest): heading, cards, save-search button, filters', str_contains( $h, 'spClassifieds(' ) && str_contains( $h, $l1->title ) && str_contains( $h, 'Save this search' ) && str_contains( $h, 'sp-cl-farms' ), strlen( $h ) . ' bytes' );
$h = $render_route( 0, 'category', [ 'sp_category' => 'bulls' ] );
$check( 'category page: bulls listing only, SEO title', str_contains( $h, $l1->title ) && ! str_contains( $h, $l2->title ) && str_contains( $fresh( ClassifiedsController::class )->title( new RouteMatch( 'category', Routes::get( 'category' ), [ 'sp_category' => 'bulls' ] ) ), 'Bulls for sale' ) );
$h = $render_route( 0, 'category', [ 'sp_category' => 'no-such-cat' ] );
$check( 'unknown category → not-found page', str_contains( $h, 'Category not found' ) );
$h = $render_route( 0, 'listing', [ 'sp_slug' => $l1->slug ], ListingPageController::class );
$check( 'single (guest): title, price, inquiry form, farm block, related, no email', str_contains( $h, $l1->title ) && str_contains( $h, '$2,500 OBO' ) && str_contains( $h, 'Ask about this listing' ) && str_contains( $h, 'About the farm' ) && str_contains( $h, $l2->title ) && ! str_contains( $h, "farm-$stamp@example.test" ), strlen( $h ) . ' bytes' );
$h = $render_route( $buyer, 'listing', [ 'sp_slug' => $l1->slug ], ListingPageController::class );
$check( 'single (verified buyer): seller contact shown', str_contains( $h, "farm-$stamp@example.test" ) );
$h = $render_route( $owner, 'listing', [ 'sp_slug' => $l1->slug ], ListingPageController::class );
$check( 'single (owner): "how buyers see" notice', str_contains( $h, 'This is how buyers see your listing' ) );
$check( 'single title = title – price – farm', str_contains( $fresh( ListingPageController::class )->title( new RouteMatch( 'listing', Routes::get( 'listing' ), [ 'sp_slug' => $l1->slug ] ) ), $l1->title . ' – $2,500 OBO' ) );
$service->toggle_active( $ctx_o(), $repo->find( $rest_id ) ); // pause → unavailable
$h = $render_route( 0, 'listing', [ 'sp_slug' => $repo->find( $rest_id )->slug ], ListingPageController::class );
$check( 'paused listing (guest): "no longer available" + farm link', str_contains( $h, 'no longer available' ) && str_contains( $h, (string) $farm->name ) );
$h = $render_route( $owner, 'listing', [ 'sp_slug' => $repo->find( $rest_id )->slug ], ListingPageController::class );
$check( 'paused listing (owner): preview notice', str_contains( $h, 'Only you can see this listing' ) );
$h = $render_route( 0, 'listing', [ 'sp_slug' => 'no-such-listing-xyz' ], ListingPageController::class );
$check( 'unknown listing → not-found page', str_contains( $h, 'Listing not found' ) );
$h = $render_section( $owner, 'my-listings' );
$check( 'My Listings renders table + stats + new-listing form', str_contains( $h, 'spMyListings(' ) && str_contains( $h, 'value="create"' ) && str_contains( $h, 'Remaining of' ) );
$h = $render_section( $owner, 'edit-listing', [ 'id' => (string) $l1->id ] );
$check( 'editor renders with values + categories + gallery manager', str_contains( $h, 'spListingEditor(' ) && str_contains( $h, 'value="' . esc_attr( $l1->title ) . '"' ) && str_contains( $h, 'name="categories[]"' ) && str_contains( $h, 'Add a YouTube or Vimeo video' ) && str_contains( $h, 'Update listing' ), strlen( $h ) . ' bytes' );
$h = $render_section( $owner, 'dashboard' );
$check( 'owner dashboard: My listings card with counts', str_contains( $h, 'My listings' ) && str_contains( $h, 'remaining of' ) );
$_GET = [];
$h = do_shortcode( '[classifieds category="bulls" per_page="5"]' );
$check( '[classifieds] shortcode renders', str_contains( $h, 'spClassifieds(' ) && str_contains( $h, $l1->title ) && ! str_contains( $h, $l2->title ) );

echo "\n== 10. Saved searches ==\n";
$as( $buyer );
$res = $rest( 'POST', 'saved-searches', [ 'params' => [ 'cats' => [ 'bulls' ], 'state' => 'MO' ] ] );
$ss_id = (int) ( $res->get_data()['item']['id'] ?? 0 );
$check( 'create saved search (auto-named, notify default off)', 200 === $res->get_status() && $ss_id > 0 && str_contains( $res->get_data()['item']['name'], 'Bulls' ) && false === $res->get_data()['item']['notify'], json_encode( $res->get_data()['item'] ?? $res->get_data() ) );
$res = $rest( 'POST', 'saved-searches', [ 'params' => [ 'state' => 'mo', 'cats' => 'bulls' ] ] );
$check( 'identical params → already_saved', true === ( $res->get_data()['already_saved'] ?? false ) && $ss_id === (int) $res->get_data()['item']['id'] );
$check( 'empty params refused (422)', 422 === $rest( 'POST', 'saved-searches', [ 'params' => [] ] )->get_status() );
$res = $rest( 'PATCH', "saved-searches/{$ss_id}", [ 'notify' => true, 'name' => 'Missouri bulls' ] );
$check( 'PATCH notify + name', 200 === $res->get_status() && $res->get_data()['item']['notify'] && 'Missouri bulls' === $res->get_data()['item']['name'] && ! isset( $res->get_data()['item']['is_default'] ) );
$res = $rest( 'GET', 'saved-searches' );
$check( 'GET saved-searches with week count ≥ 1', 1 === count( $res->get_data()['items'] ) && $res->get_data()['items'][0]['week_count'] >= 1, json_encode( [ 'items' => count( $res->get_data()['items'] ), 'week' => $res->get_data()['items'][0]['week_count'] ?? null, 'direct' => $query->count_since( [ 'cats' => [ 'bulls' ], 'state' => 'MO' ], Dates::add_days( Dates::now(), -7 ) ), 'run' => $query->run( [ 'cats' => [ 'bulls' ], 'state' => 'MO' ] )['total'], 'last_error' => $wpdb->last_error ] ) );
$h = $render_route( $buyer, 'classifieds' );
$check( 'classifieds page: no auto-applied search, quick-apply menu lists the saved search', ! str_contains( $h, 'default search' ) && str_contains( $h, $l2->title ) && str_contains( $h, 'sp-saved-bar' ) && str_contains( $h, 'Missouri bulls' ) );
$res = $rest( 'POST', "saved-searches/{$ss_id}/share" );
$token = basename( rtrim( (string) ( $res->get_data()['share_url'] ?? '' ), '/' ) );
$check( 'share token issued', 200 === $res->get_status() && strlen( $token ) >= 10 );
$h = $render_route( 0, 'search-share', [ 'sp_token' => $token ] );
$check( 'shared search page (guest) applies the filters', str_contains( $h, 'Shared search' ) && str_contains( $h, $l1->title ) && ! str_contains( $h, $l2->title ) );
$h = $render_section( $buyer, 'saved-searches' );
$check( 'Saved Searches page renders', str_contains( $h, 'spSavedSearches(' ) && str_contains( $h, 'Missouri bulls' ) );
$ss_desc = $ssvc->describe( [ 'cats' => [ 'bulls' ], 'state' => 'MO', 'since' => 'week' ] );
$check( 'describe()', str_contains( $ss_desc, 'Bulls' ) && str_contains( $ss_desc, 'Missouri' ) && str_contains( $ss_desc, 'last week' ), $ss_desc );

echo "\n== 11. Listing favorites ==\n";
$fav_draft = $service->create_draft( $ctx_o(), [ 'title' => 'Private draft ' . $stamp ] ); $created[] = $fav_draft->id;
$as( $buyer );
$res = $rest( 'POST', 'favorites/toggle', [ 'type' => 'listing', 'id' => $fav_draft->id ] );
$check( 'buyer cannot favorite another farm\'s draft listing (404, review fix #5)', 404 === $res->get_status(), (string) $res->get_status() );
$res = $rest( 'POST', 'favorites/toggle', [ 'type' => 'listing', 'id' => $l1->id ] );
$fav_l = (int) ( $res->get_data()['favorite_id'] ?? 0 );
$check( 'buyer favorites a listing', 200 === $res->get_status() && $res->get_data()['added'] && $fav_l > 0 );
$res = $rest( 'GET', 'favorites', [ 'type' => 'listing' ] );
$check( 'GET favorites (listing) → card html + active', 1 === count( $res->get_data()['items'] ) && str_contains( $res->get_data()['items'][0]['html'], 'sp-listing-card' ) && $res->get_data()['items'][0]['active'] );
$check( 'unknown listing cannot be favorited (404)', 404 === $rest( 'POST', 'favorites/toggle', [ 'type' => 'listing', 'id' => 99999999 ] )->get_status() );
$h = $render_section( $buyer, 'my-favorites' );
$check( 'My Favorites renders both tabs with the listing', str_contains( $h, 'spMyFavorites(' ) && str_contains( $h, $l1->title ) && str_contains( $h, 'Share my favorites list' ) );
$h = $render_section( $buyer, 'dashboard' );
$check( 'buyer dashboard favorites card lists the listing', str_contains( $h, 'Favorites' ) && str_contains( $h, $l1->title ) );
$res = $rest( 'POST', 'favorites/share-token' );
$share_tok = basename( rtrim( (string) ( $res->get_data()['share_url'] ?? '' ), '/' ) );
$h = $render_route( 0, 'favorites-share', [ 'sp_token' => $share_tok ], \SouthPoll\Membership\Frontend\Controllers\FavoritesShareController::class );
$check( 'public shared favorites page shows the listing', str_contains( $h, 'favorites' ) && str_contains( $h, $l1->title ) );

echo "\n== 12. Alerts pipeline ==\n";
$as( $buyer );
$rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => $farm_id ] );
$fav_f = $favorites->find_for( $buyer, 'farm', $farm_id );
$check( 'buyer favorites the farm (setup)', null !== $fav_f );
$favorites->set_notify( (int) $fav_f->id, true );
$favorites->set_notify( $fav_l, true );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}sp_notification_queue WHERE user_id = %d", $buyer ) );
delete_transient( 'sp_alert_farm_' . $buyer . '_' . $farm_id );
$wpdb->update( "{$p}sp_saved_searches", [ 'last_notified_at' => null ], [ 'id' => $ss_id ] );
$as( $owner );
$d3 = $service->create_draft( $ctx_o() ); $created[] = $d3->id;
$l3 = $editor->save( $ctx_o(), $d3, [ 'title' => 'Yearling bull ' . $stamp, 'content' => 'Yearling bull, docile.', 'categories' => [ $cats['bulls'] ], 'status' => 'available', 'price' => '1500' ], 'publish' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email_key FROM {$p}sp_notification_queue WHERE user_id = %d AND sent_at IS NULL", $buyer ) );
$qkeys = array_map( fn( $r ) => $r->email_key, $rows );
$check( 'publish queues favorite-farm + saved-search alerts for the buyer', ! is_wp_error( $l3 ) && in_array( 'alert.favorite_farm', $qkeys, true ) && in_array( 'alert.saved_search', $qkeys, true ), implode( ',', $qkeys ) );
$d4 = $service->create_draft( $ctx_o() ); $created[] = $d4->id;
$editor->save( $ctx_o(), $d4, [ 'title' => 'Another bull ' . $stamp, 'content' => 'Another bull.', 'categories' => [ $cats['bulls'] ], 'status' => 'available' ], 'publish' );
$n_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sp_notification_queue WHERE user_id = %d AND sent_at IS NULL", $buyer ) );
$check( 'throttle: a second publish within the hour queues nothing new (D-22)', 2 === $n_after, (string) $n_after );
$sent = [];
$stats = $alerts->process();
$check( 'queue worker sends both alerts', $stats['sent'] >= 2 && 0 === $stats['failed'] && in_array( 'alert.favorite_farm', $keys(), true ) && in_array( 'alert.saved_search', $keys(), true ), json_encode( $stats ) . ' ' . implode( ',', $keys() ) ); // other accounts (demo buyer) may be alerted too
$alert_msg = $sent[0];
$check( 'alert emails are configurable + carry an unsubscribe link', $alert_msg->configurable() && str_contains( (string) $alert_msg->get( 'unsubscribe_url' ), '/unsubscribe/' ) );
$sent = [];
$service->set_status( $ctx_o(), $repo->find( $l1->id ), 'pending' );
$stats = $alerts->process();
$check( 'status change on a favorited listing → favorite_listing alert', 1 === $stats['sent'] && in_array( 'alert.favorite_listing', $keys(), true ), implode( ',', $keys() ) );
$members->update( (int) $bm->id, [ 'notify_all' => 0 ] );
$sent = [];
$service->set_status( $ctx_o(), $repo->find( $l1->id ), 'available' );
$stats = $alerts->process();
$check( 'master "optional emails" off suppresses alerts', 0 === $stats['sent'] );
$members->update( (int) $bm->id, [ 'notify_all' => 1 ] );
$unsub = $c->get( UnsubscribeController::class );
$key   = Urls::unsubscribe_key( $buyer, 'search', $ss_id );
$check( 'unsubscribe link turns the saved-search alert off; bad key refused', $unsub->apply( $buyer, 'search', $ss_id, $key )['ok'] && 0 === (int) $searches->find( $ss_id )->notify && ! $unsub->apply( $buyer, 'search', $ss_id, 'bad' )['ok'] );
$check( 'alerts skip the farm\'s own people', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sp_notification_queue WHERE user_id = %d AND email_key LIKE 'alert.%%'", $owner ) ) ); // the owner's own queued *notices* (listing expiring, T6.9) are fine

echo "\n== 13. Listing cron: expiry + reminders ==\n";
$repo->update( $l3->id, [ 'expires_at' => Dates::add_days( Dates::now(), -1 ) ] );
$repo->update( $repo->find( $d4->id )->id, [ 'expires_at' => Dates::add_days( Dates::now(), 3 ) ] );
$sent = [];
$stats = $lrun();
$check( 'expired listing → sp_expired + email', $stats['expired'] >= 1 && 'sp_expired' === $repo->find( $l3->id )->post_status && in_array( 'listing.expired', $keys(), true ), json_encode( $stats ) );
$check( 'expiring listing → reminder email with renew link', $stats['reminders'] >= 1 && in_array( 'listing.expiring', $keys(), true ) && str_contains( (string) array_values( array_filter( $sent, fn( $m ) => 'listing.expiring' === $m->key() ) )[0]->get( 'renew_url' ), 'renew=1' ) );
$sent = [];
$stats = $lrun();
$check( 'cron idempotent (no second reminder)', 0 === $stats['reminders'] && 0 === $stats['expired'] );
$check( 'expired listing page (guest) → no longer available', str_contains( $render_route( 0, 'listing', [ 'sp_slug' => $repo->find( $l3->id )->slug ], ListingPageController::class ), 'no longer available' ) );
$check( 'farm stats exclude expired', 3 === (int) $farms->find( $farm_id )->active_listing_count, (string) $farms->find( $farm_id )->active_listing_count );

echo "\n== 14. Membership gating (freeze + restore) ==\n";
$before_exp = $repo->find( $l1->id )->expires_at;
$engine->expire( $mrepo->for_farm( $farm_id ), 'test' );
$hidden = $repo->find( $l1->id );
$check( 'membership lapse hides live listings with frozen clock', 'draft' === $hidden->post_status && 'membership' === $hidden->hidden_reason && $hidden->frozen_seconds > 0 && 0 === $query->run( [ 'farms' => [ $farm_id ] ] )['total'] && 0 === (int) $farms->find( $farm_id )->active_listing_count );
$h = $render_section( $owner, 'edit-listing', [ 'id' => (string) $l1->id ] );
$check( 'S1: membership inactive → editor explains, links Manage membership, Publish disabled', str_contains( $h, 'Publish is not available yet' ) && str_contains( $h, 'Manage membership' ) && str_contains( $h, 'Hidden while the farm membership is inactive' ) && preg_match( '/<button[^>]*disabled[^>]*>Publish<\/button>/', $h ), strlen( $h ) . ' bytes' );
$res = $editor->save( $ctx_o(), $hidden, [ 'title' => $l1->title . ' edited', 'content' => $l1->content, 'categories' => [ $cats['bulls'] ], 'status' => 'available' ], 'publish' );
$check( 'S1: publish while membership inactive → sp_membership_inactive, edits kept, still hidden', is_wp_error( $res ) && 'sp_membership_inactive' === $res->get_error_code() && $l1->title . ' edited' === $repo->find( $l1->id )->title && 'draft' === $repo->find( $l1->id )->post_status && 'membership' === $repo->find( $l1->id )->hidden_reason, is_wp_error( $res ) ? $res->get_error_code() : 'published' );
$repo->update( $l1->id, [ 'title' => $l1->title ] );
$engine->activate( $farm_id, $tier, [ 'source' => 'offline', 'actor' => $admin ] );
$restored = $repo->find( $l1->id );
$check( 'membership restore re-publishes with the frozen time re-applied', 'publish' === $restored->post_status && '' === $restored->hidden_reason && null === $restored->frozen_seconds && abs( Dates::days_until( $restored->expires_at ) - Dates::days_until( $before_exp ) ) <= 1 && $query->run( [ 'farms' => [ $farm_id ] ] )['total'] >= 2 );

echo "\n== 15. Listing field groups ==\n";
$grp = $fields->create_group( 'listing', 'Animal details ' . $stamp, [ $cats['bulls'] ] );
$fld = $fields->create( 'listing', [ 'label' => 'Age ' . $stamp, 'type' => 'text', 'required' => true, 'group_id' => (int) $grp->id ] );
$check( 'group + required field created', ! is_wp_error( $grp ) && ! is_wp_error( $fld ) && (int) $fld->group_id === (int) $grp->id );
$check( 'listing fields need a group', is_wp_error( $fields->create( 'listing', [ 'label' => 'Orphan' ] ) ) );
$check( 'groups_for_categories: bulls yes, females no', 1 === count( array_filter( $fields->groups_for_categories( [ $cats['bulls'] ] ), fn( $g ) => (int) $g->id === (int) $grp->id ) ) && 0 === count( array_filter( $fields->groups_for_categories( [ $cats['heifers'] ] ), fn( $g ) => (int) $g->id === (int) $grp->id ) ) );
$res = $editor->save( $ctx_o(), $repo->find( $l1->id ), [ 'title' => $l1->title, 'content' => $l1->content, 'categories' => [ $cats['bulls'] ], 'status' => 'available', 'meta' => [] ], 'publish' );
$check( 'required group field enforced on publish', is_wp_error( $res ) && in_array( 'meta_' . $fld->field_key, $res->get_error_codes(), true ), is_wp_error( $res ) ? implode( ',', $res->get_error_codes() ) : 'saved' );
$res = $editor->save( $ctx_o(), $repo->find( $l1->id ), [ 'title' => $l1->title, 'content' => $l1->content, 'categories' => [ $cats['bulls'] ], 'status' => 'available', 'meta' => [ $fld->field_key => '3 years' ] ], 'publish' );
$check( 'field value saved + shown on the listing page in its group', ! is_wp_error( $res ) && '3 years' === ( $res->meta[ $fld->field_key ] ?? '' ) && str_contains( $render_route( 0, 'listing', [ 'sp_slug' => $l1->slug ], ListingPageController::class ), 'Animal details ' . $stamp ) );
$h = $render_section( $owner, 'edit-listing', [ 'id' => (string) $l1->id ] );
$check( 'editor shows the group with the value', str_contains( $h, 'name="meta[' . $fld->field_key . ']"' ) && str_contains( $h, 'value="3 years"' ) );
wp_set_current_user( $admin );
require_once ABSPATH . 'wp-admin/includes/screen.php'; require_once ABSPATH . 'wp-admin/includes/template.php';
$_GET = [ 'scope' => 'listing' ];
ob_start(); $c->get( \SouthPoll\Membership\Admin\Pages\MetaFieldsPage::class )->render(); $h = (string) ob_get_clean(); $_GET = [];
$check( 'admin Meta Fields listing tab shows the group', str_contains( $h, 'Animal details ' . $stamp ) && str_contains( $h, 'Add a field group' ) );
$fields->delete_group( (int) $grp->id );
$check( 'delete group removes its field + listing values', null === $fields->find( (int) $fld->id ) && ! isset( $repo->find( $l1->id )->meta[ $fld->field_key ] ) );

echo "\n== 16. Inquiry on a listing ==\n";
$settings->set( 'recaptcha_enabled', false );
RateLimiter::reset( 'inquiry:ip' . substr( hash_hmac( 'sha256', '127.0.0.1', wp_salt( 'auth' ) ), 0, 32 ) );
$as( 0 ); $sent = [];
$res = $rest( 'POST', 'inquiries/listing', [ 'listing_id' => $l1->id, 'name' => 'Jane Buyer', 'email' => 'jane@example.test', 'message' => 'Is this bull still available for pickup in May?', 'elapsed' => 8 ] );
$check( 'listing inquiry sent to the farm with Reply-To', 200 === $res->get_status() && 'listing.inquiry' === ( $sent[0] ?? null )?->key() && 'jane@example.test' === $sent[0]->reply_to() && in_array( "farm-$stamp@example.test", $sent[0]->recipients(), true ), json_encode( $res->get_data() ) );
$check( 'inquiry logged against the listing', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sp_inquiries WHERE listing_id = %d AND target_type = 'listing'", $l1->id ) ) );
$check( 'inquiry on an unavailable listing → 404', 404 === $rest( 'POST', 'inquiries/listing', [ 'listing_id' => $l3->id, 'name' => 'Jane', 'email' => 'jane@example.test', 'message' => 'Still around? Asking about it.', 'elapsed' => 8 ] )->get_status() );

// Tidy: delete the suite's listings and saved search; leave the farm active and the buyer's favorites cleared.
$as( $owner );
foreach ( array_unique( $created ) as $lid ) { $l = $repo->find( (int) $lid ); if ( $l ) { $service->delete( $ctx_o(), $l ); } }
$wpdb->delete( "{$p}sp_saved_searches", [ 'user_id' => $buyer ] );
$wpdb->delete( "{$p}sp_favorites", [ 'user_id' => $buyer ] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}sp_notification_queue WHERE user_id = %d", $buyer ) );
$service->refresh_farm_stats( $farm_id );
$ctxs->forget();

$c->get( SouthPoll\Membership\Core\Settings::class )->set( 'email_window_start', $saved_window );
echo "\n== Summary: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
