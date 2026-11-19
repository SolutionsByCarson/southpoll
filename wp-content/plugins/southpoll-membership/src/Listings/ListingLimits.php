<?php
/**
 * How many listings a farm may have live at once: the tier's `listing_limit`, else the global default (D-18).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;

final class ListingLimits {

	public function __construct( private Settings $settings, private MembershipRepository $memberships, private StatusEngine $engine, private ListingRepository $listings ) {}

	public function limit_for_farm( int $farm_id ): int {
		$membership = $this->memberships->for_farm( $farm_id );
		$tier       = $this->engine->tier_for( $membership );
		$limit      = $tier && null !== $tier->listing_limit && '' !== (string) $tier->listing_limit ? (int) $tier->listing_limit : (int) $this->settings->get( 'listing_limit_default', 10 );
		/**
		 * @param int $limit
		 * @param int $farm_id
		 */
		return max( 0, (int) apply_filters( 'sp/listings/limit', $limit, $farm_id ) );
	}

	public function active_count( int $farm_id, int $exclude_id = 0 ): int {
		return $this->listings->count_active( $farm_id, $exclude_id );
	}

	public function remaining( int $farm_id, int $exclude_id = 0 ): int {
		return max( 0, $this->limit_for_farm( $farm_id ) - $this->active_count( $farm_id, $exclude_id ) );
	}

	public function can_publish_another( int $farm_id, int $exclude_id = 0 ): bool {
		return $this->remaining( $farm_id, $exclude_id ) > 0;
	}

	/** @return array{limit:int, active:int, remaining:int} */
	public function summary( int $farm_id ): array {
		$limit  = $this->limit_for_farm( $farm_id );
		$active = $this->active_count( $farm_id );
		return [ 'limit' => $limit, 'active' => $active, 'remaining' => max( 0, $limit - $active ) ];
	}
}
