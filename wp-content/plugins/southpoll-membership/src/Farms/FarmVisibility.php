<?php
/**
 * Who may see a farm page, and the farm-level events Phase 3 listens to when a membership lapses or is restored
 * (roadmap §3.1 lapse table). Directory queries apply the same rule in SQL (DirectoryQuery::base_sql).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;

final class FarmVisibility implements Service {

	public const PUBLIC_STATE = 'public';

	public function __construct( private StatusEngine $engine, private MembershipRepository $memberships, private FarmRepository $farms, private Logger $logger ) {}

	public function register(): void {
		add_action( 'sp/membership/lapsed', [ $this, 'on_lapsed' ], 10, 2 );
		add_action( 'sp/membership/restored', [ $this, 'on_restored' ], 10, 2 );
	}

	/**
	 * public | inactive (staff switched the farm off) | none | pending | expired | cancelled
	 */
	public function state( object $farm, ?object $membership = null ): string {
		if ( 'active' !== (string) $farm->status ) {
			return 'inactive';
		}
		$membership ??= $this->memberships->for_farm( (int) $farm->id );
		$status       = $this->engine->effective_status( $membership );
		return 'active' === $status ? self::PUBLIC_STATE : $status;
	}

	public function is_public( object $farm, ?object $membership = null ): bool {
		return self::PUBLIC_STATE === $this->state( $farm, $membership );
	}

	/** The farm's own people (and staff) can always open the page, with a notice instead of the public view. */
	public function can_preview( object $farm, MemberContext $ctx ): bool {
		return $ctx->is_staff() || ( $ctx->has_farm() && $ctx->farm_id() === (int) $farm->id );
	}

	public function on_lapsed( object $membership, string $reason ): void {
		$farm = $this->farms->find( (int) $membership->farm_id );
		if ( ! $farm ) {
			return;
		}
		$this->logger->info( "Farm {$farm->id} hidden from the directory ({$reason})." );
		/**
		 * Farm is no longer public (membership expired/cancelled). Phase 3 unpublishes its listings here.
		 *
		 * @param object $farm
		 * @param string $reason expired|cancelled
		 */
		do_action( 'sp/farm/hidden', $farm, $reason );
	}

	public function on_restored( object $membership, string $was ): void {
		$farm = $this->farms->find( (int) $membership->farm_id );
		if ( ! $farm ) {
			return;
		}
		$this->logger->info( "Farm {$farm->id} visible in the directory again (was {$was})." );
		/**
		 * Farm is public again. Phase 3 restores its listings here.
		 *
		 * @param object $farm
		 * @param string $was previous membership status
		 */
		do_action( 'sp/farm/shown', $farm, $was );
	}
}
