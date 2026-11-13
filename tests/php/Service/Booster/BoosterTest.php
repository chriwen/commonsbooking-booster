<?php

namespace CommonsBooking\Tests\Service\Booster;

use CommonsBooking\Repository\Index;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Service\Booster\Guard;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema;
use CommonsBooking\Service\Upgrade;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;

/**
 * Switching the performance booster on and off, booking validation without the booster tables and the guard
 * against changes made while the booster code did not run (downgrade and upgrade again).
 */
class BoosterTest extends CustomPostTypeTest {

	private function row( int $postId ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE post_id = %d', $postId ), ARRAY_A );
	}

	private function createFutureBooking( string $status = 'confirmed' ): int {
		$start = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );

		return $this->createBooking( $this->locationId, $this->itemId, $start, strtotime( '+1 day', $start ) - 1, '12:00 AM', '23:59', $status, $this->subscriberId );
	}

	/**
	 * Simulates code without the booster: removes the index rows of a post and backdates it beyond the grace period.
	 */
	private function forgetPost( int $postId ): void {
		global $wpdb;
		$wpdb->delete( Schema::table(), [ 'post_id' => $postId ] );
		$wpdb->delete( Schema::relTable(), [ 'post_id' => $postId ] );
		$this->backdate( $postId );
	}

	private function backdate( int $postId ): void {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * Guard::GRACE_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified_gmt' => $old,
				'post_modified'     => $old,
			],
			[ 'ID' => $postId ]
		);
		clean_post_cache( $postId );
	}

	/**
	 * Writes the plugin version like another plugin version would: without the hooks of the booster.
	 */
	private function writeVersionWithoutBooster( string $version ): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, [ 'option_value' => $version ], [ 'option_name' => Upgrade::VERSION_OPTION ] );
		if ( ! $wpdb->rows_affected ) {
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => Upgrade::VERSION_OPTION,
					'option_value' => $version,
					'autoload'     => 'yes',
				]
			);
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Upgrade::VERSION_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	public function testReadsOnlyWhileSwitchedOnAndActive() {
		$this->assertTrue( Booster::canRead() );
		$this->assertTrue( Index::isAvailable() );

		add_filter( 'commonsbooking_booster_enabled', '__return_false' );
		$this->assertFalse( Booster::canRead() );
		$this->assertFalse( Index::isAvailable() );
		$this->assertFalse( Availability::isEnabled() );
		remove_filter( 'commonsbooking_booster_enabled', '__return_false' );

		Booster::saveState( [ 'active' => false ] );
		$this->assertFalse( Index::isAvailable(), 'switched on, but not activated yet (index not rebuilt)' );
	}

	public function testWithoutBoosterUsesOriginalQueries() {
		$this->assertTrue( Index::isAvailable() );
		$inside = Booster::withoutBooster(
			function () {
				return [ Booster::isBypassed(), Index::isAvailable(), Availability::isEnabled() ];
			}
		);
		$this->assertSame( [ true, false, false ], $inside );
		$this->assertFalse( Booster::isBypassed() );
		$this->assertTrue( Index::isAvailable() );

		// also after an exception
		try {
			Booster::withoutBooster(
				function () {
					throw new \RuntimeException( 'denied' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'denied', $e->getMessage() );
		}
		$this->assertTrue( Index::isAvailable() );
	}

	/**
	 * A booking unknown to the index (e.g. created by a plugin version without the booster) must still block the slot:
	 * booking validation runs with the original queries.
	 */
	public function testBookingValidationSeesBookingsMissingInTheIndex() {
		$bookingId = $this->createFutureBooking();
		Indexer::flush();
		$this->forgetPost( $bookingId );

		$start = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );
		$end   = strtotime( '+1 day', $start ) - 1;
		$find  = fn() => array_map( fn( $booking ) => $booking->ID, \CommonsBooking\Repository\Booking::getExistingBookings( $this->itemId, $this->locationId, $start, $end ) );

		$this->assertNotContains( $bookingId, $find(), 'the index does not know the booking' );
		$this->assertContains( $bookingId, Booster::withoutBooster( $find ), 'validation finds it with the original queries' );
	}

	/**
	 * The plugin cache may hold results computed from the index, validation must not use it.
	 * (The cache itself is off in the tests because of WP_DEBUG, so this checks the bypass.)
	 */
	public function testWithoutBoosterBypassesThePluginCache() {
		$this->assertFalse(
			Booster::withoutBooster( fn() => \CommonsBooking\Plugin::setCacheItem( 'value', [ 'booster-test' ], 'booster-test' ) ),
			'no cache writes during validation'
		);
		$this->assertFalse( Booster::withoutBooster( fn() => \CommonsBooking\Plugin::getCacheItem( 'booster-test' ) ) );
	}

	public function testDeactivateStopsReadingAndActivateRebuilds() {
		$this->createFutureBooking();
		Indexer::flush();

		Booster::deactivate();
		$this->assertFalse( Booster::getState()['active'] );
		$this->assertFalse( Backfill::isDone() );
		$this->assertFalse( Index::isAvailable() );
		$this->assertFalse( wp_next_scheduled( Guard::CRON_HOOK ) );

		Booster::activate();
		$this->assertTrue( Booster::getState()['active'] );
		$this->assertTrue( Backfill::isRunning() );
		$this->assertFalse( Index::isAvailable(), 'original queries until the rebuild is complete' );

		Backfill::runAll();
		$this->assertTrue( Index::isAvailable() );
	}

	public function testOnInitAppliesTheSetting() {
		add_filter( 'commonsbooking_booster_enabled', '__return_false' );
		Booster::onInit();
		$this->assertFalse( Booster::getState()['active'] );
		remove_filter( 'commonsbooking_booster_enabled', '__return_false' );

		Booster::onInit();
		$this->assertTrue( Booster::getState()['active'] );
		$this->assertTrue( Backfill::isRunning() );
	}

	public function testOwnVersionWriteIsNoForeignChange() {
		update_option( Upgrade::VERSION_OPTION, '9.9.9' );
		$this->assertSame( '9.9.9', Booster::getState()['plugin_version'] );
		$this->assertNull( Guard::check() );
		$this->assertTrue( Index::isAvailable() );
	}

	public function testForeignVersionWriteStartsRebuild() {
		$this->writeVersionWithoutBooster( '2.0.0-other' );

		$reason = Guard::check();
		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'plugin version', $reason );
		$this->assertFalse( Index::isAvailable() );
		$this->assertStringContainsString( 'plugin version', Booster::getState()['last_rebuild_reason'] );

		Backfill::runAll();
		$this->assertTrue( Index::isAvailable() );
		$this->assertNull( Guard::check() );
	}

	public function testPostCreatedWithoutBoosterStartsRebuild() {
		$this->assertNull( Guard::check() );
		$bookingId = $this->createFutureBooking();
		Indexer::flush();
		$this->forgetPost( $bookingId );

		$reason = Guard::check();
		$this->assertNotNull( $reason );
		$this->assertStringContainsString( (string) $bookingId, $reason );
		$this->assertFalse( Index::isAvailable() );

		Backfill::runAll();
		$this->assertNotNull( $this->row( $bookingId ) );
		$this->assertNull( Guard::check() );
	}

	public function testRecentUnindexedPostIsNotJudgedYet() {
		$this->assertNull( Guard::check() );
		$watermark = Booster::getState()['watermark'];

		// e.g. created by a concurrent request that has not projected it yet
		$bookingId = $this->createFutureBooking();
		Indexer::flush();
		global $wpdb;
		$wpdb->delete( Schema::table(), [ 'post_id' => $bookingId ] );

		$this->assertNull( Guard::check() );
		$this->assertTrue( Index::isAvailable() );
		$this->assertLessThan( $bookingId, Booster::getState()['watermark'] );
		$this->assertGreaterThanOrEqual( $watermark, Booster::getState()['watermark'] );
	}

	public function testWatermarkAdvancesOverIndexedPosts() {
		$bookingId = $this->createFutureBooking();
		Indexer::flush();
		$this->assertNull( Guard::check() );
		$this->assertGreaterThanOrEqual( $bookingId, Booster::getState()['watermark'] );
	}

	/**
	 * Cancelled by an older plugin version (direct SQL, no hooks) and deleted without hooks.
	 */
	public function testDeepCheckRepairsChangedAndDeletedPosts() {
		global $wpdb;
		$cancelledId = $this->createFutureBooking();
		$deletedId   = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+5 days', strtotime( self::CURRENT_DATE ) ),
			strtotime( '+6 days', strtotime( self::CURRENT_DATE ) ) - 1,
			'12:00 AM',
			'23:59',
			'confirmed',
			$this->subscriberId
		);
		Indexer::flush();
		$this->backdate( $cancelledId );
		Indexer::flush();

		$wpdb->update( $wpdb->posts, [ 'post_status' => 'canceled' ], [ 'ID' => $cancelledId ] );
		$wpdb->delete( $wpdb->posts, [ 'ID' => $deletedId ] );
		clean_post_cache( $cancelledId );

		$this->assertSame( 'confirmed', $this->row( $cancelledId )['post_status'] );
		$this->assertNotNull( $this->row( $deletedId ) );

		$dryRun = Guard::deepCheck( false );
		$this->assertContains( $cancelledId, $dryRun['changed'] );
		$this->assertContains( $deletedId, $dryRun['orphans'] );
		$this->assertSame( 'confirmed', $this->row( $cancelledId )['post_status'] );

		$result = Guard::deepCheck();
		$this->assertFalse( $result['rebuild'] );
		$this->assertSame( 'canceled', $this->row( $cancelledId )['post_status'] );
		$this->assertNull( $this->row( $deletedId ) );
		$this->assertTrue( Index::isAvailable() );

		$again = Guard::deepCheck( false );
		$this->assertNotContains( $cancelledId, $again['changed'] );
		$this->assertSame( [], $again['orphans'] );
	}

	public function testPurgeRemovesEverything() {
		// Keep the tables for the following tests: record the DROP statements instead of running them.
		$dropped = [];
		$filter  = function ( $query ) use ( &$dropped ) {
			if ( stripos( $query, 'DROP TABLE' ) === 0 ) {
				$dropped[] = $query;

				return 'SELECT 1';
			}

			return $query;
		};
		add_filter( 'query', $filter );
		Booster::setEnabled( true );
		Booster::purge();
		remove_filter( 'query', $filter );

		foreach ( [ Schema::table(), Schema::relTable(), \CommonsBooking\Service\Availability\Schema::table() ] as $table ) {
			$this->assertNotEmpty( array_filter( $dropped, fn( $query ) => str_contains( $query, $table ) ), "drops $table" );
		}
		$this->assertFalse( Schema::isInstalled() );
		$this->assertFalse( \CommonsBooking\Service\Availability\Schema::isInstalled() );
		$this->assertFalse( get_option( Booster::STATE_OPTION ) );
		$this->assertFalse( get_option( Backfill::READY_OPTION ) );
		$this->assertFalse( Index::isAvailable() );
		$this->assertArrayNotHasKey( Booster::SETTINGS_FIELD, (array) get_option( Booster::SETTINGS_KEY ) );
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
		// restore the state of the suite: booster active, index complete and current
		// the tables were not dropped (see testPurgeRemovesEverything), only the options need to be restored
		update_option( Schema::SCHEMA_VERSION_OPTION, Schema::SCHEMA_VERSION, true );
		update_option( \CommonsBooking\Service\Availability\Schema::SCHEMA_VERSION_OPTION, \CommonsBooking\Service\Availability\Schema::SCHEMA_VERSION, true );
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
