<?php

namespace CommonsBooking\View\Admin;

use CommonsBooking\Repository\AssignedTimeframes as Repository;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;

/**
 * Metabox "Assigned timeframes" on the edit page of items and locations: which timeframes and restrictions control them.
 *
 * @see design/zugeordnete-zeitrahmen.md
 */
class AssignedTimeframes {

	public const METABOX_ID = 'cb-assigned-timeframes';

	/**
	 * Maximum number of names shown for other items / locations of a timeframe.
	 */
	private const MAX_NAMES = 3;

	/**
	 * Maximum number of expired entries shown.
	 */
	private const MAX_EXPIRED = 20;

	/**
	 * Registers the metabox on item and location edit pages as the first box of the main column.
	 *
	 * Runs on `add_meta_boxes_{post_type}`, i.e. after CMB2 has registered its boxes (priority "high") on
	 * `add_meta_boxes`. Boxes of equal priority are shown in registration order, so the box is moved to the front.
	 * An order saved by the user (drag and drop) still takes precedence.
	 *
	 * @param string $postType
	 *
	 * @return void
	 */
	public static function addMetaBox( string $postType ): void {
		global $wp_meta_boxes;

		if ( $postType !== Item::$postType && $postType !== Location::$postType ) {
			return;
		}
		add_meta_box(
			self::METABOX_ID,
			esc_html__( 'Assigned timeframes', 'commonsbooking' ),
			array( self::class, 'render' ),
			$postType,
			'normal',
			'high'
		);

		$boxes = $wp_meta_boxes[ $postType ]['normal']['high'] ?? array();
		if ( isset( $boxes[ self::METABOX_ID ] ) ) {
			$wp_meta_boxes[ $postType ]['normal']['high'] = array( self::METABOX_ID => $boxes[ self::METABOX_ID ] ) + $boxes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reorder only
		}
	}

	/**
	 * Returns the link to the metabox on the edit page.
	 *
	 * @param int $postId
	 *
	 * @return string
	 */
	public static function getLink( int $postId ): string {
		return get_edit_post_link( $postId, 'url' ) . '#' . self::METABOX_ID;
	}

	/**
	 * Renders the metabox.
	 *
	 * @param \WP_Post $post
	 *
	 * @return void
	 */
	public static function render( \WP_Post $post ): void {
		if ( $post->post_status === 'auto-draft' ) {
			echo '<p>' . esc_html__( 'Save the post first to see its timeframes.', 'commonsbooking' ) . '</p>';
			return;
		}
		if ( ! commonsbooking_isCurrentUserAllowedToEdit( $post ) ) {
			return;
		}

		$entity = $post->post_type === Item::$postType ? 'item' : 'location';
		$result = $entity === 'item' ? Repository::forItem( $post->ID ) : Repository::forLocation( $post->ID );

		echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS
		echo '<div class="cb-assigned-timeframes">';
		echo '<p class="cb-at-intro"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' . esc_html( self::introText( $entity ) ) . '</p>';
		self::renderSummary( $entity, $post->ID, $result['counterparts'] );

		$groups = $result['groups'];
		if ( ! $groups[ Repository::GROUP_CURRENT ] && ! $groups[ Repository::GROUP_FUTURE ] && ! $groups[ Repository::GROUP_EXPIRED ] ) {
			echo '<p class="cb-at-empty">' . esc_html__( 'No timeframes or restrictions are assigned.', 'commonsbooking' ) . '</p>';
		} else {
			self::renderGroup( Repository::GROUP_CURRENT, __( 'Current', 'commonsbooking' ), $groups[ Repository::GROUP_CURRENT ], $entity, true );
			self::renderGroup( Repository::GROUP_FUTURE, __( 'Upcoming', 'commonsbooking' ), $groups[ Repository::GROUP_FUTURE ], $entity, true );
			self::renderGroup( Repository::GROUP_EXPIRED, __( 'Expired', 'commonsbooking' ), $groups[ Repository::GROUP_EXPIRED ], $entity, false );
		}
		echo '</div>';
	}

