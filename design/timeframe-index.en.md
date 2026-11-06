# Design: Index Table for Timeframes, Bookings and Restrictions

*Deutsche Fassung: [timeframe-index.md](timeframe-index.md)*

Status: implemented (phases 1–5) in branch `booster/index-availability` · draft of 5 Nov 2026, log up to
6 Nov 2026 · base: CommonsBooking 2.11.3 (`7aa75a81`)

> This note records the draft, the decisions and the measurement series of the individual phases (historical). The
> current state of the index, including the later changes to `Booking::getForUser()` and `Item::getClosestBooking()`,
> is described in [technische-dokumentation-booster.en.md](technische-dokumentation-booster.en.md), sections 2 to 5.

## 1. Goal and scope

All queries that today search `wp_postmeta` by item, location, type and period should run via a
narrow, indexed table. The query time should then no longer depend on the size of the booking history
and of `wp_postmeta`.

- **The posts remain the authoritative source.** `cb_timeframe`, `cb_booking` and `cb_restriction` remain
  unchanged custom post types with their meta data. Admin interface, CMB2, post status, template tags, REST/GBFS,
  privacy export, booking codes and third-party hooks keep working unchanged.
- The index table is a **derived projection**. It can be rebuilt completely from the posts at any time.
- It is the basis for the precomputed availability (stage 3), because its recomputation in the booking request has to be fast.
- **Not** part of this design: the table of precomputed availability, the rework of the cache and the removal of the post types.

## 2. Starting point (measured)

Test data: 100 items/locations, 17,100 timeframes and bookings, 190,000 `postmeta` rows.

| | today (`getPostIdsByType`) | index prototype |
|---|---|---|
| item/location pair in a time window | 26 ms (full scan `postmeta`) | **0.12 ms** |
| the same with 50 times the history (867,000 index rows) | grows linearly | **0.18 ms** |
| all pairs of the map in **one** query | 400 × 25 ms ≈ 10 s | **1.7 ms** |
| conflict check (`getExistingBookings`) | `WP_Query` with 5 meta joins | **0.07 ms** |
| bookings of a user (booking rules) | `WP_Query` with meta joins | **1.1 ms** |

`EXPLAIN` shows: the pair query uses the index `item_loc_range`. The run time is independent of the amount of
historical bookings, because `end_ts` is part of the index. Script: `dev-bench/schema-test.php`.

## 3. Schema

```sql
CREATE TABLE {prefix}cb_index (
  post_id             BIGINT UNSIGNED  NOT NULL,
  post_type           VARCHAR(20)      NOT NULL,          -- cb_timeframe | cb_booking | cb_restriction
  post_status         VARCHAR(20)      NOT NULL,          -- publish, draft, confirmed, unconfirmed, canceled, trash …
  post_author         BIGINT UNSIGNED  NOT NULL DEFAULT 0, -- for bookings: the user the booking was made FOR
  type                TINYINT UNSIGNED NOT NULL DEFAULT 0, -- Timeframe::*_ID (1–7), 0 for Restriction
  item_id             BIGINT UNSIGNED  NULL,              -- NULL for multiple selection; 0 = wildcard (Restriction only)
  location_id         BIGINT UNSIGNED  NULL,              -- ditto
  is_multi            TINYINT(1)       NOT NULL DEFAULT 0, -- 1 ⇒ assignment in cb_index_rel
  start_ts            BIGINT           NOT NULL DEFAULT 0, -- repetition-start or restriction-start
  end_ts              BIGINT           NULL,              -- repetition-end or restriction-end; NULL = open
  -- fields for the availability computation, so that no postmeta has to be loaded:
  repetition          VARCHAR(8)       NULL,              -- norep | w | m | y | manual
  weekdays            TINYINT UNSIGNED NOT NULL DEFAULT 0, -- bit mask Mon=1 … Sun=64
  manual_dates        TEXT             NULL,              -- JSON array Y-m-d, only for repetition=manual
  grid                TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = slot, 1 = hourly
  grid_size           TINYINT UNSIGNED NULL,
  full_day            TINYINT(1)       NOT NULL DEFAULT 0,
  start_time          TIME             NULL,
  end_time            TIME             NULL,
  max_days            SMALLINT UNSIGNED NULL,
  advance_days        SMALLINT UNSIGNED NULL,
  startday_offset     SMALLINT UNSIGNED NULL,
  has_role_limit      TINYINT(1)       NOT NULL DEFAULT 0, -- allowed_user_roles not empty
  restriction_type    VARCHAR(10)      NULL,              -- repair | hint
  restriction_state   VARCHAR(10)      NULL,              -- none | active | solved
  -- management:
  source_modified_gmt DATETIME         NOT NULL,          -- post_modified_gmt at the time of projection
  projector_version   SMALLINT UNSIGNED NOT NULL,         -- increased when the projection logic changes ⇒ targeted reindex
  PRIMARY KEY (post_id),
  KEY item_loc_range (item_id, location_id, type, end_ts, start_ts),
  KEY loc_range      (location_id, type, end_ts),
  KEY type_range     (type, end_ts, start_ts),
  KEY author_type    (post_author, type, start_ts)
);

CREATE TABLE {prefix}cb_index_rel (
  post_id     BIGINT UNSIGNED        NOT NULL,
  entity_type ENUM('item','location') NOT NULL,
  entity_id   BIGINT UNSIGNED        NOT NULL,
  PRIMARY KEY (entity_type, entity_id, post_id),
  KEY post (post_id)
);
```

