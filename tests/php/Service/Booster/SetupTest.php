<?php

namespace CommonsBooking\Tests\Service\Booster;

use CommonsBooking\Repository\Index;
use CommonsBooking\Service\Availability\Schema as AvailabilitySchema;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Service\Booster\Diagnostics;
use CommonsBooking\Service\Booster\Guard;
use CommonsBooking\Service\Booster\SettingsView;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Schema;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;

/**
 * Setup of the booster without WP-Cron: time slices with a lock, "Finish setup now" on the settings page,
 * the warning for a stalled setup and missing tables after a database import.
 *
 * @see design/booster.md, section "Einrichtung ohne WP-Cron"
 */
class SetupTest extends CustomPostTypeTest {

	private function createFutureBooking(): int {
		$start = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );

		return $this->createBooking( $this->locationId, $this->itemId, $start, strtotime( '+1 day', $start ) - 1, '12:00 AM', '23:59', 'confirmed', $this->subscriberId );
	}

	/**
	 * Holds the lock like another process (WP-Cron or a second browser tab).
	 */
	private function holdLock( int $expires ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->options,
			[
				'option_name'  => Backfill::LOCK_OPTION,
				'option_value' => (string) $expires,
				'autoload'     => 'no',
			]
		);
	}

	private function scheduleBackfillAt( int $timestamp ): void {
		wp_clear_scheduled_hook( Backfill::CRON_HOOK );
		wp_schedule_single_event( $timestamp, Backfill::CRON_HOOK );
	}

	public function testRunForCompletesTheBackfill() {
		$this->createFutureBooking();
		Backfill::start();
		$this->assertFalse( Index::isAvailable() );

		$this->assertSame( Backfill::RUN_DONE, Backfill::runFor( 0 ) );
		$this->assertTrue( Backfill::isDone() );
		$this->assertTrue( Index::isAvailable() );
		$this->assertFalse( get_option( Backfill::LOCK_OPTION ), 'lock released' );
		$this->assertSame( Backfill::RUN_DONE, Backfill::runFor( 1 ), 'nothing to do' );
	}

	public function testRunForWaitsForAnotherProcess() {
		Backfill::start();
		$this->holdLock( time() + 60 );

		$this->assertSame( Backfill::RUN_BUSY, Backfill::runFor( 1 ) );
		$this->assertTrue( Backfill::isRunning() );

		// the cron run does not give up, it checks again later
		wp_clear_scheduled_hook( Backfill::CRON_HOOK );
		Backfill::runCron();
		$this->assertGreaterThan( time() + 30, wp_next_scheduled( Backfill::CRON_HOOK ) );

		// an expired lock (the process died) is taken over
		$this->holdLock( time() - 1 );
		$this->assertSame( Backfill::RUN_DONE, Backfill::runFor( 1 ) );
		$this->assertTrue( Backfill::isDone() );
	}

	public function testIsStalledIfTheCronEventIsOverdueOrMissing() {
		Backfill::start();
		$this->assertFalse( Backfill::isStalled(), 'just scheduled' );

		$this->scheduleBackfillAt( time() - Backfill::STALL_SECONDS + 30 );
		$this->assertFalse( Backfill::isStalled() );

		$this->scheduleBackfillAt( time() - Backfill::STALL_SECONDS - 60 );
		$this->assertTrue( Backfill::isStalled() );
		$this->assertGreaterThan( Backfill::STALL_SECONDS, Backfill::getOverdueSeconds() );

		wp_clear_scheduled_hook( Backfill::CRON_HOOK );
		$this->assertTrue( Backfill::isStalled(), 'event missing' );

		Backfill::runAll();
		$this->assertFalse( Backfill::isStalled(), 'not running' );
	}

	public function testHealthReportsAStalledSetup() {
		Backfill::start();
		$this->scheduleBackfillAt( time() - HOUR_IN_SECONDS );

		$health = Diagnostics::getHealth();
		$this->assertSame( 'error', $health['level'] );
		$texts = array_column( $health['findings'], 'text' );
		$this->assertContains( Diagnostics::getStalledText(), $texts );
		$this->assertEmpty( array_filter( $texts, fn( $text ) => str_contains( $text, Backfill::CRON_HOOK ) ), 'no second finding for the same event' );
	}

	public function testSetupStepFinishesIndexAndCalendars() {
		$this->createFutureBooking();
		// a current bookable timeframe, so that there is an item calendar to prepare
		$from = min( time(), strtotime( self::CURRENT_DATE ) ) - 10 * DAY_IN_SECONDS;
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( gmdate( 'Y-m-d', $from ) ), strtotime( '+120 days', time() ), \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID, 'on', 'd' );
		Booster::deactivate();
		Booster::activate();
		$this->assertFalse( Index::isAvailable() );

		$steps = [];
		do {
			$step    = SettingsView::runSetupStep();
			$steps[] = $step['phase'];
		} while ( ! $step['done'] && count( $steps ) < 20 );

		$this->assertTrue( $step['done'] );
		$this->assertSame( 'done', $step['phase'] );
		$this->assertSame( 100, $step['percent'] );
		$this->assertTrue( Index::isAvailable() );
		$stats = \CommonsBooking\Service\Availability\Store::getStats();
		$this->assertGreaterThan( 0, $stats['rows'], 'calendars created without WP-Cron' );
		$this->assertSame( $stats['rows'] - $stats['role_limited'], $stats['usable'], 'all calendars computed' );
	}

	public function testWorkInRequestOnlyForAdministratorsInTheBackend() {
		$this->createFutureBooking();
		$this->createAdministrator();
		Backfill::start();

		// frontend
		wp_set_current_user( $this->adminUserID );
		Booster::workInRequest();
		$this->assertFalse( Backfill::isDone() );

		// backend, but no administrator
		set_current_screen( 'dashboard' );
		wp_set_current_user( $this->subscriberId );
		Booster::workInRequest();
		$this->assertFalse( Backfill::isDone() );

		wp_set_current_user( $this->adminUserID );
		Booster::workInRequest();
		$this->assertTrue( Backfill::isDone() );
	}

	public function testActivateCreatesMissingTables() {
		// a database import without the booster tables: the options say "installed", SHOW TABLES finds nothing
		$this->assertTrue( Schema::isInstalled() );
		$described = [];
		$filter    = function ( $query ) use ( &$described ) {
			if ( stripos( $query, 'SHOW TABLES LIKE' ) === 0 ) {
				return 'SELECT NULL';
			}
			if ( stripos( $query, 'DESCRIBE' ) === 0 ) {
				$described[] = $query;
			}

			return $query;
		};
		add_filter( 'query', $filter );
		$this->assertFalse( Schema::tablesExist() );
		$this->assertFalse( AvailabilitySchema::tablesExist() );
		Booster::activate();
		remove_filter( 'query', $filter );

		// dbDelta describes the tables it creates or updates
		foreach ( [ Schema::table(), Schema::relTable(), AvailabilitySchema::table() ] as $table ) {
			$this->assertNotEmpty( array_filter( $described, fn( $query ) => str_contains( $query, $table ) ), "installs $table" );
		}
		$this->assertTrue( Schema::tablesExist() );
		$this->assertTrue( AvailabilitySchema::tablesExist() );
	}

	public function testRebuildReasonForMissingTables() {
		$this->assertSame( 'the database tables of the booster were missing', Guard::describeRebuild( 'tables_missing', [], false ) );
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! Booster::isEnabled() ) {
			$this->markTestSkipped( 'Performance booster is switched off (CB_USE_BOOSTER=0).' );
		}
		if ( getenv( 'CB_USE_INDEX' ) === '0' ) {
			$this->markTestSkipped( 'Index reading is disabled (CB_USE_INDEX=0).' );
		}
		add_filter( 'commonsbooking_disableCache', '__return_true' );
		$this->createSubscriber();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => Backfill::LOCK_OPTION ] );
		wp_cache_delete( Backfill::LOCK_OPTION, 'options' );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		// restore the state of the suite: booster active, index complete and current
		Backfill::runAll();
		Booster::saveState(
			[
				'active'         => true,
				'plugin_version' => Guard::getStoredPluginVersion(),
				'watermark'      => Guard::getMaxPostId(),
			]
		);
		remove_filter( 'commonsbooking_disableCache', '__return_true' );
		parent::tearDown();
	}
}
