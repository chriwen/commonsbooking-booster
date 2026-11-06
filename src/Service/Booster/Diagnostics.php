<?php

namespace CommonsBooking\Service\Booster;

use CommonsBooking\Service\Availability\Availability;
use CommonsBooking\Service\Availability\Maintenance;
use CommonsBooking\Service\Availability\Store;
use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Projector;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Service\Index\Verifier;

/**
 * Collects what an administrator needs to see whether the booster works correctly:
 * coverage and freshness of the index, the latest bookings with their index state, an activity log of the
 * latest index changes, results of the scheduled checks and the next cron runs.
 *
 * Writes only two non-autoloaded options: the activity log (once per request with index changes) and the
 * results of the checks (once per check run).
 */
class Diagnostics {

	/**
	 * Latest index changes (ring buffer).
	 */
	public const ACTIVITY_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_booster_activity';

	/**
	 * Results of the latest check runs, by check name.
	 */
	public const CHECKS_OPTION = COMMONSBOOKING_PLUGIN_SLUG . '_booster_checks';

	public const ACTIVITY_SIZE = 30;

	/**
	 * Events of this request, written on shutdown.
	 *
	 * @var array<int, array>
	 */
	private static array $pending = [];

	private static bool $initialized = false;

	/**
	 * Registers the hooks (only while the booster is switched on).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( Projector::CHANGED_HOOK, array( self::class, 'onIndexChange' ), 20, 3 );
		add_action( 'shutdown', array( self::class, 'writeActivity' ), 5 );
	}

	/**
	 * Records an index change (rows of a post were created, changed or removed).
	 *
	 * @param array|null $old
	 * @param array|null $new
	 * @param int|null   $postId
	 *
	 * @return void
	 */
	public static function onIndexChange( $old, $new, $postId = null ): void {
		$row    = is_array( $new ) ? $new['row'] : ( is_array( $old ) ? $old['row'] : null );
		$postId = (int) ( $postId ?? ( $row['post_id'] ?? 0 ) );
		if ( ! $postId || ! is_array( $row ) ) {
			return;
		}
		if ( $old === null ) {
			$action = 'created';
		} elseif ( $new === null ) {
			$action = 'removed';
		} else {
			$action = 'changed';
		}
		self::$pending[ $postId ] = [
			'time'       => time(),
			'post_id'    => $postId,
			'post_type'  => (string) $row['post_type'],
			'action'     => isset( self::$pending[ $postId ] ) && self::$pending[ $postId ]['action'] === 'created' && $action === 'changed' ? 'created' : $action,
			'old_status' => is_array( $old ) ? (string) $old['row']['post_status'] : '',
			'new_status' => is_array( $new ) ? (string) $new['row']['post_status'] : '',
			'source'     => self::getSource(),
		];
	}

	/**
	 * Writes the events of this request into the ring buffer.
	 *
	 * @return void
	 */
	public static function writeActivity(): void {
		if ( ! self::$pending ) {
			return;
		}
		$log = get_option( self::ACTIVITY_OPTION, [] );
		$log = is_array( $log ) ? $log : [];
		foreach ( self::$pending as $event ) {
			array_unshift( $log, $event );
		}
		self::$pending = [];
		update_option( self::ACTIVITY_OPTION, array_slice( $log, 0, self::ACTIVITY_SIZE ), false );
	}

	/**
	 * @return array<int, array{time: int, post_id: int, post_type: string, action: string, old_status: string, new_status: string, source: string}>
	 */
	public static function getActivity(): array {
		$log = get_option( self::ACTIVITY_OPTION, [] );

		return is_array( $log ) ? $log : [];
	}

	/**
	 * Stores the result of a check run.
	 *
	 * @param string $name guard_deep|index_verify|availability_verify|availability_refresh
	 * @param array  $result
	 *
	 * @return void
	 */
	public static function recordCheck( string $name, array $result ): void {
		$checks          = get_option( self::CHECKS_OPTION, [] );
		$checks          = is_array( $checks ) ? $checks : [];
		$checks[ $name ] = array_merge( [ 'time' => time() ], $result );
		update_option( self::CHECKS_OPTION, $checks, false );
	}

