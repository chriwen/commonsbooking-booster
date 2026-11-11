<?php

namespace CommonsBooking\Service\Stats;

use CommonsBooking\Model\Calendar;
use CommonsBooking\Model\Day;
use CommonsBooking\Plugin;
use CommonsBooking\Service\Availability\AccessProfile;
use CommonsBooking\Wordpress\CustomPostType\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use WP_Post;

/**
 * Computes the daily values (capacity, booked and blocked slots) of an item/location pair from the day grid and
 * stores them in {@see Schema::daily()}.
 *
 * Units: a slot of a full day timeframe (or booking) counts as one slot of unit 'day'. All other slots count in hours
 * of unit 'hour' (also fixed time slots without grid, which count with their length in hours).
 *
 * - capacity: bookable and booked slots after blocks (repair timeframes, holidays and active repair restrictions)
 * - booked: slots of confirmed bookings
 * - failure: capacity that an active repair restriction (total failure) takes away, nothing else
 * - outage: capacity that a block takes away: an active repair restriction, a holiday or closure timeframe, or a
 *   repair timeframe (the same day without the blocks is compared)
 *
 * The counts of started and cancelled bookings come from {@see Schema::booking()}.
 */
class Calculator {

	/**
	 * Days per calendar object, bounds the memory of long ranges.
	 */
	private const CHUNK_DAYS = 366;