### Decisions

**One row per post, multiple selection in a separate relation table.**
According to `removeIrrelevantPostmeta()`, multiple, category and "all" selection only exist for holidays (3) and repair (5).
`manageTimeframeMeta()` already writes the selection out as `item-id-list`/`location-id-list`.
A cross product item × location in the main table would amount to 150,000 rows per holiday for "all items × all
locations" (e.g. 500 × 300). The relation table only needs 800 rows for that. Bookings and bookable timeframes
(> 99 % of the rows) have exactly one item and one location and need no join.

**Restrictions in the same table.** `restriction-item-id` or `restriction-location-id` = 0 today means
"all" (`Restriction::filterPosts`). The index then holds `0`, the query reads `item_id IN (0, :item)`. This stays
indexable and maps the four cases from `filterPosts` exactly.

**Take over timestamps unchanged.** CommonsBooking stores "local time as timestamp"
(see the docblock of `Model\Timeframe::getStartDate()`). The index takes over the values without time zone conversion,
so that all existing comparisons stay identical. The projection normalises non-numeric legacy values like
`getStartDate()` via `strtotime()`. `end_ts = NULL` means "no end". This replaces today's
`NOT IN (SELECT … repetition-end)` subqueries.

**Covering fields for availability** (`repetition`, `weekdays`, `grid`, `full_day`, `start_time` …).
`Day::isInTimeframe()`, `processSlot()` and `sanitizeSlots()` today read these values per day and time window via
`get_post_meta`. If they are in the index, availability (stage 3) can be computed from index rows alone.
They are optional for phase 1 but built right away, so that no second backfill is needed.

**No foreign key, no triggers.** WordPress tables use no FKs, and triggers require special privileges on many hosts.
Maintenance happens in PHP via hooks (section 4).

**Multisite:** one table per site via `$wpdb->prefix`. Created via `dbDelta()`, deleted in `uninstall.php`.

## 4. Maintaining the table (projection)

### 4.1 Components

- `CommonsBooking\Service\Index\Projector`: `project(int $postId): void` reads the post via the existing models
  (`Model\Timeframe`, `Model\Booking`, `Model\Restriction`) and writes the row via `REPLACE INTO` as well as the
  relations (delete + insert). Because the models are used, the same semantics apply as today, e.g.
  `getItemIDs()` with list or single value, `getStartDate()` with `strtotime` fallback.
  `remove(int $postId)` deletes the row and the relations.
- `CommonsBooking\Service\Index\Indexer`: registers the hooks, collects affected post IDs per request
  (`$dirty`, deduplicated) and calls `flush()` at the end. Returns the **old** values (item, location, period)
  before the row is replaced. The availability invalidation (stage 3) needs them later in order to also recompute
  the period that was left.

### 4.2 Hooks (all write paths from the code)

