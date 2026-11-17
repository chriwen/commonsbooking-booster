<?php

namespace CommonsBooking\Tests\View;

use CommonsBooking\Plugin;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Admin\OccupancyPlan as AdminPlan;
use CommonsBooking\View\OccupancyPlan;
use SlopeIt\ClockMock\ClockMock;

/**
 * Shortcode [cb_occupancy_plan]: permissions, request parameters, same plan as the admin page.
 *
 * @covers \CommonsBooking\View\OccupancyPlan
 * @covers \CommonsBooking\View\Admin\OccupancyPlan
 */
class OccupancyPlanShortcodeTest extends CustomPostTypeTest {

	private int $now;

	private int $bookingId;

	private string $bookerEmail;

	protected function setUp(): void {
		parent::setUp();
		ClockMock::freeze( new \DateTime( self::CURRENT_DATE ) );
		$this->now = strtotime( self::CURRENT_DATE );
		$this->createTimeframe( $this->locationId, $this->itemId, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->bookingId   = $this->createBooking( $this->locationId, $this->itemId, strtotime( '+1 day', $this->now ), strtotime( '+2 days midnight', $this->now ) - 1 );
		$this->bookerEmail = get_userdata( self::USER_ID )->user_email;

		$instances = new \ReflectionProperty( OccupancyPlan::class, 'instances' );
		$instances->setAccessible( true );
		$instances->setValue( null, 0 );
		$_GET                   = [];
		$_SERVER['REQUEST_URI'] = '/?page_id=42';
	}

	protected function tearDown(): void {
		$_GET = [];
		wp_set_current_user( 0 );
		ClockMock::reset();
		parent::tearDown();
	}

	private function shortcode( string $atts = '' ): string {
		return do_shortcode( '[cb_occupancy_plan ' . $atts . ']' );
	}

	private function asAdmin(): void {
		$this->createAdministrator();
		wp_set_current_user( $this->adminUserID );
		delete_user_meta( $this->adminUserID, OccupancyPlan::USER_META );
		Plugin::clearCache();
	}

	/**
	 * @return string the grid of the plan (without filters and panel configuration)
	 */
	private function grid( string $html ): string {
		$start = strpos( $html, '<div class="cb-plan-scroll">' );
		$end   = strpos( $html, '<aside class="cb-plan-panel' );
		$this->assertNotFalse( $start, 'plan grid missing' );
		$this->assertNotFalse( $end, 'panel missing' );

		return substr( $html, $start, $end - $start );
	}

	public function testRegistered() {
		$this->assertTrue( shortcode_exists( OccupancyPlan::SHORTCODE ) );
	}

	public function testGuestGetsLoginNotice() {
		wp_set_current_user( 0 );
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );
		$this->assertStringContainsString( 'cb-plan-notice', $html );
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringNotContainsString( 'cb-plan-data', $html );
		$this->assertStringNotContainsString( $this->bookerEmail, $html );
	}

