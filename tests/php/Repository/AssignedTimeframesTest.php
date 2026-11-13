<?php

namespace CommonsBooking\Tests\Repository;

use CommonsBooking\Model\Restriction;
use CommonsBooking\Repository\AssignedTimeframes;
use CommonsBooking\Service\Booster\Booster;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * @covers \CommonsBooking\Repository\AssignedTimeframes
 */
class AssignedTimeframesTest extends CustomPostTypeTest {

	private int $now;

	private int $bookableId;

	protected function setUp(): void {
		parent::setUp();
		$this->now = strtotime( self::CURRENT_DATE );
		// item is bookable at the test location
		$this->bookableId = $this->createTimeframe(
			$this->locationId,
			$this->itemId,
			strtotime( '-10 days', $this->now ),
			strtotime( '+30 days', $this->now )
		);
	}

	/**
	 * Returns all entries of the result, keyed by post id.
	 */
	private function entries( array $result ): array {
		$entries = [];
		foreach ( $result['groups'] as $group ) {
			foreach ( $group as $entry ) {
				$entries[ $entry['post_id'] ] = $entry;
			}
		}

		return $entries;
	}

	private function holiday( $locations, $items, int $start, ?int $end, string $status = 'publish' ): int {
		return $this->createTimeframe(
			$locations,
			$items,
			$start,
			$end,
			Timeframe::HOLIDAYS_ID,
			'on',
			'd',
			0,
			'8:00 AM',
			'12:00 PM',
			$status
		);
	}

