<?php
/**
 * Turns member-uploaded files into media-library attachments tagged with the farm id, with plugin image sizes
 * (logo/card/banner/gallery/thumb) generated only for those uploads. Members have no `upload_files` capability,
 * so the REST controllers call this directly after their own permission checks.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use WP_Error;

final class MediaUploader implements Service {

	public const SIZES = [
		'sp-logo'    => [ 400, 300, false ],
		'sp-card'    => [ 640, 480, true ],
		'sp-banner'  => [ 1600, 500, true ],
		'sp-gallery' => [ 1400, 1050, false ],
		'sp-thumb'   => [ 320, 240, true ],
	];

	private const ALLOWED = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ];

	private static bool $plugin_upload = false;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'init', [ self::class, 'register_sizes' ] );
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'filter_sizes' ], 10, 3 );
		add_filter( 'big_image_size_threshold', [ self::class, 'threshold' ] );
	}

	public static function register_sizes(): void {
		foreach ( self::SIZES as $name => [ $w, $h, $crop ] ) {
			add_image_size( $name, $w, $h, $crop );
		}
	}

	/**
	 * Plugin sizes only for plugin uploads; plugin uploads skip the theme/plugin sizes they will never use.
	 *
	 * @param array<string, array> $sizes
	 * @return array<string, array>
	 */
	public static function filter_sizes( array $sizes, array $image_meta, int $attachment_id ): array {
		$is_plugin = self::$plugin_upload || '' !== (string) get_post_meta( $attachment_id, '_sp_farm_id', true );
		foreach ( array_keys( $sizes ) as $name ) {
			$ours = isset( self::SIZES[ $name ] );
			if ( $is_plugin && ! $ours && ! in_array( $name, [ 'thumbnail', 'medium' ], true ) ) {
				unset( $sizes[ $name ] );
			} elseif ( ! $is_plugin && $ours ) {
				unset( $sizes[ $name ] );
			}
		}
		return $sizes;
	}

	public static function threshold( $threshold ) {
		return self::$plugin_upload ? 2200 : $threshold;
	}

	/**
	 * Store an uploaded image ($_FILES entry) as an attachment for a farm.
	 *
	 * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file
	 * @param string $purpose logo|banner|gallery
	 */
	public function store( array $file, int $farm_id, int $user_id, string $purpose = 'gallery' ): int|WP_Error {
		$max_mb = (float) $this->settings->get( 'farm_image_max_mb', 10 );
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'sp_upload_error', __( 'The upload did not complete. Please try again.', 'southpoll-membership' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error( 'sp_upload_missing', __( 'No file was received.', 'southpoll-membership' ) );
		}
		if ( (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
			return new WP_Error( 'sp_upload_size', sprintf( /* translators: %s: megabytes */ __( 'That image is too large. Please keep images under %s MB.', 'southpoll-membership' ), (string) $max_mb ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], self::ALLOWED );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! isset( self::ALLOWED[ $check['ext'] ] ) ) {
			return new WP_Error( 'sp_upload_type', __( 'Please upload a JPG, PNG, WebP or GIF image.', 'southpoll-membership' ) );
		}
		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return new WP_Error( 'sp_upload_type', __( 'That file is not a valid image.', 'southpoll-membership' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$farm_slug = sanitize_title( (string) ( get_option( 'blogname' ) ) ) ?: 'farm';
		$file_arr  = [
			'name'     => sanitize_file_name( 'farm-' . $farm_id . '-' . $purpose . '-' . wp_generate_password( 6, false ) . '.' . $check['ext'] ),
			'type'     => $check['type'],
			'tmp_name' => $file['tmp_name'],
			'error'    => 0,
			'size'     => (int) $file['size'],
		];
		self::$plugin_upload = true;
		add_filter( 'upload_mimes', [ self::class, 'image_mimes' ] );
		$id = media_handle_sideload( $file_arr, 0, null, [
			'post_author' => $user_id,
			'post_title'  => ucfirst( $purpose ) . ' — farm #' . $farm_id,
			'post_status' => 'inherit',
			'meta_input'  => [ '_sp_farm_id' => $farm_id, '_sp_purpose' => $purpose ],
		] );
		remove_filter( 'upload_mimes', [ self::class, 'image_mimes' ] );
		self::$plugin_upload = false;
		unset( $farm_slug );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'sp_upload_failed', $id->get_error_message() );
		}
		do_action( 'sp/farm/media_uploaded', (int) $id, $farm_id, $purpose );
		return (int) $id;
	}

	/** @return array<string, string> */
	public static function image_mimes(): array {
		return self::ALLOWED;
	}

	/** Remove an attachment the plugin created for this farm (no-op for anything else). */
	public static function delete_if_owned( ?int $attachment_id, int $farm_id ): void {
		if ( $attachment_id && (int) get_post_meta( $attachment_id, '_sp_farm_id', true ) === $farm_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * URL for a farm image size with graceful fallback.
	 */
	public static function url( ?int $attachment_id, string $size ): string {
		if ( ! $attachment_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' ) ?: wp_get_attachment_url( $attachment_id );
		}
		return (string) ( $url ?: '' );
	}
}
