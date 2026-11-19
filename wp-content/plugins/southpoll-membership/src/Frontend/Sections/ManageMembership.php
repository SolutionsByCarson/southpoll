<?php
/**
 * /members/manage-membership/ — buyer: become a member; owner: checkout / renew / auto-renew / card / invoices /
 * offline claim / billing user; manager: read-only status.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Payments\CheckoutService;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Payments\StripeService;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\MembershipAutoRenewOffEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class ManageMembership implements Section {

	private ?WP_Error $error = null;

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private MembershipPresenter $presenter,
		private TierRepository $tiers,
		private CheckoutService $checkout,
		private OfflineClaims $claims,
		private PaymentRepository $payments,
		private FarmService $farm_service,
		private FarmRepository $farms,
		private FarmUserRepository $farm_users,
		private StripeService $stripe,
		private Settings $settings,
		private StatusEngine $engine,
		private Notifier $notifier
	) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {
		$action = isset( $_POST['sp_action'] ) ? sanitize_key( wp_unslash( $_POST['sp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $action ) {
			return;
		}
		if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_membership_' . $action ) ) {
			$this->flash->error( __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			$this->redirect();
		}
		$result = match ( $action ) {
			'create_farm'  => $this->create_farm( $context ),
			'checkout'     => $this->start_checkout( $context ),
			'claim'        => $this->claim( $context ),
			'auto_renew'   => $this->auto_renew( $context ),
			'cancel'       => $this->cancel( $context ),
			'portal'       => $this->portal( $context ),
			'billing_user' => $this->billing_user( $context ),
			default        => null,
		};
		if ( $result instanceof WP_Error ) {
			$this->error = $result;
			return;
		}
		$this->redirect();
	}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$summary  = $this->presenter->summary( $context );
		$tiers    = $this->tiers->all();
		$history  = $context->has_farm() ? $this->payments->for_farm( $context->farm_id() ) : [];
		$card     = '';
		if ( $context->membership && ! empty( $context->membership->stripe_subscription_id ) && (int) $context->membership->auto_renew === 1 && $this->stripe->client()->configured() ) {
			$sub = $this->stripe->retrieve_subscription( (string) $context->membership->stripe_subscription_id );
			if ( ! is_wp_error( $sub ) ) {
				$card = $this->stripe->card_label( $sub );
			}
		}
		return $this->view->render( 'dashboard/manage-membership', [
			'context'        => $context,
			'summary'        => $summary,
			'tiers'          => $tiers,
			'default_tier'   => $this->tiers->default(),
			'history'        => $history,
			'card_label'     => $card,
			'stripe_ready'   => $this->stripe->client()->configured(),
			'claims_enabled' => (bool) $this->settings->get( 'membership_offline_claims_enabled', true ),
			'farm_users'     => $context->is_owner() ? array_filter( $this->farm_users->for_farm( $context->farm_id() ), fn( $r ) => ! $r->is_pending ) : [],
			'owner'          => $context->has_farm() ? $this->farm_users->owner_row( $context->farm_id() ) : null,
			'error'          => $this->error,
			'urls'           => $this->urls,
			'states'         => Str::us_states(),
			'settings'       => $this->settings,
		] );
	}

	/* ---------------------------------------------------------------- actions */

	private function create_farm( MemberContext $context ): ?WP_Error {
		if ( $context->has_farm() ) {
			return null;
		}
		$name    = isset( $_POST['farm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['farm_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$state   = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$breeder = isset( $_POST['breeder_number'] ) ? sanitize_text_field( wp_unslash( $_POST['breeder_number'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$farm    = $this->farm_service->create_for_owner( $context->user_id, $name, [ 'state' => $state, 'breeder_number' => $breeder ] );
		if ( is_wp_error( $farm ) ) {
			return $farm;
		}
		$this->flash->success( __( 'Your farm is set up. Complete the membership below to publish it.', 'southpoll-membership' ) );
		return null;
	}

	private function start_checkout( MemberContext $context ): ?WP_Error {
		$tier      = $this->tiers->resolve( $_POST['tier'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$recurring = ! empty( $_POST['recurring'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $tier ) {
			return new WP_Error( 'sp_no_tier', __( 'No membership option is available right now.', 'southpoll-membership' ) );
		}
		$url = $this->checkout->start( $context, $tier, $recurring );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Stripe URL
		exit;
	}

	private function claim( MemberContext $context ): ?WP_Error {
		$tier   = $this->tiers->resolve( $_POST['tier'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $tier ) {
			return new WP_Error( 'sp_no_tier', __( 'No membership option is available right now. Please contact the office.', 'southpoll-membership' ) );
		}
		$amount = isset( $_POST['amount'] ) ? (int) round( (float) str_replace( [ '$', ',' ], '', (string) wp_unslash( $_POST['amount'] ) ) * 100 ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$res    = $this->claims->submit( $context, $tier, [
			'method'       => isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'check', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'amount_cents' => $amount,
			'paid_on'      => isset( $_POST['paid_on'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_on'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'note'         => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->flash->success( __( 'Thanks! The office will verify your payment and activate your membership. You will get an email when it is confirmed.', 'southpoll-membership' ) );
		return null;
	}

	private function auto_renew( MemberContext $context ): ?WP_Error {
		$on  = ! empty( $_POST['on'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res = $this->checkout->set_auto_renew( $context, $on );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( str_starts_with( $res, 'checkout:' ) ) {
			wp_redirect( substr( $res, 9 ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		$this->flash->success( 'on' === $res ? __( 'Automatic renewal is on.', 'southpoll-membership' ) : __( 'Automatic renewal is off. Your membership stays active until its end date.', 'southpoll-membership' ) );
		return null;
	}

	/**
	 * "Cancel membership" = stop automatic renewal (Carson, 2026-10-27). The paid term runs to its end date; nothing is refunded,
	 * nothing is hidden today. When auto-renew is already off there is nothing to cancel (the button is disabled in the template).
	 */
	private function cancel( MemberContext $context ): ?WP_Error {
		$m = $context->membership;
		if ( ! $context->can( 'manage_membership' ) || ! $m ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the farm owner can cancel the membership.', 'southpoll-membership' ) );
		}
		if ( (int) $m->auto_renew !== 1 ) {
			$this->flash->info( sprintf( /* translators: %s: date */ __( 'Nothing to cancel: automatic renewal is already off. Your membership simply ends on %s.', 'southpoll-membership' ), Dates::display( $m->ends_at ?? null ) ) );
			return null;
		}
		$res = $this->checkout->set_auto_renew( $context, false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$farm  = $context->farm;
		$owner = wp_get_current_user();
		$this->engine->add_note( $m, sprintf( 'Member cancelled (auto-renew turned off by %s; term runs to %s)', $owner->display_name, Dates::display( $m->ends_at ?? null ) ) );
		$this->notifier->farm_membership_contacts( $farm, ( new MembershipAutoRenewOffEmail() )->with( [ 'farm_name' => (string) $farm->name, 'ends_at' => $m->ends_at, 'ends_display' => Dates::display( $m->ends_at ?? null ), 'manage_url' => $this->urls->dashboard( 'manage-membership' ) ] ) );
		$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
			'type'      => 'membership_cancelled_by_member',
			'title'     => sprintf( /* translators: %s: farm */ __( 'Membership cancellation: %s', 'southpoll-membership' ), (string) $farm->name ),
			'summary'   => sprintf( '%s turned automatic renewal off for %s. The membership stays active until %s and will then expire (no refund, nothing hidden until then).', $owner->display_name, (string) $farm->name, Dates::display( $m->ends_at ?? null ) ),
			'admin_url' => admin_url( 'admin.php?page=southpoll-memberships&farm=' . $context->farm_id() ),
		] ) );
		$this->flash->success( sprintf( /* translators: %s: date */ __( 'Your membership is cancelled: it will not renew and ends on %s. You keep full access until then.', 'southpoll-membership' ), Dates::display( $m->ends_at ?? null ) ) );
		return null;
	}

	private function portal( MemberContext $context ): ?WP_Error {
		$url = $this->checkout->portal_url( $context );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private function billing_user( MemberContext $context ): ?WP_Error {
		if ( ! $context->can( 'manage_membership' ) ) {
			return new WP_Error( 'sp_forbidden', __( 'Only the owner can change the billing contact.', 'southpoll-membership' ) );
		}
		$uid = isset( $_POST['billing_user_id'] ) ? (int) $_POST['billing_user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $uid ) {
			$row = $this->farm_users->for_user( $uid );
			if ( ! $row || (int) $row->farm_id !== $context->farm_id() ) {
				return new WP_Error( 'sp_invalid_user', __( 'That person is not part of your farm.', 'southpoll-membership' ) );
			}
		}
		$this->farms->update( $context->farm_id(), [ 'billing_user_id' => $uid ?: null ] );
		$this->flash->success( __( 'Billing contact updated.', 'southpoll-membership' ) );
		return null;
	}

	private function redirect(): never {
		wp_safe_redirect( $this->urls->dashboard( 'manage-membership' ) );
		exit;
	}
}
