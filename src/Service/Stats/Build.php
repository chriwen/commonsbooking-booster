<?php

namespace CommonsBooking\Service\Stats;

/**
 * Initial build and full rebuild of the statistics, made for shared hosting.
 *
 * - Works in small steps with a time budget below `max_execution_time`, so no request runs into a timeout.
 * - The state is stored in an option, every step continues where the last one stopped (also after a crash).
 * - Steps are driven by whoever comes first: the open statistics page (AJAX polling and page load, no WP-Cron needed),
 *   WP-Cron, or WP-CLI. A lock keeps them from working in parallel.
 *
 * Phases: bookings (projection in id ranges) → pairs (find and queue all item/location pairs) → days (daily values per
 * pair, see {@see Queue}) → done.
 */
class Build {

	public const STATE_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_stats_build';
	public const LOCK_OPTION  = COMMONSBOOKING_PLUGIN_SLUG . '_stats_build_lock';

	/** Booking post ids per range. */
	private const BATCH_IDS = 5000;

	/** A lock older than this many seconds is considered abandoned. */
	private const LOCK_TTL = 120;

	/** Share of the progress bar per phase (bookings, pairs; the rest belongs to the days). */
	private const SHARE_BOOKINGS = 20;
	private const SHARE_PAIRS    = 5;

	/**
	 * Starts a (re)build. Returns at once, the work happens in {@see self::step()}.
	 *
	 * @return void
	 */
	public static function start(): void {
		Schema::install();
		update_option( Stats::ENABLED_OPTION, true, true );
		delete_option( Stats::READY_OPTION );
		update_option(
			self::STATE_OPTION,
			[
				'phase'   => 'bookings',
				'cursor'  => 0,
				'max'     => Bookings::maxId(),
				'total'   => 0,
				'started' => time(),
			],
			false
		);
		Stats::scheduleProcess();
	}

	/**
	 * @return bool true while a build is not finished
	 */
	public static function isRunning(): bool {
		$state = self::state();

		return $state !== null && $state['phase'] !== 'done';
	}

	/**
	 * Time budget for one step in seconds: well below `max_execution_time` where the host sets one.
	 *
	 * @param float $limit upper bound
	 *
	 * @return float
	 */
	public static function budget( float $limit = 8.0 ): float {
		$max = (int) ini_get( 'max_execution_time' );

		return $max > 0 ? max( 2.0, min( $limit, $max * 0.4 ) ) : $limit;
	}

	/**
	 * Works for up to $budget seconds and returns the progress. Does nothing (but returns the progress) if another
	 * process is working.
	 *
	 * @param float $budget seconds
	 *
	 * @return array{phase: string, percent: int, pending: int, total: int, done: bool, error: ?string, failed: int}
	 */
	public static function step( float $budget ): array {
		if ( self::isRunning() && self::lock() ) {
			$started = microtime( true );
			try {
				self::work( $started, $budget );
			} catch ( \Throwable $e ) {
				$state          = self::state() ?? [];
				$state['error'] = $e->getMessage();
				update_option( self::STATE_OPTION, $state, false );
				error_log( 'CommonsBooking statistics: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} finally {
				delete_option( self::LOCK_OPTION );
			}
		}

		return self::progress();
	}

	/**
	 * @return array{phase: string, percent: int, pending: int, total: int, done: bool, error: ?string, failed: int}
	 */
	public static function progress(): array {
		$state   = self::state();
		$pending = Schema::isInstalled() ? Queue::pending() : 0;
		$total   = (int) ( $state['total'] ?? 0 );
		$phase   = $state['phase'] ?? 'done';
		$percent = 100;

		if ( $phase === 'bookings' ) {
			$percent = (int) floor( self::SHARE_BOOKINGS * min( 1, ( $state['cursor'] ?? 0 ) / max( 1, $state['max'] ?? 1 ) ) );
		} elseif ( $phase === 'pairs' ) {
			$percent = self::SHARE_BOOKINGS;
		} elseif ( $phase === 'days' ) {
			$rest    = 100 - self::SHARE_BOOKINGS - self::SHARE_PAIRS;
			$percent = self::SHARE_BOOKINGS + self::SHARE_PAIRS + (int) floor( $rest * ( 1 - min( 1, $pending / max( 1, $total ) ) ) );
			$percent = min( 99, $percent );
		}

		return [
			'phase'   => $phase,
			'percent' => $percent,
			'pending' => $pending,
			'total'   => $total,
			'done'    => $phase === 'done',
			'error'   => $state['error'] ?? null,
			'failed'  => count( Queue::failures() ),
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function state(): ?array {
		$state = get_option( self::STATE_OPTION, null );

		return is_array( $state ) && isset( $state['phase'] ) ? $state : null;
	}

	private static function work( float $started, float $budget ): void {
		$state = self::state();
		if ( $state === null ) {
			return;
		}
		unset( $state['error'] );

		$ranges = 0;
		// at least one range per call, so a call always makes progress
		while ( $state['phase'] === 'bookings' && ( $ranges === 0 || ( microtime( true ) - $started < $budget && ! self::memoryLow() ) ) ) {
			++$ranges;
			Bookings::projectRange( $state['cursor'], $state['cursor'] + self::BATCH_IDS - 1 );
			$state['cursor'] += self::BATCH_IDS;
			update_option( self::STATE_OPTION, $state, false );
			if ( $state['cursor'] > $state['max'] ) {
				$state['phase'] = 'pairs';
			}
		}

		if ( $state['phase'] === 'pairs' ) {
			Queue::discoverPairs();
			Queue::markAll();
			$state['total'] = Queue::pending();
			$state['phase'] = 'days';
			update_option( self::STATE_OPTION, $state, false );
		}

		if ( $state['phase'] === 'days' ) {
			if ( Queue::pending() ) {
				Queue::process( max( 0.0, $budget - ( microtime( true ) - $started ) ) ); // takes at least one pair
			}
			if ( ! Queue::pending() ) {
				$state['phase']    = 'done';
				$state['finished'] = time();
				update_option( Stats::READY_OPTION, time(), true );
			}
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Takes the lock if nobody else holds a fresh one.
	 *
	 * @return bool
	 */
	private static function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( (int) get_option( self::LOCK_OPTION, 0 ) < time() - self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );

			return add_option( self::LOCK_OPTION, time(), '', false );
		}

		return false;
	}

	/**
	 * @return bool true if the request uses most of its memory
	 */
	public static function memoryLow(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		return $limit > 0 && memory_get_usage() > 0.7 * $limit;
	}
}
