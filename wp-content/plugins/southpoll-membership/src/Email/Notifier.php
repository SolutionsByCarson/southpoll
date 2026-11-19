<?php
/**
 * Routes messages to the right people: a member, a farm's membership contacts, or the membership admin.
 *
 * Deferrable messages (reminders, expiry notices — see Message::deferrable()) are not sent on the spot: they go into the
 * notification queue with a send_after inside the configured send window (settings email_window_start / email_window_end,
 * site time) and the five-minute queue job sends them in capped batches. Transactional mail is sent immediately.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Engagement\NotificationQueue;
use SouthPoll\Membership\Members\FarmUserRepository;
use SouthPoll\Membership\Support\Dates;

final class Notifier {

	public const QUEUE_KEY = 'message';

	public function __construct( private Mailer $mailer, private Settings $settings, private FarmUserRepository $farm_users, private NotificationQueue $queue ) {}

	/** Send to a WP user. */
	public function user( int $user_id, Message $message ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return $this->dispatch( $message->to( $user->user_email )->with( [ 'recipient_name' => $this->name( $user ) ] ), $user_id );
	}

	/** Send to the farm's owner, the billing user (if set), and managers who opted into membership notices. */
	public function farm_membership_contacts( object $farm, Message $message ): bool {
		$ids = $this->farm_users->notify_user_ids( (int) $farm->id, 'notify_membership' );
		$ids[] = (int) $farm->owner_user_id;
		if ( ! empty( $farm->billing_user_id ) ) {
			$ids[] = (int) $farm->billing_user_id;
		}
		$emails = [];
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$emails[] = $u->user_email;
			}
		}
		if ( empty( $emails ) ) {
			return false;
		}
		$owner = get_userdata( (int) $farm->owner_user_id );
		return $this->dispatch( $message->to( $emails )->with( [ 'recipient_name' => $owner ? $this->name( $owner ) : '', 'farm_name' => (string) $farm->name ] ), (int) $farm->owner_user_id );
	}

	/** Send to the farm's listing contacts: the owner plus managers who opted into listing emails. */
	public function farm_listing_contacts( object $farm, Message $message ): bool {
		$ids   = $this->farm_users->notify_user_ids( (int) $farm->id, 'notify_listings' );
		$ids[] = (int) $farm->owner_user_id;
		$emails = [];
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$emails[] = $u->user_email;
			}
		}
		if ( empty( $emails ) ) {
			return false;
		}
		$owner = get_userdata( (int) $farm->owner_user_id );
		return $this->dispatch( $message->to( $emails )->with( [ 'recipient_name' => $owner ? $this->name( $owner ) : '', 'farm_name' => (string) $farm->name ] ), (int) $farm->owner_user_id );
	}

	/** Send to the membership admin address(es) from settings. */
	public function admin( Message $message ): bool {
		$emails = (array) $this->settings->get( 'admin_notification_emails', [] );
		$contact = (string) $this->settings->get( 'admin_contact.email', '' );
		if ( empty( $emails ) && '' !== $contact ) {
			$emails = [ $contact ];
		}
		if ( empty( $emails ) ) {
			$emails = [ (string) get_option( 'admin_email' ) ];
		}
		return $this->dispatch( $message->to( $emails ), 0 );
	}

	public function name( \WP_User $user ): string {
		return trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
	}

	/* ---------------------------------------------------------------- queue */

	/** Send now, or queue for the send window when the message allows it. Returns true when sent or queued. */
	private function dispatch( Message $message, int $user_id ): bool {
		if ( ! $message->deferrable() || ! $this->deferral_enabled() ) {
			return $this->mailer->send( $message );
		}
		return $this->queue( $message, $user_id ) > 0;
	}

	/** Put a fully prepared message in the queue (recipients + data are stored, the class is rebuilt on send). */
	public function queue( Message $message, int $user_id = 0, ?string $send_after = null ): int {
		$to = $message->recipients();
		if ( empty( $to ) ) {
			return 0;
		}
		$payload = [
			'class' => get_class( $message ),
			'key'   => $message->key(),
			'to'    => $to,
			'data'  => $message->data(),
		];
		return $this->queue->enqueue( self::QUEUE_KEY, $user_id, (string) $to[0], $payload, $send_after ?: $this->send_after() );
	}

	/** Rebuild a queued message from its payload (queue worker). */
	public function rebuild( object $row ): ?Message {
		$p     = (array) $row->payload;
		$class = (string) ( $p['class'] ?? '' );
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\Messages\\' ) || ! class_exists( $class ) || ! is_subclass_of( $class, Message::class ) ) {
			return null;
		}
		/** @var Message $message */
		$message = new $class();
		return $message->to( (array) ( $p['to'] ?? [] ) )->with( (array) ( $p['data'] ?? [] ) );
	}

	/** Deferral is off when no send window is configured (tests, "send immediately" preference). */
	public function deferral_enabled(): bool {
		return '' !== trim( (string) $this->settings->get( 'email_window_start', '' ) );
	}

	/**
	 * Earliest send time (UTC, MySQL format) inside the window: now when we are inside it, otherwise the next window start.
	 * Window bounds are site-local "HH:MM"; an empty end means "until midnight".
	 */
	public function send_after( ?\DateTimeImmutable $now = null ): string {
		$tz    = wp_timezone();
		$now   = $now ? $now->setTimezone( $tz ) : new \DateTimeImmutable( 'now', $tz );
		$start = self::parse_time( (string) $this->settings->get( 'email_window_start', '' ) );
		$end   = self::parse_time( (string) $this->settings->get( 'email_window_end', '' ) );
		if ( null === $start ) {
			return Dates::now();
		}
		$open  = $now->setTime( $start[0], $start[1] );
		$close = $end ? $now->setTime( $end[0], $end[1] ) : $now->setTime( 23, 59, 59 );
		if ( $close <= $open ) { // nonsensical window: treat as open from start until midnight
			$close = $now->setTime( 23, 59, 59 );
		}
		if ( $now < $open ) {
			$at = $open;
		} elseif ( $now > $close ) {
			$at = $open->modify( '+1 day' );
		} else {
			return Dates::now();
		}
		return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** @return array{0:int,1:int}|null hours, minutes */
	public static function parse_time( string $hhmm ): ?array {
		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $hhmm ), $m ) ? [ (int) $m[1], (int) $m[2] ] : null;
	}
}
