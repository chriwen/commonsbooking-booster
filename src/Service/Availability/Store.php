<?php

namespace CommonsBooking\Service\Availability;

/**
 * Database access for the precomputed availability ({@see Schema}).
 *
 * Consistency without locks: {@see self::invalidate()} increases gen, {@see self::write()} only stores days
 * if gen is still the one read before the computation. A row is used only if computed_gen equals gen.
 */
class Store {

	/**
	 * Bytes per day in the packed days column.
	 */
	private const BYTES_PER_DAY = 2;

	/**
	 * Columns loaded by {@see self::preload()}: all but the slots.
	 */
	private const COLUMNS = 'item_id, location_id, gen, computed_gen, days_from, days, has_role_limit, computed_at, calc_version';

	/**
	 * Rows loaded in this request, by "locationId:itemId".
	 *
	 * @var array<string, array|null>
	 */
	private static array $rows = [];

	/**
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return string
	 */
	private static function key( int $itemId, int $locationId ): string {
		return $locationId . ':' . $itemId;
	}

	/**
	 * Loads the rows of several pairs with one query.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs list of [itemId, locationId]
	 *
	 * @return void
	 */
	public static function preload( array $pairs ): void {
		global $wpdb;

		$byLocation = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			$key = self::key( (int) $itemId, (int) $locationId );
			if ( ! array_key_exists( $key, self::$rows ) ) {
				self::$rows[ $key ]                               = null;
				$byLocation[ (int) $locationId ][ (int) $itemId ] = true;
			}
		}
		if ( ! $byLocation ) {
			return;
		}

		$conditions = [];
		foreach ( $byLocation as $locationId => $itemIds ) {
			$conditions[] = sprintf( '(location_id = %d AND item_id IN (%s))', $locationId, implode( ',', array_map( 'intval', array_keys( $itemIds ) ) ) );
		}
		foreach ( array_chunk( $conditions, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- only integers
			$rows = $wpdb->get_results( 'SELECT ' . self::COLUMNS . ' FROM ' . Schema::table() . ' WHERE ' . implode( ' OR ', $chunk ), ARRAY_A );
			foreach ( $rows as $row ) {
				self::$rows[ self::key( (int) $row['item_id'], (int) $row['location_id'] ) ] = $row;
			}
		}
	}

	/**
	 * Loads the rows of several pairs including the packed slots. Not kept in this request's row cache, the slots
	 * can be large and are only needed by {@see SlotReader}.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs list of [itemId, locationId]
	 *
	 * @return array<string, array> rows by "locationId:itemId", missing pairs are left out
	 */
	public static function loadWithSlots( array $pairs ): array {
		global $wpdb;

		$byLocation = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			$byLocation[ (int) $locationId ][ (int) $itemId ] = true;
		}
		$conditions = [];
		foreach ( $byLocation as $locationId => $itemIds ) {
			$conditions[] = sprintf( '(location_id = %d AND item_id IN (%s))', $locationId, implode( ',', array_map( 'intval', array_keys( $itemIds ) ) ) );
		}

		$result = [];
		foreach ( array_chunk( $conditions, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- only integers
			$rows = $wpdb->get_results( 'SELECT ' . self::COLUMNS . ', slots FROM ' . Schema::table() . ' WHERE ' . implode( ' OR ', $chunk ), ARRAY_A );
			foreach ( $rows as $row ) {
				$result[ self::key( (int) $row['item_id'], (int) $row['location_id'] ) ] = $row;
			}
		}

		return $result;
	}

	/**
	 * Returns the offset of a day in a usable row, null if the day is not covered.
	 *
	 * @param array  $row
	 * @param string $date Y-m-d
	 *
	 * @return int|null
	 */
	public static function getDayOffset( array $row, string $date ): ?int {
		$offset = (int) round( ( strtotime( $date . ' 12:00 UTC' ) - strtotime( $row['days_from'] . ' 12:00 UTC' ) ) / DAY_IN_SECONDS );
		if ( $offset < 0 || ( $offset + 1 ) * self::BYTES_PER_DAY > strlen( $row['days'] ) ) {
			return null;
		}

		return $offset;
	}

