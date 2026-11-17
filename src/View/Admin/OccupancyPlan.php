<?php

namespace CommonsBooking\View\Admin;

use CommonsBooking\Service\OccupancyPlan as Plan;
use CommonsBooking\View\OccupancyPlan as PlanView;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;

/**
 * Admin page "Occupancy plan" (menu, row actions in the location and item lists). Rendering, permissions and the
 * day explanation are shared with the shortcode [cb_occupancy_plan] in {@see PlanView}.
 *
 * @see design/belegungsplan.md
 */
class OccupancyPlan {

	public const SLUG = 'cb-occupancy-plan';

	/**
	 * Hook suffix of the admin page.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * @return void
	 */
	public static function init(): void {
		PlanView::init();
		add_filter( 'post_row_actions', [ self::class, 'addRowAction' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/**
	 * @return void
	 */
	public static function addMenu(): void {
		self::$hook = (string) add_submenu_page(
			'cb-dashboard',
			esc_html__( 'Occupancy plan', 'commonsbooking' ),
			esc_html__( 'Occupancy plan', 'commonsbooking' ),
			'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @param string $hook
	 *
	 * @return void
	 */
	public static function enqueue( $hook ): void {
		if ( self::$hook !== '' && $hook === self::$hook ) {
			PlanView::enqueueAssets();
		}
	}

	/**
	 * Link to the page.
	 *
	 * @param array $args query args (view, location, item, cat, from, days, zoom, canceled)
	 *
	 * @return string
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Row action "Occupancy plan" in the location and item lists.
	 *
	 * @param array    $actions
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	public static function addRowAction( array $actions, \WP_Post $post ): array {
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			return $actions;
		}
		if ( $post->post_type === Location::$postType && PlanView::isAllowedLocation( $post->ID ) ) {
			$url = self::url( [ 'location' => $post->ID ] );
		} elseif ( $post->post_type === Item::$postType && PlanView::isAllowedItem( $post->ID ) ) {
			$url = self::url(
				[
					'view' => Plan::VIEW_ITEM,
					'item' => $post->ID,
				]
			);
		} else {
			return $actions;
		}
		$actions['cb-occupancy-plan'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Occupancy plan', 'commonsbooking' ) . '</a>';

		return $actions;
	}

	/**
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			wp_die( esc_html__( 'Access denied', 'commonsbooking' ) );
		}
		echo PlanView::render( [ 'base' => self::url() ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render()
	}
}
