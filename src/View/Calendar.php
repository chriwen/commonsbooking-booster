<?php


namespace CommonsBooking\View;

use CommonsBooking\Helper\Helper;
use CommonsBooking\Helper\Wordpress;
use CommonsBooking\Model\CustomPost;
use CommonsBooking\Model\Day;
use CommonsBooking\Model\Week;
use CommonsBooking\Plugin;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\CalendarWindow;
use CommonsBooking\Service\Availability\DayFacts;
use CommonsBooking\Service\Availability\DayStatus;
use CommonsBooking\Service\Availability\Reader;
use CommonsBooking\Settings\Settings;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use DateTime;
use Exception;
use WP_Post;

class Calendar {

	/**
	 * Default range until calendar end date.
	 */
	public const DEFAULT_RANGE = '+3 months';

	/**
	 * Default start of calendar data.
	 */
	public const DEFAULT_RANGE_START = 'first day of this month';

	/**
	 * Markup of a day in the items table per {@see DayStatus}.
	 */
	private const TABLE_STATUS_MARKUP = [
		DayStatus::NO_SLOTS               => "<span class='is-locked'></span>",
		DayStatus::HOLIDAY                => "<span class='is-holiday'></span>",
		DayStatus::BOOKED                 => "<span class='is-booked'></span>",
		DayStatus::PARTIALLY_BOOKED_START => "<span class='is-partially-booked-start'></span>",
		DayStatus::PARTIALLY_BOOKED_END   => "<span class='is-partially-booked-end'></span>",
		DayStatus::LOCKED                 => "<span class='is-locked'></span>",
		DayStatus::AVAILABLE              => '<span></span>',
	];

