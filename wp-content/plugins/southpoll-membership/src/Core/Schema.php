<?php
/**
 * Database schema for every plugin table, in dbDelta-compatible SQL.
 *
 * Rules dbDelta cares about: one column per line, two spaces after PRIMARY KEY, KEY/UNIQUE KEY
 * definitions on their own lines, no backticks around column names, no IF NOT EXISTS.
 *
 * Table purposes are documented in temp/02-architecture-roadmap.md §2.2.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Schema {

	/**
	 * Short table names (without prefix) in creation order.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return [
			'sp_members',
			'sp_farms',
			'sp_farm_users',
			'sp_farm_media',
			'sp_farm_meta',
			'sp_meta_field_groups',
			'sp_meta_fields',
			'sp_membership_tiers',
			'sp_memberships',
			'sp_payments',
			'sp_stripe_events',
			'sp_favorites',
			'sp_saved_searches',
			'sp_inquiries',
			'sp_notification_queue',
			'sp_events',
			'sp_stats_daily',
			'sp_redirects',
			'sp_feedback',
		];
	}

	/**
	 * Fully prefixed table name.
	 */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . $short;
	}

	/**
	 * All fully prefixed table names.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array_map( [ self::class, 'table' ], self::names() );
	}

	/**
	 * Table names that do not exist yet.
	 *
	 * @return string[]
	 */
	public static function missing_tables(): array {
		global $wpdb;
		$missing = [];
		foreach ( self::table_names() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * CREATE TABLE statements keyed by short name.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		return [
			'sp_members' => "CREATE TABLE {$p}sp_members (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  address1 varchar(190) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  state varchar(2) NOT NULL DEFAULT '',
  zip varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  email_verified_at datetime NULL,
  verification_token varchar(64) NULL,
  verification_sent_at datetime NULL,
  pending_email varchar(190) NOT NULL DEFAULT '',
  notify_all tinyint(1) NOT NULL DEFAULT 1,
  default_notify_favorites tinyint(1) NOT NULL DEFAULT 0,
  default_notify_searches tinyint(1) NOT NULL DEFAULT 0,
  favorites_share_token varchar(64) NULL,
  last_seen_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY favorites_share_token (favorites_share_token),
  KEY state (state),
  KEY status (status)
) {$collate};",

			'sp_farms' => "CREATE TABLE {$p}sp_farms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_user_id bigint(20) unsigned NOT NULL,
  billing_user_id bigint(20) unsigned NULL,
  name varchar(190) NOT NULL DEFAULT '',
  slug varchar(190) NOT NULL,
  slogan varchar(255) NOT NULL DEFAULT '',
  about longtext NULL,
  logo_id bigint(20) unsigned NULL,
  banner_id bigint(20) unsigned NULL,
  address1 varchar(190) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  state varchar(2) NOT NULL DEFAULT '',
  zip varchar(20) NOT NULL DEFAULT '',
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  geocoded_at datetime NULL,
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  website varchar(255) NOT NULL DEFAULT '',
  owner_name varchar(190) NOT NULL DEFAULT '',
  social longtext NULL,
  hours longtext NULL,
  breeder_number varchar(50) NOT NULL DEFAULT '',
  contact_form_enabled tinyint(1) NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'active',
  region varchar(20) NOT NULL DEFAULT '',
  active_listing_count int(11) unsigned NOT NULL DEFAULT 0,
  last_listing_at datetime NULL,
  legacy_store_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY owner_user_id (owner_user_id),
  KEY state (state),
  KEY region (region),
  KEY status (status),
  KEY last_listing_at (last_listing_at),
  KEY legacy_store_id (legacy_store_id)
) {$collate};",

			'sp_farm_users' => "CREATE TABLE {$p}sp_farm_users (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  farm_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NULL,
  role varchar(20) NOT NULL DEFAULT 'manager',
  invite_email varchar(190) NOT NULL DEFAULT '',
  invite_token varchar(64) NULL,
  invited_at datetime NULL,
  accepted_at datetime NULL,
  notify_listings tinyint(1) NOT NULL DEFAULT 1,
  notify_membership tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY invite_token (invite_token),
  KEY farm_id (farm_id)
) {$collate};",

			'sp_farm_media' => "CREATE TABLE {$p}sp_farm_media (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  farm_id bigint(20) unsigned NOT NULL,
  type varchar(10) NOT NULL DEFAULT 'image',
  attachment_id bigint(20) unsigned NULL,
  video_url varchar(255) NOT NULL DEFAULT '',
  caption varchar(255) NOT NULL DEFAULT '',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY farm_id (farm_id)
) {$collate};",

			'sp_farm_meta' => "CREATE TABLE {$p}sp_farm_meta (
  farm_id bigint(20) unsigned NOT NULL,
  field_key varchar(100) NOT NULL,
  value longtext NULL,
  PRIMARY KEY  (farm_id,field_key)
) {$collate};",

			'sp_meta_field_groups' => "CREATE TABLE {$p}sp_meta_field_groups (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(10) NOT NULL DEFAULT 'listing',
  title varchar(190) NOT NULL DEFAULT '',
  category_term_ids longtext NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY scope (scope)
) {$collate};",

			'sp_meta_fields' => "CREATE TABLE {$p}sp_meta_fields (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(10) NOT NULL DEFAULT 'farm',
  group_id bigint(20) unsigned NULL,
  field_key varchar(100) NOT NULL,
  label varchar(190) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL DEFAULT 'text',
  options longtext NULL,
  default_value text NULL,
  required tinyint(1) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scope_key (scope,field_key),
  KEY group_id (group_id)
) {$collate};",

			'sp_membership_tiers' => "CREATE TABLE {$p}sp_membership_tiers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  slug varchar(190) NOT NULL,
  description text NULL,
  price_cents int(11) unsigned NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'USD',
  duration_days int(11) unsigned NOT NULL DEFAULT 365,
  billing_interval varchar(10) NOT NULL DEFAULT 'year',
  interval_count int(11) unsigned NOT NULL DEFAULT 1,
  listing_limit int(11) unsigned NULL,
  stripe_product_id varchar(64) NOT NULL DEFAULT '',
  stripe_price_id varchar(64) NOT NULL DEFAULT '',
  stripe_recurring_price_id varchar(64) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$collate};",

			'sp_memberships' => "CREATE TABLE {$p}sp_memberships (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  farm_id bigint(20) unsigned NOT NULL,
  tier_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'none',
  starts_at datetime NULL,
  ends_at datetime NULL,
  auto_renew tinyint(1) NOT NULL DEFAULT 0,
  stripe_customer_id varchar(64) NOT NULL DEFAULT '',
  stripe_subscription_id varchar(64) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'stripe',
  price_cents_paid int(11) unsigned NULL,
  reminders_sent longtext NULL,
  activated_at datetime NULL,
  expired_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY farm_id (farm_id),
  KEY status (status),
  KEY ends_at (ends_at),
  KEY stripe_customer_id (stripe_customer_id),
  KEY stripe_subscription_id (stripe_subscription_id)
) {$collate};",

			'sp_payments' => "CREATE TABLE {$p}sp_payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  membership_id bigint(20) unsigned NULL,
  farm_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NULL,
  tier_id bigint(20) unsigned NULL,
  amount_cents int(11) unsigned NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'USD',
  method varchar(20) NOT NULL DEFAULT 'stripe',
  status varchar(20) NOT NULL DEFAULT 'pending',
  stripe_checkout_session_id varchar(100) NOT NULL DEFAULT '',
  stripe_payment_intent_id varchar(64) NOT NULL DEFAULT '',
  stripe_invoice_id varchar(64) NOT NULL DEFAULT '',
  stripe_charge_id varchar(64) NOT NULL DEFAULT '',
  stripe_subscription_id varchar(64) NOT NULL DEFAULT '',
  recurring tinyint(1) NOT NULL DEFAULT 0,
  receipt_url varchar(255) NOT NULL DEFAULT '',
  term_start datetime NULL,
  term_end datetime NULL,
  paid_at datetime NULL,
  verified_by bigint(20) unsigned NULL,
  legacy_payment_id bigint(20) unsigned NULL,
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY farm_id (farm_id),
  KEY membership_id (membership_id),
  KEY status (status),
  KEY stripe_payment_intent_id (stripe_payment_intent_id),
  KEY stripe_checkout_session_id (stripe_checkout_session_id),
  KEY paid_at (paid_at)
) {$collate};",

			'sp_stripe_events' => "CREATE TABLE {$p}sp_stripe_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(64) NOT NULL,
  type varchar(100) NOT NULL DEFAULT '',
  payload_hash varchar(64) NOT NULL DEFAULT '',
  processed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id)
) {$collate};",

			'sp_favorites' => "CREATE TABLE {$p}sp_favorites (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  object_type varchar(10) NOT NULL DEFAULT 'listing',
  object_id bigint(20) unsigned NOT NULL,
  notify tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_object (user_id,object_type,object_id),
  KEY object (object_type,object_id)
) {$collate};",

			'sp_saved_searches' => "CREATE TABLE {$p}sp_saved_searches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  params longtext NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  notify tinyint(1) NOT NULL DEFAULT 0,
  share_token varchar(64) NULL,
  last_viewed_at datetime NULL,
  last_notified_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY share_token (share_token),
  KEY user_id (user_id)
) {$collate};",

			'sp_inquiries' => "CREATE TABLE {$p}sp_inquiries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target_type varchar(10) NOT NULL DEFAULT 'farm',
  farm_id bigint(20) unsigned NULL,
  listing_id bigint(20) unsigned NULL,
  sender_user_id bigint(20) unsigned NULL,
  sender_name varchar(190) NOT NULL DEFAULT '',
  sender_email varchar(190) NOT NULL DEFAULT '',
  sender_phone varchar(40) NOT NULL DEFAULT '',
  message text NULL,
  ip_hash varchar(64) NOT NULL DEFAULT '',
  recaptcha_score decimal(3,2) NULL,
  delivered_to varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY farm_id (farm_id),
  KEY listing_id (listing_id),
  KEY created_at (created_at)
) {$collate};",

			'sp_notification_queue' => "CREATE TABLE {$p}sp_notification_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_key varchar(100) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NULL,
  recipient varchar(190) NOT NULL DEFAULT '',
  payload longtext NULL,
  send_after datetime NOT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  sent_at datetime NULL,
  error text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY pending (sent_at,send_after),
  KEY user_id (user_id)
) {$collate};",

			'sp_events' => "CREATE TABLE {$p}sp_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(50) NOT NULL DEFAULT '',
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NULL,
  visitor_hash varchar(64) NOT NULL DEFAULT '',
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event_created (event,created_at),
  KEY object (object_type,object_id)
) {$collate};",

			'sp_stats_daily' => "CREATE TABLE {$p}sp_stats_daily (
  day date NOT NULL,
  metric varchar(50) NOT NULL DEFAULT '',
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  value bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric,object_type,object_id)
) {$collate};",

			'sp_feedback' => "CREATE TABLE {$p}sp_feedback (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NULL,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  page_url varchar(2000) NOT NULL DEFAULT '',
  user_agent varchar(500) NOT NULL DEFAULT '',
  message text NULL,
  screenshot_id bigint(20) unsigned NULL,
  status varchar(10) NOT NULL DEFAULT 'new',
  ip_hash varchar(64) NOT NULL DEFAULT '',
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {$collate};",

			'sp_redirects' => "CREATE TABLE {$p}sp_redirects (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  from_path varchar(255) NOT NULL,
  to_url varchar(255) NOT NULL DEFAULT '',
  status smallint(3) unsigned NOT NULL DEFAULT 301,
  hits int(11) unsigned NOT NULL DEFAULT 0,
  last_hit_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY from_path (from_path)
) {$collate};",
		];
	}
}
