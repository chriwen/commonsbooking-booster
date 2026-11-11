# Analytics dashboard (utilisation and usage)

Status: draft (2026-11-09), branch `feature/dashboard` (based on `booster/index-availability`). Aggregation (phase 1, step 1) implemented, user interface to follow.
Own tables and options only, no change to existing postmeta, so it stays downgradable.

## Goal

The administrators of an instance see at a glance **how heavily items and locations are used** and where
action is needed (idle time, fully booked periods, late cancellations, outages). This feeds reports for boards, funders
and municipalities. An instance belongs to exactly one initiative; there is no grouping of several initiatives. The
dashboard reads only precomputed daily values, so it answers in milliseconds even on instances with 100,000 bookings.

## Metrics

Groups A to F. Phase says when the metric ships (see "Phases").

| # | Metric | Group | Phase |
|---|---|---|---|
| 1 | Utilisation rate per item, location, period | A Utilisation | 1 |
| 2 | Usage days and bookings per item, also per month; total per location | A | 1 |
| 3 | Idle rate (bookable, never booked), items with persistently low utilisation | A | 1 |
| 4 | Heatmap by weekday, month and season | A | 1 |
| 5 | Fully booked periods (share of days at 100 % utilisation) | A | 1 |
| 6 | Overall availability (share of time without block or repair) | A | 1 |
| 7 | Booking duration: mean, median, distribution | B Demand | 1 |
| 8 | Lead time (creation to start) | B | 1 |
| 9 | Cancellation rate, cancellation timing (early / shortly before start) | B | 1 |
| 10 | Bookings per day, week, month with year-over-year comparison | B | 1 |
| 11 | Active users, new and returning | C Users | 2 |
| 12 | Bookings per user (distribution), share of top 10 | C | 2 |
| 13 | New registrations and first-booking rate | C | 2 |
| 14 | Downtime (repair, restrictions), breakdown frequency | D Operations | 2 |
| 15 | Booking codes: stock for the coming days | D | 2 |
| 16 | Configuration warnings (items without timeframe, expiring timeframes, conflicts) | D | 2 |
| 17 | Location comparison: bookings, utilisation, cancellations | E Area | 1 |
| 18 | Item categories compared | E | 1 |
| 19 | Map view of utilisation (locations coloured) | E | 3 |
| 20 | Impact: total usage days and CO2 estimate with configurable assumption | F Impact | 3 |

Not measurable as long as the plugin records no handover: actual pickup and return (no-show rate), kilometres,
condition, rejected requests. That is not part of this draft.

## Definitions

- **Station** = location. An instance belongs to exactly one initiative; there are no groupings of several initiatives
  or location groups (decision 2026-11-09, replaces the earlier approach via location categories). Location and item
  categories serve only as **filters** (see "User interface").
- **Capacity** per item, location and day = slots of the bookable timeframes, minus repair, holiday and block
  timeframes. Counted in the timeframe's own slots. Full-day and hourly timeframes are kept **separate** (decision
  2026-11-09): every row carries the unit `day` (full day, one slot per day) or `hour` (hour slots). Utilisation rates
  are formed only within one unit, never mixed across units. Unit-free counts (bookings, cancellations, usage days) may
  be summed. If an item has both kinds, it appears in rankings with two rows ("... (full day)", "... (hourly)").
- **Utilisation** = booked slots, status `confirmed` only. `canceled` counts only in metric 9 (counted on the booking start
  day and on the cancellation day).
- **Usage day** = calendar day with at least one confirmed booking.
- Day boundaries use the site's time zone.
- **Restrictions** of type total breakdown count as downtime (metric 14), not as utilisation.
- **Impact** (metric 20, comes in phase 3; decision 2026-11-09): estimate from usage days. The administrator enters the
  factors in the dashboard settings:
  - **Distance per usage day** in km. Default: **6 km**.
  - **CO2 factor** in g per km of the replaced mode of transport. No default: if empty, the dashboard shows only the
    replaced kilometres (usage days × distance), no CO2 figure.
  - **Source note** as free text (where the CO2 factor comes from). It is printed with every output of the impact.
  Every output names the values used and calls the result an estimate.

