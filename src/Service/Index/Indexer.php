<?php

namespace CommonsBooking\Service\Index;

use WP_Post;

/**
 * Keeps the index in sync with the posts.
 *
 * Changes are collected per request ("dirty" post ids) and written in {@see self::flush()}, which runs
 * - at the end of save_post (after all CommonsBooking save handlers changed the meta),
 * - before reading from the index (read-your-writes, see Phase 2) and
 * - on shutdown as safety net.
 *
 * Deleting a post removes its rows immediately.
 *
 * Writes that bypass WordPress hooks (direct SQL) must call {@see self::touch()} explicitly,
 * e.g. {@see \CommonsBooking\Model\Booking::cancel()}.
 */
class Indexer {

	/**
	 * Post ids that need to be (re-)projected, as keys.
	 *
	 * @var array<int, true>
	 */
	private static array $dirty = [];

	/**
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( Schema::class, 'maybeInstall' ), 1 );

		add_action( 'added_post_meta', array( self::class, 'onMetaChange' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'onMetaChange' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'onMetaChange' ), 10, 3 );
		add_action( 'transition_post_status', array( self::class, 'onTransitionPostStatus' ), 10, 3 );
		add_action( 'save_post', array( self::class, 'onSavePost' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_post', array( self::class, 'onDeletedPost' ), 10, 1 );
		add_action( 'shutdown', array( self::class, 'flush' ), 0 );

		add_action( Backfill::CRON_HOOK, array( Backfill::class, 'runCron' ) );
		add_action( Verifier::CRON_HOOK, array( Verifier::class, 'runCron' ) );
		add_action( 'init', array( Verifier::class, 'schedule' ), 50 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'commonsbooking index', Cli::class );
		}
	}

	/**
	 * Marks a post as changed, if the meta key is relevant for the index.
	 *
	 * @param int|int[] $metaIds unused
	 * @param int       $objectId
	 * @param string    $metaKey
	 *
	 * @return void
	 */
	public static function onMetaChange( $metaIds, $objectId, $metaKey ): void {
		if ( ! in_array( $metaKey, Projector::getRelevantMetaKeys(), true ) ) {
			return;
		}
		if ( ! self::isIndexedPostType( get_post_type( (int) $objectId ) ) ) {
			return;
		}
		self::touch( (int) $objectId );
	}

	/**
	 * @param string $newStatus
	 * @param string $oldStatus
	 * @param mixed  $post WP_Post, typed loosely because third parties may call the hook with other values
	 *
	 * @return void
	 */
	public static function onTransitionPostStatus( $newStatus, $oldStatus, $post ): void {
		if ( $post instanceof WP_Post && self::isIndexedPostType( $post->post_type ) ) {
			self::touch( $post->ID );
		}
	}

	/**
	 * Runs after all other save_post handlers (priority PHP_INT_MAX), so the meta is final.
	 *
	 * @param int   $postId
	 * @param mixed $post WP_Post, typed loosely because third parties may call the hook with other values
	 *
	 * @return void
	 */
	public static function onSavePost( $postId, $post ): void {
		if ( $post instanceof WP_Post && self::isIndexedPostType( $post->post_type ) ) {
			self::touch( (int) $postId );
		}
		self::flush();
	}

	/**
	 * @param int $postId
	 *
	 * @return void
	 */
	public static function onDeletedPost( $postId ): void {
		$postId = (int) $postId;
		unset( self::$dirty[ $postId ] );
		if ( Schema::isInstalled() ) {
			Projector::remove( $postId );
		}
	}

	/**
	 * Marks a post for re-projection.
	 *
	 * @param int $postId
	 *
	 * @return void
	 */
	public static function touch( int $postId ): void {
		if ( $postId > 0 ) {
			self::$dirty[ $postId ] = true;
		}
	}

	/**
	 * Projects all changed posts.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( ! self::$dirty || ! Schema::isInstalled() ) {
			return;
		}

		// Take the list first: projecting may trigger hooks that mark posts again.
		$postIds     = array_keys( self::$dirty );
		self::$dirty = [];

		foreach ( $postIds as $postId ) {
			try {
				Projector::project( $postId );
			} catch ( \Throwable $e ) {
				// Never break the actual request. The verifier repairs inconsistencies.
				commonsbooking_write_log( sprintf( 'Index projection failed for post %d: %s', $postId, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Returns the post ids waiting to be projected (for tests and debugging).
	 *
	 * @return int[]
	 */
	public static function getDirty(): array {
		return array_keys( self::$dirty );
	}

	/**
	 * @param string|false|null $postType
	 *
	 * @return bool
	 */
	private static function isIndexedPostType( $postType ): bool {
		return is_string( $postType ) && in_array( $postType, Projector::getPostTypes(), true );
	}
}
