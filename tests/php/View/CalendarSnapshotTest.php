<?php

namespace CommonsBooking\Tests\View;

use CommonsBooking\Helper\Helper;
use CommonsBooking\Map\MapItemAvailable;
use CommonsBooking\Model\Day;
use CommonsBooking\Model\Restriction;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Plugin;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Calculator;
use CommonsBooking\Service\Availability\CalendarWindow;
use CommonsBooking\Service\Availability\DayFacts;
use CommonsBooking\Service\Availability\DayStatus;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Calendar;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use SlopeIt\ClockMock\ClockMock;

/**
 * Pins the output of the availability calculation (calendar json, map availability, items table)
 * for a broad set of scenarios.
 *
 * The snapshot was recorded with the calculation before it was split into
 * {@see \CommonsBooking\Service\Availability\DayFacts} and {@see \CommonsBooking\Service\Availability\CalendarWindow}.
 * The refactoring and later precomputation must not change it.
 *
 * Delete the snapshot file to record it again (only if a behaviour change is intended).
 */
class CalendarSnapshotTest extends CustomPostTypeTest {

	private const SNAPSHOT = __DIR__ . '/snapshots/calendar-core.json';

	private const NOW = '01.07.2021 10:30';

	/**
	 * @var array<string, array{0:int,1:int}> scenario name => [item id, location id]
	 */
	private array $pairs = [];

	public function testSnapshot() {
		$actual = [];
		foreach ( [
			'anonymous' => 0,
			'subscriber' => $this->subscriberId,
			'editor' => $this->editorUserID,
			'admin' => $this->adminUserID,
		] as $userLabel => $userId ) {
			wp_set_current_user( $userId );
			Plugin::clearCache();
			wp_cache_flush();
			$actual[ $userLabel ] = $this->collect();

			// same result when map and table read stored rows (cold: rows computed on the way, warm: rows read)
			// and when they compute every day live
			if ( Availability::isEnabled() ) {
				Store::reset();
				Calculator::reset();
				$computations = Calculator::getComputeCount();
				$this->assertSame( $actual[ $userLabel ], $this->collect(), "warm, $userLabel" );
				// nothing is computed again, the role limited pair is evaluated live from the calendar json
				$this->assertSame( $computations, Calculator::getComputeCount(), "warm, $userLabel" );
				$this->assertGreaterThan( 0, Store::getStats()['usable'] );

				add_filter( 'commonsbooking_use_availability_cache', '__return_false' );
				$this->assertSame( $actual[ $userLabel ], $this->collect(), "live, $userLabel" );
				remove_filter( 'commonsbooking_use_availability_cache', '__return_false' );
			}
		}

		$json = wp_json_encode( $actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! file_exists( self::SNAPSHOT ) ) {
			if ( ! is_dir( dirname( self::SNAPSHOT ) ) ) {
				mkdir( dirname( self::SNAPSHOT ) );
			}
			file_put_contents( self::SNAPSHOT, $json . "\n" );
			$this->markTestIncomplete( 'Snapshot recorded, run again to compare.' );
		}

		$this->assertSame( json_decode( file_get_contents( self::SNAPSHOT ), true ), json_decode( $json, true ) );
	}

	/**
	 * The packed flags of a day must give the same calendar day (apart from the slots) and the same status
	 * as the facts evaluated from the grid. This is what precomputed availability relies on.
	 */
	public function testFlagsCarryEverythingButTheSlots() {
		$checkedDays = 0;
		$statuses    = [];
		foreach ( [ 0, $this->subscriberId, $this->editorUserID, $this->adminUserID ] as $userId ) {
			wp_set_current_user( $userId );
			Plugin::clearCache();
			foreach ( $this->pairs as [ $itemId, $locationId ] ) {
				$start    = new Day( date( 'Y-m-d', strtotime( '-3 days' ) ) );
				$end      = new Day( date( 'Y-m-d', strtotime( '+45 days' ) ) );
				$window   = CalendarWindow::forTimeframes(
					\CommonsBooking\Repository\Timeframe::getBookableForCurrentUser( [ $locationId ], [ $itemId ], null, true, Helper::getLastFullHourTimestamp() ),
					$start,
					$end,
					true
				);
				$calendar = new \CommonsBooking\Model\Calendar( $start, $end, [ $locationId ], [ $itemId ] );
				foreach ( $calendar->getWeeks() as $week ) {
					foreach ( $week->getDays() as $day ) {
						$facts  = DayFacts::fromGrid( $day->getGrid() );
						$packed = DayFacts::fromFlags( $facts->getFlags() );

						$expected = $window->mapDay( $day, $facts );
						$actual   = $window->mapDay( $day, $packed );
						$label    = $day->getDate() . ' ' . get_the_title( $itemId ) . ' user ' . $userId;

						$this->assertSame( $expected['types'], $actual['types'], $label );
						$this->assertSame( array_diff_key( $expected['day'], [ 'slots' => 1 ] ), array_diff_key( $actual['day'], [ 'slots' => 1 ] ), $label );

						$hasSlots = $packed->hasSlots() && $window->isInBookingWindow( $day );
						$status   = DayStatus::fromDayArray( $expected['day'] );
						$this->assertSame( $status, DayStatus::fromDayArray( $actual['day'], $hasSlots ), $label );

						$statuses[ $status ] = true;
						++$checkedDays;
					}
				}
			}
		}

		$this->assertGreaterThan( 1000, $checkedDays );
		// the scenarios cover every status
		$this->assertEqualsCanonicalizing(
			[ DayStatus::NO_SLOTS, DayStatus::HOLIDAY, DayStatus::BOOKED, DayStatus::PARTIALLY_BOOKED_START, DayStatus::PARTIALLY_BOOKED_END, DayStatus::LOCKED, DayStatus::AVAILABLE ],
			array_keys( $statuses )
		);
	}

