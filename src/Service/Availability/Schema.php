<?php

namespace CommonsBooking\Service\Availability;

/**
 * Tables of the precomputed availability:
 * - {@see self::table()}: one row per item/location pair,
 * - {@see self::accessTable()}: days of pairs with role limited timeframes, one row per pair and access profile
 *   ({@see AccessProfile}, {@see AccessStore}).
 *
 * The tables are a cache. Every row can be recomputed from the posts at any time, rows that are missing or
 * outdated ({@see Store::isUsable()}, {@see AccessStore::isUsable()}) are computed live.
 *
 * @see design/availability-precompute.md
 * @see design/role-restricted-availability.md
 */
class Schema {

	/**
	 * Version of the table structure. Increase on every change of {@see self::getTableDefinitions()}.
	 * 3: table of the access profiles
	 */
	public const SCHEMA_VERSION = 3;

	public const SCHEMA_VERSION_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_availability_schema_version';

	public const TABLE = 'cb_availability';

	public const ACCESS_TABLE = 'cb_availability_access';

	/**
	 * @return string fully qualified table name
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @return string fully qualified name of the table of the access profiles
	 */
	public static function accessTable(): string {
		global $wpdb;
		return $wpdb->prefix . self::ACCESS_TABLE;
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
	 * Returns true if both tables exist in the database, see {@see \CommonsBooking\Service\Index\Schema::tablesExist()}.
	 *
	 * @return bool
	 */
	public static function tablesExist(): bool {
		global $wpdb;
		foreach ( [ self::table(), self::accessTable() ] as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Creates or updates the tables. The rows are a cache, so a structure change simply empties them.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		$installedVersion = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );

		if ( $installedVersion > 0 && $installedVersion !== self::SCHEMA_VERSION ) {
			self::dropTables();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::getTableDefinitions() );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the tables.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		self::dropTables();
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * @return void
	 */
	private static function dropTables(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() . ', ' . self::accessTable() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Notes:
	 * - gen is increased by every change that affects the pair, computed_gen is the gen the days were computed from.
	 *   A row is only used if both are equal (see {@see Store::isUsable()}).
	 * - days holds the {@see DayFacts} flags, 2 bytes (unsigned short, little endian) per day starting at days_from.
	 * - has_role_limit marks pairs with timeframes limited to user roles. Their days depend on the user and are
	 *   stored per access profile in the second table, days and slots of the pair row stay empty.
	 * - access table: access_profile is the result of {@see AccessProfile::forCurrentUser()}, computed_gen the gen
	 *   of the pair row the days were computed from, last_read the last day the row was read (for the cleanup).
	 * - slots holds the bookable slots of the same days for API and GBFS, packed with {@see Slots::pack()}.
	 *   Not loaded by {@see Store::preload()} (map and table don't need it), see {@see Store::loadWithSlots()}.
	 *
	 * @return string[] table definitions in dbDelta format
	 */
	public static function getTableDefinitions(): array {
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$table          = self::table();
		$accessTable    = self::accessTable();

		return [
			"CREATE TABLE $table (
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  gen int(10) unsigned NOT NULL DEFAULT 1,
  computed_gen int(10) unsigned NOT NULL DEFAULT 0,
  days_from date NULL,
  days varbinary(1024) NULL,
  slots mediumblob NULL,
  has_role_limit tinyint(1) NOT NULL DEFAULT 0,
  computed_at datetime NULL,
  calc_version smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (location_id,item_id),
  KEY item_id (item_id),
  KEY computed_at (computed_at)
) $charsetCollate;",
			"CREATE TABLE $accessTable (
  item_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL,
  access_profile varchar(191) NOT NULL,
  computed_gen int(10) unsigned NOT NULL,
  days_from date NOT NULL,
  days varbinary(1024) NOT NULL,
  slots mediumblob NULL,
  computed_at datetime NOT NULL,
  calc_version smallint(5) unsigned NOT NULL DEFAULT 0,
  last_read date NULL,
  PRIMARY KEY  (location_id,item_id,access_profile),
  KEY last_read (last_read)
) $charsetCollate;",
		];
	}
}
