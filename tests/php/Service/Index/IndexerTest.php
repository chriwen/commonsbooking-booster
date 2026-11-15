<?php

namespace CommonsBooking\Tests\Service\Index;

use CommonsBooking\Model\Booking;
use CommonsBooking\Model\Restriction;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Projector;
use CommonsBooking\Service\Index\Schema;
use CommonsBooking\Service\Index\Verifier;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Tests that the index (cb_index / cb_index_rel) follows every write path of timeframes, bookings and restrictions.
 */
class IndexerTest extends CustomPostTypeTest {

	private function row( int $postId ): ?array {
		global $wpdb;
		Indexer::flush();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE post_id = %d', $postId ), ARRAY_A );
	}

	private function rel( int $postId ): array {
		global $wpdb;
		Indexer::flush();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT entity_type, entity_id FROM ' . Schema::relTable() . ' WHERE post_id = %d ORDER BY entity_type, entity_id', $postId ), ARRAY_A );
		return array_map( fn( $r ) => $r['entity_type'] . ':' . $r['entity_id'], $rows );
	}

	public function testSchemaIsInstalled() {
		$this->assertTrue( Schema::isInstalled() );
	}

	public function testBookableTimeframeIsProjected() {
		$start       = strtotime( self::CURRENT_DATE );
		$end         = strtotime( '+10 days', $start );
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, $start, $end, Timeframe::BOOKABLE_ID, 'off', 'w', 0, '8:00 AM', '12:00 PM', 'publish', [ '1', '3', '7' ] );

