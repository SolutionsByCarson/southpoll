<?php
/**
 * Feedback admin (staff only), S8.
 *
 *   GET    /sp/v1/admin/feedback            table (q, status, sort, dir, page, per_page) + meta.counts
 *   POST   /sp/v1/admin/feedback/bulk       { ids[], action: seen | closed | new | delete }
 *   PATCH  /sp/v1/admin/feedback/{id}       { status?, notes? }
 *   DELETE /sp/v1/admin/feedback/{id}
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Feedback\FeedbackRepository;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Support\Dates;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminFeedbackController extends BaseController {

	public function __construct( private FeedbackRepository $feedback ) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$this->route( '/admin/feedback', [ [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/feedback/bulk', [ [ 'methods' => 'POST', 'callback' => [ $this, 'bulk' ], 'permission_callback' => $perm ] ] );
		$this->route( '/admin/feedback/(?P<id>\d+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ],
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$args = [];
		foreach ( [ 'q', 'status', 'sort', 'dir', 'page', 'per_page' ] as $p ) {
			if ( $request->has_param( $p ) ) {
				$args[ $p ] = $request->get_param( $p );
			}
		}
		$r = $this->feedback->query( $args );
		return $this->list( array_map( [ self::class, 'row' ], $r['rows'] ), $r['total'], $r['page'], $r['per_page'], [ 'counts' => $this->feedback->counts() ] );
	}

	public static function row( object $f ): array {
		$user = $f->user_id ? get_userdata( (int) $f->user_id ) : null;
		return [
			'id'             => (int) $f->id,
			'status'         => (string) $f->status,
			'status_label'   => self::status_label( (string) $f->status ),
			'name'           => (string) $f->name,
			'email'          => (string) $f->email,
			'user_id'        => (int) $f->user_id,
			'user_login'     => $user ? $user->user_login : '',
			'user_url'       => $user ? admin_url( 'admin.php?page=southpoll-users&q=' . rawurlencode( $user->user_email ) ) : '',
			'page_url'       => (string) $f->page_url,
			'page_path'      => (string) ( wp_parse_url( (string) $f->page_url, PHP_URL_PATH ) ?: $f->page_url ),
			'user_agent'     => (string) $f->user_agent,
			'message'        => (string) $f->message,
			'excerpt'        => wp_trim_words( (string) $f->message, 18, '…' ),
			'screenshot_id'  => (int) $f->screenshot_id,
			'screenshot_url' => $f->screenshot_id ? (string) wp_get_attachment_url( (int) $f->screenshot_id ) : '',
			'screenshot_thumb' => $f->screenshot_id ? (string) wp_get_attachment_image_url( (int) $f->screenshot_id, 'thumbnail' ) : '',
			'notes'          => (string) $f->notes,
			'created_at'     => (string) $f->created_at,
			'created_date'   => Dates::display( (string) $f->created_at ),
			'created_ago'    => sprintf( /* translators: %s: human time */ __( '%s ago', 'southpoll-membership' ), human_time_diff( Dates::utc( (string) $f->created_at )->getTimestamp(), time() ) ),
		];
	}

	public static function status_label( string $status ): string {
		return [
			'new'    => __( 'New', 'southpoll-membership' ),
			'seen'   => __( 'Seen', 'southpoll-membership' ),
			'closed' => __( 'Closed', 'southpoll-membership' ),
		][ $status ] ?? ucfirst( $status );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		$f  = $this->feedback->find( $id );
		if ( ! $f ) {
			return $this->fail( 'sp_not_found', __( 'Feedback not found.', 'southpoll-membership' ), 404 );
		}
		$status = $request->has_param( 'status' ) ? sanitize_key( (string) $request->get_param( 'status' ) ) : (string) $f->status;
		if ( ! in_array( $status, FeedbackRepository::STATUSES, true ) ) {
			return $this->fail( 'sp_bad_status', __( 'Unknown status.', 'southpoll-membership' ), 422 );
		}
		$notes = $request->has_param( 'notes' ) ? sanitize_textarea_field( (string) $request->get_param( 'notes' ) ) : null;
		$this->feedback->set_status( $id, $status, $notes );
		return $this->ok( [ 'item' => self::row( $this->feedback->find( $id ) ), 'message' => sprintf( /* translators: %s: status */ __( 'Marked as %s.', 'southpoll-membership' ), self::status_label( $status ) ) ] );
	}

	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( empty( $ids ) ) {
			return $this->fail( 'sp_no_ids', __( 'Select at least one row.', 'southpoll-membership' ), 422 );
		}
		if ( ! in_array( $action, array_merge( FeedbackRepository::STATUSES, [ 'delete' ] ), true ) ) {
			return $this->fail( 'sp_bad_action', __( 'Unknown action.', 'southpoll-membership' ), 422 );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$done += (int) ( 'delete' === $action ? $this->remove_row( $id ) : $this->feedback->set_status( $id, $action ) );
		}
		return $this->ok( [ 'done' => $done, 'message' => sprintf( /* translators: %d: rows */ _n( '%d row updated.', '%d rows updated.', $done, 'southpoll-membership' ), $done ) ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! $this->feedback->find( $id ) ) {
			return $this->fail( 'sp_not_found', __( 'Feedback not found.', 'southpoll-membership' ), 404 );
		}
		$this->remove_row( $id );
		return $this->ok( [ 'deleted' => $id, 'message' => __( 'Feedback deleted.', 'southpoll-membership' ) ] );
	}

	private function remove_row( int $id ): bool {
		$f = $this->feedback->find( $id );
		if ( ! $f ) {
			return false;
		}
		if ( $f->screenshot_id && 'feedback' === get_post_meta( (int) $f->screenshot_id, '_sp_purpose', true ) ) {
			wp_delete_attachment( (int) $f->screenshot_id, true );
		}
		return $this->feedback->delete( $id );
	}
}
