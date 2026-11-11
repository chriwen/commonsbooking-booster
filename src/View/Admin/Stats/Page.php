<?php

namespace CommonsBooking\View\Admin\Stats;

use CommonsBooking\Service\Stats\Build;
use CommonsBooking\Service\Stats\Filters;
use CommonsBooking\Service\Stats\Queue;
use CommonsBooking\Service\Stats\Query;
use CommonsBooking\Service\Stats\Stats;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;

/**
 * Admin page "Statistics" (analytics dashboard): filters, overview, items, locations, CSV export.
 * Reads only the aggregated tables. Administrators only.
 */
class Page {

	public const SLUG          = 'cb-stats';
	public const ACTION_ENABLE = 'commonsbooking_stats_enable';
	public const ACTION_STEP   = 'commonsbooking_stats_step';

	private const VIEWS = [ 'overview', 'items', 'locations' ];

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_ENABLE, [ self::class, 'handleEnable' ] );
		add_action( 'wp_ajax_' . self::ACTION_STEP, [ self::class, 'handleStep' ] );
		Export::init();
	}

	/**
	 * Adds the menu entry (administrators only).
	 *
	 * @return void
	 */
	public static function addMenu(): void {
		if ( ! commonsbooking_isCurrentUserAdmin() ) {
			return;
		}
		add_submenu_page(
			'cb-dashboard',
			esc_html__( 'Statistics', 'commonsbooking' ),
			esc_html__( 'Statistics', 'commonsbooking' ),
			'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @return void
	 */
	public static function handleEnable(): void {
		if ( ! commonsbooking_isCurrentUserAdmin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'commonsbooking' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION_ENABLE );
		Stats::enable();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * AJAX: works on the build for a few seconds and returns the progress. The open page calls it repeatedly, so the
	 * build does not depend on WP-Cron and every request stays short.
	 *
	 * @return void
	 */
	public static function handleStep(): void {
		if ( ! commonsbooking_isCurrentUserAdmin() ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( self::ACTION_STEP );
		wp_send_json_success( Build::step( Build::budget() ) );
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return string
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @return void
	 */
	public static function render(): void {
		if ( ! commonsbooking_isCurrentUserAdmin() ) {
			wp_die( esc_html__( 'You are not allowed to see statistics.', 'commonsbooking' ), '', [ 'response' => 403 ] );
		}

		echo '<div class="wrap cb-stats"><h1>' . esc_html__( 'Statistics', 'commonsbooking' ) . '</h1>';
		echo '<p class="description" style="margin:0 0 8px;font-size:14px">' . esc_html__( 'How heavily your items and locations are used. Click the (i) next to a figure for an explanation.', 'commonsbooking' ) . '</p>';
		self::styles();

		if ( ! Stats::isEnabled() ) {
			self::renderEnable();
		} elseif ( ! Stats::isReady() ) {
			self::renderProgress();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters
			$request = wp_unslash( $_GET );
			$filters = Filters::fromRequest( $request );
			$view    = in_array( $request['view'] ?? '', self::VIEWS, true ) ? $request['view'] : 'overview';
			self::renderFilters( $filters, $view );
			self::renderTabs( $filters, $view );
			$query = new Query( $filters );
			if ( $query->isEmptySelection() ) {
				echo '<p>' . esc_html__( 'No items or locations match the selected categories.', 'commonsbooking' ) . '</p>';
			} elseif ( $view === 'items' ) {
				self::renderRanking( $filters, $query, 'item', $request );
			} elseif ( $view === 'locations' ) {
				self::renderRanking( $filters, $query, 'location', $request );
			} else {
				self::renderOverview( $filters, $query );
			}
			self::renderExport( $filters, $view );
		}
		echo '</div>';
	}

	/**
	 * Progress bar of the build. Works without JavaScript too: every page load does one step.
	 */
	private static function renderProgress(): void {
		$progress = Build::isRunning() ? Build::step( min( 3.0, Build::budget() ) ) : Build::progress();
		if ( $progress['done'] ) {
			echo '<script>location.reload();</script><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Show statistics', 'commonsbooking' ) . '</a></p>';
			return;
		}
		$config = wp_json_encode(
			[
				'url'    => admin_url( 'admin-ajax.php' ),
				'action' => self::ACTION_STEP,
				'nonce'  => wp_create_nonce( self::ACTION_STEP ),
				'texts'  => [
					'bookings' => __( 'Reading bookings …', 'commonsbooking' ),
					'pairs'    => __( 'Finding items and locations …', 'commonsbooking' ),
					'days'     => __( 'Computing daily values …', 'commonsbooking' ),
					'error'    => __( 'The build was interrupted. It continues when you reload this page.', 'commonsbooking' ),
				],
			]
		);
		echo '<div class="notice notice-info inline cb-stats-progress"><p><strong>' . esc_html__( 'The statistics are being built.', 'commonsbooking' ) . '</strong> '
			. esc_html__( 'You can leave this page, the build continues in the background. It is done in small steps and is safe on shared hosting.', 'commonsbooking' ) . '</p>';
		echo '<div class="cb-stats-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $progress['percent'] . '" aria-label="' . esc_attr__( 'Progress', 'commonsbooking' ) . '"><div style="width:' . (int) $progress['percent'] . '%"></div></div>';
		echo '<p class="cb-stats-status" aria-live="polite">' . (int) $progress['percent'] . ' %</p>';
		echo '<noscript><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Reload to continue', 'commonsbooking' ) . '</a></p></noscript></div>';
		echo '<style>.cb-stats-bar{height:14px;background:#dcdcde;border-radius:7px;overflow:hidden;margin:8px 0}.cb-stats-bar div{height:100%;background:var(--cb-stats-a,#2271b1);transition:width .4s}</style>';
		?>
<script>
(function(){
	var c = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON ?>;
	var bar = document.querySelector('.cb-stats-bar'), fill = bar.firstChild, status = document.querySelector('.cb-stats-status');
	var fails = 0;
	function show(p){
		fill.style.width = p.percent + '%'; bar.setAttribute('aria-valuenow', p.percent);
		status.textContent = p.percent + ' % – ' + (c.texts[p.phase] || '') + (p.phase === 'days' && p.total ? ' (' + (p.total - p.pending) + ' / ' + p.total + ')' : '');
	}
	function step(){
		var body = new URLSearchParams({action: c.action, _ajax_nonce: c.nonce});
		fetch(c.url, {method: 'POST', credentials: 'same-origin', body: body})
			.then(function(r){ return r.json(); })
			.then(function(j){
				if (!j.success) { throw new Error('denied'); }
				fails = 0; show(j.data);
				if (j.data.error) { status.textContent = c.texts.error; setTimeout(step, 5000); return; }
				if (j.data.done) { location.reload(); } else { setTimeout(step, 300); }
			})
			.catch(function(){ // timeout or network: try again, the state is saved after every step
				if (++fails > 20) { status.textContent = c.texts.error; return; }
				setTimeout(step, 2000 * fails);
			});
	}
	step();
})();
</script>
		<?php
	}

	private static function renderEnable(): void {
		echo '<p>' . esc_html__( 'The statistics show how heavily items and locations are used. They are computed once from your bookings and then kept up to date in the background. They add their own database tables and change nothing in your existing data.', 'commonsbooking' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ENABLE ) . '">';
		wp_nonce_field( self::ACTION_ENABLE );
		submit_button( __( 'Switch on statistics', 'commonsbooking' ), 'primary', 'submit', false );
		echo '</form>';
	}

	private static function renderFilters( Filters $f, string $view ): void {
		$presets = [
			'30d'      => __( 'Last 30 days', 'commonsbooking' ),
			'12m'      => __( 'Last 12 months', 'commonsbooking' ),
			'year'     => __( 'This year', 'commonsbooking' ),
			'lastyear' => __( 'Last year', 'commonsbooking' ),
			'custom'   => __( 'Custom', 'commonsbooking' ),
		];
		echo '<form method="get" class="cb-stats-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
		echo '<label>' . esc_html__( 'Period', 'commonsbooking' ) . ' ' . self::select( 'preset', $presets, $f->preset ) . '</label>';
		echo '<label>' . esc_html__( 'From', 'commonsbooking' ) . ' <input type="date" name="from" value="' . esc_attr( $f->from ) . '"></label>';
		echo '<label>' . esc_html__( 'To', 'commonsbooking' ) . ' <input type="date" name="to" value="' . esc_attr( $f->to ) . '"></label>';
		echo '<label>' . esc_html__( 'Compare with', 'commonsbooking' ) . ' ' . self::select(
			'compare',
			[
				'none'     => __( 'nothing', 'commonsbooking' ),
				'previous' => __( 'previous period', 'commonsbooking' ),
				'year'     => __( 'previous year', 'commonsbooking' ),
			],
			$f->compare
		) . '</label>';
		echo '<label>' . esc_html__( 'Resolution', 'commonsbooking' ) . ' ' . self::select(
			'resolution',
			[
				'day'   => __( 'day', 'commonsbooking' ),
				'week'  => __( 'week', 'commonsbooking' ),
				'month' => __( 'month', 'commonsbooking' ),
			],
			$f->resolution
		) . '</label>';
		echo '<label>' . esc_html__( 'Booking kind', 'commonsbooking' ) . ' ' . self::select(
			'unit',
			[
				'all'  => __( 'overall, plus separately', 'commonsbooking' ),
				'day'  => __( 'full day', 'commonsbooking' ),
				'hour' => __( 'hourly', 'commonsbooking' ),
			],
			$f->unit
		) . '</label>';
		echo '<div class="cb-stats-field"><span>' . esc_html__( 'Items', 'commonsbooking' ) . '</span>' . self::multiSelect( 'items', self::posts( Item::getPostType() ), $f->items, __( 'Items', 'commonsbooking' ) ) . '</div>';
		echo '<div class="cb-stats-field"><span>' . esc_html__( 'Locations', 'commonsbooking' ) . '</span>' . self::multiSelect( 'locations', self::posts( Location::getPostType() ), $f->locations, __( 'Locations', 'commonsbooking' ) ) . '</div>';
		echo '<div class="cb-stats-field"><span>' . esc_html__( 'Item categories', 'commonsbooking' ) . '</span>' . self::multiSelect( 'item_cat', self::terms( Item::getTaxonomyName() ), $f->itemCategories, __( 'Item categories', 'commonsbooking' ) ) . '</div>';
		echo '<div class="cb-stats-field"><span>' . esc_html__( 'Location categories', 'commonsbooking' ) . '</span>' . self::multiSelect( 'location_cat', self::terms( Location::getTaxonomyName() ), $f->locationCategories, __( 'Location categories', 'commonsbooking' ) ) . '</div>';
		submit_button( __( 'Apply', 'commonsbooking' ), 'secondary', '', false );
		echo ' <a class="button cb-stats-reset" href="' . esc_url( self::url( [ 'view' => $view ] ) ) . '">' . esc_html__( 'Reset filters', 'commonsbooking' ) . '</a>';
		echo '</form>';
		self::multiSelectScript();
	}

	private static function renderTabs( Filters $f, string $view ): void {
		$tabs = [
			'overview'  => __( 'Overview', 'commonsbooking' ),
			'items'     => __( 'Items', 'commonsbooking' ),
			'locations' => __( 'Locations', 'commonsbooking' ),
		];
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$key === $view ? ' nav-tab-active' : '',
				esc_url( self::url( array_merge( $f->toArgs(), [ 'view' => $key ] ) ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	private static function renderOverview( Filters $f, Query $q ): void {
		$range   = [ $f->from, $f->to ];
		$compare = $f->comparisonRange();
		$totals  = $q->totals( $range );
		$prev    = $compare ? $q->totals( $compare ) : [];

		if ( ! $totals ) {
			echo '<p>' . esc_html__( 'No data for this selection.', 'commonsbooking' ) . '</p>';
			return;
		}

		$series     = $q->series( $range );
		$prevSeries = $compare ? $q->series( $compare ) : [];
		if ( $f->unit === 'all' ) {
			self::renderCombined( $f, $q, $range, $compare );
		}
		foreach ( [ 'day', 'hour' ] as $unit ) {
			if ( ! isset( $totals[ $unit ] ) ) {
				continue;
			}
			$t = $totals[ $unit ];
			echo '<h2 class="cb-stats-section">' . esc_html( $unit === 'day' ? __( 'Full-day slots (counted in days)', 'commonsbooking' ) : __( 'Hourly slots (counted in hours)', 'commonsbooking' ) ) . '</h2>';
			echo '<div class="cb-stats-tiles">';
			$u = $unit === 'day' ? __( 'days', 'commonsbooking' ) : __( 'hours', 'commonsbooking' );
			self::tile( __( 'Utilisation', 'commonsbooking' ), self::pct( $t['booked'], $t['capacity'] ), isset( $prev[ $unit ] ) ? self::pct( $prev[ $unit ]['booked'], $prev[ $unit ]['capacity'] ) : null, sprintf( /* translators: %s: days or hours */ __( 'Share of the bookable %s that were actually booked. Times when an item was not offered do not count.', 'commonsbooking' ), $u ), $t['capacity'] > 0 ? $t['booked'] / $t['capacity'] : null );
			self::tile( __( 'Bookings', 'commonsbooking' ), number_format_i18n( $t['started'] ), isset( $prev[ $unit ] ) ? number_format_i18n( $prev[ $unit ]['started'] ) : null, __( 'Confirmed bookings that start in the period. Cancelled and unconfirmed bookings are not counted.', 'commonsbooking' ) );
			self::tile( __( 'Usage days', 'commonsbooking' ), number_format_i18n( $t['usage_days'] ), isset( $prev[ $unit ] ) ? number_format_i18n( $prev[ $unit ]['usage_days'] ) : null, __( 'Days on which an item was booked at least once, added up over all items and locations.', 'commonsbooking' ) );
			self::tile( __( 'Cancellation rate', 'commonsbooking' ), self::pct( $t['canceled'], $t['started'] + $t['canceled'], 1 ), isset( $prev[ $unit ] ) ? self::pct( $prev[ $unit ]['canceled'], $prev[ $unit ]['started'] + $prev[ $unit ]['canceled'], 1 ) : null, __( 'Cancelled bookings in relation to all bookings (confirmed and cancelled) that start in the period.', 'commonsbooking' ) );
			self::tile( __( 'Fully booked days', 'commonsbooking' ), self::pct( $t['full_days'], $t['open_days'] ), null, __( 'Share of the days with offered capacity on which everything was booked.', 'commonsbooking' ), $t['open_days'] > 0 ? $t['full_days'] / $t['open_days'] : null );
			self::tile( __( 'Failure rate', 'commonsbooking' ), self::pct( $t['failure'], $t['capacity'] + $t['outage'], 1 ), isset( $prev[ $unit ] ) ? self::pct( $prev[ $unit ]['failure'], $prev[ $unit ]['capacity'] + $prev[ $unit ]['outage'], 1 ) : null, __( 'Share of the planned capacity lost to a total failure (active repair restriction). Holidays, closures and repair timeframes are not included.', 'commonsbooking' ) );
			self::tile( __( 'Availability', 'commonsbooking' ), self::pct( $t['capacity'], $t['capacity'] + $t['outage'], 1 ), null, __( 'Share of the planned capacity that was really available. Holidays, closures, repairs and restrictions with total failure reduce it.', 'commonsbooking' ), $t['capacity'] + $t['outage'] > 0 ? $t['capacity'] / ( $t['capacity'] + $t['outage'] ) : null );
			echo '</div>';

			$buckets = self::buckets( $f->from, $f->to, $f->resolution );
			$rows    = self::byBucket( $series, $unit );
			$labels  = array_map( fn( $b ) => self::bucketLabel( $b, $f->resolution ), $buckets );
			$util    = array_map( fn( $b ) => self::ratio( $rows[ $b ]['booked'] ?? 0, $rows[ $b ]['capacity'] ?? 0 ), $buckets );
			$book    = array_map( fn( $b ) => (float) ( $rows[ $b ]['started'] ?? 0 ), $buckets );

			$utilCompare = null;
			$bookCompare = null;
			if ( $compare ) {
				$cb          = self::buckets( $compare[0], $compare[1], $f->resolution );
				$cr          = self::byBucket( $prevSeries, $unit );
				$utilCompare = array_map( fn( $i ) => isset( $cb[ $i ] ) ? self::ratio( $cr[ $cb[ $i ] ]['booked'] ?? 0, $cr[ $cb[ $i ] ]['capacity'] ?? 0 ) : 0.0, array_keys( $buckets ) );
				$bookCompare = array_map( fn( $i ) => isset( $cb[ $i ] ) ? (float) ( $cr[ $cb[ $i ] ]['started'] ?? 0 ) : 0.0, array_keys( $buckets ) );
			}
			$copts = [
				'compareLabel' => $compare ? $compare[0] . ' – ' . $compare[1] : '',
				'valueLabel'   => $f->from . ' – ' . $f->to,
			];

			echo '<div class="cb-stats-grid"><section class="cb-stats-card"><h3>' . esc_html__( 'Utilisation over time', 'commonsbooking' ) . '</h3>';
			echo Chart::columns(
				$labels,
				$util,
				$utilCompare,
				array_merge(
					$copts,
					[
						'percent' => true,
						'title' => __( 'Utilisation over time', 'commonsbooking' ),
						'desc' => self::unitName( $unit ),
					]
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Chart
			echo '</section><section class="cb-stats-card"><h3>' . esc_html__( 'Bookings over time', 'commonsbooking' ) . '</h3>';
			echo Chart::columns(
				$labels,
				$book,
				$bookCompare,
				array_merge(
					$copts,
					[
						'title' => __( 'Bookings over time', 'commonsbooking' ),
						'desc' => self::unitName( $unit ),
					]
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</section></div>';
			$tableRows = [];
			foreach ( $buckets as $i => $b ) {
				$tableRows[] = [ $labels[ $i ], self::pctF( $util[ $i ] ), number_format_i18n( $book[ $i ] ), number_format_i18n( $rows[ $b ]['canceled'] ?? 0 ) ];
			}
			self::details( [ __( 'Period', 'commonsbooking' ), __( 'Utilisation', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ), __( 'Cancellations', 'commonsbooking' ) ], $tableRows );
		}

		self::renderHeatmap( $q->heatmap( $range ) );
		self::renderDistributions( $q->distributions( $range ) );
	}

	/**
	 * Overall view over full-day and hourly bookings: unit-free key figures based on days.
	 *
	 * @param array{0: string, 1: string}      $range
	 * @param array{0: string, 1: string}|null $compare
	 */
	private static function renderCombined( Filters $f, Query $q, array $range, ?array $compare ): void {
		$t = $q->combinedTotals( $range );
		if ( ! $t ) {
			return;
		}
		$p = $compare ? $q->combinedTotals( $compare ) : [];
		$r = fn( $a, $b ) => $b > 0 ? $a / $b : null;

		echo '<h2 class="cb-stats-section">' . esc_html__( 'Overall (full-day and hourly bookings together)', 'commonsbooking' ) . '</h2>';
		echo '<div class="cb-stats-tiles">';
		self::tile( __( 'Day rate', 'commonsbooking' ), self::pct( $t['usage_days'], $t['open_days'] ), $p ? self::pct( $p['usage_days'], $p['open_days'] ) : null, __( 'Share of the days with offered capacity on which an item was booked at least once, hourly or full-day. A single booked hour makes the day a usage day, so hourly items look somewhat busier than their hour utilisation.', 'commonsbooking' ), $r( $t['usage_days'], $t['open_days'] ) );
		self::tile( __( 'Bookings', 'commonsbooking' ), number_format_i18n( $t['started'] ), $p ? number_format_i18n( $p['started'] ) : null, __( 'Confirmed bookings of both kinds that start in the period. Cancelled and unconfirmed bookings are not counted.', 'commonsbooking' ) );
		self::tile( __( 'Usage days', 'commonsbooking' ), number_format_i18n( $t['usage_days'] ), $p ? number_format_i18n( $p['usage_days'] ) : null, __( 'Days on which an item was booked at least once, added up over all items and locations.', 'commonsbooking' ) );
		self::tile( __( 'Cancellation rate', 'commonsbooking' ), self::pct( $t['canceled'], $t['started'] + $t['canceled'], 1 ), $p ? self::pct( $p['canceled'], $p['started'] + $p['canceled'], 1 ) : null, __( 'Cancelled bookings in relation to all bookings (confirmed and cancelled) that start in the period.', 'commonsbooking' ) );
		self::tile( __( 'Fully booked days', 'commonsbooking' ), self::pct( $t['full_days'], $t['open_days'] ), null, __( 'Share of the days with offered capacity on which everything was booked.', 'commonsbooking' ), $r( $t['full_days'], $t['open_days'] ) );
		self::tile( __( 'Availability', 'commonsbooking' ), self::pct( $t['avail'], $t['planned_days'], 1 ), null, __( 'Share of the planned capacity that was really available, counted by days. Holidays, closures, repairs and restrictions with total failure reduce it.', 'commonsbooking' ), $r( $t['avail'], $t['planned_days'] ) );
		self::tile( __( 'Failure rate', 'commonsbooking' ), self::pct( $t['fail'], $t['planned_days'], 1 ), null, __( 'Share of the planned capacity lost to a total failure (active repair restriction), counted by days. Holidays, closures and repair timeframes are not included.', 'commonsbooking' ) );
		echo '</div>';

		$buckets = self::buckets( $f->from, $f->to, $f->resolution );
		$labels  = array_map( fn( $b ) => self::bucketLabel( $b, $f->resolution ), $buckets );
		$rows    = array_column( $q->combinedSeries( $range ), null, 'bucket' );
		$rate    = array_map( fn( $b ) => self::ratio( $rows[ $b ]['usage_days'] ?? 0, $rows[ $b ]['open_days'] ?? 0 ), $buckets );
		$book    = array_map( fn( $b ) => (float) ( $rows[ $b ]['started'] ?? 0 ), $buckets );
		$rateC   = null;
		$bookC   = null;
		if ( $compare ) {
			$cb    = self::buckets( $compare[0], $compare[1], $f->resolution );
			$cr    = array_column( $q->combinedSeries( $compare ), null, 'bucket' );
			$rateC = array_map( fn( $i ) => isset( $cb[ $i ] ) ? self::ratio( $cr[ $cb[ $i ] ]['usage_days'] ?? 0, $cr[ $cb[ $i ] ]['open_days'] ?? 0 ) : 0.0, array_keys( $buckets ) );
			$bookC = array_map( fn( $i ) => isset( $cb[ $i ] ) ? (float) ( $cr[ $cb[ $i ] ]['started'] ?? 0 ) : 0.0, array_keys( $buckets ) );
		}
		$copts = [
			'compareLabel' => $compare ? $compare[0] . ' – ' . $compare[1] : '',
			'valueLabel'   => $f->from . ' – ' . $f->to,
		];
		echo '<div class="cb-stats-grid"><section class="cb-stats-card"><h3>' . esc_html__( 'Day rate over time', 'commonsbooking' ) . '</h3>';
		echo Chart::columns(
			$labels,
			$rate,
			$rateC,
			array_merge(
				$copts,
				[
					'percent' => true,
					'title' => __( 'Day rate over time', 'commonsbooking' ),
					'desc' => __( 'Overall', 'commonsbooking' ),
				]
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section><section class="cb-stats-card"><h3>' . esc_html__( 'Bookings over time', 'commonsbooking' ) . '</h3>';
		echo Chart::columns(
			$labels,
			$book,
			$bookC,
			array_merge(
				$copts,
				[
					'title' => __( 'Bookings over time', 'commonsbooking' ),
					'desc' => __( 'Overall', 'commonsbooking' ),
				]
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section></div>';
		$tableRows = [];
		foreach ( $buckets as $i => $b ) {
			$tableRows[] = [ $labels[ $i ], self::pctF( $rate[ $i ] ), number_format_i18n( $book[ $i ] ), number_format_i18n( $rows[ $b ]['canceled'] ?? 0 ) ];
		}
		self::details( [ __( 'Period', 'commonsbooking' ), __( 'Day rate', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ), __( 'Cancellations', 'commonsbooking' ) ], $tableRows );
		echo '<h2 class="cb-stats-section">' . esc_html__( 'Breakdown by booking kind', 'commonsbooking' ) . '</h2>';
	}

	/**
	 * @param array<string, array<int, array<int, array{capacity: int, booked: int}>>> $heat
	 */
	private static function renderHeatmap( array $heat ): void {
		global $wp_locale;
		$wd = [];
		foreach ( [ 1, 2, 3, 4, 5, 6, 0 ] as $i ) {
			$wd[] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) );
		}
		$mo = [];
		for ( $m = 1; $m <= 12; $m++ ) {
			$mo[] = $wp_locale->get_month_abbrev( $wp_locale->get_month( $m ) );
		}
		foreach ( $heat as $unit => $cells ) {
			$matrix = [];
			$table  = [];
			foreach ( $wd as $r => $label ) {
				$row = [ $label ];
				for ( $c = 0; $c < 12; $c++ ) {
					$cell               = $cells[ $r ][ $c + 1 ] ?? null;
					$value              = $cell && $cell['capacity'] > 0 ? $cell['booked'] / $cell['capacity'] : null;
					$matrix[ $r ][ $c ] = $value;
					$row[]              = $value === null ? '–' : self::pctF( $value );
				}
				$table[] = $row;
			}
			echo '<section class="cb-stats-card"><h3>' . esc_html( sprintf( /* translators: %s: booking kind */ __( 'Utilisation by weekday and month (%s)', 'commonsbooking' ), self::unitName( $unit ) ) ) . '</h3>';
			echo Chart::heatmap(
				$wd,
				$mo,
				$matrix,
				[
					'title' => __( 'Utilisation by weekday and month', 'commonsbooking' ),
					'desc' => self::unitName( $unit ),
				]
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::details( array_merge( [ '' ], $mo ), $table );
			echo '</section>';
		}
	}

	/**
	 * @param array<string, mixed> $d
	 */
	private static function renderDistributions( array $d ): void {
		foreach ( $d['duration'] as $unit => $counts ) {
			$labels = $unit === 'day'
				? [ '1', '2', '3', '4–7', '8+' ]
				: [ '1', '2', '3–4', '5–8', '9+' ];
			echo '<section class="cb-stats-card"><h3>' . esc_html( sprintf( /* translators: %s: booking kind */ __( 'Booking duration (%s)', 'commonsbooking' ), self::unitName( $unit ) ) ) . '</h3>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: mean 2: median 3: unit */
					__( 'Mean %1$s, median %2$s %3$s', 'commonsbooking' ),
					number_format_i18n( $d['mean'][ $unit ], 1 ),
					number_format_i18n( $d['median'][ $unit ], 0 ),
					$unit === 'day' ? __( 'days', 'commonsbooking' ) : __( 'hours', 'commonsbooking' )
				)
			) . '</p>';
			echo Chart::columns(
				$labels,
				array_map( 'floatval', $counts ),
				null,
				[
					'title' => __( 'Booking duration', 'commonsbooking' ),
					'desc' => self::unitName( $unit ),
				]
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::details( [ __( 'Length', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ) ], array_map( null, $labels, array_map( 'number_format_i18n', $counts ) ) );
			echo '</section>';
		}

		$lead = [ __( '< 1 day', 'commonsbooking' ), __( '1–3 days', 'commonsbooking' ), __( '3–7 days', 'commonsbooking' ), __( '1–2 weeks', 'commonsbooking' ), __( '2–4 weeks', 'commonsbooking' ), __( '> 4 weeks', 'commonsbooking' ) ];
		if ( array_sum( $d['lead'] ) ) {
			echo '<section class="cb-stats-card"><h3>' . esc_html__( 'Lead time (booking to start)', 'commonsbooking' ) . '</h3>';
			echo Chart::columns( $lead, array_map( 'floatval', $d['lead'] ), null, [ 'title' => __( 'Lead time', 'commonsbooking' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::details( [ __( 'Lead time', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ) ], array_map( null, $lead, array_map( 'number_format_i18n', $d['lead'] ) ) );
			echo '</section>';
		}

		$cancel = [ __( '< 1 day before start', 'commonsbooking' ), __( '1–2 days', 'commonsbooking' ), __( '2–7 days', 'commonsbooking' ), __( '> 1 week', 'commonsbooking' ) ];
		if ( array_sum( $d['cancel'] ) ) {
			echo '<section class="cb-stats-card"><h3>' . esc_html__( 'Cancellation timing', 'commonsbooking' ) . '</h3>';
			echo Chart::columns( $cancel, array_map( 'floatval', $d['cancel'] ), null, [ 'title' => __( 'Cancellation timing', 'commonsbooking' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::details( [ __( 'Cancelled', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ) ], array_map( null, $cancel, array_map( 'number_format_i18n', $d['cancel'] ) ) );
			if ( $d['cancelUnknown'] ) {
				echo '<p>' . esc_html( sprintf( /* translators: %d: number of bookings */ __( '%d more cancelled bookings have no recorded cancellation time.', 'commonsbooking' ), $d['cancelUnknown'] ) ) . '</p>';
			}
			echo '</section>';
		}
	}

	/**
	 * Ranking table and chart per item or location.
	 *
	 * @param array<string, mixed> $request
	 */
	private static function renderRanking( Filters $f, Query $q, string $by, array $request ): void {
		if ( $f->unit === 'all' ) {
			self::renderRankingCombined( $f, $q, $by, $request );
			return;
		}
		$rows = $q->totalsBy( [ $f->from, $f->to ], $by );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No data for this selection.', 'commonsbooking' ) . '</p>';
			return;
		}
		$key = $by . '_id';

		$sorts = [
			'utilisation' => fn( $r ) => self::ratio( $r['booked'], $r['capacity'] ),
			'bookings'    => fn( $r ) => $r['started'],
			'idle'        => fn( $r ) => self::ratio( $r['idle_days'], $r['open_days'] ),
			'cancel'      => fn( $r ) => self::ratio( $r['canceled'], $r['started'] + $r['canceled'] ),
			'usage'       => fn( $r ) => $r['usage_days'],
		];
		$sort  = isset( $request['sort'] ) && isset( $sorts[ $request['sort'] ] ) ? $request['sort'] : 'utilisation';
		$dir   = ( $request['dir'] ?? 'desc' ) === 'asc' ? 'asc' : 'desc';
		usort( $rows, fn( $a, $b ) => $dir === 'asc' ? $sorts[ $sort ]( $a ) <=> $sorts[ $sort ]( $b ) : $sorts[ $sort ]( $b ) <=> $sorts[ $sort ]( $a ) );

		$multiUnit = count( array_unique( array_column( $rows, 'unit' ) ) ) > 1;
		$bars      = [];
		foreach ( array_slice( $rows, 0, 20 ) as $r ) {
			$bars[] = [
				'label' => self::title( $r[ $key ] ) . ( $multiUnit || $r['unit'] === 'hour' ? ' (' . self::unitName( $r['unit'] ) . ')' : '' ),
				'value' => self::ratio( $r['booked'], $r['capacity'] ),
			];
		}
		echo '<section class="cb-stats-card"><h2>' . esc_html( $by === 'item' ? __( 'Utilisation by item', 'commonsbooking' ) : __( 'Utilisation by location', 'commonsbooking' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Rates are formed within one booking kind only; items with both kinds appear twice. The chart shows the first 20 rows of the table.', 'commonsbooking' ) . '</p>';
		echo Chart::bars(
			$bars,
			[
				'percent' => true,
				'title' => __( 'Utilisation ranking', 'commonsbooking' ),
				'desc' => $f->from . ' – ' . $f->to,
			]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		$cols = [
			'utilisation' => __( 'Utilisation', 'commonsbooking' ),
			'bookings'    => __( 'Bookings', 'commonsbooking' ),
			'idle'        => __( 'Idle days', 'commonsbooking' ),
			'cancel'      => __( 'Cancellation rate', 'commonsbooking' ),
			'usage'       => __( 'Usage days', 'commonsbooking' ),
		];
		echo '<div class="cb-stats-card cb-stats-tablecard"><table class="widefat striped cb-stats-table"><thead><tr><th>' . esc_html( $by === 'item' ? __( 'Item', 'commonsbooking' ) : __( 'Location', 'commonsbooking' ) ) . '</th><th>' . esc_html__( 'Kind', 'commonsbooking' ) . '</th>';
		foreach ( $cols as $col => $label ) {
			$next = $sort === $col && $dir === 'desc' ? 'asc' : 'desc';
			printf(
				'<th class="num"><a href="%s">%s%s</a></th>',
				esc_url(
					self::url(
						array_merge(
							$f->toArgs(),
							[
								'view' => $by . 's',
								'sort' => $col,
								'dir' => $next,
							]
						)
					)
				),
				esc_html( $label ),
				$sort === $col ? ( $dir === 'desc' ? ' ▼' : ' ▲' ) : ''
			);
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
				esc_html( self::title( $r[ $key ] ) ),
				esc_html( self::unitName( $r['unit'] ) ),
				esc_html( self::pct( $r['booked'], $r['capacity'] ) ),
				esc_html( number_format_i18n( $r['started'] ) ),
				esc_html( self::pct( $r['idle_days'], $r['open_days'] ) ),
				esc_html( self::pct( $r['canceled'], $r['started'] + $r['canceled'] ) ),
				esc_html( number_format_i18n( $r['usage_days'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Ranking with one row per item or location over both booking kinds, plus the slot utilisation per kind.
	 *
	 * @param array<string, mixed> $request
	 */
	private static function renderRankingCombined( Filters $f, Query $q, string $by, array $request ): void {
		$range = [ $f->from, $f->to ];
		$rows  = $q->combinedBy( $range, $by );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No data for this selection.', 'commonsbooking' ) . '</p>';
			return;
		}
		$key = $by . '_id';
		$per = [];
		foreach ( $q->totalsBy( $range, $by ) as $r ) {
			$per[ $r[ $key ] ][ $r['unit'] ] = self::ratio( $r['booked'], $r['capacity'] );
		}

		$sorts = [
			'utilisation' => fn( $r ) => self::ratio( $r['usage_days'], $r['open_days'] ),
			'bookings'    => fn( $r ) => $r['started'],
			'idle'        => fn( $r ) => self::ratio( $r['open_days'] - $r['usage_days'], $r['open_days'] ),
			'cancel'      => fn( $r ) => self::ratio( $r['canceled'], $r['started'] + $r['canceled'] ),
			'usage'       => fn( $r ) => $r['usage_days'],
		];
		$sort  = isset( $request['sort'] ) && isset( $sorts[ $request['sort'] ] ) ? $request['sort'] : 'utilisation';
		$dir   = ( $request['dir'] ?? 'desc' ) === 'asc' ? 'asc' : 'desc';
		usort( $rows, fn( $a, $b ) => $dir === 'asc' ? $sorts[ $sort ]( $a ) <=> $sorts[ $sort ]( $b ) : $sorts[ $sort ]( $b ) <=> $sorts[ $sort ]( $a ) );

		$bars = [];
		foreach ( array_slice( $rows, 0, 20 ) as $r ) {
			$bars[] = [
				'label' => self::title( $r[ $key ] ),
				'value' => self::ratio( $r['usage_days'], $r['open_days'] ),
			];
		}
		echo '<section class="cb-stats-card"><h2>' . esc_html( $by === 'item' ? __( 'Day rate by item', 'commonsbooking' ) : __( 'Day rate by location', 'commonsbooking' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One row per item or location over full-day and hourly bookings together. The columns on the right show the slot utilisation of each kind separately. The chart shows the first 20 rows of the table.', 'commonsbooking' ) . '</p>';
		echo Chart::bars(
			$bars,
			[
				'percent' => true,
				'title' => __( 'Day rate ranking', 'commonsbooking' ),
				'desc' => $f->from . ' – ' . $f->to,
			]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		$cols = [
			'utilisation' => __( 'Day rate', 'commonsbooking' ),
			'bookings'    => __( 'Bookings', 'commonsbooking' ),
			'idle'        => __( 'Idle days', 'commonsbooking' ),
			'cancel'      => __( 'Cancellation rate', 'commonsbooking' ),
			'usage'       => __( 'Usage days', 'commonsbooking' ),
		];
		echo '<div class="cb-stats-card cb-stats-tablecard"><table class="widefat striped cb-stats-table"><thead><tr><th>' . esc_html( $by === 'item' ? __( 'Item', 'commonsbooking' ) : __( 'Location', 'commonsbooking' ) ) . '</th>';
		foreach ( $cols as $col => $label ) {
			$next = $sort === $col && $dir === 'desc' ? 'asc' : 'desc';
			printf(
				'<th class="num"><a href="%s">%s%s</a></th>',
				esc_url(
					self::url(
						array_merge(
							$f->toArgs(),
							[
								'view' => $by . 's',
								'sort' => $col,
								'dir' => $next,
							]
						)
					)
				),
				esc_html( $label ),
				$sort === $col ? ( $dir === 'desc' ? ' ▼' : ' ▲' ) : ''
			);
		}
		echo '<th class="num">' . esc_html__( 'Utilisation full-day', 'commonsbooking' ) . '</th><th class="num">' . esc_html__( 'Utilisation hourly', 'commonsbooking' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = $per[ $r[ $key ] ] ?? [];
			printf(
				'<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
				esc_html( self::title( $r[ $key ] ) ),
				esc_html( self::pct( $r['usage_days'], $r['open_days'] ) ),
				esc_html( number_format_i18n( $r['started'] ) ),
				esc_html( self::pct( $r['open_days'] - $r['usage_days'], $r['open_days'] ) ),
				esc_html( self::pct( $r['canceled'], $r['started'] + $r['canceled'] ) ),
				esc_html( number_format_i18n( $r['usage_days'] ) ),
				esc_html( isset( $u['day'] ) ? self::pctF( $u['day'] ) : '–' ),
				esc_html( isset( $u['hour'] ) ? self::pctF( $u['hour'] ) : '–' )
			);
		}
		echo '</tbody></table></div>';
	}

	private static function renderExport( Filters $f, string $view ): void {
		$url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$f->toArgs(),
					[
						'action' => Export::ACTION,
						'view' => $view,
					]
				),
				admin_url( 'admin-post.php' )
			),
			Export::ACTION
		);
		echo '<p class="cb-stats-export"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export as CSV', 'commonsbooking' ) . '</a> <span class="description">' . esc_html__( 'Exports the aggregated table of this view with the current filters.', 'commonsbooking' ) . '</span></p>';
	}

	// ---- helpers -------------------------------------------------------------------------------------------------

	/**
	 * Key figure with a short explanation behind the (i) button (native <details>, works without JavaScript).
	 *
	 * @param float|null $gauge share 0..1 for the small bar, null for none
	 */
	private static function tile( string $label, string $value, ?string $previous, string $info = '', ?float $gauge = null ): void {
		echo '<div class="cb-stats-tile"><div class="cb-stats-tile-head"><span class="cb-stats-tile-label">' . esc_html( $label ) . '</span>';
		if ( $info !== '' ) {
			echo '<details class="cb-stats-info"><summary aria-label="' . esc_attr( sprintf( /* translators: %s: name of the key figure */ __( 'Explanation: %s', 'commonsbooking' ), $label ) ) . '"><span aria-hidden="true">i</span></summary><div class="cb-stats-info-text">' . esc_html( $info ) . '</div></details>';
		}
		echo '</div><div class="cb-stats-tile-value">' . esc_html( $value ) . '</div>';
		if ( $gauge !== null ) {
			echo '<div class="cb-stats-gauge" aria-hidden="true"><div style="width:' . esc_attr( (string) round( max( 0, min( 1, $gauge ) ) * 100, 1 ) ) . '%"></div></div>';
		}
		if ( $previous !== null ) {
			echo '<div class="cb-stats-tile-prev">' . esc_html( sprintf( /* translators: %s: value of the comparison period */ __( 'before: %s', 'commonsbooking' ), $previous ) ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Collapsible table with the values of a chart.
	 *
	 * @param string[]                      $head
	 * @param array<int, array<int, mixed>> $rows
	 */
	private static function details( array $head, array $rows ): void {
		echo '<details class="cb-stats-details"><summary>' . esc_html__( 'Show values as table', 'commonsbooking' ) . '</summary><table class="widefat striped"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th>' . esc_html( (string) $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $cell ) {
				echo '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></details>';
	}

	/**
	 * @param array<int, array<string, mixed>> $series
	 *
	 * @return array<string, array<string, mixed>> rows of the unit by bucket
	 */
	private static function byBucket( array $series, string $unit ): array {
		$out = [];
		foreach ( $series as $row ) {
			if ( $row['unit'] === $unit ) {
				$out[ $row['bucket'] ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Bucket keys of a range as the SQL expression produces them.
	 *
	 * @return string[]
	 */
	public static function buckets( string $from, string $to, string $resolution ): array {
		$out = [];
		if ( $resolution === 'month' ) {
			$d = strtotime( date( 'Y-m-01', strtotime( $from ) ) );
			while ( date( 'Y-m-d', $d ) <= $to ) {
				$out[] = date( 'Y-m-d', $d );
				$d     = strtotime( '+1 month', $d );
			}
		} elseif ( $resolution === 'week' ) {
			$d = strtotime( $from . ' monday this week' );
			if ( date( 'Y-m-d', $d ) > $from ) {
				$d = strtotime( '-1 week', $d );
			}
			while ( date( 'Y-m-d', $d ) <= $to ) {
				$out[] = date( 'Y-m-d', $d );
				$d     = strtotime( '+1 week', $d );
			}
		} else {
			for ( $d = strtotime( $from ); date( 'Y-m-d', $d ) <= $to; $d = strtotime( '+1 day', $d ) ) {
				$out[] = date( 'Y-m-d', $d );
			}
		}

		return $out;
	}

	private static function bucketLabel( string $bucket, string $resolution ): string {
		return $resolution === 'month' ? substr( $bucket, 0, 7 ) : $bucket;
	}

	private static function ratio( $part, $whole ): float {
		return $whole > 0 ? $part / $whole : 0.0;
	}

	private static function pct( $part, $whole, int $decimals = 0 ): string {
		return $whole > 0 ? self::pctF( $part / $whole, $decimals ) : '–';
	}

	private static function pctF( float $ratio, int $decimals = 0 ): string {
		return number_format_i18n( $ratio * 100, $decimals ) . ' %';
	}

	private static function unitName( string $unit ): string {
		return $unit === 'day' ? __( 'full day', 'commonsbooking' ) : __( 'hourly', 'commonsbooking' );
	}

	private static function title( int $postId ): string {
		$title = get_the_title( $postId );

		return $title !== '' ? $title : '#' . $postId;
	}

	/**
	 * @return array<int, string> id => title
	 */
	private static function posts( string $postType ): array {
		$out = [];
		foreach ( get_posts(
			[
				'post_type' => $postType,
				'post_status' => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 1000,
				'orderby' => 'title',
				'order' => 'ASC',
				'no_found_rows' => true,
			]
		) as $post ) {
			$out[ $post->ID ] = $post->post_title;
		}

		return $out;
	}

	/**
	 * @return array<int, string> id => name
	 */
	private static function terms( string $taxonomy ): array {
		$out   = [];
		$terms = get_terms(
			[
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
			]
		);
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$out[ $term->term_id ] = $term->name;
		}

		return $out;
	}

	/**
	 * @param array<string, string> $options
	 */
	private static function select( string $name, array $options, string $current ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * @param array<int, string> $options id => label
	 * @param int[]              $current
	 * @param string             $label   name of the filter for the search field
	 */
	private static function multiSelect( string $name, array $options, array $current, string $label ): string {
		if ( ! $options ) {
			return '<em>–</em>';
		}
		$html = '<details class="cb-ms" data-all="' . esc_attr__( 'All', 'commonsbooking' ) . '" data-n="' . esc_attr__( '%d selected', 'commonsbooking' ) . '"><summary>' . esc_html__( 'All', 'commonsbooking' ) . '</summary><div class="cb-ms-panel">'
			. '<input type="search" class="cb-ms-search" placeholder="' . esc_attr__( 'Search …', 'commonsbooking' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: filter name */ __( 'Search: %s', 'commonsbooking' ), $label ) ) . '">'
			. '<div class="cb-ms-list">';
		foreach ( $options as $id => $text ) {
			$html .= '<label class="cb-ms-opt"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . (int) $id . '"' . ( in_array( (int) $id, $current, true ) ? ' checked' : '' ) . '> <span>' . esc_html( $text ) . '</span></label>';
		}

		return $html . '</div><button type="button" class="button-link cb-ms-clear">' . esc_html__( 'Clear selection', 'commonsbooking' ) . '</button></div></details>';
	}

	/**
	 * Search, selection counter, clear button and closing of the filter dropdowns. Without JavaScript the lists stay usable.
	 */
	private static function multiSelectScript(): void {
		?>
<script>
(function(){
	var boxes = document.querySelectorAll('.cb-ms');
	function count(b){
		var n = b.querySelectorAll('input[type=checkbox]:checked').length, s = b.querySelector('summary');
		s.textContent = n ? b.dataset.n.replace('%d', n) : b.dataset.all;
		s.classList.toggle('cb-ms-active', n > 0);
	}
	boxes.forEach(function(b){
		count(b);
		b.addEventListener('change', function(){ count(b); });
		b.querySelector('.cb-ms-search').addEventListener('input', function(e){
			var q = e.target.value.toLowerCase();
			b.querySelectorAll('.cb-ms-opt').forEach(function(o){ o.hidden = q && o.textContent.toLowerCase().indexOf(q) < 0; });
		});
		b.querySelector('.cb-ms-clear').addEventListener('click', function(){
			b.querySelectorAll('input[type=checkbox]').forEach(function(i){ i.checked = false; }); count(b);
		});
		b.addEventListener('toggle', function(){
			if (b.open) { boxes.forEach(function(o){ if (o !== b) { o.open = false; } }); b.querySelector('.cb-ms-search').focus(); }
		});
	});
	document.addEventListener('click', function(e){ boxes.forEach(function(b){ if (!b.contains(e.target)) { b.open = false; } }); });
	document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { boxes.forEach(function(b){ b.open = false; }); } });
})();
</script>
		<?php
	}

	private static function styles(): void {
		echo '<style>
.cb-stats{--cb-stats-a:#4f46e5;--cb-stats-b:#f59e0b;--cb-stats-ink:#1e293b;--cb-stats-mute:#64748b;--cb-stats-line:#e2e8f0;--cb-stats-bg:#f8fafc;max-width:1400px;color:var(--cb-stats-ink)}
.cb-stats h1{font-size:26px;font-weight:700;letter-spacing:-.02em;margin:18px 0 4px}
.cb-stats h2{font-size:18px;font-weight:650;margin:28px 0 8px}
.cb-stats h2.cb-stats-section{display:flex;align-items:center;gap:10px}
.cb-stats h2.cb-stats-section:before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(var(--cb-stats-a),#818cf8)}
.cb-stats h3{font-size:14px;font-weight:600;margin:0 0 12px;color:var(--cb-stats-ink)}
.cb-stats-card{background:#fff;border:1px solid var(--cb-stats-line);border-radius:14px;padding:18px 20px;margin:0 0 16px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 4px 14px rgba(15,23,42,.04)}
.cb-stats-card h2{margin-top:0}
.cb-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;align-items:start}
.cb-stats-filters{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:flex-end;margin:14px 0;background:#fff;border:1px solid var(--cb-stats-line);border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.cb-stats-filters label,.cb-stats-field{display:flex;flex-direction:column;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--cb-stats-mute);gap:4px}
.cb-stats-filters select,.cb-stats-filters input[type=date]{border-radius:8px;border-color:#cbd5e1;min-height:34px;font-size:13px;text-transform:none;letter-spacing:0;font-weight:400;color:var(--cb-stats-ink)}
.cb-stats-filters select{min-width:150px}
.cb-ms{position:relative;min-width:190px}
.cb-ms summary{list-style:none;cursor:pointer;border:1px solid #cbd5e1;border-radius:8px;min-height:34px;box-sizing:border-box;padding:0 30px 0 10px;display:flex;align-items:center;background:#fff url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath d=%27M2 4l4 4 4-4%27 fill=%27none%27 stroke=%27%2364748b%27 stroke-width=%271.6%27/%3E%3C/svg%3E") no-repeat right 10px center;font-size:13px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--cb-stats-ink)}
.cb-ms summary::-webkit-details-marker{display:none}
.cb-ms summary.cb-ms-active{border-color:var(--cb-stats-a);background-color:#eef2ff;color:var(--cb-stats-a);font-weight:600}
.cb-ms[open] summary{border-color:var(--cb-stats-a);box-shadow:0 0 0 1px var(--cb-stats-a)}
.cb-ms-panel{position:absolute;z-index:20;top:40px;left:0;width:280px;background:#fff;border:1px solid var(--cb-stats-line);border-radius:12px;padding:10px;box-shadow:0 10px 30px rgba(15,23,42,.18);text-transform:none;letter-spacing:0;font-weight:400}
.cb-ms-search{width:100%;box-sizing:border-box;border-radius:8px;margin-bottom:8px}
.cb-ms-list{max-height:220px;overflow:auto}
.cb-ms-opt{display:flex!important;flex-direction:row!important;align-items:center;gap:8px!important;padding:5px 6px;border-radius:6px;font-size:13px!important;text-transform:none!important;letter-spacing:0!important;font-weight:400!important;color:var(--cb-stats-ink)!important;cursor:pointer}
.cb-ms-opt:hover{background:#f1f5f9}
.cb-ms-opt[hidden]{display:none!important}
.cb-ms-opt input{margin:0}
.cb-ms-clear{margin-top:8px;font-size:12px}
.cb-stats-reset{border-radius:8px;min-height:34px;line-height:32px}
.cb-stats-filters .button{border-radius:8px;min-height:34px;background:var(--cb-stats-a);border-color:var(--cb-stats-a);color:#fff}
.cb-stats-filters .button:hover{background:#4338ca;border-color:#4338ca;color:#fff}
.cb-stats .nav-tab-wrapper{border:0;display:inline-flex;gap:4px;background:#e2e8f0;border-radius:12px;padding:4px;margin:4px 0 12px}
.cb-stats .nav-tab{border:0;border-radius:9px;background:transparent;margin:0;padding:7px 18px;font-size:13px;font-weight:600;color:var(--cb-stats-mute)}
.cb-stats .nav-tab-active,.cb-stats .nav-tab-active:hover{background:#fff;color:var(--cb-stats-a);box-shadow:0 1px 3px rgba(15,23,42,.15)}
.cb-stats-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin:8px 0 18px}
.cb-stats-tile{position:relative;background:#fff;border:1px solid var(--cb-stats-line);border-radius:14px;padding:14px 16px 14px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 4px 14px rgba(15,23,42,.04)}
.cb-stats-tile-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.cb-stats-tile-label{font-size:12px;font-weight:600;color:var(--cb-stats-mute);text-transform:uppercase;letter-spacing:.04em}
.cb-stats-tile-value{font-size:32px;font-weight:700;letter-spacing:-.02em;line-height:1.2;margin-top:6px}
.cb-stats-tile-prev{font-size:12px;color:var(--cb-stats-mute);margin-top:4px}
.cb-stats-gauge{height:6px;border-radius:3px;background:#e2e8f0;margin-top:8px;overflow:hidden}
.cb-stats-gauge div{height:100%;border-radius:3px;background:linear-gradient(90deg,#818cf8,var(--cb-stats-a))}
.cb-stats-info summary{list-style:none;cursor:pointer;width:20px;height:20px;border-radius:50%;background:#eef2ff;color:var(--cb-stats-a);font:italic 700 12px/20px Georgia,serif;text-align:center;user-select:none}
.cb-stats-info summary::-webkit-details-marker{display:none}
.cb-stats-info summary:hover,.cb-stats-info[open] summary{background:var(--cb-stats-a);color:#fff}
.cb-stats-info summary:focus-visible{outline:2px solid var(--cb-stats-a);outline-offset:2px}
.cb-stats-info{position:static}
.cb-stats-info-text{position:absolute;left:8px;right:8px;top:42px;z-index:5;padding:10px 12px;border-radius:10px;background:#1e293b;color:#f8fafc;box-shadow:0 8px 24px rgba(15,23,42,.25);font-size:12px;line-height:1.5;font-weight:400;text-transform:none;letter-spacing:0}
.cb-stats .num{text-align:right}
.cb-stats-details{margin:10px 0 0}
.cb-stats-details summary{cursor:pointer;color:var(--cb-stats-a);font-size:12px;font-weight:600}
.cb-stats svg{display:block;color:var(--cb-stats-mute);background:transparent;border:0}
.cb-stats-tablecard{padding:0;overflow:hidden}
.cb-stats-table{border:0;box-shadow:none;margin:0}
.cb-stats-table thead th{background:var(--cb-stats-bg);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--cb-stats-mute);padding:12px 14px}
.cb-stats-table td{padding:10px 14px}
.cb-stats-table th a{text-decoration:none;color:inherit}
.cb-stats-export{margin-top:18px}
.cb-stats-export .button{border-radius:8px}
.cb-stats-progress{border-radius:14px}
</style>';
	}
}