	public function testMemberWithoutPermissionGetsNotice() {
		$this->createSubscriber();
		wp_set_current_user( $this->subscriberId );
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );
		$this->assertStringContainsString( 'only available to administrators and CB managers', $html );
		$this->assertStringNotContainsString( 'cb-plan-root', $html );
		$this->assertStringNotContainsString( $this->bookerEmail, $html );
		$this->assertStringNotContainsString( get_the_title( $this->locationId ), $html );
	}

	public function testCBManagerWithoutLocations() {
		$this->createCBManager();
		wp_set_current_user( $this->cbManagerUserID );
		Plugin::clearCache();
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );
		$this->assertStringContainsString( 'No location available', $html );
		$this->assertStringNotContainsString( 'cb-plan-data', $html );
		$this->assertStringNotContainsString( $this->bookerEmail, $html );
	}

	public function testCBManagerSeesOnlyManagedLocations() {
		$this->createCBManager();
		$managed = $this->createLocation( 'Managed station', 'publish', [ (string) $this->cbManagerUserID ] );
		$item    = $this->createItem( 'Item at managed station' );
		$this->createTimeframe( $managed, $item, strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$booking = $this->createBooking( $managed, $item, strtotime( '+3 days', $this->now ), strtotime( '+4 days midnight', $this->now ) - 1 );
		wp_set_current_user( $this->cbManagerUserID );
		Plugin::clearCache();

		// Not managed location via attribute and via URL: dropped silently
		$_GET = [
			'cbp_sel'      => '1',
			'cbp_location' => [ (string) $this->locationId, (string) $managed ],
		];
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );
		$this->assertStringContainsString( 'data-post="' . $booking . '"', $html, 'booking at the managed location' );
		$this->assertStringNotContainsString( 'data-post="' . $this->bookingId . '"', $html, 'no booking of the other location' );
		$this->assertStringNotContainsString( 'value="' . $this->locationId . '"', $html, 'other location neither selected nor offered' );
		$this->assertStringNotContainsString( '"' . get_the_title( $this->locationId ) . '"', $html );
	}

	public function testAdminSeesPlanWithPrefixedParameters() {
		$this->asAdmin();
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );

		$this->assertStringContainsString( 'id="cb-plan-root"', $html );
		$this->assertStringContainsString( 'cb-plan-front', $html );
		$this->assertStringContainsString( 'data-post="' . $this->bookingId . '"', $html );
		$this->assertStringContainsString( $this->bookerEmail, $html, 'booking details for admins' );
		$this->assertStringContainsString( 'name="cbp_location[]"', $html );
		$this->assertStringContainsString( 'name="cbp_days"', $html );
		$this->assertStringNotContainsString( 'name="cat"', $html, '"cat" would be the WordPress category query' );
		$this->assertStringNotContainsString( 'name="days"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="42">', $html, 'query of the page kept in the GET form' );
		$this->assertStringContainsString( 'cbp_from=', $html, 'navigation links with prefix' );
		$this->assertStringNotContainsString( 'nav-tab', $html, 'no admin classes in the frontend' );
		$this->assertTrue( wp_style_is( OccupancyPlan::ASSET_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( OccupancyPlan::ASSET_HANDLE, 'enqueued' ) );
	}

	public function testSamePlanAsAdminPage() {
		$this->asAdmin();
		$_GET = [
			'page'     => AdminPlan::SLUG,
			'sel'      => '1',
			'location' => [ (string) $this->locationId ],
			'days'     => '14',
		];
		ob_start();
		AdminPlan::render();
		$admin = (string) ob_get_clean();

		$_GET  = [];
		$front = $this->shortcode( 'location="' . $this->locationId . '" days="14"' );

		$this->assertStringContainsString( 'nav-tab', $admin );
		$this->assertSame( $this->grid( $admin ), $this->grid( $front ) );
	}

	public function testUrlParametersOverrideAttributes() {
		$this->asAdmin();
		$_GET = [
			'cbp_sel'      => '1',
			'cbp_location' => [ (string) $this->locationId ],
			'cbp_days'     => '7',
		];
		$html = $this->shortcode( 'location="' . $this->locationId . '" days="56"' );
		$this->assertMatchesRegularExpression( '/<option value="7" selected/', $html );
	}

	public function testUncheckingCanceledOverridesAttribute() {
		$this->asAdmin();
		$_GET = [
			'cbp_sel'      => '1',
			'cbp_location' => [ (string) $this->locationId ],
		];
		$html = $this->shortcode( 'location="' . $this->locationId . '" canceled="1"' );
		$this->assertDoesNotMatchRegularExpression( '/name="cbp_canceled" value="1" checked/', $html );
	}

	public function testLockFixesSelection() {
		$this->asAdmin();
		$other = $this->createLocation( 'Other station' );
		$this->createTimeframe( $other, $this->createItem( 'Other item' ), strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		update_user_meta( $this->adminUserID, OccupancyPlan::USER_META, [ 'view' => 'item' ] );

		$_GET = [
			'cbp_sel'      => '1',
			'cbp_view'     => 'item',
			'cbp_location' => [ (string) $other ],
		];
		$html = $this->shortcode( 'view="location" location="' . $this->locationId . '" lock="1"' );
		$this->assertStringContainsString( 'data-post="' . $this->bookingId . '"', $html );
		$this->assertStringNotContainsString( 'cb-plan-picker', $html, 'no search field' );
		$this->assertStringNotContainsString( 'cb-plan-tabs', $html, 'no view tabs' );
		$this->assertSame( [ 'view' => 'item' ], get_user_meta( $this->adminUserID, OccupancyPlan::USER_META, true ), 'remembered selection untouched' );
	}

	public function testSelectionIsSharedWithAdminPage() {
		$this->asAdmin();
		$this->shortcode( 'location="' . $this->locationId . '" days="56"' );
		$saved = get_user_meta( $this->adminUserID, OccupancyPlan::USER_META, true );
		$this->assertSame( [ $this->locationId ], $saved['location']['ids'] );
		$this->assertSame( 56, $saved['location']['days'] );
	}

	public function testIdsAttributeSelectsView() {
		$this->asAdmin();
		update_user_meta( $this->adminUserID, OccupancyPlan::USER_META, [ 'view' => 'item' ] );
		$html = $this->shortcode( 'location="' . $this->locationId . '"' );
		$this->assertStringContainsString( 'name="cbp_location[]"', $html, 'location ids without view: location view, not the remembered one' );
	}

	public function testOnlyOneInstancePerPage() {
		$this->asAdmin();
		$html = do_shortcode( '[cb_occupancy_plan location="' . $this->locationId . '"][cb_occupancy_plan]' );
		$this->assertSame( 1, substr_count( $html, 'id="cb-plan-root"' ) );
		$this->assertStringContainsString( 'only be shown once per page', $html );
	}

	public function testItemViewAndAlign() {
		$this->asAdmin();
		$html = $this->shortcode( 'view="item" item="' . $this->itemId . '" align="full" tabs="0"' );
		$this->assertStringContainsString( 'alignfull', $html );
		$this->assertStringContainsString( 'name="cbp_item[]"', $html );
		$this->assertStringContainsString( 'data-post="' . $this->bookingId . '"', $html );
		$this->assertStringNotContainsString( 'cb-plan-tabs', $html );
	}

	public function testCollapsibleGroupsOnlyWithSeveralLocations() {
		$other = $this->createLocation( 'Other station' );
		$this->createTimeframe( $other, $this->createItem( 'Other item' ), strtotime( '-10 days', $this->now ), strtotime( '+30 days', $this->now ) );
		$this->asAdmin();
		$instances = new \ReflectionProperty( OccupancyPlan::class, 'instances' );
		$instances->setAccessible( true );

		$single = $this->grid( $this->shortcode( 'location="' . $this->locationId . '"' ) );
		$this->assertStringNotContainsString( 'cb-plan-group', $single, 'one location: no group row to collapse' );

		$instances->setValue( null, 0 );
		$_GET = [
			'cbp_sel'      => '1',
			'cbp_location' => [ (string) $this->locationId, (string) $other ],
		];
		$html = $this->grid( $this->shortcode() );
		foreach ( [ $this->locationId, $other ] as $id ) {
			$this->assertStringContainsString( '<div class="cb-plan-line cb-plan-group" data-group="' . $id . '">', $html );
		}
		$this->assertSame( 2, substr_count( $html, 'class="cb-plan-toggle" aria-expanded="true"' ) );
		$this->assertSame( 2, substr_count( $html, 'class="cb-plan-solo"' ) );
		$this->assertStringContainsString( '1 item</span>', $html, 'summary for the collapsed state' );
		$this->assertMatchesRegularExpression( '/class="cb-plan-line cb-plan-row[^"]*"[^>]*data-group="' . $other . '"/', $html, 'rows carry their group for hiding' );
	}
}
