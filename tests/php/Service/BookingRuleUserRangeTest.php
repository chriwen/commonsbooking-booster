<?php

namespace CommonsBooking\Tests\Service;

use CommonsBooking\Model\Booking;
use CommonsBooking\Service\BookingRule;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Item;

/**
 * The booking rules with a range (days / bookings per week, month, period) query the bookings of the user first. The
 * result is the same as before, when all bookings in the range were loaded and filtered for terms and user.
 */
class BookingRuleUserRangeTest extends CustomPostTypeTest {

	private int $otherUserId;

	private int $termA;

	private int $termB;

	private int $termUnused;

	private Booking $probe;

	/**
	 * @var int[]
	 */
	private array $days;

	protected function setUp(): void {
		parent::setUp();
		$this->createSubscriber();
		$this->otherUserId = wp_create_user( 'otheruser', 'other', 'other@example.com' );

		$this->termA      = wp_insert_term( 'cat-a', Item::getTaxonomyName() )['term_id'];
		$this->termB      = wp_insert_term( 'cat-b', Item::getTaxonomyName() )['term_id'];
		$this->termUnused = wp_insert_term( 'cat-unused', Item::getTaxonomyName() )['term_id'];
		$itemA            = $this->createItem( 'Item A' );
		$itemB            = $this->createItem( 'Item B' );
		wp_set_post_terms( $itemA, [ $this->termA ], Item::getTaxonomyName() );
		wp_set_post_terms( $itemB, [ $this->termB ], Item::getTaxonomyName() );

		$day        = strtotime( self::CURRENT_DATE );
		$this->days = array_map( fn( $offset ) => strtotime( "+$offset days", $day ), range( 0, 12 ) );
		$d          = $this->days;
		$user       = $this->subscriberId;
		$other      = $this->otherUserId;
		$loc        = $this->locationId;

		$this->createBooking( $loc, $itemB, $d[1], $d[2] - 1, '12:00 AM', '23:59', 'confirmed', $user );
		$this->createBooking( $loc, $this->itemId, $d[3], $d[4] - 1, '12:00 AM', '23:59', 'confirmed', $user );
		$this->createBooking( $loc, $itemB, $d[5], $d[6] - 1, '12:00 AM', '23:59', 'canceled', $user );
		$this->createBooking( $loc, $itemA, $d[2], $d[3] - 1, '12:00 AM', '23:59', 'confirmed', $other );
		$this->createBooking( $loc, $itemA, $d[9], $d[10] - 1, '12:00 AM', '23:59', 'confirmed', $other );
		$this->createBooking( $loc, $itemB, $d[10], $d[11] - 1, '12:00 AM', '23:59', 'confirmed', $user );

		$this->probe = new Booking( $this->createBooking( $loc, $itemA, $d[7], $d[8] - 1, '12:00 AM', '23:59', 'unconfirmed', $user ) );
	}

	public function testSameBookingsAsFilteringAllBookings() {
		$d        = $this->days;
		$ranges   = [ [ $d[0], $d[12] ], [ $d[0], $d[2] ], [ $d[2], $d[4] ], [ $d[6], $d[9] ], [ $d[9], $d[12] ], [ $d[11], $d[12] ] ];
		$termSets = [ false, [], [ $this->termA ], [ $this->termB ], [ $this->termUnused ], [ $this->termA, $this->termB ] ];
		$statuses = [ [ 'confirmed' ], [ 'confirmed', 'canceled' ] ];

		$found = 0;
		foreach ( [ true, false ] as $bypass ) {
			foreach ( $ranges as [ $start, $end ] ) {
				foreach ( $termSets as $terms ) {
					foreach ( $statuses as $status ) {
						$run      = fn( $fn ) => $bypass ? Booster::withoutBooster( $fn ) : $fn();
						$expected = $run( fn() => $this->formerResult( $start, $end, $terms, $status ) );
						$actual   = $run( fn() => $this->newResult( $start, $end, $terms, $status ) );
						$this->assertSame(
							$expected,
							$actual,
							sprintf( 'range %s-%s, terms %s, %s, bypass %d', $start, $end, wp_json_encode( $terms ), implode( ',', $status ), $bypass )
						);
						$found += count( $expected ?? [] );
					}
				}
			}
		}
		$this->assertGreaterThan( 20, $found, 'the cases find bookings at all' );
	}

	/**
	 * The former query of checkBookingRangeForDays() / checkBookingAmount().
	 *
	 * @return int[]|null
	 */
	private function formerResult( int $start, int $end, $terms, array $status ): ?array {
		$all    = \CommonsBooking\Repository\Booking::getByTimerange(
			$start,
			$end,
			null,
			null,
			[
				'orderby' => 'date',
				'order'   => 'ASC',
			],
			$status
		);
		$filter = new \ReflectionMethod( BookingRule::class, 'filterBookingsForTermsAndUser' );
		$filter->setAccessible( true );

		return $this->ids( $filter->invoke( null, $all, $this->probe->getUserData(), $terms ) );
	}

	/**
	 * @return int[]|null
	 */
	private function newResult( int $start, int $end, $terms, array $status ): ?array {
		$method = new \ReflectionMethod( BookingRule::class, 'getUserBookingsInRange' );
		$method->setAccessible( true );

		return $this->ids( $method->invoke( null, new \DateTime( '@' . $start ), new \DateTime( '@' . $end ), $this->probe, $terms, $status ) );
	}

	/**
	 * @param Booking[]|null $bookings
	 *
	 * @return int[]|null
	 */
	private function ids( ?array $bookings ): ?array {
		if ( empty( $bookings ) ) {
			return null; // the callers treat null and [] the same
		}
		$ids = array_map( fn( $booking ) => $booking->ID, array_values( $bookings ) );
		sort( $ids );

		return $ids;
	}
}
