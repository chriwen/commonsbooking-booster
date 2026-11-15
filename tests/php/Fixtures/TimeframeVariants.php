<?php

namespace CommonsBooking\Tests\Fixtures;

use CommonsBooking\Model\Restriction;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Creates timeframes in as many configurations as CommonsBooking offers, each on its own item (and mostly its own
 * location), together with bookings, holidays, repairs and restrictions that interact with them.
 *
 * Used by the parity test (booster vs. original queries) and by the demo data of the test environment
 * (testenv/seed-demo.php), so that both cover the same cases.
 *
 * Everything is created through the WordPress API, so the index and the precomputed availability are built by the
 * hooks like in production.
 */
class TimeframeVariants {

	private int $today;

	private string $prefix;

	/** @var callable|null returns extra meta for a new location */
	private $locationMeta;

	/** @var array<string, array{0: int, 1: int}> case => [item id, location id] */
	private array $pairs = [];

	/** @var array<string, string> case => description */
	private array $descriptions = [];

	private int $bookings = 0;

	/** user id of a cb_manager who is assigned as admin of one item and location (0 = none) */
	private int $managerId;

	/**
	 * @param int           $today        timestamp of today 00:00
	 * @param string        $prefix       prefix of the post titles
	 * @param callable|null $locationMeta fn( int $number ): array, e.g. coordinates for the map
	 */
	private function __construct( int $today, string $prefix, ?callable $locationMeta, int $managerId ) {
		$this->managerId    = $managerId;
		$this->today        = $today;
		$this->prefix       = $prefix;
		$this->locationMeta = $locationMeta;
	}

	/**
	 * Creates all variants.
	 *
	 * @param int           $today          timestamp of today 00:00
	 * @param string        $prefix         prefix of the post titles
	 * @param callable|null $locationMeta   fn( int $number ): array, extra meta for each location
	 * @param bool          $globalHoliday  also create a holiday for all items and locations (selection "all")
	 * @param int           $managerId      cb_manager assigned as admin of the item and location "Von Manager betreut"
	 *
	 * @return array{pairs: array<string, array{0: int, 1: int}>, descriptions: array<string, string>, bookings: int}
	 */
	public static function create( int $today, string $prefix = 'Variante', ?callable $locationMeta = null, bool $globalHoliday = false, int $managerId = 0 ): array {
		$fixture = new self( $today, $prefix, $locationMeta, $managerId );
		$fixture->build( $globalHoliday );

		return [
			'pairs'        => $fixture->pairs,
			'descriptions' => $fixture->descriptions,
			'bookings'     => $fixture->bookings,
		];
	}

	/**
	 * Weekday (1 = Monday ... 7 = Sunday) of a day offset.
	 */
	private function weekday( int $offset ): int {
		return (int) gmdate( 'N', $this->day( $offset ) );
	}

	private function day( int $offset, string $time = '00:00' ): int {
		return strtotime( gmdate( 'Y-m-d', $this->today + $offset * DAY_IN_SECONDS ) . ' ' . $time . ' UTC' );
	}

	private function date( int $offset ): string {
		return gmdate( 'Y-m-d', $this->day( $offset ) );
	}

	/**
	 * Next day offset >= $from that falls on one of the weekdays.
	 */
	private function nextWeekday( int $from, array $weekdays ): int {
		for ( $offset = $from; $offset < $from + 7; $offset++ ) {
			if ( in_array( $this->weekday( $offset ), $weekdays, true ) ) {
				return $offset;
			}
		}

		return $from;
	}

