<?php

namespace CommonsBooking\Tests\Service\Availability;

use CommonsBooking\Model\Calendar;
use CommonsBooking\Model\Day;
use CommonsBooking\Model\Restriction;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Calculator;
use CommonsBooking\Service\Availability\Invalidator;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Schema;
use CommonsBooking\Service\Availability\SlotReader;
use CommonsBooking\Service\Availability\Slots;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Availability slots for API and GBFS from the precomputed rows: same result as the live calculation.
 */
class SlotsTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	private int $hourlyItemId;

	private int $offsetItemId;

	private int $otherLocationId;

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );

		if ( ! Availability::isEnabled() ) {
			$this->markTestSkipped( 'Precomputed availability is disabled (CB_USE_INDEX=0 or CB_USE_AVAILABILITY=0).' );
		}

		$this->hourlyItemId    = $this->createItem( 'Hourly item' );
		$this->offsetItemId    = $this->createItem( 'Offset item' );
		$this->otherLocationId = $this->createLocation( 'Other location' );

		// full day slots, daily repetition
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -10 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		// hourly grid 8:00 - 12:00
		$this->createTimeframe( $this->locationId, $this->hourlyItemId, $this->day( -10 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, '', 'd', 1, '8:00 AM', '12:00 PM' );
		// booking start day offset of 2 days, only on weekdays
		$this->createTimeframe(
			$this->otherLocationId,
			$this->offsetItemId,
			$this->day( -10 ),
			$this->day( 120, '23:59:59' ),
			Timeframe::BOOKABLE_ID,
			'on',
			'w',
			0,
			'8:00 AM',
			'12:00 PM',
			'publish',
			[ '1', '2', '3', '4', '5' ],
			'',
			self::USER_ID,
			3,
			30,
			2
		);

		// full day booking, hourly booking, repair
		$this->createBooking( $this->locationId, $this->itemId, $this->day( 2 ), $this->day( 3, '23:59:59' ) );
		$this->createBooking( $this->locationId, $this->hourlyItemId, $this->day( 1, '09:00' ), $this->day( 1, '09:59:59' ), '9:00 AM', '10:00 AM', 'confirmed', self::USER_ID, 'd', 3, 'Booking', 1 );
		$this->createRestriction( Restriction::TYPE_REPAIR, $this->otherLocationId, $this->offsetItemId, $this->day( 5 ), $this->day( 6, '23:59:59' ) );

		Indexer::flush();
		Maintenance::syncPairs();
		Maintenance::refresh( null, 0 );
		Invalidator::reset();
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( date( 'Y-m-d', strtotime( "$offset days" ) ) . ' ' . $time );
	}

	private function date( int $offset ): string {
		return date( 'Y-m-d', strtotime( "$offset days" ) );
	}

	/**
	 * Asserts that the calendar is answered from the stored rows with the same result as the live calculation.
	 *
	 * Compared are the days up to the end date. After it (through sunday) the live calendar only knows the
	 * timeframes overlapping the requested range, see {@see SlotReader} and {@see self::testKnownLiveDeviations()}.
	 * Without location filter the stored answer is the union of the item's pairs, so it is compared with the live
	 * calendars of the pairs.
	 *
	 * @return array the slots
	 */
	private function assertSameAsLive( string $from, string $to, array $locations, array $items, bool $ignoreStartDayOffset = false ): array {
		$stored = SlotReader::getAvailabilitySlots( $from, $to, $locations, $items, $ignoreStartDayOffset, false );
		$this->assertNotNull( $stored, 'answered from the stored rows' );

		$calendar = new Calendar( new Day( $from ), new Day( $to ), $locations, $items );
		$calendar->setIgnoreStartDayOffset( $ignoreStartDayOffset );
		$this->assertEquals( $stored, $calendar->getAvailabilitySlots(), 'calendar delegates to the stored rows' );

		$live = [];
		foreach ( $locations ?: $this->getLocationsOf( $items[0] ) as $locationId ) {
			$pairCalendar = new Calendar( new Day( $from ), new Day( $to ), [ $locationId ], $items );
			$pairCalendar->setIgnoreStartDayOffset( $ignoreStartDayOffset );
			$live = array_merge( $live, $pairCalendar->getAvailabilitySlotsLive() );
		}
		usort( $live, fn( $a, $b ) => $a->start <=> $b->start );

		$untilEnd = fn( array $slots ) => array_values( array_filter( $slots, fn( $slot ) => substr( $slot->start, 0, 10 ) <= $to ) );
		$this->assertEquals( $untilEnd( $live ), $untilEnd( $stored ) );

		return $stored;
	}

	private function getLocationsOf( int $itemId ): array {
		return $itemId === $this->offsetItemId ? [ $this->otherLocationId ] : [ $this->locationId ];
	}

	private function days( array $slots ): array {
		return array_map( fn( $slot ) => substr( $slot->start, 0, 10 ), $slots );
	}

	public function testApiCalendarsMatchLive() {
		foreach ( [ $this->itemId, $this->hourlyItemId, $this->offsetItemId ] as $itemId ) {
			$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 14 ), [], [ $itemId ] );
			$this->assertNotEmpty( $slots );
		}
	}

	public function testGbfsCalendarsMatchLive() {
		foreach ( [ [ $this->itemId, $this->locationId ], [ $this->hourlyItemId, $this->locationId ], [ $this->offsetItemId, $this->otherLocationId ] ] as [ $itemId, $locationId ] ) {
			// VehicleStatus::getAvailableUntil()
			$this->assertSameAsLive( $this->date( -1 ), $this->date( 3 ), [ $locationId ], [ $itemId ], true );
			// Item::isCurrentlyFreeAtLocation() without ignoring restrictions (StationStatus)
			$this->assertSameAsLive( $this->date( -1 ), $this->date( 1 ), [ $locationId ], [ $itemId ] );
			$this->assertSameAsLive( $this->date( -1 ), $this->date( 1 ), [ $locationId ], [ $itemId ], true );
		}
	}

	public function testSlotContents() {
		$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 6 ), [], [ $this->hourlyItemId ] );
		$day1  = array_values( array_filter( $slots, fn( $slot ) => substr( $slot->start, 0, 10 ) === $this->date( 1 ) ) );
		// 8-9, 10-11, 11-12 bookable, 9-10 booked
		$this->assertSame( [ '08', '10', '11' ], array_map( fn( $slot ) => substr( $slot->start, 11, 2 ), $day1 ) );
		$this->assertSame( (string) $this->locationId, $day1[0]->locationId );
		$this->assertSame( (string) $this->hourlyItemId, $day1[0]->itemId );

		// booked days 2 and 3 of the full day item are missing
		$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 6 ), [], [ $this->itemId ] );
		$days  = array_map( fn( $slot ) => substr( $slot->start, 0, 10 ), $slots );
		$this->assertNotContains( $this->date( 2 ), $days );
		$this->assertNotContains( $this->date( 3 ), $days );
		$this->assertContains( $this->date( 4 ), $days );

		// start day offset of 2 days hides today and tomorrow, unless ignored; repair hides days 5 and 6
		$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 9 ), [], [ $this->offsetItemId ] );
		$days  = array_map( fn( $slot ) => substr( $slot->start, 0, 10 ), $slots );
		$this->assertNotContains( $this->date( 0 ), $days );
		$this->assertNotContains( $this->date( 1 ), $days );
		$this->assertNotContains( $this->date( 5 ), $days );
		$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 9 ), [], [ $this->offsetItemId ], true );
		$days  = array_map( fn( $slot ) => substr( $slot->start, 0, 10 ), $slots );
		$this->assertContains( $this->date( 0 ), $days ); // thursday
	}

	public function testFirstBookableDayIsAppliedOnRead() {
		// the stored row stays the same, tomorrow the offset hides other days
		ClockMock::freeze( new \DateTime( '02.07.2021 10:30' ) );
		$this->assertSameAsLive( $this->date( 0 ), $this->date( 9 ), [], [ $this->offsetItemId ] );
	}

	public function testChangesAreVisibleImmediately() {
		$this->createBooking( $this->locationId, $this->itemId, $this->day( 5 ), $this->day( 5, '23:59:59' ) );
		Indexer::flush();
		Invalidator::reset(); // no recomputation at the end of the request: the reader computes the pair itself

		$slots = $this->assertSameAsLive( $this->date( 0 ), $this->date( 9 ), [], [ $this->itemId ] );
		$this->assertNotContains( $this->date( 5 ), array_map( fn( $slot ) => substr( $slot->start, 0, 10 ), $slots ) );
	}

	/**
	 * Two cases where the live calculation is wrong and the stored rows are right (documented in SlotReader).
	 */
	public function testKnownLiveDeviations() {
		// 1. calendar without location filter: Restriction::get() does not find restrictions with item and location,
		// the live calendar shows the repair days 5 and 6 as available
		$calendar = new Calendar( new Day( $this->date( 0 ) ), new Day( $this->date( 9 ) ), [], [ $this->offsetItemId ] );
		$this->assertContains( $this->date( 5 ), $this->days( $calendar->getAvailabilitySlotsLive() ) );
		$this->assertNotContains( $this->date( 5 ), $this->days( $calendar->getAvailabilitySlots() ) );

		// 2. days after the end date: the booking of days 2 and 3 starts after the end date (day 1) and is missing live
		$calendar = new Calendar( new Day( $this->date( -1 ) ), new Day( $this->date( 1 ) ), [ $this->locationId ], [ $this->itemId ] );
		$this->assertContains( $this->date( 2 ), $this->days( $calendar->getAvailabilitySlotsLive() ) );
		$stored = $this->days( $calendar->getAvailabilitySlots() );
		$this->assertNotContains( $this->date( 2 ), $stored );
		$this->assertNotContains( $this->date( 3 ), $stored );
	}

	public function testLiveFallbacks() {
		// restrictions ignored (VehicleStatus)
		$this->assertNull( SlotReader::getAvailabilitySlots( $this->date( -1 ), $this->date( 1 ), [ $this->locationId ], [ $this->itemId ], true, true ) );
		// no item filter
		$this->assertNull( SlotReader::getAvailabilitySlots( $this->date( 0 ), $this->date( 7 ), [], [], false, false ) );
		// beyond the horizon
		$this->assertNull( SlotReader::getAvailabilitySlots( $this->date( 0 ), $this->date( 120 ), [], [ $this->itemId ], false, false ) );

		// pairs with role limited timeframes
		$limitedItem = $this->createItem( 'Limited item' );
		$timeframeId = $this->createTimeframe( $this->locationId, $limitedItem, $this->day( -10 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		update_post_meta( $timeframeId, 'allowed_user_roles', [ 'administrator' ] );
		wp_update_post( [ 'ID' => $timeframeId ] );
		Indexer::flush();
		// stored per access profile (AccessProfileTest), live if switched off
		$this->assertNotNull( SlotReader::getAvailabilitySlots( $this->date( 0 ), $this->date( 7 ), [], [ $limitedItem ], false, false ) );
		add_filter( 'commonsbooking_availability_access_profiles', '__return_false' );
		$this->assertNull( SlotReader::getAvailabilitySlots( $this->date( 0 ), $this->date( 7 ), [], [ $limitedItem ], false, false ) );

		// the calendar still answers, live
		$calendar = new Calendar( new Day( $this->date( 0 ) ), new Day( $this->date( 7 ) ), [], [ $limitedItem ] );
		$this->assertEquals( $calendar->getAvailabilitySlotsLive(), $calendar->getAvailabilitySlots() );
		remove_filter( 'commonsbooking_availability_access_profiles', '__return_false' );
	}

	public function testPackRoundTrip() {
		$timeframe = get_post( $this->createTimeframe( $this->locationId, $this->itemId, $this->day( 200 ), $this->day( 201 ) ) );
		$base      = strtotime( '2021-07-01' );
		$packed    = Slots::pack(
			[
				[
					'date'  => '2021-07-01',
					'slots' => [
						[
							'start'     => $base + 8 * 3600,
							'end'       => $base + 9 * 3600 - 1,
							'timeframe' => $timeframe,
						],
						[
							'start'     => $base + 9 * 3600,
							'end'       => $base + 10 * 3600 - 1,
							'timeframe' => $timeframe,
						],
					],
				],
				[
					'date'  => '2021-07-02',
					'slots' => [],
				],
			]
		);
		$unpacked  = Slots::unpack( $packed );
		$this->assertSame( [ [ $timeframe->ID, $this->locationId, $this->itemId ] ], $unpacked['timeframes'] );
		$this->assertSame( [ [ [ 8 * 3600, 9 * 3600 - 1, 0 ], [ 9 * 3600, 10 * 3600 - 1, 0 ] ], [] ], $unpacked['days'] );
	}

	public function testVerifyDetectsChangedSlots() {
		$this->assertSame( [], Maintenance::verify()['mismatches'] );

		// remove the slots of all days from one row, the flags stay correct
		global $wpdb;
		$row      = Store::loadWithSlots( [ [ $this->itemId, $this->locationId ] ] )[ $this->locationId . ':' . $this->itemId ];
		$unpacked = Slots::unpack( $row['slots'] );
		$empty    = pack( 'v', 0 ) . str_repeat( pack( 'C', 0 ), count( $unpacked['days'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table() . ' SET slots = UNHEX(%s) WHERE item_id = %d AND location_id = %d', bin2hex( $empty ), $this->itemId, $this->locationId ) ); // phpcs:ignore

		$this->assertSame( [ $this->locationId . ':' . $this->itemId ], Maintenance::verify( null, true )['mismatches'] );
		$this->assertSame( [], Maintenance::verify()['mismatches'] );
		$this->assertSame( Calculator::VERSION, (int) Store::loadWithSlots( [ [ $this->itemId, $this->locationId ] ] )[ $this->locationId . ':' . $this->itemId ]['calc_version'] );
	}
}