| Trigger | Hook / place | Action |
|---|---|---|
| post saved | `save_post` with priority `PHP_INT_MAX` (after `Timeframe::savePost` with priority 11, which rewrites meta data and sets invalid timeframes to `draft`) | project immediately |
| meta data changed (CMB2, importer, `update_post_meta` from code) | `added_post_meta`, `updated_post_meta`, `deleted_post_meta` for the relevant keys and post types | mark as dirty |
| status change | `transition_post_status` | mark as dirty |
| trash / delete | `trashed_post` (project status), `deleted_post` | `remove()` |
| **cancellation** `Model\Booking::cancel()` | sets `post_status` via direct SQL, **without post hooks** | add **explicit** `Indexer::touch($id)` in `cancel()`. Affects admin status changes, `Restriction::cancelBookings()` and the privacy erasure function. |
| clean up unconfirmed bookings | `Service\Booking::cleanupBookings()` → `wp_delete_post` | via `deleted_post` |
| category or "all" selection | `Timeframe::updateAllTimeframes()` → `manageTimeframeMeta()` → `update_post_meta(*-id-list)` | via the meta hooks |
| item/location deleted or unpublished | the status of the timeframes does not change | keep today's semantics: rows remain, filtering happens when reading (see 6.3) |
| migrations, upgrade, `Migration.php:433` (direct SQL) | — | reindex of the affected types afterwards |

Relevant meta keys: `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`, `repetition-start`,
`repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`, `start-time`, `end-time`,
`timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`, `allowed_user_roles`,
`restriction-*`. All other keys are ignored (e.g. `_edit_lock`, booking codes, comments).

### 4.3 When is it written?

`flush()` runs:
1. at the end of `save_post` (priority `PHP_INT_MAX`),
2. explicitly at the end of `Booking::handleBookingRequest()` and `Model\Booking::cancel()`, i.e. **before** the redirect,
3. as a safety net on `shutdown`.

The index is therefore current **in the same request** in which the post changes. That is the prerequisite for the
immediate recomputation of availability (stage 3).

### 4.4 Failure and consistency

- If the projection fails, e.g. on a fatal error between saving and `flush()`, the post ID is noted in the option
  `cb_index_pending` (or in the dirty table from stage 3). A cron job processes it (e.g. every 5 minutes).
- `wp commonsbooking index verify [--sample=500]` compares index and projection and repairs deviations.
  Additionally runs daily via cron with a sample.
- `projector_version`: if the projection logic changes, an upgrade task re-projects only rows with an older version.

## 5. Initial fill and rollout

1. **Create the table:** in `Plugin::activation()` and as an entry in `Upgrade::$upgradeTasks`, version option
   `commonsbooking_index_db_version`.
2. **Backfill in chunks** via the existing AJAX upgrade infrastructure (`Upgrade::$ajaxUpgradeTasks`,
   `runAJAXUpgradeTasks`), 500 posts each, resumable. In parallel `wp commonsbooking index rebuild [--type=…]`
   for large instances. Fast path: a bulk `INSERT … SELECT` over `postmeta` (as in the prototype) fills the
   basic columns in seconds. The PHP projection then corrects special cases (multiple selection, legacy values, `manual_dates`).
3. **Switch:** the repositories only use the index if `get_option('commonsbooking_index_ready') === projector_version`.
   Until then, and when the filter `commonsbooking_use_index` returns `false`, the previous path via `postmeta` runs.
   This allows a gradual release and an immediate way back.
4. The hooks write **from activation on**, also during the backfill. Whatever the backfill overwrites afterwards is
   idempotent (`REPLACE`).

## 6. Converting the repositories

### 6.1 New query layer `CommonsBooking\Repository\Index`

```php
Index::find( array $criteria ): int[]   // returns post_ids
// criteria: items[], locations[], types[], post_types[], status[], from_ts, to_ts, date (Y-m-d),
//           authors[], restriction_state, include_multi=true, include_wildcards (Restriction only)
Index::rows( array $criteria ): array   // returns the index rows themselves (for availability, grouping)
```

Afterwards always load in bulk: `_prime_post_caches( $ids )` plus `update_meta_cache( 'post', $ids )`, then the models.
This replaces today's individual `get_post()` calls (17,200 queries in the map scenario).

