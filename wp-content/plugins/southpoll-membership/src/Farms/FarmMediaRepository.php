<?php
/**
 * Farm gallery (sp_farm_media): ordered images (attachments) and YouTube / Vimeo videos.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;
use WP_Error;

final class FarmMediaRepository {

	private function table(): string {
		return Schema::table( 'sp_farm_media' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return object[] in display order */
	public function for_farm( int $farm_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d ORDER BY sort_order, id", $farm_id ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function count( int $farm_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE farm_id = %d", $farm_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function add_image( int $farm_id, int $attachment_id, string $caption = '' ): object {
		return $this->insert( $farm_id, [ 'type' => 'image', 'attachment_id' => $attachment_id, 'video_url' => '', 'caption' => sanitize_text_field( $caption ) ] );
	}

	public function add_video( int $farm_id, string $url, string $caption = '' ): \stdClass|WP_Error {
		$video = self::parse_video( $url );
		if ( null === $video ) {
			return new WP_Error( 'sp_video_url', __( 'Please paste a YouTube or Vimeo link (for example https://youtu.be/abc123).', 'southpoll-membership' ) );
		}
		return $this->insert( $farm_id, [ 'type' => 'video', 'attachment_id' => null, 'video_url' => $video['url'], 'caption' => sanitize_text_field( $caption ) ] );
	}

	public function update_caption( int $id, string $caption ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'caption' => sanitize_text_field( $caption ) ], [ 'id' => $id ] );
	}

	/** @param int[] $ordered_ids */
	public function reorder( int $farm_id, array $ordered_ids ): void {
		global $wpdb;
		foreach ( array_values( array_map( 'intval', $ordered_ids ) ) as $i => $id ) {
			$wpdb->update( $this->table(), [ 'sort_order' => $i ], [ 'id' => $id, 'farm_id' => $farm_id ] );
		}
	}

	/** Delete the row and, for images uploaded through the plugin, the attachment file too. */
	public function delete( int $id ): bool {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
		if ( 'image' === $row->type && ! empty( $row->attachment_id ) && (int) get_post_meta( (int) $row->attachment_id, '_sp_farm_id', true ) === (int) $row->farm_id ) {
			wp_delete_attachment( (int) $row->attachment_id, true );
		}
		return true;
	}

	/* --------------------------------------------------------------- videos */

	/**
	 * Normalise a YouTube / Vimeo URL. Returns provider, id, canonical url, embed url, thumbnail.
	 *
	 * @return array{provider:string, id:string, url:string, embed:string, thumb:string}|null
	 */
	public static function parse_video( string $url ): ?array {
		$url = trim( $url );
		if ( preg_match( '#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m ) ) {
			$id = $m[1];
			return [ 'provider' => 'youtube', 'id' => $id, 'url' => 'https://www.youtube.com/watch?v=' . $id, 'embed' => 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0', 'thumb' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' ];
		}
		if ( preg_match( '#vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d{5,})#i', $url, $m ) ) {
			$id = $m[1];
			return [ 'provider' => 'vimeo', 'id' => $id, 'url' => 'https://vimeo.com/' . $id, 'embed' => 'https://player.vimeo.com/video/' . $id . '?autoplay=1', 'thumb' => '' ];
		}
		return null;
	}

	/**
	 * Serialise a media row for templates / REST (urls resolved).
	 *
	 * @return array<string, mixed>
	 */
	public static function serialize( object $row ): array {
		$out = [ 'id' => (int) $row->id, 'type' => (string) $row->type, 'caption' => (string) $row->caption, 'sort_order' => (int) $row->sort_order ];
		if ( 'video' === $row->type ) {
			$v = self::parse_video( (string) $row->video_url ) ?? [ 'provider' => '', 'id' => '', 'url' => (string) $row->video_url, 'embed' => '', 'thumb' => '' ];
			return $out + [ 'provider' => $v['provider'], 'url' => $v['url'], 'embed' => $v['embed'], 'thumb' => $v['thumb'], 'full' => '' ];
		}
		$aid = (int) $row->attachment_id;
		return $out + [
			'attachment_id' => $aid,
			'thumb'         => (string) ( wp_get_attachment_image_url( $aid, 'sp-thumb' ) ?: wp_get_attachment_image_url( $aid, 'medium' ) ?: '' ),
			'full'          => (string) ( wp_get_attachment_image_url( $aid, 'sp-gallery' ) ?: wp_get_attachment_image_url( $aid, 'large' ) ?: wp_get_attachment_url( $aid ) ?: '' ),
			'alt'           => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
		];
	}

	private function insert( int $farm_id, array $data ): object {
		global $wpdb;
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), -1) FROM {$this->table()} WHERE farm_id = %d", $farm_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( $this->table(), $data + [ 'farm_id' => $farm_id, 'sort_order' => $max + 1, 'created_at' => Dates::now() ] );
		return $this->find( (int) $wpdb->insert_id );
	}
}