	/**
	 * Short explanation shown at the top of the metabox.
	 *
	 * @param string $entity
	 *
	 * @return string
	 */
	public static function introText( string $entity ): string {
		return $entity === 'item'
			? __( 'All timeframes and restrictions assigned to this item, whether directly, via a list, a category or "all". Holidays and blocked timeframes only apply at locations that are selected in the timeframe as well. The column "Applies at" shows where. ⚠ marks entries that are assigned but currently have no effect.', 'commonsbooking' )
			: __( 'All timeframes and restrictions assigned to this location, whether directly, via a list, a category or "all". Holidays and blocked timeframes only apply to items that are selected in the timeframe as well. The column "Affects items" shows which ones. ⚠ marks entries that are assigned but currently have no effect.', 'commonsbooking' );
	}

	/**
	 * Toolbar with the counterparts (where the item is bookable / which items are bookable) and the link to the list.
	 *
	 * @param string $entity
	 * @param int    $postId
	 * @param int[]  $counterparts
	 *
	 * @return void
	 */
	private static function renderSummary( string $entity, int $postId, array $counterparts ): void {
		$label = $entity === 'item'
			? __( 'Bookable at:', 'commonsbooking' )
			: __( 'Bookable items:', 'commonsbooking' );
		$names = $counterparts ? self::names( $counterparts ) : esc_html__( 'none (no bookable timeframe)', 'commonsbooking' );

		$listUrl = add_query_arg(
			array(
				'post_type'               => Timeframe::getPostType(),
				'admin_filter_' . $entity => $postId,
			),
			admin_url( 'edit.php' )
		);

		printf(
			'<div class="cb-at-toolbar"><div class="cb-at-bookable"><span class="dashicons %s" aria-hidden="true"></span><strong>%s</strong> %s</div><a class="button button-small" href="%s"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>%s</a></div>',
			$entity === 'item' ? 'dashicons-location' : 'dashicons-products',
			esc_html( $label ),
			$names, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in names()
			esc_url( $listUrl ),
			esc_html__( 'Show in timeframe list', 'commonsbooking' )
		);
	}

	/**
	 * @param string $group one of the Repository::GROUP_* constants
	 * @param string $title
	 * @param array  $entries
	 * @param string $entity
	 * @param bool   $open
	 *
	 * @return void
	 */
	private static function renderGroup( string $group, string $title, array $entries, string $entity, bool $open ): void {
		$count = count( $entries );
		printf(
			'<details class="cb-at-group cb-at-group--%s"%s><summary><span class="cb-at-dot" aria-hidden="true"></span><span class="cb-at-group-title">%s</span><span class="cb-at-count">%d</span></summary>',
			esc_attr( $group ),
			$open && $count ? ' open' : '',
			esc_html( $title ),
			(int) $count
		);
		if ( ! $count ) {
			echo '<p class="cb-at-empty">' . esc_html__( 'No entries.', 'commonsbooking' ) . '</p></details>';
			return;
		}

		$shown = $open ? $entries : array_slice( $entries, 0, self::MAX_EXPIRED );

		$headers = array(
			__( 'Type', 'commonsbooking' ),
			__( 'Title (status)', 'commonsbooking' ),
			__( 'Period', 'commonsbooking' ),
			__( 'Repetition', 'commonsbooking' ),
			__( 'Grid', 'commonsbooking' ),
			__( 'Items', 'commonsbooking' ),
			__( 'Locations', 'commonsbooking' ),
			$entity === 'item' ? __( 'Applies at', 'commonsbooking' ) : __( 'Affects items', 'commonsbooking' ),
		);
		echo '<div class="cb-at-scroll"><table class="cb-at-table"><thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $shown as $entry ) {
			self::renderEntry( $entry, $entity );
		}
		echo '</tbody></table></div>';