		$row = $this->row( $timeframeId );
		$this->assertNotNull( $row );
		$this->assertEquals( 'cb_timeframe', $row['post_type'] );
		$this->assertEquals( 'publish', $row['post_status'] );
		$this->assertEquals( Timeframe::BOOKABLE_ID, $row['type'] );
		$this->assertEquals( $this->itemId, $row['item_id'] );
		$this->assertEquals( $this->locationId, $row['location_id'] );
		$this->assertEquals( 0, $row['is_multi'] );
		$this->assertEquals( $start, $row['start_ts'] );
		$this->assertEquals( $end, $row['end_ts'] );
		$this->assertEquals( 'w', $row['repetition'] );
		$this->assertEquals( 1 | 4 | 64, $row['weekdays'] );
		$this->assertEquals( 0, $row['full_day'] );
		$this->assertEquals( '08:00:00', $row['start_time'] );
		$this->assertEquals( '12:00:00', $row['end_time'] );
		$this->assertEquals( 4, $row['grid_size'] );
		$this->assertEquals( 3, $row['max_days'] );
		$this->assertEquals( 30, $row['advance_days'] );
		$this->assertEquals( 0, $row['startday_offset'] );
		$this->assertEquals( Projector::PROJECTOR_VERSION, $row['projector_version'] );
		$this->assertSame( [], $this->rel( $timeframeId ) );
	}

	public function testOpenEndTimeframeHasNullEnd() {
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		$this->assertNull( $this->row( $timeframeId )['end_ts'] );
	}

	public function testMetaChangeUpdatesRow() {
		$start       = strtotime( self::CURRENT_DATE );
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, $start, strtotime( '+10 days', $start ) );
		$this->row( $timeframeId );

		$newEnd = strtotime( '+20 days', $start );
		update_post_meta( $timeframeId, \CommonsBooking\Model\Timeframe::REPETITION_END, $newEnd );
		$this->assertContains( $timeframeId, Indexer::getDirty() );
		$this->assertEquals( $newEnd, $this->row( $timeframeId )['end_ts'] );

		delete_post_meta( $timeframeId, \CommonsBooking\Model\Timeframe::REPETITION_END );
		$this->assertNull( $this->row( $timeframeId )['end_ts'] );
	}

	public function testIrrelevantMetaDoesNotMarkDirty() {
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		Indexer::flush();
		update_post_meta( $timeframeId, '_edit_lock', '123:1' );
		$this->assertNotContains( $timeframeId, Indexer::getDirty() );
	}

	public function testNonIndexedPostTypeIsIgnored() {
		update_post_meta( $this->itemId, 'type', 2 );
		$this->assertNotContains( $this->itemId, Indexer::getDirty() );
		$this->assertNull( Projector::buildRow( get_post( $this->itemId ) ) );
	}

	public function testBookingIsProjected() {
		$start     = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );
		$bookingId = $this->createBooking( $this->locationId, $this->itemId, $start, strtotime( '+1 day', $start ) - 1, '12:00 AM', '23:59', 'confirmed', $this->subscriberId );

		$row = $this->row( $bookingId );
		$this->assertEquals( 'cb_booking', $row['post_type'] );
		$this->assertEquals( 'confirmed', $row['post_status'] );
		$this->assertEquals( Timeframe::BOOKING_ID, $row['type'] );
		$this->assertEquals( $this->subscriberId, $row['post_author'] );
		$this->assertEquals( $this->itemId, $row['item_id'] );
		$this->assertEquals( $this->locationId, $row['location_id'] );
		$this->assertEquals( $start, $row['start_ts'] );
		$this->assertEquals( '23:59:00', $row['end_time'] );
	}

	public function testStatusTransitionUpdatesRow() {
		$start     = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );
		$bookingId = $this->createBooking( $this->locationId, $this->itemId, $start, strtotime( '+1 day', $start ) - 1, '12:00 AM', '23:59', 'unconfirmed' );
		$this->assertEquals( 'unconfirmed', $this->row( $bookingId )['post_status'] );

		wp_update_post(
			[
				'ID'          => $bookingId,
				'post_status' => 'confirmed',
			]
		);
		$this->assertEquals( 'confirmed', $this->row( $bookingId )['post_status'] );
	}

	/**
	 * Booking::cancel() updates the status with direct SQL, without post hooks.
	 */
	public function testCancelUpdatesRowImmediately() {
		$bookingId = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+1 day' ),
			strtotime( '+2 days' )
		);
		Indexer::flush();

		( new Booking( $bookingId ) )->cancel();

		$this->assertSame( [], Indexer::getDirty(), 'cancel() must flush the index itself' );
		$this->assertEquals( 'canceled', $this->row( $bookingId )['post_status'] );
		$this->assertEquals( 'canceled', get_post( $bookingId )->post_status, 'post cache must not be stale after cancel()' );
	}

	public function testMultiSelectionUsesRelationTable() {
		$secondItemId = $this->createItem( 'Second item' );
		$holidayId    = $this->createHolidayTimeframeForAllItemsAndLocations();

		$row = $this->row( $holidayId );
		$this->assertEquals( Timeframe::HOLIDAYS_ID, $row['type'] );
		$this->assertEquals( 1, $row['is_multi'] );
		$this->assertNull( $row['item_id'] );
		$this->assertNull( $row['location_id'] );

		$rel = $this->rel( $holidayId );
		$this->assertContains( 'item:' . $this->itemId, $rel );
		$this->assertContains( 'item:' . $secondItemId, $rel );
		$this->assertContains( 'location:' . $this->locationId, $rel );
	}

	public function testRestrictionWithWildcardItem() {
		$start         = strtotime( self::CURRENT_DATE );
		$restrictionId = $this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, 0, $start, null );

		$row = $this->row( $restrictionId );
		$this->assertEquals( 'cb_restriction', $row['post_type'] );
		$this->assertEquals( 0, $row['type'] );
		$this->assertEquals( 0, $row['item_id'], '0 = all items' );
		$this->assertEquals( $this->locationId, $row['location_id'] );
		$this->assertEquals( $start, $row['start_ts'] );
		$this->assertNull( $row['end_ts'] );
		$this->assertEquals( Restriction::TYPE_REPAIR, $row['restriction_type'] );
		$this->assertEquals( Restriction::STATE_ACTIVE, $row['restriction_state'] );

		update_post_meta( $restrictionId, Restriction::META_STATE, Restriction::STATE_SOLVED );
		$this->assertEquals( Restriction::STATE_SOLVED, $this->row( $restrictionId )['restriction_state'] );
	}

	public function testTrashAndDeleteRemoveOrUpdateRows() {
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		$this->assertNotNull( $this->row( $timeframeId ) );

		wp_trash_post( $timeframeId );
		$this->assertEquals( 'trash', $this->row( $timeframeId )['post_status'] );

		wp_delete_post( $timeframeId, true );
		$this->assertNull( $this->row( $timeframeId ) );
	}

	/**
	 * Restriction::apply() cancels affected bookings via Booking::cancel() (direct SQL).
	 */
	public function testRestrictionCancelsBookingsInIndex() {
		$start     = strtotime( '+2 days midnight' );
		$bookingId = $this->createBooking( $this->locationId, $this->itemId, $start, $start + 86399 );
		$this->assertEquals( 'confirmed', $this->row( $bookingId )['post_status'] );

		$restrictionId = $this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, $start - 3600, $start + 2 * 86400 );
		( new Restriction( $restrictionId ) )->apply();

		$this->assertEquals( 'canceled', $this->row( $bookingId )['post_status'] );
		$this->assertNull( Projector::diff( $bookingId ) );
	}

	/**
	 * The cleanup cron deletes unconfirmed bookings older than 10 minutes.
	 */
	public function testCleanupCronRemovesRows() {
		global $wpdb;
		$start     = strtotime( '+2 days midnight' );
		$bookingId = $this->createBooking( $this->locationId, $this->itemId, $start, $start + 86399, '12:00 AM', '23:59', 'unconfirmed' );
		$this->assertNotNull( $this->row( $bookingId ) );

		$old = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'     => $old,
				'post_date_gmt' => $old,
			],
			[ 'ID' => $bookingId ]
		);
		clean_post_cache( $bookingId );

		\CommonsBooking\Service\Booking::cleanupBookings();

		$this->assertNull( get_post( $bookingId ) );
		$this->assertNull( $this->row( $bookingId ) );
	}

	/**
	 * Timeframes with "all items" are updated when an item is added (Item::savePost -> updateAllTimeframes).
	 */
	public function testNewItemIsAddedToAllItemsHoliday() {
		$this->createItem( 'Second item' );
		$holidayId = $this->createHolidayTimeframeForAllItemsAndLocations();
		$this->assertEquals( 1, $this->row( $holidayId )['is_multi'] );

		$newItemId = $this->createItem( 'Added later' );

		$this->assertContains( 'item:' . $newItemId, $this->rel( $holidayId ) );
		$this->assertNull( Projector::diff( $holidayId ) );
	}

	public function testUntrashRestoresStatus() {
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		wp_trash_post( $timeframeId );
		$this->assertEquals( 'trash', $this->row( $timeframeId )['post_status'] );

		wp_untrash_post( $timeframeId );
		$this->assertEquals( get_post( $timeframeId )->post_status, $this->row( $timeframeId )['post_status'] );
		$this->assertNotEquals( 'trash', $this->row( $timeframeId )['post_status'] );
	}

	public function testBackfillRebuildsIndexAndEnablesReading() {
		global $wpdb;
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		$bookingId   = $this->createConfirmedBookingStartingToday();
		Indexer::flush();

		// simulate a fresh installation with existing data
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . Schema::table() );
		$wpdb->query( 'DELETE FROM ' . Schema::relTable() );
		// phpcs:enable
		Backfill::start();
		$this->assertFalse( Backfill::isReady() );

		Backfill::runAll( 1 );

		$this->assertTrue( Backfill::isDone() );
		// the suite can run with the index disabled (CB_USE_INDEX=0)
		$this->assertSame( getenv( 'CB_USE_INDEX' ) !== '0', Backfill::isReady() );
		$this->assertFalse( Backfill::isRunning() );
		$this->assertNotNull( $this->row( $timeframeId ) );
		$this->assertNotNull( $this->row( $bookingId ) );

		add_filter( 'commonsbooking_use_index', '__return_false' );
		$this->assertFalse( Backfill::isReady() );
		remove_filter( 'commonsbooking_use_index', '__return_false' );
	}

	public function testVerifierDetectsAndRepairsDifferences() {
		global $wpdb;
		$timeframeId = $this->createTimeframe( $this->locationId, $this->itemId, strtotime( self::CURRENT_DATE ), null );
		Indexer::flush();
		$this->assertNull( Projector::diff( $timeframeId ) );

		// a write that bypasses all hooks
		$wpdb->update( Schema::table(), [ 'location_id' => 999999 ], [ 'post_id' => $timeframeId ] );
		$wpdb->insert(
			Schema::table(),
			[
				'post_id'     => 999999999,
				'post_type'   => 'cb_booking',
				'post_status' => 'confirmed',
			]
		);
		$this->assertArrayHasKey( 'location_id', Projector::diff( $timeframeId ) );

		$result = Verifier::verify( null, true );
		$this->assertArrayHasKey( $timeframeId, $result['mismatches'] );
		$this->assertEquals( 1, $result['orphans'] );

		$this->assertNull( Projector::diff( $timeframeId ) );
		$this->assertNull( $this->row( 999999999 ) );
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! \CommonsBooking\Service\Booster\Booster::isEnabled() ) {
			$this->markTestSkipped( 'Performance booster is switched off (CB_USE_BOOSTER=0), the index is not maintained.' );
		}
		$this->createSubscriber();
	}
}
