# Technical reference: performance booster

*German version: [technische-referenz-booster.md](technische-referenz-booster.md)*

As of 19.11.2026 · branch `booster/index-availability` · comparison base: CommonsBooking 2.11.3 (`master`, `7aa75a81`)
· plugin version of the branch: `2.11.4-beta.5`

## 0. Purpose of this document

Reference for developers who read, review, change or compare the booster code with upstream. It describes the
**code as it is today**, class by class and file by file, and answers questions like "which class does what, what is
active while the booster is off, what is left behind after a downgrade?".

| Document | Content | Relation to this document |
|---|---|---|
| [`technische-dokumentation-booster-02.md`](technische-dokumentation-booster-02.md) | concept, data model in detail, write and read processes with scenarios, measurements (as of 07.11.2026) | explains the **why** and the flows; this document points to its sections |
| [`booster-aenderungen-bestehender-dateien.md`](booster-aenderungen-bestehender-dateien.md) | changes to original files (as of 06.11.2026) | **superseded by section 6** of this document (which includes later changes) |
| [`booster.md`](booster.md), [`timeframe-index.md`](timeframe-index.md), [`availability-precompute.md`](availability-precompute.md), [`role-restricted-availability.md`](role-restricted-availability.md), [`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md), [`zeitrahmen-info.md`](zeitrahmen-info.md) | drafts and decisions | historical record of the decisions |
| `TECHNICAL.md` | summary and conventions for changes | binding rules |
| `dev-changelog/` | every change since 19.11.2026 | ongoing |

**Finding it in the code:** every change to a file of the original carries a note `@since 2.11.4 Booster: …` in the
docblock (method, constant, property) or a comment `Booster (2.11.4)` / `Booster update (2.11.4 …)` in templates,
options and SCSS:

```bash
grep -rn "2.11.4 Booster\|Booster (2.11.4)\|Booster update (2.11.4" src includes templates assets/public/sass
```

The class docblock of every new class describes purpose, collaboration, behaviour while the booster is off and
consequences for a downgrade.

---

## 1. Overview

```
                 Writing (same request)                               Reading
 post saved ──► Indexer ──► Projector ──► cb_index / cb_index_rel ──► Repository\Index ──► repositories
 (save_post, meta,  │          │                                          (Timeframe, Booking,
  status, delete)   │          └─► action "index_rows_changed"              Restriction, BookablePost)
                    │                       │
                    │                       ▼
                    │                 Invalidator ──► cb_availability.gen + 1
                    │                       │              (invalid immediately)
                    │                       ▼
                    │                 Calculator (end of request / cron) ──► cb_availability(_access)
                    │                                                           │
                    │                                                           ▼
                    │                                      Reader (map, table), SlotReader (API, GBFS)
                    ▼
   booking validation ──► Booster::withoutBooster() ──► original queries only, no plugin cache
```

One switch (`booster_enabled`, default **off**) controls everything. While it is off, the original code runs.

---

## 2. Which path runs? (decision chain)

| Check | Method | If not |
|---|---|---|
| booster switched on (setting, filter `commonsbooking_booster_enabled`) | `Booster::isEnabled()` | no hooks, no cron, no reads; original behaviour |
| booster active (activation finished) and not bypassed | `Booster::canRead()` | original queries |
| index complete, filter `commonsbooking_use_index` not `false` | `Backfill::isReady()` = `Repository\Index::isAvailable()` | original queries (`postmeta`) |
| precomputation allowed (`commonsbooking_use_availability_cache`), tables installed, index ready | `Availability::isEnabled()` | every day is computed live as in the original |
| row usable (`computed_gen = gen`, current `calc_version`, younger than `getMaxAge()`) | `Store::isUsable()`, `AccessStore::isUsable()` | row is recomputed and stored while reading |

Inside `Booster::withoutBooster()`, `canRead()` is always false and the plugin cache is bypassed
(`Cache::withoutCache()`). Details: version 2, sections 14 and 19.

---

## 3. Components

Column "off": what the class does while the booster is switched off.

### 3.1 `CommonsBooking\Service\Booster` – switch, guard, administration

| Class | Purpose | Key public methods | off |
|---|---|---|---|
| `Booster` | one switch for index and precomputation, state transitions, bypass for booking validation | `init()`, `isEnabled()`, `canRead()`, `withoutBooster()`, `activate()`, `deactivate()`, `purge()`, `getStatus()`, `onPluginActivation()`, `onPluginDeactivation()` | registers only the settings section and WP-CLI |
| `Guard` | detects changes made without booster code (downgrade, third-party code) and rebuilds or repairs | `check()` (every request, cheap), `deepCheck()` (hourly, CLI), `rebuild()` | not loaded |
| `Diagnostics` | figures for the settings page: coverage, latest bookings, activity log, check results, cron times | `getHealth()`, `getCoverage()`, `getLatestBookings()`, `runAllChecks()`, `recordCheck()` | not loaded |
| `SettingsView` | status section under *Advanced options*, dashboard column, buttons "Check", "Rebuild", "Finish setup" (AJAX in steps of 8 s) | `render()`, `renderDashboardColumn()`, `handleCheck()`, `handleRebuild()`, `handleSetupStep()` | loaded in the admin (shows the switch state) |
| `Cli` | `wp commonsbooking booster status\|enable [--now]\|disable\|rebuild\|check [--deep] [--dry-run]\|purge [--yes]` | – | always registered |

### 3.2 `CommonsBooking\Service\Index` – timeframe index (stage 2)

| Class | Purpose | Key public methods | off |
|---|---|---|---|
| `Schema` | tables `cb_index`, `cb_index_rel`, schema version | `install()`, `uninstall()`, `isInstalled()`, `tablesExist()` | tables stay until `purge` |
| `Projector` | maps a post to its index row(s), idempotent; reports changes via an action | `project()`, `remove()`, `buildRow()`, `diff()`, `getPostTypes()` | not called |
| `Indexer` | hooks on posts and meta, per-request queue, `flush()` before reading and on `shutdown` | `touch()`, `flush()` | not loaded |
| `Backfill` | rebuild in batches (WP-Cron, WP-CLI or time slices from the settings page) with a lock | `start()`, `runBatch()`, `runAll()`, `runFor()`, `isReady()`, `isDone()`, `isStalled()` | not scheduled |
| `Verifier` | sample (daily) or full check (CLI) against the posts, repairs differences | `verify()` | not scheduled |
| `Cli` | `wp commonsbooking index status\|rebuild [--batch-size=<n>]\|verify [--repair] [--sample=<n>]\|drop` | – | not registered |

### 3.3 `CommonsBooking\Repository\Index` – query layer

`isAvailable()`, `findPostIds( $criteria )` (post types, types, statuses, items, locations, authors, range),
`loadPosts()`, `sortByDate()`. Use only if `isAvailable()` is true; otherwise the original queries run. Used by
`Repository\Timeframe`, `Booking`, `Restriction`, `BookablePost` and `AssignedTimeframes`.

### 3.4 `CommonsBooking\Service\Availability` – precomputed availability (stage 3)

| Class | Purpose | Key public methods | off |
|---|---|---|---|
| `Availability` | entry point, horizon (today − 1 to today + 92), maximum age | `init()`, `isEnabled()`, `getHorizon()`, `getMaxAge()` | not loaded |
| `Schema` | tables `cb_availability`, `cb_availability_access` (pure cache) | `install()`, `uninstall()`, `isInstalled()` | tables stay until `purge` |
| `Store` | read/write pair rows, consistency via `gen` without locks | `preload()`, `get()`, `write()`, `invalidate()`, `isUsable()`, `getFlags()` | not called |
| `AccessProfile` | access profile = sorted roles that matter for a role limited pair | `forCurrentUser()`, `fromRoles()`, `runAs()` | not called |
| `AccessStore` | rows per pair and access profile, cleanup of unused profiles | `preload()`, `get()`, `write()`, `hasRoom()`, `cleanup()` | not called |
| `Invalidator` | increments `gen` of the affected pairs on every index change (synchronously) and queues them for recomputation | `onIndexChange()`, `processQueue()` | not loaded |
| `Calculator` | computes the days (flags) and slots of a pair with the same logic as the calendar json, without booking window | `recompute()`, `compute()`, `computeAccessProfile()` | not called |
| `Maintenance` | hourly maintenance, daily check, WP-CLI | `runCron()`, `runVerifyCron()`, `syncPairs()`, `verify()` | not scheduled |
| `Reader` | day status for map and item table; the window is applied while reading | `preload()`, `getDayStatuses()`, `evaluateLive()` | not called (map/table compute live) |
| `SlotReader` | slots for REST API and GBFS from column `slots` | `getAvailabilitySlots()` | not called (API computes live) |
| `DayFacts` | date independent day state (10 flags), from the day grid or from stored flags | `fromGrid()`, `fromFlags()`, `toDayArray()` | **active while off too** (calendar json) |
| `CalendarWindow` | date dependent part: displayed range, booking window, start day offset | `forTimeframes()`, `mapDay()`, `isInBookingWindow()` | **active while off too** (calendar json) |
| `DayStatus` | reduces a day to one status for map and table | `fromDayArray()` | **active while off too** |
| `Slots` | slot generation for the API (live and precomputed), packed binary format | `bookableSlots()`, `toApiSlot()`, `pack()`, `unpack()` | **active while off too** (live API) |
| `Cli` | `wp commonsbooking availability status\|rebuild\|verify [--repair] [--sample=<n>]\|drop` | – | not registered |

`DayFacts`, `CalendarWindow`, `DayStatus` and `Slots` are logic extracted from the original code. Because the live and
the precomputed path use the same classes, their results cannot drift apart. Covered by
`tests/php/View/CalendarSnapshotTest.php` (output compared with 2.11.3).

### 3.5 Assigned timeframes (part of the booster update, active without the booster too)

| Class | Purpose |
|---|---|
| `Repository\AssignedTimeframes` | finds all timeframes and restrictions that control an item or location (single, multiple, category and "all" selection), grouped into current / future / expired. With the index only the candidate search is faster |
| `View\Admin\AssignedTimeframes` | box "Assigned timeframes" on the edit page of items and locations, info box "Timeframes with the same item and location" in the timeframe editor, row action "Timeframes" in the lists |

Read only, no data of its own. Drafts: [`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md),
[`zeitrahmen-info.md`](zeitrahmen-info.md).

