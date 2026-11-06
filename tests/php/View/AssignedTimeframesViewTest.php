<?php

namespace CommonsBooking\Tests\View;

use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\Admin\AssignedTimeframes;
use CommonsBooking\Wordpress\CustomPostType\CustomPostType;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Metabox "Assigned timeframes", row action and the item / location filter of the timeframe list.
 *
 * @covers \CommonsBooking\View\Admin\AssignedTimeframes
 */
class AssignedTimeframesViewTest extends CustomPostTypeTest {

	private int $bookableId;
	private int $holidayId;

	protected function setUp(): void {
		parent::setUp();
		$now              = current_time( 'timestamp' );
		$this->bookableId = $this->createTimeframe(
			$this->locationId,
			$this->itemId,
			strtotime( '-10 days', $now ),
			strtotime( '+30 days', $now )
		);
		$this->holidayId  = $this->createHolidayTimeframeForAllItemsAndLocations();
		wp_update_post(
			[
				'ID'         => $this->holidayId,
				'post_title' => 'Summer break',
			]
		);
		update_post_meta( $this->holidayId, 'repetition-start', strtotime( '-1 day', $now ) );
		update_post_meta( $this->holidayId, 'repetition-end', strtotime( '+1 day', $now ) );
		$this->createAdministrator();
		wp_set_current_user( $this->adminUserID );
	}

	public function testRenderMetabox() {
		ob_start();
		AssignedTimeframes::render( get_post( $this->itemId ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Summer break', $html );
		$this->assertStringContainsString( 'TestTimeframe', $html );
		$this->assertStringContainsString( 'Testlocation', $html );
		$this->assertStringContainsString( 'admin_filter_item=' . $this->itemId, $html );
		$this->assertStringContainsString( esc_url( get_edit_post_link( $this->holidayId, 'url' ) ), $html );

		ob_start();
		AssignedTimeframes::render( get_post( $this->locationId ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Summer break', $html );
		$this->assertStringContainsString( 'TestItem', $html );
	}

	public function testMetaboxIsEmptyForUsersWithoutRights() {
		$this->createSubscriber();
		wp_set_current_user( $this->subscriberId );
		ob_start();
		AssignedTimeframes::render( get_post( $this->itemId ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function testRowAction() {
		$actions = CustomPostType::modifyRowActions( [], get_post( $this->itemId ) );
		$this->assertArrayHasKey( 'cb_assigned_timeframes', $actions );
		$this->assertStringContainsString( '#' . AssignedTimeframes::METABOX_ID, $actions['cb_assigned_timeframes'] );

		$actions = CustomPostType::modifyRowActions( [], get_post( $this->holidayId ) );
		$this->assertArrayNotHasKey( 'cb_assigned_timeframes', $actions );
	}

	public function testTimeframeListFilterFindsMultiSelection() {
		global $pagenow;
		$otherItem = $this->createItem( 'Other item' );
		$other     = $this->createTimeframe( $this->locationId, $otherItem, strtotime( '-1 day' ), strtotime( '+1 day' ) );

		set_current_screen( 'edit-' . Timeframe::$postType );
		$pagenow                       = 'edit.php';
		$_GET['post_type']             = Timeframe::$postType;
		$_GET['admin_filter_item']     = (string) $this->itemId;

		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$ids                     = $query->query(
			[
				'post_type'   => Timeframe::$postType,
				'post_status' => 'any',
				'fields'      => 'ids',
				'nopaging'    => true,
			]
		);

		unset( $_GET['post_type'], $_GET['admin_filter_item'] );
		set_current_screen( 'front' );

		$this->assertContains( $this->bookableId, $ids );
		$this->assertContains( $this->holidayId, $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function testTimeframeListColumnShowsMultiSelection() {
		$cpt = new Timeframe();
		ob_start();
		$cpt->setCustomColumnsData( \CommonsBooking\Model\Timeframe::META_ITEM_ID, $this->holidayId );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'all', $html );

		ob_start();
		$cpt->setCustomColumnsData( \CommonsBooking\Model\Timeframe::META_ITEM_ID, $this->bookableId );
		$this->assertStringContainsString( 'TestItem', ob_get_clean() );
	}
}