	/**
	 * @return array
	 */
	private function collect(): array {
		$today = date( 'Y-m-d' );
		$out   = [];

		foreach ( $this->pairs as $name => [ $itemId, $locationId ] ) {
			$out['calendar'][ $name ]['default'] = $this->compactCalendar(
				Calendar::getCalendarDataArray( $itemId, $locationId, $today, date( 'Y-m-d', strtotime( '+70 days' ) ) )
			);
			$out['calendar'][ $name ]['keep']    = $this->compactCalendar(
				Calendar::getCalendarDataArray( $itemId, $locationId, $today, date( 'Y-m-d', strtotime( '+40 days' ) ), true )
			);
			$out['calendar'][ $name ]['past']    = $this->compactCalendar(
				Calendar::getCalendarDataArray( $itemId, $locationId, date( 'Y-m-d', strtotime( '-5 days' ) ), date( 'Y-m-d', strtotime( '+5 days' ) ), true )
			);
		}

		// map availability
		$locations = [];
		foreach ( $this->pairs as $name => [ $itemId, $locationId ] ) {
			$locations[ $locationId ]['items'][] = [
				'id' => $itemId,
				'name' => $name,
			];
		}
		$locations = MapItemAvailable::create_items_availabilities( $locations, $today, date( 'Y-m-d', strtotime( '+14 days' ) ) );
		foreach ( $locations as $location ) {
			foreach ( $location['items'] as $item ) {
				$out['map'][ $item['name'] ] = array_column( $item['availability'], 'status', 'date' );
			}
		}

		// items table: keep item name, location name and the state of each day
		$html = Calendar::renderTable( [ 'days' => 45 ] );
		preg_match_all( '#<tr><td><b><a [^>]*>([^<]*)</a></b></td><td><div data-title="([^"]*)">.*?</div></td><td>(.*?)</td></tr>#s', $html, $rows, PREG_SET_ORDER );
		foreach ( $rows as $row ) {
			preg_match_all( "#<span(?:\\s*class=)?'?([^'>]*)'?>#", $row[3], $spans );
			$out['table'][ $row[1] . ' @ ' . $row[2] ] = implode( ',', $spans[1] );
		}
		ksort( $out['table'] );

		return $out;
	}

	/**
	 * Normalizes calendar data and writes each day as a single json line.
	 *
	 * @param array $calendarData
	 *
	 * @return array
	 */
	private function compactCalendar( array $calendarData ): array {
		$calendarData = $this->normalize( $calendarData );
		if ( isset( $calendarData['days'] ) ) {
			$calendarData['days'] = array_map( 'wp_json_encode', $calendarData['days'] );
		}

		return $calendarData;
	}

