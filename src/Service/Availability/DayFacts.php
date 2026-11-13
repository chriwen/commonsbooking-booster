<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Day;
use CommonsBooking\Model\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use WP_Post;

/**
 * State of a single day for one item/location pair, derived from the slots of the day grid.
 *
 * Only depends on posts (timeframes, bookings, restrictions), never on the current date.
 * The rules that depend on the current date (booking window, past days, start day offset)
 * are applied afterwards by {@see CalendarWindow::mapDay()}.
 *
 * Can be packed into an integer ({@see self::getFlags()}) to be stored, but then the slots themselves are lost.
 *
 * @see design/availability-precompute.md
 */
final class DayFacts {

	/** At least one slot has a timeframe. */
	public const HAS_SLOTS = 1;

	/** At least one slot is locked (holiday, repair, booking) or not bookable for the current user. */
	public const SLOT_LOCKED = 2;

	/** No slot can be selected. */
	public const ALL_LOCKED = 4;

	/** All slots are holidays (or there are no slots). */
	public const HOLIDAY = 8;

	/** Legacy flag "repair", see {@see self::fromGrid()}. */
	public const REPAIR = 16;

	/** All slots are bookings or repairs (or there are no slots). */
	public const BOOKED_DAY = 32;

	/** At least one slot is a booking, holiday or repair. */
	public const PARTIALLY_BOOKED = 64;

	/** The first slot is not bookable. Only meaningful with {@see self::HAS_SLOTS}. */
	public const FIRST_SLOT_BOOKED = 128;

	/** The last slot is not bookable. Only meaningful with {@see self::HAS_SLOTS}. */
	public const LAST_SLOT_BOOKED = 256;

	/** There is exactly one slot and its timeframe is a full day timeframe. */
	public const FULL_DAY = 512;

	private int $flags;

	/**
	 * @var array untyped slot structure from {@see Day::getGrid()}, empty if created from flags
	 */
	private array $slots;

	/**
	 * Timeframe of the first slot if it is bookable.
	 */
	private ?WP_Post $firstBookableTimeframe;

	private function __construct( int $flags, array $slots = [], ?WP_Post $firstBookableTimeframe = null ) {
		$this->flags                  = $flags;
		$this->slots                  = $slots;
		$this->firstBookableTimeframe = $firstBookableTimeframe;
	}

	/**
	 * Restores facts from stored flags. Slots are not available afterwards.
	 *
	 * @param int $flags
	 *
	 * @return self
	 */
	public static function fromFlags( int $flags ): self {
		return new self( $flags );
	}

