<?php
/**
 * Wires listings into the rest of the plugin: membership lapse/restore, the directory's "stock available" filter and
 * options, the farm page's listings section, listing favorites, the dashboard "My listings" card, and the
 * listing-published notifications to the farm and the office.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\ListingPublishedEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;

final class ListingVisibility implements Service {

	public function __construct(
		private ListingRepository $listings,
		private ListingService $service,
		private ListingPresenter $presenter,
		private ListingLimits $limits,
		private ListingTypes $types,
		private FarmRepository $farms,
		private Notifier $notifier,
		private View $view,
		private Urls $urls,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( 'sp/farm/hidden', [ $this, 'on_farm_hidden' ], 10, 2 );
		add_action( 'sp/farm/shown', [ $this, 'on_farm_shown' ], 10, 1 );
		add_filter( 'sp/directory/farm_ids_with_stock', [ $this, 'farm_ids_with_stock' ], 10, 2 );
		add_filter( 'sp/directory/stock_options', [ $this, 'stock_options' ] );
		add_filter( 'sp/farm/listings_html', [ $this, 'farm_listings_html' ], 10, 3 );
		add_filter( 'sp/favorites/listing_items', [ $this, 'favorite_listing_items' ], 10, 3 );
		add_filter( 'sp/favorites/listing_exists', [ $this, 'listing_exists' ], 10, 2 );
		add_filter( 'sp/dashboard/home_cards', [ $this, 'home_card' ], 20, 2 );
		add_action( 'sp/listing/published', [ $this, 'on_published' ], 10, 3 );
	}

	public function on_farm_hidden( object $farm, string $reason ): void {
		$this->service->hide_for_farm( $farm, $reason );
	}

	public function on_farm_shown( object $farm ): void {
		$this->service->restore_for_farm( $farm );
	}

	/** @param int[]|null $ids @param string[] $slugs @return int[] */
	public function farm_ids_with_stock( $ids, array $slugs ): array {
		return $this->listings->farm_ids_with_categories( $slugs, $this->service->sold_visible_days() );
	}

	/** @param array $options @return array<int, array{value:string, label:string}> */
	public function stock_options( array $options ): array {
		foreach ( $this->types->categories() as $c ) {
			$options[] = [ 'value' => $c->slug, 'label' => $c->name ];
		}
		return $options;
	}

	public function farm_listings_html( string $html, object $farm, MemberContext $ctx ): string {
		$items = $this->listings->public_for_farm( (int) $farm->id, 12, 0, $this->service->sold_visible_days() );
		if ( empty( $items ) ) {
			return $html;
		}
		$cards = $this->presenter->cards( $items, $ctx, [ (int) $farm->id => $farm ] );
		foreach ( $cards as &$card ) {
			$card['html'] = $this->view->render( 'classifieds/card', [ 'item' => $card, 'context' => $ctx, 'compact' => true ] );
		}
		unset( $card );
		return $this->view->render( 'farm/partials/listings', [ 'items' => $cards, 'farm' => $farm, 'urls' => $this->urls, 'total' => (int) $farm->active_listing_count ] );
	}

	/**
	 * @param array    $items
	 * @param object[] $rows favorites rows
	 */
	public function favorite_listing_items( array $items, array $rows, MemberContext $ctx ): array {
		$listings = $this->listings->find_many( array_map( static fn( $r ) => (int) $r->object_id, $rows ) );
		$cards    = $this->presenter->cards( $listings, $ctx );
		$by_id    = [];
		foreach ( $rows as $r ) {
			$by_id[ (int) $r->object_id ] = $r;
		}
		$farm_ids = array_unique( array_map( static fn( $l ) => $l->farm_id, $listings ) );
		$farms    = $this->farms->find_many( $farm_ids );
		$public_f = [];
		foreach ( $farms as $fid => $farm ) {
			$public_f[ $fid ] = 'active' === (string) $farm->status && (int) $farm->active_listing_count >= 0; // membership check below via query-free rule
		}
		foreach ( $cards as &$card ) {
			$row            = $by_id[ $card['id'] ] ?? null;
			$card['notify'] = (bool) ( $row->notify ?? false );
			$card['added_at'] = (string) ( $row->created_at ?? '' );
			$card['active'] = $card['is_public'] && ! empty( $public_f[ $card['farm']['id'] ?? 0 ] ) && $this->farm_membership_active( (int) ( $card['farm']['id'] ?? 0 ) );
			$card['html']   = $this->view->render( 'classifieds/card', [ 'item' => $card, 'context' => $ctx, 'compact' => true ] );
		}
		unset( $card );
		return $cards;
	}

	private function farm_membership_active( int $farm_id ): bool {
		global $wpdb;
		$t = ListingRepository::table_memberships();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE farm_id = %d AND status = 'active' AND (ends_at IS NULL OR ends_at > %s)", $farm_id, Dates::now() ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Favorites may only point at listings the public can see (drafts / withdrawn / expired ads stay private). */
	public function listing_exists( bool $exists, int $id ): bool {
		$listing = $this->listings->find( $id );
		return null !== $listing && $listing->is_public( (int) $this->settings->get( 'listing_sold_visible_days', 14 ) ) && $this->farm_membership_active( (int) $listing->farm_id );
	}

	/** @param array<string, string> $cards */
	public function home_card( array $cards, MemberContext $ctx ): array {
		if ( ! $ctx->can( 'view_farm_pages' ) || ! $ctx->has_farm() ) {
			return $cards;
		}
		$all    = $this->listings->for_farm( $ctx->farm_id(), 50 );
		$live   = array_values( array_filter( $all, static fn( Listing $l ) => 'publish' === $l->post_status ) );
		$cards['listings'] = $this->view->render( 'dashboard/cards/listings', [
			'context' => $ctx,
			'urls'    => $this->urls,
			'summary' => $this->limits->summary( $ctx->farm_id() ),
			'items'   => $this->presenter->cards( array_slice( $live, 0, 5 ), $ctx ),
			'drafts'  => count( array_filter( $all, static fn( Listing $l ) => 'draft' === $l->post_status ) ),
			'expired' => count( array_filter( $all, static fn( Listing $l ) => ListingTypes::STATUS_EXPIRED === $l->post_status ) ),
			'can_publish' => $ctx->can( 'publish_listing' ),
		] );
		return $cards;
	}

	/** Farm contacts + the office hear about every (re)publish. */
	public function on_published( Listing $listing, ?MemberContext $ctx, bool $first ): void {
		$farm = $this->farms->find( $listing->farm_id );
		if ( ! $farm ) {
			return;
		}
		$url = $this->urls->listing( $listing->slug );
		$this->notifier->farm_listing_contacts( $farm, ( new ListingPublishedEmail() )->with( [ 'title' => $listing->title, 'farm_name' => $farm->name, 'url' => $url, 'expires_at' => $listing->expires_at, 'price' => $listing->price_display(), 'manage_url' => $this->urls->dashboard( 'my-listings' ), 'first' => $first ] ) );
		if ( $first ) {
			$owner = get_userdata( (int) $farm->owner_user_id );
			$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
				'type'      => 'new_listing',
				'farm_name' => (string) $farm->name,
				'summary'   => sprintf( '%s published a new classified listing: %s (%s).', (string) $farm->name, $listing->title, $listing->price_display() ),
				'rows'      => array_filter( [ 'Listing' => $listing->title, 'Price' => $listing->price_display(), 'Categories' => implode( ', ', array_map( static fn( $c ) => $c->name, $listing->categories ) ), 'Farm' => (string) $farm->name, 'Owner' => $owner ? $owner->display_name . ' &lt;' . esc_html( $owner->user_email ) . '&gt;' : '', 'Expires' => $listing->expires_at ? Dates::display( $listing->expires_at ) : '' ] ),
				'cta_label' => 'View listing',
				'cta_url'   => $url,
			] ) );
		}
	}
}
