<?php

namespace CommonsBooking\View;

use CommonsBooking\Repository\Item as ItemRepository;
use CommonsBooking\Repository\Location as LocationRepository;
use CommonsBooking\Service\OccupancyPlan as Plan;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;

/**
 * Occupancy plan: timeline per location (rows = items) or per item (rows = locations) with bookable days, blocks,
 * restrictions and bookings. Click on a bar shows its details, click on a day explains the day. Read-only, for
 * admins and CB managers (only locations / items they may see).
 *
 * Rendered in two contexts with the same data, logic and markup:
 * - admin page Commons Booking → Occupancy plan ({@see Admin\OccupancyPlan}),
 * - shortcode [cb_occupancy_plan] on any page ({@see self::shortcode()}).
 *
 * @see design/belegungsplan.md
 */
class OccupancyPlan {

	public const SHORTCODE = 'cb_occupancy_plan';

	public const AJAX_DAY = 'cb_occupancy_day';

	public const ASSET_HANDLE = 'cb-occupancy-plan';

	/**
	 * User meta with the last selection (view, and per view ids, category, period, day width, canceled). Shared by
	 * the admin page and the shortcode. Only read by the occupancy plan, so it does not affect a downgrade.
	 */
	public const USER_META = 'commonsbooking_occupancy_plan';

	/**
	 * Prefix of the request parameters in the frontend. Without it, "cat" would be taken by WordPress as category
	 * query and turn the page into an archive.
	 */
	public const FRONTEND_PREFIX = 'cbp_';

	/**
	 * Request parameters of the plan (without prefix).
	 */
	private const PARAMS = [ 'view', 'location', 'item', 'sel', 'cat', 'from', 'days', 'zoom', 'canceled' ];

	/**
	 * Selectable range lengths in days.
	 */
	public const SPANS = [ 7, 14, 28, 56, 91, 182, 365 ];

	private const DEFAULT_SPAN = 28;

	/**
	 * Days shown before today by default.
	 */
	private const DEFAULT_DAYS_BEFORE = 3;

	/**
	 * Day widths in px.
	 */
	public const ZOOMS = [
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
	 * Number of shortcode instances rendered in this request (only one per page: fixed element ids).
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_DAY, [ self::class, 'handleDay' ] );
		add_action( 'init', [ self::class, 'registerAssets' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueOnShortcodePage' ] );
		add_action( 'template_redirect', [ self::class, 'preventCaching' ] );
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );
	}

