<?php

namespace CommonsBooking\Service\Stats;

use CommonsBooking\Model\Restriction as RestrictionModel;
use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Wordpress\CustomPostType\Booking;
use CommonsBooking\Wordpress\CustomPostType\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Aggregation for the analytics dashboard: keeps {@see Schema::daily()} and {@see Schema::booking()} up to date.
 *
 * Switched on with the option {@see self::ENABLED_OPTION} (default off, `wp commonsbooking stats enable`). Hooks only
 * collect the ids of changed posts; the work happens at the end of the request ({@see self::flush()}) because meta
 * values such as `cancellation_time` are written after the status change. What is not finished there stays in the
 * queue ({@see Queue}) and is worked off by WP-Cron.
 *
 * @see design/dashboard.md
 */
class Stats {

	public const ENABLED_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_stats_enabled';
	public const READY_OPTION   = COMMONSBOOKING_PLUGIN_SLUG . '_stats_ready';

	public const CRON_PROCESS  = COMMONSBOOKING_PLUGIN_SLUG . '_stats_process';
	public const CRON_ROLLOVER = COMMONSBOOKING_PLUGIN_SLUG . '_stats_rollover';

	/**
	 * Seconds per cron run.
	 */
	private const CRON_BUDGET = 20.0;

	/**
	 * Pairs that are computed synchronously at the end of a request, the rest goes to WP-Cron.
	 */
	private const REQUEST_PAIRS = 2;

	private static bool $initialized = false;

	/**
	 * @var int[]
	 */
	private static array $bookings = [];

	/**
	 * @var int[]
	 */
	private static array $others = [];

	/**
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'commonsbooking stats', Cli::class );
		}
		if ( is_admin() ) {
			\CommonsBooking\View\Admin\Stats\Page::init();
		}
		if ( ! self::isEnabled() ) {
			return;
		}
		if ( ! Schema::isInstalled() && ! Build::isRunning() ) {
			// plugin update with a new table layout: install it and recompute everything
			Build::start();
		}

		add_action( 'transition_post_status', [ self::class, 'onTransition' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'onMetaChange' ], 10, 3 );
		add_action( 'updated_post_meta', [ self::class, 'onMetaChange' ], 10, 3 );
		add_action( 'commonsbooking_booster_rebuild', [ self::class, 'onBoosterRebuild' ] );
		add_action( 'commonsbooking_booster_repaired', [ self::class, 'onBoosterRepaired' ] );
		add_action( 'save_post_' . Timeframe::$postType, [ self::class, 'onOtherSaved' ] );
		add_action( 'save_post_' . Restriction::$postType, [ self::class, 'onOtherSaved' ] );
		add_action( 'before_delete_post', [ self::class, 'onDelete' ] );
		add_action( 'shutdown', [ self::class, 'flush' ], 20 );
		add_action( self::CRON_PROCESS, [ self::class, 'cronProcess' ] );
		add_action( self::CRON_ROLLOVER, [ self::class, 'cronRollover' ] );

		if ( ! wp_next_scheduled( self::CRON_ROLLOVER ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:20' ), 'daily', self::CRON_ROLLOVER );
		}
	}

	/**
	 * @return bool
	 */
	public static function isEnabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * True if the aggregation is up to date enough to show: switched on, built once and nothing open.
	 *
	 * @return bool
	 */
	public static function isReady(): bool {
		return self::isEnabled() && get_option( self::READY_OPTION, false ) && Schema::isInstalled();
	}

	/**
	 * Switches the aggregation on and starts the initial build, see {@see Build}. Returns at once.
	 *
	 * @return void
	 */
	public static function enable(): void {
		Build::start();
	}

	/**
	 * Switches the aggregation off, the tables stay.
	 *
	 * @return void
	 */
	public static function disable(): void {
		update_option( self::ENABLED_OPTION, false, true );
		wp_clear_scheduled_hook( self::CRON_PROCESS );
		wp_clear_scheduled_hook( self::CRON_ROLLOVER );
	}

