<?php

namespace CommonsBooking\Service\Index;

/**
 * Database schema of the timeframe index.
 *
 * The index is a derived projection of the posts of type cb_timeframe, cb_booking and cb_restriction.
 * The posts (and their postmeta) stay the single source of truth, the index can be rebuilt at any time.
 *
 * @see \CommonsBooking\Service\Index\Projector for how a post is mapped to a row
 * @see design/timeframe-index.md
 */
class Schema {

	/**
	 * Version of the table structure. Increase on every change of {@see self::getTableDefinitions()}.
	 */
	public const SCHEMA_VERSION = 3;

	/**
	 * Option that stores the installed schema version.
	 */
	public const SCHEMA_VERSION_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_index_schema_version';

	/**
	 * Unprefixed name of the main index table (one row per post).
	 */
	public const TABLE = 'cb_index';

	/**
	 * Unprefixed name of the relation table, used for timeframes with multiple items and/or locations.
	 */
	public const REL_TABLE = 'cb_index_rel';

	/**
	 * Fully qualified name of the main table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Fully qualified name of the relation table.
	 *
	 * @return string
	 */
	public static function relTable(): string {
		global $wpdb;
		return $wpdb->prefix . self::REL_TABLE;
	}

	/**
	 * Returns true if the tables exist in the current schema version.
	 *
	 * @return bool
	 */
	public static function isInstalled(): bool {
		return (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) === self::SCHEMA_VERSION;
	}

	/**
	 * Returns true if both tables exist in the database. Unlike {@see self::isInstalled()} this asks the database,
	 * e.g. after a database import without the booster tables (the options say "installed").
	 * Two queries, not meant for every request.
	 *
	 * @return bool
	 */
	public static function tablesExist(): bool {
		global $wpdb;
		foreach ( [ self::table(), self::relTable() ] as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Installs or upgrades the tables if needed and (re)starts the backfill.
	 * Cheap to call on every request: only reads an autoloaded option.
	 *
	 * @return void
	 */
	public static function maybeInstall(): void {
		if ( ! self::isInstalled() ) {
			// New tables or a changed structure (e.g. additional keys). The rows stay valid and readable,
			// a rebuild is only needed if the projection changed (see below).
			self::install();
		}
		if ( ! Backfill::isDone() && ! Backfill::isRunning() ) {
			// new installation, changed projector logic or an aborted backfill
			Backfill::start();
		}
	}

	/**
	 * Creates or updates the tables using dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		$installedVersion = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::getTableDefinitions() as $sql ) {
			dbDelta( $sql );
		}

		// dbDelta does not change the NULL constraint of existing columns
		if ( $installedVersion < 2 ) {
			$wpdb->query( 'ALTER TABLE ' . self::table() . ' MODIFY start_ts bigint(20) NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the tables and resets all index related options.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::relTable() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::SCHEMA_VERSION_OPTION );
		Backfill::reset();
	}

	/**
	 * Table definitions in dbDelta format.
	 *
	 * Notes:
	 * - Timestamps are copied as stored by CommonsBooking ("local time as timestamp"), no timezone conversion.
	 * - start_ts NULL means "no start date stored" (legacy queries require the meta to exist), end_ts NULL means "open end".
	 * - item_id / location_id are NULL when the timeframe uses multiple items/locations (is_multi = 1, see rel table).
	 *   For restrictions 0 means "all items" / "all locations".
	 *
	 * @return string[]
	 */
	public static function getTableDefinitions(): array {
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$table          = self::table();
		$relTable       = self::relTable();

		return [
			"CREATE TABLE $table (
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL,
  post_status varchar(20) NOT NULL,
  post_author bigint(20) unsigned NOT NULL DEFAULT 0,
  type tinyint(3) unsigned NOT NULL DEFAULT 0,
  item_id bigint(20) unsigned NULL,
  location_id bigint(20) unsigned NULL,
  is_multi tinyint(1) NOT NULL DEFAULT 0,
  start_ts bigint(20) NULL,
  end_ts bigint(20) NULL,
  repetition varchar(8) NULL,
  weekdays tinyint(3) unsigned NOT NULL DEFAULT 0,
  manual_dates text NULL,
  grid tinyint(3) unsigned NOT NULL DEFAULT 0,
  grid_size tinyint(3) unsigned NULL,
  full_day tinyint(1) NOT NULL DEFAULT 0,
  start_time time NULL,
  end_time time NULL,
  max_days smallint(5) unsigned NULL,
  advance_days smallint(5) unsigned NULL,
  startday_offset smallint(5) unsigned NULL,
  has_role_limit tinyint(1) NOT NULL DEFAULT 0,
  restriction_type varchar(10) NULL,
  restriction_state varchar(10) NULL,
  source_modified_gmt datetime NULL,
  projector_version smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id),
  KEY item_loc_range (item_id,location_id,type,end_ts,start_ts),
  KEY loc_range (location_id,type,end_ts),
  KEY type_range (type,end_ts,start_ts),
  KEY author_type (post_author,type,start_ts),
  KEY multi_type (is_multi,type),
  KEY ptype_item (post_type,item_id),
  KEY ptype_location (post_type,location_id),
  KEY ptype_end (post_type,end_ts)
) $charsetCollate;",
			"CREATE TABLE $relTable (
  post_id bigint(20) unsigned NOT NULL,
  entity_type varchar(8) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (entity_type,entity_id,post_id),
  KEY post_id (post_id)
) $charsetCollate;",
		];
	}
}