	/**
	 * Renders item table.
	 * Many thanks to fLotte Berlin!
	 * Forked from https://github.com/flotte-berlin/cb-shortcodes/blob/master/custom-shortcodes-cb-items.php
	 *
	 * @param $atts array Supports the following attributes:
	 *                    - locationcat: Filter by location category
	 *                    - itemcat: Filter by item category
	 *                    - days: Number of days to show in calendar table view
	 *                    - desc: Description text
	 *
	 * @return string
	 * @throws Exception
	 */
	public static function renderTable( $atts ): string {
		$locationCategory = false;
		if ( is_array( $atts ) && array_key_exists( 'locationcat', $atts ) ) {
			$locationCategory = $atts['locationcat'];
		}
		$itemCategory = false;
		if ( is_array( $atts ) && array_key_exists( 'itemcat', $atts ) ) {
			$itemCategory = $atts['itemcat'];
		}

		// defines the number of days shown in the calendar table view. If not set, default is 31 days
		// TODO: max days should be made configurable in options
		$days = is_array( $atts ) && array_key_exists( 'days', $atts ) ? $atts['days'] : 31;

		$desc  = $atts['desc'] ?? '';
		$date  = Wordpress::getUTCDateTimeByTimestamp( current_time( 'timestamp' ) );
		$today = $date->format( 'Y-m-d' );

		$days_display = array_fill( 0, $days, 'n' );
		$days_cols    = array_fill( 0, $days, '<col>' );
		$month        = date( 'Y-m' );
		$month_cols   = [ $month => 0 ];
		$days_dates   = [];

		for ( $i = 0; $i < $days; $i++ ) {
			++$month_cols[ $month ];
			$days_display[ $i ] = $date->format( 'd' );
			$days_dates[ $i ]   = $date->format( 'Y-m-d' );

			if ( $date->format( 'N' ) >= 7 ) {
				$days_cols[ $i ] = '<col class="bg_we">';
			}
			$date->modify( '+1 day' );
			if ( $date->format( 'Y-m' ) != $month ) {
				$month                = $date->format( 'Y-m' );
				$month_cols[ $month ] = 0;
			}
		}

		$last_day = $days_dates[ $days - 1 ];
		$colStr   = implode( ' ', $days_cols );

		$print  = '<div class="cb-table-scroll">';
		$print .= "<table class='cb-items-table tablesorter'><colgroup><col><col>" . $colStr . '</colgroup><thead>';
		// Use td-tag when no table header description is given, to match semantics of header cells
		$accessible_table_header_tag = empty( $desc ) ? 'td' : 'th';
		$print                      .= "<tr><$accessible_table_header_tag colspan='2' class='sortless'>" . $desc . "</$accessible_table_header_tag>";

		// Render months
		$print .= self::renderHeadlineMonths( $month_cols );
		$print .= '</tr>';

		// Render Headline Days
		$print .= '<tr>';
		$print .= self::renderHeadlineDays( $days_display );
		$print .= '</tr></thead><tbody>';

		$items = get_posts(
			array(
				'post_type'      => 'cb_item',
				'post_status'    => 'publish',
				'order'          => $atts['order'] ?? 'ASC',
				'orderby'        => $atts['orderby'] ?? 'post_title',
				'posts_per_page' => - 1,
			)
		);

		// filter out items not in our categories
		if ( $itemCategory ) {
			$items = array_filter( $items, fn( $item ) => has_term( $itemCategory, Item::getTaxonomyName(), $item->ID ) );
		}

		$itemIDs = array_map( fn( $item ) => $item->ID, $items );

		/* @var Timeframe[] $allTimeframes */
		$allTimeframes = \CommonsBooking\Repository\Timeframe::getInRangeForCurrentUser(
			strtotime( $today ),
			strtotime( $last_day ),
			[],
			$itemIDs,
			[ Timeframe::BOOKABLE_ID ],
			true
		);

		// group by item ID
		$timeframeByItemID = [];
		foreach ( $allTimeframes as $timeframe ) {
			foreach ( $timeframe->getItemIDs() as $itemID ) {
				$timeframeByItemID[ $itemID ][] = $timeframe;
			}
		}

		// precomputed availability: load the stored days and bookable timeframes of all rows at once
		$reader = null;
		if ( Availability::isEnabled() ) {
			$reader = new Reader();
			$pairs  = [];
			foreach ( $timeframeByItemID as $itemId => $itemTimeframes ) {
				foreach ( $itemTimeframes as $timeframe ) {
					foreach ( $timeframe->getLocationIDs() as $locationId ) {
						$pairs[] = [ (int) $itemId, (int) $locationId ];
					}
				}
			}
			$reader->preload( $pairs );
		}

		$itemRowsHTML = '';

		foreach ( $items as $item ) {
			$rowHtml = ' ';

			$timeframes = $timeframeByItemID[ $item->ID ] ?? [];

			if ( $timeframes ) {
				// Collect unique locations from timeframes
				$locations = [];
				foreach ( $timeframes as $timeframe ) {
					// TODO #507
					$location = $timeframe->getLocation();
					if ( ! $location ) {
						continue;
					}
					$locations[ $location->ID ] = $location->post_title;
				}

				// loop through location
				foreach ( $locations as $locationId => $locationName ) {
					// the number of days changes the row; the roles of the user are part of every cache key
					$customCacheKey = $item->ID . '_' . $locationId . '_' . $today . '_' . $days;
					$cacheItem      = Plugin::getCacheItem( $customCacheKey );
					if ( $cacheItem ) {
						$rowHtml .= $cacheItem;
					} else {
						// Check for category term
						if ( $locationCategory ) {
							if ( ! has_term( $locationCategory, Location::getTaxonomyName(), $locationId ) ) {
								continue;
							}
						}

						$locationHtml = self::renderItemLocationRow( $item, $locationId, $locationName, $today, $last_day, $days, $days_display, $reader );
						Plugin::setCacheItem( $locationHtml, [ strval( $item->ID ), strval( $locationId ) ], $customCacheKey );
						$rowHtml .= $locationHtml;
					}
				}
				$itemRowsHTML .= $rowHtml;
			}
		}

		if ( empty( $itemRowsHTML ) ) { // print message of unavailable items
			$print .= '<tr style="color: var(--commonsbooking-color-error);"><td colspan="2">' . __( 'No items found.', 'commonsbooking' ) . '</td></tr>';
		} else { // if there are item rows, append them to the table
			$print .= $itemRowsHTML;
		}

		$print .= '</tbody></table>';
		$print .= '</div>';

		return $print;
	}