	/**
	 * Removes tables and options.
	 *
	 * @return void
	 */
	public static function purge(): void {
		self::disable();
		Schema::uninstall();
		delete_option( self::ENABLED_OPTION );
		delete_option( self::READY_OPTION );
		delete_option( Build::STATE_OPTION );
		delete_option( Build::LOCK_OPTION );
		delete_option( Queue::FAILURES_OPTION );
	}

	/**
	 * @param string   $new
	 * @param string   $old
	 * @param \WP_Post $post
	 *
	 * @return void
	 */
	public static function onTransition( $new, $old, $post ): void {
		if ( $new === $old ) {
			return;
		}
		if ( $post->post_type === Booking::$postType ) {
			self::$bookings[] = (int) $post->ID;
		}
	}

	/**
	 * `Model\Booking::cancel()` sets the status with direct SQL, without `transition_post_status`. The meta
	 * `cancellation_time` written right after it is the only hook of that path (the booster index uses it as well).
	 *
	 * @param int    $metaId
	 * @param int    $postId
	 * @param string $metaKey
	 *
	 * @return void
	 */
	public static function onMetaChange( $metaId, $postId, $metaKey ): void {
		if ( $metaKey === 'cancellation_time' && get_post_type( (int) $postId ) === Booking::$postType ) {
			self::$bookings[] = (int) $postId;
		}
	}

	/**
	 * The booster guard found changes made while this code did not run (downgrade, database import, posts created by
	 * other code) and rebuilds the index. The statistics are outdated in the same way and are rebuilt too.
	 * A manual rebuild of the index says nothing about the statistics.
	 *
	 * @param string $code see {@see \CommonsBooking\Service\Booster\Guard::describeRebuild()}
	 *
	 * @return void
	 */
	public static function onBoosterRebuild( $code ): void {
		if ( $code === 'manual' ) {
			return;
		}
		commonsbooking_write_log( 'Statistics: rebuilding together with the booster index. Reason: ' . $code );
		Build::start();
	}

