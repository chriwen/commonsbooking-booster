<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Timeframe;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use stdClass;

/**
 * Availability slots for the API and GBFS from the precomputed rows ({@see Store}, column slots).
 *
 * The stored slots are computed without booking window and start day offset, both are applied here per timeframe.
 *
 * Same result as {@see \CommonsBooking\Model\Calendar::getAvailabilitySlots()} computed live, with two intended
 * differences:
 * - A calendar for an item without location filter is answered as the union of the item's pairs. The live calendar
 *   puts all locations of the item into one day grid, where only one timeframe per hour survives. Both are the same
 *   as long as the item has no overlapping timeframes at different locations.
 * - Days after the end date (the calendar always ends on a sunday) consider all posts. The live calendar only knows
 *   the timeframes overlapping the requested range there, e.g. a booking starting on such a day is missing.
 *
 * Returns null whenever the live calculation has to answer: precomputation disabled, restrictions ignored, a range
 * outside the horizon, timeframes with several items or locations, access profiles beyond the maximum per pair.
 * Pairs with role limited timeframes are read from the current user's access profile ({@see AccessStore}).
 */
class SlotReader {

	/**
	 * @param string $startDate Y-m-d, start of the calendar
	 * @param string $endDate Y-m-d, end of the calendar
	 * @param int[]  $locations location filter of the calendar, empty for all
	 * @param int[]  $items item filter of the calendar, must not be empty
	 * @param bool   $ignoreStartDayOffset
	 * @param bool   $ignoreRestrictions
	 *
	 * @return stdClass[]|null slots like {@see \CommonsBooking\Model\Calendar::getAvailabilitySlots()}, null if the
	 *                         calendar has to be computed live
	 * @throws \Exception
	 */
	public static function getAvailabilitySlots( string $startDate, string $endDate, array $locations, array $items, bool $ignoreStartDayOffset, bool $ignoreRestrictions ): ?array {
		if ( $ignoreRestrictions || ! Availability::isEnabled() ) {
			return null;
		}
		$items     = self::ids( $items );
		$locations = self::ids( $locations );
		if ( ! $items ) {
			return null;
		}

		// days of the calendar: from the start through the sunday of the end date's week
		$dates = Reader::getCalendarDates( $startDate, $endDate );
		if ( ! $dates ) {
			return null;
		}
		[ $from, $to ] = Availability::getHorizon();
		if ( reset( $dates ) < $from || end( $dates ) > $to ) {
			return null;
		}

		// changes of this request must reach the index (and mark the pairs) before stored rows are read
		Indexer::flush();

		$pairs = self::getPairs( $items, $locations, strtotime( reset( $dates ) ), strtotime( end( $dates ) . ' 23:59:59' ) );
		if ( $pairs === null ) {
			return null;
		}

		$rows       = Store::loadWithSlots( $pairs );
		$accessRows = self::loadAccessProfileRows( $pairs, $rows );
		$data       = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			$pairData = self::getPairData( $itemId, $locationId, $rows[ $locationId . ':' . $itemId ] ?? null, $from, $accessRows );
			if ( $pairData === null ) {
				return null;
			}
			$data[] = $pairData;
		}

