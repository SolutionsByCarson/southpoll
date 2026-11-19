<?php
/**
 * Route controller contract.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

interface Controller {

	/**
	 * Document title for the route (used before any output, in wp_head).
	 */
	public function title( RouteMatch $match ): string;

	/**
	 * Produce output. Theme routes echo only their content (the wrapper template adds header/footer);
	 * standalone and raw routes output the full response and may exit.
	 */
	public function render( RouteMatch $match ): void;
}
