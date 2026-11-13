<?php

namespace CommonsBooking\Repository;

use CommonsBooking\Model\Restriction as RestrictionModel;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Wordpress\CustomPostType\Item as ItemCPT;
use CommonsBooking\Wordpress\CustomPostType\Location as LocationCPT;
use CommonsBooking\Wordpress\CustomPostType\Restriction as RestrictionCPT;
use CommonsBooking\Wordpress\CustomPostType\Timeframe as TimeframeCPT;

/**
 * Finds all timeframes and restrictions that control an item or a location ("assigned timeframes"),
 * including multi selection, category selection and "all" for holidays / repair timeframes
 * and restrictions with 0 = all.
 *
 * Works on postmeta. If the booster index is available, it is only used to find the candidate post ids faster.
 *
 * @see design/zugeordnete-zeitrahmen.md
 */
class AssignedTimeframes {

	public const GROUP_CURRENT = 'current';
	public const GROUP_FUTURE  = 'future';
	public const GROUP_EXPIRED = 'expired';

	public const ASSIGNMENT_DIRECT   = 'direct';
	public const ASSIGNMENT_LIST     = 'list';
	public const ASSIGNMENT_CATEGORY = 'category';
	public const ASSIGNMENT_ALL      = 'all';

	/**
	 * Timeframe types that control availability. Bookings (6, 7) are never part of the overview.
	 */
	public const TIMEFRAME_TYPES = [
		TimeframeCPT::BOOKABLE_ID,
		TimeframeCPT::HOLIDAYS_ID,
		TimeframeCPT::REPAIR_ID,
	];

	/**
	 * Post statuses shown in the overview. Only 'publish' is effective, the others are shown as information.
	 */
	public const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	/**
	 * Returns the timeframes and restrictions controlling the item.
	 *
	 * @param int      $itemId
	 * @param int|null $now timestamp used for grouping (default: current local time)
	 *
	 * @return array{groups: array<string, array[]>, counterparts: int[]}
	 *
	 * Entry keys: kind (timeframe|restriction), post_id, title, status, type, type_label, sort_type, blocking,
	 * start (int|null), end (int|null = open end), repetition, weekdays (int[], 1 = Monday), manual_dates (Y-m-d),
	 * grid (full|slot|hourly|null), start_time, end_time (H:i|null), items / locations ({@see self::assignment()}),
	 * effective_at (ids of the other side at which the entry is effective), effective (bool), group, can_edit, hint.
	 */
	public static function forItem( int $itemId, ?int $now = null ): array {
		return self::forEntity( 'item', $itemId, $now );
	}

	/**
	 * Returns the timeframes and restrictions controlling the location.
	 *
	 * @param int      $locationId
	 * @param int|null $now timestamp used for grouping (default: current local time)
	 *
	 * @return array{groups: array<string, array[]>, counterparts: int[]}
	 */
	public static function forLocation( int $locationId, ?int $now = null ): array {
		return self::forEntity( 'location', $locationId, $now );
	}

