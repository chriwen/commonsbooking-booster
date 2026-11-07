<?php

namespace CommonsBooking\Service\Booster;

use CommonsBooking\Service\Availability\AccessProfile;
use CommonsBooking\Service\Availability\AccessStore;
use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Verifier;

/**
 * Status and diagnostics of the booster on the settings page (Advanced Options), plus the actions
 * "run checks now" and "rebuild index".
 */
class SettingsView {

	public const ACTION_CHECK   = 'commonsbooking_booster_check';
	public const ACTION_REBUILD = 'commonsbooking_booster_rebuild';
	private const NOTICE_ARG    = 'cb-booster';

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_CHECK, array( self::class, 'handleCheck' ) );
		add_action( 'admin_post_' . self::ACTION_REBUILD, array( self::class, 'handleRebuild' ) );
		add_action( 'admin_notices', array( self::class, 'renderNotice' ) );
	}

	/**
	 * Button "run checks now".
	 *
	 * @return void
	 */
	public static function handleCheck(): void {
		self::authorize( self::ACTION_CHECK );
		$result = Booster::isEnabled() && Booster::getState()['active'] ? Diagnostics::runAllChecks() : null;
		if ( $result === null ) {
			$notice = 'inactive';
		} elseif ( $result['rebuild'] !== null ) {
			$notice = 'rebuild';
		} else {
			$repaired = count( $result['deep']['changed'] ?? [] ) + count( $result['deep']['orphans'] ?? [] )
				+ count( $result['index']['mismatches'] ?? [] ) + (int) ( $result['index']['orphans'] ?? 0 )
				+ count( $result['availability']['mismatches'] ?? [] );
			$notice   = $repaired ? 'repaired-' . $repaired : 'ok';
		}
		self::redirect( $notice );
	}

	/**
	 * Button "rebuild index".
	 *
	 * @return void
	 */
	public static function handleRebuild(): void {
		self::authorize( self::ACTION_REBUILD );
		if ( Booster::isEnabled() ) {
			Booster::activate();
			Booster::saveState(
				[
					'last_rebuild_reason' => gmdate( 'Y-m-d H:i' ) . ' UTC: started manually',
					'last_rebuild'        => [
						'time' => time(),
						'code' => 'manual',
						'args' => [],
					],
				]
			);
			self::redirect( 'rebuild' );
		}
		self::redirect( 'inactive' );
	}

	/**
	 * Result of an action, shown above the settings.
	 *
	 * @return void
	 */
	public static function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a message
		$notice = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		if ( ! $notice ) {
			return;
		}
		$class = 'notice-success';
		if ( $notice === 'ok' ) {
			$text = __( 'Performance booster: all checks passed, the index matches WordPress.', 'commonsbooking' );
		} elseif ( str_starts_with( $notice, 'repaired-' ) ) {
			/* translators: %d: number of repaired entries */
			$text  = sprintf( __( 'Performance booster: %d differences were found and repaired.', 'commonsbooking' ), (int) substr( $notice, 9 ) );
			$class = 'notice-warning';
		} elseif ( $notice === 'rebuild' ) {
			$text = __( 'Performance booster: the index is being rebuilt in the background. Until then, the original queries are used.', 'commonsbooking' );
		} else {
			$text  = __( 'Performance booster: the booster is switched off.', 'commonsbooking' );
			$class = 'notice-info';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Renders the status row (CMB2 render_row_cb).
	 *
	 * @param array $field_args unused
	 * @param mixed $field unused
	 *
	 * @return void
	 */
	public static function render( $field_args, $field ): void {
		echo '<div class="cmb-row cb-booster-status">';
		self::renderStyles();
		echo '<div class="cmb-th">' . esc_html__( 'Status', 'commonsbooking' ) . '</div><div class="cmb-td">';

		if ( ! Booster::isEnabled() ) {
			self::renderBadge( 'off', __( 'Switched off: CommonsBooking uses the original queries.', 'commonsbooking' ) );
			echo '<p class="cmb2-metabox-description">' . esc_html__( 'Changes take effect with the next page load.', 'commonsbooking' ) . '</p></div></div>';

			return;
		}

		$health = Diagnostics::getHealth();
		$labels = [
			'ok'      => __( 'Everything is fine', 'commonsbooking' ),
			'warning' => __( 'Please note', 'commonsbooking' ),
			'error'   => __( 'Problem found', 'commonsbooking' ),
		];
		self::renderBadge( $health['level'], $labels[ $health['level'] ] ?? '' );
		if ( $health['findings'] ) {
			echo '<ul class="cb-booster-findings">';
			foreach ( $health['findings'] as $finding ) {
				echo '<li class="cb-booster-' . esc_attr( $finding['level'] ) . '">' . esc_html( $finding['text'] ) . '</li>';
			}
			echo '</ul>';
		}

		self::renderSummary();
		self::renderCoverage();
		self::renderAvailability();
		self::renderLatestBookings();
		self::renderActivity();
		self::renderChecks();
		self::renderActions();

		echo '<p class="cmb2-metabox-description">' . esc_html__( 'Changes take effect with the next page load.', 'commonsbooking' ) . '</p></div></div>';
	}

	/**
	 * Fourth column of the CommonsBooking dashboard: status dot, two key figures, a short description and a link to
	 * the settings. Written for users without technical knowledge. Only cheap queries (counts), no checks.
	 *
	 * @return void
	 */
	public static function renderDashboardColumn(): void {
		$enabled = Booster::isEnabled();
		$health  = $enabled ? Diagnostics::getHealth() : [
			'level'    => 'off',
			'findings' => [],
		];
		$level   = $health['level'];
		$url     = admin_url( 'admin.php?page=commonsbooking_options_advanced-options' ) . '#booster-group-header';
		$isNew   = ! $enabled && ! Booster::getState()['activated_at'];

		$figures = $enabled && ! Backfill::isRunning() && Availability::isEnabled();
		if ( $figures ) {
			$coverage = Diagnostics::getCoverage();
			$index    = array_sum( array_column( $coverage['types'], 'index' ) );
			$posts    = array_sum( array_column( $coverage['types'], 'posts' ) );
			$stats    = Store::getStats();
			$pairs    = max( 0, $stats['rows'] - $stats['role_limited'] ); // as on the settings page
			$usable   = min( $pairs, $stats['usable'] );
		}
		// e.g. right after the index build: the calendars are prepared by the next WP-Cron run
		$preparing = $figures && $pairs && $usable < $pairs;

		if ( ! $enabled ) {
			$label = __( 'Switched off', 'commonsbooking' );
		} elseif ( ! Backfill::isDone() ) {
			$level = 'warning';
			$label = Backfill::isRunning()
				/* translators: %d: progress of the setup in percent */
				? sprintf( __( 'Being set up (%d %%)', 'commonsbooking' ), Booster::getBackfillProgress() )
				: __( 'Waiting to be set up', 'commonsbooking' );
		} else {
			$labels = [
				'ok'      => __( 'Active, everything is fine', 'commonsbooking' ),
				'warning' => __( 'Active, please note', 'commonsbooking' ),
				'error'   => __( 'Active, problem found', 'commonsbooking' ),
			];
			$label  = $labels[ $level ] ?? '';
			if ( 'ok' === $level && $preparing ) {
				$level = 'warning';
				$label = __( 'Active, item calendars are being prepared', 'commonsbooking' );
			}
		}

		// the styles inside the column, so that it stays the fourth child of the container
		echo '<div class="cb_welcome-panel-column cb-booster-column">';
		self::renderDashboardStyles();
		echo '<h3 style="padding-bottom:20px">' . esc_html__( 'Performance booster', 'commonsbooking' );
		if ( $isNew ) {
			echo ' <span class="cb-booster-card-new">' . esc_html__( 'New', 'commonsbooking' ) . '</span>';
		}
		echo '</h3>';
		echo '<div class="cb-booster-card cb-booster-card-' . esc_attr( $level ) . '">';
		echo '<p class="cb-booster-card-head"><span class="cb-booster-dot" aria-hidden="true"></span><strong>' . esc_html( $label ) . '</strong></p>';

		$notes = count( array_filter( $health['findings'], fn( $finding ) => 'info' !== $finding['level'] ) );
		if ( $preparing && ! $notes ) {
			echo '<p class="cb-booster-card-note">' . esc_html__( 'This happens automatically with the next page view and takes a few seconds. Until then, the calendars are computed as without the booster.', 'commonsbooking' ) . '</p>';
		} elseif ( $notes && Backfill::isDone() ) {
			/* translators: %d: number of notes */
			echo '<p class="cb-booster-card-note">' . esc_html( sprintf( _n( '%d note, details in the settings', '%d notes, details in the settings', $notes, 'commonsbooking' ), $notes ) ) . '</p>';
		}

		if ( $enabled && Backfill::isRunning() ) {
			echo '<div class="cb-booster-card-progress"><span style="width:' . esc_attr( (string) Booster::getBackfillProgress() ) . '%"></span></div>';
		} elseif ( $figures ) {
			echo '<div class="cb-booster-card-figures">';
			self::renderFigure(
				/* translators: 1: entries in the index, 2: all entries */
				sprintf( __( '%1$s of %2$s', 'commonsbooking' ), number_format_i18n( $index ), number_format_i18n( $posts ) ),
				__( 'timeframes, bookings and restrictions captured', 'commonsbooking' ),
				$index === $posts
			);
			if ( $pairs ) {
				self::renderFigure(
					/* translators: 1: up-to-date calendars, 2: all calendars */
					sprintf( __( '%1$s of %2$s', 'commonsbooking' ), number_format_i18n( $usable ), number_format_i18n( $pairs ) ),
					__( 'item calendars prepared', 'commonsbooking' ),
					$usable === $pairs
				);
			} else {
				self::renderFigure( '–', __( 'item calendars are prepared when the map is shown next', 'commonsbooking' ), true );
			}
			echo '</div>';
			if ( $coverage['newest_index'] ) {
				$updated = (int) strtotime( $coverage['newest_index'] . ' UTC' );
				echo '<p class="cb-booster-card-updated" title="' . esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) ) . '">' . esc_html(
					/* translators: %s: human readable time difference, e.g. "5 mins" */
					sprintf( __( 'Last updated %s ago', 'commonsbooking' ), human_time_diff( $updated ) )
				) . '</p>';
			}
		}

		echo '<p class="cb-booster-card-desc">' . esc_html__( 'Makes the map, item lists and calendars noticeably faster on sites with many items and bookings.', 'commonsbooking' ) . '</p>';
		echo '<a class="cb-booster-card-link" href="' . esc_url( $url ) . '">' . ( $enabled ? esc_html__( 'Status and settings', 'commonsbooking' ) : esc_html__( 'Switch on in the settings', 'commonsbooking' ) ) . ' &rarr;</a>';
		echo '</div></div>';
	}

	/**
	 * @param string $value    the figure, e.g. "40 of 40"
	 * @param string $text     what is counted
	 * @param bool   $complete false highlights the value
	 *
	 * @return void
	 */
	private static function renderFigure( string $value, string $text, bool $complete ): void {
		echo '<div class="cb-booster-card-figure' . ( $complete ? '' : ' cb-booster-card-figure-open' ) . '"><b>' . esc_html( $value ) . '</b><span>' . esc_html( $text ) . '</span></div>';
	}

	/**
	 * Widths of the four dashboard columns and the card.
	 *
	 * @return void
	 */
	private static function renderDashboardStyles(): void {
		echo '<style>
			.cb_welcome-panel .cb-dashboard-four-columns { display: flex; flex-wrap: nowrap; }
			.cb_welcome-panel .cb-dashboard-four-columns > .cb_welcome-panel-column { float: none; box-sizing: border-box; flex: 1 1 0; width: auto; min-width: 0; padding-right: 24px; overflow-wrap: break-word; }
			.cb_welcome-panel .cb-dashboard-four-columns > .cb_welcome-panel-column:first-child { width: auto; }
			.cb_welcome-panel .cb-dashboard-four-columns > .cb_welcome-panel-column:nth-child(3) { flex-grow: 0.7; }
			.cb_welcome-panel .cb-dashboard-four-columns > .cb_welcome-panel-column:nth-child(4) { flex-grow: 1.3; padding-right: 0; }
			.cb-booster-card { margin: 0.8em 0 1.5em; padding: 12px 14px; border: 1px solid #dcdcde; border-left: 4px solid #a7aaad; border-radius: 4px; background: #fff; max-width: 420px; }
			.cb-booster-card-ok { border-left-color: #2e9e4f; }
			.cb-booster-card-warning { border-left-color: #dba617; }
			.cb-booster-card-error { border-left-color: #d63638; }
			.cb_welcome-panel .cb-booster-card p { margin: 0 0 8px; }
			.cb_welcome-panel .cb-booster-card p.cb-booster-card-head { display: flex; align-items: center; gap: 8px; color: #1d2327; }
			.cb-booster-card-new { margin-left: 4px; padding: 1px 7px; border-radius: 9px; background: #67b32a; color: #fff; font-size: 11px; font-weight: 600; text-transform: uppercase; vertical-align: middle; }
			.cb-booster-dot { width: 10px; height: 10px; border-radius: 50%; flex: none; background: #a7aaad; }
			.cb-booster-card-ok .cb-booster-dot { background: #2e9e4f; box-shadow: 0 0 0 3px #d1f0d8; }
			.cb-booster-card-warning .cb-booster-dot { background: #dba617; box-shadow: 0 0 0 3px #fcf0c4; }
			.cb-booster-card-error .cb-booster-dot { background: #d63638; box-shadow: 0 0 0 3px #f9d6d6; }
			.cb_welcome-panel .cb-booster-card p.cb-booster-card-note { margin-left: 18px; font-size: 12px; }
			.cb-booster-card-figures { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 10px; }
			.cb-booster-card-figure { flex: 1 1 110px; padding: 6px 8px; background: #f6f7f7; border-radius: 4px; line-height: 1.4; }
			.cb-booster-card-figure b { display: block; font-size: 16px; white-space: nowrap; }
			.cb-booster-card-figure span { color: #50575e; font-size: 12px; }
			.cb-booster-card-figure-open b { color: #996800; }
			.cb-booster-card-progress { height: 8px; margin: 0 0 10px; background: #e5e5e5; border-radius: 4px; overflow: hidden; }
			.cb-booster-card-progress span { display: block; height: 100%; background: #dba617; }
			.cb_welcome-panel .cb-booster-card p.cb-booster-card-updated { margin-top: -4px; color: #50575e; font-size: 12px; }
			.cb-booster-card-link { font-weight: 600; text-decoration: none; }
		</style>';
	}

	/**
	 * Mode and last rebuild.
	 *
	 * @return void
	 */
	private static function renderSummary(): void {
		$status = Booster::getStatus( true );
		echo '<table class="cb-booster-kv"><tbody>';
		$labels = [
			'mode'         => __( 'Mode', 'commonsbooking' ),
			'last rebuild' => __( 'Last rebuild', 'commonsbooking' ),
		];
		foreach ( $labels as $key => $label ) {
			if ( isset( $status[ $key ] ) ) {
				echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $status[ $key ] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	/**
	 * Index coverage per post type with bars, newest change in WordPress and in the index.
	 *
	 * @return void
	 */
	private static function renderCoverage(): void {
		$coverage = Diagnostics::getCoverage();
		echo '<h4>' . esc_html__( 'Index', 'commonsbooking' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Every timeframe, booking and restriction is copied into the index when it is saved. The numbers on both sides should be equal.', 'commonsbooking' ) . '</p>';

		if ( Backfill::isRunning() ) {
			$progress = Booster::getBackfillProgress();
			self::renderBar( __( 'Index build', 'commonsbooking' ), $progress, 100, sprintf( '%d %%', $progress ), 'warning' );
		}
		foreach ( $coverage['types'] as $type => $counts ) {
			$state = $counts['posts'] === $counts['index'] ? 'ok' : 'warning';
			self::renderBar(
				Diagnostics::getTypeLabel( $type ),
				$counts['index'],
				max( 1, $counts['posts'] ),
				/* translators: 1: number of index rows, 2: number of posts in WordPress */
				sprintf( __( '%1$s of %2$s in the index', 'commonsbooking' ), number_format_i18n( $counts['index'] ), number_format_i18n( $counts['posts'] ) ),
				$state
			);
		}
		echo '<table class="cb-booster-kv"><tbody>';
		echo '<tr><th>' . esc_html__( 'Newest change in WordPress', 'commonsbooking' ) . '</th><td>' . esc_html( self::formatGmt( $coverage['newest_post'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Newest change in the index', 'commonsbooking' ) . '</th><td>' . esc_html( self::formatGmt( $coverage['newest_index'] ) ) . ' ' . self::stateIcon( $coverage['newest_post'] === $coverage['newest_index'] ? 'ok' : 'pending' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is static
		echo '</tbody></table>';
	}

	/**
	 * Precomputed availability: usable, changed, outdated pairs.
	 *
	 * @return void
	 */
	private static function renderAvailability(): void {
		echo '<h4>' . esc_html__( 'Precomputed availability', 'commonsbooking' ) . '</h4>';
		if ( ! Availability::isEnabled() ) {
			echo '<p>' . esc_html__( 'Not in use until the index is complete: every day is computed live.', 'commonsbooking' ) . '</p>';

			return;
		}
		[ $from, $to ] = Availability::getHorizon();
		$stats         = Store::getStats();
		$live          = $stats['role_limited'];
		$outdated      = max( 0, $stats['rows'] - $stats['usable'] - $stats['changed'] - $live );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: first day, 2: last day */
				__( 'One row per item and location with the state of every day from %1$s to %2$s. A booking marks its row as changed at once; it is computed again after the response or when it is shown next.', 'commonsbooking' ),
				date_i18n( get_option( 'date_format' ), strtotime( $from ) ),
				date_i18n( get_option( 'date_format' ), strtotime( $to ) )
			)
		) . '</p>';
		self::renderBar(
			__( 'Item/location pairs', 'commonsbooking' ),
			$stats['usable'],
			max( 1, $stats['rows'] - $live ),
			/* translators: 1: usable rows, 2: all rows */
			sprintf( __( '%1$s of %2$s up to date', 'commonsbooking' ), number_format_i18n( $stats['usable'] ), number_format_i18n( $stats['rows'] - $live ) ),
			$outdated ? 'warning' : 'ok'
		);
		echo '<table class="cb-booster-kv"><tbody>';
		echo '<tr><th>' . esc_html__( 'Changed, computed with the next request', 'commonsbooking' ) . '</th><td>' . esc_html( number_format_i18n( $stats['changed'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Older than 24 hours', 'commonsbooking' ) . '</th><td>' . esc_html( number_format_i18n( $outdated ) ) . '</td></tr>';
		if ( AccessProfile::isEnabled() ) {
			$access = AccessStore::getStats();
			echo '<tr><th>' . esc_html__( 'Limited to user roles (stored per access profile)', 'commonsbooking' ) . '</th><td>' . esc_html( number_format_i18n( $live ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Stored access profiles (up to date)', 'commonsbooking' ) . '</th><td>' . esc_html( number_format_i18n( $access['rows'] ) . ' (' . number_format_i18n( $access['usable'] ) . ')' ) . '</td></tr>';
		} else {
			echo '<tr><th>' . esc_html__( 'Limited to user roles (always computed live)', 'commonsbooking' ) . '</th><td>' . esc_html( number_format_i18n( $live ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The latest bookings with their state in WordPress and in the index.
	 *
	 * @return void
	 */
	private static function renderLatestBookings(): void {
		echo '<h4>' . esc_html__( 'Latest bookings', 'commonsbooking' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'The most recently created, changed or cancelled bookings. The status in the index must match the status in WordPress.', 'commonsbooking' ) . '</p>';
		$bookings = Diagnostics::getLatestBookings();
		if ( ! $bookings ) {
			echo '<p>' . esc_html__( 'No bookings yet.', 'commonsbooking' ) . '</p>';

			return;
		}
		echo '<table class="widefat striped cb-booster-table"><thead><tr>';
		foreach ( [ __( 'Changed', 'commonsbooking' ), __( 'Booking', 'commonsbooking' ), __( 'Item', 'commonsbooking' ), __( 'Location', 'commonsbooking' ), __( 'Period', 'commonsbooking' ), __( 'Status', 'commonsbooking' ), __( 'Index', 'commonsbooking' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $bookings as $booking ) {
			$state = Diagnostics::compareStatus( $booking['status'], $booking['index_status'], $booking['changed'] );
			echo '<tr>';
			echo '<td>' . esc_html( self::formatTime( $booking['changed'] ) ) . '</td>';
			echo '<td><a href="' . esc_url( (string) get_edit_post_link( $booking['id'] ) ) . '">' . esc_html( '#' . $booking['id'] ) . '</a></td>';
			echo '<td>' . esc_html( $booking['item'] ) . '</td>';
			echo '<td>' . esc_html( $booking['location'] ) . '</td>';
			echo '<td>' . esc_html( self::formatPeriod( $booking['start'], $booking['end'] ) ) . '</td>';
			echo '<td>' . esc_html( self::statusLabel( $booking['status'] ) ) . '</td>';
			echo '<td>' . self::stateIcon( $state ) . ' ' . esc_html( $booking['index_status'] !== null ? self::statusLabel( $booking['index_status'] ) : __( 'missing', 'commonsbooking' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is static
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The activity log: latest index changes and whether the index still matches.
	 *
	 * @return void
	 */
	private static function renderActivity(): void {
		echo '<h4>' . esc_html__( 'Latest index activity', 'commonsbooking' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Every change of a timeframe, booking or restriction that the index processed, including deletions. The last column checks the current state again.', 'commonsbooking' ) . '</p>';
		$events = array_slice( Diagnostics::getActivity(), 0, 15 );
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No changes recorded since the booster was switched on.', 'commonsbooking' ) . '</p>';

			return;
		}
		$actions = [
			'created' => __( 'added', 'commonsbooking' ),
			'changed' => __( 'changed', 'commonsbooking' ),
			'removed' => __( 'removed', 'commonsbooking' ),
		];
		$sources = [
			'frontend' => __( 'website', 'commonsbooking' ),
			'admin'    => __( 'admin', 'commonsbooking' ),
			'cron'     => __( 'scheduled task', 'commonsbooking' ),
			'cli'      => __( 'WP-CLI', 'commonsbooking' ),
		];
		echo '<table class="widefat striped cb-booster-table"><thead><tr>';
		foreach ( [ __( 'Time', 'commonsbooking' ), __( 'Post', 'commonsbooking' ), __( 'Action', 'commonsbooking' ), __( 'Status', 'commonsbooking' ), __( 'Source', 'commonsbooking' ), __( 'Index now', 'commonsbooking' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			$status = $event['old_status'] && $event['new_status'] && $event['old_status'] !== $event['new_status']
				? self::statusLabel( $event['old_status'] ) . ' → ' . self::statusLabel( $event['new_status'] )
				: self::statusLabel( $event['new_status'] ?: $event['old_status'] );
			$post   = get_post( (int) $event['post_id'] );
			$name   = Diagnostics::getTypeLabel( $event['post_type'] ) . ' #' . (int) $event['post_id'];
			echo '<tr>';
			echo '<td>' . esc_html( self::formatTime( (int) $event['time'] ) ) . '</td>';
			echo '<td>' . ( $post ? '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts
			echo '<td>' . esc_html( $actions[ $event['action'] ] ?? $event['action'] ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( $sources[ $event['source'] ] ?? $event['source'] ) . '</td>';
			echo '<td>' . self::stateIcon( Diagnostics::getEventState( $event ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is static
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Checks and scheduled tasks: last run, result, next run.
	 *
	 * @return void
	 */
	private static function renderChecks(): void {
		echo '<h4>' . esc_html__( 'Checks and scheduled tasks', 'commonsbooking' ) . '</h4>';
		$checks   = Diagnostics::getChecks();
		$schedule = Diagnostics::getSchedule();
		$state    = Booster::getState();
		$rows     = [
			[
				__( 'Quick check for changes made without the booster', 'commonsbooking' ),
				__( 'on every page load', 'commonsbooking' ),
				/* translators: %d: post id */
				sprintf( __( 'checked up to post %d', 'commonsbooking' ), $state['watermark'] ),
				null,
			],
			[
				__( 'Deep check: status and changes of all posts', 'commonsbooking' ),
				self::formatCheckTime( $checks['guard_deep'] ?? null ),
				isset( $checks['guard_deep'] ) ? self::formatResult( $checks['guard_deep']['changed'] + $checks['guard_deep']['orphans'] ) : '–',
				$schedule[ Guard::CRON_HOOK ] ?? null,
			],
			[
				__( 'Index sample (200 random posts)', 'commonsbooking' ),
				self::formatCheckTime( $checks['index_verify'] ?? null ),
				isset( $checks['index_verify'] ) ? self::formatResult( $checks['index_verify']['mismatches'] + $checks['index_verify']['orphans'], (int) $checks['index_verify']['checked'] ) : '–',
				$schedule[ Verifier::CRON_HOOK ] ?? null,
			],
			[
				__( 'Availability sample (50 pairs)', 'commonsbooking' ),
				self::formatCheckTime( $checks['availability_verify'] ?? null ),
				isset( $checks['availability_verify'] ) ? self::formatResult( $checks['availability_verify']['mismatches'], (int) $checks['availability_verify']['checked'] ) : '–',
				$schedule[ Maintenance::VERIFY_HOOK ] ?? null,
			],
			[
				__( 'Compute changed and old availability rows', 'commonsbooking' ),
				self::formatCheckTime( $checks['availability_refresh'] ?? null ),
				isset( $checks['availability_refresh'] )
					/* translators: %d: number of computed pairs */
					? sprintf( __( '%d computed', 'commonsbooking' ), (int) $checks['availability_refresh']['recomputed'] )
					: '–',
				$schedule[ Maintenance::CRON_HOOK ] ?? null,
			],
		];
		echo '<table class="widefat striped cb-booster-table"><thead><tr>';
		foreach ( [ __( 'Task', 'commonsbooking' ), __( 'Last run', 'commonsbooking' ), __( 'Result', 'commonsbooking' ), __( 'Next run', 'commonsbooking' ) ] as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as [ $task, $last, $result, $next ] ) {
			echo '<tr><td>' . esc_html( $task ) . '</td><td>' . esc_html( $last ) . '</td><td>' . esc_html( $result ) . '</td><td>' . esc_html( $next === null ? '–' : self::formatNext( $next ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Buttons (links, the settings page is a form already).
	 *
	 * @return void
	 */
	private static function renderActions(): void {
		$check   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CHECK ), self::ACTION_CHECK );
		$rebuild = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_REBUILD ), self::ACTION_REBUILD );
		echo '<p class="cb-booster-actions">';
		echo '<a class="button button-secondary" href="' . esc_url( $check ) . '">' . esc_html__( 'Run all checks now', 'commonsbooking' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( $rebuild ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Rebuild the index? Until it is complete, the original (slower) queries are used.', 'commonsbooking' ) ) ) . ');">' . esc_html__( 'Rebuild index', 'commonsbooking' ) . '</a>';
		echo '</p><p class="description">' . esc_html__( 'The check compares all posts with the index and a sample of the availability with a fresh computation and repairs differences.', 'commonsbooking' ) . '</p>';
	}

	/**
	 * @param string $level ok|warning|error|off
	 * @param string $text
	 *
	 * @return void
	 */
	private static function renderBadge( string $level, string $text ): void {
		echo '<p><span class="cb-booster-badge cb-booster-badge-' . esc_attr( $level ) . '">' . esc_html( $text ) . '</span></p>';
	}

	/**
	 * @param string $label
	 * @param int    $value
	 * @param int    $max
	 * @param string $text
	 * @param string $state ok|warning
	 *
	 * @return void
	 */
	private static function renderBar( string $label, int $value, int $max, string $text, string $state ): void {
		$percent = (int) min( 100, round( 100 * $value / max( 1, $max ) ) );
		echo '<div class="cb-booster-bar-row"><span class="cb-booster-bar-label">' . esc_html( $label ) . '</span>';
		echo '<span class="cb-booster-bar"><span class="cb-booster-bar-fill cb-booster-' . esc_attr( $state ) . '" style="width:' . esc_attr( (string) $percent ) . '%"></span></span>';
		echo '<span class="cb-booster-bar-text">' . esc_html( $text ) . '</span></div>';
	}

	/**
	 * @param string $state ok|pending|mismatch
	 *
	 * @return string markup
	 */
	private static function stateIcon( string $state ): string {
		$titles = [
			'ok'       => __( 'matches', 'commonsbooking' ),
			'pending'  => __( 'being processed', 'commonsbooking' ),
			'mismatch' => __( 'differs', 'commonsbooking' ),
		];
		$icons  = [
			'ok'       => 'dashicons-yes-alt',
			'pending'  => 'dashicons-clock',
			'mismatch' => 'dashicons-warning',
		];

		return '<span class="dashicons ' . esc_attr( $icons[ $state ] ?? 'dashicons-minus' ) . ' cb-booster-icon-' . esc_attr( $state ) . '" title="' . esc_attr( $titles[ $state ] ?? '' ) . '"></span><span class="screen-reader-text">' . esc_html( $titles[ $state ] ?? '' ) . '</span>';
	}

	/**
	 * @return void
	 */
	private static function renderStyles(): void {
		echo '<style>
			.cb-booster-status h4 { margin: 1.6em 0 .3em; }
			.cb-booster-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-weight: 600; }
			.cb-booster-badge-ok { background: #d1f0d8; color: #0a5c1f; }
			.cb-booster-badge-warning { background: #fcf0c4; color: #6b4d00; }
			.cb-booster-badge-error { background: #f9d6d6; color: #8a1414; }
			.cb-booster-badge-off { background: #e5e5e5; color: #3c434a; }
			.cb-booster-findings { margin: .2em 0 1em 1.2em; list-style: disc; }
			.cb-booster-findings .cb-booster-error { color: #8a1414; }
			.cb-booster-bar-row { display: flex; align-items: center; gap: 10px; margin: 4px 0; }
			.cb-booster-bar-label { width: 170px; }
			.cb-booster-bar { flex: 0 0 260px; height: 12px; background: #e5e5e5; border-radius: 6px; overflow: hidden; }
			.cb-booster-bar-fill { display: block; height: 100%; }
			.cb-booster-bar-fill.cb-booster-ok { background: #2e9e4f; }
			.cb-booster-bar-fill.cb-booster-warning { background: #dba617; }
			.cb-booster-kv { margin: .4em 0; border-collapse: collapse; }
			.cb-booster-kv th { text-align: left; font-weight: normal; color: #50575e; padding: 2px 16px 2px 0; }
			.cb-booster-kv td { padding: 2px 0; }
			.cb-booster-table { max-width: 900px; margin-top: .4em; }
			.cb-booster-table td, .cb-booster-table th { padding: 4px 8px; }
			.cb-booster-icon-ok { color: #2e9e4f; }
			.cb-booster-icon-pending { color: #996800; }
			.cb-booster-icon-mismatch { color: #b32d2e; }
			.cb-booster-actions { margin-top: 1.2em; }
		</style>';
	}

	/**
	 * @param string $action
	 *
	 * @return void
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'commonsbooking' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param string $notice
	 *
	 * @return void
	 */
	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, admin_url( 'admin.php?page=commonsbooking_options_advanced-options' ) ) . '#booster-group-header' );
		exit;
	}


	/**
	 * @param string $status
	 *
	 * @return string
	 */
	private static function statusLabel( string $status ): string {
		$object = get_post_status_object( $status );

		return $object && $object->label ? (string) $object->label : $status;
	}

	/**
	 * @param int $timestamp
	 *
	 * @return string
	 */
	private static function formatTime( int $timestamp ): string {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
			/* translators: %s: human readable time difference, e.g. "5 mins" */
			. ' (' . sprintf( __( '%s ago', 'commonsbooking' ), human_time_diff( $timestamp ) ) . ')';
	}

	/**
	 * @param string|null $gmt Y-m-d H:i:s
	 *
	 * @return string
	 */
	private static function formatGmt( ?string $gmt ): string {
		return $gmt ? self::formatTime( (int) strtotime( $gmt . ' UTC' ) ) : '–';
	}

	/**
	 * CommonsBooking stores local times as timestamps ("local time as UTC").
	 *
	 * @param int|null $start
	 * @param int|null $end
	 *
	 * @return string
	 */
	private static function formatPeriod( ?int $start, ?int $end ): string {
		if ( ! $start ) {
			return '–';
		}
		$format = get_option( 'date_format' );
		$from   = gmdate( $format, $start );
		$to     = $end ? gmdate( $format, $end ) : '';

		return $to && $to !== $from ? $from . ' – ' . $to : $from;
	}

	/**
	 * @param array|null $check
	 *
	 * @return string
	 */
	private static function formatCheckTime( ?array $check ): string {
		return $check ? self::formatTime( (int) $check['time'] ) : __( 'not yet', 'commonsbooking' );
	}

	/**
	 * @param int      $differences
	 * @param int|null $checked
	 *
	 * @return string
	 */
	private static function formatResult( int $differences, ?int $checked = null ): string {
		$text = $differences
			/* translators: %d: number of repaired differences */
			? sprintf( _n( '%d difference repaired', '%d differences repaired', $differences, 'commonsbooking' ), $differences )
			: __( 'no differences', 'commonsbooking' );
		if ( $checked !== null ) {
			/* translators: %d: number of checked entries */
			$text .= ' (' . sprintf( __( '%d checked', 'commonsbooking' ), $checked ) . ')';
		}

		return $text;
	}

	/**
	 * @param int $timestamp
	 *
	 * @return string
	 */
	private static function formatNext( int $timestamp ): string {
		if ( $timestamp < time() - 30 * MINUTE_IN_SECONDS ) {
			/* translators: %s: human readable time difference */
			return sprintf( __( 'overdue for %s', 'commonsbooking' ), human_time_diff( $timestamp ) );
		}
		if ( $timestamp <= time() ) {
			return __( 'now', 'commonsbooking' );
		}

		/* translators: %s: human readable time difference */
		return sprintf( __( 'in %s', 'commonsbooking' ), human_time_diff( $timestamp ) );
	}
}
