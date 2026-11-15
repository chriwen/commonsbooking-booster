<?php

namespace CommonsBooking\Service\Stats;

/**
 * Projects confirmed and cancelled bookings into {@see Schema::booking()}, set based in SQL.
 *
 * Timestamps are "local time as timestamp" like the timeframe index. The length is counted in days for full day
 * bookings and in hours otherwise.
 */
class Bookings {

	private const SELECT = "SELECT p.ID, CAST(mi.meta_value AS UNSIGNED), CAST(ml.meta_value AS UNSIGNED), p.post_author,
			IF(mf.meta_value = 'on', 'day', 'hour'),
			IF(mf.meta_value = 'on',
				DATEDIFF(DATE_ADD('1970-01-01', INTERVAL CAST(me.meta_value AS SIGNED) SECOND), DATE_ADD('1970-01-01', INTERVAL CAST(ms.meta_value AS SIGNED) SECOND)) + 1,
				GREATEST(1, ROUND((CAST(me.meta_value AS SIGNED) - CAST(ms.meta_value AS SIGNED) + 1) / 3600))),
			p.post_status, TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', p.post_date),
			CAST(ms.meta_value AS SIGNED), CAST(me.meta_value AS SIGNED), CAST(mc.meta_value AS SIGNED)";

	/**
	 * Booking post ids from $fromId to $toId are rewritten (inclusive).
	 *
	 * @param int $fromId
	 * @param int $toId
	 *
	 * @return int number of bookings in the range
	 */
	public static function projectRange( int $fromId, int $toId ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::booking() . ' WHERE booking_id BETWEEN %d AND %d', $fromId, $toId ) );
		$wpdb->query( self::insertSql( $wpdb->prepare( 'p.ID BETWEEN %d AND %d', $fromId, $toId ) ) );
		// phpcs:enable

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Rewrites the rows of single bookings, or removes them if the post is no longer a confirmed or cancelled booking.
	 *
	 * @param int[] $ids
	 *
	 * @return void
	 */
	public static function projectIds( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$list = implode( ',', $ids );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . Schema::booking() . " WHERE booking_id IN ($list)" );
		$wpdb->query( self::insertSql( "p.ID IN ($list)" ) );
		// phpcs:enable
	}

	/**
	 * @return int highest booking post id
	 */
	public static function maxId(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = %s", \CommonsBooking\Wordpress\CustomPostType\Booking::$postType ) );
	}

	/**
	 * Rebuilds the whole table in id ranges.
	 *
	 * @param int           $batch ids per range
	 * @param callable|null $progress called with the end of each range
	 *
	 * @return int number of bookings in the table afterwards
	 */
	public static function rebuild( int $batch = 20000, ?callable $progress = null ): int {
		global $wpdb;
		$max = self::maxId();
		for ( $from = 0; $from <= $max; $from += $batch ) {
			self::projectRange( $from, $from + $batch - 1 );
			if ( $progress ) {
				$progress( min( $max, $from + $batch - 1 ) );
			}
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::booking() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Item/location pairs and the first start of the bookings with the given ids.
	 *
	 * @param int[] $ids
	 *
	 * @return array<int, array{item_id: int, location_id: int, start_ts: int}>
	 */
	public static function pairsOf( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			'SELECT item_id, location_id, MIN(start_ts) AS start_ts FROM ' . Schema::booking() . ' WHERE booking_id IN (' . implode( ',', $ids ) . ') GROUP BY item_id, location_id',
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			fn( $r ) => [
				'item_id'     => (int) $r['item_id'],
				'location_id' => (int) $r['location_id'],
				'start_ts'    => (int) $r['start_ts'],
			],
			$rows ?: []
		);
	}

	/**
	 * @param string $where prepared condition on p
	 *
	 * @return string
	 */
	private static function insertSql( string $where ): string {
		global $wpdb;
		$post = \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
		$pm   = $wpdb->postmeta;
		$join = static fn( string $alias, string $key, string $type = 'JOIN' ) => "$type $pm $alias ON $alias.post_id = p.ID AND $alias.meta_key = '$key'";

		return 'INSERT IGNORE INTO ' . Schema::booking() . '
			(booking_id,item_id,location_id,user_id,unit,slots,status,created_ts,start_ts,end_ts,canceled_ts) '
			. self::SELECT . " FROM {$wpdb->posts} p "
			. $join( 'ms', 'repetition-start' ) . ' '
			. $join( 'me', 'repetition-end' ) . ' '
			. $join( 'mi', 'item-id' ) . ' '
			. $join( 'ml', 'location-id' ) . ' '
			. $join( 'mf', 'full-day', 'LEFT JOIN' ) . ' '
			. $join( 'mc', 'cancellation_time', 'LEFT JOIN' )
			. " WHERE p.post_type = '$post' AND p.post_status IN ('confirmed','canceled') AND $where"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
