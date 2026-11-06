<?php

namespace CommonsBooking\Tests\Service\Booster;

use CommonsBooking\Plugin;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;

/**
 * The plugin cache must not hand the result computed for one user role to another: timeframes can be limited to
 * user roles, so map, tables and calendars differ between guests, members and admins.
 */
class CacheRoleContextTest extends CustomPostTypeTest {

	/**
	 * getCacheId() names the cache entry after the calling function, two levels up.
	 */
	private function cacheIdFor( string $customId ): string {
		return $this->cacheIdOuter( $customId );
	}

	private function cacheIdOuter( string $customId ): string {
		return Plugin::getCacheId( $customId );
	}

	public function testCacheIdDependsOnTheUserRoles() {
		wp_set_current_user( 0 );
		$guest = $this->cacheIdFor( 'x' );

		$this->createSubscriber();
		wp_set_current_user( $this->subscriberId );
		$subscriber = $this->cacheIdFor( 'x' );

		$this->createEditor();
		wp_set_current_user( $this->editorUserID );
		$editor = $this->cacheIdFor( 'x' );

		$this->createCBManager();
		wp_set_current_user( $this->cbManagerUserID );
		$manager = $this->cacheIdFor( 'x' );

		$this->createAdministrator();
		wp_set_current_user( $this->adminUserID );
		$admin = $this->cacheIdFor( 'x' );

		$this->assertCount( 5, array_unique( [ $guest, $subscriber, $editor, $manager, $admin ] ) );
	}

	public function testSameRolesShareTheCacheEntry() {
		$this->createSubscriber();
		$other = wp_create_user( 'secondsubscriber', 'pw', 'second@example.org' );

		wp_set_current_user( $this->subscriberId );
		$first = $this->cacheIdFor( 'x' );
		wp_set_current_user( $other );
		$second = $this->cacheIdFor( 'x' );

		$this->assertSame( $first, $second );
		wp_set_current_user( 0 );
		$this->assertSame( $this->cacheIdFor( 'x' ), $this->cacheIdFor( 'x' ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}
}
