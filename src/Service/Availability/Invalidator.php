<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Day;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Projector;
use CommonsBooking\Wordpress\CustomPostType\Booking;
use CommonsBooking\Wordpress\CustomPostType\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Marks pairs as changed whenever the index rows of a timeframe, booking or restriction change.
 *
 * The marking (gen + 1) happens synchronously while the index is updated, so a reader never uses days computed
 * before the change. The recomputation follows at the end of the request (after the response was sent, if possible),
 * for large changes in the cron job ({@see Maintenance}).
 */
class Invalidator {

	/**
	 * Up to this number of pairs are recomputed at the end of the request, more are left to the cron job and readers.
	 */
	public const SYNC_LIMIT = 25;

	/**
	 * Pairs to recompute at the end of the request, by "locationId:itemId".
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private static array $queue = [];

	private static bool $overflow = false;

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( Projector::CHANGED_HOOK, array( self::class, 'onIndexChange' ), 10, 2 );
		add_action( Backfill::REBUILT_HOOK, array( self::class, 'onIndexRebuilt' ) );
		add_action( 'shutdown', array( self::class, 'onShutdown' ), PHP_INT_MAX );
	}

	/**
	 * @param array|null $old index rows before the change
	 * @param array|null $new index rows after the change
	 *
	 * @return void
	 */
	public static function onIndexChange( $old, $new ): void {
		if ( ! Schema::isInstalled() ) {
			return;
		}
		foreach ( [ $old, $new ] as $data ) {
			$scope = is_array( $data ) ? self::getScope( $data ) : null;
			if ( $scope === null ) {
				continue;
			}
			[ $itemIds, $locationIds ] = $scope;
			self::enqueue( Store::invalidate( $itemIds, $locationIds ) );
			// results computed earlier in this request are outdated now
			Calculator::reset();
		}
	}

	/**
	 * After a complete rebuild of the index nothing is known about single changes.
	 *
	 * The rebuild usually finishes in a background request (WP-Cron, WP-CLI). The calendars are computed there at once,
	 * so they are ready together with the index instead of waiting for the next cron run (up to a few minutes on
	 * sites with little traffic). Otherwise, the cron job is requested.
	 *
	 * @return void
	 */
	public static function onIndexRebuilt(): void {
		if ( ! Schema::isInstalled() ) {
			return;
		}
		Store::invalidateAll();
		Calculator::reset();

		$background = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		/**
		 * Whether to compute the availability right after a rebuild of the index, in the same background request.
		 *
		 * @param bool $refresh default: true in WP-Cron and WP-CLI requests
		 */
		if ( apply_filters( 'commonsbooking_availability_refresh_after_rebuild', $background ) ) {
			Maintenance::refreshAfterRebuild();
		} else {
			Maintenance::scheduleRefresh();
		}
	}

	/**
	 * Determines the pairs affected by an index row.
	 *
	 * @param array $data index row and relation rows
	 *
	 * @return array{0: int[]|null, 1: int[]|null}|null item ids and location ids (null = all), null if the row does not affect the availability
	 */
	public static function getScope( array $data ): ?array {
		$row = $data['row'];

		if ( ! self::isEffective( $row ) || ! self::overlapsHorizon( $row ) ) {
			return null;
		}

		if ( $row['post_type'] === Restriction::$postType ) {
			// restrictions without start are never applied ({@see Day::isInRestriction()}),
			// e.g. while a new restriction is inserted and its meta is not saved yet
			if ( $row['start_ts'] === null ) {
				return null;
			}

			// 0 means all items / all locations
			$itemId     = (int) $row['item_id'];
			$locationId = (int) $row['location_id'];

			return [
				$itemId ? [ $itemId ] : null,
				$locationId ? [ $locationId ] : null,
			];
		}

		$itemIds     = [];
		$locationIds = [];
		if ( (int) $row['is_multi'] === 1 ) {
			foreach ( $data['rel'] as $relation ) {
				if ( $relation['entity_type'] === 'item' ) {
					$itemIds[] = (int) $relation['entity_id'];
				} elseif ( $relation['entity_type'] === 'location' ) {
					$locationIds[] = (int) $relation['entity_id'];
				}
			}
		} else {
			$itemIds     = $row['item_id'] !== null ? [ (int) $row['item_id'] ] : [];
			$locationIds = $row['location_id'] !== null ? [ (int) $row['location_id'] ] : [];
		}

		if ( ! $itemIds || ! $locationIds ) {
			return null;
		}

		return [ array_values( array_unique( $itemIds ) ), array_values( array_unique( $locationIds ) ) ];
	}

