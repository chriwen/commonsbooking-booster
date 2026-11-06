<?php

namespace CommonsBooking\Tests\Service\Index;

use CommonsBooking\Repository\Index;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Compares the results of the index based queries with the legacy postmeta queries
 * of {@see \CommonsBooking\Repository\Timeframe} for a broad data set.
 */
class TimeframeIndexParityTest extends CustomPostTypeTest {

	private int $secondItemId;
	private int $secondLocationId;
	private int $start;

	/**
	 * Runs the callback once with the legacy queries and once with the index and returns both id lists.
	 *
	 * @param callable $query returns posts / models
	 *
	 * @return array{0: int[], 1: int[]} [legacy ids, index ids], sorted
	 */
	private function both( callable $query ): array {
		$results = [];
		foreach ( [ false, true ] as $useIndex ) {
			$filter = $useIndex ? '__return_true' : '__return_false';
			add_filter( 'commonsbooking_use_index', $filter, 99 );
			$this->assertSame( $useIndex, Index::isAvailable() );
			\CommonsBooking\Plugin::clearCache();
			$ids = array_map( fn( $post ) => (int) $post->ID, $query() );
			sort( $ids );
			$results[] = $ids;
			remove_filter( 'commonsbooking_use_index', $filter, 99 );
		}

		return $results;
	}

	/**
	 * Number of compared queries with a non-empty result, to make sure the comparisons are not vacuous.
	 */
	private int $nonEmpty = 0;

	private function assertParity( string $label, callable $query ): void {
		list( $legacy, $index ) = $this->both( $query );
		$this->assertSame( $legacy, $index, "Index result differs from legacy result for: $label" );
		if ( $legacy ) {
			++$this->nonEmpty;
		}
	}

	public function testGetParity() {
		$day        = 86400;
		$typeSets   = [
			[],
			[ Timeframe::BOOKABLE_ID ],
			[ Timeframe::BOOKING_ID ],
			[ Timeframe::HOLIDAYS_ID ],
			[ Timeframe::REPAIR_ID, Timeframe::BOOKING_ID ],
		];
		$entitySets = [
			'none'                 => [ [], [] ],
			'item'                 => [ [], [ $this->itemId ] ],
			'location'             => [ [ $this->locationId ], [] ],
			'item+location'        => [ [ $this->locationId ], [ $this->itemId ] ],
			'second item+location' => [ [ $this->secondLocationId ], [ $this->secondItemId ] ],
			'both items'           => [ [], [ $this->itemId, $this->secondItemId ] ],
		];
		$dateSets   = [
			'no date'          => [ null, null ],
			'day in the past'  => [ date( 'Y-m-d', $this->start - 30 * $day ), null ],
			'first day'        => [ date( 'Y-m-d', $this->start ), null ],
			'day +5'           => [ date( 'Y-m-d', $this->start + 5 * $day ), null ],
			'day +40'          => [ date( 'Y-m-d', $this->start + 40 * $day ), null ],
			'min timestamp'    => [ null, $this->start + 3 * $day + 3600 ],
			'date and min'     => [ date( 'Y-m-d', $this->start + 5 * $day ), $this->start ],
		];
		$statusSets = [
			[ 'confirmed', 'unconfirmed', 'publish', 'inherit' ],
			[ 'publish', 'confirmed' ],
			[ 'canceled' ],
			[ 'draft' ],
		];

		$cases = 0;
		foreach ( $typeSets as $types ) {
			foreach ( $entitySets as $entityLabel => list( $locations, $items ) ) {
				foreach ( $dateSets as $dateLabel => list( $date, $min ) ) {
					foreach ( $statusSets as $statuses ) {
						$label = sprintf( 'get(types=%s, %s, %s, status=%s)', implode( ',', $types ), $entityLabel, $dateLabel, implode( ',', $statuses ) );
						$this->assertParity(
							$label,
							fn() => \CommonsBooking\Repository\Timeframe::get( $locations, $items, $types, $date, false, $min, $statuses )
						);
						++$cases;
					}
				}
			}
		}
		$this->assertGreaterThan( 500, $cases );
		$this->assertGreaterThan( 200, $this->nonEmpty, 'too few non-empty results' );
	}

