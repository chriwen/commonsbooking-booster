<?php

namespace CommonsBooking\Service\Index;

/**
 * Detects and repairs differences between the posts and the index.
 *
 * Safety net for writes that bypass the WordPress hooks (direct SQL, imports, aborted requests).
 * A daily cron run checks a random sample, `wp commonsbooking index verify` can check everything.
 */
class Verifier {

	public const CRON_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_index_verify';

	public const CRON_SAMPLE_SIZE = 200;

	/**
	 * Schedules the daily verification.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( Schema::isInstalled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:00' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: verifies and repairs a random sample.
	 *
	 * @return void
	 */
	public static function runCron(): void {
		if ( ! Backfill::isDone() ) {
			return;
		}
		$result = self::verify( self::CRON_SAMPLE_SIZE, true );
		\CommonsBooking\Service\Booster\Diagnostics::recordCheck(
			'index_verify',
			[
				'checked'    => $result['checked'],
				'mismatches' => count( $result['mismatches'] ),
				'orphans'    => $result['orphans'],
			]
		);
		if ( $result['mismatches'] ) {
			commonsbooking_write_log(
				sprintf(
					'Index verification repaired %d of %d checked posts (ids: %s), removed %d orphaned rows.',
					count( $result['mismatches'] ),
					$result['checked'],
					implode( ',', array_keys( $result['mismatches'] ) ),
					$result['orphans']
				)
			);
		}
	}

	/**
	 * Compares posts with their index rows.
	 *
	 * @param int|null $sampleSize null = all indexable posts
	 * @param bool     $repair re-project posts with differences and remove orphaned rows
	 *
	 * @return array{checked: int, mismatches: array<int, array>, orphans: int}
	 */
	public static function verify( ?int $sampleSize = null, bool $repair = false ): array {
		global $wpdb;

		$postTypes  = Projector::getPostTypes();
		$typeMarker = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );
		$order      = $sampleSize ? 'ORDER BY RAND() LIMIT ' . (int) $sampleSize : 'ORDER BY ID';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql     = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($typeMarker) $order", $postTypes );
		$postIds = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$mismatches = [];
		foreach ( array_chunk( $postIds, 500 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $postId ) {
				$diff = Projector::diff( $postId );
				if ( $diff !== null ) {
					$mismatches[ $postId ] = $diff;
					if ( $repair ) {
						Projector::project( $postId );
					}
				}
			}
		}

		// rows whose post does not exist anymore
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphans = array_map( 'intval', $wpdb->get_col( "SELECT i.post_id FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL" ) );
		if ( $repair ) {
			foreach ( $orphans as $postId ) {
				Projector::remove( $postId );
			}
		}

		return [
			'checked'    => count( $postIds ),
			'mismatches' => $mismatches,
			'orphans'    => count( $orphans ),
		];
	}
}
