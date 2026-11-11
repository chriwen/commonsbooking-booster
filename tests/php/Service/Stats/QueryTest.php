<?php

namespace CommonsBooking\Tests\Service\Stats;

use CommonsBooking\Service\Stats\Bookings;
use CommonsBooking\Service\Stats\Calculator;
use CommonsBooking\Service\Stats\Filters;
use CommonsBooking\Service\Stats\Query;
use CommonsBooking\Service\Stats\Schema;
use CommonsBooking\Service\Stats\Stats;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Admin\Stats\Chart;
use CommonsBooking\View\Admin\Stats\Export;
use CommonsBooking\View\Admin\Stats\Page;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Filters, queries, charts and CSV export of the dashboard.
 */
class QueryTest extends CustomPostTypeTest {

	private const NOW = '01.07.2021 10:30';

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );
		Schema::install();
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -60 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd' );
		$this->booking( -5, -3, 'confirmed' );
		$this->booking( -20, -20, 'canceled', $this->day( -25 ) );
		Bookings::rebuild();
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -40 ), $this->date( 0 ) );
	}

	protected function tearDown(): void {
		Stats::purge();
		parent::tearDown();
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( date( 'Y-m-d', strtotime( "$offset days" ) ) . ' ' . $time );
	}

	private function date( int $offset ): string {
		return date( 'Y-m-d', strtotime( "$offset days" ) );
	}

	private function booking( int $from, int $to, string $status, ?int $canceledAt = null ): int {
		$id = $this->createBooking( $this->locationId, $this->itemId, $this->day( $from ), $this->day( $to, '23:59:59' ), '12:00 AM', '23:59', $status );
		update_post_meta( $id, 'full-day', 'on' );
		if ( $canceledAt ) {
			update_post_meta( $id, 'cancellation_time', $canceledAt );
		}

		return $id;
	}

	private function filters( array $request = [] ): Filters {
		return Filters::fromRequest(
			array_merge(
				[
					'preset' => 'custom',
					'from' => $this->date( -40 ),
					'to' => $this->date( 0 ),
				],
				$request
			)
		);
	}

	public function testFiltersAreValidated() {
		$f = Filters::fromRequest(
			[
				'preset' => 'bogus',
				'resolution' => 'year',
				'unit' => 'x',
				'compare' => 'x',
				'items' => [ '5', 'a', '0' ],
			]
		);
		$this->assertSame( '12m', $f->preset );
		$this->assertSame( 'month', $f->resolution );
		$this->assertSame( 'all', $f->unit );
		$this->assertSame( 'all', Filters::fromRequest( [ 'unit' => 'both' ] )->unit, 'old links keep working' );
		$this->assertSame( 'none', $f->compare );
		$this->assertSame( [ 5 ], $f->items );
		$this->assertSame( $this->date( 0 ), $f->to );

		$f = Filters::fromRequest(
			[
				'preset' => 'custom',
				'from' => '2021-13-45',
				'to' => '2030-01-01',
			]
		);
		$this->assertSame( $this->date( 0 ), $f->to, 'the future is cut off' );
		$this->assertLessThanOrEqual( Filters::MAX_DAYS, ( strtotime( $f->to ) - strtotime( $f->from ) ) / 86400 );

		$f = $this->filters( [ 'compare' => 'previous' ] );
		$this->assertSame( [ $this->date( -81 ), $this->date( -41 ) ], $f->comparisonRange() );
		$f = $this->filters( [ 'compare' => 'year' ] );
		$this->assertSame( [ date( 'Y-m-d', strtotime( $this->date( -40 ) . ' -1 year' ) ), date( 'Y-m-d', strtotime( $this->date( 0 ) . ' -1 year' ) ) ], $f->comparisonRange() );
	}

	public function testTotalsAndSeries() {
		$f = $this->filters();
		$q = new Query( $f );
		$t = $q->totals( [ $f->from, $f->to ] )['day'];

		$this->assertSame( 41, $t['capacity'] );
		$this->assertSame( 3, $t['booked'] );
		$this->assertSame( 3, $t['usage_days'] );
		$this->assertSame( 1, $t['started'] );
		$this->assertSame( 1, $t['canceled'] );
		$this->assertSame( 38, $t['idle_days'] );
		$this->assertSame( 3, $t['full_days'] );

		$series = $q->series( [ $f->from, $f->to ] );
		$this->assertSame( 3, array_sum( array_column( $series, 'booked' ) ) );
		foreach ( $series as $row ) {
			$this->assertContains( $row['bucket'], Page::buckets( $f->from, $f->to, 'month' ) );
		}
	}

	public function testCombinedTotalsMergeBothBookingKinds() {
		// the same item also offers hourly slots (4 h per day), one hour is booked yesterday
		$this->createTimeframe( $this->locationId, $this->itemId, $this->day( -60 ), $this->day( 120, '23:59:59' ), Timeframe::BOOKABLE_ID, 'off', 'd', 1, '8:00 AM', '12:00 PM' );
		$id = $this->createBooking( $this->locationId, $this->itemId, $this->day( -1, '08:00' ), $this->day( -1, '08:59:59' ), '8:00 AM', '9:00 AM', 'confirmed' );
		update_post_meta( $id, 'full-day', 'off' );
		Bookings::rebuild();
		Calculator::recompute( $this->itemId, $this->locationId, $this->date( -40 ), $this->date( 0 ) );

		$f = $this->filters();
		$q = new Query( $f );
		$c = $q->combinedTotals( [ $f->from, $f->to ] );

		// a day counts once, whatever kinds of slots it has: 41 open days, booked on 3 full days plus yesterday (hourly)
		$this->assertSame( 41, $c['open_days'] );
		$this->assertSame( 4, $c['usage_days'] );
		$this->assertSame( 2, $c['started'] );
		$this->assertSame( 1, $c['canceled'] );
		$this->assertEqualsWithDelta( 41.0, $c['avail'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, $c['fail'], 0.0001 );

		$rows = $q->combinedBy( [ $f->from, $f->to ], 'item' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 4, $rows[0]['usage_days'] );
		$this->assertNotEmpty( $q->combinedSeries( [ $f->from, $f->to ] ) );
		$this->assertSame( [], ( new Query( $this->filters( [ 'items' => [ 999999 ] ] ) ) )->combinedTotals( [ $f->from, $f->to ] ) );

		// the page renders both the overall and the separate view
		ob_start();
		Page::render();
		ob_end_clean();
	}

	public function testFiltersRestrictTheRows() {
		$f = $this->filters( [ 'items' => [ $this->itemId + 999 ] ] );
		$this->assertSame( [], ( new Query( $f ) )->totals( [ $f->from, $f->to ] ) );

		$f = $this->filters(
			[
				'items' => [ $this->itemId ],
				'locations' => [ $this->locationId ],
			]
		);
		$this->assertArrayHasKey( 'day', ( new Query( $f ) )->totals( [ $f->from, $f->to ] ) );

		$f = $this->filters( [ 'unit' => 'hour' ] );
		$this->assertSame( [], ( new Query( $f ) )->totals( [ $f->from, $f->to ] ) );
	}

	public function testCategoryWithoutMembersSelectsNothing() {
		$term = wp_insert_term( 'Empty', \CommonsBooking\Wordpress\CustomPostType\Item::getTaxonomyName() );
		$f    = $this->filters( [ 'item_cat' => [ $term['term_id'] ] ] );
		$q    = new Query( $f );
		$this->assertTrue( $q->isEmptySelection() );
		$this->assertSame( [], $q->totals( [ $f->from, $f->to ] ) );

		wp_set_object_terms( $this->itemId, [ (int) $term['term_id'] ], \CommonsBooking\Wordpress\CustomPostType\Item::getTaxonomyName() );
		$q = new Query( $f );
		$this->assertFalse( $q->isEmptySelection() );
		$this->assertArrayHasKey( 'day', $q->totals( [ $f->from, $f->to ] ) );
	}

	public function testDistributions() {
		$f = $this->filters();
		$d = ( new Query( $f ) )->distributions( [ $f->from, $f->to ] );

		$this->assertSame( [ 0, 0, 1, 0, 0 ], $d['duration']['day'], 'one booking of 3 days' );
		$this->assertSame( 3.0, $d['mean']['day'] );
		$this->assertSame( 3.0, $d['median']['day'] );
		$this->assertSame( 1, array_sum( $d['lead'] ) );
		$this->assertSame( 1, array_sum( $d['cancel'] ) );
		$this->assertSame( 0, $d['cancelUnknown'] );
		// cancelled 5 days before start: bucket "2-7 days"
		$this->assertSame( 1, $d['cancel'][2] );
	}

	public function testBucketsMatchSql() {
		$this->assertSame( [ '2021-05-01', '2021-06-01', '2021-07-01' ], Page::buckets( '2021-05-17', '2021-07-01', 'month' ) );
		$this->assertSame( '2021-06-28', Page::buckets( '2021-06-30', '2021-07-01', 'week' )[0] );
		$this->assertCount( 3, Page::buckets( '2021-06-29', '2021-07-01', 'day' ) );
	}

	public function testChartsRenderValidEscapedSvg() {
		foreach (
			[
				Chart::columns(
					[ 'a', '<b>' ],
					[ 0.5, 1.0 ],
					[ 0.25, 0.0 ],
					[
						'percent' => true,
						'title' => 'T<',
						'desc' => 'D',
						'compareLabel' => 'c',
						'valueLabel' => 'v',
					]
				),
				Chart::columns( [ 'a' ], [ 5.0 ] ),
				Chart::columns( [], [] ),
				Chart::bars(
					[
						[
							'label' => 'x&y',
							'value' => 0.3,
						],
					],
					[ 'percent' => true ]
				),
				Chart::heatmap( [ 'r' ], [ 'c1', 'c2' ], [ [ 0.5, null ] ] ),
			] as $svg
		) {
			$xml = new \DOMDocument();
			$this->assertTrue( $xml->loadXML( $svg ), 'well-formed: ' . $svg );
			$this->assertStringContainsString( '<title', $svg );
			$this->assertStringContainsString( '<desc', $svg );
		}
		$this->assertStringNotContainsString( '<b>', Chart::columns( [ '<b>' ], [ 1.0 ] ) );
	}

	public function testCsvRowsAndFormat() {
		$f    = $this->filters();
		$rows = Export::rows( 'items', $f );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Item', $rows[0][0] );
		$this->assertSame( 41, $rows[1][2] );
		$this->assertSame( 3, $rows[1][3] );
		$this->assertEqualsWithDelta( 0.0732, $rows[1][4], 0.0001 );

		$this->assertStringStartsWith( '"\'=SUM(A1)";1;0,5;', Export::line( [ '=SUM(A1)', 1, 0.5, null ] ) );
		$this->assertStringContainsString( '"2021-06-30"', Export::line( [ '2021-06-30' ] ) );
		$this->assertSame( "\"a\"\"b\"\r\n", Export::line( [ 'a"b' ] ) );
		$overview = Export::rows( 'overview', $f );
		$this->assertSame( [ 'Period', 'Location', 'Item' ], array_slice( $overview[0], 0, 3 ) );
	}
}