		if ( count( $shown ) < $count ) {
			printf(
				'<p class="cb-at-empty">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of entries not shown */
						__( '%d more entries are not shown.', 'commonsbooking' ),
						$count - count( $shown )
					)
				)
			);
		}
		echo '</details>';
	}

	/**
	 * Visual variant of the entry: bookable, holiday, blocked, breakdown, notice.
	 *
	 * @param array $entry
	 *
	 * @return array{0: string, 1: string} css modifier and dashicon
	 */
	private static function variant( array $entry ): array {
		if ( $entry['kind'] === 'restriction' ) {
			return $entry['type'] === \CommonsBooking\Model\Restriction::TYPE_REPAIR
				? array( 'breakdown', 'dashicons-warning' )
				: array( 'notice', 'dashicons-megaphone' );
		}
		switch ( $entry['type'] ) {
			case Timeframe::BOOKABLE_ID:
				return array( 'bookable', 'dashicons-yes-alt' );
			case Timeframe::HOLIDAYS_ID:
				return array( 'holiday', 'dashicons-palmtree' );
			default:
				return array( 'blocked', 'dashicons-hammer' );
		}
	}

	/**
	 * @param array  $entry
	 * @param string $entity
	 *
	 * @return void
	 */
	private static function renderEntry( array $entry, string $entity ): void {
		list( $variant, $icon ) = self::variant( $entry );
		$classes                = array( 'cb-at-entry', 'cb-at-entry--' . $variant );
		if ( ! $entry['effective'] ) {
			$classes[] = 'cb-at-entry--inactive';
		}

		// title cell: title, status pill, read-only pill, warning, hint
		$title = $entry['title'] !== '' ? $entry['title'] : '#' . $entry['post_id'];
		if ( $entry['can_edit'] ) {
			$titleHtml = sprintf( '<a class="cb-at-title" href="%s">%s</a>', esc_url( get_edit_post_link( $entry['post_id'], 'url' ) ), esc_html( $title ) );
		} else {
			$titleHtml = '<span class="cb-at-title">' . esc_html( $title ) . '</span>';
		}
		$active     = $entry['kind'] === 'restriction' ? $entry['status'] === 'active' : $entry['status'] === 'publish';
		$titleHtml .= sprintf( ' <span class="cb-at-pill cb-at-pill--%s">%s</span>', $active ? 'ok' : 'off', esc_html( self::statusLabel( $entry ) ) );
		if ( ! $entry['can_edit'] ) {
			$titleHtml .= ' <span class="cb-at-pill cb-at-pill--readonly"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__( 'managed by an administrator', 'commonsbooking' ) . '</span>';
		}
		if ( ! $entry['effective'] ) {
			$titleHtml .= '<div class="cb-at-warning">⚠ ' . esc_html( self::ineffectiveReason( $entry, $entity ) ) . '</div>';
		}
		if ( $entry['kind'] === 'restriction' && $entry['hint'] && $entry['can_edit'] ) {
			$titleHtml .= '<div class="cb-at-hint">' . esc_html( wp_trim_words( $entry['hint'], 25 ) ) . '</div>';
		}

		$start = $entry['start'] ? date_i18n( get_option( 'date_format' ), $entry['start'] ) : '?';
		$end   = $entry['end'] ? date_i18n( get_option( 'date_format' ), $entry['end'] ) : __( 'open end', 'commonsbooking' );

		printf(
			'<tr class="%s"><td class="cb-at-type"><span class="cb-at-badge"><span class="dashicons %s" aria-hidden="true"></span>%s</span></td><td>%s</td><td class="cb-at-nowrap">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="cb-at-applies">%s</td></tr>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $icon ),
			esc_html( $entry['type_label'] ),
			$titleHtml, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			esc_html( $start . ' – ' . $end ),
			$entry['repetition'] !== null ? esc_html( self::repetitionLabel( $entry ) ) : '–',
			$entry['grid'] !== null ? esc_html( self::gridLabel( $entry ) ) : '–',
			self::assignmentHtml( $entry['items'], Item::getTaxonomyName() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
			self::assignmentHtml( $entry['locations'], Location::getTaxonomyName() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
			$entry['effective_at'] ? self::names( $entry['effective_at'] ) : '–' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
		);
	}

	/**
	 * @param array $entry
	 *
	 * @return string
	 */
	private static function statusLabel( array $entry ): string {
		if ( $entry['kind'] === 'restriction' ) {
			$labels = array(
				'active' => __( 'active', 'commonsbooking' ),
				'solved' => __( 'solved', 'commonsbooking' ),
				'none'   => __( 'not active', 'commonsbooking' ),
			);

			return $labels[ $entry['status'] ] ?? $entry['status'];
		}
		$status = get_post_status_object( $entry['status'] );

		return $status ? $status->label : $entry['status'];
	}

	/**
	 * @param array  $entry
	 * @param string $entity
	 *
	 * @return string
	 */
	private static function ineffectiveReason( array $entry, string $entity ): string {
		if ( $entry['kind'] === 'timeframe' && $entry['status'] !== 'publish' ) {
			return __( 'not published', 'commonsbooking' );
		}
		if ( $entry['kind'] === 'restriction' && $entry['status'] !== 'active' && $entry['effective_at'] ) {
			return __( 'restriction not active', 'commonsbooking' );
		}

		return $entity === 'item'
			? __( 'assigned, but not at a location of this item', 'commonsbooking' )
			: __( 'assigned, but not for an item of this location', 'commonsbooking' );
	}

	/**
	 * @param array $entry
	 *
	 * @return string
	 */
	public static function repetitionLabel( array $entry ): string {
		if ( $entry['repetition'] === null ) {
			return '–';
		}
		$repetitions = Timeframe::getTimeFrameRepetitions();
		$label       = $repetitions[ $entry['repetition'] ] ?? $entry['repetition'];

		if ( $entry['repetition'] === 'w' && $entry['weekdays'] ) {
			$label .= ': ' . self::weekdaysLabel( $entry['weekdays'] );
		}
		if ( $entry['repetition'] === 'manual' && $entry['manual_dates'] ) {
			$dates  = array_map(
				fn( $date ) => date_i18n( get_option( 'date_format' ), strtotime( $date ) ),
				array_slice( $entry['manual_dates'], 0, 5 )
			);
			$label .= ': ' . implode( ', ', $dates );
			if ( count( $entry['manual_dates'] ) > 5 ) {
				$label .= ' …';
			}
		}

		return $label;
	}

	/**
	 * @param int[] $weekdays 1 = Monday … 7 = Sunday
	 *
	 * @return string
	 */
	public static function weekdaysLabel( array $weekdays ): string {
		global $wp_locale;
		sort( $weekdays );
		if ( $weekdays === array( 1, 2, 3, 4, 5, 6, 7 ) ) {
			return __( 'every day', 'commonsbooking' );
		}

		return implode(
			', ',
			array_map(
				fn( $day ) => $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day % 7 ) ) : (string) $day,
				$weekdays
			)
		);
	}

	/**
	 * @param array $entry
	 *
	 * @return string
	 */
	public static function gridLabel( array $entry ): string {
		switch ( $entry['grid'] ) {
			case 'full':
				return __( 'Full day', 'commonsbooking' );
			case 'slot':
				return sprintf( '%s %s–%s', __( 'Slot', 'commonsbooking' ), $entry['start_time'], $entry['end_time'] );
			case 'hourly':
				return sprintf( '%s %s–%s', __( 'Hourly', 'commonsbooking' ), $entry['start_time'], $entry['end_time'] );
			default:
				return '–';
		}
	}

	/**
	 * Describes the assignment of items or locations, e.g. "all (37)", "Category: Cargo bikes (5)" or names.
	 * Also used in the column of the timeframe list.
	 *
	 * @param array  $assignment see {@see Repository::assignment()}
	 * @param string $taxonomy
	 *
	 * @return string HTML
	 */
	public static function assignmentHtml( array $assignment, string $taxonomy ): string {
		$count = count( $assignment['ids'] );
		switch ( $assignment['mode'] ) {
			case Repository::ASSIGNMENT_ALL:
				/* translators: %d: number of items or locations */
				return esc_html( $count ? sprintf( __( 'all (%d)', 'commonsbooking' ), $count ) : __( 'all', 'commonsbooking' ) );
			case Repository::ASSIGNMENT_CATEGORY:
				$terms = array_filter( array_map( fn( $id ) => get_term( $id, $taxonomy ), $assignment['category_ids'] ) );
				$terms = array_filter( $terms, fn( $term ) => $term instanceof \WP_Term );
				$label = implode( ', ', array_map( fn( $term ) => $term->name, $terms ) );

				/* translators: 1: category names, 2: number of items or locations */
				return esc_html( sprintf( __( 'Category: %1$s (%2$d)', 'commonsbooking' ), $label, $count ) );
			case Repository::ASSIGNMENT_LIST:
				/* translators: %d: number of items or locations */
				return esc_html( sprintf( __( 'List (%d)', 'commonsbooking' ), $count ) ) . '<br>' . self::names( $assignment['ids'] );
			default:
				return $count ? self::names( $assignment['ids'] ) : '–';
		}
	}

	/**
	 * Returns the names of the posts. Only posts the current user may edit are named, the others are counted.
	 *
	 * @param int[] $ids
	 *
	 * @return string HTML
	 */
	private static function names( array $ids ): string {
		$names  = array();
		$hidden = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_status === 'trash' ) {
				continue;
			}
			if ( count( $names ) >= self::MAX_NAMES || ! self::mayEdit( $post ) ) {
				++$hidden;
				continue;
			}
			$names[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $post->ID, 'url' ) ), esc_html( get_the_title( $post ) ) );
		}
		if ( $hidden ) {
			/* translators: %d: number of further items or locations */
			$names[] = esc_html( sprintf( _n( '%d more', '%d more', $hidden, 'commonsbooking' ), $hidden ) );
		}

		return $names ? implode( ', ', $names ) : '–';
	}

	/**
	 * @param \WP_Post $post
	 *
	 * @return bool
	 */
	private static function mayEdit( \WP_Post $post ): bool {
		if ( commonsbooking_isCurrentUserAdmin() ) {
			return true;
		}
		try {
			return commonsbooking_isCurrentUserAllowedToEdit( $post );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * @return string
	 */
	private static function styles(): string {
		// WordPress admin colors (notice / status colors of the core admin styles)
		return '<style>
			.cb-assigned-timeframes { --cb-at-border: #dcdcde; --cb-at-muted: #646970; }
			.cb-assigned-timeframes .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
			.cb-assigned-timeframes .cb-at-intro { display: flex; gap: 6px; color: var(--cb-at-muted); margin: 4px 0 12px; }
			.cb-assigned-timeframes .cb-at-intro .dashicons { flex: none; margin-top: 2px; }
			.cb-assigned-timeframes .cb-at-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 8px 12px; margin-bottom: 12px; background: #f6f7f7; border: 1px solid var(--cb-at-border); border-radius: 4px; }
			.cb-assigned-timeframes .cb-at-bookable .dashicons { color: #2271b1; margin-right: 4px; }
			.cb-assigned-timeframes .cb-at-toolbar .button .dashicons { margin: 0 4px 0 -2px; vertical-align: middle; }
			.cb-assigned-timeframes .cb-at-group { margin: 0 0 12px; }
			.cb-assigned-timeframes .cb-at-group > summary { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 0; font-weight: 600; font-size: 13px; list-style: none; }
			.cb-assigned-timeframes .cb-at-group > summary::-webkit-details-marker { display: none; }
			.cb-assigned-timeframes .cb-at-group > summary::after { content: "\f140"; font-family: dashicons; color: var(--cb-at-muted); }
			.cb-assigned-timeframes .cb-at-group[open] > summary::after { content: "\f142"; }
			.cb-assigned-timeframes .cb-at-dot { width: 10px; height: 10px; border-radius: 50%; background: #00a32a; }
			.cb-assigned-timeframes .cb-at-group--future .cb-at-dot { background: #2271b1; }
			.cb-assigned-timeframes .cb-at-group--expired .cb-at-dot { background: #a7aaad; }
			.cb-assigned-timeframes .cb-at-count { min-width: 18px; padding: 0 6px; border-radius: 9px; background: #f0f0f1; color: #50575e; font-size: 11px; line-height: 18px; text-align: center; }
			.cb-assigned-timeframes .cb-at-scroll { overflow-x: auto; border: 1px solid var(--cb-at-border); border-radius: 4px; }
			.cb-assigned-timeframes .cb-at-table { width: 100%; border-collapse: collapse; background: #fff; }
			.cb-assigned-timeframes .cb-at-table th { text-align: left; padding: 6px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--cb-at-muted); background: #f6f7f7; border-bottom: 1px solid var(--cb-at-border); white-space: nowrap; }
			.cb-assigned-timeframes .cb-at-table td { padding: 8px 10px; vertical-align: top; border-bottom: 1px solid #f0f0f1; }
			.cb-assigned-timeframes .cb-at-table tbody tr:last-child td { border-bottom: 0; }
			.cb-assigned-timeframes .cb-at-table tbody tr:hover td { background: #f6f7f7; }
			.cb-assigned-timeframes .cb-at-entry { --cb-at-accent: #2271b1; --cb-at-tint: #f0f6fc; --cb-at-ink: #135e96; }
			.cb-assigned-timeframes .cb-at-entry > td:first-child { box-shadow: inset 4px 0 0 var(--cb-at-accent); padding-left: 14px; }
			.cb-assigned-timeframes .cb-at-entry--bookable { --cb-at-accent: #00a32a; --cb-at-tint: #edfaef; --cb-at-ink: #007017; }
			.cb-assigned-timeframes .cb-at-entry--holiday { --cb-at-accent: #dba617; --cb-at-tint: #fcf9e8; --cb-at-ink: #8a6d00; }
			.cb-assigned-timeframes .cb-at-entry--blocked, .cb-assigned-timeframes .cb-at-entry--breakdown { --cb-at-accent: #d63638; --cb-at-tint: #fcf0f1; --cb-at-ink: #b32d2e; }
			.cb-assigned-timeframes .cb-at-entry--notice { --cb-at-accent: #72aee6; --cb-at-tint: #f0f6fc; --cb-at-ink: #135e96; }
			.cb-assigned-timeframes .cb-at-entry--inactive { --cb-at-accent: #c3c4c7; }
			.cb-assigned-timeframes .cb-at-entry--inactive td { color: #787c82; }
			.cb-assigned-timeframes .cb-at-entry--inactive .cb-at-badge { filter: grayscale(1); opacity: .8; }
			.cb-assigned-timeframes .cb-at-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 3px; background: var(--cb-at-tint); color: var(--cb-at-ink); font-size: 12px; font-weight: 500; white-space: nowrap; }
			.cb-assigned-timeframes .cb-at-title { font-weight: 600; }
			.cb-assigned-timeframes .cb-at-pill { display: inline-flex; align-items: center; gap: 3px; padding: 0 7px; border-radius: 10px; font-size: 11px; line-height: 18px; background: #f0f0f1; color: #50575e; white-space: nowrap; }
			.cb-assigned-timeframes .cb-at-pill--ok { background: #edfaef; color: #007017; }
			.cb-assigned-timeframes .cb-at-pill .dashicons { font-size: 12px; width: 12px; height: 12px; vertical-align: middle; }
			.cb-assigned-timeframes .cb-at-nowrap { white-space: nowrap; }
			.cb-assigned-timeframes .cb-at-applies { font-weight: 600; }
			.cb-assigned-timeframes .cb-at-warning { margin-top: 4px; color: #8a6d00; font-size: 12px; }
			.cb-assigned-timeframes .cb-at-hint { margin-top: 4px; color: var(--cb-at-muted); font-style: italic; font-size: 12px; }
			.cb-assigned-timeframes .cb-at-empty { margin: 0 0 4px 18px; color: var(--cb-at-muted); }
		</style>';
	}
}
