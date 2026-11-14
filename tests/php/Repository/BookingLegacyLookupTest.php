<?php

namespace CommonsBooking\Tests\Repository;

use CommonsBooking\Repository\Booking;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;

/**
 * The postmeta lookup of the bookings of one item (used by the booking check, which never reads the index) returns
 * the same bookings as the former WP_Query meta queries.
 */
class BookingLegacyLookupTest extends CustomPostTypeTest {

	private int $otherItemId;

	private int $otherLocationId;

	/**
	 * @var int[]
	 */
	private array $days;

	protected function setUp(): void {
		parent::setUp();
		$this->otherItemId     = $this->createItem( 'Other item' );
		$this->otherLocationId = $this->createLocation( 'Other location' );

		$day        = strtotime( self::CURRENT_DATE );
		$this->days = array_map( fn( $offset ) => strtotime( "+$offset days", $day ), range( 0, 9 ) );
		$d          = $this->days;

		// full days, several statuses, neighbours, other item / location
		$this->createBooking( $this->locationId, $this->itemId, $d[1], $d[2] - 1 );
		$this->createBooking( $this->locationId, $this->itemId, $d[2], $d[4] - 1, '12:00 AM', '23:59', 'unconfirmed' );
		$this->createBooking( $this->locationId, $this->itemId, $d[4], $d[5] - 1, '12:00 AM', '23:59', 'canceled' );
		$this->createBooking( $this->locationId, $this->itemId, $d[5], $d[6] - 1, '12:00 AM', '23:59', 'draft' );
		$this->createBooking( $this->otherLocationId, $this->itemId, $d[6], $d[7] - 1 );
		$this->createBooking( $this->locationId, $this->otherItemId, $d[1], $d[3] - 1 );
		// hourly slots on day 7
		$this->createBooking( $this->locationId, $this->itemId, $d[7] + 10 * HOUR_IN_SECONDS, $d[7] + 12 * HOUR_IN_SECONDS - 1, '10:00', '12:00' );
		$this->createBooking( $this->locationId, $this->itemId, $d[7] + 12 * HOUR_IN_SECONDS, $d[7] + 13 * HOUR_IN_SECONDS - 1, '12:00', '13:00' );
		// a timeframe of the item is no booking
		$this->createBookableTimeFrameIncludingCurrentDay( $this->locationId, $this->itemId );
	}

	public function testTimerangeMatchesMetaQuery() {
		$d          = $this->days;
		$ranges     = [
			[ $d[0], $d[1] - 1 ],
			[ $d[1], $d[2] - 1 ],
			[ $d[1], $d[9] ],
			[ $d[2] - 1, $d[2] ],
			[ $d[3], $d[3] + 1 ],
			[ $d[7] + 11 * HOUR_IN_SECONDS, $d[7] + 12 * HOUR_IN_SECONDS ],
			[ $d[7] + 13 * HOUR_IN_SECONDS, $d[8] ],
			[ $d[0] - YEAR_IN_SECONDS, $d[9] + YEAR_IN_SECONDS ],
		];
		$statusSets = [ [ 'confirmed', 'unconfirmed' ], [ 'confirmed' ], [ 'canceled', 'confirmed', 'unconfirmed' ] ];
		$pairs      = [
			[ $this->locationId, $this->itemId ],
			[ null, $this->itemId ],
			[ $this->otherLocationId, $this->itemId ],
			[ $this->locationId, $this->otherItemId ],
			[ (string) $this->locationId, (string) $this->itemId ],
		];

		$compared = 0;
		foreach ( $ranges as [ $start, $end ] ) {
			foreach ( $statusSets as $statuses ) {
				foreach ( $pairs as [ $locationId, $itemId ] ) {
					$expected = $this->ids( Booking::getByTimerangeWithMetaQuery( $start, $end, $locationId, $itemId, [], $statuses ) );
					$actual   = $this->ids( Booster::withoutBooster( fn() => Booking::getByTimerange( $start, $end, $locationId, $itemId, [], $statuses ) ) );
					$this->assertEqualsCanonicalizing( $expected, $actual, "range $start-$end, location $locationId, item $itemId, " . implode( ',', $statuses ) );
					$compared += count( $expected );
				}
			}
		}
		$this->assertGreaterThan( 20, $compared, 'the cases find bookings at all' );
	}

	public function testExactSlotMatchesMetaQuery() {
		$d     = $this->days;
		$slots = [
			[ $d[1], $d[2] - 1 ],
			[ $d[2], $d[4] - 1 ],
			[ $d[4], $d[5] - 1 ],
			[ $d[5], $d[6] - 1 ],
			[ $d[6], $d[7] - 1 ],
			[ $d[7] + 10 * HOUR_IN_SECONDS, $d[7] + 12 * HOUR_IN_SECONDS - 1 ],
			[ $d[1], $d[2] ],
		];
		$found = 0;
		foreach ( $slots as [ $start, $end ] ) {
			foreach ( [ $this->locationId, $this->otherLocationId ] as $locationId ) {
				$expected = Booking::getByDateWithMetaQuery( $start, $end, $locationId, $this->itemId );
				$actual   = Booster::withoutBooster( fn() => Booking::getByDate( $start, $end, $locationId, $this->itemId ) );
				$this->assertSame( $expected->ID ?? null, $actual->ID ?? null, "slot $start-$end, location $locationId" );
				$found += $expected ? 1 : 0;
			}
		}
		$this->assertSame( 4, $found );
	}

	/**
	 * @param \CommonsBooking\Model\Booking[] $bookings
	 *
	 * @return int[]
	 */
	private function ids( array $bookings ): array {
		return array_values( array_map( fn( $booking ) => $booking->ID, $bookings ) );
	}
}
