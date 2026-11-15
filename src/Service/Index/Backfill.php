<?php

namespace CommonsBooking\Service\Index;

/**
 * Initial filling (and rebuilding) of the index in batches.
 *
 * Runs via a self-rescheduling WP-Cron event, via WP-CLI (`wp commonsbooking booster rebuild`) or in time slices
 * started from the settings page ({@see \CommonsBooking\Service\Booster\SettingsView::handleSetupStep()}), which
 * does not depend on WP-Cron. A lock keeps WP-Cron and the browser from working at the same time.
 * While the backfill is running, the hooks of {@see Indexer} already keep changed posts up to date,
 * projecting a post twice is harmless.
 *
 * The index is only used for reading when {@see self::isReady()} returns true.
 */
class Backfill {

	public const CRON_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_index_backfill';

	/**
	 * Projector version the completed backfill was built with.
	 */
	public const READY_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_index_ready';

	/**
	 * Highest post id processed by the running backfill.
	 */
	public const CURSOR_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_index_backfill_cursor';

	/**
	 * Expiry time (unix timestamp) of the lock held by the process that is working on the backfill.
	 */
	public const LOCK_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_index_backfill_lock';

	public const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Results of {@see self::runFor()}.
	 */
	public const RUN_DONE = 'done';
	public const RUN_MORE = 'more';
	public const RUN_BUSY = 'busy';

	/**
	 * The backfill counts as stalled if its cron event is overdue by more than this (or missing).
	 */
	public const STALL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Action fired after a complete rebuild.
	 */
	public const REBUILT_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_index_rebuilt';

	/**
	 * Seconds a single cron run may spend before it reschedules itself.
	 */
	private const CRON_TIME_BUDGET = 20;

	/**
	 * Returns true if the index is complete and may be used for reading:
	 * the booster is switched on and not bypassed in this request (see {@see \CommonsBooking\Service\Booster\Booster}).
	 * Can be switched off with the filter `commonsbooking_use_index`.
	 *
	 * @return bool
	 */
	public static function isReady(): bool {
		if ( ! \CommonsBooking\Service\Booster\Booster::canRead() ) {
			return false;
		}

		/**
		 * Allows to disable reading from the index (e.g. to compare with the legacy postmeta queries).
		 *
		 * @param bool $useIndex
		 */
		if ( ! apply_filters( 'commonsbooking_use_index', true ) ) {
			return false;
		}

		return Schema::isInstalled() && self::isDone();
	}

	/**
	 * @return bool true if a complete backfill with the current projector version exists
	 */
	public static function isDone(): bool {
		return (int) get_option( self::READY_OPTION, 0 ) === Projector::PROJECTOR_VERSION;
	}

	/**
	 * @return bool
	 */
	public static function isRunning(): bool {
		return get_option( self::CURSOR_OPTION, false ) !== false;
	}

	/**
	 * Returns true if the backfill is running but WP-Cron does not process it: the event is missing or overdue by
	 * more than {@see self::STALL_SECONDS}, e.g. because the loopback request of WP-Cron is blocked by a password
	 * protection of the site.
	 *
	 * @return bool
	 */
	public static function isStalled(): bool {
		if ( ! self::isRunning() ) {
			return false;
		}
		$next = wp_next_scheduled( self::CRON_HOOK );

		return ! $next || $next < time() - self::STALL_SECONDS;
	}

	/**
	 * @return int seconds the cron event is overdue (0 if it is not)
	 */
	public static function getOverdueSeconds(): int {
		$next = wp_next_scheduled( self::CRON_HOOK );

		return $next ? max( 0, time() - (int) $next ) : 0;
	}

	/**
	 * Starts a (new) backfill. The index stays unusable for reading until it has finished.
	 *
	 * @return void
	 */
	public static function start(): void {
		delete_option( self::READY_OPTION );
		update_option( self::CURSOR_OPTION, 0, false );
		self::scheduleNext();
	}

	/**
	 * Removes all backfill state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::READY_OPTION );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::LOCK_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Processes the next batch of posts.
	 *
	 * @param int $batchSize
	 *
	 * @return bool true if the backfill is complete
	 */
	public static function runBatch( int $batchSize = self::DEFAULT_BATCH_SIZE ): bool {
		global $wpdb;

		if ( ! Schema::isInstalled() ) {
			Schema::install();
		}

		$cursor     = (int) get_option( self::CURSOR_OPTION, 0 );
		$postTypes  = Projector::getPostTypes();
		$typeMarker = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql     = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($typeMarker) ORDER BY ID ASC LIMIT %d", array_merge( [ $cursor ], $postTypes, [ $batchSize ] ) );
		$postIds = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $postIds ) {
			// load posts and meta with two queries instead of two per post
			_prime_post_caches( $postIds, false, true );

			foreach ( $postIds as $postId ) {
				try {
					Projector::project( $postId, false );
				} catch ( \Throwable $e ) {
					commonsbooking_write_log( sprintf( 'Index backfill failed for post %d: %s', $postId, $e->getMessage() ) );
				}
			}

			update_option( self::CURSOR_OPTION, end( $postIds ), false );

			// free memory on large instances
			foreach ( $postIds as $postId ) {
				wp_cache_delete( $postId, 'posts' );
				wp_cache_delete( $postId, 'post_meta' );
			}
		}

