<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Service\Index\Backfill;

/**
 * Entry point of the precomputed availability (stage 3).
 *
 * - {@see Reader}: map and items table read the day status from here.
 * - {@see Invalidator}: every change the index sees marks the affected pairs, synchronously in the same request.
 * - {@see Calculator}: (re)computes pairs after the response, in cron runs or when a reader finds no usable row.
 * - {@see Maintenance}: cron jobs, verification and WP-CLI.
 *
 * @see design/availability-precompute.md
 */
class Availability {

	/**
	 * Number of days after today that are precomputed.
	 */
	public const HORIZON_DAYS = 92;

	/**
	 * Rows older than this (seconds) are not used, to limit the effect of writes that bypass the hooks.
	 */
	public const MAX_AGE = DAY_IN_SECONDS;

	private static bool $initialized = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( Schema::class, 'maybeInstall' ), 2 );
		Invalidator::init();
		Maintenance::init();
	}

	/**
	 * Returns true if map and items table use the precomputed availability.
	 * Requires a complete timeframe index. Can be switched off with the filter `commonsbooking_use_availability_cache`.
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		/**
		 * Allows to disable the precomputed availability (map and items table compute every day live then).
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'commonsbooking_use_availability_cache', true ) ) {
			return false;
		}

		return Schema::isInstalled() && Backfill::isReady();
	}

	/**
	 * First and last precomputed day.
	 *
	 * @return array{0: string, 1: string} Y-m-d
	 */
	public static function getHorizon(): array {
		/**
		 * Number of days after today that are precomputed. Larger values cost more time per recomputation.
		 *
		 * @param int $days
		 */
		$days  = max( 1, (int) apply_filters( 'commonsbooking_availability_horizon_days', self::HORIZON_DAYS ) );
		$today = date( 'Y-m-d' );

		return [
			date( 'Y-m-d', strtotime( $today . ' -1 day' ) ),
			date( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) ),
		];
	}

	/**
	 * @return int seconds
	 */
	public static function getMaxAge(): int {
		/**
		 * Rows computed longer ago are not used.
		 *
		 * @param int $seconds
		 */
		return (int) apply_filters( 'commonsbooking_availability_max_age', self::MAX_AGE );
	}
}
