<?php
/**
 * A dashboard section: one class per /members/{section}/ screen.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\RouteMatch;

interface Section {

	/**
	 * Handle a POST (form submit). Should redirect (PRG) after acting; may return to let render() show inline errors.
	 */
	public function handle( RouteMatch $match, MemberContext $context ): void;

	/**
	 * Section HTML (placed inside the dashboard layout).
	 */
	public function render( RouteMatch $match, MemberContext $context ): string;
}
