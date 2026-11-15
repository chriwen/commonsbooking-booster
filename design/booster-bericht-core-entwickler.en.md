# Performance booster: changes to existing code (report for the core developers)

As of 2026-11-12. Compared were `master` of the original project (`wielebenwir/commonsbooking`, commit `7aa75a81`,
merge of PR #2418, based on 2.11.3) and the branch `booster/index-availability` (fork `chriwen/commonsbooking-booster`,
commit `0312f3aa`, version `2.11.4-beta.5`, 26 commits). German version:
[`booster-bericht-core-entwickler.md`](booster-bericht-core-entwickler.md).

This report is meant for developers of the main project. It answers: **Which existing files, classes and methods were
changed, how did they behave before, how do they behave now, and what changes even with the booster switched off?**
New classes are only described as far as they interfere with existing code. Their full description is in
[`technische-dokumentation-booster-02.en.md`](technische-dokumentation-booster-02.en.md).

Method: the diff was read file by file (`git diff 7aa75a81 HEAD`) and compared with the design documents
(`booster.md`, `booster-aenderungen-bestehender-dateien.md`, `technische-dokumentation-booster-02.md`,
`zugeordnete-zeitrahmen.md`, `zeitrahmen-info.md`). Where the code differs from a document or a document is missing
something, this is listed in section 19.6.

---

## Contents

