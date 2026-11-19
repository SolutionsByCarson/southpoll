<?php
/**
 * Service contract: anything the Plugin boots by calling register() to add its hooks.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

interface Service {

	/**
	 * Attach WordPress hooks. Called once during boot; must be idempotent and cheap.
	 */
	public function register(): void;
}
