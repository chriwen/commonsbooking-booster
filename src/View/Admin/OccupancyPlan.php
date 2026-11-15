<?php

namespace CommonsBooking\View\Admin;

use CommonsBooking\Repository\Item as ItemRepository;
use CommonsBooking\Repository\Location as LocationRepository;
use CommonsBooking\Service\OccupancyPlan as Plan;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;

/**
 * Admin page "Occupancy plan": timeline per location (rows = items) or per item (rows = locations) with bookable
 * days, blocks, restrictions and bookings. Click on a bar shows its details, click on a day explains the day.
 * Read-only, for admins and CB managers (only locations / items they may see).
 *
 * @see design/belegungsplan.md
 */
class OccupancyPlan {

	public const SLUG = 'cb-occupancy-plan';

	public const AJAX_DAY = 'cb_occupancy_day';

	/**
	 * Selectable range lengths in days.
	 */
	private const SPANS = [ 7, 14, 28, 56, 91, 182, 365 ];

	private const DEFAULT_SPAN = 28;

	/**
	 * Days shown before today by default.
	 */
	private const DEFAULT_DAYS_BEFORE = 3;

	/**
	 * Day widths in px.
	 */
	private const ZOOMS = [
		'xs' => 18,
		's'  => 28,
		'm'  => 44,
		'l'  => 64,
		'xl' => 96,
	];

	/**
	 * Below this width (px) a booking shows initials instead of the name, blocks show no text.
	 */
	private const MIN_LABEL_WIDTH = 60;

	/**
	 * User meta with the last selection (view, and per view ids, category, period, day width, canceled). Only read
	 * by this page, so it does not affect a downgrade.
	 */
	public const USER_META = 'commonsbooking_occupancy_plan';