	/**
	 * @return void
	 */
	public static function registerAssets(): void {
		$base    = COMMONSBOOKING_PLUGIN_URL . 'assets/occupancy-plan/';
		$version = fn( string $file ) => COMMONSBOOKING_VERSION . '.' . (int) @filemtime( COMMONSBOOKING_PLUGIN_DIR . 'assets/occupancy-plan/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file only changes the version
		wp_register_style( self::ASSET_HANDLE, $base . 'occupancy-plan.css', [], $version( 'occupancy-plan.css' ) );
		wp_register_script( self::ASSET_HANDLE, $base . 'occupancy-plan.js', [], $version( 'occupancy-plan.js' ), true );
	}

	/**
	 * @return void
	 */
	public static function enqueueAssets(): void {
		if ( ! wp_style_is( self::ASSET_HANDLE, 'registered' ) ) {
			self::registerAssets();
		}
		wp_enqueue_style( self::ASSET_HANDLE );
		wp_enqueue_script( self::ASSET_HANDLE );
	}

	/**
	 * @return bool whether the current request shows a single post containing the shortcode
	 */
	public static function isShortcodePage(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();

		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Styles in the head on pages with the shortcode (otherwise the shortcode enqueues them late, in the footer).
	 *
	 * @return void
	 */
	public static function enqueueOnShortcodePage(): void {
		if ( self::isShortcodePage() && current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			self::enqueueAssets();
		}
	}

	/**
	 * The plan contains personal data (names, e-mail, phone): pages with the shortcode must not be cached.
	 *
	 * @return void
	 */
	public static function preventCaching(): void {
		if ( self::isShortcodePage() ) {
			self::markNoCache();
			if ( ! headers_sent() ) {
				nocache_headers();
			}
		}
	}

	/**
	 * Tells page cache plugins not to store the page (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed, …).
	 *
	 * @return void
	 */
	private static function markNoCache(): void {
		foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ] as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- constants of page cache plugins
			}
		}
		do_action( 'litespeed_control_set_nocache', 'commonsbooking occupancy plan' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook of LiteSpeed Cache
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
	public static function parseIds( $value ): array {
		if ( is_string( $value ) || is_int( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * AJAX: explanation of a day (all rows or one row). Used by the admin page and the shortcode; logged-in users
	 * only (no nopriv action).
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
				// The panel groups the rows by location (item view: by item)
				$explained['group_id']    = $id;
				$explained['group_title'] = $allowed[ $id ];
				$rows[]                   = $explained;
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
	 * Shortcode [cb_occupancy_plan]. Same plan and permissions as the admin page; others get a notice.
	 *
	 * Attributes: view (location|item), location / item (ids, comma separated), days, zoom, canceled (0|1),
	 * tabs (0|1), lock (0|1: fixed selection), align (wide|full).
	 *
	 * @param array|string $atts
	 *
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'view'     => '',
				'location' => '',
				'item'     => '',
				'days'     => '',
				'zoom'     => '',
				'canceled' => '',
				'tabs'     => '1',
				'lock'     => '0',
				'align'    => '',
			],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE
		);

		if ( ! is_user_logged_in() ) {
			$url = is_singular() ? get_permalink() : home_url( '/' );

			return self::notice(
				sprintf(
					/* translators: %s: login url */
					commonsbooking_sanitizeHTML( __( 'Please <a href="%s">log in</a> to see the occupancy plan.', 'commonsbooking' ) ),
					esc_url( wp_login_url( $url ?: '' ) )
				)
			);
		}
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			return self::notice( esc_html__( 'The occupancy plan is only available to administrators and CB managers.', 'commonsbooking' ) );
		}
		if ( ++self::$instances > 1 ) {
			return self::notice( esc_html__( 'The occupancy plan can only be shown once per page.', 'commonsbooking' ) );
		}

		self::markNoCache();
		self::enqueueAssets();

		$prefixed = array_map( fn( $key ) => self::FRONTEND_PREFIX . $key, self::PARAMS );
		$base     = is_singular() ? (string) get_permalink() : remove_query_arg( $prefixed );
		$view     = $atts['view'] === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : ( $atts['view'] === Plan::VIEW_LOCATION ? Plan::VIEW_LOCATION : '' );
		if ( $view === '' && ( $atts['location'] === '' ) !== ( $atts['item'] === '' ) ) {
			// Only location or only item ids: their view (instead of the remembered one)
			$view = $atts['item'] !== '' ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		}
		$defaults = array_filter(
			[
				'view'     => $view,
				'location' => self::parseIds( $atts['location'] ),
				'item'     => self::parseIds( $atts['item'] ),
				'days'     => absint( $atts['days'] ) ?: '',
				'zoom'     => sanitize_key( $atts['zoom'] ),
				'canceled' => $atts['canceled'] === '' ? '' : (int) filter_var( $atts['canceled'], FILTER_VALIDATE_BOOLEAN ),
			],
			fn( $value ) => $value !== '' && $value !== []
		);
		$lock     = filter_var( $atts['lock'], FILTER_VALIDATE_BOOLEAN );
		$align    = in_array( $atts['align'], [ 'wide', 'full' ], true ) ? $atts['align'] : '';

		return self::render(
			[
				'frontend' => true,
				'base'     => $base,
				'prefix'   => self::FRONTEND_PREFIX,
				'defaults' => $defaults,
				'tabs'     => filter_var( $atts['tabs'], FILTER_VALIDATE_BOOLEAN ) && ! ( $lock && $view ),
				'lock'     => $lock,
				'align'    => $align,
			]
		);
	}

	/**
	 * @param string $html escaped html
	 *
	 * @return string
	 */
	private static function notice( string $html ): string {
		return '<div class="cb-plan-wrap cb-plan-ui cb-plan-front"><p class="cb-plan-notice">' . $html . '</p></div>';
	}

