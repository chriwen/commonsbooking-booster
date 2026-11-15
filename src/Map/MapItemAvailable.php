<?php

namespace CommonsBooking\Map;

use CommonsBooking\Helper\Wordpress;
use CommonsBooking\Model\Day;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\DayStatus;
use CommonsBooking\Service\Availability\Reader;
use DateInterval;
use DatePeriod;
use Exception;

class MapItemAvailable {

	/**
	 * item is available
	 */
	const ITEM_AVAILABLE = 'available';


	/**
	 * location closed because of holiday / official holiday
	 */
	const LOCATION_HOLIDAY = 'location-holiday';

	/**
	 * item is partially booked
	 */
	const ITEM_PARTIALLY_BOOKED = 'partially-booked';

	/**
	 * item is partially locked
	 */
	const ITEM_LOCKED = 'locked';


	/**
	 * item is booked or blocked
	 */
	const ITEM_BOOKED = 'booked';

	/**
	 * no timeframe for item set
	 */
	const OUT_OF_TIMEFRAME = 'no-timeframe';

	/**
	 * @param $locations
	 * @param $date_start
	 * @param $date_end
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public static function create_items_availabilities( $locations, $date_start, $date_end ) {

		$startDay = new Day( $date_start );
		$endDay   = new Day( $date_end );
		$from     = $startDay->getFormattedDate( 'Y-m-d' );
		$to       = $endDay->getFormattedDate( 'Y-m-d' );

		$filter_period = new DatePeriod(
			Wordpress::getUTCDateTime( $date_start ),
			new DateInterval( 'P1D' ),
			Wordpress::getUTCDateTime( $date_end . ' +1 day' )
		);

		// precomputed availability: load the stored days and bookable timeframes of all pairs at once
		$reader = null;
		if ( Availability::isEnabled() ) {
			$reader = new Reader();
			$pairs  = [];
			foreach ( $locations as $location_id => $location ) {
				foreach ( $location['items'] as $item ) {
					$pairs[] = [ (int) $item['id'], (int) $location_id ];
				}
			}
			$reader->preload( $pairs );
		}

		foreach ( $locations as $location_id => &$location ) {
			foreach ( $location['items'] as &$item ) {

				// Init availability array
				$availability = [];
				foreach ( $filter_period as $date ) {
					$availability[] = [
						'date'   => $date->format( 'Y-m-d' ),
						'status' => self::OUT_OF_TIMEFRAME,
					];
				}

				// status of the days of the calendar data, same as Calendar::getCalendarDataArray( item, location, from, to )
				$statuses = $reader
					? $reader->getDayStatuses( (int) $item['id'], (int) $location_id, $from, $to, false, $from, $to )
					: Reader::evaluateLive( (int) $item['id'], (int) $location_id, $from, $to, false, $from, $to );

				$item['availability'] = self::markDays( $statuses['days'], $availability );
			}
		}

		return $locations;
	}

	/**
	 * Maps the day status of the calendar data to the status shown in the map.
	 */
	private const STATUS_MAPPING = [
		DayStatus::NO_SLOTS               => self::ITEM_LOCKED,
		DayStatus::HOLIDAY                => self::LOCATION_HOLIDAY,
		DayStatus::BOOKED                 => self::ITEM_BOOKED,
		DayStatus::PARTIALLY_BOOKED_START => self::ITEM_PARTIALLY_BOOKED,
		DayStatus::PARTIALLY_BOOKED_END   => self::ITEM_PARTIALLY_BOOKED,
		DayStatus::LOCKED                 => self::ITEM_LOCKED,
		DayStatus::AVAILABLE              => self::ITEM_AVAILABLE,
	];

	/**
	 * Sets the map status of the days that are part of the calendar data.
	 * Uses the same rules as the items table ({@see DayStatus}), other days keep the status "no-timeframe".
	 *
	 * @param array<string, string> $dayStatuses {@see DayStatus} by date
	 * @param array                 $availabilities
	 *
	 * @return array
	 */
	protected static function markDays( array $dayStatuses, array $availabilities ): array {
		foreach ( $availabilities as &$availability ) {
			if ( isset( $dayStatuses[ $availability['date'] ] ) ) {
				$availability['status'] = self::STATUS_MAPPING[ $dayStatuses[ $availability['date'] ] ];
			}
		}

		return $availabilities;
	}
}
