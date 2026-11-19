<?php
/**
 * Describes every admin-editable setting: group, label, type, bounds, help. Drives the Settings page, REST validation
 * and the settings CSV. Keys not listed here are internal and never exposed.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class SettingsSchema {

	/**
	 * @return array<string, array{title:string, description?:string, fields:array<string, array<string, mixed>>}>
	 */
	public static function groups(): array {
		return [
			'listings'    => [
				'title'  => __( 'Classified listings', 'southpoll-membership' ),
				'fields' => [
					'listing_limit_default'         => [ 'label' => __( 'Active listings per farm', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 500, 'help' => __( 'A membership tier can override this.', 'southpoll-membership' ) ],
					'listing_duration_days'         => [ 'label' => __( 'Listing runs for (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
					'listing_renewal_reminder_days' => [ 'label' => __( 'Renewal reminder (days before expiry)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 60 ],
					'listing_sold_visible_days'     => [ 'label' => __( 'Sold listings stay visible (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 0, 'max' => 90 ],
					'listing_new_badge_days'        => [ 'label' => __( '"New listing" badge (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 0, 'max' => 60 ],
					'listing_max_images'            => [ 'label' => __( 'Gallery items per listing', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 50 ],
					'listing_max_image_mb'          => [ 'label' => __( 'Max image size (MB)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 50 ],
					'listing_excerpt_words'         => [ 'label' => __( 'Card excerpt length (words)', 'southpoll-membership' ), 'type' => 'number', 'min' => 10, 'max' => 100 ],
					'classifieds_per_page'          => [ 'label' => __( 'Listings per page', 'southpoll-membership' ), 'type' => 'number', 'min' => 5, 'max' => 200 ],
					'classifieds_sort_default'      => [ 'label' => __( 'Default sort', 'southpoll-membership' ), 'type' => 'select', 'options' => [ 'newest' => __( 'Newest first', 'southpoll-membership' ), 'oldest' => __( 'Oldest first', 'southpoll-membership' ), 'price_asc' => __( 'Price: low to high', 'southpoll-membership' ), 'price_desc' => __( 'Price: high to low', 'southpoll-membership' ), 'title' => __( 'Title A–Z', 'southpoll-membership' ) ] ],
				],
			],
			'memberships' => [
				'title'  => __( 'Memberships', 'southpoll-membership' ),
				'description' => __( 'Price and term length live on the membership tier (Memberships → Tiers).', 'southpoll-membership' ),
				'fields' => [
					'membership_expiring_window_days'      => [ 'label' => __( '"Expiring soon" window (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 120 ],
					'membership_countdown_days'            => [ 'label' => __( 'Countdown banner (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 60 ],
					'membership_reminder_days'             => [ 'label' => __( 'Reminders before expiry (days, comma-separated)', 'southpoll-membership' ), 'type' => 'int_list', 'help' => __( 'e.g. 30, 7, 1', 'southpoll-membership' ) ],
					'membership_post_expiry_reminder_days' => [ 'label' => __( 'Prompts after expiry (days, comma-separated)', 'southpoll-membership' ), 'type' => 'int_list', 'help' => __( 'e.g. 7, 30', 'southpoll-membership' ) ],
					'membership_grace_days'                => [ 'label' => __( 'Grace period (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 0, 'max' => 90, 'help' => __( 'Reserved; visibility currently ends at the end date.', 'southpoll-membership' ) ],
					'membership_offline_claims_enabled'    => [ 'label' => __( 'Allow "I paid by check/cash" claims', 'southpoll-membership' ), 'type' => 'toggle' ],
				],
			],
			'accounts'    => [
				'title'  => __( 'Accounts & protection', 'southpoll-membership' ),
				'fields' => [
					'require_email_verification' => [ 'label' => __( 'Require email verification before contact details are shown', 'southpoll-membership' ), 'type' => 'toggle' ],
					'recaptcha_enabled'          => [ 'label' => __( 'reCAPTCHA v3 on sign-up, login and guest contact forms', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'Keys are entered under Integrations.', 'southpoll-membership' ) ],
					'contact_reveal_rate_limit'  => [ 'label' => __( 'Contact reveals per account per hour', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 10000 ],
					'contact_form_rate_limit'    => [ 'label' => __( 'Contact-form messages per visitor per hour', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 100 ],
					'inquiry_max_message_chars'  => [ 'label' => __( 'Contact-form message length (characters)', 'southpoll-membership' ), 'type' => 'number', 'min' => 200, 'max' => 10000 ],
					'inquiry_admin_copy'         => [ 'label' => __( 'Copy the membership admin on every contact-form message', 'southpoll-membership' ), 'type' => 'toggle' ],
					'login_lockout_attempts'     => [ 'label' => __( 'Login lockout after (failed attempts)', 'southpoll-membership' ), 'type' => 'number', 'min' => 3, 'max' => 50 ],
					'login_lockout_minutes'      => [ 'label' => __( 'Lockout length (minutes)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 1440 ],
				],
			],
			'contact'     => [
				'title'  => __( 'Membership admin & email', 'southpoll-membership' ),
				'fields' => [
					'admin_contact.name'         => [ 'label' => __( 'Membership admin name', 'southpoll-membership' ), 'type' => 'text' ],
					'admin_contact.phone'        => [ 'label' => __( 'Membership admin phone', 'southpoll-membership' ), 'type' => 'text' ],
					'admin_contact.email'        => [ 'label' => __( 'Membership admin email (shown to members)', 'southpoll-membership' ), 'type' => 'email' ],
					'admin_notification_emails'  => [ 'label' => __( 'Office notification addresses (comma-separated)', 'southpoll-membership' ), 'type' => 'email_list', 'help' => __( 'New accounts, memberships, claims, failed payments, new listings.', 'southpoll-membership' ) ],
					'email_from_override'        => [ 'label' => __( 'Send plugin emails from the address under Integrations → Email', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'Off = use the site’s normal From address.', 'southpoll-membership' ) ],
					'default_notify_favorites'   => [ 'label' => __( 'New accounts: subscribe to favorite alerts by default', 'southpoll-membership' ), 'type' => 'toggle' ],
					'default_notify_searches'    => [ 'label' => __( 'New accounts: subscribe to saved-search alerts by default', 'southpoll-membership' ), 'type' => 'toggle' ],
					'email_window_start'         => [ 'label' => __( 'Send reminders from (site time, HH:MM)', 'southpoll-membership' ), 'type' => 'time', 'help' => __( 'Renewal reminders, expiry and listing notices wait in the queue until this time of day instead of going out whenever the hourly job runs. Leave empty to send immediately.', 'southpoll-membership' ) ],
					'email_window_end'           => [ 'label' => __( 'Send reminders until (site time, HH:MM)', 'southpoll-membership' ), 'type' => 'time', 'help' => __( 'Notices generated after this time go out the next morning. Empty = until midnight.', 'southpoll-membership' ) ],
				],
			],
			'registry'    => [
				'title'  => __( 'Cattle registry digest', 'southpoll-membership' ),
				'fields' => [
					'registry_digest_enabled'    => [ 'label' => __( 'Send the registry digest', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'One email per run listing the members to ADD to the cattle registry (memberships that became active since the previous digest) and to REMOVE (memberships that expired or were cancelled), with a CSV attached.', 'southpoll-membership' ) ],
					'registry_digest_emails'     => [ 'label' => __( 'Digest recipients (comma-separated)', 'southpoll-membership' ), 'type' => 'email_list' ],
					'registry_digest_days'       => [ 'label' => __( 'Days (comma-separated: mon, tue, wed, thu, fri, sat, sun)', 'southpoll-membership' ), 'type' => 'days' ],
					'registry_digest_time'       => [ 'label' => __( 'Time (site time, HH:MM)', 'southpoll-membership' ), 'type' => 'time' ],
					'registry_digest_send_empty' => [ 'label' => __( 'Also send when there is nothing to add or remove', 'southpoll-membership' ), 'type' => 'toggle' ],
				],
			],
			'directory'   => [
				'title'  => __( 'Member directory & farms', 'southpoll-membership' ),
				'fields' => [
					'directory_per_page'          => [ 'label' => __( 'Farms per page', 'southpoll-membership' ), 'type' => 'number', 'min' => 4, 'max' => 200 ],
					'directory_sort_default'      => [ 'label' => __( 'Default sort', 'southpoll-membership' ), 'type' => 'select', 'options' => [ 'name_asc' => __( 'Name A–Z', 'southpoll-membership' ), 'name_desc' => __( 'Name Z–A', 'southpoll-membership' ), 'active' => __( 'Most active', 'southpoll-membership' ) ] ],
					'map_guest_precision'         => [ 'label' => __( 'Map pins for visitors who are not logged in', 'southpoll-membership' ), 'type' => 'select', 'options' => [ 'city' => __( 'Approximate (about 7 miles)', 'southpoll-membership' ), 'exact' => __( 'Exact', 'southpoll-membership' ) ] ],
					'map_default_center.lat'      => [ 'label' => __( 'Map default centre latitude', 'southpoll-membership' ), 'type' => 'float', 'min' => -90, 'max' => 90 ],
					'map_default_center.lng'      => [ 'label' => __( 'Map default centre longitude', 'southpoll-membership' ), 'type' => 'float', 'min' => -180, 'max' => 180 ],
					'map_default_center.zoom'     => [ 'label' => __( 'Map default zoom', 'southpoll-membership' ), 'type' => 'number', 'min' => 2, 'max' => 15 ],
					'farm_image_max_mb'           => [ 'label' => __( 'Max farm image size (MB)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 50 ],
					'farm_gallery_max_items'      => [ 'label' => __( 'Farm gallery items', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 100 ],
					'farm_new_listing_badge_days' => [ 'label' => __( 'Farm "new listing" badge (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 0, 'max' => 60 ],
					'region_map'                  => [ 'label' => __( 'Regions (one per line: Region: ST, ST, ST)', 'southpoll-membership' ), 'type' => 'region_map', 'help' => __( 'Farms are assigned a region from their state automatically.', 'southpoll-membership' ) ],
				],
			],
			'pages'       => [
				'title'  => __( 'Pages & URLs', 'southpoll-membership' ),
				'description' => __( 'The plugin renders its screens at these addresses. Assign a WordPress page to supply the theme header/footer context and SEO settings; the page content itself is replaced.', 'southpoll-membership' ),
				'fields' => [
					'routes.members'     => [ 'label' => __( 'Member area base', 'southpoll-membership' ), 'type' => 'slug', 'help' => __( 'Directory + /login/, /dashboard/, …', 'southpoll-membership' ) ],
					'routes.classifieds' => [ 'label' => __( 'Classifieds base', 'southpoll-membership' ), 'type' => 'slug' ],
					'routes.farms'       => [ 'label' => __( 'Farm pages base', 'southpoll-membership' ), 'type' => 'slug' ],
					'routes.favorites'   => [ 'label' => __( 'Shared favorites base', 'southpoll-membership' ), 'type' => 'slug' ],
					'routes.search'      => [ 'label' => __( 'Shared search base', 'southpoll-membership' ), 'type' => 'slug' ],
					'page_directory'     => [ 'label' => __( 'Host page for the directory', 'southpoll-membership' ), 'type' => 'page' ],
					'page_classifieds'   => [ 'label' => __( 'Host page for the classifieds', 'southpoll-membership' ), 'type' => 'page' ],
					'page_login'         => [ 'label' => __( 'Host page for login', 'southpoll-membership' ), 'type' => 'page' ],
					'pagination_default' => [ 'label' => __( 'Table rows per page (member + admin tables)', 'southpoll-membership' ), 'type' => 'select', 'options' => [ '10' => '10', '25' => '25', '50' => '50', '100' => '100' ] ],
				],
			],
			'alerts'      => [
				'title'  => __( 'Email alerts (favorites & saved searches)', 'southpoll-membership' ),
				'fields' => [
					'alerts_per_run'             => [ 'label' => __( 'Queued emails per 5-minute run (alerts, reminders, notices)', 'southpoll-membership' ), 'type' => 'number', 'min' => 5, 'max' => 500, 'help' => __( '50 = up to 600 emails an hour. Keep it under the mail provider’s hourly limit.', 'southpoll-membership' ) ],
					'alert_throttle_minutes'     => [ 'label' => __( 'Minimum minutes between alerts for the same search/farm', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 1440 ],
					'alert_queue_retention_days' => [ 'label' => __( 'Keep sent alert records (days)', 'southpoll-membership' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
				],
			],
			'launch'      => [
				'title'       => __( 'Launch & feedback', 'southpoll-membership' ),
				'description' => __( 'Soft-launch tools: a notice on the login page, a one-time popup on the member dashboard, and the floating "Feedback" button. Switch them off (or leave them on) after launch.', 'southpoll-membership' ),
				'fields'      => [
					'launch_notice_enabled'    => [ 'label' => __( 'Show the release notice on the login page', 'southpoll-membership' ), 'type' => 'toggle' ],
					'launch_notice_title'      => [ 'label' => __( 'Notice heading', 'southpoll-membership' ), 'type' => 'text' ],
					'launch_notice_text'       => [ 'label' => __( 'Notice text (plain text; line breaks are kept)', 'southpoll-membership' ), 'type' => 'textarea' ],
					'launch_notice_link_label' => [ 'label' => __( 'Optional link text (e.g. "What\'s new")', 'southpoll-membership' ), 'type' => 'text' ],
					'launch_notice_link_url'   => [ 'label' => __( 'Optional link URL', 'southpoll-membership' ), 'type' => 'url' ],
					'launch_notice_until'      => [ 'label' => __( 'Hide the notice after (date, YYYY-MM-DD; empty = no end)', 'southpoll-membership' ), 'type' => 'date' ],
					'launch_popup_enabled'     => [ 'label' => __( 'Show the welcome popup on the member dashboard', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'Each account sees it once per version; change the version below to show it again to everyone.', 'southpoll-membership' ) ],
					'launch_popup_title'       => [ 'label' => __( 'Popup heading', 'southpoll-membership' ), 'type' => 'text' ],
					'launch_popup_text'        => [ 'label' => __( 'Popup text (plain text; line breaks are kept)', 'southpoll-membership' ), 'type' => 'textarea' ],
					'launch_popup_version'     => [ 'label' => __( 'Popup version', 'southpoll-membership' ), 'type' => 'text', 'help' => __( 'Any short label, e.g. 2026-11 or v2. Members who dismissed an older version see the popup again.', 'southpoll-membership' ) ],
					'feedback_enabled'         => [ 'label' => __( 'Show the floating "Feedback" button on the login page and the member dashboard', 'southpoll-membership' ), 'type' => 'toggle' ],
					'feedback_emails'          => [ 'label' => __( 'Send feedback to (comma-separated)', 'southpoll-membership' ), 'type' => 'email_list', 'help' => __( 'Every submission is also kept under SouthPoll → Feedback.', 'southpoll-membership' ) ],
					'feedback_label'           => [ 'label' => __( 'Button label', 'southpoll-membership' ), 'type' => 'text' ],
				],
			],
			'advanced'    => [
				'title'  => __( 'Advanced', 'southpoll-membership' ),
				'fields' => [
					'legacy_redirects_enabled' => [ 'label' => __( 'Redirect old Classified Listing URLs (301) to the new pages', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'Turn on at cutover, after the migration has run. Uses the redirect table plus pattern rules for old listing, store, category, location, account and checkout URLs.', 'southpoll-membership' ) ],
					'debug'                    => [ 'label' => __( 'Write debug lines to the plugin log', 'southpoll-membership' ), 'type' => 'toggle' ],
					'delete_data_on_uninstall' => [ 'label' => __( 'Delete all plugin data when the plugin is uninstalled', 'southpoll-membership' ), 'type' => 'toggle', 'help' => __( 'Leave off unless you are retiring the plugin for good.', 'southpoll-membership' ) ],
				],
			],
		];
	}

	/** @return array<string, array<string, mixed>> flat key => field */
	public static function fields(): array {
		$out = [];
		foreach ( self::groups() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				$out[ $key ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Current values for every schema key (dot keys resolved).
	 *
	 * @return array<string, mixed>
	 */
	public static function values( Settings $settings ): array {
		$out = [];
		foreach ( array_keys( self::fields() ) as $key ) {
			$out[ $key ] = self::to_display( $key, $settings->get( $key ) );
		}
		return $out;
	}

	/** Value as the form/REST shows it (lists become strings). */
	public static function to_display( string $key, mixed $value ): mixed {
		$field = self::fields()[ $key ] ?? [ 'type' => 'text' ];
		return match ( $field['type'] ) {
			'int_list'   => implode( ', ', array_map( 'intval', (array) $value ) ),
			'email_list' => implode( ', ', (array) $value ),
			'region_map' => implode( "\n", array_map( static fn( $r, $states ) => $r . ': ' . implode( ', ', (array) $states ), array_keys( (array) $value ), array_values( (array) $value ) ) ),
			'toggle'     => (bool) $value,
			'page'       => (int) $value,
			default      => $value,
		};
	}

	/**
	 * Validate + normalise one submitted value. Returns [value, error|null].
	 *
	 * @return array{0:mixed, 1:?string}
	 */
	public static function sanitize( string $key, mixed $raw ): array {
		$field = self::fields()[ $key ] ?? null;
		if ( null === $field ) {
			return [ null, __( 'Unknown setting.', 'southpoll-membership' ) ];
		}
		switch ( $field['type'] ) {
			case 'number':
				if ( '' === trim( (string) $raw ) || ! is_numeric( $raw ) ) {
					return [ null, __( 'Enter a whole number.', 'southpoll-membership' ) ];
				}
				$n = (int) $raw;
				if ( isset( $field['min'] ) && $n < $field['min'] ) {
					return [ null, sprintf( /* translators: %d: minimum */ __( 'Must be at least %d.', 'southpoll-membership' ), (int) $field['min'] ) ];
				}
				if ( isset( $field['max'] ) && $n > $field['max'] ) {
					return [ null, sprintf( /* translators: %d: maximum */ __( 'Must be at most %d.', 'southpoll-membership' ), (int) $field['max'] ) ];
				}
				return [ $n, null ];
			case 'float':
				if ( ! is_numeric( $raw ) ) {
					return [ null, __( 'Enter a number.', 'southpoll-membership' ) ];
				}
				$f = (float) $raw;
				if ( ( isset( $field['min'] ) && $f < $field['min'] ) || ( isset( $field['max'] ) && $f > $field['max'] ) ) {
					return [ null, __( 'Out of range.', 'southpoll-membership' ) ];
				}
				return [ $f, null ];
			case 'toggle':
				return [ in_array( $raw, [ true, 1, '1', 'true', 'on', 'yes' ], true ), null ];
			case 'select':
				$raw = (string) $raw;
				return isset( $field['options'][ $raw ] ) ? [ is_numeric( $raw ) ? (int) $raw : $raw, null ] : [ null, __( 'Choose one of the options.', 'southpoll-membership' ) ];
			case 'email':
				$raw = sanitize_email( (string) $raw );
				return '' === $raw || is_email( $raw ) ? [ $raw, null ] : [ null, __( 'Enter a valid email address.', 'southpoll-membership' ) ];
			case 'email_list':
				$list = array_values( array_unique( array_filter( array_map( 'sanitize_email', preg_split( '/[,\s;]+/', (string) $raw ) ?: [] ) ) ) );
				foreach ( $list as $e ) {
					if ( ! is_email( $e ) ) {
						return [ null, sprintf( /* translators: %s: address */ __( '"%s" is not a valid email address.', 'southpoll-membership' ), $e ) ];
					}
				}
				return [ $list, null ];
			case 'int_list':
				$list = array_values( array_unique( array_filter( array_map( 'intval', preg_split( '/[,\s;]+/', (string) $raw ) ?: [] ), static fn( $n ) => $n > 0 ) ) );
				rsort( $list );
				return $list ? [ $list, null ] : [ null, __( 'Enter at least one number of days.', 'southpoll-membership' ) ];
			case 'time':
				$raw = trim( (string) $raw );
				if ( '' === $raw ) {
					return [ '', null ];
				}
				return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $m ) ? [ sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] ), null ] : [ null, __( 'Enter a time as HH:MM (24-hour).', 'southpoll-membership' ) ];
			case 'days':
				$valid = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
				$list  = array_values( array_unique( array_filter( array_map( static fn( $d ) => strtolower( substr( trim( $d ), 0, 3 ) ), preg_split( '/[,\s;]+/', (string) $raw ) ?: [] ) ) ) );
				foreach ( $list as $d ) {
					if ( ! in_array( $d, $valid, true ) ) {
						return [ null, sprintf( /* translators: %s: value */ __( '"%s" is not a day (use mon … sun).', 'southpoll-membership' ), $d ) ];
					}
				}
				usort( $list, static fn( $a, $b ) => array_search( $a, $valid, true ) <=> array_search( $b, $valid, true ) );
				return $list ? [ implode( ',', $list ), null ] : [ null, __( 'Enter at least one day.', 'southpoll-membership' ) ];
			case 'textarea':
				return [ sanitize_textarea_field( (string) $raw ), null ];
			case 'url':
				$raw = trim( (string) $raw );
				if ( '' === $raw ) {
					return [ '', null ];
				}
				$url = esc_url_raw( $raw );
				return '' !== $url && wp_http_validate_url( $url ) ? [ $url, null ] : [ null, __( 'Enter a full web address starting with https://.', 'southpoll-membership' ) ];
			case 'date':
				$raw = trim( (string) $raw );
				if ( '' === $raw ) {
					return [ '', null ];
				}
				$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
				return $d && $d->format( 'Y-m-d' ) === $raw ? [ $raw, null ] : [ null, __( 'Enter a date as YYYY-MM-DD.', 'southpoll-membership' ) ];
			case 'slug':
				$slug = sanitize_title( (string) $raw );
				return '' !== $slug ? [ $slug, null ] : [ null, __( 'Enter a URL-safe word (letters, numbers, dashes).', 'southpoll-membership' ) ];
			case 'page':
				$id = (int) $raw;
				return 0 === $id || 'page' === get_post_type( $id ) ? [ $id, null ] : [ null, __( 'Choose a page.', 'southpoll-membership' ) ];
			case 'region_map':
				$map = [];
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) ?: [] as $line ) {
					if ( '' === trim( $line ) ) {
						continue;
					}
					if ( ! str_contains( $line, ':' ) ) {
						return [ null, sprintf( /* translators: %s: line */ __( 'Line "%s" needs the form "Region: ST, ST".', 'southpoll-membership' ), trim( $line ) ) ];
					}
					[ $region, $states ] = explode( ':', $line, 2 );
					$region = sanitize_text_field( trim( $region ) );
					$codes  = array_values( array_unique( array_filter( array_map( static fn( $s ) => strtoupper( trim( $s ) ), explode( ',', $states ) ), static fn( $s ) => preg_match( '/^[A-Z]{2}$/', $s ) ) ) );
					if ( '' === $region || empty( $codes ) ) {
						return [ null, sprintf( /* translators: %s: line */ __( 'Line "%s" has no valid state codes.', 'southpoll-membership' ), trim( $line ) ) ];
					}
					$map[ $region ] = $codes;
				}
				return $map ? [ $map, null ] : [ null, __( 'Enter at least one region.', 'southpoll-membership' ) ];
			default:
				return [ sanitize_text_field( (string) $raw ), null ];
		}
	}
}
