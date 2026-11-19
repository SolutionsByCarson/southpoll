<?php
/**
 * Hourly membership automation (roadmap §7):
 *   - expire memberships whose end date has passed
 *   - reminders before expiry (non-recurring: each configured threshold; recurring: one notice at the largest threshold)
 *   - prompts after expiry (configured thresholds)
 * Idempotent through sp_memberships.reminders_sent (reset on every activation).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Memberships;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\MembershipExpiredEmail;
use SouthPoll\Membership\Email\Messages\MembershipReminderEmail;
use SouthPoll\Membership\Email\Messages\RecurringNoticeEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class MembershipCron implements Service {

	/** Days an auto-renewing membership may sit past its end date before the cron expires it (Stripe settles the renewal invoice after the period end). */
	public const AUTO_RENEW_GRACE_DAYS = 3;

	public function __construct(
		private MembershipRepository $memberships,
		private StatusEngine $engine,
		private FarmRepository $farms,
		private Notifier $notifier,
		private Settings $settings,
		private Urls $urls,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( 'sp/cron/hourly', [ $this, 'run' ] );
	}

	/**
	 * @return array{expired:int, reminders:int, recurring_notices:int, post_expiry:int}
	 */
	public function run( ?string $now = null ): array {
		$now   = is_string( $now ) && '' !== $now ? $now : Dates::now();
		$stats = [ 'expired' => 0, 'reminders' => 0, 'recurring_notices' => 0, 'post_expiry' => 0 ];

		// 1. Expire.
		foreach ( $this->memberships->due_to_expire( $now ) as $m ) {
			// Auto-renewing memberships: Stripe pays the renewal invoice up to an hour (sometimes a day) after the period end,
			// so give them a short grace before declaring them expired — otherwise every renewal would first send the
			// "expired" e-mails, hide the farm and put a REMOVE in the registry digest, then come straight back as ADD.
			if ( (int) $m->auto_renew && Dates::days_until( (string) $m->ends_at, $now ) > -self::AUTO_RENEW_GRACE_DAYS ) {
				continue;
			}
			$farm = $this->farms->find( (int) $m->farm_id );
			$this->engine->expire( $m, 'term ended' );
			$this->memberships->mark_reminder( $this->memberships->find( (int) $m->id ), 'after_0' );
			if ( $farm ) {
				$this->notifier->farm_membership_contacts( $farm, ( new MembershipExpiredEmail() )->with( [ 'farm_name' => $farm->name, 'ends_at' => $m->ends_at, 'days_after' => 0, 'renew_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
				$owner = get_userdata( (int) $farm->owner_user_id );
				$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
					'type'      => 'expired',
					'farm_name' => $farm->name,
					'summary'   => sprintf( 'The SPGCA membership for %s expired on %s%s.', $farm->name, Dates::display( $m->ends_at ), (int) $m->auto_renew ? ' (auto-renew was on but no payment arrived)' : '' ),
					'rows'      => array_filter( [ 'Farm' => $farm->name, 'Owner' => $owner ? $owner->display_name . ' &lt;' . esc_html( $owner->user_email ) . '&gt;' : '', 'Breeder #' => (string) $farm->breeder_number, 'Ended' => Dates::display( $m->ends_at ) ] ),
					'cta_label' => 'Open memberships',
					'cta_url'   => admin_url( 'admin.php?page=southpoll-memberships' ),
				] ) );
			}
			$stats['expired']++;
		}

		// 2. Before-expiry reminders.
		$thresholds = array_values( array_unique( array_map( 'intval', (array) $this->settings->get( 'membership_reminder_days', [ 30, 7, 1 ] ) ) ) );
		rsort( $thresholds );
		$max = $thresholds[0] ?? 30;
		foreach ( $this->memberships->ending_within( $max, $now ) as $m ) {
			$days = max( 0, Dates::days_until( (string) $m->ends_at, $now ) );
			$sent = $this->memberships->reminders_sent( $m );
			$farm = $this->farms->find( (int) $m->farm_id );
			if ( ! $farm ) {
				continue;
			}
			if ( (int) $m->auto_renew === 1 ) {
				if ( $days <= $max && ! in_array( 'recurring_' . $max, $sent, true ) ) {
					$this->notifier->farm_membership_contacts( $farm, ( new RecurringNoticeEmail() )->with( [ 'farm_name' => $farm->name, 'ends_at' => $m->ends_at, 'days' => $days, 'amount_cents' => (int) ( $this->engine->tier_for( $m )->price_cents ?? 0 ), 'card_label' => '', 'manage_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
					$this->memberships->mark_reminder( $m, 'recurring_' . $max );
					$stats['recurring_notices']++;
				}
				continue;
			}
			$applicable = array_values( array_filter( $thresholds, fn( int $t ) => $days <= $t ) );
			if ( empty( $applicable ) ) {
				continue;
			}
			$unsent = array_values( array_filter( $applicable, fn( int $t ) => ! in_array( 'before_' . $t, $sent, true ) ) );
			if ( empty( $unsent ) ) {
				continue;
			}
			$most_urgent = min( $unsent );
			$this->notifier->farm_membership_contacts( $farm, ( new MembershipReminderEmail() )->with( [ 'farm_name' => $farm->name, 'ends_at' => $m->ends_at, 'days' => $days, 'renew_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
			foreach ( $applicable as $t ) { // mark every applicable threshold so one lapse-day run sends one email, not three
				$this->memberships->mark_reminder( $this->memberships->find( (int) $m->id ), 'before_' . $t );
			}
			$this->logger->debug( "Reminder (before_{$most_urgent}) sent for farm {$farm->id}, {$days} days left" );
			$stats['reminders']++;
		}

		// 3. After-expiry prompts.
		$after = array_values( array_unique( array_map( 'intval', (array) $this->settings->get( 'membership_post_expiry_reminder_days', [ 7, 30 ] ) ) ) );
		sort( $after );
		$max_after = ( $after[ count( $after ) - 1 ] ?? 30 ) + 7;
		foreach ( $this->memberships->expired_rows() as $m ) {
			if ( empty( $m->ends_at ) ) {
				continue;
			}
			$since = -Dates::days_until( (string) $m->ends_at, $now );
			if ( $since < 1 || $since > $max_after ) {
				continue; // too fresh, or old enough that we stop nagging
			}
			$sent = $this->memberships->reminders_sent( $m );
			$farm = $this->farms->find( (int) $m->farm_id );
			if ( ! $farm ) {
				continue;
			}
			foreach ( $after as $t ) {
				if ( $since >= $t && ! in_array( 'after_' . $t, $sent, true ) ) {
					$this->notifier->farm_membership_contacts( $farm, ( new MembershipExpiredEmail() )->with( [ 'farm_name' => $farm->name, 'ends_at' => $m->ends_at, 'days_after' => $t, 'renew_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
					$this->memberships->mark_reminder( $this->memberships->find( (int) $m->id ), 'after_' . $t );
					$sent[] = 'after_' . $t;
					$stats['post_expiry']++;
					break; // one email per run per membership
				}
			}
		}

		if ( array_sum( $stats ) > 0 ) {
			$this->logger->info( 'Membership cron: ' . wp_json_encode( $stats ) );
		}
		return $stats;
	}
}
