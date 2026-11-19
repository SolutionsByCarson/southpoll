<?php
/**
 * Users admin (staff only).
 *
 *   GET   /sp/v1/admin/users                 table (q, role, farm_id, sort, dir, page, per_page)
 *   GET   /sp/v1/admin/users/{id}            detail + drawer html
 *   PATCH /sp/v1/admin/users/{id}            profile fields
 *   POST  /sp/v1/admin/users/{id}/action     { action, farm_id?, old_owner_becomes? }
 *          actions: reset_password | resend_verification | verify | suspend | unsuspend | detach | attach_manager | make_owner | delete
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Auth\AccountService;
use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Members\UserGuards;
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

final class AdminUsersController extends BaseController {

	public const PARAMS = [ 'q', 'role', 'farm_id', 'sort', 'dir', 'page', 'per_page' ];

	public function __construct(
		private AdminTables $tables,
		private MemberRepository $members,
		private FarmUserRepository $farm_users,
		private FarmRepository $farms,
		private MembershipRepository $memberships,
		private StatusEngine $engine,
		private PaymentRepository $payments,
		private FavoriteRepository $favorites,
		private SavedSearchRepository $searches,
		private AccountService $accounts,
		private UserGuards $guards,
		private ContextProvider $contexts,
		private View $view,
		private Urls $urls
	) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$this->route( '/admin/users', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/users/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $perm ],
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/admin/users/(?P<id>\d+)/action', [ [ 'methods' => 'POST', 'callback' => [ $this, 'action' ], 'permission_callback' => $perm ] ] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( self::PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r = $this->tables->users( $args );
		return $this->list( array_map( [ $this, 'row' ], $r['items'] ), $r['total'], $r['page'], $r['per_page'] );
	}

	/** @return array<string, mixed> */
	public function row( object $u ): array {
		return [
			'id'         => (int) $u->id,
			'name'       => (string) $u->name,
			'email'      => (string) $u->email,
			'role'       => (string) $u->role,
			'role_label' => self::role_label( (string) $u->role ),
			'farm'       => $u->farm_id ? [ 'id' => (int) $u->farm_id, 'name' => (string) $u->farm_name, 'url' => $this->urls->farm( (string) $u->farm_slug ) ] : null,
			'membership' => (string) $u->membership_status,
			'ends_at'    => $u->membership_ends_at ? Dates::display( (string) $u->membership_ends_at ) : '',
			'verified'   => (bool) $u->verified,
			'suspended'  => (bool) $u->suspended,
			'phone'      => (string) $u->phone,
			'location'   => trim( (string) $u->city . ( $u->city && $u->state ? ', ' : '' ) . (string) $u->state ),
			'created'    => Dates::display( (string) $u->created_at ),
			'created_at' => (string) $u->created_at,
			'last_seen'  => $u->last_seen_at ? Dates::display( (string) $u->last_seen_at ) : '',
			'has_profile' => ! empty( $u->member_id ),
		];
	}

	public static function role_label( string $role ): string {
		return match ( $role ) {
			'owner'   => __( 'Farm owner', 'southpoll-membership' ),
			'manager' => __( 'Account manager', 'southpoll-membership' ),
			'staff'   => __( 'Staff', 'southpoll-membership' ),
			default   => __( 'Buyer', 'southpoll-membership' ),
		};
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$detail = $this->detail( (int) $request['id'] );
		return $detail ? $this->ok( $detail ) : $this->fail( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ), 404 );
	}

	/** @return array<string, mixed>|null */
	public function detail( int $id ): ?array {
		global $wpdb;
		$user = get_userdata( $id );
		if ( ! $user ) {
			return null;
		}
		$member     = $this->members->find_by_user( $id );
		$fu         = $this->farm_users->for_user( $id );
		$farm       = $fu ? $this->farms->find( (int) $fu->farm_id ) : null;
		$membership = $farm ? $this->memberships->for_farm( (int) $farm->id ) : null;
		$role       = Capabilities::is_staff( $id ) && ! $fu ? 'staff' : ( $fu ? (string) $fu->role : 'buyer' );
		$pending    = $wpdb->get_row( $wpdb->prepare( 'SELECT fu.*, f.name AS farm_name FROM ' . Schema::table( 'sp_farm_users' ) . ' fu JOIN ' . Schema::table( 'sp_farms' ) . ' f ON f.id = fu.farm_id WHERE fu.invite_email = %s AND fu.accepted_at IS NULL', $user->user_email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data       = [
			'id'          => $id,
			'login'       => $user->user_login,
			'email'       => $user->user_email,
			'name'        => $member ? trim( $member->first_name . ' ' . $member->last_name ) : $user->display_name,
			'role'        => $role,
			'role_label'  => self::role_label( $role ),
			'wp_roles'    => array_values( (array) $user->roles ),
			'profile'     => $member ? [ 'first_name' => $member->first_name, 'last_name' => $member->last_name, 'phone' => $member->phone, 'address1' => $member->address1, 'city' => $member->city, 'state' => $member->state, 'zip' => $member->zip, 'notify_all' => (bool) $member->notify_all, 'default_notify_favorites' => (bool) $member->default_notify_favorites, 'default_notify_searches' => (bool) $member->default_notify_searches ] : null,
			'verified'    => $member ? ! empty( $member->email_verified_at ) : false,
			'suspended'   => $member ? 'suspended' === (string) $member->status : false,
			'pending_email' => $member ? (string) $member->pending_email : '',
			'created'     => Dates::display_datetime( (string) $user->user_registered ),
			'last_seen'   => $member && $member->last_seen_at ? Dates::display_datetime( (string) $member->last_seen_at ) : '',
			'farm'        => $farm ? [ 'id' => (int) $farm->id, 'name' => (string) $farm->name, 'url' => $this->urls->farm( (string) $farm->slug ), 'role' => (string) $fu->role, 'notify_listings' => (bool) $fu->notify_listings, 'notify_membership' => (bool) $fu->notify_membership, 'admin_url' => admin_url( 'admin.php?page=southpoll-farms&farm=' . (int) $farm->id ) ] : null,
			'pending_invite' => $pending ? [ 'farm' => (string) $pending->farm_name, 'invited_at' => Dates::display( (string) $pending->invited_at ) ] : null,
			'membership'  => $membership ? [ 'status' => $this->engine->effective_status( $membership ), 'starts_at' => Dates::display( (string) $membership->starts_at ), 'ends_at' => Dates::display( (string) $membership->ends_at ), 'auto_renew' => (bool) $membership->auto_renew, 'source' => (string) $membership->source ] : null,
			'payments'    => $farm ? array_map( fn( $p ) => [ 'id' => (int) $p->id, 'amount' => Str::money( (int) $p->amount_cents, (string) $p->currency ), 'method' => PaymentRepository::method_label( (string) $p->method ), 'status' => (string) $p->status, 'paid_at' => $p->paid_at ? Dates::display( (string) $p->paid_at ) : Dates::display( (string) $p->created_at ) ], array_slice( $this->payments->for_farm( (int) $farm->id, 10 ), 0, 10 ) ) : [],
			'counts'      => [
				'favorite_farms'    => $this->favorites->count( $id, 'farm' ),
				'favorite_listings' => $this->favorites->count( $id, 'listing' ),
				'saved_searches'    => $this->searches->count( $id ),
				'listings_authored' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status <> 'trash'", ListingTypes::POST_TYPE, $id ) ),
				'inquiries_sent'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'sp_inquiries' ) . ' WHERE sender_user_id = %d', $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			],
			'can_delete'  => ! $this->guards->owns_farm( $id ) && ! Capabilities::is_staff( $id ),
			'edit_url'    => admin_url( 'user-edit.php?user_id=' . $id ),
		];
		$data['html'] = $this->view->render( 'admin/drawers/user', [ 'u' => $data, 'states' => Str::us_states(), 'farms_endpoint' => 'admin/farms' ] );
		return $data;
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return $this->fail( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ), 404 );
		}
		// Staff may manage members, never other staff: an editor must not be able to change an administrator's e-mail
		// (→ password reset → takeover) or role. Only users who can promote/edit users in WordPress itself may touch staff.
		if ( ( user_can( $user, Capabilities::CAP_MANAGE ) || user_can( $user, 'manage_options' ) ) && ! ( current_user_can( 'edit_user', $id ) && current_user_can( 'promote_users' ) ) ) {
			return $this->fail( 'sp_forbidden', __( 'Staff accounts can only be changed by an administrator in Users → All Users.', 'southpoll-membership' ), 403 );
		}
		$member = $this->members->find_by_user( $id ) ?? $this->members->create_for_user( $id );
		$data   = [];
		foreach ( [ 'first_name', 'last_name', 'phone', 'address1', 'city', 'state', 'zip' ] as $k ) {
			if ( $request->has_param( $k ) ) {
				$data[ $k ] = 'phone' === $k ? Str::phone( (string) $request->get_param( $k ) ) : ( 'state' === $k ? Str::state( (string) $request->get_param( $k ) ) : sanitize_text_field( (string) $request->get_param( $k ) ) );
			}
		}
		foreach ( [ 'notify_all', 'default_notify_favorites', 'default_notify_searches' ] as $k ) {
			if ( $request->has_param( $k ) ) {
				$data[ $k ] = (int) rest_sanitize_boolean( $request->get_param( $k ) );
			}
		}
		if ( $data ) {
			$this->members->update( (int) $member->id, $data );
			if ( isset( $data['first_name'] ) || isset( $data['last_name'] ) ) {
				wp_update_user( [ 'ID' => $id, 'first_name' => $data['first_name'] ?? $member->first_name, 'last_name' => $data['last_name'] ?? $member->last_name, 'display_name' => trim( ( $data['first_name'] ?? $member->first_name ) . ' ' . ( $data['last_name'] ?? $member->last_name ) ) ?: $user->display_name ] );
			}
		}
		if ( $request->has_param( 'email' ) ) {
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return $this->fail( 'sp_invalid_email', __( 'Enter a valid email address.', 'southpoll-membership' ), 422 );
			}
			$other = get_user_by( 'email', $email );
			if ( $other && (int) $other->ID !== $id ) {
				return $this->fail( 'sp_email_taken', __( 'Another account already uses that email address.', 'southpoll-membership' ), 422 );
			}
			wp_update_user( [ 'ID' => $id, 'user_email' => $email ] );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Saved.', 'southpoll-membership' ) ] + (array) $this->detail( $id ) );
	}

	public function action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return $this->fail( 'sp_not_found', __( 'User not found.', 'southpoll-membership' ), 404 );
		}
		// Staff may manage members, never other staff: an editor must not be able to change an administrator's e-mail
		// (→ password reset → takeover) or role. Only users who can promote/edit users in WordPress itself may touch staff.
		if ( ( user_can( $user, Capabilities::CAP_MANAGE ) || user_can( $user, 'manage_options' ) ) && ! ( current_user_can( 'edit_user', $id ) && current_user_can( 'promote_users' ) ) ) {
			return $this->fail( 'sp_forbidden', __( 'Staff accounts can only be changed by an administrator in Users → All Users.', 'southpoll-membership' ), 403 );
		}
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$member  = $this->members->find_by_user( $id ) ?? $this->members->create_for_user( $id );
		$farm_id = (int) $request->get_param( 'farm_id' );
		$message = __( 'Done.', 'southpoll-membership' );
		switch ( $action ) {
			case 'reset_password':
				$this->accounts->request_password_reset( $user->user_email );
				$message = __( 'Password reset email sent.', 'southpoll-membership' );
				break;
			case 'resend_verification':
				$res = $this->accounts->send_verification( $id, '' !== (string) $member->pending_email );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 429 );
				}
				$message = __( 'Verification email sent.', 'southpoll-membership' );
				break;
			case 'verify':
				$this->members->update( (int) $member->id, [ 'email_verified_at' => Dates::now(), 'verification_token' => null ] );
				$message = __( 'Email marked as verified.', 'southpoll-membership' );
				break;
			case 'suspend':
				if ( Capabilities::is_staff( $id ) ) {
					return $this->fail( 'sp_forbidden', __( 'Staff accounts cannot be suspended here.', 'southpoll-membership' ), 403 );
				}
				$this->members->update( (int) $member->id, [ 'status' => 'suspended' ] );
				$message = __( 'Account suspended: it can log in but cannot see contact details, favorite, save searches or edit farm/listings.', 'southpoll-membership' );
				break;
			case 'unsuspend':
				$this->members->update( (int) $member->id, [ 'status' => 'active' ] );
				$message = __( 'Account reinstated.', 'southpoll-membership' );
				break;
			case 'detach':
				$row = $this->farm_users->for_user( $id );
				if ( ! $row ) {
					return $this->fail( 'sp_not_attached', __( 'This account is not attached to a farm.', 'southpoll-membership' ), 422 );
				}
				$res = $this->farm_users->remove( (int) $row->id );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$message = __( 'Removed from the farm; the account is now a buyer.', 'southpoll-membership' );
				break;
			case 'attach_manager':
				if ( ! $farm_id || ! $this->farms->find( $farm_id ) ) {
					return $this->fail( 'sp_farm_required', __( 'Choose a farm.', 'southpoll-membership' ), 422 );
				}
				$res = $this->farm_users->attach_manager( $farm_id, $id );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$message = __( 'Attached as an account manager.', 'southpoll-membership' );
				break;
			case 'make_owner':
				if ( ! $farm_id || ! $this->farms->find( $farm_id ) ) {
					return $this->fail( 'sp_farm_required', __( 'Choose a farm.', 'southpoll-membership' ), 422 );
				}
				$becomes = 'buyer' === $request->get_param( 'old_owner_becomes' ) ? 'buyer' : 'manager';
				$res     = $this->farm_users->transfer_ownership( $farm_id, $id, $becomes );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$message = __( 'Ownership transferred.', 'southpoll-membership' );
				break;
			case 'delete':
				if ( $this->guards->owns_farm( $id ) || Capabilities::is_staff( $id ) || $id === get_current_user_id() ) {
					return $this->fail( 'sp_cannot_delete', __( 'Farm owners, staff and your own account cannot be deleted here.', 'southpoll-membership' ), 422 );
				}
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
				return $this->ok( [ 'ok' => true, 'deleted' => true, 'message' => __( 'Account deleted.', 'southpoll-membership' ) ] );
			default:
				return $this->fail( 'sp_bad_action', __( 'Unknown action.', 'southpoll-membership' ), 400 );
		}
		$this->contexts->forget();
		return $this->ok( [ 'ok' => true, 'message' => $message ] + (array) $this->detail( $id ) );
	}
}