	/**
	 * Known, intended deviation: the legacy SQL compares meta_value (string) with IN (0) for invalid ids,
	 * MySQL casts '' to 0, so timeframes with an empty item-id meta matched. The index matches nothing.
	 */
	public function testInvalidItemIdMatchesNothingInIndex() {
		list( $legacy, $index ) = $this->both( fn() => \CommonsBooking\Repository\Timeframe::get( [], [ 'abc' ] ) );
		$this->assertNotEmpty( $legacy, 'legacy quirk: empty item-id meta matches IN (0)' );
		$this->assertSame( [], $index );
	}

	/**
	 * Known, intended deviation: a stored but empty repetition-end means "open end" in the index (like
	 * Model\Timeframe::getTimeframeEndDate()). The legacy SQL excluded such timeframes from date queries,
	 * because the meta key exists but its value is not in the range.
	 */
	public function testEmptyEndMetaMeansOpenEndInIndex() {
		$timeframeId = $this->createTimeframe( $this->secondLocationId, $this->secondItemId, $this->start - 100 * 86400, null );
		update_post_meta( $timeframeId, \CommonsBooking\Model\Timeframe::REPETITION_END, '' );
		$date = date( 'Y-m-d', $this->start - 90 * 86400 );

		list( $legacy, $index ) = $this->both( fn() => \CommonsBooking\Repository\Timeframe::get( [ $this->secondLocationId ], [ $this->secondItemId ], [ Timeframe::BOOKABLE_ID ], $date, false, null, [ 'publish' ] ) );
		$this->assertNotContains( $timeframeId, $legacy );
		$this->assertContains( $timeframeId, $index );
	}

	public function testGetInRangeParity() {
		$day        = 86400;
		$ranges     = [
			'past'          => [ $this->start - 60 * $day, $this->start - 20 * $day ],
			'first week'    => [ $this->start, $this->start + 7 * $day ],
			'two months'    => [ $this->start, $this->start + 60 * $day ],
			'later'         => [ $this->start + 30 * $day, $this->start + 90 * $day ],
		];
		$entitySets = [
			'none'          => [ [], [] ],
			'item'          => [ [], [ $this->itemId ] ],
			'item+location' => [ [ $this->locationId ], [ $this->itemId ] ],
			'second'        => [ [ $this->secondLocationId ], [ $this->secondItemId ] ],
		];
		foreach ( $ranges as $rangeLabel => list( $min, $max ) ) {
			foreach ( $entitySets as $entityLabel => list( $locations, $items ) ) {
				foreach ( [ [], [ Timeframe::BOOKABLE_ID ], [ Timeframe::BOOKING_ID ] ] as $types ) {
					foreach ( [ [ 'confirmed', 'publish' ], [ 'confirmed', 'unconfirmed', 'publish', 'inherit' ] ] as $statuses ) {
						$this->assertParity(
							"getInRange($rangeLabel, $entityLabel, types=" . implode( ',', $types ) . ', status=' . implode( ',', $statuses ) . ')',
							fn() => \CommonsBooking\Repository\Timeframe::getInRange( $min, $max, $locations, $items, $types, false, $statuses )
						);
					}
				}
			}
		}
		$this->assertGreaterThan( 40, $this->nonEmpty, 'too few non-empty results' );
	}

	public function testBookableForCurrentUserParity() {
		$this->assertParity(
			'getBookableForCurrentUser(item, location)',
			fn() => \CommonsBooking\Repository\Timeframe::getBookableForCurrentUser( [ $this->locationId ], [ $this->itemId ], null, true, $this->start )
		);
		$this->assertParity(
			'getBookable(all)',
			fn() => \CommonsBooking\Repository\Timeframe::getBookable()
		);
	}

