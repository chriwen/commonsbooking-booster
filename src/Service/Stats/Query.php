<?php

namespace CommonsBooking\Service\Stats;

use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;

/**
 * Reads the aggregated tables for the dashboard and the CSV export. Never touches posts, except to resolve
 * category filters to item and location ids.
 */
class Query {

	private Filters $filters;

	/** @var int[]|null null = no restriction */
	private ?array $itemIds;

	/** @var int[]|null */
	private ?array $locationIds;

	public function __construct( Filters $filters ) {
		$this->filters     = $filters;
		$this->itemIds     = self::resolve( $filters->items, $filters->itemCategories, Item::getTaxonomyName() );
		$this->locationIds = self::resolve( $filters->locations, $filters->locationCategories, Location::getTaxonomyName() );
	}

	/**
	 * Daily values grouped by period bucket and unit.
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<int, array<string, mixed>> keys bucket, unit, capacity, booked, outage, failure, started, canceled, usage_days
	 */
	public function series( array $range ): array {
		return $this->grouped( $range, $this->bucketSql() . ' AS bucket, ', 'bucket' );
	}

	/**
	 * Daily values grouped by item or location and unit.
	 *
	 * @param array{0: string, 1: string} $range
	 * @param string                      $by 'item' or 'location'
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function totalsBy( array $range, string $by ): array {
		$column = $by === 'item' ? 'item_id' : 'location_id';

		return $this->grouped( $range, $column . ', ', $column );
	}

	/**
	 * Daily values grouped by item, location and unit (export).
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function totalsByPair( array $range ): array {
		return $this->grouped( $range, 'item_id, location_id, ', 'item_id, location_id' );
	}

	/**
	 * Daily values grouped by period bucket, item, location and unit (export).
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function seriesByPair( array $range ): array {
		return $this->grouped( $range, $this->bucketSql() . ' AS bucket, item_id, location_id, ', 'bucket, item_id, location_id' );
	}

	/**
	 * Totals of the period per unit.
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<string, array<string, mixed>> by unit
	 */
	public function totals( array $range ): array {
		$out = [];
		foreach ( $this->grouped( $range, '', '' ) as $row ) {
			$out[ $row['unit'] ] = $row;
		}

		return $out;
	}

	/**
	 * Booked and capacity slots by weekday (0 = Monday) and month (1-12) for the heatmap.
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<string, array<int, array<int, array{capacity: int, booked: int}>>> by unit, weekday, month
	 */
	public function heatmap( array $range ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			'SELECT unit, WEEKDAY(day) AS wd, MONTH(day) AS mo, SUM(capacity_slots) AS capacity, SUM(booked_slots) AS booked FROM ' . Schema::daily() . ' ' . $this->where( $range ) . ' GROUP BY unit, wd, mo',
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( $rows ?: [] as $r ) {
			$out[ $r['unit'] ][ (int) $r['wd'] ][ (int) $r['mo'] ] = [
				'capacity' => (int) $r['capacity'],
				'booked'   => (int) $r['booked'],
			];
		}

		return $out;
	}

	/**
	 * Distributions of the bookings that start in the period.
	 *
	 * - duration: by unit, buckets by length (see {@see self::DURATION_LABELS})
	 * - lead: confirmed, time between creation and start in 6 buckets
	 * - cancel: cancelled, time between cancellation and start in 4 buckets plus 'unknown'
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array{duration: array<string, int[]>, mean: array<string, float>, median: array<string, float>, lead: int[], cancel: int[], cancelUnknown: int}
	 */
	public function distributions( array $range ): array {
		global $wpdb;
		$where = $this->bookingWhere( $range );

		$out = [
			'duration'      => [],
			'mean'          => [],
			'median'        => [],
			'lead'          => array_fill( 0, 6, 0 ),
			'cancel'        => array_fill( 0, 4, 0 ),
			'cancelUnknown' => 0,
		];
		$tbl = Schema::booking();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT unit,
				CASE WHEN unit = 'day' THEN (slots >= 2) + (slots >= 3) + (slots >= 4) + (slots >= 8)
					ELSE (slots >= 2) + (slots >= 3) + (slots >= 5) + (slots >= 9) END AS bucket,
				COUNT(*) AS n, AVG(slots) AS mean
			FROM $tbl $where AND status = 'confirmed' GROUP BY unit, bucket",
			ARRAY_A
		);
		$sums = [];
		foreach ( $rows ?: [] as $r ) {
			$out['duration'][ $r['unit'] ]                       = $out['duration'][ $r['unit'] ] ?? array_fill( 0, 5, 0 );
			$out['duration'][ $r['unit'] ][ (int) $r['bucket'] ] = (int) $r['n'];
			$sums[ $r['unit'] ][0]                               = ( $sums[ $r['unit'] ][0] ?? 0 ) + (int) $r['n'];
			$sums[ $r['unit'] ][1]                               = ( $sums[ $r['unit'] ][1] ?? 0 ) + (float) $r['mean'] * (int) $r['n'];
		}
		foreach ( $sums as $unit => [ $n, $total ] ) {
			$out['mean'][ $unit ]   = $n ? $total / $n : 0.0;
			$offset                 = (int) floor( ( $n - 1 ) / 2 );
			$out['median'][ $unit ] = (float) $wpdb->get_var(
				$wpdb->prepare( "SELECT slots FROM $tbl $where AND status = 'confirmed' AND unit = %s ORDER BY slots LIMIT 1 OFFSET %d", $unit, $offset )
			);
		}

