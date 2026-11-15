<?php

namespace CommonsBooking\Service\Stats;

/**
 * Filters of the dashboard. They live in the URL (GET), so views can be linked, and apply to every view and to the
 * CSV export.
 */
class Filters {

	public const PRESETS     = [ '30d', '12m', 'year', 'lastyear', 'custom' ];
	public const RESOLUTIONS = [ 'day', 'week', 'month' ];
	public const UNITS       = [ 'all', 'day', 'hour' ];
	public const COMPARE     = [ 'none', 'previous', 'year' ];

	/** Maximum length of the period in days. */
	public const MAX_DAYS = 1830;

	public string $preset     = '12m';
	public string $from       = '';
	public string $to         = '';
	public string $compare    = 'none';
	public string $resolution = 'month';
	public string $unit       = 'all';

	/** @var int[] */
	public array $items = [];
	/** @var int[] */
	public array $locations = [];
	/** @var int[] */
	public array $itemCategories = [];
	/** @var int[] */
	public array $locationCategories = [];

	/**
	 * @param array $request usually $_GET
	 *
	 * @return self
	 */
	public static function fromRequest( array $request ): self {
		$f     = new self();
		$today = date( 'Y-m-d' );

		$preset    = (string) ( $request['preset'] ?? '12m' );
		$f->preset = in_array( $preset, self::PRESETS, true ) ? $preset : '12m';
		switch ( $f->preset ) {
			case '30d':
				$f->from = date( 'Y-m-d', strtotime( $today . ' -29 days' ) );
				$f->to   = $today;
				break;
			case 'year':
				$f->from = date( 'Y-01-01' );
				$f->to   = $today;
				break;
			case 'lastyear':
				$f->from = ( (int) date( 'Y' ) - 1 ) . '-01-01';
				$f->to   = ( (int) date( 'Y' ) - 1 ) . '-12-31';
				break;
			case 'custom':
				$f->from = self::date( $request['from'] ?? '', date( 'Y-m-d', strtotime( $today . ' -1 year +1 day' ) ) );
				$f->to   = self::date( $request['to'] ?? '', $today );
				break;
			default:
				$f->from = date( 'Y-m-d', strtotime( $today . ' -1 year +1 day' ) );
				$f->to   = $today;
		}
		$f->to = min( $f->to, $today );
		if ( $f->from > $f->to ) {
			$f->from = $f->to;
		}
		if ( strtotime( $f->to ) - strtotime( $f->from ) > self::MAX_DAYS * 86400 ) {
			$f->from = date( 'Y-m-d', strtotime( $f->to . ' -' . self::MAX_DAYS . ' days' ) );
		}

		$f->compare    = self::enum( $request['compare'] ?? 'none', self::COMPARE, 'none' );
		$f->resolution = self::enum( $request['resolution'] ?? 'month', self::RESOLUTIONS, 'month' );
		// 'both' is the old name of 'all' (links from before)
		$unit    = $request['unit'] ?? 'all';
		$f->unit = self::enum( $unit === 'both' ? 'all' : $unit, self::UNITS, 'all' );

		$f->items              = self::ids( $request['items'] ?? [] );
		$f->locations          = self::ids( $request['locations'] ?? [] );
		$f->itemCategories     = self::ids( $request['item_cat'] ?? [] );
		$f->locationCategories = self::ids( $request['location_cat'] ?? [] );

		return $f;
	}

	/**
	 * Query arguments that reproduce these filters.
	 *
	 * @return array<string, mixed>
	 */
	public function toArgs(): array {
		$args = [
			'preset'     => $this->preset,
			'resolution' => $this->resolution,
			'compare'    => $this->compare,
			'unit'       => $this->unit,
		];
		if ( $this->preset === 'custom' ) {
			$args['from'] = $this->from;
			$args['to']   = $this->to;
		}
		foreach ( [
			'items' => $this->items,
			'locations' => $this->locations,
			'item_cat' => $this->itemCategories,
			'location_cat' => $this->locationCategories,
		] as $key => $ids ) {
			if ( $ids ) {
				$args[ $key ] = $ids;
			}
		}

		return $args;
	}

	/**
	 * Period to compare with, null without comparison.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public function comparisonRange(): ?array {
		if ( $this->compare === 'year' ) {
			return [ date( 'Y-m-d', strtotime( $this->from . ' -1 year' ) ), date( 'Y-m-d', strtotime( $this->to . ' -1 year' ) ) ];
		}
		if ( $this->compare === 'previous' ) {
			$days = (int) round( ( strtotime( $this->to ) - strtotime( $this->from ) ) / 86400 ) + 1;

			return [ date( 'Y-m-d', strtotime( $this->from . ' -' . $days . ' days' ) ), date( 'Y-m-d', strtotime( $this->from . ' -1 day' ) ) ];
		}

		return null;
	}

	private static function date( $value, string $default ): string {
		$value = is_string( $value ) ? $value : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && strtotime( $value ) !== false && date( 'Y-m-d', strtotime( $value ) ) === $value ) {
			return $value;
		}

		return $default;
	}

	private static function enum( $value, array $allowed, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * @param mixed $value
	 *
	 * @return int[]
	 */
	private static function ids( $value ): array {
		$value = is_array( $value ) ? $value : [ $value ];

		return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
	}
}