	public static function shortcode( $atts ) {
		global $templateData;
		$templateData         = [];
		$templateData['data'] = self::renderTable( $atts );

		if ( ! empty( $templateData['data'] ) ) {
			ob_start();
			commonsbooking_get_template_part( 'shortcode', 'items_table', true, false, false );
			return ob_get_clean();
		}
	}

	/**
	 * Renders months in headline.
	 *
	 * @param $month_cols
	 *
	 * @return string
	 */
	protected static function renderHeadlineMonths( $month_cols ): string {
		$print = '';
		foreach ( $month_cols as $month => $colspan ) {
			$print .= "<th class='sortless' colspan='" . $colspan . "'>";

			if ( $colspan > 3 ) {
				$print .= date_i18n( 'F', strtotime( get_date_from_gmt( $month ) ) ) . '</th>';
			} else {
				$print .= date_i18n( 'M', strtotime( get_date_from_gmt( $month ) ) ) . '</th>';
			}
		}

		return $print;
	}

	/**
	 * Renders days in headline.
	 *
	 * @param $days_display
	 *
	 * @return string
	 */
	protected static function renderHeadlineDays( $days_display ): string {
		$divider = "</th><th class='cal sortless'>";
		$dayStr  = implode( $divider, $days_display );

		return '<th>' . __( 'Item', 'commonsbooking' ) . '</th>' .
				'<th>' . __( 'Location', 'commonsbooking' ) . "<th class='cal sortless'>" . $dayStr . '</th>';
	}

	/**
	 * Renders item/location row.
	 *
	 * @param $item
	 * @param $locationId
	 * @param $locationName
	 * @param $today
	 * @param $last_day
	 * @param $days
	 * @param $days_display
	 * @param Reader|null $reader precomputed availability, null to evaluate the calendar data
	 *
	 * @return string
	 * @throws Exception
	 */
	protected static function renderItemLocationRow( $item, $locationId, $locationName, $today, $last_day, $days, $days_display, ?Reader $reader = null ): string {
		// Cached by the caller (renderTable). An own cache item would be keyed by all arguments including the reader
		// and never be found again.
		$divider  = '</td><td>';
		$itemName = $item->post_title;

		// Get the day status for current item/location combination, same days as in the calendar data
		$endDate  = date( 'Y-m-d', strtotime( '+' . $days . ' days', time() ) );
		$statuses = $reader
			? $reader->getDayStatuses( (int) $item->ID, (int) $locationId, $today, $endDate, true, $today, $last_day )
			: Reader::evaluateLive( (int) $item->ID, (int) $locationId, $today, $endDate, true, $today, $last_day );

		$dayIterator = 0;
		foreach ( $statuses['days'] as $day => $status ) {
			$days_display[ $dayIterator++ ] = self::TABLE_STATUS_MARKUP[ $status ];

			// Stop when enddate (advanced booking days limit) is reached
			if ( $day == $statuses['endDate'] ) {
				break;
			}
		}

		// Show item as not available outside of timeframe timerange.
		for ( $dayIterator; $dayIterator < count( $days_display ); $dayIterator++ ) {
			$days_display[ $dayIterator ] = self::TABLE_STATUS_MARKUP[ DayStatus::LOCKED ];
		}

		$dayStr         = implode( $divider, $days_display );
		$itemLink       = add_query_arg( 'cb-location', $locationId, get_permalink( $item->ID ) );
		$locationString = '<div data-title="' . $locationName . '">' . $locationName . '</div>';
		$locationLink   = get_permalink( $locationId );

		$rowHtml = "<tr><td><b><a href='" . $itemLink . "'>" . $itemName . '</a></b>' . $divider . $locationString . $divider . $dayStr . '</td></tr>';

		return $rowHtml;
	}

