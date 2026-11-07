<?php

namespace CommonsBooking\Service\Availability;

use CommonsBooking\Model\Timeframe as TimeframeModel;
use CommonsBooking\Service\Index\Indexer;
use CommonsBooking\Service\Index\Schema as IndexSchema;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use WP_User;

/**
 * Access profiles of pairs with role limited timeframes.
 *
 * The only user dependent step of the day calculation is {@see commonsbooking_isCurrentUserAllowedToBook()}:
 * a timeframe without allowed roles is open to everybody, admins may book everything, everybody else needs one of
 * the allowed roles. For a pair whose limited timeframes allow the roles R, the days therefore only depend on
 *
 *     access profile = R                               for admins
 *                    = sorted( roles of the user ∩ R ) otherwise
 *
 * All users with the same access profile see the same days, so they are stored once per pair and access profile
 * ({@see AccessStore}). An admin gets the same access profile as a user with all roles of R: both may book every
 * timeframe of the pair. Roles are joined with commas ('' = no matching role), role names never contain commas.
 *
 * @see design/role-restricted-availability.md
 */
class AccessProfile {

	/**
	 * Default maximum number of stored access profiles per pair, see {@see self::getMaxPerPair()}.
	 */
	public const MAX_PER_PAIR = 8;

	/**
	 * Allowed roles of the role limited timeframes, by "locationId:itemId".
	 *
	 * @var array<string, string[]>
	 */
	private static array $roles = [];

	/**
	 * Returns true if pairs with role limited timeframes are stored per access profile.
	 * Can be switched off with the filter `commonsbooking_availability_access_profiles`, they are computed live then.
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		/**
		 * Allows to compute pairs with role limited timeframes live instead of storing them per access profile.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'commonsbooking_availability_access_profiles', true );
	}

	/**
	 * Maximum number of stored access profiles per pair. Further access profiles of the pair are computed live.
	 *
	 * @return int
	 */
	public static function getMaxPerPair(): int {
		/**
		 * Maximum number of access profiles stored per item/location pair.
		 *
		 * @param int $max
		 */
		return max( 2, (int) apply_filters( 'commonsbooking_availability_access_profiles_max', self::MAX_PER_PAIR ) );
	}

	/**
	 * Access profile of the current user for a pair.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return string
	 */
	public static function forCurrentUser( int $itemId, int $locationId ): string {
		$roles = self::getRoles( $itemId, $locationId );
		if ( ! $roles || commonsbooking_isCurrentUserAdmin() ) {
			return implode( ',', $roles );
		}

		return self::fromRoles( array_intersect( wp_get_current_user()->roles, $roles ) );
	}

	/**
	 * Access profile of all users with every role of the pair, and of admins.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return string
	 */
	public static function forAllRoles( int $itemId, int $locationId ): string {
		return implode( ',', self::getRoles( $itemId, $locationId ) );
	}

	/**
	 * @param string[] $roles
	 *
	 * @return string
	 */
	public static function fromRoles( array $roles ): string {
		$roles = array_values( array_unique( array_filter( array_map( 'strval', $roles ), fn( string $role ): bool => '' !== $role ) ) );
		sort( $roles );

		return implode( ',', $roles );
	}

	/**
	 * @param string $accessProfile
	 *
	 * @return string[]
	 */
	public static function toRoles( string $accessProfile ): array {
		return $accessProfile === '' ? [] : explode( ',', $accessProfile );
	}

