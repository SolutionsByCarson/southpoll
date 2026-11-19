<?php
/**
 * /members/help/ — the member Help Center (S10 / T6.3): account & login, farm page, listings, membership & payment,
 * renewals & cancelling, favorites & saved searches, account managers, contact & feedback. Copy pulls the live settings
 * (membership price, listing duration, limits, reminder days, office contact) so it never goes stale.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;

final class HelpCenter implements Section {

	public function __construct( private View $view, private Urls $urls, private Settings $settings, private TierRepository $tiers ) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$tier     = $this->tiers->default();
		$contact  = (array) $this->settings->get( 'admin_contact', [] );
		$reminder = array_map( 'intval', (array) $this->settings->get( 'membership_reminder_days', [ 30, 7, 1 ] ) );
		rsort( $reminder );
		return $this->view->render( 'dashboard/help', [
			'context'          => $context,
			'urls'             => $this->urls,
			'tier_name'        => $tier ? (string) $tier->name : __( 'Annual Membership', 'southpoll-membership' ),
			'tier_price'       => $tier ? Str::money( (int) $tier->price_cents, (string) $tier->currency ) : '',
			'listing_days'     => (int) $this->settings->get( 'listing_duration_days', 60 ),
			'sold_days'        => (int) $this->settings->get( 'listing_sold_visible_days', 14 ),
			'listing_limit'    => $tier && null !== $tier->listing_limit && '' !== (string) $tier->listing_limit ? (int) $tier->listing_limit : (int) $this->settings->get( 'listing_limit_default', 10 ),
			'renewal_days'     => (int) $this->settings->get( 'listing_renewal_reminder_days', 7 ),
			'reminder_days'    => $reminder,
			'offline_claims'   => (bool) $this->settings->get( 'membership_offline_claims_enabled', true ),
			'verify_required'  => (bool) $this->settings->get( 'require_email_verification', true ),
			'feedback_enabled' => (bool) $this->settings->get( 'feedback_enabled', false ),
			'feedback_label'   => (string) ( $this->settings->get( 'feedback_label', '' ) ?: __( 'Feedback / report a problem', 'southpoll-membership' ) ),
			'contact_name'     => (string) ( $contact['name'] ?? '' ),
			'contact_email'    => (string) ( $contact['email'] ?? '' ),
			'contact_phone'    => (string) ( $contact['phone'] ?? '' ),
			'lost_url'         => add_query_arg( 'action', 'lost', $this->urls->login() ),
		] );
	}
}