Query pattern (pair with multiple selection, deliberately `UNION ALL` instead of `OR`, so that both parts use an index):

```sql
SELECT post_id FROM cb_index
 WHERE item_id IN (:items) AND location_id IN (:locs) AND type IN (:types)
   AND post_status IN (:status) AND (end_ts IS NULL OR end_ts >= :from) AND start_ts <= :to
UNION ALL
SELECT x.post_id FROM cb_index_rel ri
  JOIN cb_index_rel rl ON rl.post_id = ri.post_id AND rl.entity_type='location' AND rl.entity_id IN (:locs)
  JOIN cb_index x      ON x.post_id  = ri.post_id
 WHERE ri.entity_type='item' AND ri.entity_id IN (:items)
   AND x.type IN (:types) AND x.post_status IN (:status)
   AND (x.end_ts IS NULL OR x.end_ts >= :from) AND x.start_ts <= :to
```

With an empty item or location list the respective condition or join is dropped, as today.

### 6.2 Mapping of the existing methods

| Existing method | New |
|---|---|
| `Repository\Timeframe::getPostIdsByType()` + `getPostsByBaseParams()` (basis of `get`, `getInRange`, `getBookable*`) | `Index::find()` with date or range filter directly in SQL. **Historical bookings are no longer loaded.** |
| `Timeframe::filterTimeframesByConfiguredDays()` | weekday/manual from the index row, without `get_post_meta` |
| `Timeframe::getByLocationItemTimestamp()` | `Index::find()` + comparison of `start_time`/`end_time` from the row |
| `Repository\Booking::getByDate/getByTimerange/getExistingBookings` | `Index::find(post_types=[cb_booking], type=6, …)`. **Conflict check**: reads the source (the index is current within the same request, see 4.3) |
| `Booking::getForUser/getForCurrentUser`, booking rules (`BookingRule::check*`) | `Index::find(authors=[…])` via `author_type`. Implemented as a union of own and managed bookings (technical documentation 5.4) |
| `Item::getClosestBooking()` (GBFS `vehicle_id`) | added later: `Booking::getLatestPastConfirmed()`, two index queries instead of all bookings of the pair (technical documentation 5.5) |
| `Booking::getBeginningBookingsByDate/getEndingBookingsByDate` (reminder emails) | `type_range` |
| `Repository\Restriction::get/queryPosts` | `Index::find(post_types=[cb_restriction], restriction_state='active', include_wildcards)`. **Once per period instead of per day.** |
| `BookablePost::getRelatedPosts()` (item ↔ location, map/lists) | `SELECT DISTINCT location_id/item_id FROM cb_index WHERE … post_type='cb_timeframe' AND is_multi=0`. Same semantics as today: only single assignment, all types and statuses from the existing query. |

Admin lists (`filterAdminList`, `pre_get_posts`) and the REST API stay unchanged in phase 1. They can later be
converted to the index via `posts_clauses`.

### 6.3 Behaviour deliberately kept

- Deleted or unpublished items/locations: today timeframes with stale IDs remain and are filtered
  via the `post_status` of the item (`View::getShortcodeData`, `getRelatedPosts`). The index takes this over
  unchanged. Deviations from this belong in a separate ticket.
- Unconfirmed bookings still do not block the calendar (`getInRange` with `confirmed, publish`), but the
  conflict check does (`confirmed, unconfirmed`). Both filters go unchanged into the criteria as `status`.

## 7. Tests

- **Parity test:** for random criteria (item/location, types, date, period, status) the index path and the
  `postmeta` path must return the same IDs. Runs in PHPUnit against the fixtures from `CPTCreationTrait`, including
  holidays with category and "all" selection, restrictions with wildcards, manual repetition and open end.
- **Projection tests per hook:** save, change meta data, status change, `cancel()`, `wp_trash_post`, `wp_delete_post`,
  `cleanupBookings`, category reassignment.
- **Run the entire existing test suite twice**, with `commonsbooking_use_index` on and off (data provider or
  CI matrix).
- **Benchmarks:** `tests/benchmark/Map/MapDataBench.php`, `CalendarBench`, `ShortcodeBench`, `BookingBench` before and
  after. Additionally a scenario with ten times the history, to prove independence from the history.