---

## 4. Data

All tables carry the WordPress prefix, all options start with `commonsbooking_`. Existing tables (`wp_posts`,
`wp_postmeta`, option entries of the original) are never written differently than in the original.

### 4.1 Tables

| Table | Key | Content | Details |
|---|---|---|---|
| `cb_index` | `post_id` | one row per timeframe, booking, restriction: type, status, author, item/location (single selection), range (`start_ts`, `end_ts`), repetition, grid, booking rules, role limit, restriction type/state, `source_modified_gmt`, `projector_version` | version 2, section 6 |
| `cb_index_rel` | `entity_type, entity_id, post_id` | multiple assignments (lists, categories, "all") | version 2, section 7 |
| `cb_availability` | `location_id, item_id` | per pair: `gen`/`computed_gen`, `days_from`, `days` (flags per day), `slots` (packed), `has_role_limit`, `computed_at`, `calc_version` | version 2, section 8 |
| `cb_availability_access` | `location_id, item_id, access_profile` | days of role limited pairs per access profile, `last_read` for cleanup | version 2, section 8.8 |

### 4.2 Version constants (increase on change)

| Constant | Value | Increase when … | Effect |
|---|---|---|---|
| `Index\Schema::SCHEMA_VERSION` | 3 | the index table structure changes | `dbDelta` on the next request; rows stay valid, no rebuild |
| `Index\Projector::PROJECTOR_VERSION` | 2 | the mapping post → row changes | full rebuild |
| `Availability\Schema::SCHEMA_VERSION` | 3 | the precomputation table structure changes | tables are emptied and recreated |
| `Availability\Calculator::VERSION` | 3 | `DayFacts`, the day grid or the slot format change | all rows are recomputed |

