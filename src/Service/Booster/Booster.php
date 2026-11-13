<?php

namespace CommonsBooking\Service\Booster;

use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Service\Index\Verifier;
use CommonsBooking\Service\Availability\Schema as AvailabilitySchema;
use CommonsBooking\Settings\Settings;

/**
 * Performance booster: the timeframe index (stage 2) and the precomputed availability (stage 3) as one switch.
 *
 * Off (default): no hooks, no cron jobs, no reads from the additional tables. The plugin behaves exactly like the
 * version without the booster, which makes the update downgradable.
 * On: the index is (re)built in the background. Until it is complete, everything keeps using the original queries.
 *
 * Every switch-on rebuilds the index, because nobody maintained it while the booster was off. The {@see Guard}
 * detects the same situation after a downgrade and a later upgrade (the booster code did not run in between).
 *
 * Booking validation never reads from the booster tables, see {@see self::withoutBooster()}.
 */
class Booster {

	/**
	 * CMB2 options key and field of the setting (CommonsBooking → Settings → Advanced Options).
	 */
	public const SETTINGS_KEY   = COMMONSBOOKING_PLUGIN_SLUG . '_options_advanced-options';
	public const SETTINGS_FIELD = 'booster_enabled';

	/**
	 * Internal state: whether the booster code was active, what it saw last (see {@see Guard}).
	 */
	public const STATE_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_booster_state';

	/**
	 * Options of the booster, removed by {@see self::purge()}.
	 */
	private const OPTIONS = [
		self::STATE_OPTION,
		Backfill::READY_OPTION,
		Backfill::CURSOR_OPTION,
		Backfill::LOCK_OPTION,
		IndexSchema::SCHEMA_VERSION_OPTION,
		AvailabilitySchema::SCHEMA_VERSION_OPTION,
		Maintenance::SYNC_OPTION,
		Diagnostics::ACTIVITY_OPTION,
		Diagnostics::CHECKS_OPTION,
	];

	/**
	 * Seconds of index build in the request that switched the booster on or started a rebuild from the settings page.
	 * Small sites are done at once, larger ones continue on the settings page or via WP-Cron.
	 */
	public const REQUEST_BUDGET = 5;

	private static bool $initialized = false;

	/**
	 * True if this request switched the booster on (the settings page then continues the setup in the browser).
	 */
	private static bool $activatedInRequest = false;

	/**
	 * > 0 while {@see self::withoutBooster()} runs.
	 */
	private static int $bypassDepth = 0;