## 8. Phases and effort (estimate)

| Phase | Content | Effort |
|---|---|---|
| 1 | schema, projector, indexer with hooks incl. `cancel()`, backfill, CLI `rebuild`/`verify`, switch | 4–5 days |
| 2 | `Repository\Index` + conversion of `Timeframe::get/getInRange/getBookable*`, bulk loading | 2–3 days |
| 3 | conversion of the `Booking` repository (conflict check, user bookings, reminders) and `Restriction` | 2 days |
| 4 | `getRelatedPosts`, map (`Map::get_locations` with one query for all pairs) | 1–2 days |
| 5 | parity and hook tests, CI matrix, benchmarks | 2–3 days |

Then stage 3 (precomputed availability): it uses `Index::rows()` and the old values from `Indexer` for the
targeted recomputation.

## 8a. State of phase 1 (implemented on 6 Nov 2026)

Code: `src/Service/Index/` (`Schema`, `Projector`, `Indexer`, `Backfill`, `Verifier`, `Cli`), tests:
`tests/php/Service/Index/IndexerTest.php` (14 tests). Changed: `Plugin::init/activation/deactivation`,
`Model\Booking::cancel()`, `phpstan.neon` (ignore WP_CLI). The suite is green (475 tests), PHPStan reports no
errors, `phpcbf` leaves the code unchanged.

Deviations from the draft above:

| Draft | Implementation | Reason |
|---|---|---|
| backfill via `Upgrade::$ajaxUpgradeTasks` | own schema version (`commonsbooking_index_schema_version`), installation on `init`. Backfill via a self-rescheduling WP cron (20 s per run) or `wp commonsbooking index rebuild` | The upgrade tasks depend on the plugin version (the fork stays 2.11.3), and AJAX tasks are ignored on new installations. |
| bulk `INSERT … SELECT` as fast path | not needed: PHP projection with `_prime_post_caches` manages about 5,000 posts/s (17,100 in 2.5–3.4 s) | less code, a single projection logic |
| option `cb_index_pending` for aborted projections | `flush()` at the end of `save_post` and on `shutdown` (also runs after fatal errors). Plus a daily `Verifier` cron (sample 200, repairs) and `wp commonsbooking index verify --repair` | no additional write per meta change |
| item/location assignment via the model getters | **union** of `item-id` (all values) and `item-id-list` (likewise for locations) | corresponds exactly to the previous SQL in `getEntityQuery()` (single **or** list). The getters just prefer the list. |
| — | `cancel()` additionally calls `clean_post_cache()` | Without this call `get_post()` still returns the old status in the same request. This already affected all subsequent reads (the tests work around it with `wp_cache_flush()`). |
| — | `repetition-end` = `''`/`0` becomes `end_ts = NULL` (open end) | Corresponds to the model semantics (`getTimeframeEndDate()`). The previous SQL would have excluded a post with an existing but empty key. **Check in the parity test in phase 2.** |

Note for development: new classes under `src/` require `composer prefix-namespaces`, because the plugin only loads the
Strauss autoloader in `vendor-prefixed/`.

## 8b. State of phase 2 (implemented on 6 Nov 2026)

- `src/Repository/Index.php`: `findPostIds()` (UNION ALL of single and multiple assignment, sorted by `post_id`,
  calls `Indexer::flush()` first, so that changes of the own request are visible) and `loadPosts()`
  (bulk loading of posts and meta data).
- `Repository\Timeframe::get()` and `getInRange()` (and thus also `getBookable*`, `getInRangeForCurrentUser`) use
  the index via `queryPosts()` when `Index::isAvailable()`. Otherwise the previous path runs. The PHP filters
  (weekdays, `isBookable`, roles) and the cache are unchanged.
- Schema v2: `start_ts` may be NULL (the previous SQL requires the meta key `repetition-start` for the date and
  range filter). Plus the index `multi_type`. An explicit `ALTER` is necessary because `dbDelta`
  does not change the NULL property.
- Tests: `tests/php/bootstrap.php` builds the index before the suite. `CB_USE_INDEX=0` switches the index off,
  `dev-bench/phpunit/run.sh` passes the variable through. Parity test with over 1,400 comparisons old vs. new
  (`TimeframeIndexParityTest`). The suite is green in both modes (479 tests).