	public function testBookingRepositoryParity() {
		$day = 86400;
		foreach ( [ -40, -5, 0, 1, 2, 5, 6, 8, 12, 35, 60 ] as $offset ) {
			$ts = $this->start + $offset * $day;
			$this->assertParity( "getBeginningBookingsByDate(+$offset)", fn() => \CommonsBooking\Repository\Booking::getBeginningBookingsByDate( $ts ) );
			$this->assertParity( "getEndingBookingsByDate(+$offset)", fn() => \CommonsBooking\Repository\Booking::getEndingBookingsByDate( $ts ) );
		}

		$ranges     = [
			[ $this->start - 60 * $day, $this->start ],
			[ $this->start, $this->start + 3 * $day ],
			[ $this->start + 5 * $day, $this->start + 9 * $day ],
			[ $this->start, $this->start + 90 * $day ],
		];
		$statusSets = [ [ 'confirmed', 'unconfirmed' ], [ 'confirmed' ], [ 'confirmed', 'canceled' ] ];
		foreach ( $ranges as list( $from, $to ) ) {
			foreach ( $statusSets as $statuses ) {
				foreach ( [ [ null, null ], [ $this->locationId, $this->itemId ], [ $this->secondLocationId, $this->secondItemId ], [ null, $this->itemId ] ] as list( $location, $item ) ) {
					$label = "getByTimerange($from-$to, $location, $item, " . implode( ',', $statuses ) . ')';
					$this->assertParity( $label, fn() => \CommonsBooking\Repository\Booking::getByTimerange( $from, $to, $location, $item, [], $statuses ) );
				}
				$this->assertParity( 'getByTimerange(author)', fn() => \CommonsBooking\Repository\Booking::getByTimerange( $from, $to, null, null, [ 'author' => $this->subscriberId ], $statuses ) );
			}
		}

		// order must follow WP_Query (post_date) for orderby=date
		list( $legacy, $index ) = $this->bothOrdered(
			fn() => \CommonsBooking\Repository\Booking::getByTimerange(
				$this->start - 60 * $day,
				$this->start + 90 * $day,
				null,
				null,
				[
					'orderby' => 'date',
					'order' => 'ASC',
				],
				[ 'confirmed' ]
			)
		);
		$this->assertSame( $legacy, $index, 'order of getByTimerange with orderby=date ASC' );

		// existing booking lookup used by the booking form
		$this->assertParity(
			'getExistingBookings',
			fn() => \CommonsBooking\Repository\Booking::getExistingBookings( $this->itemId, $this->locationId, $this->start, $this->start + 10 * $day )
		);
		foreach ( [ 0, 6, 8 ] as $offset ) {
			$from = $this->start + $offset * $day;
			$this->assertParity(
				"getByDate(+$offset)",
				function () use ( $from, $day ) {
					$booking = \CommonsBooking\Repository\Booking::getByDate( $from, $from + $day - 1, $this->locationId, $this->itemId );
					return $booking ? [ $booking ] : [];
				}
			);
		}
		$this->assertGreaterThan( 40, $this->nonEmpty, 'too few non-empty results' );
	}

	public function testRestrictionRepositoryParity() {
		$day  = 86400;
		$sets = [
			'none'          => [ [], [] ],
			'location'      => [ [ $this->locationId ], [] ],
			'item'          => [ [], [ $this->itemId ] ],
			'item+location' => [ [ $this->locationId ], [ $this->itemId ] ],
			'second'        => [ [ $this->secondLocationId ], [ $this->secondItemId ] ],
		];
		foreach ( $sets as $label => list( $locations, $items ) ) {
			foreach ( [ -20, 0, 2, 4, 10, 40 ] as $offset ) {
				$date = date( 'Y-m-d', $this->start + $offset * $day );
				$this->assertParity( "Restriction::get($label, date +$offset)", fn() => \CommonsBooking\Repository\Restriction::get( $locations, $items, $date, true, null, [ 'publish', 'confirmed', 'unconfirmed' ] ) );
				$min = $this->start + $offset * $day;
				$this->assertParity( "Restriction::get($label, min +$offset)", fn() => \CommonsBooking\Repository\Restriction::get( $locations, $items, null, true, $min ) );
			}
			$this->assertParity( "Restriction::get($label)", fn() => \CommonsBooking\Repository\Restriction::get( $locations, $items ) );
		}
		$this->assertGreaterThan( 30, $this->nonEmpty, 'too few non-empty results' );
	}