	/**
	 * Only posts with these statuses are part of the day grid ({@see Day::getTimeframes()}, {@see Day::RESTRICTION_STATUSES}).
	 *
	 * @param array $row
	 *
	 * @return bool
	 */
	private static function isEffective( array $row ): bool {
		if ( $row['post_type'] === Restriction::$postType ) {
			return in_array( $row['post_status'], Day::RESTRICTION_STATUSES, true );
		}
		if ( in_array( $row['post_type'], [ Timeframe::$postType, Booking::$postType ], true ) ) {
			return in_array( $row['post_status'], [ 'publish', 'confirmed' ], true );
		}

		return false;
	}

	/**
	 * Changes far in the past or beyond the horizon don't affect stored days.
	 * Generous margins, timestamps are stored as local time.
	 *
	 * @param array $row
	 *
	 * @return bool
	 */
	private static function overlapsHorizon( array $row ): bool {
		[ $from, $to ] = Availability::getHorizon();
		$fromTimestamp = strtotime( $from ) - 2 * DAY_IN_SECONDS;
		$toTimestamp   = strtotime( $to ) + 2 * DAY_IN_SECONDS;

		if ( $row['end_ts'] !== null && (int) $row['end_ts'] < $fromTimestamp ) {
			return false;
		}

		return ! ( $row['start_ts'] !== null && (int) $row['start_ts'] > $toTimestamp );
	}

	/**
	 * @param array<int, array{0:int,1:int}> $pairs
	 *
	 * @return void
	 */
	private static function enqueue( array $pairs ): void {
		foreach ( $pairs as $pair ) {
			if ( count( self::$queue ) >= self::getSyncLimit() ) {
				self::$overflow = true;
				return;
			}
			self::$queue[ $pair[1] . ':' . $pair[0] ] = $pair;
		}
	}

	/**
	 * @return int
	 */
	private static function getSyncLimit(): int {
		/**
		 * Number of pairs recomputed at the end of a request after a change. Larger changes are processed by cron.
		 *
		 * @param int $limit
		 */
		return (int) apply_filters( 'commonsbooking_availability_sync_limit', self::SYNC_LIMIT );
	}

	/**
	 * Returns the pairs waiting for recomputation (for tests and debugging).
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function getQueue(): array {
		return array_values( self::$queue );
	}

	/**
	 * Recomputes the queued pairs.
	 *
	 * @return int number of recomputed pairs
	 */
	public static function processQueue(): int {
		// changes of this request must be in the index (and invalidated) before computing
		\CommonsBooking\Service\Index\Indexer::flush();

		$queue          = self::$queue;
		$overflow       = self::$overflow;
		self::$queue    = [];
		self::$overflow = false;

		if ( $overflow ) {
			Maintenance::scheduleRefresh();
		}

		$done = 0;
		foreach ( $queue as [ $itemId, $locationId ] ) {
			try {
				Calculator::recompute( $itemId, $locationId );
				++$done;
			} catch ( \Throwable $e ) {
				// never break the request, the row stays marked as changed and is computed later
				commonsbooking_write_log( sprintf( 'Availability recomputation failed for item %d at location %d: %s', $itemId, $locationId, $e->getMessage() ) );
			}
		}

		return $done;
	}

	/**
	 * Recomputes changed pairs at the end of the request. With PHP-FPM the response is sent first.
	 *
	 * @return void
	 */
	public static function onShutdown(): void {
		\CommonsBooking\Service\Index\Indexer::flush();
		if ( ! self::$queue && ! self::$overflow ) {
			return;
		}

		/**
		 * Whether to send the response before recomputing (fastcgi_finish_request).
		 *
		 * @param bool $finish
		 */
		if ( function_exists( 'fastcgi_finish_request' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && apply_filters( 'commonsbooking_availability_finish_request', true ) ) {
			fastcgi_finish_request();
		}

		self::processQueue();
	}

	/**
	 * Forgets the queue (for tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$queue    = [];
		self::$overflow = false;
	}
}