	/**
	 * @return array<string, array>
	 */
	public static function getChecks(): array {
		$checks = get_option( self::CHECKS_OPTION, [] );

		return is_array( $checks ) ? $checks : [];
	}

	/**
	 * Runs all checks now (button on the settings page): quick guard checks, deep check, index sample,
	 * availability sample. Differences are repaired.
	 *
	 * @return array{rebuild: ?string, deep: array, index: array, availability: array}
	 */
	public static function runAllChecks(): array {
		$result = [
			'rebuild'      => Guard::check(),
			'deep'         => [],
			'index'        => [],
			'availability' => [],
		];
		if ( $result['rebuild'] !== null || ! Backfill::isDone() ) {
			return $result;
		}
		$result['deep']  = Guard::deepCheck();
		$result['index'] = Verifier::verify( Verifier::CRON_SAMPLE_SIZE, true );
		self::recordCheck(
			'index_verify',
			[
				'checked'    => $result['index']['checked'],
				'mismatches' => count( $result['index']['mismatches'] ),
				'orphans'    => $result['index']['orphans'],
			]
		);
		if ( Availability::isEnabled() ) {
			$result['availability'] = Maintenance::verify( Maintenance::VERIFY_SAMPLE_SIZE, true );
			self::recordCheck(
				'availability_verify',
				[
					'checked'    => $result['availability']['checked'],
					'mismatches' => count( $result['availability']['mismatches'] ),
				]
			);
		}

		return $result;
	}

	/**
	 * Number of posts per indexed post type in WordPress and in the index, newest change on both sides.
	 *
	 * @return array{types: array<string, array{posts: int, index: int}>, newest_post: ?string, newest_index: ?string}
	 */
	public static function getCoverage(): array {
		global $wpdb;
		$table      = IndexSchema::table();
		$postTypes  = Projector::getPostTypes();
		$typeMarker = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );

		$types = array_fill_keys(
			$postTypes,
			[
				'posts' => 0,
				'index' => 0,
			]
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_type, COUNT(*) AS n, MAX(post_modified_gmt) AS newest FROM {$wpdb->posts} WHERE post_type IN ($typeMarker) AND post_status <> 'auto-draft' GROUP BY post_type", $postTypes ),
			ARRAY_A
		);
		$index = IndexSchema::isInstalled()
			? $wpdb->get_results( "SELECT post_type, COUNT(*) AS n, MAX(source_modified_gmt) AS newest FROM $table GROUP BY post_type", ARRAY_A )
			: [];
		// phpcs:enable

		$newestPost  = null;
		$newestIndex = null;
		foreach ( $posts as $row ) {
			$types[ $row['post_type'] ]['posts'] = (int) $row['n'];
			$newestPost                          = max( $newestPost ?? '', (string) $row['newest'] );
		}
		foreach ( $index as $row ) {
			if ( isset( $types[ $row['post_type'] ] ) ) {
				$types[ $row['post_type'] ]['index'] = (int) $row['n'];
			}
			$newestIndex = max( $newestIndex ?? '', (string) $row['newest'] );
		}

