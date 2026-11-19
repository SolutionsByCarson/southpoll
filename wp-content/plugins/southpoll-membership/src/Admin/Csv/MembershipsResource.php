<?php
/**
 * Memberships CSV: one row per farm; import sets the term (status/dates) through the StatusEngine so emails and
 * visibility behave as if staff had done it on screen.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Support\Dates;
use WP_Error;

final class MembershipsResource extends Resource {

	public function __construct( private AdminTables $tables, private FarmRepository $farms, private MembershipRepository $memberships, private StatusEngine $engine, private TierRepository $tiers ) {}

	public function name(): string {
		return 'memberships';
	}

	public function label(): string {
		return __( 'Memberships', 'southpoll-membership' );
	}

	public function columns(): array {
		return [ 'farm_id' => 'farm_id', 'farm' => 'farm', 'owner_email' => 'owner_email', 'breeder_number' => 'breeder_number', 'status' => 'status', 'starts_at' => 'starts_at', 'ends_at' => 'ends_at', 'auto_renew' => 'auto_renew', 'source' => 'source', 'tier' => 'tier', 'price_paid' => 'price_paid', 'notes' => 'notes', 'stripe_customer_id' => 'stripe_customer_id', 'stripe_subscription_id' => 'stripe_subscription_id' ];
	}

	public function export_only(): array {
		return [ 'farm', 'owner_email', 'breeder_number', 'stripe_customer_id', 'stripe_subscription_id' ];
	}

	public function required(): array {
		return [ 'farm_id' ];
	}

	public function example(): array {
		return [ 'farm_id' => '12', 'status' => 'active', 'starts_at' => '2026-01-15', 'ends_at' => '2027-01-15', 'auto_renew' => 'no', 'source' => 'migrated', 'tier' => 'annual', 'price_paid' => '100.00', 'notes' => 'Imported from the old system' ];
	}

	public function export( array $args ): iterable {
		$r = $this->tables->memberships( $args + [ 'per_page' => 'all' ] );
		foreach ( $r['items'] as $m ) {
			yield [ 'farm_id' => $m->farm_id, 'farm' => $m->farm_name, 'owner_email' => $m->owner_email, 'breeder_number' => $m->breeder_number, 'status' => $m->effective_status, 'starts_at' => $m->starts_at, 'ends_at' => $m->ends_at, 'auto_renew' => self::yn( (int) $m->auto_renew ), 'source' => $m->source, 'tier' => $m->tier_name, 'price_paid' => null !== $m->price_cents_paid ? number_format( (int) $m->price_cents_paid / 100, 2, '.', '' ) : '', 'notes' => $m->notes, 'stripe_customer_id' => $m->stripe_customer_id, 'stripe_subscription_id' => $m->stripe_subscription_id ];
		}
	}

	public function validate( array $row ): array {
		$errors = [];
		$id     = (int) ( $row['farm_id'] ?? 0 );
		$farm   = $id ? $this->farms->find( $id ) : null;
		if ( ! $farm ) {
			$errors[] = __( 'farm_id must match an existing farm', 'southpoll-membership' );
		}
		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
		if ( '' !== $status && ! in_array( $status, [ 'active', 'expired', 'cancelled', 'none', 'pending' ], true ) ) {
			$errors[] = __( 'status must be active, expired, cancelled, pending or none', 'southpoll-membership' );
		}
		if ( 'active' === $status && null === self::date( $row['ends_at'] ?? '' ) ) {
			$errors[] = __( 'ends_at is required for an active membership', 'southpoll-membership' );
		}
		foreach ( [ 'starts_at', 'ends_at' ] as $k ) {
			if ( self::has( $row, $k ) && null === self::date( $row[ $k ] ) ) {
				$errors[] = sprintf( /* translators: %s: column */ __( '%s is not a recognisable date', 'southpoll-membership' ), $k );
			}
		}
		if ( self::has( $row, 'tier' ) && ! $this->tiers->find_by_slug( sanitize_title( (string) $row['tier'] ) ) ) {
			$errors[] = __( 'tier slug does not exist', 'southpoll-membership' );
		}
		return [ 'action' => $farm ? 'update' : 'skip', 'errors' => $errors, 'key' => $farm ? '#' . $farm->id . ' ' . $farm->name : 'farm ' . $id ];
	}

	public function import( array $row ): array|WP_Error {
		$farm_id = (int) $row['farm_id'];
		$m       = $this->memberships->ensure( $farm_id );
		$actor   = get_current_user_id();
		$data    = [];
		if ( self::has( $row, 'tier' ) ) {
			$data['tier_id'] = (int) $this->tiers->find_by_slug( sanitize_title( (string) $row['tier'] ) )->id;
		}
		if ( self::has( $row, 'source' ) ) {
			$data['source'] = sanitize_key( (string) $row['source'] );
		}
		if ( self::has( $row, 'auto_renew' ) ) {
			$data['auto_renew'] = (int) self::yes( $row['auto_renew'] );
		}
		if ( self::has( $row, 'price_paid' ) && is_numeric( str_replace( [ '$', ',' ], '', (string) $row['price_paid'] ) ) ) {
			$data['price_cents_paid'] = (int) round( (float) str_replace( [ '$', ',' ], '', (string) $row['price_paid'] ) * 100 );
		}
		if ( self::has( $row, 'starts_at' ) ) {
			$data['starts_at'] = self::date( $row['starts_at'] );
		}
		if ( self::has( $row, 'notes' ) ) {
			$data['notes'] = trim( (string) $m->notes . "\n[" . Dates::now() . '] import: ' . sanitize_text_field( (string) $row['notes'] ) );
		}
		if ( $data ) {
			$this->memberships->update( (int) $m->id, $data );
			$m = $this->memberships->find( (int) $m->id );
		}
		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
		switch ( $status ) {
			case 'active':
				$this->engine->set_end_date( $m, (string) self::date( $row['ends_at'] ), 'Set by CSV import', $actor );
				break;
			case 'expired':
				if ( self::has( $row, 'ends_at' ) ) {
					$this->memberships->update( (int) $m->id, [ 'ends_at' => self::date( $row['ends_at'] ) ] );
					$m = $this->memberships->find( (int) $m->id );
				}
				if ( 'expired' !== (string) $m->status ) {
					$this->engine->expire( $m, 'CSV import' );
				}
				break;
			case 'cancelled':
				if ( 'cancelled' !== (string) $m->status ) {
					$this->engine->cancel( $m, 'CSV import', $actor );
				}
				break;
			case 'pending':
				$this->engine->set_pending( $farm_id, 'CSV import' );
				break;
			case 'none':
				$this->memberships->update( (int) $m->id, [ 'status' => 'none' ] );
				break;
		}
		return [ 'action' => 'update', 'id' => $farm_id ];
	}
}
