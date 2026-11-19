<?php
/**
 * sp_listing data access (WP posts + `_sp_*` meta). Business transitions live in ListingService.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;
use WP_Post;

final class ListingRepository {

	public const META_PREFIX = '_sp_meta_';

	public const ALL_STATUSES = [ 'publish', 'draft', ListingTypes::STATUS_EXPIRED ];

	public function find( int $id ): ?Listing {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || ListingTypes::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		return Listing::from_post( $post );
	}

	public function find_by_slug( string $slug ): ?Listing {
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status IN ('publish','draft','sp_expired') ORDER BY FIELD(post_status,'publish','sp_expired','draft') LIMIT 1", ListingTypes::POST_TYPE, $slug ) );
		return $id ? $this->find( $id ) : null;
	}

	/** Listing that used to live at this slug (for 301s after a rename). */
	public function find_by_old_slug( string $slug ): ?Listing {
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sp_old_slugs' AND pm.meta_value LIKE %s AND p.post_type = %s AND p.post_status <> 'trash' ORDER BY p.ID DESC LIMIT 1", '%' . $wpdb->esc_like( wp_json_encode( $slug ) ) . '%', ListingTypes::POST_TYPE ) );
		return $id ? $this->find( $id ) : null;
	}

	/**
	 * @param int[] $ids
	 * @return Listing[] in the given order
	 */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$posts = get_posts( [ 'post_type' => ListingTypes::POST_TYPE, 'post__in' => $ids, 'orderby' => 'post__in', 'post_status' => self::ALL_STATUSES, 'numberposts' => -1, 'suppress_filters' => true ] );
		update_object_term_cache( $ids, ListingTypes::POST_TYPE );
		return array_map( [ Listing::class, 'from_post' ], $posts );
	}

	/**
	 * Create a listing post. $data: farm_id, author_id, title, content, excerpt, status, price_cents, price_label, categories (ids), meta (key=>value).
	 */
	public function create( array $data ): Listing {
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ?: __( 'Untitled listing', 'southpoll-membership' );
		$id    = wp_insert_post( [
			'post_type'    => ListingTypes::POST_TYPE,
			'post_status'  => 'draft',
			'post_author'  => (int) ( $data['author_id'] ?? get_current_user_id() ),
			'post_title'   => $title,
			'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ),
			'post_name'    => '',
			'meta_input'   => [
				'_sp_farm_id'       => (int) ( $data['farm_id'] ?? 0 ),
				'_sp_status'        => in_array( $data['status'] ?? '', ListingTypes::STATUSES, true ) ? $data['status'] : 'available',
				'_sp_price'         => isset( $data['price_cents'] ) && null !== $data['price_cents'] ? (int) $data['price_cents'] : '',
				'_sp_price_label'   => sanitize_text_field( (string) ( $data['price_label'] ?? '' ) ),
				'_sp_renewed_count' => 0,
				'_sp_view_count'    => 0,
				'_sp_gallery'       => wp_json_encode( array_values( array_filter( array_map( [ Listing::class, 'clean_gallery_item' ], (array) ( $data['gallery'] ?? [] ) ) ) ) ),
				'_sp_old_slugs'     => '[]',
				'_sp_reminders'     => '[]',
			],
		], true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not create listing: ' . $id->get_error_message() );
		}
		$this->set_slug( (int) $id, $title );
		if ( ! empty( $data['categories'] ) ) {
			$this->set_categories( (int) $id, (array) $data['categories'] );
		}
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$this->set_fields( (int) $id, $data['meta'] );
		}
		if ( ! empty( $data['featured_id'] ) ) {
			set_post_thumbnail( (int) $id, (int) $data['featured_id'] );
		}
		return $this->find( (int) $id );
	}

	/**
	 * Update post columns + meta. $data keys: title, content, excerpt, post_status, status, price_cents, price_label, categories, meta,
	 * featured_id, gallery, published_at, expires_at, sold_at, renewed_count, hidden_reason, frozen_seconds, reminders, post_date.
	 */
	public function update( int $id, array $data ): ?Listing {
		$before = $this->find( $id );
		if ( ! $before ) {
			return null;
		}
		$post = [ 'ID' => $id ];
		if ( isset( $data['title'] ) ) {
			$post['post_title'] = sanitize_text_field( (string) $data['title'] ) ?: $before->title;
		}
		if ( array_key_exists( 'content', $data ) ) {
			$post['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( array_key_exists( 'excerpt', $data ) ) {
			$post['post_excerpt'] = sanitize_textarea_field( (string) $data['excerpt'] );
		}
		if ( isset( $data['post_status'] ) && in_array( $data['post_status'], self::ALL_STATUSES, true ) ) {
			$post['post_status'] = $data['post_status'];
		}
		if ( ! empty( $data['post_date'] ) ) {
			$post['post_date_gmt'] = (string) $data['post_date'];
			$post['post_date']     = get_date_from_gmt( (string) $data['post_date'] );
			$post['edit_date']     = true;
		}
		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		}
		if ( isset( $post['post_title'] ) && $post['post_title'] !== $before->title ) {
			$this->set_slug( $id, $post['post_title'], $before );
		}
		$meta_map = [ 'status' => '_sp_status', 'price_label' => '_sp_price_label', 'published_at' => '_sp_published_at', 'expires_at' => '_sp_expires_at', 'sold_at' => '_sp_sold_at', 'renewed_count' => '_sp_renewed_count', 'hidden_reason' => '_sp_hidden_reason', 'frozen_seconds' => '_sp_frozen_seconds', 'farm_id' => '_sp_farm_id' ];
		foreach ( $meta_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $data ) ) {
				$value = $data[ $key ];
				if ( 'status' === $key && ! in_array( $value, ListingTypes::STATUSES, true ) ) {
					continue;
				}
				update_post_meta( $id, $meta_key, null === $value ? '' : ( is_string( $value ) ? sanitize_text_field( $value ) : $value ) );
			}
		}
		if ( array_key_exists( 'price_cents', $data ) ) {
			update_post_meta( $id, '_sp_price', null === $data['price_cents'] || '' === $data['price_cents'] ? '' : (int) $data['price_cents'] );
		}
		if ( array_key_exists( 'gallery', $data ) ) {
			update_post_meta( $id, '_sp_gallery', wp_json_encode( array_values( array_filter( array_map( [ Listing::class, 'clean_gallery_item' ], (array) $data['gallery'] ) ) ) ) );
		}
		if ( array_key_exists( 'reminders', $data ) ) {
			update_post_meta( $id, '_sp_reminders', wp_json_encode( array_values( array_map( 'strval', (array) $data['reminders'] ) ) ) );
		}
		if ( array_key_exists( 'categories', $data ) ) {
			$this->set_categories( $id, (array) $data['categories'] );
		}
		if ( array_key_exists( 'meta', $data ) && is_array( $data['meta'] ) ) {
			$this->set_fields( $id, $data['meta'] );
		}
		if ( array_key_exists( 'featured_id', $data ) ) {
			if ( $data['featured_id'] ) {
				set_post_thumbnail( $id, (int) $data['featured_id'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}
		clean_post_cache( $id );
		return $this->find( $id );
	}

	/** Unique slug from the title; renames keep the previous slug for 301s. */
	public function set_slug( int $id, string $title, ?Listing $before = null ): string {
		global $wpdb;
		$base = sanitize_title( $title ) ?: 'listing';
		$slug = wp_unique_post_slug( $base, $id, 'publish', ListingTypes::POST_TYPE, 0 );
		if ( $before && '' !== $before->slug && $before->slug !== $slug ) {
			$old = array_values( array_unique( array_merge( $before->old_slugs, [ $before->slug ] ) ) );
			update_post_meta( $id, '_sp_old_slugs', wp_json_encode( $old ) );
		}
		$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		return $slug;
	}

	/** @param array<int, int|string> $ids term ids (unknown values ignored; never auto-created) */
	public function set_categories( int $id, array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		wp_set_object_terms( $id, $ids, ListingTypes::TAXONOMY, false );
		clean_object_term_cache( $id, ListingTypes::POST_TYPE );
	}

	/** @param array<string, string|null> $values field_key => value (null deletes) */
	public function set_fields( int $id, array $values ): void {
		foreach ( $values as $key => $value ) {
			$key = str_replace( '-', '_', sanitize_key( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			if ( null === $value || '' === $value ) {
				delete_post_meta( $id, self::META_PREFIX . $key );
			} else {
				update_post_meta( $id, self::META_PREFIX . $key, (string) $value );
			}
		}
	}

	public function increment_views( int $id ): int {
		$count = (int) get_post_meta( $id, '_sp_view_count', true ) + 1;
		update_post_meta( $id, '_sp_view_count', $count );
		return $count;
	}

	public function delete( int $id ): bool {
		return (bool) wp_delete_post( $id, true );
	}

	/* ------------------------------------------------------------- farm views */

	/**
	 * Every listing of a farm (owner table). Newest first.
	 *
	 * @return Listing[]
	 */
	public function for_farm( int $farm_id, int $limit = 500 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' WHERE p.post_type = %s AND p.post_status IN ('publish','draft','sp_expired') AND mf.meta_value = %d ORDER BY p.post_modified_gmt DESC, p.ID DESC LIMIT %d", ListingTypes::POST_TYPE, $farm_id, $limit ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** Published listings in an active status (counts against the limit, D-18). */
	public function count_active( int $farm_id, int $exclude_id = 0 ): int {
		global $wpdb;
		$in = "'" . implode( "','", ListingTypes::ACTIVE_STATUSES ) . "'";
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_sp_status' WHERE p.post_type = %s AND p.post_status = 'publish' AND mf.meta_value = %d AND ms.meta_value IN ({$in}) AND p.ID <> %d", ListingTypes::POST_TYPE, $farm_id, $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array{active:int, last_published_at:?string} */
	public function farm_stats( int $farm_id ): array {
		global $wpdb;
		$in   = "'" . implode( "','", ListingTypes::ACTIVE_STATUSES ) . "'";
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(mp.meta_value) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_sp_status' LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_sp_published_at' WHERE p.post_type = %s AND p.post_status = 'publish' AND mf.meta_value = %d AND ms.meta_value IN ({$in})", ListingTypes::POST_TYPE, $farm_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'active' => $this->count_active( $farm_id ), 'last_published_at' => $last ?: null ];
	}

	/**
	 * Publicly visible listings of a farm (newest first). Membership gating is the caller's job (the farm page already checks).
	 *
	 * @return Listing[]
	 */
	public function public_for_farm( int $farm_id, int $limit = 12, int $exclude_id = 0, int $sold_days = 14 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_sp_published_at' WHERE p.post_type = %s AND p.post_status = 'publish' AND mf.meta_value = %d AND p.ID <> %d ORDER BY mp.meta_value DESC, p.ID DESC LIMIT %d", ListingTypes::POST_TYPE, $farm_id, $exclude_id, $limit * 2 ) );
		$out = [];
		foreach ( $this->find_many( array_map( 'intval', $ids ) ) as $l ) {
			if ( $l->is_public( $sold_days ) ) {
				$out[] = $l;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Farm ids with a live listing in any of the given category slugs (directory "stock available"). @return int[] */
	public function farm_ids_with_categories( array $slugs, int $sold_days = 14 ): array {
		global $wpdb;
		$slugs = array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
		if ( empty( $slugs ) ) {
			return [];
		}
		$ph  = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$sql = "SELECT DISTINCT mf.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_sp_status' LEFT JOIN {$wpdb->postmeta} msold ON msold.post_id = p.ID AND msold.meta_key = '_sp_sold_at' JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE p.post_type = %s AND p.post_status = 'publish' AND t.slug IN ({$ph}) AND (ms.meta_value <> 'sold' OR msold.meta_value >= %s)";
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, array_merge( [ ListingTypes::TAXONOMY, ListingTypes::POST_TYPE ], $slugs, [ Dates::add_days( Dates::now(), -$sold_days ) ] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------- cron */

	/** @return Listing[] published listings whose expiry has passed */
	public function due_to_expire( string $now ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} me ON me.post_id = p.ID AND me.meta_key = '_sp_expires_at' WHERE p.post_type = %s AND p.post_status = 'publish' AND me.meta_value <> '' AND me.meta_value <= %s", ListingTypes::POST_TYPE, $now ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** @return Listing[] published listings expiring within $days days */
	public function expiring_within( int $days, string $now ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} me ON me.post_id = p.ID AND me.meta_key = '_sp_expires_at' WHERE p.post_type = %s AND p.post_status = 'publish' AND me.meta_value > %s AND me.meta_value <= %s", ListingTypes::POST_TYPE, $now, Dates::add_days( $now, $days ) ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** @return Listing[] published sold listings whose public window has closed */
	public function sold_window_closed( int $sold_days, string $now ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_sp_status' AND ms.meta_value = 'sold' LEFT JOIN {$wpdb->postmeta} msold ON msold.post_id = p.ID AND msold.meta_key = '_sp_sold_at' WHERE p.post_type = %s AND p.post_status = 'publish' AND (msold.meta_value IS NULL OR msold.meta_value = '' OR msold.meta_value < %s)", ListingTypes::POST_TYPE, Dates::add_days( $now, -$sold_days ) ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** @return Listing[] published listings of a farm (any public status), for hide/restore */
	public function published_for_farm( int $farm_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' WHERE p.post_type = %s AND p.post_status = 'publish' AND mf.meta_value = %d", ListingTypes::POST_TYPE, $farm_id ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** @return Listing[] drafts hidden for a reason (membership) of a farm */
	public function hidden_for_farm( int $farm_id, string $reason ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = '_sp_farm_id' JOIN {$wpdb->postmeta} mh ON mh.post_id = p.ID AND mh.meta_key = '_sp_hidden_reason' WHERE p.post_type = %s AND p.post_status = 'draft' AND mf.meta_value = %d AND mh.meta_value = %s", ListingTypes::POST_TYPE, $farm_id, $reason ) );
		return $this->find_many( array_map( 'intval', $ids ) );
	}

	/** Is an attachment referenced by any listing other than $exclude_id (featured or gallery)? */
	public function attachment_in_use( int $attachment_id, int $exclude_id = 0 ): bool {
		global $wpdb;
		$thumb = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id <> %d", $attachment_id, $exclude_id ) );
		if ( $thumb > 0 ) {
			return true;
		}
		$gal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sp_gallery' AND meta_value LIKE %s AND post_id <> %d", '%' . $wpdb->esc_like( '"id":' . $attachment_id . ',' ) . '%', $exclude_id ) );
		return $gal > 0;
	}

	public function count_all(): int {
		return (int) ( wp_count_posts( ListingTypes::POST_TYPE )->publish ?? 0 );
	}

	public static function table_memberships(): string {
		return Schema::table( 'sp_memberships' );
	}
}
