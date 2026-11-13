<?php

namespace CommonsBooking\Service\Availability;

/**
 * Condenses a day of the calendar json into one status.
 * Shared by the map ({@see \CommonsBooking\Map\MapItemAvailable}) and the items table
 * ({@see \CommonsBooking\View\Calendar::renderTable()}), so both show days the same way.
 */
final class DayStatus {

	/** No slot (no timeframe or outside of the booking window). */
	public const NO_SLOTS = 'no-slots';

	public const HOLIDAY = 'holiday';

	/** First and last slot are not bookable. */
	public const BOOKED = 'booked';

	/** Partially booked, the day is free at its start. */
	public const PARTIALLY_BOOKED_START = 'partially-booked-start';

	/** Partially booked, the day is free at its end (or in between). */
	public const PARTIALLY_BOOKED_END = 'partially-booked-end';

	public const LOCKED = 'locked';

	public const AVAILABLE = 'available';

	/**
	 * @param array     $day day array of the calendar json
	 * @param bool|null $hasSlots overrides the slot check, for day arrays without slots (precomputed)
	 *
	 * @return string one of the class constants
	 */
	public static function fromDayArray( array $day, ?bool $hasSlots = null ): string {
		if ( $hasSlots === null ) {
			$hasSlots = count( $day['slots'] ) > 0;
		}

		if ( ! $hasSlots ) {
			return self::NO_SLOTS;
		}
		if ( $day['holiday'] ) {
			return self::HOLIDAY;
		}
		if ( $day['locked'] && $day['firstSlotBooked'] && $day['lastSlotBooked'] ) {
			return self::BOOKED;
		}
		if ( $day['locked'] && $day['partiallyBookedDay'] ) {
			return ( ! $day['firstSlotBooked'] && $day['lastSlotBooked'] ) ? self::PARTIALLY_BOOKED_START : self::PARTIALLY_BOOKED_END;
		}
		if ( $day['locked'] ) {
			return self::LOCKED;
		}

		return self::AVAILABLE;
	}
}
