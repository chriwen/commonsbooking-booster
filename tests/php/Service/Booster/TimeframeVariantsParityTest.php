<?php

namespace CommonsBooking\Tests\Service\Booster;

use CommonsBooking\Map\MapItemAvailable;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Calculator;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Reader;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Tests\Fixtures\TimeframeVariants;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Calendar;
use SlopeIt\ClockMock\ClockMock;

/**
 * For every timeframe configuration of {@see TimeframeVariants} and every kind of user, the booster must show
 * exactly what the original code shows: calendar data, precomputed day status and map availability.
 */
class TimeframeVariantsParityTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	private const DAYS = 45;

	private array $variants;

	private int $managerId = 0;

	private int $otherManagerId = 0;

	/**
	 * @return array<string, int> label => user id (0 = guest)
	 */
	private function users(): array {
		$this->createSubscriber();
		$this->createEditor();
		$this->createAdministrator();

		return [
			'guest'                => 0,
			'subscriber'           => (int) $this->subscriberId,
			'editor'               => (int) $this->editorUserID,
			'cb_manager (assigned)' => $this->managerId,
			'cb_manager (other)'   => $this->otherManagerId,
			'admin'                => $this->adminUserID,
		];
	}

	private function forEachUser( callable $check ): void {
		foreach ( $this->users() as $label => $userId ) {
			wp_set_current_user( $userId );
			Calculator::reset();
			$check( $label );
		}
	}

	private function range(): array {
		return [ date( 'Y-m-d' ), date( 'Y-m-d', strtotime( '+' . self::DAYS . ' days' ) ) ];
	}

	public function testCalendarDataEqualsOriginal() {
		[ $from, $to ] = $this->range();
		$this->forEachUser(
			function ( string $user ) use ( $from, $to ) {
				foreach ( $this->variants['pairs'] as $case => [ $itemId, $locationId ] ) {
					$original = Booster::withoutBooster( fn() => Calendar::getCalendarDataArray( $itemId, $locationId, $from, $to ) );
					$boosted  = Calendar::getCalendarDataArray( $itemId, $locationId, $from, $to );
					$this->assertEquals( $original, $boosted, "calendar data differs: $case as $user" );
				}
			}
		);
	}

	public function testPrecomputedDaysEqualOriginal() {
		if ( ! Availability::isEnabled() ) {
			$this->markTestSkipped( 'Precomputed availability is disabled.' );
		}
		[ $from, $to ] = $this->range();
		$this->forEachUser(
			function ( string $user ) use ( $from, $to ) {
				foreach ( $this->variants['pairs'] as $case => [ $itemId, $locationId ] ) {
					$original = Booster::withoutBooster( fn() => Reader::evaluateLive( $itemId, $locationId, $from, $to, false, $from, $to )['days'] );
					$stored   = ( new Reader() )->getDayStatuses( $itemId, $locationId, $from, $to, false, $from, $to )['days'];
					$this->assertSame( $original, $stored, "day status differs: $case as $user" );
				}
			}
		);
	}

	public function testMapAvailabilityEqualsOriginal() {
		[ $from, $to ] = $this->range();
		$locations     = [];
		foreach ( $this->variants['pairs'] as [ $itemId, $locationId ] ) {
			$locations[ $locationId ]['items'][] = [ 'id' => $itemId ];
		}
		$this->forEachUser(
			function ( string $user ) use ( $locations, $from, $to ) {
				$original = Booster::withoutBooster( fn() => MapItemAvailable::create_items_availabilities( $locations, $from, $to ) );
				$boosted  = MapItemAvailable::create_items_availabilities( $locations, $from, $to );
				$this->assertEquals( $original, $boosted, "map availability differs as $user" );
			}
		);
	}

	public function testMapLocationsEqualOriginal() {
		$mapId = $this->createMap();
		$this->forEachUser(
			function ( string $user ) use ( $mapId ) {
				$original = Booster::withoutBooster( fn() => ( new \CommonsBooking\Model\Map( $mapId ) )->get_locations( [] ) );
				$boosted  = ( new \CommonsBooking\Model\Map( $mapId ) )->get_locations( [] );
				$this->assertNotEmpty( $original );
				$this->assertEquals( $original, $boosted, "map locations differ as $user" );
			}
		);
	}

	public function testOnlyTheIntendedTimeframesAreUnpublished() {
		// CommonsBooking saves invalid timeframes as draft; a variant must not lose its meaning this way
		global $wpdb;
		$unpublished = $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'cb_timeframe' AND post_title LIKE 'Variante:%' AND post_status <> 'publish' ORDER BY post_title" );
		$this->assertSame( [ 'Variante: Do–So Entwurf', 'Variante: Do–So gelöscht' ], array_values( array_unique( $unpublished ) ) );
	}

	public function testVariantsShowWhatTheyDescribe() {
		// a few spot checks, so that the fixture does not silently lose its meaning
		[ $from, $to ] = $this->range();
		$days          = fn( string $case ) => Booster::withoutBooster( fn() => Reader::evaluateLive( $this->variants['pairs'][ $case ][0], $this->variants['pairs'][ $case ][1], $from, $to, false, $from, $to )['days'] );
		wp_set_current_user( 0 );

		$weekend = $days( 'weekly_weekend' );
		foreach ( $weekend as $date => $status ) {
			if ( (int) date( 'N', strtotime( $date ) ) <= 5 ) {
				$this->assertNotSame( 'available', $status, "weekly_weekend: weekday $date must not be bookable" );
			}
		}

		$slots = $days( 'two_slots_mon_thu' );
		foreach ( $slots as $date => $status ) {
			if ( in_array( (int) date( 'N', strtotime( $date ) ), [ 5, 6 ], true ) ) {
				$this->assertNotSame( 'available', $status, "two_slots_mon_thu: Friday/Saturday $date must not be bookable" );
			}
		}
		$this->assertContains( 'available', $slots );

		$this->assertNotContains( 'available', $days( 'expired' ) );
		$this->assertNotContains( 'available', $days( 'roles_editor' ), 'guest must not see the editor-only item as available' );
		$this->assertContains( 'available', $days( 'daily_open_end' ) );
		$this->assertNotContains( 'available', $days( 'roles_cb_manager' ), 'guest must not see the cb_manager-only item as available' );
		wp_set_current_user( $this->otherManagerId );
		$this->assertContains( 'available', $days( 'roles_cb_manager' ), 'any cb_manager may book the cb_manager-only item' );
		$this->assertNotContains( 'available', $days( 'roles_editor' ), 'cb_manager must not see the editor-only item as available' );
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! Booster::isEnabled() || ! Booster::canRead() ) {
			$this->markTestSkipped( 'Performance booster is switched off (CB_USE_BOOSTER=0).' );
		}
		ClockMock::freeze( new \DateTime( self::NOW ) );
		add_filter( 'commonsbooking_disableCache', '__return_true' );

		$this->createCBManager();
		$this->managerId      = (int) $this->cbManagerUserID;
		$other                = get_user_by( 'login', 'othermanager' );
		$this->otherManagerId = $other ? $other->ID : (int) wp_create_user( 'othermanager', 'othermanager', 'othermanager@example.org' );
		( new \WP_User( $this->otherManagerId ) )->set_role( \CommonsBooking\Plugin::$CB_MANAGER_ID );

		$counter        = 0;
		$this->variants = TimeframeVariants::create(
			strtotime( date( 'Y-m-d' ) ),
			'Variante',
			function () use ( &$counter ) {
				++$counter;

				return [
					'geo_latitude'                                      => 50.9 + $counter / 100,
					'geo_longitude'                                     => 6.9 + $counter / 100,
					COMMONSBOOKING_METABOX_PREFIX . 'location_street'   => "Straße $counter",
					COMMONSBOOKING_METABOX_PREFIX . 'location_postcode' => '50667',
					COMMONSBOOKING_METABOX_PREFIX . 'location_city'     => 'Köln',
				];
			},
			true,
			$this->managerId
		);
		Indexer::flush();
		Maintenance::syncPairs();
		Maintenance::refresh( null, 0 );
		Store::reset();
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		remove_filter( 'commonsbooking_disableCache', '__return_true' );
		parent::tearDown();
	}
}
