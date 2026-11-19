<?php
/**
 * My Farm media (owner or active manager): logo/banner and the gallery.
 *
 *   POST   /sp/v1/my-farm/image              multipart { file, purpose: logo|banner }
 *   DELETE /sp/v1/my-farm/image/{purpose}
 *   GET    /sp/v1/my-farm/media
 *   POST   /sp/v1/my-farm/media              multipart { file } | json { video_url, caption }
 *   PATCH  /sp/v1/my-farm/media/{id}         { caption }
 *   DELETE /sp/v1/my-farm/media/{id}
 *   POST   /sp/v1/my-farm/media/reorder      { ids: [] }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Members\ContextProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FarmMediaController extends BaseController {

	public function __construct(
		private ContextProvider $contexts,
		private FarmRepository $farms,
		private FarmMediaRepository $media,
		private MediaUploader $uploader,
		private Settings $settings
	) {}

	public function register_routes(): void {
		$perm = [ $this, 'can_edit' ];
		$this->route( '/my-farm/image', [ [ 'methods' => 'POST', 'callback' => [ $this, 'upload_image' ], 'permission_callback' => $perm ] ] );
		$this->route( '/my-farm/image/(?P<purpose>logo|banner)', [ [ 'methods' => 'DELETE', 'callback' => [ $this, 'remove_image' ], 'permission_callback' => $perm ] ] );
		$this->route( '/my-farm/media', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'add' ], 'permission_callback' => $perm ],
		] );
		$this->route( '/my-farm/media/reorder', [ [ 'methods' => 'POST', 'callback' => [ $this, 'reorder' ], 'permission_callback' => $perm ] ] );
		$this->route( '/my-farm/media/(?P<id>\d+)', [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ],
		] );
	}

	public function can_edit(): bool|WP_Error {
		$login = self::require_login();
		if ( true !== $login ) {
			return $login;
		}
		$ctx = $this->contexts->current();
		if ( ! $ctx->has_farm() || ! $ctx->can( 'edit_farm' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot edit a farm page right now.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function upload_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = $this->rate_limit( 'farm_upload', 60 );
		if ( true !== $limit ) {
			return $limit;
		}
		$ctx     = $this->contexts->current();
		$purpose = 'banner' === $request->get_param( 'purpose' ) ? 'banner' : 'logo';
		$files   = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return $this->fail( 'sp_upload_missing', __( 'Choose an image to upload.', 'southpoll-membership' ), 422 );
		}
		$id = $this->uploader->store( $files['file'], $ctx->farm_id(), $ctx->user_id, $purpose );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id->get_error_code(), $id->get_error_message(), 422 );
		}
		$farm = $this->farms->find( $ctx->farm_id() );
		$old  = 'banner' === $purpose ? (int) $farm->banner_id : (int) $farm->logo_id;
		$this->farms->set_image( $ctx->farm_id(), $purpose, $id );
		if ( $old && $old !== $id ) {
			MediaUploader::delete_if_owned( $old, $ctx->farm_id() );
		}
		return $this->ok( [ 'ok' => true, 'id' => $id, 'url' => MediaUploader::url( $id, 'banner' === $purpose ? 'sp-banner' : 'sp-logo' ), 'message' => 'banner' === $purpose ? __( 'Banner updated.', 'southpoll-membership' ) : __( 'Logo updated.', 'southpoll-membership' ) ] );
	}

	public function remove_image( WP_REST_Request $request ): WP_REST_Response {
		$ctx     = $this->contexts->current();
		$purpose = 'banner' === $request['purpose'] ? 'banner' : 'logo';
		$farm    = $this->farms->find( $ctx->farm_id() );
		$old     = 'banner' === $purpose ? (int) $farm->banner_id : (int) $farm->logo_id;
		$this->farms->set_image( $ctx->farm_id(), $purpose, null );
		MediaUploader::delete_if_owned( $old ?: null, $ctx->farm_id() );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Removed.', 'southpoll-membership' ) ] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'items' => $this->items() ] );
	}

	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ctx = $this->contexts->current();
		$max = (int) $this->settings->get( 'farm_gallery_max_items', 20 );
		if ( $this->media->count( $ctx->farm_id() ) >= $max ) {
			return $this->fail( 'sp_gallery_full', sprintf( /* translators: %d: limit */ __( 'The gallery holds up to %d items. Remove one to add another.', 'southpoll-membership' ), $max ), 422 );
		}
		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) ) {
			$limit = $this->rate_limit( 'farm_upload', 60 );
			if ( true !== $limit ) {
				return $limit;
			}
			$id = $this->uploader->store( $files['file'], $ctx->farm_id(), $ctx->user_id, 'gallery' );
			if ( is_wp_error( $id ) ) {
				return $this->fail( $id->get_error_code(), $id->get_error_message(), 422 );
			}
			$row = $this->media->add_image( $ctx->farm_id(), $id, (string) $request->get_param( 'caption' ) );
		} else {
			$row = $this->media->add_video( $ctx->farm_id(), (string) $request->get_param( 'video_url' ), (string) $request->get_param( 'caption' ) );
			if ( is_wp_error( $row ) ) {
				return $this->fail( $row->get_error_code(), $row->get_error_message(), 422 );
			}
		}
		return $this->ok( [ 'ok' => true, 'item' => FarmMediaRepository::serialize( $row ), 'items' => $this->items(), 'message' => __( 'Added to your gallery.', 'southpoll-membership' ) ] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $request->has_param( 'caption' ) ) {
			$this->media->update_caption( (int) $row->id, (string) $request->get_param( 'caption' ) );
		}
		return $this->ok( [ 'ok' => true, 'items' => $this->items() ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->own( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->media->delete( (int) $row->id );
		return $this->ok( [ 'ok' => true, 'items' => $this->items(), 'message' => __( 'Removed from your gallery.', 'southpoll-membership' ) ] );
	}

	public function reorder( WP_REST_Request $request ): WP_REST_Response {
		$ctx = $this->contexts->current();
		$this->media->reorder( $ctx->farm_id(), array_map( 'intval', (array) $request->get_param( 'ids' ) ) );
		return $this->ok( [ 'ok' => true, 'items' => $this->items() ] );
	}

	/* ---------------------------------------------------------------- helpers */

	private function own( int $id ): \stdClass|WP_Error {
		$row = $this->media->find( $id );
		if ( ! $row || (int) $row->farm_id !== $this->contexts->current()->farm_id() ) {
			return $this->fail( 'sp_not_found', __( 'Gallery item not found.', 'southpoll-membership' ), 404 );
		}
		return $row;
	}

	/** @return array<int, array<string, mixed>> */
	private function items(): array {
		return array_map( [ FarmMediaRepository::class, 'serialize' ], $this->media->for_farm( $this->contexts->current()->farm_id() ) );
	}
}
