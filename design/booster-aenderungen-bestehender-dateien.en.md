# Booster: Changes to Existing Files

*Deutsche Fassung: [booster-aenderungen-bestehender-dateien.md](booster-aenderungen-bestehender-dateien.md)*

As of 6 Nov 2026. Comparison of `master` (wielebenwir, `7aa75a81`, corresponds to 2.11.3) with
`chriwen/commonsbooking-booster` branch `booster/index-availability` (working state
after `8ade45b8`, including the change to `Item::getClosestBooking()`).

Only **changed** files (38, 3 of them language files). New files – mainly `src/Service/Index/`, `src/Service/Availability/`,
`src/Service/Booster/`, `src/Repository/Index.php`, new tests and `design/` – are not described here but in
[`technische-dokumentation-booster-02.en.md`](technische-dokumentation-booster-02.en.md) (part G: changes to existing code).

---

## 1. Overview

| Area | Files | What changes | Also effective with the booster switched off? |
|---|---|---|---|
| Wiring | `Plugin.php`, `includes/OptionsArray.php`, `commonsbooking.php` | start/stop the booster, setting, version | switch and setting yes, otherwise no |
| Data access | `Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | queries read from the index instead of `postmeta` | no (original queries), **except** `Booking::getForUser()` |
| Item model | `Model/Item.php` | `getClosestBooking()` (GBFS `vehicle_id`) only loads the candidates with the latest start | no (unchanged without index) |
| Calendar model | `Model/Calendar.php`, `Week.php`, `Day.php` | load restrictions once per calendar, posts only when needed, API slots from the precomputation | yes (loading), slots only with booster |
| Display | `View/Calendar.php`, `Map/MapItemAvailable.php` | day logic moved to a shared core, read from the precomputation | refactoring yes, precomputation no |
| Map | `Model/Map.php` | load timeframes, terms, images for all locations in bulk | yes |
| Booking | `Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `Model/Booking.php`, `Service/BookingRuleApplied.php`, `includes/Public.php` | validation always without booster, item lock, double-click protection | **yes** (lock, double click, rule check) |
| Cache | `Service/Cache.php` | roles in the cache key, `withoutCache()` | **yes** |
| Minor fixes | `templates/location-calendar-header.php`, `_lists.scss` | pickup instructions without extra whitespace | yes |
| Dashboard | `templates/dashboard-index.php` | fourth column with the booster status (admins only) | yes (shows "switched off" and a link to the setting) |
| Tests, CI, docs | `tests/…` (incl. `Model/ItemTest.php`), `.github/workflows/phpunit.yml`, `phpstan*.neon`, `TECHNICAL.md` | booster on in tests, three additional CI runs | – |
| Translations | `languages/*` | new strings (settings page, lock message) | – |

**Guiding principle of the rework:** every converted query first checks `Index::isAvailable()` or
`Availability::isEnabled()`. If the booster is off, not fully built or bypassed via `Booster::withoutBooster()`,
the unchanged original code runs. `wp_posts`/`wp_postmeta` are never written differently than before.

---

## 2. Wiring

**`src/Plugin.php`**
- `activation()` → `Booster::onPluginActivation()` (rebuild the index after a pause), `deactivation()` →
  `Booster::onPluginDeactivation()` (remove cron jobs).
- `init()` → `Booster::init()` registers hooks, cron and WP-CLI, **only when switched on**.
- `handleBookingForms()` runs `Booking::handleFormRequest()` inside `Booster::withoutBooster()` (see 6).

**`includes/OptionsArray.php`** – new group "Performance booster" in *Advanced options*: checkbox
`Booster::SETTINGS_FIELD`, status area via `SettingsView::render` (diagnostics, buttons "Check"/"Rebuild").
Default: off.

**`commonsbooking.php`** – version `2.11.4-beta.3`. The version is part of every cache key, so old cache entries
expire.

---

## 3. Data access (repositories)