## Data model (new tables only)

Implemented in `src/Service/Stats/` (as of 2026-11-09). All tables are derived and can be rebuilt at any time.

1. `wp_cb_stats_daily` (primary key `day, item_id, location_id, unit`; `unit` = `day` or `hour`): `capacity_slots`,
   `booked_slots`, `outage_slots`, `failure_slots`, `bookings_started`, `bookings_canceled`. Basis for metrics 1 to 6, 10, 14, 17 and 18.
   - Only days up to **today** are stored (history; the future keeps changing).
   - `unit` `day`: one slot per day (full day timeframe or booking). `hour`: everything else, counted in **hours**
     (fixed time slots without grid count with their length in hours).
   - Capacity = bookable plus booked slots after repair timeframes, holidays and active repair restrictions;
     `outage_slots` = the capacity a block removes: an active repair restriction, a
     holiday/closure timeframe or a repair timeframe (decision 2026-11-10; before, only restrictions counted and
     holidays/closures left availability at 100%). The day is compared with and without blocks (only bookable
     timeframes and bookings), so the unit of the underlying slots applies.
   - `failure_slots` (decision 2026-11-10) = capacity that only an active restriction of type **total failure**
     takes away (day compared with and without restrictions); holidays, closures and repair timeframes do not count here.
     **Failure rate** = `failure_slots` / (capacity + `outage_slots`), i.e. share of the planned capacity. It is a
     separate key figure next to availability (which includes all blocks) and appears in the tiles and the CSV.
     Schema version 2: after the plugin update the table is extended and the statistics are recomputed automatically.
   - `bookings_started` counts confirmed, `bookings_canceled` cancelled bookings on the booking's **start day**. The
     cancellation time itself is in the booking table (metric 9).
2. `wp_cb_stats_booking` (primary key `booking_id`): `item_id`, `location_id`, `user_id` (author), `unit`, `slots`
   (days for `day`, hours for `hour`), `status`, `created_ts`, `start_ts`, `end_ts`, `canceled_ts` (meta
   `cancellation_time`, else NULL). Timestamps are "local time as timestamp" like the timeframe index. Confirmed and
   cancelled bookings only. Basis for 7 to 9 and 11 to 13.
3. `wp_cb_stats_pairs` (primary key `item_id, location_id`): work queue with `dirty_from`, `dirty_to` (days to compute)
   and `computed_at`. `dirty_from` = `1970-01-01` means "from the pair's first day".
4. Options `commonsbooking_stats_enabled` (default off), `commonsbooking_stats_ready` (initial build done) and
   `commonsbooking_stats_schema_version`. `wp commonsbooking stats purge` removes tables and options completely.

The dashboard uses **no** booking posts at runtime. The aggregation is **independent of the booster**: the booking
table is built set-based in SQL from posts and meta, the daily values from the uncached day grid (`Model\Day`) as a user
with all roles of the pair (`AccessProfile::runAs`). If the timeframe index is complete it finds pairs and the first
day (including multi selection); without it only timeframes with exactly one item and one location are found. History is
limited to 1826 days (filter `commonsbooking_stats_max_history_days`).

## Updating

- **Events:** hooks (`transition_post_status` for bookings, `save_post_cb_timeframe`/`_cb_restriction`,
  `before_delete_post`) only collect post IDs. At the end of the request (`shutdown`) the booking rows are projected
  again (meta such as `cancellation_time` is written after the status change) and the affected pairs are marked dirty
  from the start day. At most 2 pairs are computed immediately, the rest by WP-Cron (single event +30 s).
- **Nightly run (00:20):** discover new pairs, recompute yesterday and today for all pairs (rollover; also catches
  cases the hooks miss, e.g. timeframes selecting items by category or list).
- **Initial build:** `wp commonsbooking stats enable` or `rebuild`; the queue runs in 20-second WP-Cron slices. Until
  `ready`, the dashboard shows a notice instead of partial values.
