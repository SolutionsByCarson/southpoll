<?php
/**
 * /members/dashboard/ — profile strip, membership banner and the home cards. Farm accounts see My listings and My farm
 * first, then Favorites and Saved searches; buyers see Favorites and Saved searches. Each card can be replaced through
 * the `sp/dashboard/home_cards` filter (keyed); the order here wins regardless of what the filter returns.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmMediaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class DashboardHome implements Section {

	public function __construct(
		private View $view,
		private Urls $urls,
		private MembershipPresenter $presenter,
		private MemberRepository $members,
		private FarmPresenter $farm_presenter,
		private FarmVisibility $visibility,
		private FarmMediaRepository $media,
		private Settings $settings
	) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$user       = wp_get_current_user();
		$membership = $this->presenter->summary( $context );
		$has_farm   = $context->can( 'view_farm_pages' ) && $context->has_farm();
		$keys       = $has_farm ? [ 'listings', 'farm', 'favorites', 'saved_searches' ] : [ 'favorites', 'saved_searches' ];
		$farm_data  = $has_farm ? $this->farm_summary( $context, $membership ) : [];
		$cards      = [];
		foreach ( $keys as $key ) {
			$cards[ $key ] = $this->view->render( 'dashboard/cards/defaults', [ 'card' => $key, 'context' => $context, 'urls' => $this->urls, 'farm_summary' => $farm_data ] );
		}
		$cards   = (array) apply_filters( 'sp/dashboard/home_cards', $cards, $context );
		$ordered = [];
		foreach ( $keys as $key ) {
			if ( isset( $cards[ $key ] ) ) {
				$ordered[ $key ] = $cards[ $key ];
			}
		}
		$ordered += $cards; // anything an add-on appended keeps its place after the standard cards
		return $this->view->render( 'dashboard/home', [
			'context'    => $context,
			'user'       => $user,
			'name'       => $this->members->display_name( $context->member, $user ),
			'membership' => $membership,
			'urls'       => $this->urls,
			'cards'      => array_filter( array_map( 'strval', $ordered ) ),
			'popup'      => $this->launch_popup( $context ),
		] );
	}

	/**
	 * S7: the soft-launch welcome popup, shown once per account per version (dismissal stored as user meta by
	 * POST /sp/v1/account/dismiss). Null when disabled or already seen.
	 *
	 * @return array{title:string, text:string, version:string, feedback:bool}|null
	 */
	public function launch_popup( MemberContext $context ): ?array {
		if ( ! $this->settings->get( 'launch_popup_enabled', false ) ) {
			return null;
		}
		$version = trim( (string) $this->settings->get( 'launch_popup_version', '' ) );
		if ( '' === $version || (string) get_user_meta( $context->user_id, \SouthPoll\Membership\Rest\AccountController::dismiss_meta_key( 'launch_popup' ), true ) === $version ) {
			return null;
		}
		return [
			'title'    => (string) $this->settings->get( 'launch_popup_title', '' ),
			'text'     => (string) $this->settings->get( 'launch_popup_text', '' ),
			'version'  => $version,
			'feedback' => (bool) $this->settings->get( 'feedback_enabled', false ),
		];
	}

	/**
	 * Everything the My Farm card summarises.
	 *
	 * @param array<string, mixed> $membership MembershipPresenter::summary()
	 * @return array<string, mixed>
	 */
	private function farm_summary( MemberContext $context, array $membership ): array {
		$farm  = $context->farm;
		$card  = $this->farm_presenter->card( $farm, $context );
		$state = $this->visibility->state( $farm );
		return [
			'card'        => $card,
			'visibility'  => $state,
			'visible'     => FarmVisibility::PUBLIC_STATE === $state,
			'membership'  => $membership,
			'photos'      => $this->media->count( (int) $farm->id ),
			'breeder'     => (string) $farm->breeder_number,
			'phone'       => (string) $farm->phone,
			'email'       => (string) $farm->email,
			'website'     => (string) $farm->website,
			'address'     => trim( (string) $farm->address1 ),
			'contact_on'  => (bool) (int) $farm->contact_form_enabled,
			'url'         => $this->urls->farm( (string) $farm->slug ),
			'missing'     => array_values( array_filter( [
				empty( $farm->logo_id ) ? __( 'a logo', 'southpoll-membership' ) : '',
				empty( $farm->banner_id ) ? __( 'a banner photo', 'southpoll-membership' ) : '',
				'' === trim( (string) $farm->about ) ? __( 'an about section', 'southpoll-membership' ) : '',
				empty( $farm->city ) ? __( 'your city', 'southpoll-membership' ) : '',
				empty( $card['offerings'] ) ? __( 'what you offer', 'southpoll-membership' ) : '',
			] ) ),
		];
	}
}
