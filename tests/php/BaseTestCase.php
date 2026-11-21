<?php

namespace CommonsBooking\Tests;

use CommonsBooking\Tests\Helper\GeoHelperTest;
use PHPUnit\Framework\TestCase;

class BaseTestCase extends TestCase {

	protected function setUp(): void {

		// state of the precomputed availability that lives for one request
		\CommonsBooking\Service\Availability\Store::reset();
		\CommonsBooking\Service\Availability\Calculator::reset();
		\CommonsBooking\Service\Availability\Invalidator::reset();

		// Default case: Tests should work offline
		GeoHelperTest::setUpGeoHelperMock( $this );
	}
}
