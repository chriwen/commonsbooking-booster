# Stage 3: Precomputed Availability (Draft and Implementation Log)

*Deutsche Fassung: [availability-precompute.md](availability-precompute.md)*

As of 6 Nov 2026, implemented in branch `booster/index-availability`. Builds on the index from
[timeframe-index.en.md](timeframe-index.en.md).

> This note records the draft and the implementation steps (historical). The current state with all later
> additions (column `slots` for API and GBFS, fixes in the item table) is described in
> [technische-dokumentation-booster.en.md](technische-dokumentation-booster.en.md), sections 1.4.5, 2.4 and 6.

## 1. Goal and starting point

Since stage 2 the queries cost almost nothing. What remains is the computation of the days themselves
(`Model\Calendar` → `Week` → `Day::getGrid()` → `View\Calendar::mapDay()/processSlot()`), for every pair
of item and location on every call.

Measured on the tenfold development data (`dev-bench/pair-cost.php`, cache off):

| Period | Cost per pair |
|---|---|
| 31 days | 6.8 ms |
| 90 days | 10.5 ms |

This explains the remaining times: map with availability 1.06 s (SQL only 0.11 s), `[cb_items_table]` 0.56 s.
The time grows linearly with the number of pairs.

**Goal:** map and table read the finished day state from a table instead of computing it.
Expectation: map with availability about 0.15 s, table about 0.1 s, independent of the number of pairs.

**User requirement:** the display must be correct after every booking; a daily cron is not enough.

## 2. Core idea: correctness does not depend on when the recomputation happens

Every stored row carries a generation number. Every change that affects a pair increases the generation
of the pair **synchronously in the same request** (a single `UPDATE`, under 1 ms). A row with a stale generation
is never delivered. The reader then computes the pair live (as today) and writes the result back.

The recomputation after a booking is therefore only an optimisation, so that the next call is fast.
When it runs (right after saving, after the response has been delivered or only at the next read) does not change
correctness.

## 3. What is stored and what is not

The day state of a pair depends on two kinds of input:

| Kind | Examples | Handling |
|---|---|---|
| **Posts** (independent of today's date) | timeframes, bookings (`publish`/`confirmed`), holidays, repairs, active restrictions | **precomputed** |
| **Today's date, current time** | advance booking days (`getLatestPossibleBookingDateTimestamp`), start offset (`getFirstBookableDay`), "next bookable timeframe" (`getClosestBookableTimeFrameForToday`), past days locked | applied **when reading** |
| **Logged-in user** | role restriction on timeframes (`commonsbooking_isCurrentUserAllowedToBook`), admins see everything | pairs with a role restriction **always live** |

Verified: `Day::getGrid()`, `mapTimeFrames()`, `mapRestrictions()`, `getHigherPrioFrame()` and `isLocked()` depend only on
the posts, not on `time()`. All date-dependent parts are in `View\Calendar::getCalendarDataArray()` and
`mapDay()`. So the day itself can be stored; the window around it is determined anew on every read.

Without a role restriction the result is the same for all users, including admins: `commonsbooking_isCurrentUserAllowedToBook`
always returns `true` for an empty role list. The column `has_role_limit` in the index shows the affected pairs.
In practice these are few.

### Stored day facts

Result of `processSlot()` over all slots of a day, without the window rules from `mapDay()`.
One 16-bit mask per day:

| Bit | Field | Origin in `mapDay`/`processSlot` |
|---|---|---|
| 0 | `has_slots` | `! $noSlots` |
| 1 | `slot_locked` | a slot with `locked` (holiday, repair, booking) |
| 2 | `all_locked` | `$allLocked` |
| 3 | `holiday` | `holiday` |
| 4 | `repair` | `repair` |
| 5 | `booked_day` | `bookedDay` |
| 6 | `partially_booked` | `partiallyBookedDay` |
| 7 | `first_slot_booked` | `firstSlotBooked` |
| 8 | `last_slot_booked` | `lastSlotBooked` |
| 9 | `full_day` | exactly one slot, timeframe with `full-day` |

From this the day array of `mapDay()` (without `slots`) as well as the status for the map (`markDaysInTimeframe`) and
the table (`renderItemLocationRow`) can be fully derived. For the map and the table the number of slots is sufficient
(`count($day['slots'])` is only checked against 0).

Not stored: the slots with their timeframe objects. Only the booking calendar on the item page needs them
(Litepicker, time selection), which keeps computing live (one pair, fast with the index).

## 4. Data model

One row per pair; the days are packed into one column. This keeps the table small (1,000 pairs = 1,000 rows)
and makes reading and writing one statement each.

```sql
CREATE TABLE {prefix}cb_availability (
  item_id        bigint(20) unsigned NOT NULL,
  location_id    bigint(20) unsigned NOT NULL,
  gen            int unsigned NOT NULL DEFAULT 1,  -- increased on every change
  computed_gen   int unsigned NOT NULL DEFAULT 0,  -- generation from which `days` was computed
  days_from      date NULL,                        -- first day in `days`
  days           varbinary(1024) NULL,             -- 2 bytes per day, up to 512 days
  has_role_limit tinyint(1) NOT NULL DEFAULT 0,
  computed_at    datetime NULL,
  calc_version   smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (location_id, item_id),
  KEY item (item_id)
);
```

A row is valid if `computed_gen = gen` and `calc_version = AVAILABILITY_VERSION` hold and it covers the
requested day range.

Which pairs exist: all combinations of item and location that have a bookable timeframe
(from the index, type 2). Pairs without a bookable timeframe need no row; the map shows `no-timeframe` there.

## 5. Read path

New service `Service\Availability\Reader` with `getDays(array $pairs, string $from, string $to): array`.

1. One query loads the rows of all requested pairs.
2. The bookable timeframes of the pairs are loaded in one step (as already done today in
   `Map::getBookableTimeframesByPair`).
3. The window is determined per pair, with the same logic as today in `getCalendarDataArray()`:
   next bookable timeframe, `advanceBookingDays`, `lastBookableDate`, `firstBookableDay`, start date.
   This logic is moved into a method of its own for that purpose and shared with the live path.
4. Valid rows: unpack the day facts, apply the window rules from `mapDay()` (locked after `lastBookableDate`,
   before `firstBookableDay`, before today, after `endDate`).
5. Invalid, missing, too short rows and pairs with a role restriction: compute live as before. Except for pairs
   with a role restriction, the result is written back with the generation read before the computation
   (see 6.3).

Converted:

- `MapItemAvailable::create_items_availabilities()`: only computes the displayed days. The quick win
  (60 instead of 12 days) is thereby included.
- `View\Calendar::renderTable()` / `renderItemLocationRow()`.
- `getAvailabilitySlots()` for API and GBFS (column `slots`, `SlotReader`, see 13, addendum).

**Never** via the table: the validation when booking (overlaps, rules). It keeps reading the posts.
The table serves the display only.

## 6. Write path

### 6.1 Triggers

Every change the index sees becomes an invalidation. When projecting, the indexer knows the old row (before the
`REPLACE`) and the new one. The pairs from both rows are affected, so that moving a timeframe to
another item also covers the old pair.

| Change | affected pairs |
|---|---|
| booking created, confirmed, cancelled, deleted, moved | the pair of the booking (old and new) |
| bookable timeframe, holiday, repair, opening hours | single pair or, with multiple selection, all pairs from the assignment rows |
| restriction (repair, hint, active/lifted) | item × location, wildcard 0 = all pairs of the item or of the location |
| item or location deleted or unpublished | delete the rows of the item or location |
| new item with an "all items" holiday | via the meta change of the holiday (as tested in the index) |
| rebuild, verifier repair | all pairs or the repaired pair |
| new version of the computation logic | increase `AVAILABILITY_VERSION`, all rows invalid |

Changes that do not touch the precomputation period (e.g. cleaning up old bookings) trigger nothing:
the period of the old and the new row is compared with `[today − 1, today + horizon]`.

Implementation: `Projector::project()` returns the old and the new row. `Indexer::flush()` passes both on to
`Service\Availability\Invalidator`. It runs an `UPDATE … SET gen = gen + 1 WHERE …`. For wildcards and
multiple selection this is a `WHERE item_id IN (…)` or `location_id IN (…)`.

### 6.2 Recomputation

- **Few pairs** (default limit 25, corresponds to about 250 ms): recompute after the flush. Under PHP-FPM after
  `fastcgi_finish_request()`, otherwise at the end of the request. A booking thus costs about 10 ms, which the user does not notice.
- **Many pairs** (e.g. a nationwide holiday for all locations): only invalidate. A cron job recomputes in
  batches; readers compute missing pairs themselves (as fast as today, so no regression).

### 6.3 Concurrent changes

Procedure when recomputing: read generation `G`, compute, then

```sql
UPDATE … SET days = ?, days_from = ?, computed_gen = G, computed_at = NOW()
WHERE location_id = ? AND item_id = ? AND gen = G
```

If a parallel booking has increased `gen` in the meantime, the `UPDATE` matches no row. The row stays
invalid and is renewed by the parallel recomputation or at the next read. Without locks there is thus no
window in which stale data appears valid.

### 6.4 Writes that bypass the hooks

Direct SQL writes, imports or other plugins are seen neither by the index nor by the new table. Safeguards:

- `computed_at` older than 24 h counts as invalid. Stale data thus stays visible for at most one day, and only
  for writes that bypass the hooks.
- The daily verifier additionally checks a sample of pairs against a live computation and repairs.

## 7. Horizon and change of day

- Precomputed are `today − 1` to `today + horizon`. Default horizon 92 days, changeable via filter.
  This covers the map default (`availability_max_days_to_show`), the table (default 31 days) and the change of month.
- The change of day requires no recomputation: past days and the advance booking window are applied when reading.
- A nightly cron extends the rows by the new days at the end. If it fails, the readers compute missing days
  themselves. The cron is therefore not needed for correctness.
- Requests beyond the horizon (e.g. `[cb_items_table days=180]`) are computed live for the remainder.

## 8. One computation core for both paths

The biggest risk is that precomputed and live computed days diverge. Therefore:

1. `processSlot()`/`mapDay()` are split into two parts: `DayFacts::fromDay(Day)` (posts only) and
   `DayFacts::applyWindow(…)` (date and window).
2. The previous live path (`prepareJsonResponse`) uses the same two functions. So there is only one implementation.
3. Parity tests compare the result of map, table and calendar JSON (without slots) between both paths for all
   test cases, also with a frozen clock (`ClockMock`) across midnight and at the daylight saving time change.

## 9. Implementation in phases

| Phase | Content | Result |
|---|---|---|
| A | move out the computation core (`DayFacts`, window logic), `View\Calendar` uses it, no behaviour change | existing tests green, new tests for the core |
| B | table, writer, `Reader` with reading and writing back, feature flag `commonsbooking_use_availability_cache` (off at first) | map and table read from the table, parity tests |
| C | invalidation from the indexer (old and new row), generation check, recomputation after delivering the response | tests: booking, cancellation, holiday, restriction, concurrent changes |
| D | nightly cron for the horizon, batch cron, WP-CLI (`wp commonsbooking availability status / rebuild / verify`), verifier | operation secured |
| E | measurement (1× and 10× data, PHPBench), CI run with the flag switched off, docs in `TECHNICAL.md` | flag on by default |

The phases build on each other. After A nothing is visibly different, after B the gain is measurable, from C on it is
production-ready.

## 10. Not included / later

- ~~API and GBFS~~: implemented, column `slots` (see addendum in 13).
- **Pairs with a role restriction:** live at first. If needed, store one variant per role combination,
  implemented with access profiles: [role-restricted-availability.en.md](role-restricted-availability.en.md).
- **Booking calendar on the item page:** still live (needs clock times and is user-dependent).
- **Existing transient caches** (`Plugin::getCacheItem`): stay switched on, largely superfluous for map and table.
  ~~Bug in the cache key in `renderTable`~~: fixed (technical documentation 6.1).

## 11. Risks

| Risk | Countermeasure |
|---|---|
| deviations between precomputed and live computed day | one computation core (8), parity tests, verifier with sample |
| forgotten trigger | all triggers go through the index, which was already tested for completeness in phases 1–5, plus the 24 h limit |
| large invalidations (global holiday) | above the limit only invalidate, readers compute live (not slower than today), cron catches up |
| concurrent bookings | generation check when writing (6.3) |
| index not ready (rebuild running) | precomputation switched off, live path as before |

## 12. Decisions

1. Horizon: 92 days (confirmed by the user).
2. Pairs with a role restriction are computed live.
3. API and GBFS came later (column `slots`, calc version 2).

## 13. State of implementation (6 Nov 2026)

All phases A–E are implemented. Code in `src/Service/Availability/`, tests in `tests/php/View/CalendarSnapshotTest.php`
and `tests/php/Service/Availability/AvailabilityTest.php`.

### Phase A: computation core

- `DayFacts` (day facts from the slot grid, 10 bits), `CalendarWindow` (window from `getCalendarDataArray()`,
  default values from `prepareJsonResponse()`, window rules from `mapDay()`), `DayStatus` (shared status logic of
  map and table, previously implemented twice).
- `View\Calendar::processSlot()`, `getLockedDayArray()` and `getDefaultCalendarEnddateTimestamp()` are removed,
  `mapDay()` and `getCalendarDataArray()` use the core. `prepareJsonResponse()` keeps its signature.
- Peculiarities deliberately taken over and documented: `repair` is reset by every slot (strict comparison of
  string against integer), 0 advance booking days count as "not set", `<span'is-locked'>` in the table.
- Snapshot test: calendar JSON (three periods), map status and table rows for 11 scenarios and 4 users
  (guest, subscriber, editor, admin) were recorded **before** the refactoring (`tests/php/View/snapshots/calendar-core.json`,
  1.3 MB). Identical with and without the index, unchanged after the refactoring.
- A second test shows for over 1,000 days that the packed bits yield the same day array (without slots) and the same
  status as evaluating the grid. All seven statuses occur in the scenarios.

### Phase B: table and reading

- `Schema` (`{prefix}cb_availability`, own version option; on a structural change the table is recreated,
  it is only a cache), `Store` (access, generation check), `Calculator` (computation via `Model\Calendar`
  without the plugin cache), `Reader` (window when reading, days from the table, otherwise compute and store, otherwise live).
- The days are written as hex via `UNHEX()`: `wpdb` may strip bytes from queries that it considers invalid
  text.
- New in the cache trait: `Plugin::withoutCache()`. The computation must not read cached weeks from the time
  before a change.
- Map (`MapItemAvailable`) and table (`renderItemLocationRow`) read via the `Reader`. The map only computes
  the displayed days (quick win); the live path also evaluates only these days.
- Deviation from the draft: the generation applies to the whole pair, not to a day range. Recomputing a pair
  costs about 13 ms; this is simpler and just as fast.

### Phase C: invalidation

- `Projector` fires `commonsbooking_index_rows_changed` with the old and new row when the projection changes
  (excluding bookkeeping columns). The rebuild only fires `commonsbooking_index_rebuilt`, which invalidates all pairs.
- `Invalidator` determines the pairs: single row, multiple selection via the assignment rows, wildcard 0 for
  restrictions. Only effective statuses (timeframes `publish`, bookings `confirmed`, restrictions
  `Day::RESTRICTION_STATUSES`), only if the period touches the horizon (with 2 days of buffer).
  Restrictions without a start do not count (they never take effect; this avoids invalidating all pairs on creation
  while the meta data is still missing).
- The `Reader` calls `Indexer::flush()` before reading: changes of the same request are visible immediately.
- Up to 25 pairs are recomputed at the end of the request (`shutdown`, with `fastcgi_finish_request()` after the response).

### Phase D: operation

- `Maintenance`: hourly create new pairs, remove pairs without a bookable timeframe (once a day), recompute changed
  rows and rows older than 12 hours (20 s budget, then rescheduled). Daily compare a sample of 50
  pairs with a fresh computation and repair.
- WP-CLI: `wp commonsbooking availability status|rebuild|verify [--sample=<n>] [--repair]|drop`.
- Activation creates the table, deactivation removes the cron jobs.

### Phase E: completion

- Tests: 20 new tests for the write path (booking, confirming, cancelling, deleting, rebooking to another item,
  holiday for several pairs, wildcards, outside the horizon, concurrent change, role restriction,
  age, computation version, range beyond the horizon, large changes via cron, index rebuild,
  pair reconciliation, check finds changes written bypassing the hooks). Suite green with precomputation, without
  precomputation (`CB_USE_AVAILABILITY=0`) and without index (`CB_USE_INDEX=0`): 512 tests each.
- CI: additional run with `CB_USE_AVAILABILITY=0`.
- Docs: section "Precomputed availability" in `TECHNICAL.md`.
- Feature flag `commonsbooking_use_availability_cache` is on by default.

### Measurements (development data with tenfold history, 100 pairs, plugin cache off)

| View | original (1× data) | index (stage 2) | precomputed (stage 3) |
|---|---|---|---|
| map with availability | 15.4 s | 1.14 s | **0.11 s** |
| `[cb_items_table]` | 7.05 s | 0.74 s | **0.08 s** |

- First call without stored rows: map 1.36 s, table 1.29 s (all pairs are computed and stored).
- Complete build for 100 pairs: 1.3 s (`wp commonsbooking availability rebuild`).
- One booking: index and marking within the request, recomputation of the pair 13 ms after the response.
  Verified end to end (`dev-bench/booking-cost.php`): day free before, booked immediately after the booking,
  free again immediately after deletion.

PHPBench (fixture of the project, same session, time per call):

| Benchmark | index, computed live | index + precomputed |
|---|---|---|
| map with availability | 1.153 s | 0.151 s (the first iteration computes the rows, hence ±99 %) |
| `[cb_items_table]` | 1.131 s | 0.085 s |
| map without availability, `[cb_items]`, `[cb_locations]` | unchanged (65 ms, 112 ms, 105 ms) | unchanged |

Test environment (`testenv/`, 15 pairs, browser, time to response): map query 39 ms precomputed, 153 ms live,
264 ms without index; table 55 / 130 / 270 ms. Output byte-identical in all three modes.

### Local test environment

`testenv/` (excluded from git): own wp-env instance on port 8890 with sample data (6 stations in Cologne,
15 items, about 560 bookings, holidays, repair, role restriction), pages for all shortcodes and an
MU plugin to switch between precomputed, live and without index (`?cb-mode=`). Instructions in `testenv/README.md`.

### Addendum: API and GBFS (column `slots`)

API and GBFS read the stored slots (`Schema::SCHEMA_VERSION = 2`, column `slots`, `Calculator::VERSION = 2`,
`SlotReader`, test `SlotsTest`). Measured on the development data (126 items): `/availability` 1,067 ms → 605 ms,
GBFS `station_status` 194 ms → 138 ms. Also fixed: the cache key in `renderTable` and the markup
`<span'is-locked'>`. Details, deviations from the live path and remaining open points:
[`technische-dokumentation-booster.en.md`](technische-dokumentation-booster.en.md), sections 2.4, 6.1, 6.6 and 12.3.


## Addendum 6 Nov 2026: Performance booster

Index and precomputed availability are now one shared switch, off by default. The
booking validation always uses the original queries; a guard detects changes made by a version without the booster.
See [`booster.en.md`](booster.en.md).