	private function insert( string $type, string $title, array $meta = [], string $status = 'publish', array $post = [] ): int {
		$id = wp_insert_post(
			$post + [
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => $status,
				'post_author' => 1,
				'meta_input'  => $meta,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}

		return $id;
	}

	private function location( string $name, array $meta = [] ): int {
		static $number = 0;
		++$number;
		$extra = $this->locationMeta ? ( $this->locationMeta )( $number ) : [];
		$info  = [
			COMMONSBOOKING_METABOX_PREFIX . 'location_pickupinstructions' => "Abholung „{$name}“: Schlüssel im Schlüsselkasten neben dem Eingang (Code steht in der Buchungsbestätigung). Bitte das Fahrzeug sauber und abgeschlossen zurückbringen.",
			COMMONSBOOKING_METABOX_PREFIX . 'location_contact'            => "Ansprechperson: Team {$name}\nTelefon: 0221 123456-{$number}\nErreichbar Mo–Fr 9–18 Uhr",
			COMMONSBOOKING_METABOX_PREFIX . 'location_email'              => "standort{$number}@example.test",
			COMMONSBOOKING_METABOX_PREFIX . 'location_email_bcc'          => '',
			'loc_showmap'                                                 => 'on',
		];

		return $this->insert(
			'cb_location',
			"{$this->prefix}: $name",
			$meta + $extra + $info,
			'publish',
			[
				'post_content' => "<p>Testdaten-Standort für die Variante „{$name}“. Hier ist alles erfunden.</p>",
				'post_excerpt' => "Standort der Variante „{$name}“",
			]
		);
	}

	private function item( string $name ): int {
		static $number = 0;
		++$number;

		return $this->insert(
			'cb_item',
			"{$this->prefix}: $name",
			[
				COMMONSBOOKING_METABOX_PREFIX . 'item_maintainer_email' => "wartung{$number}@example.test",
			],
			'publish',
			[
				'post_content' => "<p>Lastenrad für die Variante „{$name}“: zweirädrig, Transportkiste mit Regenhaube, 8 Gänge, Kindersitz für zwei Kinder.</p><p>Bitte vor der Fahrt Reifendruck und Bremsen prüfen.</p>",
				'post_excerpt' => "Lastenrad für die Variante „{$name}“",
			]
		);
	}

	/**
	 * @param int|int[] $locationId single id or list
	 * @param int|int[] $itemId     single id or list
	 * @param array     $options
	 */
	private function timeframe( $locationId, $itemId, array $options ): int {
		$options += [
			'title'      => 'Zeitrahmen',
			'type'       => Timeframe::BOOKABLE_ID,
			'status'     => 'publish',
			'start'      => $this->day( -30 ),
			'end'        => $this->day( 120 ),
			'repetition' => 'd',
			'weekdays'   => [ '1', '2', '3', '4', '5', '6', '7' ],
			'full_day'   => 'on',
			'grid'       => 0,
			'start_time' => '00:00',
			'end_time'   => '23:59',
			'max_days'   => 3,
			'advance'    => 365,
			'offset'     => 0,
			'roles'      => [],
			'manual'     => '',
			'selection'  => TimeframeModel::SELECTION_MANUAL_ID,
		];
		$meta     = [
			'type'                                              => $options['type'],
			TimeframeModel::META_REPETITION                     => $options['repetition'],
			TimeframeModel::REPETITION_START                    => $options['start'],
			'full-day'                                          => $options['full_day'],
			'grid'                                              => $options['grid'],
			'start-time'                                        => $options['start_time'],
			'end-time'                                          => $options['end_time'],
			'weekdays'                                          => $options['weekdays'],
			TimeframeModel::META_MAX_DAYS                       => $options['max_days'],
			TimeframeModel::META_TIMEFRAME_ADVANCE_BOOKING_DAYS => $options['advance'],
			TimeframeModel::META_BOOKING_START_DAY_OFFSET       => $options['offset'],
			TimeframeModel::META_SHOW_BOOKING_CODES             => 'off',
			TimeframeModel::META_CREATE_BOOKING_CODES           => 'off',
			TimeframeModel::META_ITEM_SELECTION_TYPE            => $options['selection'],
			TimeframeModel::META_LOCATION_SELECTION_TYPE        => $options['selection'],
			TimeframeModel::META_MANUAL_SELECTION               => $options['manual'],
		];
		if ( $options['end'] ) {
			$meta[ TimeframeModel::REPETITION_END ] = $options['end'];
		}
		if ( $options['roles'] ) {
			$meta[ TimeframeModel::META_ALLOWED_USER_ROLES ] = $options['roles'];
		}
		if ( $options['selection'] === TimeframeModel::SELECTION_MANUAL_ID ) {
			if ( is_array( $itemId ) ) {
				$meta[ TimeframeModel::META_ITEM_ID_LIST ] = array_map( 'strval', $itemId );
			} else {
				$meta[ TimeframeModel::META_ITEM_ID ] = $itemId;
			}
			if ( is_array( $locationId ) ) {
				$meta[ TimeframeModel::META_LOCATION_ID_LIST ] = array_map( 'strval', $locationId );
			} else {
				$meta[ TimeframeModel::META_LOCATION_ID ] = $locationId;
			}
		}

		return $this->insert( 'cb_timeframe', "{$this->prefix}: {$options['title']}", $meta, $options['status'] );
	}

	/**
	 * Booking from day $from to day $to (inclusive), full day or between the given times on each day.
	 */
	private function booking( int $locationId, int $itemId, int $from, int $to, string $status = 'confirmed', string $startTime = '00:00', string $endTime = '23:59', int $grid = 0 ): int {
		++$this->bookings;
		$end    = $endTime === '23:59' ? $this->day( $to ) : $this->day( $to, $endTime ) - 1;
		$length = $to - $from + 1;
		$title  = sprintf(
			'%s: Buchung %s, %d %s%s',
			$this->prefix,
			$length > 1 ? $this->date( $from ) . ' – ' . $this->date( $to ) : $this->date( $from ),
			$length,
			$length === 1 ? 'Tag' : 'Tage',
			$endTime === '23:59' ? '' : " {$startTime}–{$endTime}"
		);

		return $this->insert(
			'cb_booking',
			$title,
			[
				'type'                          => Timeframe::BOOKING_ID,
				TimeframeModel::META_REPETITION => 'w',
				'start-time'                    => $startTime,
				'end-time'                      => $endTime,
				'full-day'                      => $startTime === '00:00' && $endTime === '23:59' ? 'on' : '',
				TimeframeModel::META_MAX_DAYS   => max( 3, $length ),
				'location-id'                   => $locationId,
				'item-id'                       => $itemId,
				'grid'                          => $grid,
				TimeframeModel::REPETITION_START => $this->day( $from, $startTime ),
				TimeframeModel::REPETITION_END  => $end,
				'weekdays'                      => [ '1', '2', '3', '4', '5', '6', '7' ],
			],
			$status
		);
	}

	private function restriction( string $type, int $locationId, int $itemId, int $start, ?int $end, string $state = Restriction::STATE_ACTIVE, string $title = 'Einschränkung' ): int {
		$meta = [
			Restriction::META_TYPE        => $type,
			Restriction::META_LOCATION_ID => $locationId,
			Restriction::META_ITEM_ID     => $itemId,
			Restriction::META_HINT        => $title,
			Restriction::META_START       => $start,
			Restriction::META_STATE       => $state,
		];
		if ( $end ) {
			$meta[ Restriction::META_END ] = $end;
		}

		return $this->insert( 'cb_restriction', "{$this->prefix}: $title", $meta );
	}

	/**
	 * Registers a case (item at location) for the parity checks.
	 */
	private function add( string $case, int $itemId, int $locationId, string $description ): void {
		$this->pairs[ $case ]        = [ $itemId, $locationId ];
		$this->descriptions[ $case ] = $description;
	}

	/**
	 * A new location with one item.
	 *
	 * @return array{0: int, 1: int} [item id, location id]
	 */
	private function station( string $name, array $locationMeta = [] ): array {
		return [ $this->item( $name ), $this->location( $name, $locationMeta ) ];
	}

	private function build( bool $globalHoliday ): void {
		$workdays = [ '1', '2', '3', '4', '5' ];

		// ---------------------------------------------------------------- repetition
		[ $item, $location ] = $this->station( 'Täglich, ohne Ende' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Täglich ohne Enddatum',
				'end' => null,
			]
		);
		$this->booking( $location, $item, 2, 4 );
		$this->booking( $location, $item, 6, 6, 'unconfirmed' );
		$this->booking( $location, $item, 8, 9, 'canceled' );
		$this->booking( $location, $item, -5, -3 );
		$this->add( 'daily_open_end', $item, $location, 'täglich, ohne Enddatum, Buchungen bestätigt/unbestätigt/storniert/vergangen' );

		[ $item, $location ] = $this->station( 'Lange Buchungen' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Täglich, bis 14 Tage am Stück',
				'max_days' => 14,
			]
		);
		$this->booking( $location, $item, -3, 1 );
		$this->booking( $location, $item, 3, 9 );
		$this->booking( $location, $item, 11, 24 );
		$this->booking( $location, $item, 26, 35, 'canceled' );
		$this->booking( $location, $item, 38, 42, 'unconfirmed' );
		$this->add( 'long_bookings', $item, $location, 'max. 14 Tage: laufende Buchung (seit 3 Tagen), 7 und 14 Tage bestätigt, 10 Tage storniert, 5 Tage unbestätigt' );