Common pattern: the public method stays the same; internally a new private method decides between the
index (`Index::findPostIds()` + `Index::loadPosts()`) and the original query. PHP filters afterwards (weekdays,
`isBookable`, roles, item/location filter) and the cache remain unchanged.

| File | Methods | Change |
|---|---|---|
| `Repository/Timeframe.php` | `get()`, `getInRange()` (and thus `getBookable*`, `getInRangeForCurrentUser`) | new private `queryPosts()`: date and range filters directly in SQL via the index instead of `getPostIdsByType()` (full scan on `postmeta`) + `getPostsByBaseParams()` |
| | new `getByAuthor()` | timeframes/bookings of an author (for `getForUser`), with the index or via SQL on `post_author` |
| `Repository/Booking.php` | `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()`, `getByDate()` | new private `findViaIndex()`; supports `$customArgs` `author`, `post_status`, `orderby=date`, `order`; for anything else `null` → `WP_Query` as before. Sort order as in `WP_Query` (`post_date DESC`) |
| | new `getLatestPastConfirmed( $itemId, $locationId, $now )` | candidates for `Item::getClosestBooking()`: confirmed bookings of the pair that ended before `$now`, with the latest start (two index queries). Without index `null` → the caller uses the original path. Details below in 4a |
| | `getForUser()` | **also without booster:** no longer loads all bookings, only candidates (`getVisibleCandidates()`): own bookings + bookings of managed items/locations (`getManagedPostIds()`: author or `_cb_*_admins`). CB admins still everything. `commonsbooking_isUserAllowedToSee()` still filters, result identical |
| `Repository/Restriction.php` | `get()` | `queryPostsFromIndex()` with `restriction_state = active`, otherwise `queryPosts()` as before |
| `Repository/BookablePost.php` | `getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `getTimeframesByRelation()`: candidates from the index, then filter on the **single** `*-id` value (old semantics). Related posts are loaded in bulk (`_prime_post_caches`), also without the index |

Not converted: admin lists, `Booking::getForUserPaginated()`, all write paths.

---

## 4. Calendar model

**`Model/Calendar.php`**
- Timeframes and **restrictions** are loaded in `loadPosts()`, only when `getWeeks()` does not come from the cache
  (previously in the constructor, even on a cache hit). Restrictions once for the whole period
  (`Restriction::get()` with `minTimestamp`) instead of once per day.
- `getAvailabilitySlots()` (REST `/availability`, GBFS) asks `SlotReader` (precomputation) first and falls back to the
  new public `getAvailabilitySlotsLive()`. Its logic is unchanged; only the slot generation lives in
  `Service\Availability\Slots` (shared with the precomputation).

**`Model/Week.php`, `Model/Day.php`** – new optional last constructor parameter `$possibleRestrictions`.
`Day::getRestrictions()` then filters the preloaded restrictions in PHP (`isInRestriction()`, same
date condition as the SQL query); otherwise the day queries itself as before. New constant
`Day::RESTRICTION_STATUSES`.

**`Model/Item.php`** (4a) – `getClosestBooking()` determines the most recently ended confirmed booking of the item at
its current location; from it the cloaked GBFS `vehicle_id` (`getCloakedId()`) is derived, which changes after every
rental. Previously the method loaded **all** confirmed bookings of the pair as models and filtered in PHP
(`isPast()`); on dev about 1,300 bookings or 10 MB and 95 ms per item; `vehicle_availability.json` aborted with
HTTP 500 at a 128 MB memory limit. New:

```php
$allBookings = Repository\Booking::getLatestPastConfirmed( $this->ID, $location->ID, current_time( 'timestamp' ) );
if ( $allBookings === null ) {          // no index: original path as in 2.11.3
    $allBookings = Repository\Booking::get( [ $location->ID ], [ $this->ID ], null, true, null, [ 'confirmed' ] );
    $allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );
}
// unchanged afterwards: usort by start day, on a tie by start time; array_pop()
```

The index only returns the bookings with the latest start (usually one). The unchanged sorting picks among them
exactly as it previously did among all of them, so the `vehicle_id` stays identical: verified on dev for all 126
items and on the test environment for all 41 items. Result: `vehicle_availability.json` 0.65 s instead of HTTP 500.
Without the booster nothing changes (not even the memory load). Test: `ItemTest::testGetClosestBooking_indexMatchesLegacy`.
In detail: [`technische-dokumentation-booster-02.en.md`](technische-dokumentation-booster-02.en.md), section 15.6.

---

## 5. Display: map and table

**`View/Calendar.php`** (largest change, about 450 changed lines): the day logic, which previously existed twice
(`processSlot()`/`getLockedDayArray()` here, `markDaysInTimeframe()` in the map), now lives in
`Service\Availability\DayFacts`, `CalendarWindow` and `DayStatus`. Output secured by a snapshot test against 2.11.3.
- removed: `processSlot()`, `getLockedDayArray()` (protected), `getDefaultCalendarEnddateTimestamp()` (private);
- `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )` – **changed signature** (protected);
- new `renderJsonResponse()`; `getCalendarDataArray()` and `prepareJsonResponse()` keep their signatures;
- `renderTable()` preloads all pairs via a `Reader` when the booster is active; `renderItemLocationRow()` gets
  an optional `?Reader $reader` and reads the day status through it (otherwise live). Markup per status via
  `TABLE_STATUS_MARKUP`;
- **Fix cache key:** row now `item_location_today_days` (previously without separators and without the number of days,
  so collisions were possible); the inner cache item in `renderItemLocationRow()`, which was never read back, is removed;
- **Fix:** days after the status range get `<span class='is-locked'>` instead of the broken `<span'is-locked'>`.

**`Map/MapItemAvailable.php`** – `create_items_availabilities()` preloads all pairs when the booster is active
(`Reader::preload`), otherwise `Reader::evaluateLive()` (= `getCalendarDataArray()` as before). `markDaysInTimeframe()`
(protected) replaced by `markDays()` with the table `STATUS_MAPPING` (`DayStatus` → map status). Only computes
the displayed days.

**`Model/Map.php`** – `get_locations()` loads items per location, bookable timeframes of **all** pairs (new
`getBookableTimeframesByPair()`, one query instead of one per pair), terms and preview images in bulk. Applies also
without the booster.

---

## 6. Booking flow

| File | Change | Reason |
|---|---|---|
| `Plugin::handleBookingForms()`, `CustomPostType/Booking.php` (admin save), `CustomPostType/Timeframe::validateTimeFrame()` | validation inside `Booster::withoutBooster()` | booking and timeframe validation never read the index, the precomputation or the cache. A stale index can only affect the display, never create a double booking |
| `CustomPostType/Booking.php` | new `withItemLock()`: `GET_LOCK('cb_booking_{blog}_{item}', 20)` around `handleBookingRequest()` | **also without booster:** concurrent requests for the same item are validated and saved one after the other. After waiting 20 s `BookingDeniedException` ("Other booking requests … being processed"). Without lock support in the DB it runs as before |
| `includes/Public.php` | inline script: submit booking forms only once, button shows "Please wait …", released again on back navigation | **also without booster:** a double click created two requests |
| `Model/Booking.php` | `cancel()`: after the direct SQL update `clean_post_cache()` + `Indexer::touch()`/`flush()` | the SQL bypasses the post hooks; without index tables the indexer calls have no effect (`flush()` checks `Schema::isInstalled()`), `clean_post_cache` additionally fixes a stale object cache |
| `Service/BookingRuleApplied.php` | "only one unconfirmed booking": loads only the user's own unconfirmed bookings (`getForUserPaginated`) instead of all visible ones and no longer filters afterwards | **also without booster**, same result |

---

## 7. Cache (`Service/Cache.php`)

- **Role context in the cache key** (`getRoleContext()`: guest, or sorted roles + admin flag).
  Timeframes can be restricted to roles; previously a guest could get an admin's cached view.
  **Also without booster.** Consequence: somewhat more cache entries.
- new `withoutCache( callable )`: reads and writes no cache meanwhile (used when computing the
  precomputation and in `withoutBooster()`).

---

## 8. Minor fixes

- `templates/location-calendar-header.php` + `assets/public/sass/partials/_lists.scss`: pickup instructions in their own
  `<span class="cb-location-pickupinstructions-text">`, `white-space: pre-wrap` only there. Previously the
  whitespace of the template markup produced extra blank lines.

---

## 9. Tests, CI, tools

- `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php`: booster **on** by default, the index is built before the
  run. Environment variables `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`.
- `tests/php/BaseTestCase.php`: resets static state of `Store`, `Calculator`, `Invalidator`.
- `tests/php/Model/CalendarTest.php`: expected `Week` objects with the new parameter.
- New test cases in `Repository/BookingTest.php` (`getForUser`), `View/CalendarTest.php` (is-locked),
  `Model/ItemTest.php` (`getClosestBooking` index against original path),
  `CustomPostType/BookingTest.php` (rule "one unconfirmed booking" with a managed item).
- `.github/workflows/phpunit.yml`: three additional runs (PHP 8.1, WP latest) with booster off, without index, without
  precomputation.
- `phpstan.neon`: `WP_CLI` ignored (no stubs); `phpstan-baseline.neon`: one entry for `View/Calendar.php`
  removed (code removed).
- `.distignore`: the plugin zip only contains runtime files (4.8 → 2.7 MB). Additionally excluded: `design/`,
  `screenshots/`, `phpbench.json`, JS sources (`assets/*/js/src/`), admin SCSS, unused unminified variants
  (`leaflet-src*.js`, `leaflet.markercluster-src.js`, `leaflet.spin.js`, `jquery.overscroll.js`, `vendor.min.js`),
  source maps, `.po`/`.pot` (`.mo` is loaded) and example and tool files of the libraries. `assets/global/sass` and
  `assets/public/sass` must stay (`View::getColorCSS()` compiles them at runtime), as must the `.js`/`.min.js` pairs
  the debug mode chooses between, and the license files.
- `TECHNICAL.md`: new sections "Performance booster", "Timeframe index", "Precomputed availability".
- `languages/*`: strings of the settings page, "Please wait …", message of the item lock (de_DE translated).

---

## 10. Notes for developers and reviewers

**Behaviour changes that apply regardless of the switch:**
item lock when booking, double-click protection, roles in the cache key, `getForUser()` without full scan, rule check
of own bookings only, bulk loading of the map, restrictions once per calendar, table cache key,
`is-locked` markup (days beyond the advance booking limit now grey), pickup instructions.

**Changed interfaces (for subclasses or third-party code):**

| Element | Change |
|---|---|
| `View\Calendar::mapDay()` (protected) | new signature |
| `View\Calendar::processSlot()`, `getLockedDayArray()` (protected) | removed |
| `View\Calendar::renderItemLocationRow()` (protected) | additional optional parameter |
| `Map\MapItemAvailable::markDaysInTimeframe()` (protected) | removed, replaced by `markDays()` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | additional optional parameter |
| `Model\Calendar` | `$timeframes` now `?array`, loading in `loadPosts()` |
| new public | `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`, `Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Cache::withoutCache()`, `Day::RESTRICTION_STATUSES` |

**Anyone who changes posts via direct SQL** (like `Model\Booking::cancel()`) must call `Indexer::touch( $id )` and
`Indexer::flush()`; otherwise the index only sees the change via the guard or the daily check.

**Downgrade:** switch the booster off first. The existing tables are not changed; the additional tables
remain until `wp commonsbooking booster purge` runs.
