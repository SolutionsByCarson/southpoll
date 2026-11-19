<?php
/**
 * sp_memberships data access (one current row per farm; history lives in sp_payments).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Support\Dates;

final class MembershipRepository {

	public const COLUMNS = [ 'tier_id', 'status', 'starts_at', 'ends_at', 'auto_renew', 'stripe_customer_id', 'stripe_subscription_id', 'source', 'price_cents_paid', 'reminders_sent', 'activated_at', 'expired_at', 'created_by', 'notes' ];

	public function __construct( private ContextProvider $contexts ) {}

	private function table(): string {
		return Schema::table( 'sp_memberships' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function for_farm( int $farm_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d", $farm_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_stripe_customer( string $customer_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_customer_id = %s", $customer_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by_stripe_subscription( string $subscription_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_subscription_id = %s", $subscription_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Get or create the farm's membership row (status "none" when new). */
	public function ensure( int $farm_id ): object {
		$row = $this->for_farm( $farm_id );
		if ( $row ) {
			return $row;
		}
		global $wpdb;
		$now = Dates::now();
		$wpdb->insert( $this->table(), [ 'farm_id' => $farm_id, 'status' => 'none', 'source' => 'stripe', 'created_at' => $now, 'updated_at' => $now ] );
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data = array_intersect_key( $data, array_flip( self::COLUMNS ) );
		if ( isset( $data['reminders_sent'] ) && is_array( $data['reminders_sent'] ) ) {
			$data['reminders_sent'] = wp_json_encode( array_values( $data['reminders_sent'] ) );
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		$ok                 = false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
		$this->contexts->forget();
		return $ok;
	}

	/** @return string[] */
	public function reminders_sent( object $membership ): array {
		$list = json_decode( (string) ( $membership->reminders_sent ?? '' ), true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : [];
	}

	public function mark_reminder( object $membership, string $key ): void {
		$list = $this->reminders_sent( $membership );
		if ( ! in_array( $key, $list, true ) ) {
			$list[] = $key;
			$this->update( (int) $membership->id, [ 'reminders_sent' => $list ] );
		}
	}

	/* ---------------------------------------------------------- queries for cron */

	/** Active rows whose end date has passed. @return object[] */
	public function due_to_expire( string $now ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at <= %s", $now ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Active rows ending within $days days (inclusive). @return object[] */
	public function ending_within( int $days, string $now ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'active' AND ends_at > %s AND ends_at <= %s", $now, Dates::add_days( $now, $days ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Expired rows that expired at least $days days ago but less than $days + 1 … (window handled by caller). @return object[] */
	public function expired_rows(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE status = 'expired'" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string, int> status => count */
	public function counts_by_status(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$this->table()} GROUP BY status" ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (string) $r->status ] = (int) $r->c;
		}
		return $out;
	}
}
