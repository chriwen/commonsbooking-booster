<?php

namespace CommonsBooking\Tests\Service\Availability;

use CommonsBooking\Model\Restriction;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Calculator;
use CommonsBooking\Service\Availability\DayStatus;
use CommonsBooking\Service\Availability\Invalidator;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Reader;
use CommonsBooking\Service\Availability\Schema;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Write path of the precomputed availability: every change marks the affected pairs in the same request,
 * readers never use outdated rows.
 */
class AvailabilityTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	private int $otherItemId;

	private int $otherLocationId;

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );

		if ( ! Availability::isEnabled() ) {
			$this->markTestSkipped( 'Precomputed availability is disabled (CB_USE_INDEX=0 or CB_USE_AVAILABILITY=0).' );
		}

		$this->otherItemId     = $this->createItem( 'Other item' );
		$this->otherLocationId = $this->createLocation( 'Other location' );

		foreach ( [ [ $this->itemId, $this->locationId ], [ $this->otherItemId, $this->locationId ], [ $this->itemId, $this->otherLocationId ], [ $this->otherItemId, $this->otherLocationId ] ] as [ $itemId, $locationId ] ) {
			$this->createTimeframe( $locationId, $itemId, $this->day( -10 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		}
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
	 * Status of the days 0..13 as the map would show them, plus the live result for comparison.
	 */
	private function statuses( int $itemId, int $locationId ): array {
		$from   = $this->date( 0 );
		$to     = $this->date( 13 );
		$stored = ( new Reader() )->getDayStatuses( $itemId, $locationId, $from, $to, false, $from, $to )['days'];
		$live   = Reader::evaluateLive( $itemId, $locationId, $from, $to, false, $from, $to )['days'];
		$this->assertSame( $live, $stored, 'stored and live availability differ' );

		return $stored;
	}

	private function isUsable( int $itemId, int $locationId ): bool {
		Store::reset();

		return Store::isUsable( Store::get( $itemId, $locationId ) );
	}

	private function confirmedBooking( int $itemId, int $locationId, int $fromDay, int $toDay, string $status = 'confirmed' ): int {
		$bookingId = $this->createBooking( $locationId, $itemId, $this->day( $fromDay ), $this->day( $toDay, '23:59:59' ), '12:00 AM', '23:59', $status );
		Indexer::flush();

		return $bookingId;
	}

	public function testRowsAreComputed() {
		$this->assertSame( 4, Store::getStats()['rows'] );
		$this->assertSame( 4, Store::getStats()['usable'] );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );

		$statuses = $this->statuses( $this->itemId, $this->locationId );
		$this->assertSame( DayStatus::AVAILABLE, $statuses[ $this->date( 2 ) ] );
	}

	public function testBookingMarksPairAndIsRecomputed() {
		$this->confirmedBooking( $this->itemId, $this->locationId, 2, 3 );

		// marked synchronously, other pairs untouched
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertTrue( $this->isUsable( $this->otherItemId, $this->locationId ) );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->otherLocationId ) );
		$this->assertSame( [ [ $this->itemId, $this->locationId ] ], Invalidator::getQueue() );

		// recomputed at the end of the request
		$this->assertSame( 1, Invalidator::processQueue() );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );

		$statuses = $this->statuses( $this->itemId, $this->locationId );
		$this->assertSame( DayStatus::BOOKED, $statuses[ $this->date( 2 ) ] );
		$this->assertSame( DayStatus::BOOKED, $statuses[ $this->date( 3 ) ] );
		$this->assertSame( DayStatus::AVAILABLE, $statuses[ $this->date( 4 ) ] );
	}

	public function testReaderNeverUsesOutdatedRow() {
		// no recomputation at the end of the request: the reader computes the pair itself
		$this->confirmedBooking( $this->itemId, $this->locationId, 2, 2 );
		Invalidator::reset();

		$statuses = $this->statuses( $this->itemId, $this->locationId );
		$this->assertSame( DayStatus::BOOKED, $statuses[ $this->date( 2 ) ] );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );
	}

	public function testChangesInTheSameRequestAreSeen() {
		$this->statuses( $this->itemId, $this->locationId );
		// meta changes without explicit flush: the reader flushes the index first
		$this->createBooking( $this->locationId, $this->itemId, $this->day( 5 ), $this->day( 5, '23:59:59' ) );

		$statuses = $this->statuses( $this->itemId, $this->locationId );
		$this->assertSame( DayStatus::BOOKED, $statuses[ $this->date( 5 ) ] );
	}

	public function testCancelAndStatusChanges() {
		$bookingId = $this->confirmedBooking( $this->itemId, $this->locationId, 2, 2, 'unconfirmed' );
		// unconfirmed bookings are not part of the day grid
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );

		wp_update_post(
			[
				'ID'          => $bookingId,
				'post_status' => 'confirmed',
			]
		);
		Indexer::flush();
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		Invalidator::processQueue();
		$this->assertSame( DayStatus::BOOKED, $this->statuses( $this->itemId, $this->locationId )[ $this->date( 2 ) ] );

		$this->cancelBooking( new \CommonsBooking\Model\Booking( $bookingId ) );
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		Invalidator::processQueue();
		$this->assertSame( DayStatus::AVAILABLE, $this->statuses( $this->itemId, $this->locationId )[ $this->date( 2 ) ] );
	}

	public function testDeletingABooking() {
		$bookingId = $this->confirmedBooking( $this->itemId, $this->locationId, 2, 2 );
		Invalidator::processQueue();

		wp_delete_post( $bookingId, true );
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertSame( DayStatus::AVAILABLE, $this->statuses( $this->itemId, $this->locationId )[ $this->date( 2 ) ] );
	}

	public function testMovingABookingMarksOldAndNewPair() {
		$bookingId = $this->confirmedBooking( $this->itemId, $this->locationId, 2, 2 );
		Invalidator::processQueue();

		update_post_meta( $bookingId, 'item-id', $this->otherItemId );
		Indexer::flush();
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertFalse( $this->isUsable( $this->otherItemId, $this->locationId ) );
		$this->assertSame( DayStatus::AVAILABLE, $this->statuses( $this->itemId, $this->locationId )[ $this->date( 2 ) ] );
		$this->assertSame( DayStatus::BOOKED, $this->statuses( $this->otherItemId, $this->locationId )[ $this->date( 2 ) ] );
	}

	public function testHolidayForSeveralItemsAndLocations() {
		$this->createTimeframe( [ $this->locationId, $this->otherLocationId ], [ $this->itemId, $this->otherItemId ], $this->day( 4 ), $this->day( 4, '23:59:59' ), Timeframe::HOLIDAYS_ID, 'on', 'd' );
		Indexer::flush();

		$this->assertSame( 0, Store::getStats()['usable'] );
		Invalidator::processQueue();
		$this->assertSame( 4, Store::getStats()['usable'] );
		$this->assertSame( DayStatus::HOLIDAY, $this->statuses( $this->otherItemId, $this->otherLocationId )[ $this->date( 4 ) ] );
	}

	public function testRestrictionWildcards() {
		// all items at one location
		$this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, 0, $this->day( 6 ), $this->day( 6, '23:59' ) );
		Indexer::flush();
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertFalse( $this->isUsable( $this->otherItemId, $this->locationId ) );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->otherLocationId ) );
		$this->assertSame( DayStatus::BOOKED, $this->statuses( $this->otherItemId, $this->locationId )[ $this->date( 6 ) ] );

		// one item at all locations
		Invalidator::processQueue();
		$this->createRestriction( Restriction::TYPE_REPAIR, 0, $this->otherItemId, $this->day( 7 ), $this->day( 7, '23:59' ) );
		Indexer::flush();
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertFalse( $this->isUsable( $this->otherItemId, $this->locationId ) );
		$this->assertFalse( $this->isUsable( $this->otherItemId, $this->otherLocationId ) );
	}

	public function testChangesOutsideOfTheHorizonAreIgnored() {
		$this->confirmedBooking( $this->itemId, $this->locationId, 110, 111 );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );

		$this->confirmedBooking( $this->itemId, $this->locationId, -20, -15 );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );
	}

	public function testConcurrentChangeIsNotOverwritten() {
		$gen = Store::getGen( $this->itemId, $this->locationId );
		$old = Calculator::compute( $this->itemId, $this->locationId );

		// another request changes the pair while this one computes
		Store::invalidate( [ $this->itemId ], [ $this->locationId ] );

		$this->assertFalse( Store::write( $this->itemId, $this->locationId, $gen, $old['from'], $old['flags'], false ) );
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
	}

	public function testRoleLimitedPairIsStoredPerAccessProfile() {
		$timeframeId = $this->createTimeframe( $this->otherLocationId, $this->otherItemId, $this->day( 20 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		update_post_meta( $timeframeId, \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES, [ 'editor' ] );
		Indexer::flush();
		Invalidator::processQueue();

		Store::reset();
		$row = Store::get( $this->otherItemId, $this->otherLocationId );
		$this->assertSame( 1, (int) $row['has_role_limit'] );
		$this->assertNull( $row['days'] );
		$this->assertFalse( Store::isUsable( $row ) );

		$computations = Calculator::getComputeCount();
		$this->statuses( $this->otherItemId, $this->otherLocationId );
		$this->assertSame( $computations, Calculator::getComputeCount() );
	}

	public function testOldRowsAreNotUsed() {
		ClockMock::freeze( new \DateTime( '02.07.2021 11:00' ) );
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );

		// the cron job refreshes them, the horizon moves on
		$result = Maintenance::refresh();
		$this->assertSame( 4, $result['recomputed'] );
		Store::reset();
		$this->assertSame( '2021-07-01', Store::get( $this->itemId, $this->locationId )['days_from'] );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );
	}

	public function testRowsOfAnOldCalculationAreNotUsed() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::table() . ' SET calc_version = ' . ( Calculator::VERSION - 1 ) );
		$this->assertFalse( $this->isUsable( $this->itemId, $this->locationId ) );
		$this->assertSame( 4, Maintenance::refresh()['recomputed'] );
	}

	public function testRangeBeyondTheHorizonIsEvaluatedLive() {
		$from   = $this->date( 0 );
		$to     = $this->date( 110 );
		$stored = ( new Reader() )->getDayStatuses( $this->itemId, $this->locationId, $from, $to, true, $from, $to );
		$live   = Reader::evaluateLive( $this->itemId, $this->locationId, $from, $to, true, $from, $to );
		$this->assertSame( $live, $stored );
		$this->assertCount( 111, $stored['days'] );
	}

	public function testLargeChangesAreLeftToCron() {
		add_filter( 'commonsbooking_availability_sync_limit', fn() => 2 );
		$this->createTimeframe( [ $this->locationId, $this->otherLocationId ], [ $this->itemId, $this->otherItemId ], $this->day( 4 ), $this->day( 4, '23:59:59' ), Timeframe::HOLIDAYS_ID, 'on', 'd' );
		Indexer::flush();

		$this->assertCount( 2, Invalidator::getQueue() );
		$this->assertSame( 2, Invalidator::processQueue() );
		$this->assertNotFalse( wp_next_scheduled( Maintenance::CRON_HOOK ) );
		$this->assertSame( 2, Store::getStats()['usable'] );

		Maintenance::runCron();
		$this->assertSame( 4, Store::getStats()['usable'] );
	}

	public function testIndexRebuildMarksAllPairs() {
		Backfill::runAll();
		$this->assertSame( 0, Store::getStats()['usable'] );
		Maintenance::refresh();
		$this->assertSame( 4, Store::getStats()['usable'] );
	}

	public function testSyncPairs() {
		$newItemId = $this->createItem( 'New item' );
		$this->createTimeframe( $this->locationId, $newItemId, $this->day( -1 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		// an expired timeframe gets no row
		$expiredItemId = $this->createItem( 'Expired item' );
		$this->createTimeframe( $this->locationId, $expiredItemId, $this->day( -30 ), $this->day( -5, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		Indexer::flush();

		// a row of a pair without timeframe is removed
		Store::getGen( $expiredItemId, $this->otherLocationId );

		$result = Maintenance::syncPairs();
		$this->assertSame( 5, $result['pairs'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['deleted'] );
	}

	public function testVerifyRepairsWritesThatBypassedTheHooks() {
		global $wpdb;
		$bookingId = $this->confirmedBooking( $this->itemId, $this->locationId, 2, 2 );
		Invalidator::processQueue();

		// direct SQL, no hook fires
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'canceled' ], [ 'ID' => $bookingId ] );
		clean_post_cache( $bookingId );
		\CommonsBooking\Service\Index\Projector::project( $bookingId, false );
		$this->assertTrue( $this->isUsable( $this->itemId, $this->locationId ) );

		$result = Maintenance::verify( null, true );
		$this->assertSame( 4, $result['checked'] );
		$this->assertSame( [ $this->locationId . ':' . $this->itemId ], $result['mismatches'] );
		$this->assertSame( DayStatus::AVAILABLE, $this->statuses( $this->itemId, $this->locationId )[ $this->date( 2 ) ] );
		$this->assertSame( [], Maintenance::verify()['mismatches'] );
	}

	public function testDisabledWithoutIndex() {
		add_filter( 'commonsbooking_use_index', '__return_false' );
		$this->assertFalse( Availability::isEnabled() );
		remove_filter( 'commonsbooking_use_index', '__return_false' );
		$this->assertTrue( Availability::isEnabled() );
		$this->assertTrue( Backfill::isReady() );
	}
}
