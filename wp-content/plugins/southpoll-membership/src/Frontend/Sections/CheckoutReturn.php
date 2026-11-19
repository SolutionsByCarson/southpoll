<?php
/**
 * /members/checkout/{success|cancel}/ — Stripe return URLs. Fulfils (idempotently) and redirects with a toast.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Payments\CheckoutService;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class CheckoutReturn implements Section {

	public function __construct( private CheckoutService $checkout, private Flash $flash, private Urls $urls ) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$result = (string) $match->param( 'sp_result', '' );
		if ( 'cancel' === $result ) {
			$pid = isset( $_GET['payment'] ) ? (int) $_GET['payment'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $pid ) {
				$this->checkout->cancel_session( $pid, $context->user_id );
			}
			$this->flash->info( __( 'Checkout cancelled. No charge was made.', 'southpoll-membership' ) );
			$this->redirect();
		}
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $session_id ) {
			$this->redirect();
		}
		$res = $this->checkout->complete_session( $session_id );
		if ( is_wp_error( $res ) ) {
			$this->flash->error( $res->get_error_message() );
			$this->redirect();
		}
		switch ( $res['result'] ) {
			case 'fulfilled':
			case 'already':
				$this->flash->success( __( 'Payment received. Your membership is active and a receipt is on its way.', 'southpoll-membership' ), __( 'Thank you!', 'southpoll-membership' ) );
				break;
			case 'auto_renew':
				$this->flash->success( __( 'Automatic renewal is set up. Your card will be charged when the current term ends.', 'southpoll-membership' ) );
				break;
			default:
				$this->flash->info( __( 'Your payment is still processing. Your membership will activate automatically once it clears.', 'southpoll-membership' ) );
		}
		$this->redirect();
	}

	private function redirect(): never {
		wp_safe_redirect( $this->urls->dashboard( 'manage-membership' ) );
		exit;
	}
}
