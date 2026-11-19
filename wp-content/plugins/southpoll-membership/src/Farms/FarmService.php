<?php
/**
 * Farm lifecycle helpers that span repositories (farm + owner row + membership row).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use WP_Error;

final class FarmService {

	public function __construct(
		private FarmRepository $farms,
		private FarmUserRepository $farm_users,
		private MembershipRepository $memberships
	) {}

	/**
	 * Create the farm shell for a new member: farm row, owner attachment, membership row (status none).
	 *
	 * @param array<string, mixed> $data Extra farm columns (state, city, phone…)
	 */
	public function create_for_owner( int $user_id, string $name, array $data = [] ): \stdClass|WP_Error {
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'sp_farm_name_required', __( 'Please enter your farm or ranch name.', 'southpoll-membership' ) );
		}
		if ( $this->farm_users->for_user( $user_id ) ) {
			return new WP_Error( 'sp_already_attached', __( 'This account already belongs to a farm.', 'southpoll-membership' ) );
		}
		$farm = $this->farms->create( $user_id, $name, $data );
		$this->farm_users->add_owner( (int) $farm->id, $user_id );
		$this->memberships->ensure( (int) $farm->id );
		return $farm;
	}

	public function farm_for_user( int $user_id ): ?object {
		$row = $this->farm_users->for_user( $user_id );
		return $row ? $this->farms->find( (int) $row->farm_id ) : null;
	}
}
