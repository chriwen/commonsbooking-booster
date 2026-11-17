# Occupancy plan in the admin area

Status: stages 1 to 3 implemented, plus search with multiple selection (2026-11-15, not committed) in branch `feature/belegungsplan` (based on `booster/index-availability`), not committed.
No new tables, options or post meta, so downgradable (only one user meta entry for the last selection, read only
by this page). Works with and without the booster.
German version: `belegungsplan.md`.

## Goal

Admins and CB managers see for a location at a glance **when which item is booked by whom** and **why a day is not
bookable** (holidays, repair, restriction, no timeframe, beyond the advance booking period), with direct links to edit.

## Decisions of the user (2026-11-12)

- **Admin area only.** An info layer in the frontend calendar (tooltip per day) was rejected.
- **CB managers may see all booking data at a location** they manage, including items they do not manage.
- **Freely selectable period, also beyond the advance booking period**, and into the past.

## Decisions of the user (2026-11-15)

- **Selection via search field** (type-in) instead of a long dropdown.
- **Only active locations or items are offered:** published and part of an active combination, i.e. with at least one
  published bookable timeframe that has not ended (running or upcoming) whose location and item are both published.
  Drafts and entries without a timeframe are not listed. Entries already selected (e.g. a draft via the row action)
  stay visible; permissions still cover all allowed posts. "Running or upcoming" (instead of valid today only) is our
  reading of "currently active", so new combinations can be planned before they start.
- **The last selection is remembered** and loaded when the page is opened without parameters: view and, per view,
  selection, category, period, day width, "show canceled". The start date is not remembered (always today − 3 days).
  Stored in the user meta `commonsbooking_occupancy_plan` instead of the browser, so it applies on the first page load
  without a redirect and on every device.
- **Multiple selection:** several locations or items; each gets its own block with a heading in the plan.

## Decisions of the user (2026-11-17): occupancy plan in the frontend

Branch `feature/belegungsplan-frontend` (based on `feature/belegungsplan`).

- **Shortcode `[cb_occupancy_plan]`** shows the occupancy plan on any page, with the same features, data and
  computations as in the admin area. The decision "admin area only" of 2026-11-12 concerned the info layer in the
  booking calendar for all users and still applies to it.
- **Admins and CB managers only**, with their existing permissions; guests get a notice with a login link, other
  members the notice "The occupancy plan is only available to administrators and CB managers." without any data.
- **Remembered selection shared with the admin page** (same user meta).
- **"Edit" links in the frontend too** (they lead to the admin area), only with edit permission as before.
- **Shared rendering:** one class renders the plan for both, no duplicate code.
- **Attribute `lock`** for pages with a fixed selection (e.g. one page per location).

## Presentation

Admin page **Commons Booking → Occupancy plan** (`admin.php?page=cb-occupancy-plan`), plus row action
"Occupancy plan" in the location and item lists.

- **Two views** (tabs): **By location** (rows = items of the location) and **By item** (rows = locations of the item,
  `view=item`).
- **Selection:** search field with multiple selection (chips, filters while typing ignoring case and accents, arrow
  keys, Enter selects, Backspace removes the last chip, Enter in the empty field or "Show" loads; the button is
  highlighted when the selection changed). An empty selection shows a hint instead of a plan. Below: category of the rows (item category or location category; only if categories with
  entries exist), "Show canceled bookings"; second line: start date, period (1, 2, 4, 8 weeks, 3, 6, 12 months), day
  width, paging ← Today →. Default: 4 weeks from today − 3 days. At most 366 days.
- **Day width:** "Automatic" depends on the period (up to 2 weeks 96 px, 4 weeks 64 px, 8 weeks 44 px, 3 months
  28 px, longer 18 px), or fixed from very narrow to very wide. Wider days because names were unreadable at 30 px
  (feedback of the user).
- **Timeline:** columns = days (month and day header sticky, weekday from 28 px, today marked, weekends shaded, week and
  month starts as stronger lines), rows = items or locations (column sticky, link to edit with edit permission).
- **Bar labels** by width: full name from 60 px, below that initials for bookings and no text for blocks. The tooltip
  shows the rest.
- **Several locations or items:** one block per selected entry in a shared grid (shared day header), with a heading
  line (name, edit link) only from two entries on. Clicking the day header explains the day for all blocks (row title
  "Location · Item"), clicking a cell only for its block.
- **Row "All items of the location"** or "All locations of the item" (top, only if needed): blocks that occur identically in *every* item row (same
  post, same days) are moved there instead of being repeated. Only with two or more rows.
- **Layers per row:**
  1. bookable timeframe as light green, semi-transparent background with a green top edge on the days it applies
     (weekdays, manual dates, repetition); white = no timeframe;
  2. blocks as bars: holidays/closed (yellow), blocked/repair (dark red), restriction total breakdown (red), notice
     (yellow outline); inactive restrictions and unpublished timeframes faded;
  3. bookings as bars with the name of the booking user; unconfirmed dashed, canceled struck through.
  Overlapping bars get their own lanes (blocks first, then bookings; each lane is filled as far as possible).
