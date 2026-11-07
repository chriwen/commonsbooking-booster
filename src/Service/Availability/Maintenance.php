<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Background work of the precomputed availability.
 *
 * None of it is needed for correct results (readers never use outdated rows), it keeps the rows warm:
 * - hourly: create rows for new pairs, remove rows of pairs without bookable timeframe, recompute changed rows and
 *   rows older than {@see self::REFRESH_AGE} (moves the horizon forward every day, for pairs with role limited
 *   timeframes including the access profiles "no matching role" and "all roles", see {@see Calculator::recompute()}),
 * - daily: remove access profiles that are outdated or unused ({@see AccessStore::cleanup()}),
 *   compare a sample of rows with a fresh computation ({@see self::verify()}).
 */
class Maintenance {

	public const CRON_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_availability_refresh';

	public const VERIFY_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_availability_verify';

	/**
	 * Rows older than this (seconds) are recomputed by the cron job, before readers stop using them ({@see Availability::MAX_AGE}).
	 */
	public const REFRESH_AGE = 12 * HOUR_IN_SECONDS;

	/**
	 * Seconds a single cron run may spend.
	 */
	private const CRON_TIME_BUDGET = 20;

	public const VERIFY_SAMPLE_SIZE = 50;

	/**
	 * Option with the time of the last synchronization of the pair list.
	 */
	public const SYNC_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_availability_pairs_synced';

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'runCron' ) );
		add_action( self::VERIFY_HOOK, array( self::class, 'runVerifyCron' ) );
		add_action( 'init', array( self::class, 'schedule' ), 50 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'commonsbooking availability', Cli::class );
		}
	}

	/**
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! Schema::isInstalled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::VERIFY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:30' ), 'daily', self::VERIFY_HOOK );
		}
	}

	/**
	 * Removes the scheduled events (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::VERIFY_HOOK );
	}

	/**
	 * Requests an additional refresh run as soon as possible (e.g. after a large change).
	 *
	 * @return void
	 */
	public static function scheduleRefresh(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next === false || $next > time() + 60 ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function runCron(): void {
		if ( ! Availability::isEnabled() ) {
			return;
		}

		if ( (int) get_option( self::SYNC_OPTION, 0 ) < time() - DAY_IN_SECONDS ) {
			self::syncPairs();
		}

		$result = self::refresh( self::CRON_TIME_BUDGET );
		\CommonsBooking\Service\Booster\Diagnostics::recordCheck( 'availability_refresh', [ 'recomputed' => $result['recomputed'] ] );
		if ( $result['remaining'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function runVerifyCron(): void {
		if ( ! Availability::isEnabled() ) {
			return;
		}
		AccessStore::cleanup();
		$result = self::verify( self::VERIFY_SAMPLE_SIZE, true );
		\CommonsBooking\Service\Booster\Diagnostics::recordCheck(
			'availability_verify',
			[
				'checked'    => $result['checked'],
				'mismatches' => count( $result['mismatches'] ),
			]
		);
		if ( $result['mismatches'] ) {
			commonsbooking_write_log(
				sprintf(
					'Availability verification found %d of %d checked pairs outdated (location:item %s) and recomputed them.',
					count( $result['mismatches'] ),
					$result['checked'],
					implode( ', ', $result['mismatches'] )
				)
			);
		}
	}

	/**
	 * Recomputes changed and old rows.
	 *
	 * @param int|null $timeBudget seconds, null = until done
	 * @param int|null $olderThan recompute rows computed more than this many seconds ago, default {@see self::REFRESH_AGE}
	 *
	 * @return array{recomputed: int, remaining: bool}
	 */
	public static function refresh( ?int $timeBudget = null, ?int $olderThan = null ): array {
		$started    = microtime( true );
		$before     = time() - ( $olderThan ?? self::REFRESH_AGE );
		$recomputed = 0;
		$failed     = [];

		do {
			$pairs = array_filter(
				Store::getStalePairs( $before, 50 + count( $failed ) ),
				fn( $pair ) => ! isset( $failed[ $pair[1] . ':' . $pair[0] ] )
			);
			foreach ( $pairs as [ $itemId, $locationId ] ) {
				try {
					Calculator::recompute( $itemId, $locationId );
					++$recomputed;
				} catch ( \Throwable $e ) {
					$failed[ $locationId . ':' . $itemId ] = true;
					commonsbooking_write_log( sprintf( 'Availability recomputation failed for item %d at location %d: %s', $itemId, $locationId, $e->getMessage() ) );
				}
				if ( $timeBudget !== null && microtime( true ) - $started > $timeBudget ) {
					return [
						'recomputed' => $recomputed,
						'remaining'  => true,
					];
				}
			}
		} while ( $pairs );

		return [
			'recomputed' => $recomputed,
			'remaining'  => false,
		];
	}

	/**
	 * Creates rows for all pairs with a current bookable timeframe and removes rows of other pairs.
	 * New rows are computed by the next {@see self::refresh()}.
	 *
	 * @return array{pairs: int, created: int, deleted: int}
	 */
	public static function syncPairs(): array {
		global $wpdb;
		$pairs = self::getBookablePairs();

		$existing = [];
		foreach ( Store::getAllPairs() as [ $itemId, $locationId ] ) {
			$existing[ $locationId . ':' . $itemId ] = true;
		}

		$created = 0;
		$values  = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			if ( ! isset( $existing[ $locationId . ':' . $itemId ] ) ) {
				$values[] = sprintf( '(%d, %d, 1, 0)', $itemId, $locationId );
				++$created;
			}
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- only integers
			$wpdb->query( 'INSERT IGNORE INTO ' . Schema::table() . ' (item_id, location_id, gen, computed_gen) VALUES ' . implode( ',', $chunk ) );
		}

		$deleted = Store::deleteExcept( $pairs );
		update_option( self::SYNC_OPTION, time(), false );

		return [
			'pairs'   => count( $pairs ),
			'created' => $created,
			'deleted' => $deleted,
		];
	}

	/**
	 * Pairs with a published bookable timeframe that has not ended before the horizon.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function getBookablePairs(): array {
		global $wpdb;
		[ $from ] = Availability::getHorizon();
		$table    = IndexSchema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = $wpdb->prepare(
			"SELECT DISTINCT item_id, location_id FROM $table WHERE post_type = %s AND type = %d AND post_status = 'publish' AND item_id IS NOT NULL AND location_id IS NOT NULL AND (end_ts IS NULL OR end_ts >= %d)",
			Timeframe::$postType,
			Timeframe::BOOKABLE_ID,
			strtotime( $from )
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( fn( $row ) => [ (int) $row['item_id'], (int) $row['location_id'] ], $rows );
	}

	/**
	 * Compares usable rows (pairs and access profiles) with a fresh computation.
	 *
	 * @param int|null $sampleSize null = all rows, otherwise the sample size for pairs and for access profiles each
	 * @param bool     $repair recompute rows with differences
	 *
	 * @return array{checked: int, mismatches: string[]} mismatches as "locationId:itemId" or
	 *                                                   "locationId:itemId:accessProfile"
	 * @throws \Exception
	 */
	public static function verify( ?int $sampleSize = null, bool $repair = false ): array {
		$pairs    = self::verifyPairs( $sampleSize, $repair );
		$profiles = self::verifyAccessProfiles( $sampleSize, $repair );

		return [
			'checked'    => $pairs['checked'] + $profiles['checked'],
			'mismatches' => array_merge( $pairs['mismatches'], $profiles['mismatches'] ),
		];
	}

	/**
	 * @param int|null $sampleSize
	 * @param bool     $repair
	 *
	 * @return array{checked: int, mismatches: string[]}
	 * @throws \Exception
	 */
	private static function verifyAccessProfiles( ?int $sampleSize, bool $repair ): array {
		$rows = AccessStore::getAll();
		if ( $sampleSize !== null && count( $rows ) > $sampleSize ) {
			shuffle( $rows );
			$rows = array_slice( $rows, 0, $sampleSize );
		}

		$checked    = 0;
		$mismatches = [];
		foreach ( $rows as [ $itemId, $locationId, $accessProfile ] ) {
			Store::reset();
			$pairRow = Store::get( $itemId, $locationId );
			$row     = AccessStore::loadWithSlots( [ [ $itemId, $locationId, $accessProfile ] ] )[ $locationId . ':' . $itemId . ':' . $accessProfile ] ?? null;
			if ( ! AccessStore::isUsable( $row, $pairRow ) ) {
				continue;
			}
			++$checked;

			$fresh = Calculator::compute( $itemId, $locationId, $accessProfile );
			if ( ! self::matches( $row, $fresh ) ) {
				$mismatches[] = $locationId . ':' . $itemId . ':' . $accessProfile;
				if ( $repair ) {
					AccessStore::delete( $itemId, $locationId, $accessProfile );
					Calculator::reset();
					Calculator::computeAccessProfile( $itemId, $locationId, $accessProfile );
				}
			}
		}

		return [
			'checked'    => $checked,
			'mismatches' => $mismatches,
		];
	}

	/**
	 * @param int|null $sampleSize
	 * @param bool     $repair
	 *
	 * @return array{checked: int, mismatches: string[]}
	 * @throws \Exception
	 */
	private static function verifyPairs( ?int $sampleSize, bool $repair ): array {
		$pairs = Store::getAllPairs();
		if ( $sampleSize !== null && count( $pairs ) > $sampleSize ) {
			shuffle( $pairs );
			$pairs = array_slice( $pairs, 0, $sampleSize );
		}

		$checked    = 0;
		$mismatches = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			Store::reset();
			$row = Store::loadWithSlots( [ [ $itemId, $locationId ] ] )[ $locationId . ':' . $itemId ] ?? null;
			if ( ! Store::isUsable( $row ) ) {
				continue;
			}
			++$checked;

			$fresh = Calculator::compute( $itemId, $locationId );
			if ( $fresh['roleLimited'] || ! self::matches( $row, $fresh ) ) {
				$mismatches[] = $locationId . ':' . $itemId;
				if ( $repair ) {
					Store::invalidate( [ $itemId ], [ $locationId ] );
					Calculator::recompute( $itemId, $locationId );
				}
			}
		}

		return [
			'checked'    => $checked,
			'mismatches' => $mismatches,
		];
	}

	/**
	 * Compares the days both computations cover.
	 *
	 * @param array $row
	 * @param array $fresh result of {@see Calculator::compute()}
	 *
	 * @return bool
	 */
	private static function matches( array $row, array $fresh ): bool {
		if ( ( $row['slots'] ?? null ) === null ) {
			return false;
		}
		$storedSlots = Slots::unpack( (string) $row['slots'] );
		$freshSlots  = Slots::unpack( (string) $fresh['slots'] );

		foreach ( $fresh['flags'] as $offset => $flags ) {
			$date   = date( 'Y-m-d', strtotime( $fresh['from'] . ' +' . $offset . ' days' ) );
			$stored = Store::getFlags( $row, $date );
			if ( $stored === null ) {
				continue;
			}
			if ( $stored !== $flags ) {
				return false;
			}
			$storedOffset = Store::getDayOffset( $row, $date );
			if ( self::resolveSlots( $storedSlots, $storedOffset ) !== self::resolveSlots( $freshSlots, $offset ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Slots of a day with the timeframe index replaced by timeframe, location and item id.
	 *
	 * @param array $unpacked result of {@see Slots::unpack()}
	 * @param int   $offset day offset
	 *
	 * @return array
	 */
	private static function resolveSlots( array $unpacked, int $offset ): array {
		return array_map(
			fn( $slot ) => [ $slot[0], $slot[1], $unpacked['timeframes'][ $slot[2] ] ?? null ],
			$unpacked['days'][ $offset ] ?? []
		);
	}
}