		$slots             = [];
		$done              = [];
		$firstBookableDays = []; // by timeframe id, depends on the current date
		$latestBookingTime = []; // by timeframe id, depends on the current date
		foreach ( $dates as $date ) {
			$base     = strtotime( $date );
			$daySlots = [];
			foreach ( $data as [ $row, $unpacked ] ) {
				$offset = Store::getDayOffset( $row, $date );
				if ( $offset === null ) {
					return null; // not covered by the row
				}
				foreach ( $unpacked['days'][ $offset ] ?? [] as [ $start, $end, $timeframeIndex ] ) {
					[ $timeframeId, $locationId, $itemId ] = $unpacked['timeframes'][ $timeframeIndex ];
					// booking window: the stored slots are computed without it, the live day drops the timeframe
					// once its latest possible booking date is before the day ({@see \CommonsBooking\Model\Day})
					if ( ! array_key_exists( $timeframeId, $latestBookingTime ) ) {
						$latestBookingTime[ $timeframeId ] = self::getLatestPossibleBookingDateTimestamp( $timeframeId );
					}
					if ( $latestBookingTime[ $timeframeId ] === null || $latestBookingTime[ $timeframeId ] < $base ) {
						continue;
					}
					if ( ! $ignoreStartDayOffset ) {
						if ( ! array_key_exists( $timeframeId, $firstBookableDays ) ) {
							$firstBookableDays[ $timeframeId ] = self::getFirstBookableDay( $timeframeId );
						}
						$firstBookableDay = $firstBookableDays[ $timeframeId ];
						if ( $firstBookableDay === null || $firstBookableDay > $date ) {
							continue;
						}
					}
					$daySlots[] = [ $base + $start, $base + $end, $locationId, $itemId ];
				}
			}
			// live: one grid per day, ordered by time
			usort( $daySlots, fn( $a, $b ) => $a[0] <=> $b[0] );
			foreach ( $daySlots as [ $start, $end, $locationId, $itemId ] ) {
				Slots::addUnique( $slots, $done, Slots::toApiSlot( $start, $end, $locationId, $itemId ) );
			}
		}

