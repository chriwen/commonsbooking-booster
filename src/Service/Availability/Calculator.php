<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Calendar;
use CommonsBooking\Model\Day;
use CommonsBooking\Plugin;
use CommonsBooking\Repository\Index;

/**
 * Computes the {@see DayFacts} of an item/location pair for the precomputation horizon and stores them.
 */
class Calculator {

	/**
	 * Version of the calculation. Increase when {@see DayFacts::fromGrid()}, {@see Slots} or the grid calculation
	 * changes, all stored rows are recomputed then.
	 * 2: packed availability slots for API and GBFS (column slots)
	 */
	public const VERSION = 2;

	/**
	 * Result of the last computations in this request, by "locationId:itemId".
	 *
	 * @var array<string, array{from: string, flags: int[], slots: ?string, roleLimited: bool}>
	 */
	private static array $computed = [];

	/**
	 * Number of computations in this request (for tests and measurements).
	 */
	private static int $computeCount = 0;

	/**
	 * Computes and stores a pair.
	 *
	 * The result is returned even if it could not be stored because the pair changed during the computation:
	 * it reflects the database state after the change that was read, which is what the caller asked for.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return array{from: string, flags: int[], slots: ?string, roleLimited: bool}
	 * @throws \Exception
	 */
	public static function recompute( int $itemId, int $locationId ): array {
		$gen    = Store::getGen( $itemId, $locationId );
		$result = self::compute( $itemId, $locationId );
		Store::write( $itemId, $locationId, $gen, $result['from'], $result['flags'], $result['roleLimited'], $result['slots'] );

		self::$computed[ $locationId . ':' . $itemId ] = $result;

		return $result;
	}

	/**
	 * Computes the facts and the bookable slots of all days of the horizon from the current database state
	 * (bypassing the cache). Both come from the same day grid.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return array{from: string, flags: int[], slots: ?string, roleLimited: bool} slots packed with {@see Slots::pack()}
	 * @throws \Exception
	 */
	public static function compute( int $itemId, int $locationId ): array {
		[ $from, $to ] = Availability::getHorizon();
		++self::$computeCount;

		if ( self::hasRoleLimit( $itemId, $locationId ) ) {
			return [
				'from'        => $from,
				'flags'       => [],
				'slots'       => null,
				'roleLimited' => true,
			];
		}

		[ $flags, $slots ] = Plugin::withoutCache(
			function () use ( $itemId, $locationId, $from, $to ) {
				$flags    = [];
				$slots    = [];
				$calendar = new Calendar( new Day( $from ), new Day( $to ), [ $locationId ], [ $itemId ] );
				foreach ( $calendar->getWeeks() as $week ) {
					/** @var Day $day */
					foreach ( $week->getDays() as $day ) {
						$date = $day->getDate();
						if ( $date >= $from && $date <= $to ) {
							$grid           = $day->getGrid();
							$flags[ $date ] = DayFacts::fromGrid( $grid )->getFlags();
							$slots[ $date ] = Slots::bookableSlots( $grid );
						}
					}
				}

				return [ $flags, $slots ];
			}
		);

		// the calendar contains every day of the range, make sure the lists start at $from without gaps
		$list     = [];
		$slotDays = [];
		for ( $date = $from; $date <= $to; $date = date( 'Y-m-d', strtotime( $date . ' +1 day' ) ) ) {
			$list[]     = $flags[ $date ] ?? 0;
			$slotDays[] = [
				'date'  => $date,
				'slots' => $slots[ $date ] ?? [],
			];
		}

		return [
			'from'        => $from,
			'flags'       => $list,
			'slots'       => Slots::pack( $slotDays ),
			'roleLimited' => false,
		];
	}

	/**
	 * Returns the facts of a pair computed in this request, if any.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return array{from: string, flags: int[], slots: ?string, roleLimited: bool}|null
	 */
	public static function getComputed( int $itemId, int $locationId ): ?array {
		return self::$computed[ $locationId . ':' . $itemId ] ?? null;
	}

	/**
	 * @return int number of computations in this request
	 */
	public static function getComputeCount(): int {
		return self::$computeCount;
	}

	/**
	 * Forgets the results of this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$computed = [];
	}

	/**
	 * Days of a pair with timeframes limited to user roles depend on the current user and are not stored.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return bool
	 */
	public static function hasRoleLimit( int $itemId, int $locationId ): bool {
		$postIds = Index::findPostIds(
			[
				'post_types' => [ \CommonsBooking\Wordpress\CustomPostType\Timeframe::$postType ],
				'statuses'   => [ 'publish', 'confirmed' ],
				'items'      => [ $itemId ],
				'locations'  => [ $locationId ],
			]
		);
		if ( ! $postIds ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- only integers
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . \CommonsBooking\Service\Index\Schema::table() . ' WHERE has_role_limit = 1 AND post_id IN (' . implode( ',', array_map( 'intval', $postIds ) ) . ') LIMIT 1' );
	}
}
