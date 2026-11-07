<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Helper\Helper;
use CommonsBooking\Model\Day;
use CommonsBooking\Repository\Timeframe;
use CommonsBooking\View\Calendar;

/**
 * Returns the {@see DayStatus} of the days of item/location pairs for the map and the items table.
 *
 * Gives the same result as evaluating the calendar json of {@see Calendar::getCalendarDataArray()}:
 * - the window (displayed range, booking window, start day offset) is determined at read time with {@see CalendarWindow},
 * - the facts of the days come from the stored rows ({@see Store}), or are computed and stored if the row is not usable,
 * - pairs with role limited timeframes read the row of the current user's access profile ({@see AccessStore}),
 * - ranges beyond the horizon and access profiles beyond the maximum per pair are evaluated live with the calendar json.
 */
class Reader {

	/**
	 * Bookable timeframes of preloaded pairs for the current user, by location and item.
	 *
	 * @var array<int, array<int, \CommonsBooking\Model\Timeframe[]>>
	 */
	private array $bookableTimeframes = [];

	/**
	 * @var array<string, true> pairs whose bookable timeframes are in $bookableTimeframes
	 */
	private array $loadedPairs = [];

	/**
	 * Loads stored rows and bookable timeframes of several pairs with few queries.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs list of [itemId, locationId]
	 *
	 * @return void
	 */
	public function preload( array $pairs ): void {
		// changes of this request must reach the index (and mark the pairs) before stored rows are read
		\CommonsBooking\Service\Index\Indexer::flush();

		$pairs = array_values(
			array_filter(
				$pairs,
				fn( $pair ) => ! isset( $this->loadedPairs[ $pair[1] . ':' . $pair[0] ] )
			)
		);
		if ( ! $pairs ) {
			return;
		}

		Store::preload( $pairs );
		self::preloadAccessProfiles( $pairs );

		$itemIds     = array_values( array_unique( array_map( fn( $pair ) => (int) $pair[0], $pairs ) ) );
		$locationIds = array_values( array_unique( array_map( fn( $pair ) => (int) $pair[1], $pairs ) ) );

		// same query as in Calendar::getCalendarDataArray(), for all pairs at once
		$timeframes = Timeframe::getBookableForCurrentUser( $locationIds, $itemIds, null, true, Helper::getLastFullHourTimestamp() );
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			$this->loadedPairs[ $locationId . ':' . $itemId ]   = true;
			$this->bookableTimeframes[ $locationId ][ $itemId ] = [];
		}
		foreach ( $timeframes as $timeframe ) {
			foreach ( $timeframe->getLocationIDs() as $locationId ) {
				foreach ( $timeframe->getItemIDs() as $itemId ) {
					if ( isset( $this->loadedPairs[ $locationId . ':' . $itemId ] ) ) {
						$this->bookableTimeframes[ $locationId ][ $itemId ][] = $timeframe;
					}
				}
			}
		}
	}

	/**
	 * Loads the access profile rows of the current user for the pairs with role limited timeframes.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs list of [itemId, locationId], rows already in {@see Store}
	 *
	 * @return void
	 */
	private static function preloadAccessProfiles( array $pairs ): void {
		if ( ! AccessProfile::isEnabled() ) {
			return;
		}
		$limited = array_values(
			array_filter(
				$pairs,
				function ( $pair ) {
					$row = Store::get( (int) $pair[0], (int) $pair[1] );
					return $row && (int) $row['has_role_limit'] === 1;
				}
			)
		);
		if ( ! $limited ) {
			return;
		}
		AccessProfile::preloadRoles( $limited );
		AccessStore::preload(
			array_map( fn( $pair ) => [ (int) $pair[0], (int) $pair[1], AccessProfile::forCurrentUser( (int) $pair[0], (int) $pair[1] ) ], $limited )
		);
	}

	/**
	 * Returns the status of the days of a pair.
	 *
	 * Mirrors the use of {@see Calendar::getCalendarDataArray()} with the same parameters: only days that would be part
	 * of the calendar json are returned, limited to [$rangeFrom, $rangeTo].
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $startDate Y-m-d, start passed to the calendar json
	 * @param string $endDate Y-m-d, end passed to the calendar json
	 * @param bool   $keepDaterange as in the calendar json
	 * @param string $rangeFrom Y-m-d, first day of interest
	 * @param string $rangeTo Y-m-d, last day of interest
	 *
	 * @return array{days: array<string, string>, endDate: string} status by date, end date of the calendar json
	 * @throws \Exception
	 */
	public function getDayStatuses( int $itemId, int $locationId, string $startDate, string $endDate, bool $keepDaterange, string $rangeFrom, string $rangeTo ): array {
		$this->preload( [ [ $itemId, $locationId ] ] );

		$window = CalendarWindow::forTimeframes(
			$this->bookableTimeframes[ $locationId ][ $itemId ] ?? [],
			new Day( $startDate ),
			new Day( $endDate ),
			$keepDaterange
		);

		// days of the calendar json: from the start through the sunday of the end date's week
		$dates = self::getCalendarDates( $window->getStartDate()->getDate(), $window->getEndDate()->getDate() );
		$dates = array_values( array_filter( $dates, fn( $date ) => $date >= $rangeFrom && $date <= $rangeTo ) );

		$facts = $this->getFacts( $itemId, $locationId, $window, $dates );
		if ( $facts === null ) {
			return self::evaluateLive( $itemId, $locationId, $startDate, $endDate, $keepDaterange, $rangeFrom, $rangeTo );
		}

		$days = [];
		foreach ( $dates as $date ) {
			$day      = new Day( $date );
			$dayFacts = $facts[ $date ] ?? null;
			$mapped   = $window->mapDay( $day, $dayFacts );
			$hasSlots = $dayFacts !== null && $dayFacts->hasSlots() && $window->isInBookingWindow( $day );

			$days[ $date ] = DayStatus::fromDayArray( $mapped['day'], $hasSlots );
		}

		return [
			'days'    => $days,
			'endDate' => $window->getEndDate()->getDate(),
		];
	}

	/**
	 * Returns the facts of the days inside the booking window.
	 *
	 * @param int            $itemId
	 * @param int            $locationId
	 * @param CalendarWindow $window
	 * @param string[]       $dates
	 *
	 * @return array<string, DayFacts>|null null if the pair has to be evaluated live
	 * @throws \Exception
	 */
	private function getFacts( int $itemId, int $locationId, CalendarWindow $window, array $dates ): ?array {
		$needed = array_values( array_filter( $dates, fn( $date ) => $window->isInBookingWindow( new Day( $date ) ) ) );
		if ( ! $needed ) {
			return [];
		}

		$row = Store::get( $itemId, $locationId );
		if ( Store::isUsable( $row ) ) {
			$facts = [];
			foreach ( $needed as $date ) {
				$flags = Store::getFlags( $row, $date );
				if ( $flags === null ) {
					// requested range is not covered by the horizon
					return null;
				}
				$facts[ $date ] = DayFacts::fromFlags( $flags );
			}

			return $facts;
		}

		[ $from, $to ] = Availability::getHorizon();
		$inHorizon     = reset( $needed ) >= $from && end( $needed ) <= $to;

		if ( $row && (int) $row['has_role_limit'] === 1 && (int) $row['computed_gen'] === (int) $row['gen'] ) {
			return $inHorizon ? self::getAccessProfileFacts( $itemId, $locationId, $row, $needed ) : null;
		}

		if ( ! $inHorizon ) {
			return null;
		}

		$computed = Calculator::getComputed( $itemId, $locationId ) ?? Calculator::recompute( $itemId, $locationId );
		if ( $computed['roleLimited'] ) {
			$row = Store::get( $itemId, $locationId );
			return $row ? self::getAccessProfileFacts( $itemId, $locationId, $row, $needed ) : null;
		}

		return self::factsFromComputed( $computed, $needed );
	}

	/**
	 * Facts of a pair with role limited timeframes for the current user's access profile.
	 *
	 * @param int      $itemId
	 * @param int      $locationId
	 * @param array    $pairRow row of the pair ({@see Store})
	 * @param string[] $needed dates inside the horizon
	 *
	 * @return array<string, DayFacts>|null null if the pair has to be evaluated live
	 * @throws \Exception
	 */
	private static function getAccessProfileFacts( int $itemId, int $locationId, array $pairRow, array $needed ): ?array {
		if ( ! AccessProfile::isEnabled() ) {
			return null;
		}
		$accessProfile = AccessProfile::forCurrentUser( $itemId, $locationId );
		$row           = AccessStore::get( $itemId, $locationId, $accessProfile );
		if ( AccessStore::isUsable( $row, $pairRow ) ) {
			$facts = [];
			foreach ( $needed as $date ) {
				$flags = Store::getFlags( $row, $date );
				if ( $flags === null ) {
					return null;
				}
				$facts[ $date ] = DayFacts::fromFlags( $flags );
			}

			return $facts;
		}

		$computed = Calculator::computeAccessProfile( $itemId, $locationId, $accessProfile );

		return $computed === null ? null : self::factsFromComputed( $computed, $needed );
	}

	/**
	 * @param array{from: string, flags: int[]} $computed result of the {@see Calculator}
	 * @param string[]                          $needed dates
	 *
	 * @return array<string, DayFacts>|null null if the computation does not start at the current horizon
	 */
	private static function factsFromComputed( array $computed, array $needed ): ?array {
		[ $from ] = Availability::getHorizon();
		if ( $computed['from'] !== $from ) {
			return null;
		}

		$facts = [];
		foreach ( $needed as $date ) {
			$offset         = (int) round( ( strtotime( $date . ' 12:00 UTC' ) - strtotime( $from . ' 12:00 UTC' ) ) / DAY_IN_SECONDS );
			$facts[ $date ] = DayFacts::fromFlags( $computed['flags'][ $offset ] ?? 0 );
		}

		return $facts;
	}

	/**
	 * Evaluates a pair with the calendar json, like before the precomputation.
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $startDate
	 * @param string $endDate
	 * @param bool   $keepDaterange
	 * @param string $rangeFrom
	 * @param string $rangeTo
	 *
	 * @return array{days: array<string, string>, endDate: string}
	 * @throws \Exception
	 */
	public static function evaluateLive( int $itemId, int $locationId, string $startDate, string $endDate, bool $keepDaterange, string $rangeFrom, string $rangeTo ): array {
		$calendarData = Calendar::getCalendarDataArray( $itemId, $locationId, $startDate, $endDate, $keepDaterange );

		$days = [];
		foreach ( $calendarData['days'] ?? [] as $date => $day ) {
			if ( $date >= $rangeFrom && $date <= $rangeTo ) {
				$days[ $date ] = DayStatus::fromDayArray( $day );
			}
		}

		return [
			'days'    => $days,
			'endDate' => $calendarData['endDate'] ?? $endDate,
		];
	}

	/**
	 * Dates of the calendar json for a range, see {@see \CommonsBooking\Model\Calendar::getWeeks()}:
	 * the weeks start at the start date and on mondays, the last week ends on sunday.
	 * A range whose end is not after its start has no days.
	 *
	 * @param string $start Y-m-d
	 * @param string $end Y-m-d
	 *
	 * @return string[]
	 */
	public static function getCalendarDates( string $start, string $end ): array {
		$startTimestamp = strtotime( $start );
		$endTimestamp   = strtotime( $end );
		if ( $startTimestamp + 1 > $endTimestamp ) {
			return [];
		}

		$dates = [];
		$date  = new \DateTime( $start );
		$last  = new \DateTime( $end );
		while ( $date <= $last || $date->format( 'w' ) !== '1' ) {
			$dates[] = $date->format( 'Y-m-d' );
			$date->modify( '+1 day' );
		}

		return $dates;
	}
}