	/**
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'post_row_actions', [ self::class, 'addRowAction' ], 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX_DAY, [ self::class, 'handleDay' ] );
	}

	/**
	 * @return void
	 */
	public static function addMenu(): void {
		add_submenu_page(
			'cb-dashboard',
			esc_html__( 'Occupancy plan', 'commonsbooking' ),
			esc_html__( 'Occupancy plan', 'commonsbooking' ),
			'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
			self::SLUG,
			[ self::class, 'render' ]
		);
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
		if ( $post->post_type === Location::$postType && self::isAllowedLocation( $post->ID ) ) {
			$url = self::url( [ 'location' => $post->ID ] );
		} elseif ( $post->post_type === Item::$postType && self::isAllowedItem( $post->ID ) ) {
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
	 * Locations the current user may see in the plan: all for admins, otherwise those the user manages or authored.
	 *
	 * @return array<int, string> id => title
	 */
	public static function allowedLocations(): array {
		return self::titles( LocationRepository::getByCurrentUser() );
	}

	/**
	 * Items the current user may see in the plan: all for admins, otherwise those the user manages or authored.
	 *
	 * @return array<int, string> id => title
	 */
	public static function allowedItems(): array {
		return self::titles( ItemRepository::getByCurrentUser() );
	}

	/**
	 * @param \WP_Post[] $posts
	 *
	 * @return array<int, string>
	 */
	private static function titles( array $posts ): array {
		$titles = [];
		foreach ( $posts as $post ) {
			if ( $post->post_status !== 'trash' ) {
				$titles[ (int) $post->ID ] = $post->post_title;
			}
		}
		natcasesort( $titles );

		return $titles;
	}

	/**
	 * Choices of the search field: allowed locations or items that are part of an active combination
	 * ({@see Plan::activeIds()}), plus the ones already selected (e.g. a draft opened via the row action).
	 *
	 * @param bool               $isItemView
	 * @param array<int, string> $allowed id => title
	 * @param int[]              $selected
	 *
	 * @return array<int, string> id => title
	 */
	private static function choices( bool $isItemView, array $allowed, array $selected ): array {
		$active  = Plan::activeIds()[ $isItemView ? 'items' : 'locations' ];
		$choices = array_intersect_key( $allowed, array_flip( array_merge( $active, $selected ) ) );
		natcasesort( $choices );

		return $choices;
	}

	/**
	 * @return array saved selection of the current user {@see self::USER_META}
	 */
	private static function savedState(): array {
		$saved = get_user_meta( get_current_user_id(), self::USER_META, true );

		return is_array( $saved ) ? $saved : [];
	}

	/**
	 * Remembers the selection per view (not the start date, which follows today).
	 *
	 * @param array  $saved
	 * @param string $view
	 * @param array  $state
	 *
	 * @return void
	 */
	private static function saveState( array $saved, string $view, array $state ): void {
		$new          = $saved;
		$new['view']  = $view;
		$new[ $view ] = [
			'ids'      => array_values( array_map( 'intval', $state['ids'] ) ),
			'cat'      => (int) $state['cat'],
			'days'     => (int) $state['days'],
			'zoom'     => (string) $state['zoom'],
			'canceled' => (int) $state['canceled'],
		];
		if ( $new !== $saved ) {
			update_user_meta( get_current_user_id(), self::USER_META, $new );
		}
	}

	/**
	 * @param mixed $value request value: one id, a list or a comma separated string
	 *
	 * @return int[]
	 */
	private static function parseIds( $value ): array {
		if ( is_string( $value ) || is_int( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * @param int $locationId
	 *
	 * @return bool
	 */
	public static function isAllowedLocation( int $locationId ): bool {
		return isset( self::allowedLocations()[ $locationId ] );
	}

	/**
	 * @param int $itemId
	 *
	 * @return bool
	 */
	public static function isAllowedItem( int $itemId ): bool {
		return isset( self::allowedItems()[ $itemId ] );
	}

	/**
	 * AJAX: explanation of a day (all rows or one row).
	 *
	 * @return void
	 */
	public static function handleDay(): void {
		check_ajax_referer( self::AJAX_DAY );
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			wp_send_json_error( null, 403 );
		}
		$view    = isset( $_POST['view'] ) && $_POST['view'] === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		$ids     = self::parseIds( isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '' );
		$row     = isset( $_POST['row'] ) ? absint( $_POST['row'] ) : 0;
		$date    = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$allowed = $view === Plan::VIEW_ITEM ? self::allowedItems() : self::allowedLocations();
		if ( ! $ids || array_diff_key( array_flip( $ids ), $allowed ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( null, 403 );
		}

		$rows = [];
		foreach ( $ids as $id ) {
			$result = Plan::explainDay( $view, $id, $date, $row ?: null );
			$date   = $result['date'];
			foreach ( $result['rows'] as $explained ) {
				if ( count( $ids ) > 1 ) {
					$explained['title'] = $allowed[ $id ] . ' · ' . $explained['title'];
				}
				$rows[] = $explained;
			}
		}
		wp_send_json_success(
			[
				'date'       => $date,
				'date_label' => date_i18n( 'l, ' . get_option( 'date_format' ), strtotime( $date ) ),
				'rows'       => $rows,
			]
		);
	}

	/**
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			wp_die( esc_html__( 'Access denied', 'commonsbooking' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page, parameters only select what is shown
		$saved = self::savedState();
		if ( isset( $_GET['view'] ) ) {
			$view = $_GET['view'] === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		} else {
			$view = ( $saved['view'] ?? '' ) === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		}
		$isItemView = $view === Plan::VIEW_ITEM;
		$allowed    = $isItemView ? self::allowedItems() : self::allowedLocations();
		$param      = $isItemView ? 'item' : 'location';
		// Explicit selection (form, links with ids) or the remembered one (menu, tabs)
		$explicit = isset( $_GET['sel'] ) || isset( $_GET[ $param ] );
		$remember = $explicit ? [] : (array) ( $saved[ $view ] ?? [] );
		$input    = fn( string $key ) => isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : ( $remember[ $key ] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below

		$ids     = array_values( array_filter( self::parseIds( $explicit ? wp_unslash( $_GET[ $param ] ?? [] ) : ( $remember['ids'] ?? [] ) ), fn( $id ) => isset( $allowed[ $id ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint in parseIds
		$choices = self::choices( $isItemView, $allowed, $ids );
		if ( ! $ids && ! $explicit && $choices ) {
			$ids = [ (int) array_key_first( $choices ) ];
		}
		$taxonomy = $isItemView ? Location::getTaxonomyName() : Item::getTaxonomyName();
		$cat      = absint( $input( 'cat' ) );
		$span     = absint( $input( 'days' ) ?? self::DEFAULT_SPAN );
		if ( ! in_array( $span, self::SPANS, true ) ) {
			$span = self::DEFAULT_SPAN;
		}
		$zoom = sanitize_key( (string) $input( 'zoom' ) );
		if ( ! isset( self::ZOOMS[ $zoom ] ) ) {
			$zoom = '';
		}
		$today    = strtotime( 'today', current_time( 'timestamp' ) );
		$from     = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : date( 'Y-m-d', strtotime( '-' . self::DEFAULT_DAYS_BEFORE . ' days', $today ) );
		$canceled = ! empty( $input( 'canceled' ) );
		// phpcs:enable

		[ $from, $to ] = Plan::normalizeRange( $from, date( 'Y-m-d', strtotime( $from . ' +' . ( $span - 1 ) . ' days' ) ?: $today ) );

		$state = [
			'view'     => $view,
			$param     => $ids,
			'cat'      => $cat,
			'from'     => $from,
			'days'     => $span,
			'zoom'     => $zoom,
			'canceled' => $canceled ? 1 : 0,
		];

		echo '<div class="wrap cb-plan-wrap" style="--cb-plan-day:' . (int) self::dayWidth( $zoom, $span ) . 'px">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Occupancy plan', 'commonsbooking' ) . '</h1>';
		self::styles();
		self::renderTabs( $view );

		if ( ! $allowed ) {
			echo '<p>' . esc_html( $isItemView ? __( 'No item available. You need to be admin of at least one item.', 'commonsbooking' ) : __( 'No location available. You need to be admin of at least one location.', 'commonsbooking' ) ) . '</p></div>';

			return;
		}

		self::renderFilters( $state, $choices, $allowed, $taxonomy );

		if ( ! $ids ) {
			echo '<p class="cb-plan-empty-selection">' . esc_html( $isItemView ? __( 'Select at least one item.', 'commonsbooking' ) : __( 'Select at least one location.', 'commonsbooking' ) ) . '</p></div>';

			return;
		}
		self::saveState( $saved, $view, array_merge( $state, [ 'ids' => $ids ] ) );

		$onlyRows = null;
		if ( $cat ) {
			$objects  = get_objects_in_term( $cat, $taxonomy );
			$onlyRows = is_array( $objects ) ? array_map( 'intval', $objects ) : [];
		}
		$groups = [];
		foreach ( $ids as $id ) {
			$post     = get_post( $id );
			$groups[] = [
				'id'       => $id,
				'title'    => $allowed[ $id ],
				'edit_url' => $post ? Plan::editUrl( $post ) : null,
				'plan'     => $isItemView
					? Plan::buildForItem( $id, $from, $to, $canceled, $onlyRows )
					: Plan::build( $id, $from, $to, $canceled, $onlyRows ),
			];
		}

		self::renderLegend();
		self::renderPlan( $groups, (int) self::dayWidth( $zoom, $span ) );
		self::renderPanel( $groups, $view );
		echo '</div>';
	}

	/**
	 * @param string $zoom
	 * @param int    $span
	 *
	 * @return int day width in px
	 */
	private static function dayWidth( string $zoom, int $span ): int {
		if ( isset( self::ZOOMS[ $zoom ] ) ) {
			return self::ZOOMS[ $zoom ];
		}
		if ( $span <= 14 ) {
			return self::ZOOMS['xl'];
		}
		if ( $span <= 28 ) {
			return self::ZOOMS['l'];
		}
		if ( $span <= 56 ) {
			return self::ZOOMS['m'];
		}

		return $span <= 91 ? self::ZOOMS['s'] : self::ZOOMS['xs'];
	}

	/**
	 * @param string $view
	 *
	 * @return void
	 */
	private static function renderTabs( string $view ): void {
		echo '<nav class="nav-tab-wrapper cb-plan-tabs">';
		foreach ( [
			Plan::VIEW_LOCATION => __( 'By location', 'commonsbooking' ),
			Plan::VIEW_ITEM     => __( 'By item', 'commonsbooking' ),
		] as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $view ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( [ 'view' => $key ] ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * @param array              $state current query args
	 * @param array<int, string> $choices locations or items offered in the search field
	 * @param array<int, string> $allowed all locations or items the user may see (titles of the selection)
	 * @param string             $taxonomy category taxonomy of the rows
	 *
	 * @return void
	 */
	private static function renderFilters( array $state, array $choices, array $allowed, string $taxonomy ): void {
		$isItemView = $state['view'] === Plan::VIEW_ITEM;
		$param      = $isItemView ? 'item' : 'location';
		$base       = array_filter( $state );
		$prev       = self::url( array_merge( $base, [ 'from' => date( 'Y-m-d', strtotime( $state['from'] . ' -' . $state['days'] . ' days' ) ) ] ) );
		$next       = self::url( array_merge( $base, [ 'from' => date( 'Y-m-d', strtotime( $state['from'] . ' +' . $state['days'] . ' days' ) ) ] ) );
		$todayArgs  = $base;
		unset( $todayArgs['from'] );
		$today = self::url( $todayArgs );
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			]
		);

		echo '<form method="get" class="cb-plan-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="view" value="' . esc_attr( $state['view'] ) . '">';
		echo '<input type="hidden" name="sel" value="1">';
		echo '<div class="cb-plan-filter-row">';
		self::renderPicker( $param, $state[ $param ], $choices, $allowed, $isItemView );
		echo '<button type="submit" class="button cb-plan-show">' . esc_html__( 'Show', 'commonsbooking' ) . '</button>';
		echo '</div><div class="cb-plan-filter-row">';
		if ( is_array( $terms ) && $terms ) {
			echo '<label>' . esc_html( $isItemView ? __( 'Location category', 'commonsbooking' ) : __( 'Item category', 'commonsbooking' ) ) . ' <select name="cat" onchange="this.form.submit()">';
			echo '<option value="0">' . esc_html__( 'All', 'commonsbooking' ) . '</option>';
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( $term->term_id, $state['cat'], false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '<label><input type="checkbox" name="canceled" value="1"' . checked( (bool) $state['canceled'], true, false ) . ' onchange="this.form.submit()"> ' . esc_html__( 'Show canceled bookings', 'commonsbooking' ) . '</label>';
		echo '</div><div class="cb-plan-filter-row">';
		echo '<label>' . esc_html__( 'From', 'commonsbooking' ) . ' <input type="date" name="from" value="' . esc_attr( $state['from'] ) . '" onchange="this.form.submit()"></label>';
		echo '<label>' . esc_html__( 'Period', 'commonsbooking' ) . ' <select name="days" onchange="this.form.submit()">';
		foreach ( self::SPANS as $days ) {
			echo '<option value="' . esc_attr( (string) $days ) . '"' . selected( $days, $state['days'], false ) . '>' . esc_html( self::spanLabel( $days ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Day width', 'commonsbooking' ) . ' <select name="zoom" onchange="this.form.submit()">';
		$zoomLabels = [
			''   => __( 'Automatic', 'commonsbooking' ),
			'xs' => __( 'Very narrow', 'commonsbooking' ),
			's'  => __( 'Narrow', 'commonsbooking' ),
			'm'  => __( 'Medium', 'commonsbooking' ),
			'l'  => __( 'Wide', 'commonsbooking' ),
			'xl' => __( 'Very wide', 'commonsbooking' ),
		];
		foreach ( $zoomLabels as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $state['zoom'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<span class="cb-plan-nav">';
		echo '<a class="button" href="' . esc_url( $prev ) . '" aria-label="' . esc_attr__( 'Earlier', 'commonsbooking' ) . '">&larr;</a>';
		echo '<a class="button" href="' . esc_url( $today ) . '">' . esc_html__( 'Today', 'commonsbooking' ) . '</a>';
		echo '<a class="button" href="' . esc_url( $next ) . '" aria-label="' . esc_attr__( 'Later', 'commonsbooking' ) . '">&rarr;</a>';
		echo '</span>';
		echo '</div></form>';
	}

	/**
	 * Search field with multiple selection: chips for the selected entries (hidden inputs), a text field that filters
	 * the choices as you type. Without JavaScript the chips still submit the current selection.
	 *
	 * @param string             $param location or item
	 * @param int[]              $selected
	 * @param array<int, string> $choices
	 * @param array<int, string> $allowed
	 * @param bool               $isItemView
	 *
	 * @return void
	 */
	private static function renderPicker( string $param, array $selected, array $choices, array $allowed, bool $isItemView ): void {
		$options = [];
		foreach ( $choices as $choiceId => $title ) {
			$options[] = [ $choiceId, $title ];
		}
		$hint = $isItemView
			? __( 'Only published items with a current or upcoming bookable timeframe are listed.', 'commonsbooking' )
			: __( 'Only published locations with a current or upcoming bookable timeframe are listed.', 'commonsbooking' );
		$i18n = [
			/* translators: %s: name of a location or item */
			'remove'    => __( 'Remove %s', 'commonsbooking' ),
			'noMatches' => __( 'No matches', 'commonsbooking' ),
		];

		echo '<div class="cb-plan-picker" data-name="' . esc_attr( $param ) . '[]" data-i18n="' . esc_attr( (string) wp_json_encode( $i18n ) ) . '">';
		echo '<span class="cb-plan-picker-label" id="cb-plan-picker-label">' . esc_html( $isItemView ? __( 'Items', 'commonsbooking' ) : __( 'Locations', 'commonsbooking' ) ) . '</span>';
		echo '<div class="cb-plan-picker-box">';
		echo '<ul class="cb-plan-chips">';
		foreach ( $selected as $id ) {
			echo '<li class="cb-plan-chip" data-id="' . (int) $id . '"><span>' . esc_html( $allowed[ $id ] ?? '#' . $id ) . '</span>';
			/* translators: %s: name of a location or item */
			echo '<button type="button" class="cb-plan-chip-remove" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'commonsbooking' ), $allowed[ $id ] ?? '#' . $id ) ) . '">&times;</button>';
			echo '<input type="hidden" name="' . esc_attr( $param ) . '[]" value="' . (int) $id . '"></li>';
		}
		echo '</ul>';
		echo '<input type="text" class="cb-plan-picker-input" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cb-plan-picker-list" aria-labelledby="cb-plan-picker-label" autocomplete="off" placeholder="' . esc_attr( $isItemView ? __( 'Search item …', 'commonsbooking' ) : __( 'Search location …', 'commonsbooking' ) ) . '" title="' . esc_attr( $hint ) . '">';
		echo '<ul class="cb-plan-picker-list" id="cb-plan-picker-list" role="listbox" aria-multiselectable="true" hidden></ul>';
		echo '</div>';
		echo '<script type="application/json" class="cb-plan-picker-options">' . wp_json_encode( $options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		echo '</div>';
		?>
<script>
(function(){
	function el(tag, cls, text){
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
	document.querySelectorAll('.cb-plan-picker').forEach(function(picker){
		var options = JSON.parse(picker.querySelector('.cb-plan-picker-options').textContent);
		var pt = JSON.parse(picker.getAttribute('data-i18n'));
		var chips = picker.querySelector('.cb-plan-chips');
		var input = picker.querySelector('.cb-plan-picker-input');
		var list = picker.querySelector('.cb-plan-picker-list');
		var form = picker.closest('form');
		var name = picker.getAttribute('data-name');
		var matches = [];
		var current = -1;

		function fold(s){ return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
		function selected(){
			var ids = {};
			chips.querySelectorAll('.cb-plan-chip').forEach(function(c){ ids[c.getAttribute('data-id')] = true; });
			return ids;
		}
		function changed(){ form.classList.add('is-dirty'); }
		function addChip(option){
			var li = el('li', 'cb-plan-chip');
			li.setAttribute('data-id', option[0]);
			li.appendChild(el('span', null, option[1]));
			var b = el('button', 'cb-plan-chip-remove', '×');
			b.type = 'button';
			b.setAttribute('aria-label', pt.remove.replace('%s', option[1]));
			li.appendChild(b);
			var h = el('input');
			h.type = 'hidden';
			h.name = name;
			h.value = option[0];
			li.appendChild(h);
			chips.appendChild(li);
			changed();
		}
		function closeList(){
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			current = -1;
		}
		function highlight(index){
			var items = list.querySelectorAll('[role=option]');
			if (!items.length) { return; }
			current = (index + items.length) % items.length;
			items.forEach(function(li, i){ li.classList.toggle('is-current', i === current); });
			input.setAttribute('aria-activedescendant', items[current].id);
			items[current].scrollIntoView({block: 'nearest'});
		}
		function openList(){
			var q = fold(input.value.trim());
			var ids = selected();
			matches = options.filter(function(o){ return !ids[o[0]] && (!q || fold(o[1]).indexOf(q) !== -1); });
			list.textContent = '';
			if (!matches.length) {
				list.appendChild(el('li', 'cb-plan-picker-none', pt.noMatches));
			}
			matches.forEach(function(o, i){
				var li = el('li', null, o[1]);
				li.id = 'cb-plan-option-' + o[0];
				li.setAttribute('role', 'option');
				li.setAttribute('data-index', i);
				list.appendChild(li);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			current = -1;
			if (q && matches.length) { highlight(0); }
		}
		function choose(index){
			if (!matches[index]) { return; }
			addChip(matches[index]);
			input.value = '';
			openList();
			input.focus();
		}

		input.addEventListener('focus', openList);
		input.addEventListener('input', openList);
		input.addEventListener('keydown', function(e){
			if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { openList(); } highlight(current + 1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(current - 1); }
			else if (e.key === 'Enter') {
				e.preventDefault();
				if (!list.hidden && current >= 0) { choose(current); }
				else if (!input.value.trim()) { form.submit(); }
			}
			else if (e.key === 'Escape') { if (!list.hidden) { e.stopPropagation(); closeList(); } }
			else if (e.key === 'Backspace' && !input.value) {
				var last = chips.lastElementChild;
				if (last) { last.remove(); changed(); if (!list.hidden) { openList(); } }
			}
		});
		input.addEventListener('blur', function(){ setTimeout(function(){ if (document.activeElement !== input) { closeList(); } }, 150); });
		// mousedown keeps the focus in the input
		list.addEventListener('mousedown', function(e){
			e.preventDefault();
			var li = e.target.closest('[role=option]');
			if (li) { choose(parseInt(li.getAttribute('data-index'), 10)); }
		});
		chips.addEventListener('click', function(e){
			var b = e.target.closest('.cb-plan-chip-remove');
			if (!b) { return; }
			b.closest('.cb-plan-chip').remove();
			changed();
			input.focus();
		});
		picker.querySelector('.cb-plan-picker-box').addEventListener('click', function(e){ if (e.target === this || e.target === chips) { input.focus(); } });
	});
})();
</script>
		<?php
	}

	/**
	 * @param int $days
	 *
	 * @return string
	 */
	private static function spanLabel( int $days ): string {
		if ( $days % 7 === 0 && $days <= 56 ) {
			/* translators: %d: number of weeks */
			return sprintf( _n( '%d week', '%d weeks', $days / 7, 'commonsbooking' ), $days / 7 );
		}
		$months = (int) round( $days / 30.4 );

		/* translators: %d: number of months */
		return sprintf( _n( '%d month', '%d months', $months, 'commonsbooking' ), $months );
	}

	/**
	 * @return void
	 */
	private static function renderLegend(): void {
		$entries = [
			'bookable'                => __( 'Bookable', 'commonsbooking' ),
			'booking'                 => __( 'Booking', 'commonsbooking' ),
			'booking is-unconfirmed'  => __( 'Booking (unconfirmed)', 'commonsbooking' ),
			'booking has-conflict'    => __( 'Conflict', 'commonsbooking' ),
			'holiday'                 => __( 'Holidays or location closed', 'commonsbooking' ),
			'repair'                  => __( 'Blocked (not overbookable)', 'commonsbooking' ),
			'restriction is-repair'   => __( 'Total breakdown', 'commonsbooking' ),
			'restriction is-hint'     => __( 'Notice', 'commonsbooking' ),
			'window'                  => __( 'Beyond advance booking period', 'commonsbooking' ),
		];
		echo '<ul class="cb-plan-legend">';
		foreach ( $entries as $class => $label ) {
			echo '<li><span class="cb-plan-swatch cb-plan-' . esc_attr( $class ) . '"></span>' . esc_html( $label ) . '</li>';
		}
		echo '<li class="cb-plan-hint">' . esc_html__( 'Click a bar for details, a day for the reasons.', 'commonsbooking' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * @param array $groups one per selected location or item: id, title, edit_url, plan {@see Plan::build()}
	 * @param int   $dayWidth px
	 *
	 * @return void
	 */
	private static function renderPlan( array $groups, int $dayWidth ): void {
		$plan  = $groups[0]['plan'];
		$days  = $plan['days'];
		$count = count( $days );

		echo '<div class="cb-plan-scroll"><div class="cb-plan" style="--cb-plan-days:' . (int) $count . '">';

		// Month row
		echo '<div class="cb-plan-line cb-plan-months"><div class="cb-plan-label"></div>';
		$start = 0;
		for ( $i = 1; $i <= $count; $i++ ) {
			if ( $i === $count || substr( $days[ $i ], 0, 7 ) !== substr( $days[ $start ], 0, 7 ) ) {
				$format = ( $i - $start ) * $dayWidth < 90 ? 'M y' : 'F Y';
				echo '<div class="cb-plan-month" style="grid-column:' . (int) ( $start + 2 ) . ' / ' . (int) ( $i + 2 ) . '">' . esc_html( date_i18n( $format, strtotime( $days[ $start ] ) ) ) . '</div>';
				$start = $i;
			}
		}
		echo '</div>';

		// Day row: buttons explain the day for all rows
		echo '<div class="cb-plan-line cb-plan-days"><div class="cb-plan-label"></div>';
		foreach ( $days as $i => $date ) {
			$ts      = strtotime( $date );
			$weekday = $dayWidth >= 28 ? '<span>' . esc_html( date_i18n( $dayWidth >= 44 ? 'D' : 'D', $ts ) ) . '</span>' : '';
			echo '<button type="button" class="cb-plan-dayhead' . esc_attr( self::dayClasses( $date, $plan['today'], null ) ) . '" style="grid-column:' . (int) ( $i + 2 ) . '" data-date="' . esc_attr( $date ) . '" data-tip="' . esc_attr( date_i18n( 'l, ' . get_option( 'date_format' ), $ts ) . ' · ' . __( 'week', 'commonsbooking' ) . ' ' . date( 'W', $ts ) ) . '">';
			echo $weekday . '<b>' . esc_html( date( 'j', $ts ) ) . '</b></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
		}
		echo '</div>';

		foreach ( $groups as $group ) {
			self::renderGroup( $group, $dayWidth, count( $groups ) > 1 );
		}

		echo '</div></div>';
	}

	/**
	 * Rows of one selected location or item, with a heading line if several are selected.
	 *
	 * @param array $group
	 * @param int   $dayWidth
	 * @param bool  $withHeading
	 *
	 * @return void
	 */
	private static function renderGroup( array $group, int $dayWidth, bool $withHeading ): void {
		$plan    = $group['plan'];
		$groupId = (int) $group['id'];

		if ( $withHeading ) {
			$title = $group['edit_url']
				? '<a href="' . esc_url( $group['edit_url'] ) . '">' . esc_html( $group['title'] ) . '</a>'
				: esc_html( $group['title'] );
			echo '<div class="cb-plan-line cb-plan-group" data-group="' . (int) $groupId . '"><div class="cb-plan-label">' . $title . '</div><div class="cb-plan-group-fill"></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
		}

		if ( $plan['station']['segments'] ) {
			$label = $plan['view'] === Plan::VIEW_ITEM ? __( 'All locations of the item', 'commonsbooking' ) : __( 'All items of the location', 'commonsbooking' );
			self::renderRow( $plan, $dayWidth, '<strong>' . esc_html( $label ) . '</strong>', $plan['station']['lanes'], $plan['station']['segments'], null, 'cb-plan-station', 0, $groupId );
		}

		if ( ! $plan['rows'] ) {
			echo '<p class="cb-plan-empty">' . esc_html( $plan['view'] === Plan::VIEW_ITEM ? __( 'No locations with timeframes or bookings for this item in the selected period.', 'commonsbooking' ) : __( 'No items with timeframes or bookings at this location in the selected period.', 'commonsbooking' ) ) . '</p>';
		}

		foreach ( $plan['rows'] as $row ) {
			$label = $row['edit_url']
				? '<a href="' . esc_url( $row['edit_url'] ) . '">' . esc_html( $row['title'] ) . '</a>'
				: esc_html( $row['title'] );
			if ( $row['conflicts'] ) {
				/* translators: %d: number of bookings with conflicts */
				$label .= ' <span class="cb-plan-badge" data-tip="' . esc_attr( sprintf( _n( '%d booking with conflict', '%d bookings with conflicts', $row['conflicts'], 'commonsbooking' ), $row['conflicts'] ) ) . '">⚠ ' . (int) $row['conflicts'] . '</span>';
			}
			self::renderRow( $plan, $dayWidth, $label, $row['lanes'], $row['segments'], $row['window_end'], '', (int) $row['row_id'], $groupId );
		}
	}

	/**
	 * @param array       $plan
	 * @param int         $dayWidth
	 * @param string      $labelHtml escaped html
	 * @param int         $lanes
	 * @param array       $segments
	 * @param string|null $windowEnd Y-m-d
	 * @param string      $class
	 * @param int         $rowId 0 = station row
	 * @param int         $groupId selected location or item the row belongs to
	 *
	 * @return void
	 */
	private static function renderRow( array $plan, int $dayWidth, string $labelHtml, int $lanes, array $segments, ?string $windowEnd, string $class, int $rowId, int $groupId ): void {
		echo '<div class="cb-plan-line cb-plan-row ' . esc_attr( $class ) . '" style="--cb-plan-lanes:' . (int) $lanes . '" data-row="' . (int) $rowId . '" data-group="' . (int) $groupId . '">';
		echo '<div class="cb-plan-label">' . $labelHtml . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by caller
		foreach ( $plan['days'] as $i => $date ) {
			echo '<div class="cb-plan-cell' . esc_attr( self::dayClasses( $date, $plan['today'], $windowEnd ) ) . '" style="grid-column:' . (int) ( $i + 2 ) . '" data-date="' . esc_attr( $date ) . '"></div>';
		}
		foreach ( $segments as $segment ) {
			self::renderSegment( $plan, $dayWidth, $segment );
		}
		echo '</div>';
	}

	/**
	 * @param array $plan
	 * @param int   $dayWidth
	 * @param array $segment
	 *
	 * @return void
	 */
	private static function renderSegment( array $plan, int $dayWidth, array $segment ): void {
		$post    = $plan['posts'][ $segment['post_id'] ] ?? null;
		$classes = [ 'cb-plan-seg', 'cb-plan-' . $segment['kind'] ];
		if ( $segment['kind'] === Plan::KIND_RESTRICTION ) {
			[ $type, $state ] = array_pad( explode( ':', $segment['state'] ), 2, '' );
			$classes[]        = 'is-' . $type;
			if ( $state !== 'active' ) {
				$classes[] = 'is-inactive';
			}
		} elseif ( $segment['kind'] === Plan::KIND_BOOKING ) {
			$classes[] = 'is-' . $segment['state'];
		} elseif ( $segment['state'] !== 'publish' && $segment['state'] !== 'confirmed' ) {
			$classes[] = 'is-inactive';
		}
		if ( ! empty( $segment['conflict'] ) ) {
			$classes[] = 'has-conflict';
		}
		if ( $segment['clip_start'] ) {
			$classes[] = 'clip-start';
		}
		if ( $segment['clip_end'] ) {
			$classes[] = 'clip-end';
		}
		$period = date_i18n( get_option( 'date_format' ), strtotime( $plan['days'][ $segment['from'] ] ) );
		if ( $segment['to'] !== $segment['from'] ) {
			$period .= ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $plan['days'][ $segment['to'] ] ) );
		}
		$tip = trim( ( $post['type_label'] ?? '' ) . ': ' . $segment['label'], ': ' ) . "\n" . $period;
		if ( ! empty( $post['conflicts'] ) ) {
			$tip .= "\n⚠ " . implode( "\n⚠ ", $post['conflicts'] );
		}
		$column = 'grid-column:' . (int) ( $segment['from'] + 2 ) . ' / ' . (int) ( $segment['to'] + 3 );

		if ( $segment['kind'] === Plan::KIND_BOOKABLE ) {
			// background only, clicks go to the day cells below
			printf( '<div class="%s" style="%s;grid-row:1 / -1" aria-hidden="true"></div>', esc_attr( implode( ' ', $classes ) ), esc_attr( $column ) );

			return;
		}

		$width = ( $segment['to'] - $segment['from'] + 1 ) * $dayWidth - 4;
		$text  = $segment['label'];
		if ( $width < self::MIN_LABEL_WIDTH ) {
			$text = $segment['kind'] === Plan::KIND_BOOKING && $width >= 18 ? self::initials( $segment['label'] ) : '';
		}
		if ( ! empty( $segment['conflict'] ) ) {
			$text = '⚠ ' . $text;
		}

		printf(
			'<button type="button" class="%s" style="%s;grid-row:%d" data-post="%d" data-tip="%s" aria-label="%s"><span>%s</span></button>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $column ),
			(int) ( $segment['lane'] + 1 ),
			(int) $segment['post_id'],
			esc_attr( $tip ),
			esc_attr( str_replace( "\n", ', ', $tip ) ),
			esc_html( $text )
		);
	}

	/**
	 * @param string $name
	 *
	 * @return string e.g. "AM" for "Anna Muster"
	 */
	private static function initials( string $name ): string {
		$parts = preg_split( '/[\s.\-_@]+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$text  = '';
		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$text .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}

		return $text;
	}

	/**
	 * @param string      $date
	 * @param string      $today
	 * @param string|null $windowEnd
	 *
	 * @return string leading space + classes
	 */
	private static function dayClasses( string $date, string $today, ?string $windowEnd ): string {
		$classes = '';
		$weekday = (int) date( 'N', strtotime( $date ) );
		if ( $weekday >= 6 ) {
			$classes .= ' is-weekend';
		}
		if ( $date === $today ) {
			$classes .= ' is-today';
		} elseif ( $date < $today ) {
			$classes .= ' is-past';
		}
		if ( $windowEnd !== null && $date > $windowEnd ) {
			$classes .= ' is-beyond-window';
		}
		if ( $weekday === 1 ) {
			$classes .= ' is-week-start';
		}
		if ( substr( $date, 8, 2 ) === '01' ) {
			$classes .= ' is-month-start';
		}

		return $classes;
	}

	/**
	 * Detail panel, tooltip and script. The details of all posts are embedded as JSON (only what the user may see
	 * anyway); day explanations are loaded via AJAX.
	 *
	 * @param array  $groups
	 * @param string $view
	 *
	 * @return void
	 */
	private static function renderPanel( array $groups, string $view ): void {
		$posts = [];
		foreach ( $groups as $group ) {
			$posts = array_replace( $posts, $group['plan']['posts'] );
		}
		$config = [
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'action' => self::AJAX_DAY,
			'nonce'  => wp_create_nonce( self::AJAX_DAY ),
			'view'   => $view,
			'ids'    => array_map( fn( $group ) => (int) $group['id'], $groups ),
			'posts'  => (object) $posts,
			'i18n'   => [
				'edit'      => __( 'Edit', 'commonsbooking' ),
				'loading'   => __( 'Loading …', 'commonsbooking' ),
				'error'     => __( 'Could not load the details.', 'commonsbooking' ),
				'day'       => __( 'Day', 'commonsbooking' ),
				'reasons'   => __( 'Reasons', 'commonsbooking' ),
				'noReasons' => __( 'No timeframes, restrictions or bookings on this day.', 'commonsbooking' ),
				'conflicts' => __( 'Conflicts', 'commonsbooking' ),
				'calendar'  => __( 'In the booking calendar', 'commonsbooking' ),
			],
		];
		echo '<aside class="cb-plan-panel" id="cb-plan-panel" hidden aria-live="polite">';
		echo '<button type="button" class="cb-plan-close" aria-label="' . esc_attr__( 'Close', 'commonsbooking' ) . '">&times;</button>';
		echo '<div class="cb-plan-panel-body"></div>';
		echo '</aside>';
		echo '<div class="cb-plan-tip" id="cb-plan-tip" role="tooltip" hidden></div>';
		echo '<script type="application/json" id="cb-plan-data">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		?>
<script>
(function(){
	var cfg = JSON.parse(document.getElementById('cb-plan-data').textContent);
	var t = cfg.i18n;
	var root = document.querySelector('.cb-plan');
	var panel = document.getElementById('cb-plan-panel');
	var body = panel.querySelector('.cb-plan-panel-body');
	var tip = document.getElementById('cb-plan-tip');
	var active = null;
	var request = 0;

	function el(tag, cls, text){
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
	function setActive(node){
		if (active) { active.classList.remove('is-active'); }
		active = node;
		if (node) { node.classList.add('is-active'); }
	}
	function close(){
		panel.hidden = true;
		var last = active;
		setActive(null);
		if (last && last.focus) { last.focus(); }
	}
	function details(list){
		var dl = el('dl', 'cb-plan-details');
		(list || []).forEach(function(d){
			if (d[1] === '') { return; }
			dl.appendChild(el('dt', null, d[0]));
			dl.appendChild(el('dd', null, d[1]));
		});
		return dl;
	}
	function conflicts(list){
		var box = el('div', 'cb-plan-conflicts');
		box.appendChild(el('strong', null, '⚠ ' + t.conflicts));
		var ul = el('ul');
		list.forEach(function(c){ ul.appendChild(el('li', null, c)); });
		box.appendChild(ul);
		return box;
	}
	function editLink(url){
		var p = el('p');
		var a = el('a', 'button', t.edit);
		a.href = url;
		p.appendChild(a);
		return p;
	}
	function show(nodes){
		body.textContent = '';
		nodes.forEach(function(n){ body.appendChild(n); });
		panel.hidden = false;
	}
	function openPost(button){
		var post = cfg.posts[button.getAttribute('data-post')];
		if (!post) { return; }
		setActive(button);
		var nodes = [el('p', 'cb-plan-panel-type', post.type_label), el('h2', 'cb-plan-panel-title', post.title)];
		if (post.conflicts && post.conflicts.length) { nodes.push(conflicts(post.conflicts)); }
		nodes.push(details(post.details));
		if (post.edit_url) { nodes.push(editLink(post.edit_url)); }
		show(nodes);
	}
	function renderDay(data){
		var nodes = [el('p', 'cb-plan-panel-type', t.day), el('h2', 'cb-plan-panel-title', data.date_label)];
		data.rows.forEach(function(row){
			var sec = el('section', 'cb-plan-day');
			if (data.rows.length > 1) { sec.appendChild(el('h3', null, row.title)); }
			var res = el('div', 'cb-plan-result is-' + row.result.tone);
			res.appendChild(el('span', 'cb-plan-result-label', t.calendar));
			res.appendChild(el('strong', null, row.result.label));
			row.result.notes.forEach(function(n){ res.appendChild(el('p', null, n)); });
			sec.appendChild(res);
			if (!row.reasons.length) {
				sec.appendChild(el('p', 'cb-plan-muted', t.noReasons));
			}
			row.reasons.forEach(function(r){
				var d = el('details', 'cb-plan-reason cb-plan-reason-' + r.kind);
				var s = el('summary');
				s.appendChild(el('span', 'cb-plan-swatch cb-plan-' + r.kind));
				s.appendChild(el('span', 'cb-plan-reason-type', r.type_label));
				s.appendChild(el('span', 'cb-plan-reason-title', r.title));
				d.appendChild(s);
				if (r.conflicts && r.conflicts.length) { d.appendChild(conflicts(r.conflicts)); d.open = true; }
				d.appendChild(details(r.details));
				if (r.edit_url) { d.appendChild(editLink(r.edit_url)); }
				sec.appendChild(d);
			});
			nodes.push(sec);
		});
		show(nodes);
	}
	function openDay(node, date, row, ids){
		setActive(node);
		show([el('p', 'cb-plan-muted', t.loading)]);
		var id = ++request;
		var params = new URLSearchParams({action: cfg.action, _ajax_nonce: cfg.nonce, view: cfg.view, ids: ids.join(','), date: date, row: row || 0});
		fetch(cfg.ajax, {method: 'POST', credentials: 'same-origin', body: params})
			.then(function(r){ return r.json(); })
			.then(function(json){
				if (id !== request) { return; }
				if (!json || !json.success) { throw new Error(); }
				renderDay(json.data);
			})
			.catch(function(){ if (id === request) { show([el('p', 'cb-plan-muted', t.error)]); } });
	}

	root.addEventListener('click', function(e){
		var seg = e.target.closest('.cb-plan-seg[data-post]');
		if (seg) { openPost(seg); return; }
		var head = e.target.closest('.cb-plan-dayhead');
		if (head) { openDay(head, head.getAttribute('data-date'), 0, cfg.ids); return; }
		var cell = e.target.closest('.cb-plan-cell');
		if (cell) {
			var row = cell.closest('.cb-plan-row');
			openDay(cell, cell.getAttribute('data-date'), parseInt(row.getAttribute('data-row'), 10) || 0, [row.getAttribute('data-group')]);
		}
	});
	panel.querySelector('.cb-plan-close').addEventListener('click', close);
	document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !panel.hidden) { close(); } });

	// Tooltip on top of everything (native titles get clipped by the scroll area)
	function hideTip(){ tip.hidden = true; }
	function showTip(target){
		var text = target.getAttribute('data-tip');
		if (!text) { return; }
		tip.textContent = text;
		tip.hidden = false;
		var r = target.getBoundingClientRect();
		var w = tip.offsetWidth, h = tip.offsetHeight;
		var x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
		var y = r.top - h - 6;
		if (y < 40) { y = r.bottom + 6; }
		tip.style.left = x + 'px';
		tip.style.top = y + 'px';
	}
	root.addEventListener('mouseover', function(e){
		var target = e.target.closest('[data-tip]');
		if (target) { showTip(target); } else { hideTip(); }
	});
	root.addEventListener('mouseleave', hideTip);
	root.addEventListener('focusin', function(e){ var target = e.target.closest('[data-tip]'); if (target) { showTip(target); } });
	root.addEventListener('focusout', hideTip);
	document.querySelector('.cb-plan-scroll').addEventListener('scroll', hideTip, {passive: true});
})();
</script>
		<?php
	}

	/**
	 * @return void
	 */
	private static function styles(): void {
		?>
<style>
.cb-plan-wrap{--cb-plan-label:220px;--cb-plan-lane:26px;
	--cb-plan-bookable:rgba(104,184,84,.22);--cb-plan-bookable-b:#7fc06c;--cb-plan-booking:#2271b1;--cb-plan-holiday:#f2c66d;
	--cb-plan-repair:#8a2424;--cb-plan-restriction:#d63638;--cb-plan-hint:#dba617;--cb-plan-conflict:#d63638;--cb-plan-grid:#e2e4e7;
	--cb-plan-weekend:#f3f4f5;--cb-plan-past:rgba(0,0,0,.035);--cb-plan-today:#2271b1;--cb-plan-bg:#fff}
.cb-plan-tabs{margin-bottom:12px}
.cb-plan-filters{display:flex;flex-direction:column;gap:8px;margin:0 0 10px}
.cb-plan-filter-row{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center}
.cb-plan-filters label{display:flex;gap:6px;align-items:center}
.cb-plan-nav{margin-left:auto;display:flex;gap:4px}
.cb-plan-picker{display:flex;gap:6px;align-items:flex-start;flex:1 1 420px;max-width:900px}
.cb-plan-picker-label{line-height:30px}
.cb-plan-picker-box{position:relative;flex:1;display:flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:30px;padding:2px 4px;border:1px solid #8c8f94;border-radius:4px;background:#fff;box-sizing:border-box;cursor:text}
.cb-plan-picker-box:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.cb-plan-chips{display:contents;margin:0}
.cb-plan-chip{display:inline-flex;align-items:center;gap:2px;margin:0;padding:0 0 0 8px;border-radius:12px;background:#e5f0fa;color:#1d2327;line-height:22px;max-width:100%}
.cb-plan-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cb-plan-chip-remove{border:0;background:transparent;width:22px;height:22px;padding:0;border-radius:11px;font-size:16px;line-height:1;cursor:pointer;color:#50575e}
.cb-plan-chip-remove:hover,.cb-plan-chip-remove:focus{background:#c5d9ed;color:#1d2327}
.cb-plan-filters .cb-plan-picker-input{flex:1 0 160px;min-width:0;border:0;box-shadow:none;background:transparent;min-height:24px;padding:0 4px}
.cb-plan-filters .cb-plan-picker-input:focus{box-shadow:none;outline:0}
.cb-plan-picker-list{position:absolute;left:-1px;right:-1px;top:100%;z-index:20;margin:2px 0 0;padding:4px 0;max-height:320px;overflow:auto;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.cb-plan-picker-list[hidden]{display:none}
.cb-plan-picker-list li{margin:0;padding:5px 10px;cursor:pointer}
.cb-plan-picker-list li:hover,.cb-plan-picker-list li.is-current{background:#2271b1;color:#fff}
.cb-plan-picker-list .cb-plan-picker-none,.cb-plan-picker-list .cb-plan-picker-none:hover{color:#50575e;background:transparent;cursor:default;font-style:italic}
.cb-plan-filters.is-dirty .cb-plan-show{background:#2271b1;border-color:#2271b1;color:#fff}
.cb-plan-empty-selection{font-style:italic;color:#50575e}
.cb-plan-group{background:#f0f0f1;border-top:2px solid #8c8f94}
.cb-plan-group .cb-plan-label{background:#f0f0f1;font-weight:600;font-size:14px;padding:7px 8px}
.cb-plan-group .cb-plan-label a{color:#1d2327}
.cb-plan-group-fill{grid-column:2 / -1}
.cb-plan-legend{display:flex;flex-wrap:wrap;gap:4px 14px;margin:6px 0 10px;padding:0;font-size:12px;color:#50575e}
.cb-plan-legend li{display:flex;align-items:center;gap:6px;margin:0}
.cb-plan-legend .cb-plan-hint{margin-left:auto;font-style:italic}
.cb-plan-swatch{display:inline-block;flex:none;width:20px;height:12px;border-radius:3px;box-sizing:border-box}
.cb-plan-swatch.cb-plan-window{background:repeating-linear-gradient(135deg,#bbb 0 2px,transparent 2px 6px);border:1px solid #ccc}
.cb-plan-scroll{overflow:auto;max-height:calc(100vh - 260px);min-height:200px;border:1px solid #c3c4c7;background:var(--cb-plan-bg);position:relative}
.cb-plan{display:grid;grid-template-columns:var(--cb-plan-label) repeat(var(--cb-plan-days),var(--cb-plan-day));width:max-content;min-width:100%}
.cb-plan-line{display:grid;grid-column:1 / -1;grid-template-columns:subgrid}
.cb-plan-label{grid-column:1;grid-row:1 / -1;position:sticky;left:0;z-index:5;background:var(--cb-plan-bg);border-right:1px solid #c3c4c7;border-bottom:1px solid var(--cb-plan-grid);padding:6px 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:flex;align-items:flex-start;gap:6px}
.cb-plan-label a{overflow:hidden;text-overflow:ellipsis;font-weight:600;text-decoration:none}
.cb-plan-badge{flex:none;background:var(--cb-plan-conflict);color:#fff;border-radius:9px;padding:0 6px;font-size:11px;line-height:18px}
.cb-plan-months,.cb-plan-days{position:sticky;z-index:6;background:var(--cb-plan-bg)}
.cb-plan-months{top:0;height:24px}
.cb-plan-days{top:24px;border-bottom:1px solid #c3c4c7}
.cb-plan-months .cb-plan-label,.cb-plan-days .cb-plan-label{z-index:7;border-bottom:0}
.cb-plan-month{grid-row:1;padding:4px 6px;font-weight:600;white-space:nowrap;overflow:hidden;border-left:1px solid #8c8f94}
.cb-plan-dayhead{grid-row:1;margin:0;padding:3px 0;border:0;border-left:1px solid transparent;background:transparent;font:inherit;text-align:center;font-size:11px;line-height:1.25;color:#50575e;cursor:pointer;min-width:0;overflow:hidden}
.cb-plan-dayhead.is-weekend{background:var(--cb-plan-weekend)}
.cb-plan-dayhead:hover,.cb-plan-dayhead.is-active{background:#e5f0fa}
.cb-plan-dayhead span{display:block}
.cb-plan-dayhead b{display:block;font-size:13px;color:#1d2327}
.cb-plan-dayhead.is-today b{background:var(--cb-plan-today);color:#fff;border-radius:10px;margin:0 2px}
.cb-plan-row{grid-template-rows:repeat(var(--cb-plan-lanes),var(--cb-plan-lane));padding:3px 0}
.cb-plan-cell{grid-row:1 / -1;border-left:1px solid var(--cb-plan-grid);border-bottom:1px solid var(--cb-plan-grid);margin:-3px 0;cursor:pointer}
.cb-plan-cell.is-weekend{background:var(--cb-plan-weekend)}
.cb-plan-cell.is-past{background-color:var(--cb-plan-past)}
.cb-plan-cell.is-weekend.is-past{background-color:#eceded}
.cb-plan-cell:hover,.cb-plan-cell.is-active{background-color:#e5f0fa}
.cb-plan-cell.is-today{box-shadow:inset 2px 0 0 var(--cb-plan-today),inset -2px 0 0 var(--cb-plan-today)}
.cb-plan-cell.is-week-start{border-left-color:#c3c4c7}
.cb-plan-cell.is-month-start,.cb-plan-dayhead.is-month-start{border-left-color:#8c8f94}
.cb-plan-cell.is-beyond-window{background-image:repeating-linear-gradient(135deg,rgba(0,0,0,.13) 0 2px,transparent 2px 7px)}
.cb-plan-station .cb-plan-label{background:#fcf9e8}
.cb-plan-station .cb-plan-cell{background-color:#fefcf3}
.cb-plan-seg{position:relative;z-index:3;margin:2px 1px;padding:0 5px;min-width:0;border:0;border-radius:4px;font:inherit;font-size:12px;line-height:22px;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;color:#1d2327;box-sizing:border-box}
.cb-plan-seg span{pointer-events:none}
.cb-plan-seg:hover{filter:brightness(1.08)}
.cb-plan-seg:focus-visible,.cb-plan-seg.is-active{outline:2px solid #1d2327;outline-offset:1px;z-index:4}
div.cb-plan-seg.cb-plan-bookable{z-index:1;margin:-3px 0;padding:0;border-radius:0;background:var(--cb-plan-bookable);border-top:3px solid var(--cb-plan-bookable-b);pointer-events:none;cursor:default}
.cb-plan-seg.cb-plan-booking{background:var(--cb-plan-booking);color:#fff}
.cb-plan-seg.cb-plan-booking.is-unconfirmed{background:#fff;color:var(--cb-plan-booking);border:2px dashed var(--cb-plan-booking);line-height:18px}
.cb-plan-seg.cb-plan-booking.is-canceled{background:#dcdcde;color:#50575e;text-decoration:line-through}
.cb-plan-seg.has-conflict{box-shadow:0 0 0 2px var(--cb-plan-conflict);}
.cb-plan-seg.cb-plan-holiday{background:var(--cb-plan-holiday)}
.cb-plan-seg.cb-plan-repair{background:var(--cb-plan-repair);color:#fff}
.cb-plan-seg.cb-plan-restriction.is-repair{background:var(--cb-plan-restriction);color:#fff}
.cb-plan-seg.cb-plan-restriction.is-hint{background:#fff;border:2px solid var(--cb-plan-hint);line-height:18px}
.cb-plan-seg.is-inactive{opacity:.45}
.cb-plan-seg.clip-start{border-top-left-radius:0;border-bottom-left-radius:0;margin-left:0}
.cb-plan-seg.clip-end{border-top-right-radius:0;border-bottom-right-radius:0;margin-right:0}
.cb-plan-swatch.cb-plan-bookable{background:var(--cb-plan-bookable);border-top:3px solid var(--cb-plan-bookable-b)}
.cb-plan-swatch.cb-plan-booking{background:var(--cb-plan-booking)}
.cb-plan-swatch.cb-plan-booking.is-unconfirmed{background:#fff;border:2px dashed var(--cb-plan-booking)}
.cb-plan-swatch.cb-plan-booking.has-conflict{box-shadow:0 0 0 2px var(--cb-plan-conflict)}
.cb-plan-swatch.cb-plan-holiday{background:var(--cb-plan-holiday)}
.cb-plan-swatch.cb-plan-repair{background:var(--cb-plan-repair)}
.cb-plan-swatch.cb-plan-restriction,.cb-plan-swatch.cb-plan-restriction.is-repair{background:var(--cb-plan-restriction)}
.cb-plan-swatch.cb-plan-restriction.is-hint{background:#fff;border:2px solid var(--cb-plan-hint)}
.cb-plan-empty{grid-column:1 / -1;padding:12px;margin:0}
.cb-plan-tip{position:fixed;z-index:100000;max-width:320px;padding:6px 9px;border-radius:4px;background:#1d2327;color:#fff;font-size:12px;line-height:1.45;white-space:pre-line;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.cb-plan-tip[hidden]{display:none}
.cb-plan-panel{position:fixed;top:32px;right:0;bottom:0;width:380px;max-width:100%;z-index:9990;background:#fff;border-left:1px solid #c3c4c7;box-shadow:-4px 0 16px rgba(0,0,0,.1);padding:16px 20px 24px;overflow:auto;box-sizing:border-box}
@media (max-width:782px){.cb-plan-panel{top:46px}}
.cb-plan-panel[hidden]{display:none}
.cb-plan-close{position:absolute;top:8px;right:10px;width:32px;height:32px;border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:#50575e}
.cb-plan-close:hover{color:#1d2327}
.cb-plan-panel-type{margin:0;color:#50575e;text-transform:uppercase;font-size:11px;letter-spacing:.04em}
.cb-plan-panel-title{margin:4px 32px 12px 0;font-size:18px}
.cb-plan-details{display:grid;grid-template-columns:auto 1fr;gap:5px 12px;margin:8px 0}
.cb-plan-details dt{font-weight:600;color:#50575e}
.cb-plan-details dd{margin:0;white-space:pre-line;overflow-wrap:anywhere}
.cb-plan-conflicts{margin:8px 0;padding:8px 10px;border-left:4px solid var(--cb-plan-conflict);background:#fcf0f1}
.cb-plan-conflicts ul{margin:4px 0 0 16px;list-style:disc}
.cb-plan-day{margin:0 0 16px}
.cb-plan-day h3{margin:16px 0 6px;font-size:14px}
.cb-plan-result{padding:8px 10px;border-left:4px solid #8c8f94;background:#f6f7f7;margin-bottom:8px}
.cb-plan-result.is-ok{border-color:#00a32a;background:#edfaef}
.cb-plan-result.is-warn{border-color:var(--cb-plan-hint);background:#fcf9e8}
.cb-plan-result.is-bad{border-color:var(--cb-plan-conflict);background:#fcf0f1}
.cb-plan-result-label{display:block;font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:.04em}
.cb-plan-result p{margin:4px 0 0}
.cb-plan-reason{border:1px solid #dcdcde;border-radius:4px;margin:0 0 6px;padding:0 10px}
.cb-plan-reason summary{display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer}
.cb-plan-reason-type{color:#50575e;font-size:12px}
.cb-plan-reason-title{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cb-plan-muted{color:#50575e}
@media (max-width:782px){.cb-plan-wrap{--cb-plan-label:120px}.cb-plan-nav{margin-left:0}.cb-plan-legend .cb-plan-hint{margin-left:0}.cb-plan-scroll{max-height:none}}
</style>
		<?php
	}
}
