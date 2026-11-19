<?php
/**
 * REST API bootstrap: namespace sp/v1, one controller class per resource.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest;

use SouthPoll\Membership\Core\Container;
use SouthPoll\Membership\Core\Service;

final class Api implements Service {

	public const NAMESPACE = 'sp/v1';

	public function __construct( private Container $container ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		foreach ( $this->controllers() as $class ) {
			$controller = $this->container->get( $class );
			if ( $controller instanceof BaseController ) {
				$controller->register_routes();
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function controllers(): array {
		$controllers = [
			PingController::class,
			AccountController::class,
			FarmUsersController::class,
			FarmsController::class,
			FarmMediaController::class,
			FavoritesController::class,
			InquiriesController::class,
			ListingsController::class,
			MyListingsController::class,
			SavedSearchesController::class,
			FeedbackController::class,
			Admin\AdminStatsController::class,
			Admin\AdminFeedbackController::class,
			Admin\AdminUsersController::class,
			Admin\AdminFarmsController::class,
			Admin\AdminListingsController::class,
			Admin\AdminMembershipsController::class,
			Admin\AdminSettingsController::class,
			Admin\AdminTaxonomiesController::class,
			Admin\AdminImportController::class,
		];

		/**
		 * @param string[] $controllers Classes extending Rest\BaseController.
		 */
		return (array) apply_filters( 'sp/rest_controllers', $controllers );
	}
}
