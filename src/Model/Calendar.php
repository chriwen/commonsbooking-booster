<?php

namespace CommonsBooking\Model;

use CommonsBooking\Plugin;
use CommonsBooking\Service\Availability\SlotReader;
use CommonsBooking\Service\Availability\Slots;

/**
 * Represents a span of weeks, which is used to display a calendar.
 *
 * @uses Week
 */
class Calendar {

	/**
	 * @var Day
	 */
	protected Day $startDate;

	/**
	 * @var Day
	 */
	protected Day $endDate;

	/**
	 * @var int[]
	 */
	protected array $items;

	/**
	 * @var int[]
	 */
	protected array $locations;

	/**
	 * @var array
	 */
	protected $types;

	/**
	 * The timeframes that are relevant for this calendar, loaded with the first uncached {@see self::getWeeks()}.
	 *
	 * @var Timeframe[]|null
	 */
	protected ?array $timeframes = null;

	/**
	 * Active restrictions overlapping the calendar range, loaded once and handed to the days.
	 *
	 * @var Restriction[]
	 */
	protected array $restrictions = [];

	/**
	 * When this is enabled, @see Timeframe::META_BOOKING_START_DAY_OFFSET is ignored.
	 * This is used for the API routes, where we want to show the actual availability of the items, regardless of the booking start day offset.
	 *
	 * @var bool
	 */
	protected bool $ignoreStartDayOffset = false;

	/**
	 * When this is enabled, restrictions are ignored when creating availabilities.
	 * This is useful when you want to differentiate between an item that is booked / not available
	 * and an item that would be bookable but is in repair.
	 *
	 * Just passed to the \CommonsBooking\Model\Day model, because restriction calculations are made there
	 *
	 * Used in @see \CommonsBooking\API\GBFS\VehicleStatus to differentiate between booked items and disabled items
	 *
	 * @var bool
	 */
	protected bool $ignoreRestrictions = false;

	/**
	 * Calendar constructor.
	 *
	 * @param Day   $startDate
	 * @param Day   $endDate
	 * @param int[] $locations
	 * @param int[] $items
	 * @param array $types
	 */
	public function __construct( Day $startDate, Day $endDate, array $locations = [], array $items = [], array $types = [] ) {
		// check, that it spans at least two days
		if ( $startDate->getDate() == $endDate->getDate() ) {
			throw new \InvalidArgumentException( 'Calendar must span at least two days' );
		}

		$startDate->setIgnoreRestrictions( $this->ignoreRestrictions );
		$endDate->setIgnoreRestrictions( $this->ignoreRestrictions );

		$this->startDate = $startDate;
		$this->endDate   = $endDate;
		$this->items     = $items;
		$this->locations = $locations;
		$this->types     = $types;
	}

	/**
	 * Loads the timeframes and restrictions of the range. Not done in the constructor because a calendar answered
	 * from the cache or from the precomputed availability doesn't need them.
	 *
	 * @return void
	 * @throws \Exception
	 */
	protected function loadPosts(): void {
		if ( $this->timeframes !== null ) {
			return;
		}

		$this->timeframes = \CommonsBooking\Repository\Timeframe::getInRange(
			$this->startDate->getStartTimestamp(),
			$this->endDate->getEndTimestamp(),
			$this->locations,
			$this->items,
			$this->types,
			true,
			[ 'confirmed', 'publish' ]
		);

		// Superset for all days of the range (end after the day before the start or open end).
		// Each day filters for itself, see Day::getRestrictions().
		$this->restrictions = \CommonsBooking\Repository\Restriction::get(
			$this->locations,
			$this->items,
			null,
			true,
			$this->startDate->getStartTimestamp() - 1,
			Day::RESTRICTION_STATUSES
		);
	}

	/**
	 * Returns weeks for calendar time range.
	 *
	 * Uses cache and expires at midnight on a daily basis.
	 *
	 * @return array
	 */
	public function getWeeks(): array {
		$startDate = strtotime( $this->startDate->getDate() ) + 1;
		$endDate   = strtotime( $this->endDate->getDate() );

		$customId = md5(
			$startDate .
			$endDate .
			serialize( $this->items ) .
			serialize( $this->locations ) .
			serialize( $this->types )
		);

		$cacheItem = Plugin::getCacheItem( $customId );
		if ( $cacheItem ) {
			return $cacheItem;
		} else {
			$this->loadPosts();
			$weeks = array();
			while ( $startDate <= $endDate ) {
				$dayOfYear = date( 'z', $startDate );
				$year      = date( 'Y', $startDate );
				$weeks[]   = new Week(
					$year,
					$dayOfYear,
					$this->locations,
					$this->items,
					$this->types,
					$this->timeframes,
					$this->restrictions
				);
				$startDate = strtotime( 'next monday', $startDate );
			}

			// set cache expiration to force daily fresh after midnight
			Plugin::setCacheItem( $weeks, array( 'misc' ), $customId, 'midnight' );

			return $weeks;
		}
	}

	/**
	 * Will retrieve the respective availability slots for a given calendar.
	 * This is used to display availabilities for the API routes.
	 *
	 * Because we process the calendar by weeks, at least two days are needed to get a valid calendar.
	 * The calendar does not consider the individual boundaries set by $startDate and $endDate but will always return a full week.
	 *
	 * Read from the precomputed availability if possible ({@see SlotReader}), otherwise computed live.
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function getAvailabilitySlots(): array {
		$slots = SlotReader::getAvailabilitySlots(
			$this->startDate->getDate(),
			$this->endDate->getDate(),
			$this->locations,
			$this->items,
			$this->ignoreStartDayOffset,
			$this->ignoreRestrictions
		);

		return $slots ?? $this->getAvailabilitySlotsLive();
	}

	/**
	 * Computes the availability slots from the day grids.
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function getAvailabilitySlotsLive(): array {
		$slots     = [];
		$doneSlots = [];
		/** @var Week $week */
		foreach ( $this->getWeeks() as $week ) {
			/** @var Day $day */
			foreach ( $week->getDays() as $day ) {
				$day->setIgnoreRestrictions( $this->ignoreRestrictions );
				// Skip everything where the most important slot is not bookable. We are only interested in direct availability.
				foreach ( Slots::bookableSlots( $day->getGrid() ) as $slot ) {
					$timeframe = new Timeframe( $slot['timeframe'] );

					// Skip timeframes that are not bookable today
					if ( ! $this->ignoreStartDayOffset && $timeframe->getFirstBookableDay() > $day->getDate() ) {
						continue;
					}

					[ $locationId, $itemId ] = Slots::getEntityIds( $timeframe );
					Slots::addUnique( $slots, $doneSlots, Slots::toApiSlot( $slot['start'], $slot['end'], $locationId, $itemId ) );
				}
			}
		}
		return $slots;
	}

	public function setIgnoreStartDayOffset( bool $ignoreStartDayOffset ): void {
		$this->ignoreStartDayOffset = $ignoreStartDayOffset;
	}

	public function setIgnoreRestrictions( bool $ignoreRestrictions ): void {
		$this->ignoreRestrictions = $ignoreRestrictions;
	}
}
