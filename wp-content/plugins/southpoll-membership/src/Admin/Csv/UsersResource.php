<?php
/**
 * Users CSV: export every account with profile, role, farm and membership; import creates buyer accounts (random
 * password, no email sent) or updates profiles by email.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class UsersResource extends Resource {

	public function __construct( private AdminTables $tables, private MemberRepository $members ) {}

	public function name(): string {
		return 'users';
	}

	public function label(): string {
		return __( 'Users', 'southpoll-membership' );
	}

	public function columns(): array {
		return [
			'email'      => 'email',
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'phone'      => 'phone',
			'address1'   => 'address1',
			'city'       => 'city',
			'state'      => 'state',
			'zip'        => 'zip',
			'verified'   => 'verified',
			'suspended'  => 'suspended',
			'notify_all' => 'notify_all',
			'role'       => 'role',
			'farm'       => 'farm',
			'farm_id'    => 'farm_id',
			'membership' => 'membership',
			'membership_ends' => 'membership_ends',
			'created'    => 'created',
			'last_seen'  => 'last_seen',
			'user_id'    => 'user_id',
		];
	}

	public function export_only(): array {
		return [ 'role', 'farm', 'farm_id', 'membership', 'membership_ends', 'created', 'last_seen', 'user_id' ];
	}

	public function required(): array {
		return [ 'email' ];
	}

	public function example(): array {
		return [ 'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Rancher', 'phone' => '816-555-0100', 'address1' => '123 Pasture Ln', 'city' => 'Kearney', 'state' => 'MO', 'zip' => '64060', 'verified' => 'yes', 'suspended' => 'no', 'notify_all' => 'yes' ];
	}

	public function export( array $args ): iterable {
		$r = $this->tables->users( $args + [ 'per_page' => 'all' ] );
		foreach ( $r['items'] as $u ) {
			yield [
				'email'           => $u->email,
				'first_name'      => $u->first_name,
				'last_name'       => $u->last_name,
				'phone'           => $u->phone,
				'address1'        => $u->address1,
				'city'            => $u->city,
				'state'           => $u->state,
				'zip'             => $u->zip,
				'verified'        => self::yn( $u->verified ),
				'suspended'       => self::yn( $u->suspended ),
				'notify_all'      => self::yn( (int) $u->notify_all ),
				'role'            => $u->role,
				'farm'            => $u->farm_name,
				'farm_id'         => $u->farm_id,
				'membership'      => $u->membership_status,
				'membership_ends' => $u->membership_ends_at,
				'created'         => $u->created_at,
				'last_seen'       => $u->last_seen_at,
				'user_id'         => $u->id,
			];
		}
	}

	public function validate( array $row ): array {
		$errors = [];
		$email  = sanitize_email( (string) ( $row['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'email is missing or invalid', 'southpoll-membership' );
		}
		if ( self::has( $row, 'state' ) && ! isset( Str::us_states()[ Str::state( (string) $row['state'] ) ] ) ) {
			$errors[] = __( 'state must be a 2-letter US state code', 'southpoll-membership' );
		}
		$exists = $email && get_user_by( 'email', $email );
		if ( ! $exists && ! self::has( $row, 'first_name' ) ) {
			$errors[] = __( 'first_name is required for a new account', 'southpoll-membership' );
		}
		return [ 'action' => $exists ? 'update' : 'create', 'errors' => $errors, 'key' => $email ];
	}

	public function import( array $row ): array|WP_Error {
		$email = sanitize_email( (string) $row['email'] );
		$user  = get_user_by( 'email', $email );
		$data  = [];
		foreach ( [ 'first_name', 'last_name', 'phone', 'address1', 'city', 'state', 'zip' ] as $k ) {
			if ( array_key_exists( $k, $row ) ) {
				$data[ $k ] = 'phone' === $k ? Str::phone( (string) $row[ $k ] ) : ( 'state' === $k ? Str::state( (string) $row[ $k ] ) : sanitize_text_field( (string) $row[ $k ] ) );
			}
		}
		if ( ! $user ) {
			$login = sanitize_user( strstr( $email, '@', true ) ?: $email, true ) ?: 'member';
			$base  = $login;
			$i     = 2;
			while ( username_exists( $login ) ) {
				$login = $base . $i++;
			}
			$id = wp_insert_user( [ 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'first_name' => $data['first_name'] ?? '', 'last_name' => $data['last_name'] ?? '', 'display_name' => trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) ) ?: $login, 'role' => Capabilities::ROLE_BUYER ] );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$member = $this->members->create_for_user( (int) $id, $data + [ 'verified' => self::has( $row, 'verified' ) ? self::yes( $row['verified'] ) : false ] );
			$action = 'create';
		} else {
			$id     = (int) $user->ID;
			$member = $this->members->find_by_user( $id ) ?? $this->members->create_for_user( $id );
			$action = 'update';
			if ( isset( $data['first_name'] ) || isset( $data['last_name'] ) ) {
				wp_update_user( [ 'ID' => $id, 'first_name' => $data['first_name'] ?? $member->first_name, 'last_name' => $data['last_name'] ?? $member->last_name ] );
			}
		}
		if ( self::has( $row, 'verified' ) ) {
			$data['email_verified_at'] = self::yes( $row['verified'] ) ? ( $member->email_verified_at ?: Dates::now() ) : null;
		}
		if ( self::has( $row, 'suspended' ) ) {
			$data['status'] = self::yes( $row['suspended'] ) ? 'suspended' : 'active';
		}
		if ( self::has( $row, 'notify_all' ) ) {
			$data['notify_all'] = (int) self::yes( $row['notify_all'] );
		}
		if ( $data ) {
			$this->members->update( (int) $member->id, $data );
		}
		return [ 'action' => $action, 'id' => (int) $id ];
	}
}