	/**
	 * Days of a calendar get the restrictions of the whole range preloaded and filter them themselves.
	 * The result must equal the per-day query, with and without index.
	 */
	public function testPreloadedDayRestrictionsEqualPerDayQuery() {
		$day = 86400;
		foreach ( [ '__return_false', '__return_true' ] as $filter ) {
			add_filter( 'commonsbooking_use_index', $filter, 99 );
			foreach ( [ [ [ $this->locationId ], [ $this->itemId ] ], [ [ $this->secondLocationId ], [ $this->secondItemId ] ], [ [ $this->locationId ], [ $this->secondItemId ] ] ] as list( $locations, $items ) ) {
				$rangeStart = new \CommonsBooking\Model\Day( date( 'Y-m-d', $this->start - 35 * $day ) );
				$preloaded  = \CommonsBooking\Repository\Restriction::get( $locations, $items, null, true, $rangeStart->getStartTimestamp() - 1, \CommonsBooking\Model\Day::RESTRICTION_STATUSES );
				for ( $offset = -35; $offset <= 50; $offset++ ) {
					$date      = date( 'Y-m-d', $this->start + $offset * $day );
					$perDay    = ( new \CommonsBooking\Model\Day( $date, $locations, $items ) )->getRestrictions();
					$fromRange = ( new \CommonsBooking\Model\Day( $date, $locations, $items, [], [], $preloaded ) )->getRestrictions();
					$ids       = fn( $restrictions ) => array_map( fn( $r ) => $r->ID, $restrictions );
					$this->assertEqualsCanonicalizing( $ids( $perDay ), $ids( $fromRange ), "restrictions on $date ($filter)" );
					if ( $perDay ) {
						++$this->nonEmpty;
					}
				}
			}
			remove_filter( 'commonsbooking_use_index', $filter, 99 );
		}
		$this->assertGreaterThan( 50, $this->nonEmpty, 'too few non-empty results' );
	}

	public function testRelatedPostsParity() {
		foreach ( [ $this->locationId, $this->secondLocationId ] as $locationId ) {
			list( $legacy, $index ) = $this->bothOrdered( fn() => \CommonsBooking\Repository\Item::getByLocation( $locationId ) );
			$this->assertSame( $legacy, $index, "Item::getByLocation($locationId)" );
			list( $legacy, $index ) = $this->bothOrdered( fn() => \CommonsBooking\Repository\Item::getByLocation( $locationId, true ) );
			$this->assertSame( $legacy, $index, "Item::getByLocation($locationId, bookable)" );
			if ( $legacy ) {
				++$this->nonEmpty;
			}
		}
		foreach ( [ $this->itemId, $this->secondItemId ] as $itemId ) {
			list( $legacy, $index ) = $this->bothOrdered( fn() => \CommonsBooking\Repository\Location::getByItem( $itemId ) );
			$this->assertSame( $legacy, $index, "Location::getByItem($itemId)" );
			list( $legacy, $index ) = $this->bothOrdered( fn() => \CommonsBooking\Repository\Location::getByItem( $itemId, true ) );
			$this->assertSame( $legacy, $index, "Location::getByItem($itemId, bookable)" );
			if ( $legacy ) {
				++$this->nonEmpty;
			}
		}
		$this->assertGreaterThanOrEqual( 2, $this->nonEmpty, 'too few non-empty results' );
	}

