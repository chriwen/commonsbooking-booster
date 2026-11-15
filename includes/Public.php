<?php

use CommonsBooking\Map\MapData;
use CommonsBooking\Migration\Migration;
use CommonsBooking\View\Booking;
use CommonsBooking\View\Calendar;
use CommonsBooking\View\View;


function commonsbooking_public() {

	wp_enqueue_style(
		'cb-styles-public',
		COMMONSBOOKING_PLUGIN_ASSETS_URL . 'public/css/public.css',
		array(),
		WP_DEBUG ? time() : COMMONSBOOKING_VERSION
	);

	$colorScheme_css = View::getColorCSS();
	if ( $colorScheme_css ) { // if custom color variables exist, import them after importing the rest of the CSS, overwriting existing defaults
		wp_add_inline_style( 'cb-styles-public', $colorScheme_css );
	}

	// Template specific styles
	$template            = wp_get_theme()->template;
	$customizedTemplates = [
		'graphene',
		'kasimir',
		'twentytwenty',
		'twentynineteen',
	];
	if ( in_array( $template, $customizedTemplates ) ) {
		wp_enqueue_style(
			'cb-styles-public-theme',
			COMMONSBOOKING_PLUGIN_ASSETS_URL . 'public/css/themes/' . $template . '.css',
			array(),
			WP_DEBUG ? time() : COMMONSBOOKING_VERSION
		);
	}

	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'jquery-ui-datepicker', '', array( 'jquery' ) );

	wp_enqueue_script(
		'cb-scripts-vendor',
		COMMONSBOOKING_PLUGIN_ASSETS_URL . 'global/js/vendor.js',
		array( 'jquery' ),
		'1.0.0'
	);

	wp_enqueue_style( 'cb-styles-select2' );
	wp_enqueue_script( 'cb-scripts-select2' );
	wp_enqueue_script( 'cb-scripts-moment' );

	// Litepicker
	wp_enqueue_script( 'cb-litepicker' ); // registered in Plugin::registerScriptsAndStyles

	/**
	 * Public scripts
	 */
	if ( WP_DEBUG ) {
		wp_enqueue_script(
			'cb-scripts-public',
			COMMONSBOOKING_PLUGIN_ASSETS_URL . 'public/js/public.js',
			array( 'jquery' ),
			(string) time(),
			true
		);
	} else {
		wp_enqueue_script(
			'cb-scripts-public',
			COMMONSBOOKING_PLUGIN_ASSETS_URL . 'public/js/public.min.js',
			array( 'jquery' ),
			COMMONSBOOKING_VERSION,
			true
		);
	}

	/**
	 * Booking forms: send only once. Checking and saving a booking can take a few seconds; a second click during
	 * that time would send the same booking again.
	 */
	wp_add_inline_script(
		'cb-scripts-public',
		sprintf(
			'(function(){var wait=%s;'
			. 'document.addEventListener("submit",function(e){var f=e.target;'
			. 'if(!f.matches||!f.matches("#booking-form,[id^=cb-booking-form-set-]"))return;'
			. 'var b=e.submitter;if(b&&b.name==="calendar-download")return;'
			. 'if(f.dataset.cbSent){e.preventDefault();return;}'
			. 'f.dataset.cbSent="1";f.setAttribute("aria-busy","true");f.classList.add("cb-form-sending");'
			. 'if(b&&b.tagName==="INPUT"){var h=document.createElement("input");h.type="hidden";h.className="cb-sent-copy";if(b.name){h.name=b.name;h.value=b.value;f.appendChild(h);}b.dataset.cbLabel=b.value;b.value=wait;}'
			. 'f.querySelectorAll("[type=submit]").forEach(function(x){if(x.name!=="calendar-download"){x.disabled=true;}});'
			. '});'
			. 'window.addEventListener("pageshow",function(e){if(!e.persisted)return;document.querySelectorAll("form[data-cb-sent]").forEach(function(f){delete f.dataset.cbSent;f.removeAttribute("aria-busy");f.classList.remove("cb-form-sending");f.querySelectorAll(".cb-sent-copy").forEach(function(h){h.remove();});f.querySelectorAll("[type=submit]").forEach(function(x){x.disabled=false;if(x.dataset.cbLabel){x.value=x.dataset.cbLabel;delete x.dataset.cbLabel;}});});});'
			. '})();',
			wp_json_encode( __( 'Please wait …', 'commonsbooking' ) )
		)
	);

	/**
	 * Ajax - calendar data
	 */
	wp_localize_script(
		'cb-scripts-public',
		'cb_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cb_calendar_data' ),
		)
	);

	/**
	 * Ajax - bookings
	 */
	wp_localize_script(
		'cb-scripts-public',
		'cb_ajax_bookings',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cb_bookings_data' ),
		)
	);

	/**
	 * Ajax - cache warmup
	 */
	wp_localize_script(
		'cb-scripts-public',
		'cb_ajax_cache_warmup',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cb_cache_warmup' ),
		)
	);
}

