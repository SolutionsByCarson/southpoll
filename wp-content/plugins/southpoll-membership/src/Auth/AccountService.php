<?php
/**
 * Registration, login, password reset, email verification, and profile updates.
 * WordPress owns credentials (D-03); this service wraps core functions with plugin rules and emails.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Auth;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\PasswordResetEmail;
use SouthPoll\Membership\Email\Messages\VerifyEmail;
use SouthPoll\Membership\Email\Messages\WelcomeEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmService;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\Urls;
use SouthPoll\Membership\Support\Str;
use WP_Error;
use WP_User;

final class AccountService {

	public const MIN_PASSWORD = 8;

	public function __construct(
		private Settings $settings,
		private MemberRepository $members,
		private FarmService $farm_service,
		private FarmUserRepository $farm_users,
		private ContextProvider $contexts,
		private Notifier $notifier,
		private Urls $urls,
		private Lockout $lockout,
		private Logger $logger
	) {}

	/* ------------------------------------------------------------ register */

	/**
	 * @param array<string, mixed> $in first_name, last_name, email, password, password_confirm, phone, account_type (buyer|member), farm_name, state, invite_token
	 * @return WP_User|WP_Error
	 */
	public function register( array $in ): WP_User|WP_Error {
		$errors = new WP_Error();
		$first  = sanitize_text_field( (string) ( $in['first_name'] ?? '' ) );
		$last   = sanitize_text_field( (string) ( $in['last_name'] ?? '' ) );
		$email  = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$pass   = (string) ( $in['password'] ?? '' );
		$type   = 'member' === ( $in['account_type'] ?? 'buyer' ) ? 'member' : 'buyer';
		$farm   = sanitize_text_field( (string) ( $in['farm_name'] ?? '' ) );

		if ( '' === $first ) {
			$errors->add( 'first_name', __( 'First name is required.', 'southpoll-membership' ) );
		}
		if ( '' === $last ) {
			$errors->add( 'last_name', __( 'Last name is required.', 'southpoll-membership' ) );
		}
		if ( ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address.', 'southpoll-membership' ) );
		} elseif ( email_exists( $email ) ) {
			$errors->add( 'email', sprintf( /* translators: %s: login URL */ __( 'An account with that email already exists. <a href="%s">Log in instead</a> or reset your password.', 'southpoll-membership' ), esc_url( $this->urls->login() ) ) );
		}
		if ( strlen( $pass ) < self::MIN_PASSWORD ) {
			$errors->add( 'password', sprintf( /* translators: %d: minimum length */ __( 'Password must be at least %d characters.', 'southpoll-membership' ), self::MIN_PASSWORD ) );
		} elseif ( isset( $in['password_confirm'] ) && $pass !== (string) $in['password_confirm'] ) {
			$errors->add( 'password_confirm', __( 'The two passwords do not match.', 'southpoll-membership' ) );
		}
		if ( 'member' === $type && '' === $farm ) {
			$errors->add( 'farm_name', __( 'Please enter your farm or ranch name.', 'southpoll-membership' ) );
		}
		if ( empty( $in['agree'] ) ) {
			$errors->add( 'agree', __( 'Please agree to the website terms to continue.', 'southpoll-membership' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$user_id = wp_insert_user( [
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
			'role'         => Capabilities::ROLE_BUYER,
		] );
		if ( is_wp_error( $user_id ) ) {
			$this->logger->error( 'Registration failed: ' . $user_id->get_error_message() );
			return new WP_Error( 'sp_register_failed', __( 'We could not create your account. Please try again or contact the office.', 'southpoll-membership' ) );
		}

		$verify_required = (bool) $this->settings->get( 'require_email_verification', true );
		$member          = $this->members->create_for_user( (int) $user_id, [
			'first_name' => $first,
			'last_name'  => $last,
			'phone'      => (string) ( $in['phone'] ?? '' ),
			'state'      => (string) ( $in['state'] ?? '' ),
			'verified'   => ! $verify_required,
		] );

		$farm_row = null;
		if ( 'member' === $type ) {
			$farm_row = $this->farm_service->create_for_owner( (int) $user_id, $farm, [ 'state' => (string) ( $in['state'] ?? '' ), 'phone' => (string) ( $in['phone'] ?? '' ), 'breeder_number' => (string) ( $in['breeder_number'] ?? '' ) ] );
			if ( is_wp_error( $farm_row ) ) {
				$this->logger->error( 'Farm shell creation failed for user ' . $user_id . ': ' . $farm_row->get_error_message() );
				$farm_row = null;
			}
		}

		// Invitation accepted during signup (email must match the invite).
		if ( ! empty( $in['invite_token'] ) && 'member' !== $type ) {
			$invite = $this->farm_users->find_by_token( (string) $in['invite_token'] );
			if ( $invite ) {
				$this->farm_users->accept( $invite, (int) $user_id );
			}
		}

		$user = get_userdata( (int) $user_id );
		$this->login_user( $user, true );

		$verify_url = $verify_required ? $this->urls->verify( $this->members->issue_verification_token( (int) $member->id ) ) . '&uid=' . (int) $user_id : '';
		$this->notifier->user( (int) $user_id, ( new WelcomeEmail() )->with( [
			'verify_url'       => $verify_url,
			'dashboard_url'    => $this->urls->dashboard(),
			'is_member_signup' => 'member' === $type,
		] ) );
		$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
			'type'        => 'new_account',
			'person_name' => trim( $first . ' ' . $last ),
			'summary'     => sprintf( '%s created a %s account.', trim( $first . ' ' . $last ), 'member' === $type ? 'member (unpaid)' : 'buyer' ),
			'rows'        => array_filter( [ 'Name' => trim( $first . ' ' . $last ), 'Email' => $email, 'Phone' => Str::phone( (string) ( $in['phone'] ?? '' ) ), 'Farm' => $farm_row ? $farm_row->name : '', 'Breeder #' => $farm_row ? (string) $farm_row->breeder_number : '', 'State' => Str::state( (string) ( $in['state'] ?? '' ) ) ] ),
			'cta_label'   => 'View members',
			'cta_url'     => admin_url( 'admin.php?page=southpoll' ),
		] ) );

		$this->logger->info( "Registered user {$user_id} ({$type})" );
		do_action( 'sp/account/registered', $user, $type, $farm_row );
		return $user;
	}

	/* --------------------------------------------------------------- login */

	public function login( string $login, string $password, bool $remember = false ): WP_User|WP_Error {
		$login = trim( $login );
		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'sp_login_empty', __( 'Please enter your email and password.', 'southpoll-membership' ) );
		}
		if ( $this->lockout->is_locked( $login ) ) {
			return new WP_Error( 'sp_locked', sprintf( /* translators: %d: minutes */ __( 'Too many failed attempts. Please wait %d minutes and try again, or reset your password.', 'southpoll-membership' ), $this->lockout->minutes() ) );
		}
		$user = wp_signon( [ 'user_login' => $login, 'user_password' => $password, 'remember' => $remember ], is_ssl() );
		if ( is_wp_error( $user ) ) {
			$this->lockout->record_failure( $login );
			return new WP_Error( 'sp_login_failed', __( 'That email and password combination was not recognized.', 'southpoll-membership' ) );
		}
		$this->lockout->clear( $login );
		wp_set_current_user( $user->ID );
		$this->members->create_for_user( $user->ID ); // legacy accounts get a profile row on first login
		$this->members->touch_last_seen( $user->ID );
		$this->contexts->forget( $user->ID );
		do_action( 'sp/account/logged_in', $user );
		return $user;
	}

	public function login_user( WP_User $user, bool $remember = true ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		$this->members->touch_last_seen( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
	}

	/* ------------------------------------------------------ password reset */

	/** Always returns true (does not reveal whether the account exists). */
	public function request_password_reset( string $login_or_email ): bool {
		$login_or_email = trim( $login_or_email );
		$user           = is_email( $login_or_email ) ? get_user_by( 'email', $login_or_email ) : get_user_by( 'login', $login_or_email );
		if ( ! $user ) {
			return true;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			$this->logger->error( 'Password reset key failed: ' . $key->get_error_message() );
			return true;
		}
		$this->notifier->user( $user->ID, ( new PasswordResetEmail() )->with( [ 'reset_url' => $this->urls->reset_password( $key, $user->user_login ) ] ) );
		return true;
	}

	public function validate_reset( string $key, string $login ): WP_User|WP_Error {
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'sp_reset_invalid', __( 'This reset link is invalid or has expired. Please request a new one.', 'southpoll-membership' ) );
		}
		return $user;
	}

	public function reset_password( string $key, string $login, string $password, string $confirm ): WP_User|WP_Error {
		$user = $this->validate_reset( $key, $login );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			return new WP_Error( 'password', sprintf( /* translators: %d: minimum length */ __( 'Password must be at least %d characters.', 'southpoll-membership' ), self::MIN_PASSWORD ) );
		}
		if ( $password !== $confirm ) {
			return new WP_Error( 'password_confirm', __( 'The two passwords do not match.', 'southpoll-membership' ) );
		}
		reset_password( $user, $password );
		$this->lockout->clear( $user->user_login );
		$this->lockout->clear( $user->user_email );
		return $user;
	}

	/* ------------------------------------------------------- verification */

	public function send_verification( int $user_id, bool $is_change = false ): bool|WP_Error {
		$member = $this->members->find_by_user( $user_id );
		if ( ! $member ) {
			$member = $this->members->create_for_user( $user_id );
		}
		if ( ! $this->members->can_resend_verification( $member ) ) {
			return new WP_Error( 'sp_verify_cooldown', __( 'A verification email was sent a moment ago. Check your inbox (and spam folder) or try again in a couple of minutes.', 'southpoll-membership' ) );
		}
		$raw = $this->members->issue_verification_token( (int) $member->id );
		$url = $this->urls->verify( $raw ) . '&uid=' . $user_id;
		$to  = $is_change && '' !== (string) $member->pending_email ? (string) $member->pending_email : null;
		$msg = ( new VerifyEmail() )->with( [ 'verify_url' => $url, 'is_change' => $is_change ] );
		if ( $to ) {
			$msg->to( $to );
			return $this->notifier_send( $msg );
		}
		return $this->notifier->user( $user_id, $msg );
	}

	public function verify( int $user_id, string $token ): ?object {
		$member = $this->members->verify( $user_id, $token );
		if ( $member ) {
			$this->contexts->forget( $user_id );
			do_action( 'sp/account/verified', $user_id );
		}
		return $member;
	}

	/* ------------------------------------------------------------ profile */

	/**
	 * @param array<string, mixed> $in
	 */
	public function update_profile( WP_User $user, array $in ): true|WP_Error {
		$member = $this->members->find_by_user( $user->ID ) ?? $this->members->create_for_user( $user->ID );
		$first  = sanitize_text_field( (string) ( $in['first_name'] ?? '' ) );
		$last   = sanitize_text_field( (string) ( $in['last_name'] ?? '' ) );
		if ( '' === $first || '' === $last ) {
			return new WP_Error( 'name', __( 'First and last name are required.', 'southpoll-membership' ) );
		}
		wp_update_user( [ 'ID' => $user->ID, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ] );
		$this->members->update( (int) $member->id, [
			'first_name' => $first,
			'last_name'  => $last,
			'phone'      => Str::phone( (string) ( $in['phone'] ?? '' ) ),
			'address1'   => sanitize_text_field( (string) ( $in['address1'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $in['city'] ?? '' ) ),
			'state'      => Str::state( (string) ( $in['state'] ?? '' ) ),
			'zip'        => sanitize_text_field( (string) ( $in['zip'] ?? '' ) ),
		] );
		do_action( 'sp/account/profile_updated', $user );
		return true;
	}

	/**
	 * @param array<string, mixed> $in notify_all, default_notify_favorites, default_notify_searches
	 */
	public function update_preferences( WP_User $user, array $in ): void {
		$member = $this->members->find_by_user( $user->ID ) ?? $this->members->create_for_user( $user->ID );
		$this->members->update( (int) $member->id, [
			'notify_all'               => (int) ! empty( $in['notify_all'] ),
			'default_notify_favorites' => (int) ! empty( $in['default_notify_favorites'] ),
			'default_notify_searches'  => (int) ! empty( $in['default_notify_searches'] ),
		] );
	}

	public function change_password( WP_User $user, string $current, string $new, string $confirm ): true|WP_Error {
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'current_password', __( 'Your current password is incorrect.', 'southpoll-membership' ) );
		}
		if ( strlen( $new ) < self::MIN_PASSWORD ) {
			return new WP_Error( 'new_password', sprintf( /* translators: %d: minimum length */ __( 'Password must be at least %d characters.', 'southpoll-membership' ), self::MIN_PASSWORD ) );
		}
		if ( $new !== $confirm ) {
			return new WP_Error( 'new_password_confirm', __( 'The two passwords do not match.', 'southpoll-membership' ) );
		}
		wp_set_password( $new, $user->ID );
		$this->login_user( get_userdata( $user->ID ), true ); // wp_set_password logs the user out
		return true;
	}

	/** Start an email change: stored as pending until the new address is verified. */
	public function request_email_change( WP_User $user, string $new_email ): true|WP_Error {
		$new_email = sanitize_email( $new_email );
		if ( ! is_email( $new_email ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'southpoll-membership' ) );
		}
		if ( strtolower( $new_email ) === strtolower( $user->user_email ) ) {
			return new WP_Error( 'email', __( 'That is already your email address.', 'southpoll-membership' ) );
		}
		if ( email_exists( $new_email ) ) {
			return new WP_Error( 'email', __( 'Another account already uses that email address.', 'southpoll-membership' ) );
		}
		$member = $this->members->find_by_user( $user->ID ) ?? $this->members->create_for_user( $user->ID );
		$this->members->update( (int) $member->id, [ 'pending_email' => $new_email ] );
		$sent = $this->send_verification( $user->ID, true );
		return is_wp_error( $sent ) ? $sent : true;
	}

	public function cancel_email_change( WP_User $user ): void {
		$member = $this->members->find_by_user( $user->ID );
		if ( $member ) {
			$this->members->update( (int) $member->id, [ 'pending_email' => '', 'verification_token' => null ] );
		}
	}

	private function notifier_send( VerifyEmail $msg ): bool {
		return sp_membership()->get( \SouthPoll\Membership\Email\Mailer::class )->send( $msg );
	}
}
