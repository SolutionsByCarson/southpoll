<?php
/**
 * Soft-launch feedback (S8): validate a submission from the floating button, store it, e-mail the team.
 *
 * Logged-in users: no captcha, name/e-mail taken from the account. Guests (login page): honeypot, minimum fill time,
 * reCAPTCHA v3 when configured, name + e-mail required. Everyone: rate limit per account / IP.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Feedback;

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Messages\FeedbackEmail;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Rest\RateLimiter;
use WP_Error;

final class FeedbackService {

	public const RECAPTCHA_ACTION = 'feedback';
	public const MIN_SECONDS      = 3;
	public const RATE_LIMIT       = 10; // per account / IP per hour
	public const MAX_CHARS        = 5000;
	public const SCREENSHOT_MB    = 5;

	public function __construct(
		private FeedbackRepository $feedback,
		private Settings $settings,
		private Recaptcha $recaptcha,
		private Mailer $mailer,
		private Logger $logger
	) {}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'feedback_enabled', false );
	}

	/** @return string[] */
	public function recipients(): array {
		$list = array_values( array_filter( array_map( 'sanitize_email', (array) $this->settings->get( 'feedback_emails', [] ) ), 'is_email' ) );
		return $list ?: array_values( array_filter( array_map( 'sanitize_email', (array) $this->settings->get( 'admin_notification_emails', [] ) ), 'is_email' ) );
	}

	/**
	 * @param array<string, mixed> $in name, email, message, page_url, user_agent, screenshot_id, elapsed, g-recaptcha-response, sp_website_url
	 */
	public function submit( array $in, MemberContext $ctx, string $ip_hash ): \stdClass|WP_Error {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'sp_feedback_off', __( 'Feedback is not open right now.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		if ( '' !== trim( (string) ( $in[ Honeypot::FIELD ] ?? '' ) ) ) {
			return new WP_Error( 'sp_spam', __( 'Your message could not be sent.', 'southpoll-membership' ), [ 'status' => 400 ] );
		}
		if ( ! $ctx->is_logged_in() && ( ! isset( $in['elapsed'] ) || (float) $in['elapsed'] < self::MIN_SECONDS ) ) {
			return new WP_Error( 'sp_too_fast', __( 'Please take a moment to write your message, then send it again.', 'southpoll-membership' ), [ 'status' => 400 ] );
		}
		$key = 'feedback:' . ( $ctx->is_logged_in() ? 'u' . $ctx->user_id : 'ip' . $ip_hash );
		if ( ! RateLimiter::hit( $key, self::RATE_LIMIT, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'sp_rate_limited', __( 'You have sent several messages recently. Please wait a while before sending another.', 'southpoll-membership' ), [ 'status' => 429 ] );
		}
		$score = null;
		if ( ! $ctx->is_logged_in() && $this->recaptcha->enabled() ) {
			$score = $this->recaptcha->verify( (string) ( $in[ Recaptcha::FIELD ] ?? '' ), self::RECAPTCHA_ACTION );
			if ( null === $score ) {
				return new WP_Error( 'sp_recaptcha', __( 'We could not confirm you are human. Please reload the page and try again.', 'southpoll-membership' ), [ 'status' => 400 ] );
			}
		}

		$errors  = new WP_Error();
		$message = trim( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ) );
		if ( strlen( $message ) < 5 ) {
			$errors->add( 'message', __( 'Please tell us what happened or what you would like to see.', 'southpoll-membership' ) );
		} elseif ( mb_strlen( $message ) > self::MAX_CHARS ) {
			$errors->add( 'message', sprintf( /* translators: %d: characters */ __( 'Please keep your message under %d characters.', 'southpoll-membership' ), self::MAX_CHARS ) );
		}
		if ( $ctx->is_logged_in() ) {
			$user      = get_userdata( $ctx->user_id );
			$name      = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
			$full_name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
			if ( '' === $name ) {
				$name = '' !== $full_name ? $full_name : ( $user ? (string) $user->display_name : '' );
			}
			$email = $user ? (string) $user->user_email : sanitize_email( (string) ( $in['email'] ?? '' ) );
		} else {
			$name  = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
			$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
			if ( '' === $name ) {
				$errors->add( 'name', __( 'Please enter your name.', 'southpoll-membership' ) );
			}
			if ( '' === $email || ! is_email( $email ) ) {
				$errors->add( 'email', __( 'Please enter a valid e-mail address so we can reply.', 'southpoll-membership' ) );
			}
		}
		$screenshot_id = (int) ( $in['screenshot_id'] ?? 0 );
		if ( $screenshot_id > 0 ) {
			// Only an image this same user uploaded through /feedback/upload may be attached.
			if ( ! $ctx->is_logged_in() || 'attachment' !== get_post_type( $screenshot_id ) || (int) get_post_meta( $screenshot_id, '_sp_feedback_user', true ) !== $ctx->user_id ) {
				$screenshot_id = 0;
			}
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( [ 'status' => 422 ] );
			return $errors;
		}
		$row = $this->feedback->insert( [
			'user_id'       => $ctx->is_logged_in() ? $ctx->user_id : 0,
			'name'          => $name,
			'email'         => $email,
			'page_url'      => esc_url_raw( (string) ( $in['page_url'] ?? '' ) ),
			'user_agent'    => sanitize_text_field( (string) ( $in['user_agent'] ?? '' ) ),
			'message'       => $message,
			'screenshot_id' => $screenshot_id,
			'ip_hash'       => $ip_hash,
		] );
		if ( $screenshot_id ) {
			update_post_meta( $screenshot_id, '_sp_feedback_id', (int) $row->id );
		}
		$this->logger->info( "Feedback #{$row->id} from " . ( $ctx->is_logged_in() ? "user {$ctx->user_id}" : 'guest' ) . ( null !== $score ? " (reCAPTCHA {$score})" : '' ) );
		$this->notify( $row, $ctx );
		do_action( 'sp/feedback/submitted', $row, $ctx );
		return $row;
	}

	private function notify( object $row, MemberContext $ctx ): void {
		$to = $this->recipients();
		if ( ! $to ) {
			$this->logger->warning( "Feedback #{$row->id}: no recipient configured (Settings → Launch & feedback)." );
			return;
		}
		$farm = $ctx->has_farm() ? (string) $ctx->farm->name : '';
		$this->mailer->send( ( new FeedbackEmail() )->to( $to )->with( [
			'id'          => (int) $row->id,
			'name'        => (string) $row->name,
			'email'       => (string) $row->email,
			'farm'        => $farm,
			'role'        => $ctx->is_logged_in() ? ( $ctx->is_staff() ? 'staff' : ( $ctx->is_owner() ? 'farm owner' : ( $ctx->is_manager() ? 'account manager' : 'buyer' ) ) ) : 'guest (login page)',
			'page_url'    => (string) $row->page_url,
			'user_agent'  => (string) $row->user_agent,
			'message'     => (string) $row->message,
			'screenshot'  => $row->screenshot_id ? (string) wp_get_attachment_url( (int) $row->screenshot_id ) : '',
			'admin_url'   => admin_url( 'admin.php?page=southpoll-feedback&q=' . (int) $row->id ),
			'created_at'  => (string) $row->created_at,
		] ) );
	}
}
