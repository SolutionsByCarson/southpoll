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
		];
	}
}