		return [
			'types'        => $types,
			'newest_post'  => $newestPost ?: null,
			'newest_index' => $newestIndex ?: null,
		];
	}

	/**
	 * The latest created, changed or cancelled bookings with their state in WordPress and in the index.
	 *
	 * @param int $limit
	 *
	 * @return array<int, array{id: int, title: string, item: string, location: string, start: ?int, end: ?int, status: string, index_status: ?string, changed: int}>
	 */
	public static function getLatestBookings( int $limit = 8 ): array {
		global $wpdb;
		$table = IndexSchema::table();

		// cancellations do not update the modification date (direct SQL), they store a cancellation time
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$byModified = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'cb_booking' AND post_status <> 'auto-draft' ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d", $limit )
		);
		$byCancel   = $wpdb->get_col(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'cancellation_time' ORDER BY CAST(meta_value AS UNSIGNED) DESC LIMIT %d", $limit )
		);
		$ids        = array_values( array_unique( array_map( 'intval', array_merge( $byModified, $byCancel ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$idList = implode( ',', $ids );
		$sql    = IndexSchema::isInstalled()
			? "SELECT p.ID, p.post_title, p.post_status, p.post_modified_gmt, i.post_status AS index_status, i.item_id, i.location_id, i.start_ts, i.end_ts
				FROM {$wpdb->posts} p LEFT JOIN $table i ON i.post_id = p.ID WHERE p.ID IN ($idList)"
			: "SELECT ID, post_title, post_status, post_modified_gmt, NULL AS index_status, NULL AS item_id, NULL AS location_id, NULL AS start_ts, NULL AS end_ts
				FROM {$wpdb->posts} WHERE ID IN ($idList)";
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		_prime_post_caches( $ids, false, true );
		$bookings = [];
		foreach ( $rows as $row ) {
			$id        = (int) $row['ID'];
			$changed   = (int) strtotime( $row['post_modified_gmt'] . ' UTC' );
			$cancelled = (int) get_post_meta( $id, 'cancellation_time', true );
			if ( $cancelled ) {
				// stored as local time "timestamp" (current_time( 'timestamp' ))
				$changed = max( $changed, $cancelled - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			}
			$itemId     = (int) ( $row['item_id'] ?: get_post_meta( $id, 'item-id', true ) );
			$locationId = (int) ( $row['location_id'] ?: get_post_meta( $id, 'location-id', true ) );
			$start      = $row['start_ts'] !== null ? (int) $row['start_ts'] : (int) get_post_meta( $id, 'repetition-start', true );
			$end        = $row['end_ts'] !== null ? (int) $row['end_ts'] : (int) get_post_meta( $id, 'repetition-end', true );

			$bookings[] = [
				'id'           => $id,
				'title'        => (string) $row['post_title'],
				'item'         => $itemId ? get_the_title( $itemId ) : '',
				'location'     => $locationId ? get_the_title( $locationId ) : '',
				'start'        => $start ?: null,
				'end'          => $end ?: null,
				'status'       => (string) $row['post_status'],
				'index_status' => $row['index_status'],
				'changed'      => $changed,
			];
		}
		usort( $bookings, fn( $a, $b ) => $b['changed'] <=> $a['changed'] ?: $b['id'] <=> $a['id'] );

		return array_slice( $bookings, 0, $limit );
	}

	/**
	 * Current state of a post from the activity log: does the index still match WordPress?
	 *
	 * @param array $event
	 *
	 * @return string ok|pending|mismatch
	 */
	public static function getEventState( array $event ): string {
		global $wpdb;
		$post = get_post( (int) $event['post_id'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexStatus = IndexSchema::isInstalled() ? $wpdb->get_var( $wpdb->prepare( 'SELECT post_status FROM ' . IndexSchema::table() . ' WHERE post_id = %d', (int) $event['post_id'] ) ) : null;

		if ( ! $post || $post->post_status === 'auto-draft' ) {
			return $indexStatus === null ? 'ok' : 'mismatch';
		}

		return self::compareStatus( $post->post_status, $indexStatus, (int) strtotime( $post->post_modified_gmt . ' UTC' ) );
	}

	/**
	 * @param string      $status status in WordPress
	 * @param string|null $indexStatus status in the index, null = no row
	 * @param int         $changed time of the last change
	 *
	 * @return string ok|pending|mismatch
	 */
	public static function compareStatus( string $status, ?string $indexStatus, int $changed ): string {
		if ( $status === $indexStatus ) {
			return 'ok';
		}

		return time() - $changed < Guard::GRACE_SECONDS ? 'pending' : 'mismatch';
	}

	/**
	 * Scheduled booster cron events: next run, overdue or missing.
	 *
	 * @return array<string, ?int> hook => timestamp of the next run (null = not scheduled)
	 */
	public static function getSchedule(): array {
		$hooks = [
			Guard::CRON_HOOK,
			Maintenance::CRON_HOOK,
			Verifier::CRON_HOOK,
			Maintenance::VERIFY_HOOK,
		];
		if ( Backfill::isRunning() ) {
			$hooks[] = Backfill::CRON_HOOK;
		}
		$schedule = [];
		foreach ( $hooks as $hook ) {
			$next              = wp_next_scheduled( $hook );
			$schedule[ $hook ] = $next ? (int) $next : null;
		}

		return $schedule;
	}

	/**
	 * Overall assessment for the settings page.
	 *
	 * @return array{level: string, findings: array<int, array{level: string, text: string}>}
	 */
	public static function getHealth(): array {
		$findings = [];
		$add      = function ( string $level, string $text ) use ( &$findings ) {
			$findings[] = [
				'level' => $level,
				'text'  => $text,
			];
		};

		if ( ! Booster::isEnabled() ) {
			return [
				'level'    => 'off',
				'findings' => [],
			];
		}

		if ( ! Backfill::isDone() ) {
			$add( 'warning', __( 'The index is being built. Until it is complete, the original queries are used (correct, but slower).', 'commonsbooking' ) );
		} else {
			foreach ( self::getCoverage()['types'] as $type => $counts ) {
				if ( $counts['posts'] !== $counts['index'] ) {
					$add(
						'warning',
						sprintf(
							/* translators: 1: post type label, 2: number of posts in WordPress, 3: number of index rows */
							__( '%1$s: %2$d in WordPress, %3$d in the index. Newly saved posts appear after a few seconds; if the difference persists, run the check.', 'commonsbooking' ),
							self::getTypeLabel( $type ),
							$counts['posts'],
							$counts['index']
						)
					);
				}
			}
			foreach ( self::getLatestBookings() as $booking ) {
				if ( self::compareStatus( $booking['status'], $booking['index_status'], $booking['changed'] ) === 'mismatch' ) {
					/* translators: %d: booking id */
					$add( 'error', sprintf( __( 'Booking %d differs between WordPress and the index. Run the check to repair it.', 'commonsbooking' ), $booking['id'] ) );
				}
			}
		}

		$stats = Store::getStats();
		if ( Availability::isEnabled() && $stats['rows'] && $stats['usable'] + $stats['role_limited'] < $stats['rows'] - $stats['changed'] ) {
			$add( 'warning', __( 'Some precomputed rows are older than 24 hours. They are computed again when used; check that WP-Cron runs.', 'commonsbooking' ) );
		}

		foreach ( self::getSchedule() as $hook => $next ) {
			if ( $next === null ) {
				/* translators: %s: name of the cron event */
				$add( 'warning', sprintf( __( 'The scheduled task %s is missing. It is created again with the next page load.', 'commonsbooking' ), $hook ) );
			} elseif ( $next < time() - 30 * MINUTE_IN_SECONDS ) {
				/* translators: %s: name of the cron event */
				$add( 'warning', sprintf( __( 'The scheduled task %s is overdue. WP-Cron does not seem to run regularly.', 'commonsbooking' ), $hook ) );
			}
		}

		$deep = self::getChecks()['guard_deep'] ?? null;
		if ( $deep && ( $deep['changed'] || $deep['orphans'] ) && time() - $deep['time'] < DAY_IN_SECONDS ) {
			$add(
				'info',
				sprintf(
					/* translators: %d: number of repaired posts */
					__( 'The last deep check repaired %d posts that were changed without the booster (e.g. by an older plugin version).', 'commonsbooking' ),
					$deep['changed'] + $deep['orphans']
				)
			);
		}

		$level = 'ok';
		foreach ( $findings as $finding ) {
			if ( $finding['level'] === 'error' ) {
				$level = 'error';
			} elseif ( $finding['level'] === 'warning' && $level !== 'error' ) {
				$level = 'warning';
			}
		}

		return [
			'level'    => $level,
			'findings' => $findings,
		];
	}

	/**
	 * @param string $postType
	 *
	 * @return string
	 */
	public static function getTypeLabel( string $postType ): string {
		$object = get_post_type_object( $postType );

		return $object ? (string) $object->labels->name : $postType;
	}

	/**
	 * Where the change came from (for the activity log).
	 *
	 * @return string
	 */
	private static function getSource(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return 'admin';
		}

		return 'frontend';
	}
}
