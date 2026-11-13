<?php

namespace CommonsBooking\Service\Availability;

/**
 * Database access for the days of pairs with role limited timeframes, one row per pair and access profile
 * ({@see AccessProfile}, table {@see Schema::accessTable()}).
 *
 * The rows use the gen of the pair row ({@see Store}): a row is only used while its computed_gen equals the gen of
 * the pair, so every change of the pair invalidates all its access profiles without touching this table.
 * days_from and days have the same format as in the pair row, {@see Store::getFlags()} reads both.
 *
 * @see design/role-restricted-availability.md
 */
class AccessStore {

	/**
	 * Rows not read for this many days are removed by {@see self::cleanup()}.
	 */
	public const UNUSED_DAYS = 30;

	private const COLUMNS = 'item_id, location_id, access_profile, computed_gen, days_from, days, computed_at, calc_version, last_read';

	/**
	 * Rows loaded in this request, by "locationId:itemId:accessProfile", null if there is none.
	 *
	 * @var array<string, array|null>
	 */
	private static array $rows = [];

	/**
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $accessProfile
	 *
	 * @return string
	 */
	private static function key( int $itemId, int $locationId, string $accessProfile ): string {
		return $locationId . ':' . $itemId . ':' . $accessProfile;
	}

	/**
	 * Loads the rows of several pairs and access profiles with one query.
	 *
	 * @param array<int, array{0:int,1:int,2:string}> $requests list of [itemId, locationId, accessProfile]
	 *
	 * @return void
	 */
	public static function preload( array $requests ): void {
		$requests = array_values( array_filter( $requests, fn( $r ) => ! array_key_exists( self::key( $r[0], $r[1], $r[2] ), self::$rows ) ) );
		if ( ! $requests ) {
			return;
		}
		foreach ( $requests as [ $itemId, $locationId, $accessProfile ] ) {
			self::$rows[ self::key( $itemId, $locationId, $accessProfile ) ] = null;
		}
		foreach ( self::query( $requests, false ) as $key => $row ) {
			self::$rows[ $key ] = $row;
		}
	}

	/**
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $accessProfile
	 *
	 * @return array|null the row, null if there is none
	 */
	public static function get( int $itemId, int $locationId, string $accessProfile ): ?array {
		self::preload( [ [ $itemId, $locationId, $accessProfile ] ] );

		return self::$rows[ self::key( $itemId, $locationId, $accessProfile ) ];
	}

	/**
	 * Loads rows including the packed slots. Not kept in this request's row cache, like {@see Store::loadWithSlots()}.
	 *
	 * @param array<int, array{0:int,1:int,2:string}> $requests list of [itemId, locationId, accessProfile]
	 *
	 * @return array<string, array> rows by "locationId:itemId:accessProfile", missing ones are left out
	 */
	public static function loadWithSlots( array $requests ): array {
		return $requests ? self::query( $requests, true ) : [];
	}