	/**
	 * Replaces timeframe posts in slots with stable values.
	 *
	 * @param mixed $data
	 *
	 * @return mixed
	 */
	private function normalize( $data ) {
		if ( $data instanceof \WP_Post ) {
			return [
				'title'  => $data->post_title,
				'type'   => get_post_meta( $data->ID, 'type', true ) ?: get_post_meta( $data->ID, Restriction::META_TYPE, true ),
				'locked' => $data->locked ?? null,
			];
		}
		if ( is_array( $data ) && array_key_exists( 'timestampstart', $data ) ) {
			// one compact line per slot
			$timeframe = $this->normalize( $data['timeframe'] ?? null );
			return sprintf(
				'%s-%s %s %s %s',
				date( 'H:i', $data['timestampstart'] ),
				date( 'H:i', $data['timestampend'] ),
				$timeframe['title'] ?? '-',
				$timeframe['type'] ?? '-',
				var_export( $timeframe['locked'] ?? null, true )
			);
		}
		if ( is_array( $data ) && array_key_exists( 'slots', $data ) && is_array( $data['slots'] ) ) {
			$data['slots'] = $this->mergeSlots( array_map( [ $this, 'normalize' ], $data['slots'] ) );
		}
		if ( is_array( $data ) ) {
			return array_map( [ $this, 'normalize' ], $data );
		}

		return $data;
	}

	/**
	 * Merges adjacent slots of the same timeframe into one line to keep the snapshot small.
	 *
	 * @param string[] $slots
	 *
	 * @return string[]
	 */
	private function mergeSlots( array $slots ): array {
		$merged = [];
		foreach ( $slots as $slot ) {
			[ $range, $rest ] = explode( ' ', $slot, 2 );
			$last             = count( $merged ) - 1;
			if ( $last >= 0 && $merged[ $last ][1] === $rest ) {
				$merged[ $last ][0] = substr( $merged[ $last ][0], 0, 5 ) . substr( $range, 5 );
			} else {
				$merged[] = [ $range, $rest ];
			}
		}

		return array_map( fn( $slot ) => $slot[0] . ' ' . $slot[1], $merged );
	}

	private function pair( string $name ): array {
		$itemId               = $this->createItem( 'Item ' . $name );
		$locationId           = $this->createLocation( 'Location ' . $name );
		$this->pairs[ $name ] = [ $itemId, $locationId ];

		return [ $itemId, $locationId ];
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( date( 'Y-m-d', strtotime( "$offset days" ) ) . ' ' . $time );
	}