	/**
	 * Returns calendar data
	 *
	 * @param WP_Post|int|string|CustomPost $item
	 * @param WP_Post|int|string|CustomPost $location
	 * @param string                        $startDateString YYYY-MM-DD Format
	 * @param string                        $endDateString YYYY-MM-DD Format
	 * @param bool                          $keepDaterange
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function getCalendarDataArray( $item, $location, string $startDateString, string $endDateString, bool $keepDaterange = false ): array {
		if ( $item instanceof WP_Post || $item instanceof CustomPost ) {
			$item = $item->ID;
		}

		if ( $location instanceof WP_Post || $location instanceof CustomPost ) {
			$location = $location->ID;
		}

		if ( ! Wordpress::isValidDateString( $startDateString ) || ! Wordpress::isValidDateString( $endDateString ) ) {
			throw new \Exception( 'invalid date format' );
		}

		if ( ! $item || ! $location ) {
			return [];
		}

		$bookableTimeframes = \CommonsBooking\Repository\Timeframe::getBookableForCurrentUser(
			[ $location ],
			[ $item ],
			null,
			true,
			Helper::getLastFullHourTimestamp()
		);
		$window             = CalendarWindow::forTimeframes( $bookableTimeframes, new Day( $startDateString ), new Day( $endDateString ), $keepDaterange );

		return self::renderJsonResponse( $window, [ $location ], [ $item ] );
	}

	/**
	 * Returns closest timeframe from date/time perspective.
	 *
	 * @param $bookableTimeframes
	 *
	 * @return \CommonsBooking\Model\Timeframe|null
	 */
	public static function getClosestBookableTimeFrameForToday( $bookableTimeframes ): ?\CommonsBooking\Model\Timeframe {
		$today           = new Day( date( 'Y-m-d' ) );
		$todayTimeframes = \CommonsBooking\Repository\Timeframe::filterTimeframesForTimerange( $bookableTimeframes, $today->getStartTimestamp(), $today->getEndTimestamp() );
		$todayTimeframes = array_filter(
			$todayTimeframes,
			function ( $timeframe ) use ( $today ) {
				// also consider repetition
				return $today->isInTimeframe( $timeframe );
			}
		);
		switch ( count( $todayTimeframes ) ) {
			case 1:
				$bookableTimeframes = $todayTimeframes;
				break;
			case 0:
				usort(
					$bookableTimeframes,
					function ( $a, $b ) {
						$aStartDate = $a->getStartDate();
						$bStartDate = $b->getStartDate();

						if ( $aStartDate == $bStartDate ) {
							$aStartTimeDT = $a->getStartTimeDateTime();
							$bStartTimeDT = $b->getStartTimeDateTime();

							return $bStartTimeDT <=> $aStartTimeDT;
						}

						return $bStartDate <=> $aStartDate;
					}
				);
				break;
			default: // More than one timeframe for current day
				// consider starttime and endtime
				$now                = new DateTime();
				$bookableTimeframes = array_filter(
					$todayTimeframes,
					function ( $timeframe ) use ( $now ) {
						$startTime   = $timeframe->getStartTime();
						$startTimeDT = new DateTime( $startTime );
						$endTime     = $timeframe->getEndTime();
						$endTimeDT   = new DateTime( $endTime );

						return $startTimeDT <= $now && $now <= $endTimeDT;
					}
				);

				// condition, that we are not currently in a timeframe
				// for example, we have two timeframes, one from 02:00pm to 04:00pm and one from 04:00pm to 06:00pm.
				// it is currently 11:00am, so we should take the first timeframe
				if ( empty( $bookableTimeframes ) ) {
					usort(
						$todayTimeframes,
						function ( $a, $b ) {
							$aStartTimeDT = $a->getStartTimeDateTime();
							$bStartTimeDT = $b->getStartTimeDateTime();

							return $bStartTimeDT <=> $aStartTimeDT;
						}
					);
					$bookableTimeframes = $todayTimeframes;
				}
				break;
		}

		return array_pop( $bookableTimeframes );
	}

	/**
	 * The value for the amount of months shown in the Litepicker mobile view portrait mode.
	 * Fixes #1103, an issue where one instance has issues with switching the months on mobile.
	 * This value is configurable through a filter hook only.
	 *
	 * Default value is 1.
	 *
	 * @return int
	 */
	private static function getMobileCalendarMonthCount(): int {
		$month = 1;
		/**
		 * Default amount of months shown in the Litepicker mobile view portrait mode.
		 *
		 * @since 2.10.5
		 *
		 * @param int $month defaults is 1
		 */
		return apply_filters( 'commonsbooking_mobile_calendar_month_count', $month );
	}