- **Conflicts** (stage 2): bookings with a red outline and ⚠, counter "⚠ n" at the row. A conflict means: no bookable
  timeframe or location closed (holiday) on the pickup or return day, or overlap with a block that cannot be
  overbooked (blocked/repair, active total breakdown). Pickup and return day are only checked if they are in the
  period. Canceled bookings have no conflicts. It shows today's rules, not whether the booking was valid back then.
- **End of the advance booking period:** days after the latest booking window end of the bookable timeframes are
  hatched. If the timeframe ends earlier, there is no hatching.
- Bars that continue outside the period have square ends on that side.

## Interaction

- **Click on a bar** opens a side panel: kind, title, conflicts, details, "Edit" button (only with edit permission).
  Esc or × closes it.
- **Click on a day** (stage 2, "Why is this day like this?"): cell = this day for this row, day header = all rows. The
  panel shows the result as the booking calendar sees it (bookable, partially booked, booked/blocked for the whole day,
  all slots locked, holidays/closed, no timeframe), notes on the past and the advance booking period, and below all
  reasons (timeframes, restrictions, bookings) expandable with details and edit link. Loaded via AJAX.
- **Tooltip on hover** as its own element (`position: fixed`, on top) instead of `title`: the native `title` was hidden
  by the scroll area and the sticky column (feedback of the user). Also on keyboard focus.
- Timeframe details: period, repetition, weekdays or dates, time, maximum booking days, advance booking days, role
  restriction, status (if not published).
- Restriction details: period, state, hint text.
- Booking details: period, status, name, email, phone, comment (these three always, "–" if empty), booking code,
  booked on. Phone is the profile field `phone`, comment the booking field `comment`.
- Read-only, bookings cannot be moved.

## Frontend (shortcode)

```
[cb_occupancy_plan]
[cb_occupancy_plan view="item" days="56"]
[cb_occupancy_plan location="12,15" lock="1" align="wide"]
```

| Attribute | Meaning | Default |
|---|---|---|
| `view` | `location` or `item`; if omitted, derived from `location`/`item`, otherwise from the remembered selection | `location` |
| `location` / `item` | preselection (ids, comma separated), always intersected with the permissions | remembered selection |
| `days` | 7, 14, 28, 56, 91, 182, 365 | 28 |
| `zoom` | `xs`, `s`, `m`, `l`, `xl` or empty (automatic) | automatic |
| `canceled` | `1` = show canceled bookings | `0` |
| `tabs` | `0` = hide the view tabs | `1` |
| `lock` | `1` = fixed selection: search field and tabs hidden, view and selection from the URL or the remembered selection are ignored, the remembered selection is not overwritten. Period, category etc. stay usable. Affects the selection only together with `location` or `item`. | `0` |
| `align` | `wide` or `full` (classes `alignwide`/`alignfull` for block themes) | – |

- **Precedence:** URL parameters (after submitting the form or paging) over attributes over the remembered selection.
- **URL parameters with prefix `cbp_`** (`cbp_view`, `cbp_location[]`, `cbp_item[]`, `cbp_cat`, `cbp_from`,
  `cbp_days`, `cbp_zoom`, `cbp_canceled`, `cbp_sel`): without the prefix WordPress would read `cat` as category query.
  The admin page keeps its names. The page's own query (e.g. `page_id` with plain permalinks) is kept as hidden field
  in the GET form.
- **One instance per page** (fixed element ids); a second one shows a notice.
- **No page cache:** on pages with the shortcode `nocache_headers()` (via `template_redirect`) and
  `DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCACHEDB` plus `litespeed_control_set_nocache`, since the plan contains
  names, email and phone.
- **Display in themes:**
  - All rules are scoped to the ids `#cb-plan-root` (wrapper), `#cb-plan-panel` and `#cb-plan-tip`. The script moves
    panel and tooltip to `<body>`, so `position: fixed` does not depend on ancestors with `transform` or `overflow`.
    Theme rules such as `.entry-content button` (specificity 0,1,1) therefore cannot override the plan.
  - In the frontend, reset rules with `#cb-plan-root:where(.cb-plan-front) button` (1,0,1) undo typical theme styles
    (buttons, lists, links, form fields, headings in the panel). They rank above theme rules and below the plan's own
    rules (1,1,0).
  - Built from cards instead of one wrapper: the view as a segmented control, the filters as a light grey toolbar
    card (rows selection, options, period separated by hairlines, small labels above the fields, 36 px fields with a
    focus ring, "Show" as primary button, ← Today → as a button group), legend and plan together in a white card
    (12 px radius). Borders and grid as light grey 1 px hairlines, today as a light tint instead of thick lines, bars
    with 6 px radius. Decision of 17.11. at the user's request (lines too thick, filters poorly structured). Frontend
    only (`.cb-plan-front`); the admin page keeps the WP style.
  - Font from the theme, base size 14 px (day widths are in px), own tabs and buttons instead of the admin classes,
    accent colour overridable via the CSS variable `--cb-plan-accent`. Deviation from the concept: no sizes in `rem` and no use of
    `--wp--preset--color--primary`, because themes use that variable inconsistently.
  - Scroll area at most 75 % of the window height, panel above theme headers (`z-index` 99999), below the admin bar,
    full width below 600 px.
  - Checked in the browser with Twenty Twenty-Five (block theme) and Twenty Twenty-One (classic), desktop and 375 px.