### 4.3 Options

| Option (constant) | Content | autoload |
|---|---|---|
| `commonsbooking_options_advanced-options` → `booster_enabled` | switch (part of the existing settings group) | as before |
| `Booster::STATE_OPTION` | active, activation time, known plugin version, guard watermark, last rebuild | yes |
| `Backfill::READY_OPTION`, `CURSOR_OPTION`, `LOCK_OPTION` | index ready (projector version), progress, lock | yes / no / no |
| `Index\Schema::SCHEMA_VERSION_OPTION`, `Availability\Schema::SCHEMA_VERSION_OPTION` | installed schema version | yes |
| `Maintenance::SYNC_OPTION` | time of the last pair sync | no |
| `Diagnostics::ACTIVITY_OPTION`, `CHECKS_OPTION` | activity log (30 entries), check results | no |

All except the switch are listed in `Booster::OPTIONS` and removed by `purge`.

### 4.4 Cron events

| Hook (constant) | Interval | Task |
|---|---|---|
| `Guard::CRON_HOOK` | hourly | `deepCheck()`: repair diverging posts, create missing tables |
| `Backfill::CRON_HOOK` | single, reschedules itself | next batch of the rebuild |
| `Verifier::CRON_HOOK` | daily | sample of 200 posts against the index |
| `Maintenance::CRON_HOOK` | hourly | new pairs, changed, missing and rows older than 12 h |
| `Maintenance::VERIFY_HOOK` | daily | remove unused access profiles, check a sample of 50 rows |

