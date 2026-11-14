<?php

namespace CommonsBooking\Tests\Service;

use CommonsBooking\Exception\BookingRuleException;
use CommonsBooking\Model\Booking;
use CommonsBooking\Service\BookingRule;
use CommonsBooking\Service\BookingRuleApplied;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Item;

/**
 * Fixes of the booking rules: values of one rule configuration no longer carry over to the next one, the reset day of
 * the weekly rules, and the category filter when counting the bookings of a user.
 */
class BookingRuleFixesTest extends CustomPostTypeTest {

	private int $termA;

	private int $termB;

	protected function setUp(): void {
		parent::setUp();
		$this->createSubscriber();
		$this->termA = $this->term( 'cat-a' );
		$this->termB = $this->term( 'cat-b' );
	}

	protected function tearDown(): void {
		delete_option( 'commonsbooking_options_restrictions' );
		parent::tearDown();
	}

	public function testCategoryRuleAfterRuleForAllKeepsItsCategories() {
		$this->setRules(
			[
				[
					'rule-type'         => 'maxBookingDaysPerWeek',
					'rule-param1'       => '3',
					'rule-select-param' => '2',
					'rule-applies-all'  => 'on',
				],
				[
					'rule-type'               => 'maxBookingDaysPerMonth',
					'rule-param1'             => '6',
					'rule-select-param'       => '1',
					'rule-applies-categories' => [ (string) $this->termA ],
				],
				[
					'rule-type'        => 'noSimultaneousBooking',
					'rule-applies-all' => 'on',
				],
			]
		);
		$rules = BookingRuleApplied::init();
		$this->assertCount( 3, $rules );
		$this->assertTrue( $this->property( $rules[0], 'appliesToAll' ) );
		$this->assertFalse( $this->property( $rules[1], 'appliesToAll' ), 'the category rule must not apply to all items' );
		$this->assertSame( [ (string) $this->termA ], $this->property( $rules[1], 'appliedTerms' ) );
		$this->assertTrue( $this->property( $rules[2], 'appliesToAll' ) );
		$this->assertSame( [], $this->property( $rules[2], 'appliedTerms' ) );
	}

	public function testMissingSelectParamIsNotTakenFromPreviousRule() {
		$this->setRules(
			[
				[
					'rule-type'         => 'maxBookingDaysPerWeek',
					'rule-param1'       => '3',
					'rule-select-param' => '2',
					'rule-applies-all'  => 'on',
				],
				[
					'rule-type'        => 'maxBookingsWeek',
					'rule-param1'      => '2',
					'rule-applies-all' => 'on',
				],
			]
		);
		$this->expectException( BookingRuleException::class );
		BookingRuleApplied::init();
	}

	public function testWeekResetDay() {
		$resetDay = new \ReflectionMethod( BookingRule::class, 'getWeekResetDay' );
		$resetDay->setAccessible( true );
		foreach ( [
			1 => 1,
			2 => 2,
			6 => 6,
			7 => 7,
			'7' => 7,
			0 => 1,
			8 => 1,
		] as $value => $expected ) {
			$this->assertSame( $expected, $resetDay->invoke( null, [ 2, null, $value ] ), "select value $value" );
		}
		$this->assertSame( 1, $resetDay->invoke( null, [ 2, null, null ] ) );

		// a booking on a Wednesday: the week starts on the last reset day
		$wednesday = strtotime( 'next wednesday', strtotime( self::CURRENT_DATE ) );
		$booking   = new Booking( $this->createBooking( $this->locationId, $this->itemId, $wednesday, $wednesday + DAY_IN_SECONDS - 1 ) );
		$weekRange = new \ReflectionMethod( BookingRule::class, 'getBookingWeekRange' );
		$weekRange->setAccessible( true );
		foreach ( [ 1, 2, 6, 7 ] as $value ) {
			[ $start, $end ] = $weekRange->invoke( null, $booking, $resetDay->invoke( null, [ 2, null, $value ] ) );
			$this->assertSame( $value - 1, (int) $start->format( 'w' ), "start of week for select value $value" );
			$this->assertSame( $value - 1, (int) $end->format( 'w' ), "end of week for select value $value" );
		}
	}