add_action( 'wp_enqueue_scripts', 'commonsbooking_public' );

// Calendar data ajax
add_action( 'wp_ajax_cb_calendar_data', array( Calendar::class, 'getCalendarData' ) );
add_action( 'wp_ajax_nopriv_cb_calendar_data', array( Calendar::class, 'getCalendarData' ) );

add_action( 'wp_ajax_cb_bookings_data', array( Booking::class, 'getTemplateData' ) );
add_action( 'wp_ajax_nopriv_cb_bookings_data', array( Booking::class, 'getTemplateData' ) );

add_action( 'wp_ajax_cb_cache_warmup', array( \CommonsBooking\Plugin::class, 'warmupCache' ) );
add_action( 'wp_ajax_nopriv_cb_cache_warmup', array( \CommonsBooking\Plugin::class, 'warmupCache' ) );

if ( is_admin() ) {
	// migration AJAX
	add_action( 'wp_ajax_cb_start_migration', array( Migration::class, 'migrateAll' ) );
	add_action( 'wp_ajax_cb_start_booking_migration', array( \CommonsBooking\Migration\Booking::class, 'ajaxMigrate' ) );
	add_action( 'wp_ajax_cb_run_upgrade', array( \CommonsBooking\Service\Upgrade::class, 'runAJAXUpgradeTasks' ) );
	add_action( 'wp_ajax_cb_export_timeframes', array( \CommonsBooking\Service\TimeframeExport::class, 'ajaxExportCsv' ) );

	// getting bookable Location for item AJAX
	add_action( 'wp_ajax_cb_get_bookable_location', array( \CommonsBooking\View\Booking::class, 'getLocationForItem_AJAX' ) );

	// getting booking code for new backend booking AJAX
	add_action( 'wp_ajax_cb_get_booking_code', array( \CommonsBooking\View\Booking::class, 'getBookingCode_AJAX' ) );
	add_action( 'wp_ajax_cb_orphaned_booking_migration', array( \CommonsBooking\Service\MassOperations::class, 'ajaxMigrateOrphaned' ) );
}

// Map ajax
add_action( 'wp_ajax_cb_map_locations', array( MapData::class, 'get_locations' ) );
add_action( 'wp_ajax_nopriv_cb_map_locations', array( MapData::class, 'get_locations' ) );
add_action( 'wp_ajax_cb_map_geo_search', array( MapData::class, 'geo_search' ) );
add_action( 'wp_ajax_nopriv_cb_map_geo_search', array( MapData::class, 'geo_search' ) );

// Query vars
function commonsbooking_query_vars( $qvars ) {
	$qvars[] = 'cb-location';
	$qvars[] = 'cb-item';
	$qvars[] = 'cb-type';

	return $qvars;
}

add_filter( 'query_vars', 'commonsbooking_query_vars' );