	/**
	 * Map::getBookableTimeframesByPair() loads all pairs at once, it must equal the per-pair query.
	 */
	public function testBookableTimeframesByPairEqualsPerPairQuery() {
		$locations = [ $this->locationId, $this->secondLocationId ];
		$items     = [ $this->itemId, $this->secondItemId ];
		foreach ( [ '__return_false', '__return_true' ] as $filter ) {
			add_filter( 'commonsbooking_use_index', $filter, 99 );
			$byPair = \CommonsBooking\Model\Map::getBookableTimeframesByPair( $locations, $items );
			foreach ( $locations as $locationId ) {
				foreach ( $items as $itemId ) {
					$perPair = \CommonsBooking\Repository\Timeframe::getBookableForCurrentUser( [ $locationId ], [ $itemId ], null, true );
					$ids     = fn( $timeframes ) => array_map( fn( $t ) => $t->ID, $timeframes );
					$this->assertEqualsCanonicalizing( $ids( $perPair ), $ids( $byPair[ $locationId ][ $itemId ] ?? [] ), "pair $locationId/$itemId ($filter)" );
					if ( $perPair ) {
						++$this->nonEmpty;
					}
				}
			}
			remove_filter( 'commonsbooking_use_index', $filter, 99 );
		}
		$this->assertGreaterThan( 2, $this->nonEmpty, 'too few non-empty results' );
	}

	/**
	 * The complete map data must not depend on the index.
	 */
	public function testMapLocationsParity() {
		update_post_meta( $this->locationId, 'geo_latitude', 50.1 );
		update_post_meta( $this->locationId, 'geo_longitude', 8.1 );
		update_post_meta( $this->secondLocationId, 'geo_latitude', 50.2 );
		update_post_meta( $this->secondLocationId, 'geo_longitude', 8.2 );
		foreach ( [ $this->locationId, $this->secondLocationId ] as $locationId ) {
			update_post_meta( $locationId, COMMONSBOOKING_METABOX_PREFIX . 'location_street', 'Street 1' );
			update_post_meta( $locationId, COMMONSBOOKING_METABOX_PREFIX . 'location_city', 'City' );
			update_post_meta( $locationId, COMMONSBOOKING_METABOX_PREFIX . 'location_postcode', '12345' );
		}
		$mapId = $this->createMap();

		$results = [];
		foreach ( [ '__return_false', '__return_true' ] as $filter ) {
			add_filter( 'commonsbooking_use_index', $filter, 99 );
			\CommonsBooking\Plugin::clearCache();
			$results[] = ( new \CommonsBooking\Model\Map( $mapId ) )->get_locations( [] );
			remove_filter( 'commonsbooking_use_index', $filter, 99 );
		}
		$this->assertNotEmpty( $results[0] );
		$this->assertEquals( $results[0], $results[1] );
	}

	/**
	 * Like {@see self::both()}, but keeps the order of the results.
	 */
	private function bothOrdered( callable $query ): array {
		$results = [];
		foreach ( [ '__return_false', '__return_true' ] as $filter ) {
			add_filter( 'commonsbooking_use_index', $filter, 99 );
			\CommonsBooking\Plugin::clearCache();
			$results[] = array_values( array_map( fn( $post ) => (int) $post->ID, $query() ) );
			remove_filter( 'commonsbooking_use_index', $filter, 99 );
		}

		return $results;
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! \CommonsBooking\Service\Booster\Booster::isEnabled() ) {
			$this->markTestSkipped( 'Performance booster is switched off (CB_USE_BOOSTER=0), the index is not maintained.' );
		}
		add_filter( 'commonsbooking_disableCache', '__return_true' );

		$day                    = 86400;
		$this->start            = strtotime( self::CURRENT_DATE );
		$this->secondItemId     = $this->createItem( 'Second item' );
		$this->secondLocationId = $this->createLocation( 'Second location' );

