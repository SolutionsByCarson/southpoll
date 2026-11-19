<?php
/**
 * Listings admin (staff only).
 *
 *   GET    /sp/v1/admin/listings            table (q, visibility, status, farm_id, category, sort, dir, page, per_page)
 *   POST   /sp/v1/admin/listings/bulk       { ids[], action: publish | pause | expire | delete | status, status? }
 *   DELETE /sp/v1/admin/listings/{id}
 *
 * Editing reuses the member editor (/members/edit-listing/?id=N), which staff may open for any listing.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Rest\BaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminListingsController extends BaseController {

	public const PARAMS = [ 'q', 'visibility', 'status', 'farm_id', 'category', 'sort', 'dir', 'page', 'per_page' ];

	public function __construct( private AdminTables $tables, private ListingRepository $listings, private ListingService $service, private ListingPresenter $presenter, private ContextProvider $contexts ) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$this->route( '/admin/listings', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/listings/bulk', [ [ 'methods' => 'POST', 'callback' => [ $this, 'bulk' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/listings/(?P<id>\d+)', [ [ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ] ] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( self::PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r     = $this->tables->listings( $args );
		$cards = $this->presenter->cards( $this->listings->find_many( $r['ids'] ), $this->contexts->current() );
		foreach ( $cards as &$c ) {
			$c['visibility'] = match ( $c['post_status'] ) {
				'publish'    => 'live',
				'sp_expired' => 'expired',
				default      => 'draft',
			};
			$c['farm_name']  = $c['farm']['name'] ?? '';
			$c['category_names'] = implode( ', ', array_map( static fn( $x ) => $x['name'], $c['categories'] ) );
		}
		unset( $c );
		return $this->list( $cards, $r['total'], $r['page'], $r['per_page'] );
	}

	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( empty( $ids ) ) {
			return $this->fail( 'sp_no_ids', __( 'Select at least one listing.', 'southpoll-membership' ), 422 );
		}
		$ctx    = $this->contexts->current();
		$done   = 0;
		$errors = [];
		foreach ( $ids as $id ) {
			$l = $this->listings->find( $id );
			if ( ! $l ) {
				continue;
			}
			$res = match ( $action ) {
				'publish' => $this->service->save( $ctx, $l, [ 'status' => 'inactive' === $l->status ? 'available' : $l->status ], 'publish' ),
				'pause'   => $this->service->save( $ctx, $l, [ 'status' => 'inactive' ], 'draft' ),
				'expire'  => $this->service->expire( $l ),
				'delete'  => $this->service->delete( $ctx, $l ),
				'status'  => in_array( $status, ListingTypes::STATUSES, true ) ? $this->service->set_status( $ctx, $l, $status ) : new WP_Error( 'sp_bad_status', __( 'Choose a status.', 'southpoll-membership' ) ),
				default   => new WP_Error( 'sp_bad_action', __( 'Unknown bulk action.', 'southpoll-membership' ) ),
			};
			if ( is_wp_error( $res ) ) {
				$errors[] = $l->title . ': ' . $res->get_error_message();
				if ( 'sp_bad_action' === $res->get_error_code() ) {
					return $this->fail( 'sp_bad_action', $res->get_error_message(), 400 );
				}
				continue;
			}
			$done++;
		}
		return $this->ok( [ 'ok' => true, 'done' => $done, 'errors' => $errors, 'message' => sprintf( /* translators: %d: count */ _n( 'Applied to %d listing.', 'Applied to %d listings.', $done, 'southpoll-membership' ), $done ) . ( $errors ? ' ' . implode( ' ', $errors ) : '' ) ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$l = $this->listings->find( (int) $request['id'] );
		if ( ! $l ) {
			return $this->fail( 'sp_not_found', __( 'Listing not found.', 'southpoll-membership' ), 404 );
		}
		$res = $this->service->delete( $this->contexts->current(), $l );
		return is_wp_error( $res ) ? $this->fail( $res->get_error_code(), $res->get_error_message(), 403 ) : $this->ok( [ 'ok' => true, 'message' => __( 'Listing deleted.', 'southpoll-membership' ) ] );
	}
}
