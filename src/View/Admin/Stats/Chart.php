<?php

namespace CommonsBooking\View\Admin\Stats;

/**
 * Server-rendered inline SVG charts for the dashboard. Pure functions from data to SVG text, no JavaScript.
 *
 * Colours come from CSS variables with fixed fallbacks (a colour-blind-safe pair), text uses currentColor so dark
 * admin colour schemes work. Every chart has <title> and <desc>; the page puts the same values as a table beneath it.
 */
class Chart {

	private const WIDTH = 720;

	/**
	 * Columns over categories (time, histogram buckets), optionally with a second series (e.g. previous period).
	 *
	 * @param string[]             $labels category labels
	 * @param float[]              $values values of the series
	 * @param float[]|null         $compare values of the comparison, same length
	 * @param array<string, mixed> $options title, desc, percent (bool), compareLabel, valueLabel
	 *
	 * @return string
	 */
	public static function columns( array $labels, array $values, ?array $compare = null, array $options = [] ): string {
		$percent = ! empty( $options['percent'] );
		$n       = count( $labels );
		$height  = 260;
		$left    = 44;
		$bottom  = 44;
		$top     = 12;
		$plotW   = self::WIDTH - $left - 8;
		$plotH   = $height - $top - $bottom;
		$max     = max( 0.0001, $percent ? 1.0 : max( array_merge( $values, $compare ?? [], [ 0 ] ) ) );
		$max     = self::niceMax( $max, $percent );
		$series  = $compare !== null ? 2 : 1;

		$svg  = self::open( $height, $options );
		$svg .= self::grid( $left, $top, $plotW, $plotH, $max, $percent );

		if ( $n > 0 ) {
			$slot = $plotW / $n;
			$barW = max( 1.0, ( $slot * 0.8 ) / $series );
			$step = max( 1, (int) ceil( $n / 12 ) );
			foreach ( $labels as $i => $label ) {
				$x0 = $left + $i * $slot + $slot * 0.1;
				foreach ( array_filter(
					[
						0 => $values[ $i ] ?? 0,
						1 => $compare[ $i ] ?? null,
					],
					fn( $v ) => $v !== null
				) as $k => $value ) {
					$h    = $plotH * min( 1, $value / $max );
					$x    = $x0 + $k * $barW;
					$fill = $k === 0 ? 'var(--cb-stats-a,#4f46e5)' : 'var(--cb-stats-b,#f59e0b)';
					$text = $label . ': ' . self::format( $value, $percent ) . ( $k === 1 ? ' (' . ( $options['compareLabel'] ?? '' ) . ')' : '' );
					$svg .= sprintf(
						'<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="3" fill="%s"><title>%s</title></rect>',
						$x,
						$top + $plotH - $h,
						$barW,
						$h,
						$fill,
						esc_html( $text )
					);
				}
				if ( $i % $step === 0 ) {
					$svg .= sprintf(
						'<text x="%.2f" y="%d" font-size="11" text-anchor="middle" fill="currentColor">%s</text>',
						$left + $i * $slot + $slot / 2,
						$top + $plotH + 16,
						esc_html( $label )
					);
				}
			}
		}

		return $svg . self::legend( $compare !== null, $options, $height ) . '</svg>';
	}

	/**
	 * Horizontal bars for rankings. Shows the first entries only.
	 *
	 * @param array<int, array{label: string, value: float}> $rows
	 * @param array<string, mixed>                           $options title, desc, percent, limit
	 *
	 * @return string
	 */
	public static function bars( array $rows, array $options = [] ): string {
		$percent = ! empty( $options['percent'] );
		$rows    = array_slice( $rows, 0, (int) ( $options['limit'] ?? 20 ) );
		$rowH    = 22;
		$labelW  = 230;
		$height  = max( 40, count( $rows ) * $rowH + 12 );
		$plotW   = self::WIDTH - $labelW - 60;
		$max     = max( 0.0001, $percent ? 1.0 : max( array_merge( array_column( $rows, 'value' ), [ 0 ] ) ) );

		$svg = self::open( $height, $options );
		foreach ( $rows as $i => $row ) {
			$y     = 6 + $i * $rowH;
			$w     = $plotW * min( 1, $row['value'] / $max );
			$label = mb_strlen( $row['label'] ) > 34 ? mb_substr( $row['label'], 0, 33 ) . '…' : $row['label'];
			$svg  .= sprintf( '<text x="%d" y="%d" font-size="12" text-anchor="end" fill="currentColor">%s<title>%s</title></text>', $labelW - 8, $y + 13, esc_html( $label ), esc_html( $row['label'] ) );
			$svg  .= sprintf( '<rect x="%d" y="%d" width="%.2f" height="%d" rx="4" fill="var(--cb-stats-a,#4f46e5)"><title>%s</title></rect>', $labelW, $y, $w, $rowH - 6, esc_html( $row['label'] . ': ' . self::format( $row['value'], $percent ) ) );
			$svg  .= sprintf( '<text x="%.2f" y="%d" font-size="12" fill="currentColor">%s</text>', $labelW + $w + 6, $y + 13, esc_html( self::format( $row['value'], $percent ) ) );
		}

		return $svg . '</svg>';
	}