- **Self-test:** `wp commonsbooking stats verify [--pairs=n]` recomputes random pairs and compares the sums.
- **Commands:** `stats status | enable | process [--limit] | rebuild | verify | disable | purge`.

### Measurement (2026-11-09, `hostenv/`, 99,680 bookings, 719 pairs)

Booking table 5 s, daily values of all pairs 63 s (about 0.1 s per pair), about 70 s in total; 414,445 daily rows
(343,435 `day`, 71,010 `hour`). Spot check: booking slots of the booking table and `booked_slots` agree to within
0.05 % (bookings running past midnight of today).

### Initial build in steps (shared hosting)

Decision (2026-11-09): "Switch on statistics" computes nothing inside the request but only starts the build
(`Service\Stats\Build`). The build works in small steps with a time budget (at most 8 s and at most 40% of
`max_execution_time`) and a memory guard (70% of `memory_limit`):

- Phases: bookings (id ranges of 5,000) → find pairs → daily values per pair → done. The state is kept in the option
  `commonsbooking_stats_build`; every step continues there, also after a crash or timeout.
- Whoever comes first drives the build: the open statistics page (progress bar, calls the admin AJAX endpoint
  `commonsbooking_stats_step` repeatedly, administrators only, nonce), WP-Cron (single event, when the page is closed)
  or WP-CLI. Without JavaScript every reload does one step. A lock (option with timestamp, expires after 120 s) keeps
  runs from overlapping.
- Progress in percent: bookings 20%, pairs 5%, daily values 75% (by finished pairs).
- A pair that throws an error does not block the queue; it is noted (`commonsbooking_stats_failures`, last 20) and
  retried at night.
- Changes during the build are only marked; the build does the processing.

Measurement: 99,680 bookings, 719 pairs: 78 s in steps of 30 s (WP-CLI).

### Overall utilisation instead of strict separation (2026-11-10, implemented)

Reason: the usual question is "how heavily was the station or bike used overall". Strictly separating full-day and
hourly bookings does not answer it, and items with both kinds appear twice.

- Slots of different units cannot be added cleanly (a day has no fixed number of hours, opening hours belong to the
  location). Converting day → hours would be arbitrary.
- Unit-free and combinable: bookings (sum), cancellation rate (counts) and a **day rate**: days with at least one
  booking divided by days with offered capacity, merged over both units per item and location. It can be derived from
  `cb_stats_daily` without a schema change. Weakness: a single hour makes the day a "usage day", so hourly items look
  more heavily used.
- Proposal: default view "Overall" with bookings, day rate, cancellation rate, availability and failure rate
  (availability and failure by day: a day counts as available if capacity remains). Below it, slot utilisation
  separately in days and in hours with the number of bookings per kind. Rankings show one row per item (day rate) with
  columns for day and hour slots; the "Booking kind" filter keeps "full day only" and "hourly only".
Implementation: the filter "Booking kind" has `all` (default, the old value `both` is accepted), `day`, `hour`. With `all` the overview first shows "Overall" (day rate, bookings, usage days, cancellation rate, fully booked days, availability, failure rate counted by days, with the shares averaged over the kinds of each day), then the breakdown by kind. Rankings have one row per item/location with the day rate and the columns "Utilisation full-day/hourly". The CSV export stays separate per kind.

## User interface

Own page under the CommonsBooking menu, without extra libraries (charts: see "Charts").

**Filters** (apply to all views and to the export and live in the URL so views can be linked):

- Period: quick choices (30 days, 12 months, current year, previous year) or free; comparison with previous period or
  previous year.
- Location and item: single or multiple. Plus location category and item category.
- Resolution: day, week or month (default: month).
- Booking status (confirmed, cancelled) where the metric allows it.
- Booking kind: full day, hourly or both separately (default). The choice appears only if the instance has both kinds.

**Views:**

- **Overview:** metric tiles (utilisation, bookings, usage days, cancellations) and trend.
- **Items:** table sortable by utilisation, idle time, cancellations; detail page with heatmap and distributions.
- **Locations:** location comparison, map (phase 3).
- **Operations:** downtime, code stock, configuration warnings (phase 2).
- **Report:** printable view of the current filter (phase 2).

