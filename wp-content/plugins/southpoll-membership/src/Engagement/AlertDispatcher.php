<?php
/**
 * Alert pipeline (roadmap §7, D-22): when a listing is published, queue emails for people who favorited the farm
 * (notify on) and for saved searches it matches (notify on); when a favorited listing changes status, queue an email
 * for its watchers. The five-minute queue job sends in batches. Every alert honours the member's master
 * "optional emails" switch, is throttled per user+object, and carries a one-click unsubscribe link.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Message;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Email\Messages\FavoriteFarmAlertEmail;
use SouthPoll\Membership\Email\Messages\FavoriteListingAlertEmail;
use SouthPoll\Membership\Email\Messages\SavedSearchAlertEmail;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\Listing;
use SouthPoll\Membership\Listings\ListingQuery;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class AlertDispatcher implements Service {

	public function __construct(
		private NotificationQueue $queue,
		private FavoriteRepository $favorites,
		private SavedSearchRepository $searches,
		private SavedSearchService $search_service,
		private ListingQuery $query,
		private ListingRepository $listings,
		private FarmRepository $farms,
		private FarmUserRepository $farm_users,
		private MemberRepository $members,
		private Mailer $mailer,
		private Settings $settings,
		private Urls $urls,
		private Logger $logger,
		private Notifier $notifier
	) {}

	public function register(): void {
		add_action( 'sp/listing/published', [ $this, 'on_published' ], 20, 3 );
		add_action( 'sp/listing/status_changed', [ $this, 'on_status_changed' ], 20, 3 );
		add_action( 'sp/cron/queue', [ $this, 'process' ] );
		add_action( 'sp/cron/daily', [ $this, 'purge' ] );
	}

	/** @return array{farm_alerts:int, search_alerts:int} */
	public function on_published( Listing $listing, $ctx = null, bool $first = true ): array {
		$stats    = [ 'farm_alerts' => 0, 'search_alerts' => 0 ];
		$throttle = max( 1, (int) $this->settings->get( 'alert_throttle_minutes', 60 ) );
		$insiders = $this->farm_user_ids( $listing->farm_id );

		foreach ( $this->favorites->users_watching( 'farm', $listing->farm_id ) as $uid ) {
			if ( in_array( $uid, $insiders, true ) || ! $this->wants_optional_email( $uid ) ) {
				continue;
			}
			$key = 'sp_alert_farm_' . $uid . '_' . $listing->farm_id;
			if ( get_transient( $key ) || $this->queue->has_pending( 'alert.favorite_farm', $uid, '"listing_id":' . $listing->id . ',' ) ) {
				continue;
			}
			$email = $this->email_for( $uid );
			if ( '' === $email ) {
				continue;
			}
			$this->queue->enqueue( 'alert.favorite_farm', $uid, $email, [ 'listing_id' => $listing->id, 'farm_id' => $listing->farm_id ] );
			set_transient( $key, 1, $throttle * MINUTE_IN_SECONDS );
			$stats['farm_alerts']++;
		}

		$cutoff = Dates::add_days( Dates::now(), 0 );
		foreach ( $this->searches->notify_enabled() as $search ) {
			$uid = (int) $search->user_id;
			if ( in_array( $uid, $insiders, true ) || ! $this->wants_optional_email( $uid ) ) {
				continue;
			}
			if ( ! empty( $search->last_notified_at ) && Dates::utc( (string) $search->last_notified_at )->getTimestamp() > time() - $throttle * MINUTE_IN_SECONDS ) {
				continue; // one alert per search per hour (D-22)
			}
			if ( ! $this->query->matches( $listing->id, $search->params ) ) {
				continue;
			}
			$email = $this->email_for( $uid );
			if ( '' === $email ) {
				continue;
			}
			$this->queue->enqueue( 'alert.saved_search', $uid, $email, [ 'listing_id' => $listing->id, 'search_id' => (int) $search->id ] );
			$this->searches->mark_notified( (int) $search->id );
			$stats['search_alerts']++;
		}
		unset( $cutoff );
		if ( array_sum( $stats ) ) {
			$this->logger->info( "Alerts queued for listing #{$listing->id}: " . wp_json_encode( $stats ) );
		}
		return $stats;
	}

	public function on_status_changed( Listing $listing, string $old, string $new ): int {
		if ( $old === $new ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->favorites->users_watching( 'listing', $listing->id ) as $uid ) {
			if ( ! $this->wants_optional_email( $uid ) ) {
				continue;
			}
			$email = $this->email_for( $uid );
			if ( '' === $email || $this->queue->has_pending( 'alert.favorite_listing', $uid, '"listing_id":' . $listing->id . ',' ) ) {
				continue;
			}
			$this->queue->enqueue( 'alert.favorite_listing', $uid, $email, [ 'listing_id' => $listing->id, 'old' => $old, 'new' => $new ] );
			$n++;
		}
		return $n;
	}

	/** Queue worker. @return array{sent:int, failed:int, skipped:int} */
	public function process(): array {
		$stats = [ 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];
		foreach ( $this->queue->pending( max( 1, (int) $this->settings->get( 'alerts_per_run', 50 ) ) ) as $row ) {
			$message = $this->build( $row );
			if ( null === $message ) {
				$this->queue->mark_sent( (int) $row->id ); // nothing to send any more (listing gone, alerts turned off)
				$stats['skipped']++;
				continue;
			}
			if ( $this->mailer->send( $message ) ) {
				$this->queue->mark_sent( (int) $row->id );
				$stats['sent']++;
			} else {
				$this->queue->mark_failed( (int) $row->id, 'mailer returned false' );
				$stats['failed']++;
			}
		}
		if ( array_sum( $stats ) ) {
			$this->logger->info( 'Alert queue: ' . wp_json_encode( $stats ) );
		}
		return $stats;
	}

	public function purge(): void {
		$this->queue->purge( (int) $this->settings->get( 'alert_queue_retention_days', 30 ) );
	}

	/* ---------------------------------------------------------------- builders */

	public function build( object $row ): ?Message {
		if ( Notifier::QUEUE_KEY === (string) $row->email_key ) {
			return $this->notifier->rebuild( $row ); // a deferred notice (reminder, expiry …) stored with its recipients + data
		}
		$uid     = (int) $row->user_id;
		$p       = (array) $row->payload;
		$listing = $this->listings->find( (int) ( $p['listing_id'] ?? 0 ) );
		if ( ! $listing || ! $this->wants_optional_email( $uid ) ) {
			return null;
		}
		$farm    = $this->farms->find( $listing->farm_id );
		$user    = get_userdata( $uid );
		$summary = [
			'title'    => $listing->title,
			'price'    => $listing->price_display(),
			'farm'     => $farm ? (string) $farm->name : '',
			'location' => $farm ? \SouthPoll\Membership\Farms\FarmPresenter::location( $farm ) : '',
			'url'      => $this->urls->listing( $listing->slug ),
			'summary'  => $listing->summary( 30 ),
		];
		$name    = $user ? trim( $user->first_name ) ?: $user->display_name : '';
		switch ( (string) $row->email_key ) {
			case 'alert.favorite_farm':
				if ( ! $this->favorites->find_for( $uid, 'farm', $listing->farm_id ) || ! $farm ) {
					return null;
				}
				$fav = $this->favorites->find_for( $uid, 'farm', $listing->farm_id );
				if ( ! (int) $fav->notify ) {
					return null;
				}
				return ( new FavoriteFarmAlertEmail() )->to( (string) $row->recipient )->with( $summary + [ 'recipient_name' => $name, 'farm_name' => (string) $farm->name, 'farm_url' => $this->urls->farm( (string) $farm->slug ), 'unsubscribe_url' => $this->urls->unsubscribe( $uid, 'farm', $listing->farm_id ), 'favorites_url' => $this->urls->my_favorites() ] );
			case 'alert.saved_search':
				$search = $this->searches->find_for_user( $uid, (int) ( $p['search_id'] ?? 0 ) );
				if ( ! $search || ! (int) $search->notify ) {
					return null;
				}
				return ( new SavedSearchAlertEmail() )->to( (string) $row->recipient )->with( $summary + [ 'recipient_name' => $name, 'search_name' => (string) $search->name, 'search_summary' => $this->search_service->describe( $search->params ), 'search_url' => $this->search_service->url( $search->params, [ 'saved' => (int) $search->id ] ), 'unsubscribe_url' => $this->urls->unsubscribe( $uid, 'search', (int) $search->id ), 'searches_url' => $this->urls->dashboard( 'saved-searches' ) ] );
			case 'alert.favorite_listing':
				$fav = $this->favorites->find_for( $uid, 'listing', $listing->id );
				if ( ! $fav || ! (int) $fav->notify ) {
					return null;
				}
				return ( new FavoriteListingAlertEmail() )->to( (string) $row->recipient )->with( $summary + [ 'recipient_name' => $name, 'old_status' => ListingTypes::status_label( (string) ( $p['old'] ?? '' ) ), 'new_status' => ListingTypes::status_label( (string) ( $p['new'] ?? '' ) ), 'is_public' => $listing->is_public( (int) $this->settings->get( 'listing_sold_visible_days', 14 ) ), 'unsubscribe_url' => $this->urls->unsubscribe( $uid, 'listing', $listing->id ), 'favorites_url' => $this->urls->my_favorites() ] );
		}
		return null;
	}

	/* ---------------------------------------------------------------- helpers */

	/** Owner + accepted managers of a farm never get alerts about their own listings. @return int[] */
	private function farm_user_ids( int $farm_id ): array {
		$ids = [];
		foreach ( $this->farm_users->for_farm( $farm_id ) as $row ) {
			if ( ! empty( $row->user_id ) && ! $row->is_pending ) {
				$ids[] = (int) $row->user_id;
			}
		}
		return $ids;
	}

	private function wants_optional_email( int $user_id ): bool {
		$member = $this->members->find_by_user( $user_id );
		return $member && (int) $member->notify_all === 1 && 'suspended' !== (string) $member->status;
	}

	private function email_for( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user && is_email( $user->user_email ) ? $user->user_email : '';
	}
}
