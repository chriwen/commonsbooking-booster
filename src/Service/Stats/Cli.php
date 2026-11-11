<?php

namespace CommonsBooking\Service\Stats;

use WP_CLI;

/**
 * Manages the aggregation for the analytics dashboard.
 *
 * ## EXAMPLES
 *
 *     wp commonsbooking stats enable
 *     wp commonsbooking stats status
 *     wp commonsbooking stats verify
 */
class Cli {

	/**
	 * Shows the state of the aggregation.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assocArgs ): void {
		global $wpdb;
		WP_CLI::log( 'Switched on:      ' . ( Stats::isEnabled() ? 'yes' : 'no' ) );
		WP_CLI::log( 'Schema installed: ' . ( Schema::isInstalled() ? 'yes (v' . Schema::SCHEMA_VERSION . ')' : 'no' ) );
		WP_CLI::log( 'Ready:            ' . ( Stats::isReady() ? 'yes' : 'no' ) );
		if ( Schema::isInstalled() && Schema::tablesExist() ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			WP_CLI::log( 'Pairs:            ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::pairs() ) . ' (open: ' . Queue::pending() . ')' );
			WP_CLI::log( 'Bookings:         ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::booking() ) );
			WP_CLI::log( 'Daily rows:       ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::daily() ) );
			// phpcs:enable
		}
	}

	/**
	 * Switches the aggregation on and builds it completely.
	 *
	 * @subcommand enable
	 */
	public function enable( $args, $assocArgs ): void {
		Stats::enable();
		$this->build();
	}

	/**
	 * Works off the queue (computes the daily values of all open pairs).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of pairs.
	 *
	 * @subcommand process
	 */
	public function process( $args, $assocArgs ): void {
		$started = microtime( true );
		$max     = isset( $assocArgs['limit'] ) ? max( 1, (int) $assocArgs['limit'] ) : PHP_INT_MAX;
		$total   = 0;
		do {
			$done   = Queue::process( 30.0, $max - $total );
			$total += $done;
			if ( $done ) {
				WP_CLI::log( sprintf( '%d pairs done, %d open (%.1fs)', $total, Queue::pending(), microtime( true ) - $started ) );
			}
		} while ( $done && $total < $max );
		Stats::finishOrSchedule();
		WP_CLI::success( sprintf( '%d pairs processed in %.1fs.', $total, microtime( true ) - $started ) );
	}

	/**
	 * Rebuilds everything: booking table and all daily values.
	 *
	 * @subcommand rebuild
	 */
	public function rebuild( $args, $assocArgs ): void {
		Build::start();
		$this->build();
	}

	/**
	 * Runs the build steps until the build is finished.
	 */
	private function build(): void {
		$started = microtime( true );
		do {
			$progress = Build::step( 30.0 );
			WP_CLI::log( sprintf( '%3d%%  %s  (%d of %d pairs open, %.1fs)', $progress['percent'], $progress['phase'], $progress['pending'], $progress['total'], microtime( true ) - $started ) );
			if ( $progress['error'] ) {
				WP_CLI::error( $progress['error'] );
			}
		} while ( ! $progress['done'] );
		WP_CLI::success( sprintf( 'Built in %.1fs.', microtime( true ) - $started ) . ( $progress['failed'] ? ' Pairs with errors: ' . $progress['failed'] : '' ) );
	}

	/**
	 * Compares sampled daily values with a fresh computation.
	 *
	 * ## OPTIONS
	 *
	 * [--pairs=<n>]
	 * : Number of randomly chosen pairs.
	 * ---
	 * default: 5
	 * ---
	 *
	 * @subcommand verify
	 */
	public function verify( $args, $assocArgs ): void {
		global $wpdb;
		$n = max( 1, (int) ( $assocArgs['pairs'] ?? 5 ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$pairs = $wpdb->get_results( $wpdb->prepare( 'SELECT item_id, location_id FROM ' . Schema::pairs() . ' WHERE dirty_from IS NULL ORDER BY RAND() LIMIT %d', $n ), ARRAY_A );
		$bad   = 0;
		foreach ( $pairs as $pair ) {
			$item  = (int) $pair['item_id'];
			$loc   = (int) $pair['location_id'];
			$sum   = 'SELECT COALESCE(SUM(capacity_slots),0) c, COALESCE(SUM(booked_slots),0) b, COALESCE(SUM(outage_slots),0) o, COALESCE(SUM(failure_slots),0) f, COALESCE(SUM(bookings_started),0) s, COALESCE(SUM(bookings_canceled),0) x FROM ' . Schema::daily() . ' WHERE item_id = %d AND location_id = %d';
			$old   = $wpdb->get_row( $wpdb->prepare( $sum, $item, $loc ), ARRAY_A );
			$first = Queue::earliestDay( $item, $loc );
			if ( $first !== null ) {
				Calculator::recompute( $item, $loc, $first, date( 'Y-m-d' ) );
			}
			$new = $wpdb->get_row( $wpdb->prepare( $sum, $item, $loc ), ARRAY_A );
			if ( $old !== $new ) {
				++$bad;
				WP_CLI::warning( "Pair item $item / location $loc differs: " . wp_json_encode( $old ) . ' -> ' . wp_json_encode( $new ) );
			}
		}
		// phpcs:enable
		if ( $bad ) {
			WP_CLI::error( "$bad of " . count( $pairs ) . ' pairs differ (recomputed now).' );
		}
		WP_CLI::success( count( $pairs ) . ' pairs checked, all identical.' );
	}

	/**
	 * Switches the aggregation off (tables stay).
	 *
	 * @subcommand disable
	 */
	public function disable( $args, $assocArgs ): void {
		Stats::disable();
		WP_CLI::success( 'Switched off.' );
	}

	/**
	 * Removes tables and options completely.
	 *
	 * @subcommand purge
	 */
	public function purge( $args, $assocArgs ): void {
		Stats::purge();
		WP_CLI::success( 'Tables and options removed.' );
	}
}
