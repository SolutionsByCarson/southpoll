<?php
/**
 * /farms/{slug}/ — public farm page. Hidden farms (membership not active) show a minimal "not active" page so
 * links never 404; the farm's own people and staff see the full page with a notice (roadmap §3.1).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Contact\InquiryService;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class FarmPageController implements Controller {

	private ?object $farm = null;

	private bool $looked_up = false;

	public function __construct(
		private View $view,
		private Urls $urls,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmPresenter $presenter,
		private Offerings $offerings,
		private FarmMetaRepository $meta,
		private FarmMediaRepository $media,
		private MembershipRepository $memberships,
		private FarmUserRepository $farm_users,
		private ContextProvider $contexts,
		private Recaptcha $recaptcha,
		private Settings $settings,
		private Integrations $integrations
	) {}

	private function farm( RouteMatch $match ): ?object {
		if ( ! $this->looked_up ) {
			$this->looked_up = true;
			$slug            = sanitize_title( $match->slug() );
			$this->farm      = '' !== $slug ? $this->farms->find_by_slug( $slug ) : null;
		}
		return $this->farm;
	}

	public function title( RouteMatch $match ): string {
		$farm = $this->farm( $match );
		if ( ! $farm ) {
			return __( 'Farm not found', 'southpoll-membership' );
		}
		$location = FarmPresenter::location( $farm );
		return (string) $farm->name . ( $location ? ' – ' . $location : '' );
	}

	public function render( RouteMatch $match ): void {
		$farm = $this->farm( $match );
		$ctx  = $this->contexts->current();
		if ( ! $farm ) {
			status_header( 404 );
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			$this->view->display( 'farm/not-found', [ 'urls' => $this->urls, 'slug' => $match->slug() ] );
			return;
		}
		$membership = $this->memberships->for_farm( (int) $farm->id );
		$state      = $this->visibility->state( $farm, $membership );
		$is_public  = FarmVisibility::PUBLIC_STATE === $state;
		$preview    = ! $is_public && $this->visibility->can_preview( $farm, $ctx );
		if ( ! $is_public && ! $preview ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			$this->view->display( 'farm/unavailable', [ 'farm' => $farm, 'state' => $state, 'urls' => $this->urls, 'context' => $ctx ] );
			return;
		}
		if ( $preview ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}

		$card        = $this->presenter->card( $farm, $ctx );
		$exact       = $this->presenter->exact_coordinates( $ctx );
		$see_contact = $ctx->can( 'view_contact_info' );
		$coords      = $this->presenter->coordinates( $farm, $exact );
		$form_on     = (bool) (int) $farm->contact_form_enabled && $is_public;
		$owner       = $this->farm_users->owner_row( (int) $farm->id );
		if ( $form_on && ! $ctx->is_logged_in() ) {
			$this->recaptcha->enqueue();
		}
		/**
		 * Phase 3 injects the farm's active listings here.
		 *
		 * @param string $html
		 * @param object $farm
		 */
		$listings_html = (string) apply_filters( 'sp/farm/listings_html', '', $farm, $ctx );
		do_action( 'sp/farm/viewed', $farm, $ctx );

		$this->view->display( 'farm/single', [
			'farm'          => $farm,
			'card'          => $card,
			'context'       => $ctx,
			'urls'          => $this->urls,
			'state'         => $state,
			'is_public'     => $is_public,
			'preview'       => $preview,
			'is_mine'       => $ctx->has_farm() && $ctx->farm_id() === (int) $farm->id,
			'offerings'     => $this->offerings->for_farm( (int) $farm->id ),
			'meta_rows'     => $this->meta->display_rows( (int) $farm->id ),
			'media'         => array_map( [ FarmMediaRepository::class, 'serialize' ], $this->media->for_farm( (int) $farm->id ) ),
			'hours_rows'    => FarmRepository::has_hours( $farm ) ? FarmPresenter::hours_rows( $farm ) : [],
			'social'        => FarmPresenter::social_links( $farm ),
			'see_contact'   => $see_contact,
			'contact'       => $see_contact ? [
				'owner_name' => (string) $farm->owner_name ?: ( $owner ? (string) $owner->display_name : '' ),
				'phone'      => (string) $farm->phone,
				'email'      => (string) $farm->email ?: ( $owner ? (string) $owner->email : '' ),
				'website'    => (string) $farm->website,
				'address'    => FarmRepository::address_line( $farm, true ),
			] : [ 'address' => FarmRepository::address_line( $farm, false ) ],
			'form_on'       => $form_on,
			'form'          => [
				'recaptcha' => $this->recaptcha->enabled() && ! $ctx->is_logged_in(),
				'action'    => InquiryService::RECAPTCHA_ACTION,
				'honeypot'  => Honeypot::FIELD,
				'max_chars' => (int) $this->settings->get( 'inquiry_max_message_chars', 2000 ),
				'user'      => $ctx->is_logged_in() ? wp_get_current_user() : null,
			],
			'directions'    => $ctx->is_logged_in() ? $this->presenter->directions_url( $farm, $exact ) : '', // members only
			'map'           => $coords && $this->integrations->maps_configured() ? [ 'lat' => $coords['lat'], 'lng' => $coords['lng'], 'exact' => $exact ] : null,
			'share'         => $this->presenter->share( $farm ),
			'listings_html' => $listings_html,
			'login_url'     => $this->urls->login( $this->urls->farm( (string) $farm->slug ) ),
			'register_url'  => $this->urls->register(),
		] );
	}
}
