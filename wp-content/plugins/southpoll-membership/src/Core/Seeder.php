<?php
/**
 * Seeds default data. Every method is idempotent (checks before inserting).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Seeder {

	public function __construct( private Settings $settings, private Integrations $integrations, private Logger $logger ) {}

	/**
	 * Store settings defaults that are not yet present (never overwrites existing values).
	 */
	public function seed_settings(): void {
		$stored = (array) get_option( Settings::OPTION, [] );
		$merged = array_replace( Settings::defaults(), $stored );
		update_option( Settings::OPTION, $merged, true );
		$this->settings->flush();
	}

	/**
	 * The single default tier: Annual Membership, $100, 365 days (per Carson, 2026-10-18).
	 */
	public function seed_default_tier(): void {
		global $wpdb;
		$table = Schema::table( 'sp_membership_tiers' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			[
				'name'             => 'Annual Membership',
				'slug'             => 'annual',
				'description'      => 'Full SPGCA membership for one year: farm page in the member directory, classified listings, and member benefits.',
				'price_cents'      => 10000,
				'currency'         => 'USD',
				'duration_days'    => 365,
				'billing_interval' => 'year',
				'interval_count'   => 1,
				'listing_limit'    => null,
				'is_default'       => 1,
				'active'           => 1,
				'sort_order'       => 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);
		$this->logger->info( 'Seeded default membership tier (Annual Membership, $100 / 365 days).' );
	}

	/**
	 * Default farm meta fields from the spec. Admins can edit/remove them later.
	 */
	/**
	 * Farm page fields (Nathan, 2026-11-18): key, label, type — in display order. Existing installs are brought in line by
	 * `temp/scripts/update-farm-fields.php` (relabels in place, adds hay_type, retires cool_season / warm_seasons).
	 */
	public const DEFAULT_FARM_FIELDS = [
		[ 'forage_type', 'Primary Forage Type', 'text' ],
		[ 'cover_crops', 'Cover Crops', 'text' ],
		[ 'feeding_program', 'Supplemental Feed', 'text' ],
		[ 'hay_type', 'Hay Type', 'text' ],
		[ 'grazing_system', 'Grazing Style', 'text' ],
		[ 'feeding_notes', 'Additional Info', 'textarea' ],
	];

	/** Seeded by older builds; kept (inactive, data preserved) rather than deleted. */
	public const RETIRED_FARM_FIELDS = [ 'cool_season', 'warm_seasons' ];

	public function seed_farm_meta_fields(): void {
		global $wpdb;
		$table = Schema::table( 'sp_meta_fields' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scope = %s", 'farm' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}
		$fields = self::DEFAULT_FARM_FIELDS;
		$now = current_time( 'mysql', true );
		foreach ( $fields as $i => [ $key, $label, $type ] ) {
			$wpdb->insert(
				$table,
				[
					'scope'         => 'farm',
					'group_id'      => null,
					'field_key'     => $key,
					'label'         => $label,
					'type'          => $type,
					'options'       => null,
					'default_value' => null,
					'required'      => 0,
					'sort_order'    => $i,
					'active'        => 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				]
			);
		}
		$this->logger->info( 'Seeded default farm meta fields.' );
	}

	/**
	 * Default offerings taxonomy terms from the spec (Females, Bulls, Finishers, Feeders, Calves, Semen).
	 * Only seeds when the taxonomy has no terms at all, so staff deletions stick.
	 */
	public function seed_offerings(): void {
		\SouthPoll\Membership\Farms\Offerings::register_taxonomy();
		$existing = get_terms( [ 'taxonomy' => \SouthPoll\Membership\Farms\Offerings::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ] );
		if ( is_wp_error( $existing ) || ! empty( $existing ) ) {
			return;
		}
		foreach ( \SouthPoll\Membership\Farms\Offerings::DEFAULTS as $i => $name ) {
			$term = wp_insert_term( $name, \SouthPoll\Membership\Farms\Offerings::TAXONOMY, [ 'slug' => sanitize_title( $name ) ] );
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( (int) $term['term_id'], 'sp_order', $i );
			}
		}
		$this->logger->info( 'Seeded default offerings.' );
	}

	/**
	 * Default listing categories from the spec (females, bulls, finishers, feeders, calves, semen). Only when empty.
	 */
	public function seed_listing_categories(): void {
		\SouthPoll\Membership\Listings\ListingTypes::register_types();
		$existing = get_terms( [ 'taxonomy' => \SouthPoll\Membership\Listings\ListingTypes::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ] );
		if ( is_wp_error( $existing ) || ! empty( $existing ) ) {
			return;
		}
		foreach ( \SouthPoll\Membership\Listings\ListingTypes::DEFAULT_CATEGORIES as $i => $name ) {
			$term = wp_insert_term( $name, \SouthPoll\Membership\Listings\ListingTypes::TAXONOMY, [ 'slug' => sanitize_title( $name ) ] );
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( (int) $term['term_id'], 'sp_order', $i );
				update_term_meta( (int) $term['term_id'], 'sp_seo_title', sprintf( '%s for sale – South Poll cattle classifieds', $name ) );
				update_term_meta( (int) $term['term_id'], 'sp_seo_description', sprintf( 'Browse South Poll %s offered by SPGCA member farms. Contact sellers directly through SouthPoll.com.', strtolower( $name ) ) );
			}
		}
		$this->logger->info( 'Seeded default listing categories.' );
	}

	/**
	 * Fill sp_farms.region from state for rows that predate the column (idempotent).
	 */
	public function backfill_farm_regions(): void {
		global $wpdb;
		$table = Schema::table( 'sp_farms' );
		$rows  = $wpdb->get_results( "SELECT id, state FROM {$table} WHERE region = '' AND state <> ''" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$region = $this->settings->region_for_state( (string) $row->state );
			if ( '' !== $region ) {
				$wpdb->update( $table, [ 'region' => $region ], [ 'id' => (int) $row->id ] );
			}
		}
	}

	/**
	 * Copy reCAPTCHA + Stripe credentials from the Classified Listing settings (same SPGCA accounts)
	 * so the new plugin starts configured. Only runs when sp_integrations is empty; values are never logged.
	 */
	public function import_legacy_integrations(): void {
		if ( ! empty( get_option( Integrations::OPTION, [] ) ) ) {
			return;
		}
		$misc   = (array) get_option( 'rtcl_misc_settings', [] );
		$stripe = (array) get_option( 'rtcl_payment_stripe', [] );
		$email  = (array) get_option( 'rtcl_email_settings', [] );

		$values = [
			'recaptcha' => [
				'site_key'   => (string) ( $misc['recaptcha_site_key'] ?? '' ),
				'secret_key' => (string) ( $misc['recaptcha_secret_key'] ?? '' ),
				'threshold'  => 0.5,
			],
			'stripe'    => [
				'mode'                 => ( ! empty( $stripe['testmode'] ) && 'yes' === $stripe['testmode'] ) ? 'test' : 'live',
				'test_publishable_key' => (string) ( $stripe['stripe_testpublickey'] ?? '' ),
				'test_secret_key'      => (string) ( $stripe['stripe_testsecretkey'] ?? '' ),
				'live_publishable_key' => (string) ( $stripe['stripe_livepublickey'] ?? '' ),
				'live_secret_key'      => (string) ( $stripe['stripe_livesecretkey'] ?? '' ),
				'webhook_secret'       => '',
			],
			'google'    => [
				'maps_browser_key' => '',
				'geocoding_key'    => '',
			],
			'email'     => [
				'from_name'  => (string) ( $email['from_name'] ?? get_bloginfo( 'name' ) ),
				'from_email' => (string) ( $email['from_email'] ?? get_option( 'admin_email' ) ),
			],
		];
		$this->integrations->replace( $values );
		$this->logger->info( 'Imported integration credentials from Classified Listing settings (values not logged).' );
	}
}
