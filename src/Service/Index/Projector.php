<?php

namespace CommonsBooking\Service\Index;

use CommonsBooking\Model\Restriction as RestrictionModel;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Wordpress\CustomPostType\Booking;
use CommonsBooking\Wordpress\CustomPostType\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use WP_Post;

/**
 * Maps a post (cb_timeframe, cb_booking, cb_restriction) to its index row(s) and writes them.
 *
 * All methods are idempotent: projecting the same post twice yields the same rows.
 * If the post does not exist (anymore) or is not indexable, its rows are removed.
 */
class Projector {

	/**
	 * Version of the mapping logic. Increase when {@see self::buildRow()} changes in a way that requires
	 * existing rows to be rebuilt. A change triggers a new backfill.
	 */
	public const PROJECTOR_VERSION = 2;

	/**
	 * Post statuses that are never indexed.
	 */
	private const IGNORED_STATUSES = [ 'auto-draft' ];

	/**
	 * Action fired when the index rows of a post changed, see {@see self::notify()}.
	 */
	public const CHANGED_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_index_rows_changed';

	/**
	 * Returns the post types which are part of the index.
	 *
	 * @return string[]
	 */
	public static function getPostTypes(): array {
		return [ Timeframe::$postType, Booking::$postType, Restriction::$postType ];
	}

	/**
	 * Returns the meta keys whose change requires a re-projection.
	 *
	 * @return string[]
	 */
	public static function getRelevantMetaKeys(): array {
		return [
			'type',
			TimeframeModel::META_ITEM_ID,
			TimeframeModel::META_LOCATION_ID,
			TimeframeModel::META_ITEM_ID_LIST,
			TimeframeModel::META_LOCATION_ID_LIST,
			TimeframeModel::REPETITION_START,
			TimeframeModel::REPETITION_END,
			TimeframeModel::META_REPETITION,
			TimeframeModel::META_MANUAL_SELECTION,
			TimeframeModel::META_MAX_DAYS,
			TimeframeModel::META_TIMEFRAME_ADVANCE_BOOKING_DAYS,
			TimeframeModel::META_BOOKING_START_DAY_OFFSET,
			TimeframeModel::META_ALLOWED_USER_ROLES,
			'weekdays',
			'grid',
			'full-day',
			'start-time',
			'end-time',
			RestrictionModel::META_START,
			RestrictionModel::META_END,
			RestrictionModel::META_TYPE,
			RestrictionModel::META_STATE,
			RestrictionModel::META_ITEM_ID,
			RestrictionModel::META_LOCATION_ID,
		];
	}

	/**
	 * Builds the row and writes it, or removes the rows if the post is not indexable.
	 *
	 * @param int  $postId
	 * @param bool $notify fire {@see self::CHANGED_HOOK} if the rows changed (off for the backfill)
	 *
	 * @return void
	 */
	public static function project( int $postId, bool $notify = true ): void {
		$post = get_post( $postId );
		$data = $post instanceof WP_Post ? self::buildRow( $post ) : null;
		$old  = $notify && has_action( self::CHANGED_HOOK ) ? self::load( $postId ) : null;

		if ( $data === null ) {
			self::delete( $postId );
		} else {
			self::write( $data['row'], $data['rel'] );
		}

		if ( $notify ) {
			self::notify( $postId, $old, $data );
		}
	}

	/**
	 * Removes all rows of a post.
	 *
	 * @param int  $postId
	 * @param bool $notify fire {@see self::CHANGED_HOOK} if rows existed
	 *
	 * @return void
	 */
	public static function remove( int $postId, bool $notify = true ): void {
		$old = $notify && has_action( self::CHANGED_HOOK ) ? self::load( $postId ) : null;
		self::delete( $postId );
		if ( $notify ) {
			self::notify( $postId, $old, null );
		}
	}

	/**
	 * Reads the stored rows of a post.
	 *
	 * @param int $postId
	 *
	 * @return array{row: array<string, mixed>, rel: array<int, array{entity_type: string, entity_id: int}>}|null
	 */
	public static function load( int $postId ): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE post_id = %d', $postId ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$rel = $wpdb->get_results( $wpdb->prepare( 'SELECT entity_type, entity_id FROM ' . Schema::relTable() . ' WHERE post_id = %d', $postId ), ARRAY_A );
		// phpcs:enable

