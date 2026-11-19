<?php
/**
 * Memberships, payments, claims and tiers (staff only).
 *
 *   GET   /sp/v1/admin/memberships                     table
 *   GET   /sp/v1/admin/memberships/{farm_id}           detail + drawer html
 *   POST  /sp/v1/admin/memberships/{farm_id}/action    { action: extend|set_end|comp|cancel|auto_renew_off|note|record, days?, ends_at?, note?, reason?, method?, amount?, paid_at? }
 *   GET   /sp/v1/admin/payments                        table (+ sum)
 *   POST  /sp/v1/admin/payments/{id}/action            { action: note|refunded, note }
 *   POST  /sp/v1/admin/claims/{id}/approve|reject      { paid_at?, note? }
 *   GET   /sp/v1/admin/tiers   POST /sp/v1/admin/tiers   PATCH /sp/v1/admin/tiers/{id}
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\MembershipCancelledEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Payments\StripeService;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminMembershipsController extends BaseController {

	public const PARAMS         = [ 'q', 'status', 'source', 'auto_renew', 'sort', 'dir', 'page', 'per_page' ];
	public const PAYMENT_PARAMS = [ 'q', 'status', 'method', 'from', 'to', 'farm_id', 'sort', 'dir', 'page', 'per_page' ];

	public function __construct(
		private AdminTables $tables,
		private MembershipRepository $memberships,
		private StatusEngine $engine,
		private TierRepository $tiers,
		private FarmRepository $farms,
		private PaymentRepository $payments,
		private OfflineClaims $claims,
		private StripeService $stripe,
		private Notifier $notifier,
		private Settings $settings,
		private ContextProvider $contexts,
		private View $view,
		private Urls $urls
	) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$this->route( '/admin/memberships', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/memberships/(?P<farm_id>\d+)', [ [ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/memberships/(?P<farm_id>\d+)/action', [ [ 'methods' => 'POST', 'callback' => [ $this, 'action' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/payments', [ [ 'methods' => 'GET', 'callback' => [ $this, 'payments' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/payments/(?P<id>\d+)/action', [ [ 'methods' => 'POST', 'callback' => [ $this, 'payment_action' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/claims/(?P<id>\d+)/(?P<verb>approve|reject)', [ [ 'methods' => 'POST', 'callback' => [ $this, 'claim' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/tiers', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'tiers' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'tier_create' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/admin/tiers/(?P<id>\d+)', [ [ 'methods' => 'PATCH', 'callback' => [ $this, 'tier_update' ], 'permission_callback' => $perm ] ] );
	}

	/* ----------------------------------------------------------- memberships */

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( self::PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r = $this->tables->memberships( $args );
		return $this->list( array_map( [ $this, 'row' ], $r['items'] ), $r['total'], $r['page'], $r['per_page'] );
	}

	/** @return array<string, mixed> */
	public function row( object $m ): array {
		return [
			'id'             => (int) $m->farm_id,
			'membership_id'  => (int) $m->id,
			'farm'           => (string) $m->farm_name,
			'farm_url'       => $this->urls->farm( (string) $m->farm_slug ),
			'farm_admin_url' => admin_url( 'admin.php?page=southpoll-farms&farm=' . (int) $m->farm_id ),
			'owner'          => (string) $m->owner_name,
			'owner_email'    => (string) ( $m->owner_email ?? '' ),
			'breeder_number' => (string) $m->breeder_number,
			'status'         => (string) $m->effective_status,
			'starts_at'      => $m->starts_at ? Dates::display( (string) $m->starts_at ) : '',
			'ends_at'        => $m->ends_at ? Dates::display( (string) $m->ends_at ) : '',
			'ends_at_raw'    => (string) ( $m->ends_at ?? '' ),
			'days_left'      => $m->days_left,
			'auto_renew'     => (bool) $m->auto_renew,
			'source'         => (string) $m->source,
			'tier'           => (string) ( $m->tier_name ?? '' ),
			'price_paid'     => null !== $m->price_cents_paid ? Str::money( (int) $m->price_cents_paid ) : '',
			'stripe'         => '' !== (string) $m->stripe_customer_id,
		];
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$d = $this->detail( (int) $request['farm_id'] );
		return $d ? $this->ok( $d ) : $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
	}

	/** @return array<string, mixed>|null */
	public function detail( int $farm_id ): ?array {
		$farm = $this->farms->find( $farm_id );
		if ( ! $farm ) {
			return null;
		}
		$m     = $this->memberships->ensure( $farm_id );
		$tier  = $this->engine->tier_for( $m );
		$owner = get_userdata( (int) $farm->owner_user_id );
		$data  = [
			'id'         => $farm_id,
			'farm'       => [ 'id' => $farm_id, 'name' => (string) $farm->name, 'url' => $this->urls->farm( (string) $farm->slug ), 'admin_url' => admin_url( 'admin.php?page=southpoll-farms&farm=' . $farm_id ), 'owner' => (string) $farm->owner_name, 'owner_email' => $owner ? $owner->user_email : '', 'breeder_number' => (string) $farm->breeder_number ],
			'membership' => [
				'status'       => $this->engine->effective_status( $m ),
				'stored'       => (string) $m->status,
				'starts_at'    => $m->starts_at ? Dates::display( (string) $m->starts_at ) : '',
				'ends_at'      => $m->ends_at ? Dates::display( (string) $m->ends_at ) : '',
				'ends_at_raw'  => $m->ends_at ? substr( (string) $m->ends_at, 0, 10 ) : '',
				'days_left'    => $this->engine->days_remaining( $m ),
				'auto_renew'   => (bool) $m->auto_renew,
				'source'       => (string) $m->source,
				'tier'         => $tier ? (string) $tier->name : '',
				'tier_days'    => $tier ? (int) $tier->duration_days : 365,
				'tier_price'   => $tier ? number_format( (int) $tier->price_cents / 100, 2, '.', '' ) : '',
				'price_paid'   => null !== $m->price_cents_paid ? Str::money( (int) $m->price_cents_paid ) : '',
				'stripe_customer_id'     => (string) $m->stripe_customer_id,
				'stripe_subscription_id' => (string) $m->stripe_subscription_id,
				'activated_at' => $m->activated_at ? Dates::display_datetime( (string) $m->activated_at ) : '',
				'notes'        => (string) $m->notes,
			],
			'payments'   => array_map( fn( $p ) => [ 'id' => (int) $p->id, 'amount' => Str::money( (int) $p->amount_cents, (string) $p->currency ), 'method' => PaymentRepository::method_label( (string) $p->method ), 'status' => (string) $p->status, 'paid_at' => $p->paid_at ? Dates::display( (string) $p->paid_at ) : Dates::display( (string) $p->created_at ), 'term' => $p->term_start ? Dates::display( (string) $p->term_start ) . ' – ' . Dates::display( (string) $p->term_end ) : '', 'receipt_url' => (string) $p->receipt_url, 'notes' => (string) $p->notes ], $this->payments->for_farm( $farm_id, 20 ) ),
			'pending_claim' => $this->payments->has_pending_claim( $farm_id ),
		];
		$data['html'] = $this->view->render( 'admin/drawers/membership', [ 'm' => $data ] );
		return $data;
	}

	public function action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$farm_id = (int) $request['farm_id'];
		$farm    = $this->farms->find( $farm_id );
		if ( ! $farm ) {
			return $this->fail( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ), 404 );
		}
		$m       = $this->memberships->ensure( $farm_id );
		$actor   = get_current_user_id();
		$note    = sanitize_text_field( (string) $request->get_param( 'note' ) );
		$message = __( 'Done.', 'southpoll-membership' );
		switch ( sanitize_key( (string) $request->get_param( 'action' ) ) ) {
			case 'extend':
				$days = max( 1, min( 3650, (int) $request->get_param( 'days' ) ) );
				$base = $this->engine->is_active( $m ) && $m->ends_at ? (string) $m->ends_at : Dates::now();
				$this->engine->set_end_date( $m, Dates::add_days( $base, $days ), sprintf( 'Extended %d days by staff #%d', $days, $actor ) . ( $note ? ': ' . $note : '' ), $actor );
				$message = sprintf( /* translators: %d: days */ __( 'Extended by %d days.', 'southpoll-membership' ), $days );
				break;
			case 'set_end':
				$raw = (string) $request->get_param( 'ends_at' );
				$ts  = strtotime( $raw );
				if ( ! $ts ) {
					return $this->fail( 'sp_bad_date', __( 'Enter a valid end date.', 'southpoll-membership' ), 422 );
				}
				$this->engine->set_end_date( $m, gmdate( 'Y-m-d', $ts ) . ' 23:59:59', sprintf( 'End date set by staff #%d', $actor ) . ( $note ? ': ' . $note : '' ), $actor );
				$message = __( 'End date updated.', 'southpoll-membership' );
				break;
			case 'comp':
				$res = $this->claims->record( $farm_id, 'comp', 0, Dates::now(), $actor, $note ?: 'Complimentary membership' );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$message = __( 'Complimentary term added (member emailed).', 'southpoll-membership' );
				break;
			case 'record':
				$method = sanitize_key( (string) $request->get_param( 'method' ) ) ?: 'check';
				$amount = (int) round( (float) str_replace( [ '$', ',' ], '', (string) $request->get_param( 'amount' ) ) * 100 );
				$res    = $this->claims->record( $farm_id, $method, $amount, (string) $request->get_param( 'paid_at' ), $actor, $note );
				if ( is_wp_error( $res ) ) {
					return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
				}
				$message = __( 'Payment recorded and membership activated.', 'southpoll-membership' );
				break;
			case 'cancel':
				$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) ) ?: $note;
				if ( ! empty( $m->stripe_subscription_id ) && $this->stripe->client()->configured() ) {
					$this->stripe->cancel_subscription_now( (string) $m->stripe_subscription_id );
				}
				$this->engine->cancel( $m, $reason, $actor );
				$this->notifier->farm_membership_contacts( $farm, ( new MembershipCancelledEmail() )->with( [ 'farm_name' => $farm->name, 'reason' => $reason, 'renew_url' => $this->urls->dashboard( 'manage-membership' ), 'admin_email' => (string) $this->settings->get( 'admin_contact.email', '' ) ] ) );
				$this->notifier->admin( ( new AdminNoticeEmail() )->with( [ 'type' => 'cancelled', 'farm_name' => (string) $farm->name, 'summary' => sprintf( 'The membership for %s was cancelled by staff #%d%s.', (string) $farm->name, $actor, $reason ? ' (' . $reason . ')' : '' ) ] ) );
				$message = __( 'Membership cancelled; the farm and its listings are hidden.', 'southpoll-membership' );
				break;
			case 'auto_renew_off':
				if ( ! empty( $m->stripe_subscription_id ) && $this->stripe->client()->configured() ) {
					$this->stripe->set_cancel_at_period_end( (string) $m->stripe_subscription_id, true );
				}
				$this->engine->set_auto_renew( $m, false );
				$message = __( 'Auto-renew turned off.', 'southpoll-membership' );
				break;
			case 'note':
				if ( '' === $note ) {
					return $this->fail( 'sp_note_required', __( 'Enter a note.', 'southpoll-membership' ), 422 );
				}
				$existing = trim( (string) $m->notes );
				$this->memberships->update( (int) $m->id, [ 'notes' => ( $existing ? $existing . "\n" : '' ) . '[' . Dates::now() . '] staff #' . $actor . ': ' . $note ] );
				$message = __( 'Note added.', 'southpoll-membership' );
				break;
			default:
				return $this->fail( 'sp_bad_action', __( 'Unknown action.', 'southpoll-membership' ), 400 );
		}
		$this->contexts->forget();
		return $this->ok( [ 'ok' => true, 'message' => $message ] + (array) $this->detail( $farm_id ) );
	}

	/* -------------------------------------------------------------- payments */

	public function payments( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( self::PAYMENT_PARAMS as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r     = $this->tables->payments( $args );
		$items = array_map( fn( $p ) => [
			'id'         => (int) $p->id,
			'farm'       => (string) $p->farm_name,
			'farm_id'    => (int) $p->farm_id,
			'farm_admin_url' => admin_url( 'admin.php?page=southpoll-farms&farm=' . (int) $p->farm_id ),
			'owner'      => (string) $p->owner_name,
			'amount'     => Str::money( (int) $p->amount_cents, (string) $p->currency ),
			'amount_cents' => (int) $p->amount_cents,
			'method'     => PaymentRepository::method_label( (string) $p->method ),
			'status'     => (string) $p->status,
			'recurring'  => (bool) $p->recurring,
			'paid_at'    => $p->paid_at ? Dates::display( (string) $p->paid_at ) : '',
			'created'    => Dates::display( (string) $p->created_at ),
			'term'       => $p->term_start ? Dates::display( (string) $p->term_start ) . ' – ' . Dates::display( (string) $p->term_end ) : '',
			'receipt_url' => (string) $p->receipt_url,
			'stripe_id'  => (string) ( $p->stripe_payment_intent_id ?: $p->stripe_invoice_id ?: $p->stripe_checkout_session_id ),
			'notes'      => (string) $p->notes,
		], $r['items'] );
		return $this->list( $items, $r['total'], $r['page'], $r['per_page'], [ 'sum' => Str::money( (int) $r['sum_cents'] ), 'sum_cents' => (int) $r['sum_cents'] ] );
	}

	public function payment_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$p = $this->payments->find( (int) $request['id'] );
		if ( ! $p ) {
			return $this->fail( 'sp_not_found', __( 'Payment not found.', 'southpoll-membership' ), 404 );
		}
		$note  = sanitize_text_field( (string) $request->get_param( 'note' ) );
		$actor = get_current_user_id();
		switch ( sanitize_key( (string) $request->get_param( 'action' ) ) ) {
			case 'note':
				if ( '' === $note ) {
					return $this->fail( 'sp_note_required', __( 'Enter a note.', 'southpoll-membership' ), 422 );
				}
				$this->payments->append_note( $p, 'Staff #' . $actor . ': ' . $note );
				break;
			case 'refunded':
				$this->payments->update( (int) $p->id, [ 'status' => 'refunded' ] );
				$this->payments->append_note( $this->payments->find( (int) $p->id ), 'Marked refunded by staff #' . $actor . ( $note ? ': ' . $note : '' ) . ' (refund the card in the Stripe Dashboard if applicable)' );
				break;
			default:
				return $this->fail( 'sp_bad_action', __( 'Unknown action.', 'southpoll-membership' ), 400 );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Saved.', 'southpoll-membership' ) ] );
	}

	public function claim( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$note = sanitize_text_field( (string) $request->get_param( 'note' ) );
		$res  = 'approve' === $request['verb']
			? $this->claims->approve( $id, get_current_user_id(), sanitize_text_field( (string) $request->get_param( 'paid_at' ) ), $note )
			: $this->claims->reject( $id, get_current_user_id(), $note );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
		}
		return $this->ok( [ 'ok' => true, 'message' => 'approve' === $request['verb'] ? __( 'Claim approved; membership activated.', 'southpoll-membership' ) : __( 'Claim rejected; member emailed.', 'southpoll-membership' ), 'pending' => count( $this->payments->pending_claims() ) ] );
	}

	/* ----------------------------------------------------------------- tiers */

	public function tiers( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'items' => array_map( [ $this, 'tier_row' ], $this->tiers->all( false ) ) ] );
	}

	/** @return array<string, mixed> */
	public function tier_row( object $t ): array {
		return [
			'id'            => (int) $t->id,
			'name'          => (string) $t->name,
			'slug'          => (string) $t->slug,
			'description'   => (string) $t->description,
			'price'         => number_format( (int) $t->price_cents / 100, 2, '.', '' ),
			'price_cents'   => (int) $t->price_cents,
			'duration_days' => (int) $t->duration_days,
			'listing_limit' => null === $t->listing_limit ? '' : (int) $t->listing_limit,
			'is_default'    => (bool) $t->is_default,
			'active'        => (bool) $t->active,
			'sort_order'    => (int) $t->sort_order,
			'stripe_synced' => '' !== (string) $t->stripe_price_id,
		];
	}

	public function tier_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $this->tier_input( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( '' === (string) ( $data['name'] ?? '' ) ) {
			return $this->fail( 'sp_name_required', __( 'Enter a tier name.', 'southpoll-membership' ), 422 );
		}
		$this->tiers->create( $data );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Tier added.', 'southpoll-membership' ), 'items' => array_map( [ $this, 'tier_row' ], $this->tiers->all( false ) ) ] );
	}

	public function tier_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tier = $this->tiers->find( (int) $request['id'] );
		if ( ! $tier ) {
			return $this->fail( 'sp_not_found', __( 'Tier not found.', 'southpoll-membership' ), 404 );
		}
		$data = $this->tier_input( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['active'] ) && ! $data['active'] && (int) $tier->is_default ) {
			return $this->fail( 'sp_default_tier', __( 'The default tier cannot be switched off. Make another tier the default first.', 'southpoll-membership' ), 422 );
		}
		$this->tiers->update( (int) $tier->id, $data );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Tier saved. A price change creates a new Stripe price the next time someone checks out.', 'southpoll-membership' ), 'items' => array_map( [ $this, 'tier_row' ], $this->tiers->all( false ) ) ] );
	}

	/** @return array<string, mixed>|WP_Error */
	private function tier_input( WP_REST_Request $request ): array|WP_Error {
		$data = [];
		if ( $request->has_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}
		if ( $request->has_param( 'price' ) ) {
			$raw = str_replace( [ '$', ',' ], '', (string) $request->get_param( 'price' ) );
			if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
				return $this->fail( 'sp_bad_price', __( 'Enter the price in dollars.', 'southpoll-membership' ), 422 );
			}
			$data['price_cents'] = (int) round( (float) $raw * 100 );
		}
		if ( $request->has_param( 'duration_days' ) ) {
			$d = (int) $request->get_param( 'duration_days' );
			if ( $d < 1 || $d > 3650 ) {
				return $this->fail( 'sp_bad_duration', __( 'Duration must be between 1 and 3650 days.', 'southpoll-membership' ), 422 );
			}
			$data['duration_days'] = $d;
		}
		if ( $request->has_param( 'listing_limit' ) ) {
			$raw = (string) $request->get_param( 'listing_limit' );
			$data['listing_limit'] = '' === trim( $raw ) ? null : max( 0, (int) $raw );
		}
		foreach ( [ 'is_default', 'active' ] as $k ) {
			if ( $request->has_param( $k ) ) {
				$data[ $k ] = (int) rest_sanitize_boolean( $request->get_param( $k ) );
			}
		}
		if ( $request->has_param( 'sort_order' ) ) {
			$data['sort_order'] = (int) $request->get_param( 'sort_order' );
		}
		return $data;
	}
}