	public function testBookableTimeframeDirect() {
		$result  = AssignedTimeframes::forItem( $this->itemId, $this->now );
		$entries = $this->entries( $result );

		$this->assertArrayHasKey( $this->bookableId, $entries );
		$entry = $entries[ $this->bookableId ];
		$this->assertEquals( AssignedTimeframes::GROUP_CURRENT, $entry['group'] );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_DIRECT, $entry['items']['mode'] );
		$this->assertEquals( [ $this->locationId ], $entry['effective_at'] );
		$this->assertTrue( $entry['effective'] );
		$this->assertFalse( $entry['blocking'] );
		$this->assertEquals( 'full', $entry['grid'] );
		$this->assertEquals( [ $this->locationId ], $result['counterparts'] );
	}

	public function testHolidayWithItemList() {
		$otherItem = $this->createItem( 'Other item' );
		$thirdItem = $this->createItem( 'Third item' );
		$holiday   = $this->holiday( [ $this->locationId ], [ $this->itemId, $otherItem ], $this->now, strtotime( '+1 day', $this->now ) );

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_LIST, $entries[ $holiday ]['items']['mode'] );
		$this->assertEqualsCanonicalizing( [ $this->itemId, $otherItem ], $entries[ $holiday ]['items']['ids'] );
		$this->assertTrue( $entries[ $holiday ]['blocking'] );
		$this->assertTrue( $entries[ $holiday ]['effective'] );

		$this->assertArrayHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $otherItem, $this->now ) ) );
		$this->assertArrayNotHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $thirdItem, $this->now ) ) );
	}

	public function testForTimeframeFindsOtherTimeframesOfSameCombination() {
		$otherItem     = $this->createItem( 'Other item' );
		$otherLocation = $this->createLocation( 'Other location' );
		$sameCombo     = $this->holiday( [ $this->locationId ], [ $this->itemId, $otherItem ], $this->now, strtotime( '+1 day', $this->now ), 'draft' );
		$onlyItem      = $this->createTimeframe( $otherLocation, $this->itemId, strtotime( '-1 day', $this->now ), strtotime( '+1 day', $this->now ) );
		$onlyLocation  = $this->createTimeframe( $this->locationId, $otherItem, strtotime( '-1 day', $this->now ), strtotime( '+1 day', $this->now ) );
		$expired       = $this->holiday( [ $this->locationId ], [ $this->itemId ], strtotime( '-20 days', $this->now ), strtotime( '-10 days', $this->now ) );

		$result  = AssignedTimeframes::forTimeframe( new \CommonsBooking\Model\Timeframe( $this->bookableId ), $this->now );
		$entries = $this->entries( $result );

		$this->assertEquals( [ $this->itemId ], $result['items'] );
		$this->assertEquals( [ $this->locationId ], $result['locations'] );
		$this->assertArrayHasKey( $sameCombo, $entries );
		$this->assertArrayHasKey( $expired, $entries );
		$this->assertArrayNotHasKey( $this->bookableId, $entries, 'the timeframe itself is not listed' );
		$this->assertArrayNotHasKey( $onlyItem, $entries );
		$this->assertArrayNotHasKey( $onlyLocation, $entries );
		$this->assertEquals( [ $this->locationId ], $entries[ $sameCombo ]['effective_at'] );
		$this->assertFalse( $entries[ $sameCombo ]['effective'], 'draft is shown, but not effective' );
		$this->assertEquals( AssignedTimeframes::GROUP_EXPIRED, $entries[ $expired ]['group'] );
	}

	public function testForTimeframeWithHolidayForAll() {
		$holiday = $this->createHolidayTimeframeForAllItemsAndLocations();

		$entries = $this->entries( AssignedTimeframes::forTimeframe( new \CommonsBooking\Model\Timeframe( $this->bookableId ), $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );

		$entries = $this->entries( AssignedTimeframes::forTimeframe( new \CommonsBooking\Model\Timeframe( $holiday ), $this->now ) );
		$this->assertArrayHasKey( $this->bookableId, $entries );
		$this->assertArrayNotHasKey( $holiday, $entries );
	}

	/**
	 * Item 12 must not find a timeframe with the list [112, 120].
	 */
	public function testListMatchingIsExact() {
		$holiday = $this->holiday( [ $this->locationId ], [ '1' . $this->itemId, $this->itemId . '0' ], $this->now, strtotime( '+1 day', $this->now ) );

		$this->assertArrayNotHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) ) );
	}

	public function testHolidayForAllItemsAndLocations() {
		$holiday = $this->createHolidayTimeframeForAllItemsAndLocations();

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_ALL, $entries[ $holiday ]['items']['mode'] );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_ALL, $entries[ $holiday ]['locations']['mode'] );
		$this->assertEquals( [ $this->locationId ], $entries[ $holiday ]['effective_at'] );

		// a new item is added to the dynamic selection on save
		$newItem = $this->createItem( 'New item' );
		\CommonsBooking\Wordpress\CustomPostType\Timeframe::updateAllTimeframes();
		$this->assertArrayHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $newItem, $this->now ) ) );

		// location view
		$locationEntries = $this->entries( AssignedTimeframes::forLocation( $this->locationId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $locationEntries );
		$this->assertArrayHasKey( $this->bookableId, $locationEntries );
		$this->assertEquals( [ $this->itemId ], $locationEntries[ $holiday ]['effective_at'] );
	}

	public function testHolidayByCategory() {
		$term = wp_insert_term( 'Cargo bikes', Item::getTaxonomyName() );
		$this->assertIsArray( $term );
		$termId = $term['term_id'];
		wp_set_object_terms( $this->itemId, [ $termId ], Item::getTaxonomyName() );

		$holiday = $this->holiday( [ $this->locationId ], '', $this->now, strtotime( '+1 day', $this->now ) );
		update_post_meta( $holiday, \CommonsBooking\Model\Timeframe::META_ITEM_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_CATEGORY_ID );
		update_post_meta( $holiday, \CommonsBooking\Model\Timeframe::META_ITEM_CATEGORY_IDS, [ (string) $termId ] );
		Timeframe::manageTimeframeMeta( $holiday );

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_CATEGORY, $entries[ $holiday ]['items']['mode'] );
		$this->assertEquals( [ $termId ], $entries[ $holiday ]['items']['category_ids'] );

		// item removed from category
		wp_set_object_terms( $this->itemId, [], Item::getTaxonomyName() );
		Timeframe::manageTimeframeMeta( $holiday );
		$this->assertArrayNotHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) ) );
	}

	public function testHolidayAtOtherLocationIsNotEffective() {
		$otherLocation = $this->createLocation( 'Other location' );
		$holiday       = $this->holiday( [ $otherLocation ], [ $this->itemId ], $this->now, strtotime( '+1 day', $this->now ) );

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );
		$this->assertSame( [], $entries[ $holiday ]['effective_at'] );
		$this->assertFalse( $entries[ $holiday ]['effective'] );
	}

	/**
	 * A holiday of another location for "all items" (or by category) does not concern the item.
	 */
	public function testHolidayOfOtherLocationForAllItemsIsHidden() {
		$otherLocation = $this->createLocation( 'Other location' );
		$holiday       = $this->holiday( [ $otherLocation ], '', $this->now, strtotime( '+1 day', $this->now ) );
		update_post_meta( $holiday, \CommonsBooking\Model\Timeframe::META_ITEM_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_ALL_ID );
		Timeframe::manageTimeframeMeta( $holiday );

		$this->assertArrayNotHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) ) );
		// still shown at its own location
		$this->assertArrayHasKey( $holiday, $this->entries( AssignedTimeframes::forLocation( $otherLocation, $this->now ) ) );

		// same holiday at the item's location
		$ownHoliday = $this->holiday( [ $this->locationId ], '', $this->now, strtotime( '+1 day', $this->now ) );
		update_post_meta( $ownHoliday, \CommonsBooking\Model\Timeframe::META_ITEM_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_ALL_ID );
		Timeframe::manageTimeframeMeta( $ownHoliday );
		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $ownHoliday, $entries );
		$this->assertTrue( $entries[ $ownHoliday ]['effective'] );

		// by category
		$term = wp_insert_term( 'Trailers', Item::getTaxonomyName() );
		$this->assertIsArray( $term );
		wp_set_object_terms( $this->itemId, [ $term['term_id'] ], Item::getTaxonomyName() );
		update_post_meta( $holiday, \CommonsBooking\Model\Timeframe::META_ITEM_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_CATEGORY_ID );
		update_post_meta( $holiday, \CommonsBooking\Model\Timeframe::META_ITEM_CATEGORY_IDS, [ (string) $term['term_id'] ] );
		Timeframe::manageTimeframeMeta( $holiday );
		$this->assertArrayNotHasKey( $holiday, $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) ) );
	}

	/**
	 * A blocked timeframe for another item at "all locations" does not concern the location.
	 */
	public function testBlockedOtherItemAtAllLocationsIsHidden() {
		$otherItem = $this->createItem( 'Other item' );
		$blocked   = $this->createTimeframe( '', [ $otherItem ], $this->now, strtotime( '+1 day', $this->now ), Timeframe::REPAIR_ID );
		update_post_meta( $blocked, \CommonsBooking\Model\Timeframe::META_LOCATION_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_ALL_ID );
		Timeframe::manageTimeframeMeta( $blocked );

		$this->assertArrayNotHasKey( $blocked, $this->entries( AssignedTimeframes::forLocation( $this->locationId, $this->now ) ) );
		$this->assertArrayHasKey( $blocked, $this->entries( AssignedTimeframes::forItem( $otherItem, $this->now ) ) );

		// blocked for the item of the location: shown and effective
		$ownBlocked = $this->createTimeframe( '', [ $this->itemId ], $this->now, strtotime( '+1 day', $this->now ), Timeframe::REPAIR_ID );
		update_post_meta( $ownBlocked, \CommonsBooking\Model\Timeframe::META_LOCATION_SELECTION_TYPE, \CommonsBooking\Model\Timeframe::SELECTION_ALL_ID );
		Timeframe::manageTimeframeMeta( $ownBlocked );
		$entries = $this->entries( AssignedTimeframes::forLocation( $this->locationId, $this->now ) );
		$this->assertArrayHasKey( $ownBlocked, $entries );
		$this->assertTrue( $entries[ $ownBlocked ]['effective'] );
	}

	public function testGroups() {
		$expired = $this->holiday( [ $this->locationId ], [ $this->itemId ], strtotime( '-20 days', $this->now ), strtotime( '-5 days', $this->now ) );
		$future  = $this->holiday( [ $this->locationId ], [ $this->itemId ], strtotime( '+5 days', $this->now ), strtotime( '+6 days', $this->now ) );
		$openEnd = $this->holiday( [ $this->locationId ], [ $this->itemId ], strtotime( '-5 days', $this->now ), null );

		$groups = AssignedTimeframes::forItem( $this->itemId, $this->now )['groups'];
		$ids    = fn( $group ) => array_column( $groups[ $group ], 'post_id' );

		$this->assertContains( $expired, $ids( AssignedTimeframes::GROUP_EXPIRED ) );
		$this->assertContains( $future, $ids( AssignedTimeframes::GROUP_FUTURE ) );
		$this->assertContains( $openEnd, $ids( AssignedTimeframes::GROUP_CURRENT ) );
		$this->assertContains( $this->bookableId, $ids( AssignedTimeframes::GROUP_CURRENT ) );
	}

	public function testDraftsAreShownButNotEffectiveAndBookingsAreExcluded() {
		$draft   = $this->holiday( [ $this->locationId ], [ $this->itemId ], $this->now, strtotime( '+1 day', $this->now ), 'draft' );
		$booking = $this->createBooking(
			$this->locationId,
			$this->itemId,
			strtotime( '+1 day', $this->now ),
			strtotime( '+2 days', $this->now )
		);

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $draft, $entries );
		$this->assertFalse( $entries[ $draft ]['effective'] );
		$this->assertArrayNotHasKey( $booking, $entries );
	}

	public function testRestrictions() {
		$otherItem     = $this->createItem( 'Other item' );
		$otherLocation = $this->createLocation( 'Other location' );
		$start         = strtotime( '-1 day', $this->now );

		$all           = $this->createRestriction( Restriction::TYPE_REPAIR, 0, 0, $start, null );
		$own           = $this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $this->itemId, $start, null );
		$hint          = $this->createRestriction( Restriction::TYPE_HINT, 0, $this->itemId, $start, null );
		$solved        = $this->createRestriction( Restriction::TYPE_REPAIR, 0, $this->itemId, $start, null, Restriction::STATE_SOLVED );
		$otherItemOnly = $this->createRestriction( Restriction::TYPE_REPAIR, 0, $otherItem, $start, null );
		$allAtOther    = $this->createRestriction( Restriction::TYPE_REPAIR, $otherLocation, 0, $start, null );
		$ownAtOther    = $this->createRestriction( Restriction::TYPE_REPAIR, $otherLocation, $this->itemId, $start, null );

		$result  = AssignedTimeframes::forItem( $this->itemId, $this->now );
		$entries = $this->entries( $result );

		$this->assertTrue( $entries[ $all ]['blocking'] );
		$this->assertEquals( AssignedTimeframes::ASSIGNMENT_ALL, $entries[ $all ]['items']['mode'] );
		$this->assertTrue( $entries[ $own ]['effective'] );
		$this->assertFalse( $entries[ $hint ]['blocking'] );
		$this->assertEquals( AssignedTimeframes::GROUP_EXPIRED, $entries[ $solved ]['group'] );
		$this->assertArrayNotHasKey( $otherItemOnly, $entries );
		$this->assertArrayNotHasKey( $allAtOther, $entries );
		$this->assertArrayHasKey( $ownAtOther, $entries );
		$this->assertFalse( $entries[ $ownAtOther ]['effective'] );

		$locationEntries = $this->entries( AssignedTimeframes::forLocation( $this->locationId, $this->now ) );
		$this->assertArrayHasKey( $all, $locationEntries );
		$this->assertArrayHasKey( $own, $locationEntries );
		$this->assertArrayNotHasKey( $otherItemOnly, $locationEntries );
		$this->assertArrayNotHasKey( $allAtOther, $locationEntries );
	}

	public function testDeletedItemInListIsIgnored() {
		$deletedItem = $this->createItem( 'Deleted item' );
		$holiday     = $this->holiday( [ $this->locationId ], [ $this->itemId, $deletedItem ], $this->now, strtotime( '+1 day', $this->now ) );
		wp_delete_post( $deletedItem, true );

		$entries = $this->entries( AssignedTimeframes::forItem( $this->itemId, $this->now ) );
		$this->assertArrayHasKey( $holiday, $entries );
		// the raw ids are kept, the metabox skips posts that don't exist anymore
		$this->assertContains( $deletedItem, $entries[ $holiday ]['items']['ids'] );
		$this->assertTrue( $entries[ $holiday ]['effective'] );
	}

	public function testManagerSeesForeignTimeframesReadOnly() {
		$this->createCBManager();
		$managedItem = $this->createItem( 'Managed item', 'publish', [ $this->cbManagerUserID ] );
		$this->createTimeframe(
			$this->locationId,
			$managedItem,
			strtotime( '-10 days', $this->now ),
			strtotime( '+30 days', $this->now ),
			Timeframe::BOOKABLE_ID,
			'on',
			'w',
			0,
			'8:00 AM',
			'12:00 PM',
			'publish',
			[ '1', '2', '3', '4', '5', '6', '7' ],
			'',
			self::USER_ID
		);
		$own     = $this->holiday( [ $this->locationId ], [ $managedItem ], $this->now, strtotime( '+1 day', $this->now ) );
		$foreign = $this->createHolidayTimeframeForAllItemsAndLocations();
		wp_update_post(
			[
				'ID'          => $own,
				'post_author' => $this->cbManagerUserID,
			]
		);

		wp_set_current_user( $this->cbManagerUserID );
		$entries = $this->entries( AssignedTimeframes::forItem( $managedItem, $this->now ) );

		$this->assertArrayHasKey( $foreign, $entries );
		$this->assertFalse( $entries[ $foreign ]['can_edit'] );
		$this->assertTrue( $entries[ $own ]['can_edit'] );

		$this->createAdministrator();
		wp_set_current_user( $this->adminUserID );
		$entries = $this->entries( AssignedTimeframes::forItem( $managedItem, $this->now ) );
		$this->assertTrue( $entries[ $foreign ]['can_edit'] );
	}

	/**
	 * The booster index (if available) and postmeta must find the same posts.
	 */
	public function testIndexAndPostmetaFindTheSamePosts() {
		if ( getenv( 'CB_USE_BOOSTER' ) !== '0' ) {
			$this->assertTrue( \CommonsBooking\Repository\Index::isAvailable(), 'index should be used with booster' );
		}
		$otherItem = $this->createItem( 'Other item' );
		$this->holiday( [ $this->locationId ], [ $this->itemId, $otherItem ], $this->now, strtotime( '+1 day', $this->now ) );
		$this->createHolidayTimeframeForAllItemsAndLocations();
		$this->createRestriction( Restriction::TYPE_REPAIR, 0, 0, $this->now, null );
		$this->createRestriction( Restriction::TYPE_REPAIR, $this->locationId, $otherItem, $this->now, null );

		foreach ( [
			'item' => [ $this->itemId, $otherItem ],
			'location' => [ $this->locationId ],
		] as $entity => $ids ) {
			foreach ( $ids as $id ) {
				$fast   = [ AssignedTimeframes::timeframeCandidateIds( $entity, $id ), AssignedTimeframes::restrictionCandidateIds( $entity, $id ) ];
				$legacy = Booster::withoutBooster(
					fn() => [ AssignedTimeframes::timeframeCandidateIds( $entity, $id ), AssignedTimeframes::restrictionCandidateIds( $entity, $id ) ]
				);
				$this->assertEquals( $legacy, $fast, "$entity $id" );
			}
		}
	}
}
