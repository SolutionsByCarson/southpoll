<?php
/**
 * /members/login/ (login · create account · lost password), /members/reset-password/, /members/verify/, /members/invite/.
 * Theme-wrapped routes; classic POST + redirect with flash messages.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Auth\AccountService;
use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Frontend\Flash;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Memberships\TierRepository;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class AuthController implements Controller {

	public function __construct(
		private View $view,
		private Urls $urls,
		private Flash $flash,
		private AccountService $accounts,
		private Recaptcha $recaptcha,
		private TierRepository $tiers,
		private FarmUserRepository $farm_users,
		private Settings $settings
	) {}

	public function title( RouteMatch $match ): string {
		if ( 'login' === $match->name ) {
			return match ( $this->tab() ) {
				'register' => __( 'Create Your Account', 'southpoll-membership' ),
				'lost'     => __( 'Reset Your Password', 'southpoll-membership' ),
				default    => __( 'Member Login', 'southpoll-membership' ),
			};
		}
		return (string) ( $match->spec['title'] ?? '' );
	}

	public function render( RouteMatch $match ): void {
		$this->recaptcha->enqueue();
		match ( $match->name ) {
			'login'          => $this->login_page(),
			'reset-password' => $this->reset_page(),
			'verify'         => $this->verify_page(),
			'invite'         => $this->invite_page(),
			default          => null,
		};
	}

	/* --------------------------------------------------------------- login */

	private function tab(): string {
		$tab = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['sp_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tab = sanitize_key( wp_unslash( $_POST['sp_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return in_array( $tab, [ 'login', 'register', 'lost' ], true ) ? $tab : 'login';
	}

	private function redirect_target(): string {
		$raw = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = wp_validate_redirect( rawurldecode( $raw ), '' );
		return '' !== $url ? $url : $this->urls->dashboard();
	}

	private function login_page(): void {
		$errors = new WP_Error();
		$old    = [];

		if ( is_user_logged_in() && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( $this->redirect_target() );
			exit;
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['sp_action'] ) ) {
			$action = $this->tab();
			$old    = $this->old_input();
			if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_auth_' . $action ) ) {
				$errors->add( 'nonce', __( 'Your session expired. Please try again.', 'southpoll-membership' ) );
			} elseif ( ! Honeypot::passes() ) {
				$errors->add( 'spam', __( 'The form could not be submitted. Please try again.', 'southpoll-membership' ) );
			} elseif ( null === $this->recaptcha->verify_post( $action ) ) {
				$errors->add( 'recaptcha', __( 'We could not confirm you are human. Please try again.', 'southpoll-membership' ) );
			} else {
				$result = match ( $action ) {
					'login'    => $this->handle_login(),
					'register' => $this->handle_register(),
					'lost'     => $this->handle_lost(),
					default    => null,
				};
				if ( $result instanceof WP_Error ) {
					foreach ( $result->get_error_codes() as $code ) {
						$errors->add( $code, $result->get_error_message( $code ) );
					}
				}
			}
		}

		$tier    = $this->tiers->default();
		$invite  = $this->invite_from_request();
		$this->view->display( 'auth/login', [
			'tab'         => $this->tab(),
			'errors'      => $errors,
			'old'         => $old,
			'urls'        => $this->urls,
			'tier'        => $tier,
			'price'       => $tier ? Str::money( (int) $tier->price_cents, (string) $tier->currency ) : '',
			'redirect_to' => $this->redirect_target(),
			'invite'      => $invite,
			'recaptcha'   => $this->recaptcha,
			'states'      => Str::us_states(),
			'settings'    => $this->settings,
		] );
	}

	private function handle_login(): ?WP_Error {
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pass  = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$user  = $this->accounts->login( $login, $pass, ! empty( $_POST['rememberme'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$invite = $this->invite_from_request();
		if ( $invite ) {
			$accepted = $this->farm_users->accept( $invite, $user->ID );
			if ( is_wp_error( $accepted ) ) {
				$this->flash->error( $accepted->get_error_message() );
			} else {
				$this->flash->success( __( 'Invitation accepted. You can now help manage the farm.', 'southpoll-membership' ) );
			}
		}
		wp_safe_redirect( $this->redirect_target() );
		exit;
	}

	/** Per-IP throttle for the two forms that send e-mail to arbitrary addresses (registration, lost password). */
	private function ip_limited( string $bucket, int $limit ): ?WP_Error {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = $bucket . ':ip' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		return \SouthPoll\Membership\Rest\RateLimiter::hit( $key, $limit, HOUR_IN_SECONDS ) ? null : new WP_Error( 'sp_rate_limited', __( 'Too many attempts from your connection. Please wait an hour and try again, or contact the office.', 'southpoll-membership' ) );
	}

	private function handle_register(): ?WP_Error {
		if ( $limited = $this->ip_limited( 'register', 10 ) ) {
			return $limited;
		}
		$in = [];
		foreach ( [ 'first_name', 'last_name', 'email', 'phone', 'state', 'account_type', 'farm_name', 'breeder_number', 'invite_token', 'agree' ] as $k ) {
			$in[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$in['password']         = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$in['password_confirm'] = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		$user = $this->accounts->register( $in );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( 'member' === $in['account_type'] ) {
			$this->flash->success( __( 'Your account is ready. Complete your membership below to unlock your farm page and listings.', 'southpoll-membership' ), __( 'Welcome!', 'southpoll-membership' ) );
			wp_safe_redirect( $this->urls->dashboard( 'manage-membership' ) );
		} else {
			$this->flash->success( __( 'Your account is ready. Check your email to verify your address.', 'southpoll-membership' ), __( 'Welcome!', 'southpoll-membership' ) );
			wp_safe_redirect( $this->redirect_target() );
		}
		exit;
	}

	private function handle_lost(): ?WP_Error {
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $login ) {
			return new WP_Error( 'user_login', __( 'Please enter your email address.', 'southpoll-membership' ) );
		}
		if ( $limited = $this->ip_limited( 'lost', 8 ) ) {
			return $limited;
		}
		$this->accounts->request_password_reset( $login );
		$this->flash->info( __( 'If an account exists for that email, a reset link is on its way. Check your inbox and spam folder.', 'southpoll-membership' ), __( 'Check your email', 'southpoll-membership' ) );
		wp_safe_redirect( $this->urls->login() );
		exit;
	}

	/* ------------------------------------------------------- reset / verify */

	private function reset_page(): void {
		$key   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$login = isset( $_REQUEST['login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = null;
		$user  = $this->accounts->validate_reset( $key, $login );
		if ( is_wp_error( $user ) ) {
			$this->view->display( 'auth/reset-password', [ 'invalid' => true, 'error' => $user->get_error_message(), 'urls' => $this->urls ] );
			return;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['sp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sp_nonce'] ) ), 'sp_reset' ) ) {
				$error = __( 'Your session expired. Please try again.', 'southpoll-membership' );
			} else {
				$result = $this->accounts->reset_password( $key, $login, (string) wp_unslash( $_POST['password'] ?? '' ), (string) wp_unslash( $_POST['password_confirm'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$this->flash->success( __( 'Your password has been changed. You can log in now.', 'southpoll-membership' ) );
					wp_safe_redirect( $this->urls->login() );
					exit;
				}
			}
		}
		$this->view->display( 'auth/reset-password', [ 'invalid' => false, 'error' => $error, 'key' => $key, 'login' => $login, 'urls' => $this->urls ] );
	}

	private function verify_page(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uid   = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ok    = $uid > 0 && '' !== $token ? $this->accounts->verify( $uid, $token ) : null;
		if ( $ok ) {
			$this->flash->success( __( 'Your email address is verified. Thank you!', 'southpoll-membership' ) );
			wp_safe_redirect( is_user_logged_in() ? $this->urls->dashboard() : $this->urls->login() );
			exit;
		}
		$this->view->display( 'auth/verify', [ 'ok' => false, 'urls' => $this->urls, 'logged_in' => is_user_logged_in() ] );
	}

	/* --------------------------------------------------------------- invite */

	private function invite_from_request(): ?object {
		$token = isset( $_REQUEST['invite'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['invite'] ) ) : ( isset( $_REQUEST['invite_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['invite_token'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return null;
		}
		$invite = $this->farm_users->find_by_token( $token );
		if ( $invite ) {
			$invite->raw_token = $token;
		}
		return $invite;
	}

	private function invite_page(): void {
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invite = '' !== $token ? $this->farm_users->find_by_token( $token ) : null;
		$farm   = $invite ? sp_membership()->get( \SouthPoll\Membership\Farms\FarmRepository::class )->find( (int) $invite->farm_id ) : null;

		if ( $invite && is_user_logged_in() ) {
			$result = $this->farm_users->accept( $invite, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				$this->flash->error( $result->get_error_message() );
				wp_safe_redirect( $this->urls->dashboard() );
			} else {
				$this->flash->success( sprintf( /* translators: %s: farm name */ __( 'You are now an account manager for %s.', 'southpoll-membership' ), $farm ? $farm->name : '' ) );
				wp_safe_redirect( $this->urls->dashboard( 'my-farm' ) );
			}
			exit;
		}
		$this->view->display( 'auth/invite', [ 'invite' => $invite, 'farm' => $farm, 'token' => $token, 'urls' => $this->urls ] );
	}

	/** @return array<string, string> */
	private function old_input(): array {
		$old = [];
		foreach ( [ 'log', 'first_name', 'last_name', 'email', 'phone', 'state', 'account_type', 'farm_name', 'breeder_number', 'user_login', 'invite_token' ] as $k ) {
			$old[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $old;
	}
}
