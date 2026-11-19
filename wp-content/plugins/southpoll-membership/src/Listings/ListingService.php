<?php
/**
 * Listing lifecycle: create / save / publish / status changes / duplicate / delete / renew / expire, plus hiding and
 * restoring (with the frozen clock) when a farm's membership lapses. Every transition fires an `sp/listing/*` action
 * and keeps the farm's denormalised listing stats current.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Support\Dates;
use WP_Error;

final class ListingService {

	public function __construct(
		private ListingRepository $listings,
		private ListingLimits $limits,
		private FarmRepository $farms,
		private Settings $settings,
		private Logger $logger
	) {}

	public function duration_days(): int {
		return max( 1, (int) $this->settings->get( 'listing_duration_days', 60 ) );
	}

	public function sold_visible_days(): int {
		return max( 0, (int) $this->settings->get( 'listing_sold_visible_days', 14 ) );
	}

	/** New draft for the context's farm (editor opens on it so uploads have an id to attach to). */
	public function create_draft( MemberContext $ctx, array $data = [] ): Listing|WP_Error {
		if ( ! $ctx->can( 'manage_listings' ) || ! $ctx->has_farm() ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot create listings right now.', 'southpoll-membership' ) );
		}
		$listing = $this->listings->create( array_merge( [ 'title' => '' ], $data, [ 'farm_id' => $ctx->farm_id(), 'author_id' => $ctx->user_id ] ) );
		$this->logger->info( "Listing #{$listing->id} draft created for farm {$ctx->farm_id()} by user {$ctx->user_id}" );
		do_action( 'sp/listing/created', $listing, $ctx );
		return $listing;
	}

	/**
	 * Save validated editor data. $action: save (keep current visibility), publish (go live / update live), draft (unpublish).
	 */
	public function save( MemberContext $ctx, Listing $listing, array $data, string $action = 'save' ): Listing|WP_Error {
		if ( ! $this->owns( $ctx, $listing ) || ! $ctx->can( 'manage_listings' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot edit this listing.', 'southpoll-membership' ) );
		}
		$was_public = $listing->is_public( $this->sold_visible_days() );
		$old_status = $listing->status;
		$new_status = in_array( $data['status'] ?? $listing->status, ListingTypes::STATUSES, true ) ? (string) ( $data['status'] ?? $listing->status ) : $listing->status;
		$update     = $data;
		unset( $update['post_status'] );

		if ( 'sold' === $new_status && 'sold' !== $old_status ) {
			$update['sold_at'] = Dates::now();
		} elseif ( 'sold' !== $new_status ) {
			$update['sold_at'] = null;
		}

		$go_live = 'publish' === $action || ( 'save' === $action && 'publish' === $listing->post_status );
		if ( 'inactive' === $new_status ) {
			$go_live = false;
		}
		if ( 'draft' === $action ) {
			$go_live = false;
		}
		if ( $go_live ) {
			if ( ! $ctx->can( 'publish_listing' ) ) {
				$this->listings->update( $listing->id, $update ); // keep the edits (visibility unchanged) so nothing typed is lost
				return new WP_Error( 'sp_membership_inactive', __( 'This listing was not published: listings can only be published while the farm membership is active. Your changes were kept as a draft, which buyers cannot see. Renew the membership on Manage Membership, then press Publish.', 'southpoll-membership' ) );
			}
			if ( in_array( $new_status, ListingTypes::ACTIVE_STATUSES, true ) && ! ( 'publish' === $listing->post_status && $listing->is_active_status() ) && ! $this->limits->can_publish_another( $listing->farm_id, $listing->id ) ) {
				$this->listings->update( $listing->id, $update );
				return new WP_Error( 'sp_limit', sprintf( /* translators: %d: limit */ __( 'You have reached your limit of %d active listings. Mark one sold or inactive to publish another. Your changes were saved as a draft.', 'southpoll-membership' ), $this->limits->limit_for_farm( $listing->farm_id ) ) );
			}
			$update['post_status']   = 'publish';
			$update['hidden_reason'] = '';
			if ( 'publish' !== $listing->post_status || null === $listing->published_at ) {
				$now                     = Dates::now();
				$update['published_at']  = $now;
				$update['post_date']     = $now;
				$update['expires_at']    = Dates::add_days( $now, $this->duration_days() );
				$update['reminders']     = [];
				$update['frozen_seconds'] = null;
			}
		} else {
			$update['post_status'] = ListingTypes::STATUS_EXPIRED === $listing->post_status && 'save' === $action ? ListingTypes::STATUS_EXPIRED : 'draft';
			if ( 'draft' === $update['post_status'] && 'publish' === $listing->post_status ) {
				$update['hidden_reason'] = 'inactive' === $new_status ? 'inactive' : 'withdrawn';
			}
		}
		$updated = $this->listings->update( $listing->id, $update );
		$this->refresh_farm_stats( $listing->farm_id );
		$now_public = $updated->is_public( $this->sold_visible_days() );
		if ( $now_public && ! $was_public ) {
			$this->logger->info( "Listing #{$updated->id} published (farm {$updated->farm_id})" );
			/**
			 * A listing became publicly visible (first publish, re-publish, or renewal).
			 *
			 * @param Listing       $updated
			 * @param MemberContext $ctx
			 * @param bool          $first  True on the very first publish
			 */
			do_action( 'sp/listing/published', $updated, $ctx, null === $listing->published_at );
		}
		if ( $old_status !== $updated->status ) {
			do_action( 'sp/listing/status_changed', $updated, $old_status, $updated->status, $ctx );
		}
		do_action( 'sp/listing/saved', $updated, $ctx, $action );
		return $updated;
	}

	/** Quick status change from the My Listings table (available/coming_soon/pending/sold/inactive). */
	public function set_status( MemberContext $ctx, Listing $listing, string $status ): Listing|WP_Error {
		if ( ! in_array( $status, ListingTypes::STATUSES, true ) ) {
			return new WP_Error( 'sp_bad_status', __( 'Unknown status.', 'southpoll-membership' ) );
		}
		if ( 'inactive' === $status ) {
			return $this->save( $ctx, $listing, [ 'status' => 'inactive' ], 'draft' );
		}
		$action = 'publish' === $listing->post_status || 'inactive' === $listing->status || ListingTypes::STATUS_EXPIRED === $listing->post_status ? 'publish' : 'save';
		return $this->save( $ctx, $listing, [ 'status' => $status ], $action );
	}

	/** Toggle live/inactive (My Listings switch). */
	public function toggle_active( MemberContext $ctx, Listing $listing ): Listing|WP_Error {
		if ( 'publish' === $listing->post_status ) {
			return $this->save( $ctx, $listing, [ 'status' => 'inactive' ], 'draft' );
		}
		$status = 'inactive' === $listing->status || 'sold' === $listing->status ? 'available' : $listing->status;
		return $this->save( $ctx, $listing, [ 'status' => $status ], 'publish' );
	}

	/** Renew: fresh 60-day clock from now (also re-publishes an expired/withdrawn listing after the member reviews it). */
	public function renew( MemberContext $ctx, Listing $listing, array $data = [] ): Listing|WP_Error {
		if ( ! $this->owns( $ctx, $listing ) || ! $ctx->can( 'publish_listing' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'Listings can only be renewed while the farm membership is active.', 'southpoll-membership' ) );
		}
		$status = in_array( $data['status'] ?? $listing->status, ListingTypes::ACTIVE_STATUSES, true ) ? (string) ( $data['status'] ?? $listing->status ) : 'available';
		if ( ! ( 'publish' === $listing->post_status && $listing->is_active_status() ) && ! $this->limits->can_publish_another( $listing->farm_id, $listing->id ) ) {
			return new WP_Error( 'sp_limit', sprintf( /* translators: %d: limit */ __( 'You have reached your limit of %d active listings. Mark one sold or inactive first.', 'southpoll-membership' ), $this->limits->limit_for_farm( $listing->farm_id ) ) );
		}
		$now        = Dates::now();
		$was_public = $listing->is_public( $this->sold_visible_days() );
		$updated    = $this->listings->update( $listing->id, array_merge( $data, [
			'status'         => $status,
			'post_status'    => 'publish',
			'published_at'   => $now,
			'post_date'      => $now,
			'expires_at'     => Dates::add_days( $now, $this->duration_days() ),
			'renewed_count'  => $listing->renewed_count + 1,
			'reminders'      => [],
			'hidden_reason'  => '',
			'frozen_seconds' => null,
			'sold_at'        => null,
		] ) );
		$this->refresh_farm_stats( $listing->farm_id );
		$this->logger->info( "Listing #{$listing->id} renewed (#{$updated->renewed_count})" );
		do_action( 'sp/listing/renewed', $updated, $ctx );
		if ( ! $was_public ) {
			do_action( 'sp/listing/published', $updated, $ctx, false );
		}
		return $updated;
	}

	public function duplicate( MemberContext $ctx, Listing $listing ): Listing|WP_Error {
		if ( ! $this->owns( $ctx, $listing ) || ! $ctx->can( 'manage_listings' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot copy this listing.', 'southpoll-membership' ) );
		}
		$copy = $this->listings->create( [
			'farm_id'     => $listing->farm_id,
			'author_id'   => $ctx->user_id,
			'title'       => sprintf( /* translators: %s: title */ __( '%s (copy)', 'southpoll-membership' ), $listing->title ),
			'content'     => $listing->content,
			'excerpt'     => $listing->excerpt,
			'status'      => 'sold' === $listing->status || 'inactive' === $listing->status ? 'available' : $listing->status,
			'price_cents' => $listing->price_cents,
			'price_label' => $listing->price_label,
			'categories'  => $listing->category_ids(),
			'meta'        => $listing->meta,
			'gallery'     => $listing->gallery,
			'featured_id' => $listing->featured_id,
		] );
		do_action( 'sp/listing/duplicated', $copy, $listing, $ctx );
		return $copy;
	}

	public function delete( MemberContext $ctx, Listing $listing ): true|WP_Error {
		if ( ! $this->owns( $ctx, $listing ) || ! $ctx->can( 'manage_listings' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot delete this listing.', 'southpoll-membership' ) );
		}
		$attachments = array_filter( array_merge( [ (int) $listing->featured_id ], array_map( static fn( $g ) => (int) $g['id'], $listing->gallery ) ) );
		$this->listings->delete( $listing->id );
		foreach ( array_unique( $attachments ) as $aid ) {
			if ( (int) get_post_meta( $aid, '_sp_listing_id', true ) === $listing->id && ! $this->listings->attachment_in_use( $aid, $listing->id ) ) {
				MediaUploader::delete_if_owned( $aid, $listing->farm_id );
			}
		}
		$this->refresh_farm_stats( $listing->farm_id );
		$this->logger->info( "Listing #{$listing->id} deleted by user {$ctx->user_id}" );
		do_action( 'sp/listing/deleted', $listing, $ctx );
		return true;
	}

	/* ----------------------------------------------------------- system moves */

	/** Cron: the 60-day clock ran out. */
	public function expire( Listing $listing ): Listing {
		$updated = $this->listings->update( $listing->id, [ 'post_status' => ListingTypes::STATUS_EXPIRED, 'hidden_reason' => 'expired' ] );
		$this->refresh_farm_stats( $listing->farm_id );
		$this->logger->info( "Listing #{$listing->id} expired" );
		do_action( 'sp/listing/expired', $updated );
		do_action( 'sp/listing/status_changed', $updated, $listing->status, 'expired', null );
		return $updated;
	}

	/** Cron: a sold listing's public window closed. */
	public function hide_sold( Listing $listing ): Listing {
		$updated = $this->listings->update( $listing->id, [ 'post_status' => 'draft', 'hidden_reason' => 'sold' ] );
		$this->refresh_farm_stats( $listing->farm_id );
		return $updated;
	}

	/** Membership lapsed: unpublish every live listing and freeze its remaining time (roadmap §3.1). */
	public function hide_for_farm( object $farm, string $reason = 'membership' ): int {
		$n   = 0;
		$now = Dates::now();
		foreach ( $this->listings->published_for_farm( (int) $farm->id ) as $l ) {
			$remaining = null !== $l->expires_at ? max( 0, Dates::utc( $l->expires_at )->getTimestamp() - Dates::utc( $now )->getTimestamp() ) : null;
			$this->listings->update( $l->id, [ 'post_status' => 'draft', 'hidden_reason' => 'membership', 'frozen_seconds' => $remaining ] );
			$n++;
		}
		$this->refresh_farm_stats( (int) $farm->id );
		if ( $n ) {
			$this->logger->info( "Farm {$farm->id}: {$n} listing(s) hidden ({$reason})" );
		}
		return $n;
	}

	/** Membership restored: re-publish listings hidden for membership with their frozen time re-applied. */
	public function restore_for_farm( object $farm ): int {
		$n   = 0;
		$now = Dates::now();
		foreach ( $this->listings->hidden_for_farm( (int) $farm->id, 'membership' ) as $l ) {
			$expires = null !== $l->frozen_seconds ? gmdate( Dates::MYSQL, Dates::utc( $now )->getTimestamp() + $l->frozen_seconds ) : $l->expires_at;
			if ( null !== $expires && $expires <= $now ) {
				$this->listings->update( $l->id, [ 'post_status' => ListingTypes::STATUS_EXPIRED, 'hidden_reason' => 'expired', 'frozen_seconds' => null ] );
				continue;
			}
			$this->listings->update( $l->id, [ 'post_status' => 'publish', 'hidden_reason' => '', 'frozen_seconds' => null, 'expires_at' => $expires ] );
			$n++;
		}
		$this->refresh_farm_stats( (int) $farm->id );
		if ( $n ) {
			$this->logger->info( "Farm {$farm->id}: {$n} listing(s) restored" );
		}
		return $n;
	}

	public function refresh_farm_stats( int $farm_id ): void {
		$stats = $this->listings->farm_stats( $farm_id );
		$this->farms->set_listing_stats( $farm_id, $stats['active'], $stats['last_published_at'] );
	}

	public function owns( MemberContext $ctx, Listing $listing ): bool {
		return $ctx->is_staff() || ( $ctx->has_farm() && $ctx->farm_id() === $listing->farm_id );
	}
}