	/**
	 * Computes and stores the days from $from to $to (inclusive) of a pair. Days after today are never stored.
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 *
	 * @return int number of stored rows
	 * @throws \Exception
	 */
	public static function recompute( int $itemId, int $locationId, string $from, string $to ): int {
		global $wpdb;
		$today = date( 'Y-m-d' );
		$to    = min( $to, $today );
		if ( $from > $to ) {
			return 0;
		}

		$rows = AccessProfile::runAs(
			AccessProfile::forAllRoles( $itemId, $locationId ),
			fn() => self::computeRows( $itemId, $locationId, $from, $to )
		);
		$rows = self::mergeBookingCounts( $itemId, $locationId, $from, $to, $rows );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::daily() . ' WHERE item_id = %d AND location_id = %d AND day BETWEEN %s AND %s',
				$itemId,
				$locationId,
				$from,
				$to
			)
		);
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $row ) {
				$values[] = $wpdb->prepare(
					'(%s,%d,%d,%s,%d,%d,%d,%d,%d,%d)',
					$row['day'],
					$itemId,
					$locationId,
					$row['unit'],
					$row['capacity'],
					$row['booked'],
					$row['outage'],
					$row['failure'],
					$row['started'],
					$row['canceled']
				);
			}
			$wpdb->query(
				'INSERT INTO ' . Schema::daily() . ' (day,item_id,location_id,unit,capacity_slots,booked_slots,outage_slots,failure_slots,bookings_started,bookings_canceled) VALUES ' . implode( ',', $values )
			);
		}
		// phpcs:enable

		return count( $rows );
	}

	/**
	 * Daily slot counts from the day grid.
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $from
	 * @param string $to
	 *
	 * @return array<int, array{day: string, unit: string, capacity: int, booked: int, outage: int, failure: int, started: int, canceled: int}>
	 * @throws \Exception
	 */
	private static function computeRows( int $itemId, int $locationId, string $from, string $to ): array {
		$result = [];
		for ( $chunkFrom = $from; $chunkFrom <= $to; $chunkFrom = date( 'Y-m-d', strtotime( $chunkFrom . ' +' . self::CHUNK_DAYS . ' days' ) ) ) {
			$chunkTo = min( $to, date( 'Y-m-d', strtotime( $chunkFrom . ' +' . ( self::CHUNK_DAYS - 1 ) . ' days' ) ) );
			Plugin::withoutCache(
				function () use ( $itemId, $locationId, $chunkFrom, $chunkTo, &$result ) {
					$calendar   = // a calendar spans at least two days, the surplus day is skipped below
					$calendarTo = $chunkTo === $chunkFrom ? date( 'Y-m-d', strtotime( $chunkTo . ' +1 day' ) ) : $chunkTo;
					$calendar   = new Calendar( new Day( $chunkFrom ), new Day( $calendarTo ), [ $locationId ], [ $itemId ] );
					foreach ( $calendar->getWeeks() as $week ) {
						/** @var Day $day */
						foreach ( $week->getDays() as $day ) {
							$date = $day->getDate();
							if ( $date < $chunkFrom || $date > $chunkTo ) {
								continue;
							}
							foreach ( self::countDay( $day, $itemId, $locationId ) as $unit => $counts ) {
								$result[] = [
									'day'      => $date,
									'unit'     => $unit,
									'capacity' => $counts['capacity'],
									'booked'   => $counts['booked'],
									'outage'   => $counts['outage'],
									'failure'  => $counts['failure'],
									'started'  => 0,
									'canceled' => 0,
								];
							}
						}
					}
				}
			);
		}

		return $result;
	}

	/**
	 * @param Day $day
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return array<string, array{capacity: int, booked: int, outage: int, failure: int}> by unit, only units with values
	 * @throws \Exception
	 */
	private static function countDay( Day $day, int $itemId, int $locationId ): array {
		$grid    = $day->getGrid();
		$counts  = self::countGrid( $grid );
		$outage  = [];
		$failure = [];
		if ( self::hasRestriction( $grid ) ) {
			// the same day without restrictions: what a total failure (repair restriction) takes away
			$day->setIgnoreRestrictions( true );
			foreach ( self::countGrid( $day->getGrid() ) as $unit => $c ) {
				$failure[ $unit ] = max( 0, $c['capacity'] - ( $counts[ $unit ]['capacity'] ?? 0 ) );
			}
			$day->setIgnoreRestrictions( false );
		}
		if ( self::hasBlock( $grid ) ) {
			// the same day with only bookable timeframes and bookings, without restrictions, holidays, closures and
			// repair timeframes, shows the capacity that these blocks take away
			$open = new Day( $day->getDate(), [ $locationId ], [ $itemId ], [ Timeframe::BOOKABLE_ID, Timeframe::BOOKING_ID ] );
			$open->setIgnoreRestrictions( true );
			foreach ( self::countGrid( $open->getGrid() ) as $unit => $c ) {
				$outage[ $unit ] = max( 0, $c['capacity'] - ( $counts[ $unit ]['capacity'] ?? 0 ) );
			}
		}

		$result = [];
		foreach ( array_unique( array_merge( array_keys( $counts ), array_keys( $outage ), array_keys( $failure ) ) ) as $unit ) {
			$result[ $unit ] = [
				'capacity' => $counts[ $unit ]['capacity'] ?? 0,
				'booked'   => $counts[ $unit ]['booked'] ?? 0,
				'outage'   => $outage[ $unit ] ?? 0,
				'failure'  => $failure[ $unit ] ?? 0,
			];
		}

		return $result;
	}

	/**
	 * @param array $grid
	 *
	 * @return bool true if a repair restriction (total failure) blocks a slot
	 */
	private static function hasRestriction( array $grid ): bool {
		foreach ( $grid as $slot ) {
			$post = $slot['timeframe'] ?? null;
			if ( $post instanceof WP_Post && $post->post_type === Restriction::$postType ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True if a slot of the grid is blocked: by a repair restriction, or by a holiday, closure or repair timeframe.
	 *
	 * @param array $grid
	 *
	 * @return bool
	 */
	private static function hasBlock( array $grid ): bool {
		foreach ( $grid as $slot ) {
			$post = $slot['timeframe'] ?? null;
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( $post->post_type === Restriction::$postType ) {
				return true;
			}
			$type = (int) get_post_meta( $post->ID, 'type', true );
			if ( $type === Timeframe::HOLIDAYS_ID || $type === Timeframe::REPAIR_ID ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Counts capacity and booked slots of a grid by unit.
	 *
	 * @param array $grid slots from {@see Day::getGrid()}
	 *
	 * @return array<string, array{capacity: int, booked: int}>
	 */
	private static function countGrid( array $grid ): array {
		$counts = [];
		foreach ( $grid as $slot ) {
			$post = $slot['timeframe'] ?? null;
			if ( ! $post instanceof WP_Post || $post->post_type === Restriction::$postType ) {
				continue;
			}
			$type = (int) get_post_meta( $post->ID, 'type', true );
			if ( $type !== Timeframe::BOOKABLE_ID && $type !== Timeframe::BOOKING_ID ) {
				continue;
			}
			if ( get_post_meta( $post->ID, 'full-day', true ) === 'on' ) {
				$unit = 'day';
				$size = 1;
			} else {
				$unit = 'hour';
				$size = max( 1, (int) round( ( (int) $slot['timestampend'] + 1 - (int) $slot['timestampstart'] ) / 3600 ) );
			}
			$counts[ $unit ]['capacity'] = ( $counts[ $unit ]['capacity'] ?? 0 ) + $size;
			$counts[ $unit ]['booked']   = ( $counts[ $unit ]['booked'] ?? 0 ) + ( $type === Timeframe::BOOKING_ID ? $size : 0 );
		}

		return $counts;
	}

	/**
	 * Adds the started and cancelled bookings (counted on the start day) from the booking table.
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $from
	 * @param string $to
	 * @param array  $rows rows of {@see self::computeRows()}
	 *
	 * @return array
	 */
	private static function mergeBookingCounts( int $itemId, int $locationId, string $from, string $to, array $rows ): array {
		global $wpdb;
		$index = [];
		foreach ( $rows as $i => $row ) {
			$index[ $row['day'] . '|' . $row['unit'] ] = $i;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(DATE_ADD('1970-01-01', INTERVAL start_ts SECOND)) AS day, unit,
					SUM(status = 'confirmed') AS started, SUM(status = 'canceled') AS canceled
				FROM " . Schema::booking() . '
				WHERE item_id = %d AND location_id = %d
					AND start_ts >= %d AND start_ts < %d
				GROUP BY day, unit',
				$itemId,
				$locationId,
				strtotime( $from . ' 00:00:00 UTC' ),
				strtotime( $to . ' 00:00:00 UTC' ) + 86400
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $found as $f ) {
			$key = $f['day'] . '|' . $f['unit'];
			if ( ! isset( $index[ $key ] ) ) {
				$rows[]        = [
					'day'      => $f['day'],
					'unit'     => $f['unit'],
					'capacity' => 0,
					'booked'   => 0,
					'outage'   => 0,
					'failure'  => 0,
					'started'  => 0,
					'canceled' => 0,
				];
				$index[ $key ] = array_key_last( $rows );
			}
			$rows[ $index[ $key ] ]['started']  = (int) $f['started'];
			$rows[ $index[ $key ] ]['canceled'] = (int) $f['canceled'];
		}

		return $rows;
	}
}
