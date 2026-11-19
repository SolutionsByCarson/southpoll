<?php
/**
 * Feedback button (S8).
 *
 *   POST /sp/v1/feedback          { name?, email?, message, page_url, user_agent, screenshot_id?, elapsed, g-recaptcha-response?, sp_website_url }
 *   POST /sp/v1/feedback/upload   multipart `file` (image ≤ 5 MB) — logged-in users only → { id, url }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Feedback\FeedbackService;
use SouthPoll\Membership\Members\ContextProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FeedbackController extends BaseController {

	private const FIELDS = [ 'name', 'email', 'message', 'page_url', 'user_agent', 'screenshot_id', 'elapsed', Recaptcha::FIELD, Honeypot::FIELD ];
	private const ALLOWED = [ 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ];

	public function __construct( private FeedbackService $feedback, private ContextProvider $contexts ) {}

	public function register_routes(): void {
		$this->route( '/feedback', [ [ 'methods' => 'POST', 'callback' => [ $this, 'submit' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
		$this->route( '/feedback/upload', [ [ 'methods' => 'POST', 'callback' => [ $this, 'upload' ], 'permission_callback' => [ self::class, 'require_login' ] ] ] );
	}

	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$in = [];
		foreach ( self::FIELDS as $k ) {
			$in[ $k ] = $request->get_param( $k );
		}
		$result = $this->feedback->submit( $in, $this->contexts->current(), $this->ip_hash() );
		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$errors = [];
			foreach ( $result->get_error_codes() as $code ) {
				$errors[ (string) $code ] = (string) $result->get_error_message( $code );
			}
			return $this->fail( (string) $result->get_error_code(), (string) $result->get_error_message(), (int) ( $data['status'] ?? 400 ), [ 'errors' => $errors ] );
		}
		return $this->ok( [ 'ok' => true, 'id' => (int) $result->id, 'message' => __( 'Thank you — your feedback has been sent to the team.', 'southpoll-membership' ) ] );
	}

	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->feedback->enabled() ) {
			return $this->fail( 'sp_feedback_off', __( 'Feedback is not open right now.', 'southpoll-membership' ), 403 );
		}
		$ctx = $this->contexts->current();
		if ( $ctx->is_suspended() || ! $ctx->is_verified() ) {
			return $this->fail( 'sp_forbidden', __( 'Please verify your e-mail address before attaching a screenshot; you can still send the message without one.', 'southpoll-membership' ), 403 );
		}
		$limit = $this->rate_limit( 'feedback-upload', 10 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return $this->fail( 'sp_upload_missing', __( 'No image was received.', 'southpoll-membership' ), 422 );
		}
		if ( (int) $file['size'] > FeedbackService::SCREENSHOT_MB * MB_IN_BYTES ) {
			return $this->fail( 'sp_upload_size', sprintf( /* translators: %d: MB */ __( 'Please keep the screenshot under %d MB.', 'southpoll-membership' ), FeedbackService::SCREENSHOT_MB ), 422 );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], self::ALLOWED );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $this->fail( 'sp_upload_type', __( 'Please upload a JPG, PNG, WebP or GIF image.', 'southpoll-membership' ), 422 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$uid = get_current_user_id();
		$id  = media_handle_sideload( [
			'name'     => sanitize_file_name( 'feedback-' . $uid . '-' . wp_generate_password( 6, false ) . '.' . $check['ext'] ),
			'type'     => $check['type'],
			'tmp_name' => $file['tmp_name'],
			'error'    => 0,
			'size'     => (int) $file['size'],
		], 0, null, [
			'post_author' => $uid,
			'post_title'  => 'Feedback screenshot — user #' . $uid,
			'post_status' => 'inherit',
			'meta_input'  => [ '_sp_feedback_user' => $uid, '_sp_purpose' => 'feedback' ],
		] );
		if ( is_wp_error( $id ) ) {
			return $this->fail( 'sp_upload_failed', $id->get_error_message(), 422 );
		}
		return $this->ok( [ 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( (int) $id ) ] );
	}
}
