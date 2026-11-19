<?php
/**
 * sp_membership_tiers data access. One tier today; the API is multi-tier from the start.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;

final class TierRepository {

	private function table(): string {
		return Schema::table( 'sp_membership_tiers' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_slug( string $slug ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE slug = %s", $slug ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return object[] */
	public function all( bool $active_only = true ): array {
		global $wpdb;
		$where = $active_only ? 'WHERE active = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$this->table()} {$where} ORDER BY sort_order, id" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function default(): ?object {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$this->table()} WHERE active = 1 ORDER BY is_default DESC, sort_order, id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** Resolve a tier from a request value (id or slug), falling back to the default. */
	public function resolve( mixed $value ): ?object {
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$tier = $this->find( (int) $value );
		} elseif ( is_string( $value ) && '' !== $value ) {
			$tier = $this->find_by_slug( sanitize_title( $value ) );
		} else {
			$tier = null;
		}
		if ( $tier && (int) $tier->active === 1 ) {
			return $tier;
		}
		return $this->default();
	}

	/** True when checkout must show a picker. */
	public function has_choice(): bool {
		return count( $this->all() ) > 1;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'name', 'slug', 'description', 'price_cents', 'currency', 'duration_days', 'billing_interval', 'interval_count', 'listing_limit', 'stripe_product_id', 'stripe_price_id', 'stripe_recurring_price_id', 'is_default', 'active', 'sort_order' ];
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		if ( ! empty( $data['is_default'] ) ) {
			$wpdb->update( $this->table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
		}
		return false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	public function create( array $data ): object {
		global $wpdb;
		$now      = Dates::now();
		$slug     = sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? 'tier' ) );
		$interval = (string) ( $data['billing_interval'] ?? 'year' );
		$wpdb->insert( $this->table(), [
			'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'             => $slug,
			'description'      => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'price_cents'      => (int) ( $data['price_cents'] ?? 0 ),
			'currency'         => strtoupper( substr( (string) ( $data['currency'] ?? 'USD' ), 0, 3 ) ),
			'duration_days'    => max( 1, (int) ( $data['duration_days'] ?? 365 ) ),
			'billing_interval' => in_array( $interval, [ 'year', 'month' ], true ) ? $interval : 'year',
			'interval_count'   => max( 1, (int) ( $data['interval_count'] ?? 1 ) ),
			'listing_limit'    => isset( $data['listing_limit'] ) && '' !== $data['listing_limit'] ? (int) $data['listing_limit'] : null,
			'is_default'       => (int) ! empty( $data['is_default'] ),
			'active'           => (int) ( $data['active'] ?? 1 ),
			'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'       => $now,
			'updated_at'       => $now,
		] );
		$id = (int) $wpdb->insert_id;
		if ( ! empty( $data['is_default'] ) ) {
			$wpdb->update( $this->table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
			$wpdb->update( $this->table(), [ 'is_default' => 1 ], [ 'id' => $id ] );
		}
		return $this->find( $id );
	}
}
