<?php
/**
 * A classified listing: WP_Post + its `_sp_*` meta in one read-friendly object.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Post;

final class Listing {

	public int $id = 0;
	public string $title = '';
	public string $slug = '';
	public string $excerpt = '';
	public string $content = '';
	public string $post_status = 'draft';
	public int $author_id = 0;
	public int $farm_id = 0;
	public ?int $price_cents = null;
	public string $price_label = '';
	public string $status = 'available';
	public ?string $published_at = null;
	public ?string $expires_at = null;
	public ?string $sold_at = null;
	public int $renewed_count = 0;
	/** @var array<int, array{key:string, type:string, id:int, url:string, caption:string}> */
	public array $gallery = [];
	public int $view_count = 0;
	public ?int $featured_id = null;
	/** @var array<string, string> field_key => value */
	public array $meta = [];
	/** @var string[] */
	public array $old_slugs = [];
	public string $hidden_reason = '';
	public ?int $frozen_seconds = null;
	/** @var string[] */
	public array $reminders = [];
	public string $created_at = '';
	public string $modified_at = '';
	/** @var array<int, object{id:int, name:string, slug:string}> */
	public array $categories = [];

	public static function from_post( WP_Post $post ): self {
		$l              = new self();
		$l->id          = (int) $post->ID;
		$l->title       = (string) $post->post_title;
		$l->slug        = (string) $post->post_name;
		$l->excerpt     = (string) $post->post_excerpt;
		$l->content     = (string) $post->post_content;
		$l->post_status = (string) $post->post_status;
		$l->author_id   = (int) $post->post_author;
		$l->created_at  = (string) $post->post_date_gmt;
		$l->modified_at = (string) $post->post_modified_gmt;
		$meta           = get_post_meta( $post->ID );
		$get            = static fn( string $k, $default = '' ) => isset( $meta[ $k ][0] ) ? (string) $meta[ $k ][0] : $default;
		$l->farm_id     = (int) $get( '_sp_farm_id', '0' );
		$price          = $get( '_sp_price', '' );
		$l->price_cents = '' === $price ? null : (int) $price;
		$l->price_label = $get( '_sp_price_label' );
		$l->status      = in_array( $get( '_sp_status', 'available' ), ListingTypes::STATUSES, true ) ? $get( '_sp_status', 'available' ) : 'available';
		$l->published_at   = $get( '_sp_published_at', '' ) ?: null;
		$l->expires_at     = $get( '_sp_expires_at', '' ) ?: null;
		$l->sold_at        = $get( '_sp_sold_at', '' ) ?: null;
		$l->renewed_count  = (int) $get( '_sp_renewed_count', '0' );
		$l->view_count     = (int) $get( '_sp_view_count', '0' );
		$l->featured_id    = (int) $get( '_thumbnail_id', '0' ) ?: null;
		$l->hidden_reason  = $get( '_sp_hidden_reason' );
		$l->frozen_seconds = '' !== $get( '_sp_frozen_seconds', '' ) ? (int) $get( '_sp_frozen_seconds' ) : null;
		$gallery           = json_decode( $get( '_sp_gallery', '[]' ), true );
		$l->gallery        = is_array( $gallery ) ? array_values( array_filter( array_map( [ self::class, 'clean_gallery_item' ], $gallery ) ) ) : [];
		$old               = json_decode( $get( '_sp_old_slugs', '[]' ), true );
		$l->old_slugs      = is_array( $old ) ? array_values( array_map( 'strval', $old ) ) : [];
		$rem               = json_decode( $get( '_sp_reminders', '[]' ), true );
		$l->reminders      = is_array( $rem ) ? array_values( array_map( 'strval', $rem ) ) : [];
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( (string) $key, '_sp_meta_' ) ) {
				$l->meta[ substr( (string) $key, 9 ) ] = (string) ( $values[0] ?? '' );
			}
		}
		$terms = get_the_terms( $post, ListingTypes::TAXONOMY );
		if ( is_array( $terms ) ) {
			$l->categories = array_map( static fn( $t ) => (object) [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ], $terms );
		}
		return $l;
	}

	/** @return array{key:string, type:string, id:int, url:string, caption:string}|null */
	public static function clean_gallery_item( mixed $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$type = 'video' === ( $item['type'] ?? '' ) ? 'video' : 'image';
		$id   = (int) ( $item['id'] ?? 0 );
		$url  = esc_url_raw( (string) ( $item['url'] ?? '' ) );
		if ( ( 'image' === $type && $id <= 0 ) || ( 'video' === $type && '' === $url ) ) {
			return null;
		}
		return [ 'key' => sanitize_key( (string) ( $item['key'] ?? '' ) ) ?: substr( md5( $type . $id . $url ), 0, 10 ), 'type' => $type, 'id' => $id, 'url' => $url, 'caption' => sanitize_text_field( (string) ( $item['caption'] ?? '' ) ) ];
	}

	/** @return int[] */
	public function category_ids(): array {
		return array_map( static fn( $c ) => $c->id, $this->categories );
	}

	public function is_active_status(): bool {
		return in_array( $this->status, ListingTypes::ACTIVE_STATUSES, true );
	}

	public function is_expired(): bool {
		return ListingTypes::STATUS_EXPIRED === $this->post_status || ( null !== $this->expires_at && Dates::is_past( $this->expires_at ) );
	}

	/** Shown on public pages? (status/sold window/expiry; the farm + membership rule is applied by callers/queries). */
	public function is_public( int $sold_visible_days = 14, ?string $now = null ): bool {
		$now ??= Dates::now();
		if ( 'publish' !== $this->post_status ) {
			return false;
		}
		if ( null !== $this->expires_at && $this->expires_at <= $now ) {
			return false;
		}
		if ( 'sold' === $this->status ) {
			return null !== $this->sold_at && $this->sold_at >= Dates::add_days( $now, -$sold_visible_days );
		}
		return 'inactive' !== $this->status;
	}

	public function days_left(): ?int {
		return null === $this->expires_at ? null : max( 0, Dates::days_until( $this->expires_at ) );
	}

	public function price_display(): string {
		if ( null !== $this->price_cents && $this->price_cents > 0 ) {
			return Str::money( $this->price_cents ) . ( '' !== $this->price_label ? ' ' . $this->price_label : '' );
		}
		return '' !== $this->price_label ? $this->price_label : __( 'Call for price', 'southpoll-membership' );
	}

	/** Never published: a draft the member is still writing (as opposed to a paused / hidden listing). */
	public function is_unpublished_draft(): bool {
		return 'draft' === $this->post_status && null === $this->published_at;
	}

	/** Effective status for badges: expired beats stored status; a never-published draft is "draft", a paused one "inactive". */
	public function effective_status(): string {
		if ( ListingTypes::STATUS_EXPIRED === $this->post_status ) {
			return 'expired';
		}
		if ( 'draft' === $this->post_status && 'sold' !== $this->status ) {
			return $this->is_unpublished_draft() ? 'draft' : 'inactive';
		}
		return $this->status;
	}

	public function status_label(): string {
		return ListingTypes::status_label( $this->effective_status() );
	}

	public function summary( int $words = 30 ): string {
		$text = '' !== trim( $this->excerpt ) ? $this->excerpt : wp_strip_all_tags( $this->content );
		return wp_trim_words( $text, $words, '…' );
	}
}
