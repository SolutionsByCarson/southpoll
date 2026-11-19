<?php
/**
 * Roles and capabilities.
 *
 * WordPress roles only distinguish staff from everyone else:
 *   - administrator: sp_manage + sp_manage_settings + sp_manage_integrations
 *   - editor:        sp_manage (users, farms, listings, taxonomies, meta fields, analytics)
 *   - sp_buyer:      every member-area account (buyers, farm owners, account managers)
 * What a member may do (edit a farm, publish a listing, invite managers…) is computed per request
 * from farm membership state by Members\MemberContext, never stored as WP caps, so expiry takes
 * effect instantly.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use SouthPoll\Membership\Members\ContextProvider;

final class Capabilities implements Service {

	public const ROLE_BUYER = 'sp_buyer';

	public const CAP_MANAGE              = 'sp_manage';
	public const CAP_MANAGE_SETTINGS     = 'sp_manage_settings';
	public const CAP_MANAGE_INTEGRATIONS = 'sp_manage_integrations';

	public function __construct( private ContextProvider $contexts ) {}

	public function register(): void {
		add_action( 'init', [ self::class, 'install_roles' ], 5 );
		add_filter( 'show_admin_bar', [ $this, 'filter_admin_bar' ], 20 );
		add_action( 'admin_init', [ $this, 'block_wp_admin_for_members' ] );
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Create the buyer role and grant staff caps. Idempotent; cheap enough to run on init.
	 */
	public static function install_roles(): void {
		if ( null === get_role( self::ROLE_BUYER ) ) {
			add_role(
				self::ROLE_BUYER,
				_x( 'SouthPoll Member Account', 'user role', 'southpoll-membership' ),
				[ 'read' => true ]
			);
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( [ self::CAP_MANAGE, self::CAP_MANAGE_SETTINGS, self::CAP_MANAGE_INTEGRATIONS ] as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$admin->add_cap( $cap );
				}
			}
		}
		$editor = get_role( 'editor' );
		if ( $editor && ! $editor->has_cap( self::CAP_MANAGE ) ) {
			$editor->add_cap( self::CAP_MANAGE );
		}
	}

	public static function remove_roles(): void {
		remove_role( self::ROLE_BUYER );
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( [ self::CAP_MANAGE, self::CAP_MANAGE_SETTINGS, self::CAP_MANAGE_INTEGRATIONS ] as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Staff = anyone who can manage the plugin (administrators and editors by default).
	 */
	public static function is_staff( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id > 0 && user_can( $user_id, self::CAP_MANAGE );
	}

	/**
	 * Can the user reach wp-admin at all? Staff, or anyone with a content role (authors, contributors…).
	 */
	public static function can_use_wp_admin( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id > 0 && ( user_can( $user_id, self::CAP_MANAGE ) || user_can( $user_id, 'edit_posts' ) );
	}

	public function filter_admin_bar( bool $show ): bool {
		if ( is_user_logged_in() && ! self::can_use_wp_admin() ) {
			return false;
		}
		return $show;
	}

	/**
	 * Member-area accounts have no business in wp-admin; send them to their dashboard.
	 */
	public function block_wp_admin_for_members(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_user_logged_in() || self::can_use_wp_admin() ) {
			return;
		}
		/**
		 * @param string $url Where non-staff accounts land when they hit wp-admin.
		 */
		$target = (string) apply_filters( 'sp/member_dashboard_url', home_url( '/members/dashboard/' ) );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Expose member-area permissions through current_user_can( 'sp_member_can', 'publish_listing' ).
	 *
	 * @param string[] $caps
	 * @param string   $cap
	 * @param int      $user_id
	 * @param array    $args
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'sp_member_can' !== $cap ) {
			return $caps;
		}
		$action = isset( $args[0] ) ? (string) $args[0] : '';
		return $this->contexts->for_user( $user_id )->can( $action ) ? [ 'exist' ] : [ 'do_not_allow' ];
	}
}
