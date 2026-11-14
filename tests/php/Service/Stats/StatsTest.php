<?php

namespace CommonsBooking\Tests\Service\Stats;

use CommonsBooking\Model\Restriction;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Stats\Bookings;
use CommonsBooking\Service\Stats\Build;
use CommonsBooking\Service\Stats\Calculator;
use CommonsBooking\Service\Stats\Queue;
use CommonsBooking\Service\Stats\Schema;
use CommonsBooking\Service\Stats\Stats;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Aggregation of the analytics dashboard: booking table, daily values, hooks and queue.
 */
class StatsTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );
		Schema::install();
		$this->truncate();
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -30 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		// with the booster on the timeframe index is written at the end of the request
		Indexer::flush();
	}

	protected function tearDown(): void {
		Stats::purge();
		parent::tearDown();
	}

	private function truncate(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( date( 'Y-m-d', strtotime( "$offset days" ) ) . ' ' . $time );
	}

	private function date( int $offset ): string {
		return date( 'Y-m-d', strtotime( "$offset days" ) );
	}

	private function daily( ?int $itemId = null, string $unit = 'day' ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::daily() . ' WHERE item_id = %d AND unit = %s ORDER BY day', $itemId ?? $this->itemId, $unit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_column( $rows, null, 'day' );
	}

	/**
	 * Full day booking like the plugin creates it for a full day timeframe (the test helper leaves the flag out).
	 */
	private function booking( $locationId, $itemId, $start, $end, $startTime = '12:00 AM', $endTime = '23:59', $status = 'confirmed' ): int {
		$id = $this->createBooking( $locationId, $itemId, $start, $end, $startTime, $endTime, $status );
		update_post_meta( $id, 'full-day', 'on' );

		return $id;
	}

	public function testSchemaIsInstalled() {
		$this->assertTrue( Schema::isInstalled() );
		$this->assertTrue( Schema::tablesExist() );
	}

	public function testBookingsAreProjected() {
		$confirmed = $this->booking( $this->locationId, $this->itemId, $this->day( -5 ), $this->day( -3, '23:59:59' ) );
		$canceled  = $this->booking( $this->locationId, $this->itemId, $this->day( -10 ), $this->day( -10, '23:59:59' ), '12:00 AM', '23:59', 'canceled' );
		$cancelTs  = $this->day( -14, '09:00' );
		update_post_meta( $canceled, 'cancellation_time', $cancelTs );
		$unconfirmed = $this->booking( $this->locationId, $this->itemId, $this->day( -2 ), $this->day( -2, '23:59:59' ), '12:00 AM', '23:59', 'unconfirmed' );

		$this->assertSame( 2, Bookings::rebuild() );

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . Schema::booking() . ' WHERE booking_id = ' . $confirmed, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 'day', $row['unit'] );
		$this->assertSame( '3', $row['slots'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNull( $row['canceled_ts'] );
		$this->assertSame( (string) self::USER_ID, $row['user_id'] );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Schema::booking() . ' WHERE booking_id = ' . $canceled, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 'canceled', $row['status'] );
		$this->assertSame( (string) $cancelTs, $row['canceled_ts'] );
		$this->assertNull( $wpdb->get_var( 'SELECT booking_id FROM ' . Schema::booking() . ' WHERE booking_id = ' . $unconfirmed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function testDailyValues() {
		$this->booking( $this->locationId, $this->itemId, $this->day( -5 ), $this->day( -3, '23:59:59' ) );
		$this->booking( $this->locationId, $this->itemId, $this->day( -10 ), $this->day( -10, '23:59:59' ), '12:00 AM', '23:59', 'canceled' );
		Bookings::rebuild();

		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -9 ), $this->date( 5 ) );
		$days = $this->daily();

		// days after today are never stored
		$this->assertSame( $this->date( 0 ), array_key_last( $days ) );
		$this->assertSame( 10, count( $days ) );
		$this->assertSame( '1', $days[ $this->date( -9 ) ]['capacity_slots'] );
		$this->assertSame( '0', $days[ $this->date( -9 ) ]['booked_slots'] );
		foreach ( [ -5, -4, -3 ] as $offset ) {
			$this->assertSame( '1', $days[ $this->date( $offset ) ]['booked_slots'], "day $offset" );
		}
		$this->assertSame( '1', $days[ $this->date( -5 ) ]['bookings_started'] );
		$this->assertSame( '0', $days[ $this->date( -4 ) ]['bookings_started'] );

		// the canceled booking is not part of the capacity use, but counted on its start day (before the range)
		$this->assertSame( 0, (int) ( $days[ $this->date( -9 ) ]['bookings_canceled'] ?? 0 ) );
	}

	public function testCanceledBookingCountsOnStartDay() {
		$this->booking( $this->locationId, $this->itemId, $this->day( -4 ), $this->day( -4, '23:59:59' ), '12:00 AM', '23:59', 'canceled' );
		Bookings::rebuild();
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -9 ), $this->date( 0 ) );
		$days = $this->daily();

		$this->assertSame( '1', $days[ $this->date( -4 ) ]['bookings_canceled'] );
		$this->assertSame( '0', $days[ $this->date( -4 ) ]['bookings_started'] );
		$this->assertSame( '0', $days[ $this->date( -4 ) ]['booked_slots'] );
		$this->assertSame( '1', $days[ $this->date( -4 ) ]['capacity_slots'] );
	}

	public function testHourlyAndFullDayAreKeptApart() {
		$other = $this->createItem( 'Hourly item' );
		$this->createTimeframe( $this->locationId, $other, $this->day( -30 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'off', 'd', 1, '8:00 AM', '12:00 PM' );

		Calculator::recompute( $other, $this->locationId, $this->date( -3 ), $this->date( 0 ) );
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -3 ), $this->date( 0 ) );

		$this->assertSame( [], $this->daily( $other, 'day' ) );
		$this->assertSame( '4', $this->daily( $other, 'hour' )[ $this->date( -1 ) ]['capacity_slots'] );
		$this->assertSame( [], $this->daily( $this->itemId, 'hour' ) );
		$this->assertSame( '1', $this->daily( $this->itemId, 'day' )[ $this->date( -1 ) ]['capacity_slots'] );
	}

	public function testRepairIsCountedAsOutage() {
		$this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, $this->day( -3 ), $this->day( -2, '23:59:59' ) );
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -5 ), $this->date( 0 ) );
		$days = $this->daily();

		$this->assertSame( '1', $days[ $this->date( -5 ) ]['capacity_slots'] );
		$this->assertSame( '0', $days[ $this->date( -3 ) ]['capacity_slots'] );
		$this->assertSame( '1', $days[ $this->date( -3 ) ]['outage_slots'] );
		$this->assertSame( '1', $days[ $this->date( -2 ) ]['outage_slots'] );
		$this->assertSame( '1', $days[ $this->date( -3 ) ]['failure_slots'] );
		$this->assertSame( '0', $days[ $this->date( -1 ) ]['failure_slots'] );
		$this->assertSame( '0', $days[ $this->date( -1 ) ]['outage_slots'] );
	}

	public function testHolidayAndRepairTimeframesAreCountedAsOutage() {
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -3 ), $this->day( -3, '23:59:59' ), Timeframe::HOLIDAYS_ID, 'on', 'd' );
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -2 ), $this->day( -2, '23:59:59' ), Timeframe::REPAIR_ID, 'on', 'd' );
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -5 ), $this->date( 0 ) );
		$days = $this->daily();

		$this->assertSame( '0', $days[ $this->date( -5 ) ]['outage_slots'] );
		foreach ( [ -3, -2 ] as $offset ) {
			$this->assertSame( '0', $days[ $this->date( $offset ) ]['capacity_slots'], "capacity on day $offset" );
			$this->assertSame( '1', $days[ $this->date( $offset ) ]['outage_slots'], "outage on day $offset" );
			$this->assertSame( '0', $days[ $this->date( $offset ) ]['failure_slots'], "no total failure on day $offset" );
		}
		$this->assertSame( '0', $days[ $this->date( -1 ) ]['outage_slots'] );
	}

	public function testQueueMergesRangesAndIsWorkedOff() {
		global $wpdb;
		Queue::mark( $this->itemId, $this->locationId, $this->date( -5 ), $this->date( -3 ) );
		Queue::mark( $this->itemId, $this->locationId, $this->date( -9 ), $this->date( -4 ) );
		$pair = $wpdb->get_row( 'SELECT * FROM ' . Schema::pairs(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $this->date( -9 ), $pair['dirty_from'] );
		$this->assertSame( $this->date( -3 ), $pair['dirty_to'] );
		$this->assertSame( 1, Queue::pending() );

		$this->assertSame( 1, Queue::process() );
		$this->assertSame( 0, Queue::pending() );
		$this->assertArrayHasKey( $this->date( -9 ), $this->daily() );
	}

	public function testFutureRangeIsIgnored() {
		Queue::mark( $this->itemId, $this->locationId, $this->date( 3 ), $this->date( 9 ) );
		$this->assertSame( 0, Queue::pending() );
	}

	public function testChangedBookingIsAggregatedAtTheEndOfTheRequest() {
		Stats::enable();
		$this->finishBuild();
		$this->assertTrue( Stats::isReady() );

		$bookingId = $this->booking( $this->locationId, $this->itemId, $this->day( -2 ), $this->day( -2, '23:59:59' ) );
		Stats::onTransition( 'confirmed', 'unconfirmed', get_post( $bookingId ) );
		Stats::flush();

		$days = $this->daily();
		$this->assertSame( '1', $days[ $this->date( -2 ) ]['booked_slots'] );
		$this->assertSame( '1', $days[ $this->date( -2 ) ]['bookings_started'] );

		// cancelling removes the use again and counts the cancellation
		wp_update_post(
			[
				'ID' => $bookingId,
				'post_status' => 'canceled',
			]
		);
		update_post_meta( $bookingId, 'cancellation_time', $this->day( -1 ) );
		Stats::onTransition( 'canceled', 'confirmed', get_post( $bookingId ) );
		Stats::flush();

		$days = $this->daily();
		$this->assertSame( '0', $days[ $this->date( -2 ) ]['booked_slots'] );
		$this->assertSame( '0', $days[ $this->date( -2 ) ]['bookings_started'] );
		$this->assertSame( '1', $days[ $this->date( -2 ) ]['bookings_canceled'] );
	}

	public function testDeletedBookingDisappears() {
		Stats::enable();
		$this->finishBuild();
		$bookingId = $this->booking( $this->locationId, $this->itemId, $this->day( -2 ), $this->day( -2, '23:59:59' ) );
		Stats::onTransition( 'confirmed', 'new', get_post( $bookingId ) );
		Stats::flush();
		$this->assertSame( '1', $this->daily()[ $this->date( -2 ) ]['booked_slots'] );

		Stats::onDelete( $bookingId );
		wp_delete_post( $bookingId, true );
		Stats::flush();
		$this->assertSame( '0', $this->daily()[ $this->date( -2 ) ]['booked_slots'] );
	}

	private function finishBuild(): void {
		for ( $i = 0; $i < 50 && ! Build::progress()['done']; $i++ ) {
			Build::step( 5.0 );
		}
	}

	private function bookingRow( int $bookingId ): ?array {
		global $wpdb;
		return $wpdb->get_row( 'SELECT * FROM ' . Schema::booking() . ' WHERE booking_id = ' . $bookingId, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Model\Booking::cancel() sets the status with direct SQL (no transition_post_status), e.g. when a restriction
	 * is sent. The cancellation must arrive anyway.
	 */
	public function testCancelWithoutStatusTransitionIsCounted() {
		Stats::enable();
		$this->finishBuild();
		$bookingId = $this->booking( $this->locationId, $this->itemId, $this->day( 2 ), $this->day( 2, '23:59:59' ) );
		Stats::onTransition( 'confirmed', 'unconfirmed', get_post( $bookingId ) );
		Stats::flush();
		$this->assertSame( 'confirmed', $this->bookingRow( $bookingId )['status'] );

		// the hooks Stats::init() registers when the statistics are switched on
		add_action( 'added_post_meta', [ Stats::class, 'onMetaChange' ], 10, 3 );
		add_action( 'updated_post_meta', [ Stats::class, 'onMetaChange' ], 10, 3 );
		try {
			( new \CommonsBooking\Model\Booking( $bookingId ) )->cancel();
		} finally {
			remove_action( 'added_post_meta', [ Stats::class, 'onMetaChange' ] );
			remove_action( 'updated_post_meta', [ Stats::class, 'onMetaChange' ] );
		}
		Stats::flush();

		$row = $this->bookingRow( $bookingId );
		$this->assertSame( 'canceled', $row['status'] );
		$this->assertNotNull( $row['canceled_ts'] );
	}

	public function testOtherMetaChangesAreIgnored() {
		$bookingId = $this->booking( $this->locationId, $this->itemId, $this->day( 2 ), $this->day( 2, '23:59:59' ) );
		Stats::onMetaChange( 0, $bookingId, 'comment' );
		Stats::onMetaChange( 0, $this->itemId, 'cancellation_time' );
		Stats::flush();
		$this->assertNull( $this->bookingRow( $bookingId ) );
	}

	/**
	 * F3: the booster guard found changes made without this code (downgrade, database import) and rebuilds the
	 * index. The statistics follow: not ready until rebuilt, then complete.
	 */
	public function testBoosterRebuildRebuildsTheStatistics() {
		global $wpdb;
		Stats::enable();
		$this->finishBuild();
		$this->assertTrue( Stats::isReady() );

		// created and changed while the statistics code did not run
		$added   = $this->booking( $this->locationId, $this->itemId, $this->day( -3 ), $this->day( -3, '23:59:59' ) );
		$deleted = $this->booking( $this->locationId, $this->itemId, $this->day( -5 ), $this->day( -5, '23:59:59' ) );
		Stats::onTransition( 'confirmed', 'new', get_post( $deleted ) );
		Stats::flush();
		$wpdb->delete( $wpdb->posts, [ 'ID' => $deleted ] );
		clean_post_cache( $deleted );
		$this->assertNull( $this->bookingRow( $added ) );

		Stats::onBoosterRebuild( 'manual' );
		$this->assertTrue( Stats::isReady(), 'a manual index rebuild says nothing about the statistics' );

		Stats::onBoosterRebuild( 'plugin_version' );
		$this->assertFalse( Stats::isReady() );
		$this->assertTrue( Build::isRunning() );
		$this->finishBuild();

		$this->assertTrue( Stats::isReady() );
		$this->assertSame( 'confirmed', $this->bookingRow( $added )['status'] );
		$this->assertNull( $this->bookingRow( $deleted ) );
		$days = $this->daily();
		$this->assertSame( '1', $days[ $this->date( -3 ) ]['booked_slots'] );
		$this->assertSame( '0', $days[ $this->date( -5 ) ]['booked_slots'] );
	}

	public function testPostsRepairedByTheBoosterGuardAreProjected() {
		global $wpdb;
		Stats::enable();
		$this->finishBuild();
		$changed = $this->booking( $this->locationId, $this->itemId, $this->day( -3 ), $this->day( -3, '23:59:59' ) );
		$deleted = $this->booking( $this->locationId, $this->itemId, $this->day( -5 ), $this->day( -5, '23:59:59' ) );
		Stats::onTransition( 'confirmed', 'new', get_post( $changed ) );
		Stats::onTransition( 'confirmed', 'new', get_post( $deleted ) );
		Stats::flush();

		// changed and deleted without hooks
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'canceled' ], [ 'ID' => $changed ] );
		clean_post_cache( $changed );
		$wpdb->delete( $wpdb->posts, [ 'ID' => $deleted ] );
		clean_post_cache( $deleted );

		// like Guard::deepCheck(): repair the index first, then fire the action
		\CommonsBooking\Service\Index\Projector::project( $changed );
		\CommonsBooking\Service\Index\Projector::remove( $deleted );
		Stats::onBoosterRepaired( [ $changed, $deleted, $this->itemId ] );
		Stats::flush();

		$this->assertSame( 'canceled', $this->bookingRow( $changed )['status'] );
		$this->assertNull( $this->bookingRow( $deleted ) );
		$days = $this->daily();
		$this->assertSame( '0', $days[ $this->date( -3 ) ]['booked_slots'] );
		$this->assertSame( '0', $days[ $this->date( -5 ) ]['booked_slots'] );
	}

	public function testBuildRunsInStepsAndReportsProgress() {
		$bookingId = $this->booking( $this->locationId, $this->itemId, $this->day( -2 ), $this->day( -2, '23:59:59' ) );
		wp_update_post(
			[
				'ID' => $bookingId,
				'post_status' => 'confirmed',
			]
		);

		Stats::enable();
		$this->assertTrue( Stats::isEnabled() );
		$this->assertFalse( Stats::isReady() );
		$this->assertTrue( Build::isRunning() );
		$this->assertSame( 0, Build::progress()['percent'] );

		$seen = [];
		for ( $i = 0; $i < 50 && ! Build::progress()['done']; $i++ ) {
			$p      = Build::step( 0.0 ); // zero budget: one unit of work per call at least must still be possible
			$seen[] = $p['percent'];
		}
		$this->assertSame( $seen, array_values( array_filter( $seen, fn( $v ) => $v <= 100 ) ) );
		$sorted = $seen;
		sort( $sorted );
		$this->assertSame( $sorted, $seen, 'progress never goes backwards' );
		$this->assertTrue( Stats::isReady() );
		$this->assertSame( 100, Build::progress()['percent'] );
		$this->assertArrayHasKey( $this->date( -2 ), $this->daily() );
		$this->assertSame( '1', $this->daily()[ $this->date( -2 ) ]['booked_slots'] );
	}

	public function testBuildContinuesAfterInterruptionAndLocksAgainstParallelRuns() {
		Stats::enable();
		add_option( Build::LOCK_OPTION, time(), '', false );
		$before = Build::progress();
		$after  = Build::step( 5.0 );
		$this->assertSame( $before['percent'], $after['percent'], 'a fresh lock keeps a second process out' );

		update_option( Build::LOCK_OPTION, time() - 1000, false ); // a crashed process left its lock
		$this->finishBuild();
		$this->assertTrue( Stats::isReady() );
		$this->assertFalse( get_option( Build::LOCK_OPTION, false ) );
	}

	public function testStepNeverStartsWhenNothingRuns() {
		$this->assertFalse( Build::isRunning() );
		$this->assertSame( 100, Build::step( 1.0 )['percent'] );
	}
}
