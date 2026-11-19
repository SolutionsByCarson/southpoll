<?php
/**
 * Contact forms (farm page + listing page): validation, anti-spam layers (honeypot, fill time, rate limit, reCAPTCHA v3
 * for guests), delivery to the farm's contacts with Reply-To set to the sender, optional admin copy, sp_inquiries log.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Contact;

use SouthPoll\Membership\Auth\Honeypot;
use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Messages\AdminNoticeEmail;
use SouthPoll\Membership\Email\Messages\FarmInquiryEmail;
use SouthPoll\Membership\Email\Messages\ListingInquiryEmail;
use SouthPoll\Membership\Email\Notifier;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Farms\FarmVisibility;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Rest\RateLimiter;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;

final class InquiryService {

	public const RECAPTCHA_ACTION = 'farm_inquiry';
	public const MIN_SECONDS      = 3;

	public function __construct(
		private FarmRepository $farms,
		private FarmVisibility $visibility,
		private FarmUserRepository $farm_users,
		private InquiryRepository $inquiries,
		private ListingRepository $listings,
		private Recaptcha $recaptcha,
		private Mailer $mailer,
		private Notifier $notifier,
		private Settings $settings,
		private Urls $urls,
		private Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $in farm_id, name, email, phone, message, elapsed, g-recaptcha-response, sp_website_url
	 */
	public function submit_farm( array $in, MemberContext $ctx, string $ip_hash ): \stdClass|WP_Error {
		$farm = $this->farms->find( (int) ( $in['farm_id'] ?? 0 ) );
		if ( ! $farm || ! $this->visibility->is_public( $farm ) ) {
			return new WP_Error( 'sp_farm_unavailable', __( 'This farm is not accepting messages right now.', 'southpoll-membership' ), [ 'status' => 404 ] );
		}
		return $this->submit( $farm, null, $in, $ctx, $ip_hash );
	}

	/**
	 * @param array<string, mixed> $in listing_id, name, email, phone, message, elapsed, g-recaptcha-response, sp_website_url
	 */
	public function submit_listing( array $in, MemberContext $ctx, string $ip_hash ): \stdClass|WP_Error {
		$listing = $this->listings->find( (int) ( $in['listing_id'] ?? 0 ) );
		$farm    = $listing ? $this->farms->find( $listing->farm_id ) : null;
		if ( ! $listing || ! $farm || ! $this->visibility->is_public( $farm ) || ! $listing->is_public( (int) $this->settings->get( 'listing_sold_visible_days', 14 ) ) ) {
			return new WP_Error( 'sp_listing_unavailable', __( 'This listing is no longer available.', 'southpoll-membership' ), [ 'status' => 404 ] );
		}
		return $this->submit( $farm, $listing, $in, $ctx, $ip_hash );
	}

	private function submit( object $farm, ?object $listing, array $in, MemberContext $ctx, string $ip_hash ): \stdClass|WP_Error {
		if ( ! (int) $farm->contact_form_enabled && ! $ctx->is_staff() ) {
			return new WP_Error( 'sp_form_disabled', __( 'This farm has turned off its contact form.', 'southpoll-membership' ), [ 'status' => 403 ] );
		}
		// Anti-spam layer 1: honeypot + minimum fill time (client-measured; cheap filter for naive bots).
		if ( '' !== trim( (string) ( $in[ Honeypot::FIELD ] ?? '' ) ) ) {
			$this->logger->info( 'Inquiry blocked by honeypot', [ 'farm' => (int) $farm->id ] );
			return new WP_Error( 'sp_spam', __( 'Your message could not be sent.', 'southpoll-membership' ), [ 'status' => 400 ] );
		}
		if ( ! $ctx->is_logged_in() && ( ! isset( $in['elapsed'] ) || (float) $in['elapsed'] < self::MIN_SECONDS ) ) {
			return new WP_Error( 'sp_too_fast', __( 'Please take a moment to review your message, then send it again.', 'southpoll-membership' ), [ 'status' => 400 ] );
		}
		// Anti-spam layer 2: rate limit per IP (guests) / user.
		$limit = (int) $this->settings->get( 'contact_form_rate_limit', 5 );
		$key   = 'inquiry:' . ( $ctx->is_logged_in() ? 'u' . $ctx->user_id : 'ip' . $ip_hash );
		if ( ! RateLimiter::hit( $key, $limit, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'sp_rate_limited', __( 'You have sent several messages recently. Please wait a while before sending another.', 'southpoll-membership' ), [ 'status' => 429 ] );
		}
		// Anti-spam layer 3: reCAPTCHA v3 for guests (logged-in accounts were verified at signup).
		$score = null;
		if ( ! $ctx->is_logged_in() ) {
			$score = $this->recaptcha->verify( (string) ( $in[ Recaptcha::FIELD ] ?? '' ), self::RECAPTCHA_ACTION );
			if ( null === $score ) {
				return new WP_Error( 'sp_recaptcha', __( 'We could not confirm you are human. Please reload the page and try again.', 'southpoll-membership' ), [ 'status' => 400 ] );
			}
		}

		$errors  = new WP_Error();
		$name    = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$email   = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$phone   = trim( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
		$message = trim( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ) );
		$max     = (int) $this->settings->get( 'inquiry_max_message_chars', 2000 );
		if ( mb_strlen( $name ) < 2 ) {
			$errors->add( 'name', __( 'Please enter your name.', 'southpoll-membership' ) );
		}
		if ( ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address so the seller can reply.', 'southpoll-membership' ) );
		}
		if ( mb_strlen( $message ) < 10 ) {
			$errors->add( 'message', __( 'Please write a short message (at least 10 characters).', 'southpoll-membership' ) );
		} elseif ( mb_strlen( $message ) > $max ) {
			$errors->add( 'message', sprintf( /* translators: %d: character limit */ __( 'Please keep your message under %d characters.', 'southpoll-membership' ), $max ) );
		} elseif ( preg_match_all( '#https?://#i', $message ) > 3 ) {
			$errors->add( 'message', __( 'Please include no more than three links.', 'southpoll-membership' ) );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( [ 'status' => 422 ] );
			return $errors;
		}

		// Recipients: farm contact email (falls back to the owner's login email) + managers who get listing emails.
		$owner      = get_userdata( (int) $farm->owner_user_id );
		$recipients = [ is_email( (string) $farm->email ) ? (string) $farm->email : ( $owner ? $owner->user_email : '' ) ];
		foreach ( $this->farm_users->notify_user_ids( (int) $farm->id, 'notify_listings' ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$recipients[] = $u->user_email;
			}
		}
		$recipients = array_values( array_unique( array_filter( $recipients, 'is_email' ) ) );
		if ( empty( $recipients ) ) {
			return new WP_Error( 'sp_no_recipient', __( 'This farm has no contact email on file. Please try again later.', 'southpoll-membership' ), [ 'status' => 500 ] );
		}

		$farm_url = $this->urls->farm( (string) $farm->slug );
		$common   = [
			'farm_name'    => (string) $farm->name,
			'farm_url'     => $farm_url,
			'sender_name'  => $name,
			'sender_email' => $email,
			'sender_phone' => $phone,
			'message'      => $message,
			'sender_user'  => $ctx->is_logged_in() ? get_userdata( $ctx->user_id ) : null,
			'my_farm_url'  => $this->urls->dashboard( 'my-farm' ),
		];
		$email_msg = $listing
			? ( new ListingInquiryEmail() )->to( $recipients )->with( $common + [ 'title' => $listing->title, 'listing_url' => $this->urls->listing( $listing->slug ) ] )
			: ( new FarmInquiryEmail() )->to( $recipients )->with( $common );
		$sent      = $this->mailer->send( $email_msg );
		if ( $this->settings->get( 'inquiry_admin_copy', false ) ) {
			$this->notifier->admin( ( new AdminNoticeEmail() )->with( [
				'type'      => $listing ? 'listing_inquiry' : 'inquiry',
				'farm_name' => (string) $farm->name,
				'summary'   => $listing
					? sprintf( /* translators: 1: sender, 2: listing, 3: farm */ __( '%1$s sent a message about "%2$s" (%3$s) through the listing contact form (copy).', 'southpoll-membership' ), $name, $listing->title, (string) $farm->name )
					: sprintf( /* translators: 1: sender, 2: farm */ __( '%1$s sent a message to %2$s through the farm page contact form (copy).', 'southpoll-membership' ), $name, (string) $farm->name ),
				'rows'      => array_filter( [ 'From' => $name . ' &lt;' . esc_html( $email ) . '&gt;', 'Phone' => $phone, 'Message' => nl2br( esc_html( $message ) ), 'Delivered to' => implode( ', ', $recipients ) ] ),
				'cta_label' => $listing ? __( 'Open listing', 'southpoll-membership' ) : __( 'Open farm page', 'southpoll-membership' ),
				'cta_url'   => $listing ? $this->urls->listing( $listing->slug ) : $farm_url,
			] ) );
		}
		$row = $this->inquiries->create( [
			'target_type'     => $listing ? 'listing' : 'farm',
			'farm_id'         => (int) $farm->id,
			'listing_id'      => $listing ? $listing->id : null,
			'sender_user_id'  => $ctx->user_id ?: null,
			'sender_name'     => $name,
			'sender_email'    => $email,
			'sender_phone'    => $phone,
			'message'         => $message,
			'ip_hash'         => $ip_hash,
			'recaptcha_score' => $score,
			'delivered_to'    => implode( ',', $recipients ),
		] );
		$this->logger->info( "Inquiry #{$row->id} sent to farm {$farm->id}" . ( $listing ? " (listing {$listing->id})" : '' ) . ( $sent ? '' : ' (mailer reported failure)' ) );
		do_action( 'sp/inquiry/sent', $row, $farm, $ctx, $listing );
		return $row;
	}
}