	/**
	 * Renders the plan.
	 *
	 * @param array $context frontend (bool), base (url of the page), prefix (of the request parameters),
	 *                       defaults (shortcode attributes), tabs (bool), lock (bool: view and selection fixed by
	 *                       the defaults), align (wide|full)
	 *
	 * @return string html
	 */
	public static function render( array $context ): string {
		$context = array_merge(
			[
				'frontend' => false,
				'base'     => '',
				'prefix'   => '',
				'defaults' => [],
				'tabs'     => true,
				'lock'     => false,
				'align'    => '',
			],
			$context
		);
		if ( ! current_user_can( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG ) ) {
			return '';
		}
		$prefix   = (string) $context['prefix'];
		$defaults = (array) $context['defaults'];
		$get      = fn( string $key ) => isset( $_GET[ $prefix . $key ] ) ? wp_unslash( $_GET[ $prefix . $key ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only, sanitized by the callers

		// Locked: view and selection come only from the shortcode
		$lockView = $context['lock'] && isset( $defaults['view'] );
		$saved    = self::savedState();
		if ( $lockView ) {
			$view = $defaults['view'];
		} elseif ( $get( 'view' ) !== null ) {
			$view = $get( 'view' ) === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		} else {
			$view = ( $defaults['view'] ?? $saved['view'] ?? '' ) === Plan::VIEW_ITEM ? Plan::VIEW_ITEM : Plan::VIEW_LOCATION;
		}
		$isItemView = $view === Plan::VIEW_ITEM;
		$allowed    = $isItemView ? self::allowedItems() : self::allowedLocations();
		$param      = $isItemView ? 'item' : 'location';
		$lockIds    = $context['lock'] && ! empty( $defaults[ $param ] );
		// Explicit selection (form, links with ids), otherwise shortcode attributes, otherwise the remembered one
		$explicit = $get( 'sel' ) !== null || $get( $param ) !== null;
		$remember = $explicit ? [] : array_merge( (array) ( $saved[ $view ] ?? [] ), $defaults, isset( $defaults[ $param ] ) ? [ 'ids' => $defaults[ $param ] ] : [] );
		$input    = fn( string $key ) => $explicit ? $get( $key ) : ( $remember[ $key ] ?? null );

		if ( $lockIds ) {
			$rawIds = $defaults[ $param ];
		} else {
			$rawIds = $explicit ? ( $get( $param ) ?? [] ) : ( $remember['ids'] ?? [] );
		}
		$ids     = array_values( array_filter( self::parseIds( $rawIds ), fn( $id ) => isset( $allowed[ $id ] ) ) );
		$choices = self::choices( $isItemView, $allowed, $ids );
		if ( ! $ids && ! $explicit && ! $lockIds && $choices ) {
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
		$today    = (int) strtotime( current_time( 'Y-m-d' ) );
		$from     = $get( 'from' ) !== null ? sanitize_text_field( (string) $get( 'from' ) ) : gmdate( 'Y-m-d', strtotime( '-' . self::DEFAULT_DAYS_BEFORE . ' days', $today ) );
		$canceled = ! empty( $input( 'canceled' ) );

		[ $from, $to ] = Plan::normalizeRange( $from, gmdate( 'Y-m-d', strtotime( $from . ' +' . ( $span - 1 ) . ' days' ) ?: $today ) );

		$state = [
			'view'     => $view,
			$param     => $ids,
			'cat'      => $cat,
			'from'     => $from,
			'days'     => $span,
			'zoom'     => $zoom,
			'canceled' => $canceled ? 1 : 0,
		];

		$context['lock_ids'] = $lockIds;
		$dayWidth            = self::dayWidth( $zoom, $span );
		$wrapClass           = 'cb-plan-wrap cb-plan-ui ' . ( $context['frontend'] ? 'cb-plan-front' : 'wrap' ) . ( $context['align'] ? ' align' . $context['align'] : '' );

		ob_start();
		echo '<div id="cb-plan-root" class="' . esc_attr( $wrapClass ) . '" style="--cb-plan-day:' . (int) $dayWidth . 'px">';
		if ( ! $context['frontend'] ) {
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Occupancy plan', 'commonsbooking' ) . '</h1>';
		}
		if ( $context['tabs'] ) {
			self::renderTabs( $context, $view );
		}

		if ( ! $allowed ) {
			echo '<p class="cb-plan-notice">' . esc_html( $isItemView ? __( 'No item available. You need to be admin of at least one item.', 'commonsbooking' ) : __( 'No location available. You need to be admin of at least one location.', 'commonsbooking' ) ) . '</p></div>';

			return (string) ob_get_clean();
		}

		self::renderFilters( $context, $state, $choices, $allowed, $taxonomy );

		if ( ! $ids ) {
			echo '<p class="cb-plan-empty-selection">' . esc_html( $isItemView ? __( 'Select at least one item.', 'commonsbooking' ) : __( 'Select at least one location.', 'commonsbooking' ) ) . '</p></div>';

			return (string) ob_get_clean();
		}
		if ( ! $context['lock'] ) {
			self::saveState( $saved, $view, array_merge( $state, [ 'ids' => $ids ] ) );
		}

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

		echo '<div class="cb-plan-card">';
		self::renderLegend();
		self::renderPlan( $groups, $dayWidth );
		echo '</div>';
		self::renderPanel( $context, $groups, $view );
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Link to the plan in the given context.
	 *
	 * @param array $context
	 * @param array $args plan parameters without prefix (view, location, item, cat, from, days, zoom, canceled)
	 *
	 * @return string
	 */
	private static function link( array $context, array $args ): string {
		$query = [];
		foreach ( $args as $key => $value ) {
			$query[ $context['prefix'] . $key ] = $value;
		}

		return add_query_arg( $query, $context['base'] );
	}

	/**
	 * @param array $context
	 *
	 * @return string css class of buttons: WordPress admin buttons in the backend, own style in the frontend
	 */
	private static function buttonClass( array $context ): string {
		return $context['frontend'] ? 'cb-plan-button' : 'button';
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
	 * @param array  $context
	 * @param string $view
	 *
	 * @return void
	 */
	private static function renderTabs( array $context, string $view ): void {
		$frontend = $context['frontend'];
		echo '<nav class="' . ( $frontend ? 'cb-plan-tabs' : 'nav-tab-wrapper cb-plan-tabs' ) . '">';
		foreach ( [
			Plan::VIEW_LOCATION => __( 'By location', 'commonsbooking' ),
			Plan::VIEW_ITEM     => __( 'By item', 'commonsbooking' ),
		] as $key => $label ) {
			$class = ( $frontend ? 'cb-plan-tab' : 'nav-tab' ) . ( $key === $view ? ( $frontend ? ' is-active' : ' nav-tab-active' ) : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::link( $context, [ 'view' => $key ] ) ) . '"' . ( $key === $view ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * @param array              $context
	 * @param array              $state current query args
	 * @param array<int, string> $choices locations or items offered in the search field
	 * @param array<int, string> $allowed all locations or items the user may see (titles of the selection)
	 * @param string             $taxonomy category taxonomy of the rows
	 *
	 * @return void
	 */
	private static function renderFilters( array $context, array $state, array $choices, array $allowed, string $taxonomy ): void {
		$isItemView = $state['view'] === Plan::VIEW_ITEM;
		$param      = $isItemView ? 'item' : 'location';
		$p          = $context['prefix'];
		$button     = self::buttonClass( $context );
		$base       = array_filter( $state );
		$prev       = self::link( $context, array_merge( $base, [ 'from' => gmdate( 'Y-m-d', strtotime( $state['from'] . ' -' . $state['days'] . ' days' ) ) ] ) );
		$next       = self::link( $context, array_merge( $base, [ 'from' => gmdate( 'Y-m-d', strtotime( $state['from'] . ' +' . $state['days'] . ' days' ) ) ] ) );
		$todayArgs  = $base;
		unset( $todayArgs['from'] );
		$today = self::link( $context, $todayArgs );
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			]
		);

		// GET forms drop the query of the action url: keep it as hidden fields (page=… in the admin, page_id=… with plain permalinks)
		$action = strtok( $context['base'], '?' );
		echo '<form method="get" class="cb-plan-filters" action="' . esc_url( (string) $action ) . '">';
		parse_str( (string) wp_parse_url( $context['base'], PHP_URL_QUERY ), $baseQuery );
		foreach ( $baseQuery as $key => $value ) {
			if ( is_scalar( $value ) ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
		}
		echo '<input type="hidden" name="' . esc_attr( $p . 'view' ) . '" value="' . esc_attr( $state['view'] ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $p . 'sel' ) . '" value="1">';
		if ( empty( $context['lock_ids'] ) ) {
			echo '<div class="cb-plan-filter-row is-selection">';
			self::renderPicker( $p . $param, $state[ $param ], $choices, $allowed, $isItemView );
			echo '<button type="submit" class="' . esc_attr( $button . ( $context['frontend'] ? '' : ' button-primary' ) . ' cb-plan-show' ) . '">' . esc_html__( 'Show', 'commonsbooking' ) . '</button>';
			echo '</div>';
		}
		echo '<div class="cb-plan-filter-row is-options">';
		if ( is_array( $terms ) && $terms ) {
			echo '<label class="cb-plan-field"><span class="cb-plan-field-label">' . esc_html( $isItemView ? __( 'Location category', 'commonsbooking' ) : __( 'Item category', 'commonsbooking' ) ) . '</span> <select name="' . esc_attr( $p . 'cat' ) . '" data-autosubmit>';
			echo '<option value="0">' . esc_html__( 'All', 'commonsbooking' ) . '</option>';
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( $term->term_id, $state['cat'], false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '<label class="cb-plan-check"><input type="checkbox" name="' . esc_attr( $p . 'canceled' ) . '" value="1"' . checked( (bool) $state['canceled'], true, false ) . ' data-autosubmit> ' . esc_html__( 'Show canceled bookings', 'commonsbooking' ) . '</label>';
		echo '</div><div class="cb-plan-filter-row is-period">';
		echo '<label class="cb-plan-field"><span class="cb-plan-field-label">' . esc_html__( 'From', 'commonsbooking' ) . '</span> <input type="date" name="' . esc_attr( $p . 'from' ) . '" value="' . esc_attr( $state['from'] ) . '" data-autosubmit></label>';
		echo '<label class="cb-plan-field"><span class="cb-plan-field-label">' . esc_html__( 'Period', 'commonsbooking' ) . '</span> <select name="' . esc_attr( $p . 'days' ) . '" data-autosubmit>';
		foreach ( self::SPANS as $days ) {
			echo '<option value="' . esc_attr( (string) $days ) . '"' . selected( $days, $state['days'], false ) . '>' . esc_html( self::spanLabel( $days ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="cb-plan-field"><span class="cb-plan-field-label">' . esc_html__( 'Day width', 'commonsbooking' ) . '</span> <select name="' . esc_attr( $p . 'zoom' ) . '" data-autosubmit>';
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
		echo '<a class="' . esc_attr( $button ) . '" href="' . esc_url( $prev ) . '" aria-label="' . esc_attr__( 'Earlier', 'commonsbooking' ) . '">&larr;</a>';
		echo '<a class="' . esc_attr( $button ) . '" href="' . esc_url( $today ) . '">' . esc_html__( 'Today', 'commonsbooking' ) . '</a>';
		echo '<a class="' . esc_attr( $button ) . '" href="' . esc_url( $next ) . '" aria-label="' . esc_attr__( 'Later', 'commonsbooking' ) . '">&rarr;</a>';
		echo '</span>';
		echo '</div></form>';
	}

	/**
	 * Search field with multiple selection: chips for the selected entries (hidden inputs), a text field that filters
	 * the choices as you type (assets/occupancy-plan/occupancy-plan.js). Without JavaScript the chips still submit
	 * the current selection.
	 *
	 * @param string             $name request parameter (location or item, with prefix)
	 * @param int[]              $selected
	 * @param array<int, string> $choices
	 * @param array<int, string> $allowed
	 * @param bool               $isItemView
	 *
	 * @return void
	 */
	private static function renderPicker( string $name, array $selected, array $choices, array $allowed, bool $isItemView ): void {
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

		echo '<div class="cb-plan-picker" data-name="' . esc_attr( $name ) . '[]" data-i18n="' . esc_attr( (string) wp_json_encode( $i18n ) ) . '">';
		echo '<span class="cb-plan-picker-label" id="cb-plan-picker-label">' . esc_html( $isItemView ? __( 'Items', 'commonsbooking' ) : __( 'Locations', 'commonsbooking' ) ) . '</span>';
		echo '<div class="cb-plan-picker-box">';
		echo '<ul class="cb-plan-chips">';
		foreach ( $selected as $id ) {
			echo '<li class="cb-plan-chip" data-id="' . (int) $id . '"><span>' . esc_html( $allowed[ $id ] ?? '#' . $id ) . '</span>';
			/* translators: %s: name of a location or item */
			echo '<button type="button" class="cb-plan-chip-remove" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'commonsbooking' ), $allowed[ $id ] ?? '#' . $id ) ) . '">&times;</button>';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="' . (int) $id . '"></li>';
		}
		echo '</ul>';
		echo '<input type="text" class="cb-plan-picker-input" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cb-plan-picker-list" aria-labelledby="cb-plan-picker-label" autocomplete="off" placeholder="' . esc_attr( $isItemView ? __( 'Search item …', 'commonsbooking' ) : __( 'Search location …', 'commonsbooking' ) ) . '" title="' . esc_attr( $hint ) . '">';
		echo '<ul class="cb-plan-picker-list" id="cb-plan-picker-list" role="listbox" aria-multiselectable="true" hidden></ul>';
		echo '</div>';
		echo '<script type="application/json" class="cb-plan-picker-options">' . wp_json_encode( $options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		echo '</div>';
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
			'none'                    => __( 'No timeframe', 'commonsbooking' ),
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
		echo '<li>';
		self::renderHelp();
		echo '</li>';
		echo '</ul>';
	}

	/**
	 * Short how-to (closed by default, opens as a card below the legend). Same for the admin page and the frontend.
	 *
	 * @return void
	 */
	private static function renderHelp(): void {
		$steps = [
			__( 'Day', 'commonsbooking' )                      => __( 'Click a date in the header: for every row it explains whether the day can be booked and why, with the timeframes, restrictions and bookings involved.', 'commonsbooking' ),
			__( 'Bar', 'commonsbooking' )                      => __( 'Click a timeframe, booking or restriction in the calendar to see its details.', 'commonsbooking' ),
			__( 'Collapse', 'commonsbooking' )                 => __( 'With several locations (or items) selected, the arrow or the name collapses a group. "Only this" collapses all others, "Expand all" at the top left brings them back. The day explanation follows.', 'commonsbooking' ),
			__( 'All items of the location', 'commonsbooking' ) => __( 'Holidays, blocks and restrictions that apply to every row of the group are shown once in this row. The "i" explains it.', 'commonsbooking' ),
			__( 'Conflicts', 'commonsbooking' )                => __( 'The red number counts bookings that collide with a block, holiday or restriction.', 'commonsbooking' ),
			__( 'Names', 'commonsbooking' )                    => __( 'Drag the edge of the name column in the header to widen it, double-click to reset. Shortened names show in full on hover.', 'commonsbooking' ),
			__( 'Period', 'commonsbooking' )                   => __( '← Today → moves the period, the filters above change start, length and day width. The selection is remembered.', 'commonsbooking' ),
		];
		echo '<details class="cb-plan-help"><summary>' . esc_html__( 'How to use the plan', 'commonsbooking' ) . '</summary>';
		echo '<div class="cb-plan-help-box"><dl>';
		foreach ( $steps as $term => $text ) {
			echo '<dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl></div></details>';
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
				echo '<div class="cb-plan-month" style="grid-column:' . (int) ( $start + 2 ) . ' / ' . (int) ( $i + 2 ) . '"><span>' . esc_html( date_i18n( $format, strtotime( $days[ $start ] ) ) ) . '</span></div>';
				$start = $i;
			}
		}
		echo '</div>';

		// Day row: buttons explain the day for all rows
		echo '<div class="cb-plan-line cb-plan-days"><div class="cb-plan-label"></div>';
		foreach ( $days as $i => $date ) {
			$ts      = strtotime( $date );
			$weekday = $dayWidth >= 28 ? '<span>' . esc_html( date_i18n( 'D', $ts ) ) . '</span>' : '';
			echo '<button type="button" class="cb-plan-dayhead' . esc_attr( self::dayClasses( $date, $plan['today'], null ) ) . '" style="grid-column:' . (int) ( $i + 2 ) . '" data-date="' . esc_attr( $date ) . '" data-tip="' . esc_attr( date_i18n( 'l, ' . get_option( 'date_format' ), $ts ) . ' · ' . __( 'week', 'commonsbooking' ) . ' ' . gmdate( 'W', $ts ) ) . '">';
			echo $weekday . '<b>' . esc_html( gmdate( 'j', $ts ) ) . '</b></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
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
		$plan     = $group['plan'];
		$groupId  = (int) $group['id'];
		$itemView = $plan['view'] === Plan::VIEW_ITEM;

		if ( $withHeading ) {
			self::renderGroupHeading( $group, $itemView );
		}

		if ( $plan['station']['segments'] ) {
			$label = $itemView ? __( 'All locations of the item', 'commonsbooking' ) : __( 'All items of the location', 'commonsbooking' );
			$help  = $itemView
				? __( 'Holidays, blocks and restrictions that apply to this item at every location shown. They are collected in this row instead of being repeated in each location row below.', 'commonsbooking' )
				: __( 'Holidays, blocks and restrictions that apply to every item at this location. They are collected in this row instead of being repeated in each item row below.', 'commonsbooking' );
			$name  = self::icon( 'all' ) . '<strong class="cb-plan-name" data-tip="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</strong>'
				. '<span class="cb-plan-info" tabindex="0" role="img" aria-label="' . esc_attr( $help ) . '" data-tip="' . esc_attr( $help ) . '">i</span>';
			self::renderRow( $plan, $dayWidth, $name, $plan['station']['lanes'], $plan['station']['segments'], null, 'cb-plan-station', 0, $groupId );
		}

		if ( ! $plan['rows'] ) {
			echo '<p class="cb-plan-empty" data-group="' . (int) $groupId . '">' . esc_html( $itemView ? __( 'No locations with timeframes or bookings for this item in the selected period.', 'commonsbooking' ) : __( 'No items with timeframes or bookings at this location in the selected period.', 'commonsbooking' ) ) . '</p>';
		}

		foreach ( $plan['rows'] as $row ) {
			$label = self::icon( $itemView ? 'location' : 'item' ) . self::name( $row['title'], $row['edit_url'] );
			if ( $row['conflicts'] ) {
				$label .= ' ' . self::conflictBadge( (int) $row['conflicts'] );
			}
			self::renderRow( $plan, $dayWidth, $label, $row['lanes'], $row['segments'], $row['window_end'], '', (int) $row['row_id'], $groupId );
		}
	}

	/**
	 * Heading of a location (item view: item) when several are shown: name, collapse toggle, number of rows and
	 * conflicts, "only this" button. Collapsing is done by the script and only changes the view.
	 *
	 * @param array $group
	 * @param bool  $itemView
	 *
	 * @return void
	 */
	private static function renderGroupHeading( array $group, bool $itemView ): void {
		$groupId   = (int) $group['id'];
		$rows      = $group['plan']['rows'];
		$conflicts = array_sum( array_column( $rows, 'conflicts' ) );
		$count     = count( $rows );
		/* translators: %s: name of the location or item */
		$collapse = sprintf( __( 'Collapse %s', 'commonsbooking' ), $group['title'] );
		$meta     = $itemView
			/* translators: %d: number of locations */
			? sprintf( _n( '%d location', '%d locations', $count, 'commonsbooking' ), $count )
			/* translators: %d: number of items */
			: sprintf( _n( '%d item', '%d items', $count, 'commonsbooking' ), $count );

		echo '<div class="cb-plan-line cb-plan-group" data-group="' . (int) $groupId . '">';
		echo '<div class="cb-plan-label">' . self::icon( $itemView ? 'item' : 'location' ) . self::name( $group['title'], $group['edit_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in icon() and name()
		echo '<button type="button" class="cb-plan-toggle" aria-expanded="true" aria-label="' . esc_attr( $collapse ) . '" data-tip="' . esc_attr( $collapse ) . '"></button></div>';
		echo '<div class="cb-plan-group-fill"><div class="cb-plan-group-meta"><span class="cb-plan-group-count">' . esc_html( $meta ) . '</span>';
		if ( $conflicts ) {
			echo self::conflictBadge( (int) $conflicts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in conflictBadge()
		}
		echo '<button type="button" class="cb-plan-solo">' . esc_html__( 'Only this', 'commonsbooking' ) . '</button>';
		echo '</div></div></div>';
	}

	/**
	 * @param int $count bookings with conflicts
	 *
	 * @return string html
	 */
	private static function conflictBadge( int $count ): string {
		/* translators: %d: number of bookings with conflicts */
		$tip = sprintf( _n( '%d booking with conflict', '%d bookings with conflicts', $count, 'commonsbooking' ), $count );

		return '<span class="cb-plan-badge" data-tip="' . esc_attr( $tip ) . '">⚠ ' . (int) $count . '</span>';
	}

	/**
	 * Name in the label column: shortened with an ellipsis, the full name as tooltip (shown when shortened).
	 *
	 * @param string      $title
	 * @param string|null $editUrl
	 *
	 * @return string
	 */
	private static function name( string $title, ?string $editUrl ): string {
		$name = $editUrl ? '<a href="' . esc_url( $editUrl ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

		return '<span class="cb-plan-name" data-tip="' . esc_attr( $title ) . '">' . $name . '</span>';
	}

	/**
	 * Symbol before a row label (shown in the frontend only).
	 *
	 * @param string $type location, item or all
	 *
	 * @return string
	 */
	private static function icon( string $type ): string {
		return '<span class="cb-plan-icon is-' . esc_attr( $type ) . '" aria-hidden="true"></span>';
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
		$weekday = (int) gmdate( 'N', (int) strtotime( $date ) );
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
	 * Detail panel, tooltip and configuration of the script. The details of all posts are embedded as JSON (only what the user may see
	 * anyway); day explanations are loaded via AJAX.
	 *
	 * @param array  $context
	 * @param array  $groups
	 * @param string $view
	 *
	 * @return void
	 */
	private static function renderPanel( array $context, array $groups, string $view ): void {
		$posts = [];
		foreach ( $groups as $group ) {
			$posts = array_replace( $posts, $group['plan']['posts'] );
		}
		$config = [
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'action'      => self::AJAX_DAY,
			'nonce'       => wp_create_nonce( self::AJAX_DAY ),
			'view'        => $view,
			'buttonClass' => self::buttonClass( $context ),
			'ids'         => array_map( fn( $group ) => (int) $group['id'], $groups ),
			'posts'       => (object) $posts,
			'i18n'        => [
				'edit'      => __( 'Edit', 'commonsbooking' ),
				'loading'   => __( 'Loading …', 'commonsbooking' ),
				'error'     => __( 'Could not load the details.', 'commonsbooking' ),
				'day'       => __( 'Day', 'commonsbooking' ),
				'reasons'   => __( 'Reasons', 'commonsbooking' ),
				'noReasons' => __( 'No timeframes, restrictions or bookings on this day.', 'commonsbooking' ),
				'conflicts' => __( 'Conflicts', 'commonsbooking' ),
				'calendar'  => __( 'In the booking calendar', 'commonsbooking' ),
				'resize'    => __( 'Width of the name column', 'commonsbooking' ),
				/* translators: %s: name of the location or item */
				'collapse'  => __( 'Collapse %s', 'commonsbooking' ),
				/* translators: %s: name of the location or item */
				'expand'    => __( 'Expand %s', 'commonsbooking' ),
				'expandAll' => __( 'Expand all', 'commonsbooking' ),
				'groups'    => [
					'timeframe'   => __( 'Timeframes', 'commonsbooking' ),
					'restriction' => __( 'Restrictions', 'commonsbooking' ),
					'booking'     => __( 'Bookings', 'commonsbooking' ),
				],
				'resizeTip' => __( 'Drag to widen the name column, double-click to reset.', 'commonsbooking' ),
			],
		];
		$front  = ' cb-plan-ui' . ( $context['frontend'] ? ' cb-plan-front' : '' );
		echo '<aside class="cb-plan-panel' . esc_attr( $front ) . '" id="cb-plan-panel" hidden aria-live="polite">';
		echo '<button type="button" class="cb-plan-close" aria-label="' . esc_attr__( 'Close', 'commonsbooking' ) . '">&times;</button>';
		echo '<div class="cb-plan-panel-body"></div>';
		echo '</aside>';
		echo '<div class="cb-plan-tip' . esc_attr( $front ) . '" id="cb-plan-tip" role="tooltip" hidden></div>';
		echo '<script type="application/json" id="cb-plan-data">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
	}
}
