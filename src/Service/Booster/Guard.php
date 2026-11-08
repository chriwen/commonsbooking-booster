<?php

namespace CommonsBooking\Service\Booster;

use CommonsBooking\Service\Index\Backfill;
use CommonsBooking\Service\Index\Projector;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Service\Availability\Schema as AvailabilitySchema;
use CommonsBooking\Service\Upgrade;

/**
 * Detects changes that were made while the booster code did not run, e.g. after a downgrade to a version without
 * the booster and a later upgrade. The index would be outdated then, although it is marked as complete.
 *
 * Checks on every request (cheap, see {@see self::check()}):
 * 1. The stored plugin version was changed by other code. The booster records every change it makes itself
 *    (its own upgrade routine), so a different value means another plugin version ran. → full rebuild
 * 2. Posts of the indexed types above the last checked post id have no index row (created by other code). → full rebuild
 *
 * Checks every hour and via WP-CLI (more expensive, see {@see self::deepCheck()}):
 * 3. Posts whose status or modification date differ from the index (changed or cancelled by other code)
 *    and index rows of deleted posts. → repair these posts, full rebuild if there are many
 *
 * 4. (hourly) The booster tables are missing, e.g. after a database import without them. → create, full rebuild
 *
 * Changes of meta values only (without updating the post) are found by the daily index verification.
 * Booking validation never depends on the index (see {@see Booster::withoutBooster()}), so an outdated index can
 * only cause an outdated display, never a double booking.
 */
class Guard {

	public const CRON_HOOK = COMMONSBOOKING_PLUGIN_SLUG . '_booster_guard';

	/**
	 * Posts without index row are only treated as foreign changes after this many seconds:
	 * a concurrent request may have created the post and not projected it yet.
	 */
	public const GRACE_SECONDS = 120;

	/**
	 * Maximum number of posts {@see self::deepCheck()} repairs individually; more mean a full rebuild.
	 */
	public const REPAIR_LIMIT = 200;

	/**
	 * Registers the hooks (only while the booster is switched on).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_option_' . Upgrade::VERSION_OPTION, array( self::class, 'onOwnVersionWrite' ) );
		add_action( 'update_option_' . Upgrade::VERSION_OPTION, array( self::class, 'onOwnVersionWrite' ) );
		add_action( self::CRON_HOOK, array( self::class, 'runCron' ) );
		add_action( 'init', array( self::class, 'schedule' ), 50 );
	}

	/**
	 * The plugin version was written by this code (e.g. its upgrade routine): not a foreign change.
	 *
	 * @return void
	 */
	public static function onOwnVersionWrite(): void {
		Booster::saveState( [ 'plugin_version' => self::getStoredPluginVersion() ] );
	}

	/**
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cheap checks on every request (two autoloaded options, two queries on the primary key).
	 *
	 * @return string|null reason, if a rebuild was started
	 */
	public static function check(): ?string {
		if ( ! Backfill::isDone() ) {
			// a rebuild is running or pending anyway
			return null;
		}

		$state = Booster::getState();

		$storedVersion = self::getStoredPluginVersion();
		if ( $storedVersion !== $state['plugin_version'] ) {
			return self::rebuild( 'plugin_version', [ $state['plugin_version'] ?: '-', $storedVersion ?: '-' ] );
		}

		$maxId = self::getMaxPostId();
		if ( $maxId <= $state['watermark'] ) {
			return null;
		}

		$unindexed = self::findUnindexedPosts( $state['watermark'], $maxId );
		$graceEnd  = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_SECONDS );
		$recentIds = [];
		foreach ( $unindexed as $post ) {
			if ( $post['post_modified_gmt'] < $graceEnd ) {
				return self::rebuild( 'unindexed_post', [ (int) $post['ID'] ] );
			}
			$recentIds[] = (int) $post['ID'];
		}

		// everything up to here is indexed (or too recent to judge, checked again with the next request)
		$watermark = $recentIds ? min( $recentIds ) - 1 : $maxId;
		if ( $watermark > $state['watermark'] ) {
			Booster::saveState( [ 'watermark' => $watermark ] );
		}

