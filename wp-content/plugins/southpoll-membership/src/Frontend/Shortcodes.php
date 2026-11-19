<?php
/**
 * Shortcodes:
 *   [member-directory] (alias [member_directory]) — attributes: view="list|map" per_page="24" heading="" intro=""
 *   [classifieds]                                  — attributes: view="list|map" per_page="20" heading="" intro="" category="slug"
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend;

use SouthPoll\Membership\Core\Assets;
use SouthPoll\Membership\Core\Container;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Frontend\Controllers\ClassifiedsController;
use SouthPoll\Membership\Frontend\Controllers\DirectoryController;
use SouthPoll\Membership\Listings\ListingTypes;

final class Shortcodes implements Service {

	public function __construct( private Container $container, private Assets $assets ) {}

	public function register(): void {
		add_shortcode( 'member-directory', [ $this, 'directory' ] );
		add_shortcode( 'member_directory', [ $this, 'directory' ] );
		add_shortcode( 'classifieds', [ $this, 'classifieds' ] );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function directory( $atts = [] ): string {
		$atts = shortcode_atts( [ 'view' => 'list', 'per_page' => '', 'heading' => '', 'intro' => '' ], is_array( $atts ) ? $atts : [], 'member-directory' );
		$this->assets->request_frontend();
		return '<div class="sp-scope sp-page sp-page--directory sp-shortcode">' . $this->container->get( DirectoryController::class )->render_directory( $this->clean( $atts ) ) . '</div>';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function classifieds( $atts = [] ): string {
		$atts = shortcode_atts( [ 'view' => 'list', 'per_page' => '', 'heading' => '', 'intro' => '', 'category' => '' ], is_array( $atts ) ? $atts : [], 'classifieds' );
		$this->assets->request_frontend();
		$args = $this->clean( $atts );
		if ( '' !== $atts['category'] ) {
			$cat = $this->container->get( ListingTypes::class )->category( $atts['category'] );
			if ( $cat ) {
				$args['category'] = $cat;
			}
		}
		$args['route'] = 'shortcode';
		return '<div class="sp-scope sp-page sp-page--classifieds sp-shortcode">' . $this->container->get( ClassifiedsController::class )->render_archive( $args ) . '</div>';
	}

	/** @param array<string, string> $atts @return array<string, mixed> */
	private function clean( array $atts ): array {
		$args = [ 'view' => $atts['view'] ];
		foreach ( [ 'per_page', 'heading', 'intro' ] as $k ) {
			if ( isset( $atts[ $k ] ) && '' !== $atts[ $k ] ) {
				$args[ $k ] = $atts[ $k ];
			}
		}
		return $args;
	}
}
