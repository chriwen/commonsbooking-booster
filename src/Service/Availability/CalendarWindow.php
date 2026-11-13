<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Day;
use CommonsBooking\Model\Timeframe;
use CommonsBooking\View\Calendar;

/**
 * The part of the availability that depends on the current date and time:
 * displayed range, booking window (advance booking days) and start day offset.
 *
 * Combined with the date independent {@see DayFacts} it yields the day arrays of the calendar json.
 * Extracted from \CommonsBooking\View\Calendar::getCalendarDataArray(), prepareJsonResponse() and mapDay().
 *
 * @see design/availability-precompute.md
 */
final class CalendarWindow {

	private Day $startDate;

	private Day $endDate;

	private int $advanceBookingDays;

	/**
	 * Timestamp, days starting at or after it are not bookable.
	 */
	private int $lastBookableDate;

	/**
	 * First bookable day (Y-m-d) because of the start day offset, null if there is no bookable timeframe.
	 */
	private ?string $firstBookableDay;

	/**
	 * @param Day         $startDate
	 * @param Day         $endDate
	 * @param mixed       $advanceBookingDays null (or 0) to use the length of the range
	 * @param mixed       $lastBookableDate timestamp, null to derive it from $advanceBookingDays
	 * @param string|null $firstBookableDay
	 */
	public function __construct( Day $startDate, Day $endDate, $advanceBookingDays = null, $lastBookableDate = null, $firstBookableDay = null ) {
		// loose comparisons kept from the original implementation: 0 advance booking days means "not set"
		if ( $advanceBookingDays == null ) {
			$advanceBookingDays = date_diff( $startDate->getDateObject(), $endDate->getDateObject() );
			$advanceBookingDays = (int) $advanceBookingDays->format( '%a ' ) + 1;
		}

		if ( $lastBookableDate == null ) {
			$lastBookableDate = strtotime( '+ ' . $advanceBookingDays . ' days midnight' );
		}

		$this->startDate          = $startDate;
		$this->endDate            = $endDate;
		$this->advanceBookingDays = (int) $advanceBookingDays;
		$this->lastBookableDate   = (int) $lastBookableDate;
		$this->firstBookableDay   = $firstBookableDay;
	}

	/**
	 * Determines the window for an item/location pair from its bookable timeframes.
	 *
	 * @param Timeframe[] $bookableTimeframes bookable timeframes of the pair (for the current user)
	 * @param Day         $startDate requested start
	 * @param Day         $endDate requested end
	 * @param bool        $keepDaterange true to keep the requested range, otherwise it is adjusted to the closest timeframe
	 *
	 * @return self
	 */
	public static function forTimeframes( array $bookableTimeframes, Day $startDate, Day $endDate, bool $keepDaterange = false ): self {
		$advanceBookingDays = null;
		$lastBookableDate   = null;
		$firstBookableDay   = null;

		$closestBookableTimeframe = count( $bookableTimeframes ) ? Calendar::getClosestBookableTimeFrameForToday( $bookableTimeframes ) : null;
		if ( $closestBookableTimeframe ) {
			$advanceBookingDays = intval( $closestBookableTimeframe->getFieldValue( 'timeframe-advance-booking-days' ) );
			$firstBookableDay   = $closestBookableTimeframe->getFirstBookableDay();

			// Only if passed daterange must not be kept
			if ( ! $keepDaterange ) {
				// start with current day or the start of the closest timeframe
				$startDateTimestamp = time();
				if ( $closestBookableTimeframe->getStartDate() > $startDateTimestamp ) {
					$startDateTimestamp = $closestBookableTimeframe->getStartDate();
				}

				if ( $startDateTimestamp > $startDate->getDateObject()->getTimestamp() ) {
					$startDate = new Day( date( 'Y-m-d', $startDateTimestamp ) );
				}
			}

			// Last day of month after next as default for calendar view
			// -> we just need to ensure, that pagination is possible
			$endDateTimestamp = strtotime( 'last day of +3 months', $startDate->getDateObject()->getTimestamp() );

			// get max advance booking days based on user defined max days in closest bookable timeframe
			$latestPossibleBookingDateTimestamp = $closestBookableTimeframe->getLatestPossibleBookingDateTimestamp();
			if ( $latestPossibleBookingDateTimestamp < $endDateTimestamp ) {
				$lastBookableDate = $endDateTimestamp = $latestPossibleBookingDateTimestamp;
			}

			// Only if passed daterange must not be kept
			if ( ! $keepDaterange ) {
				$endDate = new Day( date( 'Y-m-d', $endDateTimestamp ) );
			}
		}

		return new self( $startDate, $endDate, $advanceBookingDays, $lastBookableDate, $firstBookableDay );
	}

