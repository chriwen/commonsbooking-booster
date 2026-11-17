<?php

namespace CommonsBooking\Service;

use CommonsBooking\Model\Booking as BookingModel;
use CommonsBooking\Model\Day;
use CommonsBooking\Model\Restriction as RestrictionModel;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Repository\Booking as BookingRepository;
use CommonsBooking\Service\Availability\DayFacts;
use CommonsBooking\Repository\Restriction as RestrictionRepository;
use CommonsBooking\Repository\Timeframe as TimeframeRepository;
use CommonsBooking\Wordpress\CustomPostType\Restriction as RestrictionCPT;
use CommonsBooking\Wordpress\CustomPostType\Timeframe as TimeframeCPT;

/**
 * Occupancy plan of a location: per item, on which days it is bookable, blocked (holidays, repair, restrictions)
 * and booked by whom. Read-only, for admins and CB managers.
 *
 * Which timeframe applies on which day is decided by the same rules as the booking calendar
 * ({@see Day::isInTimeframe()}, {@see TimeframeRepository::filterTimeframesForTimerange()}), but without the
 * booking window, so that days beyond the advance booking period are shown too.
 *
 * Result:
 * - days: list of Y-m-d
 * - today: Y-m-d
 * - station: ['lanes' => int, 'segments' => segment[]] for blocks that affect every item of the location
 * - rows: list of ['item_id', 'title', 'edit_url', 'lanes', 'window_end' (Y-m-d|null), 'segments' => segment[]]
 * - posts: post id => ['kind', 'title', 'type_label', 'details' => [[label, value], ...], 'edit_url']
 *
 * Segment: ['kind' => bookable|holiday|repair|restriction|booking, 'post_id', 'from', 'to' (day indexes, inclusive),
 * 'clip_start', 'clip_end' (bool, continues outside the range), 'lane', 'label', 'state'].
 *
 * @see design/belegungsplan.md
 */
class OccupancyPlan {

	public const VIEW_LOCATION = 'location';
	public const VIEW_ITEM     = 'item';

	public const KIND_BOOKABLE    = 'bookable';
	public const KIND_HOLIDAY     = 'holiday';
	public const KIND_REPAIR      = 'repair';
	public const KIND_RESTRICTION = 'restriction';
	public const KIND_BOOKING     = 'booking';

	/**
	 * Maximum number of days per plan.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Kinds that may be moved to the station row when they affect every item in the same way.
	 */
	private const STATION_KINDS = [ self::KIND_HOLIDAY, self::KIND_REPAIR, self::KIND_RESTRICTION ];

	/**
	 * Lane order: blocks first, then bookings.
	 */
	private const LANE_ORDER = [
		self::KIND_REPAIR      => 0,
		self::KIND_RESTRICTION => 1,
		self::KIND_HOLIDAY     => 2,
		self::KIND_BOOKING     => 3,
	];

	/**
	 * @var string[] Y-m-d
	 */
	private array $days = [];

	/**
	 * @var array<string, int> Y-m-d => index
	 */
	private array $dayIndex = [];

	private int $startTs;

	private int $endTs;

	/**
	 * @var array<int, array>
	 */
	private array $posts = [];

	/**
	 * Builds the plan of a location for a date range: one row per item.
	 *
	 * @param int        $locationId
	 * @param string     $from Y-m-d
	 * @param string     $to Y-m-d, inclusive; limited to {@see self::MAX_DAYS} days
	 * @param bool       $withCanceled include canceled bookings
	 * @param int[]|null $onlyRows item ids to show (e.g. of a category), null = all
	 *
	 * @return array
	 */
	public static function build( int $locationId, string $from, string $to, bool $withCanceled = false, ?array $onlyRows = null ): array {
		$plan = new self( $from, $to );

		return $plan->buildFor( self::VIEW_LOCATION, $locationId, $withCanceled, $onlyRows, true );
	}

	/**
	 * Builds the plan of an item for a date range: one row per location.
	 *
	 * @param int        $itemId
	 * @param string     $from Y-m-d
	 * @param string     $to Y-m-d, inclusive
	 * @param bool       $withCanceled
	 * @param int[]|null $onlyRows location ids to show, null = all
	 *
	 * @return array
	 */
	public static function buildForItem( int $itemId, string $from, string $to, bool $withCanceled = false, ?array $onlyRows = null ): array {
		$plan = new self( $from, $to );

		return $plan->buildFor( self::VIEW_ITEM, $itemId, $withCanceled, $onlyRows, true );
	}

