<?php

namespace CommonsBooking\Service\Availability;

/**
 * Table of the precomputed availability: one row per item/location pair.
 *
 * The table is a cache. Every row can be recomputed from the posts at any time, rows that are missing,
 * outdated ({@see Store::isUsable()}) or belong to pairs with role limited timeframes are computed live.
 *
 * @see design/availability-precompute.md
 */
class Schema {

	/**
	 * Version of the table structure. Increase on every change of {@see self::getTableDefinition()}.
	 */
	public const SCHEMA_VERSION = 1;

	public const SCHEMA_VERSION_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_availability_schema_version';

	public const TABLE = 'cb_availability';

	/**
	 * @return string fully qualified table name
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @return bool
	 */
	public static function isInstalled(): bool {
		return (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) === self::SCHEMA_VERSION;
	}

	/**
	 * Cheap to call on every request: only reads an autoloaded option.
	 *
	 * @return void
	 */
	public static function maybeInstall(): void {
		if ( ! self::isInstalled() ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the table. The rows are a cache, so a structure change simply empties it.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		$installedVersion = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );

		if ( $installedVersion > 0 && $installedVersion !== self::SCHEMA_VERSION ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::getTableDefinition() );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the table.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Notes:
	 * - gen is increased by every change that affects the pair, computed_gen is the gen the days were computed from.
	 *   A row is only used if both are equal (see {@see Store::isUsable()}).
	 * - days holds the {@see DayFacts} flags, 2 bytes (unsigned short, little endian) per day starting at days_from.
	 * - has_role_limit marks pairs with timeframes limited to user roles, they are always computed live.
	 *
	 * @return string table definition in dbDelta format
	 */
	public static function getTableDefinition(): string {
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$table          = self::table();

		return "CREATE TABLE $table (
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  gen int(10) unsigned NOT NULL DEFAULT 1,
  computed_gen int(10) unsigned NOT NULL DEFAULT 0,
  days_from date NULL,
  days varbinary(1024) NULL,
  has_role_limit tinyint(1) NOT NULL DEFAULT 0,
  computed_at datetime NULL,
  calc_version smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (location_id,item_id),
  KEY item_id (item_id),
  KEY computed_at (computed_at)
) $charsetCollate;";
	}
}
