<?php

namespace CommonsBooking\Service\Booster;

use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Backfill;
use WP_CLI;

/**
 * Switches the performance booster (timeframe index and precomputed availability) on or off.
 *
 * ## EXAMPLES
 *
 *     wp commonsbooking booster status
 *     wp commonsbooking booster enable --now
 *     wp commonsbooking booster check --deep
 *     wp commonsbooking booster disable
 *     wp commonsbooking booster purge --yes
 */
class Cli {

	/**
	 * Shows whether the booster is switched on and in use.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assocArgs ): void {
		foreach ( Booster::getStatus() as $key => $value ) {
			WP_CLI::log( str_pad( $key . ':', 15 ) . $value );
		}
		$state = Booster::getState();
		WP_CLI::log( str_pad( 'guard:', 15 ) . sprintf( 'plugin version %s, checked up to post id %d', $state['plugin_version'] ?: '-', $state['watermark'] ) );
	}

	/**
	 * Switches the booster on. The index is rebuilt in the background (WP-Cron) or, with --now, right away.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Build index and availability synchronously.
	 *
	 * @subcommand enable
	 */
	public function enable( $args, $assocArgs ): void {
		Booster::setEnabled( true );
		Booster::activate();
		if ( isset( $assocArgs['now'] ) ) {
			$this->buildNow();
		}
		WP_CLI::success( 'Booster switched on.' );
		$this->status( [], [] );
	}

	/**
	 * Switches the booster off. All reads use the original queries again, the cron jobs stop.
	 * The tables stay until `purge`.
	 *
	 * @subcommand disable
	 */
	public function disable( $args, $assocArgs ): void {
		Booster::setEnabled( false );
		Booster::deactivate();
		WP_CLI::success( 'Booster switched off, the original queries are used.' );
	}

	/**
	 * Rebuilds index and availability synchronously.
	 *
	 * @subcommand rebuild
	 */
	public function rebuild( $args, $assocArgs ): void {
		if ( ! Booster::isEnabled() ) {
			WP_CLI::error( 'The booster is switched off (wp commonsbooking booster enable).' );
		}
		Booster::activate();
		$this->buildNow();
		WP_CLI::success( 'Index and availability rebuilt.' );
	}

	/**
	 * Looks for changes made without the booster (e.g. by an older plugin version) and repairs them.
	 *
	 * ## OPTIONS
	 *
	 * [--deep]
	 * : Also compare status and modification date of all posts with the index (otherwise only the quick checks).
	 *
	 * [--dry-run]
	 * : Only report differences found by --deep, do not repair.
	 *
	 * @subcommand check
	 */
	public function check( $args, $assocArgs ): void {
		if ( ! Booster::isEnabled() || ! Booster::getState()['active'] ) {
			WP_CLI::error( 'The booster is not active.' );
		}
		$reason = Guard::check();
		if ( $reason !== null ) {
			WP_CLI::warning( "Rebuild started: $reason" );

			return;
		}
		WP_CLI::log( 'Quick checks: ok' );

		if ( isset( $assocArgs['deep'] ) ) {
			$result = Guard::deepCheck( ! isset( $assocArgs['dry-run'] ) );
			WP_CLI::log( sprintf( 'Changed posts: %d %s', count( $result['changed'] ), implode( ',', array_slice( $result['changed'], 0, 20 ) ) ) );
			WP_CLI::log( sprintf( 'Rows of deleted posts: %d', count( $result['orphans'] ) ) );
			if ( $result['rebuild'] ) {
				WP_CLI::warning( 'Too many differences, rebuild started.' );
			} elseif ( ( $result['changed'] || $result['orphans'] ) && ! isset( $assocArgs['dry-run'] ) ) {
				WP_CLI::success( 'Repaired.' );
			}
		}
	}

	/**
	 * Removes all tables, options and cron jobs of the booster and switches it off.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand purge
	 */
	public function purge( $args, $assocArgs ): void {
		WP_CLI::confirm( 'Remove all booster tables and options?', $assocArgs );
		Booster::purge();
		WP_CLI::success( 'Booster removed. CommonsBooking uses the original queries.' );
	}

	/**
	 * @return void
	 */
	private function buildNow(): void {
		$started = microtime( true );
		Backfill::runAll();
		WP_CLI::log( sprintf( 'Index built in %.1fs.', microtime( true ) - $started ) );

		$started = microtime( true );
		Maintenance::syncPairs();
		Store::invalidateAll();
		$result = Maintenance::refresh( null, 0 );
		WP_CLI::log( sprintf( 'Availability of %d pairs computed in %.1fs.', $result['recomputed'], microtime( true ) - $started ) );
	}
}
