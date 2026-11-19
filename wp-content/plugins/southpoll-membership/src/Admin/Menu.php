<?php
/**
 * wp-admin menu: Dashboard, Users, Farms, Listings, Memberships, Taxonomies, Meta Fields, Settings, Integrations,
 * Import / Export, Status. (Farms\Offerings and Listings\ListingTypes add links to the native term screens.)
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin;

use SouthPoll\Membership\Admin\Pages\DashboardPage;
use SouthPoll\Membership\Admin\Pages\FarmsPage;
use SouthPoll\Membership\Admin\Pages\ImportExportPage;
use SouthPoll\Membership\Admin\Pages\IntegrationsPage;
use SouthPoll\Membership\Admin\Pages\ListingsPage;
use SouthPoll\Membership\Admin\Pages\MembershipsPage;
use SouthPoll\Membership\Admin\Pages\MetaFieldsPage;
use SouthPoll\Membership\Admin\Pages\SettingsPage;
use SouthPoll\Membership\Admin\Pages\StatusPage;
use SouthPoll\Membership\Admin\Pages\FeedbackPage;
use SouthPoll\Membership\Admin\Pages\TaxonomiesPage;
use SouthPoll\Membership\Admin\Pages\UsersPage;
use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Migrator;
use SouthPoll\Membership\Core\Service;

final class Menu implements Service {

	public const SLUG = 'southpoll';

	public function __construct(
		private DashboardPage $dashboard,
		private UsersPage $users,
		private FarmsPage $farms,
		private ListingsPage $listings,
		private MembershipsPage $memberships,
		private TaxonomiesPage $taxonomies,
		private MetaFieldsPage $meta_fields,
		private SettingsPage $settings,
		private IntegrationsPage $integrations,
		private ImportExportPage $import_export,
		private StatusPage $status,
		private FeedbackPage $feedback
	) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_notices', [ $this, 'migration_notice' ] );
		add_action( 'admin_init', [ $this->status, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->memberships, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->meta_fields, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->settings, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->integrations, 'handle_actions' ] );
		add_action( 'admin_post_sp_export', [ $this->import_export, 'handle_export' ] );
		add_action( 'admin_post_sp_template', [ $this->import_export, 'handle_template' ] );
		add_action( 'admin_post_' . FeedbackPage::EXPORT, [ $this->feedback, 'handle_export' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'SouthPoll Membership', 'southpoll-membership' ),
			__( 'SouthPoll', 'southpoll-membership' ),
			Capabilities::CAP_MANAGE,
			self::SLUG,
			[ $this->dashboard, 'render' ],
			'dashicons-groups',
			26
		);
		$items = [
			[ __( 'Dashboard', 'southpoll-membership' ), DashboardPage::SLUG, Capabilities::CAP_MANAGE, $this->dashboard ],
			[ __( 'Users', 'southpoll-membership' ), UsersPage::SLUG, Capabilities::CAP_MANAGE, $this->users ],
			[ __( 'Farms', 'southpoll-membership' ), FarmsPage::SLUG, Capabilities::CAP_MANAGE, $this->farms ],
			[ __( 'Listings', 'southpoll-membership' ), ListingsPage::SLUG, Capabilities::CAP_MANAGE, $this->listings ],
			[ __( 'Memberships', 'southpoll-membership' ), MembershipsPage::SLUG, Capabilities::CAP_MANAGE, $this->memberships ],
			[ __( 'Taxonomies', 'southpoll-membership' ), TaxonomiesPage::SLUG, Capabilities::CAP_MANAGE, $this->taxonomies ],
			[ __( 'Meta Fields', 'southpoll-membership' ), MetaFieldsPage::SLUG, Capabilities::CAP_MANAGE, $this->meta_fields ],
			[ __( 'Settings', 'southpoll-membership' ), SettingsPage::SLUG, Capabilities::CAP_MANAGE_SETTINGS, $this->settings ],
			[ __( 'Integrations', 'southpoll-membership' ), IntegrationsPage::SLUG, Capabilities::CAP_MANAGE_INTEGRATIONS, $this->integrations ],
			[ __( 'Import / Export', 'southpoll-membership' ), ImportExportPage::SLUG, Capabilities::CAP_MANAGE, $this->import_export ],
			[ __( 'Status', 'southpoll-membership' ), StatusPage::SLUG, Capabilities::CAP_MANAGE, $this->status ],
			[ __( 'Feedback', 'southpoll-membership' ), FeedbackPage::SLUG, Capabilities::CAP_MANAGE, $this->feedback ],
		];
		foreach ( $items as [ $label, $slug, $cap, $page ] ) {
			add_submenu_page( self::SLUG, $label . ' — SouthPoll', $label, $cap, $slug, [ $page, 'render' ] );
		}
	}

	public function migration_notice(): void {
		$error = get_option( Migrator::OPTION_ERROR );
		if ( $error && current_user_can( Capabilities::CAP_MANAGE ) ) {
			echo '<div class="notice notice-error"><p><strong>SouthPoll Membership:</strong> ' . esc_html( (string) $error ) . '</p></div>';
		}
	}
}
