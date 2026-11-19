<?php
/**
 * Plugin settings (non-secret). Stored in one autoloaded option; dot-notation access.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Settings {

	public const OPTION = 'sp_settings';

	private ?array $cache = null;

	/**
	 * Every setting with its default. Admin UI (T4.8) edits these.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			// Listings.
			'listing_limit_default'          => 10,   // active listings per farm unless the tier overrides
			'listing_duration_days'          => 60,
			'listing_renewal_reminder_days'  => 7,
			'listing_sold_visible_days'      => 14,
			'listing_new_badge_days'         => 7,
			'listing_max_images'             => 10,
			'listing_max_image_mb'           => 10,
			'listing_excerpt_words'          => 30,
			'classifieds_per_page'           => 20,
			'classifieds_sort_default'       => 'newest',

			// Alerts (saved searches + favorites). Per-user emails default off (spec); these shape the pipeline.
			'alerts_per_run'                 => 50,   // emails sent per 5-minute queue run
			'alert_throttle_minutes'         => 60,   // max one alert per saved search / favorite farm per user per hour (D-22)
			'alert_queue_retention_days'     => 30,

			// Memberships.
			'membership_expiring_window_days'        => 30,
			'membership_countdown_days'              => 7,
			'membership_reminder_days'               => [ 30, 7, 1 ],
			'membership_post_expiry_reminder_days'   => [ 7, 30 ],
			'membership_grace_days'                  => 0,
			'membership_offline_claims_enabled'      => true,

			// Accounts.
			'require_email_verification'     => true,
			'recaptcha_enabled'              => true,  // master switch (keys live in Integrations); off = skip verification
			'contact_reveal_rate_limit'      => 60,   // reveals per user per hour
			'contact_form_rate_limit'        => 5,    // submissions per IP per hour
			'login_lockout_attempts'         => 6,
			'login_lockout_minutes'          => 15,

			// URL bases (first path segment of every plugin route) and optional host pages.
			// A host page supplies the theme chrome, SEO context and page title for theme-wrapped routes;
			// when 0 the plugin looks for a page whose slug equals the base, else renders a virtual page.
			'routes' => [
				'members'     => 'members',
				'classifieds' => 'classifieds',
				'farms'       => 'farms',
				'favorites'   => 'favorites',
				'search'      => 'search',
			],
			'page_directory'                 => 0,
			'page_classifieds'               => 0,
			'page_login'                     => 0,
			'page_help'                      => 0,

			// Membership admin contact (displayed + notified).
			'admin_contact' => [
				'name'  => 'SPGCA Registry',
				'phone' => '816-629-8010',
				'email' => 'registry@southpoll.com',
			],
			'admin_notification_emails'      => [ 'registry@southpoll.com' ],

			// Email behaviour.
			'email_from_override'            => false,
			'email_window_start'             => '07:00', // site time; deferrable notices (reminders, expiries) wait for this window
			'email_window_end'               => '20:00', // empty = until midnight; empty start = send immediately

			// Registry digest (T6.8, daily since S9): who to add to / remove from the cattle registry.
			'registry_digest_enabled'        => true,
			'registry_digest_emails'         => [ 'registry@southpoll.com' ],
			'registry_digest_days'           => 'mon,tue,wed,thu,fri,sat,sun', // daily (Carson, 2026-11-18, S9); was mon,thu
			'registry_digest_time'           => '08:00',
			'registry_digest_send_empty'     => false, // quiet days are skipped (Carson, 2026-11-18, S9); Kathy can switch it on

			// Notification defaults for new accounts (spec: always default off).
			'default_notify_favorites'       => false,
			'default_notify_searches'        => false,

			// Directory / classifieds UI.
			'pagination_default'             => 25,
			'pagination_options'             => [ 10, 25, 50, 100, 'all' ],
			'directory_per_page'             => 24,   // farm cards per page (public feed)
			'directory_sort_default'         => 'name_asc', // name_asc|name_desc|active
			'map_guest_precision'            => 'city', // city|exact — guests see pins rounded to ~7 miles (D-21)
			'map_default_center'             => [ 'lat' => 37.8, 'lng' => -92.5, 'zoom' => 5 ],
			'region_map'                     => self::default_region_map(),

			// Farms.
			'farm_image_max_mb'              => 10,
			'farm_gallery_max_items'         => 20,
			'farm_new_listing_badge_days'    => 7,
			'inquiry_admin_copy'             => false, // copy farm contact-form messages to the membership admin
			'inquiry_max_message_chars'      => 2000,

			// Launch & feedback (soft launch, 2026-11-18). Off by default; switched on for the launch, see temp/08-soft-launch-plan.md.
			'launch_notice_enabled'          => false,
			'launch_notice_title'            => 'Welcome to the new SouthPoll.com member site',
			'launch_notice_text'             => "Your login is the same e-mail address as before. If you have never logged in here, use \"Forgot your password?\" to set one.\nSomething not working or looks wrong? Use the Feedback button in the corner of the page and tell us.",
			'launch_notice_link_label'       => '',
			'launch_notice_link_url'         => '',
			'launch_notice_until'            => '',
			'launch_popup_enabled'           => false,
			'launch_popup_title'             => 'Welcome to the new member dashboard',
			'launch_popup_text'              => "This is the first release of the new SouthPoll.com member site. Your farm page, listings, membership and favorites are all here.\nIf anything looks wrong or does not work, please use the Feedback button at the bottom-right of the page — every message goes straight to the team.",
			'launch_popup_version'           => '2026-11',
			'feedback_enabled'               => false,
			'feedback_emails'                => [ 'carson@solutionsbycarson.com' ],
			'feedback_label'                 => 'Feedback / report a problem',

			// Misc.
			'legacy_redirects_enabled'       => false, // cutover: 301 old Classified Listing URLs (sp_redirects + patterns)
			'debug'                          => false,
			'delete_data_on_uninstall'       => false,
		];
	}

	/**
	 * State → region map carried over from the child theme's [member_grid] shortcode.
	 *
	 * @return array<string, string[]>
	 */
	public static function default_region_map(): array {
		return [
			'East'    => [ 'MI', 'IN', 'OH', 'KY', 'VA', 'WV', 'MD', 'DE', 'NJ', 'CT', 'RI', 'MA', 'VT', 'NH', 'ME', 'PA' ],
			'South'   => [ 'OK', 'AR', 'TN', 'NC', 'SC', 'GA', 'AL', 'MS', 'LA', 'TX', 'FL' ],
			'Midwest' => [ 'ND', 'SD', 'MN', 'WI', 'IL', 'IA', 'NE', 'KS', 'MO' ],
			'West'    => [ 'WA', 'ID', 'MT', 'WY', 'OR', 'CA', 'NV', 'UT', 'AZ', 'CO', 'NM', 'AK', 'HI' ],
		];
	}

	/**
	 * All settings, defaults merged under stored values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_replace( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * Get a setting. Supports dot notation for nested arrays (e.g. "admin_contact.email").
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$value = $this->all();
		foreach ( explode( '.', $key ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	public function set( string $key, mixed $value ): void {
		$all  = $this->all();
		$ref  = &$all;
		$path = explode( '.', $key );
		$last = array_pop( $path );
		foreach ( $path as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = [];
			}
			$ref = &$ref[ $segment ];
		}
		$ref[ $last ] = $value;
		$this->persist( $all );
	}

	/**
	 * Merge many top-level values at once.
	 *
	 * @param array<string, mixed> $values
	 */
	public function update( array $values ): void {
		$this->persist( array_replace( $this->all(), $values ) );
	}

	public function region_for_state( string $state ): string {
		$state = strtoupper( trim( $state ) );
		foreach ( (array) $this->get( 'region_map', [] ) as $region => $states ) {
			if ( in_array( $state, (array) $states, true ) ) {
				return (string) $region;
			}
		}
		return '';
	}

	public function flush(): void {
		$this->cache = null;
	}

	private function persist( array $all ): void {
		update_option( self::OPTION, $all, true );
		/**
		 * Settings were persisted (jobs with their own calendar reschedule on this).
		 *
		 * @param array<string, mixed> $all
		 */
		do_action( 'sp/settings/updated', $all );
		$this->cache = $all;
	}
}
