<?php

namespace CommonsBooking\Repository;

use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema;

/**
 * Query layer for the timeframe index (cb_index / cb_index_rel).
 *
 * Only use when {@see self::isAvailable()} returns true, otherwise fall back to the postmeta based queries.
 *
 * @see \CommonsBooking\Service\Index\Projector for the meaning of the columns
 */
class Index {

	/**
	 * Returns true if the index is complete and enabled for reading.
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return Backfill::isReady();
	}

	/**
	 * Returns post ids matching the criteria, ordered by post id.
	 *
	 * Item / location matching: a post matches if one of its items is in $items (and one of its locations in $locations).
	 * Empty lists mean "no filter".
	 *
	 * @param array{
	 *     post_types?: string[],
	 *     types?: int[],
	 *     statuses?: string[],
	 *     items?: int[],
	 *     locations?: int[],
	 *     start_min?: int|null,
	 *     start_max?: int|null,
	 *     end_min?: int|null,
	 *     end_max?: int|null,
	 *     end_exact?: int|null,
	 *     require_end?: bool,
	 *     authors?: int[],
	 *     restriction_states?: string[]
	 * } $criteria
	 *  - start_min / start_max: start_ts must be set and within the bounds
	 *  - end_min: end_ts >= end_min or open end
	 *  - end_max: end_ts <= end_max or open end
	 *  - end_exact: end_ts = end_exact
	 *  - require_end: exclude posts with open end
	 *
	 * @return int[]
	 */
	public static function findPostIds( array $criteria ): array {
		global $wpdb;

		// read-your-writes: changes of the current request must be visible
		Indexer::flush();

		$table    = Schema::table();
		$relTable = Schema::relTable();

		$items     = self::ids( $criteria['items'] ?? [] );
		$locations = self::ids( $criteria['locations'] ?? [] );

		$common = self::commonConditions( $criteria );

		if ( ! $items && ! $locations ) {
			$sql = "SELECT i.post_id FROM $table i WHERE $common ORDER BY i.post_id";
		} else {
			$single = [ 'i.is_multi = 0' ];
			$multi  = [ 'i.is_multi = 1' ];
			if ( $items ) {
				$single[] = 'i.item_id IN (' . implode( ',', $items ) . ')';
				$multi[]  = "EXISTS (SELECT 1 FROM $relTable ri WHERE ri.post_id = i.post_id AND ri.entity_type = 'item' AND ri.entity_id IN (" . implode( ',', $items ) . '))';
			}
			if ( $locations ) {
				$single[] = 'i.location_id IN (' . implode( ',', $locations ) . ')';
				$multi[]  = "EXISTS (SELECT 1 FROM $relTable rl WHERE rl.post_id = i.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (" . implode( ',', $locations ) . '))';
			}
			$sql = "SELECT post_id FROM (
					SELECT i.post_id FROM $table i WHERE " . implode( ' AND ', $single ) . " AND $common
					UNION ALL
					SELECT i.post_id FROM $table i WHERE " . implode( ' AND ', $multi ) . " AND $common
				) matches ORDER BY post_id";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- all values are prepared or cast to int above
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Loads posts for the given ids with two queries (posts + meta) instead of two per post.
	 *
	 * @param int[] $postIds
	 *
	 * @return \WP_Post[] in the order of $postIds, missing posts are skipped
	 */
	public static function loadPosts( array $postIds ): array {
		if ( ! $postIds ) {
			return [];
		}
		_prime_post_caches( $postIds, false, true );

		return array_values( array_filter( array_map( 'get_post', $postIds ) ) );
	}

	/**
	 * Sorts posts like WP_Query with orderby=date: by post_date, ties by ascending ID.
	 *
	 * @param \WP_Post[] $posts
	 * @param string     $order ASC|DESC
	 *
	 * @return \WP_Post[]
	 */
	public static function sortByDate( array $posts, string $order = 'DESC' ): array {
		$direction = strtoupper( $order ) === 'ASC' ? 1 : -1;
		usort(
			$posts,
			function ( $a, $b ) use ( $direction ) {
				$byDate = strcmp( $a->post_date, $b->post_date );

				// ties: ascending ID, like MySQL returns rows with equal post_date
				return $byDate !== 0 ? $direction * $byDate : $a->ID <=> $b->ID;
			}
		);

		return $posts;
	}

	/**
	 * Builds the conditions on the main table that don't depend on items / locations.
	 *
	 * @param array $criteria
	 *
	 * @return string SQL condition
	 */
	private static function commonConditions( array $criteria ): string {
		global $wpdb;
		$conditions = [];

		if ( ! empty( $criteria['post_types'] ) ) {
			$conditions[] = 'i.post_type IN (' . self::strings( $criteria['post_types'] ) . ')';
		}
		if ( ! empty( $criteria['types'] ) ) {
			$conditions[] = 'i.type IN (' . implode( ',', array_map( 'intval', $criteria['types'] ) ) . ')';
		}
		if ( ! empty( $criteria['statuses'] ) ) {
			$conditions[] = 'i.post_status IN (' . self::strings( $criteria['statuses'] ) . ')';
		}
		if ( isset( $criteria['start_min'] ) ) {
			$conditions[] = $wpdb->prepare( 'i.start_ts >= %d', $criteria['start_min'] );
		}
		if ( isset( $criteria['start_max'] ) ) {
			$conditions[] = $wpdb->prepare( 'i.start_ts <= %d', $criteria['start_max'] );
		}
		if ( isset( $criteria['end_min'] ) ) {
			$conditions[] = $wpdb->prepare( '(i.end_ts IS NULL OR i.end_ts >= %d)', $criteria['end_min'] );
		}
		if ( isset( $criteria['end_max'] ) ) {
			$conditions[] = $wpdb->prepare( '(i.end_ts IS NULL OR i.end_ts <= %d)', $criteria['end_max'] );
		}
		if ( isset( $criteria['end_exact'] ) ) {
			$conditions[] = $wpdb->prepare( 'i.end_ts = %d', $criteria['end_exact'] );
		}
		if ( ! empty( $criteria['require_end'] ) ) {
			$conditions[] = 'i.end_ts IS NOT NULL';
		}
		if ( ! empty( $criteria['authors'] ) ) {
			$conditions[] = 'i.post_author IN (' . implode( ',', array_map( 'intval', $criteria['authors'] ) ) . ')';
		}
		if ( ! empty( $criteria['restriction_states'] ) ) {
			$conditions[] = 'i.restriction_state IN (' . self::strings( $criteria['restriction_states'] ) . ')';
		}

		return $conditions ? implode( ' AND ', $conditions ) : '1=1';
	}

	/**
	 * @param array $values
	 *
	 * Same sanitizing as the legacy queries: empty values are dropped (no filter), all others are cast to int.
	 * A non-empty list of invalid values therefore results in IN (0), which matches nothing.
	 *
	 * @return int[]
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_map( 'intval', array_filter( $values ) ) ) );
	}

	/**
	 * @param string[] $values
	 *
	 * @return string comma separated, escaped and quoted
	 */
	private static function strings( array $values ): string {
		global $wpdb;

		return implode(
			',',
			array_map( fn( $value ) => $wpdb->prepare( '%s', (string) $value ), $values )
		);
	}
}