	/**
	 * The booster guard repaired single posts changed without the hooks: the bookings among them are projected again.
	 *
	 * @param int[] $postIds changed and deleted posts
	 *
	 * @return void
	 */
	public static function onBoosterRepaired( $postIds ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $postIds ) ) );
		if ( ! $ids ) {
			return;
		}
		// deleted posts have no type any more, they are found by their row in the booking table
		$list = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known = array_map( 'intval', $wpdb->get_col( 'SELECT booking_id FROM ' . Schema::booking() . " WHERE booking_id IN ($list)" ) );
		foreach ( $ids as $postId ) {
			if ( in_array( $postId, $known, true ) || get_post_type( $postId ) === Booking::$postType ) {
				self::$bookings[] = $postId;
			}
		}
	}

	/**
	 * @param int $postId
	 *
	 * @return void
	 */
	public static function onOtherSaved( $postId ): void {
		self::$others[] = (int) $postId;
	}

	/**
	 * @param int $postId
	 *
	 * @return void
	 */
	public static function onDelete( $postId ): void {
		$type = get_post_type( $postId );
		if ( $type === Booking::$postType ) {
			self::$bookings[] = (int) $postId;
		} elseif ( $type === Timeframe::$postType || $type === Restriction::$postType ) {
			self::$others[] = (int) $postId;
		}
	}

	/**
	 * Processes the collected posts at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( ! self::$bookings && ! self::$others ) {
			return;
		}
		$bookings       = array_unique( self::$bookings );
		$others         = array_unique( self::$others );
		self::$bookings = [];
		self::$others   = [];

		if ( $bookings ) {
			self::markBookings( $bookings );
		}
		foreach ( $others as $postId ) {
			self::markOther( (int) $postId );
		}

		if ( ! Build::isRunning() ) {
			Queue::process( 5.0, self::REQUEST_PAIRS );
		}
		self::finishOrSchedule();
	}

	/**
	 * Marks the build as done if nothing is open, otherwise makes sure WP-Cron continues.
	 *
	 * @return void
	 */
	public static function finishOrSchedule(): void {
		if ( Build::isRunning() || Queue::pending() ) {
			self::scheduleProcess();
		} elseif ( ! get_option( self::READY_OPTION, false ) ) {
			update_option( self::READY_OPTION, time(), true );
		}
	}

	/**
	 * Updates the booking rows and marks the days of the affected pairs.
	 *
	 * @param int[] $ids
	 *
	 * @return void
	 */
	private static function markBookings( array $ids ): void {
		$pairs = Bookings::pairsOf( $ids );
		Bookings::projectIds( $ids );
		$pairs = array_merge( $pairs, Bookings::pairsOf( $ids ) );
		$today = date( 'Y-m-d' );
		foreach ( $pairs as $pair ) {
			$from = gmdate( 'Y-m-d', $pair['start_ts'] );
			if ( $from <= $today ) {
				Queue::mark( $pair['item_id'], $pair['location_id'], $from );
			}
		}
	}

	/**
	 * Marks the affected pairs of a timeframe or restriction. Posts that select items or locations by category
	 * or in lists are covered by the nightly discovery of new pairs and the daily recomputation of recent days.
	 *
	 * @param int $postId
	 *
	 * @return void
	 */
	private static function markOther( int $postId ): void {
		global $wpdb;
		$isRestriction = get_post_type( $postId ) === Restriction::$postType;
		$itemKey       = $isRestriction ? RestrictionModel::META_ITEM_ID : TimeframeModel::META_ITEM_ID;
		$locationKey   = $isRestriction ? RestrictionModel::META_LOCATION_ID : TimeframeModel::META_LOCATION_ID;
		$startKey      = $isRestriction ? RestrictionModel::META_START : TimeframeModel::REPETITION_START;

		$items     = array_filter( array_map( 'intval', (array) get_post_meta( $postId, $itemKey, true ) ) );
		$locations = array_filter( array_map( 'intval', (array) get_post_meta( $postId, $locationKey, true ) ) );
		if ( ! $isRestriction ) {
			$items     = array_merge( $items, array_map( 'intval', (array) get_post_meta( $postId, TimeframeModel::META_ITEM_ID_LIST, true ) ) );
			$locations = array_merge( $locations, array_map( 'intval', (array) get_post_meta( $postId, TimeframeModel::META_LOCATION_ID_LIST, true ) ) );
		}
		$items     = array_values( array_unique( array_filter( $items ) ) );
		$locations = array_values( array_unique( array_filter( $locations ) ) );

		$start = (int) get_post_meta( $postId, $startKey, true );
		$from  = $start > 0 ? max( gmdate( 'Y-m-d', $start ), Queue::FROM_BEGINNING ) : Queue::FROM_BEGINNING;
		$today = date( 'Y-m-d' );
		if ( $from > $today ) {
			return;
		}

		if ( $items && $locations ) {
			foreach ( array_slice( $items, 0, 50 ) as $itemId ) {
				foreach ( array_slice( $locations, 0, 50 ) as $locationId ) {
					Queue::mark( $itemId, $locationId, $from );
				}
			}
			return;
		}

		// only one side known: all known pairs of it
		$where = $items ? 'item_id IN (' . implode( ',', $items ) . ')' : ( $locations ? 'location_id IN (' . implode( ',', $locations ) . ')' : '1=1' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known = $wpdb->get_results( 'SELECT item_id, location_id FROM ' . Schema::pairs() . ' WHERE ' . $where, ARRAY_A );
		foreach ( $known as $pair ) {
			Queue::mark( (int) $pair['item_id'], (int) $pair['location_id'], $from );
		}
	}

	/**
	 * @return void
	 */
	public static function scheduleProcess(): void {
		if ( ! wp_next_scheduled( self::CRON_PROCESS ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_PROCESS );
		}
	}

	/**
	 * WP-Cron: works off the queue, continues until it is empty.
	 *
	 * @return void
	 */
	public static function cronProcess(): void {
		if ( Build::isRunning() ) {
			Build::step( Build::budget( self::CRON_BUDGET ) );
		} else {
			Queue::process( self::CRON_BUDGET );
		}
		self::finishOrSchedule();
	}

	/**
	 * WP-Cron, daily: finds new pairs, closes yesterday, starts today.
	 *
	 * @return void
	 */
	public static function cronRollover(): void {
		Queue::discoverPairs();
		Queue::markRecent();
		self::scheduleProcess();
	}
}
