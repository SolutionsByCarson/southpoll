<?php
/**
 * Wires the geocoder to farm events: re-geocode when an address changes, backfill nightly.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Service;

final class GeoService implements Service {

	public function __construct( private Geocoder $geocoder ) {}

	public function register(): void {
		add_action( 'sp/farm/address_changed', [ $this, 'on_address_changed' ], 10, 1 );
		add_action( 'sp/farm/created', [ $this, 'on_created' ] );
		add_action( 'sp/cron/daily', [ $this, 'nightly_backfill' ] );
	}

	public function on_address_changed( object $farm ): void {
		$this->geocoder->geocode_farm( $farm );
	}

	public function on_created( object $farm ): void {
		if ( '' !== FarmRepository::address_line( $farm ) ) {
			$this->geocoder->geocode_farm( $farm );
		}
	}

	public function nightly_backfill(): void {
		$this->geocoder->backfill( 50 );
	}
}