	/**
	 * @param array<int, array{0:int,1:int,2:string}> $requests
	 * @param bool                                    $withSlots
	 *
	 * @return array<string, array>
	 */
	private static function query( array $requests, bool $withSlots ): array {
		global $wpdb;
		$table   = Schema::accessTable();
		$columns = self::COLUMNS . ( $withSlots ? ', slots' : '' );

		$result = [];
		$touch  = [];
		$today  = date( 'Y-m-d' );
		foreach ( array_chunk( $requests, 200 ) as $chunk ) {
			$conditions = array_map(
				fn( $r ) => $wpdb->prepare( '(location_id = %d AND item_id = %d AND access_profile = %s)', $r[1], $r[0], $r[2] ),
				$chunk
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- conditions prepared above
			$rows = $wpdb->get_results( "SELECT $columns FROM $table WHERE " . implode( ' OR ', $conditions ), ARRAY_A );
			foreach ( $rows as $row ) {
				$key            = self::key( (int) $row['item_id'], (int) $row['location_id'], $row['access_profile'] );
				$result[ $key ] = $row;
				if ( $row['last_read'] === null || $row['last_read'] < $today ) {
					$touch[] = $wpdb->prepare( '(location_id = %d AND item_id = %d AND access_profile = %s)', $row['location_id'], $row['item_id'], $row['access_profile'] );
				}
			}
		}

		// at most one write per row and day, for the cleanup of unused access profiles
		foreach ( array_chunk( $touch, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- conditions prepared above
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET last_read = %s WHERE ", $today ) . implode( ' OR ', $chunk ) );
		}

		return $result;
	}

	/**
	 * Returns true if the row may be used to answer a request.
	 *
	 * @param array|null $row
	 * @param array|null $pairRow the row of the pair ({@see Store})
	 *
	 * @return bool
	 */
	public static function isUsable( ?array $row, ?array $pairRow ): bool {
		if ( ! $row || ! $pairRow || $row['days'] === null || $row['days_from'] === null ) {
			return false;
		}
		if ( (int) $row['computed_gen'] !== (int) $pairRow['gen'] || (int) $row['calc_version'] !== Calculator::VERSION ) {
			return false;
		}

		return strtotime( $row['computed_at'] . ' UTC' ) >= time() - Availability::getMaxAge();
	}

	/**
	 * Returns true if another access profile may be stored for the pair ({@see AccessProfile::getMaxPerPair()}).
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $accessProfile
	 * @param int    $gen current gen of the pair
	 *
	 * @return bool
	 */
	public static function hasRoom( int $itemId, int $locationId, string $accessProfile, int $gen ): bool {
		global $wpdb;
		$table = Schema::accessTable();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE location_id = %d AND item_id = %d AND computed_gen = %d AND access_profile <> %s", $locationId, $itemId, $gen, $accessProfile ) );

		return $count < AccessProfile::getMaxPerPair();
	}

	/**
	 * Stores computed days of an access profile, if the pair did not change since $gen was read.
	 *
	 * @param int         $itemId
	 * @param int         $locationId
	 * @param string      $accessProfile
	 * @param int         $gen gen of the pair read before the computation
	 * @param string      $daysFrom Y-m-d
	 * @param int[]       $flags flags per day starting at $daysFrom
	 * @param string|null $slots packed slots of the same days ({@see Slots::pack()})
	 *
	 * @return bool true if stored, false if the pair changed in the meantime
	 */
	public static function write( int $itemId, int $locationId, string $accessProfile, int $gen, string $daysFrom, array $flags, ?string $slots ): bool {
		global $wpdb;
		$table     = Schema::accessTable();
		$pairTable = Schema::table();
		// hex keeps the query ASCII only, see Store::write()
		$days     = "UNHEX('" . bin2hex( pack( 'v*', ...$flags ) ) . "')";
		$slotsHex = null === $slots ? 'NULL' : "UNHEX('" . bin2hex( $slots ) . "')";

		// the select only returns the pair row while its gen is still $gen
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and hex literals only
		$sql = $wpdb->prepare(
			"INSERT INTO $table (item_id, location_id, access_profile, computed_gen, days_from, days, slots, computed_at, calc_version, last_read)
			SELECT item_id, location_id, %s, gen, %s, $days, $slotsHex, %s, %d, %s FROM $pairTable WHERE location_id = %d AND item_id = %d AND gen = %d
			ON DUPLICATE KEY UPDATE computed_gen = VALUES(computed_gen), days_from = VALUES(days_from), days = VALUES(days), slots = VALUES(slots),
			computed_at = VALUES(computed_at), calc_version = VALUES(calc_version)",
			$accessProfile,
			$daysFrom,
			gmdate( 'Y-m-d H:i:s' ),
			Calculator::VERSION,
			date( 'Y-m-d' ),
			$locationId,
			$itemId,
			$gen
		);
		// phpcs:enable
		$stored = (int) $wpdb->query( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		unset( self::$rows[ self::key( $itemId, $locationId, $accessProfile ) ] );

		return $stored;
	}

	/**
	 * Removes rows that can never be used again (pair removed or changed) and rows not read for {@see self::UNUSED_DAYS}.
	 *
	 * @return int number of removed rows
	 */
	public static function cleanup(): int {
		global $wpdb;
		$table     = Schema::accessTable();
		$pairTable = Schema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql     = $wpdb->prepare(
			"DELETE a FROM $table a LEFT JOIN $pairTable p ON p.location_id = a.location_id AND p.item_id = a.item_id
			WHERE p.item_id IS NULL OR p.has_role_limit = 0 OR a.computed_gen < p.gen OR a.last_read < %s",
			date( 'Y-m-d', strtotime( '-' . self::UNUSED_DAYS . ' days' ) )
		);
		$deleted = (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::reset();

		return $deleted;
	}

	/**
	 * Removes one row.
	 *
	 * @param int    $itemId
	 * @param int    $locationId
	 * @param string $accessProfile
	 *
	 * @return void
	 */
	public static function delete( int $itemId, int $locationId, string $accessProfile ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::accessTable(),
			[
				'location_id'    => $locationId,
				'item_id'        => $itemId,
				'access_profile' => $accessProfile,
			],
			[ '%d', '%d', '%s' ]
		);
		unset( self::$rows[ self::key( $itemId, $locationId, $accessProfile ) ] );
	}

	/**
	 * All stored rows as [itemId, locationId, accessProfile].
	 *
	 * @return array<int, array{0:int,1:int,2:string}>
	 */
	public static function getAll(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT item_id, location_id, access_profile FROM ' . Schema::accessTable(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( fn( $row ) => [ (int) $row['item_id'], (int) $row['location_id'], (string) $row['access_profile'] ], $rows );
	}

	/**
	 * @return array{rows: int, usable: int, pairs: int}
	 */
	public static function getStats(): array {
		global $wpdb;
		$table     = Schema::accessTable();
		$pairTable = Schema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) AS rows_total, SUM(a.computed_gen = p.gen AND a.calc_version = %d AND a.computed_at >= %s) AS usable, COUNT(DISTINCT a.location_id, a.item_id) AS pairs
			FROM $table a LEFT JOIN $pairTable p ON p.location_id = a.location_id AND p.item_id = a.item_id",
			Calculator::VERSION,
			gmdate( 'Y-m-d H:i:s', time() - Availability::getMaxAge() )
		);
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'rows'   => (int) ( $row['rows_total'] ?? 0 ),
			'usable' => (int) ( $row['usable'] ?? 0 ),
			'pairs'  => (int) ( $row['pairs'] ?? 0 ),
		];
	}

	/**
	 * Forgets the rows loaded in this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rows = [];
	}
}