	/**
	 * Explains one day: per row the result as the booking calendar sees it and all reasons (timeframes, restrictions,
	 * bookings) on that day.
	 *
	 * @param string   $view {@see self::VIEW_LOCATION} or {@see self::VIEW_ITEM}
	 * @param int      $id location or item id
	 * @param string   $date Y-m-d
	 * @param int|null $rowId only this row (item id in the location view, location id in the item view)
	 *
	 * @return array{date: string, rows: array}
	 */
	public static function explainDay( string $view, int $id, string $date, ?int $rowId = null ): array {
		[ $date ] = self::normalizeRange( $date, $date );
		$plan     = new self( $date, $date );
		$result   = $plan->buildFor( $view, $id, false, $rowId ? [ $rowId ] : null, false );

		$rows = [];
		foreach ( $result['rows'] as $row ) {
			$reasons = [];
			foreach ( $row['segments'] as $segment ) {
				$post      = $result['posts'][ $segment['post_id'] ] ?? [];
				$reasons[] = [
					'kind'       => $segment['kind'],
					'state'      => $segment['state'],
					'type_label' => $post['type_label'] ?? '',
					'title'      => $post['title'] ?? '',
					'details'    => $post['details'] ?? [],
					'conflicts'  => $post['conflicts'] ?? [],
					'edit_url'   => $post['edit_url'] ?? null,
				];
			}
			usort( $reasons, fn( $a, $b ) => ( self::LANE_ORDER[ $a['kind'] ] ?? -1 ) <=> ( self::LANE_ORDER[ $b['kind'] ] ?? -1 ) );

			$rows[] = [
				'title'   => $row['title'],
				'result'  => self::dayResult( $row['location_id'], $row['item_id'], $date, $result['today'], $row['window_end'] ),
				'reasons' => $reasons,
			];
		}

		return [
			'date' => $date,
			'rows' => $rows,
		];
	}

	/**
	 * Result of a day as the booking calendar sees it (same grid evaluation via {@see DayFacts}), plus notes on the
	 * rules that depend on the current date.
	 *
	 * @param int         $locationId
	 * @param int         $itemId
	 * @param string      $date
	 * @param string      $today
	 * @param string|null $windowEnd last day of the booking window
	 *
	 * @return array{key: string, label: string, tone: string, notes: string[]}
	 */
	public static function dayResult( int $locationId, int $itemId, string $date, string $today, ?string $windowEnd ): array {
		$facts = DayFacts::fromGrid( ( new Day( $date, [ $locationId ], [ $itemId ] ) )->getGrid() );

		if ( ! $facts->has( DayFacts::HAS_SLOTS ) ) {
			$key   = 'none';
			$label = __( 'Not bookable: no bookable timeframe', 'commonsbooking' );
			$tone  = 'neutral';
		} elseif ( $facts->has( DayFacts::HOLIDAY ) ) {
			$key   = 'holiday';
			$label = __( 'Not bookable: holidays or location closed', 'commonsbooking' );
			$tone  = 'warn';
		} elseif ( $facts->has( DayFacts::BOOKED_DAY ) ) {
			$key   = 'booked';
			$label = __( 'Booked or blocked for the whole day', 'commonsbooking' );
			$tone  = 'bad';
		} elseif ( $facts->has( DayFacts::ALL_LOCKED ) ) {
			$key   = 'locked';
			$label = __( 'Not bookable: all slots locked', 'commonsbooking' );
			$tone  = 'bad';
		} elseif ( $facts->has( DayFacts::PARTIALLY_BOOKED ) ) {
			$key   = 'partial';
			$label = __( 'Partially booked', 'commonsbooking' );
			$tone  = 'warn';
		} else {
			$key   = 'free';
			$label = __( 'Bookable', 'commonsbooking' );
			$tone  = 'ok';
		}

		$notes = [];
		if ( $date < $today ) {
			$notes[] = __( 'In the past: cannot be selected in the calendar.', 'commonsbooking' );
		} elseif ( $windowEnd !== null && $date > $windowEnd && $key !== 'none' ) {
			$notes[] = sprintf(
				/* translators: %s: date */
				__( 'Beyond the advance booking period (currently bookable until %s): shown as not bookable in the calendar.', 'commonsbooking' ),
				self::formatDate( strtotime( $windowEnd ) )
			);
		}

		return [
			'key'   => $key,
			'label' => $label,
			'tone'  => $tone,
			'notes' => $notes,
		];
	}

