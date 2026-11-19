<?php
/**
 * test-phase2-farms.php — Phase 2 (farms & member directory) suite: schema/seeds, farm editor, geocoder (fake
 * transport), media/gallery, directory query + REST, farm page render states, favorites, inquiries, shortcode,
 * My Farm editor render, admin meta fields, and membership gating hooks. Uses the throwaway accounts from
 * test-phase1-auth.php (run that first). Restores every setting/integration it touches.
 *
 * Run: ./wp-run.sh test-phase2-farms.php
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

use SouthPoll\Membership\Admin\Pages\MetaFieldsPage;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Farms\DirectoryQuery;
use SouthPoll\Membership\Farms\FarmEditor;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\Geocoder;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\Controllers\DashboardController;
use SouthPoll\Membership\Frontend\Controllers\FarmPageController;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Rest\RateLimiter;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Routes;
use SouthPoll\Membership\Support\Dates;

$c     = sp_membership();
$pass  = 0; $fail = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) { $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS ' : 'FAIL ' ), $label, $detail ? " — $detail" : '', "\n"; };
$stamp = substr( (string) time(), -6 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
global $wpdb;
$p = $wpdb->prefix;

$settings     = $c->get( Settings::class );
$integrations = $c->get( Integrations::class );
$farms        = $c->get( FarmRepository::class );
$ctxs         = $c->get( ContextProvider::class );
$members      = $c->get( MemberRepository::class );
$mrepo        = $c->get( MembershipRepository::class );
$engine       = $c->get( StatusEngine::class );
$offerings    = $c->get( Offerings::class );
$editor       = $c->get( FarmEditor::class );
$meta         = $c->get( FarmMetaRepository::class );
$media        = $c->get( FarmMediaRepository::class );
$uploader     = $c->get( MediaUploader::class );
$geo          = $c->get( Geocoder::class );
$query        = $c->get( DirectoryQuery::class );
$presenter    = $c->get( FarmPresenter::class );
$favorites    = $c->get( FavoriteRepository::class );
$fields       = $c->get( MetaFieldRepository::class );

// Capture emails instead of sending.
$sent = [];
add_filter( 'sp/email/before_send', function ( $msg ) use ( &$sent ) { $sent[] = $msg; return false; } );

// Restore anything we touch, even on fatal.
$restore = [ 'recaptcha_enabled' => $settings->get( 'recaptcha_enabled' ), 'inquiry_admin_copy' => $settings->get( 'inquiry_admin_copy' ), 'geocoding_key' => $integrations->get( 'google.geocoding_key', '' ) ];
register_shutdown_function( function () use ( $settings, $integrations, $restore ) {
	$settings->set( 'recaptcha_enabled', $restore['recaptcha_enabled'] );
	$settings->set( 'inquiry_admin_copy', $restore['inquiry_admin_copy'] );
	$integrations->set( 'google.geocoding_key', $restore['geocoding_key'] );
} );

// Accounts + deterministic state: owner farm active for a year, buyer verified, other test farm active.
$owner = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-owner-new-%@example.test' ORDER BY ID DESC LIMIT 1" );
$buyer = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 'sp-buyer-%@example.test' ORDER BY ID DESC LIMIT 1" );
$admin = (int) $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='{$p}capabilities' WHERE m.meta_value LIKE '%administrator%' ORDER BY u.ID LIMIT 1" );
if ( ! $owner || ! $buyer ) { die( "Run test-phase1-auth.php first to create test accounts.\n" ); }
$farm    = $farms->find_by_owner( $owner );
$farm_id = (int) $farm->id;
$other   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}sp_farms WHERE id <> %d AND owner_user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%%@example.test') ORDER BY id LIMIT 1", $farm_id ) );
$tier    = $c->get( TierRepository::class )->default();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}sp_payments WHERE farm_id = %d AND status = 'pending'", $farm_id ) );
$mrepo->update( (int) $mrepo->ensure( $farm_id )->id, [ 'status' => 'active', 'starts_at' => Dates::now(), 'ends_at' => Dates::add_days( Dates::now(), 365 ), 'auto_renew' => 0, 'reminders_sent' => [], 'expired_at' => null ] );
$bm = $members->find_by_user( $buyer ) ?? $members->create_for_user( $buyer );
$members->update( (int) $bm->id, [ 'email_verified_at' => Dates::now(), 'default_notify_favorites' => 0 ] );
$other_membership = $other ? $mrepo->ensure( (int) $other->id ) : null;
$farms->set_listing_stats( $farm_id, 0, null ); // this suite drives the denormalised counter itself; restored from real listings at the end
$ctxs->forget();
echo "owner #{$owner} (farm #{$farm_id}), buyer #{$buyer}, admin #{$admin}, other farm #" . ( $other ? $other->id : 'none' ) . "\n\n";

$render_section = function ( int $uid, string $route ) use ( $c, $ctxs ): string {
	wp_set_current_user( $uid ); $ctxs->forget();
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/members/' . $route . '/';
	ob_start();
	try { $c->get( DashboardController::class )->render( new RouteMatch( $route, Routes::get( $route ) ) ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
	return (string) ob_get_clean();
};
$render_farm = function ( int $uid, string $slug ) use ( $c, $ctxs ): string {
	wp_set_current_user( $uid ); $ctxs->forget();
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/farms/' . $slug . '/';
	$controller = $c->get( FarmPageController::class );
	// Fresh instance per render (the controller memoises the slug lookup).
	$ref = new ReflectionClass( $controller );
	foreach ( [ 'farm' => null, 'looked_up' => false ] as $prop => $val ) { $rp = $ref->getProperty( $prop ); $rp->setAccessible( true ); $rp->setValue( $controller, $val ); }
	ob_start();
	try { $controller->render( new RouteMatch( 'farm', Routes::get( 'farm' ), [ 'sp_slug' => $slug ] ) ); } catch ( \Throwable $e ) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
	return (string) ob_get_clean();
};
$rest = function ( string $method, string $path, array $params = [], array $files = [] ) {
	$req = new WP_REST_Request( $method, '/sp/v1/' . ltrim( $path, '/' ) );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( $files ) { $req->set_file_params( $files ); }
	return rest_do_request( $req );
};
$png = function ( int $w, int $h ): array {
	$path = tempnam( sys_get_temp_dir(), 'sp' ) . '.png';
	$im   = imagecreatetruecolor( $w, $h ); imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 141, 24, 60 ) ); imagepng( $im, $path ); imagedestroy( $im );
	return [ 'name' => 'test-logo.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ];
};

echo "== 1. Schema 0.3.0 + seeds ==\n";
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}sp_farms", 0 );
$check( 'sp_farms has region + active_listing_count', in_array( 'region', $cols, true ) && in_array( 'active_listing_count', $cols, true ) );
$check( 'db version ≥ 0.3.0', version_compare( (string) get_option( 'sp_db_version' ), '0.3.0', '>=' ), (string) get_option( 'sp_db_version' ) );
$all_off = $offerings->all();
$check( 'offerings seeded (5 classes, bulls present)', count( $all_off ) >= 5 && in_array( 'bulls', array_map( fn( $o ) => $o->slug, $all_off ), true ), implode( ',', array_map( fn( $o ) => $o->slug, $all_off ) ) );
$check( 'existing farm region backfilled from state', 'Midwest' === (string) $farms->find( $farm_id )->region, (string) $farms->find( $farm_id )->region );

echo "\n== 2. Farm editor (owner) ==\n";
$wpdb->update( "{$p}sp_farms", [ 'lat' => null, 'lng' => null, 'geocoded_at' => null ], [ 'id' => $farm_id ] ); // idempotent across re-runs
$ctx  = $ctxs->for_user( $owner );
$by_slug = []; foreach ( $all_off as $o ) { $by_slug[ $o->slug ] = $o->id; }
$bad  = $editor->save( $ctx, [ 'name' => 'X', 'email' => 'nope', 'zip' => '12', 'hours' => [ 'tue' => [ 'open' => 1, 'from' => '09:00', 'to' => '08:00' ] ] ] );
$check( 'validation: short name, bad email, bad zip, bad hours rejected', is_wp_error( $bad ) && count( array_intersect( [ 'name', 'email', 'zip', 'hours_tue' ], $bad->get_error_codes() ) ) === 4, is_wp_error( $bad ) ? implode( ',', $bad->get_error_codes() ) : 'no error' );
$name = 'Phase Two Ranch ' . $stamp;
$res  = $editor->save( $ctx, [
	'name' => $name, 'slogan' => 'Grass fed, grass finished', 'breeder_number' => 'SP-' . $stamp, 'address1' => '123 Pasture Ln', 'city' => 'Kearney', 'state' => 'MO', 'zip' => '64060',
	'phone' => '8166298010', 'email' => "farm-$stamp@example.test", 'website' => 'example.com', 'owner_name' => 'Test Owner',
	'about' => '<p>Grass <strong>fed</strong> since 2010.</p><script>alert(1)</script>',
	'social' => [ 'facebook' => 'facebook.com/spgca', 'instagram' => '' ],
	'hours' => [ 'mon' => [ 'open' => 1, 'from' => '08:00', 'to' => '17:00' ], 'tue' => [ 'open' => 1, 'from' => '08:00', 'to' => '17:00' ], 'sat' => [ 'open' => 0 ] ],
	'offerings' => [ $by_slug['bulls'], $by_slug['cows'] ],
	'meta' => [ 'forage_type' => 'Fescue', 'hay_type' => 'Bermuda rolls', 'feeding_notes' => 'Hay in winter' ],
	'contact_form_enabled' => '1',
] );
$farm = $farms->find( $farm_id );
$check( 'save ok + name/slug updated', ! is_wp_error( $res ) && $farm->name === $name && str_starts_with( $farm->slug, 'phase-two-ranch' ), is_wp_error( $res ) ? $res->get_error_message() : $farm->slug );
$check( 'region auto-calculated (MO → Midwest)', 'Midwest' === $farm->region, (string) $farm->region );
$check( 'about sanitised (script stripped, strong kept)', ! str_contains( (string) $farm->about, '<script' ) && str_contains( (string) $farm->about, '<strong>' ) );
$check( 'website normalised to https', 'https://example.com' === $farm->website, (string) $farm->website );
$check( 'social stored with https prefix', str_starts_with( FarmRepository::social( $farm )['facebook'] ?? '', 'https://facebook.com' ) && ! isset( FarmRepository::social( $farm )['instagram'] ) );
$hours = FarmRepository::hours( $farm );
$check( 'hours decoded (mon open 08:00–17:00, sat closed)', $hours['mon']['open'] && '08:00' === $hours['mon']['from'] && ! $hours['sat']['open'] );
$check( 'hours rows grouped (Mon – Tue)', 'Mon – Tue' === ( FarmPresenter::hours_rows( $farm )[0]['days'] ?? '' ), (string) ( FarmPresenter::hours_rows( $farm )[0]['days'] ?? '' ) );
$check( 'offerings attached (2)', 2 === count( $offerings->for_farm( $farm_id ) ), implode( ',', array_map( fn( $o ) => $o->slug, $offerings->for_farm( $farm_id ) ) ) );
$vals = $meta->values( $farm_id );
$rows = $meta->display_rows( $farm_id );
$check( 'meta saved + displayed (S4 labels: Primary Forage Type, Hay Type)', 'Fescue' === ( $vals['forage_type'] ?? '' ) && 'Bermuda rolls' === ( $vals['hay_type'] ?? '' ) && in_array( 'Hay Type', array_column( $rows, 'label' ), true ) && in_array( 'Primary Forage Type', array_column( $rows, 'label' ), true ) && ! in_array( 'Cool Season', array_column( $rows, 'label' ), true ), json_encode( $vals ) . ' ' . json_encode( array_column( $rows, 'label' ) ) );
$check( 'contact form flag + breeder number saved', 1 === (int) $farm->contact_form_enabled && 'SP-' . $stamp === $farm->breeder_number );
$check( 'no geocode without key (lat null)', null === $farm->lat );
$ctxs->forget();
$check( 'buyer cannot save a farm', is_wp_error( $editor->save( $ctxs->for_user( $buyer ), [ 'name' => 'Hijack' ] ) ) );

echo "\n== 3. Geocoder (fake transport) ==\n";
$integrations->set( 'google.geocoding_key', 'TESTKEY' );
$calls = 0;
$geo->set_transport( function ( string $url ) use ( &$calls ) { $calls++; return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => 'OK', 'results' => [ [ 'geometry' => [ 'location' => [ 'lat' => 39.3639, 'lng' => -94.3622 ], 'location_type' => 'ROOFTOP' ], 'formatted_address' => 'Kearney, MO 64060, USA' ] ] ] ) ]; } );
$check( 'geocode_farm stores coordinates', $geo->geocode_farm( $farms->find( $farm_id ) ) && abs( (float) $farms->find( $farm_id )->lat - 39.3639 ) < 0.001 && ! empty( $farms->find( $farm_id )->geocoded_at ), (string) $farms->find( $farm_id )->lat );
$ctx2 = $ctxs->for_user( $owner );
$editor->save( $ctx2, [ 'city' => 'Liberty' ] );
$check( 'address change re-geocodes via sp/farm/address_changed', 2 === $calls, "calls={$calls}" );
$editor->save( $ctx2, [ 'city' => 'Kearney' ] );
$wpdb->update( "{$p}sp_farms", [ 'lat' => null, 'lng' => null, 'geocoded_at' => null ], [ 'id' => $farm_id ] );
// Geocode the test farm directly: backfill() works through every farm missing coordinates in id order, and on Local that queue now
// holds the 600+ migrated farms, which must not receive the fake transport's coordinates.
$ok_geo = $geo->geocode_farm( $farms->find( $farm_id ) );
$check( 'geocode_farm stores coordinates (status OK)', $ok_geo && 'OK' === $geo->last_status() && null !== $farms->find( $farm_id )->lat, $geo->last_status() );
$geo->set_transport( fn( $url ) => [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => 'REQUEST_DENIED', 'error_message' => 'API not enabled', 'results' => [] ] ) ] );
$bf = $geo->backfill( 1 );
$check( 'backfill stops on a service error without stamping the farm', 'REQUEST_DENIED' === $bf['error'] && 0 === $bf['geocoded'] && ( 0 === $bf['attempted'] || null === $farms->missing_coordinates( 1 )[0]->geocoded_at ), json_encode( $bf ) );
$geo->set_transport( function () { return new WP_Error( 'boom', 'network down' ); } );
$check( 'transport error → null, no exception', null === $geo->geocode( 'Kearney, MO' ) );
$integrations->set( 'google.geocoding_key', '' );
$geo->set_transport( null );
$check( 'unconfigured geocoder is inert', null === $geo->geocode( 'Kearney, MO' ) && ! $geo->configured() );

echo "\n== 4. Media: logo upload + gallery ==\n";
wp_set_current_user( $owner ); $ctxs->forget();
foreach ( $media->for_farm( $farm_id ) as $leftover ) { $media->delete( (int) $leftover->id ); } // idempotent across re-runs
$logo_id = $uploader->store( $png( 1200, 800 ), $farm_id, $owner, 'logo' );
$check( 'logo stored as attachment tagged with farm id', is_int( $logo_id ) && (int) get_post_meta( $logo_id, '_sp_farm_id', true ) === $farm_id, is_wp_error( $logo_id ) ? $logo_id->get_error_message() : "#{$logo_id}" );
$sizes = is_int( $logo_id ) ? array_keys( (array) ( wp_get_attachment_metadata( $logo_id )['sizes'] ?? [] ) ) : [];
$check( 'plugin image sizes generated (sp-logo, sp-card, sp-thumb)', count( array_intersect( [ 'sp-logo', 'sp-card', 'sp-thumb' ], $sizes ) ) === 3, implode( ',', $sizes ) );
$farms->set_image( $farm_id, 'logo', is_int( $logo_id ) ? $logo_id : null );
$check( 'presenter resolves logo url (sp-logo size)', str_contains( $presenter->card( $farms->find( $farm_id ) )['logo'], 'farm-' . $farm_id . '-logo' ), $presenter->card( $farms->find( $farm_id ) )['logo'] );
$bad_file = [ 'name' => 'x.txt', 'type' => 'text/plain', 'tmp_name' => tempnam( sys_get_temp_dir(), 'sp' ), 'error' => 0, 'size' => 10 ];
file_put_contents( $bad_file['tmp_name'], 'hello' );
$check( 'non-image rejected', is_wp_error( $uploader->store( $bad_file, $farm_id, $owner, 'gallery' ) ) );
$res = $rest( 'POST', 'my-farm/media', [], [ 'file' => $png( 900, 600 ) ] );
$check( 'REST gallery image upload', 200 === $res->get_status() && 'image' === ( $res->get_data()['item']['type'] ?? '' ), (string) $res->get_status() . ' ' . json_encode( $res->get_data()['message'] ?? $res->get_data() ) );
$img_row = (int) ( $res->get_data()['item']['id'] ?? 0 );
$res = $rest( 'POST', 'my-farm/media', [ 'video_url' => 'https://youtu.be/dQw4w9WgXcQ' ] );
$check( 'REST add YouTube video (nocookie embed)', 200 === $res->get_status() && str_contains( (string) ( $res->get_data()['item']['embed'] ?? '' ), 'youtube-nocookie' ), json_encode( $res->get_data()['item'] ?? $res->get_data() ) );
$vid_row = (int) ( $res->get_data()['item']['id'] ?? 0 );
$res = $rest( 'POST', 'my-farm/media', [ 'video_url' => 'https://example.com/not-a-video' ] );
$check( 'bad video url → 422', 422 === $res->get_status() );
$check( 'vimeo url parsed', 'vimeo' === ( FarmMediaRepository::parse_video( 'https://vimeo.com/123456789' )['provider'] ?? '' ) );
$res = $rest( 'POST', 'my-farm/media/reorder', [ 'ids' => [ $vid_row, $img_row ] ] );
$order = array_map( fn( $r ) => (int) $r->id, $media->for_farm( $farm_id ) );
$check( 'reorder persisted (video first)', [ $vid_row, $img_row ] === $order, implode( ',', $order ) );
$res = $rest( 'PATCH', "my-farm/media/{$img_row}", [ 'caption' => 'Spring pasture' ] );
$check( 'caption saved', 'Spring pasture' === (string) $media->find( $img_row )->caption );
$img_att = (int) $media->find( $img_row )->attachment_id;
$res = $rest( 'DELETE', "my-farm/media/{$img_row}" );
$check( 'delete removes row + its attachment', 200 === $res->get_status() && null === $media->find( $img_row ) && null === get_post( $img_att ) );
wp_set_current_user( $buyer ); $ctxs->forget();
$check( 'buyer cannot use my-farm media (403)', 403 === $rest( 'GET', 'my-farm/media' )->get_status() );

echo "\n== 5. Directory query ==\n";
if ( $other_membership ) { $mrepo->update( (int) $other_membership->id, [ 'status' => 'expired', 'expired_at' => Dates::now() ] ); }
$ids = fn( array $r ) => array_map( fn( $f ) => (int) $f->id, $r['items'] );
$run = fn( array $a ) => $query->run( $a + [ 'q' => $name, 'per_page' => 'all' ] ); // 501 migrated farms on Local: scope every directory query to the test farm
$r = $run( [] );
$check( 'public farms only (active farm in, expired farm out)', in_array( $farm_id, $ids( $r ), true ) && ( ! $other || 0 === $query->run( [ 'q' => (string) $other->name, 'per_page' => 'all' ] )['total'] ), 'total=' . $r['total'] );
$check( 'filter state MO / TX', in_array( $farm_id, $ids( $run( [ 'state' => 'mo' ] ) ), true ) && ! in_array( $farm_id, $ids( $run( [ 'state' => 'TX' ] ) ), true ) );
$check( 'filter region Midwest', in_array( $farm_id, $ids( $run( [ 'region' => 'Midwest' ] ) ), true ) );
$check( 'text search (name) + miss', in_array( $farm_id, $ids( $run( [ 'q' => 'phase two' ] ) ), true ) && 0 === $run( [ 'q' => 'zzz-no-such-farm' ] )['total'] );
$check( 'offerings filter bulls / semen', in_array( $farm_id, $ids( $run( [ 'offerings' => [ 'bulls' ] ] ) ), true ) && ! in_array( $farm_id, $ids( $run( [ 'offerings' => 'semen' ] ) ), true ) );
$check( 'has_listings excludes farm with 0 listings', ! in_array( $farm_id, $ids( $run( [ 'has_listings' => '1' ] ) ), true ) );
$farms->set_listing_stats( $farm_id, 3, Dates::now() );
$active_sorted = $run( [ 'sort' => 'active' ] )['items'];
$active_counts = array_map( fn( $f ) => (int) $f->active_listing_count, $active_sorted );
$check( 'listing stats: has_listings + since 24h + sort active first', in_array( $farm_id, $ids( $run( [ 'has_listings' => 1 ] ) ), true ) && in_array( $farm_id, $ids( $run( [ 'since' => '24h' ] ) ), true ) && in_array( $farm_id, array_map( fn( $f ) => (int) $f->id, $active_sorted ), true ) && $active_counts === array_values( array_reverse( array_values( ( function ( $a ) { sort( $a ); return $a; } )( $active_counts ) ) ) ), 'counts ' . implode( ',', $active_counts ) ); // other (demo) farms may rank above the test farm; the order itself must be descending
$card = $presenter->card( $farms->find( $farm_id ) );
$check( 'presenter: light on + new-listing badge + 3 listings', 'on' === $card['light'] && $card['new_listing'] && 3 === $card['active_listings'] );
$check( 'states()/regions() counts', ( $query->states()['MO'] ?? 0 ) >= 1 && ( $query->regions()['Midwest'] ?? 0 ) >= 1 );
$check( 'pins() returns geocoded farm', in_array( $farm_id, array_map( fn( $f ) => (int) $f->id, $query->pins( [] ) ), true ) );
$check( 'normalize: bad sort/since/per_page fall back', 'name_asc' === $query->normalize( [ 'sort' => 'hack' ] )['sort'] && '' === $query->normalize( [ 'since' => 'year' ] )['since'] && 500 === $query->normalize( [ 'per_page' => 'all' ] )['per_page'] );
$stock_filter = fn( $ids, $slugs ) => in_array( 'bulls', $slugs, true ) ? [ $farm_id ] : [];
add_filter( 'sp/directory/farm_ids_with_stock', $stock_filter, 10, 2 );
$check( 'stock filter hook (Phase 3 seam)', in_array( $farm_id, $ids( $run( [ 'stock' => [ 'bulls' ] ] ) ), true ) && 0 === $run( [ 'stock' => [ 'heifers' ] ] )['total'] );
remove_filter( 'sp/directory/farm_ids_with_stock', $stock_filter, 10 );

echo "\n== 6. Directory REST ==\n";
wp_set_current_user( 0 ); $ctxs->forget();
$res = $rest( 'GET', 'farms', [ 'q' => 'phase two' ] );
$item = $res->get_data()['items'][0] ?? [];
$check( 'GET farms (guest) returns card html, no contact fields', 200 === $res->get_status() && str_contains( (string) ( $item['html'] ?? '' ), 'sp-farm-card' ) && ! isset( $item['email'] ) && ! isset( $item['phone'] ), (string) $res->get_status() );
$res = $rest( 'GET', 'farms/pins' );
$pin = null; foreach ( $res->get_data()['items'] as $x ) { if ( $x['id'] === $farm_id ) { $pin = $x; } }
$check( 'pins (guest) rounded to ~7 miles', $pin && 39.4 === (float) $pin['lat'] && false === $res->get_data()['exact'], json_encode( $pin ) );
wp_set_current_user( $buyer ); $ctxs->forget();
$res = $rest( 'GET', 'farms/pins' );
$pin = null; foreach ( $res->get_data()['items'] as $x ) { if ( $x['id'] === $farm_id ) { $pin = $x; } }
$check( 'pins (verified buyer) exact', $pin && abs( (float) $pin['lat'] - 39.3639 ) < 0.001 && true === $res->get_data()['exact'] );
wp_set_current_user( 0 ); $ctxs->forget();
$res = $rest( 'GET', 'farms/filters' );
$check( 'filters endpoint (states, regions, offerings, sorts)', 200 === $res->get_status() && in_array( 'MO', array_column( $res->get_data()['states'], 'value' ), true ) && count( $res->get_data()['offerings'] ) >= 5 && 3 === count( $res->get_data()['sorts'] ) );
$slug = (string) $farms->find( $farm_id )->slug;
$check( 'GET farms/{slug} public → 200; unknown → 404', 200 === $rest( 'GET', "farms/{$slug}" )->get_status() && 404 === $rest( 'GET', 'farms/no-such-farm-xyz' )->get_status() );
if ( $other ) {
	$check( 'expired farm hidden from guests (404) but previewable by staff (200)', 404 === $rest( 'GET', "farms/{$other->slug}" )->get_status() && ( wp_set_current_user( $admin ) !== null ) && ( $ctxs->forget() ?? true ) && 200 === $rest( 'GET', "farms/{$other->slug}" )->get_status() );
}

echo "\n== 7. Farm page render states ==\n";
$h = $render_farm( 0, $slug );
$check( 'guest: name, gated contact prompt, inquiry form, details, hours, gallery', str_contains( $h, $name ) && str_contains( $h, 'Create account' ) && str_contains( $h, 'Send a message' ) && str_contains( $h, 'Fescue' ) && str_contains( $h, 'Visiting hours' ) && str_contains( $h, 'sp-gallery' ), strlen( $h ) . ' bytes' );
$check( 'guest: farm email hidden', ! str_contains( $h, "farm-$stamp@example.test" ) );
$dir_href = 'href="' . $c->get( \SouthPoll\Membership\Routing\Urls::class )->directory() . '"';
$check( 'S3: directory link in the hero actions and at the bottom', str_contains( $h, 'sp-farm__directory-link' ) && 2 === substr_count( $h, $dir_href ) && str_contains( $h, 'Back to the member directory' ), (string) substr_count( $h, $dir_href ) );
$h = $render_farm( $buyer, $slug );
$check( 'verified buyer: contact details visible, no inquiry form', str_contains( $h, "farm-$stamp@example.test" ) && str_contains( $h, '816-629-8010' ) && str_contains( $h, 'Get directions' ) );
$h = $render_farm( $owner, $slug );
$check( 'owner: "how visitors see" notice + edit link', str_contains( $h, 'This is how visitors see your farm page' ) );
if ( $other ) {
	$h = $render_farm( 0, (string) $other->slug );
	$check( 'expired farm (guest): minimal not-active page, no contact', str_contains( $h, 'not active' ) && ! str_contains( $h, 'Send a message' ) );
	$h = $render_farm( (int) $other->owner_user_id, (string) $other->slug );
	$check( 'expired farm (its owner): full page with "only you" notice', str_contains( $h, 'Only you can see this page' ) );
}
$h = $render_farm( 0, 'no-such-farm-xyz' );
$check( 'unknown slug: not-found page', str_contains( $h, 'Farm not found' ) );
$t   = $c->get( FarmPageController::class );
$ref = new ReflectionClass( $t ); // the controller memoises one slug per request; reset it like a fresh request
foreach ( [ 'farm' => null, 'looked_up' => false ] as $prop => $val ) { $rp = $ref->getProperty( $prop ); $rp->setAccessible( true ); $rp->setValue( $t, $val ); }
$check( 'route title = name – location', $name . ' – Kearney, MO' === $t->title( new RouteMatch( 'farm', Routes::get( 'farm' ), [ 'sp_slug' => $slug ] ) ), $t->title( new RouteMatch( 'farm', Routes::get( 'farm' ), [ 'sp_slug' => $slug ] ) ) );

echo "\n== 8. Favorites ==\n";
wp_set_current_user( 0 ); $ctxs->forget();
$check( 'guest toggle → 401', 401 === $rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => $farm_id ] )->get_status() );
$members->update( (int) $bm->id, [ 'email_verified_at' => null ] );
wp_set_current_user( $buyer ); $ctxs->forget();
$res = $rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => $farm_id ] );
$check( 'unverified buyer → 403 with verify flag', 403 === $res->get_status() && ! empty( $res->get_data()['data']['verify'] ) );
$members->update( (int) $bm->id, [ 'email_verified_at' => Dates::now() ] );
$ctxs->forget();
$res = $rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => $farm_id ] );
$fav_id = (int) ( $res->get_data()['favorite_id'] ?? 0 );
$check( 'verified buyer adds favorite (notify defaults off)', 200 === $res->get_status() && true === $res->get_data()['added'] && $fav_id > 0 && false === $res->get_data()['notify'] && str_contains( (string) $res->get_data()['note'], 'Turn it on' ), json_encode( $res->get_data() ) );
$res = $rest( 'PATCH', "favorites/{$fav_id}", [ 'notify' => true ] );
$check( 'PATCH notify on', true === ( $res->get_data()['notify'] ?? false ) );
$res = $rest( 'GET', 'favorites', [ 'type' => 'farm' ] );
$check( 'GET favorites lists the farm with notify + active', 1 === count( $res->get_data()['items'] ) && $res->get_data()['items'][0]['notify'] && $res->get_data()['items'][0]['active'] );
$check( 'presenter marks favorited for the buyer', $presenter->card( $farms->find( $farm_id ), $ctxs->for_user( $buyer ) )['favorited'] );
$check( 'users_watching includes buyer', in_array( $buyer, $favorites->users_watching( 'farm', $farm_id ), true ) );
$h = $render_section( $buyer, 'dashboard' );
$check( 'buyer dashboard favorites card lists the farm', str_contains( $h, 'sp-home__card--favorites' ) && str_contains( $h, $name ) );
$res = $rest( 'POST', 'favorites/notify-all', [ 'type' => 'farm', 'notify' => false ] );
$check( 'notify-all off', 1 === (int) ( $res->get_data()['updated'] ?? 0 ) && 0 === (int) $favorites->find( $fav_id )->notify );
if ( $other ) { $check( 'cannot favorite a hidden farm (404)', 404 === $rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => (int) $other->id ] )->get_status() ); }
$res = $rest( 'POST', 'favorites/toggle', [ 'type' => 'farm', 'id' => $farm_id ] );
$check( 'toggle again removes', false === ( $res->get_data()['added'] ?? true ) && 0 === $favorites->count( $buyer, 'farm' ) );

echo "\n== 9. Inquiries (contact form) ==\n";
$settings->set( 'recaptcha_enabled', false );
$settings->set( 'inquiry_admin_copy', false );
$ip_hash = substr( hash_hmac( 'sha256', '127.0.0.1', wp_salt( 'auth' ) ), 0, 32 );
RateLimiter::reset( 'inquiry:ip' . $ip_hash );
wp_set_current_user( 0 ); $ctxs->forget();
$sent = [];
$res  = $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Jane Buyer', 'email' => 'jane@example.test', 'phone' => '5551234567', 'message' => 'Do you have any bred heifers available this fall?', 'elapsed' => 8 ] );
$msg  = $sent[0] ?? null;
$check( 'guest inquiry sent (200)', 200 === $res->get_status(), json_encode( $res->get_data() ) );
$check( 'email to farm contact email with Reply-To sender', $msg && 'farm.inquiry' === $msg->key() && in_array( "farm-$stamp@example.test", $msg->recipients(), true ) && 'jane@example.test' === $msg->reply_to(), $msg ? implode( ',', $msg->recipients() ) : 'no email' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}sp_inquiries WHERE farm_id = %d ORDER BY id DESC LIMIT 1", $farm_id ) );
$check( 'inquiry logged with delivered_to', $row && 'jane@example.test' === $row->sender_email && str_contains( (string) $row->delivered_to, "farm-$stamp@example.test" ) );
$check( 'honeypot filled → 400', 400 === $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'buy cheap things now please', 'elapsed' => 9, 'sp_website_url' => 'http://spam' ] )->get_status() );
$check( 'too fast → 400', 400 === $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'buy cheap things now please', 'elapsed' => 1 ] )->get_status() );
$res = $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'J', 'email' => 'bad', 'message' => 'short', 'elapsed' => 9 ] );
$check( 'validation errors → 422 keyed by field', 422 === $res->get_status() && isset( $res->get_data()['data']['errors']['name'], $res->get_data()['data']['errors']['email'], $res->get_data()['data']['errors']['message'] ), json_encode( $res->get_data()['data']['errors'] ?? null ) );
$farms->update( $farm_id, [ 'contact_form_enabled' => 0 ] );
$check( 'form disabled → 403', 403 === $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Jane', 'email' => 'jane@example.test', 'message' => 'Hello there, any bulls?', 'elapsed' => 9 ] )->get_status() );
$farms->update( $farm_id, [ 'contact_form_enabled' => 1 ] );
$settings->set( 'inquiry_admin_copy', true );
$sent = [];
$rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Jane', 'email' => 'jane@example.test', 'message' => 'Hello there, any bulls for sale?', 'elapsed' => 9 ] );
$check( 'admin copy sent when enabled', in_array( 'admin.inquiry', array_map( fn( $m ) => $m->key(), $sent ), true ), implode( ',', array_map( fn( $m ) => $m->key(), $sent ) ) );
$settings->set( 'inquiry_admin_copy', false );
$codes = [];
for ( $i = 0; $i < 4; $i++ ) { $codes[] = $rest( 'POST', 'inquiries/farm', [ 'farm_id' => $farm_id, 'name' => 'Jane', 'email' => 'jane@example.test', 'message' => 'Hello there, any bulls for sale? ' . $i, 'elapsed' => 9 ] )->get_status(); }
$check( 'rate limit after 5 per hour (429)', 429 === end( $codes ), implode( ',', $codes ) );
RateLimiter::reset( 'inquiry:ip' . $ip_hash );
if ( $other ) { $check( 'hidden farm refuses inquiries (404)', 404 === $rest( 'POST', 'inquiries/farm', [ 'farm_id' => (int) $other->id, 'name' => 'Jane', 'email' => 'jane@example.test', 'message' => 'Hello there, any bulls?', 'elapsed' => 9 ] )->get_status() ); }

echo "\n== 10. Shortcode + directory page ==\n";
wp_set_current_user( 0 ); $ctxs->forget();
$_GET = [ 'q' => $name ]; // first page of 24 would not reach the test farm among 501 migrated ones
$h = do_shortcode( '[member-directory]' );
$check( '[member-directory] renders SSR cards', str_contains( $h, 'spDirectory(' ) && str_contains( $h, $name ) && str_contains( $h, 'sp-farm-card' ), strlen( $h ) . ' bytes' );
$_GET = [ 'state' => 'TX' ];
$h = do_shortcode( '[member_directory]' );
$check( 'query-string filters applied to SSR (TX → no card)', ! str_contains( $h, $name ) && str_contains( $h, 'No member farms match' ) );
$_GET = [];

echo "\n== 11. My Farm editor render ==\n";
$h = $render_section( $owner, 'my-farm' );
$check( 'owner: editor + users manager', str_contains( $h, 'spFarmEditor(' ) && str_contains( $h, 'Save farm page' ) && str_contains( $h, 'name="offerings[]"' ) && str_contains( $h, 'name="meta[forage_type]"' ) && str_contains( $h, 'spFarmUsers' ), strlen( $h ) . ' bytes' );
$check( 'editor pre-fills saved values', str_contains( $h, 'value="Kearney"' ) && str_contains( $h, 'value="Fescue"' ) && str_contains( $h, 'Live in the directory' ) );
$h = $render_section( $owner, 'dashboard' );
$check( 'owner dashboard: My farm card with View farm page', str_contains( $h, 'View farm page' ) );

echo "\n== 12. Admin meta fields ==\n";
$f = $fields->create( 'farm', [ 'label' => 'Test Field ' . $stamp, 'type' => 'select', 'options' => "Alpha\nBeta", 'default_value' => 'Alpha' ] );
$check( 'field created with unique key + options', ! is_wp_error( $f ) && str_starts_with( $f->field_key, 'test_field_' ) && [ 'Alpha', 'Beta' ] === $f->options, is_wp_error( $f ) ? $f->get_error_message() : $f->field_key );
$h = $render_section( $owner, 'my-farm' );
$check( 'new field appears in the editor as a select', str_contains( $h, 'name="meta[' . $f->field_key . ']"' ) && str_contains( $h, '<option value="Beta"' ) );
$check( 'select value validated (Gamma rejected)', null === MetaFieldRepository::sanitize_value( $f, 'Gamma' ) && 'Beta' === MetaFieldRepository::sanitize_value( $f, 'Beta' ) );
wp_set_current_user( $admin );
require_once ABSPATH . 'wp-admin/includes/screen.php'; require_once ABSPATH . 'wp-admin/includes/template.php';
ob_start(); $c->get( MetaFieldsPage::class )->render(); $h = (string) ob_get_clean();
$check( 'admin Meta Fields page lists the field', str_contains( $h, 'Test Field ' . $stamp ) && str_contains( $h, 'Add a farm field' ) );
$fields->move( (int) $f->id, -1 );
$check( 'move up changes order', (int) $fields->find( (int) $f->id )->sort_order < count( $fields->for_scope( 'farm', false ) ) - 1 );
$fields->delete( (int) $f->id );
$check( 'field deleted', null === $fields->find( (int) $f->id ) );

echo "\n== 13. Membership gating hooks ==\n";
$hidden = []; $shown = [];
add_action( 'sp/farm/hidden', function ( $farm, $reason ) use ( &$hidden ) { $hidden[] = [ (int) $farm->id, $reason ]; }, 10, 2 );
add_action( 'sp/farm/shown', function ( $farm, $was ) use ( &$shown ) { $shown[] = [ (int) $farm->id, $was ]; }, 10, 2 );
$sent = [];
$engine->expire( $mrepo->for_farm( $farm_id ), 'test' );
$check( 'expire → sp/farm/hidden + farm out of the directory', [ [ $farm_id, 'expired' ] ] === $hidden && ! in_array( $farm_id, $ids( $run( [ 'q' => 'phase two' ] ) ), true ) );
$check( 'hidden farm page shows not-active to guests', str_contains( $render_farm( 0, $slug ), 'not active' ) );
$engine->activate( $farm_id, $tier, [ 'source' => 'offline', 'actor' => $admin ] );
$check( 'activate → sp/farm/shown + back in the directory', ! empty( $shown ) && $farm_id === $shown[0][0] && in_array( $farm_id, $ids( $run( [ 'q' => 'phase two' ] ) ), true ) );

// Tidy: listing stats back to 0, other farm membership back to active, owner membership left active for a year so the
// farm can be browsed on Local (test-phase1-render.php resets it to "none" when it runs; re-run this suite to re-activate).
if ( class_exists( \SouthPoll\Membership\Listings\ListingService::class ) ) {
	$c->get( \SouthPoll\Membership\Listings\ListingService::class )->refresh_farm_stats( $farm_id ); // back to the real listing counts (Phase 3)
} else {
	$farms->set_listing_stats( $farm_id, 0, null );
}
if ( $other_membership ) { $mrepo->update( (int) $other_membership->id, [ 'status' => 'active', 'expired_at' => null ] ); }
$ctxs->forget();

echo "\n== Summary: $pass passed, $fail failed ==\n";
echo "Browse: " . $c->get( \SouthPoll\Membership\Routing\Urls::class )->farm( $slug ) . "  |  " . $c->get( \SouthPoll\Membership\Routing\Urls::class )->directory() . "\n";
exit( $fail ? 1 : 0 );