	/**
	 * @param string   $entity 'item' or 'location'
	 * @param int      $entityId
	 * @param int|null $now
	 *
	 * @return array{groups: array<string, array[]>, counterparts: int[]}
	 */
	private static function forEntity( string $entity, int $entityId, ?int $now ): array {
		$now        = $now ?? current_time( 'timestamp' );
		$todayStart = strtotime( 'today', $now );
		$todayEnd   = $todayStart + DAY_IN_SECONDS - 1;

		$timeframeIds   = self::timeframeCandidateIds( $entity, $entityId );
		$restrictionIds = self::restrictionCandidateIds( $entity, $entityId );
		$allIds         = array_merge( $timeframeIds, $restrictionIds );
		if ( $allIds ) {
			_prime_post_caches( $allIds, false, true );
		}

		$timeframes = [];
		foreach ( $timeframeIds as $id ) {
			$post = get_post( $id );
			if ( $post && $post->post_type === TimeframeCPT::$postType ) {
				$timeframes[] = new TimeframeModel( $post );
			}
		}

		$counterparts = self::counterparts( $entity, $entityId, $timeframes, $todayStart );

		$entries = [];
		foreach ( $timeframes as $timeframe ) {
			$entry = self::buildTimeframeEntry( $timeframe, $entity, $entityId, $counterparts, $todayStart, $todayEnd );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
		foreach ( $restrictionIds as $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== RestrictionCPT::$postType ) {
				continue;
			}
			$entry = self::buildRestrictionEntry( new RestrictionModel( $post ), $entity, $entityId, $counterparts, $todayStart, $todayEnd );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}

		$groups = [
			self::GROUP_CURRENT => [],
			self::GROUP_FUTURE  => [],
			self::GROUP_EXPIRED => [],
		];
		foreach ( $entries as $entry ) {
			$groups[ $entry['group'] ][] = $entry;
		}
		foreach ( $groups as $group => &$list ) {
			usort(
				$list,
				function ( $a, $b ) use ( $group ) {
					// expired: most recent first, otherwise chronological; ties by type and id
					$byStart = $group === self::GROUP_EXPIRED ? ( $b['end'] ?? PHP_INT_MAX ) <=> ( $a['end'] ?? PHP_INT_MAX ) : $a['start'] <=> $b['start'];

					return $byStart ?: ( $a['sort_type'] <=> $b['sort_type'] ?: $a['post_id'] <=> $b['post_id'] );
				}
			);
		}
		unset( $list );

		return [
			'groups'       => $groups,
			'counterparts' => $counterparts,
		];
	}

	/**
	 * Returns the other timeframes that are set up for the same item / location combination as the timeframe:
	 * those sharing at least one item AND at least one location with it (the same rule that makes a timeframe
	 * control an item, see {@see self::buildTimeframeEntry()}). Bookable, holiday and repair timeframes are considered,
	 * whatever their status. The timeframe itself is not part of the result.
	 *
	 * The entries have the same keys as in {@see self::forItem()}; `effective_at` contains the shared locations.
	 *
	 * @param TimeframeModel $timeframe
	 * @param int|null       $now timestamp used for grouping (default: current local time)
	 *
	 * @return array{groups: array<string, array[]>, items: int[], locations: int[]} items / locations: those of the timeframe
	 */
	public static function forTimeframe( TimeframeModel $timeframe, ?int $now = null ): array {
		$now        = $now ?? current_time( 'timestamp' );
		$todayStart = strtotime( 'today', $now );
		$todayEnd   = $todayStart + DAY_IN_SECONDS - 1;

		$itemIds     = self::assignment( $timeframe, 'item' )['ids'];
		$locationIds = self::assignment( $timeframe, 'location' )['ids'];

		$groups = [
			self::GROUP_CURRENT => [],
			self::GROUP_FUTURE  => [],
			self::GROUP_EXPIRED => [],
		];
		$result = [
			'groups'    => $groups,
			'items'     => $itemIds,
			'locations' => $locationIds,
		];
		if ( ! $itemIds || ! $locationIds ) {
			return $result;
		}

		// Search from the smaller side (e.g. one location instead of "all items"), filter by the other one.
		$byItem     = count( $itemIds ) <= count( $locationIds );
		$searchIds  = $byItem ? $itemIds : $locationIds;
		$candidates = [];
		foreach ( $searchIds as $searchId ) {
			$candidates = array_merge( $candidates, self::timeframeCandidateIds( $byItem ? 'item' : 'location', $searchId ) );
		}
		$candidates = array_diff( array_unique( $candidates ), [ $timeframe->ID ] );
		if ( $candidates ) {
			_prime_post_caches( $candidates, false, true );
		}

		foreach ( $candidates as $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== TimeframeCPT::$postType ) {
				continue;
			}
			$other     = new TimeframeModel( $post );
			$items     = self::assignment( $other, 'item' );
			$locations = self::assignment( $other, 'location' );

			$sharedItems     = array_intersect( $itemIds, $items['ids'] );
			$sharedLocations = array_values( array_intersect( $locationIds, $locations['ids'] ) );
			if ( ! $sharedItems || ! $sharedLocations ) {
				continue;
			}
			$entry                       = self::timeframeEntry( $other, $items, $locations, $sharedLocations, $todayStart, $todayEnd );
			$groups[ $entry['group'] ][] = $entry;
		}

