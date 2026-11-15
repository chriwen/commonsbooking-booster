<?php

namespace CommonsBooking\Tests\Wordpress\CustomPostType;

use CommonsBooking\Exception\BookingDeniedException;
use CommonsBooking\Wordpress\CustomPostType\Booking;
use PHPUnit\Framework\TestCase;

/**
 * Booking requests for the same item run one after the other (double clicks must not create two bookings).
 */
class BookingItemLockTest extends TestCase {

	private function withItemLock( int $itemId, callable $callback ) {
		$method = new \ReflectionMethod( Booking::class, 'withItemLock' );
		$method->setAccessible( true );

		return $method->invoke( null, $itemId, $callback );
	}

	private function isFree( int $itemId ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', 'cb_booking_' . get_current_blog_id() . '_' . $itemId ) ) === '1';
	}

	public function testHoldsTheLockWhileTheCallbackRuns() {
		$during = null;
		$result = $this->withItemLock(
			4711,
			function () use ( &$during ) {
				$during = $this->isFree( 4711 );
				return 'ok';
			}
		);
		$this->assertSame( 'ok', $result );
		$this->assertFalse( $during );
		$this->assertTrue( $this->isFree( 4711 ) );
	}

	public function testReleasesTheLockWhenTheCallbackThrows() {
		try {
			$this->withItemLock(
				4712,
				function () {
					throw new BookingDeniedException( 'denied' );
				}
			);
			$this->fail( 'exception expected' );
		} catch ( BookingDeniedException $e ) {
			$this->assertSame( 'denied', $e->getMessage() );
		}
		$this->assertTrue( $this->isFree( 4712 ) );
	}

	public function testOtherItemsAreNotBlocked() {
		$other = $this->withItemLock( 4713, fn() => $this->isFree( 4714 ) );
		$this->assertTrue( $other );
	}
}