		return null;
	}

	/**
	 * Compares status and modification date of all indexed post types with the index and repairs differences.
	 *
	 * @param bool $repair
	 *
	 * @return array{changed: int[], orphans: int[], rebuild: bool}
	 */
	public static function deepCheck( bool $repair = true ): array {
		global $wpdb;

		$result = [
			'changed' => [],
			'orphans' => [],
			'rebuild' => false,
		];
		if ( ! IndexSchema::isInstalled() || ! Backfill::isDone() ) {
			return $result;
		}

		$table      = IndexSchema::table();
		$postTypes  = Projector::getPostTypes();
		$typeMarker = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );
		$limit      = self::REPAIR_LIMIT + 1;
		$graceEnd   = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql               = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $table i ON i.post_id = p.ID
			WHERE p.post_type IN ($typeMarker) AND p.post_status <> 'auto-draft' AND p.post_modified_gmt < %s
			AND (
				i.post_id IS NULL
				OR i.post_status <> p.post_status
				OR NOT ( i.source_modified_gmt <=> IF( p.post_modified_gmt = '0000-00-00 00:00:00', NULL, p.post_modified_gmt ) )
			)
			LIMIT %d",
			array_merge( $postTypes, [ $graceEnd, $limit ] )
		);
		$result['changed'] = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result['orphans'] = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT i.post_id FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL LIMIT %d", $limit ) )
		);
		// phpcs:enable

		if ( $repair ) {
			Diagnostics::recordCheck(
				'guard_deep',
				[
					'changed' => count( $result['changed'] ),
					'orphans' => count( $result['orphans'] ),
				]
			);
		}
		if ( ! $repair || ( ! $result['changed'] && ! $result['orphans'] ) ) {
			return $result;
		}

		if ( count( $result['changed'] ) + count( $result['orphans'] ) > self::REPAIR_LIMIT ) {
			$result['rebuild'] = true;
			self::rebuild( 'many_changes', [] );

			return $result;
		}

		foreach ( $result['changed'] as $postId ) {
			Projector::project( $postId );
		}
		foreach ( $result['orphans'] as $postId ) {
			Projector::remove( $postId );
		}
		commonsbooking_write_log(
			sprintf(
				'Booster guard repaired index rows changed without the booster: posts %s, removed rows of deleted posts %s',
				implode( ',', $result['changed'] ) ?: '-',
				implode( ',', $result['orphans'] ) ?: '-'
			)
		);

		return $result;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function runCron(): void {
		if ( ! Booster::isEnabled() ) {
			return;
		}
		if ( ! IndexSchema::tablesExist() || ! AvailabilitySchema::tablesExist() ) {
			commonsbooking_write_log( 'Booster: tables are missing, creating them and rebuilding the index.' );
			Booster::activate();
			Booster::saveState(
				[
					'last_rebuild_reason' => gmdate( 'Y-m-d H:i' ) . ' UTC: ' . self::describeRebuild( 'tables_missing', [], false ),
					'last_rebuild'        => [
						'time' => time(),
						'code' => 'tables_missing',
						'args' => [],
					],
				]
			);

			return;
		}
		self::deepCheck();
	}

	/**
	 * Starts a full rebuild. Until it is complete, all reads use the original queries.
	 *
	 * @param string $code see {@see self::describeRebuild()}
	 * @param array  $args
	 *
	 * @return string the reason (English)
	 */
	public static function rebuild( string $code, array $args ): string {
		$reason = self::describeRebuild( $code, $args, false );
		commonsbooking_write_log( 'Booster: index is outdated, rebuilding. Reason: ' . $reason );
		Backfill::start();
		Booster::saveState(
			[
				'plugin_version'      => self::getStoredPluginVersion(),
				'watermark'           => self::getMaxPostId(),
				'last_rebuild_reason' => gmdate( 'Y-m-d H:i' ) . ' UTC: ' . $reason,
				'last_rebuild'        => [
					'time' => time(),
					'code' => $code,
					'args' => $args,
				],
			]
		);

		return $reason;
	}

	/**
	 * Text for the reason of a rebuild.
	 *
	 * @param string $code plugin_version|unindexed_post|many_changes|manual|tables_missing
	 * @param array  $args
	 * @param bool   $translate false for log and WP-CLI
	 *
	 * @return string
	 */
	public static function describeRebuild( string $code, array $args, bool $translate = true ): string {
		switch ( $code ) {
			case 'plugin_version':
				$text = $translate
					/* translators: 1: previous plugin version, 2: plugin version written by other code */
					? __( 'plugin version was changed by other code (%1$s → %2$s)', 'commonsbooking' )
					: 'plugin version was changed by other code (%1$s → %2$s)';
				break;
			case 'unindexed_post':
				$text = $translate
					/* translators: %d: post id */
					? __( 'post %d was created without the booster', 'commonsbooking' )
					: 'post %d was created without the booster';
				break;
			case 'many_changes':
				$text = $translate ? __( 'many posts changed without the booster', 'commonsbooking' ) : 'many posts changed without the booster';
				break;
			case 'tables_missing':
				$text = $translate ? __( 'the database tables of the booster were missing', 'commonsbooking' ) : 'the database tables of the booster were missing';
				break;
			case 'manual':
				$text = $translate ? __( 'started manually', 'commonsbooking' ) : 'started manually';
				break;
			default:
				return $code;
		}

		return vsprintf( $text, $args );
	}

	/**
	 * @return int highest post id (any type)
	 */
	public static function getMaxPostId(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	}

	/**
	 * @return string the plugin version stored by the upgrade routine of whichever plugin version runs
	 */
	public static function getStoredPluginVersion(): string {
		return (string) get_option( Upgrade::VERSION_OPTION, '' );
	}

	/**
	 * Posts of the indexed types with $fromId < ID <= $toId that have no index row.
	 *
	 * @param int $fromId
	 * @param int $toId
	 *
	 * @return array<int, array{ID: string, post_modified_gmt: string}>
	 */
	private static function findUnindexedPosts( int $fromId, int $toId ): array {
		global $wpdb;

		$table      = IndexSchema::table();
		$postTypes  = Projector::getPostTypes();
		$typeMarker = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_modified_gmt FROM {$wpdb->posts} p LEFT JOIN $table i ON i.post_id = p.ID
			WHERE p.ID > %d AND p.ID <= %d AND p.post_type IN ($typeMarker) AND p.post_status <> 'auto-draft' AND i.post_id IS NULL
			ORDER BY p.ID LIMIT 50",
			array_merge( [ $fromId, $toId ], $postTypes )
		);
		// phpcs:enable

		return $wpdb->get_results( $sql, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
