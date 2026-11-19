<?php
/**
 * sp_inquiries: log of contact-form messages sent to farms (and, in Phase 3, listing sellers).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Contact;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Dates;

final class InquiryRepository {

	private function table(): string {
		return Schema::table( 'sp_inquiries' );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): object {
		global $wpdb;
		$wpdb->insert( $this->table(), [
			'target_type'     => in_array( $data['target_type'] ?? 'farm', [ 'farm', 'listing' ], true ) ? $data['target_type'] : 'farm',
			'farm_id'         => ! empty( $data['farm_id'] ) ? (int) $data['farm_id'] : null,
			'listing_id'      => ! empty( $data['listing_id'] ) ? (int) $data['listing_id'] : null,
			'sender_user_id'  => ! empty( $data['sender_user_id'] ) ? (int) $data['sender_user_id'] : null,
			'sender_name'     => sanitize_text_field( (string) ( $data['sender_name'] ?? '' ) ),
			'sender_email'    => sanitize_email( (string) ( $data['sender_email'] ?? '' ) ),
			'sender_phone'    => sanitize_text_field( (string) ( $data['sender_phone'] ?? '' ) ),
			'message'         => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
			'ip_hash'         => (string) ( $data['ip_hash'] ?? '' ),
			'recaptcha_score' => isset( $data['recaptcha_score'] ) ? (float) $data['recaptcha_score'] : null,
			'delivered_to'    => substr( (string) ( $data['delivered_to'] ?? '' ), 0, 255 ),
			'created_at'      => Dates::now(),
		] );
		return $this->find( (int) $wpdb->insert_id );
	}

	/** @return object[] newest first */
	public function for_farm( int $farm_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE farm_id = %d ORDER BY id DESC LIMIT %d", $farm_id, $limit ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function count_since( string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
