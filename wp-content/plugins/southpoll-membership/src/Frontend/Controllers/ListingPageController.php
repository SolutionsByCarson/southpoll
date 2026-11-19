<?php
/**
 * /classifieds/listing/{slug}/ — single listing. Renamed listings 301 from their old slug; listings that are no longer
 * public show a "no longer available" page (200, noindex) with a link to the farm; the farm's own people and staff see
 * a preview with a notice.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Contact\InquiryService;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Farms\FarmMetaRepository;
use SouthPoll\Membership\Farms\FarmPresenter;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Listings\Listing;
use SouthPoll\Membership\Listings\ListingPresenter;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class ListingPageController implements Controller {

	private ?Listing $listing = null;

	private ?object $farm = null;

	private bool $resolved = false;

	private string $state = 'ok'; // ok | unavailable | not_found

	public function __construct(
		private View $view,
		private Urls $urls,
		private ListingRepository $listings,
		private ListingService $service,
		private ListingPresenter $presenter,
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmPresenter $farm_presenter,
		private FarmMetaRepository $farm_meta,
		private FarmUserRepository $farm_users,
		private MetaFieldRepository $fields,
		private ContextProvider $contexts,
		private Recaptcha $recaptcha,
		private Settings $settings,
		private Integrations $integrations
	) {}

	private function resolve( RouteMatch $match ): void {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;
		$slug           = sanitize_title( $match->slug() );
		$this->listing  = '' !== $slug ? $this->listings->find_by_slug( $slug ) : null;
		if ( ! $this->listing ) {
			$old = '' !== $slug ? $this->listings->find_by_old_slug( $slug ) : null;
			if ( $old ) {
				wp_safe_redirect( $this->urls->listing( $old->slug ), 301 );
				exit;
			}
			$this->state = 'not_found';
			return;
		}
		$this->farm = $this->farms->find( $this->listing->farm_id );
		$public     = $this->farm && $this->visibility->is_public( $this->farm ) && $this->listing->is_public( $this->service->sold_visible_days() );
		if ( ! $public ) {
			$this->state = 'unavailable';
		}
	}

	public function prepare( RouteMatch $match ): void {
		$this->resolve( $match );
		if ( 'not_found' === $this->state ) {
			status_header( 404 );
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		} elseif ( 'unavailable' === $this->state ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		} elseif ( $this->listing ) {
			$desc = $this->listing->summary( 28 );
			add_filter( 'wpseo_metadesc', static fn() => $desc, 99 );
			add_filter( 'wpseo_opengraph_desc', static fn() => $desc, 99 );
			if ( ! defined( 'WPSEO_VERSION' ) ) {
				add_action( 'wp_head', static function () use ( $desc ): void {
					echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
				}, 1 );
			}
		}
	}

	public function title( RouteMatch $match ): string {
		$this->resolve( $match );
		if ( ! $this->listing ) {
			return __( 'Listing not found', 'southpoll-membership' );
		}
		if ( 'unavailable' === $this->state && ! $this->can_preview() ) {
			return __( 'Listing no longer available', 'southpoll-membership' );
		}
		return $this->listing->title . ' – ' . $this->listing->price_display() . ( $this->farm ? ' – ' . (string) $this->farm->name : '' );
	}

	private function can_preview(): bool {
		$ctx = $this->contexts->current();
		return $ctx->is_staff() || ( $this->listing && $ctx->has_farm() && $ctx->farm_id() === $this->listing->farm_id );
	}

	public function render( RouteMatch $match ): void {
		$this->resolve( $match );
		$ctx = $this->contexts->current();
		if ( 'not_found' === $this->state || ! $this->listing ) {
			$this->view->display( 'listing/not-found', [ 'urls' => $this->urls ] );
			return;
		}
		$preview = 'unavailable' === $this->state && $this->can_preview();
		if ( 'unavailable' === $this->state && ! $preview ) {
			$farm_public = $this->farm && $this->visibility->is_public( $this->farm );
			$this->view->display( 'listing/unavailable', [ 'listing' => $this->listing, 'farm' => $this->farm, 'farm_public' => $farm_public, 'urls' => $this->urls, 'context' => $ctx ] );
			return;
		}
		if ( $preview ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
		$listing   = $this->listing;
		$farm      = $this->farm;
		$card      = $this->presenter->card( $listing, $ctx, $farm );
		$farm_card = $farm ? $this->farm_presenter->card( $farm, $ctx ) : null;
		$exact     = $this->farm_presenter->exact_coordinates( $ctx );
		$coords    = $farm ? $this->farm_presenter->coordinates( $farm, $exact ) : null;
		$see       = $ctx->can( 'view_contact_info' );
		$owner     = $farm ? $this->farm_users->owner_row( (int) $farm->id ) : null;
		$form_on   = $farm && (bool) (int) $farm->contact_form_enabled && 'ok' === $this->state;
		if ( $form_on && ! $ctx->is_logged_in() ) {
			$this->recaptcha->enqueue();
		}
		$groups = [];
		foreach ( $this->fields->groups_for_categories( $listing->category_ids(), 'listing' ) as $group ) {
			$rows = [];
			foreach ( $this->fields->fields_for_group( (int) $group->id ) as $field ) {
				$display = MetaFieldRepository::display_value( $field, $listing->meta[ (string) $field->field_key ] ?? null );
				if ( '' !== $display ) {
					$rows[] = [ 'label' => (string) $field->label, 'value' => $display ];
				}
			}
			if ( $rows ) {
				$groups[] = [ 'title' => (string) $group->title, 'rows' => $rows ];
			}
		}
		$related = $farm ? $this->presenter->cards( $this->listings->public_for_farm( (int) $farm->id, 4, $listing->id, $this->service->sold_visible_days() ), $ctx, [ (int) $farm->id => $farm ] ) : [];
		foreach ( $related as &$r ) {
			$r['html'] = $this->view->render( 'classifieds/card', [ 'item' => $r, 'context' => $ctx, 'compact' => true ] );
		}
		unset( $r );
		do_action( 'sp/listing/page_viewed', $listing, $ctx );

		$this->view->display( 'listing/single', [
			'listing'      => $listing,
			'card'         => $card,
			'gallery'      => $this->presenter->gallery( $listing ),
			'groups'       => $groups,
			'farm'         => $farm,
			'farm_card'    => $farm_card,
			'farm_meta'    => $farm ? $this->farm_meta->display_rows( (int) $farm->id ) : [],
			'farm_hours'   => $farm && FarmRepository::has_hours( $farm ) ? FarmPresenter::hours_rows( $farm ) : [],
			'social'       => $farm ? FarmPresenter::social_links( $farm ) : [],
			'context'      => $ctx,
			'urls'         => $this->urls,
			'preview'      => $preview,
			'is_mine'      => $ctx->has_farm() && $ctx->farm_id() === $listing->farm_id,
			'see_contact'  => $see,
			'contact'      => $see && $farm ? [
				'owner_name' => (string) $farm->owner_name ?: ( $owner ? (string) $owner->display_name : '' ),
				'phone'      => (string) $farm->phone,
				'email'      => (string) $farm->email ?: ( $owner ? (string) $owner->email : '' ),
				'website'    => (string) $farm->website,
				'address'    => FarmRepository::address_line( $farm, true ),
			] : [ 'address' => $farm ? FarmRepository::address_line( $farm, false ) : '' ],
			'form_on'      => $form_on,
			'form'         => [
				'recaptcha' => $this->recaptcha->enabled() && ! $ctx->is_logged_in(),
				'action'    => InquiryService::RECAPTCHA_ACTION,
				'honeypot'  => Honeypot::FIELD,
				'max_chars' => (int) $this->settings->get( 'inquiry_max_message_chars', 2000 ),
				'user'      => $ctx->is_logged_in() ? wp_get_current_user() : null,
			],
			'directions'   => $farm && $ctx->is_logged_in() ? $this->farm_presenter->directions_url( $farm, $exact ) : '', // members only
			'map'          => $coords && $this->integrations->maps_configured() ? [ 'lat' => $coords['lat'], 'lng' => $coords['lng'], 'exact' => $exact ] : null,
			'share'        => $this->presenter->share( $listing, $farm ),
			'related'      => $related,
			'login_url'    => $this->urls->login( $this->urls->listing( $listing->slug ) ),
			'register_url' => $this->urls->register(),
			'count_view'   => 'ok' === $this->state && ! ( $ctx->has_farm() && $ctx->farm_id() === $listing->farm_id ),
		] );
	}
}