- Deliberate deviation: an invalid ID (`'abc'` → `IN (0)`) matched timeframes with an empty `item-id` in the old SQL
  (MySQL converts `''` to 0). In the index it matches nothing.

Measurement on the test data (cache off, 100 items, 17,100 timeframes and bookings):

| View | before | after | factor |
|---|---|---|---|
| map, locations only | 5.37 s / 1,005 queries | 0.21 s / 805 | 25× |
| map with availability | 15.41 s / 40,800 | 2.39 s / 6,600 | 6.4× |
| `[cb_items_table]` 31 days | 7.05 s / 3,604 | 1.25 s / 3,403 | 5.6× |
| `[cb_items]` | 2.78 s / 201 | 0.10 s / 101 | 28× |

Remaining costs: `Restriction::get()` per day (6,000 and 3,200 queries, about 1 s) → phase 3. The map still computes
60 instead of 12 days → stage 1 (quick win).

## 8c. State of phase 3 (implemented on 6 Nov 2026)

- `Repository\Booking`: `getByDate`, `getByTimerange` (and thus `getExistingBookings`, `getByRestriction`,
  booking rules, adjacent bookings), `getBeginningBookingsByDate` and `getEndingBookingsByDate` (dashboard,
  reminder emails) read via `findViaIndex()`. Supported `$customArgs`: `author`, `post_status`,
  `orderby=date`, `order`. For other arguments the previous `WP_Query` runs automatically. The sort order
  corresponds to `WP_Query` (`post_date`, default DESC) and is secured by a test.
- `Repository\Restriction::get()` reads via the index (`restriction_state = active`). The PHP filter for
  item/location (incl. "all") is unchanged.
- **Restrictions per period instead of per day:** `Model\Calendar` loads the active restrictions of the range once and
  passes them via `Week` to `Day`. `Day::getRestrictions()` filters them with the same date condition as the
  per-day query. Without a preloaded list (direct use of `Day`) the query remains.
- Not converted: `getForUserPaginated` (pagination with `author`, uncritical) and `getForUser` (loads all
  bookings and filters by visibility in PHP → separate point, see below).
- Tests: parity tests for bookings, restrictions and the preloaded day restrictions (together over 3,800
  assertions, with a check for non-empty results). In `CalendarTest::testGetDays` the expected
  `Week` objects were extended by the new field. The suite is green in both modes (482 tests).

Measurement on the test data (cache off):

| View | baseline | phase 2 | phase 3 |
|---|---|---|---|
| map with availability | 15.41 s / 40,800 queries | 2.39 s / 6,600 | **1.17 s / 700** |
| `[cb_items_table]` 31 days | 7.05 s / 3,604 | 1.25 s / 3,403 | **0.72 s / 303** |
| `[cb_items]` | 2.78 s / 201 | 0.10 s / 101 | **0.09 s / 101** |
| map, locations only | 5.37 s / 1,005 | 0.21 s / 805 | **0.20 s / 805** |

The remainder is now mostly PHP computation time (map: 0.26 s SQL of 1.17 s). Biggest lever: the map computes
60 instead of the displayed 12 days (quick win). Then follows stage 3 (precomputed availability).

Open point (at the time): `Booking::getForUser()` (page "My bookings", `BookingRuleApplied`) loaded **all**
bookings of the instance and filtered them in PHP by `commonsbooking_isUserAllowedToSee()`. **Implemented since:**
union of own bookings and bookings of managed items/locations; for a normal user on dev
1,203 instead of 167,000 loaded bookings; `BookingRuleApplied` only queries the user's own unconfirmed bookings.
For CB admins all bookings are still loaded (technical documentation 5.4 and 12.3).

## 8d. State of phase 4 (implemented on 6 Nov 2026)

