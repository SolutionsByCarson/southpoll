<?php
/**
 * Template renderer with child-theme override support:
 *   {child theme}/southpoll-membership/{template}.php  →  {plugin}/templates/{template}.php
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend;

final class View {

	/**
	 * Absolute path to a template (theme override first).
	 */
	public function locate( string $template ): string {
		$template = ltrim( str_replace( [ '..', "\0" ], '', $template ), '/' );
		$theme    = locate_template( [ 'southpoll-membership/' . $template . '.php' ] );
		if ( '' !== $theme ) {
			return $theme;
		}
		return SP_PLUGIN_DIR . 'templates/' . $template . '.php';
	}

	/**
	 * Render a template to a string. $data keys become local variables; $view is always available.
	 *
	 * @param array<string, mixed> $data
	 */
	public function render( string $template, array $data = [] ): string {
		$file = $this->locate( $template );
		if ( ! is_file( $file ) ) {
			return '<!-- template not found: ' . esc_html( $template ) . ' -->';
		}
		ob_start();
		$this->include_template( $file, $data );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function display( string $template, array $data = [] ): void {
		echo $this->render( $template, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape their own output
	}

	/**
	 * Isolated include scope.
	 *
	 * @param array<string, mixed> $data
	 */
	private function include_template( string $file, array $data ): void {
		$view = $this;
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}
}