**CSV export** (phase 1): every view has an "Export as CSV" button. It exports exactly the currently filtered,
**aggregated** table at the chosen resolution, never individual bookings.

- Columns (example items and locations): period, location, item, unit (full day / hourly), capacity, booked slots,
  utilisation rate, bookings, cancellations, usage days. Column headers are translated.
- Format (decision 2026-11-09): UTF-8 with BOM, semicolon separator, **decimal comma** (`0,73`; the utilisation rate is a
  share, 0,73 = 73 %), ISO dates. This opens directly in German Excel. The format can be changed via a filter if an
  instance needs decimal points.
- Cells starting with `=`, `+`, `-` or `@` are neutralised (protection against formula injection via item names).
- Streamed delivery; with resolution "day" across many items and locations the row count can get large, hence a
  warning above a threshold and month as the default.
- Access as for the dashboard (administrators; CB managers only with their own scope, see "Permissions"; nonce).

## User interface implementation (phase 1)

As of 2026-11-09. Page "Statistics" under the CommonsBooking menu, administrators only (checked in page and export,
nonce on export). Classes: `Service\Stats\Filters` (filters from the URL, validated), `Service\Stats\Query` (reads the
aggregate tables only), `View\Admin\Stats\Page`, `Chart`, `Export`.

- **Switching on:** If statistics are off, the page shows a "Switch on statistics" button (builds tables and queue); until
  done a notice shows the number of open pairs.
- **Filters:** period (30 days, 12 months, this/last year, custom), comparison (previous period, previous year),
  resolution, booking kind, items, locations, item and location categories. A filter without matches (empty category)
  yields an empty selection, not "everything". Period at most 1830 days, never in the future.
- **Views:** overview (tiles per booking kind with comparison value, utilisation and bookings over time, heatmap weekday ×
  month, duration, lead time, cancellation timing), items and locations (ranking of the first 20 as bars, sortable
  table). Every chart has its values as a collapsible table beneath it.
- **Deliberately not yet:** booking status filter (confirmed/cancelled): bookings and cancellations appear side by side
  in their own tiles and columns, which covers the need without a switch. Item detail page follows with phase 2.
- **CSV:** "Export as CSV" button in every view, exports the aggregated table of the view with the current filters
  (overview: period × location × item; items; locations). UTF-8 with BOM, semicolon, decimal comma (filter
  `commonsbooking_stats_csv_decimal`), formula neutralisation, streamed.
- **Metrics:** utilisation = booked / capacity per booking kind; cancellation rate = cancelled / (confirmed + cancelled)
  on the start day; availability = capacity / (capacity + outage); idle days = days with capacity and no booking; fully
  booked days = days with booked ≥ capacity.

### Design (2026-11-09)

Cards with rounded corners and a soft shadow, key figures as tiles with a share bar, tabs as a segmented switch, charts
in cards (utilisation and bookings side by side), accent colours indigo (`--cb-stats-a`) and amber (`--cb-stats-b`).
Every important key figure has an (i): a click opens a short explanation (native `<details>`, works without JavaScript
and by keyboard).

Filters for items, locations and categories are dropdowns with a search field and checkboxes (shows "n selected", "Clear selection", Esc or a click outside closes); "Reset filters" reloads the page without filters. Without JavaScript the checkbox list stays usable.

## Charts

Decision 2026-11-09: **server-rendered inline SVG, no JavaScript library.** The plugin ships no chart library
(dependencies: Leaflet, Vue for the frontend package, Select2, Litepicker).

Reasons:

- **Fits the flow:** filters live in the URL and the page reloads via GET. Charts can then be drawn at render time from
  the aggregated tables; no second data interface for JavaScript is needed.
- **Print and report:** SVG prints sharply and stays usable in the printable report (phase 2) and a later mailed report.
  A canvas library would need extra handling for that.
