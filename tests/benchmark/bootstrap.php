<?php
/**
 * PHPBench bootstrap file.
 *
 * @package Commonsbooking
 */

// Reflection subprocesses only need the autoloader. WordPress is already
// running in the local executor process started by phpbench.php.
if ( getenv( 'COMMONSBOOKING_BENCHMARK_BOOTSTRAPPED' ) === '1' ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	return;
}

// Silence all output during reflection
ob_start();

// Suppress deprecations for PHPBench discovery
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once "{$_tests_dir}/includes/functions.php";

function _manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/commonsbooking.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
tests_add_filter( 'commonsbooking_booster_enabled', getenv( 'CB_USE_BOOSTER' ) === '0' ? '__return_false' : '__return_true' );

// Composer autoload
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Bootstrap WordPress
require_once "{$_tests_dir}/includes/bootstrap.php";

// Timeframe index: build it, so the benchmarks measure what a live site uses.
// Run with CB_USE_INDEX=0 to benchmark the legacy postmeta queries.
\CommonsBooking\Service\Index\Schema::install();
\CommonsBooking\Service\Index\Backfill::runAll();
if ( \CommonsBooking\Service\Booster\Booster::isEnabled() ) {
	\CommonsBooking\Service\Booster\Booster::saveState(
		[
			'active' => true,
			'plugin_version' => \CommonsBooking\Service\Booster\Guard::getStoredPluginVersion(),
			'watermark' => \CommonsBooking\Service\Booster\Guard::getMaxPostId(),
		]
	);
}
if ( getenv( 'CB_USE_INDEX' ) === '0' ) {
	add_filter( 'commonsbooking_use_index', '__return_false' );
}
// Precomputed availability (stage 3). Run with CB_USE_AVAILABILITY=0 to compute every day live.
\CommonsBooking\Service\Availability\Schema::install();
if ( getenv( 'CB_USE_AVAILABILITY' ) === '0' ) {
	add_filter( 'commonsbooking_use_availability_cache', '__return_false' );
}

// Discard all output
ob_end_clean();
