<?php
/**
 * Farms admin (staff only).
 *
 *   GET   /sp/v1/admin/farms                  table (q, status, membership, state, region, sort, dir, page, per_page)
 *   GET   /sp/v1/admin/farms/{id}             detail + drawer html
 *   PATCH /sp/v1/admin/farms/{id}             any farm field (FarmEditor rules) + owner_user_id / old_owner_becomes / billing_user_id / status
 *   POST  /sp/v1/admin/farms/{id}/action      { action: geocode | refresh_stats }
 *   POST  /sp/v1/admin/farms/bulk             { ids[], action: activate | deactivate | extend | email, days?, subject?, body? }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Email\Messages\AdminBroadcastEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmEditor;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\Geocoder;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminFarmsController extends BaseController {

	public const PARAMS = [ 'q', 'status', 'membership', 'state', 'region', 'sort', 'dir', 'page', 'per_page' ];

	public function __construct(
		private AdminTables $tables,
		private FarmRepository $farms,
		private FarmEditor $editor,
		private FarmPresenter $presenter,
		private FarmVisibility $visibility,
		private FarmUserRepository $farm_users,
		private MembershipRepository $memberships,
		private StatusEngine $engine,
		private PaymentRepository $payments,
		private ListingRepository $listings,
		private ListingService $listing_service,
		private Offerings $offerings,
		private FarmMetaRepository $meta,
		private Geocoder $geocoder,
		private Notifier $notifier,
		private ContextProvider $contexts,
		private View $view,
		private Urls $urls
	) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$this->route( '/admin/farms', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/farms/bulk', [ [ 'methods' => 'POST', 'callback' => [ $this, 'bulk' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/farms/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $perm ],
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/admin/farms/(?P<id>\d+)/action', [ [ 'methods' => 'POST', 'callback' => [ $this, 'action' ], 'permission_callback' => $perm ] ] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( self::PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r = $this->tables->farms( $args );
		return $this->list( array_map( [ $this, 'row' ], $r['items'] ), $r['total'], $r['page'], $r['per_page'] );
	}

	/** @return array<string, mixed> */
	public function row( object $f ): array {
		return [
			'id'              => (int) $f->id,
			'name'            => (string) $f->name,
			'url'             => $this->urls->farm( (string) $f->slug ),
			'edit_url'        => admin_url( 'admin.php?page=southpoll-farms&farm=' . (int) $f->id ),
			'owner'           => (string) $f->owner_name ?: (string) ( $f->owner_display ?? '' ),
			'owner_email'     => (string) ( $f->owner_email ?? '' ),
			'owner_user_id'   => (int) $f->owner_user_id,
			'location'        => FarmPresenter::location( $f ),
			'state'           => (string) $f->state,
			'region'          => (string) $f->region,
			'breeder_number'  => (string) $f->breeder_number,
			'status'          => (string) $f->status,
			'membership'      => (string) $f->membership_status,
			'ends_at'         => $f->membership_ends_at ? Dates::display( (string) $f->membership_ends_at ) : '',
			'ends_at_raw'     => (string) ( $f->membership_ends_at ?? '' ),
			'auto_renew'      => (bool) ( $f->auto_renew ?? false ),
			'active_listings' => (int) $f->active_listing_count,
			'last_listing_at' => $f->last_listing_at ? Dates::display( (string) $f->last_listing_at ) : '',
			'has_logo'        => ! empty( $f->logo_id ),
			'geocoded'        => null !== $f->lat,
			'created'         => Dates::display( (string) $f->created_at ),
		];
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$detail = $this->detail( (int) $request['id'] );
		return $detail ? $this->ok( $detail ) : $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
	}

	/** @return array<string, mixed>|null */
	public function detail( int $id ): ?array {
		$farm = $this->farms->find( $id );
		if ( ! $farm ) {
			return null;
		}
		$membership = $this->memberships->for_farm( $id );
		$card       = $this->presenter->card( $farm, $this->contexts->current() );
		$all        = $this->listings->for_farm( $id, 200 );
		$data       = [
			'id'         => $id,
			'farm'       => (array) $farm,
			'card'       => $card,
			'url'        => $this->urls->farm( (string) $farm->slug ),
			'edit_url'   => admin_url( 'admin.php?page=southpoll-farms&farm=' . $id ),
			'visibility' => $this->visibility->state( $farm, $membership ),
			'users'      => array_map( static fn( $r ) => [ 'id' => (int) $r->id, 'user_id' => (int) ( $r->user_id ?? 0 ), 'name' => (string) $r->display_name, 'email' => (string) $r->email, 'role' => (string) $r->role, 'pending' => (bool) $r->is_pending, 'notify_listings' => (bool) $r->notify_listings, 'notify_membership' => (bool) $r->notify_membership ], $this->farm_users->for_farm( $id ) ),
			'membership' => $membership ? [ 'status' => $this->engine->effective_status( $membership ), 'starts_at' => Dates::display( (string) $membership->starts_at ), 'ends_at' => Dates::display( (string) $membership->ends_at ), 'ends_at_raw' => (string) $membership->ends_at, 'auto_renew' => (bool) $membership->auto_renew, 'source' => (string) $membership->source, 'stripe_customer_id' => (string) $membership->stripe_customer_id, 'stripe_subscription_id' => (string) $membership->stripe_subscription_id, 'price_paid' => Str::money( (int) $membership->price_cents_paid ), 'notes' => (string) $membership->notes ] : null,
			'payments'   => array_map( fn( $p ) => [ 'id' => (int) $p->id, 'amount' => Str::money( (int) $p->amount_cents, (string) $p->currency ), 'method' => PaymentRepository::method_label( (string) $p->method ), 'status' => (string) $p->status, 'paid_at' => $p->paid_at ? Dates::display( (string) $p->paid_at ) : Dates::display( (string) $p->created_at ), 'term' => $p->term_start ? Dates::display( (string) $p->term_start ) . ' – ' . Dates::display( (string) $p->term_end ) : '' ], $this->payments->for_farm( $id, 10 ) ),
			'listings'   => [ 'live' => count( array_filter( $all, static fn( $l ) => 'publish' === $l->post_status ) ), 'draft' => count( array_filter( $all, static fn( $l ) => 'draft' === $l->post_status ) ), 'expired' => count( array_filter( $all, static fn( $l ) => 'sp_expired' === $l->post_status ) ), 'admin_url' => admin_url( 'admin.php?page=southpoll-listings&farm_id=' . $id ) ],
			'offerings'  => array_map( static fn( $o ) => $o->name, $this->offerings->for_farm( $id ) ),
			'offering_ids'  => array_map( static fn( $o ) => (int) $o->id, $this->offerings->for_farm( $id ) ),
			'all_offerings' => array_map( static fn( $o ) => [ 'id' => (int) $o->id, 'name' => (string) $o->name, 'slug' => (string) $o->slug ], $this->offerings->all() ),
			'states'     => Str::us_states(),
			'meta'       => $this->meta->display_rows( $id ),
			'geocoded'   => null !== $farm->lat,
		];
		$data['html'] = $this->view->render( 'admin/drawers/farm', [ 'f' => $data ] );
		return $data;
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$farm = $this->farms->find( $id );
		if ( ! $farm ) {
			return $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
		}
		$in = [];
		foreach ( array_merge( FarmEditor::TEXT_FIELDS, [ 'about', 'social', 'hours', 'offerings', 'meta', 'contact_form_enabled' ] ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$in[ $key ] = $request->get_param( $key );
			}
		}
		if ( $request->has_param( 'contact_form_enabled' ) ) {
			$in['_contact_form_submitted'] = 1;
			$in['contact_form_enabled']   = rest_sanitize_boolean( $request->get_param( 'contact_form_enabled' ) ) ? '1' : '';
		}
		if ( $request->has_param( 'offerings' ) ) {
			$in['_offerings_submitted'] = 1;
		}
		if ( $in ) {
			$res = $this->editor->save_farm( $id, $in, true, $this->contexts->current() );
			if ( is_wp_error( $res ) ) {
				$errors = [];
				foreach ( $res->get_error_codes() as $code ) {
					$errors[ (string) $code ] = (string) $res->get_error_message( $code );
				}
				return $this->fail( 'sp_validation', $res->get_error_message(), 422, [ 'errors' => $errors ] );
			}
		}
		if ( $request->has_param( 'status' ) ) {
			$this->farms->set_status( $id, (string) $request->get_param( 'status' ) );
		}
		if ( $request->has_param( 'billing_user_id' ) ) {
			$bid = (int) $request->get_param( 'billing_user_id' );
			$att = $bid ? $this->farm_users->for_user( $bid ) : null;
			if ( $bid && ( ! $att || (int) $att->farm_id !== $id ) ) {
				return $this->fail( 'sp_billing_user', __( 'The billing contact must be an owner or manager of this farm.', 'southpoll-membership' ), 422 );
			}
			$this->farms->update( $id, [ 'billing_user_id' => $bid ?: null ] );
		}
		if ( $request->has_param( 'owner_user_id' ) ) {
			$new = (int) $request->get_param( 'owner_user_id' );
			if ( $new && $new !== (int) $farm->owner_user_id ) {
				if ( ! get_userdata( $new ) ) {
					return $this->fail( 'sp_not_found', __( 'New owner account not found.', 'southpoll-membership' ), 422 );
				}
				$becomes = 'buyer' === $request->get_param( 'old_owner_becomes' ) ? 'buyer' : 'manager';
				$res     = $this->farm_users->transfer_ownership( $id, $new, $becomes );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$u = get_userdata( $new );
				$this->farms->update( $id, [ 'owner_name' => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ] );
			}
		}
		$this->contexts->forget();
		return $this->ok( [ 'ok' => true, 'message' => __( 'Farm saved.', 'southpoll-membership' ) ] + (array) $this->detail( $id ) );
	}

	public function action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$farm = $this->farms->find( $id );
		if ( ! $farm ) {
			return $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
		}
		switch ( sanitize_key( (string) $request->get_param( 'action' ) ) ) {
			case 'geocode':
				if ( ! $this->geocoder->configured() ) {
					return $this->fail( 'sp_no_key', __( 'Add a Google Geocoding key under Integrations first.', 'southpoll-membership' ), 422 );
				}
				$ok      = $this->geocoder->geocode_farm( $farm );
				$message = $ok ? __( 'Coordinates updated.', 'southpoll-membership' ) : __( 'Google could not find that address.', 'southpoll-membership' );
				break;
			case 'refresh_stats':
				$this->listing_service->refresh_farm_stats( $id );
				$message = __( 'Listing counts refreshed.', 'southpoll-membership' );
				break;
			default:
				return $this->fail( 'sp_bad_action', __( 'Unknown action.', 'southpoll-membership' ), 400 );
		}
		return $this->ok( [ 'ok' => true, 'message' => $message ] + (array) $this->detail( $id ) );
	}

	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( empty( $ids ) ) {
			return $this->fail( 'sp_no_ids', __( 'Select at least one farm.', 'southpoll-membership' ), 422 );
		}
		$done  = 0;
		$actor = get_current_user_id();
		foreach ( $ids as $id ) {
			$farm = $this->farms->find( $id );
			if ( ! $farm ) {
				continue;
			}
			switch ( $action ) {
				case 'activate':
				case 'deactivate':
					$this->farms->set_status( $id, 'activate' === $action ? 'active' : 'inactive' );
					$done++;
					break;
				case 'extend':
					$days = max( 1, min( 3650, (int) $request->get_param( 'days' ) ) );
					$m    = $this->memberships->ensure( $id );
					$base = $this->engine->is_active( $m ) && $m->ends_at ? (string) $m->ends_at : Dates::now();
					$this->engine->set_end_date( $m, Dates::add_days( $base, $days ), sprintf( 'Extended %d days by staff #%d%s', $days, $actor, $request->get_param( 'note' ) ? ': ' . sanitize_text_field( (string) $request->get_param( 'note' ) ) : '' ), $actor );
					$done++;
					break;
				case 'email':
					$subject = sanitize_text_field( (string) $request->get_param( 'subject' ) );
					$body    = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
					if ( '' === $subject || '' === $body ) {
						return $this->fail( 'sp_email_required', __( 'Enter a subject and a message.', 'southpoll-membership' ), 422 );
					}
					$this->notifier->farm_membership_contacts( $farm, ( new AdminBroadcastEmail() )->with( [ 'subject' => $subject, 'body' => $body, 'farm_name' => (string) $farm->name, 'cta_url' => $this->urls->dashboard() ] ) );
					$done++;
					break;
				default:
					return $this->fail( 'sp_bad_action', __( 'Unknown bulk action.', 'southpoll-membership' ), 400 );
			}
		}
		$this->contexts->forget();
		return $this->ok( [ 'ok' => true, 'done' => $done, 'message' => sprintf( /* translators: %d: count */ _n( 'Applied to %d farm.', 'Applied to %d farms.', $done, 'southpoll-membership' ), $done ) ] );
	}
}
