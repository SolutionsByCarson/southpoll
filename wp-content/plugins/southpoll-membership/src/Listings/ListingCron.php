<?php
/**
 * Hourly listing automation (roadmap §7): expire past-due listings, remind N days before expiry (with the renew link),
 * close the public window on sold listings. Idempotent through `_sp_reminders`.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\ListingExpiredEmail;
use SouthPoll\Membership\Email\Messages\ListingExpiringEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class ListingCron implements Service {

	public function __construct(
		private ListingRepository $listings,
		private ListingService $service,
		private FarmRepository $farms,
		private Notifier $notifier,
		private Settings $settings,
		private Urls $urls,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( 'sp/cron/hourly', [ $this, 'run' ] );
	}

	/** @return array{expired:int, reminders:int, sold_hidden:int} */
	public function run( ?string $now = null ): array {
		$now   = is_string( $now ) && '' !== $now ? $now : Dates::now();
		$stats = [ 'expired' => 0, 'reminders' => 0, 'sold_hidden' => 0 ];

		foreach ( $this->listings->due_to_expire( $now ) as $l ) {
			$updated = $this->service->expire( $l );
			$farm    = $this->farms->find( $l->farm_id );
			if ( $farm ) {
				$this->notifier->farm_listing_contacts( $farm, ( new ListingExpiredEmail() )->with( [ 'title' => $l->title, 'farm_name' => $farm->name, 'renew_url' => $this->urls->edit_listing( $l->id, [ 'renew' => 1 ] ), 'listings_url' => $this->urls->dashboard( 'my-listings' ) ] ) );
			}
			$stats['expired']++;
		}

		$days = max( 1, (int) $this->settings->get( 'listing_renewal_reminder_days', 7 ) );
		foreach ( $this->listings->expiring_within( $days, $now ) as $l ) {
			if ( in_array( 'expiring_' . $days, $l->reminders, true ) || null === $l->expires_at ) {
				continue;
			}
			$farm = $this->farms->find( $l->farm_id );
			if ( $farm ) {
				$this->notifier->farm_listing_contacts( $farm, ( new ListingExpiringEmail() )->with( [ 'title' => $l->title, 'farm_name' => $farm->name, 'days' => max( 0, Dates::days_until( $l->expires_at, $now ) ), 'expires_at' => $l->expires_at, 'renew_url' => $this->urls->edit_listing( $l->id, [ 'renew' => 1 ] ), 'listing_url' => $this->urls->listing( $l->slug ) ] ) );
			}
			$this->listings->update( $l->id, [ 'reminders' => array_merge( $l->reminders, [ 'expiring_' . $days ] ) ] );
			$stats['reminders']++;
		}

		$sold_days = $this->service->sold_visible_days();
		foreach ( $this->listings->sold_window_closed( $sold_days, $now ) as $l ) {
			$this->service->hide_sold( $l );
			$stats['sold_hidden']++;
		}

		if ( array_sum( $stats ) > 0 ) {
			$this->logger->info( 'Listing cron: ' . wp_json_encode( $stats ) );
		}
		return $stats;
	}
}