	/**
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return array|null the row, null if there is none
	 */
	public static function get( int $itemId, int $locationId ): ?array {
		self::preload( [ [ $itemId, $locationId ] ] );

		return self::$rows[ self::key( $itemId, $locationId ) ];
	}

	/**
	 * Forgets the rows loaded in this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rows = [];
		AccessStore::reset();
		AccessProfile::reset();
	}

	/**
	 * Returns true if the row may be used to answer a request.
	 *
	 * @param array|null $row
	 *
	 * @return bool
	 */
	public static function isUsable( ?array $row ): bool {
		if ( ! $row || $row['days'] === null || $row['days_from'] === null ) {
			return false;
		}
		if ( (int) $row['has_role_limit'] === 1 ) {
			return false;
		}
		if ( (int) $row['computed_gen'] !== (int) $row['gen'] || (int) $row['calc_version'] !== Calculator::VERSION ) {
			return false;
		}

		return strtotime( $row['computed_at'] . ' UTC' ) >= time() - Availability::getMaxAge();
	}

	/**
	 * Returns the flags of a day from a usable row.
	 *
	 * @param array  $row
	 * @param string $date Y-m-d
	 *
	 * @return int|null null if the day is not covered by the row
	 */
	public static function getFlags( array $row, string $date ): ?int {
		$offset = self::getDayOffset( $row, $date );
		if ( $offset === null ) {
			return null;
		}

		return unpack( 'v', $row['days'], $offset * self::BYTES_PER_DAY )[1];
	}

