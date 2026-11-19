<?php
/**
 * Membership state machine (roadmap §3.1, §6). Every transition goes through here so emails,
 * farm/listing visibility, and audit notes stay consistent.
 *
 * Stored statuses: none | pending | active | expired | cancelled. "expiring" is derived.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Support\Dates;

final class StatusEngine {

	public function __construct(
		private MembershipRepository $memberships,
		private TierRepository $tiers,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Status with the "ends_at already passed" check applied (cron may not have run yet).
	 */
	public function effective_status( ?object $membership, ?string $now = null ): string {
		if ( null === $membership ) {
			return 'none';
		}
		$status = (string) $membership->status;
		if ( 'active' === $status && Dates::is_past( $membership->ends_at ?? null, $now ) ) {
			return 'expired';
		}
		return $status;
	}

	public function is_active( ?object $membership, ?string $now = null ): bool {
		return 'active' === $this->effective_status( $membership, $now );
	}

	public function days_remaining( ?object $membership ): ?int {
		if ( null === $membership || empty( $membership->ends_at ) ) {
			return null;
		}
		return max( 0, Dates::days_until( (string) $membership->ends_at ) );
	}

	public function is_expiring( ?object $membership ): bool {
		$days = $this->days_remaining( $membership );
		return $this->is_active( $membership ) && null !== $days && $days <= (int) $this->settings->get( 'membership_expiring_window_days', 30 );
	}

	/**
	 * Activate or extend a membership after a confirmed payment / admin approval.
	 *
	 * Term math (D-27): active → extend from current ends_at; otherwise fresh term from $paid_at.
	 *
	 * @param array{source?:string, auto_renew?:bool|null, stripe_customer_id?:string, stripe_subscription_id?:string, price_cents?:int, actor?:int, note?:string, paid_at?:string, duration_days?:int} $opts
	 * @return object Updated membership row.
	 */
	public function activate( int $farm_id, object $tier, array $opts = [] ): object {
		$m        = $this->memberships->ensure( $farm_id );
		$now      = $opts['paid_at'] ?? Dates::now();
		$days     = (int) ( $opts['duration_days'] ?? $tier->duration_days );
		$was      = $this->effective_status( $m, $now );
		$extend   = 'active' === $was && ! empty( $m->ends_at );
		$start    = $extend ? (string) $m->starts_at : $now;
		$ends     = $extend ? Dates::add_days( (string) $m->ends_at, $days ) : Dates::add_days( $now, $days );
		$data     = [
			'tier_id'          => (int) $tier->id,
			'status'           => 'active',
			'starts_at'        => $start ?: $now,
			'ends_at'          => $ends,
			'source'           => $opts['source'] ?? 'stripe',
			'price_cents_paid' => (int) ( $opts['price_cents'] ?? $tier->price_cents ),
			'reminders_sent'   => [],
			'activated_at'     => $now,
			'expired_at'       => null,
			'created_by'       => (int) ( $opts['actor'] ?? get_current_user_id() ),
		];
		if ( array_key_exists( 'auto_renew', $opts ) && null !== $opts['auto_renew'] ) {
			$data['auto_renew'] = (int) (bool) $opts['auto_renew'];
		}
		foreach ( [ 'stripe_customer_id', 'stripe_subscription_id' ] as $k ) {
			if ( ! empty( $opts[ $k ] ) ) {
				$data[ $k ] = (string) $opts[ $k ];
			}
		}
		if ( ! empty( $opts['note'] ) ) {
			$data['notes'] = $this->append_note( $m, (string) $opts['note'] );
		}
		$this->memberships->update( (int) $m->id, $data );
		$updated = $this->memberships->find( (int) $m->id );
		$this->logger->info( sprintf( 'Membership %s for farm %d: %s → active until %s (%s)', $extend ? 'extended' : 'activated', $farm_id, $was, $ends, $data['source'] ) );

		/**
		 * @param object $updated   Membership row
		 * @param string $was       Previous effective status
		 * @param bool   $extend    True when the term was extended rather than started
		 * @param object $tier
		 */
		do_action( 'sp/membership/activated', $updated, $was, $extend, $tier );
		if ( 'active' !== $was ) {
			do_action( 'sp/membership/restored', $updated, $was );
		}
		return $updated;
	}

	/** Mark pending while an offline claim awaits verification (only when not currently active). */
	public function set_pending( int $farm_id, string $note = '' ): object {
		$m = $this->memberships->ensure( $farm_id );
		if ( $this->is_active( $m ) ) {
			return $m; // renewal claim: stays active until approved
		}
		$this->memberships->update( (int) $m->id, [ 'status' => 'pending', 'notes' => $this->append_note( $m, $note ?: 'Offline payment claim submitted' ) ] );
		$updated = $this->memberships->find( (int) $m->id );
		do_action( 'sp/membership/pending', $updated );
		return $updated;
	}

	/** Pending → none (claim rejected). */
	public function clear_pending( int $farm_id, string $note = '' ): object {
		$m = $this->memberships->ensure( $farm_id );
		if ( 'pending' === $m->status ) {
			$this->memberships->update( (int) $m->id, [ 'status' => 'none', 'notes' => $this->append_note( $m, $note ?: 'Offline payment claim rejected' ) ] );
		}
		return $this->memberships->find( (int) $m->id );
	}

	/** Active → expired (cron, or admin). */
	public function expire( object $membership, string $reason = 'term ended' ): object {
		$this->memberships->update( (int) $membership->id, [ 'status' => 'expired', 'expired_at' => Dates::now(), 'notes' => $this->append_note( $membership, 'Expired: ' . $reason ) ] );
		$updated = $this->memberships->find( (int) $membership->id );
		$this->logger->info( sprintf( 'Membership for farm %d expired (%s)', (int) $membership->farm_id, $reason ) );
		do_action( 'sp/membership/expired', $updated, $reason );
		do_action( 'sp/membership/lapsed', $updated, 'expired' );
		return $updated;
	}

	/** Any → cancelled (staff action). Takes effect immediately. */
	public function cancel( object $membership, string $reason = '', int $actor = 0 ): object {
		$this->memberships->update( (int) $membership->id, [ 'status' => 'cancelled', 'auto_renew' => 0, 'expired_at' => Dates::now(), 'created_by' => $actor ?: get_current_user_id(), 'notes' => $this->append_note( $membership, 'Cancelled: ' . ( $reason ?: 'by staff' ) ) ] );
		$updated = $this->memberships->find( (int) $membership->id );
		do_action( 'sp/membership/cancelled', $updated, $reason );
		do_action( 'sp/membership/lapsed', $updated, 'cancelled' );
		return $updated;
	}

	/** Staff: adjust the end date directly (extend/shorten), keeping status active when the new date is in the future. */
	public function set_end_date( object $membership, string $ends_at, string $note = '', int $actor = 0 ): object {
		$status = Dates::is_past( $ends_at ) ? 'expired' : 'active';
		$data   = [ 'ends_at' => $ends_at, 'status' => $status, 'created_by' => $actor ?: get_current_user_id(), 'notes' => $this->append_note( $membership, $note ?: 'End date set to ' . $ends_at ) ];
		if ( 'active' === $status ) {
			$data['expired_at'] = null;
			if ( empty( $membership->starts_at ) ) {
				$data['starts_at'] = Dates::now();
			}
		}
		$this->memberships->update( (int) $membership->id, $data );
		$updated = $this->memberships->find( (int) $membership->id );
		if ( 'active' === $status && 'active' !== $this->effective_status( $membership ) ) {
			do_action( 'sp/membership/restored', $updated, (string) $membership->status );
		}
		return $updated;
	}

	public function set_auto_renew( object $membership, bool $on, string $subscription_id = '' ): object {
		$data = [ 'auto_renew' => (int) $on ];
		if ( '' !== $subscription_id ) {
			$data['stripe_subscription_id'] = $subscription_id;
		}
		$this->memberships->update( (int) $membership->id, $data );
		$updated = $this->memberships->find( (int) $membership->id );
		do_action( 'sp/membership/auto_renew_changed', $updated, $on );
		return $updated;
	}

	public function tier_for( ?object $membership ): ?object {
		if ( $membership && ! empty( $membership->tier_id ) ) {
			$tier = $this->tiers->find( (int) $membership->tier_id );
			if ( $tier ) {
				return $tier;
			}
		}
		return $this->tiers->default();
	}

	/** Append a dated note to the membership's audit trail without changing anything else. */
	public function add_note( object $membership, string $note ): void {
		$this->memberships->update( (int) $membership->id, [ 'notes' => $this->append_note( $membership, $note ) ] );
	}

	private function append_note( object $membership, string $note ): string {
		$existing = trim( (string) ( $membership->notes ?? '' ) );
		$line     = '[' . Dates::now() . '] ' . sanitize_text_field( $note );
		return '' === $existing ? $line : $existing . "\n" . $line;
	}
}