	/**
	 * Active combinations: locations and items that have at least one published bookable timeframe that has not ended
	 * yet (running or upcoming), where both the location and the item are published. Drafts and posts without such a
	 * timeframe are not included.
	 *
	 * @return array{locations: int[], items: int[]}
	 */
	public static function activeIds(): array {
		$timeframes = TimeframeRepository::getInRange(
			(int) strtotime( current_time( 'Y-m-d' ) ),
			null,
			[],
			[],
			[ TimeframeCPT::BOOKABLE_ID ],
			true,
			[ 'publish' ]
		);
		$locations  = [];
		$items      = [];
		foreach ( $timeframes as $timeframe ) {
			$timeframeLocations = array_filter( array_map( 'intval', $timeframe->getLocationIDs() ), fn( $id ) => get_post_status( $id ) === 'publish' );
			$timeframeItems     = array_filter( array_map( 'intval', $timeframe->getItemIDs() ), fn( $id ) => get_post_status( $id ) === 'publish' );
			if ( ! $timeframeLocations || ! $timeframeItems ) {
				continue;
			}
			foreach ( $timeframeLocations as $id ) {
				$locations[ $id ] = $id;
			}
			foreach ( $timeframeItems as $id ) {
				$items[ $id ] = $id;
			}
		}

		return [
			'locations' => array_values( $locations ),
			'items'     => array_values( $items ),
		];
	}

