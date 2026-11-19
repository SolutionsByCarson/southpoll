<?php
/**
 * The matched route for the current request.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

final class RouteMatch {

	/**
	 * @param string               $name   Route name (see Routes::all()).
	 * @param array<string, mixed> $spec   Route definition.
	 * @param array<string, mixed> $params Captured params (sp_slug, sp_category, sp_token, sp_page, sp_result …).
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $spec,
		public readonly array $params = []
	) {}

	public function kind(): string {
		return (string) ( $this->spec['kind'] ?? Routes::KIND_THEME );
	}

	public function controller(): string {
		return (string) ( $this->spec['controller'] ?? '' );
	}

	public function host(): string {
		return (string) ( $this->spec['host'] ?? '' );
	}

	public function param( string $key, mixed $default = null ): mixed {
		return $this->params[ $key ] ?? $default;
	}

	public function slug(): string {
		return (string) $this->param( 'sp_slug', '' );
	}

	public function page(): int {
		return max( 1, (int) $this->param( 'sp_page', 1 ) );
	}

	public function is( string ...$names ): bool {
		return in_array( $this->name, $names, true );
	}
}