		// bookable: closed range, open end, other pair, draft, manual repetition, weekly with few weekdays
		$this->createTimeframe( $this->locationId, $this->itemId, $this->start, $this->start + 30 * $day );
		$this->createTimeframe( $this->locationId, $this->itemId, $this->start + 31 * $day, null );
		$this->createTimeframe( $this->secondLocationId, $this->secondItemId, $this->start - 10 * $day, $this->start + 10 * $day );
		$this->createTimeframe( $this->secondLocationId, $this->itemId, $this->start, $this->start + 5 * $day, Timeframe::BOOKABLE_ID, 'on', 'w', 0, '8:00 AM', '12:00 PM', 'draft' );
		$this->createTimeframe(
			$this->secondLocationId,
			$this->secondItemId,
			$this->start + 40 * $day,
			$this->start + 50 * $day,
			Timeframe::BOOKABLE_ID,
			'on',
			'manual',
			0,
			'8:00 AM',
			'12:00 PM',
			'publish',
			[],
			date( 'Y-m-d', $this->start + 40 * $day ) . ',' . date( 'Y-m-d', $this->start + 45 * $day )
		);
		$this->createTimeframe( $this->locationId, $this->secondItemId, $this->start, $this->start + 20 * $day, Timeframe::BOOKABLE_ID, 'on', 'w', 0, '8:00 AM', '12:00 PM', 'publish', [ '6', '7' ] );

		// repair and holidays (one with all items and locations, i.e. multi selection)
		$this->createTimeframe( $this->locationId, $this->itemId, $this->start + 3 * $day, $this->start + 4 * $day, Timeframe::REPAIR_ID );
		$this->createHolidayTimeframeForAllItemsAndLocations();
		$this->createTimeframe( [ $this->locationId, $this->secondLocationId ], [ $this->itemId, $this->secondItemId ], $this->start + 7 * $day, $this->start + 8 * $day, Timeframe::HOLIDAYS_ID );

		// bookings: past, current, future; confirmed, unconfirmed, canceled; both pairs
		foreach ( [ -40, -5, 0, 2, 5, 12, 35, 60 ] as $offset ) {
			$bookingStart = $this->start + $offset * $day;
			$this->createBooking( $this->locationId, $this->itemId, $bookingStart, $bookingStart + $day - 1 );
		}
		$this->createBooking( $this->locationId, $this->itemId, $this->start + 6 * $day, $this->start + 7 * $day - 1, '12:00 AM', '23:59', 'unconfirmed' );
		$this->createBooking( $this->locationId, $this->itemId, $this->start + 8 * $day, $this->start + 9 * $day - 1, '12:00 AM', '23:59', 'canceled' );
		$this->createBooking( $this->secondLocationId, $this->secondItemId, $this->start + 1 * $day, $this->start + 3 * $day - 1 );
		$this->createSubscriber();
		$this->createBooking( $this->locationId, $this->secondItemId, $this->start + 2 * $day, $this->start + 3 * $day - 1, '12:00 AM', '23:59', 'confirmed', $this->subscriberId );

		// restrictions: item+location, location only (all items), item only (all locations), open end, solved, draft
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, $this->start + 2 * $day, $this->start + 4 * $day );
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_HINT, $this->locationId, 0, $this->start, $this->start + 10 * $day );
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_REPAIR, 0, $this->secondItemId, $this->start - 30 * $day, null );
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_REPAIR, $this->secondLocationId, $this->secondItemId, $this->start, $this->start + 5 * $day, \CommonsBooking\Model\Restriction::STATE_SOLVED );
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, $this->start, $this->start + 5 * $day, 'active', 'Hint', self::USER_ID, 'Draft', 'draft' );
		$this->createRestriction( \CommonsBooking\Model\Restriction::TYPE_HINT, 0, 0, $this->start + 35 * $day, $this->start + 45 * $day );
	}

	protected function tearDown(): void {
		remove_filter( 'commonsbooking_disableCache', '__return_true' );
		parent::tearDown();
	}
}
