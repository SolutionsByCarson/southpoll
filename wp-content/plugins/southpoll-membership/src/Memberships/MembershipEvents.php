<?php
/**
 * Writes membership lifecycle events to sp_events so reports (registry digest, analytics) can ask "what changed between
 * these two moments" without inferring it from the current row.
 *
 * Events (object_type "membership", object_id = membership id, meta.farm_id always set):
 *   membership.activated   meta: was, extend (bool), tier_id, source   — new or reactivated (extend = renewal of an active term)
 *   membership.expired     meta: reason
 *   membership.cancelled   meta: reason
 *   membership.auto_renew  meta: on (bool)
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Support\Dates;

final class MembershipEvents implements Service {

	public function register(): void {
		add_action( 'sp/membership/activated', [ $this, 'on_activated' ], 5, 4 );
		add_action( 'sp/membership/expired', [ $this, 'on_expired' ], 5, 2 );
		add_action( 'sp/membership/cancelled', [ $this, 'on_cancelled' ], 5, 2 );
		add_action( 'sp/membership/auto_renew_changed', [ $this, 'on_auto_renew' ], 5, 2 );
	}

	public function on_activated( object $m, string $was, bool $extend, object $tier ): void {
		$this->record( 'membership.activated', $m, [ 'was' => $was, 'extend' => $extend, 'tier_id' => (int) $tier->id, 'source' => (string) ( $m->source ?? '' ), 'ends_at' => (string) ( $m->ends_at ?? '' ) ] );
	}

	public function on_expired( object $m, string $reason = '' ): void {
		$this->record( 'membership.expired', $m, [ 'reason' => $reason, 'ends_at' => (string) ( $m->ends_at ?? '' ) ] );
	}

	public function on_cancelled( object $m, string $reason = '' ): void {
		$this->record( 'membership.cancelled', $m, [ 'reason' => $reason, 'ends_at' => (string) ( $m->ends_at ?? '' ) ] );
	}

	public function on_auto_renew( object $m, bool $on ): void {
		$this->record( 'membership.auto_renew', $m, [ 'on' => $on ] );
	}

	/** @param array<string, mixed> $meta */
	public function record( string $event, object $m, array $meta = [] ): int {
		global $wpdb;
		$meta['farm_id'] = (int) $m->farm_id;
		$wpdb->insert( Schema::table( 'sp_events' ), [
			'event'        => $event,
			'object_type'  => 'membership',
			'object_id'    => (int) $m->id,
			'user_id'      => get_current_user_id() ?: null,
			'visitor_hash' => '',
			'meta'         => wp_json_encode( $meta ),
			'created_at'   => Dates::now(),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Membership events in a half-open window (since, until], newest last. Meta is decoded.
	 *
	 * @param string[] $events
	 * @return object[]
	 */
	public function between( string $since, string $until, array $events ): array {
		global $wpdb;
		if ( ! $events ) {
			return [];
		}
		$in   = implode( ',', array_fill( 0, count( $events ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'sp_events' ) . " WHERE object_type = 'membership' AND event IN ($in) AND created_at > %s AND created_at <= %s ORDER BY created_at ASC, id ASC", array_merge( $events, [ $since, $until ] ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$m       = json_decode( (string) $r->meta, true );
			$r->meta = is_array( $m ) ? $m : [];
		}
		return $rows;
	}
}
