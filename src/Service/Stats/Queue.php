<?php

namespace CommonsBooking\Service\Stats;

use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Schema as IndexSchema;

/**
 * Work queue of the statistics: item/location pairs whose days between dirty_from and dirty_to have to be recomputed.
 *
 * dirty_from = {@see self::FROM_BEGINNING} means "from the first day the pair has data".
 */
class Queue {

	public const FROM_BEGINNING = '1970-01-01';

	/**
	 * Default maximum number of days into the past that are computed per pair, see {@see self::earliestDay()}.
	 */
	public const MAX_HISTORY_DAYS = 1826;

	/**
	 * Option with the pairs that could not be computed (last entries).
	 */
	public const FAILURES_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_stats_failures';

	/**
	 * Marks days of a pair as dirty. Overlapping marks are merged into one range.
	 *
	 * @param int         $itemId
	 * @param int         $locationId
	 * @param string      $from Y-m-d
	 * @param string|null $to Y-m-d, default today
	 *
	 * @return void
	 */
	public static function mark( int $itemId, int $locationId, string $from, ?string $to = null ): void {
		global $wpdb;
		$to = min( $to ?? date( 'Y-m-d' ), date( 'Y-m-d' ) );
		if ( $from > $to ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::pairs() . ' (item_id,location_id,dirty_from,dirty_to) VALUES (%d,%d,%s,%s)
				ON DUPLICATE KEY UPDATE
					dirty_from = IF(dirty_from IS NULL, VALUES(dirty_from), LEAST(dirty_from, VALUES(dirty_from))),
					dirty_to = IF(dirty_to IS NULL, VALUES(dirty_to), GREATEST(dirty_to, VALUES(dirty_to)))',
				$itemId,
				$locationId,
				$from,
				$to
			)
		);
		// phpcs:enable
	}

	/**
	 * Adds the pairs of all bookings and bookable timeframes (when the timeframe index exists) to the queue.
	 * Existing pairs keep their state.
	 *
	 * @return int number of new pairs
	 */
	public static function discoverPairs(): int {
		global $wpdb;
		$today = date( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::pairs() );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::pairs() . ' (item_id,location_id,dirty_from,dirty_to)
				SELECT DISTINCT item_id, location_id, %s, %s FROM ' . Schema::booking() . ' WHERE item_id > 0 AND location_id > 0',
				self::FROM_BEGINNING,
				$today
			)
		);
		if ( ! self::useIndex() ) {
			// without the timeframe index only timeframes with exactly one item and one location are found
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . Schema::pairs() . " (item_id,location_id,dirty_from,dirty_to)
					SELECT DISTINCT CAST(i.meta_value AS UNSIGNED), CAST(l.meta_value AS UNSIGNED), %s, %s
					FROM {$wpdb->posts} p " . self::timeframeJoins() . '
					WHERE p.post_type = %s AND p.post_status = %s AND CAST(i.meta_value AS UNSIGNED) > 0 AND CAST(l.meta_value AS UNSIGNED) > 0',
					self::FROM_BEGINNING,
					$today,
					\CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType,
					'publish'
				)
			);
		} else {
			$index = IndexSchema::table();
			$rel   = IndexSchema::relTable();
			$type  = \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID;
			$post  = \CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . Schema::pairs() . " (item_id,location_id,dirty_from,dirty_to)
					SELECT DISTINCT i.entity_id, l.entity_id, %s, %s
					FROM $rel i JOIN $rel l ON l.post_id = i.post_id AND l.entity_type = 'location'
					JOIN $index x ON x.post_id = i.post_id AND x.type = %d AND x.post_type = %s AND x.post_status = 'publish'
					WHERE i.entity_type = 'item'",
					self::FROM_BEGINNING,
					$today,
					$type,
					$post
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . Schema::pairs() . " (item_id,location_id,dirty_from,dirty_to)
					SELECT DISTINCT item_id, location_id, %s, %s FROM $index
					WHERE type = %d AND post_type = %s AND post_status = 'publish' AND item_id > 0 AND location_id > 0",
					self::FROM_BEGINNING,
					$today,
					$type,
					$post
				)
			);
		}
		// phpcs:enable

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::pairs() ) - $before; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Marks all pairs as dirty from the beginning (full rebuild of the daily values) and forgets old failures.
	 *
	 * @return void
	 */
	public static function markAll(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::pairs() . ' SET dirty_from = %s, dirty_to = %s', self::FROM_BEGINNING, date( 'Y-m-d' ) ) );
		// phpcs:enable
		delete_option( self::FAILURES_OPTION );
	}

	/**
	 * Marks yesterday and today of all pairs (daily run: closes the previous day and starts the new one).
	 *
	 * @return void
	 */
	public static function markRecent(): void {
		global $wpdb;
		$today = date( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::pairs() . ' SET
					dirty_from = IF(dirty_from IS NULL, %s, LEAST(dirty_from, %s)),
					dirty_to = IF(dirty_to IS NULL, %s, GREATEST(dirty_to, %s))',
				date( 'Y-m-d', strtotime( $today . ' -1 day' ) ),
				date( 'Y-m-d', strtotime( $today . ' -1 day' ) ),
				$today,
				$today
			)
		);
		// phpcs:enable
	}

	/**
	 * @return int number of pairs with open work
	 */
	public static function pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::pairs() . ' WHERE dirty_from IS NOT NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Works off the queue, oldest ranges first.
	 *
	 * @param float $budget seconds, at least one pair is always processed
	 * @param int   $max maximum number of pairs
	 *
	 * @return int number of processed pairs
	 */
	public static function process( float $budget = 20.0, int $max = PHP_INT_MAX ): int {
		global $wpdb;
		$started = microtime( true );
		$done    = 0;
		while ( $done < $max && ( $done === 0 || ( microtime( true ) - $started < $budget && ! Build::memoryLow() ) ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pair = $wpdb->get_row( 'SELECT item_id, location_id, dirty_from, dirty_to FROM ' . Schema::pairs() . ' WHERE dirty_from IS NOT NULL ORDER BY dirty_from, item_id LIMIT 1', ARRAY_A );
			// phpcs:enable
			if ( ! $pair ) {
				break;
			}
			self::processPair( (int) $pair['item_id'], (int) $pair['location_id'], $pair['dirty_from'], $pair['dirty_to'] );
			++$done;
		}

		return $done;
	}

	/**
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $from
	 * @param string $to
	 *
	 * @return void
	 */
	private static function processPair( int $itemId, int $locationId, string $from, string $to ): void {
		global $wpdb;
		try {
			$first = self::earliestDay( $itemId, $locationId );
			if ( $first === null ) {
				// no data: nothing to compute
				$wpdb->delete(
					Schema::daily(),
					[
						'item_id' => $itemId,
						'location_id' => $locationId,
					]
				);
			} else {
				Calculator::recompute( $itemId, $locationId, max( $from, $first ), $to );
			}
		} catch ( \Throwable $e ) {
			// a pair that fails must not block the queue: note it and go on
			$failures   = self::failures();
			$failures[] = $itemId . '/' . $locationId . ': ' . $e->getMessage();
			update_option( self::FAILURES_OPTION, array_slice( $failures, -20 ), false );
		}

		// clear the mark unless the range changed meanwhile
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::pairs() . ' SET dirty_from = NULL, dirty_to = NULL, computed_at = %s
				WHERE item_id = %d AND location_id = %d AND dirty_from = %s AND dirty_to = %s',
				current_time( 'mysql', true ),
				$itemId,
				$locationId,
				$from,
				$to
			)
		);
		// phpcs:enable
	}

	/**
	 * @return string[] last pairs that failed ("item/location: message")
	 */
	public static function failures(): array {
		$failures = get_option( self::FAILURES_OPTION, [] );

		return is_array( $failures ) ? $failures : [];
	}

	/**
	 * First day with data of a pair (first booking or start of a bookable timeframe), not earlier than the maximum
	 * history. Null if the pair has no data.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return string|null Y-m-d
	 */
	public static function earliestDay( int $itemId, int $locationId ): ?string {
		global $wpdb;
		/**
		 * Maximum number of days into the past that are computed per item/location pair.
		 *
		 * @param int $days
		 */
		$days  = max( 30, (int) apply_filters( 'commonsbooking_stats_max_history_days', self::MAX_HISTORY_DAYS ) );
		$floor = strtotime( date( 'Y-m-d' ) . ' -' . $days . ' days UTC' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$first = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(start_ts) FROM ' . Schema::booking() . ' WHERE item_id = %d AND location_id = %d', $itemId, $locationId )
		);
		if ( ! self::useIndex() ) {
			$tf = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MIN(CAST(rs.meta_value AS UNSIGNED)) FROM ' . $wpdb->posts . ' p ' . self::timeframeJoins() . '
					WHERE p.post_type = %s AND p.post_status = %s AND CAST(i.meta_value AS UNSIGNED) = %d AND CAST(l.meta_value AS UNSIGNED) = %d AND CAST(rs.meta_value AS UNSIGNED) > 0',
					\CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType,
					'publish',
					$itemId,
					$locationId
				)
			);
			if ( $tf !== null && ( $first === null || (int) $tf < (int) $first ) ) {
				$first = $tf;
			}
		} else {
			$index = IndexSchema::table();
			$rel   = IndexSchema::relTable();
			$tf    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MIN(x.start_ts) FROM $index x
					WHERE x.type = %d AND x.post_type = %s AND x.post_status = 'publish' AND x.start_ts > 0
					AND ( (x.item_id = %d AND x.location_id = %d)
						OR ( x.post_id IN (SELECT post_id FROM $rel WHERE entity_type = 'item' AND entity_id = %d)
							AND x.post_id IN (SELECT post_id FROM $rel WHERE entity_type = 'location' AND entity_id = %d) ) )",
					\CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID,
					\CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType,
					$itemId,
					$locationId,
					$itemId,
					$locationId
				)
			);
			if ( $tf !== null && ( $first === null || (int) $tf < (int) $first ) ) {
				$first = $tf;
			}
		}
		// phpcs:enable
		if ( $first === null ) {
			return null;
		}

		return gmdate( 'Y-m-d', max( (int) $first, $floor ) );
	}

	/**
	 * @return bool true if the timeframe index is complete and can be read
	 */
	private static function useIndex(): bool {
		return IndexSchema::isInstalled() && Backfill::isReady();
	}

	/**
	 * Joins of the bookable timeframes with exactly one item (i), one location (l) and a start (rs).
	 *
	 * @return string
	 */
	private static function timeframeJoins(): string {
		global $wpdb;
		$pm   = $wpdb->postmeta;
		$type = \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID;

		return "JOIN $pm t ON t.post_id = p.ID AND t.meta_key = 'type' AND t.meta_value = '$type'
			JOIN $pm i ON i.post_id = p.ID AND i.meta_key = 'item-id'
			JOIN $pm l ON l.post_id = p.ID AND l.meta_key = 'location-id'
			JOIN $pm rs ON rs.post_id = p.ID AND rs.meta_key = 'repetition-start'";
	}
}