	private function booking( array $pair, int $fromDay, int $toDay, string $status = 'confirmed', string $startTime = '12:00 AM', string $endTime = '23:59', string $from = '00:00', string $to = '23:59:59', int $grid = 0 ) {
		return $this->createBooking( $pair[1], $pair[0], $this->day( $fromDay, $from ), $this->day( $toDay, $to ), $startTime, $endTime, $status, self::USER_ID, 'w', 3, "Booking $fromDay-$toDay $status", $grid );
	}

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::NOW ) );
		\CommonsBooking\Wordpress\CustomPostType\Item::registerPostTypeTaxonomy();
		$this->createSubscriber();
		$this->createAdministrator();
		$this->createEditor();

		// the parent creates a pair without any timeframe
		$this->pairs['no timeframe'] = [ $this->itemId, $this->locationId ];

		// daily, full day, bookings in several states
		$p = $this->pair( 'daily full day' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -10 ), $this->day( 60, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Bookable daily' );
		$this->booking( $p, 2, 4 );
		$this->booking( $p, 6, 6, 'unconfirmed' );
		$this->booking( $p, 8, 8, 'canceled' );
		$this->booking( $p, -3, 0 );

		// hourly grid, partial bookings
		$p = $this->pair( 'hourly' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -1 ), $this->day( 40, '23:59:59' ), Timeframe::BOOKABLE_ID, 'off', 'd', 1, '8:00 AM', '6:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Bookable hourly' );
		$this->booking( $p, 1, 1, 'confirmed', '8:00 AM', '10:00 AM', '08:00', '09:59:59', 1 );
		$this->booking( $p, 2, 2, 'confirmed', '4:00 PM', '6:00 PM', '16:00', '17:59:59', 1 );
		$this->booking( $p, 3, 3, 'confirmed', '8:00 AM', '6:00 PM', '08:00', '17:59:59', 1 );
		$this->booking( $p, 4, 4, 'confirmed', '11:00 AM', '1:00 PM', '11:00', '12:59:59', 1 );

		// holidays, repair timeframe, restrictions
		$p = $this->pair( 'blocked' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -1 ), null, Timeframe::BOOKABLE_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 45, 0, 'on', 'on', 'Bookable open end' );
		$this->createTimeframe( $p[1], $p[0], $this->day( 3 ), $this->day( 4, '23:59:59' ), Timeframe::HOLIDAYS_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Holiday' );
		$this->createTimeframe( $p[1], $p[0], $this->day( 6 ), $this->day( 7, '23:59:59' ), Timeframe::REPAIR_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Repair timeframe' );
		$this->createTimeframe( $p[1], $p[0], $this->day( 9 ), $this->day( 9, '23:59:59' ), Timeframe::OFF_HOLIDAYS_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Official holiday' );
		$this->createRestriction( Restriction::TYPE_REPAIR, $p[1], $p[0], $this->day( 11, '10:00' ), $this->day( 12, '15:00' ), Restriction::STATE_ACTIVE, 'Broken', self::USER_ID, 'Repair restriction' );
		$this->createRestriction( Restriction::TYPE_REPAIR, $p[1], $p[0], $this->day( 14 ), $this->day( 15 ), Restriction::STATE_SOLVED, 'Fixed', self::USER_ID, 'Solved restriction' );
		$this->createRestriction( Restriction::TYPE_HINT, $p[1], $p[0], $this->day( 16 ), $this->day( 17 ), Restriction::STATE_ACTIVE, 'Hint', self::USER_ID, 'Hint restriction' );
		$this->createRestriction( Restriction::TYPE_REPAIR, $p[1], 0, $this->day( 20 ), $this->day( 20, '23:59' ), Restriction::STATE_ACTIVE, 'All items', self::USER_ID, 'Location wide restriction' );
		$this->booking( $p, 1, 1 );

		// weekdays only, advance booking days, start day offset
		$p = $this->pair( 'weekdays offset' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -30 ), $this->day( 90, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'w', 0, '8:00 AM', '12:00 PM', 'publish', [ '1', '2', '3', '4', '5' ], '', self::USER_ID, 5, 10, 2, 'on', 'on', 'Bookable weekdays' );
		$this->booking( $p, 5, 5 );

		// timeframe starting in the future, manual dates
		$p = $this->pair( 'future start' );
		$this->createTimeframe( $p[1], $p[0], $this->day( 7 ), $this->day( 20, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Bookable future' );
		$p      = $this->pair( 'manual dates' );
		$manual = implode( ',', [ date( 'Y-m-d', $this->day( 1 ) ), date( 'Y-m-d', $this->day( 3 ) ), date( 'Y-m-d', $this->day( 8 ) ) ] );
		$this->createTimeframe( $p[1], $p[0], $this->day( 1 ), $this->day( 8, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'manual', 0, '8:00 AM', '12:00 PM', 'publish', [], $manual, self::USER_ID, 3, 30, 0, 'on', 'on', 'Bookable manual' );

		// two timeframes on one day: morning and afternoon, booking in the morning
		$p = $this->pair( 'morning afternoon' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -1 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'off', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 2, 20, 0, 'on', 'on', 'Morning' );
		$this->createTimeframe( $p[1], $p[0], $this->day( -1 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'off', 'd', 0, '1:00 PM', '6:00 PM', 'publish', [], '', self::USER_ID, 4, 25, 1, 'on', 'on', 'Afternoon' );
		$this->booking( $p, 2, 2, 'confirmed', '8:00 AM', '12:00 PM', '08:00', '11:59:59' );

		// limited to editors
		$p  = $this->pair( 'role limited' );
		$tf = $this->createTimeframe( $p[1], $p[0], $this->day( -1 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Editors only' );
		update_post_meta( $tf, TimeframeModel::META_ALLOWED_USER_ROLES, [ 'editor' ] );
		$this->booking( $p, 3, 3 );

		// location specific overbooking settings, holiday for several items and locations
		$p = $this->pair( 'overbooking' );
		update_post_meta( $p[1], COMMONSBOOKING_METABOX_PREFIX . 'use_global_settings', 'off' );
		update_post_meta( $p[1], COMMONSBOOKING_METABOX_PREFIX . 'allow_lockdays_in_range', 'on' );
		update_post_meta( $p[1], COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_in_range', 'on' );
		update_post_meta( $p[1], COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_maximum', 2 );
		$this->createTimeframe( $p[1], $p[0], $this->day( -1 ), $this->day( 30, '23:59:59' ), Timeframe::BOOKABLE_ID, 'on', 'w', 0, '8:00 AM', '12:00 PM', 'publish', [ '1', '2', '3', '4', '5' ], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Bookable overbooking' );
		$q = $this->pairs['daily full day'];
		$this->createTimeframe( [ $p[1], $q[1] ], [ $p[0], $q[0] ], $this->day( 12 ), $this->day( 13, '23:59:59' ), Timeframe::HOLIDAYS_ID, 'on', 'd', 0, '8:00 AM', '12:00 PM', 'publish', [], '', self::USER_ID, 3, 30, 0, 'on', 'on', 'Multi holiday' );
	}
}
