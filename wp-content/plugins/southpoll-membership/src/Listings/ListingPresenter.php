<?php
/**
 * Read-side helpers for listings: card / row / page data with URLs, images, badges and farm context resolved.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class ListingPresenter {

	public function __construct(
		private Urls $urls,
		private Settings $settings,
		private FarmRepository $farms,
		private FavoriteRepository $favorites,
		private ContextProvider $contexts
	) {}

	/** @return array<string, mixed> */
	public function card( Listing $listing, ?MemberContext $ctx = null, ?object $farm = null ): array {
		$list = $this->cards( [ $listing ], $ctx, $farm ? [ $listing->farm_id => $farm ] : [] );
		return $list[0] ?? [];
	}

	/**
	 * @param Listing[] $listings
	 * @param array<int, object> $farms preloaded farms by id (optional)
	 * @return array<int, array<string, mixed>>
	 */
	public function cards( array $listings, ?MemberContext $ctx = null, array $farms = [] ): array {
		$ctx     ??= $this->contexts->current();
		$missing   = array_values( array_diff( array_unique( array_map( static fn( $l ) => $l->farm_id, $listings ) ), array_keys( $farms ) ) );
		$farms    += $this->farms->find_many( $missing );
		$favs      = $ctx->is_logged_in() ? $this->favorites->ids_for_user( $ctx->user_id, 'listing' ) : [];
		$new_days  = (int) $this->settings->get( 'listing_new_badge_days', 7 );
		$sold_days = (int) $this->settings->get( 'listing_sold_visible_days', 14 );
		$words     = (int) $this->settings->get( 'listing_excerpt_words', 30 );
		$out       = [];
		foreach ( $listings as $l ) {
			$farm      = $farms[ $l->farm_id ] ?? null;
			$status    = $l->effective_status();
			$published = $l->published_at ?: ( $l->created_at ?: null );
			$out[]     = [
				'id'             => $l->id,
				'title'          => $l->title,
				'slug'           => $l->slug,
				'url'            => $this->urls->listing( $l->slug ),
				'edit_url'       => $this->urls->edit_listing( $l->id ),
				'summary'        => $l->summary( $words ),
				'price'          => $l->price_display(),
				'price_cents'    => $l->price_cents,
				'price_label'    => $l->price_label,
				'status'         => $status,
				'status_label'   => $l->status_label(),
				'status_class'   => self::status_class( $status ),
				'post_status'    => $l->post_status,
				'is_public'      => $l->is_public( $sold_days ),
				'published_at'   => $published,
				'published_ago'  => $published ? sprintf( /* translators: %s: human time */ __( 'Published %s ago', 'southpoll-membership' ), human_time_diff( Dates::utc( $published )->getTimestamp(), time() ) ) : '',
				'published_date' => $published ? Dates::display( $published ) : '',
				'expires_at'     => $l->expires_at,
				'expires_date'   => $l->expires_at ? Dates::display( $l->expires_at ) : '',
				'days_left'      => $l->days_left(),
				'is_new'         => $published && 'publish' === $l->post_status && Dates::days_until( $published ) >= -$new_days,
				'view_count'     => $l->view_count,
				'renewed_count'  => $l->renewed_count,
				'image'          => $this->image( $l, $farm, 'sp-card' ),
				'image_full'     => $this->image( $l, $farm, 'sp-gallery' ),
				'categories'     => array_map( fn( $c ) => [ 'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'url' => $this->urls->category( $c->slug ) ], $l->categories ),
				'farm'           => $farm ? [
					'id'         => (int) $farm->id,
					'name'       => (string) $farm->name,
					'slug'       => (string) $farm->slug,
					'url'        => $this->urls->farm( (string) $farm->slug ),
					'location'   => FarmPresenter::location( $farm ),
					'state'      => (string) $farm->state,
					'region'     => (string) $farm->region,
					'owner_name' => (string) $farm->owner_name,
					'logo'       => MediaUploader::url( $farm->logo_id ? (int) $farm->logo_id : null, 'sp-thumb' ),
					'initials'   => FarmPresenter::initials( (string) $farm->name ),
				] : null,
				'seller'         => $farm ? (string) $farm->owner_name : '',
				'favorited'      => isset( $favs[ $l->id ] ),
				'favorite_id'    => $favs[ $l->id ] ?? 0,
				'gallery_count'  => count( $l->gallery ) + ( $l->featured_id ? 1 : 0 ),
			];
		}
		return $out;
	}

	/** Card image: featured image, else first gallery image, else the farm logo. */
	public function image( Listing $l, ?object $farm, string $size ): string {
		if ( $l->featured_id ) {
			$url = MediaUploader::url( $l->featured_id, $size );
			if ( '' !== $url ) {
				return $url;
			}
		}
		foreach ( $l->gallery as $g ) {
			if ( 'image' === $g['type'] ) {
				$url = MediaUploader::url( (int) $g['id'], $size );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
		return $farm && $farm->logo_id ? MediaUploader::url( (int) $farm->logo_id, 'sp-logo' ) : '';
	}

	/**
	 * Gallery items for the single page / lightbox: featured image first, then the gallery in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function gallery( Listing $l ): array {
		$items = [];
		$seen  = [];
		if ( $l->featured_id ) {
			$items[]                    = $this->image_item( $l->featured_id, '' );
			$seen[ $l->featured_id ]    = true;
		}
		foreach ( $l->gallery as $g ) {
			if ( 'video' === $g['type'] ) {
				$v       = FarmMediaRepository::parse_video( $g['url'] ) ?? [ 'provider' => '', 'url' => $g['url'], 'embed' => '', 'thumb' => '' ];
				$items[] = [ 'key' => $g['key'], 'type' => 'video', 'provider' => $v['provider'], 'url' => $v['url'], 'embed' => $v['embed'], 'thumb' => $v['thumb'], 'full' => '', 'caption' => $g['caption'] ];
			} elseif ( empty( $seen[ (int) $g['id'] ] ) ) {
				$items[] = $this->image_item( (int) $g['id'], $g['caption'], $g['key'] );
			}
		}
		return array_values( array_filter( $items, static fn( $i ) => 'video' === $i['type'] || '' !== $i['full'] ) );
	}

	/** @return array<string, mixed> */
	private function image_item( int $id, string $caption, string $key = '' ): array {
		return [ 'key' => $key ?: 'featured', 'type' => 'image', 'attachment_id' => $id, 'thumb' => MediaUploader::url( $id, 'sp-thumb' ), 'full' => MediaUploader::url( $id, 'sp-gallery' ), 'caption' => $caption, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ];
	}

	public static function status_class( string $status ): string {
		return match ( $status ) {
			'available'   => 'sp-badge--success',
			'coming_soon' => 'sp-badge--warning',
			'pending'     => 'sp-badge--warning',
			'sold'        => 'sp-badge--danger',
			default       => 'sp-badge--muted',
		};
	}

	/** Share payload for the share modal. */
	public function share( Listing $l, ?object $farm ): array {
		return [
			'url'   => $this->urls->listing( $l->slug ),
			'title' => sprintf( /* translators: %s: listing title */ __( '%s – SouthPoll.com classifieds', 'southpoll-membership' ), $l->title ),
			'text'  => sprintf( /* translators: 1: title, 2: price, 3: farm */ __( '%1$s (%2$s)%3$s on SouthPoll.com:', 'southpoll-membership' ), $l->title, $l->price_display(), $farm ? ' from ' . (string) $farm->name : '' ),
		];
	}
}