	public function testWeekMessagesNameTheResetDay() {
		$this->assertStringContainsString( 'Monday', BookingRule::maxBookingsWeekErrorMessage( [ 2, null, 2 ] ) );
		$this->assertStringNotContainsString( 'Tuesday', BookingRule::maxBookingsWeekErrorMessage( [ 2, null, 2 ] ) );
		$this->assertStringContainsString( 'Saturday', BookingRule::maxBookingsWeekErrorMessage( [ 2, null, 7 ] ) );
		$this->assertStringContainsString( 'Monday', BookingRule::maxDaysWeekErrorMessage( [ 3, null, 2 ] ) );
		$this->assertStringContainsString( 'Saturday', BookingRule::maxDaysWeekErrorMessage( [ 3, null, 7 ] ) );
	}

	public function testOnlyBookingsOfTheRuleCategoriesCount() {
		$itemA = $this->createItem( 'Item A' );
		$itemB = $this->createItem( 'Item B' );
		wp_set_post_terms( $itemA, [ $this->termA ], Item::getTaxonomyName() );
		wp_set_post_terms( $itemB, [ $this->termB ], Item::getTaxonomyName() );
		$other = wp_create_user( 'otheruser', 'other', 'other@example.com' );
		$day   = strtotime( '+1 day', strtotime( self::CURRENT_DATE ) );

		$userA  = $this->createBooking( $this->locationId, $itemA, $day, $day + DAY_IN_SECONDS - 1, '12:00 AM', '23:59', 'confirmed', $this->subscriberId );
		$userB  = $this->createBooking( $this->locationId, $itemB, $day + DAY_IN_SECONDS, $day + 2 * DAY_IN_SECONDS - 1, '12:00 AM', '23:59', 'confirmed', $this->subscriberId );
		$otherA = $this->createBooking( $this->locationId, $itemA, $day + 2 * DAY_IN_SECONDS, $day + 3 * DAY_IN_SECONDS - 1, '12:00 AM', '23:59', 'confirmed', $other );

		$bookings = array_map( fn( $id ) => new Booking( $id ), [ $userA, $userB, $otherA ] );
		$user     = get_user_by( 'id', $this->subscriberId );
		$filter   = new \ReflectionMethod( BookingRule::class, 'filterBookingsForTermsAndUser' );
		$filter->setAccessible( true );
		$ids = fn( $result ) => $result === null ? null : array_values( array_map( fn( $booking ) => $booking->ID, $result ) );

		$this->assertSame( [ $userA ], $ids( $filter->invoke( null, $bookings, $user, [ $this->termA ] ) ) );
		$this->assertSame( [ $userB ], $ids( $filter->invoke( null, $bookings, $user, [ $this->termB ] ) ) );
		$this->assertSame( [ $userA, $userB ], $ids( $filter->invoke( null, $bookings, $user, false ) ) );
		// only the other user booked the category
		$this->assertNull( $filter->invoke( null, [ $bookings[1], $bookings[2] ], $user, [ $this->termA ] ) );
	}

	private function term( string $slug ): int {
		$term = get_term_by( 'slug', $slug, Item::getTaxonomyName() );

		return $term ? $term->term_id : wp_insert_term( $slug, Item::getTaxonomyName() )['term_id'];
	}

	private function setRules( array $rules ): void {
		update_option( 'commonsbooking_options_restrictions', [ 'rules_group' => $rules ] );
	}

	/**
	 * @return mixed
	 */
	private function property( object $object, string $name ) {
		$property = new \ReflectionProperty( $object, $name );
		$property->setAccessible( true );

		return $property->getValue( $object );
	}
}