		$rows = $wpdb->get_results(
			"SELECT (d >= 86400) + (d >= 259200) + (d >= 604800) + (d >= 1209600) + (d >= 2592000) AS bucket, COUNT(*) AS n
			FROM (SELECT start_ts - created_ts AS d FROM $tbl $where AND status = 'confirmed') t GROUP BY bucket",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $r ) {
			$out['lead'][ (int) $r['bucket'] ] = (int) $r['n'];
		}

		$rows = $wpdb->get_results(
			"SELECT (d >= 86400) + (d >= 172800) + (d >= 604800) AS bucket, COUNT(*) AS n
			FROM (SELECT start_ts - canceled_ts AS d FROM $tbl $where AND status = 'canceled' AND canceled_ts IS NOT NULL) t GROUP BY bucket",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $r ) {
			$out['cancel'][ (int) $r['bucket'] ] = (int) $r['n'];
		}
		$out['cancelUnknown'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl $where AND status = 'canceled' AND canceled_ts IS NULL" );
		// phpcs:enable

		return $out;
	}

	/**
	 * @return bool true if the filters select no item or location at all (category without members)
	 */
	public function isEmptySelection(): bool {
		return $this->itemIds === [] || $this->locationIds === [];
	}

	/**
	 * @param array{0: string, 1: string} $range
	 * @param string                      $select columns to select, ending with a comma
	 * @param string                      $groupBy columns to group by (besides unit)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped( array $range, string $select, string $groupBy ): array {
		global $wpdb;
		$group = $groupBy === '' ? 'unit' : $groupBy . ', unit';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			'SELECT ' . $select . 'unit, SUM(capacity_slots) AS capacity, SUM(booked_slots) AS booked, SUM(outage_slots) AS outage, SUM(failure_slots) AS failure,
				SUM(bookings_started) AS started, SUM(bookings_canceled) AS canceled,
				SUM(booked_slots > 0) AS usage_days, SUM(capacity_slots > 0 AND booked_slots = 0) AS idle_days,
				SUM(capacity_slots > 0 AND booked_slots >= capacity_slots) AS full_days, SUM(capacity_slots > 0) AS open_days
			FROM ' . Schema::daily() . ' ' . $this->where( $range ) . ' GROUP BY ' . $group . ' ORDER BY ' . $group,
			ARRAY_A
		);
		// phpcs:enable

		return array_map( [ self::class, 'cast' ], $rows ?: [] );
	}

	/**
	 * Unit-free totals over both booking kinds: a day of an item at a location counts once, whatever kinds of slots it has.
	 * Keys: open_days (days with capacity), usage_days (days with a booking), full_days (everything booked),
	 * planned_days (days with capacity or outage), avail and fail (days as fractions: share of the planned capacity that
	 * was available or lost to a total failure, averaged over the kinds of the day), started, canceled.
	 *
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<string, int|float> empty without data
	 */
	public function combinedTotals( array $range ): array {
		$rows = $this->combined( $range, '', '' );

		return $rows[0] ?? [];
	}

	/**
	 * @param array{0: string, 1: string} $range
	 *
	 * @return array<int, array<string, int|float|string>> by period bucket
	 */
	public function combinedSeries( array $range ): array {
		return $this->combined( $range, $this->bucketSql() . ' AS bucket, ', 'bucket' );
	}

	/**
	 * @param array{0: string, 1: string} $range
	 * @param string                      $by 'item' or 'location'
	 *
	 * @return array<int, array<string, int|float|string>>
	 */
	public function combinedBy( array $range, string $by ): array {
		$column = $by === 'item' ? 'item_id' : 'location_id';

		return $this->combined( $range, $column . ', ', $column );
	}