	/**
	 * Evaluates the slots of a day grid.
	 *
	 * Same rules as the former \CommonsBooking\View\Calendar::processSlot(), including its quirks:
	 * - "repair" is reset by every slot, because the meta value (string) is compared strictly with the type id (int).
	 * - The current user's role is checked per slot (only relevant for timeframes with allowed user roles).
	 *
	 * @param array $grid slots from {@see Day::getGrid()}
	 *
	 * @return self
	 */
	public static function fromGrid( array $grid ): self {
		$slots                  = [];
		$firstBookableTimeframe = null;
		$firstSlotBooked        = null;
		$lastSlotBooked         = null;
		$holiday                = true;
		$repair                 = true;
		$bookedDay              = true;
		$partiallyBooked        = false;
		$slotLocked             = false;
		$allLocked              = true;

		foreach ( $grid as $slot ) {
			if ( empty( $slot['timeframe'] ) || ! $slot['timeframe'] instanceof WP_Post ) {
				continue;
			}

			$timeFrameType = get_post_meta( $slot['timeframe']->ID, 'type', true );
			if ( ! $timeFrameType ) {
				$timeFrameType = get_post_meta( $slot['timeframe']->ID, Restriction::META_TYPE, true );
			}

			$isUserAllowedToBook = commonsbooking_isCurrentUserAllowedToBook( $slot['timeframe']->ID );

			if ( $firstSlotBooked === null ) {
				if ( $timeFrameType == Timeframe::BOOKABLE_ID ) {
					$firstSlotBooked        = false;
					$firstBookableTimeframe = $slot['timeframe'];
				} else {
					$firstSlotBooked = true;
				}
			}

			$lastSlotBooked = $timeFrameType != Timeframe::BOOKABLE_ID;

			$slots[] = $slot;

			if ( ! in_array( $timeFrameType, [ Timeframe::HOLIDAYS_ID, Timeframe::OFF_HOLIDAYS_ID ] ) ) {
				$holiday = false;
			}

			// strict comparison kept from the original implementation, see method comment
			if ( $timeFrameType !== Timeframe::REPAIR_ID ) {
				$repair = false;
			}

			if ( ! in_array( $timeFrameType, [ Timeframe::BOOKING_ID, Timeframe::REPAIR_ID ] ) ) {
				$bookedDay = false;
			}

			if ( in_array( $timeFrameType, [ Timeframe::BOOKING_ID, Timeframe::HOLIDAYS_ID, Timeframe::REPAIR_ID, Restriction::TYPE_REPAIR ] ) ) {
				$partiallyBooked = true;
			}

			// locked is set by Day::mapTimeFrames() / Day::mapRestrictions()
			if ( ( $slot['timeframe']->locked ?? false ) || ! $isUserAllowedToBook ) {
				$slotLocked = true;
			} else {
				$allLocked = false;
			}
		}

		$fullDay = count( $slots ) === 1 && get_post_meta( $slots[0]['timeframe']->ID, 'full-day', true ) == 'on';

		$flags = ( count( $slots ) ? self::HAS_SLOTS : 0 )
			| ( $slotLocked ? self::SLOT_LOCKED : 0 )
			| ( $allLocked ? self::ALL_LOCKED : 0 )
			| ( $holiday ? self::HOLIDAY : 0 )
			| ( $repair ? self::REPAIR : 0 )
			| ( $bookedDay ? self::BOOKED_DAY : 0 )
			| ( $partiallyBooked ? self::PARTIALLY_BOOKED : 0 )
			| ( $firstSlotBooked ? self::FIRST_SLOT_BOOKED : 0 )
			| ( $lastSlotBooked ? self::LAST_SLOT_BOOKED : 0 )
			| ( $fullDay ? self::FULL_DAY : 0 );

		return new self( $flags, $slots, $firstBookableTimeframe );
	}

	/**
	 * @return int all flags, e.g. to store them
	 */
	public function getFlags(): int {
		return $this->flags;
	}

	/**
	 * @param int $flag one of the class constants
	 *
	 * @return bool
	 */
	public function has( int $flag ): bool {
		return ( $this->flags & $flag ) === $flag;
	}

	/**
	 * @return bool
	 */
	public function hasSlots(): bool {
		return $this->has( self::HAS_SLOTS );
	}

	/**
	 * @return array slots with timeframe (empty if restored from flags)
	 */
	public function getSlots(): array {
		return $this->slots;
	}

	/**
	 * @return WP_Post|null timeframe of the first slot, if that slot is bookable (null if restored from flags)
	 */
	public function getFirstBookableTimeframe(): ?WP_Post {
		return $this->firstBookableTimeframe;
	}

	/**
	 * Day array as used in the calendar json, before the booking window is applied.
	 *
	 * @param Day $day
	 *
	 * @return array
	 */
	public function toDayArray( Day $day ): array {
		$hasSlots = $this->hasSlots();

		return [
			'date'               => $day->getFormattedDate( 'd.m.Y' ),
			'slots'              => $this->slots,
			'locked'             => $this->has( self::SLOT_LOCKED ),
			'bookedDay'          => $this->has( self::BOOKED_DAY ),
			'partiallyBookedDay' => $this->has( self::PARTIALLY_BOOKED ),
			'holiday'            => $this->has( self::HOLIDAY ),
			'repair'             => $this->has( self::REPAIR ),
			'fullDay'            => false,
			'firstSlotBooked'    => $hasSlots ? $this->has( self::FIRST_SLOT_BOOKED ) : null,
			'lastSlotBooked'     => $hasSlots ? $this->has( self::LAST_SLOT_BOOKED ) : null,
		];
	}
}
