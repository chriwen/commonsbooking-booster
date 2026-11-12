<?php

namespace CommonsBooking\Tests\Service;

use CommonsBooking\Model\Day;
use CommonsBooking\Model\Restriction;
use CommonsBooking\Service\Availability\DayFacts;
use CommonsBooking\Service\OccupancyPlan;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Admin\OccupancyPlan as OccupancyPlanView;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * @covers \CommonsBooking\Service\OccupancyPlan
 * @covers \CommonsBooking\View\Admin\OccupancyPlan
 */
class OccupancyPlanTest extends CustomPostTypeTest {

	private int $now;

	private string $today;

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::CURRENT_DATE ) );
		$this->now   = strtotime( self::CURRENT_DATE );
		$this->today = date( 'Y-m-d', $this->now );
	}

	private function day( int $offset ): string {
		return date( 'Y-m-d', strtotime( $offset . ' days', $this->now ) );
	}

	private function row( array $plan, int $itemId ): array {
		foreach ( $plan['rows'] as $row ) {
			if ( $row['item_id'] === $itemId ) {
				return $row;
			}
		}
		$this->fail( 'row for item ' . $itemId . ' missing' );
	}

	private function segments( array $segments, string $kind, ?int $postId = null ): array {
		return array_values(
			array_filter(
				$segments,
				fn( $s ) => $s['kind'] === $kind && ( $postId === null || $s['post_id'] === $postId )
			)
		);
	}

	private function holiday( $items, int $start, int $end ): int {
		return $this->createTimeframe( $this->locationId, $items, $start, $end, Timeframe::HOLIDAYS_ID );
	}

	public function testNormalizeRange() {
		$this->assertEquals( [ '2021-07-01', '2021-07-10' ], OccupancyPlan::normalizeRange( '2021-07-01', '2021-07-10' ) );
		$this->assertEquals( [ '2021-07-10', '2021-07-10' ], OccupancyPlan::normalizeRange( '2021-07-10', '2021-07-01' ), 'end before start' );
		[ $from, $to ] = OccupancyPlan::normalizeRange( '2021-01-01', '2030-01-01' );
		$this->assertEquals( '2021-01-01', $from );
		$this->assertEquals( OccupancyPlan::MAX_DAYS, ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS + 1 );
		[ $from ] = OccupancyPlan::normalizeRange( '2021-02-30', '' );
		$this->assertEquals( $this->today, $from, 'invalid date falls back to today' );
	}

	public function testBookableWeekdaysAndBookingWindow() {
		// Monday to Friday, 30 advance booking days, runs for 100 days
		$timeframe = $this->createTimeframe(
			$this->locationId,
			$this->itemId,
			strtotime( '-10 days', $this->now ),
			strtotime( '+100 days', $this->now ),
			Timeframe::BOOKABLE_ID,
			'on',
			'w',
			0,
			'8:00 AM',
			'12:00 PM',
			'publish',
			[ '1', '2', '3', '4', '5' ]
		);

		$plan = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 59 ) );
		$this->assertCount( 60, $plan['days'] );
		$this->assertEquals( $this->today, $plan['today'] );

		$row = $this->row( $plan, $this->itemId );
		$this->assertEquals( $this->day( 29 ), $row['window_end'] );

		$days = [];
		foreach ( $this->segments( $row['segments'], OccupancyPlan::KIND_BOOKABLE, $timeframe ) as $segment ) {
			for ( $i = $segment['from']; $i <= $segment['to']; $i++ ) {
				$days[] = $plan['days'][ $i ];
			}
		}
		$expected = array_values( array_filter( $plan['days'], fn( $d ) => (int) date( 'N', strtotime( $d ) ) <= 5 ) );
		$this->assertEquals( $expected, $days, 'only weekdays, also beyond the booking window' );
		$this->assertContains( $this->day( 50 ), $days );

		// 2021-07-01 is a Thursday: first run is Thu+Fri
		$first = $this->segments( $row['segments'], OccupancyPlan::KIND_BOOKABLE )[0];
		$this->assertEquals( [ 0, 1 ], [ $first['from'], $first['to'] ] );
		$this->assertEquals( OccupancyPlan::KIND_BOOKABLE, $plan['posts'][ $timeframe ]['kind'] );
	}

	public function testBookingsWithUserAndStatus() {
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$user        = get_userdata( self::USER_ID );
		$confirmed   = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+2 days', $this->now ),
			strtotime( '+4 days midnight', $this->now ) - 1
		);
		$unconfirmed = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+6 days', $this->now ),
			strtotime( '+7 days midnight', $this->now ) - 1,
			'12:00 AM',
			'23:59',
			'unconfirmed'
		);
		$canceled    = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+10 days', $this->now ),
			strtotime( '+11 days midnight', $this->now ) - 1,
			'12:00 AM',
			'23:59',
			'canceled'
		);

		$plan     = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 20 ) );
		$bookings = $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_BOOKING );
		$byId     = array_column( $bookings, null, 'post_id' );

		$this->assertArrayHasKey( $confirmed, $byId );
		$this->assertArrayHasKey( $unconfirmed, $byId );
		$this->assertArrayNotHasKey( $canceled, $byId, 'canceled only on request' );
		$this->assertEquals( [ 2, 3 ], [ $byId[ $confirmed ]['from'], $byId[ $confirmed ]['to'] ] );
		$this->assertEquals( $user->display_name, $byId[ $confirmed ]['label'] );
		$this->assertEquals( 'unconfirmed', $byId[ $unconfirmed ]['state'] );

		$details = array_column( $plan['posts'][ $confirmed ]['details'], 1, 0 );
		$this->assertContains( $user->user_email, $details );

		$plan = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 20 ), true );
		$this->assertCount( 1, $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_BOOKING, $canceled ) );
	}

	public function testBookingClippedAtRange() {
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$booking = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '-2 days', $this->now ),
			strtotime( '+2 days midnight', $this->now ) - 1
		);

		$plan    = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 6 ) );
		$segment = $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_BOOKING, $booking )[0];
		$this->assertEquals( 0, $segment['from'] );
		$this->assertEquals( 1, $segment['to'] );
		$this->assertTrue( $segment['clip_start'] );
		$this->assertFalse( $segment['clip_end'] );
	}

	public function testHolidayForAllItemsGoesToStationRow() {
		$otherItem = $this->createItem( 'Other item' );
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->createTimeframe( $this->locationId, $otherItem, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$both = $this->holiday( [ $this->itemId, $otherItem ], strtotime( '+3 days', $this->now ), strtotime( '+5 days', $this->now ) );
		$one  = $this->holiday( [ $this->itemId ], strtotime( '+8 days', $this->now ), strtotime( '+9 days', $this->now ) );

		$plan = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 13 ) );

		$this->assertCount( 2, $plan['rows'] );
		$station = $this->segments( $plan['station']['segments'], OccupancyPlan::KIND_HOLIDAY, $both );
		$this->assertCount( 1, $station );
		$this->assertEquals( [ 3, 5 ], [ $station[0]['from'], $station[0]['to'] ] );
		$this->assertEmpty( $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_HOLIDAY, $both ) );
		$this->assertEmpty( $this->segments( $this->row( $plan, $otherItem )['segments'], OccupancyPlan::KIND_HOLIDAY, $both ) );
		$this->assertCount( 1, $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_HOLIDAY, $one ) );
		$this->assertEmpty( $this->segments( $this->row( $plan, $otherItem )['segments'], OccupancyPlan::KIND_HOLIDAY, $one ) );
	}

	public function testRestrictionWithoutEnd() {
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$restriction = $this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, strtotime( '+2 days', $this->now ), null );

		$plan    = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 9 ) );
		$segment = $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_RESTRICTION, $restriction );
		$this->assertCount( 1, $segment );
		$this->assertEquals( [ 2, 9 ], [ $segment[0]['from'], $segment[0]['to'] ] );
		$this->assertTrue( $segment[0]['clip_end'] );
		$this->assertEquals( 'repair:active', $segment[0]['state'] );
	}

	/**
	 * Same rules as the booking calendar: a day has bookable or holiday slots exactly when the plan shows them.
	 */
	public function testMatchesCalendarDays() {
		$this->createTimeframe(
			$this->locationId,
			$this->itemId,
			strtotime( '-3 days', $this->now ),
			strtotime( '+12 days', $this->now ),
			Timeframe::BOOKABLE_ID,
			'on',
			'w',
			0,
			'8:00 AM',
			'12:00 PM',
			'publish',
			[ '1', '3', '5' ]
		);
		$holiday = $this->holiday( [ $this->itemId ], strtotime( '+4 days', $this->now ), strtotime( '+4 days', $this->now ) );

		$plan = OccupancyPlan::build( $this->locationId, $this->day( -5 ), $this->day( 15 ) );
		$row  = $this->row( $plan, $this->itemId );

		$covered = [];
		foreach ( $row['segments'] as $segment ) {
			for ( $i = $segment['from']; $i <= $segment['to']; $i++ ) {
				$covered[ $plan['days'][ $i ] ][ $segment['kind'] ] = true;
			}
		}

		foreach ( $plan['days'] as $date ) {
			$facts = DayFacts::fromGrid( ( new Day( $date, [ $this->locationId ], [ $this->itemId ] ) )->getGrid() );
			$this->assertSame( isset( $covered[ $date ] ), $facts->has( DayFacts::HAS_SLOTS ), 'slots on ' . $date );
			if ( isset( $covered[ $date ][ OccupancyPlan::KIND_HOLIDAY ] ) ) {
				$this->assertTrue( $facts->has( DayFacts::HOLIDAY ), 'holiday on ' . $date );
			}
		}
		$this->assertNotEmpty( $this->segments( $row['segments'], OccupancyPlan::KIND_HOLIDAY, $holiday ) );
	}

	/**
	 * With the booster index and with the original postmeta queries the plan is the same.
	 */
	public function testSameResultWithAndWithoutBooster() {
		$otherItem = $this->createItem( 'Other item' );
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->createTimeframe( $this->locationId, $otherItem, strtotime( '-10 days', $this->now ), null );
		$this->holiday( [ $this->itemId, $otherItem ], strtotime( '+3 days', $this->now ), strtotime( '+5 days', $this->now ) );
		$this->createHolidayTimeframeForAllItemsAndLocations();
		$this->createRestriction( Restriction::TYPE_HINT, 0, 0, strtotime( '+1 day', $this->now ), strtotime( '+2 days', $this->now ) );
		$this->createBooking( $this->locationId, $otherItem, strtotime( '+6 days', $this->now ), strtotime( '+8 days midnight', $this->now ) - 1 );

		$fast = OccupancyPlan::build( $this->locationId, $this->day( -3 ), $this->day( 40 ) );
		\CommonsBooking\Plugin::clearCache();
		$legacy = \CommonsBooking\Service\Booster\Booster::withoutBooster(
			fn() => OccupancyPlan::build( $this->locationId, $this->day( -3 ), $this->day( 40 ) )
		);

		$this->assertCount( 2, $fast['rows'] );
		$this->assertNotEmpty( $fast['station']['segments'] );
		$this->assertEquals( $legacy, $fast );
	}

	public function testAssignLanes() {
		$segments = [
			[
				'kind' => OccupancyPlan::KIND_BOOKABLE,
				'from' => 0,
				'to' => 9,
				'lane' => 0,
			],
			[
				'kind' => OccupancyPlan::KIND_BOOKING,
				'from' => 2,
				'to' => 2,
				'lane' => 0,
			],
			[
				'kind' => OccupancyPlan::KIND_BOOKING,
				'from' => 2,
				'to' => 3,
				'lane' => 0,
			],
			[
				'kind' => OccupancyPlan::KIND_BOOKING,
				'from' => 4,
				'to' => 5,
				'lane' => 0,
			],
			[
				'kind' => OccupancyPlan::KIND_HOLIDAY,
				'from' => 3,
				'to' => 4,
				'lane' => 0,
			],
		];
		$lanes    = OccupancyPlan::assignLanes( $segments );

		$this->assertEquals( 2, $lanes );
		$this->assertEquals( 0, $segments[0]['lane'], 'bookable stays in the background' );
		$this->assertEquals( 0, $segments[4]['lane'], 'blocks first' );
		$this->assertEquals( 0, $segments[1]['lane'], 'fits next to the block' );
		$this->assertEquals( 1, $segments[2]['lane'] );
		$this->assertEquals( 1, $segments[3]['lane'] );
	}

	public function testItemViewRowsAreLocationsAndCategoryFilter() {
		$otherLocation = $this->createLocation( 'Other location' );
		$otherItem     = $this->createItem( 'Other item' );
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->createTimeframe( $otherLocation, $this->itemId, strtotime( '+40 days', $this->now ), strtotime( '+60 days', $this->now ) );
		$this->createTimeframe( $this->locationId, $otherItem, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$booking = $this->createBooking( $otherLocation, $this->itemId, strtotime( '+42 days', $this->now ), strtotime( '+43 days midnight', $this->now ) - 1 );

		$plan = OccupancyPlan::buildForItem( $this->itemId, $this->today, $this->day( 59 ) );
		$this->assertEquals( OccupancyPlan::VIEW_ITEM, $plan['view'] );
		$rows = array_column( $plan['rows'], null, 'row_id' );
		$this->assertEqualsCanonicalizing( [ $this->locationId, $otherLocation ], array_keys( $rows ) );
		foreach ( $rows as $locationId => $row ) {
			$this->assertEquals( $this->itemId, $row['item_id'] );
			$this->assertEquals( $locationId, $row['location_id'] );
		}
		$this->assertCount( 1, $this->segments( $rows[ $otherLocation ]['segments'], OccupancyPlan::KIND_BOOKING, $booking ) );
		$this->assertEmpty( $this->segments( $rows[ $this->locationId ]['segments'], OccupancyPlan::KIND_BOOKING ) );

		$filtered = OccupancyPlan::buildForItem( $this->itemId, $this->today, $this->day( 59 ), false, [ $otherLocation ] );
		$this->assertEquals( [ $otherLocation ], array_column( $filtered['rows'], 'row_id' ) );

		$filtered = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 20 ), false, [ $otherItem ] );
		$this->assertEquals( [ $otherItem ], array_column( $filtered['rows'], 'row_id' ) );
		$this->assertEmpty( OccupancyPlan::build( $this->locationId, $this->today, $this->day( 20 ), false, [] )['rows'], 'empty category' );
	}

	public function testConflicts() {
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+10 days', $this->now ) );
		$this->holiday( [ $this->itemId ], strtotime( '+3 days', $this->now ), strtotime( '+3 days', $this->now ) );
		$this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, strtotime( '+6 days', $this->now ), strtotime( '+6 days 23:59', $this->now ) );
		$fine    = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+1 day', $this->now ), strtotime( '+2 days midnight', $this->now ) - 1 );
		$closed  = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+3 days', $this->now ), strtotime( '+5 days midnight', $this->now ) - 1 );
		$blocked = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+5 days', $this->now ), strtotime( '+8 days midnight', $this->now ) - 1 );
		$noTf    = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+12 days', $this->now ), strtotime( '+13 days midnight', $this->now ) - 1 );
		$clipped = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+14 days', $this->now ), strtotime( '+20 days midnight', $this->now ) - 1 );

		$plan = OccupancyPlan::build( $this->locationId, $this->today, $this->day( 14 ) );
		$row  = $this->row( $plan, $this->itemId );
		$byId = array_column( $this->segments( $row['segments'], OccupancyPlan::KIND_BOOKING ), null, 'post_id' );

		$this->assertFalse( $byId[ $fine ]['conflict'] );
		$this->assertTrue( $byId[ $closed ]['conflict'] );
		$this->assertTrue( $byId[ $blocked ]['conflict'] );
		$this->assertTrue( $byId[ $noTf ]['conflict'] );
		$this->assertTrue( $byId[ $clipped ]['conflict'], 'pickup day without timeframe, return day outside the range' );
		$this->assertEquals( 4, $row['conflicts'] );
		$this->assertArrayNotHasKey( 'conflicts', $plan['posts'][ $fine ] );
		$this->assertCount( 1, $plan['posts'][ $closed ]['conflicts'] );
		$this->assertStringContainsString( 'pickup day', $plan['posts'][ $closed ]['conflicts'][0] );
		$this->assertCount( 1, $plan['posts'][ $blocked ]['conflicts'] );
		$this->assertEquals( [ 'No bookable timeframe on the pickup and return day.' ], $plan['posts'][ $noTf ]['conflicts'], 'one-day booking' );

		$plan = OccupancyPlan::build( $this->locationId, $this->day( 15 ), $this->day( 30 ) );
		$byId = array_column( $this->segments( $this->row( $plan, $this->itemId )['segments'], OccupancyPlan::KIND_BOOKING ), null, 'post_id' );
		$this->assertTrue( $byId[ $clipped ]['clip_start'] );
		$this->assertTrue( $byId[ $clipped ]['conflict'], 'return day without timeframe' );
	}

	public function testExplainDay() {
		$otherItem = $this->createItem( 'Other item' );
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->createTimeframe( $this->locationId, $otherItem, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->createRestriction( Restriction::TYPE_HINT, $this->locationId, $this->itemId, strtotime( '+2 days', $this->now ), strtotime( '+2 days 23:59', $this->now ) );
		$booking = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+2 days', $this->now ), strtotime( '+3 days midnight', $this->now ) - 1 );

		$day = OccupancyPlan::explainDay( OccupancyPlan::VIEW_LOCATION, $this->locationId, $this->day( 2 ) );
		$this->assertEquals( $this->day( 2 ), $day['date'] );
		$this->assertCount( 2, $day['rows'] );

		$day = OccupancyPlan::explainDay( OccupancyPlan::VIEW_LOCATION, $this->locationId, $this->day( 2 ), $this->itemId );
		$this->assertCount( 1, $day['rows'] );
		$row = $day['rows'][0];
		$this->assertEquals( [ OccupancyPlan::KIND_BOOKABLE, OccupancyPlan::KIND_RESTRICTION, OccupancyPlan::KIND_BOOKING ], array_column( $row['reasons'], 'kind' ) );
		$this->assertEquals( get_userdata( self::USER_ID )->display_name, $row['reasons'][2]['title'] );
		$this->assertEquals( 'booked', $row['result']['key'] );
		$this->assertEmpty( $row['result']['notes'] );

		$free = OccupancyPlan::explainDay( OccupancyPlan::VIEW_LOCATION, $this->locationId, $this->day( 2 ), $otherItem )['rows'][0];
		$this->assertEquals( 'free', $free['result']['key'] );

		$past = OccupancyPlan::explainDay( OccupancyPlan::VIEW_ITEM, $this->itemId, $this->day( -2 ) )['rows'][0];
		$this->assertCount( 1, $past['result']['notes'], 'in the past' );

		$beyond = OccupancyPlan::explainDay( OccupancyPlan::VIEW_ITEM, $this->itemId, $this->day( 30 ) )['rows'][0];
		$this->assertNotEquals( 'none', $beyond['result']['key'] );
		$this->assertCount( 1, $beyond['result']['notes'], 'beyond the booking window' );
	}

	public function testCBManagerSeesOnlyOwnLocationsWithAllBookings() {
		$this->createCBManager();
		$managed   = $this->createLocation( 'Managed', 'publish', [ (string) $this->cbManagerUserID ] ); // stored as strings, like CMB2 does
		$otherItem = $this->createItem( 'Not managed item' );
		$this->createTimeframe( $managed, $otherItem, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$booking = $this->createBooking( $managed, $otherItem, strtotime( '+1 day', $this->now ), strtotime( '+2 days midnight', $this->now ) - 1 );

		wp_set_current_user( $this->cbManagerUserID );
		\CommonsBooking\Plugin::clearCache();

		$locations = OccupancyPlanView::allowedLocations();
		$this->assertArrayHasKey( $managed, $locations );
		$this->assertArrayNotHasKey( $this->locationId, $locations );
		$this->assertTrue( OccupancyPlanView::isAllowedLocation( $managed ) );
		$this->assertFalse( OccupancyPlanView::isAllowedLocation( $this->locationId ) );

		$plan = OccupancyPlan::build( $managed, $this->today, $this->day( 6 ) );
		$this->assertCount( 1, $this->segments( $this->row( $plan, $otherItem )['segments'], OccupancyPlan::KIND_BOOKING, $booking ), 'all bookings at a managed location' );
		$this->assertContains( get_userdata( self::USER_ID )->user_email, array_column( $plan['posts'][ $booking ]['details'], 1 ) );
	}
}