- `BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) fetches the timeframes via
  `getTimeframesByRelation()` from the index. It then filters on the **single** `*-id` value (semantics of the
  previous meta query) and sorts like `WP_Query` (`post_date DESC`). The associated items or locations
  are loaded in bulk.
- `Index::sortByDate()`: for equal `post_date` the ID ascending applies. That is how MySQL returns ties for
  `ORDER BY post_date` (observed in the parity test).
- `Model\Map::get_locations()` first loads items per location and then **all bookable timeframes in one query**
  (`Map::getBookableTimeframesByPair()`, grouped by location and item). Term cache and
  preview images are loaded in bulk as well. Equivalence with the per-pair query is secured by a test, as is
  the equality of the complete map data with and without the index.
- Suite green in both modes (485 tests), PHPStan without errors.

| View | baseline | after phase 4 |
|---|---|---|
| map, locations only | 5.37 s / 1,005 queries | **0.09 s / 707** |
| map with availability | 15.41 s / 40,800 | **1.17 s / 701** |
| `[cb_items_table]` 31 days | 7.05 s / 3,604 | **0.67 s / 302** |
| `[cb_items]` | 2.78 s / 201 | **0.09 s / 101** |

The queries in `get_locations` are now cheap individual queries per location (index, meta data, categories,
together 0.05 s). The map with availability is dominated by the PHP computation of the days (0.27 s SQL of
1.17 s). Next lever: compute only the displayed period (quick win), then the precomputed availability
(stage 3).

## 8e. State of phase 5 (implemented on 6 Nov 2026)

- Further hook tests: cancellation by `Restriction::apply()`, cleanup cron for unconfirmed bookings, new
  item with an "all items" holiday, trash/restore. Plus a test that records the deviation for an empty
  `repetition-end` (open end instead of excluded).
- CI (`.github/workflows/phpunit.yml`): additional matrix run with `CB_USE_INDEX=0` (PHP 8.1, WP latest).
- Benchmarks: `tests/benchmark/bootstrap.php` builds the index, `CB_USE_INDEX=0` measures the old path.
- **Schema v3:** indexes `ptype_item`, `ptype_location`, `ptype_end`. Without them the queries for restrictions
  (full scan) and for the item ↔ location assignment (all bookings of a location) grew with the history.
- `Schema::maybeInstall()` no longer starts a rebuild for pure structural changes, only for a changed
  `PROJECTOR_VERSION`. The index stays readable during the upgrade.
- Developer documentation in `TECHNICAL.md`. Suite green in both modes (490 tests), PHPStan without errors.

PHPBench (fixture of the project: 100 items × 110 bookings, time per call, cache off):

| Benchmark | without index | with index | factor |
|---|---|---|---|
| booking flow | 347 ms | 14.6 ms | 24× |
| booking flow with booking rule | 852–1,157 ms | 17–108 ms | 11–50× |
| map | 2.98 s | 0.11 s | 28× |
| map with availability | 9.85 s | 2.03 s | 4.8× |
| `[cb_items]` / `[cb_locations]` | 3.14 s / 2.90 s | 0.17 s / 0.17 s | 18× |
| `[cb_items_table]` | 8.78 s | 1.96 s | 4.5× |
| GBFS vehicle availability / vehicle status / station status | 22.5 s / 20.8 s / 7.36 s | 1.81 s / 1.02 s / 0.58 s | 12–20× |
| entire run | 16:43 min | 2:52 min | |

Scaling (development data enlarged tenfold: 167,000 posts, 1.84 million `postmeta`; index rebuild 40 s):

| View | 1× with index | 10× with index | 10× without index |
|---|---|---|---|
| map, locations only | 0.09 s | 0.09 s | 49.96 s |
| map with availability | 1.17 s | 1.06 s | (not measured, minutes) |
| `[cb_items_table]` | 0.67 s | 0.56 s | – |
| `[cb_items]` | 0.09 s | 0.09 s | 25.41 s |

## 9. Risks

| Risk | Countermeasure |
|---|---|
| index deviates from the posts (write path without hooks, direct SQL by third parties) | `verify` via cron, `rebuild` via CLI, switch for the way back, `projector_version` |
| performance when saving drops | projection per post ≈ 1 `REPLACE` + a few `INSERT`s; meta data is already in the cache after saving |
| `updateAllTimeframes()` with many items (category or "all" holidays) | already writes all lists today. The index projects deduplicated once per timeframe. |
| large backfill on shared hosting | bulk `INSERT … SELECT`, AJAX in chunks with resumption, CLI |
| semantic deviation from the old path | projection via the existing models, parity tests, switch |
