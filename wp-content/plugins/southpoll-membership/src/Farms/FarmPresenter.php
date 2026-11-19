<?php
/**
 * Read-side helpers that turn farm rows into card / pin / page data with URLs, images, lights and badges resolved.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class FarmPresenter {

	public function __construct(
		private Urls $urls,
		private Settings $settings,
		private Offerings $offerings,
		private FavoriteRepository $favorites,
		private ContextProvider $contexts
	) {}

	/**
	 * Card data for one farm (use cards() for lists: it batches the offerings + favorites lookups).
	 *
	 * @return array<string, mixed>
	 */
	public function card( object $farm, ?MemberContext $ctx = null ): array {
		$list = $this->cards( [ $farm ], $ctx );
		return $list[0] ?? [];
	}

	/**
	 * @param object[] $farms
	 * @return array<int, array<string, mixed>>
	 */
	public function cards( array $farms, ?MemberContext $ctx = null ): array {
		$ctx     ??= $this->contexts->current();
		$ids       = array_map( static fn( $f ) => (int) $f->id, $farms );
		$offerings = $this->offerings->for_farms( $ids );
		$favs      = $ctx->is_logged_in() ? $this->favorites->ids_for_user( $ctx->user_id, 'farm' ) : [];
		$exact     = $this->exact_coordinates( $ctx );
		$out       = [];
		foreach ( $farms as $farm ) {
			$id     = (int) $farm->id;
			$coords = $this->coordinates( $farm, $exact );
			$out[]  = [
				'id'              => $id,
				'name'            => (string) $farm->name,
				'slug'            => (string) $farm->slug,
				'url'             => $this->urls->farm( (string) $farm->slug ),
				'slogan'          => (string) $farm->slogan,
				'owner_name'      => (string) $farm->owner_name,
				'city'            => (string) $farm->city,
				'state'           => (string) $farm->state,
				'region'          => (string) $farm->region,
				'location'        => self::location( $farm ),
				'logo'            => MediaUploader::url( $farm->logo_id ? (int) $farm->logo_id : null, 'sp-logo' ),
				'banner'          => MediaUploader::url( $farm->banner_id ? (int) $farm->banner_id : null, 'sp-banner' ),
				'initials'        => self::initials( (string) $farm->name ),
				'active_listings' => (int) $farm->active_listing_count,
				'light'           => (int) $farm->active_listing_count > 0 ? 'on' : 'off',
				'new_listing'     => $this->is_new( $farm ),
				'last_listing_at' => $farm->last_listing_at ? (string) $farm->last_listing_at : null,
				'offerings'       => array_map( static fn( $o ) => [ 'id' => $o->id, 'name' => $o->name, 'slug' => $o->slug ], $offerings[ $id ] ?? [] ),
				'favorited'       => isset( $favs[ $id ] ),
				'favorite_id'     => $favs[ $id ] ?? 0,
				'lat'             => $coords['lat'] ?? null,
				'lng'             => $coords['lng'] ?? null,
			];
		}
		return $out;
	}

	/**
	 * Map pin payload (lighter than a card).
	 *
	 * @return array<string, mixed>|null
	 */
	public function pin( object $farm, bool $exact, array $offerings = [], bool $favorited = false ): ?array {
		$coords = $this->coordinates( $farm, $exact );
		if ( null === $coords ) {
			return null;
		}
		$pin = [
			'id'              => (int) $farm->id,
			'name'            => (string) $farm->name,
			'url'             => $this->urls->farm( (string) $farm->slug ),
			'slogan'          => (string) ( $farm->slogan ?? '' ),
			'location'        => self::location( $farm ),
			'state'           => (string) ( $farm->state ?? '' ),
			'region'          => (string) ( $farm->region ?? '' ),
			'owner_name'      => (string) ( $farm->owner_name ?? '' ),
			'logo'            => MediaUploader::url( $farm->logo_id ? (int) $farm->logo_id : null, 'sp-logo' ),
			'initials'        => self::initials( (string) $farm->name ),
			'active_listings' => (int) $farm->active_listing_count,
			'light'           => (int) $farm->active_listing_count > 0 ? 'on' : 'off',
			'new_listing'     => $this->is_new( $farm ),
			'offerings'       => array_values( array_map( static fn( $o ) => (string) $o->name, $offerings ) ),
			'favorited'       => $favorited,
			'lat'             => $coords['lat'],
			'lng'             => $coords['lng'],
		];
		if ( isset( $farm->listings ) ) {
			$pin['listings'] = (int) $farm->listings; // matching listings (classifieds map)
		}
		return $pin;
	}

	/**
	 * Map pins for many farm rows at once (offerings and favorite state fetched in one query each).
	 *
	 * @param object[] $rows
	 * @return array<int, array<string, mixed>>
	 */
	public function pins( array $rows, bool $exact, ?MemberContext $ctx = null ): array {
		$ctx     ??= $this->contexts->current();
		$ids       = array_map( static fn( $r ) => (int) $r->id, $rows );
		$offerings = $ids ? $this->offerings->for_farms( $ids ) : [];
		$favs      = $ctx->is_logged_in() ? $this->favorites->ids_for_user( $ctx->user_id, 'farm' ) : [];
		$out       = [];
		foreach ( $rows as $row ) {
			$pin = $this->pin( $row, $exact, $offerings[ (int) $row->id ] ?? [], isset( $favs[ (int) $row->id ] ) );
			if ( $pin ) {
				$out[] = $pin;
			}
		}
		return $out;
	}

	/** Logged-in verified accounts (and staff) get exact pins; guests get ~7-mile precision unless settings say exact (D-21). */
	public function exact_coordinates( MemberContext $ctx ): bool {
		return 'exact' === (string) $this->settings->get( 'map_guest_precision', 'city' ) || $ctx->can( 'view_contact_info' );
	}

	/** @return array{lat:float, lng:float}|null */
	public function coordinates( object $farm, bool $exact ): ?array {
		if ( null === $farm->lat || null === $farm->lng || '' === (string) $farm->lat ) {
			return null;
		}
		$lat = (float) $farm->lat;
		$lng = (float) $farm->lng;
		if ( ! $exact ) {
			$lat = round( $lat, 1 );
			$lng = round( $lng, 1 );
		}
		return [ 'lat' => $lat, 'lng' => $lng ];
	}

	public function is_new( object $farm ): bool {
		if ( empty( $farm->last_listing_at ) || (int) $farm->active_listing_count <= 0 ) {
			return false;
		}
		$days = (int) $this->settings->get( 'farm_new_listing_badge_days', 7 );
		return Dates::days_until( (string) $farm->last_listing_at ) >= -$days;
	}

	public function directions_url( object $farm, bool $exact ): string {
		$coords = $this->coordinates( $farm, true );
		if ( $exact && null !== $coords ) {
			return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $coords['lat'] . ',' . $coords['lng'] );
		}
		$address = FarmRepository::address_line( $farm, $exact );
		return '' !== $address ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $address ) : '';
	}

	public static function location( object $farm ): string {
		return trim( (string) $farm->city . ( $farm->city && $farm->state ? ', ' : '' ) . (string) $farm->state );
	}

	public static function initials( string $name ): string {
		$words = preg_split( '/\s+/', trim( $name ) ) ?: [];
		$out   = '';
		foreach ( array_slice( $words, 0, 2 ) as $w ) {
			$out .= mb_strtoupper( mb_substr( $w, 0, 1 ) );
		}
		return $out ?: 'SP';
	}

	/**
	 * Grouped opening hours for display: consecutive days with identical hours share a row.
	 *
	 * @return array<int, array{days:string, hours:string}>
	 */
	public static function hours_rows( object $farm ): array {
		$labels = [ 'mon' => __( 'Mon', 'southpoll-membership' ), 'tue' => __( 'Tue', 'southpoll-membership' ), 'wed' => __( 'Wed', 'southpoll-membership' ), 'thu' => __( 'Thu', 'southpoll-membership' ), 'fri' => __( 'Fri', 'southpoll-membership' ), 'sat' => __( 'Sat', 'southpoll-membership' ), 'sun' => __( 'Sun', 'southpoll-membership' ) ];
		$rows   = [];
		$run    = null;
		foreach ( FarmRepository::hours( $farm ) as $day => $h ) {
			$text = $h['open'] ? self::time( $h['from'] ) . ' – ' . self::time( $h['to'] ) : __( 'Closed', 'southpoll-membership' );
			if ( $run && $run['hours'] === $text ) {
				$run['to'] = $day;
				continue;
			}
			if ( $run ) {
				$rows[] = $run;
			}
			$run = [ 'from' => $day, 'to' => $day, 'hours' => $text ];
		}
		if ( $run ) {
			$rows[] = $run;
		}
		return array_map( static fn( $r ) => [ 'days' => $r['from'] === $r['to'] ? $labels[ $r['from'] ] : $labels[ $r['from'] ] . ' – ' . $labels[ $r['to'] ], 'hours' => $r['hours'] ], $rows );
	}

	public static function time( string $hhmm ): string {
		$ts = strtotime( '1970-01-01 ' . $hhmm . ' UTC' );
		return $ts ? gmdate( 'g:i a', $ts ) : $hhmm;
	}

	/**
	 * @return array<int, array{key:string, label:string, url:string}>
	 */
	public static function social_links( object $farm ): array {
		$labels = [ 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'x' => 'X', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'other' => __( 'Website', 'southpoll-membership' ) ];
		$out    = [];
		foreach ( FarmRepository::social( $farm ) as $key => $url ) {
			$out[] = [ 'key' => $key, 'label' => $labels[ $key ] ?? ucfirst( $key ), 'url' => $url ];
		}
		return $out;
	}

	/** Text used by the share modal (subject/body). */
	public function share( object $farm ): array {
		$location = self::location( $farm );
		return [
			'url'   => $this->urls->farm( (string) $farm->slug ),
			'title' => sprintf( /* translators: %s: farm name */ __( '%s – South Poll Grass Cattle Association member', 'southpoll-membership' ), (string) $farm->name ),
			'text'  => sprintf( /* translators: 1: farm name, 2: location */ __( 'Take a look at %1$s%2$s on SouthPoll.com:', 'southpoll-membership' ), (string) $farm->name, $location ? ' (' . $location . ')' : '' ),
		];
	}
}
