<?php
/**
 * Member listing management (owner or active manager).
 *
 *   GET    /sp/v1/my-listings                       rows + limit summary
 *   POST   /sp/v1/my-listings                       create a draft → { id, edit_url }
 *   POST   /sp/v1/my-listings/{id}/toggle           live ↔ inactive
 *   POST   /sp/v1/my-listings/{id}/status           { status }
 *   POST   /sp/v1/my-listings/{id}/duplicate        → { id, edit_url }
 *   POST   /sp/v1/my-listings/{id}/renew
 *   DELETE /sp/v1/my-listings/{id}
 *   POST   /sp/v1/my-listings/{id}/image            multipart { file } → featured image
 *   DELETE /sp/v1/my-listings/{id}/image
 *   GET    /sp/v1/my-listings/{id}/media
 *   POST   /sp/v1/my-listings/{id}/media            multipart { file } | json { video_url, caption }
 *   PATCH  /sp/v1/my-listings/{id}/media/{key}      { caption }
 *   DELETE /sp/v1/my-listings/{id}/media/{key}
 *   POST   /sp/v1/my-listings/{id}/media/reorder    { keys: [] }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Listings\Listing;
use SouthPoll\Membership\Listings\ListingLimits;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class MyListingsController extends BaseController {

	public function __construct(
		private ContextProvider $contexts,
		private ListingRepository $listings,
		private ListingService $service,
		private ListingLimits $limits,
		private ListingPresenter $presenter,
		private MediaUploader $uploader,
		private Settings $settings,
		private Urls $urls
	) {}

	public function register_routes(): void {
		$perm = [ $this, 'can_manage' ];
		$this->route( '/my-listings', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $perm ],
		] );
		foreach ( [ 'toggle', 'status', 'duplicate', 'renew' ] as $action ) {
			$this->route( '/my-listings/(?P<id>\d+)/' . $action, [ [ 'methods' => 'POST', 'callback' => [ $this, $action ], 'permission_callback' => $perm ] ] );
		}
		$this->route( '/my-listings/(?P<id>\d+)', [ [ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ] ] );
		$this->route( '/my-listings/(?P<id>\d+)/image', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'upload_featured' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove_featured' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/my-listings/(?P<id>\d+)/media', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'media' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'media_add' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/my-listings/(?P<id>\d+)/media/reorder', [ [ 'methods' => 'POST', 'callback' => [ $this, 'media_reorder' ], 'permission_callback' => $perm ] ] );
		$this->route( '/my-listings/(?P<id>\d+)/media/(?P<key>[a-z0-9_-]+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'media_update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'media_remove' ], 'permission_callback' => $perm ],
		] );
	}

	public function can_manage(): bool|WP_Error {
		$login = self::require_login();
		if ( true !== $login ) {
			return $login;
		}
		$ctx = $this->contexts->current();
		if ( ! $ctx->is_staff() && ( ! $ctx->has_farm() || ! $ctx->can( 'manage_listings' ) ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot manage listings right now.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/* ---------------------------------------------------------------- table */

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->payload() );
	}

	/** @return array<string, mixed> rows + summary (shared with the My Listings section) */
	public function payload(): array {
		$ctx   = $this->contexts->current();
		$rows  = $this->listings->for_farm( $ctx->farm_id() );
		$cards = $this->presenter->cards( $rows, $ctx, [ $ctx->farm_id() => $ctx->farm ] );
		return [
			'items'       => $cards,
			'summary'     => $this->limits->summary( $ctx->farm_id() ),
			'can_publish' => $ctx->can( 'publish_listing' ),
			'drafts'      => count( array_filter( $rows, static fn( Listing $l ) => 'draft' === $l->post_status ) ),
			'expired'     => count( array_filter( $rows, static fn( Listing $l ) => 'sp_expired' === $l->post_status ) ),
		];
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ctx     = $this->contexts->current();
		$listing = $this->service->create_draft( $ctx, [ 'title' => (string) $request->get_param( 'title' ) ] );
		if ( is_wp_error( $listing ) ) {
			return $this->fail( $listing->get_error_code(), $listing->get_error_message(), 403 );
		}
		return $this->ok( [ 'ok' => true, 'id' => $listing->id, 'edit_url' => $this->urls->edit_listing( $listing->id ) ] );
	}

	public function toggle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( (int) $request['id'], fn( $ctx, $l ) => $this->service->toggle_active( $ctx, $l ), fn( Listing $l ) => 'publish' === $l->post_status ? __( 'Listing is live.', 'southpoll-membership' ) : __( 'Listing paused (inactive).', 'southpoll-membership' ) );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		return $this->act( (int) $request['id'], fn( $ctx, $l ) => $this->service->set_status( $ctx, $l, $status ), fn( Listing $l ) => sprintf( /* translators: %s: status */ __( 'Status set to %s.', 'southpoll-membership' ), $l->status_label() ) );
	}

	public function renew( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( (int) $request['id'], fn( $ctx, $l ) => $this->service->renew( $ctx, $l ), fn( Listing $l ) => sprintf( /* translators: %s: date */ __( 'Renewed — now runs until %s.', 'southpoll-membership' ), \SouthPoll\Membership\Support\Dates::display( (string) $l->expires_at ) ) );
	}

	public function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$copy = $this->service->duplicate( $this->contexts->current(), $listing );
		if ( is_wp_error( $copy ) ) {
			return $this->fail( $copy->get_error_code(), $copy->get_error_message(), 403 );
		}
		return $this->ok( [ 'ok' => true, 'id' => $copy->id, 'edit_url' => $this->urls->edit_listing( $copy->id ), 'message' => __( 'Copy created as a draft.', 'southpoll-membership' ) ] + $this->payload() );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$res = $this->service->delete( $this->contexts->current(), $listing );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res->get_error_code(), $res->get_error_message(), 403 );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Listing deleted.', 'southpoll-membership' ) ] + $this->payload() );
	}

	/* ---------------------------------------------------------------- media */

	public function upload_featured( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$limit = $this->rate_limit( 'listing_upload', 120 );
		if ( true !== $limit ) {
			return $limit;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return $this->fail( 'sp_upload_missing', __( 'Choose an image to upload.', 'southpoll-membership' ), 422 );
		}
		$id = $this->store( $files['file'], $listing );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id->get_error_code(), $id->get_error_message(), 422 );
		}
		$old = (int) $listing->featured_id;
		$this->listings->update( $listing->id, [ 'featured_id' => $id ] );
		if ( $old && $old !== $id && ! $this->listings->attachment_in_use( $old, $listing->id ) && ! $this->in_gallery( $listing, $old ) ) {
			MediaUploader::delete_if_owned( $old, $listing->farm_id );
		}
		return $this->ok( [ 'ok' => true, 'id' => $id, 'url' => MediaUploader::url( $id, 'sp-card' ), 'message' => __( 'Featured image updated.', 'southpoll-membership' ) ] );
	}

	public function remove_featured( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$old = (int) $listing->featured_id;
		$this->listings->update( $listing->id, [ 'featured_id' => null ] );
		if ( $old && ! $this->listings->attachment_in_use( $old, $listing->id ) && ! $this->in_gallery( $listing, $old ) ) {
			MediaUploader::delete_if_owned( $old, $listing->farm_id );
		}
		return $this->ok( [ 'ok' => true, 'message' => __( 'Removed.', 'southpoll-membership' ) ] );
	}

	public function media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		return is_wp_error( $listing ) ? $listing : $this->ok( [ 'items' => $this->items( $listing ) ] );
	}

	public function media_add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$max = (int) $this->settings->get( 'listing_max_images', 10 );
		if ( count( $listing->gallery ) >= $max ) {
			return $this->fail( 'sp_gallery_full', sprintf( /* translators: %d: limit */ __( 'A listing can hold up to %d gallery items. Remove one to add another.', 'southpoll-membership' ), $max ), 422 );
		}
		$files   = $request->get_file_params();
		$gallery = $listing->gallery;
		if ( ! empty( $files['file'] ) ) {
			$limit = $this->rate_limit( 'listing_upload', 120 );
			if ( true !== $limit ) {
				return $limit;
			}
			$id = $this->store( $files['file'], $listing );
			if ( is_wp_error( $id ) ) {
				return $this->fail( $id->get_error_code(), $id->get_error_message(), 422 );
			}
			$gallery[] = [ 'key' => $this->key(), 'type' => 'image', 'id' => $id, 'url' => '', 'caption' => sanitize_text_field( (string) $request->get_param( 'caption' ) ) ];
		} else {
			$video = FarmMediaRepository::parse_video( (string) $request->get_param( 'video_url' ) );
			if ( null === $video ) {
				return $this->fail( 'sp_video_url', __( 'Please paste a YouTube or Vimeo link (for example https://youtu.be/abc123).', 'southpoll-membership' ), 422 );
			}
			$gallery[] = [ 'key' => $this->key(), 'type' => 'video', 'id' => 0, 'url' => $video['url'], 'caption' => sanitize_text_field( (string) $request->get_param( 'caption' ) ) ];
		}
		$updated = $this->listings->update( $listing->id, [ 'gallery' => $gallery ] );
		return $this->ok( [ 'ok' => true, 'items' => $this->items( $updated ), 'message' => __( 'Added to the gallery.', 'southpoll-membership' ) ] );
	}

	public function media_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$key     = sanitize_key( (string) $request['key'] );
		$gallery = $listing->gallery;
		foreach ( $gallery as &$g ) {
			if ( $g['key'] === $key && $request->has_param( 'caption' ) ) {
				$g['caption'] = sanitize_text_field( (string) $request->get_param( 'caption' ) );
			}
		}
		unset( $g );
		$updated = $this->listings->update( $listing->id, [ 'gallery' => $gallery ] );
		return $this->ok( [ 'ok' => true, 'items' => $this->items( $updated ) ] );
	}

	public function media_remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$key     = sanitize_key( (string) $request['key'] );
		$removed = null;
		$gallery = array_values( array_filter( $listing->gallery, static function ( $g ) use ( $key, &$removed ) {
			if ( $g['key'] === $key ) {
				$removed = $g;
				return false;
			}
			return true;
		} ) );
		if ( ! $removed ) {
			return $this->fail( 'sp_not_found', __( 'Gallery item not found.', 'southpoll-membership' ), 404 );
		}
		$updated = $this->listings->update( $listing->id, [ 'gallery' => $gallery ] );
		if ( 'image' === $removed['type'] && (int) $removed['id'] !== (int) $listing->featured_id && ! $this->listings->attachment_in_use( (int) $removed['id'], $listing->id ) ) {
			MediaUploader::delete_if_owned( (int) $removed['id'], $listing->farm_id );
		}
		return $this->ok( [ 'ok' => true, 'items' => $this->items( $updated ), 'message' => __( 'Removed from the gallery.', 'southpoll-membership' ) ] );
	}

	public function media_reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = $this->own( (int) $request['id'] );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$keys  = array_map( 'sanitize_key', (array) $request->get_param( 'keys' ) );
		$by    = [];
		foreach ( $listing->gallery as $g ) {
			$by[ $g['key'] ] = $g;
		}
		$ordered = [];
		foreach ( $keys as $k ) {
			if ( isset( $by[ $k ] ) ) {
				$ordered[] = $by[ $k ];
				unset( $by[ $k ] );
			}
		}
		$ordered = array_merge( $ordered, array_values( $by ) );
		$updated = $this->listings->update( $listing->id, [ 'gallery' => $ordered ] );
		return $this->ok( [ 'ok' => true, 'items' => $this->items( $updated ) ] );
	}

	/* ---------------------------------------------------------------- helpers */

	private function act( int $id, callable $fn, callable $message ): WP_REST_Response|WP_Error {
		$listing = $this->own( $id );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$res = $fn( $this->contexts->current(), $listing );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res->get_error_code(), $res->get_error_message(), in_array( $res->get_error_code(), [ 'sp_limit', 'sp_membership_inactive' ], true ) ? 422 : 403, $this->payload() );
		}
		return $this->ok( [ 'ok' => true, 'message' => $message( $res ), 'item' => $this->presenter->card( $res, $this->contexts->current() ) ] + $this->payload() );
	}

	private function own( int $id ): Listing|WP_Error {
		$listing = $this->listings->find( $id );
		$ctx     = $this->contexts->current();
		if ( ! $listing || ! $this->service->owns( $ctx, $listing ) ) {
			return $this->fail( 'sp_not_found', __( 'Listing not found.', 'southpoll-membership' ), 404 );
		}
		return $listing;
	}

	/** @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file */
	private function store( array $file, Listing $listing ): int|WP_Error {
		$ctx = $this->contexts->current();
		$id  = $this->uploader->store( $file, $listing->farm_id, $ctx->user_id, 'listing' );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_sp_listing_id', $listing->id );
		}
		return $id;
	}

	private function in_gallery( Listing $listing, int $attachment_id ): bool {
		foreach ( $listing->gallery as $g ) {
			if ( 'image' === $g['type'] && (int) $g['id'] === $attachment_id ) {
				return true;
			}
		}
		return false;
	}

	private function key(): string {
		return substr( md5( uniqid( 'g', true ) ), 0, 10 );
	}

	/** @return array<int, array<string, mixed>> */
	public function items( Listing $listing ): array {
		$out = [];
		foreach ( $listing->gallery as $g ) {
			if ( 'video' === $g['type'] ) {
				$v     = FarmMediaRepository::parse_video( $g['url'] ) ?? [ 'provider' => '', 'url' => $g['url'], 'embed' => '', 'thumb' => '' ];
				$out[] = [ 'key' => $g['key'], 'type' => 'video', 'caption' => $g['caption'], 'provider' => $v['provider'], 'url' => $v['url'], 'embed' => $v['embed'], 'thumb' => $v['thumb'], 'full' => '' ];
			} else {
				$out[] = [ 'key' => $g['key'], 'type' => 'image', 'caption' => $g['caption'], 'attachment_id' => (int) $g['id'], 'thumb' => MediaUploader::url( (int) $g['id'], 'sp-thumb' ), 'full' => MediaUploader::url( (int) $g['id'], 'sp-gallery' ) ];
			}
		}
		return $out;
	}
}