		foreach ( $groups as $group => &$list ) {
			usort(
				$list,
				function ( $a, $b ) use ( $group ) {
					$byStart = $group === self::GROUP_EXPIRED ? ( $b['end'] ?? PHP_INT_MAX ) <=> ( $a['end'] ?? PHP_INT_MAX ) : $a['start'] <=> $b['start'];

					return $byStart ?: ( $a['sort_type'] <=> $b['sort_type'] ?: $a['post_id'] <=> $b['post_id'] );
				}
			);
		}
		unset( $list );
		$result['groups'] = $groups;

		return $result;
	}

	/**
	 * Returns the ids of timeframes (types {@see self::TIMEFRAME_TYPES}) that have the entity in their
	 * single field (item-id / location-id) or in their list (item-id-list / location-id-list).
	 * Same matching as {@see Timeframe::getPostIdsByType()}.
	 *
	 * @param string $entity
	 * @param int    $entityId
	 *
	 * @return int[]
	 */
	public static function timeframeCandidateIds( string $entity, int $entityId ): array {
		if ( $entityId <= 0 ) {
			return [];
		}

		if ( Index::isAvailable() ) {
			$criteria = [
				'post_types' => [ TimeframeCPT::$postType ],
				'types'      => self::TIMEFRAME_TYPES,
				'statuses'   => self::STATUSES,
			];
			if ( $entity === 'item' ) {
				$criteria['items'] = [ $entityId ];
			} else {
				$criteria['locations'] = [ $entityId ];
			}
			return Index::findPostIds( $criteria );
		}

		global $wpdb;
		$singleKey = $entity === 'item' ? TimeframeModel::META_ITEM_ID : TimeframeModel::META_LOCATION_ID;
		$listKey   = $entity === 'item' ? TimeframeModel::META_ITEM_ID_LIST : TimeframeModel::META_LOCATION_ID_LIST;
		// the list is a serialized array of strings, e.g. a:2:{i:0;s:2:"12";i:1;s:2:"13";}
		$like     = '%' . $wpdb->esc_like( ':"' . $entityId . '";' ) . '%';
		$types    = implode( ',', array_map( 'intval', self::TIMEFRAME_TYPES ) );
		$statuses = implode( ',', array_map( fn( $status ) => $wpdb->prepare( '%s', $status ), self::STATUSES ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- types and statuses are prepared above
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM $wpdb->posts p
			INNER JOIN $wpdb->postmeta t ON t.post_id = p.ID AND t.meta_key = 'type' AND t.meta_value IN ($types)
			INNER JOIN $wpdb->postmeta e ON e.post_id = p.ID AND (
				( e.meta_key = %s AND e.meta_value = %s ) OR
				( e.meta_key = %s AND e.meta_value LIKE %s )
			)
			WHERE p.post_type = %s AND p.post_status IN ($statuses)
			ORDER BY p.ID",
			$singleKey,
			(string) $entityId,
			$listKey,
			$like,
			TimeframeCPT::$postType
		);
		// phpcs:enable

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the ids of published restrictions whose item (for $entity = 'item') or location
	 * (for $entity = 'location') is the entity or 0 (= all). The exact matching follows in {@see self::buildRestrictionEntry()}.
	 *
	 * @param string $entity
	 * @param int    $entityId
	 *
	 * @return int[]
	 */
	public static function restrictionCandidateIds( string $entity, int $entityId ): array {
		if ( $entityId <= 0 ) {
			return [];
		}
		global $wpdb;

		if ( Index::isAvailable() ) {
			$column = $entity === 'item' ? 'item_id' : 'location_id';
			$table  = IndexSchema::table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are fixed
			$sql = $wpdb->prepare( "SELECT post_id FROM $table WHERE post_type = %s AND post_status = 'publish' AND $column IN (0, %d) ORDER BY post_id", RestrictionCPT::$postType, $entityId );

			return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$metaKey = $entity === 'item' ? RestrictionModel::META_ITEM_ID : RestrictionModel::META_LOCATION_ID;
		$sql     = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM $wpdb->posts p
			LEFT JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = 'publish'
			AND ( m.meta_value IS NULL OR m.meta_value IN ('', '0', %s) )
			ORDER BY p.ID",
			$metaKey,
			RestrictionCPT::$postType,
			(string) $entityId
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The "other side" of the entity: the locations at which the item is bookable, or the items bookable at the location.
	 * Taken from the published bookable timeframes found for the entity that have not expired;
	 * if there are none, from all published bookable timeframes.
	 *
	 * @param string           $entity
	 * @param int              $entityId
	 * @param TimeframeModel[] $timeframes
	 * @param int              $todayStart
	 *
	 * @return int[]
	 */
	private static function counterparts( string $entity, int $entityId, array $timeframes, int $todayStart ): array {
		$active = [];
		$all    = [];
		foreach ( $timeframes as $timeframe ) {
			if ( $timeframe->getType() !== TimeframeCPT::BOOKABLE_ID || $timeframe->post_status !== 'publish' ) {
				continue;
			}
			$ids = $entity === 'item' ? $timeframe->getLocationIDs() : $timeframe->getItemIDs();
			$all = array_merge( $all, $ids );
			$end = self::endTimestamp( $timeframe );
			if ( $end === null || $end >= $todayStart ) {
				$active = array_merge( $active, $ids );
			}
		}
		$ids = $active ?: $all;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * @param TimeframeModel $timeframe
	 * @param string         $entity
	 * @param int            $entityId
	 * @param int[]          $counterparts
	 * @param int            $todayStart
	 * @param int            $todayEnd
	 *
	 * @return array|null null if the timeframe does not concern the entity
	 */
	private static function buildTimeframeEntry( TimeframeModel $timeframe, string $entity, int $entityId, array $counterparts, int $todayStart, int $todayEnd ): ?array {
		$type = $timeframe->getType();

		$items     = self::assignment( $timeframe, 'item' );
		$locations = self::assignment( $timeframe, 'location' );

		// A timeframe controls an item only at the locations that are selected as well (and vice versa).
		$otherIds    = $entity === 'item' ? $locations['ids'] : $items['ids'];
		$effectiveAt = $type === TimeframeCPT::BOOKABLE_ID ? $otherIds : array_values( array_intersect( $otherIds, $counterparts ) );

		// Our side only implicitly selected ("all" or by category), e.g. a holiday of another location for all items:
		// only relevant if it applies to one of our counterparts or the other side is "all" too (like restrictions).
		$own   = $entity === 'item' ? $items : $locations;
		$other = $entity === 'item' ? $locations : $items;
		if ( $effectiveAt === [] && in_array( $own['mode'], [ self::ASSIGNMENT_ALL, self::ASSIGNMENT_CATEGORY ], true ) && $other['mode'] !== self::ASSIGNMENT_ALL ) {
			return null;
		}

		return self::timeframeEntry( $timeframe, $items, $locations, $effectiveAt, $todayStart, $todayEnd );
	}

	/**
	 * Builds the entry array of a timeframe (shared by the item / location overview and {@see self::forTimeframe()}).
	 *
	 * @param TimeframeModel $timeframe
	 * @param array          $items       {@see self::assignment()}
	 * @param array          $locations   {@see self::assignment()}
	 * @param int[]          $effectiveAt
	 * @param int            $todayStart
	 * @param int            $todayEnd
	 *
	 * @return array
	 */
	private static function timeframeEntry( TimeframeModel $timeframe, array $items, array $locations, array $effectiveAt, int $todayStart, int $todayEnd ): array {
		$type     = $timeframe->getType();
		$start    = $timeframe->getStartDate() ?: null;
		$end      = self::endTimestamp( $timeframe );
		$weekdays = $timeframe->getWeekDays();

		return [
			'kind'         => 'timeframe',
			'post_id'      => $timeframe->ID,
			'title'        => get_the_title( $timeframe->ID ),
			'status'       => $timeframe->post_status,
			'type'         => $type,
			'type_label'   => TimeframeCPT::getTypes()[ $type ] ?? (string) $type,
			'sort_type'    => $type,
			'blocking'     => $type !== TimeframeCPT::BOOKABLE_ID,
			'start'        => $start,
			'end'          => $end,
			'repetition'   => $timeframe->getRepetition(),
			'weekdays'     => is_array( $weekdays ) ? array_values( array_map( 'intval', $weekdays ) ) : [],
			'manual_dates' => $timeframe->getRepetition() === 'manual' ? $timeframe->getManualSelectionDates() : [],
			'grid'         => $timeframe->isFullDay() ? 'full' : ( $timeframe->getGrid() === 0 ? 'slot' : 'hourly' ),
			'start_time'   => $timeframe->isFullDay() ? null : self::formatTime( $timeframe->getStartTime() ),
			'end_time'     => $timeframe->isFullDay() ? null : self::formatTime( $timeframe->getEndTime() ),
			'items'        => $items,
			'locations'    => $locations,
			'effective_at' => $effectiveAt,
			'effective'    => $timeframe->post_status === 'publish' && $effectiveAt !== [],
			'group'        => self::group( $start, $end, $todayStart, $todayEnd ),
			'can_edit'     => self::canEdit( $timeframe->getPost() ),
			'hint'         => null,
		];
	}

	/**
	 * Builds the entry for a restriction or returns null if the restriction does not concern the entity.
	 * Same matching as {@see Restriction::filterPosts()}: 0 means all.
	 *
	 * @param RestrictionModel $restriction
	 * @param string           $entity
	 * @param int              $entityId
	 * @param int[]            $counterparts
	 * @param int              $todayStart
	 * @param int              $todayEnd
	 *
	 * @return array|null
	 */
	private static function buildRestrictionEntry( RestrictionModel $restriction, string $entity, int $entityId, array $counterparts, int $todayStart, int $todayEnd ): ?array {
		$itemId     = intval( $restriction->getMeta( RestrictionModel::META_ITEM_ID ) );
		$locationId = intval( $restriction->getMeta( RestrictionModel::META_LOCATION_ID ) );
		$own        = $entity === 'item' ? $itemId : $locationId;
		$other      = $entity === 'item' ? $locationId : $itemId;

		if ( $own !== $entityId && $own !== 0 ) {
			return null;
		}
		// "all" on our side: only relevant if the other side is "all" too or one of our counterparts
		if ( $own === 0 && $other !== 0 && ! in_array( $other, $counterparts, true ) ) {
			return null;
		}

		$effectiveAt = $other === 0 ? $counterparts : ( $own === 0 || in_array( $other, $counterparts, true ) ? [ $other ] : [] );
		// a restriction for another location (item) than the ones of the entity does not block it
		$effective = $other === 0 || $effectiveAt !== [];

		$start = intval( $restriction->getMeta( RestrictionModel::META_START ) ) ?: null;
		$end   = intval( $restriction->getMeta( RestrictionModel::META_END ) ) ?: null;
		$state = (string) $restriction->getMeta( RestrictionModel::META_STATE );
		$type  = (string) $restriction->getMeta( RestrictionModel::META_TYPE );

		$group = self::group( $start ?? 0, $end, $todayStart, $todayEnd );
		if ( $state === RestrictionModel::STATE_SOLVED ) {
			$group = self::GROUP_EXPIRED;
		}

		return [
			'kind'         => 'restriction',
			'post_id'      => $restriction->ID,
			'title'        => get_the_title( $restriction->ID ),
			'status'       => $state !== '' ? $state : RestrictionModel::STATE_NONE,
			'type'         => $type,
			'type_label'   => $type === RestrictionModel::TYPE_REPAIR ? __( 'Restriction: total breakdown', 'commonsbooking' ) : __( 'Restriction: notice', 'commonsbooking' ),
			'sort_type'    => 10,
			'blocking'     => $type === RestrictionModel::TYPE_REPAIR && $state === RestrictionModel::STATE_ACTIVE,
			'start'        => $start,
			'end'          => $end,
			'repetition'   => null,
			'weekdays'     => [],
			'manual_dates' => [],
			'grid'         => null,
			'start_time'   => null,
			'end_time'     => null,
			'items'        => self::restrictionAssignment( $itemId ),
			'locations'    => self::restrictionAssignment( $locationId ),
			'effective_at' => $effectiveAt,
			'effective'    => $effective && $state === RestrictionModel::STATE_ACTIVE,
			'group'        => $group,
			'can_edit'     => self::canEdit( $restriction->getPost() ),
			'hint'         => (string) $restriction->getHint(),
		];
	}

	/**
	 * Describes how items or locations are assigned to a timeframe.
	 *
	 * @param TimeframeModel $timeframe
	 * @param string         $side 'item' or 'location'
	 *
	 * @return array{mode: string, ids: int[], category_ids: int[]}
	 */
	public static function assignment( TimeframeModel $timeframe, string $side ): array {
		$ids     = $side === 'item' ? $timeframe->getItemIDs() : $timeframe->getLocationIDs();
		$single  = $side === 'item' ? $timeframe->getMeta( TimeframeModel::META_ITEM_ID ) : $timeframe->getMeta( TimeframeModel::META_LOCATION_ID );
		$list    = $side === 'item' ? $timeframe->getMeta( TimeframeModel::META_ITEM_ID_LIST ) : $timeframe->getMeta( TimeframeModel::META_LOCATION_ID_LIST );
		$select  = intval( $timeframe->getMeta( $side === 'item' ? TimeframeModel::META_ITEM_SELECTION_TYPE : TimeframeModel::META_LOCATION_SELECTION_TYPE ) );
		$catMeta = $timeframe->getMeta( $side === 'item' ? TimeframeModel::META_ITEM_CATEGORY_IDS : TimeframeModel::META_LOCATION_CATEGORY_IDS );

		// the legacy queries also match the single field, even if a list is set (see Timeframe::getPostIdsByType())
		if ( $single ) {
			$ids[] = intval( $single );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		$type = $timeframe->getType();
		$mode = self::ASSIGNMENT_DIRECT;
		if ( $type === TimeframeCPT::HOLIDAYS_ID || $type === TimeframeCPT::REPAIR_ID ) {
			if ( $select === TimeframeModel::SELECTION_ALL_ID ) {
				$mode = self::ASSIGNMENT_ALL;
			} elseif ( $select === TimeframeModel::SELECTION_CATEGORY_ID ) {
				$mode = self::ASSIGNMENT_CATEGORY;
			} elseif ( ! empty( $list ) ) {
				$mode = self::ASSIGNMENT_LIST;
			}
		}

		return [
			'mode'         => $mode,
			'ids'          => $ids,
			'category_ids' => $mode === self::ASSIGNMENT_CATEGORY && is_array( $catMeta ) ? array_values( array_map( 'intval', $catMeta ) ) : [],
		];
	}

	/**
	 * @param int $id 0 = all
	 *
	 * @return array{mode: string, ids: int[], category_ids: int[]}
	 */
	private static function restrictionAssignment( int $id ): array {
		return [
			'mode'         => $id === 0 ? self::ASSIGNMENT_ALL : self::ASSIGNMENT_DIRECT,
			'ids'          => $id === 0 ? [] : [ $id ],
			'category_ids' => [],
		];
	}

	/**
	 * @param int|null $start
	 * @param int|null $end null = open end
	 * @param int      $todayStart
	 * @param int      $todayEnd
	 *
	 * @return string
	 */
	public static function group( ?int $start, ?int $end, int $todayStart, int $todayEnd ): string {
		if ( $end !== null && $end < $todayStart ) {
			return self::GROUP_EXPIRED;
		}
		if ( $start !== null && $start > $todayEnd ) {
			return self::GROUP_FUTURE;
		}

		return self::GROUP_CURRENT;
	}

	/**
	 * @param TimeframeModel $timeframe
	 *
	 * @return int|null null = open end
	 */
	private static function endTimestamp( TimeframeModel $timeframe ): ?int {
		$end = $timeframe->getTimeframeEndDate();

		return $end ? intval( $end ) : null;
	}

	/**
	 * @param mixed $time e.g. '8:00 AM' or '08:00'
	 *
	 * @return string|null H:i
	 */
	private static function formatTime( $time ): ?string {
		if ( ! $time ) {
			return null;
		}
		$timestamp = strtotime( (string) $time, 0 );

		return $timestamp === false ? (string) $time : gmdate( 'H:i', $timestamp );
	}

	/**
	 * @param \WP_Post $post
	 *
	 * @return bool
	 */
	private static function canEdit( \WP_Post $post ): bool {
		try {
			return commonsbooking_isCurrentUserAllowedToEdit( $post );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Returns the post type of the other side.
	 *
	 * @param string $entity
	 *
	 * @return string
	 */
	public static function counterpartPostType( string $entity ): string {
		return $entity === 'item' ? LocationCPT::$postType : ItemCPT::$postType;
	}
}
