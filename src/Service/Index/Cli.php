<?php

namespace CommonsBooking\Service\Index;

use WP_CLI;

/**
 * Manages the CommonsBooking timeframe index.
 *
 * ## EXAMPLES
 *
 *     wp commonsbooking index status
 *     wp commonsbooking index rebuild
 *     wp commonsbooking index verify --repair
 */
class Cli {

	/**
	 * Shows the state of the index.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assocArgs ): void {
		global $wpdb;
		$installed = Schema::isInstalled();
		WP_CLI::log( 'Schema installed:  ' . ( $installed ? 'yes (v' . Schema::SCHEMA_VERSION . ')' : 'no' ) );
		WP_CLI::log( 'Backfill done:     ' . ( Backfill::isDone() ? 'yes (projector v' . Projector::PROJECTOR_VERSION . ')' : 'no' ) );
		WP_CLI::log( 'Backfill running:  ' . ( Backfill::isRunning() ? 'yes (cursor ' . get_option( Backfill::CURSOR_OPTION ) . ')' : 'no' ) );
		WP_CLI::log( 'Used for reading:  ' . ( Backfill::isReady() ? 'yes' : 'no' ) );
		if ( $installed ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			WP_CLI::log( 'Rows:              ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table() ) );
			WP_CLI::log( 'Relation rows:     ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::relTable() ) );
			// phpcs:enable
		}
	}

	/**
	 * Rebuilds the index from all posts.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Posts per batch.
	 * ---
	 * default: 500
	 * ---
	 *
	 * @subcommand rebuild
	 */
	public function rebuild( $args, $assocArgs ): void {
		Schema::install();
		$started = microtime( true );
		Backfill::runAll(
			max( 1, (int) ( $assocArgs['batch-size'] ?? Backfill::DEFAULT_BATCH_SIZE ) ),
			function ( int $cursor ) {
				WP_CLI::log( "processed up to post id $cursor" );
			}
		);
		WP_CLI::success( sprintf( 'Index rebuilt in %.1fs.', microtime( true ) - $started ) );
	}

	/**
	 * Compares the index with the posts.
	 *
	 * ## OPTIONS
	 *
	 * [--sample=<n>]
	 * : Only check n random posts.
	 *
	 * [--repair]
	 * : Re-project posts with differences and remove orphaned rows.
	 *
	 * @subcommand verify
	 */
	public function verify( $args, $assocArgs ): void {
		$sample = isset( $assocArgs['sample'] ) ? (int) $assocArgs['sample'] : null;
		$repair = isset( $assocArgs['repair'] );
		$result = Verifier::verify( $sample, $repair );

		foreach ( array_slice( $result['mismatches'], 0, 20, true ) as $postId => $diff ) {
			WP_CLI::log( "post $postId: " . wp_json_encode( $diff ) );
		}
		$message = sprintf( '%d posts checked, %d mismatches, %d orphaned rows%s.', $result['checked'], count( $result['mismatches'] ), $result['orphans'], $repair ? ' (repaired)' : '' );
		if ( $result['mismatches'] || $result['orphans'] ) {
			$repair ? WP_CLI::success( $message ) : WP_CLI::warning( $message );
		} else {
			WP_CLI::success( $message );
		}
	}

	/**
	 * Drops the index tables. They are recreated and refilled on the next request.
	 *
	 * @subcommand drop
	 */
	public function drop( $args, $assocArgs ): void {
		Schema::uninstall();
		WP_CLI::success( 'Index tables dropped.' );
	}
}
