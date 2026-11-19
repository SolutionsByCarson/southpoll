<?php
/**
 * Owner accounts cannot be deleted while they own a farm (D-19 rule 4): block core user deletion and keep the plugin's
 * own rows tidy when a buyer/manager account is removed.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Members;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Service;

final class UserGuards implements Service {

	public function __construct( private FarmUserRepository $farm_users ) {}

	public function register(): void {
		add_action( 'delete_user', [ $this, 'before_delete' ], 1, 1 );
		add_action( 'deleted_user', [ $this, 'after_delete' ], 10, 1 );
		add_filter( 'user_row_actions', [ $this, 'row_actions' ], 10, 2 );
	}

	public function owns_farm( int $user_id ): bool {
		$row = $this->farm_users->for_user( $user_id );
		return null !== $row && FarmUserRepository::ROLE_OWNER === (string) $row->role;
	}

	public function before_delete( int $user_id ): void {
		if ( $this->owns_farm( $user_id ) ) {
			wp_die( esc_html__( 'This account owns a farm in the SouthPoll member directory. Transfer the farm to another owner (SouthPoll → Farms) before deleting it.', 'southpoll-membership' ), esc_html__( 'Cannot delete farm owner', 'southpoll-membership' ), [ 'back_link' => true ] );
		}
	}

	public function after_delete( int $user_id ): void {
		global $wpdb;
		foreach ( [ 'sp_members', 'sp_farm_users', 'sp_favorites', 'sp_saved_searches', 'sp_notification_queue' ] as $t ) {
			$wpdb->delete( Schema::table( $t ), [ 'user_id' => $user_id ] );
		}
	}

	/** @param array<string, string> $actions */
	public function row_actions( array $actions, \WP_User $user ): array {
		if ( $this->owns_farm( (int) $user->ID ) ) {
			unset( $actions['delete'] );
		}
		return $actions;
	}
}