- **No extra weight:** Chart.js and similar cost about 200 KB, plus licence review, updates and integration into the
  Grunt build. Only a few simple types are needed.
- **Accessible:** every chart has `<title>`/`<desc>` and directly below it the same values as a table (collapsible).
  Values are labelled, never encoded by colour alone.

Implementation: one small class `View\Admin\Stats\Chart` with fixed types. The types cover all metrics:

| Type | Used for (metric) |
|---|---|
| Columns / line over time, optional previous period as second series | utilisation, bookings, cancellations over time (1, 2, 9, 10) |
| Horizontal bars, ranking | items and locations by utilisation, idle time, cancellations (2, 3, 17, 18) |
| Heatmap (weekday × month) | seasonal pattern (4, 5) |
| Histogram | booking duration, lead time (7, 8) |
| Tile with sparkline | overview (1, 2, 9) |

Rules:

- Colours from CSS variables, defaulting to the active WordPress admin colour scheme; a colour-blind-safe pair for
  previous period and period. Dark admin schemes are handled via `currentColor` and variables.
- Rankings show the top 20 entries; the rest is in the table and the CSV.
- Hover hints via the SVG elements' `<title>`. Own JavaScript only if wanted later (progressive enhancement), never a
  requirement.
- Tests: the class is a pure function from data to SVG text and runs in PHPUnit against reference output.

## Permissions and privacy

- **Administrators** of the instance (`commonsbooking_isCurrentUserAdmin()`) see everything and may export. The check
  applies in every endpoint (page, queries, export, nonce), not just the menu item.
- **CB managers** (role `cb_manager`) get a **read-only** view (decision 2026-11-09, phase 2) if the operator switches it
  on in the dashboard settings (default off):
  - Only rows whose item or location is assigned to the user as admin are visible (meta `_cb_item_admins` and
    `_cb_location_admins`, see `Repository\BookablePost::getByCurrentUser` for items and locations). This matches the
    view of bookings, where item and location admins are combined.
  - Enforced in the query (restriction to the permitted item and location IDs), not just in the filter choice lists.
    Totals and tiles count visible rows only and are labelled "Your items and locations".
  - Export is allowed, with the same scope.
  - User metrics (group C) and configuration warnings stay reserved for administrators.
  - Cached results are separated by scope so no manager receives another's values.
- User metrics (group C) are aggregated; no names or individual histories.
- `wp_cb_stats_booking.user_id` is set to 0 when a user is deleted (anonymisation), hooked into the WordPress data
  erasure flow.
- Exports and reports contain aggregates only.

## Phases

1. **Aggregation, core, filters and CSV export:** tables, backfill, hooks, overview, items, location comparison
   (metrics 1 to 10, 17, 18), filters by period, location, item, booking kind and categories, export of the filtered
   table.
2. **Users, operations and manager view:** groups C and D, printable report, read-only access for CB managers.
3. **Impact and map:** metrics 19 and 20.

## Test data

State of the local build instance `hostenv/` (port 8891), measured on 2026-11-09:

- 700 items, 500 locations, 688 item-location pairs with bookings; 1,012 published timeframes, 370 restrictions;
  booster index populated (about 98,700 rows).
- **99,680 bookings:** 92,607 confirmed, 7,073 cancelled. Start from 11/2024 to 01/2027 (2024: 7,121,
  2025: 46,875, 2026: 45,334, 2027: 350), of which 5,305 are in the future.
- 173 bookers; 94,667 distinct creation times, so lead time (metric 8) is testable.
- Patterns: according to `hostenv/README.md` about 11 % of items are hourly (9 % and 2 %), plus 5 % with morning and
  afternoon. The share of hourly bookings has not been measured yet.

That is enough to measure backfill and query times at 100,000 bookings.

**Cancellation times:** The build instance contains `cancellation_time` for all 7,073 cancelled bookings (metric 9).
Added on 2026-11-09 with `hostenv/patch-cancellation-time.php`; new builds create them via `hostenv/seed-scale.php`
(mostly early, 15 % under 24 hours before start). This means the test data covers all phase 1 metrics.

## Open questions

None.
