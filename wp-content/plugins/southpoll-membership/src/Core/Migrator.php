<?php
/**
 * Versioned, idempotent schema + data migrations.
 *
 * Runs dbDelta for every table on each version change (dbDelta only applies differences), then
 * any data steps whose version is newer than the stored one.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use SouthPoll\Membership\Support\Dates;

final class Migrator {

	public const OPTION_VERSION = 'sp_db_version';
	public const OPTION_ERROR   = 'sp_db_error';

	public function __construct( private Logger $logger, private Seeder $seeder ) {}

	public function current_version(): string {
		return (string) get_option( self::OPTION_VERSION, '0' );
	}

	public function needs_migration(): bool {
		return version_compare( $this->current_version(), SP_DB_VERSION, '<' ) || ! empty( Schema::missing_tables() );
	}

	/**
	 * Called on every request (cheap option read); migrates only when needed.
	 */
	public function maybe_migrate(): void {
		if ( $this->current_version() === SP_DB_VERSION ) {
			return;
		}
		$this->migrate();
	}

	/**
	 * Apply schema and data migrations. Safe to call repeatedly.
	 */
	public function migrate(): void {
		global $wpdb;

		if ( 'yes' === get_transient( 'sp_migrating' ) ) {
			return;
		}
		set_transient( 'sp_migrating', 'yes', 5 * MINUTE_IN_SECONDS );

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$wpdb->hide_errors();
			foreach ( Schema::tables() as $short => $sql ) {
				dbDelta( $sql );
			}
			$wpdb->show_errors();

			$missing = Schema::missing_tables();
			if ( ! empty( $missing ) ) {
				$message = 'Schema migration failed; missing tables: ' . implode( ', ', $missing ) . ( $wpdb->last_error ? ' — ' . $wpdb->last_error : '' );
				$this->logger->error( $message );
				update_option( self::OPTION_ERROR, $message, false );
				return;
			}

			$from = $this->current_version();
			foreach ( $this->data_steps() as $version => $callback ) {
				if ( version_compare( $from, $version, '<' ) ) {
					$callback();
					$this->logger->info( "Data migration step {$version} applied." );
				}
			}

			update_option( self::OPTION_VERSION, SP_DB_VERSION, true );
			delete_option( self::OPTION_ERROR );
			Plugin::request_rewrite_flush(); // new versions usually ship new routes
			$this->logger->info( "Migrated database from {$from} to " . SP_DB_VERSION . '.' );
		} finally {
			delete_transient( 'sp_migrating' );
		}
	}

	/**
	 * Data migrations keyed by the version that introduced them. Each must be idempotent.
	 *
	 * @return array<string, callable>
	 */
	private function data_steps(): array {
		return [
			'0.1.0' => function (): void {
				$this->seeder->seed_settings();
				$this->seeder->seed_default_tier();
				$this->seeder->seed_farm_meta_fields();
				$this->seeder->import_legacy_integrations();
			},
			'0.3.0' => function (): void {
				$this->seeder->seed_settings();
				$this->seeder->seed_offerings();
				$this->seeder->backfill_farm_regions();
			},
			'0.4.0' => function (): void {
				$this->seeder->seed_settings();
				$this->seeder->seed_listing_categories();
			},
			'0.5.1' => function (): void {
				$this->repair_phantom_renewals();
			},
		];
	}

	/**
	 * Feedback round 1 (F1.1 / R1.2): before 0.3.1 the invoice.paid event of a one-time Checkout Session ran the
	 * recurring-renewal path, which added a second "automatic renewal" payment row, extended the term a second year and
	 * switched auto-renew on without a Stripe subscription. Such rows are recognisable: method stripe, recurring, no
	 * subscription id, the "Automatic renewal (invoice …)" note. Each one is voided, the membership's end date goes back
	 * to the row's term_start (the end date before the phantom extension) when it still equals the row's term_end, and
	 * auto-renew is cleared when the membership has no subscription. Idempotent (voided rows are skipped).
	 *
	 * @return int rows repaired
	 */
	public function repair_phantom_renewals(): int {
		global $wpdb;
		$payments    = Schema::table( 'sp_payments' );
		$memberships = Schema::table( 'sp_memberships' );
		$rows        = $wpdb->get_results( "SELECT * FROM {$payments} WHERE method = 'stripe' AND recurring = 1 AND status = 'succeeded' AND (stripe_subscription_id IS NULL OR stripe_subscription_id = '') AND notes LIKE '%Automatic renewal (invoice%' ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fixed       = 0;
		foreach ( $rows as $p ) {
			$now  = Dates::now();
			$m    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE id = %d", (int) $p->membership_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$note = "[{$now}] Voided: not a payment — Stripe's invoice.paid for the one-time checkout was recorded as an automatic renewal (fixed in 0.3.1)";
			$wpdb->update( $payments, [ 'status' => 'void', 'notes' => trim( (string) $p->notes . "\n" . $note ), 'updated_at' => $now ], [ 'id' => (int) $p->id ] );
			if ( $m ) {
				$data = [];
				$what = [];
				if ( '' !== (string) $p->term_start && (string) $m->ends_at === (string) $p->term_end ) {
					$data['ends_at'] = (string) $p->term_start;
					$data['status']  = Dates::is_past( (string) $p->term_start, $now ) ? 'expired' : 'active';
					$what[]          = "end date restored from {$m->ends_at} to {$data['ends_at']}";
				}
				if ( (int) $m->auto_renew === 1 && '' === (string) $m->stripe_subscription_id ) {
					$data['auto_renew'] = 0;
					$what[]             = 'auto-renew cleared (no Stripe subscription)';
				}
				if ( ! empty( $data ) ) {
					$data['notes']      = trim( (string) $m->notes . "\n[{$now}] Phantom renewal from payment #{$p->id} undone: " . implode( '; ', $what ) );
					$data['updated_at'] = $now;
					$wpdb->update( $memberships, $data, [ 'id' => (int) $m->id ] );
				}
			}
			$this->logger->info( "Phantom renewal voided: payment #{$p->id} (membership #{$p->membership_id}, farm #{$p->farm_id})" );
			$fixed++;
		}
		return $fixed;
	}
}