## Permissions

- Page and shortcode: capability `manage_commonsbooking` (admins and CB managers). In the frontend without it only a
  notice, no data, no list of choices, no nonce.
- Locations: `Repository\Location::getByCurrentUser()`, i.e. all for admins, otherwise the locations the user manages
  or authored. Another location in the URL falls back to the first allowed one.
- Items (view by item): `Repository\Item::getByCurrentUser()` accordingly. All locations where the item has a
  timeframe are shown, with all bookings (in line with the decision above).
- For an allowed location **all** bookings are shown with full data (decision above). Edit links still check
  `commonsbooking_isCurrentUserAllowedToEdit()`, so existing permissions do not change.
- Day explanation via AJAX (`wp_ajax_cb_occupancy_day`, parameter `ids` as a list): nonce, capability
  `manage_commonsbooking` and every location or item of the list are checked on every request.
- No cache for the page (the calendar JSON is keyed by roles only and must not contain names).

## Technical notes

- `Service\OccupancyPlan::build( $locationId, $from, $to, $withCanceled, $onlyRows )` and `buildForItem( $itemId, … )`
  return view, days, rows (`row_id`, `item_id`, `location_id`, `conflicts`), segments (`kind`, `post_id`, `from`/`to`
  as day index, `clip_start`/`clip_end`, `lane`, `label`, `state`, `conflict`) and the details per post. `$onlyRows` is
  the category filter (ids from `get_objects_in_term()`).
- `explainDay( $view, $id, $date, $rowId )` builds the plan for one day and returns per row `dayResult()` (result via
  `DayFacts::fromGrid()` of `Model\Day`, i.e. the same evaluation as the calendar) and the reasons.
- `View\OccupancyPlan::render( $context )` renders a CSS grid (subgrid per row) for the admin page and the shortcode;
  the context only sets base url, parameter prefix, tabs, button classes and the wrapper. `View\Admin\OccupancyPlan`
  is the thin layer for menu, row actions and admin page. CSS and script (no dependencies) live in
  `assets/occupancy-plan/` without a build step and are loaded only on the admin page or on pages with the shortcode.
- **Plan rendered on the server** (deviation from the concept): the page reloads via GET. Only the day explanation
  uses AJAX, because it computes the calendar grid per day, which would be too expensive for all days on page load. The details of all visible posts are embedded as JSON, for permitted users only.
- **Data sources:**
  - Items of the location: bookable timeframes of the location in the period (`Repository\Timeframe::getInRange`)
    plus items with bookings in the period.
  - Timeframes per item: `getInRange( …, [ $location ], [ $item ], [ bookable, holidays, repair ] )` like
    `Model\Calendar`, so lists, categories and "all" are included.
  - Restrictions: `Repository\Restriction::get()` with the statuses from `Day::RESTRICTION_STATUSES`.
  - Bookings: `Repository\Booking::getByTimerange()`; uses the index when the booster is on.
- **Same rules as the calendar:** whether a timeframe applies on a day is decided by
  `Repository\Timeframe::filterTimeframesForTimerange()` and `Model\Day::isInTimeframe()`, run inside
  `Model\Timeframe::withoutBookingWindow()` so that days after the advance booking period are shown too. No second
  implementation of the repetition rules.
- **Tests** (`tests/php/Service/OccupancyPlanTest.php`): weekdays and booking window, bookings with status and
  clipping, station row, restriction without end, lanes, permissions for CB managers, comparison with `DayFacts` from
  the calendar grid, same result with and without the booster, view by item and category filter, conflicts (holiday on
  the pickup day, block, no timeframe, clipping), day explanation (reasons, result, notes), active combinations (`activeIds()`: running, upcoming, ended, draft,
  holiday only, without timeframe).
- **Shortcode tests** (`tests/php/View/OccupancyPlanShortcodeTest.php`): guest, member without permission, CB manager
  without and with location (foreign ids via attribute and URL are dropped), admin, parameter prefix and `page_id` in
  the form, same grid as the admin page, URL over attribute, unchecking "canceled", `lock`, view from the ids, shared
  remembered selection, one instance only, item view with `align`.

## Stages

1. **Done:** timeline per location, detail panel, permissions, tests.
2. **Done:** "Why is this day like this?" and conflict marking.
3. **Done:** view per item, filter by category; plus reworked layout (day width, tooltip).
   Afterwards (2026-11-15): search field with multiple selection, active entries only, remembered selection.
4. Optional: print/export, link with `feature/dashboard`.
5. **Done (2026-11-17, branch `feature/belegungsplan-frontend`):** shortcode `[cb_occupancy_plan]`, shared rendering
   for admin area and frontend.

## Open questions

- Default period. No limit on the number of selected entries; each block builds its plan separately.
- Hourly bookings currently show only the day; the time is in the panel.