	/**
	 * Normalizes a date range: valid dates, from <= to, at most {@see self::MAX_DAYS} days.
	 *
	 * @param string $from
	 * @param string $to
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function normalizeRange( string $from, string $to ): array {
		$fromTs = self::parseDate( $from ) ?? (int) strtotime( current_time( 'Y-m-d' ) );
		$toTs   = self::parseDate( $to ) ?? strtotime( '+55 days', $fromTs );
		if ( $toTs < $fromTs ) {
			$toTs = $fromTs;
		}
		$maxTs = strtotime( '+' . ( self::MAX_DAYS - 1 ) . ' days', $fromTs );
		if ( $toTs > $maxTs ) {
			$toTs = $maxTs;
		}

		return [ gmdate( 'Y-m-d', $fromTs ), gmdate( 'Y-m-d', $toTs ) ];
	}

	/**
	 * @param string $date
	 *
	 * @return int|null timestamp of the day or null if not a valid Y-m-d date
	 */
	private static function parseDate( string $date ): ?int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return strtotime( $date );
	}

	private function __construct( string $from, string $to ) {
		[ $from, $to ] = self::normalizeRange( $from, $to );
		$this->startTs = strtotime( $from );
		$this->endTs   = strtotime( $to . ' +1 day' ) - 1;
		for ( $ts = $this->startTs; $ts <= $this->endTs; $ts = strtotime( '+1 day', $ts ) ) {
			$date                    = gmdate( 'Y-m-d', $ts );
			$this->dayIndex[ $date ] = count( $this->days );
			$this->days[]            = $date;
		}
	}

	/**
	 * @param string     $view
	 * @param int        $id location id (location view) or item id (item view)
	 * @param bool       $withCanceled
	 * @param int[]|null $onlyRows
	 * @param bool       $hoist move blocks that affect every row to the station row
	 *
	 * @return array
	 */
	private function buildFor( string $view, int $id, bool $withCanceled, ?array $onlyRows, bool $hoist ): array {
		$isItemView = $view === self::VIEW_ITEM;
		$bookings   = $isItemView ? $this->loadBookings( null, $id, $withCanceled ) : $this->loadBookings( $id, null, $withCanceled );
		$rowIds     = array_unique( array_merge( $this->bookableRowIds( $view, $id ), array_keys( $bookings ) ) );
		if ( $onlyRows !== null ) {
			$rowIds = array_intersect( $rowIds, array_map( 'intval', $onlyRows ) );
		}

		$rows = [];
		foreach ( $rowIds as $rowId ) {
			$rowPost = get_post( $rowId );
			if ( ! $rowPost ) {
				continue;
			}
			$rows[] = $isItemView
				? $this->buildRow( $rowId, $id, $rowPost, $bookings[ $rowId ] ?? [] )
				: $this->buildRow( $id, $rowId, $rowPost, $bookings[ $rowId ] ?? [] );
		}
		usort( $rows, fn( $a, $b ) => strnatcasecmp( $a['title'], $b['title'] ) );

		$station = $hoist ? $this->extractStationSegments( $rows ) : [];

		foreach ( $rows as &$row ) {
			$row['lanes'] = self::assignLanes( $row['segments'] );
		}
		unset( $row );
		$stationLanes = self::assignLanes( $station );

		return [
			'view'    => $view,
			'days'    => $this->days,
			'today'   => current_time( 'Y-m-d' ),
			'station' => [
				'lanes'    => $stationLanes,
				'segments' => $station,
			],
			'rows'    => $rows,
			'posts'   => $this->posts,
		];
	}

	/**
	 * Rows with a bookable timeframe in the range: items at the location, or locations of the item.
	 *
	 * @param string $view
	 * @param int    $id
	 *
	 * @return int[]
	 */
	private function bookableRowIds( string $view, int $id ): array {
		$isItemView = $view === self::VIEW_ITEM;
		$timeframes = TimeframeRepository::getInRange(
			$this->startTs,
			$this->endTs,
			$isItemView ? [] : [ $id ],
			$isItemView ? [ $id ] : [],
			[ TimeframeCPT::BOOKABLE_ID ],
			true,
			[ 'publish' ]
		);
		$ids        = [];
		foreach ( $timeframes as $timeframe ) {
			foreach ( ( $isItemView ? $timeframe->getLocationIDs() : $timeframe->getItemIDs() ) as $rowId ) {
				$ids[] = (int) $rowId;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Bookings overlapping the range, by row: by item for a location, by location for an item.
	 *
	 * @param int|null $locationId
	 * @param int|null $itemId
	 * @param bool     $withCanceled
	 *
	 * @return array<int, BookingModel[]>
	 */
	private function loadBookings( ?int $locationId, ?int $itemId, bool $withCanceled ): array {
		$statuses = [ 'confirmed', 'unconfirmed' ];
		if ( $withCanceled ) {
			$statuses[] = 'canceled';
		}
		$bookings = BookingRepository::getByTimerange( $this->startTs, $this->endTs, $locationId, $itemId, [], $statuses );
		$byRow    = [];
		foreach ( $bookings as $booking ) {
			$rowId = $itemId ? (int) $booking->getLocationID() : (int) $booking->getItemID();
			if ( $rowId ) {
				$byRow[ $rowId ][] = $booking;
			}
		}

		return $byRow;
	}

	/**
	 * @param int            $locationId
	 * @param int            $itemId
	 * @param \WP_Post       $rowPost item (location view) or location (item view)
	 * @param BookingModel[] $bookings
	 *
	 * @return array
	 */
	private function buildRow( int $locationId, int $itemId, \WP_Post $rowPost, array $bookings ): array {
		$segments  = [];
		$windowEnd = null;

		$timeframes = TimeframeRepository::getInRange(
			$this->startTs,
			$this->endTs,
			[ $locationId ],
			[ $itemId ],
			[ TimeframeCPT::BOOKABLE_ID, TimeframeCPT::HOLIDAYS_ID, TimeframeCPT::REPAIR_ID ],
			true,
			[ 'confirmed', 'publish' ]
		);

		foreach ( $timeframes as $timeframe ) {
			$kind = self::timeframeKind( $timeframe );
			if ( $kind === null ) {
				continue;
			}
			$this->addTimeframePost( $timeframe, $kind );
			foreach ( $this->runs( $this->timeframeDays( $timeframe ) ) as [ $from, $to ] ) {
				$segments[] = $this->segment( $kind, $timeframe->ID, $from, $to, $timeframe->post_title, $timeframe->post_status );
			}

			if ( $kind === self::KIND_BOOKABLE ) {
				$windowEnd = self::laterDate( $windowEnd, $this->bookingWindowEnd( $timeframe ) );
			}
		}

		foreach ( $this->restrictions( $locationId, $itemId ) as $restriction ) {
			$this->addRestrictionPost( $restriction );
			$range = $this->clip( $restriction->getStartDate(), $restriction->hasEnddate() ? $restriction->getEndDate() : null );
			if ( $range ) {
				$segments[] = $this->segment( self::KIND_RESTRICTION, $restriction->ID, $range[0], $range[1], $restriction->getHint() ?: $restriction->post_title, self::restrictionState( $restriction ), $range[2], $range[3] );
			}
		}

		foreach ( $bookings as $booking ) {
			$this->addBookingPost( $booking );
			$range = $this->clip( $booking->getStartDate(), $booking->getEndDate() );
			if ( $range ) {
				$segments[] = $this->segment( self::KIND_BOOKING, $booking->ID, $range[0], $range[1], self::bookingLabel( $booking ), $booking->post_status, $range[2], $range[3] );
			}
		}

		$conflicts = $this->markConflicts( $segments );

		return [
			'item_id'     => $itemId,
			'location_id' => $locationId,
			'row_id'      => $rowPost->ID,
			'title'       => $rowPost->post_title,
			'edit_url'    => self::editUrl( $rowPost ),
			'lanes'       => 1,
			'window_end'  => $windowEnd,
			'conflicts'   => $conflicts,
			'segments'    => $segments,
		];
	}

	/**
	 * Marks bookings that do not fit the timeframes: no bookable timeframe or closed on the pickup or return day,
	 * or overlapping a block that cannot be overbooked (repair timeframe, active total breakdown).
	 * Only days inside the range are checked. The messages are stored with the booking post.
	 *
	 * @param array $segments segments of one row
	 *
	 * @return int number of bookings with conflicts
	 */
	private function markConflicts( array &$segments ): int {
		$bookable = [];
		$closed   = [];
		$blocked  = [];
		foreach ( $segments as $segment ) {
			for ( $i = $segment['from']; $i <= $segment['to']; $i++ ) {
				if ( $segment['kind'] === self::KIND_BOOKABLE ) {
					$bookable[ $i ] = true;
				} elseif ( $segment['kind'] === self::KIND_HOLIDAY ) {
					$closed[ $i ] = $segment['label'];
				} elseif ( $segment['kind'] === self::KIND_REPAIR || ( $segment['kind'] === self::KIND_RESTRICTION && $segment['state'] === RestrictionModel::TYPE_REPAIR . ':' . RestrictionModel::STATE_ACTIVE ) ) {
					$blocked[ $i ] = $segment['label'];
				}
			}
		}

		$count = 0;
		foreach ( $segments as &$segment ) {
			if ( $segment['kind'] !== self::KIND_BOOKING || $segment['state'] === 'canceled' ) {
				continue;
			}
			$messages = [];
			$ends     = [];
			if ( ! $segment['clip_start'] ) {
				$ends[ $segment['from'] ] = __( 'pickup day', 'commonsbooking' );
			}
			if ( ! $segment['clip_end'] ) {
				$ends[ $segment['to'] ] = isset( $ends[ $segment['to'] ] ) ? __( 'pickup and return day', 'commonsbooking' ) : __( 'return day', 'commonsbooking' );
			}
			foreach ( $ends as $day => $what ) {
				if ( ! isset( $bookable[ $day ] ) ) {
					/* translators: %s: "pickup day" or "return day" */
					$messages[] = sprintf( __( 'No bookable timeframe on the %s.', 'commonsbooking' ), $what );
				} elseif ( isset( $closed[ $day ] ) ) {
					/* translators: 1: "pickup day" or "return day", 2: name of the timeframe */
					$messages[] = sprintf( __( 'Location closed on the %1$s: %2$s', 'commonsbooking' ), $what, $closed[ $day ] );
				}
			}
			$blocks = [];
			for ( $i = $segment['from']; $i <= $segment['to']; $i++ ) {
				if ( isset( $blocked[ $i ] ) ) {
					$blocks[ $blocked[ $i ] ] = true;
				}
			}
			foreach ( array_keys( $blocks ) as $block ) {
				/* translators: %s: name of the block or restriction */
				$messages[] = sprintf( __( 'Overlaps a block that cannot be overbooked: %s', 'commonsbooking' ), $block );
			}
			$segment['conflict'] = (bool) $messages;
			if ( $messages ) {
				++$count;
				$this->posts[ $segment['post_id'] ]['conflicts'] = $messages;
			}
		}
		unset( $segment );

		return $count;
	}

	/**
	 * Day indexes on which the timeframe applies, by the calendar rules, ignoring the booking window.
	 *
	 * @param TimeframeModel $timeframe
	 *
	 * @return int[]
	 */
	private function timeframeDays( TimeframeModel $timeframe ): array {
		return TimeframeModel::withoutBookingWindow(
			function () use ( $timeframe ) {
				$indexes = [];
				foreach ( $this->days as $index => $date ) {
					$dayStart = strtotime( $date );
					$dayEnd   = strtotime( $date . ' +1 day' ) - 1;
					if ( ! TimeframeRepository::filterTimeframesForTimerange( [ $timeframe ], $dayStart, $dayEnd ) ) {
						continue;
					}
					if ( ( new Day( $date ) )->isInTimeframe( $timeframe ) ) {
						$indexes[] = $index;
					}
				}

				return $indexes;
			}
		);
	}

	/**
	 * Groups sorted day indexes into runs of consecutive days.
	 *
	 * @param int[] $indexes
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function runs( array $indexes ): array {
		$runs = [];
		foreach ( $indexes as $index ) {
			$last = count( $runs ) - 1;
			if ( $last >= 0 && $runs[ $last ][1] === $index - 1 ) {
				$runs[ $last ][1] = $index;
			} else {
				$runs[] = [ $index, $index ];
			}
		}

		return $runs;
	}

	/**
	 * Clips a period (timestamps) to the range.
	 *
	 * @param int      $start
	 * @param int|null $end null = open end
	 *
	 * @return array{0: int, 1: int, 2: bool, 3: bool}|null from index, to index, clipped at start, clipped at end
	 */
	private function clip( int $start, ?int $end ): ?array {
		if ( $start > $this->endTs || ( $end !== null && $end < $this->startTs ) ) {
			return null;
		}
		$clipStart = $start < $this->startTs;
		$clipEnd   = $end === null || $end > $this->endTs;
		$from      = $clipStart ? 0 : $this->dayIndex[ gmdate( 'Y-m-d', $start ) ];
		$to        = $clipEnd ? count( $this->days ) - 1 : $this->dayIndex[ gmdate( 'Y-m-d', $end ) ];

		return [ $from, $to, $clipStart, $clipEnd ];
	}

	/**
	 * Restrictions of the item at the location overlapping the range (same statuses as the calendar).
	 *
	 * @param int $locationId
	 * @param int $itemId
	 *
	 * @return RestrictionModel[]
	 */
	private function restrictions( int $locationId, int $itemId ): array {
		$restrictions = RestrictionRepository::get( [ $locationId ], [ $itemId ], null, true, $this->startTs - 1, Day::RESTRICTION_STATUSES );

		return array_values(
			array_filter(
				$restrictions,
				fn( RestrictionModel $restriction ) => $restriction->getMeta( RestrictionModel::META_START ) !== '' && $restriction->getStartDate() <= $this->endTs
			)
		);
	}

	/**
	 * @param string $kind
	 * @param int    $postId
	 * @param int    $from
	 * @param int    $to
	 * @param string $label
	 * @param string $state
	 * @param bool   $clipStart
	 * @param bool   $clipEnd
	 *
	 * @return array
	 */
	private function segment( string $kind, int $postId, int $from, int $to, string $label, string $state, ?bool $clipStart = null, ?bool $clipEnd = null ): array {
		return [
			'kind'       => $kind,
			'post_id'    => $postId,
			'from'       => $from,
			'to'         => $to,
			'clip_start' => $clipStart ?? false,
			'clip_end'   => $clipEnd ?? false,
			'lane'       => 0,
			'label'      => $label,
			'state'      => $state,
			'conflict'   => false,
		];
	}

	/**
	 * Moves blocks (holidays, repair, restrictions) that occur identically in every row to the station row.
	 * Needs at least two rows, otherwise there is nothing to save.
	 *
	 * @param array $rows
	 *
	 * @return array station segments
	 */
	private function extractStationSegments( array &$rows ): array {
		if ( count( $rows ) < 2 ) {
			return [];
		}
		$key    = fn( array $s ) => $s['kind'] . ':' . $s['post_id'] . ':' . $s['from'] . ':' . $s['to'];
		$common = null;
		foreach ( $rows as $row ) {
			$keys = [];
			foreach ( $row['segments'] as $segment ) {
				if ( in_array( $segment['kind'], self::STATION_KINDS, true ) ) {
					$keys[ $key( $segment ) ] = $segment;
				}
			}
			$common = $common === null ? $keys : array_intersect_key( $common, $keys );
		}
		if ( ! $common ) {
			return [];
		}
		foreach ( $rows as &$row ) {
			$row['segments'] = array_values( array_filter( $row['segments'], fn( $s ) => ! isset( $common[ $key( $s ) ] ) ) );
		}
		unset( $row );

		return array_values( $common );
	}

	/**
	 * Assigns lanes to overlay segments (all except bookable) so that they do not overlap: blocks first, each segment
	 * in the first lane where it fits.
	 * Bookable segments stay in lane 0 and are drawn behind all lanes.
	 *
	 * @param array $segments
	 *
	 * @return int number of lanes (at least 1)
	 */
	public static function assignLanes( array &$segments ): int {
		$order = array_keys( $segments );
		usort(
			$order,
			function ( $a, $b ) use ( $segments ) {
				$sa = $segments[ $a ];
				$sb = $segments[ $b ];

				return [ self::LANE_ORDER[ $sa['kind'] ] ?? -1, $sa['from'], $sa['to'] ] <=> [ self::LANE_ORDER[ $sb['kind'] ] ?? -1, $sb['from'], $sb['to'] ];
			}
		);
		$lanes = [];
		foreach ( $order as $i ) {
			if ( $segments[ $i ]['kind'] === self::KIND_BOOKABLE ) {
				$segments[ $i ]['lane'] = 0;
				continue;
			}
			$from = $segments[ $i ]['from'];
			$to   = $segments[ $i ]['to'];
			$lane = 0;
			while ( isset( $lanes[ $lane ] ) && self::overlapsAny( $lanes[ $lane ], $from, $to ) ) {
				++$lane;
			}
			$lanes[ $lane ][]       = [ $from, $to ];
			$segments[ $i ]['lane'] = $lane;
		}

		return max( 1, count( $lanes ) );
	}

	/**
	 * @param array<int, array{0: int, 1: int}> $ranges
	 * @param int                               $from
	 * @param int                               $to
	 *
	 * @return bool
	 */
	private static function overlapsAny( array $ranges, int $from, int $to ): bool {
		foreach ( $ranges as [ $start, $end ] ) {
			if ( $from <= $end && $to >= $start ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param TimeframeModel $timeframe
	 *
	 * @return string|null
	 */
	private static function timeframeKind( TimeframeModel $timeframe ): ?string {
		switch ( $timeframe->getType() ) {
			case TimeframeCPT::BOOKABLE_ID:
				return self::KIND_BOOKABLE;
			case TimeframeCPT::HOLIDAYS_ID:
				return self::KIND_HOLIDAY;
			case TimeframeCPT::REPAIR_ID:
				return self::KIND_REPAIR;
		}

		return null;
	}

	/**
	 * Last day of the booking window of a bookable timeframe (advance booking days), null if it is not limited.
	 *
	 * @param TimeframeModel $timeframe
	 *
	 * @return string|null Y-m-d
	 */
	private function bookingWindowEnd( TimeframeModel $timeframe ): ?string {
		if ( ! $timeframe->getMeta( TimeframeModel::META_TIMEFRAME_ADVANCE_BOOKING_DAYS ) ) {
			return null;
		}
		$latest = $timeframe->getLatestPossibleBookingDateTimestamp();
		$end    = $timeframe->getTimeframeEndDate();
		if ( $end && $end <= $latest ) {
			return null; // the timeframe ends before, so the window does not limit anything
		}

		return gmdate( 'Y-m-d', $latest );
	}

	/**
	 * @param string|null $a
	 * @param string|null $b
	 *
	 * @return string|null
	 */
	private static function laterDate( ?string $a, ?string $b ): ?string {
		if ( $a === null ) {
			return $b;
		}
		if ( $b === null ) {
			return $a;
		}

		return max( $a, $b );
	}

	/**
	 * @param RestrictionModel $restriction
	 *
	 * @return string type:state, e.g. "repair:active"
	 */
	private static function restrictionState( RestrictionModel $restriction ): string {
		return $restriction->getType() . ':' . $restriction->getMeta( RestrictionModel::META_STATE );
	}

	/**
	 * @param BookingModel $booking
	 *
	 * @return string name of the booking user
	 */
	private static function bookingLabel( BookingModel $booking ): string {
		$user = $booking->getUserData();

		return $user ? $user->display_name : __( 'Unknown user', 'commonsbooking' );
	}

	/**
	 * @param \WP_Post|\CommonsBooking\Model\CustomPost $post
	 *
	 * @return string|null edit link if the current user may edit the post
	 */
	public static function editUrl( $post ): ?string {
		$wpPost = $post instanceof \WP_Post ? $post : $post->getPost();
		if ( ! commonsbooking_isCurrentUserAllowedToEdit( $wpPost ) ) {
			return null;
		}

		return get_edit_post_link( $wpPost->ID, 'url' ) ?: null;
	}

	/**
	 * @param int $timestamp
	 *
	 * @return string
	 */
	private static function formatDate( int $timestamp ): string {
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * @param int $timestamp
	 *
	 * @return string
	 */
	private static function formatDateTime( int $timestamp ): string {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * @param TimeframeModel $timeframe
	 * @param string         $kind
	 *
	 * @return void
	 */
	private function addTimeframePost( TimeframeModel $timeframe, string $kind ): void {
		if ( isset( $this->posts[ $timeframe->ID ] ) ) {
			return;
		}
		$types       = TimeframeCPT::getTypes();
		$repetitions = TimeframeCPT::getTimeFrameRepetitions();
		$end         = $timeframe->getTimeframeEndDate();
		$details     = [
			[ __( 'Period', 'commonsbooking' ), self::formatDate( $timeframe->getStartDate() ) . ' – ' . ( $end ? self::formatDate( $end ) : __( 'open end', 'commonsbooking' ) ) ],
			[ __( 'Repetition', 'commonsbooking' ), $repetitions[ $timeframe->getRepetition() ] ?? (string) $timeframe->getRepetition() ],
		];
		if ( $timeframe->getRepetition() === 'w' ) {
			$weekdays = $timeframe->getMeta( 'weekdays' );
			if ( is_array( $weekdays ) && count( $weekdays ) < 7 ) {
				$names     = array_map( fn( $d ) => date_i18n( 'D', strtotime( 'Sunday +' . (int) $d . ' days' ) ), $weekdays );
				$details[] = [ __( 'Weekdays', 'commonsbooking' ), implode( ', ', $names ) ];
			}
		}
		if ( $timeframe->getRepetition() === 'manual' ) {
			$dates     = array_map( fn( $d ) => self::formatDate( strtotime( $d ) ), $timeframe->getManualSelectionDates() );
			$details[] = [ __( 'Dates', 'commonsbooking' ), implode( ', ', $dates ) ];
		}
		$details[] = [
			__( 'Time', 'commonsbooking' ),
			$timeframe->isFullDay() ? __( 'Full day', 'commonsbooking' ) : $timeframe->getMeta( 'start-time' ) . ' – ' . $timeframe->getMeta( 'end-time' ),
		];
		if ( $kind === self::KIND_BOOKABLE ) {
			$details[] = [ __( 'Maximum booking days', 'commonsbooking' ), (string) $timeframe->getMaxDays() ];
			$advance   = $timeframe->getMeta( TimeframeModel::META_TIMEFRAME_ADVANCE_BOOKING_DAYS );
			$details[] = [ __( 'Advance booking days', 'commonsbooking' ), $advance ? (string) $advance : __( 'not limited', 'commonsbooking' ) ];
			$roles     = $timeframe->getMeta( TimeframeModel::META_ALLOWED_USER_ROLES );
			if ( is_array( $roles ) && $roles ) {
				$roleNames = wp_roles()->get_names();
				$details[] = [ __( 'Only bookable for', 'commonsbooking' ), implode( ', ', array_map( fn( $r ) => translate_user_role( $roleNames[ $r ] ?? $r ), $roles ) ) ];
			}
		}
		if ( $timeframe->post_status !== 'publish' ) {
			$details[] = [ __( 'Status', 'commonsbooking' ), get_post_status_object( $timeframe->post_status )->label ?? $timeframe->post_status ];
		}

		$this->posts[ $timeframe->ID ] = [
			'kind'       => $kind,
			'title'      => $timeframe->post_title,
			'type_label' => $types[ $timeframe->getType() ] ?? '',
			'details'    => $details,
			'edit_url'   => self::editUrl( $timeframe ),
		];
	}

	/**
	 * @param RestrictionModel $restriction
	 *
	 * @return void
	 */
	private function addRestrictionPost( RestrictionModel $restriction ): void {
		if ( isset( $this->posts[ $restriction->ID ] ) ) {
			return;
		}
		$types  = RestrictionCPT::getTypes();
		$states = RestrictionCPT::getStates();
		$state  = $restriction->getMeta( RestrictionModel::META_STATE );

		$this->posts[ $restriction->ID ] = [
			'kind'       => self::KIND_RESTRICTION,
			'title'      => $restriction->post_title !== '' ? $restriction->post_title : ( (string) $restriction->getHint() !== '' ? (string) $restriction->getHint() : ( $types[ $restriction->getType() ] ?? '' ) ),
			'type_label' => $types[ $restriction->getType() ] ?? '',
			'details'    => [
				[ __( 'Period', 'commonsbooking' ), self::formatDateTime( $restriction->getStartDate() ) . ' – ' . ( $restriction->hasEnddate() ? self::formatDateTime( $restriction->getEndDate() ) : __( 'open end', 'commonsbooking' ) ) ],
				[ __( 'Status', 'commonsbooking' ), $states[ $state ] ?? (string) $state ],
				[ __( 'Hint', 'commonsbooking' ), (string) $restriction->getHint() ],
			],
			'edit_url'   => self::editUrl( $restriction ),
		];
	}

	/**
	 * Booking details. CB managers of the location see all bookings there (decision in design/belegungsplan.md).
	 *
	 * @param BookingModel $booking
	 *
	 * @return void
	 */
	private function addBookingPost( BookingModel $booking ): void {
		$user    = $booking->getUserData();
		$details = [
			[ __( 'Period', 'commonsbooking' ), self::formatDateTime( $booking->getStartDate() ) . ' – ' . self::formatDateTime( $booking->getEndDate() ) ],
			[ __( 'Status', 'commonsbooking' ), $booking->getStatus() ],
		];
		// email, phone and comment always, so an empty value is visible as such
		$empty     = '–';
		$phone     = $user ? trim( (string) get_user_meta( $user->ID, 'phone', true ) ) : '';
		$comment   = trim( wp_strip_all_tags( (string) $booking->getMeta( 'comment' ) ) );
		$details[] = [ __( 'Name', 'commonsbooking' ), $user ? $user->display_name : $empty ];
		$details[] = [ __( 'Email', 'commonsbooking' ), $user && $user->user_email ? $user->user_email : $empty ];
		$details[] = [ __( 'Phone', 'commonsbooking' ), $phone !== '' ? $phone : $empty ];
		$details[] = [ __( 'Comment', 'commonsbooking' ), $comment !== '' ? $comment : $empty ];
		if ( $booking->getBookingCode() ) {
			$details[] = [ __( 'Booking code', 'commonsbooking' ), (string) $booking->getBookingCode() ];
		}
		$details[] = [ __( 'Booked on', 'commonsbooking' ), self::formatDateTime( strtotime( $booking->post_date ) ) ];

		$this->posts[ $booking->ID ] = [
			'kind'       => self::KIND_BOOKING,
			'title'      => self::bookingLabel( $booking ),
			'type_label' => __( 'Booking', 'commonsbooking' ),
			'details'    => $details,
			'edit_url'   => self::editUrl( $booking ),
		];
	}
}
