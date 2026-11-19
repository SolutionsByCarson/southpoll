<?php
/**
 * Settings (administrators with sp_manage_settings) and Integrations (sp_manage_integrations).
 *
 *   GET  /sp/v1/admin/settings                 schema groups with current values
 *   PUT  /sp/v1/admin/settings                 { values: { key: value } } → saved values or { errors }
 *   GET  /sp/v1/admin/integrations             masked credentials + configured flags
 *   PUT  /sp/v1/admin/integrations             { values: { "stripe.mode": "test", "stripe.test_secret_key": "sk_…" }, clear: [keys] }
 *   POST /sp/v1/admin/integrations/test        { service: stripe | email | geocoding }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Plugin;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Core\SettingsSchema;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Messages\TestEmail;
use SouthPoll\Membership\Farms\Geocoder;
use SouthPoll\Membership\Payments\StripeClient;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminSettingsController extends BaseController {

	public const INTEGRATION_KEYS = [ 'stripe.mode', 'stripe.test_publishable_key', 'stripe.test_secret_key', 'stripe.live_publishable_key', 'stripe.live_secret_key', 'stripe.webhook_secret', 'google.maps_browser_key', 'google.geocoding_key', 'recaptcha.site_key', 'recaptcha.secret_key', 'recaptcha.threshold', 'email.from_name', 'email.from_email' ];

	public const SECRET_KEYS = [ 'stripe.test_secret_key', 'stripe.live_secret_key', 'stripe.webhook_secret', 'google.geocoding_key', 'recaptcha.secret_key' ];

	public function __construct( private Settings $settings, private Integrations $integrations, private StripeClient $stripe, private Geocoder $geocoder, private Mailer $mailer, private Urls $urls ) {}

	public function register_routes(): void {
		$this->route( '/admin/settings', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_settings' ], 'permission_callback' => [ self::class, 'require_settings' ] ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'put_settings' ], 'permission_callback' => [ self::class, 'require_settings' ] ],
		] );
		$this->route( '/admin/integrations', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_integrations' ], 'permission_callback' => [ self::class, 'require_integrations' ] ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'put_integrations' ], 'permission_callback' => [ self::class, 'require_integrations' ] ],
		] );
		$this->route( '/admin/integrations/test', [ [ 'methods' => 'POST', 'callback' => [ $this, 'test' ], 'permission_callback' => [ self::class, 'require_integrations' ] ] ] );
	}

	public static function require_settings(): bool|WP_Error {
		return current_user_can( Capabilities::CAP_MANAGE_SETTINGS ) ? true : new WP_Error( 'sp_forbidden', __( 'Only administrators can change settings.', 'southpoll-membership' ), [ 'status' => 403 ] );
	}

	public static function require_integrations(): bool|WP_Error {
		return current_user_can( Capabilities::CAP_MANAGE_INTEGRATIONS ) ? true : new WP_Error( 'sp_forbidden', __( 'Only administrators can change integrations.', 'southpoll-membership' ), [ 'status' => 403 ] );
	}

	/* --------------------------------------------------------------- settings */

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'groups' => $this->groups(), 'values' => SettingsSchema::values( $this->settings ) ] );
	}

	/** @return array<string, mixed> schema groups with page choices resolved */
	public function groups(): array {
		$groups = SettingsSchema::groups();
		$pages  = [ 0 => __( '— none (auto) —', 'southpoll-membership' ) ];
		foreach ( get_pages( [ 'post_status' => 'publish', 'number' => 500 ] ) ?: [] as $page ) {
			$pages[ (int) $page->ID ] = $page->post_title . ' (/' . $page->post_name . '/)';
		}
		foreach ( $groups as &$g ) {
			foreach ( $g['fields'] as $key => &$f ) {
				if ( 'page' === $f['type'] ) {
					$f['options'] = $pages;
				}
			}
			unset( $f );
		}
		unset( $g );
		return $groups;
	}

	public function put_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$values = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return $this->fail( 'sp_bad_request', __( 'Nothing to save.', 'southpoll-membership' ), 400 );
		}
		$result = self::apply_settings( $this->settings, $values );
		if ( $result['errors'] ) {
			return $this->fail( 'sp_validation', __( 'Some settings could not be saved.', 'southpoll-membership' ), 422, [ 'errors' => $result['errors'] ] );
		}
		return $this->ok( [ 'ok' => true, 'saved' => $result['saved'], 'message' => __( 'Settings saved.', 'southpoll-membership' ), 'values' => SettingsSchema::values( $this->settings ) ] );
	}

	/**
	 * Validate + persist a key → value map (shared with the classic form fallback).
	 *
	 * @param array<string, mixed> $values
	 * @return array{saved:int, errors:array<string, string>}
	 */
	public static function apply_settings( Settings $settings, array $values ): array {
		$errors = [];
		$clean  = [];
		foreach ( $values as $key => $raw ) {
			[ $value, $error ] = SettingsSchema::sanitize( (string) $key, $raw );
			if ( null !== $error ) {
				$errors[ (string) $key ] = $error;
			} else {
				$clean[ (string) $key ] = $value;
			}
		}
		if ( $errors ) {
			return [ 'saved' => 0, 'errors' => $errors ];
		}
		$routes_before = $settings->get( 'routes' );
		foreach ( $clean as $key => $value ) {
			$settings->set( $key, $value );
		}
		if ( $routes_before !== $settings->get( 'routes' ) ) {
			Plugin::request_rewrite_flush();
		}
		return [ 'saved' => count( $clean ), 'errors' => [] ];
	}

	/* ----------------------------------------------------------- integrations */

	public function get_integrations( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->integration_state() );
	}

	/** @return array<string, mixed> */
	public function integration_state(): array {
		$values = [];
		foreach ( self::INTEGRATION_KEYS as $key ) {
			$v = $this->integrations->get( $key, '' );
			$values[ $key ] = in_array( $key, self::SECRET_KEYS, true ) ? Integrations::masked( (string) $v ) : $v;
		}
		$values['stripe.test_publishable_key'] = Integrations::masked( (string) $values['stripe.test_publishable_key'] );
		$values['stripe.live_publishable_key'] = Integrations::masked( (string) $values['stripe.live_publishable_key'] );
		$values['recaptcha.site_key']          = Integrations::masked( (string) $values['recaptcha.site_key'] );
		$values['google.maps_browser_key']     = Integrations::masked( (string) $values['google.maps_browser_key'] );
		return [
			'values'      => $values,
			'present'     => array_combine( self::INTEGRATION_KEYS, array_map( fn( $k ) => '' !== (string) $this->integrations->get( $k, '' ), self::INTEGRATION_KEYS ) ),
			'configured'  => [
				'stripe'    => $this->integrations->stripe_configured(),
				'webhook'   => '' !== (string) $this->integrations->get( 'stripe.webhook_secret', '' ),
				'recaptcha' => $this->integrations->recaptcha_configured(),
				'maps'      => $this->integrations->maps_configured(),
				'geocoding' => $this->geocoder->configured(),
			],
			'stripe_mode' => $this->integrations->stripe_mode(),
			'webhook_url' => $this->urls->stripe_webhook(),
		];
	}

	public function put_integrations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$values = (array) $request->get_param( 'values' );
		$clear  = array_map( 'strval', (array) $request->get_param( 'clear' ) );
		$saved  = 0;
		foreach ( $values as $key => $raw ) {
			$key = (string) $key;
			if ( ! in_array( $key, self::INTEGRATION_KEYS, true ) ) {
				continue;
			}
			$raw = is_string( $raw ) ? trim( $raw ) : $raw;
			if ( 'stripe.mode' === $key ) {
				$this->integrations->set( $key, 'live' === $raw ? 'live' : 'test' );
				$saved++;
				continue;
			}
			if ( 'recaptcha.threshold' === $key ) {
				if ( '' !== (string) $raw && is_numeric( $raw ) ) {
					$this->integrations->set( $key, max( 0.0, min( 1.0, (float) $raw ) ) );
					$saved++;
				}
				continue;
			}
			if ( 'email.from_email' === $key && '' !== (string) $raw && ! is_email( (string) $raw ) ) {
				return $this->fail( 'sp_invalid_email', __( 'The From address is not a valid email address.', 'southpoll-membership' ), 422 );
			}
			if ( '' === (string) $raw || str_contains( (string) $raw, '•' ) ) {
				continue; // blank or still masked = keep the stored value
			}
			$this->integrations->set( $key, sanitize_text_field( (string) $raw ) );
			$saved++;
		}
		foreach ( $clear as $key ) {
			if ( in_array( $key, self::INTEGRATION_KEYS, true ) && 'stripe.mode' !== $key ) {
				$this->integrations->set( $key, 'recaptcha.threshold' === $key ? 0.5 : '' );
				$saved++;
			}
		}
		return $this->ok( [ 'ok' => true, 'saved' => $saved, 'message' => __( 'Integrations saved.', 'southpoll-membership' ) ] + $this->integration_state() );
	}

	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		switch ( sanitize_key( (string) $request->get_param( 'service' ) ) ) {
			case 'stripe':
				if ( ! $this->stripe->configured() ) {
					return $this->fail( 'sp_not_configured', __( 'Enter the Stripe keys for the selected mode first.', 'southpoll-membership' ), 422 );
				}
				$res = $this->stripe->get( 'balance' );
				if ( is_wp_error( $res ) ) {
					return $this->fail( 'sp_stripe_error', sprintf( /* translators: %s: error */ __( 'Stripe rejected the key: %s', 'southpoll-membership' ), $res->get_error_message() ), 422 );
				}
				return $this->ok( [ 'ok' => true, 'message' => sprintf( /* translators: %s: mode */ __( 'Stripe connection works (%s mode).', 'southpoll-membership' ), $this->stripe->mode() ), 'livemode' => ! empty( $res['livemode'] ) ] );
			case 'email':
				$user = wp_get_current_user();
				$sent = $this->mailer->send( ( new TestEmail() )->to( $user->user_email )->with( [ 'sent_by' => $user->display_name, 'dashboard_url' => $this->urls->dashboard() ] ) );
				return $sent ? $this->ok( [ 'ok' => true, 'message' => sprintf( /* translators: %s: email */ __( 'Test email sent to %s.', 'southpoll-membership' ), $user->user_email ) ] ) : $this->fail( 'sp_mail_failed', __( 'wp_mail reported a failure; check the site’s mail setup and the plugin log.', 'southpoll-membership' ), 422 );
			case 'geocoding':
				if ( ! $this->geocoder->configured() ) {
					return $this->fail( 'sp_not_configured', __( 'Enter a Google Geocoding key first.', 'southpoll-membership' ), 422 );
				}
				$r = $this->geocoder->geocode( 'Kearney, MO 64060' );
				if ( $r ) {
					return $this->ok( [ 'ok' => true, 'message' => sprintf( /* translators: 1: lat, 2: lng */ __( 'Geocoding works (Kearney, MO → %1$s, %2$s).', 'southpoll-membership' ), $r['lat'], $r['lng'] ) ] );
				}
				$status = $this->geocoder->last_status();
				$hint   = 'REQUEST_DENIED' === $status
					? __( 'Google refused the request: the Geocoding API is not enabled for this key’s Cloud project, or the key is restricted (HTTP referrers / API restrictions). Enable "Geocoding API" under APIs & Services and allow it on the key — a browser key with referrer restrictions cannot be used server-side.', 'southpoll-membership' )
					: __( 'Check the key and that the Geocoding API is enabled and within quota.', 'southpoll-membership' );
				return $this->fail( 'sp_geocode_failed', sprintf( /* translators: 1: Google status, 2: hint */ __( 'Google answered %1$s. %2$s', 'southpoll-membership' ), $status ?: 'no result', $hint ), 422 );
			case 'maps':
				return $this->integrations->maps_configured() ? $this->ok( [ 'ok' => true, 'message' => __( 'A browser key is stored. Open the member directory map view to confirm it loads (browser keys can only be tested in a browser).', 'southpoll-membership' ) ] ) : $this->fail( 'sp_not_configured', __( 'Enter a Google Maps browser key first.', 'southpoll-membership' ), 422 );
			case 'recaptcha':
				return $this->integrations->recaptcha_configured() ? $this->ok( [ 'ok' => true, 'message' => __( 'reCAPTCHA keys are stored. Submit the login or contact form as a visitor to confirm the badge appears.', 'southpoll-membership' ) ] ) : $this->fail( 'sp_not_configured', __( 'Enter the reCAPTCHA site and secret keys first.', 'southpoll-membership' ), 422 );
		}
		return $this->fail( 'sp_bad_service', __( 'Unknown service.', 'southpoll-membership' ), 400 );
	}
}