1. [Summary](#1-summary)
2. [Basic principle and decision logic](#2-basic-principle-and-decision-logic)
3. [Overview of all changed runtime files](#3-overview-of-all-changed-runtime-files)
4. [Wiring: plugin, options, dashboard](#4-wiring-plugin-options-dashboard)
5. [Data access: repositories](#5-data-access-repositories)
6. [Models: Calendar, Week, Day, Item, Booking, Map, Timeframe](#6-models-calendar-week-day-item-booking-map-timeframe)
7. [Display: View\Calendar, map, table](#7-display-viewcalendar-map-table)
8. [Booking flow](#8-booking-flow)
9. [Cache](#9-cache)
10. [Admin feature "Assigned timeframes"](#10-admin-feature-assigned-timeframes)
11. [Minor changes: template, SCSS, frontend script](#11-minor-changes-template-scss-frontend-script)
12. [What was deliberately not changed](#12-what-was-deliberately-not-changed)
13. [How new components hook into existing flows (hooks, options, cron)](#13-how-new-components-hook-into-existing-flows)
14. [Behaviour changes independent of the switch](#14-behaviour-changes-independent-of-the-switch)
15. [Deviations from the original behaviour with the booster switched on](#15-deviations-from-the-original-behaviour-with-the-booster-switched-on)
16. [Changed interfaces (compatibility for subclasses and third parties)](#16-changed-interfaces)
17. [Consistency and safety](#17-consistency-and-safety)
18. [Tests, CI, tools](#18-tests-ci-tools)
19. [Notes for the review, limits, comparison with the design documents](#19-notes-for-the-review-limits-comparison-with-the-design-documents)
20. [Rules for future code](#20-rules-for-future-code)

---

## 1. Summary

**Purpose.** On large instances (reference: 167,000 bookings, 100 stations) the map, `[cb_items_table]`,
`[cb_items]`, `[cb_locations]`, API and GBFS take seconds to minutes or abort at the memory limit (cause: meta joins
over `wp_postmeta` per item/location/day and computing every day with all timeframes). The booster additionally stores
the same data in four tables of its own and reads displays from them.

**Four tables, nothing existing is touched.**

| Table | Content | Written by |
|---|---|---|
| `{prefix}cb_index` | one row per timeframe/booking/restriction (type, item, location, start/end as numbers, status, author, grid, rules) | `Service\Index\Projector` |
| `{prefix}cb_index_rel` | assignment for multi-selection (holidays, repairs) | `Projector` |
| `{prefix}cb_availability` | per item/location pair precomputed day facts (10 bits per day) and free slots for 94 days | `Service\Availability\Store` |
| `{prefix}cb_availability_access` | the same per access profile for role-restricted pairs | `AccessStore` |

`wp_posts` and `wp_postmeta` are **never written differently than before**. The booster is a pure read cache that can
be rebuilt at any time, maintained through hooks.

**One switch, off by default.** Settings → Advanced → "Performance booster" (`booster_enabled` in
`commonsbooking_options_advanced-options`), filter `commonsbooking_booster_enabled`, WP-CLI `commonsbooking booster
enable|disable`. When switched off, no hooks, no cron jobs and no reads from the tables run.

**Extent of the change to existing code (without translations, docs, tests):**

| | Count |
|---|---|
| changed runtime files (`src/`, `includes/`, `templates/`, `assets/`, `commonsbooking.php`) | 27 |
| of which effective **also with the booster switched off** | 15 (section 14) |
| new classes | 29 (`src/Service/Index`, `src/Service/Availability`, `src/Service/Booster`, `src/Repository/Index.php`, `src/Repository/AssignedTimeframes.php`, `src/View/Admin/AssignedTimeframes.php`) |
| removed methods | 4 (`View\Calendar::processSlot`, `getLockedDayArray`, `getDefaultCalendarEnddateTimestamp`, `Map\MapItemAvailable::markDaysInTimeframe`) |
| methods with changed signature | 3 (`View\Calendar::mapDay`, `Model\Day::__construct`, `Model\Week::__construct`) plus one with an additional optional parameter (`renderItemLocationRow`) |
| changes to test bootstrap and CI | booster on by default, three additional CI runs (section 18) |

**Largest interventions** (by size and risk): `View\Calendar` (day logic moved into a shared computing core, output
secured by a snapshot against 2.11.3), `Repository\Booking` and `Repository\Timeframe` (index path next to the
original path), `Model\Calendar`/`Week`/`Day` (restrictions once per calendar), `CustomPostType\Booking` (item lock
against double bookings), `Service\Cache` (roles in the key).

---

## 2. Basic principle and decision logic

### 2.1 The uniform pattern

Every converted method keeps its public signature and its result. Internally:

```php
if ( ! Index::isAvailable() ) {
    return /* unchanged code from 2.11.3 */;
}
return /* index path with identical result */;
```

PHP filters after loading (`filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `isBookable`, role check,
item/location filter in `Restriction::filterPosts()`) and the plugin cache run unchanged in **both** paths. The index
only replaces **finding the post IDs**; the posts including their meta data are then loaded in bulk via
`_prime_post_caches()` and evaluated by the existing models. The index row deliberately contains no interpretation (no
days, no bookability).

### 2.2 Decision chain

```php
Booster::isEnabled()      // setting "on", can be overridden with the filter commonsbooking_booster_enabled
Booster::canRead()        // isEnabled() && not inside withoutBooster() && state "active"
Backfill::isReady()       // canRead() && filter commonsbooking_use_index && schema installed
                          //   && option commonsbooking_index_ready === Projector::PROJECTOR_VERSION
Index::isAvailable()      // = Backfill::isReady()                     → repositories
Availability::isEnabled() // filter commonsbooking_use_availability_cache && schema installed
                          //   && Backfill::isReady()                  → Reader, SlotReader
```

| Situation | Index read | Precomputation read | Behaviour |
|---|---|---|---|
| booster off (default) | no | no | like 2.11.3 |
| booster on, index being built | no | no | hooks write, reads go through `postmeta` |
| booster on, index complete | yes | yes | index path and precomputation |
| filter `commonsbooking_use_availability_cache = false` | yes | no | index, days live |
| filter `commonsbooking_use_index = false` | no | no | everything live |
| inside `Booster::withoutBooster()` | no | no | original path, without plugin cache |
| guard started a rebuild | no until finished | no | original path |

### 2.3 Most important safety decision

Booking and timeframe validation **always** run in the original path (`Booster::withoutBooster()`, section 8). An
outdated index can therefore at most cause a wrong display, never a double booking.

---

## 3. Overview of all changed runtime files

"Effective without booster" means: the change also applies when the switch is off.

| File | Change (short) | Effective without booster |
|---|---|---|
| `commonsbooking.php` | version `2.11.4-beta.5` | yes (the cache key contains the version) |
| `includes/OptionsArray.php` | new group "Performance booster" under *Advanced options* | yes (setting visible) |
| `includes/Public.php` | inline script: submit booking forms only once | yes |
| `src/Plugin.php` | `Booster::init()`, `onPluginActivation()`, `onPluginDeactivation()`, `handleBookingForms()` in `withoutBooster()` | init/hooks no, otherwise without effect |
| `src/Repository/Timeframe.php` | new `queryPosts()` (index or original), new `getByAuthor()` | only `getByAuthor()` |
| `src/Repository/Booking.php` | `findViaIndex()`, `getLatestPastConfirmed()`, `getForUser()` with candidates | `getForUser()` yes |
| `src/Repository/Restriction.php` | `queryPostsFromIndex()` | no |
| `src/Repository/BookablePost.php` | `getTimeframesByRelation()`, bulk loading of related posts | bulk loading yes |
| `src/Model/Calendar.php` | late loading, restrictions once per calendar, slots from precomputation | loading yes |
| `src/Model/Week.php`, `src/Model/Day.php` | new optional parameter, `isInRestriction()`, `RESTRICTION_STATUSES` | yes |
| `src/Model/Item.php` | `getClosestBooking()` via `getLatestPastConfirmed()` | no |
| `src/Model/Booking.php` | `cancel()`: `clean_post_cache()` and index calls | `clean_post_cache()` yes |
| `src/Model/Map.php` | `get_locations()` loads everything in bulk, new `getBookableTimeframesByPair()` | yes |
| `src/Model/Timeframe.php` | new `withoutBookingWindow()`: hide the advance-booking window during precomputation | no |
| `src/View/Calendar.php` | day logic moved out, `Reader` for the table, two bug fixes | refactoring and fixes yes |
| `src/Map/MapItemAvailable.php` | reads via `Reader`, `markDays()` instead of `markDaysInTimeframe()` | refactoring yes |
| `src/Service/BookingRuleApplied.php` | rule "one unconfirmed booking" only queries own bookings | yes |
| `src/Service/Cache.php` | role context in the key, `withoutCache()` | yes |
| `src/Wordpress/CustomPostType/Booking.php` | `withItemLock()`, admin validation in `withoutBooster()` | lock yes |
| `src/Wordpress/CustomPostType/Timeframe.php` | `validateTimeFrame()` in `withoutBooster()`, admin filter and column for multi-selection, info box | filter, column, info box yes |
| `src/Wordpress/CustomPostType/CustomPostType.php` | row action "Timeframes" for items and locations | yes |
| `src/Wordpress/CustomPostType/Item.php`, `Location.php` | meta box "Assigned timeframes" | yes |
| `templates/dashboard-index.php` | fourth dashboard column (admins only) | yes |
| `templates/location-calendar-header.php` | pickup instructions in their own `<span>` | yes |
| `assets/public/sass/partials/_lists.scss` | `white-space: pre-wrap` only on the text of the pickup instructions | yes |

Not part of the runtime scope, but changed: `.distignore`, `.github/workflows/phpunit.yml`, `phpstan.neon`,
`phpstan-baseline.neon`, `TECHNICAL.md`, `languages/*`, tests (section 18).

---

## 4. Wiring: plugin, options, dashboard

### 4.1 `src/Plugin.php`

| Place | 2.11.3 | Booster |
|---|---|---|
| `Plugin::init()` | – | calls `Service\Booster\Booster::init()` right after `do_action( 'cmb2_init' )` |
| `Plugin::activation()` | – | `Booster::onPluginActivation()` before `self::clearCache()`: sets the state to "inactive" and rebuilds the index if the switch is on (the index may be outdated after a pause) |
| `Plugin::deactivation()` | `do_action( Scheduler::UNSCHEDULER_HOOK )` | additionally `Booster::onPluginDeactivation()` (removes cron events, marks the index as outdated) |
| `Plugin::handleBookingForms()` | `Booking::handleFormRequest()` directly | in `Booster::withoutBooster( [ Booking::class, 'handleFormRequest' ] )`; the `catch ( BookingDeniedException )` stays unchanged |

`Booster::init()` only registers `init` priority 0 (`Booster::onInit()`: switching on/off, guard) and, with
`is_admin()`, the settings page. Only when the switch is on do `Indexer::init()`, `Availability::init()`,
`Guard::init()`, `Diagnostics::init()` follow (all hooks from section 13). WP-CLI commands are only registered under
WP-CLI.

### 4.2 `includes/OptionsArray.php`

New group `booster` (title "Performance booster", ID `booster_group`) before the group "Advanced caching settings":

- Checkbox `Booster::SETTINGS_FIELD` (`booster_enabled`), default: not set (off).
- Status field `booster_status` with `render_row_cb` → `SettingsView::render()` (diagnostics, buttons "Check" /
  "Rebuild" / "Finish setup now"). The field stores nothing.

The anchor of the group in CMB2 is `#booster-group-header`.

### 4.3 `commonsbooking.php`

`COMMONSBOOKING_VERSION` and plugin header: `2.11.3` → `2.11.4-beta.5`. The version number is part of every cache key
(`Cache::getCacheId()`) and is used by the guard to detect a version change (`Upgrade::VERSION_OPTION`, existing
option `commonsbooking_plugin_version`). The branch therefore has to carry a version number of **its own**, otherwise
the guard's version check does not work (the two other checks still catch the case, see 17.3).

### 4.4 `templates/dashboard-index.php`

- For administrators the class `cb-dashboard-four-columns` is added to the container
  `.cb_welcome-panel-column-container` (`commonsbooking_isCurrentUserAdmin()`).
- After the third column: `SettingsView::renderDashboardColumn()` (admins only). The column shows the status (off /
  being set up / notices / problem / active), two figures "x of y", "Last updated … ago", a link to the settings and,
  as long as the booster has never been on, the badge "New". It runs no checks and only reads counts. The column styles
  are in the column's markup, the compiled admin CSS stays unchanged.

---

## 5. Data access: repositories

### 5.1 `Repository\Timeframe`

| Method | 2.11.3 | Booster |
|---|---|---|
| `get()` | `getPostIdsByType( $types, $items, $locations )`, then `getPostsByBaseParams( $date, $minTimestamp, null, $postIds, $postStatus )` inline | `self::queryPosts( $types, $items, $locations, $date, $minTimestamp, null, $postStatus )` |
| `getInRange()` | as above with `$maxTimestamp` | `self::queryPosts( …, null, $minTimestamp, $maxTimestamp, … )` |
| `queryPosts()` | – | **new, private.** Without index: the same two-step path as before. With index: `Index::findPostIds()` + `Index::loadPosts()` |
| `getByAuthor()` | – | **new, public:** timeframes/bookings of an author (`post_author`), with index or via SQL (`posts` ⨝ `postmeta type`). Uses `filterTimeframes()` and `castPostsToModels()` like `get()`. Sorted by post ID |
| `getPostIdsByType()`, `getPostsByBaseParams()`, `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()` | central SQL building blocks | **unchanged**, now only run in the original path |
| `filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `filterTimeframesByMaxBookingDays()`, `filterTimeframesForCurrentUser()` | PHP filters after loading | unchanged, in both paths |

**Translation of the date filters** (`queryPosts()`; corresponds to the three modes of `getPostsByBaseParams()`):

| Mode | old SQL | index criteria |
|---|---|---|
| one day (`$date` without `$minTimestamp`) | `repetition-start BETWEEN 0 AND day 23:59` and (`repetition-end BETWEEN day 00:00 AND 3000000000` or no end) | `start_min = 0`, `start_max = strtotime( $date . 'T23:59' )`, `end_min = strtotime( $date )`, `end_max = 3000000000` |
| from a point in time (`$minTimestamp`) | `repetition-end >= start of day` or no end | `end_min = Helper::getLastFullDayTimestamp( $minTimestamp )` |
| range (`$minTimestamp` and `$maxTimestamp`) | overlap | `start_max = $maxTimestamp`, `end_min = $minTimestamp` |

Same edge cases as the old SQL: `start_*` excludes NULL (the old SQL required the meta key), `end_*` allows an open
end (`end_ts IS NULL`).

With an item/location filter, `Index::findPostIds()` builds a `UNION ALL` of (a) single assignment (`is_multi = 0`,
`item_id`/`location_id`) and (b) multi-assignment (`is_multi = 1`, `EXISTS` on `cb_index_rel`). This replaces the two
`INNER JOIN`s of `getEntityQuery()` (single field **or** list, item **and** location).

### 5.2 `Repository\Booking`

| Method | 2.11.3 | Booster |
|---|---|---|
| `getEndingBookingsByDate()` | `WP_Query` with meta queries | first `findViaIndex( [ require_end, end_min, end_max ], $customArgs, [ 'confirmed', 'unconfirmed' ] )` |
| `getBeginningBookingsByDate()` | `WP_Query` | first `findViaIndex( [ start_min, start_max ], $customArgs, [ 'confirmed' ] )` |
| `getByDate()` | `WP_Query`, more than one hit → `Exception` | first `findViaIndex( [ start_min, start_max, end_exact, items, locations ] )`; the exception "Found more than one bookings" is kept |
| `getByTimerange()` (basis of `getExistingBookings()`, `getByRestriction()`, booking rules) | `WP_Query` with up to five meta joins | first `findViaIndex( [ start_min = PHP_INT_MIN, start_max = $endDate, require_end, end_min = $startDate, locations?, items? ] )` |
| `findViaIndex()` | – | **new, private.** Supports only `author` (numeric), `post_status`, `orderby = date`, `order` from `$customArgs`. **Any other argument → `null`**, then the `WP_Query` runs unchanged. Sorted like `WP_Query` via `Index::sortByDate()` (`post_date`, tie: ID ascending) |
| `getLatestPastConfirmed( $itemId, $locationId, $now )` | – | **new, public.** See 6.4. Without index `null` |
| `getForUser()` | `self::get( [], [], null, $asModel, $minTimestamp, $postStatus )` loads **all** bookings, then `commonsbooking_isUserAllowedToSee()` | `getVisibleCandidates()`, then the same filter. **Also without booster** |
| `getVisibleCandidates()` | – | **new, private.** CB admin → all as before. Otherwise: own bookings (`Timeframe::getByAuthor`) ∪ bookings of managed items ∪ bookings of managed locations; duplicates removed, sorted by ID |
| `getManagedPostIds()` | – | **new, private.** IDs of items/locations with `post_author = user` **or** user in `_cb_item_admins` / `_cb_location_admins`. The admin meta is partly a serialised list, partly a single value: SQL narrows down roughly with `LIKE '%<id>%'`, PHP checks exactly after `maybe_unserialize()` (user 1 does not match user 11/17) |
| `getForUserPaginated()` | – | unchanged |

**Why `getForUser()` returns the same result.** A booking is visible to a non-admin if they are the author or admin
of the item **or** the location (`Model\Timeframe::getAdmins()` merges both sources plus `post_author` of item and
location). The candidate set is a superset of this; the unchanged `commonsbooking_isUserAllowedToSee()` filter
decides. The order stays by post ID. Nothing changes for CB admins (still all bookings, see 19.4).

### 5.3 `Repository\Restriction::get()`

```php
// 2.11.3
$posts = self::queryPosts( $date, $minTimestamp, $postStatus );
if ( $posts && count( $posts ) ) { $posts = Wordpress::flattenWpdbResult( $posts ); … }

// Booster
if ( Index::isAvailable() ) {
    $posts = self::queryPostsFromIndex( $date, $minTimestamp, $postStatus );
} else {
    $posts = self::queryPosts( $date, $minTimestamp, $postStatus );
    $posts = $posts ? Wordpress::flattenWpdbResult( $posts ) : [];
}
```

`queryPostsFromIndex()` (new, private) sets `restriction_states = [ active ]` (like the old SQL, which only reads active
restrictions) and translates `$minTimestamp` to `end_min = $minTimestamp + 1` and `$date` to `start_min 0`,
`start_max = day 23:59`, `end_min = start of day`, `end_max = Restriction::NO_END_TIMESTAMP`. The item/location
assignment (`0` = all) is still checked by the existing PHP filter `filterPosts()`. In the index `0` stands for "all"
in the same column, so the assignment is not changed.

### 5.4 `Repository\BookablePost::getRelatedPosts()`

(Basis of `Item::getByLocation()` and `Location::getByItem()`.)

| | 2.11.3 | Booster |
|---|---|---|
| candidates | `WP_Query` on `cb_timeframe` with meta `{type}-id = $postId` | `getTimeframesByRelation( $postId, $originType )` (**new, private**) |
| without index | – | the same `WP_Query` (arguments unchanged) |
| with index | – | candidates via `Index::findPostIds( [ post_types, statuses, items|locations ] )`, then **filter on the single `{type}-id` value** (the old semantics; the index would also find lists), sorted by `post_date DESC` |
| related posts | loaded one by one | in bulk with `_prime_post_caches()` – **also without index** |

---
## 6. Models: Calendar, Week, Day, Item, Booking, Map, Timeframe

### 6.1 `Model\Calendar`

| Element | 2.11.3 | Booster |
|---|---|---|
| `$timeframes` | `protected array`, filled in the constructor (also on a cache hit of `getWeeks()`) | `protected ?array = null`, filled in `loadPosts()` |
| `$restrictions` | – | **new**, `protected array` |
| `__construct()` | loaded `Timeframe::getInRange()` | loads nothing |
| `loadPosts()` | – | **new, protected.** Idempotent. Loads timeframes as before (`getInRange( start, end, locations, items, types, true, [ confirmed, publish ] )`) and **restrictions once for the range**: `Restriction::get( $locations, $items, null, true, $startTimestamp - 1, Day::RESTRICTION_STATUSES )` |
| `getWeeks()` | weeks without restrictions | calls `loadPosts()` only if not answered from the cache; passes `$this->restrictions` as the last parameter to `new Week( … )` |
| `getAvailabilitySlots()` | computed live | first asks `SlotReader::getAvailabilitySlots( start, end, locations, items, ignoreStartDayOffset, ignoreRestrictions )`; on `null` → `getAvailabilitySlotsLive()` |
| `getAvailabilitySlotsLive()` | – | **new, public.** The previous computation path; creating the slot objects lives in `Service\Availability\Slots` (`bookableSlots()`, `getEntityIds()`, `toApiSlot()`, `addUnique()`), shared with the precomputation |

Live slot logic, before/after: the type check `get_post_meta( id, 'type' ) != BOOKABLE_ID` moved to
`Slots::bookableSlots( $day->getGrid() )`, building `start`/`end` (UTC, `Y-m-d\TH:i:sP`), `locationId`, `itemId` and
the duplicate check (`md5( serialize( $slot ) )`) moved to `Slots`. The `getFirstBookableDay()` check stays in
`getAvailabilitySlotsLive()`. Output: the same objects.

### 6.2 `Model\Week`, `Model\Day`

- `Week::__construct()` and `Day::__construct()` get `?array $possibleRestrictions = null` as their **last** parameter.
  `Week` passes it on to every `Day`. Calls without the parameter behave as before.
- `Day::RESTRICTION_STATUSES = [ 'publish', 'confirmed', 'unconfirmed' ]` (previously a literal in `getRestrictions()`).
- `Day::getRestrictions()`:
  - `$possibleRestrictions !== null`: `array_filter( …, isInRestriction() )`.
  - otherwise: as before `Restriction::get( locations, items, getDate(), true, null, RESTRICTION_STATUSES )`.
- `Day::isInRestriction( Restriction $r )` (**new, protected**) reproduces the date condition of the old SQL query per
  day: start set (`''` → no), `0 <= start <= day 23:59`; without end → yes, otherwise
  `start of day <= end <= Restriction::NO_END_TIMESTAMP`.

**Effect:** a calendar (map, table, item page, REST) used to call `Restriction::get()` per day (3,000–6,000 queries per
map/table call). Now it is one query per calendar. This change applies **also without the booster**.

### 6.3 `Model\Booking::cancel()`

The direct SQL update of the status (`$wpdb->query( $sql )`) bypasses all post hooks. New after it:

```php
clean_post_cache( $this->post->ID );                    // fixes a stale object cache (also without booster)
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( $this->post->ID, 'cancellation_time', current_time( 'timestamp' ) );
\CommonsBooking\Service\Index\Indexer::flush();         // update index (and availability) immediately
$this->sendCancellationMail();
```

`touch()` and `flush()` have no effect without installed index tables (`flush()` checks `Schema::isInstalled()`).
Nuance: if the tables from earlier still exist and the booster is switched off, `flush()` writes the row to the (then
unused) table. On the next switch-on everything is rebuilt anyway.

### 6.4 `Model\Item::getClosestBooking()`

Returns the most recently started, already finished, confirmed booking of the item at the current location. The
cloaked GBFS `vehicle_id` (`getCloakedId()`) is derived from it and changes after every rental.

```php
// 2.11.3
$allBookings = Booking::get( [ $location->ID ], [ $this->ID ], null, true, null, [ 'confirmed' ] );
$allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );

// Booster
$allBookings = Booking::getLatestPastConfirmed( $this->ID, $location->ID, current_time( 'timestamp' ) );
if ( $allBookings === null ) {                 // no index: original path
    $allBookings = Booking::get( … same arguments … );
    $allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );
}
// unchanged afterwards: usort by start day, on a tie by start time; array_pop()
```

`getLatestPastConfirmed()` runs two index queries:

```sql
SELECT MAX(COALESCE(start_ts, 0)) FROM cb_index
 WHERE item_id = %d AND location_id = %d AND type = 6 AND post_type = 'cb_booking'
   AND post_status = 'confirmed' AND (end_ts IS NULL OR end_ts < :now);
SELECT post_id FROM cb_index WHERE <same condition> AND COALESCE(start_ts, 0) = :latestStart ORDER BY post_id;
```

The semantics of the original are preserved:

| Peculiarity | Reproduction |
|---|---|
| only `confirmed`, only type 6 | same conditions |
| `isPast()` compares the end meta as an integer, a missing end (`intval('') = 0`) counts as past | `end_ts IS NULL OR end_ts < :now` |
| missing start = 0 | `COALESCE( start_ts, 0 )` |
| tie on the start day: start time decides; then stable `usort`, `array_pop` takes the highest ID | all candidates with the same start are loaded, the **unchanged** sorting decides; `ORDER BY post_id` |

Result: instead of all confirmed bookings of the pair (on the reference instance ≈ 1,300 bookings, 10 MB, 95 ms per
item; `vehicle_availability.json` aborted with HTTP 500 at a 128 MB memory limit) usually one booking is loaded.
`vehicle_id` is identical: compared on the reference instance for all 126 items and on the test environment for all 41
items (`dev-bench/closest-parity.php`, 0 differences). Test: `ItemTest::testGetClosestBooking_indexMatchesLegacy`.
Without the booster the memory load stays as in 2.11.3.

### 6.5 `Model\Map::get_locations()`

| | 2.11.3 | Booster (**also without booster**) |
|---|---|---|
| items per location | `Item::getByLocation()` in the main loop | in advance for all locations (`$itemsByLocation`) |
| timeframes | per pair `Timeframe::getBookableForCurrentUser( [ $loc ], [ $item ], null, true )` | **one** query for all pairs: `Map::getBookableTimeframesByPair( $locationIds, $allItemIds )`, access `$timeframesByPair[ $loc ][ $item ] ?? []` |
| terms | `wp_get_post_terms()` per item with a single query | `update_object_term_cache()` for items and locations beforehand |
| thumbnails | one by one | `_prime_post_caches( $thumbnailIds, false, true )` |

`getBookableTimeframesByPair()` (new, public) calls `getBookableForCurrentUser( $locationIds, $itemIds, null, true )`
once and groups by `getLocationIDs() × getItemIDs()`. This is equivalent to the call per pair because bookable
timeframes have exactly one item and location (lists are removed for this type on save) and all repository filters
work per timeframe.

### 6.6 `Model\Timeframe::withoutBookingWindow()`

`getEndDate()` cuts the end of a bookable timeframe to the advance booking window
(`getLatestPossibleBookingDateTimestamp()` = `time()` + advance booking days), `isBookable()` checks the same window.
`Model\Day`, `Model\Week` and `Repository\Timeframe::filterTimeframesForTimerange()` use both. As a result, the grid of
a day (`Day::getGrid()`) also depended on today's date.

```php
// new, public static
Timeframe::withoutBookingWindow( callable $callback );   // sets a static flag for the duration of the callback
// at the start of getLatestPossibleBookingDateTimestamp():
if ( self::$ignoreBookingWindow ) { return PHP_INT_MAX; }
```

The only caller is `Service\Availability\Calculator::computeDays()`. The stored day facts therefore contain the
timeframe up to its actual end, and the readers apply the window: `CalendarWindow` (`lastBookableDate`),
`Reader::getFacts()` (computes a pair live if another bookable timeframe has a shorter window) and `SlotReader` (per
timeframe). Before, the row contained the window as of the computation time, and after midnight the last day of the
window stayed locked in map, table, API and GBFS until the next recomputation (up to 12 h) (found in the frontend test
of 2026-11-12). `Calculator::VERSION` was raised from 2 to 3 for this. Outside the callback and with the booster
switched off nothing changes; booking validation never uses the flag.

---

## 7. Display: View\Calendar, map, table

### 7.1 Starting point in 2.11.3

The same day logic (when is a day locked, booked, a holiday, partially booked) existed twice:
`View\Calendar::processSlot()` / `mapDay()` for JSON and table and `MapItemAvailable::markDaysInTimeframe()` for the
map. Both computed the full slot grid (`Day::getGrid()`) per pair and day, also for days that are not displayed (map:
60 days instead of the displayed ones).

### 7.2 New shared computing core (`Service\Availability`)

| Class | Task | Replaces |
|---|---|---|
| `DayFacts` | condenses a slot grid into ten bits (`HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_SLOT_BOOKED`, `LAST_SLOT_BOOKED`, `FULL_DAY`) and derives the day array of the JSON from them | `View\Calendar::processSlot()` |
| `CalendarWindow` | everything that depends on today's date: display range, advance booking limit, first bookable day, `mapDay()` | window logic from `getCalendarDataArray()`, `prepareJsonResponse()`, `mapDay()`, `getLockedDayArray()`, `getDefaultCalendarEnddateTimestamp()` |
| `DayStatus` | one status per day (`no-slots`, `holiday`, `booked`, `partially-booked-start`, `partially-booked-end`, `locked`, `available`) from the day array | the identical if chains in `renderItemLocationRow()` and `markDaysInTimeframe()` |
| `Reader` | returns `DayStatus` per day for map and table: from the stored bits plus window, otherwise live | – |

`DayFacts::fromGrid()` takes over the rules of `processSlot()` **including their peculiarities**, which are pinned by a
snapshot test: `repair` is reset by every slot (strict comparison of text against number,
`$timeFrameType !== Timeframe::REPAIR_ID`); 0 advance booking days count as "not set" (`== null`);
`maxDays`/`startDayOffset` come from the timeframe of the first slot if it is bookable.

### 7.3 `View\Calendar`

| Element | 2.11.3 | Booster |
|---|---|---|
| `processSlot( $slot, &$dayArray, &$jsonResponse, &$allLocked, &$noSlots )` (protected) | condenses a slot into the day array | **removed** → `DayFacts::fromGrid()` |
| `getLockedDayArray( Day )` (protected) | day array of a locked day | **removed** → `CalendarWindow::getLockedDayArray()` (public static) |
| `getDefaultCalendarEnddateTimestamp( $startDate )` (private) | `strtotime( 'last day of +3 months', … )` | **removed** → `CalendarWindow::forTimeframes()` |
| `mapDay( $day, $lastBookableDate, $endDate, &$jsonResponse, $firstBookableDay )` (protected) | apply window, build JSON | **new signature** `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )`: evaluates `DayFacts::fromGrid()` only inside the booking window, sets `maxDays` and `startDayOffset`, writes the result of `CalendarWindow::mapDay()` to `days` and the lists `holidays`, `bookedDays`, `lockDays`, `partiallyBookedDays` |
| `getCalendarDataArray()` | window logic inline | same signature; `CalendarWindow::forTimeframes( $bookableTimeframes, new Day( $start ), new Day( $end ), $keepDaterange )` and `renderJsonResponse()` |
| `prepareJsonResponse()` | default values and construction | same signature; creates `new CalendarWindow( … )` and calls `renderJsonResponse()` |
| `renderJsonResponse( CalendarWindow, $locations, $items )` | – | **new, private**; contains the former construction (cache key `serialize( $current_user->roles )`, `new Model\Calendar( … )`, `getWeeks()`, `mapDay()`) |
| `renderTable()` | every row live | with `Availability::isEnabled()` a `Reader`, `preload()` of all (item, location) pairs in advance; `$reader` passed to `renderItemLocationRow()` |
| `renderItemLocationRow( …, $days_display, ?Reader $reader = null )` (protected) | computed `getCalendarDataArray()`, if chain per day | `$reader->getDayStatuses()` or `Reader::evaluateLive()`; markup per status from the constant `TABLE_STATUS_MARKUP` |

**Fixes in `View\Calendar`, also valid without the booster:**

1. **Broken markup of locked days.** The fill loop after the status range wrote
   `"<span'is-locked'></span>"`. Browsers read this as the element `span'is-locked'` **without a class**; the cells
   stayed empty instead of grey, although `.is-locked` exists in `_shortcodeItemsTable.scss`. Now
   `TABLE_STATUS_MARKUP[ DayStatus::LOCKED ]` = `<span class='is-locked'></span>`. Visible: with a 31-day table and 14
   days of advance booking, days 15–31 are grey.
2. **Cache key of the table row.** `$item->ID . $locationId . $today` →
   `$item->ID . '_' . $locationId . '_' . $today . '_' . $days`. Separators and the number of days were missing before:
   item 1 / location 23 and item 12 / location 3 collided, a 31-day table got the row of a 14-day table. The inner cache
   item in `renderItemLocationRow()` that was never found again (key from all arguments, hence never equal) is dropped;
   the caller `renderTable()` caches the row.

Note on `Calendar::getClosestBookableTimeFrameForToday()`: unchanged (public static), called by
`CalendarWindow::forTimeframes()`.

### 7.4 `Map\MapItemAvailable`

| Element | 2.11.3 | Booster |
|---|---|---|
| `create_items_availabilities()` | per pair `Calendar::getCalendarDataArray( item, location, start, end )`, then `markDaysInTimeframe()` | with `Availability::isEnabled()`: one `Reader`, `preload()` of all pairs of the map; per pair `$reader->getDayStatuses( …, false, $from, $to )`, otherwise `Reader::evaluateLive()` (= `getCalendarDataArray()` as before); computes only the **displayed** days `$from … $to` |
| `markDaysInTimeframe( $calendarData, $availabilities )` (protected) | its own if chain | **removed** → `markDays( array $dayStatuses, array $availabilities )` (protected) with the constant `STATUS_MAPPING` (`DayStatus` → `ITEM_LOCKED`, `LOCATION_HOLIDAY`, `ITEM_BOOKED`, `ITEM_PARTIALLY_BOOKED`, `ITEM_AVAILABLE`). Days that are not in the data keep the status "no-timeframe" |

The comment promise of the original ("this logic has to match `processSlot()`, otherwise days are displayed
differently in the plugin") is now kept by shared code instead of by discipline.

---

## 8. Booking flow

| Place | Change | Reason | Without booster |
|---|---|---|---|
| `Plugin::handleBookingForms()` | `Booking::handleFormRequest()` in `Booster::withoutBooster()` | validation never reads index, precomputation or plugin cache | no effect |
| `CustomPostType\Booking` (admin confirmation, `isValid()`) | `Booster::withoutBooster( [ $booking, 'isValid' ] )` | as above | no effect |
| `CustomPostType\Timeframe::validateTimeFrame()` | `Booster::withoutBooster( [ $timeframe, 'isValid' ] )` | conflict check when saving a timeframe | no effect |
| `CustomPostType\Booking::handleFormRequest()` | `handleBookingRequest()` in `withItemLock( (int) $itemId, fn() => … )` | see below | **yes** |
| `CustomPostType\Booking::withItemLock()` | **new, private** | see below | **yes** |
| `Service\BookingRuleApplied` | rule "only one unconfirmed booking" | see below | **yes** |
| `includes\Public.php` | double-click protection | see below | **yes** |

**`withoutBooster()`** increments a counter (`Booster::$bypassDepth`), after which `canRead()` → `false` and thus
`Index::isAvailable()` and `Availability::isEnabled()` → `false`, and runs the callback in `Plugin::withoutCache()`.
Writing to the index continues.

**Item lock `withItemLock()`.** The original validation takes about 3 s with 167,000 bookings. A second click during
that time created a second unconfirmed booking (both requests found the slot free), a third ended with "Found more than
one bookings". The problem already existed in 2.11.3.

```php
$name   = 'cb_booking_' . get_current_blog_id() . '_' . $itemId;
$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 20 ) );
if ( $locked === '0' ) { throw new BookingDeniedException( __( 'Other booking requests for this item are being processed right now. …' ) ); }
// $locked === null: DB without lock support → continue as before
try { return $callback(); } finally { if ( $locked === '1' ) { $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); } }
```

Requests for the same item (and the same site on multisite) run one after another; after 20 s of waiting the user gets
a message. With `itemId = 0` nothing is locked.

**`BookingRuleApplied`.** Before: `Booking::getForCurrentUser( true, null, [ 'unconfirmed' ] )` (all unconfirmed
bookings **visible** to the user, i.e. for location admins also those of their customers), then a filter on
`post_author === current user` and on the current booking. Now:
`Booking::getForUserPaginated( wp_get_current_user(), 1, -1, [], [ 'unconfirmed' ] )` (only own bookings, `WP_Query`,
deliberately without index) and exclusion of the current booking by ID. Same result, without loading all bookings.
Guests (`! is_user_logged_in()`) result in an empty list.

**Double-click protection (`includes/Public.php`).** A script attached via `wp_add_inline_script( 'cb-scripts-public', … )`
intercepts `submit` for `#booking-form` and `[id^=cb-booking-form-set-]`, sets `data-cb-sent`, disables the submit
buttons (except `calendar-download`), shows "Please wait …" and releases the forms again when returning from the
back-forward cache (`pageshow`, `persisted`). The text is translatable (`wp_json_encode( __( 'Please wait …' ) )`).

---

## 9. Cache

`Service\Cache` (trait, used by `Plugin` and other classes):

| Element | 2.11.3 | Booster |
|---|---|---|
| `getCacheId()` | namespace from version, plugin directory, blog ID, class, function, arguments | additionally `'_' . self::getRoleContext()` after the blog ID |
| `getRoleContext()` | – | **new, private.** `'guest'` or the sorted roles of the user plus `|admin` if `commonsbooking_isCurrentUserAdmin()`. Users with the same roles share entries |
| `withoutCache( callable )` | – | **new, public** (via `Plugin::withoutCache`): increments `$cacheBypassDepth`, runs the callback |
| `getCacheItem()` | `if ( WP_DEBUG ) return false;` | `if ( WP_DEBUG \|\| self::$cacheBypassDepth > 0 ) return false;` |
| `setCacheItem()` | – | writes nothing while `$cacheBypassDepth > 0` (returns `false`) |

**Why roles in the key (also without booster).** Timeframes can be restricted to user roles (`allowed_user_roles`).
Map, table, calendar and item page therefore display such items differently per user. The plugin cache did not have
the user in its key so far: a guest could get the cached view of an admin and vice versa. Consequence of the change:
somewhat more cache entries (one entry per role combination).

`withoutCache()` is used by `Booster::withoutBooster()` and by `Calculator::compute()` (computing the precomputation
from the current database state while the cache may still contain values from before a change).

---

## 10. Admin feature "Assigned timeframes"

By decision of the user (2026-11-07) part of the booster update, because it is small, changes no postmeta semantics
and makes finding the timeframes of an item or location practicable for administrators of large instances in the first
place. Background: holidays (type 3) and blockings (type 5) can affect several items/locations via list, category or
"All" (`item-id-list` / `location-id-list`, selection mode in `item-select`); the admin list only filtered the single
field `item-id` / `location-id`, and the column showed `-` for multi-selection.

New classes: `Repository\AssignedTimeframes` (queries, uses `Index::findPostIds()` if available),
`View\Admin\AssignedTimeframes` (meta box, rendering). Changes to existing files:

| File | Change |
|---|---|
| `CustomPostType\Item::__construct()`, `Location::__construct()` | `add_action( 'add_meta_boxes_{type}', … AssignedTimeframes::addMetaBox() )`: meta box "Assigned timeframes" (timeframes **and** restrictions that control the item/location) as the first box of the main column |
| `CustomPostType\Timeframe` (construction/hook part) | same technique: meta box "Timeframes of the same item and location" in the timeframe editor (other timeframes with at least one common item **and** location) |
| `CustomPostType\CustomPostType` (row actions) | for items and locations (not in the trash, with edit permission) action `cb_assigned_timeframes` "Timeframes" with a link to the meta box (`AssignedTimeframes::getLink()`, anchor `#cb-assigned-timeframes`) |
| `CustomPostType\Timeframe::filterAdminList()` | filters "Item" / "Location" of the timeframe list: instead of only `item-id` = value now an `OR` meta query of the single field **and** `LIKE ':"<id>";'` on `item-id-list` or `location-id-list` |
| `CustomPostType\Timeframe::setCustomColumnsData()` | columns "Item" / "Location" for holidays and repairs with multi-selection: `AssignedTimeframes::assignment()` → `assignmentHtml()` ("3 items", "Category: …", "All"), for direct assignment still the old output |

Display rules (display only, no change to storage or calendar): an entry is listed at the item if the item is in the
item selection. "Applies at" is the intersection of the other side of the timeframe with the locations where the item
is bookable. If it is empty and the own side was only chosen implicitly ("All" or category), the entry is hidden; with
an explicit selection it stays with a warning. A holiday or blocking applies to pairs *selected items × selected
locations* (`getEntityQuery()` links both with `INNER JOIN`, every calendar queries with exactly one pair). "All" stores
a snapshot of the IDs (`Timeframe::manageTimeframeMeta()`, updated by `updateAllTimeframes()`).

---

## 11. Minor changes: template, SCSS, frontend script

- `templates/location-calendar-header.php`: the text of the pickup instructions is now in
  `<span class="cb-location-pickupinstructions-text">` (call of `trim()` on `formattedPickupInstructionsOneLine()`),
  without whitespace in the template markup.
- `assets/public/sass/partials/_lists.scss`: `white-space: pre-wrap` was on `.cb-address.cb-location-pickupinstructions`
  and thereby created extra blank lines from the template's whitespace. It is now only on
  `.cb-location-pickupinstructions-text`. Line breaks of the meta field are preserved. **The compiled CSS is not in the
  repository**; after changes to SCSS `npm run dist` is necessary.
- `includes/Public.php`: see section 8.

---

## 12. What was deliberately not changed

To delimit the scope for reviewers:

- **No change to postmeta semantics.** No new meta keys, no changed values, no migration. The tables are created with
  `dbDelta()`, with their own schema version option, without foreign keys and without triggers.
- **Write paths** (`savePost`, meta handlers, `Timeframe::manageTimeframeMeta()`, importer, booking codes) are
  unchanged. The booster only listens (section 13).
- **Unchanged in the original path:** `Repository\Timeframe::getPostIdsByType()`, `getPostsByBaseParams()`,
  `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()`, all `filter*`
  methods, `Repository\Booking::getForUserPaginated()`, `Repository\Item`/`Location`, the REST routes and GBFS classes
  themselves (they benefit via `Model\Calendar::getAvailabilitySlots()`), `Model\Timeframe::isValid()`,
  `Model\Booking::isValid()` and all booking rules except the one mentioned above.
- **Admin lists** still run via `postmeta` (except the extended filter from section 10).
- **`Model\Timeframe::isBookable()`, `getFirstBookableDay()`** and other date-dependent logic stays in the PHP code; the
  precomputation only stores date-independent day facts (to do so it hides the advance booking window with
  `withoutBookingWindow()`, 6.6), and `CalendarWindow`, `Reader` and `SlotReader` apply the date-dependent rules when
  reading.
- **The REST API response formats** (`/availability`, GBFS) stay the same (exceptions: 15.2).

---

## 13. How new components hook into existing flows

All hooks are registered **only with the booster switched on** (exception: `init` priority 0 for the switch and the
settings page in the admin).

### 13.1 WordPress hooks

| Hook | Priority | Class::method | Purpose |
|---|---|---|---|
| `init` | 0 | `Booster::onInit` | detect switching on/off, guard |
| `init` | 1 / 2 | `Index\Schema::maybeInstall`, `Availability\Schema::maybeInstall` | check/extend the table structure |
| `init` | 50 | `Verifier::schedule`, `Maintenance::schedule`, `Guard::schedule` | create cron events |
| `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | 10 | `Indexer::onMetaChange` | relevant key and CB post type → mark the post |
| `transition_post_status` | 10 | `Indexer::onTransitionPostStatus` | mark the post |
| `save_post` | **`PHP_INT_MAX`** | `Indexer::onSavePost` | mark and `flush()` immediately; runs **after** `CustomPostType\Timeframe::savePost()` (priority 11), so the index sees the final state |
| `deleted_post` | 10 | `Indexer::onDeletedPost` | remove the row |
| `shutdown` | 0 | `Indexer::flush` | safety net |
| `shutdown` | 5 | `Diagnostics::writeActivity` | write the activity log |
| `shutdown` | `PHP_INT_MAX` | `Invalidator::onShutdown` | recomputation (≤ 25 pairs, after `fastcgi_finish_request()`) |
| `add_option_`/`update_option_commonsbooking_plugin_version` | 10 | `Guard::onOwnVersionWrite` | distinguish its own version change from a foreign one |
| `admin_init` | 10 | `Booster::workInRequest` | 5 s of setup in the admin request after switching on |
| `admin_post_*`, `wp_ajax_commonsbooking_booster_setup`, `admin_notices` | 10 | `SettingsView` | buttons of the settings page, progress, notice when the setup is stuck |

Relevant meta keys (all others trigger nothing): `type`, `item-id`, `location-id`, `item-id-list`,
`location-id-list`, `repetition-start`, `repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`,
`grid`, `full-day`, `start-time`, `end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`,
`booking-startday-offset`, `allowed_user_roles`, `restriction-*`.

### 13.2 Own actions and filters

| Name | Kind | Meaning |
|---|---|---|
| `commonsbooking_index_rows_changed` | action (`$old`, `$new`, `$postId`) | the projection changed in substance (fingerprint without administrative columns); listened to by `Invalidator` (prio 10), `Diagnostics` (prio 20) |
| `commonsbooking_index_rebuilt` | action | backfill finished; `Invalidator::onIndexRebuilt` computes calendars in a background request |
| `commonsbooking_booster_enabled` | filter | overrides the setting |
| `commonsbooking_use_index` | filter (`true`) | `false`: everything via `postmeta` |
| `commonsbooking_use_availability_cache` | filter (`true`) | `false`: days live, index stays |
| `commonsbooking_availability_horizon_days` | filter (92) | horizon |
| `commonsbooking_availability_max_age` | filter (86400) | maximum age of a row |
| `commonsbooking_availability_sync_limit` | filter (25) | pairs recomputed at the end of the request |
| `commonsbooking_availability_finish_request` | filter (`true`) | `fastcgi_finish_request()` before the recomputation |
| `commonsbooking_availability_refresh_after_rebuild` | filter | compute calendars right after the index build |
| `commonsbooking_availability_access_profiles` / `_max` | filter (`true` / 8) | access profiles per pair |

### 13.3 Cron events

| Hook | Schedule | Task |
|---|---|---|
| `commonsbooking_index_backfill` | schedules itself, 20 s per run | index build |
| `commonsbooking_index_verify` | daily 04:00 | sample of 200 posts index ↔ fresh projection, repairs |
| `commonsbooking_booster_guard` | hourly | deep check of status/modification date, tables present |
| `commonsbooking_availability_refresh` | hourly + on demand | recompute new pairs, changed rows and rows older than 12 h (20 s budget) |
| `commonsbooking_availability_verify` | daily 04:30 | sample of 50 pairs / 50 access profiles |

All of them are removed when switching off and when deactivating the plugin. The setup can additionally be completed
without WP-Cron in the admin's browser (`SettingsView::runSetupStep()`, steps of 8 s each).

### 13.4 Options and WP-CLI

Options: `commonsbooking_booster_state`, `commonsbooking_index_schema_version`, `commonsbooking_availability_schema_version`,
`commonsbooking_index_ready`, `commonsbooking_index_backfill_cursor`, `commonsbooking_index_backfill_lock`,
`commonsbooking_availability_pairs_synced`, `commonsbooking_booster_checks`, `commonsbooking_booster_activity`.
Removal: `wp commonsbooking booster purge`.

WP-CLI: `commonsbooking booster status|enable|disable|rebuild|check [--deep]|purge`,
`commonsbooking index status|rebuild|verify|drop`, `commonsbooking availability status|rebuild|verify|drop`.

---

## 14. Behaviour changes independent of the switch

These changes also apply when the booster is switched off and therefore belong in the changelog:

| Change | Visible as | Place |
|---|---|---|
| item lock when booking | parallel bookings of the same item run one after another; after 20 s the message "Other booking requests …" | `CustomPostType\Booking::withItemLock()` |
| double-click protection | button "Please wait …" | `includes/Public.php` |
| roles in the cache key | somewhat more cache entries; no more foreign views | `Service\Cache` |
| `getForUser()` without full scan | "My bookings" faster, same result | `Repository\Booking` |
| rule "one unconfirmed booking" only over own bookings | same result, faster | `Service\BookingRuleApplied` |
| map loads timeframes, terms, images in bulk | fewer queries | `Model\Map` |
| related posts loaded in bulk | fewer queries | `Repository\BookablePost` |
| restrictions once per calendar, timeframes/restrictions only on a cache miss | 3,000–6,000 fewer queries per map/table call | `Model\Calendar`, `Week`, `Day` |
| map computes only displayed days | less computing time | `Map\MapItemAvailable` |
| table cache key with separators and number of days | no more collisions | `View\Calendar` |
| `is-locked` markup fixed | days beyond the advance booking limit are grey | `View\Calendar` |
| `cancel()` with `clean_post_cache()` | subsequent reads in the same request see "cancelled" | `Model\Booking` |
| pickup instructions without extra blank lines | display | template, SCSS |
| admin: filters and columns for multi-selection, meta boxes, row action | backend | section 10 |
| dashboard: fourth column for admins | backend | section 4.4 |
| new setting "Performance booster" | backend | section 4.2 |
| version `2.11.4-beta.5` | cache key, version display | `commonsbooking.php` |

---

## 15. Deviations from the original behaviour with the booster switched on

### 15.1 Edge cases of the index path (pinned by tests)

| Case | 2.11.3 | Booster |
|---|---|---|
| `repetition-end` present but empty | post dropped out of the query | open end (like `Model\Timeframe::getTimeframeEndDate()`) |
| invalid ID (`'abc'` → `IN (0)`) | matched timeframes with an empty `item-id` (MySQL converts `''` to 0) | matches nothing |

### 15.2 Bugs in the live path of the 2.11.3 API that the booster does not reproduce

`SlotsTest::testKnownLiveDeviations()` pins both; demonstrated on the reference instance with real data.

1. **Restrictions without location filter.** `/availability` builds the calendar without a location. Live,
   `Restriction::get( [], [ $item ] )` then only finds restrictions without a location and misses all with item **and**
   location as well as location-wide ones (item 0). Repair days appear free live and locked with the booster, because
   every pair is computed with its location.
2. **Days after the end date.** The calendar runs until Sunday but live only loads posts up to the end date. A booking
   on the Friday after a Thursday end date is missing live; with the booster it is taken into account.

Without the booster the behaviour of 2.11.3 remains. **Recommendation: report both bugs to the main project**
(section 19.5).

### 15.3 Fallbacks to the live path (no behaviour difference, but slower)

Computed live: `ignoreRestrictions = true` (GBFS `vehicle_status`), calendar days outside the horizon
(`api_future_availability_weeks` > 12), timeframes with multi-selection in the range, role-restricted pairs beyond the
8 access profiles, requests without an item filter.

---

## 16. Changed interfaces

For subclasses, child plugins and third-party code:

| Element | Visibility | Change | Replacement / note |
|---|---|---|---|
| `View\Calendar::processSlot()` | protected | **removed** | `Service\Availability\DayFacts::fromGrid()` |
| `View\Calendar::getLockedDayArray()` | protected | **removed** | `CalendarWindow::getLockedDayArray()` (public static) |
| `View\Calendar::getDefaultCalendarEnddateTimestamp()` | private | **removed** | in `CalendarWindow::forTimeframes()` |
| `View\Calendar::mapDay()` | protected | **new signature** | `mapDay( Day, CalendarWindow, array & )` |
| `View\Calendar::renderItemLocationRow()` | protected | additional optional parameter | `?Reader $reader = null` |
| `Map\MapItemAvailable::markDaysInTimeframe()` | protected | **removed** | `markDays( array $dayStatuses, array $availabilities )` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | public | additional optional last parameter | `?array $possibleRestrictions = null` |
| `Model\Calendar::$timeframes` | protected | `array` → `?array`, loaded in `loadPosts()` | subclasses reading `$this->timeframes` in the constructor see `null` |
| `Model\Calendar::getAvailabilitySlots()` | public | same signature, path via `SlotReader` | `getAvailabilitySlotsLive()` for the old path |
| `Repository\Timeframe::get()`, `getInRange()` | public | same signature and result | – |
| `Repository\Booking::getForUser()` | public | same signature and result | – |
| `Service\Cache::getCacheId()` | public | key contains the role context | stored keys from other sources no longer match (the cache expires with the version anyway) |

New public in existing classes: `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`,
`Repository\Booking::getLatestPastConfirmed()`, `Model\Map::getBookableTimeframesByPair()`, `Plugin::withoutCache()`
(via the trait `Cache`), `Day::RESTRICTION_STATUSES`, `Model\Timeframe::withoutBookingWindow()`.

---

## 17. Consistency and safety

### 17.1 How the index stays up to date

1. Hooks (section 13.1) mark changed posts in a list (`Indexer::$dirty`); marking the same post several times results
   in **one** projection.
2. `Indexer::flush()` projects (`Projector::project()`), each in `try/catch` – an error never aborts the request. It runs
   at the end of `save_post`, in `Model\Booking::cancel()`, **before every read** from the index
   (`Index::findPostIds()`, `Reader::preload()`, `SlotReader`) and on `shutdown`. This way every display in the same
   request sees its own writes ("read your writes").
3. If a projection changes in substance, `commonsbooking_index_rows_changed` fires; `Invalidator` synchronously
   increments the generation `gen` of the affected pairs in `cb_availability`. A row is only read if `computed_gen = gen`.
   Recomputation: up to 25 pairs at the end of the request, otherwise via cron or on the next read. Rows expire after
   24 h at the latest.

### 17.2 Bookings are always validated correctly

See section 8. Tested: the index row of a booking was deliberately deleted (index outdated but considered complete);
booking the same day again was still rejected because the validation uses the original queries.

### 17.3 Downgrade and upgrade

Procedure: switch off the booster, install the older version (it ignores tables, options and cron events), later
install the new version and switch the booster on again; the index is **always** rebuilt completely.

If switching off is forgotten, the guard (`Service\Booster\Guard`) detects the consequences in the data:

| Check | When | Detects | Consequence |
|---|---|---|---|
| plugin version | every request | `commonsbooking_plugin_version` was changed by another version | rebuild |
| new posts without an index row | every request, only IDs above the `watermark`, after 2 min | posts created by another version | rebuild |
| status/modification date | hourly, `booster check --deep` | cancellations/changes by another version, deleted posts | repair individually, rebuild from 200 on |
| tables present | hourly | database restored without the booster tables | create tables, rebuild |

Tested with the official version 2.11.3 as a second plugin (`dev-bench/downgrade-scenario.sh`, scenarios `both` and
`cancel`): index and availability without differences after switching back; booking validation via the form rejected/
accepted as expected.

### 17.4 Limits of the safeguards

Changes bypassing the hooks (direct SQL, importers without `save_post`) are only seen by the index via the guard or the
daily verifier; availability rows expire after 24 h. A pure meta change without saving is only found by the daily
sample. A double booking can never result from this.

---

## 18. Tests, CI, tools

| File | Change |
|---|---|
| `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php` | filter `commonsbooking_booster_enabled` `true` by default, the index is built before the run (`Schema::install()`, `Backfill::runAll()`), state set to "active". Variables `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0` switch parts off |
| `tests/php/BaseTestCase.php` | `setUp()` resets static state of `Store`, `Calculator`, `Invalidator` |
| `tests/php/Model/CalendarTest.php` | expected `Week` objects with the new parameter |
| `tests/php/Model/ItemTest.php` | `testGetClosestBooking_indexMatchesLegacy` |
| `tests/php/Repository/BookingTest.php` | `getForUser()` with managed items/locations |
| `tests/php/View/CalendarTest.php` | `is-locked` markup |
| `tests/php/Wordpress/CustomPostType/BookingTest.php` | rule "one unconfirmed booking" with a managed item |
| `.github/workflows/phpunit.yml` | matrix extended by `use_index`, `use_availability`, `use_booster`; **three additional runs** (PHP 8.1, WP latest): booster off, index off, precomputation off; variables are passed through to `phpunit` |
| `phpstan.neon` | `'#unknown class WP_CLI#'` ignored (no stubs) |
| `phpstan-baseline.neon` | entry `WP_Post::$locked` in `View/Calendar.php` dropped (code removed) |
| `.distignore` | the plugin zip only contains runtime files: excluded are `design/`, `screenshots/`, `phpbench.json`, JS sources, admin SCSS, unminified variants, source maps, `.po`/`.pot`; **not** excluded are `assets/global/sass` and `assets/public/sass` (`View::getColorCSS()` compiles them at runtime) |
| `TECHNICAL.md` | new sections "Performance booster", "Timeframe index", "Precomputed availability" |
| `languages/*` | new texts (de_DE translated); `.pot` regenerated (`wp i18n make-pot`), `.mo` rebuilt. Short single words of the activity log carry a context (`_x( …, 'index activity' )`), because `shortcode-bookings.php` passes filter values (including user names) through `__()` |

**New tests** (selection): `TimeframeIndexParityTest` and `IndexerTest` (index ↔ original path), `CalendarSnapshotTest`
(output against 2.11.3, snapshot `tests/php/View/snapshots/calendar-core.json`), `AvailabilityTest`, `SlotsTest`,
`AccessProfileTest`, `BoosterTest` (switch, bypass during booking validation, guard, `purge`), `SetupTest`,
`DiagnosticsTest`, `CacheRoleContextTest`, `BookingItemLockTest`, `AssignedTimeframesTest`,
`AssignedTimeframesViewTest`, tests with an advanced clock for the advance booking window
(`AvailabilityTest::testBookingWindowMovesWithTheDate`, `SlotsTest::testBookingWindowIsAppliedOnRead`), as well as
`TimeframeVariantsParityTest` with `Fixtures/TimeframeVariants.php` (31 timeframe configurations, compared for guest,
subscriber, editor, two cb_managers and admin). The suite runs in four modes (default, `CB_USE_BOOSTER=0`,
`CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`).

**Measurements** (reference instance with 167,000 bookings and 100 stations, `WP_DEBUG`, without plugin cache):
map data 0.11 s and `[cb_map]` + `[cb_items_table]` 0.10 s (without booster: abort at the memory limit),
`[cb_items]` 0.13 s (without booster 33.6 s), `[cb_locations]` 0.12 s (33.3 s), item page 0.06 s (2.3 s),
`vehicle_availability.json` 0.65 s (without booster HTTP 500). Index build 23.9 s, precomputation of 100 pairs 1.3 s.
Test environment: "Classic views" 213 ms/1,190 queries → 116 ms/48 queries.

---

## 19. Notes for the review, limits, comparison with the design documents

### 19.1 Where a review helps most

1. `Day::isInRestriction()` against `Restriction::queryPosts()` / `queryPostsFromIndex()`: three places reproduce the
   same date condition (SQL, index, PHP filter per day).
2. `Repository\Booking::findViaIndex()`: the whitelist of `$customArgs` is the place where a future new WP_Query
   argument could be silently ignored. Currently every unknown argument leads to the `null` fallback.
3. `DayFacts::fromGrid()` ↔ original `processSlot()`: deliberately taken over peculiarity by peculiarity, secured by
   `CalendarSnapshotTest`. Changes to the day logic belong there in the future, not in `View\Calendar` (then raise
   `Calculator::VERSION` and deliberately re-record the snapshot).
4. `Cache::getRoleContext()`: affects **every** cache key of the plugin.
5. `CustomPostType\Booking::withItemLock()`: uses `GET_LOCK()`; on databases without lock support the code runs as
   before, on shared connections (proxy poolers) the effect depends on the connection semantics.

### 19.2 The booster does not replace the original validation

Everything that accepts or rejects a booking stays in the original path. The speed-up only concerns displays (map,
tables, lists, API, GBFS) and admin listings.

### 19.3 Further known limits

- Repetition and grid columns in `cb_index` are maintained and checked by the verifier, but not yet used by any read
  path (preparation for weekday or grid filters in SQL).
- Plugins like Query Monitor that keep all queries in memory can push the first admin pages after switching on to the
  memory limit; the setup therefore works in slices and stops when more than 60 % of memory is used.

### 19.4 Open points (not part of this change)

| Point | Effect |
|---|---|
| "My bookings" for CB admins | loads all bookings from `startDate`, paginates in PHP |
| booking calendar of the item page | computes live, only benefits from the plugin cache |
| `Item::getClosestBooking()` without booster | still loads all confirmed bookings of the pair (a change to the original path would be `WP_Query` with `orderby`/`posts_per_page`) |
| `Repository\Item::getByCloakedId()` | computes `getCloakedId()` per item |
| `vehicle_status` with `ignoreRestrictions` | stays live |
| admin lists | still via `postmeta` |

### 19.5 Suggestions that could be taken into the main project independently of the booster

These changes are small, independent of the switch and can be extracted individually as PRs:

1. `is-locked` markup fix (`<span'is-locked'>` → `<span class='is-locked'>`) and the cache key fix of the table row.
2. Roles in the cache key (privacy/correctness problem with role-restricted timeframes).
3. Item lock and double-click protection against double bookings.
4. Restrictions once per calendar instead of per day (`Calendar`/`Week`/`Day`).
5. `Booking::getForUser()` without full scan and the leaner rule "one unconfirmed booking".
6. Bulk loading in `Map::get_locations()` and `BookablePost::getRelatedPosts()`.
7. The two API bugs from 15.2 (restrictions without location filter, days after the end date) as issues.
8. Admin filters and columns for multi-selection (section 10).

### 19.6 Comparison with the existing design documents (as of this report)

Reading the diff against `booster-aenderungen-bestehender-dateien.md` and part G of the technical documentation
revealed the following differences:

- Both documents count **38** changed files (as of 2026-11-06). The current diff contains **40** non-new files outside
  `languages/`; newly added are the changes for "Assigned timeframes" to `CustomPostType\CustomPostType`, `Item`,
  `Location` and `Timeframe` as well as `Model\Timeframe` (6.6). The overview in
  `booster-aenderungen-bestehender-dateien.md` does not contain these files; part G of the technical documentation lists
  them in 28.13 but does not yet describe the info box in the timeframe editor (commit `afc3f6ee`,
  `add_meta_boxes_cb_timeframe`).
- `booster-aenderungen-bestehender-dateien.md` refers in its header to the working state "after `8ade45b8`"; the branch
  is now at `0312f3aa`. `src/Model/Timeframe.php` (6.6, commit `bca2700f`) is also missing there; the change is
  described in section 8.4 of the technical documentation.
- The documentation states "no effect with the booster switched off" for `cancel()` via `Schema::isInstalled()`. This is
  only true without tables. If they still exist from an earlier activation, `flush()` keeps projecting into them
  (section 6.3). This is uncritical because every switch-on rebuilds.
- All figures and method descriptions in sections 5–10 were checked against the code and match the technical
  documentation.

---

## 20. Rules for future code

| If you … | then … |
|---|---|
| write a query on timeframes, bookings or restrictions | check `Index::isAvailable()`, offer index and original path, add a parity test |
| change posts of these types via direct SQL | `clean_post_cache( $id )`, `Indexer::touch( $id )`, `Indexer::flush()` |
| introduce a new meta key that affects assignment or availability | key in `Projector::getRelevantMetaKeys()`, if needed a column and `Schema::SCHEMA_VERSION`, raise `Projector::PROJECTOR_VERSION` (triggers a rebuild) |
| change the day logic (slots, holidays, status) | in `DayFacts` / `CalendarWindow` / `DayStatus`, not in `View\Calendar`; raise `Calculator::VERSION`; deliberately re-record the snapshot |
| validate a booking or a timeframe | run it inside `Booster::withoutBooster()` |
| create new classes under `src/` | `composer run prefix-namespaces` (the plugin only loads the Strauss autoloader in `vendor-prefixed/`) |
| test | the suite in all four modes (default, `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`) |

German version: [`booster-bericht-core-entwickler.md`](booster-bericht-core-entwickler.md).
