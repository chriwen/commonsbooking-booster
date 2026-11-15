<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Commonsbooking
 */


$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

if ( ! function_exists( '_manually_load_plugin' ) ) {
	/**
	 * Manually load the plugin being tested.
	 */
	function _manually_load_plugin() {
		require dirname( __DIR__, 2 ) . '/commonsbooking.php';
	}
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Performance booster (timeframe index and precomputed availability) is switched on for the tests.
// Run the suite with CB_USE_BOOSTER=0 to test the plugin without it (no hooks, original queries).
tests_add_filter( 'commonsbooking_booster_enabled', getenv( 'CB_USE_BOOSTER' ) === '0' ? '__return_false' : '__return_true' );

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Start up the WP testing environment.
require_once "{$_tests_dir}/includes/bootstrap.php";

// Timeframe index: build it once (fresh test db), so repositories read from it like on a live site.
// Run the suite with CB_USE_INDEX=0 to test the legacy postmeta queries instead.
\CommonsBooking\Service\Index\Schema::install();
\CommonsBooking\Service\Index\Backfill::runAll();
if ( \CommonsBooking\Service\Booster\Booster::isEnabled() ) {
	\CommonsBooking\Service\Booster\Booster::saveState(
		[
			'active'         => true,
			'plugin_version' => \CommonsBooking\Service\Booster\Guard::getStoredPluginVersion(),
			'watermark'      => \CommonsBooking\Service\Booster\Guard::getMaxPostId(),
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
