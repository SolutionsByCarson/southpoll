<?php
/**
 * Server-side Google Geocoding for farm addresses. Transport is injectable so tests never hit Google.
 * Without a geocoding key the geocoder is inert (logs once) and farms simply have no map pin.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use WP_Error;

final class Geocoder {

	public const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

	/** @var callable|null fn(string $url): array|WP_Error (wp_remote_get-compatible) */
	private $transport = null;

	private bool $warned = false;

	/** Status of the last request: OK, ZERO_RESULTS, REQUEST_DENIED, OVER_QUERY_LIMIT, … or 'transport' / 'invalid'. */
	private string $last_status = '';

	/** Statuses that mean the *service* failed (key, quota, outage) rather than the address being unresolvable. */
	public const SERVICE_FAILURES = [ 'REQUEST_DENIED', 'OVER_DAILY_LIMIT', 'OVER_QUERY_LIMIT', 'UNKNOWN_ERROR', 'transport', 'invalid' ];

	public function __construct( private Integrations $integrations, private Logger $logger, private FarmRepository $farms ) {}

	public function last_status(): string {
		return $this->last_status;
	}

	public function service_failed(): bool {
		return in_array( $this->last_status, self::SERVICE_FAILURES, true );
	}

	public function set_transport( ?callable $transport ): void {
		$this->transport = $transport;
	}

	public function key(): string {
		return (string) $this->integrations->get( 'google.geocoding_key', '' );
	}

	public function configured(): bool {
		return '' !== $this->key();
	}

	/**
	 * @return array{lat:float, lng:float, formatted:string, precision:string}|null
	 */
	public function geocode( string $address ): ?array {
		$address = trim( preg_replace( '/\s+/', ' ', $address ) ?? '' );
		if ( '' === $address ) {
			return null;
		}
		if ( ! $this->configured() ) {
			if ( ! $this->warned ) {
				$this->logger->info( 'Geocoding skipped: no Google geocoding key configured (SouthPoll → Integrations).' );
				$this->warned = true;
			}
			return null;
		}
		$url      = add_query_arg( [ 'address' => rawurlencode( $address ), 'region' => 'us', 'key' => rawurlencode( $this->key() ) ], self::ENDPOINT );
		$response = $this->transport ? ( $this->transport )( $url ) : wp_remote_get( $url, [ 'timeout' => 8 ] );
		if ( is_wp_error( $response ) ) {
			$this->last_status = 'transport';
			$this->logger->warning( 'Geocoding request failed: ' . $response->get_error_message() );
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$this->last_status = is_array( $data ) ? (string) ( $data['status'] ?? 'invalid' ) : 'invalid';
		if ( ! is_array( $data ) || 'OK' !== $this->last_status || empty( $data['results'][0]['geometry']['location'] ) ) {
			if ( $this->service_failed() ) {
				// Key / quota / outage: say so loudly (once per request) and do not treat the address as unresolvable.
				$this->logger->warning( 'Geocoding service error: ' . $this->last_status . ( ! empty( $data['error_message'] ) ? ' — ' . $data['error_message'] : '' ) );
			} else {
				$this->logger->info( 'Geocoding returned no result', [ 'status' => $this->last_status, 'address' => $address ] );
			}
			return null;
		}
		$r = $data['results'][0];
		return [
			'lat'       => (float) $r['geometry']['location']['lat'],
			'lng'       => (float) $r['geometry']['location']['lng'],
			'formatted' => (string) ( $r['formatted_address'] ?? '' ),
			'precision' => (string) ( $r['geometry']['location_type'] ?? '' ),
		];
	}

	/**
	 * Geocode a farm's address and store the result. Returns true when coordinates were stored.
	 * Farms with no usable address get their coordinates cleared.
	 */
	public function geocode_farm( object $farm ): bool {
		$address = FarmRepository::address_line( $farm );
		if ( '' === trim( $address ) ) {
			$this->farms->set_geo( (int) $farm->id, null, null );
			return false;
		}
		$result = $this->geocode( $address );
		if ( null === $result ) {
			if ( $this->configured() && ! $this->service_failed() ) {
				// Remember the attempt so the backfill does not hammer Google for unresolvable addresses.
				// (A service failure — bad key, API not enabled, quota — is not the address's fault: leave it for the next run.)
				$this->farms->set_geo( (int) $farm->id, null, null );
			}
			return false;
		}
		$this->farms->set_geo( (int) $farm->id, $result['lat'], $result['lng'] );
		$this->logger->debug( "Geocoded farm {$farm->id}", [ 'precision' => $result['precision'] ] );
		do_action( 'sp/farm/geocoded', (int) $farm->id, $result );
		return true;
	}

	/**
	 * Geocode farms that have an address but no coordinates.
	 *
	 * @return array{attempted:int, geocoded:int, error:string} error = service status that stopped the batch ('' when none)
	 */
	public function backfill( int $limit = 25 ): array {
		$attempted = 0;
		$done      = 0;
		if ( ! $this->configured() ) {
			return [ 'attempted' => 0, 'geocoded' => 0, 'error' => '' ];
		}
		$error = '';
		foreach ( $this->farms->missing_coordinates( $limit ) as $farm ) {
			$attempted++;
			if ( $this->geocode_farm( $farm ) ) {
				$done++;
			} elseif ( $this->service_failed() ) {
				$error = $this->last_status;
				break; // the service is refusing us: stop the batch instead of burning the whole quota on errors
			}
		}
		if ( $attempted ) {
			$this->logger->info( "Geocoder backfill: {$done}/{$attempted} farms geocoded." . ( $error ? " Stopped: $error." : '' ) );
		}
		return [ 'attempted' => $attempted, 'geocoded' => $done, 'error' => $error ];
	}
}
