<?php
/**
 * sp_notification_queue: outbound alert emails, drained in batches by the five-minute queue job.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Engagement;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;

final class NotificationQueue {

	public const MAX_ATTEMPTS = 3;

	private function table(): string {
		return Schema::table( 'sp_notification_queue' );
	}

	/** @param array<string, mixed> $payload */
	public function enqueue( string $email_key, int $user_id, string $recipient, array $payload, ?string $send_after = null ): int {
		global $wpdb;
		$wpdb->insert( $this->table(), [
			'email_key'  => sanitize_key( str_replace( '.', '_', $email_key ) ) === '' ? 'alert' : $email_key,
			'user_id'    => $user_id ?: null,
			'recipient'  => sanitize_email( $recipient ),
			'payload'    => wp_json_encode( $payload ),
			'send_after' => $send_after ?: Dates::now(),
			'attempts'   => 0,
			'created_at' => Dates::now(),
		] );
		return (int) $wpdb->insert_id;
	}

	/** Already waiting for this user + key + object (de-dupe burst publishes)? */
	public function has_pending( string $email_key, int $user_id, string $needle ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE email_key = %s AND user_id = %d AND sent_at IS NULL AND payload LIKE %s", $email_key, $user_id, '%' . $wpdb->esc_like( $needle ) . '%' ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return object[] rows ready to send (payload decoded) */
	public function pending( int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE sent_at IS NULL AND send_after <= %s AND attempts < %d ORDER BY id LIMIT %d", Dates::now(), self::MAX_ATTEMPTS, $limit ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$p          = json_decode( (string) $r->payload, true );
			$r->payload = is_array( $p ) ? $p : [];
		}
		return $rows;
	}

	public function mark_sent( int $id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'sent_at' => Dates::now(), 'attempts' => 1 ], [ 'id' => $id ] );
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET attempts = attempts + 1, error = %s, send_after = %s WHERE id = %d", mb_substr( $error, 0, 500 ), Dates::add_days( Dates::now(), 0 ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE sent_at IS NULL AND attempts < %d", self::MAX_ATTEMPTS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Remove sent/failed rows older than N days. */
	public function purge( int $days ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE (sent_at IS NOT NULL OR attempts >= %d) AND created_at < %s", self::MAX_ATTEMPTS, Dates::add_days( Dates::now(), -$days ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
