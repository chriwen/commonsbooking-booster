<?php

namespace CommonsBooking\View\Admin\Stats;

use CommonsBooking\Service\Stats\Filters;
use CommonsBooking\Service\Stats\Query;

/**
 * CSV export of the aggregated table of a view, with the current filters. Never contains single bookings.
 *
 * Format: UTF-8 with BOM, semicolon, decimal comma, ISO dates (filter `commonsbooking_stats_csv_decimal` changes the
 * decimal separator). Cells that start with = + - @ are neutralised against formula injection.
 */
class Export {

	public const ACTION = 'commonsbooking_stats_export';

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * @return void
	 */
	public static function handle(): void {
		if ( ! commonsbooking_isCurrentUserAdmin() ) {
			wp_die( esc_html__( 'You are not allowed to export statistics.', 'commonsbooking' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );

		$request = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above
		$view    = in_array( $request['view'] ?? '', [ 'overview', 'items', 'locations' ], true ) ? $request['view'] : 'overview';
		$filters = Filters::fromRequest( $request );
		$rows    = self::rows( $view, $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'commonsbooking-statistics-' . $view . '-' . $filters->from . '-' . $filters->to . '.csv' ) . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fwrite( $out, self::line( $row ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Header and data rows of a view.
	 *
	 * @param string  $view overview (period x location x item), items, locations
	 * @param Filters $filters
	 *
	 * @return array<int, array<int, string|int|float|null>>
	 */
	public static function rows( string $view, Filters $filters ): array {
		$query = new Query( $filters );
		$range = [ $filters->from, $filters->to ];
		$head  = [ __( 'Unit', 'commonsbooking' ), __( 'Capacity', 'commonsbooking' ), __( 'Booked slots', 'commonsbooking' ), __( 'Utilisation rate', 'commonsbooking' ), __( 'Bookings', 'commonsbooking' ), __( 'Cancellations', 'commonsbooking' ), __( 'Usage days', 'commonsbooking' ), __( 'Failure rate', 'commonsbooking' ) ];

		if ( $view === 'items' ) {
			$data  = $query->totalsBy( $range, 'item' );
			$lead  = [ __( 'Item', 'commonsbooking' ) ];
			$names = fn( $r ) => [ self::title( $r['item_id'] ) ];
		} elseif ( $view === 'locations' ) {
			$data  = $query->totalsBy( $range, 'location' );
			$lead  = [ __( 'Location', 'commonsbooking' ) ];
			$names = fn( $r ) => [ self::title( $r['location_id'] ) ];
		} else {
			$data  = $query->seriesByPair( $range );
			$lead  = [ __( 'Period', 'commonsbooking' ), __( 'Location', 'commonsbooking' ), __( 'Item', 'commonsbooking' ) ];
			$names = fn( $r ) => [ $r['bucket'], self::title( $r['location_id'] ), self::title( $r['item_id'] ) ];
		}

		$rows = [ array_merge( $lead, $head ) ];
		foreach ( $data as $r ) {
			$rows[] = array_merge(
				$names( $r ),
				[
					$r['unit'] === 'day' ? __( 'full day', 'commonsbooking' ) : __( 'hourly', 'commonsbooking' ),
					$r['capacity'],
					$r['booked'],
					$r['capacity'] > 0 ? round( $r['booked'] / $r['capacity'], 4 ) : null,
					$r['started'],
					$r['canceled'],
					$r['usage_days'],
					$r['capacity'] + $r['outage'] > 0 ? round( $r['failure'] / ( $r['capacity'] + $r['outage'] ), 4 ) : null,
				]
			);
		}

		return $rows;
	}

	/**
	 * One CSV line: strings are quoted and neutralised, numbers use the configured decimal separator.
	 *
	 * @param array<int, string|int|float|null> $row
	 *
	 * @return string
	 */
	public static function line( array $row ): string {
		/**
		 * Decimal separator of the CSV export, "," opens directly in German spreadsheet programs.
		 *
		 * @param string $separator
		 */
		$decimal = (string) apply_filters( 'commonsbooking_stats_csv_decimal', ',' );
		$cells   = [];
		foreach ( $row as $cell ) {
			if ( $cell === null ) {
				$cells[] = '';
			} elseif ( is_int( $cell ) ) {
				$cells[] = (string) $cell;
			} elseif ( is_float( $cell ) ) {
				$cells[] = str_replace( '.', $decimal, (string) $cell );
			} else {
				$cell = (string) $cell;
				if ( $cell !== '' && strpbrk( $cell[0], '=+-@' ) !== false && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cell ) ) {
					$cell = "'" . $cell;
				}
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
		}

		return implode( ';', $cells ) . "\r\n";
	}

	private static function title( int $postId ): string {
		$title = get_the_title( $postId );

		return $title !== '' ? $title : '#' . $postId;
	}
}