	/**
	 * Heatmap of ratios 0..1 (rows e.g. weekdays, columns e.g. months). Cells carry their value as text.
	 *
	 * @param string[]                           $rowLabels
	 * @param string[]                           $colLabels
	 * @param array<int, array<int, float|null>> $matrix [row][col], null = no data
	 * @param array<string, mixed>               $options title, desc
	 *
	 * @return string
	 */
	public static function heatmap( array $rowLabels, array $colLabels, array $matrix, array $options = [] ): string {
		$cellW  = 52;
		$cellH  = 26;
		$left   = 44;
		$top    = 24;
		$height = $top + count( $rowLabels ) * $cellH + 6;
		$svg    = self::open( $height, $options );
		// colour depth is relative to the highest cell, the cells carry their absolute values as text
		$peak = 0.0;
		foreach ( $matrix as $row ) {
			foreach ( $row as $v ) {
				$peak = max( $peak, (float) $v );
			}
		}
		$peak = max( 0.0001, $peak );

		foreach ( $colLabels as $c => $label ) {
			$svg .= sprintf( '<text x="%d" y="16" font-size="11" text-anchor="middle" fill="currentColor">%s</text>', $left + $c * $cellW + $cellW / 2, esc_html( $label ) );
		}
		foreach ( $rowLabels as $r => $label ) {
			$y    = $top + $r * $cellH;
			$svg .= sprintf( '<text x="%d" y="%d" font-size="11" text-anchor="end" fill="currentColor">%s</text>', $left - 6, $y + 17, esc_html( $label ) );
			foreach ( $colLabels as $c => $colLabel ) {
				$value = $matrix[ $r ][ $c ] ?? null;
				$x     = $left + $c * $cellW;
				if ( $value === null ) {
					$svg .= sprintf( '<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="currentColor" stroke-opacity=".15"><title>%s</title></rect>', $x, $y, $cellW - 2, $cellH - 2, esc_html( $label . ' / ' . $colLabel . ': –' ) );
					continue;
				}
				$alpha = round( 0.08 + 0.92 * min( 1, max( 0, $value / $peak ) ), 2 );
				$svg  .= sprintf( '<rect x="%d" y="%d" width="%d" height="%d" rx="4" fill="var(--cb-stats-a,#4f46e5)" fill-opacity="%s"><title>%s</title></rect>', $x, $y, $cellW - 2, $cellH - 2, $alpha, esc_html( $label . ' / ' . $colLabel . ': ' . self::format( $value, true ) ) );
				$svg  .= sprintf( '<text x="%d" y="%d" font-size="11" text-anchor="middle" fill="%s">%s</text>', $x + ( $cellW - 2 ) / 2, $y + 17, $alpha > 0.55 ? '#fff' : 'currentColor', esc_html( self::format( $value, true ) ) );
			}
		}

		return $svg . '</svg>';
	}

	/**
	 * Formats a value; a ratio is shown as percent with the site's decimal separator.
	 *
	 * @param float $value
	 * @param bool  $percent
	 *
	 * @return string
	 */
	public static function format( float $value, bool $percent ): string {
		return $percent
			? number_format_i18n( $value * 100, 0 ) . ' %'
			: number_format_i18n( $value, $value == floor( $value ) ? 0 : 1 );
	}

	private static function open( int $height, array $options ): string {
		static $n = 0;
		++$n;
		$title = (string) ( $options['title'] ?? '' );
		$desc  = (string) ( $options['desc'] ?? '' );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-labelledby="cb-stats-t%d cb-stats-d%d" style="max-width:%dpx;height:auto"><title id="cb-stats-t%d">%s</title><desc id="cb-stats-d%d">%s</desc>',
			self::WIDTH,
			$height,
			$n,
			$n,
			self::WIDTH,
			$n,
			esc_html( $title ),
			$n,
			esc_html( $desc )
		);
	}

	private static function grid( int $left, int $top, float $plotW, float $plotH, float $max, bool $percent ): string {
		$svg = '';
		for ( $i = 0; $i <= 4; $i++ ) {
			$y    = $top + $plotH - $plotH * $i / 4;
			$svg .= sprintf( '<line x1="%d" y1="%.2f" x2="%.2f" y2="%.2f" stroke="currentColor" stroke-opacity=".15"/>', $left, $y, $left + $plotW, $y );
			$svg .= sprintf( '<text x="%d" y="%.2f" font-size="11" text-anchor="end" fill="currentColor">%s</text>', $left - 6, $y + 4, esc_html( self::format( $max * $i / 4, $percent ) ) );
		}

		return $svg;
	}

	private static function legend( bool $hasCompare, array $options, int $height ): string {
		if ( ! $hasCompare ) {
			return '';
		}

		return sprintf(
			'<rect x="44" y="%1$d" width="10" height="10" fill="var(--cb-stats-a,#4f46e5)"/><text x="58" y="%2$d" font-size="11" fill="currentColor">%3$s</text><rect x="200" y="%1$d" width="10" height="10" fill="var(--cb-stats-b,#f59e0b)"/><text x="214" y="%2$d" font-size="11" fill="currentColor">%4$s</text>',
			$height - 14,
			$height - 5,
			esc_html( (string) ( $options['valueLabel'] ?? '' ) ),
			esc_html( (string) ( $options['compareLabel'] ?? '' ) )
		);
	}

	private static function niceMax( float $max, bool $percent ): float {
		if ( $percent ) {
			return 1.0;
		}
		$pow  = pow( 10, floor( log10( $max ) ) );
		$norm = $max / $pow;
		foreach ( [ 1, 2, 4, 5, 10 ] as $step ) {
			if ( $norm <= $step ) {
				return $step * $pow;
			}
		}

		return 10 * $pow;
	}
}