	/**
	 * Runs a callback as a user who is not logged in and has exactly the roles of the access profile.
	 * {@see commonsbooking_isCurrentUserAllowedToBook()} then decides for that access profile.
	 *
	 * @template T
	 * @param string        $accessProfile
	 * @param callable(): T $callback
	 *
	 * @return T
	 */
	public static function runAs( string $accessProfile, callable $callback ) {
		global $current_user;
		$previous     = $current_user;
		$user         = new WP_User();
		$user->roles  = self::toRoles( $accessProfile );
		$current_user = $user; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below
		try {
			return $callback();
		} finally {
			$current_user = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Allowed roles of all role limited timeframes of a pair, sorted.
	 *
	 * @param int $itemId
	 * @param int $locationId
	 *
	 * @return string[]
	 */
	public static function getRoles( int $itemId, int $locationId ): array {
		self::preloadRoles( [ [ $itemId, $locationId ] ] );

		return self::$roles[ $locationId . ':' . $itemId ];
	}

	/**
	 * Loads the allowed roles of several pairs with few queries.
	 *
	 * Same timeframes as {@see Calculator::hasRoleLimit()}: published or confirmed timeframes of the pair with
	 * allowed roles, independent of their dates.
	 *
	 * @param array<int, array{0:int,1:int}> $pairs list of [itemId, locationId]
	 *
	 * @return void
	 */
	public static function preloadRoles( array $pairs ): void {
		global $wpdb;

		$wanted = [];
		foreach ( $pairs as [ $itemId, $locationId ] ) {
			$key = (int) $locationId . ':' . (int) $itemId;
			if ( ! isset( self::$roles[ $key ] ) ) {
				$wanted[ $key ] = [ (int) $itemId, (int) $locationId ];
			}
		}
		if ( ! $wanted ) {
			return;
		}

		Indexer::flush();
		$table       = IndexSchema::table();
		$relTable    = IndexSchema::relTable();
		$itemIds     = implode( ',', array_unique( array_column( $wanted, 0 ) ) );
		$locationIds = implode( ',', array_unique( array_column( $wanted, 1 ) ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- prepared, only integers
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, item_id, location_id, is_multi FROM $table
				WHERE post_type = %s AND post_status IN ('publish', 'confirmed') AND has_role_limit = 1
				AND ( is_multi = 1 OR ( item_id IN ($itemIds) AND location_id IN ($locationIds) ) )",
				Timeframe::$postType
			),
			ARRAY_A
		);

		// timeframes with several items or locations: entities from the relation table
		$entities = [];
		$multiIds = array_map( 'intval', array_column( array_filter( $rows, fn( $row ) => (int) $row['is_multi'] === 1 ), 'post_id' ) );
		if ( $multiIds ) {
			foreach ( $wpdb->get_results( "SELECT post_id, entity_type, entity_id FROM $relTable WHERE post_id IN (" . implode( ',', $multiIds ) . ')', ARRAY_A ) as $rel ) {
				$entities[ (int) $rel['post_id'] ][ $rel['entity_type'] ][] = (int) $rel['entity_id'];
			}
		}
		// phpcs:enable

		$postIds = array_map( 'intval', array_column( $rows, 'post_id' ) );
		if ( $postIds ) {
			update_meta_cache( 'post', $postIds );
		}

		$roles = array_fill_keys( array_keys( $wanted ), [] );
		foreach ( $rows as $row ) {
			$postId    = (int) $row['post_id'];
			$items     = $row['item_id'] !== null ? [ (int) $row['item_id'] ] : ( $entities[ $postId ]['item'] ?? [] );
			$locations = $row['location_id'] !== null ? [ (int) $row['location_id'] ] : ( $entities[ $postId ]['location'] ?? [] );
			$allowed   = get_post_meta( $postId, TimeframeModel::META_ALLOWED_USER_ROLES, true );
			if ( empty( $allowed ) ) {
				continue;
			}
			foreach ( $locations as $locationId ) {
				foreach ( $items as $itemId ) {
					$key = $locationId . ':' . $itemId;
					if ( isset( $roles[ $key ] ) ) {
						$roles[ $key ] = array_merge( $roles[ $key ], (array) $allowed );
					}
				}
			}
		}

		foreach ( $roles as $key => $list ) {
			self::$roles[ $key ] = self::toRoles( self::fromRoles( $list ) );
		}
	}

	/**
	 * Forgets the roles loaded in this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$roles = [];
	}
}