	/**
	 * Registers the hooks. Called on every request (plugin init).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// transitions (switched on / off) and the guard run before the index and availability hooks (init priority 1, 2)
		add_action( 'init', array( self::class, 'onInit' ), 0 );

		if ( self::isEnabled() ) {
			Indexer::init();
			Availability::init();
			Guard::init();
			Diagnostics::init();
		}
		if ( is_admin() ) {
			SettingsView::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'commonsbooking booster', Cli::class );
		}
	}

	/**
	 * Returns true if the booster is switched on (setting, can be overridden with the filter `commonsbooking_booster_enabled`).
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		$enabled = Settings::getOption( self::SETTINGS_KEY, self::SETTINGS_FIELD ) === 'on';

		/**
		 * Switches the performance booster (timeframe index and precomputed availability) on or off.
		 *
		 * @param bool $enabled value of the setting
		 */
		return (bool) apply_filters( 'commonsbooking_booster_enabled', $enabled );
	}

	/**
	 * Returns true if the booster tables may be read in this request:
	 * switched on, not bypassed and the index is complete.
	 *
	 * @return bool
	 */
	public static function canRead(): bool {
		return self::$bypassDepth === 0 && self::isEnabled() && self::getState()['active'];
	}

	/**
	 * Runs a callback with the original queries only, e.g. the validation of bookings and timeframes.
	 * A stale index can then never allow a double booking. Writes to the index continue.
	 *
	 * The plugin cache is bypassed as well: it may contain results that were computed from the index.
	 *
	 * @param callable $callback
	 *
	 * @return mixed return value of the callback
	 */
	public static function withoutBooster( callable $callback ) {
		++self::$bypassDepth;
		try {
			return \CommonsBooking\Plugin::withoutCache( $callback );
		} finally {
			--self::$bypassDepth;
		}
	}

	/**
	 * @return bool true while {@see self::withoutBooster()} runs
	 */
	public static function isBypassed(): bool {
		return self::$bypassDepth > 0;
	}

	/**
	 * Applies switching on / off and runs the guard.
	 *
	 * @return void
	 */
	public static function onInit(): void {
		$state = self::getState();

		if ( ! self::isEnabled() ) {
			if ( $state['active'] ) {
				self::deactivate();
			}

			return;
		}

		if ( ! $state['active'] ) {
			self::activate();
			self::$activatedInRequest = true;
			// after init: the index and availability hooks (e.g. after a complete rebuild) are registered then
			add_action( 'admin_init', array( self::class, 'workInRequest' ) );

			return;
		}

		Guard::check();
	}

	/**
	 * Switches the booster code on: installs the tables and rebuilds the index from scratch.
	 * Reading starts as soon as the rebuild is complete.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// the options may say "installed" although the tables are missing, e.g. after a database import without them
		if ( ! IndexSchema::isInstalled() || ! IndexSchema::tablesExist() ) {
			IndexSchema::install();
		}
		if ( ! AvailabilitySchema::isInstalled() || ! AvailabilitySchema::tablesExist() ) {
			AvailabilitySchema::install();
		}

		// the index may contain data from an earlier activation that nobody maintained since
		Backfill::start();

		self::saveState(
			[
				'active'         => true,
				'activated_at'   => time(),
				'plugin_version' => Guard::getStoredPluginVersion(),
				'watermark'      => Guard::getMaxPostId(),
			]
		);
	}

	/**
	 * Works on the index build for {@see self::REQUEST_BUDGET} seconds, if this is a request of an administrator in
	 * the backend (not in the frontend, WP-Cron, AJAX or WP-CLI). Makes the setup independent of WP-Cron for small
	 * sites.
	 *
	 * @return void
	 */
	public static function workInRequest(): void {
		$background = wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( $background || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Backfill::runFor( self::REQUEST_BUDGET );
	}

	/**
	 * Returns true if this request has used more than 60 % of the PHP memory limit. Work in slices (index build,
	 * calendars) stops then and continues in the next request. Needed with plugins that keep every query of the
	 * request in memory, e.g. Query Monitor for administrators.
	 *
	 * @return bool
	 */
	public static function isMemoryLow(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage() > 0.6 * $limit;
	}

	/**
	 * @return bool true if this request switched the booster on
	 */
	public static function wasActivatedInRequest(): bool {
		return self::$activatedInRequest;
	}

	/**
	 * Switches the booster code off: stops the cron jobs and marks the index as outdated.
	 * The tables stay (use {@see self::purge()} to remove them).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Backfill::reset();
		wp_clear_scheduled_hook( Verifier::CRON_HOOK );
		Maintenance::unschedule();
		Guard::unschedule();

		self::saveState( [ 'active' => false ] );
	}

	/**
	 * Plugin activation: the plugin was inactive before, the booster tables may be outdated.
	 *
	 * @return void
	 */
	public static function onPluginActivation(): void {
		self::saveState( [ 'active' => false ] );
		if ( self::isEnabled() ) {
			self::activate();
		}
	}

	/**
	 * Plugin deactivation: stops the cron jobs. The next activation rebuilds the index.
	 *
	 * @return void
	 */
	public static function onPluginDeactivation(): void {
		self::deactivate();
	}

	/**
	 * Removes all tables, options and cron jobs of the booster and switches it off.
	 *
	 * @return void
	 */
	public static function purge(): void {
		self::deactivate();
		wp_clear_scheduled_hook( Backfill::CRON_HOOK );
		IndexSchema::uninstall();
		AvailabilitySchema::uninstall();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		$settings = get_option( self::SETTINGS_KEY );
		if ( is_array( $settings ) && array_key_exists( self::SETTINGS_FIELD, $settings ) ) {
			unset( $settings[ self::SETTINGS_FIELD ] );
			update_option( self::SETTINGS_KEY, $settings );
		}
	}

	/**
	 * Stores the setting (used by WP-CLI and tests; the admin form saves it via CMB2).
	 *
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public static function setEnabled( bool $enabled ): void {
		$settings = get_option( self::SETTINGS_KEY );
		$settings = is_array( $settings ) ? $settings : [];
		if ( $enabled ) {
			$settings[ self::SETTINGS_FIELD ] = 'on';
		} else {
			unset( $settings[ self::SETTINGS_FIELD ] );
		}
		update_option( self::SETTINGS_KEY, $settings );
	}

	/**
	 * @return array{active: bool, activated_at: int, plugin_version: string, watermark: int, last_rebuild_reason: string, last_rebuild: array{time: int, code: string, args: array}|null}
	 */
	public static function getState(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return array_merge(
			[
				'active'              => false,
				'activated_at'        => 0,
				'plugin_version'      => '',
				'watermark'           => 0,
				'last_rebuild_reason' => '',
				'last_rebuild'        => null,
			],
			is_array( $state ) ? $state : []
		);
	}

	/**
	 * Merges values into the stored state.
	 *
	 * @param array $values
	 *
	 * @return void
	 */
	public static function saveState( array $values ): void {
		update_option( self::STATE_OPTION, array_merge( self::getState(), $values ), true );
	}

	/**
	 * Human readable status for the settings page (translated) and WP-CLI (English).
	 *
	 * @param bool $translate
	 *
	 * @return array<string, string>
	 */
	public static function getStatus( bool $translate = false ): array {
		$state   = self::getState();
		$enabled = self::isEnabled();
		$status  = [];

		if ( $enabled ) {
			$status['enabled'] = $translate ? __( 'yes', 'commonsbooking' ) : 'yes';
		} else {
			$status['enabled'] = $translate ? __( 'no', 'commonsbooking' ) : 'no';
			$status['mode']    = $translate ? __( 'original queries', 'commonsbooking' ) : 'original queries';

			return $status;
		}

		if ( Backfill::isReady() ) {
			$status['mode'] = $translate ? __( 'booster active', 'commonsbooking' ) : 'booster active';
		} elseif ( Backfill::isRunning() ) {
			$status['mode'] = sprintf(
				$translate
					/* translators: %d: progress of the index build in percent */
					? __( 'building index (%d %%), original queries until finished', 'commonsbooking' )
					: 'building index (%d %%), original queries until finished',
				self::getBackfillProgress()
			);
		} else {
			$status['mode'] = $translate ? __( 'waiting for index build, original queries until finished', 'commonsbooking' ) : 'waiting for index build, original queries until finished';
		}

		if ( Backfill::isDone() ) {
			$status['index'] = $translate ? __( 'complete', 'commonsbooking' ) : 'complete';
		} else {
			$status['index'] = $translate ? __( 'incomplete', 'commonsbooking' ) : 'incomplete';
		}
		if ( Availability::isEnabled() ) {
			$status['availability'] = $translate ? __( 'precomputed', 'commonsbooking' ) : 'precomputed';
		} else {
			$status['availability'] = $translate ? __( 'computed live', 'commonsbooking' ) : 'computed live';
		}

		if ( $translate && $state['last_rebuild'] ) {
			$status['last rebuild'] = sprintf(
				'%s: %s',
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_rebuild']['time'] ),
				Guard::describeRebuild( $state['last_rebuild']['code'], $state['last_rebuild']['args'] )
			);
		} elseif ( $state['last_rebuild_reason'] ) {
			$status['last rebuild'] = $state['last_rebuild_reason'];
		}

		return $status;
	}

	/**
	 * @return int percent of the indexable posts processed by the running backfill
	 */
	public static function getBackfillProgress(): int {
		$max = Guard::getMaxPostId();
		if ( $max <= 0 ) {
			return 0;
		}

		return (int) min( 99, floor( 100 * (int) get_option( Backfill::CURSOR_OPTION, 0 ) / $max ) );
	}
}