	/**
	 * Returns JSON-Data for Litepicker calendar.
	 *
	 * Uses cache which expires at midnight on a daily basis.
	 *
	 * @param Day   $startDate
	 * @param Day   $endDate
	 * @param array $locations []
	 * @param array $items <int|string>
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function prepareJsonResponse(
		Day $startDate,
		Day $endDate,
		array $locations,
		array $items,
		$advanceBookingDays = null,
		$lastBookableDate = null,
		$firstBookableDay = null
	): array {

		return self::renderJsonResponse(
			new CalendarWindow( $startDate, $endDate, $advanceBookingDays, $lastBookableDate, $firstBookableDay ),
			$locations,
			$items
		);
	}

	/**
	 * Builds the calendar json for a window.
	 *
	 * @param CalendarWindow $window
	 * @param array          $locations
	 * @param array          $items
	 *
	 * @return array
	 * @throws Exception
	 */
	private static function renderJsonResponse( CalendarWindow $window, array $locations, array $items ): array {
		$startDate          = $window->getStartDate();
		$endDate            = $window->getEndDate();
		$advanceBookingDays = $window->getAdvanceBookingDays();
		$current_user       = wp_get_current_user();
		$customCacheKey     = serialize( $current_user->roles );

		if ( ! ( $jsonResponse = Plugin::getCacheItem( $customCacheKey ) ) ) {
			$calendar = new \CommonsBooking\Model\Calendar(
				$startDate,
				$endDate,
				$locations,
				$items
			);

			$jsonResponse = [
				'minDate'                 => $startDate->getFormattedDate( 'Y-m-d' ),
				'startDate'               => $startDate->getFormattedDate( 'Y-m-d' ),
				'endDate'                 => $endDate->getFormattedDate( 'Y-m-d' ),
				'lang'                    => str_replace( '_', '-', get_locale() ),
				'days'                    => [],
				'bookedDays'              => [],
				'partiallyBookedDays'     => [],
				'lockDays'                => [],
				'holidays'                => [],
				'highlightedDays'         => [],
				'maxDays'                 => null,
				'startDayOffset'                => null,
				'disallowLockDaysInRange' => true,
				'countLockDaysInRange' => true,
				'advanceBookingDays'      => $advanceBookingDays,
				'mobileCalendarMonthCount' => self::getMobileCalendarMonthCount(),
			];

			if ( count( $locations ) === 1 ) {
				// are overbooking allowed in location options?
				$useGlobalSettings = get_post_meta( $locations[0], COMMONSBOOKING_METABOX_PREFIX . 'use_global_settings', true ) === 'on';
				if ( $useGlobalSettings ) {
					$allowLockedDaysInRange = Settings::getOption( 'commonsbooking_options_general', COMMONSBOOKING_METABOX_PREFIX . 'allow_lockdays_in_range' );
				} else {
					$allowLockedDaysInRange = get_post_meta(
						$locations[0],
						COMMONSBOOKING_METABOX_PREFIX . 'allow_lockdays_in_range',
						true
					);
				}
				$jsonResponse['disallowLockDaysInRange'] = ! ( $allowLockedDaysInRange === 'on' );

				// should overbooked non bookable days be counted into maxdays selection?
				if ( $useGlobalSettings ) {
					$countLockedDaysInRange = Settings::getOption( 'commonsbooking_options_general', COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_in_range' );
				} else {
					$countLockedDaysInRange = get_post_meta(
						$locations[0],
						COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_in_range',
						true
					);
				}
				$jsonResponse['countLockDaysInRange'] = $countLockedDaysInRange === 'on';

				// if yes, what is the maximum amount of days they should count?
				if ( $useGlobalSettings ) {
					$countLockdaysMaximum = Settings::getOption( 'commonsbooking_options_general', COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_maximum' );
				} else {
					$countLockdaysMaximum = get_post_meta( $locations[0], COMMONSBOOKING_METABOX_PREFIX . 'count_lockdays_maximum', true );
				}
				$jsonResponse['countLockDaysMaxDays'] = (int) $countLockdaysMaximum;
			}

			/** @var Week $week */
			foreach ( $calendar->getWeeks() as $week ) {
				/** @var Day $day */
				foreach ( $week->getDays() as $day ) {
					self::mapDay( $day, $window, $jsonResponse );
				}
			}

			// set transient expiration time to midnight to force cache refresh by daily basis to allow dynamic advanced booking day feature
			Plugin::setCacheItem( $jsonResponse, [ 'misc' ], $customCacheKey, 'midnight' );
		}

		return $jsonResponse;
	}

	/**
	 * Processes day for calendar view of json.
	 *
	 * @param Day            $day
	 * @param CalendarWindow $window
	 * @param array          $jsonResponse
	 *
	 * @return void
	 */
	protected static function mapDay( Day $day, CalendarWindow $window, array &$jsonResponse ) {
		$facts = null;
		// Only process slots if the day is in bookingdays in advance range
		if ( $window->isInBookingWindow( $day ) ) {
			$facts = DayFacts::fromGrid( $day->getGrid() );

			// Set max-days and start day offset based on the first found bookable timeframe
			// TODO: This behaviour is already better defined in the timeframe model method getMaxDays which should be used here.
			$firstBookableTimeframe = $facts->getFirstBookableTimeframe();
			if ( $firstBookableTimeframe ) {
				if ( $jsonResponse['maxDays'] == null ) {
					$timeframeMaxDays        = get_post_meta( $firstBookableTimeframe->ID, \CommonsBooking\Model\Timeframe::META_MAX_DAYS, true );
					$jsonResponse['maxDays'] = intval( $timeframeMaxDays ?: 3 );
				}

				if ( $jsonResponse['startDayOffset'] == null ) {
					$timeframeBookingStartDayOffset = get_post_meta( $firstBookableTimeframe->ID, \CommonsBooking\Model\Timeframe::META_BOOKING_START_DAY_OFFSET, true );
					$jsonResponse['startDayOffset'] = $timeframeBookingStartDayOffset ? intval( $timeframeBookingStartDayOffset ) : null;
				}
			}
		}

		$mapped = $window->mapDay( $day, $facts );
		$date   = $day->getFormattedDate( 'Y-m-d' );

		$jsonResponse['days'][ $date ] = $mapped['day'];
		foreach ( $mapped['types'] as $type ) {
			$jsonResponse[ $type ][] = $date;
		}
	}

	/**
	 * Ajax request - Returns json-formatted calendardata.
	 *
	 * @throws Exception
	 */
	public static function getCalendarData() {
		// item by post-param
		$item = isset( $_POST['item'] ) && $_POST['item'] != '' ? intval( $_POST['item'] ) : false;
		if ( $item === false || $item == 0 ) { // 0 = failed intval check
			throw new Exception( 'missing item id.' );
		}

		// location by post-param
		$location = isset( $_POST['location'] ) && $_POST['location'] != '' ? intval( $_POST['location'] ) : false;
		if ( $location === false || $location == 0 ) { // 0 = failed intval check
			throw new Exception( 'missing location id.' );
		}

		// Ajax-Request param check
		if ( array_key_exists( 'sd', $_POST ) && Wordpress::isValidDateString( $_POST['sd'] ) ) {
			$startDateString = sanitize_text_field( $_POST['sd'] );
		} else {
			throw new Exception( 'wrong or missing start date.' );
		}

		if ( array_key_exists( 'ed', $_POST ) && Wordpress::isValidDateString( $_POST['ed'] ) ) {
			$endDateString = sanitize_text_field( $_POST['ed'] );
		} else {
			throw new Exception( 'wrong or missing end date.' );
		}

		$jsonResponse = self::getCalendarDataArray( $item, $location, $startDateString, $endDateString );

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $jsonResponse );
		wp_die(); // All ajax handlers die when finished
	}
}