`Booster::deactivate()` removes all of them.

### 4.5 Filters and actions

| Filter | Default | Effect |
|---|---|---|
| `commonsbooking_booster_enabled` | setting | override the switch (tests, emergency) |
| `commonsbooking_use_index` | `true` | `false`: original queries despite the index |
| `commonsbooking_use_availability_cache` | `true` | `false`: every day live |
| `commonsbooking_availability_horizon_days` | 92 | length of the horizon |
| `commonsbooking_availability_max_age` | 86400 s | maximum age of a row |
| `commonsbooking_availability_sync_limit` | 25 | pairs recomputed at the end of a request |
| `commonsbooking_availability_finish_request` | `true` | send the response before recomputing (PHP-FPM) |
| `commonsbooking_availability_refresh_after_rebuild` | `true` in WP-Cron and WP-CLI, otherwise `false` (then via cron) | recompute right after the index rebuild |
| `commonsbooking_availability_access_profiles`, `…_access_profiles_max` | on, 8 | store access profiles per pair, maximum number |

| Action (constant) | Parameters | Trigger |
|---|---|---|
| `Projector::CHANGED_HOOK` (`commonsbooking_index_rows_changed`) | old, new rows | every change of an index row; used by the `Invalidator` |
| `Backfill::REBUILT_HOOK` (`commonsbooking_index_rebuilt`) | – | rebuild finished |

Admin actions (`admin_post_` / `wp_ajax_`): `commonsbooking_booster_check`, `commonsbooking_booster_rebuild`,
`commonsbooking_booster_setup`; capability `manage_options` and nonce (`SettingsView::authorize()`).

### 4.6 Index hooks

`added_post_meta`, `updated_post_meta`, `deleted_post_meta`, `transition_post_status` → remember the post;
`save_post` (priority `PHP_INT_MAX`) → `flush()`; `deleted_post` → delete rows immediately; `shutdown` → `flush()`.
Code that writes posts with SQL calls `Indexer::touch( $id )` and `Indexer::flush()` (example
`Model\Booking::cancel()`).

---

## 5. Flows (summary)

| Flow | Short | Version 2 |
|---|---|---|
| Switching on | create tables, start the rebuild (8 s in the same request, then settings page or WP-Cron); original queries until it is finished | 12.1, 23 |
| Changing a post | Indexer remembers, Projector writes in the same request, Invalidator increments `gen`, Calculator recomputes up to 25 pairs at the end of the request, the rest via cron | 10, 11, 13 |
| Map / table | `Reader::preload()` loads all pairs, window applied while reading, missing rows are computed and stored | 16, 18 |
| API / GBFS | `SlotReader` from `slots`, falls back to the live path outside the horizon or for multiple assignments | 17 |
| Booking | validation inside `withoutBooster()` (original queries, no cache), item lock `GET_LOCK` 20 s | 19 |
| Downgrade detected | guard: version option written by someone else or posts without index row → rebuild | 20, 21 |

---

## 6. Changes to files of the original

"off" = effective while the booster is switched off too. Every row is marked in the code with
`@since 2.11.4 Booster:`.

### 6.1 Wiring

| File | Location | Change | off |
|---|---|---|---|
| `src/Plugin.php` | `activation()`, `deactivation()` | rebuild the index after a pause, remove cron jobs | – |
| | `init()` | `Booster::init()` | – |
| | `handleBookingForms()` | validation inside `withoutBooster()`, redirect only afterwards (otherwise `exit` skips the `finally` block) | – |
| `includes/OptionsArray.php` | group `booster` | switch and status section under *Advanced options* | switch yes |
| `templates/dashboard-index.php` | column container | fourth column with the booster state (admins only) | yes |
| `commonsbooking.php` | version | `2.11.4-beta.5` (part of every cache key) | yes |

### 6.2 Data access

