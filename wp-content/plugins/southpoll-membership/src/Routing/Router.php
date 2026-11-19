<?php
/**
 * Rewrite rules, request translation, 404 prevention, and dispatch for every plugin route.
 *
 * Theme routes are served through a "host page" so WordPress runs a normal singular page query: the
 * theme header/footer (Pro's Cornerstone header is assigned to singular pages), Yoast, and body classes
 * all behave. When no host page exists a virtual page object is injected instead.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

use SouthPoll\Membership\Core\Container;
use SouthPoll\Membership\Core\Plugin;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Frontend\View;
use WP_Post;

final class Router implements Service {

	private ?RouteMatch $match = null;

	private bool $virtual = false;

	public function __construct(
		private Settings $settings,
		private Urls $urls,
		private Container $container,
		private View $view
	) {}

	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ], 10 );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'request', [ $this, 'translate_request' ] );
		add_action( 'parse_request', [ $this, 'dispatch_raw' ], 1 );
		add_filter( 'pre_handle_404', [ $this, 'prevent_404' ], 10, 2 );
		add_filter( 'the_posts', [ $this, 'inject_virtual_page' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'dispatch_standalone' ], 1 );
		add_filter( 'template_include', [ $this, 'theme_template' ], 99 );
		add_filter( 'document_title_parts', [ $this, 'document_title' ], 20 );
		add_filter( 'pre_get_document_title', [ $this, 'pre_document_title' ], 99 ); // Yoast short-circuits at 15
		add_filter( 'wpseo_title', [ $this, 'pre_document_title' ], 99 );
		add_filter( 'wpseo_opengraph_title', [ $this, 'pre_document_title' ], 99 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
		add_filter( 'sp/member_dashboard_url', fn() => $this->urls->dashboard() );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'settings_changed' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ rules */

	public function add_rewrite_rules(): void {
		foreach ( $this->compiled_rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * @return array<string, string> regex => index.php query string
	 */
	public function compiled_rules(): array {
		$rules = [];
		foreach ( Routes::all() as $name => $spec ) {
			$pattern = preg_replace_callback(
				'/\{([a-z]+)\}/',
				fn( array $m ) => preg_quote( $this->urls->base( $m[1] ), '#' ),
				(string) $spec['pattern']
			);
			$regex = '^' . $pattern . '/?$';
			$query = 'index.php?sp_route=' . $name;
			foreach ( (array) ( $spec['params'] ?? [] ) as $i => $var ) {
				$query .= '&' . $var . '=$matches[' . ( $i + 1 ) . ']';
			}
			$rules[ $regex ] = $query;
		}
		return $rules;
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function add_query_vars( array $vars ): array {
		return array_merge( $vars, Routes::QUERY_VARS );
	}

	/**
	 * Flush rules when route bases change.
	 */
	public function settings_changed( mixed $old, mixed $new ): void {
		$old_routes = is_array( $old ) ? ( $old['routes'] ?? [] ) : [];
		$new_routes = is_array( $new ) ? ( $new['routes'] ?? [] ) : [];
		if ( $old_routes !== $new_routes ) {
			Plugin::request_rewrite_flush();
		}
	}

	/* ---------------------------------------------------------------- request */

	/**
	 * Build the RouteMatch and, for theme routes, point the main query at the host page.
	 *
	 * @param array<string, mixed> $vars
	 * @return array<string, mixed>
	 */
	public function translate_request( array $vars ): array {
		$name = isset( $vars['sp_route'] ) ? sanitize_key( (string) $vars['sp_route'] ) : '';
		if ( '' === $name ) {
			return $vars;
		}
		$spec = Routes::get( $name );
		if ( null === $spec ) {
			unset( $vars['sp_route'] );
			return $vars;
		}

		$params = [];
		foreach ( Routes::QUERY_VARS as $var ) {
			if ( isset( $vars[ $var ] ) && 'sp_route' !== $var ) {
				$params[ $var ] = is_scalar( $vars[ $var ] ) ? sanitize_text_field( (string) $vars[ $var ] ) : '';
			}
		}
		// Alias (paged variants) resolve to their parent route name but keep params.
		$resolved_name = (string) ( $spec['alias'] ?? $name );
		$resolved_spec = Routes::get( $resolved_name ) ?? $spec;
		$this->match   = new RouteMatch( $resolved_name, $resolved_spec, $params );

		if ( Routes::KIND_THEME === $this->match->kind() ) {
			$host_id = $this->host_page_id( $this->match->host() );
			// Drop anything WP may have parsed and force a clean singular page query.
			foreach ( [ 'pagename', 'name', 'page', 'paged', 'attachment', 'post_type', 'error' ] as $drop ) {
				unset( $vars[ $drop ] );
			}
			if ( $host_id > 0 ) {
				$vars['page_id'] = $host_id;
			} else {
				$this->virtual   = true;
				$vars['page_id'] = PHP_INT_MAX; // no such page; inject_virtual_page() fills the query
			}
		}
		return $vars;
	}

	/**
	 * Resolve the host page for a theme route: explicit setting, else page whose slug equals the route base.
	 */
	public function host_page_id( string $host ): int {
		$setting = (int) $this->settings->get( 'page_' . $host, 0 );
		if ( $setting > 0 && 'publish' === get_post_status( $setting ) ) {
			return $setting;
		}
		$base = match ( $host ) {
			'directory'   => $this->urls->base( 'members' ),
			'classifieds' => $this->urls->base( 'classifieds' ),
			'login'       => $this->urls->base( 'members' ),
			default       => '',
		};
		if ( '' === $base ) {
			return 0;
		}
		$page = get_page_by_path( $base, OBJECT, 'page' );
		return ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? (int) $page->ID : 0;
	}

	/**
	 * Keep WordPress from sending a 404 for plugin routes that have no post behind them.
	 */
	public function prevent_404( bool $preempt, \WP_Query $query ): bool {
		if ( $query->is_main_query() && null !== $this->match ) {
			return true;
		}
		return $preempt;
	}

	/**
	 * When no host page exists, satisfy the main query with an in-memory page so the theme renders normally.
	 *
	 * @param WP_Post[] $posts
	 * @return WP_Post[]
	 */
	public function inject_virtual_page( array $posts, \WP_Query $query ): array {
		if ( ! $this->virtual || ! $query->is_main_query() || null === $this->match ) {
			return $posts;
		}
		$now  = current_time( 'mysql' );
		$post = new WP_Post( (object) [
			'ID'                    => 0,
			'post_author'           => 0,
			'post_date'             => $now,
			'post_date_gmt'         => get_gmt_from_date( $now ),
			'post_content'          => '',
			'post_title'            => $this->title(),
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $this->match->name,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $now,
			'post_modified_gmt'     => get_gmt_from_date( $now ),
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => home_url( '/' ),
			'menu_order'            => 0,
			'post_type'             => 'page',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		] );

		$query->is_404      = false;
		$query->is_page     = true;
		$query->is_singular = true;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->found_posts = 1;
		$query->post_count  = 1;
		$query->queried_object    = $post;
		$query->queried_object_id = 0;
		return [ $post ];
	}

	/* --------------------------------------------------------------- dispatch */

	public function current(): ?RouteMatch {
		return $this->match;
	}

	public function is_route( string ...$names ): bool {
		return null !== $this->match && $this->match->is( ...$names );
	}

	/**
	 * Raw routes run before the main query (webhooks, logout).
	 */
	public function dispatch_raw( \WP $wp ): void {
		$name = isset( $wp->query_vars['sp_route'] ) ? sanitize_key( (string) $wp->query_vars['sp_route'] ) : '';
		if ( '' === $name ) {
			return;
		}
		$spec = Routes::get( $name );
		if ( null === $spec || Routes::KIND_RAW !== ( $spec['kind'] ?? '' ) ) {
			return;
		}
		$params = [];
		foreach ( Routes::QUERY_VARS as $var ) {
			if ( isset( $wp->query_vars[ $var ] ) && 'sp_route' !== $var ) {
				$params[ $var ] = sanitize_text_field( (string) $wp->query_vars[ $var ] );
			}
		}
		$this->match = new RouteMatch( $name, $spec, $params );
		nocache_headers();
		$this->controller()->render( $this->match );
		exit;
	}

	/**
	 * Standalone routes output the whole document and exit.
	 */
	public function dispatch_standalone(): void {
		if ( null === $this->match || Routes::KIND_STANDALONE !== $this->match->kind() ) {
			return;
		}
		// The dashboard is its own chrome: no WP admin bar (markup, body class, or the 32px html bump).
		add_filter( 'show_admin_bar', '__return_false', 99 );
		remove_action( 'wp_head', '_admin_bar_bump_cb' );
		nocache_headers();
		status_header( 200 );
		$this->controller()->render( $this->match );
		exit;
	}

	/**
	 * Theme routes swap the template for the plugin wrapper (header + content + footer).
	 */
	public function theme_template( string $template ): string {
		if ( null === $this->match || Routes::KIND_THEME !== $this->match->kind() ) {
			return $template;
		}
		nocache_headers();
		status_header( 200 );
		// Controllers may set SEO/robots/status (or redirect) before the theme header prints.
		$controller = $this->controller();
		if ( method_exists( $controller, 'prepare' ) ) {
			$controller->prepare( $this->match );
		}
		return $this->view->locate( 'theme/page' );
	}

	/**
	 * Called by templates/theme/page.php to print the route content.
	 */
	public function render_current_content(): void {
		if ( null === $this->match ) {
			return;
		}
		$this->controller()->render( $this->match );
	}

	public function title(): string {
		if ( null === $this->match ) {
			return '';
		}
		$title = $this->controller()->title( $this->match );
		return '' !== $title ? $title : (string) ( $this->match->spec['title'] ?? '' );
	}

	/**
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public function document_title( array $parts ): array {
		if ( null !== $this->match ) {
			$title = $this->title();
			if ( '' !== $title ) {
				$parts['title'] = $title;
			}
		}
		return $parts;
	}

	/**
	 * Full document title for routes (overrides Yoast / host page title).
	 */
	public function pre_document_title( string $title ): string {
		if ( null === $this->match ) {
			return $title;
		}
		$route_title = $this->title();
		if ( '' === $route_title ) {
			return $title;
		}
		$sep = (string) apply_filters( 'document_title_separator', '-' );
		return $route_title . ' ' . $sep . ' ' . get_bloginfo( 'name' );
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		if ( null !== $this->match ) {
			$classes[] = 'sp-route';
			$classes[] = 'sp-route-' . $this->match->name;
		}
		return $classes;
	}

	private function controller(): Controller {
		$class = $this->match ? $this->match->controller() : '';
		$obj   = '' !== $class ? $this->container->get( $class ) : null;
		if ( ! $obj instanceof Controller ) {
			throw new \RuntimeException( 'No controller for route ' . ( $this->match ? $this->match->name : '(none)' ) );
		}
		return $obj;
	}
}