		return $slots;
	}

	/**
	 * Loads the rows of the current user's access profiles for the pairs with role limited timeframes.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs
	 * @param array<string, array>           $rows pair rows by "locationId:itemId"
	 *
	 * @return array<string, array> by "locationId:itemId:accessProfile"
	 */
	private static function loadAccessProfileRows( array $pairs, array $rows ): array {
		if ( ! AccessProfile::isEnabled() ) {
			return [];
		}
		$limited = array_values( array_filter( $pairs, fn( $pair ) => (int) ( $rows[ $pair[1] . ':' . $pair[0] ]['has_role_limit'] ?? 0 ) === 1 ) );
		if ( ! $limited ) {
			return [];
		}
		AccessProfile::preloadRoles( $limited );

		return AccessStore::loadWithSlots(
			array_map( fn( $pair ) => [ $pair[0], $pair[1], AccessProfile::forCurrentUser( $pair[0], $pair[1] ) ], $limited )
		);
	}

	/**
	 * Returns the stored row and its unpacked slots, computes the pair if the row is not usable.
	 *
	 * @param int                  $itemId
	 * @param int                  $locationId
	 * @param array|null           $row from {@see Store::loadWithSlots()}
	 * @param string               $from first day of the horizon
	 * @param array<string, array> $accessRows from {@see self::loadAccessProfileRows()}
	 *
	 * @return array{0: array, 1: array}|null [row with days_from and days, unpacked slots], null for live
	 * @throws \Exception
	 */
	private static function getPairData( int $itemId, int $locationId, ?array $row, string $from, array $accessRows ): ?array {
		if ( Store::isUsable( $row ) && $row['slots'] !== null ) {
			return [ $row, Slots::unpack( $row['slots'] ) ];
		}
		if ( $row && (int) $row['has_role_limit'] === 1 && (int) $row['computed_gen'] === (int) $row['gen'] ) {
			return self::getAccessProfileData( $itemId, $locationId, $row, $from, $accessRows );
		}

		$computed = Calculator::getComputed( $itemId, $locationId ) ?? Calculator::recompute( $itemId, $locationId );
		if ( $computed['roleLimited'] ) {
			$row = Store::get( $itemId, $locationId );
			return $row ? self::getAccessProfileData( $itemId, $locationId, $row, $from, [] ) : null;
		}

		return self::fromComputed( $computed, $from );
	}

	/**
	 * Row and unpacked slots of the current user's access profile, computed if there is no usable row.
	 *
	 * @param int                  $itemId
	 * @param int                  $locationId
	 * @param array                $pairRow
	 * @param string               $from
	 * @param array<string, array> $accessRows
	 *
	 * @return array{0: array, 1: array}|null
	 * @throws \Exception
	 */
	private static function getAccessProfileData( int $itemId, int $locationId, array $pairRow, string $from, array $accessRows ): ?array {
		if ( ! AccessProfile::isEnabled() ) {
			return null;
		}
		$accessProfile = AccessProfile::forCurrentUser( $itemId, $locationId );
		$row           = $accessRows[ $locationId . ':' . $itemId . ':' . $accessProfile ] ?? null;
		if ( AccessStore::isUsable( $row, $pairRow ) && $row['slots'] !== null ) {
			return [ $row, Slots::unpack( $row['slots'] ) ];
		}

		$computed = Calculator::computeAccessProfile( $itemId, $locationId, $accessProfile );

		return $computed === null ? null : self::fromComputed( $computed, $from );
	}

	/**
	 * @param array{from: string, flags: int[], slots: ?string} $computed
	 * @param string                                            $from
	 *
	 * @return array{0: array, 1: array}|null
	 */
	private static function fromComputed( array $computed, string $from ): ?array {
		if ( $computed['from'] !== $from || $computed['slots'] === null ) {
			return null;
		}

		return [
			[
				'days_from' => $computed['from'],
				'days'      => pack( 'v*', ...$computed['flags'] ),
			],
			Slots::unpack( $computed['slots'] ),
		];
	}

	/**
	 * Pairs with a bookable timeframe in the range, from the index.
	 *
	 * @param int[] $items
	 * @param int[] $locations empty for all
	 * @param int   $startTimestamp
	 * @param int   $endTimestamp
	 *
	 * @return array<int, array{0:int,1:int}>|null [itemId, locationId], null if a timeframe with several items or
	 *                                            locations is involved (not precomputed)
	 */
	private static function getPairs( array $items, array $locations, int $startTimestamp, int $endTimestamp ): ?array {
		global $wpdb;
		$table    = IndexSchema::table();
		$relTable = IndexSchema::relTable();
		$itemList = implode( ',', $items );

		$common = $wpdb->prepare(
			"i.post_type = %s AND i.type = %d AND i.post_status IN ('publish', 'confirmed') AND (i.start_ts IS NULL OR i.start_ts <= %d) AND (i.end_ts IS NULL OR i.end_ts >= %d)",
			\CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType,
			\CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID,
			$endTimestamp,
			$startTimestamp
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- prepared above, only integers
		$multi = $wpdb->get_var(
			"SELECT 1 FROM $table i INNER JOIN $relTable r ON r.post_id = i.post_id AND r.entity_type = 'item' AND r.entity_id IN ($itemList)
			WHERE i.is_multi = 1 AND $common LIMIT 1"
		);
		if ( $multi ) {
			return null;
		}

		$rows = $wpdb->get_results( "SELECT DISTINCT i.item_id, i.location_id, i.is_multi FROM $table i WHERE i.item_id IN ($itemList) AND $common", ARRAY_A );
		// phpcs:enable

		$pairs = [];
		foreach ( $rows as $row ) {
			if ( (int) $row['is_multi'] === 1 || $row['location_id'] === null ) {
				return null;
			}
			$locationId = (int) $row['location_id'];
			if ( $locations && ! in_array( $locationId, $locations, true ) ) {
				continue;
			}
			$pairs[ $locationId . ':' . $row['item_id'] ] = [ (int) $row['item_id'], $locationId ];
		}
		ksort( $pairs );

		return array_values( $pairs );
	}

	/**
	 * @param int $timeframeId
	 *
	 * @return string|null Y-m-d, null if the timeframe does not exist anymore
	 */
	private static function getFirstBookableDay( int $timeframeId ): ?string {
		try {
			return ( new Timeframe( $timeframeId ) )->getFirstBookableDay();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * @param int $timeframeId
	 *
	 * @return int|null timestamp, null if the timeframe does not exist anymore
	 */
	private static function getLatestPossibleBookingDateTimestamp( int $timeframeId ): ?int {
		try {
			return (int) ( new Timeframe( $timeframeId ) )->getLatestPossibleBookingDateTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * @param array $values
	 *
	 * @return int[]
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_map( 'intval', array_filter( $values ) ) ) );
	}
}
