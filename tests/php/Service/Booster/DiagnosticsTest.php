<?php

namespace CommonsBooking\Tests\Service\Booster;

use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Service\Booster\Diagnostics;
use CommonsBooking\Service\Booster\Guard;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;

/**
 * Data shown on the settings page of the performance booster: activity log, latest bookings, checks and health.
 */
class DiagnosticsTest extends CustomPostTypeTest {

	private function createFutureBooking( string $status = 'confirmed' ): int {
		$start = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );

		return $this->createBooking( $this->locationId, $this->itemId, $start, strtotime( '+1 day', $start ) - 1, '12:00 AM', '23:59', $status, $this->subscriberId );
	}

	/**
	 * Index updates are written at the end of the request; the activity log too.
	 */
	private function endRequest(): void {
		Indexer::flush();
		Diagnostics::writeActivity();
	}

	private function eventsFor( int $postId ): array {
		return array_values( array_filter( Diagnostics::getActivity(), fn( $e ) => $e['post_id'] === $postId ) );
	}

	public function testActivityLogRecordsCreateCancelAndDelete() {
		$bookingId = $this->createFutureBooking();
		$this->endRequest();
		$events = $this->eventsFor( $bookingId );
		$this->assertSame( 'created', $events[0]['action'] );
		$this->assertSame( 'cb_booking', $events[0]['post_type'] );
		$this->assertSame( 'confirmed', $events[0]['new_status'] );
		$this->assertSame( 'frontend', $events[0]['source'] );
		$this->assertSame( 'ok', Diagnostics::getEventState( $events[0] ) );

		wp_update_post(
			[
				'ID'          => $bookingId,
				'post_status' => 'canceled',
			]
		);
		$this->endRequest();
		$events = $this->eventsFor( $bookingId );
		$this->assertSame( 'changed', $events[0]['action'] );
		$this->assertSame( 'confirmed', $events[0]['old_status'] );
		$this->assertSame( 'canceled', $events[0]['new_status'] );

		wp_delete_post( $bookingId, true );
		$this->endRequest();
		$events = $this->eventsFor( $bookingId );
		$this->assertSame( 'removed', $events[0]['action'] );
		$this->assertSame( 'ok', Diagnostics::getEventState( $events[0] ) );
	}

	public function testActivityLogIsLimited() {
		for ( $i = 0; $i < Diagnostics::ACTIVITY_SIZE + 5; $i++ ) {
			Diagnostics::onIndexChange(
				null,
				[
					'row' => [
						'post_id'     => 100000 + $i,
						'post_type'   => 'cb_booking',
						'post_status' => 'confirmed',
					],
				],
				100000 + $i
			);
		}
		Diagnostics::writeActivity();
		$log = Diagnostics::getActivity();
		$this->assertCount( Diagnostics::ACTIVITY_SIZE, $log );
		$this->assertSame( 100000 + Diagnostics::ACTIVITY_SIZE + 4, $log[0]['post_id'] );
	}

	public function testLatestBookingsCompareTheIndexStatus() {
		$bookingId = $this->createFutureBooking();
		$this->endRequest();
		$latest = Diagnostics::getLatestBookings();
		$this->assertSame( $bookingId, $latest[0]['id'] );
		$this->assertSame( 'confirmed', $latest[0]['index_status'] );
		$this->assertSame( 'ok', Diagnostics::compareStatus( $latest[0]['status'], $latest[0]['index_status'], $latest[0]['changed'] ) );
		$this->assertEqualsWithDelta( time(), $latest[0]['changed'], 5 );

		// a status change the index missed (e.g. made by an older plugin version)
		global $wpdb;
		$wpdb->update( Schema::table(), [ 'post_status' => 'unconfirmed' ], [ 'post_id' => $bookingId ] );
		$latest = Diagnostics::getLatestBookings();
		$this->assertSame( 'pending', Diagnostics::compareStatus( $latest[0]['status'], $latest[0]['index_status'], $latest[0]['changed'] ) );
		$this->assertSame( 'mismatch', Diagnostics::compareStatus( $latest[0]['status'], $latest[0]['index_status'], time() - 2 * Guard::GRACE_SECONDS ) );
	}

	public function testHealthReportsAMismatchAndChecksRepairIt() {
		$bookingId = $this->createFutureBooking();
		$this->endRequest();
		$this->assertNotSame( 'error', Diagnostics::getHealth()['level'] );

		global $wpdb;
		$wpdb->update( Schema::table(), [ 'post_status' => 'unconfirmed' ], [ 'post_id' => $bookingId ] );
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * Guard::GRACE_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified_gmt' => $old,
				'post_modified'     => get_date_from_gmt( $old ),
			],
			[ 'ID' => $bookingId ]
		);
		clean_post_cache( $bookingId );
		$this->assertSame( 'error', Diagnostics::getHealth()['level'] );

		$result = Diagnostics::runAllChecks();
		$this->assertNotEmpty( $result );
		$this->assertArrayHasKey( 'guard_deep', Diagnostics::getChecks() );
		$this->assertSame( 'confirmed', $wpdb->get_var( $wpdb->prepare( 'SELECT post_status FROM ' . Schema::table() . ' WHERE post_id = %d', $bookingId ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotSame( 'error', Diagnostics::getHealth()['level'] );
	}

	public function testRecordCheckStoresTimeAndResult() {
		Diagnostics::recordCheck( 'index_verify', [ 'checked' => 5, 'mismatches' => 0 ] );
		$check = Diagnostics::getChecks()['index_verify'];
		$this->assertSame( 5, $check['checked'] );
		$this->assertEqualsWithDelta( time(), $check['time'], 5 );
	}

	public function testHealthIsOffWhileSwitchedOff() {
		add_filter( 'commonsbooking_booster_enabled', '__return_false', 99 );
		$this->assertSame( 'off', Diagnostics::getHealth()['level'] );
		remove_filter( 'commonsbooking_booster_enabled', '__return_false', 99 );
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! Booster::isEnabled() || ! Booster::canRead() ) {
			$this->markTestSkipped( 'Performance booster is switched off (CB_USE_BOOSTER=0).' );
		}
		Diagnostics::init();
		delete_option( Diagnostics::ACTIVITY_OPTION );
		delete_option( Diagnostics::CHECKS_OPTION );
		$this->createSubscriber();
	}

	protected function tearDown(): void {
		delete_option( Diagnostics::ACTIVITY_OPTION );
		delete_option( Diagnostics::CHECKS_OPTION );
		parent::tearDown();
	}
}
