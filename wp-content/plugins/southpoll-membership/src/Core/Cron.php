<?php
/**
 * WP-Cron scaffolding. Jobs subscribe to the sp/cron/* actions; this class only schedules and dispatches.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

final class Cron implements Service {

	public const HOURLY = 'sp_hourly';
	public const DAILY  = 'sp_daily';
	public const QUEUE  = 'sp_queue';
	public const DIGEST = 'sp_registry_digest'; // single events, (re)scheduled by Memberships\RegistryDigest to the next configured slot

	public const SCHEDULE_FIVE_MIN = 'sp_every_five_minutes';

	public function __construct( private Logger $logger ) {}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOURLY, [ $this, 'run_hourly' ] );
		add_action( self::DAILY, [ $this, 'run_daily' ] );
		add_action( self::QUEUE, [ $this, 'run_queue' ] );
		add_action( self::DIGEST, [ $this, 'run_digest' ] );
		add_action( 'init', [ self::class, 'schedule' ], 20 );
	}

	/**
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE_FIVE_MIN ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (SouthPoll queue)', 'southpoll-membership' ),
		];
		return $schedules;
	}

	/**
	 * Ensure the three recurring events exist. Daily runs at 05:00 site time. The digest schedules itself (sp/cron/digest listener).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			$tz    = wp_timezone();
			$next  = new \DateTimeImmutable( 'today 05:00', $tz );
			if ( $next->getTimestamp() <= time() ) {
				$next = $next->modify( '+1 day' );
			}
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY );
		}
		if ( ! wp_next_scheduled( self::QUEUE ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::SCHEDULE_FIVE_MIN, self::QUEUE );
		}
		/**
		 * Jobs with their own calendar (the registry digest) make sure their next single event exists.
		 */
		do_action( 'sp/cron/schedule' );
	}

	public static function unschedule(): void {
		foreach ( [ self::HOURLY, self::DAILY, self::QUEUE, self::DIGEST ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public function run_hourly(): void {
		$this->dispatch( 'sp/cron/hourly' );
	}

	public function run_daily(): void {
		$this->dispatch( 'sp/cron/daily' );
	}

	public function run_queue(): void {
		$this->dispatch( 'sp/cron/queue' );
	}

	public function run_digest(): void {
		$this->dispatch( 'sp/cron/digest' );
	}

	/**
	 * Run a job on demand (admin "Run now" button, CLI scripts).
	 */
	public function run_now( string $which ): void {
		match ( $which ) {
			'hourly' => $this->run_hourly(),
			'daily'  => $this->run_daily(),
			'queue'  => $this->run_queue(),
			'digest' => $this->run_digest(),
			default  => null,
		};
	}

	private function dispatch( string $action ): void {
		$started = microtime( true );
		try {
			/**
			 * Cron job hook. Listeners must be idempotent.
			 */
			do_action( $action );
		} catch ( \Throwable $e ) {
			$this->logger->error( "Cron {$action} failed: " . $e->getMessage(), [ 'file' => $e->getFile(), 'line' => $e->getLine() ] );
		}
		$this->logger->debug( sprintf( 'Cron %s finished in %.3fs', $action, microtime( true ) - $started ) );
		update_option( 'sp_cron_last_' . str_replace( 'sp/cron/', '', $action ), time(), false );
	}

	/**
	 * Next-run timestamps for the status page.
	 *
	 * @return array<string, int|false>
	 */
	public static function next_runs(): array {
		return [
			self::HOURLY => wp_next_scheduled( self::HOURLY ),
			self::DAILY  => wp_next_scheduled( self::DAILY ),
			self::QUEUE  => wp_next_scheduled( self::QUEUE ),
			self::DIGEST => wp_next_scheduled( self::DIGEST ),
		];
	}
}