| File | Location | Change | off |
|---|---|---|---|
| `src/Repository/Timeframe.php` | `get()`, `getInRange()`, new `queryPosts()` | date filters as index criteria; without index original queries | – |
| | new `getByAuthor()` | timeframes/bookings of an author for `getForUser()` | yes |
| `src/Repository/Booking.php` | `getByDate()`, `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()`, new `findViaIndex()` | index, otherwise (or with unsupported `$customArgs`) `WP_Query` | – |
| | `getForUser()`, new `getVisibleCandidates()`, `getManagedPostIds()` | only candidates instead of all bookings, visibility filter unchanged | yes |
| | new `getLatestPastConfirmed()` | candidates for `Item::getClosestBooking()` | – |
| `src/Repository/Restriction.php` | `get()`, new `queryPostsFromIndex()` | active restrictions from the index | – |
| `src/Repository/BookablePost.php` | `getRelatedPosts()`, new `getTimeframesByRelation()` | candidates from the index, old semantics (single id only); related posts in one query | bulk loading yes |

### 6.3 Models

| File | Location | Change | off |
|---|---|---|---|
| `src/Model/Calendar.php` | `__construct()`, `getWeeks()`, new `loadPosts()`, `$timeframes` | load only on a cache miss, restrictions once per calendar | yes |
| | `getAvailabilitySlots()`, new `getAvailabilitySlotsLive()` | first `SlotReader`, otherwise live calculation (logic unchanged) | live path yes |
| `src/Model/Week.php`, `src/Model/Day.php` | constructors, `getRestrictions()`, new `isInRestriction()`, `RESTRICTION_STATUSES` | filter preloaded restrictions in PHP | yes |
| `src/Model/Item.php` | `getClosestBooking()` | with index load only the bookings with the latest start (before: memory limit in `vehicle_availability.json`) | – |
| `src/Model/Map.php` | `get_locations()`, new `getBookableTimeframesByPair()` | load items, timeframes, terms, images in bulk | yes |
| `src/Model/Timeframe.php` | new `withoutBookingWindow()`, `getLatestPossibleBookingDateTimestamp()` | stored days independent of the current date | – |
| | `formatBookableDate()` | fix: full sentence "Permanently available here" | yes |
| `src/Model/Booking.php` | `cancel()` | after direct SQL `clean_post_cache()` and `Indexer::touch()/flush()` | cache yes |

### 6.4 Output

