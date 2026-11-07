<?php

namespace CommonsBooking\Service\Availability;

use WP_CLI;

/**
 * Manages the precomputed availability of CommonsBooking.
 *
 * ## EXAMPLES
 *
 *     wp commonsbooking availability status
 *     wp commonsbooking availability rebuild
 *     wp commonsbooking availability verify --repair
 */
class Cli {

	/**
	 * Shows the state of the precomputed availability.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assocArgs ): void {
		$installed     = Schema::isInstalled();
		[ $from, $to ] = Availability::getHorizon();
		WP_CLI::log( 'Schema installed:  ' . ( $installed ? 'yes (v' . Schema::SCHEMA_VERSION . ')' : 'no' ) );
		WP_CLI::log( 'Used for reading:  ' . ( Availability::isEnabled() ? 'yes' : 'no (needs a complete timeframe index)' ) );
		WP_CLI::log( "Horizon:           $from to $to" );
		if ( $installed ) {
			$stats = Store::getStats();
			WP_CLI::log( 'Pairs:             ' . $stats['rows'] );
			WP_CLI::log( 'Usable:            ' . $stats['usable'] );
			WP_CLI::log( 'Changed (pending): ' . $stats['changed'] );
			$access = AccessStore::getStats();
			WP_CLI::log( 'Role limited:      ' . $stats['role_limited'] . ( AccessProfile::isEnabled() ? ' (stored per access profile)' : ' (computed live)' ) );
			WP_CLI::log( sprintf( 'Access profiles:   %d (%d usable) for %d pairs, at most %d per pair', $access['rows'], $access['usable'], $access['pairs'], AccessProfile::getMaxPerPair() ) );
		}
	}

	/**
	 * Creates rows for all pairs and computes them.
	 *
	 * @subcommand rebuild
	 */
	public function rebuild( $args, $assocArgs ): void {
		Schema::install();
		if ( ! Availability::isEnabled() ) {
			WP_CLI::warning( 'The timeframe index is not ready, the result will not be used yet (see wp commonsbooking index status).' );
		}
		$started = microtime( true );
		$sync    = Maintenance::syncPairs();
		Store::invalidateAll();
		$result = Maintenance::refresh( null, 0 );
		WP_CLI::success(
			sprintf(
				'%d pairs (%d new, %d removed), %d computed in %.1fs.',
				$sync['pairs'],
				$sync['created'],
				$sync['deleted'],
				$result['recomputed'],
				microtime( true ) - $started
			)
		);
	}

	/**
	 * Compares stored rows with a fresh computation.
	 *
	 * ## OPTIONS
	 *
	 * [--sample=<n>]
	 * : Only check n random pairs.
	 *
	 * [--repair]
	 * : Recompute pairs with differences.
	 *
	 * @subcommand verify
	 */
	public function verify( $args, $assocArgs ): void {
		$sample = isset( $assocArgs['sample'] ) ? (int) $assocArgs['sample'] : null;
		$result = Maintenance::verify( $sample, isset( $assocArgs['repair'] ) );
		if ( $result['mismatches'] ) {
			WP_CLI::warning( sprintf( '%d of %d checked rows (pairs and access profiles) differ: %s', count( $result['mismatches'] ), $result['checked'], implode( ', ', $result['mismatches'] ) ) );
		} else {
			WP_CLI::success( sprintf( '%d rows (pairs and access profiles) checked, no differences.', $result['checked'] ) );
		}
	}

	/**
	 * Drops the tables. Map and items table compute live until the next rebuild.
	 *
	 * @subcommand drop
	 */
	public function drop( $args, $assocArgs ): void {
		Schema::uninstall();
		WP_CLI::success( 'Tables dropped. They are recreated on the next request, run "wp commonsbooking availability rebuild" to fill them.' );
	}
}
