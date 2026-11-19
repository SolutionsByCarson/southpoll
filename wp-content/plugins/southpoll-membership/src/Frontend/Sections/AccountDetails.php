<?php
/**
 * /members/account-details/ — profile, email change, password, notification preferences, verification.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Sections;

use SouthPoll\Membership\Auth\AccountService;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class AccountDetails implements Section {

	private ?WP_Error $errors = null;

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private AccountService $accounts,
		private MemberRepository $members,
		private ContextProvider $contexts
	) {}

	public function handle( RouteMatch $match, MemberContext $context ): void {
		$action = isset( $_POST['sp_action'] ) ? sanitize_key( wp_unslash( $_POST['sp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $action ) {
			return;
		}
		if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_account_' . $action ) ) {
			$this->flash->error( __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			$this->redirect();
		}
		$user   = wp_get_current_user();
		$result = match ( $action ) {
			'profile'      => $this->accounts->update_profile( $user, $this->post( [ 'first_name', 'last_name', 'phone', 'address1', 'city', 'state', 'zip' ] ) ),
			'preferences'  => $this->preferences( $user ),
			'password'     => $this->accounts->change_password( $user, (string) wp_unslash( $_POST['current_password'] ?? '' ), (string) wp_unslash( $_POST['new_password'] ?? '' ), (string) wp_unslash( $_POST['new_password_confirm'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
			'email'        => $this->accounts->request_email_change( $user, (string) wp_unslash( $_POST['new_email'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
			'cancel_email' => $this->cancel_email( $user ),
			'resend'       => $this->accounts->send_verification( $user->ID, '' !== (string) ( $context->member->pending_email ?? '' ) ),
			default        => null,
		};
		if ( $result instanceof WP_Error ) {
			$this->errors = $result;
			return; // render inline
		}
		$this->flash->success( match ( $action ) {
			'profile'      => __( 'Your details have been saved.', 'southpoll-membership' ),
			'preferences'  => __( 'Notification preferences saved.', 'southpoll-membership' ),
			'password'     => __( 'Your password has been changed.', 'southpoll-membership' ),
			'email'        => __( 'Check your new inbox for a verification link. Your login email changes once you confirm it.', 'southpoll-membership' ),
			'cancel_email' => __( 'Email change cancelled.', 'southpoll-membership' ),
			'resend'       => __( 'Verification email sent. Check your inbox and spam folder.', 'southpoll-membership' ),
			default        => __( 'Saved.', 'southpoll-membership' ),
		} );
		$this->redirect();
	}

	public function render( RouteMatch $match, MemberContext $context ): string {
		$user   = wp_get_current_user();
		$member = $this->members->find_by_user( $user->ID ) ?? $this->members->create_for_user( $user->ID );
		return $this->view->render( 'dashboard/account-details', [
			'context' => $this->contexts->for_user( $user->ID ),
			'user'    => $user,
			'member'  => $member,
			'errors'  => $this->errors ?? new WP_Error(),
			'urls'    => $this->urls,
			'states'  => Str::us_states(),
			'farm'    => $context->farm,
		] );
	}

	private function preferences( \WP_User $user ): true {
		$this->accounts->update_preferences( $user, $this->post( [ 'notify_all', 'default_notify_favorites', 'default_notify_searches' ] ) );
		return true;
	}

	private function cancel_email( \WP_User $user ): true {
		$this->accounts->cancel_email_change( $user );
		return true;
	}

	/** @return array<string, string> */
	private function post( array $keys ): array {
		$out = [];
		foreach ( $keys as $k ) {
			$out[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $out;
	}

	private function redirect(): never {
		wp_safe_redirect( $this->urls->dashboard( 'account-details' ) );
		exit;
	}
}
