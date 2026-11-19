<?php
/**
 * Plugin root: owns the container and the boot sequence.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use SouthPoll\Membership\Admin\Menu;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Engagement\AlertDispatcher;
use SouthPoll\Membership\Engagement\Favorites;
use SouthPoll\Membership\Engagement\SavedSearchService;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\GeoService;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\Shortcodes;
use SouthPoll\Membership\Listings\ListingCron;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Listings\ListingVisibility;
use SouthPoll\Membership\Members\UserGuards;
use SouthPoll\Membership\Memberships\MembershipCron;
use SouthPoll\Membership\Rest\Api;
use SouthPoll\Membership\Routing\Redirects;
use SouthPoll\Membership\Routing\Router;

final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->container->instance( self::class, $this );
		$this->container->instance( Container::class, $this->container );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Shortcut for container()->get().
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	/**
	 * Boot once on plugins_loaded: translations, migrations, then every service's hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'southpoll-membership', false, dirname( SP_PLUGIN_BASENAME ) . '/languages' );

		$this->get( Migrator::class )->maybe_migrate();

		foreach ( $this->services() as $class ) {
			$service = $this->get( $class );
			if ( $service instanceof Service ) {
				$service->register();
			}
		}

		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 99 );

		/**
		 * Fires after every service has registered its hooks.
		 *
		 * @param Plugin $plugin
		 */
		do_action( 'sp/booted', $this );
	}

	/**
	 * Ordered list of services to boot. Filterable so add-ons can register their own.
	 *
	 * @return string[]
	 */
	private function services(): array {
		$services = [
			Capabilities::class,
			UserGuards::class,
			Cron::class,
			Offerings::class,
			ListingTypes::class,
			MediaUploader::class,
			Router::class,
			Redirects::class,
			Assets::class,
			Api::class,
			Mailer::class,
			MembershipCron::class,
			\SouthPoll\Membership\Memberships\MembershipEvents::class,
			\SouthPoll\Membership\Memberships\RegistryDigest::class,
			ListingCron::class,
			GeoService::class,
			FarmVisibility::class,
			ListingVisibility::class,
			Favorites::class,
			SavedSearchService::class,
			AlertDispatcher::class,
			Shortcodes::class,
			Menu::class,
		];

		/**
		 * @param string[] $services Fully qualified class names implementing Service.
		 */
		return (array) apply_filters( 'sp/services', $services );
	}

	/**
	 * Flush rewrite rules once after activation or when a service requests it (option flag).
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( 'yes' === get_option( 'sp_flush_rewrite' ) ) {
			flush_rewrite_rules( false );
			update_option( 'sp_flush_rewrite', 'no', false );
		}
	}

	public static function request_rewrite_flush(): void {
		update_option( 'sp_flush_rewrite', 'yes', false );
	}
}
