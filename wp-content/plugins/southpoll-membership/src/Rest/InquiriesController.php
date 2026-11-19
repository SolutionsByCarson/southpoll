<?php
/**
 * Contact forms (public; protected by honeypot, fill time, reCAPTCHA v3 and rate limits inside InquiryService).
 *
 *   POST /sp/v1/inquiries/farm      { farm_id, name, email, phone, message, elapsed, g-recaptcha-response, sp_website_url }
 *   POST /sp/v1/inquiries/listing   { listing_id, … }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Contact\InquiryService;
use SouthPoll\Membership\Members\ContextProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class InquiriesController extends BaseController {

	private const FIELDS = [ 'farm_id', 'listing_id', 'name', 'email', 'phone', 'message', 'elapsed', 'g-recaptcha-response', 'sp_website_url' ];

	public function __construct( private InquiryService $inquiries, private ContextProvider $contexts ) {}

	public function register_routes(): void {
		$this->route( '/inquiries/farm', [ [ 'methods' => 'POST', 'callback' => [ $this, 'farm' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
		$this->route( '/inquiries/listing', [ [ 'methods' => 'POST', 'callback' => [ $this, 'listing' ], 'permission_callback' => [ self::class, 'allow_public' ] ] ] );
	}

	public function farm( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->inquiries->submit_farm( $this->input( $request ), $this->contexts->current(), $this->ip_hash() ) );
	}

	public function listing( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->inquiries->submit_listing( $this->input( $request ), $this->contexts->current(), $this->ip_hash() ) );
	}

	/** @return array<string, mixed> */
	private function input( WP_REST_Request $request ): array {
		$in = [];
		foreach ( self::FIELDS as $k ) {
			$in[ $k ] = $request->get_param( $k );
		}
		return $in;
	}

	private function respond( $result ): WP_REST_Response|WP_Error {
		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$errors = [];
			foreach ( $result->get_error_codes() as $code ) {
				$errors[ (string) $code ] = (string) $result->get_error_message( $code );
			}
			return $this->fail( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 400 ), [ 'errors' => $errors ] );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Your message has been sent. The seller will reply to the email address you entered.', 'southpoll-membership' ) ] );
	}
}