		if ( count( $postIds ) < $batchSize ) {
			self::finish();

			return true;
		}

		return false;
	}

	/**
	 * Runs the complete backfill synchronously (WP-CLI).
	 *
	 * @param int           $batchSize
	 * @param callable|null $onProgress called with the current cursor after every batch
	 *
	 * @return void
	 */
	public static function runAll( int $batchSize = self::DEFAULT_BATCH_SIZE, ?callable $onProgress = null ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::READY_OPTION );
		update_option( self::CURSOR_OPTION, 0, false );

		do {
			$done = self::runBatch( $batchSize );
			if ( $onProgress && ! $done ) {
				$onProgress( (int) get_option( self::CURSOR_OPTION, 0 ) );
			}
		} while ( ! $done );
	}

	/**
	 * Processes batches until the backfill is complete or the time budget is used up. A batch that started before
	 * the end of the budget is finished, so plan some reserve below the PHP time limit.
	 *
	 * @param float $seconds time budget
	 *
	 * @return string {@see self::RUN_DONE} (complete or not running), {@see self::RUN_MORE} (call again) or
	 *                {@see self::RUN_BUSY} (another process is working on it)
	 */
	public static function runFor( float $seconds ): string {
		if ( ! self::isRunning() ) {
			return self::RUN_DONE;
		}
		if ( ! self::acquireLock( (int) ceil( $seconds ) + 60 ) ) {
			return self::RUN_BUSY;
		}

		try {
			// the other process may have advanced or finished the backfill
			wp_cache_delete( self::CURSOR_OPTION, 'options' );
			if ( get_option( self::CURSOR_OPTION, false ) === false ) {
				return self::RUN_DONE;
			}

			$started = microtime( true );
			do {
				$done = self::runBatch();
			} while ( ! $done && ( microtime( true ) - $started ) < $seconds && ! \CommonsBooking\Service\Booster\Booster::isMemoryLow() );
		} finally {
			self::releaseLock();
		}

		return $done ? self::RUN_DONE : self::RUN_MORE;
	}

	/**
	 * Cron callback: processes batches within a time budget and reschedules itself if needed.
	 *
	 * @return void
	 */
	public static function runCron(): void {
		$result = self::runFor( self::CRON_TIME_BUDGET );
		if ( $result === self::RUN_MORE ) {
			self::scheduleNext();
		} elseif ( $result === self::RUN_BUSY ) {
			// e.g. the settings page is working on it, check again later in case it stops
			self::scheduleNext( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Takes the lock unless another process holds it and it has not expired. Direct queries: the INSERT IGNORE on
	 * the unique option name is atomic, add_option() is not.
	 *
	 * @param int $ttl seconds until the lock expires (if the process dies)
	 *
	 * @return bool
	 */
	private static function acquireLock( int $ttl ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", self::LOCK_OPTION, time() ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) ( time() + $ttl ) ) );
		// phpcs:enable
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $wpdb->rows_affected === 1;
	}

	/**
	 * @return void
	 */
	private static function releaseLock(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Marks the backfill as complete and removes rows of posts that do not exist anymore.
	 *
	 * @return void
	 */
	private static function finish(): void {
		global $wpdb;
		$table    = Schema::table();
		$relTable = Schema::relTable();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE i FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL" );
		$wpdb->query( "DELETE r FROM $relTable r LEFT JOIN $table i ON i.post_id = r.post_id WHERE i.post_id IS NULL" );
		// phpcs:enable

		delete_option( self::CURSOR_OPTION );
		update_option( self::READY_OPTION, Projector::PROJECTOR_VERSION, true );
		wp_clear_scheduled_hook( self::CRON_HOOK );

		/**
		 * The index was rebuilt completely. Changes during the rebuild were not reported individually.
		 */
		do_action( self::REBUILT_HOOK );
	}

	/**
	 * @param int $delay seconds
	 *
	 * @return void
	 */
	private static function scheduleNext( int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}
}