	public function getStartDate(): Day {
		return $this->startDate;
	}

	public function getEndDate(): Day {
		return $this->endDate;
	}

	public function getAdvanceBookingDays(): int {
		return $this->advanceBookingDays;
	}

	public function getLastBookableDate(): int {
		return $this->lastBookableDate;
	}

	public function getFirstBookableDay(): ?string {
		return $this->firstBookableDay;
	}

	/**
	 * Days outside of the booking window are locked without looking at their slots.
	 *
	 * @param Day $day
	 *
	 * @return bool true if the slots of the day have to be evaluated
	 */
	public function isInBookingWindow( Day $day ): bool {
		return $day->getDateObject()->getTimestamp() < $this->lastBookableDate;
	}

	/**
	 * Applies the window to the facts of a day.
	 *
	 * @param Day           $day
	 * @param DayFacts|null $facts facts of the day, only needed (and evaluated) inside the booking window
	 *
	 * @return array{day: array, types: string[]} the day array of the calendar json and the
	 *               lists of the calendar json the day belongs to (holidays, bookedDays, lockDays, partiallyBookedDays)
	 */
	public function mapDay( Day $day, ?DayFacts $facts ): array {
		$allLocked = true;

		if ( $facts && $this->isInBookingWindow( $day ) ) {
			$dayArray  = $facts->toDayArray( $day );
			$allLocked = $facts->has( DayFacts::ALL_LOCKED );

			// If there are no slots defined or the day is in the past, there's nothing bookable.
			if ( ! $facts->hasSlots() || strtotime( 'today midnight' ) > strtotime( $day->getDate() ) ) {
				$dayArray['locked']    = true;
				$dayArray['holiday']   = false;
				$dayArray['repair']    = false;
				$dayArray['bookedDay'] = false;
			} elseif ( $facts->has( DayFacts::FULL_DAY ) ) {
				$dayArray['fullDay'] = true;
			}

			// if day is out max advance booking days range, day is marked as locked to avoid booking
			if ( $day->getDate() > $this->endDate->getDate() ) {
				$dayArray['locked'] = true;
			}
		} else {
			// If day is out of booking day in advance range, it's handled like a not bookable day
			$dayArray = self::getLockedDayArray( $day );
		}

		$date = $day->getFormattedDate( 'Y-m-d' );

		// if day is before minimum bookable offset, day is locked
		if ( $date < $this->firstBookableDay ) {
			$dayArray['locked'] = true;
		}

		$types = [];
		if ( $dayArray['locked'] || $allLocked ) {
			if ( $allLocked ) {
				if ( $dayArray['holiday'] ) {
					$types[] = 'holidays';
					// if all slots are booked or we have a changed timeframe, where a booking was done before change
				} elseif ( $dayArray['bookedDay'] || $dayArray['partiallyBookedDay'] ) {
					$types[] = 'bookedDays';
				} else {
					$types[] = 'lockDays';
				}
			} else {
				$types[] = 'partiallyBookedDays';
			}
		}

		// if day is before minimum bookable offset, day is locked
		if ( $date < $this->firstBookableDay ) {
			$types[] = 'lockDays';
		}

		return [
			'day'   => $dayArray,
			'types' => $types,
		];
	}

	/**
	 * @param Day $day
	 *
	 * @return array day array of a day that can not be booked
	 */
	public static function getLockedDayArray( Day $day ): array {
		return [
			'date'               => $day->getFormattedDate( 'd.m.Y' ),
			'slots'              => [],
			'locked'             => true,
			'bookedDay'          => false,
			'partiallyBookedDay' => false,
			'holiday'            => false,
			'repair'             => false,
			'fullDay'            => false,
			'firstSlotBooked'    => false,
			'lastSlotBooked'     => false,
		];
	}
}
