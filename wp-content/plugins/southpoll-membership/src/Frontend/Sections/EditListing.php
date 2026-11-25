<?php
/**
 * /members/edit-listing/?id=N — the listing editor (title, categories, price, status, copy, grouped meta fields,
 * featured image + gallery via REST). Save / Publish / Pause / Renew / Delete.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\MediaUploader;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\Listing;
use SouthPoll\Membership\Listings\ListingEditor;
use SouthPoll\Membership\Listings\ListingLimits;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Rest\MyListingsController;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;

final class EditListing implements Section {

	private ?WP_Error $errors = null;

	/** @var array<string, mixed>|null */
	private ?array $submitted = null;

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private ListingRepository $listings,
		private ListingService $service,
		private ListingEditor $editor,
		private ListingLimits $limits,
		private ListingTypes $types,
		private MetaFieldRepository $fields,
		private MyListingsController $rest,
		private Settings $settings
	) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {
		$action = isset( $_POST['sp_action'] ) ? sanitize_key( wp_unslash( $_POST['sp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'save_listing' !== $action ) {
			return;
		}
		$id = isset( $_POST['listing_id'] ) ? (int) $_POST['listing_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_listing_save_' . $id ) ) {
			$this->flash->error( __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->edit_listing( $id ) );
			exit;
		}
		$listing = $this->listings->find( $id );
		if ( ! $listing || ! $this->service->owns( $context, $listing ) ) {
			$this->flash->error( __( 'Listing not found.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->my_listings() );
			exit;
		}
		$submit = isset( $_POST['sp_submit'] ) ? sanitize_key( wp_unslash( $_POST['sp_submit'] ) ) : 'save'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$in     = [];
		foreach ( [ 'title', 'excerpt', 'content', 'price', 'price_label', 'categories', 'status', 'meta', 'featured_id' ] as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$in[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field-by-field in ListingEditor
			}
		}
		if ( ! isset( $in['categories'] ) ) {
			$in['categories'] = [];
		}
		if ( 'delete' === $submit ) {
			$res = $this->service->delete( $context, $listing );
			$this->flash->success( __( 'Listing deleted.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->my_listings() );
			exit;
		}
		$action_map = [ 'publish' => 'publish', 'draft' => 'draft', 'renew' => 'save', 'save' => 'save' ];
		$result     = $this->editor->save( $context, $listing, $in, $action_map[ $submit ] ?? 'save' );
		if ( ! is_wp_error( $result ) && 'renew' === $submit ) {
			$result = $this->service->renew( $context, $result );
		}
		if ( $result instanceof WP_Error ) {
			$this->errors    = $result;
			$this->submitted = $in;
			if ( in_array( $result->get_error_code(), [ 'sp_limit', 'sp_membership_inactive', 'sp_forbidden' ], true ) ) {
				$this->flash->error( $result->get_error_message() );
			}
			return;
		}
		$this->flash->success( match ( $submit ) {
			'publish' => 'publish' === $result->post_status ? __( 'Your listing is live.', 'southpoll-membership' ) : __( 'Saved. The listing is still a draft and is not visible to buyers.', 'southpoll-membership' ),
			'draft'   => __( 'Listing paused and hidden from the classifieds. Press Publish to show it again.', 'southpoll-membership' ),
			'renew'   => sprintf( /* translators: %s: date */ __( 'Renewed — your listing now runs until %s.', 'southpoll-membership' ), \SouthPoll\Membership\Support\Dates::display( (string) $result->expires_at ) ),
			default   => 'publish' === $result->post_status ? __( 'Listing saved.', 'southpoll-membership' ) : __( 'Draft saved. It is not visible to buyers until you press Publish.', 'southpoll-membership' ),
		}, '', 'publish' === $result->post_status ? [ [ 'label' => __( 'View listing', 'southpoll-membership' ), 'href' => $this->urls->listing( $result->slug ) ] ] : [] );
		wp_safe_redirect( $this->urls->edit_listing( $result->id ) );
		exit;
	}

	public function render( RouteMatch $match, MemberContext $context ): string {
		if ( ! $context->can( 'manage_listings' ) || ( ! $context->has_farm() && ! $context->is_staff() ) ) {
			$this->flash->info( __( 'Only farm owners and account managers with an active membership can edit listings.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->dashboard() );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$raw_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$renew  = ! empty( $_GET['renew'] );
		// phpcs:enable
		if ( 'new' === $raw_id ) {
			if ( ! $context->has_farm() ) {
				$this->flash->info( __( 'Staff accounts have no farm of their own; open a listing from SouthPoll → Listings in wp-admin.', 'southpoll-membership' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=southpoll-listings' ) );
				exit;
			}
			$draft = $this->service->create_draft( $context );
			if ( is_wp_error( $draft ) ) {
				$this->flash->error( $draft->get_error_message() );
				wp_safe_redirect( $this->urls->my_listings() );
				exit;
			}
			wp_safe_redirect( $this->urls->edit_listing( $draft->id ) );
			exit;
		}
		$listing = $this->listings->find( (int) $raw_id );
		if ( ! $listing || ! $this->service->owns( $context, $listing ) ) {
			$this->flash->error( __( 'Listing not found.', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->my_listings() );
			exit;
		}
		$values = [
			'title'       => (string) ( $this->submitted['title'] ?? ( $listing->is_untitled() ? '' : $listing->title ) ), // the placeholder title shows as an empty box (feedback round 1, F1.3)
			'excerpt'     => (string) ( $this->submitted['excerpt'] ?? $listing->excerpt ),
			'content'     => (string) ( $this->submitted['content'] ?? $listing->content ),
			'price'       => isset( $this->submitted['price'] ) ? (string) $this->submitted['price'] : ( null !== $listing->price_cents ? number_format( $listing->price_cents / 100, 2, '.', '' ) : '' ),
			'price_label' => (string) ( $this->submitted['price_label'] ?? $listing->price_label ),
			'categories'  => isset( $this->submitted['categories'] ) ? array_map( 'intval', (array) $this->submitted['categories'] ) : $listing->category_ids(),
			'status'      => (string) ( $this->submitted['status'] ?? $listing->status ),
			'meta'        => isset( $this->submitted['meta'] ) ? (array) $this->submitted['meta'] : $listing->meta,
		];
		$is_live = 'publish' === $listing->post_status;
		// Status only applies to a live listing. A draft / paused / expired listing has no status select: it goes live
		// as "Available" (or its last active status) when Publish is pressed, so "Inactive" can never block publishing.
		if ( ! $is_live && ! in_array( $values['status'], ListingTypes::ACTIVE_STATUSES, true ) ) {
			$values['status'] = 'available';
		} elseif ( 'inactive' === $values['status'] && 'draft' !== $listing->post_status ) {
			$values['status'] = 'available';
		}
		$groups = [];
		foreach ( $this->fields->groups( 'listing' ) as $group ) {
			$groups[] = [
				'id'         => (int) $group->id,
				'title'      => (string) $group->title,
				'categories' => $group->category_term_ids,
				'fields'     => $this->fields->fields_for_group( (int) $group->id ),
			];
		}
		$expired  = ListingTypes::STATUS_EXPIRED === $listing->post_status;
		$expiring = $is_live && null !== $listing->days_left() && $listing->days_left() <= (int) $this->settings->get( 'listing_renewal_reminder_days', 7 );
		$limits   = $this->limits->summary( $listing->farm_id );
		// Why Publish is unavailable ('' = it is available): membership not active, or the active-listing limit is used up.
		$publish_block = '';
		if ( ! $context->can( 'publish_listing' ) ) {
			$publish_block = 'membership';
		} elseif ( ! $is_live && ! $this->limits->can_publish_another( $listing->farm_id, $listing->id ) ) {
			$publish_block = 'limit';
		}
		return $this->view->render( 'dashboard/edit-listing', [
			'context'      => $context,
			'listing'      => $listing,
			'values'       => $values,
			'errors'       => $this->errors ?? new WP_Error(),
			'categories'   => $this->types->categories(),
			'groups'       => $groups,
			'statuses'     => ListingTypes::selectable_status_labels(),
			'featured_url' => MediaUploader::url( $listing->featured_id, 'sp-card' ),
			'gallery'      => $this->rest->items( $listing ),
			'limits'       => $limits,
			'can_publish'  => '' === $publish_block,
			'publish_block' => $publish_block,
			'can_renew'    => $context->can( 'publish_listing' ),
			'is_live'      => $is_live,
			'is_expired'   => $expired,
			'renew_mode'   => $renew || $expired || $expiring,
			'view_url'     => $this->urls->listing( $listing->slug ),
			'urls'         => $this->urls,
			'max_images'   => (int) $this->settings->get( 'listing_max_images', 10 ),
			'max_mb'       => (float) $this->settings->get( 'listing_max_image_mb', 10 ),
			'duration'     => $this->service->duration_days(),
		] );
	}
}
