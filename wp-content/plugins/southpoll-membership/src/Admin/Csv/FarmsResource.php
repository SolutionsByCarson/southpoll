<?php
/**
 * Farms CSV: export with owner, membership and listing counts; import updates by farm_id (or creates a farm for an
 * owner_email that has no farm yet).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Farms\FarmEditor;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class FarmsResource extends Resource {

	public function __construct( private AdminTables $tables, private FarmRepository $farms, private FarmEditor $editor, private FarmService $service, private FarmUserRepository $farm_users, private Offerings $offerings, private Urls $urls ) {}

	public function name(): string {
		return 'farms';
	}

	public function label(): string {
		return __( 'Farms', 'southpoll-membership' );
	}

	public function columns(): array {
		return [
			'farm_id' => 'farm_id', 'owner_email' => 'owner_email', 'name' => 'name', 'slogan' => 'slogan', 'owner_name' => 'owner_name', 'breeder_number' => 'breeder_number',
			'address1' => 'address1', 'city' => 'city', 'state' => 'state', 'zip' => 'zip', 'phone' => 'phone', 'email' => 'email', 'website' => 'website',
			'offerings' => 'offerings', 'status' => 'status', 'contact_form_enabled' => 'contact_form_enabled', 'about' => 'about', 'lat' => 'lat', 'lng' => 'lng',
			'slug' => 'slug', 'url' => 'url', 'region' => 'region', 'membership' => 'membership', 'membership_ends' => 'membership_ends', 'active_listings' => 'active_listings', 'created' => 'created',
		];
	}

	public function export_only(): array {
		return [ 'slug', 'url', 'region', 'membership', 'membership_ends', 'active_listings', 'created' ];
	}

	public function required(): array {
		return [ 'name' ];
	}

	public function example(): array {
		return [ 'farm_id' => '', 'owner_email' => 'jane@example.com', 'name' => 'Example Ranch', 'slogan' => 'Grass fed since 2010', 'owner_name' => 'Jane Rancher', 'breeder_number' => 'SP-1234', 'address1' => '123 Pasture Ln', 'city' => 'Kearney', 'state' => 'MO', 'zip' => '64060', 'phone' => '816-555-0100', 'email' => 'farm@example.com', 'website' => 'https://example.com', 'offerings' => 'bulls|cows', 'status' => 'active', 'contact_form_enabled' => 'yes', 'about' => '<p>About the farm.</p>', 'lat' => '', 'lng' => '' ];
	}

	public function export( array $args ): iterable {
		$r = $this->tables->farms( $args + [ 'per_page' => 'all' ] );
		foreach ( $r['items'] as $f ) {
			yield [
				'farm_id' => $f->id, 'owner_email' => $f->owner_email, 'name' => $f->name, 'slogan' => $f->slogan, 'owner_name' => $f->owner_name, 'breeder_number' => $f->breeder_number,
				'address1' => $f->address1, 'city' => $f->city, 'state' => $f->state, 'zip' => $f->zip, 'phone' => $f->phone, 'email' => $f->email, 'website' => $f->website,
				'offerings' => implode( '|', array_map( static fn( $o ) => $o->slug, $this->offerings->for_farm( (int) $f->id ) ) ), 'status' => $f->status, 'contact_form_enabled' => self::yn( (int) $f->contact_form_enabled ), 'about' => $f->about, 'lat' => $f->lat, 'lng' => $f->lng,
				'slug' => $f->slug, 'url' => $this->urls->farm( (string) $f->slug ), 'region' => $f->region, 'membership' => $f->membership_status, 'membership_ends' => $f->membership_ends_at, 'active_listings' => $f->active_listing_count, 'created' => $f->created_at,
			];
		}
	}

	public function validate( array $row ): array {
		$errors = [];
		$id     = (int) ( $row['farm_id'] ?? 0 );
		$farm   = $id ? $this->farms->find( $id ) : null;
		if ( $id && ! $farm ) {
			$errors[] = sprintf( /* translators: %d: id */ __( 'farm_id %d does not exist', 'southpoll-membership' ), $id );
		}
		if ( ! $farm ) {
			$email = sanitize_email( (string) ( $row['owner_email'] ?? '' ) );
			$user  = $email ? get_user_by( 'email', $email ) : null;
			if ( ! $user ) {
				$errors[] = __( 'owner_email must match an existing account (import Users first)', 'southpoll-membership' );
			} elseif ( $this->farm_users->for_user( (int) $user->ID ) ) {
				$errors[] = __( 'that owner_email already belongs to a farm; give farm_id to update it', 'southpoll-membership' );
			}
			if ( ! self::has( $row, 'name' ) ) {
				$errors[] = __( 'name is required', 'southpoll-membership' );
			}
		}
		if ( self::has( $row, 'state' ) && ! isset( Str::us_states()[ Str::state( (string) $row['state'] ) ] ) ) {
			$errors[] = __( 'state must be a 2-letter US state code', 'southpoll-membership' );
		}
		if ( self::has( $row, 'status' ) && ! in_array( strtolower( trim( (string) $row['status'] ) ), [ 'active', 'inactive' ], true ) ) {
			$errors[] = __( 'status must be active or inactive', 'southpoll-membership' );
		}
		return [ 'action' => $farm ? 'update' : 'create', 'errors' => $errors, 'key' => $farm ? '#' . $farm->id . ' ' . $farm->name : (string) ( $row['name'] ?? '' ) ];
	}

	public function import( array $row ): array|WP_Error {
		$id   = (int) ( $row['farm_id'] ?? 0 );
		$farm = $id ? $this->farms->find( $id ) : null;
		if ( ! $farm ) {
			$user = get_user_by( 'email', sanitize_email( (string) $row['owner_email'] ) );
			$farm = $this->service->create_for_owner( (int) $user->ID, (string) $row['name'], [ 'state' => (string) ( $row['state'] ?? '' ), 'breeder_number' => (string) ( $row['breeder_number'] ?? '' ) ] );
			if ( is_wp_error( $farm ) ) {
				return $farm;
			}
			$action = 'create';
		} else {
			$action = 'update';
		}
		$in = [];
		foreach ( array_merge( FarmEditor::TEXT_FIELDS, [ 'about' ] ) as $k ) {
			if ( self::has( $row, $k ) ) { // blank cells leave the stored value unchanged
				$in[ $k ] = (string) $row[ $k ];
			}
		}
		if ( array_key_exists( 'contact_form_enabled', $row ) ) {
			$in['_contact_form_submitted'] = 1;
			$in['contact_form_enabled']   = self::yes( $row['contact_form_enabled'] ) ? '1' : '';
		}
		if ( array_key_exists( 'offerings', $row ) ) {
			$in['offerings']            = array_filter( array_map( 'trim', explode( '|', (string) $row['offerings'] ) ) );
			$in['_offerings_submitted'] = 1;
		}
		if ( $in ) {
			$res = $this->editor->save_farm( (int) $farm->id, $in, true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( self::has( $row, 'status' ) ) {
			$this->farms->set_status( (int) $farm->id, strtolower( trim( (string) $row['status'] ) ) );
		}
		if ( self::has( $row, 'lat' ) && self::has( $row, 'lng' ) && is_numeric( $row['lat'] ) && is_numeric( $row['lng'] ) ) {
			$this->farms->set_geo( (int) $farm->id, (float) $row['lat'], (float) $row['lng'] );
		}
		return [ 'action' => $action, 'id' => (int) $farm->id ];
	}
}