		return [
			'row' => $row,
			'rel' => array_map(
				fn( $r ) => [
					'entity_type' => $r['entity_type'],
					'entity_id'   => (int) $r['entity_id'],
				],
				$rel
			),
		];
	}

	/**
	 * Fires {@see self::CHANGED_HOOK} if the projection of a post changed.
	 *
	 * @param int        $postId
	 * @param array|null $old rows before the change
	 * @param array|null $new rows after the change
	 *
	 * @return void
	 */
	private static function notify( int $postId, ?array $old, ?array $new ): void {
		if ( self::fingerprint( $old ) === self::fingerprint( $new ) ) {
			return;
		}

		/**
		 * The index rows of a post changed (created, changed or removed).
		 *
		 * @param array|null $old rows before the change ({@see self::load()} format), null if there were none
		 * @param array|null $new rows after the change ({@see self::buildRow()} format), null if removed
		 * @param int        $postId
		 */
		do_action( self::CHANGED_HOOK, $old, $new, $postId );
	}

	/**
	 * Comparable representation of rows, ignoring bookkeeping columns.
	 *
	 * @param array|null $data
	 *
	 * @return string|null
	 */
	private static function fingerprint( ?array $data ): ?string {
		if ( $data === null ) {
			return null;
		}
		$row = $data['row'];
		unset( $row['source_modified_gmt'], $row['projector_version'] );
		ksort( $row );
		$row = array_map( [ self::class, 'normalizeForCompare' ], $row );
		$rel = array_map( fn( $r ) => $r['entity_type'] . ':' . $r['entity_id'], $data['rel'] );
		sort( $rel );

		return serialize( [ $row, $rel ] );
	}

	/**
	 * @param int $postId
	 *
	 * @return void
	 */
	private static function delete( int $postId ): void {
		global $wpdb;
		$wpdb->delete( Schema::table(), [ 'post_id' => $postId ], [ '%d' ] );
		$wpdb->delete( Schema::relTable(), [ 'post_id' => $postId ], [ '%d' ] );
	}

	/**
	 * Builds the index row and relation rows for a post without writing anything.
	 *
	 * @param WP_Post $post
	 *
	 * @return array{row: array<string, mixed>, rel: array<int, array{entity_type: string, entity_id: int}>}|null null if the post is not indexable
	 */
	public static function buildRow( WP_Post $post ): ?array {
		if ( ! in_array( $post->post_type, self::getPostTypes(), true ) ) {
			return null;
		}
		if ( in_array( $post->post_status, self::IGNORED_STATUSES, true ) ) {
			return null;
		}

		$meta = self::getMeta( $post->ID );

		$row = [
			'post_id'             => (int) $post->ID,
			'post_type'           => $post->post_type,
			'post_status'         => $post->post_status,
			'post_author'         => (int) $post->post_author,
			'type'                => 0,
			'item_id'             => null,
			'location_id'         => null,
			'is_multi'            => 0,
			'start_ts'            => null,
			'end_ts'              => null,
			'repetition'          => null,
			'weekdays'            => 0,
			'manual_dates'        => null,
			'grid'                => 0,
			'grid_size'           => null,
			'full_day'            => 0,
			'start_time'          => null,
			'end_time'            => null,
			'max_days'            => null,
			'advance_days'        => null,
			'startday_offset'     => null,
			'has_role_limit'      => 0,
			'restriction_type'    => null,
			'restriction_state'   => null,
			'source_modified_gmt' => self::normalizeDateTime( $post->post_modified_gmt ),
			'projector_version'   => self::PROJECTOR_VERSION,
		];
		$rel = [];

		if ( $post->post_type === Restriction::$postType ) {
			$row = self::fillRestriction( $row, $meta );
		} else {
			list( $row, $rel ) = self::fillTimeframe( $row, $meta );
		}

		return [
			'row' => $row,
			'rel' => $rel,
		];
	}

	/**
	 * Compares the stored rows of a post with the projection.
	 *
	 * @param int $postId
	 *
	 * @return array|null null if the index is consistent, otherwise an array with 'expected' and 'actual'
	 */
	public static function diff( int $postId ): ?array {
		global $wpdb;
		$post     = get_post( $postId );
		$expected = $post instanceof WP_Post ? self::buildRow( $post ) : null;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actualRow = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE post_id = %d', $postId ), ARRAY_A );
		$actualRel = $wpdb->get_results( $wpdb->prepare( 'SELECT entity_type, entity_id FROM ' . Schema::relTable() . ' WHERE post_id = %d ORDER BY entity_type, entity_id', $postId ), ARRAY_A );
		// phpcs:enable

		if ( $expected === null ) {
			return ( $actualRow === null && ! $actualRel ) ? null : [
				'expected' => null,
				'actual'   => $actualRow,
			];
		}

		if ( $actualRow === null ) {
			return [
				'expected' => $expected['row'],
				'actual'   => null,
			];
		}

		$differences = [];
		foreach ( $expected['row'] as $column => $value ) {
			$stored = $actualRow[ $column ] ?? null;
			if ( self::normalizeForCompare( $value ) !== self::normalizeForCompare( $stored ) ) {
				$differences[ $column ] = [
					'expected' => $value,
					'actual'   => $stored,
				];
			}
		}

		$expectedRel = array_map(
			fn( $r ) => $r['entity_type'] . ':' . $r['entity_id'],
			$expected['rel']
		);
		$storedRel   = array_map(
			fn( $r ) => $r['entity_type'] . ':' . $r['entity_id'],
			$actualRel
		);
		sort( $expectedRel );
		sort( $storedRel );
		if ( $expectedRel !== $storedRel ) {
			$differences['rel'] = [
				'expected' => $expectedRel,
				'actual'   => $storedRel,
			];
		}

		return $differences ?: null;
	}

	/**
	 * Writes a row and replaces its relation rows.
	 *
	 * @param array $row
	 * @param array $rel
	 *
	 * @return void
	 */
	private static function write( array $row, array $rel ): void {
		global $wpdb;

		$wpdb->replace( Schema::table(), $row, self::getFormats( $row ) );

		$wpdb->delete( Schema::relTable(), [ 'post_id' => $row['post_id'] ], [ '%d' ] );
		if ( $rel ) {
			$values = [];
			foreach ( $rel as $relation ) {
				$values[] = $wpdb->prepare( '(%d, %s, %d)', $row['post_id'], $relation['entity_type'], $relation['entity_id'] );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- values are prepared above
			$wpdb->query( 'INSERT IGNORE INTO ' . Schema::relTable() . ' (post_id, entity_type, entity_id) VALUES ' . implode( ',', $values ) );
		}
	}

	/**
	 * Fills the row for timeframes and bookings.
	 *
	 * Item / location matching mirrors the legacy SQL in {@see \CommonsBooking\Repository\Timeframe::getEntityQuery()}:
	 * a post matches an item if the item is the single item-id OR part of the item-id-list (same for locations).
	 *
	 * @param array $row
	 * @param array $meta
	 *
	 * @return array [row, rel]
	 */
	private static function fillTimeframe( array $row, array $meta ): array {
		$row['type'] = (int) self::metaValue( $meta, 'type' );

		$itemIds     = self::collectIds( $meta, TimeframeModel::META_ITEM_ID, TimeframeModel::META_ITEM_ID_LIST );
		$locationIds = self::collectIds( $meta, TimeframeModel::META_LOCATION_ID, TimeframeModel::META_LOCATION_ID_LIST );

		$rel = [];
		if ( count( $itemIds ) > 1 || count( $locationIds ) > 1 ) {
			$row['is_multi'] = 1;
			foreach ( $itemIds as $itemId ) {
				$rel[] = [
					'entity_type' => 'item',
					'entity_id'   => $itemId,
				];
			}
			foreach ( $locationIds as $locationId ) {
				$rel[] = [
					'entity_type' => 'location',
					'entity_id'   => $locationId,
				];
			}
		} else {
			$row['item_id']     = $itemIds ? reset( $itemIds ) : null;
			$row['location_id'] = $locationIds ? reset( $locationIds ) : null;
		}

		$row['start_ts'] = self::toTimestamp( self::metaValue( $meta, TimeframeModel::REPETITION_START ), true );
		$row['end_ts']   = self::toTimestamp( self::metaValue( $meta, TimeframeModel::REPETITION_END ) );

		$repetition        = (string) self::metaValue( $meta, TimeframeModel::META_REPETITION );
		$row['repetition'] = $repetition !== '' ? substr( $repetition, 0, 8 ) : null;
		$row['weekdays']   = self::weekdaysToBitmask( self::metaValue( $meta, 'weekdays' ) );

		if ( $row['repetition'] === 'manual' ) {
			$manual = (string) self::metaValue( $meta, TimeframeModel::META_MANUAL_SELECTION );
			if ( $manual !== '' ) {
				$row['manual_dates'] = wp_json_encode( array_values( array_filter( array_map( 'trim', explode( ',', $manual ) ) ) ) );
			}
		}

		$row['grid']       = (int) self::metaValue( $meta, 'grid' );
		$row['full_day']   = self::metaValue( $meta, 'full-day' ) === 'on' ? 1 : 0;
		$row['start_time'] = self::toTime( self::metaValue( $meta, 'start-time' ) );
		$row['end_time']   = self::toTime( self::metaValue( $meta, 'end-time' ) );
		$row['grid_size']  = self::gridSize( $row );

		$row['max_days']        = self::toSmallInt( self::metaValue( $meta, TimeframeModel::META_MAX_DAYS ) );
		$row['advance_days']    = self::toSmallInt( self::metaValue( $meta, TimeframeModel::META_TIMEFRAME_ADVANCE_BOOKING_DAYS ) );
		$row['startday_offset'] = self::toSmallInt( self::metaValue( $meta, TimeframeModel::META_BOOKING_START_DAY_OFFSET ) );
		$row['has_role_limit']  = empty( self::metaValue( $meta, TimeframeModel::META_ALLOWED_USER_ROLES ) ) ? 0 : 1;

		return [ $row, $rel ];
	}

	/**
	 * Fills the row for restrictions. 0 for item / location means "all".
	 *
	 * @param array $row
	 * @param array $meta
	 *
	 * @return array
	 */
	private static function fillRestriction( array $row, array $meta ): array {
		$row['item_id']           = (int) self::metaValue( $meta, RestrictionModel::META_ITEM_ID );
		$row['location_id']       = (int) self::metaValue( $meta, RestrictionModel::META_LOCATION_ID );
		$start                    = self::metaValue( $meta, RestrictionModel::META_START );
		$row['start_ts']          = ( $start === '' || $start === null || $start === false ) ? null : (int) $start;
		$end                      = self::metaValue( $meta, RestrictionModel::META_END );
		$row['end_ts']            = ( $end === '' || $end === null || $end === false ) ? null : (int) $end;
		$type                     = (string) self::metaValue( $meta, RestrictionModel::META_TYPE );
		$state                    = (string) self::metaValue( $meta, RestrictionModel::META_STATE );
		$row['restriction_type']  = $type !== '' ? substr( $type, 0, 10 ) : null;
		$row['restriction_state'] = $state !== '' ? substr( $state, 0, 10 ) : null;

		return $row;
	}

	/**
	 * Returns all meta of a post as key => list of (unserialized) values.
	 *
	 * @param int $postId
	 *
	 * @return array
	 */
	private static function getMeta( int $postId ): array {
		$meta = get_post_meta( $postId );
		return is_array( $meta ) ? $meta : [];
	}

	/**
	 * Returns the first value of a meta key (like get_post_meta( ..., true )).
	 *
	 * @param array  $meta
	 * @param string $key
	 *
	 * @return mixed '' if not set
	 */
	private static function metaValue( array $meta, string $key ) {
		if ( ! isset( $meta[ $key ][0] ) ) {
			return '';
		}
		return maybe_unserialize( $meta[ $key ][0] );
	}

	/**
	 * Union of the single id meta (all values, like the legacy SQL join) and the id list meta.
	 *
	 * @param array  $meta
	 * @param string $singleKey
	 * @param string $listKey
	 *
	 * @return int[] unique, positive, sorted
	 */
	private static function collectIds( array $meta, string $singleKey, string $listKey ): array {
		$ids = [];
		foreach ( $meta[ $singleKey ] ?? [] as $value ) {
			$ids[] = (int) maybe_unserialize( $value );
		}
		$list = self::metaValue( $meta, $listKey );
		if ( is_array( $list ) ) {
			foreach ( $list as $value ) {
				$ids[] = (int) $value;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids, fn( $id ) => $id > 0 ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Converts a stored date value to a timestamp, same semantics as {@see TimeframeModel::getStartDate()}.
	 *
	 * @param mixed $value
	 * @param bool  $zeroIsValue true: 0 is a valid timestamp (start), false: 0 means "not set" (end)
	 *
	 * @return int|null null for empty values
	 */
	private static function toTimestamp( $value, bool $zeroIsValue = false ): ?int {
		if ( $value === '' || $value === null || $value === false ) {
			return null;
		}
		if ( ! $zeroIsValue && ( $value === 0 || $value === '0' ) ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$timestamp = strtotime( (string) $value );

		return $timestamp === false ? null : $timestamp;
	}

	/**
	 * Converts a time string like '8:00 AM' or '23:59' to 'H:i:s'.
	 *
	 * @param mixed $value
	 *
	 * @return string|null
	 */
	private static function toTime( $value ): ?string {
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			return null;
		}
		$timestamp = strtotime( '1970-01-01 ' . $value . ' UTC' );

		return $timestamp === false ? null : gmdate( 'H:i:s', $timestamp );
	}

	/**
	 * Same semantics as {@see TimeframeModel::getGridSize()}.
	 *
	 * @param array $row
	 *
	 * @return int|null
	 */
	private static function gridSize( array $row ): ?int {
		if ( $row['full_day'] ) {
			return 24;
		}
		if ( $row['grid'] === 0 ) {
			if ( $row['start_time'] === null || $row['end_time'] === null ) {
				return null;
			}
			$start = strtotime( '1970-01-01 ' . $row['start_time'] . ' UTC' );
			$end   = strtotime( '1970-01-01 ' . $row['end_time'] . ' UTC' );

			return min( 255, (int) round( abs( $end - $start ) / 3600, 2 ) );
		}

		return min( 255, $row['grid'] );
	}

	/**
	 * @param mixed $value
	 *
	 * @return int|null
	 */
	private static function toSmallInt( $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, min( 65535, (int) $value ) );
	}

	/**
	 * Converts the weekdays meta (array of '1'..'7', Monday = 1) to a bitmask (Monday = bit 0).
	 *
	 * @param mixed $weekdays
	 *
	 * @return int
	 */
	private static function weekdaysToBitmask( $weekdays ): int {
		if ( ! is_array( $weekdays ) ) {
			return 0;
		}
		$mask = 0;
		foreach ( $weekdays as $day ) {
			$day = (int) $day;
			if ( $day >= 1 && $day <= 7 ) {
				$mask |= 1 << ( $day - 1 );
			}
		}

		return $mask;
	}

	/**
	 * @param mixed $value
	 *
	 * @return string|null
	 */
	private static function normalizeDateTime( $value ): ?string {
		if ( ! is_string( $value ) || $value === '' || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Normalizes values for comparing a projected row with a stored row (which only contains strings / null).
	 *
	 * @param mixed $value
	 *
	 * @return string|null
	 */
	private static function normalizeForCompare( $value ): ?string {
		return $value === null ? null : (string) $value;
	}

	/**
	 * wpdb formats for a row.
	 *
	 * @param array $row
	 *
	 * @return string[]
	 */
	private static function getFormats( array $row ): array {
		$strings = [ 'post_type', 'post_status', 'repetition', 'manual_dates', 'start_time', 'end_time', 'restriction_type', 'restriction_state', 'source_modified_gmt' ];
		$formats = [];
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $strings, true ) ? '%s' : '%d';
		}

		return $formats;
	}
}