	/**
	 * @return array<int, array<string, int|float|string>>
	 */
	private function combined( array $range, string $select, string $groupBy ): array {
		global $wpdb;
		$planned = 'capacity_slots + outage_slots > 0';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			'SELECT ' . $select . 'SUM(cap) AS open_days, SUM(used) AS usage_days, SUM(full) AS full_days, SUM(planned) AS planned_days,
				SUM(avail_f) AS avail, SUM(fail_f) AS fail, SUM(started) AS started, SUM(canceled) AS canceled
			FROM (
				SELECT day, item_id, location_id, MAX(capacity_slots > 0) AS cap, MAX(booked_slots > 0) AS used,
					MAX(capacity_slots > 0) AND MIN(IF(capacity_slots > 0, booked_slots >= capacity_slots, 1)) AS full,
					MAX(' . $planned . ') AS planned,
					AVG(IF(' . $planned . ', capacity_slots / (capacity_slots + outage_slots), NULL)) AS avail_f,
					AVG(IF(' . $planned . ', failure_slots / (capacity_slots + outage_slots), NULL)) AS fail_f,
					SUM(bookings_started) AS started, SUM(bookings_canceled) AS canceled
				FROM ' . Schema::daily() . ' ' . $this->where( $range ) . ' GROUP BY day, item_id, location_id
			) t' . ( $groupBy === '' ? '' : ' GROUP BY ' . $groupBy . ' ORDER BY ' . $groupBy ),
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( $rows ?: [] as $row ) {
			if ( $groupBy === '' && $row['open_days'] === null ) {
				continue; // aggregate over no rows
			}
			foreach ( $row as $key => $value ) {
				if ( $key === 'bucket' ) {
					continue;
				}
				$row[ $key ] = in_array( $key, [ 'avail', 'fail' ], true ) ? (float) $value : (int) $value;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Period bucket expression for the resolution of the filters.
	 *
	 * @return string
	 */
	private function bucketSql(): string {
		switch ( $this->filters->resolution ) {
			case 'day':
				return 'day';
			case 'week':
				return 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)';
			default:
				return "DATE_FORMAT(day, '%Y-%m-01')";
		}
	}

	/**
	 * @param array{0: string, 1: string} $range
	 *
	 * @return string WHERE clause for the daily table
	 */
	private function where( array $range ): string {
		global $wpdb;
		$sql = $wpdb->prepare( 'WHERE day BETWEEN %s AND %s', $range[0], $range[1] );
		if ( $this->filters->unit !== 'all' ) {
			$sql .= $wpdb->prepare( ' AND unit = %s', $this->filters->unit );
		}

		return $sql . $this->idSql();
	}

	/**
	 * @param array{0: string, 1: string} $range
	 *
	 * @return string WHERE clause for the booking table (bookings that start in the period)
	 */
	private function bookingWhere( array $range ): string {
		global $wpdb;
		$sql = $wpdb->prepare( 'WHERE start_ts >= %d AND start_ts < %d', strtotime( $range[0] . ' 00:00:00 UTC' ), strtotime( $range[1] . ' 00:00:00 UTC' ) + 86400 );
		if ( $this->filters->unit !== 'all' ) {
			$sql .= $wpdb->prepare( ' AND unit = %s', $this->filters->unit );
		}

		return $sql . $this->idSql();
	}

	private function idSql(): string {
		$sql = '';
		if ( $this->itemIds !== null ) {
			$sql .= $this->itemIds ? ' AND item_id IN (' . implode( ',', $this->itemIds ) . ')' : ' AND 1 = 0';
		}
		if ( $this->locationIds !== null ) {
			$sql .= $this->locationIds ? ' AND location_id IN (' . implode( ',', $this->locationIds ) . ')' : ' AND 1 = 0';
		}

		return $sql;
	}

	/**
	 * Resolves explicit ids and categories to the ids that match both.
	 *
	 * @param int[]  $ids
	 * @param int[]  $terms
	 * @param string $taxonomy
	 *
	 * @return int[]|null null if neither is set
	 */
	private static function resolve( array $ids, array $terms, string $taxonomy ): ?array {
		$set = $ids ?: null;
		if ( $terms ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- ids are integers
			$byTerm = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tt.term_id IN (" . implode( ',', array_map( 'intval', $terms ) ) . ')',
					$taxonomy
				)
			);
			$byTerm = array_map( 'intval', $byTerm );
			$set    = $set === null ? $byTerm : array_values( array_intersect( $set, $byTerm ) );
		}

		return $set;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		foreach ( $row as $key => $value ) {
			if ( ! in_array( $key, [ 'bucket', 'unit' ], true ) ) {
				$row[ $key ] = (int) $value;
			}
		}

		return $row;
	}
}
