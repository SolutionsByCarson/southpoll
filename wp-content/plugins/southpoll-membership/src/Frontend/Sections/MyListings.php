<?php
/**
 * /members/my-listings/ — count cards, live table of the farm's listings, quick actions (REST), "new listing".
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Rest\MyListingsController;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class MyListings implements Section {

	public function __construct( private View $view, private Urls $urls, private Flash $flash, private ListingService $service, private MyListingsController $rest, private MembershipPresenter $presenter ) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {
		$action = isset( $_POST['sp_action'] ) ? sanitize_key( wp_unslash( $_POST['sp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'create' !== $action ) {
			return;
		}
		if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_listing_create' ) ) {
			$this->flash->error( __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->my_listings() );
			exit;
		}
		$listing = $this->service->create_draft( $context );
		if ( is_wp_error( $listing ) ) {
			$this->flash->error( $listing->get_error_message() );
			wp_safe_redirect( $this->urls->my_listings() );
			exit;
		}
		wp_safe_redirect( $this->urls->edit_listing( $listing->id ) );
		exit;
	}

	public function render( RouteMatch $match, MemberContext $context ): string {
		if ( ! $context->can( 'view_farm_pages' ) || ! $context->has_farm() ) {
			$this->flash->info( $context->is_manager()
				? __( 'The farm membership is not active right now, so the listing pages are unavailable. Contact the farm owner.', 'southpoll-membership' )
				: __( 'Only farm owners and account managers can manage listings.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->dashboard() );
			exit;
		}
		return $this->view->render( 'dashboard/my-listings', [
			'context'    => $context,
			'farm'       => $context->farm,
			'membership' => $this->presenter->summary( $context ),
			'payload'    => $this->rest->payload(),
			'statuses'   => ListingTypes::selectable_status_labels(),
			'urls'       => $this->urls,
		] );
	}
}