		[ $item, $location ] = $this->station( 'Täglich, endet bald' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Täglich bis in 10 Tagen',
				'end' => $this->day( 10 ),
			]
		);
		$this->booking( $location, $item, 9, 10 );
		$this->add( 'daily_ends_soon', $item, $location, 'täglich, endet in 10 Tagen, Buchung am Ende' );

		[ $item, $location ] = $this->station( 'Wochentage Mo–Fr' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Mo–Fr',
				'repetition' => 'w',
				'weekdays' => $workdays,
			]
		);
		$monday = $this->nextWeekday( 1, [ 1 ] );
		$this->booking( $location, $item, $monday, $monday + 1 );
		$this->add( 'weekly_workdays', $item, $location, 'wöchentlich Mo–Fr, Wochenende nicht buchbar' );

		[ $item, $location ] = $this->station( 'Nur Wochenende' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Sa+So',
				'repetition' => 'w',
				'weekdays' => [ '6', '7' ],
			]
		);
		$saturday = $this->nextWeekday( 1, [ 6 ] );
		$this->booking( $location, $item, $saturday, $saturday + 1 );
		$this->add( 'weekly_weekend', $item, $location, 'wöchentlich nur Sa und So, Wochenend-Buchung' );

		[ $item, $location ] = $this->station( 'Monatlich' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Monatlich am Tag des Starts',
				'repetition' => 'm',
				'start' => $this->day( -40 ),
				'end' => $this->day( 200 ),
			]
		);
		$this->add( 'monthly', $item, $location, 'monatlich (am Tag des Monats des Startdatums)' );

		[ $item, $location ] = $this->station( 'Jährlich' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Jährlich am Starttag',
				'repetition' => 'y',
				'start' => $this->day( 5 - 365 ),
				'end' => $this->day( 800 ),
			]
		);
		$this->add( 'yearly', $item, $location, 'jährlich (Tag und Monat des Startdatums, in 5 Tagen)' );

		[ $item, $location ] = $this->station( 'Manuelle Termine' );
		$manual              = implode( ',', array_map( [ $this, 'date' ], [ 1, 3, 4, 11, 25, 40 ] ) );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Manuelle Termine',
				'repetition' => 'manual',
				'manual' => $manual,
				'start' => $this->day( 1 ),
				'end' => $this->day( 40 ),
			]
		);
		$this->booking( $location, $item, 3, 3 );
		$this->add( 'manual_dates', $item, $location, 'manuelle Termine (6 Tage), einer gebucht' );

		[ $item, $location ] = $this->station( 'Einmalig' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Einmaliger Zeitraum',
				'repetition' => 'norep',
				'start' => $this->day( 4 ),
				'end' => $this->day( 8 ),
			]
		);
		$this->booking( $location, $item, 5, 5 );
		$this->add( 'no_repetition', $item, $location, 'keine Wiederholung, Zeitraum Tag 4–8' );

		// ---------------------------------------------------------------- slots and hours
		[ $item, $location ] = $this->station( 'Vormittag/Nachmittag Mo–Do' );
		$monToThu            = [ '1', '2', '3', '4' ];
		$slot                = [
			'repetition' => 'w',
			'weekdays'   => $monToThu,
			'full_day'   => 'off',
			'grid'       => 0,
			'max_days'   => 1,
		];
		$this->timeframe(
			$location,
			$item,
			$slot + [
				'title' => 'Vormittag Mo–Do',
				'start_time' => '08:00',
				'end_time' => '12:00',
			]
		);
		$this->timeframe(
			$location,
			$item,
			$slot + [
				'title' => 'Nachmittag Mo–Do',
				'start_time' => '13:00',
				'end_time' => '18:00',
			]
		);
		// Sunday: full day, Friday and Saturday: not bookable
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Sonntag ganztägig',
				'repetition' => 'w',
				'weekdays' => [ '7' ],
				'max_days' => 1,
			]
		);
		$tuesday = $this->nextWeekday( 1, [ 2 ] );
		$this->booking( $location, $item, $tuesday, $tuesday, 'confirmed', '08:00', '12:00' );
		$wednesday = $this->nextWeekday( 1, [ 3 ] );
		$this->booking( $location, $item, $wednesday, $wednesday, 'confirmed', '08:00', '12:00' );
		$this->booking( $location, $item, $wednesday, $wednesday, 'confirmed', '13:00', '18:00' );
		$this->add( 'two_slots_mon_thu', $item, $location, 'Mo–Do Vormittag (8–12) und Nachmittag (13–18), Fr+Sa geschlossen, So ganztägig; Di Vormittag gebucht, Mi ganz gebucht' );

		[ $item, $location ] = $this->station( 'Stundenweise' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Stündlich 9–18',
				'full_day' => 'off',
				'grid' => 1,
				'start_time' => '09:00',
				'end_time' => '18:00',
				'max_days' => 1,
			]
		);
		$this->booking( $location, $item, 2, 2, 'confirmed', '10:00', '12:00', 1 );
		$this->booking( $location, $item, 3, 3, 'confirmed', '09:00', '18:00', 1 );
		$this->add( 'hourly_grid', $item, $location, 'stundenweise 9–18 Uhr; Tag 2 teilweise, Tag 3 ganz gebucht' );

		[ $item, $location ] = $this->station( 'Stundenweise am Wochenende' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Sa+So stündlich 10–16',
				'repetition' => 'w',
				'weekdays' => [ '6', '7' ],
				'full_day' => 'off',
				'grid' => 1,
				'start_time' => '10:00',
				'end_time' => '16:00',
				'max_days' => 1,
			]
		);
		// overlapping bookable timeframes must use the same grid, even on different weekdays
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Mo–Fr stündlich 8–20',
				'repetition' => 'w',
				'weekdays' => $workdays,
				'full_day' => 'off',
				'grid' => 1,
				'start_time' => '08:00',
				'end_time' => '20:00',
				'max_days' => 1,
			]
		);
		$this->booking( $location, $item, $this->nextWeekday( 1, [ 6 ] ), $this->nextWeekday( 1, [ 6 ] ), 'confirmed', '12:00', '14:00', 1 );
		$this->add( 'hourly_two_timeframes', $item, $location, 'Sa+So stündlich 10–16, Mo–Fr stündlich 8–20 (zwei Zeitrahmen, gleiches Raster); Samstag 12–14 gebucht' );

		// ---------------------------------------------------------------- booking window
		[ $item, $location ] = $this->station( 'Kurzer Vorlauf' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Nur 7 Tage im Voraus',
				'advance' => 7,
			]
		);
		$this->add( 'advance_7', $item, $location, 'nur 7 Tage im Voraus buchbar' );

		[ $item, $location ] = $this->station( 'Startversatz' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Frühestens in 3 Tagen',
				'offset' => 3,
				'max_days' => 5,
			]
		);
		$this->add( 'start_offset_3', $item, $location, 'Buchung frühestens in 3 Tagen, maximal 5 Tage' );

		[ $item, $location ] = $this->station( 'Ein Tag maximal' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Maximal 1 Tag',
				'max_days' => 1,
			]
		);
		$this->booking( $location, $item, 4, 4 );
		$this->add( 'max_days_1', $item, $location, 'maximal 1 Tag am Stück' );

		[ $item, $location ] = $this->station( 'Beginnt später' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Ab Tag 15',
				'start' => $this->day( 15 ),
				'end' => $this->day( 60 ),
			]
		);
		$this->add( 'starts_later', $item, $location, 'Zeitrahmen beginnt in 15 Tagen' );

		[ $item, $location ] = $this->station( 'Abgelaufen' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Vergangen',
				'start' => $this->day( -60 ),
				'end' => $this->day( -1 ),
			]
		);
		$this->add( 'expired', $item, $location, 'Zeitrahmen endete gestern (nicht mehr buchbar)' );

		[ $item, $location ] = $this->station( 'Zwei Zeiträume' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Teil 1 bis Tag 9',
				'start' => $this->day( -10 ),
				'end' => $this->day( 9 ),
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Teil 2 ab Tag 12 Mo–Fr',
				'start' => $this->day( 12 ),
				'end' => $this->day( 90 ),
				'repetition' => 'w',
				'weekdays' => $workdays,
			]
		);
		$this->booking( $location, $item, 8, 9 );
		$this->add( 'two_ranges_gap', $item, $location, 'zwei aufeinanderfolgende Zeitrahmen mit Lücke (Tag 10–11) und anderen Wochentagen' );

		// ---------------------------------------------------------------- user roles
		[ $item, $location ] = $this->station( 'Nur Redaktion' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Nur für Redakteure',
				'roles' => [ 'editor' ],
			]
		);
		$this->add( 'roles_editor', $item, $location, 'nur Rolle Redakteur (Admins dürfen immer)' );

		[ $item, $location ] = $this->station( 'Mitglieder und Redaktion' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Abonnenten und Redakteure',
				'roles' => [ 'subscriber', 'editor' ],
			]
		);
		$this->booking( $location, $item, 2, 3 );
		$this->add( 'roles_subscriber_editor', $item, $location, 'Rollen Abonnent und Redakteur' );

		[ $item, $location ] = $this->station( 'Gemischt: Rolle und offen' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Mo–Fr für alle',
				'repetition' => 'w',
				'weekdays' => $workdays,
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Wochenende nur Redaktion',
				'repetition' => 'w',
				'weekdays' => [ '6', '7' ],
				'roles' => [ 'editor' ],
			]
		);
		$this->add( 'roles_weekend_only', $item, $location, 'Mo–Fr für alle, Wochenende nur Redakteure' );

		[ $item, $location ] = $this->station( 'Nur CB-Manager' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Nur für CB-Manager',
				'roles' => [ 'cb_manager' ],
			]
		);
		$this->add( 'roles_cb_manager', $item, $location, 'nur Rolle CB-Manager' );

		[ $item, $location ] = $this->station( 'Von Manager betreut' );
		if ( $this->managerId ) {
			update_post_meta( $item, '_cb_item_admins', [ (string) $this->managerId ] );
			update_post_meta( $location, '_cb_location_admins', [ (string) $this->managerId ] );
		}
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Mo–Fr, 7 Tage Vorlauf',
				'repetition' => 'w',
				'weekdays' => $workdays,
				'advance' => 7,
				'max_days' => 2,
			]
		);
		$this->booking( $location, $item, $this->nextWeekday( 1, [ 1 ] ), $this->nextWeekday( 1, [ 1 ] ) );
		$this->add( 'managed_by_cb_manager', $item, $location, 'Artikel und Standort von einem CB-Manager betreut, Mo–Fr, 7 Tage Vorlauf' );

		// ---------------------------------------------------------------- several items and locations in one timeframe
		// (bookable timeframes take exactly one item and location, lists are only allowed for holidays)
		$itemA     = $this->item( 'Liste A' );
		$itemB     = $this->item( 'Liste B' );
		$locationA = $this->location( 'Liste Standort 1' );
		$locationB = $this->location( 'Liste Standort 2' );
		$this->timeframe( $locationA, $itemA, [ 'title' => 'Liste A täglich' ] );
		// an item can only be bookable at one location at a time: B moves from location 1 to 2 on day 21
		$this->timeframe(
			$locationA,
			$itemB,
			[
				'title' => 'Liste B Mo–Fr bis Tag 20',
				'repetition' => 'w',
				'weekdays' => $workdays,
				'end' => $this->day( 20 ),
			]
		);
		$this->timeframe(
			$locationB,
			$itemB,
			[
				'title' => 'Liste B ab Tag 21 Sa+So an Standort 2',
				'repetition' => 'w',
				'weekdays' => [ '6', '7' ],
				'start' => $this->day( 21 ),
			]
		);
		$this->timeframe(
			[ $locationA, $locationB ],
			[ $itemA, $itemB ],
			[
				'title' => 'Feiertag Tag 6–7 für zwei Artikel und Standorte',
				'type' => Timeframe::HOLIDAYS_ID,
				'start' => $this->day( 6 ),
				'end' => $this->day( 7 ),
			]
		);
		$this->booking( $locationA, $itemA, 1, 2 );
		$this->add( 'holiday_list_a', $itemA, $locationA, 'Artikel A täglich; Feiertag (Liste mit 2 Artikeln und 2 Standorten) Tag 6–7; Tag 1–2 gebucht' );
		$this->add( 'holiday_list_b1', $itemB, $locationA, 'Artikel B Mo–Fr an Standort 1 bis Tag 20, Feiertag aus der Liste' );
		$this->add( 'holiday_list_b2', $itemB, $locationB, 'derselbe Artikel B ab Tag 21 am Wochenende an Standort 2 (Standortwechsel)' );

		// ---------------------------------------------------------------- holidays, repairs, restrictions
		[ $item, $location ] = $this->station( 'Feiertage' );
		$this->timeframe( $location, $item, [ 'title' => 'Täglich, mit Feiertagen' ] );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Feiertag Tag 3',
				'type' => Timeframe::HOLIDAYS_ID,
				'start' => $this->day( 3 ),
				'end' => $this->day( 3 ),
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Ruhetag montags',
				'type' => Timeframe::HOLIDAYS_ID,
				'repetition' => 'w',
				'weekdays' => [ '1' ],
			]
		);
		$this->add( 'holidays', $item, $location, 'Feiertag an Tag 3 und wöchentlicher Ruhetag Montag' );

		[ $item, $location ] = $this->station(
			'Feiertage überbuchbar',
			[
				COMMONSBOOKING_METABOX_PREFIX . 'allow_lockdays_in_range' => 'on',
				COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_in_range' => 'on',
				COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_maximum'  => '1',
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Täglich, Schließtage überbuchbar',
				'max_days' => 5,
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Geschlossen Tag 5–6',
				'type' => Timeframe::HOLIDAYS_ID,
				'start' => $this->day( 5 ),
				'end' => $this->day( 6 ),
			]
		);
		$this->booking( $location, $item, 4, 7 );
		$this->add( 'holidays_overbookable', $item, $location, 'Standort erlaubt Buchung über Schließtage (zählt max. 1), Buchung Tag 4–7 über Schließtage' );

		[ $item, $location ] = $this->station( 'Reparatur' );
		$this->timeframe( $location, $item, [ 'title' => 'Täglich, mit Reparatur' ] );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Reparatur Tag 2–3',
				'type' => Timeframe::REPAIR_ID,
				'start' => $this->day( 2 ),
				'end' => $this->day( 3 ),
			]
		);
		$this->add( 'repair_timeframe', $item, $location, 'Reparatur-Zeitrahmen Tag 2–3' );

		[ $item, $location ] = $this->station( 'Einschränkungen' );
		$this->timeframe( $location, $item, [ 'title' => 'Täglich, mit Einschränkungen' ] );
		$this->restriction( Restriction::TYPE_REPAIR, $location, $item, $this->day( 2, '08:00' ), $this->day( 4, '20:00' ), Restriction::STATE_ACTIVE, 'Totalausfall Tag 2–4' );
		$this->restriction( Restriction::TYPE_HINT, $location, $item, $this->day( 6 ), $this->day( 9, '23:59' ), Restriction::STATE_ACTIVE, 'Hinweis Tag 6–9' );
		$this->restriction( Restriction::TYPE_REPAIR, $location, $item, $this->day( 12 ), $this->day( 13, '23:59' ), Restriction::STATE_SOLVED, 'Behobener Ausfall Tag 12–13' );
		$this->add( 'restrictions', $item, $location, 'Totalausfall Tag 2–4, Hinweis Tag 6–9, behobener Ausfall Tag 12–13' );

		[ $item, $location ] = $this->station( 'Standort-Ausfall' );
		$this->timeframe( $location, $item, [ 'title' => 'Täglich, Standort-Einschränkung' ] );
		$this->restriction( Restriction::TYPE_REPAIR, $location, 0, $this->day( 1 ), $this->day( 2, '23:59' ), Restriction::STATE_ACTIVE, 'Standort geschlossen Tag 1–2 (alle Artikel)' );
		$this->add( 'restriction_location', $item, $location, 'Einschränkung für den ganzen Standort Tag 1–2' );

		// ---------------------------------------------------------------- posts that must not count
		[ $item, $location ] = $this->station( 'Entwurf und Papierkorb' );
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Mo–Mi veröffentlicht',
				'repetition' => 'w',
				'weekdays' => [ '1', '2', '3' ],
			]
		);
		$this->timeframe(
			$location,
			$item,
			[
				'title' => 'Do–So Entwurf',
				'repetition' => 'w',
				'weekdays' => [ '4', '5', '6', '7' ],
				'status' => 'draft',
			]
		);
		$trashed = $this->timeframe(
			$location,
			$item,
			[
				'title' => 'Do–So gelöscht',
				'repetition' => 'w',
				'weekdays' => [ '4', '5', '6', '7' ],
			]
		);
		wp_trash_post( $trashed );
		$this->add( 'draft_and_trash', $item, $location, 'Mo–Mi veröffentlicht; Do–So als Entwurf und im Papierkorb (dürfen nicht zählen)' );

		if ( $globalHoliday ) {
			$this->timeframe(
				0,
				0,
				[
					'title' => 'Feiertag für alle Tag 20',
					'type' => Timeframe::HOLIDAYS_ID,
					'start' => $this->day( 20 ),
					'end' => $this->day( 20 ),
					'selection' => TimeframeModel::SELECTION_ALL_ID,
				]
			);
		}
	}
}
