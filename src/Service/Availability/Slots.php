<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Helper\Wordpress;
use CommonsBooking\Model\Timeframe;
use stdClass;
use WP_Post;

/**
 * Availability slots for the API and GBFS ({@see \CommonsBooking\Model\Calendar::getAvailabilitySlots()}).
 *
 * One implementation for both ways:
 * - live: {@see self::bookableSlots()} on the day grid, then {@see self::toApiSlot()}
 * - precomputed: {@see Calculator} packs the bookable slots of each day with {@see self::pack()}, {@see SlotReader}
 *   unpacks them and applies the parts that depend on the current date (first bookable day).
 *
 * Packed format (little endian):
 *   header: uint16 number of timeframes T, then T × (uint32 timeframe id, uint32 location id, uint32 item id)
 *           location / item id 0 = timeframe has no location / item (empty string in the API)
 *   per day: uint8 number of slots S, then S × (uint32 start offset, uint32 end offset, uint16 timeframe index)
 *           offsets in seconds from strtotime( date ), the same base as {@see \CommonsBooking\Model\Day::getGrid()}
 */
class Slots {

	/**
	 * Returns the slots of a day grid whose most important timeframe is a bookable timeframe.
	 * Same condition as the API always used: the timeframe type decides, nothing else.
	 *
	 * @param array $grid slots from {@see \CommonsBooking\Model\Day::getGrid()}
	 *
	 * @return array<int, array{start: int, end: int, timeframe: WP_Post}>
	 */
	public static function bookableSlots( array $grid ): array {
		$slots = [];
		foreach ( $grid as $slot ) {
			$timeFrameType = get_post_meta( $slot['timeframe']->ID, 'type', true );
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- meta is a string
			if ( $timeFrameType != \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID ) {
				continue;
			}
			$slots[] = [
				'start'     => (int) $slot['timestampstart'],
				'end'       => (int) $slot['timestampend'],
				'timeframe' => $slot['timeframe'],
			];
		}

		return $slots;
	}

	/**
	 * Location and item id of a timeframe as the API shows them, 0 for none.
	 *
	 * @param Timeframe $timeframe
	 *
	 * @return array{0: int, 1: int} [locationId, itemId]
	 * @throws \Exception
	 */
	public static function getEntityIds( Timeframe $timeframe ): array {
		return [
			$timeframe->getLocation() ? (int) $timeframe->getLocationID() : 0,
			$timeframe->getItem() ? (int) $timeframe->getItemID() : 0,
		];
	}

	/**
	 * Builds the slot object of the API.
	 *
	 * @param int $start unix timestamp
	 * @param int $end unix timestamp
	 * @param int $locationId 0 for none
	 * @param int $itemId 0 for none
	 *
	 * @return stdClass
	 * @throws \Exception
	 */
	public static function toApiSlot( int $start, int $end, int $locationId, int $itemId ): stdClass {
		$availabilitySlot = new stdClass();

		$dateTimeStart = Wordpress::getUTCDateTime( 'now' );
		$dateTimeStart->setTimestamp( $start );
		$availabilitySlot->start = $dateTimeStart->format( 'Y-m-d\TH:i:sP' );

		$dateTimeEnd = Wordpress::getUTCDateTime( 'now' );
		$dateTimeEnd->setTimestamp( $end );
		$availabilitySlot->end = $dateTimeEnd->format( 'Y-m-d\TH:i:sP' );

		$availabilitySlot->locationId = $locationId ? (string) $locationId : '';
		$availabilitySlot->itemId     = $itemId ? (string) $itemId : '';

		return $availabilitySlot;
	}

	/**
	 * Adds slots to a list, skipping slots that are already in it (same start, end, location and item).
	 *
	 * @param stdClass[]          $slots list to add to
	 * @param array<string, bool> $done keys of the slots in the list
	 * @param stdClass            $slot
	 *
	 * @return void
	 */
	public static function addUnique( array &$slots, array &$done, stdClass $slot ): void {
		$slotId = md5( serialize( $slot ) );
		if ( ! isset( $done[ $slotId ] ) ) {
			$done[ $slotId ] = true;
			$slots[]         = $slot;
		}
	}

	/**
	 * Packs the bookable slots of consecutive days.
	 *
	 * @param array<int, array{date: string, slots: array}> $days in date order, slots from {@see self::bookableSlots()}
	 *
	 * @return string
	 * @throws \Exception
	 */
	public static function pack( array $days ): string {
		$timeframes = [];
		$body       = '';
		foreach ( $days as $day ) {
			$base  = strtotime( $day['date'] );
			$slots = array_slice( $day['slots'], 0, 255 );
			$body .= pack( 'C', count( $slots ) );
			foreach ( $slots as $slot ) {
				$timeframeId = (int) $slot['timeframe']->ID;
				if ( ! isset( $timeframes[ $timeframeId ] ) ) {
					[ $locationId, $itemId ]    = self::getEntityIds( new Timeframe( $slot['timeframe'] ) );
					$timeframes[ $timeframeId ] = [ count( $timeframes ), $locationId, $itemId ];
				}
				$body .= pack( 'VVv', $slot['start'] - $base, $slot['end'] - $base, $timeframes[ $timeframeId ][0] );
			}
		}

		$header = pack( 'v', count( $timeframes ) );
		foreach ( $timeframes as $timeframeId => [ , $locationId, $itemId ] ) {
			$header .= pack( 'VVV', $timeframeId, $locationId, $itemId );
		}

		return $header . $body;
	}

	/**
	 * Unpacks {@see self::pack()}.
	 *
	 * @param string $packed
	 *
	 * @return array{timeframes: array<int, array{0:int,1:int,2:int}>, days: array<int, array<int, array{0:int,1:int,2:int}>>}
	 *         timeframes: [timeframeId, locationId, itemId] by index; days: per day offset the slots as
	 *         [startOffset, endOffset, timeframe index]
	 */
	public static function unpack( string $packed ): array {
		$length = strlen( $packed );
		if ( $length < 2 ) {
			return [
				'timeframes' => [],
				'days'       => [],
			];
		}

		$pos        = 0;
		$count      = unpack( 'v', $packed, $pos )[1];
		$pos       += 2;
		$timeframes = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$entry        = unpack( 'Vid/Vlocation/Vitem', $packed, $pos );
			$timeframes[] = [ $entry['id'], $entry['location'], $entry['item'] ];
			$pos         += 12;
		}

		$days = [];
		while ( $pos < $length ) {
			$slotCount = unpack( 'C', $packed, $pos )[1];
			++$pos;
			$slots = [];
			for ( $i = 0; $i < $slotCount; $i++ ) {
				$slot    = unpack( 'Vstart/Vend/vtf', $packed, $pos );
				$slots[] = [ $slot['start'], $slot['end'], $slot['tf'] ];
				$pos    += 10;
			}
			$days[] = $slots;
		}

		return [
			'timeframes' => $timeframes,
			'days'       => $days,
		];
	}
}
