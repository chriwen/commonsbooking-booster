<?php

namespace CommonsBooking\Tests\Service\Availability;

use CommonsBooking\Model\Calendar;
use CommonsBooking\Model\Day;
use CommonsBooking\Service\Availability\AccessProfile;
use CommonsBooking\Service\Availability\AccessStore;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Calculator;
use CommonsBooking\Service\Availability\DayStatus;
use CommonsBooking\Service\Availability\Invalidator;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Reader;
use CommonsBooking\Service\Availability\Schema;
use CommonsBooking\Service\Availability\SlotReader;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Pairs with role limited timeframes are stored per access profile and give the same result as the live calculation
 * for every user.
 *
 * Pair under test: days -10..9 open to everybody, 10..19 only editors, 20..29 only authors.
 */
class AccessProfileTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	private const LAST_DAY = 27;

	/** @var int[] */
	private array $restrictedTimeframeIds = [];

	/** @var array<string, int> user ids by role */
	private array $testUsers = [];

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );

		if ( ! Availability::isEnabled() ) {
			$this->markTestSkipped( 'Precomputed availability is disabled (CB_USE_INDEX=0 or CB_USE_AVAILABILITY=0).' );
		}

		$this->restrictedTimeframeIds = [
			$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -10 ), $this->day( 9, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' ),
			$this->createTimeframe( $this->locationId, $this->itemId, $this->day( 10 ), $this->day( 19, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' ),
			$this->createTimeframe( $this->locationId, $this->itemId, $this->day( 20 ), $this->day( 29, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' ),
		];
		update_post_meta( $this->restrictedTimeframeIds[1], \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES, [ 'editor' ] );
		update_post_meta( $this->restrictedTimeframeIds[2], \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES, [ 'author' ] );

		$this->createAdministrator();
		$this->testUsers = [
			'guest'         => 0,
			'subscriber'    => $this->createUser( 'subscriber' ),
			'editor'        => $this->createUser( 'editor' ),
			'author'        => $this->createUser( 'author' ),
			'author,editor' => $this->createUser( 'editor', 'author' ),
			'administrator' => $this->adminUserID,
		];

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::accessTable() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- rows of earlier tests
		Indexer::flush();
		Maintenance::syncPairs();
		Maintenance::refresh( null, 0 );
		Invalidator::reset();
		Store::reset();
		Calculator::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'commonsbooking_availability_access_profiles' );
		remove_all_filters( 'commonsbooking_availability_access_profiles_max' );
		parent::tearDown();
	}

	private function createUser( string $role, ?string $secondRole = null ): int {
		$login = 'ap_' . $role . ( $secondRole ? '_' . $secondRole : '' );
		$user  = get_user_by( 'login', $login );
		// users may survive from earlier tests
		$userId = $user ? $user->ID : wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.org',
			]
		);
		( new \WP_User( $userId ) )->set_role( $role );
		if ( $secondRole ) {
			( new \WP_User( $userId ) )->add_role( $secondRole );
		}

		return $userId;
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( date( 'Y-m-d', strtotime( "$offset days" ) ) . ' ' . $time );
	}

	private function date( int $offset ): string {
		return date( 'Y-m-d', strtotime( "$offset days" ) );
	}

	/**
	 * Status of the days 0..LAST_DAY for a user from the stored rows, asserted to be the same as live.
	 *
	 * @return array{0: array<string, string>, 1: int} statuses, number of computations for the stored answer
	 */
	private function statusesAs( string $user ): array {
		// reload the user, role changes are not seen by an already loaded current user
		wp_set_current_user( 0 );
		wp_set_current_user( $this->testUsers[ $user ] );
		Store::reset();
		Calculator::reset();
		$from         = $this->date( 0 );
		$to           = $this->date( self::LAST_DAY );
		$computations = Calculator::getComputeCount();
		$stored       = ( new Reader() )->getDayStatuses( $this->itemId, $this->locationId, $from, $to, false, $from, $to )['days'];
		$computations = Calculator::getComputeCount() - $computations;
		$live         = Reader::evaluateLive( $this->itemId, $this->locationId, $from, $to, false, $from, $to )['days'];
		$this->assertSame( $live, $stored, "stored and live availability differ for $user" );

		return [ $stored, $computations ];
	}

	/**
	 * @return string[] stored access profiles of the pair
	 */
	private function storedProfiles(): array {
		$profiles = array_map(
			fn( $row ) => $row[2],
			array_filter( AccessStore::getAll(), fn( $row ) => $row[0] === $this->itemId && $row[1] === $this->locationId )
		);
		sort( $profiles );

		return $profiles;
	}

	public function testAccessProfileOfUsers() {
		$this->assertSame( [ 'author', 'editor' ], AccessProfile::getRoles( $this->itemId, $this->locationId ) );
		foreach ( $this->testUsers as $expected => $userId ) {
			wp_set_current_user( $userId );
			$this->assertSame(
				in_array( $expected, [ 'guest', 'subscriber' ], true ) ? '' : ( $expected === 'administrator' ? 'author,editor' : $expected ),
				AccessProfile::forCurrentUser( $this->itemId, $this->locationId ),
				$expected
			);
		}
	}

	public function testRunAsRestoresTheUser() {
		wp_set_current_user( $this->testUsers['administrator'] );
		$inside = AccessProfile::runAs( 'editor', fn() => [ is_user_logged_in(), wp_get_current_user()->roles, commonsbooking_isCurrentUserAllowedToBook( $this->restrictedTimeframeIds[1] ), commonsbooking_isCurrentUserAllowedToBook( $this->restrictedTimeframeIds[2] ) ] );
		$this->assertSame( [ false, [ 'editor' ], true, false ], $inside );
		$this->assertSame( $this->testUsers['administrator'], get_current_user_id() );
	}

	public function testRebuildStoresNoRoleAndAllRoles() {
		$this->assertSame( [ '', 'author,editor' ], $this->storedProfiles() );
	}

	public function testEveryUserGetsTheLiveResult() {
		foreach ( array_keys( $this->testUsers ) as $user ) {
			[ $statuses ] = $this->statusesAs( $user );
			$this->assertSame( DayStatus::AVAILABLE, $statuses[ $this->date( 5 ) ], $user );
		}

		[ $guest ] = $this->statusesAs( 'guest' );
		$this->assertNotSame( DayStatus::AVAILABLE, $guest[ $this->date( 12 ) ] );
		$this->assertNotSame( DayStatus::AVAILABLE, $guest[ $this->date( 22 ) ] );

		[ $editor ] = $this->statusesAs( 'editor' );
		$this->assertSame( DayStatus::AVAILABLE, $editor[ $this->date( 12 ) ] );
		$this->assertNotSame( DayStatus::AVAILABLE, $editor[ $this->date( 22 ) ] );

		[ $admin ] = $this->statusesAs( 'administrator' );
		$this->assertSame( DayStatus::AVAILABLE, $admin[ $this->date( 12 ) ] );
		$this->assertSame( DayStatus::AVAILABLE, $admin[ $this->date( 22 ) ] );

		$this->assertSame( $admin, $this->statusesAs( 'author,editor' )[0] );
		$this->assertSame( $guest, $this->statusesAs( 'subscriber' )[0] );
	}

	public function testStoredAccessProfilesAreNotComputedAgain() {
		$this->assertSame( 0, $this->statusesAs( 'guest' )[1] );
		$this->assertSame( 0, $this->statusesAs( 'administrator' )[1] );

		// further access profiles are computed on the first request
		$this->assertSame( 1, $this->statusesAs( 'editor' )[1] );
		$this->assertSame( [ '', 'author,editor', 'editor' ], $this->storedProfiles() );
		$this->assertSame( 0, $this->statusesAs( 'editor' )[1] );
	}

	public function testChangeOfThePairInvalidatesAllAccessProfiles() {
		$this->statusesAs( 'editor' );
		$this->createBooking( $this->locationId, $this->itemId, $this->day( 12 ), $this->day( 12, '23:59:59' ) );
		Indexer::flush();
		Store::reset();
		$row = Store::get( $this->itemId, $this->locationId );
		foreach ( [ '', 'author,editor', 'editor' ] as $accessProfile ) {
			$this->assertFalse( AccessStore::isUsable( AccessStore::get( $this->itemId, $this->locationId, $accessProfile ), $row ), $accessProfile );
		}

		Invalidator::processQueue();
		$this->assertSame( 0, $this->statusesAs( 'administrator' )[1] );
		$this->assertSame( DayStatus::BOOKED, $this->statusesAs( 'administrator' )[0][ $this->date( 12 ) ] );
		$this->assertSame( 1, $this->statusesAs( 'editor' )[1] );
		$this->assertSame( DayStatus::BOOKED, $this->statusesAs( 'editor' )[0][ $this->date( 12 ) ] );
	}

	public function testChangingTheAllowedRolesInvalidates() {
		update_post_meta( $this->restrictedTimeframeIds[2], \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES, [ 'author', 'subscriber' ] );
		Indexer::flush();
		Invalidator::processQueue();

		$this->assertSame( [ 'author', 'editor', 'subscriber' ], AccessProfile::getRoles( $this->itemId, $this->locationId ) );
		[ $subscriber ] = $this->statusesAs( 'subscriber' );
		$this->assertSame( DayStatus::AVAILABLE, $subscriber[ $this->date( 22 ) ] );
		$this->assertNotSame( DayStatus::AVAILABLE, $this->statusesAs( 'guest' )[0][ $this->date( 22 ) ] );
	}

	public function testRoleChangeOfAUserNeedsNoInvalidation() {
		$this->assertNotSame( DayStatus::AVAILABLE, $this->statusesAs( 'editor' )[0][ $this->date( 22 ) ] );
		( new \WP_User( $this->testUsers['editor'] ) )->set_role( 'author' );
		[ $statuses ] = $this->statusesAs( 'editor' );
		$this->assertSame( DayStatus::AVAILABLE, $statuses[ $this->date( 22 ) ] );
		$this->assertNotSame( DayStatus::AVAILABLE, $statuses[ $this->date( 12 ) ] );
	}

	public function testMaximumPerPair() {
		add_filter( 'commonsbooking_availability_access_profiles_max', fn() => 2 );
		// beyond the maximum: computed live, not stored
		$this->assertSame( 0, $this->statusesAs( 'editor' )[1] );
		$this->assertSame( [ '', 'author,editor' ], $this->storedProfiles() );
		$this->assertSame( 0, $this->statusesAs( 'guest' )[1] );
	}

	public function testDisabledComputesLive() {
		add_filter( 'commonsbooking_availability_access_profiles', '__return_false' );
		foreach ( [ 'guest', 'editor', 'administrator' ] as $user ) {
			$this->assertSame( 0, $this->statusesAs( $user )[1] );
		}
		$this->assertSame( [ '', 'author,editor' ], $this->storedProfiles() );
	}

	public function testRemovingTheRestrictionsCleansUp() {
		delete_post_meta( $this->restrictedTimeframeIds[1], \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES );
		delete_post_meta( $this->restrictedTimeframeIds[2], \CommonsBooking\Model\Timeframe::META_ALLOWED_USER_ROLES );
		Indexer::flush();
		Invalidator::processQueue();

		Store::reset();
		$this->assertTrue( Store::isUsable( Store::get( $this->itemId, $this->locationId ) ) );
		$this->assertSame( 2, AccessStore::cleanup() );
		$this->assertSame( [], $this->storedProfiles() );
		$this->assertSame( DayStatus::AVAILABLE, $this->statusesAs( 'guest' )[0][ $this->date( 22 ) ] );
	}

	public function testCleanupRemovesUnusedAccessProfiles() {
		global $wpdb;
		$this->statusesAs( 'editor' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::accessTable() . " SET last_read = %s WHERE access_profile = 'editor'", $this->date( -31 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, AccessStore::cleanup() );
		$this->assertSame( [ '', 'author,editor' ], $this->storedProfiles() );

		// reading updates last_read
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::accessTable() . ' SET last_read = %s', $this->date( -31 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->statusesAs( 'guest' );
		$this->assertSame( 1, AccessStore::cleanup() );
		$this->assertSame( [ '' ], $this->storedProfiles() );
	}

	public function testVerifyRepairsAccessProfiles() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::accessTable() . " SET days = UNHEX(REPEAT('0000', 95)) WHERE access_profile = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = Maintenance::verify( null, true );
		$this->assertSame( [ $this->locationId . ':' . $this->itemId . ':' ], $result['mismatches'] );
		$this->assertSame( 0, $this->statusesAs( 'guest' )[1] );
		$this->assertSame( [], Maintenance::verify()['mismatches'] );
	}

	public function testApiSlotsPerAccessProfile() {
		$from = $this->date( 0 );
		$to   = $this->date( self::LAST_DAY );
		foreach ( [ 'guest', 'editor', 'author', 'administrator' ] as $user ) {
			wp_set_current_user( $this->testUsers[ $user ] );
			Store::reset();
			Calculator::reset();
			$stored = SlotReader::getAvailabilitySlots( $from, $to, [ $this->locationId ], [ $this->itemId ], false, false );
			$this->assertNotNull( $stored, "answered from the stored rows for $user" );

			$live     = ( new Calendar( new Day( $from ), new Day( $to ), [ $this->locationId ], [ $this->itemId ] ) )->getAvailabilitySlotsLive();
			$untilEnd = fn( array $slots ) => array_values( array_filter( $slots, fn( $slot ) => substr( $slot->start, 0, 10 ) <= $to ) );
			$this->assertEquals( $untilEnd( $live ), $untilEnd( $stored ), $user );
		}
	}
}