	/**
	 * Makes sure that the pair has a row and returns its current gen.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return int
	 */
	public static function getGen( int $itemId, int $locationId ): int {
		global $wpdb;
		$table = Schema::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (item_id, location_id, gen, computed_gen) VALUES (%d, %d, 1, 0)", $itemId, $locationId ) );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT gen FROM $table WHERE location_id = %d AND item_id = %d", $locationId, $itemId ) );
		// phpcs:enable
	}

	/**
	 * Stores computed days, if the pair did not change since $gen was read.
	 *
	 * @param int         $itemId
	 * @param int         $locationId
	 * @param int         $gen gen read before the computation
	 * @param string      $daysFrom Y-m-d
	 * @param int[]       $flags flags per day starting at $daysFrom
	 * @param bool        $hasRoleLimit
	 * @param string|null $slots packed slots of the same days ({@see Slots::pack()})
	 *
	 * @return bool true if stored, false if the pair changed in the meantime
	 */
	public static function write( int $itemId, int $locationId, int $gen, string $daysFrom, array $flags, bool $hasRoleLimit, ?string $slots = null ): bool {
		global $wpdb;
		$table = Schema::table();
		// hex keeps the query ASCII only, wpdb may strip bytes it considers invalid text from queries
		$days     = $hasRoleLimit ? 'NULL' : "UNHEX('" . bin2hex( pack( 'v*', ...$flags ) ) . "')";
		$slotsHex = ( $hasRoleLimit || null === $slots ) ? 'NULL' : "UNHEX('" . bin2hex( $slots ) . "')";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and hex literals only
		$sql = $wpdb->prepare(
			"UPDATE $table SET computed_gen = gen, days_from = %s, days = $days, slots = $slotsHex, has_role_limit = %d, computed_at = %s, calc_version = %d WHERE location_id = %d AND item_id = %d AND gen = %d",
			$daysFrom,
			$hasRoleLimit ? 1 : 0,
			gmdate( 'Y-m-d H:i:s' ),
			Calculator::VERSION,
			$locationId,
			$itemId,
			$gen
		);
		// phpcs:enable
		$stored = (int) $wpdb->query( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		unset( self::$rows[ self::key( $itemId, $locationId ) ] );

		return $stored;
	}

	/**
	 * Marks pairs as changed.
	 *
	 * @param int[]|null $itemIds null for all items
	 * @param int[]|null $locationIds null for all locations
	 *
	 * @return array<int, array{0:int,1:int}> the invalidated pairs as [itemId, locationId]
	 */
	public static function invalidate( ?array $itemIds, ?array $locationIds ): array {
		global $wpdb;
		if ( ( $itemIds !== null && ! $itemIds ) || ( $locationIds !== null && ! $locationIds ) ) {
			return [];
		}

		$where = [ '1=1' ];
		if ( $itemIds !== null ) {
			$where[] = 'item_id IN (' . implode( ',', array_map( 'intval', $itemIds ) ) . ')';
		}
		if ( $locationIds !== null ) {
			$where[] = 'location_id IN (' . implode( ',', array_map( 'intval', $locationIds ) ) . ')';
		}
		$where = implode( ' AND ', $where );
		$table = Schema::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- only integers
		$wpdb->query( "UPDATE $table SET gen = gen + 1 WHERE $where" );
		$rows = $wpdb->get_results( "SELECT item_id, location_id FROM $table WHERE $where", ARRAY_A );
		// phpcs:enable

		self::reset();

		return array_map( fn( $row ) => [ (int) $row['item_id'], (int) $row['location_id'] ], $rows );
	}

	/**
	 * Marks all pairs as changed.
	 *
	 * @return void
	 */
	public static function invalidateAll(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::table() . ' SET gen = gen + 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::reset();
	}

	/**
	 * Returns pairs whose rows should be recomputed: changed, old calculation version or computed before $before.
	 *
	 * @param int $before unix timestamp
	 * @param int $limit
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function getStalePairs( int $before, int $limit ): array {
		global $wpdb;
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = $wpdb->prepare(
			"SELECT item_id, location_id FROM $table WHERE computed_gen <> gen OR calc_version <> %d OR computed_at IS NULL OR computed_at < %s ORDER BY computed_at IS NOT NULL, computed_at LIMIT %d",
			Calculator::VERSION,
			gmdate( 'Y-m-d H:i:s', $before ),
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( fn( $row ) => [ (int) $row['item_id'], (int) $row['location_id'] ], $rows );
	}

	/**
	 * @return array{rows: int, usable: int, changed: int, role_limited: int}
	 */
	public static function getStats(): array {
		global $wpdb;
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) AS rows_total, SUM(computed_gen = gen AND calc_version = %d AND days IS NOT NULL AND computed_at >= %s) AS usable, SUM(computed_gen <> gen) AS changed, SUM(has_role_limit) AS role_limited FROM $table",
			Calculator::VERSION,
			gmdate( 'Y-m-d H:i:s', time() - Availability::getMaxAge() )
		);
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'rows'         => (int) ( $row['rows_total'] ?? 0 ),
			'usable'       => (int) ( $row['usable'] ?? 0 ),
			'changed'      => (int) ( $row['changed'] ?? 0 ),
			'role_limited' => (int) ( $row['role_limited'] ?? 0 ),
		];
	}

	/**
	 * Returns all pairs that have a row.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function getAllPairs(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT item_id, location_id FROM ' . Schema::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( fn( $row ) => [ (int) $row['item_id'], (int) $row['location_id'] ], $rows );
	}

	/**
	 * Deletes rows of pairs that are not in the list.
	 *
	 * @param array<int, array{0:int,1:int}> $keep
	 *
	 * @return int number of deleted rows
	 */
	public static function deleteExcept( array $keep ): int {
		global $wpdb;
		$keepKeys = [];
		foreach ( $keep as [ $itemId, $locationId ] ) {
			$keepKeys[ self::key( $itemId, $locationId ) ] = true;
		}
		$deleted = 0;
		foreach ( self::getAllPairs() as [ $itemId, $locationId ] ) {
			if ( ! isset( $keepKeys[ self::key( $itemId, $locationId ) ] ) ) {
				$deleted += (int) $wpdb->delete(
					Schema::table(),
					[
						'location_id' => $locationId,
						'item_id'     => $itemId,
					],
					[ '%d', '%d' ]
				);
			}
		}
		self::reset();

		return $deleted;
	}
}
