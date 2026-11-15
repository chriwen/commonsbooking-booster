<?php

namespace CommonsBooking\Service\Stats;

/**
 * Database schema of the statistics (dashboard).
 *
 * All tables are derived data: they can be dropped and rebuilt at any time, the posts stay the single source of truth.
 * No existing table or postmeta is changed.
 *
 * @see design/dashboard.md
 */
class Schema {

	/**
	 * Version of the table structure. Increase on every change of {@see self::getTableDefinitions()}.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Option that stores the installed schema version.
	 */
	public const SCHEMA_VERSION_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_stats_schema_version';

	/**
	 * Unprefixed table names.
	 */
	public const DAILY   = 'cb_stats_daily';
	public const BOOKING = 'cb_stats_booking';
	public const PAIRS   = 'cb_stats_pairs';

	/**
	 * Daily values per item, location and unit.
	 *
	 * @return string
	 */
	public static function daily(): string {
		global $wpdb;
		return $wpdb->prefix . self::DAILY;
	}

	/**
	 * One row per confirmed or cancelled booking.
	 *
	 * @return string
	 */
	public static function booking(): string {
		global $wpdb;
		return $wpdb->prefix . self::BOOKING;
	}

	/**
	 * Item/location pairs with the days that have to be recomputed.
	 *
	 * @return string
	 */
	public static function pairs(): string {
		global $wpdb;
		return $wpdb->prefix . self::PAIRS;
	}

	/**
	 * @return string[] fully qualified names of all tables
	 */
	public static function tables(): array {
		return [ self::daily(), self::booking(), self::pairs() ];
	}

	/**
	 * Returns true if the tables are installed in the current schema version (reads an autoloaded option).
	 *
	 * @return bool
	 */
	public static function isInstalled(): bool {
		return (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) === self::SCHEMA_VERSION;
	}

	/**
	 * Returns true if all tables exist in the database. Unlike {@see self::isInstalled()} this asks the database.
	 *
	 * @return bool
	 */
	public static function tablesExist(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Installs or upgrades the tables if needed.
	 *
	 * @return void
	 */
	public static function maybeInstall(): void {
		if ( ! self::isInstalled() ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the tables using dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::getTableDefinitions() as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the tables and the schema version.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Table definitions in dbDelta format.
	 *
	 * - daily.unit: 'day' = full day slots (one slot per day), 'hour' = all other slots (hourly grid or fixed time slots).
	 *   The units are kept apart, rates are only formed within one unit.
	 * - booking timestamps are "local time as timestamp" like the timeframe index, created_ts is derived from post_date
	 *   in the same way. canceled_ts is the meta cancellation_time, NULL if unknown.
	 * - booking.slots: length of the booking in its unit (days or hours, at least 1).
	 *
	 * @return string[]
	 */
	public static function getTableDefinitions(): array {
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$daily          = self::daily();
		$booking        = self::booking();
		$pairs          = self::pairs();

		return [
			"CREATE TABLE $daily (
  day date NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  unit varchar(4) NOT NULL,
  capacity_slots int(10) unsigned NOT NULL DEFAULT 0,
  booked_slots int(10) unsigned NOT NULL DEFAULT 0,
  outage_slots int(10) unsigned NOT NULL DEFAULT 0,
  failure_slots int(10) unsigned NOT NULL DEFAULT 0,
  bookings_started int(10) unsigned NOT NULL DEFAULT 0,
  bookings_canceled int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,item_id,location_id,unit),
  KEY item_day (item_id,day),
  KEY loc_day (location_id,day)
) $charsetCollate;",
			"CREATE TABLE $booking (
  booking_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  unit varchar(4) NOT NULL,
  slots int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL,
  created_ts bigint(20) NOT NULL,
  start_ts bigint(20) NOT NULL,
  end_ts bigint(20) NOT NULL,
  canceled_ts bigint(20) NULL,
  PRIMARY KEY  (booking_id),
  KEY item_start (item_id,start_ts),
  KEY loc_start (location_id,start_ts),
  KEY user_start (user_id,start_ts),
  KEY start_status (start_ts,status)
) $charsetCollate;",
			"CREATE TABLE $pairs (
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  dirty_from date NULL,
  dirty_to date NULL,
  computed_at datetime NULL,
  PRIMARY KEY  (item_id,location_id),
  KEY dirty (dirty_from)
) $charsetCollate;",
		];
	}
}
