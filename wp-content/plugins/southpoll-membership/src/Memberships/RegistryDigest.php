<?php
/**
 * Registry digest (T6.8): on the configured days/time (default Mon + Thu 08:00 site time) email the office one list of
 * memberships that became active since the previous digest ("add to the cattle registry") and one of memberships that
 * expired or were cancelled ("remove"), plus the renewals that need no action, as a table + CSV attachment.
 *
 * Source of truth: sp_events rows written by MembershipEvents, so Stripe renewals, offline claims and staff actions all count.
 * The window always runs from the previous *successful* send (option sp_registry_digest_last) to now, so a missed cron run
 * catches up instead of skipping changes. Each run (re)schedules the next single `sp_registry_digest` event.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Cron;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Messages\RegistryDigestEmail;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Support\Dates;

final class RegistryDigest implements Service {

	public const OPTION_LAST = 'sp_registry_digest_last';   // UTC MySQL datetime of the last successful send
	public const OPTION_INFO = 'sp_registry_digest_info';   // summary of the last run for the Status page
	private const FIRST_WINDOW_DAYS = 7;                     // first ever run looks back a week

	public function __construct(
		private Settings $settings,
		private MembershipEvents $events,
		private MembershipRepository $memberships,
		private FarmRepository $farms,
		private TierRepository $tiers,
		private Mailer $mailer,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( 'sp/cron/digest', [ $this, 'run' ] );
		add_action( 'sp/cron/schedule', [ $this, 'schedule' ] );
		add_action( 'sp/settings/updated', [ $this, 'reschedule' ] );
	}

	/* ------------------------------------------------------------ schedule */

	/** Make sure the next single event exists (and matches the settings). */
	public function schedule(): void {
		if ( ! (bool) $this->settings->get( 'registry_digest_enabled', true ) ) {
			wp_clear_scheduled_hook( Cron::DIGEST );
			return;
		}
		$next = $this->next_slot();
		$have = wp_next_scheduled( Cron::DIGEST );
		if ( $have && abs( $have - $next->getTimestamp() ) < MINUTE_IN_SECONDS ) {
			return;
		}
		wp_clear_scheduled_hook( Cron::DIGEST );
		wp_schedule_single_event( $next->getTimestamp(), Cron::DIGEST );
	}

	public function reschedule(): void {
		wp_clear_scheduled_hook( Cron::DIGEST );
		$this->schedule();
	}

	/** Next configured day/time strictly after $from (site time). */
	public function next_slot( ?\DateTimeImmutable $from = null ): \DateTimeImmutable {
		$tz   = wp_timezone();
		$from = $from ? $from->setTimezone( $tz ) : new \DateTimeImmutable( 'now', $tz );
		$days = $this->days();
		$time = \SouthPoll\Membership\Email\Notifier::parse_time( (string) $this->settings->get( 'registry_digest_time', '08:00' ) ) ?: [ 8, 0 ];
		for ( $i = 0; $i <= 7; $i++ ) {
			$day = $from->modify( "+$i day" )->setTime( $time[0], $time[1] );
			if ( in_array( strtolower( $day->format( 'D' ) ), $days, true ) && $day > $from ) {
				return $day;
			}
		}
		return $from->modify( '+1 day' )->setTime( $time[0], $time[1] );
	}

	/** @return string[] lower-case 3-letter day names */
	public function days(): array {
		$list = array_values( array_filter( array_map( static fn( $d ) => strtolower( substr( trim( $d ), 0, 3 ) ), explode( ',', (string) $this->settings->get( 'registry_digest_days', 'mon,thu' ) ) ) ) );
		return $list ?: [ 'mon', 'thu' ];
	}

	/* ------------------------------------------------------------ run */

	/**
	 * Build and send the digest for (last send, now]. Returns what happened; always reschedules.
	 *
	 * @param bool $force send even when disabled / nothing to report (Status page "Send now").
	 * @return array{sent:bool, add:int, remove:int, renewed:int, since:string, until:string, reason:string}
	 */
	public function run( bool $force = false ): array {
		$until  = Dates::now();
		$since  = (string) get_option( self::OPTION_LAST, '' ) ?: Dates::add_days( $until, -self::FIRST_WINDOW_DAYS );
		$result = [ 'sent' => false, 'add' => 0, 'remove' => 0, 'renewed' => 0, 'since' => $since, 'until' => $until, 'reason' => '' ];
		try {
			if ( ! $force && ! (bool) $this->settings->get( 'registry_digest_enabled', true ) ) {
				$result['reason'] = 'disabled';
				return $result;
			}
			$to = (array) $this->settings->get( 'registry_digest_emails', [] );
			if ( empty( $to ) ) {
				$to = (array) $this->settings->get( 'admin_notification_emails', [] );
			}
			if ( empty( $to ) ) {
				$result['reason'] = 'no recipients';
				$this->logger->warning( 'Registry digest skipped: no recipient address (Settings → Cattle registry digest).' );
				return $result;
			}
			$lists             = $this->build( $since, $until );
			$result['add']     = count( $lists['add'] );
			$result['remove']  = count( $lists['remove'] );
			$result['renewed'] = count( $lists['renewed'] );
			if ( ! $force && ! $result['add'] && ! $result['remove'] && ! (bool) $this->settings->get( 'registry_digest_send_empty', true ) ) {
				$result['reason'] = 'nothing to report';
				update_option( self::OPTION_LAST, $until, false ); // the window still advances
				return $result;
			}
			$csv = $this->csv( $lists );
			$tz  = wp_timezone();
			$fmt = static fn( string $utc ) => Dates::utc( $utc )->setTimezone( $tz )->format( 'D j M Y, g:i a' );
			$msg = ( new RegistryDigestEmail() )->to( $to )->with( [
				'period_start' => $fmt( $since ),
				'period_end'   => $fmt( $until ),
				'period_label' => Dates::utc( $until )->setTimezone( $tz )->format( 'D j M' ),
				'add'          => $lists['add'],
				'remove'       => $lists['remove'],
				'renewed'      => $lists['renewed'],
				'csv_path'     => $csv,
				'admin_url'    => admin_url( 'admin.php?page=southpoll-memberships' ),
				'settings_url' => admin_url( 'admin.php?page=southpoll-settings' ),
			] );
			$sent = $this->mailer->send( $msg );
			if ( $csv && file_exists( $csv ) ) {
				wp_delete_file( $csv );
			}
			$result['sent'] = $sent;
			if ( $sent ) {
				update_option( self::OPTION_LAST, $until, false );
			} else {
				$result['reason'] = 'send failed';
			}
			$this->logger->info( sprintf( 'Registry digest %s: add %d, remove %d, renewed %d (%s → %s)', $sent ? 'sent' : 'FAILED', $result['add'], $result['remove'], $result['renewed'], $since, $until ) );
		} finally {
			update_option( self::OPTION_INFO, $result + [ 'at' => Dates::now() ], false );
			$this->reschedule();
		}
		return $result;
	}

	/**
	 * @return array{add:array<int, array<string, string>>, remove:array<int, array<string, string>>, renewed:array<int, array<string, string>>}
	 */
	public function build( string $since, string $until ): array {
		$rows  = $this->events->between( $since, $until, [ 'membership.activated', 'membership.expired', 'membership.cancelled' ] );
		$state = []; // membership id => last relevant event in the window (a lapse followed by a renewal nets out to "add")
		foreach ( $rows as $r ) {
			$state[ (int) $r->object_id ] = $r;
		}
		$lists = [ 'add' => [], 'remove' => [], 'renewed' => [] ];
		foreach ( $state as $mid => $ev ) {
			$m = $this->memberships->find( $mid );
			if ( ! $m ) {
				continue;
			}
			$row = $this->row( $m, $ev );
			if ( 'membership.activated' === $ev->event ) {
				$lists[ ! empty( $ev->meta['extend'] ) ? 'renewed' : 'add' ][] = $row;
			} else {
				$row['note'] = 'membership.cancelled' === $ev->event ? __( 'Cancelled', 'southpoll-membership' ) . ( ! empty( $ev->meta['reason'] ) ? ': ' . $ev->meta['reason'] : '' ) : __( 'Expired', 'southpoll-membership' );
				$lists['remove'][] = $row;
			}
		}
		foreach ( $lists as &$l ) {
			usort( $l, static fn( $a, $b ) => strcasecmp( $a['farm'], $b['farm'] ) );
		}
		return $lists;
	}

	/** @return array<string, string> */
	private function row( object $m, object $ev ): array {
		$farm   = $this->farms->find( (int) $m->farm_id );
		$owner  = $farm ? get_userdata( (int) $farm->owner_user_id ) : null;
		$tier   = $m->tier_id ? $this->tiers->find( (int) $m->tier_id ) : null;
		$method = $this->last_method( (int) $m->farm_id );
		return [
			'farm'           => $farm ? (string) $farm->name : sprintf( 'farm #%d', (int) $m->farm_id ),
			'owner'          => $owner ? trim( $owner->first_name . ' ' . $owner->last_name ) ?: $owner->display_name : '',
			'breeder_number' => $farm ? (string) ( $farm->breeder_number ?? '' ) : '',
			'email'          => $owner ? (string) $owner->user_email : '',
			'phone'          => $farm ? (string) ( $farm->phone ?? '' ) : '',
			'state'          => $farm ? (string) ( $farm->state ?? '' ) : '',
			'starts'         => Dates::display( $m->starts_at ?? null ),
			'ends'           => Dates::display( $m->ends_at ?? null ),
			'tier'           => $tier ? (string) $tier->name : '',
			'method'         => $method,
			'event_at'       => Dates::display_datetime( (string) $ev->created_at ),
			'note'           => '',
		];
	}

	private function last_method( int $farm_id ): string {
		global $wpdb;
		$m = (string) $wpdb->get_var( $wpdb->prepare( "SELECT method FROM {$wpdb->prefix}sp_payments WHERE farm_id = %d AND status = 'succeeded' ORDER BY paid_at DESC, id DESC LIMIT 1", $farm_id ) );
		return match ( $m ) {
			'stripe' => __( 'card', 'southpoll-membership' ),
			'check'  => __( 'check', 'southpoll-membership' ),
			'cash'   => __( 'cash', 'southpoll-membership' ),
			'comp'   => __( 'comp', 'southpoll-membership' ),
			''       => '',
			default  => $m,
		};
	}

	/** Write the three lists to a temp CSV; returns the path ('' on failure). */
	private function csv( array $lists ): string {
		$path = wp_tempnam( 'registry-digest.csv' );
		if ( ! $path ) {
			return '';
		}
		$fh = fopen( $path, 'w' );
		if ( ! $fh ) {
			return '';
		}
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, [ 'action', 'farm', 'owner', 'breeder_number', 'email', 'phone', 'state', 'term_starts', 'term_ends', 'plan', 'paid_via', 'changed_at', 'note' ] );
		foreach ( [ 'add' => 'ADD', 'remove' => 'REMOVE', 'renewed' => 'RENEWED' ] as $k => $label ) {
			foreach ( $lists[ $k ] as $r ) {
				fputcsv( $fh, [ $label, $r['farm'], $r['owner'], $r['breeder_number'], $r['email'], $r['phone'], $r['state'], $r['starts'], $r['ends'], $r['tier'], $r['method'], $r['event_at'], $r['note'] ] );
			}
		}
		fclose( $fh );
		$named = dirname( $path ) . '/registry-digest-' . gmdate( 'Ymd-Hi' ) . '.csv';
		return @rename( $path, $named ) ? $named : $path;
	}

	/** @return array<string, mixed> last run summary + next run for the Status page */
	public static function status(): array {
		return [
			'last'      => (string) get_option( self::OPTION_LAST, '' ),
			'last_info' => (array) get_option( self::OPTION_INFO, [] ),
			'next'      => wp_next_scheduled( Cron::DIGEST ),
		];
	}
}
