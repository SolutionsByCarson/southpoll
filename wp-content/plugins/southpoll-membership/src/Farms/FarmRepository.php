<?php
/**
 * sp_farms data access.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

final class FarmRepository {

	public const COLUMNS = [ 'owner_user_id', 'billing_user_id', 'name', 'slug', 'slogan', 'about', 'logo_id', 'banner_id', 'address1', 'city', 'state', 'zip', 'lat', 'lng', 'geocoded_at', 'phone', 'email', 'website', 'owner_name', 'social', 'hours', 'breeder_number', 'contact_form_enabled', 'status', 'region', 'active_listing_count', 'last_listing_at', 'legacy_store_id' ];

	/** Columns that are derived or owned by other services and never accepted from form input. */
	private const PROTECTED = [ 'owner_user_id', 'name', 'slug', 'region', 'active_listing_count', 'last_listing_at' ];

	public const ADDRESS_COLUMNS = [ 'address1', 'city', 'state', 'zip' ];

	public const SOCIAL_KEYS = [ 'facebook', 'instagram', 'youtube', 'x', 'tiktok', 'linkedin', 'other' ];

	public const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

	public function __construct( private ContextProvider $contexts, private Settings $settings ) {}

	private function table(): string {
		return Schema::table( 'sp_farms' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_slug( string $slug ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE slug = %s", $slug ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_owner( int $user_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE owner_user_id = %d ORDER BY id LIMIT 1", $user_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param int[] $ids
	 * @return array<int, object> keyed by id, in the order given
	 */
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id IN ({$placeholders})", $ids ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_id        = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}
		$out = [];
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$out[ $id ] = $by_id[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Create a farm with the given owner. The owner's sp_farm_users row is added by FarmService.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( int $owner_user_id, string $name, array $data = [] ): object {
		global $wpdb;
		$now  = Dates::now();
		$user = get_userdata( $owner_user_id );
		$row  = $this->sanitize( array_merge( [
			'email'      => $user ? $user->user_email : '',
			'owner_name' => $user ? trim( $user->first_name . ' ' . $user->last_name ) : '',
		], $data ) );
		$row['owner_user_id'] = $owner_user_id;
		$row['name']          = sanitize_text_field( $name );
		$row['slug']          = $this->unique_slug( $row['name'] );
		$row['status']        = 'active';
		$row['region']        = $this->settings->region_for_state( (string) ( $row['state'] ?? '' ) );
		$row['created_at']    = $now;
		$row['updated_at']    = $now;
		$wpdb->insert( $this->table(), $row );
		$farm = $this->find( (int) $wpdb->insert_id );
		$this->contexts->forget( $owner_user_id );

		/**
		 * @param object $farm
		 */
		do_action( 'sp/farm/created', $farm );
		return $farm;
	}

	/**
	 * Update farm columns from (already validated) input. Name changes regenerate the slug unless one is given;
	 * state changes recompute the region; address changes fire `sp/farm/address_changed` for the geocoder.
	 *
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$before = $this->find( $id );
		if ( ! $before ) {
			return false;
		}
		$row = $this->sanitize( $data );
		if ( isset( $data['name'] ) && $data['name'] !== $before->name ) {
			$row['name'] = sanitize_text_field( (string) $data['name'] );
			if ( empty( $data['slug'] ) ) {
				$row['slug'] = $this->unique_slug( $row['name'], $id );
			}
		}
		if ( isset( $data['slug'] ) ) {
			$row['slug'] = $this->unique_slug( (string) $data['slug'], $id );
		}
		if ( isset( $row['state'] ) ) {
			$row['region'] = $this->settings->region_for_state( (string) $row['state'] );
		}
		if ( empty( $row ) ) {
			return false;
		}
		$row['updated_at'] = Dates::now();
		$ok                = false !== $wpdb->update( $this->table(), $row, [ 'id' => $id ] );
		$this->contexts->forget();
		if ( ! $ok ) {
			return false;
		}
		$after = $this->find( $id );
		if ( isset( $row['slug'] ) && $row['slug'] !== $before->slug ) {
			do_action( 'sp/farm/slug_changed', $before->slug, $row['slug'], $id );
		}
		$address_changed = false;
		foreach ( self::ADDRESS_COLUMNS as $col ) {
			if ( array_key_exists( $col, $row ) && (string) $row[ $col ] !== (string) $before->$col ) {
				$address_changed = true;
				break;
			}
		}
		if ( $address_changed ) {
			/**
			 * @param object $after
			 * @param object $before
			 */
			do_action( 'sp/farm/address_changed', $after, $before );
		}
		do_action( 'sp/farm/updated', $after, $before );
		return true;
	}

	/** Store geocoding results without triggering the update hooks. */
	public function set_geo( int $id, ?float $lat, ?float $lng ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'lat' => $lat, 'lng' => $lng, 'geocoded_at' => Dates::now(), 'updated_at' => Dates::now() ], [ 'id' => $id ] );
	}

	/** Phase 3 keeps the denormalised listing stats current from here. */
	public function set_listing_stats( int $id, int $active_count, ?string $last_listing_at ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'active_listing_count' => max( 0, $active_count ), 'last_listing_at' => $last_listing_at ?: null ], [ 'id' => $id ] );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$status = in_array( $status, [ 'active', 'inactive' ], true ) ? $status : 'active';
		$wpdb->update( $this->table(), [ 'status' => $status, 'updated_at' => Dates::now() ], [ 'id' => $id ] );
		$this->contexts->forget();
	}

	public function set_image( int $id, string $which, ?int $attachment_id ): void {
		global $wpdb;
		$col = 'banner' === $which ? 'banner_id' : 'logo_id';
		$wpdb->update( $this->table(), [ $col => $attachment_id ?: null, 'updated_at' => Dates::now() ], [ 'id' => $id ] );
		$this->contexts->forget();
	}

	public function unique_slug( string $base, int $exclude_id = 0 ): string {
		global $wpdb;
		$slug = sanitize_title( $base ) ?: 'farm';
		$slug = substr( $slug, 0, 180 );
		$try  = $slug;
		$i    = 2;
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s AND id <> %d", $try, $exclude_id ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$try = $slug . '-' . $i++;
		}
		return $try;
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Farms with an address but no coordinates (geocoder backfill).
	 *
	 * @return object[]
	 */
	public function missing_coordinates( int $limit = 25 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE lat IS NULL AND (city <> '' OR zip <> '' OR address1 <> '') AND status = 'active' AND (geocoded_at IS NULL OR geocoded_at < %s) ORDER BY id LIMIT %d", Dates::add_days( Dates::now(), -7 ), $limit ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------- decoding */

	/** @return array<string, string> social network => url */
	public static function social( object $farm ): array {
		$raw = json_decode( (string) ( $farm->social ?? '' ), true );
		$out = [];
		if ( is_array( $raw ) ) {
			foreach ( self::SOCIAL_KEYS as $k ) {
				if ( ! empty( $raw[ $k ] ) ) {
					$out[ $k ] = (string) $raw[ $k ];
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<string, array{open:bool, from:string, to:string}> keyed mon..sun
	 */
	public static function hours( object $farm ): array {
		$raw = json_decode( (string) ( $farm->hours ?? '' ), true );
		$out = [];
		foreach ( self::DAYS as $d ) {
			$day       = is_array( $raw ) && isset( $raw[ $d ] ) && is_array( $raw[ $d ] ) ? $raw[ $d ] : [];
			$out[ $d ] = [ 'open' => ! empty( $day['open'] ), 'from' => (string) ( $day['from'] ?? '' ), 'to' => (string) ( $day['to'] ?? '' ) ];
		}
		return $out;
	}

	public static function has_hours( object $farm ): bool {
		foreach ( self::hours( $farm ) as $day ) {
			if ( $day['open'] ) {
				return true;
			}
		}
		return false;
	}

	public static function address_line( object $farm, bool $with_street = true ): string {
		$parts = [];
		if ( $with_street && ! empty( $farm->address1 ) ) {
			$parts[] = (string) $farm->address1;
		}
		$city_state = trim( (string) $farm->city . ( $farm->city && $farm->state ? ', ' : '' ) . (string) $farm->state . ' ' . (string) $farm->zip );
		if ( '' !== $city_state ) {
			$parts[] = $city_state;
		}
		return implode( ', ', $parts );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function sanitize( array $data ): array {
		$row = [];
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, self::COLUMNS, true ) || in_array( $key, self::PROTECTED, true ) ) {
				continue;
			}
			$row[ $key ] = match ( $key ) {
				'about'                => wp_kses_post( (string) $value ),
				'email'                => sanitize_email( (string) $value ),
				'website'              => esc_url_raw( (string) $value ),
				'phone'                => Str::phone( (string) $value ),
				'state'                => Str::state( (string) $value ),
				'social'               => wp_json_encode( self::clean_social( $value ) ),
				'hours'                => wp_json_encode( self::clean_hours( $value ) ),
				'logo_id', 'banner_id', 'billing_user_id', 'legacy_store_id' => $value ? (int) $value : null,
				'lat', 'lng'           => null === $value || '' === $value ? null : (float) $value,
				'contact_form_enabled' => (int) (bool) $value,
				'status'               => in_array( $value, [ 'active', 'inactive' ], true ) ? $value : 'active',
				'geocoded_at'          => $value ?: null,
				default                => sanitize_text_field( (string) $value ),
			};
		}
		return $row;
	}

	/** @return array<string, string> */
	public static function clean_social( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		$out = [];
		if ( is_array( $value ) ) {
			foreach ( self::SOCIAL_KEYS as $k ) {
				$url = trim( (string) ( $value[ $k ] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					$url = 'https://' . ltrim( $url, '/' );
				}
				$url = esc_url_raw( $url );
				if ( '' !== $url ) {
					$out[ $k ] = $url;
				}
			}
		}
		return $out;
	}

	/** @return array<string, array{open:bool, from:string, to:string}> */
	public static function clean_hours( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		$out = [];
		foreach ( self::DAYS as $d ) {
			$day  = is_array( $value ) && isset( $value[ $d ] ) && is_array( $value[ $d ] ) ? $value[ $d ] : [];
			$from = preg_match( '/^\d{2}:\d{2}$/', (string) ( $day['from'] ?? '' ) ) ? (string) $day['from'] : '';
			$to   = preg_match( '/^\d{2}:\d{2}$/', (string) ( $day['to'] ?? '' ) ) ? (string) $day['to'] : '';
			$open = ! empty( $day['open'] ) && '' !== $from && '' !== $to;
			$out[ $d ] = [ 'open' => $open, 'from' => $open ? $from : '', 'to' => $open ? $to : '' ];
		}
		return $out;
	}
}
