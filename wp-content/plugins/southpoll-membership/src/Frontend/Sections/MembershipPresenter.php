<?php
/**
 * Turns a member context into the banner/status data every dashboard screen shows.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

final class MembershipPresenter {

	public function __construct( private StatusEngine $engine, private Settings $settings, private Urls $urls, private PaymentRepository $payments ) {}

	/**
	 * @return array<string, mixed> state, label, badge, days, ends, ends_display, countdown, auto_renew, pending_claim, tier, price, cta[]
	 */
	public function summary( MemberContext $context ): array {
		$m       = $context->membership;
		$state   = $context->has_farm() ? $this->engine->effective_status( $m ) : 'buyer';
		$days    = $this->engine->days_remaining( $m );
		$tier    = $this->engine->tier_for( $m );
		$window  = (int) $this->settings->get( 'membership_expiring_window_days', 30 );
		$count   = (int) $this->settings->get( 'membership_countdown_days', 7 );
		$pending = $context->has_farm() && $this->payments->has_pending_claim( $context->farm_id() );

		$out = [
			'state'         => $state,
			'days'          => $days,
			'ends'          => $m->ends_at ?? null,
			'ends_display'  => Dates::display( $m->ends_at ?? null ),
			'expiring'      => 'active' === $state && null !== $days && $days <= $window,
			'countdown'     => 'active' === $state && null !== $days && $days <= $count,
			'auto_renew'    => $m && (int) $m->auto_renew === 1,
			'pending_claim' => $pending,
			'tier'          => $tier,
			'price'         => $tier ? Str::money( (int) $tier->price_cents, (string) $tier->currency ) : '',
			'manage_url'    => $this->urls->dashboard( 'manage-membership' ),
			'farm_name'     => $context->farm->name ?? '',
		];

		[ $out['badge'], $out['label'] ] = match ( $state ) {
			'active'    => [ $out['countdown'] ? 'danger' : ( $out['expiring'] ? 'warning' : 'success' ), __( 'Active', 'southpoll-membership' ) ],
			'pending'   => [ 'muted', __( 'Pending verification', 'southpoll-membership' ) ],
			'expired'   => [ 'danger', __( 'Expired', 'southpoll-membership' ) ],
			'cancelled' => [ 'danger', __( 'Cancelled', 'southpoll-membership' ) ],
			'none'      => [ 'muted', $pending ? __( 'Pending verification', 'southpoll-membership' ) : __( 'Not activated', 'southpoll-membership' ) ],
			default     => [ 'muted', __( 'Buyer account', 'southpoll-membership' ) ],
		};
		return $out;
	}
}
