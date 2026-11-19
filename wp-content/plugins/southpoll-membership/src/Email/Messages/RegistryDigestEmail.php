<?php
/**
 * Registry digest: who to add to / remove from the cattle registry since the previous digest. Sent to the office.
 *
 * Data: period_start, period_end (display strings), add (rows), remove (rows), renewed (rows), csv_path (attachment),
 *       admin_url (SouthPoll → Memberships), settings_url. Row keys: farm, owner, breeder_number, email, phone, state,
 *       starts, ends, tier, method, note.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email\Messages;

use SouthPoll\Membership\Email\Message;

final class RegistryDigestEmail extends Message {

	public function key(): string {
		return 'admin.registry_digest';
	}

	public function subject(): string {
		$add    = count( (array) $this->get( 'add', [] ) );
		$remove = count( (array) $this->get( 'remove', [] ) );
		if ( ! $add && ! $remove ) {
			return sprintf( /* translators: %s: period */ __( 'Registry digest — no changes (%s)', 'southpoll-membership' ), (string) $this->get( 'period_label' ) );
		}
		return sprintf( /* translators: 1: adds, 2: removals, 3: period */ __( 'Registry digest — add %1$d, remove %2$d (%3$s)', 'southpoll-membership' ), $add, $remove, (string) $this->get( 'period_label' ) );
	}

	public function heading(): string {
		return __( 'Cattle registry: who to add and remove', 'southpoll-membership' );
	}

	public function preheader(): string {
		return sprintf( /* translators: 1: adds, 2: removals */ __( '%1$d to add · %2$d to remove', 'southpoll-membership' ), count( (array) $this->get( 'add', [] ) ), count( (array) $this->get( 'remove', [] ) ) );
	}

	public function body(): string {
		$add     = (array) $this->get( 'add', [] );
		$remove  = (array) $this->get( 'remove', [] );
		$renewed = (array) $this->get( 'renewed', [] );
		$out     = $this->p( sprintf( /* translators: 1: from, 2: to */ __( 'Membership changes between <strong>%1$s</strong> and <strong>%2$s</strong> (site time). The same list is attached as a CSV.', 'southpoll-membership' ), esc_html( (string) $this->get( 'period_start' ) ), esc_html( (string) $this->get( 'period_end' ) ) ) );
		$out    .= $this->section( __( 'ADD to the registry', 'southpoll-membership' ), $add, '#1e8e3e', __( 'No memberships became active in this period.', 'southpoll-membership' ) );
		$out    .= $this->section( __( 'REMOVE from the registry', 'southpoll-membership' ), $remove, '#a71000', __( 'No memberships expired or were cancelled in this period.', 'southpoll-membership' ) );
		if ( $renewed ) {
			$out .= $this->section( __( 'Renewed (already on the registry — no action)', 'southpoll-membership' ), $renewed, '#5b6770', '' );
		}
		return $out;
	}

	public function cta(): ?array {
		return [ 'label' => __( 'Open Memberships in SouthPoll', 'southpoll-membership' ), 'url' => (string) $this->get( 'admin_url', admin_url( 'admin.php?page=southpoll-memberships' ) ) ];
	}

	public function footer_note(): string {
		return sprintf( /* translators: %s: settings url */ __( 'You receive this because your address is listed under SouthPoll → Settings → Cattle registry digest (%s).', 'southpoll-membership' ), (string) $this->get( 'settings_url', '' ) );
	}

	public function attachments(): array {
		$csv = (string) $this->get( 'csv_path', '' );
		return '' !== $csv && file_exists( $csv ) ? [ $csv ] : [];
	}

	/** @param array<int, array<string, string>> $rows */
	private function section( string $title, array $rows, string $color, string $empty ): string {
		$html = '<h3 style="margin:20px 0 8px;font-size:15px;color:' . esc_attr( $color ) . ';">' . esc_html( $title ) . ' <span style="font-weight:400;color:#5b6770;">(' . count( $rows ) . ')</span></h3>';
		if ( ! $rows ) {
			return $html . ( '' !== $empty ? $this->p( '<em>' . esc_html( $empty ) . '</em>' ) : '' );
		}
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 8px;font-size:13px;">';
		$html .= '<tr>' . implode( '', array_map( static fn( $h ) => '<th align="left" style="padding:6px 8px;border-bottom:2px solid #d9d9d9;color:#5b6770;font-weight:600;">' . esc_html( $h ) . '</th>', [ __( 'Farm', 'southpoll-membership' ), __( 'Owner', 'southpoll-membership' ), __( 'Breeder #', 'southpoll-membership' ), __( 'Email', 'southpoll-membership' ), __( 'State', 'southpoll-membership' ), __( 'Term', 'southpoll-membership' ), __( 'Paid via', 'southpoll-membership' ) ] ) ) . '</tr>';
		foreach ( $rows as $r ) {
			$html .= '<tr>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;"><strong>' . esc_html( (string) ( $r['farm'] ?? '' ) ) . '</strong>' . ( ! empty( $r['note'] ) ? '<br><span style="color:#5b6770;">' . esc_html( (string) $r['note'] ) . '</span>' : '' ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . esc_html( (string) ( $r['owner'] ?? '' ) ) . ( ! empty( $r['phone'] ) ? '<br>' . esc_html( (string) $r['phone'] ) : '' ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . esc_html( (string) ( $r['breeder_number'] ?? '' ) ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . esc_html( (string) ( $r['email'] ?? '' ) ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . esc_html( (string) ( $r['state'] ?? '' ) ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;white-space:nowrap;">' . esc_html( trim( (string) ( $r['starts'] ?? '' ) . ' → ' . (string) ( $r['ends'] ?? '' ), ' →' ) ) . '</td>'
				. '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . esc_html( trim( (string) ( $r['tier'] ?? '' ) . ' ' . (string) ( $r['method'] ?? '' ) ) ) . '</td>'
				. '</tr>';
		}
		return $html . '</table>';
	}
}