| File | Location | Change | off |
|---|---|---|---|
| `src/View/Calendar.php` | `getCalendarDataArray()`, `prepareJsonResponse()`, new `renderJsonResponse()`, `mapDay()` (new signature) | day logic via `DayFacts`/`CalendarWindow`, same output | yes |
| | `renderTable()`, `renderItemLocationRow()`, `TABLE_STATUS_MARKUP` | preloading via `Reader`; cache key `item_location_today_days`; fix `<span'is-locked'>` | fixes yes |
| | removed | `processSlot()`, `getLockedDayArray()` (protected), `getDefaultCalendarEnddateTimestamp()` (private) | – |
| `src/Map/MapItemAvailable.php` | `create_items_availabilities()`, new `markDays()`, `STATUS_MAPPING`; removed `markDaysInTimeframe()` | day status via `Reader` or live via the same logic | yes |
| `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | pickup notes | fix: no empty lines from the template markup | yes |

### 6.5 Booking, cache, admin

| File | Location | Change | off |
|---|---|---|---|
| `src/Wordpress/CustomPostType/Booking.php` | `savePost()` | admin validation inside `withoutBooster()` | – |
| | new `withItemLock()`, `ITEM_LOCK_TIMEOUT` | database lock per item, 20 s, then `BookingDeniedException` | yes |
| | `handleFormRequest()`, new `processFormRequest()`, `redirectToBooking()` | split so that the redirect comes after `withoutBooster()` | – |
| `src/Wordpress/CustomPostType/Timeframe.php` | `validateTimeFrame()` | validation inside `withoutBooster()` | – |
| | `filterAdminList()`, `setCustomColumnsData()`, `initHooks()` | filter finds list selections, column shows lists/categories/"all", info box | yes |
| `src/Wordpress/CustomPostType/Item.php`, `Location.php`, `CustomPostType.php` | `initHooks()`, `modifyRowActions()` | box and row action "Assigned timeframes" | yes |
| `src/Service/BookingRuleApplied.php` | `bookingConformsToRules()` | rule "one unconfirmed booking" loads only the user's own bookings | yes |
| `src/Service/Cache.php` | `getCacheId()`, new `getRoleContext()` | roles in the cache key (before, a guest could get the admin view) | yes |
| | new `withoutCache()`, `getCacheItem()`, `setCacheItem()` | switch the cache off temporarily | – |
| `includes/Public.php` | `commonsbooking_public()` | double-click protection of the booking forms | yes |

### 6.6 Changed interfaces (for subclasses and third-party code)

| Element | Change |
|---|---|
| `View\Calendar::mapDay()` (protected) | new signature `( Day, CalendarWindow, array & )` |
| `View\Calendar::processSlot()`, `getLockedDayArray()` (protected) | removed (`CalendarWindow::getLockedDayArray()`) |
| `View\Calendar::renderItemLocationRow()` (protected) | additional optional parameter `?Reader` |
| `Map\MapItemAvailable::markDaysInTimeframe()` (protected) | removed, replaced by `markDays()` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | additional optional parameter |
| `Model\Calendar::$timeframes` | now `?array`, loaded in `loadPosts()` |
| new public | `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`, `Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Cache::withoutCache()`, `Day::RESTRICTION_STATUSES`, `Timeframe::withoutBookingWindow()`, `CPT\Booking::processFormRequest()`, `redirectToBooking()` |

---

## 7. Downgrade

Result of the checklist in `TECHNICAL.md`:

| Item | Result |
|---|---|
| existing postmeta, options, usermeta, tables | not reinterpreted, not rewritten, not deleted. Only additional write on existing data: `clean_post_cache()` after `cancel()` |
| new data | four own tables (4.1), own options (4.3), switch in the existing settings group; an older version ignores them |
| switch off | no hooks, no cron, no reads (exception: settings page, WP-CLI `booster`). Only the changes marked "off" in section 6 stay active; they write no data |
| downgrade and upgrade again | `Guard` detects a plugin version written by someone else and posts without index row → rebuild; hourly deep check; rows older than 24 h are never used |
| cleanup | `wp commonsbooking booster purge --yes`: tables, options, cron jobs |
| booking validation | never from derived data (`withoutBooster()`) |

Recommended procedure: switch the booster off, install the older version, optionally `purge` (before, while the booster
code is still present) or delete tables/options by hand. Version 2, section 21.

---

## 8. Extending and changing

- **Writing posts with SQL:** afterwards `Indexer::touch( $id )` and `Indexer::flush()`.
- **Changing the mapping, tables or day calculation:** increase the matching version constant (4.2).
- **New query on timeframes/bookings:** check `Repository\Index::isAvailable()` first, otherwise the original query;
  prove parity with a test (model: `tests/php/Service/Index/TimeframeIndexParityTest.php`).
- **New booking validation:** run it inside `Booster::withoutBooster()`.
- **New classes:** `composer run prefix-namespaces`.
- **Conventions and changelog:** `TECHNICAL.md` "Conventions for changes (booster fork)", `dev-changelog/`.

---

## 9. Tests

By default the suite runs with the booster switched on and a fully built index. CI repeats it with `CB_USE_BOOSTER=0`
(original), `CB_USE_INDEX=0` (original queries) and `CB_USE_AVAILABILITY=0` (live).

| Test | Checks |
|---|---|
| `Service/Index/TimeframeIndexParityTest.php`, `Service/Booster/TimeframeVariantsParityTest.php` (+ `Fixtures/TimeframeVariants.php`) | index and original queries return the same posts, across many timeframe variants |
| `Service/Index/IndexerTest.php` | hooks, queue, deletion, direct SQL writes |
| `Service/Availability/AvailabilityTest.php`, `AccessProfileTest.php`, `SlotsTest.php` | precomputation, invalidation, access profiles, slot format |
| `View/CalendarSnapshotTest.php` (+ `snapshots/calendar-core.json`) | calendar json, map, table compared with 2.11.3; stored = live = freshly computed |
| `Service/Booster/BoosterTest.php`, `SetupTest.php`, `DiagnosticsTest.php`, `CacheRoleContextTest.php` | switch, transitions, guard, setup, diagnostics, cache key |
| `Wordpress/CustomPostType/BookingItemLockTest.php` | item lock |
| `Repository/AssignedTimeframesTest.php`, `View/AssignedTimeframesViewTest.php` | assigned timeframes |
| extended: `Repository/BookingTest.php`, `Model/ItemTest.php`, `View/CalendarTest.php`, `Wordpress/CustomPostType/BookingTest.php` | `getForUser()`, `getClosestBooking()`, `is-locked`, rule "one unconfirmed booking" |
