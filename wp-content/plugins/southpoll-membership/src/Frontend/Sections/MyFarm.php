<?php
/**
 * /members/my-farm/ — farm page editor (owner + active managers) and the users manager (owner) / manager card.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmEditor;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class MyFarm implements Section {

	private ?WP_Error $errors = null;

	/** @var array<string, mixed>|null Submitted values re-shown after a validation error. */
	private ?array $submitted = null;

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private FarmUserRepository $farm_users,
		private MembershipPresenter $presenter,
		private FarmEditor $editor,
		private FarmRepository $farms,
		private Offerings $offerings,
		private FarmMetaRepository $meta,
		private FarmMediaRepository $media,
		private FarmVisibility $visibility,
		private FarmPresenter $farm_presenter,
		private Settings $settings,
		private Integrations $integrations
	) {}

	public const FIELDS = [ 'name', 'slogan', 'breeder_number', 'address1', 'city', 'state', 'zip', 'phone', 'email', 'website', 'owner_name', 'about', 'social', 'hours', 'offerings', 'meta', 'contact_form_enabled', '_contact_form_submitted', '_offerings_submitted' ];

	public function handle( RouteMatch $match, MemberContext $context ): void {
		$action = isset( $_POST['sp_action'] ) ? sanitize_key( wp_unslash( $_POST['sp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'save_farm' !== $action ) {
			return;
		}
		if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_farm_save' ) ) {
			$this->flash->error( __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->my_farm() );
			exit;
		}
		$in = [];
		foreach ( self::FIELDS as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$in[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field-by-field in FarmEditor
			}
		}
		$result = $this->editor->save( $context, $in );
		if ( $result instanceof WP_Error ) {
			$this->errors    = $result;
			$this->submitted = $in;
			return;
		}
		$this->flash->success( __( 'Your farm page has been saved.', 'southpoll-membership' ), '', [ [ 'label' => __( 'View farm page', 'southpoll-membership' ), 'href' => $this->urls->farm( (string) $result->slug ) ] ] );
		wp_safe_redirect( $this->urls->my_farm() );
		exit;
	}

	public function render( RouteMatch $match, MemberContext $context ): string {
		if ( ! $context->can( 'view_farm_pages' ) ) {
			$this->flash->info( $context->is_manager()
				? __( 'The farm membership is not active right now, so the farm pages are unavailable. Contact the farm owner.', 'southpoll-membership' )
				: __( 'Only farm owners and account managers can view that page.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->dashboard() );
			exit;
		}
		if ( ! $context->has_farm() ) { // staff accounts pass the capability check but have no farm of their own
			return $this->view->render( 'dashboard/no-farm', [ 'context' => $context, 'urls' => $this->urls, 'screen' => 'farm' ] );
		}
		$owner  = $this->farm_users->owner_row( $context->farm_id() );
		$editor = (string) apply_filters( 'sp/dashboard/my_farm_editor', '', $context );
		if ( '' === $editor ) {
			$editor = $this->render_editor( $context );
		}
		return $this->view->render( 'dashboard/my-farm', [
			'context'    => $context,
			'farm'       => $context->farm,
			'membership' => $this->presenter->summary( $context ),
			'users'      => $context->is_owner() ? $this->farm_users->for_farm( $context->farm_id() ) : [],
			'owner'      => $owner,
			'editor'     => $editor,
			'urls'       => $this->urls,
		] );
	}

	private function render_editor( MemberContext $context ): string {
		$farm   = $this->farms->find( $context->farm_id() ) ?? $context->farm;
		$values = [];
		foreach ( FarmEditor::TEXT_FIELDS as $key ) {
			$values[ $key ] = (string) ( $this->submitted[ $key ] ?? $farm->$key ?? '' );
		}
		$values['about']                = (string) ( $this->submitted['about'] ?? $farm->about ?? '' );
		$values['social']               = isset( $this->submitted['social'] ) ? FarmRepository::clean_social( $this->submitted['social'] ) : FarmRepository::social( $farm );
		$values['hours']                = isset( $this->submitted['hours'] ) ? FarmRepository::clean_hours( $this->submitted['hours'] ) : FarmRepository::hours( $farm );
		$values['offerings']            = isset( $this->submitted['offerings'] ) ? array_map( 'intval', (array) $this->submitted['offerings'] ) : $this->offerings->ids_for_farm( (int) $farm->id );
		$values['meta']                 = isset( $this->submitted['meta'] ) ? (array) $this->submitted['meta'] : $this->meta->values( (int) $farm->id );
		$values['contact_form_enabled'] = isset( $this->submitted ) ? ! empty( $this->submitted['contact_form_enabled'] ) : (bool) (int) $farm->contact_form_enabled;

		return $this->view->render( 'dashboard/partials/farm-editor', [
			'context'     => $context,
			'farm'        => $farm,
			'values'      => $values,
			'errors'      => $this->errors ?? new WP_Error(),
			'states'      => Str::us_states(),
			'offerings'   => $this->offerings->all(),
			'meta_fields' => $this->meta->fields(),
			'media'       => array_map( [ FarmMediaRepository::class, 'serialize' ], $this->media->for_farm( (int) $farm->id ) ),
			'logo_url'    => MediaUploader::url( $farm->logo_id ? (int) $farm->logo_id : null, 'sp-logo' ),
			'banner_url'  => MediaUploader::url( $farm->banner_id ? (int) $farm->banner_id : null, 'sp-banner' ),
			'visibility'  => $this->visibility->state( $farm, $context->membership ),
			'farm_url'    => $this->urls->farm( (string) $farm->slug ),
			'urls'        => $this->urls,
			'limits'      => [
				'image_mb'  => (float) $this->settings->get( 'farm_image_max_mb', 10 ),
				'gallery'   => (int) $this->settings->get( 'farm_gallery_max_items', 20 ),
			],
			'maps_enabled' => $this->integrations->maps_configured(),
			'geocoding'    => '' !== (string) $this->integrations->get( 'google.geocoding_key', '' ),
			'days'         => [ 'mon' => __( 'Monday', 'southpoll-membership' ), 'tue' => __( 'Tuesday', 'southpoll-membership' ), 'wed' => __( 'Wednesday', 'southpoll-membership' ), 'thu' => __( 'Thursday', 'southpoll-membership' ), 'fri' => __( 'Friday', 'southpoll-membership' ), 'sat' => __( 'Saturday', 'southpoll-membership' ), 'sun' => __( 'Sunday', 'southpoll-membership' ) ],
			'social_keys'  => [ 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'x' => 'X (Twitter)', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'other' => __( 'Other link', 'southpoll-membership' ) ],
		] );
	}
}
