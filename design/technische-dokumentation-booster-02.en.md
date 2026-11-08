# Technical Documentation: Performance Booster (Version 2)

*Deutsche Fassung: [technische-dokumentation-booster-02.md](technische-dokumentation-booster-02.md)*

As of: 7 Nov 2026 · Branch `booster/index-availability` · Baseline: CommonsBooking 2.11.3 (`master`, `7aa75a81`)
· Plugin version of the branch: `2.11.4-beta.4`

This version merges the earlier documents into one coherent text and checks them against the code:

| Source | What was taken from it |
|---|---|
| [`booster.en.md`](booster.en.md) | switch, guard, downgrade, diagnostics, cache measurements, double-booking protection |
| [`timeframe-index.en.md`](timeframe-index.en.md) | design, decisions and measurements of the index (stage 2, phases 1–5) |
| [`availability-precompute.en.md`](availability-precompute.en.md) | design, decisions and measurements of the precomputation (stage 3, phases A–E) |
| [`role-restricted-availability.en.md`](role-restricted-availability.en.md) | access profiles for role-limited pairs (8.8) |
| [`booster-aenderungen-bestehender-dateien.en.md`](booster-aenderungen-bestehender-dateien.en.md) | every changed existing file |

Where the design notes differ from the code, this document describes the code. The design notes remain as a
historical record (design, phases, intermediate measurements).

**Audience:** developers who know CommonsBooking 2.11.3 or want to learn it, reviewers of the fork, and administrators
of large instances who want to understand what happens when the booster is switched on.

**How to read:** Part A explains the idea without prior knowledge. Part B describes the tables down to columns and
bytes. Parts C and D show step by step what happens on writing and on reading, each with concrete scenarios. Part E
covers safety and consistency, Part F operations, Part G all changes to existing files.

---

## Contents

- **Part A – Concept**
  1. Starting point: how CommonsBooking stores data and why it gets slow
  2. Goals and guiding principles
  3. Two stages and one switch
  4. The example world of this document
  5. Architecture and components
- **Part B – Data model**
  6. `cb_index`
  7. `cb_index_rel`
  8. `cb_availability` and `cb_availability_access`
  9. Options, cron events, filters and actions
- **Part C – Write paths**
  10. Maintaining the index: Indexer and Projector
  11. Maintaining availability: Invalidator, Calculator, Store
  12. Rebuild (backfill) and maintenance
  13. Write scenarios end to end
- **Part D – Read paths**
  14. Which path runs? The decision chain
  15. Reading the index: `Repository\Index` and the converted repositories
  16. Reading availability: `Reader` for map and table
  17. Reading slots: `SlotReader` for REST API and GBFS
  18. Read scenarios end to end
- **Part E – Safety and consistency**
  19. Booking validation always uses the original path
  20. Guard, verifier and maximum age
  21. Downgrade and upgrade
  22. Interaction with the plugin cache
- **Part F – Operations**
  23. Switch and lifecycle
  24. WP-CLI, diagnostics, filters
  25. Measurements
  26. Tests and CI
- **Part G – Changes to existing code**
  27. Overview
  28. File by file: before and now
  29. Removed, restructured and new methods
  30. Behaviour changes that apply without the booster too
- **Part H – Limits and outlook**
  31. Deliberate deviations, limits, open points
  32. Developer checklist
  33. Glossary

---

# Part A – Concept

## 1. Starting point: how CommonsBooking stores data and why it gets slow

### 1.1 The domain objects

CommonsBooking lends **items** (e.g. a cargo bike) at **locations** (e.g. a bakery that hands out the bike). What is
possible when is described by three kinds of entries:

| Object | Post type | Type number (meta `type`) | Example |
|---|---|---|---|
| Opening hours | `cb_timeframe` | 1 | (rarely used) |
| bookable timeframe | `cb_timeframe` | 2 | “Cargo bike Emil at the bakery, Mon–Fri full day, from 1 Jan 2026” |
| holiday / closed | `cb_timeframe` | 3 | “all locations closed 24–26 Dec” |
| off-holidays | `cb_timeframe` | 4 | (special form of holiday) |
| repair / blocking | `cb_timeframe` | 5 | “Emil is blocked 1–3 Dec” |
| booking | `cb_booking` | 6 | “user 17 borrows Emil on 10 Nov” |
| cancelled booking (legacy) | `cb_booking` | 7 | legacy data |
| restriction | `cb_restriction` | (no `type`) | “Emil broken from 10 Nov”, kind `repair` or `hint` |

How available an item is on a given day only emerges from the **interplay**: a bookable timeframe opens time windows;
holidays, repairs, restrictions and bookings close them again.

### 1.2 The storage format: posts and key-value pairs

CommonsBooking is a WordPress plugin and stores all three object kinds as **posts**. Every timeframe, booking and
restriction is one row in `wp_posts`. That row only knows generic fields (ID, title, status, author, date). All
domain data lives in `wp_postmeta`, **one row per value**:

```
wp_posts      ID 9301 · post_type cb_booking · post_status confirmed · post_author 17

wp_postmeta   post_id  meta_key            meta_value
              9301     type                6               (booking)
              9301     item-id             812
              9301     location-id         455
              9301     repetition-start    1794268800      (10 Nov 2026 00:00)
              9301     repetition-end      1794355199      (10 Nov 2026 23:59:59)
              9301     _cb_bookingcode     …               (and a few more)
```

In the test data set the booster was developed on, 17,100 timeframes and bookings came with about 190,000 meta rows,
roughly eleven per post. The development environment with ten times the history has 167,000 posts and 1.84 million
meta rows.

### 1.3 Problem 1: searching

Almost every view essentially asks the same question: *Which timeframes and bookings concern item 812 at location 455
in the next twelve days?* The map asks it for every item/location pair, the item table as well, the booking calendar
for one item, the REST API and GBFS for all of them.

`wp_postmeta` has no “item” or “end” column the database could put an index on. CommonsBooking 2.11.3 therefore
answers the question in two SQL stages (`Repository\Timeframe::getPostIdsByType()` and `getPostsByBaseParams()`):

```sql
-- Stage 1: all posts of the type for item and location – WITHOUT a date condition
SELECT DISTINCT pm1.post_id FROM wp_postmeta pm1
  JOIN wp_postmeta pm2 ON pm2.post_id = pm1.post_id AND (  (pm2.meta_key = 'item-id' AND pm2.meta_value IN (812))
                                                        OR (pm2.meta_key = 'item-id-list' AND …) )
  JOIN wp_postmeta pm3 ON …  -- same for location-id / location-id-list
 WHERE pm1.meta_key = 'type' AND pm1.meta_value IN (2,3,5,6,…)

-- Stage 2: date filter via further meta joins, on exactly this ID list
SELECT DISTINCT pm1.* FROM wp_posts pm1
  JOIN wp_postmeta pm4 ON pm4.post_id = pm1.id AND ( (pm4.meta_key = 'repetition-end' AND pm4.meta_value >= …)
                                                  OR pm1.id NOT IN (SELECT post_id FROM wp_postmeta WHERE meta_key = 'repetition-end') )
 WHERE pm1.id IN ( …all IDs from stage 1… ) AND pm1.post_type IN (…) AND pm1.post_status IN (…)
```

Stage 1 returns **all** posts of the pair, including every booking of past years. If Emil was booked 600 times, the
`IN` list of stage 2 contains 600 IDs, and MySQL has to check each one via `postmeta` to see whether it falls into the
period. The effort grows with the **entire history**, not with what is displayed. With several hundred pairs (map) and
several queries per pair this added up to tens of thousands of database queries.

On top of that came single loads: `get_post()` per post (more than 17,000 queries in the map scenario) and
`Restriction::get()` once **per day** and pair (3,000–6,000 queries per map or table request).

### 1.4 Problem 2: computing

Even once the matching posts are found, the answer “free or taken?” is not stored anywhere. For every day this runs:

```
Model\Calendar ─► Week ─► Day::getGrid()          grid of time sections (“slots”); per slot the timeframe with the
                                                   highest priority (booking and holiday beat “bookable”)
               ─► View\Calendar::processSlot()     (2.11.3) condenses the slots into a day state
               ─► View\Calendar::mapDay()          applies advance booking, first bookable day, past
               ─► MapItemAvailable / renderTable   derives the status for map or table
```

This costs about 7 ms per pair for 31 days and a little over 10 ms for 90 days – on every request of every visitor, for
every pair. The map also computed 60 days although it only shows 12.

### 1.5 Result in numbers (2.11.3)

| View | test data (17,100 posts) | development (167,000 posts) |
|---|---|---|
| map with availability | 15.4 s, 40,800 queries | aborted (memory) |
| `[cb_items_table]` | 7.05 s | aborted (memory) |
| map, locations only | 5.37 s | 49.96 s |
| `[cb_items]` | 2.78 s | 25.41 s |
| GBFS `vehicle_availability.json` | – | HTTP 500 (128 MB memory limit) |

---

## 2. Goals and guiding principles

### 2.1 Goals

1. Map, list and API views of large instances should answer in tenths of a second, **independent of the length of the
   booking history**.
2. After every booking the display must be correct **immediately**. A nightly rebuild is explicitly not enough (user
   requirement).
3. The booster must be **switchable** and **downgrade-safe**: switching it off or going back to 2.11.3 must produce
   neither wrong availability nor double bookings; neither may upgrading again.

### 2.2 Guiding principles

| # | Principle | What it means concretely |
|---|---|---|
| 1 | **Posts remain the single source of truth.** | `wp_posts` and `wp_postmeta` are written and read exactly as in 2.11.3. Admin, CMB2, REST, GBFS, booking codes, privacy export and third-party hooks are untouched. The new tables are derived and can be rebuilt at any time. |
| 2 | **Off by default.** | After the update: no hooks, no cron jobs, no reading from new tables. Behaviour as in 2.11.3. |
| 3 | **Two paths, one result.** | Every converted method keeps its original path. A central check decides which one runs. Parity tests compare both. |
| 4 | **Booking validation never uses the booster.** | Overlap, booking rules and timeframe validation always run with the original queries and without the plugin cache. A stale index can at most falsify the display, never allow a double booking. |
| 5 | **Up to date immediately.** | Index and validity of the precomputation change in the same request in which a post changes. |
| 6 | **Own tables and options only.** | No change to existing tables or to the meaning of existing postmeta. Downgrade = tables stay and are ignored. |

---

## 3. Two stages and one switch

### 3.1 The idea: the same data additionally in a read-friendly form

Everyone knows the principle from a non-fiction book: the **index** at the back contains no new information, everything
is in the text too. But it is ordered so that you find a passage in seconds. When the text is revised, the index must
follow – and when in doubt, the text wins.

The booster creates two such “indexes”. They are called *derived data*, *read models* or *materialised views*:

```
 Source of truth                 stage “index”                        stage “precomputation”
 (unchanged)                     solves searching                     solves computing

 wp_posts + wp_postmeta   ──►   cb_index (+ cb_index_rel)      ──►   cb_availability
 1 post =                        1 post = 1 row                        1 pair (item, location) = 1 row
 1 post + ~11 meta rows          with fixed, indexed columns           with the finished result
                                 (item, location, type,                “what does each day look like?” and
                                 start, end, status, author …)          “which time windows are free?”
                                                                       for yesterday to today + 92 days
```

- **Index (mirror):** contains nothing that is not also in the posts, only sorted so the database jumps straight to
  the right place.
- **Precomputation (result store):** contains what CommonsBooking used to compute on every request, computed once and
  stored.
- **Booster (switch):** turns both on and off together, protects against inconsistencies (guard), shows the state in
  the settings and offers WP-CLI commands.

In the design notes the stages are called “stage 2” (index) and “stage 3” (precomputation); “stage 1” was the quick
win of only computing the displayed days, which was absorbed into stage 3.

### 3.2 What each stage brings

| View (development data, 167,000 posts) | 2.11.3 | index only | index + precomputation |
|---|---|---|---|
| map with availability (100 pairs) | 15.4 s (at 1/10 of the data) | 1.14 s | **0.11 s** |
| `[cb_items_table]` | 7.05 s (at 1/10 of the data) | 0.74 s | **0.08 s** |
| map, locations only | 49.96 s | 0.09 s | 0.09 s |
| `[cb_items]` | 25.41 s | 0.09 s | 0.09 s |

The index alone makes every view independent of the history. What remains is pure computing time for the days; the
precomputation removes that.

### 3.3 Four rules everything builds on

1. **The derivation is deterministic and repeatable.** The same posts always yield the same rows. Every row can be
   deleted and recreated; checks compare stored rows with freshly computed ones.
2. **Only what does not depend on today's date is stored.** Past, advance-booking window and first bookable day are
   applied on **reading**. A row stays valid across midnight.
3. **Invalidate immediately, recompute later.** A change increments a counter (`gen`) in the same request; a row with a
   different counter is never used. When recomputation happens only affects speed.
4. **When in doubt, the source.** Where derived data is missing or does not fit, the original path computes. Bookings
   are always decided by the original path.

---

## 4. The example world of this document

All examples refer to this data. Today is **Friday, 6 Nov 2026**. Timestamps are, as usual in CommonsBooking, *local
time as a Unix timestamp* (00:00 local time is stored as if it were 00:00 UTC).

| Role | Object | ID | Properties |
|---|---|---|---|
| item | cargo bike “Emil” | 812 | |
| item | cargo bike “Paula” | 813 | |
| item | trailer “Toni” | 814 | bookable by the hour |
| location | Sonnenschein bakery | 455 | admin: user 23 (`_cb_location_admins`) |
| location | neighbourhood shop | 456 | |
| location | repair café | 457 | |
| bookable timeframe | Emil @ bakery | 501 | Mon–Fri, full day, from 1 Jan 2026, open end, 30 days advance booking |
| bookable timeframe | Paula @ bakery | 502 | daily, full day, from 1 Mar 2026, open end, 2 days lead time (`booking-startday-offset`) |
| bookable timeframe | Toni @ neighbourhood shop | 503 | Mon–Sat, hourly 08:00–18:00 |
| bookable timeframe | Paula @ repair café | 504 | bookable only for role “member” (`allowed_user_roles`) |
| holiday | Christmas | 620 | 24–26 Dec 2026, items “all” (812, 813, 814), locations 455, 456, 457 |
| restriction | Emil broken | 7004 | `repair`, active, from 10 Nov 2026, item 812, location **0 = all** |
| booking | Emil, Tue 10 Nov, full day | 9301 | `confirmed`, user 17 |
| booking | Emil, 13–16 Nov | 9302 | `canceled` |
| booking | Emil, Wed 4 Nov | 9295 | `confirmed`, already past |
| booking | Toni, Mon 9 Nov, 10–12 h | 9310 | `confirmed`, user 31 |

Useful timestamps:

| Date (00:00 local time) | Timestamp |
|---|---|
| 1 Jan 2026 | 1767225600 |
| Thu 5 Nov 2026 | 1793836800 |
| Fri 6 Nov 2026 | 1793923200 |
| Mon 9 Nov 2026 | 1794182400 |
| Tue 10 Nov 2026 | 1794268800 (end of day: 1794355199) |
| Tue 17 Nov 2026 | 1794873600 (end of day: 1794959999) |
| Thu 24 Dec 2026 | 1798070400 |
| Sat 26 Dec 2026 | 1798243200 (end of day: 1798329599) |

---

## 5. Architecture and components

### 5.1 Big picture

Detailed diagram with all methods: [`booster-architektur.png`](booster-architektur.png)
([SVG](booster-architektur.svg)).

```
                       WRITE PATH                                              READ PATH
 ┌──────────────────────────────────────────────┐        ┌──────────────────────────────────────────────┐
 │ WordPress: save_post, *_post_meta,           │        │ Repository\Timeframe, Booking, Restriction,  │
 │ transition_post_status, deleted_post         │        │ BookablePost, Model\Map, Model\Item          │
 │ CommonsBooking: Model\Booking::cancel()      │        └───────────────────┬──────────────────────────┘
 └──────────────────┬───────────────────────────┘                            │ Index::isAvailable()?
                    │ post ID “dirty”                                         │  yes → Repository\Index (cb_index)
                    ▼                                                         │  no  → original query (postmeta)
        Service\Index\Indexer ──flush()──► Projector ──REPLACE──► cb_index / cb_index_rel
                                               │
                                               │ action commonsbooking_index_rows_changed (old + new row)
                                               ▼
                               Service\Availability\Invalidator
                                   │ UPDATE gen = gen + 1   (immediately, in the same request)
                                   ▼
                              cb_availability ◄── Calculator::recompute()
                                   ▲              (≤ 25 pairs at request end, otherwise cron; or on reading)
                                   │ Store::isUsable()?  yes → days / slots
                                   │                     no  → compute and store, or live
                                   │ role-limited? → cb_availability_access per access profile (8.8)
                ┌──────────────────┴───────────────────────┐
     Service\Availability\Reader                 Service\Availability\SlotReader
     ◄── MapItemAvailable (map)                  ◄── Model\Calendar::getAvailabilitySlots()
     ◄── View\Calendar::renderTable()                (REST /availability, GBFS station/vehicle status,
         ([cb_items_table])                           GBFS vehicle availability)

 Outside the booster (always original path, without cache): booking form, admin confirmation,
 validation when saving a timeframe  →  Booster::withoutBooster()
```

### 5.2 Components by namespace

| Namespace / class | Task | Part |
|---|---|---|
| `Service\Booster\Booster` | switch, state, enable/disable, `withoutBooster()`, `purge()` | F |
| `Service\Booster\Guard` | guard against changes made without the booster (version, new posts, status/date) | E |
| `Service\Booster\Diagnostics`, `SettingsView`, `Cli` | diagnostics on the settings page, activity log, WP-CLI | F |
| `Service\Index\Schema` | create, extend, drop the tables `cb_index`, `cb_index_rel` | B |
| `Service\Index\Indexer` | hooks, collecting changed posts, `flush()` | C |
| `Service\Index\Projector` | post → index row; reports old and new row | C |
| `Service\Index\Backfill` | full build in batches (cron/CLI) | C |
| `Service\Index\Verifier` | sample index ↔ fresh projection, repairs | E |
| `Repository\Index` | read access: `isAvailable()`, `findPostIds()`, `loadPosts()`, `sortByDate()` | D |
| `Service\Availability\Availability` | switch of the precomputation, horizon, maximum age | D |
| `Service\Availability\Schema` | tables `cb_availability` and `cb_availability_access` | B |
| `Service\Availability\Store` | sole access to `cb_availability`: load, check, write, invalidate | B/C |
| `Service\Availability\Invalidator` | determine affected pairs, increment `gen`, schedule recomputation | C |
| `Service\Availability\Calculator` | compute one pair over the horizon (day facts + slots), per access profile if role-limited | C |
| `Service\Availability\AccessProfile` | access profile of the user for a pair, allowed roles per pair, computing as a profile (`runAs()`) | B/D |
| `Service\Availability\AccessStore` | sole access to `cb_availability_access`: load, check, write, clean up | B/C |
| `Service\Availability\Maintenance` | cron: new pairs, outdated rows, cleanup of access profiles, sample | C/E |
| `Service\Availability\DayFacts` | computation core: slot grid → 10 bits | B |
| `Service\Availability\CalendarWindow` | computation core: date-dependent window on reading | D |
| `Service\Availability\DayStatus` | computation core: status of a day for map and table | D |
| `Service\Availability\Reader` | reading for map and item table | D |
| `Service\Availability\Slots`, `SlotReader` | store free time windows and read them for API/GBFS | B/D |

After adding new classes under `src/`, `composer prefix-namespaces` is required: the plugin only loads the Strauss
autoloader in `vendor-prefixed/`.

---

# Part B – Data model

All tables are created per site via `$wpdb->prefix` (multisite-capable), created with `dbDelta()` and carry their own
schema version option. There are no foreign keys and no triggers (which would require special privileges on many
hosts); maintenance happens in PHP via hooks. The tables are created when the booster is switched on and removed only
with `wp commonsbooking booster purge`.

```
 wp_posts (cb_timeframe, cb_booking, cb_restriction)
   │ 1 : 1   post_id
   ▼
 cb_index ──────────── 1 : n ────────────► cb_index_rel
   │ item_id, location_id                    (only posts with is_multi = 1:
   │ (pairs = bookable timeframes,            one row per item and location)
   │  type 2, publish)
   │ n : 1
   ▼
 cb_availability   PRIMARY (location_id, item_id)
   │ 1 : n   (only pairs with has_role_limit = 1)
   ▼
 cb_availability_access   PRIMARY (location_id, item_id, access_profile)
```

| | `cb_index` | `cb_index_rel` | `cb_availability` | `cb_availability_access` |
|---|---|---|---|---|
| one row per | post | item or location of a post with multiple selection | pair with a bookable timeframe | role-limited pair and access profile |
| order of magnitude (17,100 posts, 300 pairs) | ≈ 17,100 | a few hundred | ≈ 300 | 2 per role-limited pair, at most 8 |
| content | facts from `wp_posts`/`postmeta`, typed | assignments | computed day facts + free slots | the same for one access profile |
| written by | `Index\Projector` | `Index\Projector` | `Availability\Store` | `Availability\AccessStore` |
| schema class, version | `Index\Schema`, 3 | `Index\Schema`, 3 | `Availability\Schema`, 3 | (the same) |
| version option | `commonsbooking_index_schema_version` | (the same) | `commonsbooking_availability_schema_version` | (the same) |
| on structural change | `dbDelta` extends, rows stay valid | like `cb_index` | table is dropped and recreated (pure cache) | like `cb_availability` (both together) |
| on changed derivation logic | increment `Projector::PROJECTOR_VERSION` (2) → rebuild | like `cb_index` | increment `Calculator::VERSION` (2) → old rows invalid | like `cb_availability` |

Two rules for data types: whatever is searched or compared is a number (type, IDs, timestamps). And `NULL` has a fixed
meaning in every column, so the SQL conditions reproduce the old `postmeta` SQL exactly.

---

## 6. `{prefix}cb_index`: one row per post

### 6.1 What goes in

One row for every post of type `cb_timeframe`, `cb_booking` and `cb_restriction` in **every** status except
`auto-draft` – including `draft`, `trash` and `canceled`. Statuses are only filtered on reading. There are two reasons:
a status change only changes one column, and the guard can detect cancelled or deleted posts.

The row is **not an interpretation**. The projector computes no days, no availability and no bookability. It
translates meta values into columns and normalises only where the original code also normalises on reading (e.g.
`strtotime()` for legacy values). Whether a timeframe applies on a Wednesday or whether a user may book it is still
decided by the existing PHP code after the posts have been loaded.

### 6.2 Columns

**Identity and status** (from `wp_posts`)

| Column | Type | NULL / default | Meaning |
|---|---|---|---|
| `post_id` | bigint unsigned, **PK** | – | `wp_posts.ID` |
| `post_type` | varchar(20) | – | `cb_timeframe`, `cb_booking`, `cb_restriction` |
| `post_status` | varchar(20) | – | `publish`, `draft`, `pending`, `private`, `trash`; bookings also `confirmed`, `unconfirmed`, `canceled` |
| `post_author` | bigint unsigned | 0 | for bookings the user **for whom** the booking was made (booking rules, “My bookings”) |

**Type and assignment** (metas `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`; for restrictions
`restriction-item-id`, `restriction-location-id`)

| Column | Type | NULL / default | Meaning |
|---|---|---|---|
| `type` | tinyint unsigned | 0 | 1–7 as in 1.1; **0 for restrictions** (they have no `type` meta) |
| `item_id` | bigint unsigned | **NULL** with multiple selection or without item | timeframe/booking: the single item. Restriction: item or **0 = all** |
| `location_id` | bigint unsigned | like `item_id` | like `item_id`, for the location |
| `is_multi` | tinyint(1) | 0 | 1 if, after merging `*-id` and `*-id-list`, more than one item **or** more than one location remains; assignment then in `cb_index_rel` |

**Period** (`repetition-start`/`-end` or `restriction-start`/`-end`)

| Column | Type | NULL means | Rules |
|---|---|---|---|
| `start_ts` | bigint (signed) | **no start stored** | numbers unchanged, text via `strtotime()`. `0` is a valid start. Criteria `start_min`/`start_max` exclude NULL – like the old SQL, which required the meta key |
| `end_ts` | bigint (signed) | **open end** | `''`, `0`, `'0'` and missing meta → NULL (like `Model\Timeframe::getTimeframeEndDate()`). Queries use `(end_ts IS NULL OR end_ts >= :from)` |

**Repetition and grid** (timeframes and bookings only)

| Column | Type | Source | Meaning, example |
|---|---|---|---|
| `repetition` | varchar(8), NULL | `timeframe-repetition` | `norep`, `d`, `w`, `m`, `y`, `manual` |
| `weekdays` | tinyint unsigned | `weekdays` (`'1'`…`'7'`, Mon = 1) | bit mask Mon = bit 0 … Sun = bit 6: Mon–Fri = 31, Mon–Sat = 63, Sat+Sun = 96, all = 127 |
| `manual_dates` | text, NULL | `timeframe_manual_date` | JSON array, only for `manual`, e.g. `["2026-12-01","2026-12-08"]` |
| `grid` | tinyint unsigned | `grid` | 0 = slot (one window start–end), 1 = hourly |
| `full_day` | tinyint(1) | `full-day` | 1 if meta = `on` |
| `start_time`, `end_time` | time, NULL | `start-time`, `end-time` (`8:00 AM`, `18:00` …) | normalised to `H:i:s` |
| `grid_size` | tinyint unsigned, NULL | derived like `Model\Timeframe::getGridSize()` | full day 24; slot = hours between start and end (rounded, ≤ 255); otherwise the value of `grid`; NULL if a time is missing for a slot |

These columns are currently not evaluated by **any read path**: after the search, the code loads the `WP_Post` objects
with their meta in bulk and uses the existing models. They are still maintained and checked by the verifier. They make
the row “covering” and allow weekday or grid filters in SQL later without another schema change and without a second
backfill.

**Booking rules** (timeframes only)

| Column | Type | Source | Meaning |
|---|---|---|---|
| `max_days` | smallint unsigned, NULL | `timeframe-max-days` | maximum booking duration |
| `advance_days` | smallint unsigned, NULL | `timeframe-advance-booking-days` | advance-booking window |
| `startday_offset` | smallint unsigned, NULL | `booking-startday-offset` | earliest booking day from today |
| `has_role_limit` | tinyint(1) | `allowed_user_roles` | 1 = bookable only for certain roles; such pairs are stored per access profile (8.8) |

Numbers are clamped to 0…65535, non-numeric values become NULL (“not set”). Evaluation stays in the PHP code (e.g.
0 advance-booking days count as “not set” there).

**Restrictions** (`cb_restriction` only)

| Column | Type | Source | Meaning |
|---|---|---|---|
| `restriction_type` | varchar(10), NULL | `restriction-type` | `repair` (blocks), `hint` (notice only) |
| `restriction_state` | varchar(10), NULL | `restriction-state` | `none`, `active`, `solved`; only `active` is read |

**Bookkeeping** (not part of the comparison whether a projection changed)

| Column | Type | Meaning |
|---|---|---|
| `source_modified_gmt` | datetime, NULL | `post_modified_gmt` at the time of projection. The guard compares it hourly with `wp_posts` |
| `projector_version` | smallint unsigned | `Projector::PROJECTOR_VERSION` (currently 2) |

### 6.3 Item/location assignment

The projector forms the **union** of all values of `item-id` (including duplicate meta rows) and the list
`item-id-list`, removes values ≤ 0 and duplicates, and sorts. The same for locations. This matches the old SQL in
`Repository\Timeframe::getEntityQuery()`, which combined both metas with `OR`. (The model getters prefer only the
list; the union is chosen deliberately to reproduce the SQL.)

| Result | `item_id` | `location_id` | `is_multi` | `cb_index_rel` |
|---|---|---|---|---|
| one item, one location (bookings, bookable timeframes, > 99 %) | ID | ID | 0 | – |
| no item or no location (incomplete draft) | NULL | NULL / ID | 0 | – |
| more than one item **or** location (holidays, repairs with list, category, “all”) | NULL | NULL | 1 | one row per item and location |
| restriction | ID or **0 = all** | ID or **0 = all** | 0 | – |

Why “0 = all” in the same column? The query then reads `item_id IN (0, :item)`. That stays indexable and reproduces
the four cases of `Restriction::filterPosts()` exactly.

### 6.4 Example rows

| post_id | post_type | post_status | type | item_id | location_id | is_multi | start_ts | end_ts | other |
|---|---|---|---|---|---|---|---|---|---|
| 501 | cb_timeframe | publish | 2 | 812 | 455 | 0 | 1767225600 | NULL | `repetition = w`, `weekdays = 31`, `full_day = 1`, `grid_size = 24`, `advance_days = 30` |
| 502 | cb_timeframe | publish | 2 | 813 | 455 | 0 | 1772323200 | NULL | `weekdays = 127`, `startday_offset = 2` |
| 503 | cb_timeframe | publish | 2 | 814 | 456 | 0 | … | NULL | `grid = 1`, `start_time = 08:00:00`, `end_time = 18:00:00`, `grid_size = 1`, `weekdays = 63` |
| 504 | cb_timeframe | publish | 2 | 813 | 457 | 0 | … | NULL | `has_role_limit = 1` |
| 620 | cb_timeframe | publish | 3 | NULL | NULL | 1 | 1798070400 | 1798329599 | assignment in `cb_index_rel` |
| 7004 | cb_restriction | publish | 0 | 812 | **0** | 0 | 1794268800 | NULL | `restriction_type = repair`, `restriction_state = active` |
| 9295 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794096000 | 1794182399 | `post_author = 17` |
| 9301 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794268800 | 1794355199 | `post_author = 17` |
| 9302 | cb_booking | canceled | 6 | 812 | 455 | 0 | 1794528000 | 1794873599 | stays in the index, filtered out via `post_status` |
| 9310 | cb_booking | confirmed | 6 | 814 | 456 | 0 | 1794218400 (9 Nov 10:00) | 1794225599 (11:59:59) | `post_author = 31` |

### 6.5 Keys and what they are used for

| Key | Columns | used by |
|---|---|---|
| `PRIMARY` | `post_id` | projection (`REPLACE`), guard, verifier |
| `item_loc_range` | `item_id, location_id, type, end_ts, start_ts` | pair queries (calendar, map, `SlotReader`, `getLatestPastConfirmed`) |
| `loc_range` | `location_id, type, end_ts` | queries by location only |
| `type_range` | `type, end_ts, start_ts` | queries without item/location (reminder mails: bookings beginning/ending on a day) |
| `author_type` | `post_author, type, start_ts` | bookings of a user |
| `multi_type` | `is_multi, type` | “multiple assignment” part of the `UNION ALL` |
| `ptype_item`, `ptype_location` | `post_type, item_id` / `post_type, location_id` | item ↔ location (`getRelatedPosts`), restrictions, pair list |
| `ptype_end` | `post_type, end_ts` | restrictions by period |

**Why `end_ts` in fourth position of `item_loc_range` matters so much.** Think of the key as a phone book sorted by
item, then location, then type, then end. Looking for “item 812, location 455, type 6, end from 6 Nov 2026” opens the
right page directly and reads only bookings ending after 6 Nov. The 600 old bookings are further front in the phone
book and are never touched. That is why the run time depends on the number of *current* bookings, not of all bookings
(`EXPLAIN` confirms the key is used).

Measurement (test data): pair query via `postmeta` 26 ms, via the index 0.12 ms; with fifty times the history
(867,000 index rows) 0.18 ms. All pairs of the map in **one** query: 1.7 ms instead of 400 × 25 ms ≈ 10 s.

Schema history: version 2 made `start_ts` nullable (explicit `ALTER`, because `dbDelta` does not change the NULL
property) and added `multi_type`. Version 3 added `ptype_item`, `ptype_location`, `ptype_end` – without them, queries
for restrictions and for the item ↔ location assignment grew with the history. Pure structural changes do not trigger
a rebuild; the index stays readable during the upgrade.

---

## 7. `{prefix}cb_index_rel`: multiple assignments

| Column | Type | Meaning |
|---|---|---|
| `post_id` | bigint unsigned | post with `is_multi = 1` |
| `entity_type` | varchar(8) | `item` or `location` |
| `entity_id` | bigint unsigned | ID of the item or location |

Keys: `PRIMARY (entity_type, entity_id, post_id)` for “all posts for item X”, `KEY post_id` for replacing and
deleting. On write, all rows of the post are deleted and reinserted (`INSERT IGNORE`).

Example holiday 620:

| post_id | entity_type | entity_id |
|---|---|---|
| 620 | item | 812 |
| 620 | item | 813 |
| 620 | item | 814 |
| 620 | location | 455 |
| 620 | location | 456 |
| 620 | location | 457 |

**Why a separate table instead of one row per pair?** A holiday “all items × all locations” with 500 items and 300
locations would need 150,000 rows in the main table; the relation table needs 800. Bookings and bookable timeframes
do without it.

**When multiple selection occurs:** according to `Timeframe::removeIrrelevantPostmeta()` only for holidays (3) and
repairs (5). `Timeframe::manageTimeframeMeta()` already writes category and “all” selections out as `item-id-list` or
`location-id-list`; new items end up there via `updateAllTimeframes()` and thus via the meta hooks in the index.

---

## 8. `{prefix}cb_availability`: precomputed day state per pair

### 8.1 What goes in

One row per item/location pair that has a published bookable timeframe (type 2) not ending before the horizon
(`Maintenance::getBookablePairs()`, source `cb_index`). Pairs without a bookable timeframe need no row; the map shows
“no timeframe” there.

The row describes **yesterday to today + 92 days**, i.e. 94 days (`Availability::getHorizon()`, filter
`commonsbooking_availability_horizon_days`, default 92). The horizon covers the map setting
(`availability_max_days_to_show`), the table (default 31 days) and month changes. “Yesterday” is a one-day buffer for
time zones and requests around midnight.

The table is a **pure cache**: every row can be deleted at any time and is recreated on the next read.

### 8.2 Columns

| Column | Type | Default | Meaning |
|---|---|---|---|
| `item_id`, `location_id` | bigint unsigned, **PK** `(location_id, item_id)` | – | the pair; location first because map and table load per location |
| `gen` | int unsigned | **1** | generation; incremented synchronously on **every** change of a post affecting the pair |
| `computed_gen` | int unsigned | **0** | generation from which `days` and `slots` were computed; new rows are therefore invalid at first |
| `days_from` | date, NULL | – | date of the first entry in `days` (on the day of computation: yesterday) |
| `days` | varbinary(1024), NULL | – | day facts, **2 bytes per day** from `days_from` (unsigned short, little endian); 94 days = 188 bytes, room for 512 days; NULL with role limit (then per access profile in 8.8) |
| `slots` | mediumblob, NULL | – | free bookable slots per day for API and GBFS (8.7); NULL with role limit (8.8) |
| `has_role_limit` | tinyint(1) | 0 | 1 = at least one bookable timeframe of the pair is role-limited |
| `computed_at` | datetime (UTC), NULL | – | time of computation (`gmdate()`) |
| `calc_version` | smallint unsigned | 0 | `Calculator::VERSION` (currently 2) |

Keys: `PRIMARY (location_id, item_id)`, `KEY item_id` (invalidation by item only, e.g. a restriction for all
locations), `KEY computed_at` (cron looks for the oldest rows).

`days` and `slots` are written as `UNHEX('…')` so the SQL statement stays plain ASCII text: otherwise `wpdb` strips
bytes it considers invalid text. `Store::preload()` (map, table) does not load `slots`; only `Store::loadWithSlots()`
(for `SlotReader`) reads that column.

### 8.3 Column `days`: ten bits per day (`DayFacts`)

For every day the calendar builds the slot grid as before (`Day::getGrid()`). `DayFacts::fromGrid()` condenses it into
ten bits, exactly following the rules of the former `View\Calendar::processSlot()`:

| Bit | Value | Constant | set if … |
|---|---|---|---|
| 0 | 1 | `HAS_SLOTS` | at least one slot has a timeframe |
| 1 | 2 | `SLOT_LOCKED` | at least one slot is locked (holiday, repair, booking, restriction) or not bookable for the user |
| 2 | 4 | `ALL_LOCKED` | no slot can be selected (also without slots) |
| 3 | 8 | `HOLIDAY` | all slots are holidays (type 3 or 4) (also without slots) |
| 4 | 16 | `REPAIR` | legacy of the original: reset by every slot (strict comparison string vs. number); in practice only set without slots |
| 5 | 32 | `BOOKED_DAY` | all slots are bookings or repairs (also without slots) |
| 6 | 64 | `PARTIALLY_BOOKED` | at least one slot is a booking, holiday, repair or restriction (`repair`) |
| 7 | 128 | `FIRST_SLOT_BOOKED` | the first slot is not bookable |
| 8 | 256 | `LAST_SLOT_BOOKED` | the last slot is not bookable |
| 9 | 512 | `FULL_DAY` | there is exactly one slot and its timeframe is full-day |

Bits 10–15 are free. Typical values (bytes little endian, low byte first):

| Day | Bits | Value | Bytes |
|---|---|---|---|
| no timeframe (e.g. Saturday with Mon–Fri) | `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY` | 60 | `3C 00` |
| full day bookable and free | `HAS_SLOTS`, `FULL_DAY` | 513 | `01 02` |
| full day booked | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (no `FULL_DAY`: bookings have no `full-day` meta) | 487 | `E7 01` |
| holiday (full-day holiday: + `FULL_DAY`) | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` | 463 (975) | `CF 01` (`CF 03`) |
| hourly grid 08–18, booked 10–12 | `HAS_SLOTS`, `SLOT_LOCKED`, `PARTIALLY_BOOKED` | 67 | `43 00` |
| hourly grid 08–18, booked 14–18 | as above + `LAST_SLOT_BOOKED` | 323 | `43 01` |

The “no timeframe” value shows why the bits must not be read individually: without slots, `HOLIDAY` and `BOOKED_DAY`
are set. `CalendarWindow::mapDay()` resets everything to “locked” in that case, like the original.

**Example: the row of Emil @ bakery (812, 455), computed on Fri 6 Nov.**

| Offset | Date | What applies | Value | Bytes at position |
|---|---|---|---|---|
| 0 | Thu 5 Nov | bookable, free (yesterday) | 513 | 0–1: `01 02` |
| 1 | Fri 6 Nov | bookable, free | 513 | 2–3: `01 02` |
| 2 | Sat 7 Nov | no timeframe | 60 | 4–5: `3C 00` |
| 3 | Sun 8 Nov | no timeframe | 60 | 6–7: `3C 00` |
| 4 | Mon 9 Nov | bookable, free | 513 | 8–9: `01 02` |
| 5 | Tue 10 Nov | booking 9301 + restriction 7004 | 487 | 10–11: `E7 01` |
| 6 | Wed 11 Nov | restriction 7004 (repair, active) blocks | locked | 12–13 |
| … | … | … | … | … |
| 93 | Fri 5 Feb 2027 | … | … | 186–187 |

**Reading one day:** offset = days between `days_from` and the date, then `unpack('v', $days, offset × 2)`
(`Store::getFlags()`). For Tue 10 Nov: offset 5, bytes 10–11 = `E7 01` = 0x01E7 = 487 = “booked”. No post is loaded and
no grid is built.

(In this example restriction 7004 makes Emil unbookable from 10 Nov; to keep the other examples simple, Parts C and D
only consider it where restrictions are the topic.)

### 8.4 What is not stored

Everything that depends on today's date or on the logged-in user is applied on **reading**:

| Rule | applied by | Source |
|---|---|---|
| day is in the past | `CalendarWindow::mapDay()` | today's date |
| day is outside the advance-booking window (`advance_days`) | `CalendarWindow` (`lastBookableDate`) | bookable timeframes + today |
| day is before the first bookable day (`startday_offset`) | `CalendarWindow` (`firstBookableDay`) | bookable timeframes + today |
| day is after the end of the requested range | `CalendarWindow` | request parameters |
| status for map/table | `DayStatus::fromDayArray()` | day facts after the window |
| role limit | row per access profile in `cb_availability_access` (8.8), chosen on reading | roles of the logged-in user |
| slots with times for the booking calendar of the item page | live there | – |

It was verified that `Day::getGrid()`, `mapTimeFrames()`, `mapRestrictions()`, `getHigherPrioFrame()` and `isLocked()`
depend only on the posts, not on `time()`. All date-dependent parts were in `View\Calendar::getCalendarDataArray()`
and `mapDay()` and now live in `CalendarWindow`. One exception is described in 31.2 (`Timeframe::isBookable()`).

Without a role limit the result is the same for all users, including admins:
`commonsbooking_isCurrentUserAllowedToBook()` always returns `true` for an empty role list. With a role limit it only
depends on the access profile (8.8).

### 8.5 Lifecycle of a row

| State | `gen` / `computed_gen` | `days` | how reached | on reading |
|---|---|---|---|---|
| **created** | 1 / 0 | NULL | `Maintenance::syncPairs()` (hourly) or `Store::getGen()` before the first computation (`INSERT IGNORE`) | invalid → compute |
| **valid** | n / n | set | `Store::write()` after `Calculator::compute()` | day facts from `days` |
| **invalidated** | n+1 / n | old | `Store::invalidate()` on a change | invalid → compute |
| **aged** | n / n | set, `computed_at` > 24 h | time passes (cron recomputes from 12 h) | invalid → compute |
| **outdated calc version** | n / n | set, old `calc_version` | update with new `Calculator::VERSION` | invalid → compute |
| **role-limited** | n / n | NULL, `has_role_limit = 1` | computation detects role limit | days from the row of the access profile (8.8) |
| **removed** | – | – | `syncPairs()`: no bookable timeframe any more | live (usually nothing to show) |

`Store::invalidate()` only increments **existing** rows. When a pair gets a bookable timeframe for the first time,
there is no row yet: the first reader creates it via `Calculator::recompute()`, at the latest the hourly
`syncPairs()`.

### 8.6 Validity of a row (`Store::isUsable()`)

A row is only used if **all** conditions hold:

1. `days` and `days_from` are set,
2. `has_role_limit = 0`,
3. `computed_gen = gen` (no change since computation),
4. `calc_version = Calculator::VERSION`,
5. `computed_at` is at most 24 hours old (filter `commonsbooking_availability_max_age`, default 86400 s).

In addition `Store::getFlags()` checks per day whether the date lies within `days`. If a requested day lies outside,
the `Reader` computes the whole pair live.

Correctness therefore does **not** depend on when recomputation happens: an outdated row is never delivered.

A picture for it: the notice board shows a sheet “version 41”, the office is already at version 42. Everyone knows the
sheet is no longer valid, even if nobody has put up a new one yet.

### 8.7 Column `slots`: free time windows for API and GBFS (`Slots`)

Map and table only need the day state. The REST API (`/availability`) and GBFS want to know **from when to when** an
item is free:

```json
{ "start": "2026-11-09T00:00:00+00:00", "end": "2026-11-09T23:59:59+00:00", "locationId": "455", "itemId": "812" }
```

(`+00:00` is not a conversion to UTC: CommonsBooking stores local time as a timestamp and outputs it that way. That was
the same in 2.11.3.)

From the same grid, `Slots::bookableSlots()` collects the slots whose highest-priority timeframe is bookable (type 2),
i.e. neither booked nor locked – exactly the condition of the previous live method. `Slots::pack()` stores them little
endian:

```
header   uint16  T = number of timeframes occurring
         T ×     uint32 timeframe ID, uint32 location ID, uint32 item ID   (0 = none)
per day  uint8   S = number of slots (at most 255)
         S ×     uint32 start, uint32 end (seconds from start of day), uint16 index into the header list
```

The days are contiguous from `days_from`. Example: full-day timeframe 501, one day free, one day booked:

```
01 00                                       T = 1
F5 01 00 00  C7 01 00 00  2C 03 00 00       timeframe 501, location 455, item 812
01  00 00 00 00  7F 51 01 00  00 00         day 1: 1 slot, 0 … 86399 s, header index 0
00                                          day 2: 0 slots (booked)
```

Example hourly grid Toni (814 @ 456) on Mon 9 Nov with booking 9310 from 10 to 12 h: eight slots 08–09, 09–10, 12–13,
13–14, 14–15, 15–16, 16–17, 17–18, i.e. start/end 28800/32399, 32400/35999, 43200/46799 … .

Size: 2 bytes header + 12 bytes per timeframe, per day 1 byte + 10 bytes per slot. Full day over 94 days ≈ 1 KB,
hourly grid with 10 slots ≈ 9.5 KB, worst case (24 hourly slots on 94 days) ≈ 22 KB. `mediumblob` instead of `blob`
(64 KB) so that half- or quarter-hour grids also fit.

Rationale:

- **Timeframe, location, item in the header:** the API outputs per slot the `locationId` and `itemId` of the
  **timeframe** the slot comes from. The header records this so the result matches the live path byte for byte. The
  reader also needs the timeframe ID for the “first bookable day” check.
- **Seconds from start of day instead of clock times:** stored is `timestampstart − start of day`, exactly as the
  existing code computes the slot. Quirks of the timestamps (local time as UTC, daylight saving time) are preserved.
- **Same row as `days`:** generation, invalidation, `calc_version`, `has_role_limit`, cron and verifier apply to the
  slots without further code; there is no second store that could go stale separately. Rejected: deriving slots on
  reading from day facts and grid configuration – saves storage but rebuilds the grid logic a second time and
  contradicts “one computation core for both paths”.

The column was added later (`Availability\Schema::SCHEMA_VERSION = 2`, `Calculator::VERSION = 2`). Anyone who had an
earlier beta gets the table dropped and recreated on update.

### 8.8 `{prefix}cb_availability_access`: role-limited pairs per access profile

If a pair has a role-limited timeframe, not all users see the same days: a guest sees “locked”, a member “free”. The
only user-dependent step of the day computation is `commonsbooking_isCurrentUserAllowedToBook()` (in `Day`,
`DayFacts`, `Repository\Timeframe`). It only depends on which of the allowed roles the user has, and whether they are
an admin. This gives the **access profile** (`AccessProfile::forCurrentUser()`):

```
R       = sorted union of the allowed_user_roles of all role-limited timeframes of the pair
          (publish/confirmed, from cb_index with has_role_limit = 1, multi timeframes via cb_index_rel)
profile = R                                 for admins (commonsbooking_isCurrentUserAdmin)
        = sorted( roles of the user ∩ R )   otherwise, joined with commas; '' = no matching role
```

All users with the same profile see the same days. An admin gets the profile of a user with all roles of R: both may
book every timeframe of the pair. For Paula @ repair café (504, “member” only) there are exactly two profiles: `''`
(guests, subscribers, everyone without “member”) and `cb_member` (members and admins; `cb_member` stands for the role
name of “member” here).

Columns (primary key `(location_id, item_id, access_profile)`, `KEY last_read`):

| Column | Type | Meaning |
|---|---|---|
| `item_id`, `location_id` | bigint unsigned | the pair |
| `access_profile` | varchar(191) | access profile as above |
| `computed_gen` | int unsigned | `gen` of the pair row in `cb_availability` at computation time |
| `days_from`, `days` | date, varbinary(1024) | day facts in the format of 8.3 |
| `slots` | mediumblob, NULL | free slots in the format of 8.7 |
| `computed_at`, `calc_version` | datetime (UTC), smallint | as in 8.2 |
| `last_read` | date, NULL | last read access, updated at most once a day |

**Validity** (`AccessStore::isUsable()`): the pair row is role-limited, `computed_gen` equals its `gen`,
`calc_version` is current, `computed_at` is at most 24 hours old, the day lies in `days`. The profile has no generation
of its own: every change to the pair increments the `gen` of the pair row (11.1) and thereby invalidates all profiles
of the pair without touching this table.

**Computing** (`Calculator::compute( item, location, profile )`): for the duration of the computation
`AccessProfile::runAs()` sets `$current_user` to a `WP_User` that is not logged in (ID 0, so not an admin) with exactly
the roles of the profile, and restores the real user in `finally`. Then the same computation core runs as for every
pair. It is written with a generation check in one statement:

```sql
INSERT INTO wp_cb_availability_access (item_id, location_id, access_profile, computed_gen, days_from, days, slots, …)
SELECT 813, 457, '', 42, '2026-11-05', UNHEX('…'), UNHEX('…'), …
  FROM wp_cb_availability WHERE location_id = 457 AND item_id = 813 AND gen = 42
ON DUPLICATE KEY UPDATE computed_gen = VALUES(computed_gen), days = VALUES(days), …
```

If `gen` has increased in the meantime, the statement writes nothing (as in 11.4).

**When it is computed:** for a role-limited pair, `Calculator::recompute()` finishes the pair row and immediately
computes the two most common profiles `''` and `R`. Further profiles (e.g. users with only one of two allowed roles)
are created on first reading. At most **8** profiles are stored per pair (filter
`commonsbooking_availability_access_profiles_max`, at least 2); the reader computes every further profile live, as
before access profiles.

**Cleanup** (`AccessStore::cleanup()`, daily before the sample): rows without a pair row, rows of pairs without a role
limit, rows with an old `computed_gen` and rows not read for 30 days (`AccessStore::UNUSED_DAYS`) are deleted.

**Switch:** filter `commonsbooking_availability_access_profiles` (default `true`). With `false` the reader computes
role-limited pairs live again every time.

**Schema and downgrade:** the table came with `Availability\Schema::SCHEMA_VERSION = 3`. On update both tables are
dropped and recreated once (pure cache). An older version does not know the table and leaves it alone; it is never
read, `wp commonsbooking booster purge` and uninstalling remove it.

---

## 9. Options, cron events, filters and actions

### 9.1 Options

| Option | autoload | Content |
|---|---|---|
| `commonsbooking_options_advanced-options[booster_enabled]` | (existing) | switch (`on`) |
| `commonsbooking_booster_state` | yes | `active`, `activated_at`, `plugin_version`, `watermark` (highest checked post ID), `last_rebuild_reason`, `last_rebuild` |
| `commonsbooking_index_schema_version` | yes | installed structure of `cb_index`/`cb_index_rel` |
| `commonsbooking_availability_schema_version` | yes | installed structure of `cb_availability` |
| `commonsbooking_index_ready` | yes | `PROJECTOR_VERSION` of the complete backfill; missing = index not readable |
| `commonsbooking_index_backfill_cursor` | no | highest post ID already projected during the build |
| `commonsbooking_index_backfill_lock` | no | lock during a slice of the build (expiry as unix time), only present briefly |
| `commonsbooking_availability_pairs_synced` | – | last sync of the pair list |
| `commonsbooking_booster_checks` | no | last run and result of every check |
| `commonsbooking_booster_activity` | no | ring buffer of the last 30 index changes (diagnostics) |

### 9.2 Cron events

| Hook | Schedule | Class | Task |
|---|---|---|---|
| `commonsbooking_index_backfill` | reschedules itself, 20 s per run, 500 posts per batch | `Index\Backfill` | index build; the same work also via "Finish setup now" without WP-Cron (`Backfill::runFor()`, lock) |
| `commonsbooking_index_verify` | daily 04:00 | `Index\Verifier` | sample of 200 posts against fresh projection, repairs |
| `commonsbooking_booster_guard` | hourly | `Booster\Guard` | deep check of status/modified date |
| `commonsbooking_availability_refresh` | hourly, additionally on demand | `Availability\Maintenance` | recompute new pairs, changed rows and rows older than 12 h (20 s budget) |
| `commonsbooking_availability_verify` | daily 04:30 | `Availability\Maintenance` | clean up access profiles, sample of 50 pairs and 50 access profiles against fresh computation, repairs |

Switching off the booster and deactivating the plugin remove all events.

### 9.3 Filters

| Filter | Default | Effect |
|---|---|---|
| `commonsbooking_booster_enabled` | setting | booster on/off (overrides the setting) |
| `commonsbooking_use_index` | `true` | `false`: repositories read via `postmeta`, precomputation off as well |
| `commonsbooking_use_availability_cache` | `true` | `false`: map/table/API compute every day live, index stays |
| `commonsbooking_availability_horizon_days` | 92 | precomputation period |
| `commonsbooking_availability_max_age` | 86400 | maximum age of a row in seconds |
| `commonsbooking_availability_sync_limit` | 25 | pairs recomputed at request end |
| `commonsbooking_availability_finish_request` | `true` | `fastcgi_finish_request()` before recomputation |
| `commonsbooking_availability_access_profiles` | `true` | `false`: role-limited pairs always live instead of per access profile |
| `commonsbooking_availability_access_profiles_max` | 8 | maximum stored access profiles per pair (at least 2) |

### 9.4 Actions

| Action | Parameters | Fired by | Listeners |
|---|---|---|---|
| `commonsbooking_index_rows_changed` | `$old`, `$new`, `$postId` | `Projector::notify()` when the projection (without bookkeeping columns) changed | `Invalidator::onIndexChange()` (prio 10), `Diagnostics::onIndexChange()` (prio 20) |
| `commonsbooking_index_rebuilt` | – | `Backfill::finish()` | `Invalidator::onIndexRebuilt()` |

---

# Part C – Write paths

## 10. Maintaining the index: Indexer and Projector

### 10.1 Hooks

`Indexer::init()` registers (only with the booster switched on):

| Hook | Priority | Method | Action |
|---|---|---|---|
| `init` | 1 | `Schema::maybeInstall()` | check/extend table structure |
| `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | 10 | `onMetaChange()` | relevant key and post type → `touch()` |
| `transition_post_status` | 10 | `onTransitionPostStatus()` | post type matches → `touch()` |
| `save_post` | **`PHP_INT_MAX`** | `onSavePost()` | `touch()` and immediately `flush()` |
| `deleted_post` | 10 | `onDeletedPost()` | remove from the dirty list, `Projector::remove()` |
| `shutdown` | 0 | `flush()` | safety net, also runs after fatal errors |
| `commonsbooking_index_backfill`, `commonsbooking_index_verify` | – | cron | see 12 |

`save_post` runs with the highest priority, i.e. **after** `CustomPostType\Timeframe::savePost()` (priority 11), which
rewrites meta and sets invalid timeframes to `draft`. The index thus sees the final state.

**Relevant meta keys:** `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`, `repetition-start`,
`repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`, `start-time`,
`end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`, `allowed_user_roles`,
`restriction-*`. All others (`_edit_lock`, booking codes, comments …) trigger nothing.

### 10.2 Dirty list and `flush()`

`Indexer` keeps a dirty list `$dirty` (post ID → `true`) per request. Marking the same post several times (e.g. ten
`update_post_meta` calls when saving a timeframe) results in **one** projection.

```php
Indexer::flush():
    if dirty list empty or tables not installed → do nothing
    take the IDs, clear the dirty list           // projection can trigger new hooks
    for each ID: try { Projector::project( id ) } catch ( Throwable ) { log }   // request never aborts
```

`flush()` runs:

1. at the end of `save_post` (priority `PHP_INT_MAX`),
2. explicitly in `Model\Booking::cancel()` after the direct SQL,
3. **before every read** from the index (`Repository\Index::findPostIds()`) and from the precomputation
   (`Reader::preload()`, `SlotReader`, `Invalidator::processQueue()`) – this is “read your writes”,
4. on `shutdown` (priority 0) as a safety net.

Without the index tables `flush()` has no effect (`Schema::isInstalled()`), so the calls in `cancel()` can stay even
with the booster switched off.

### 10.3 `Projector::project()` step by step

```
project( postId, notify = true ):
  1. post = get_post( postId )
  2. new  = buildRow( post )                    // null if not a CB type or auto-draft or deleted
  3. old  = load( postId )                      // only if notify and someone listens on CHANGED_HOOK
  4. new === null ? delete( postId )            // delete row + relations
                  : write( row, rel )           // REPLACE INTO cb_index; DELETE + INSERT IGNORE cb_index_rel
  5. notify: fingerprint( old ) ≠ fingerprint( new )  →  do_action( commonsbooking_index_rows_changed, old, new, postId )
```

`buildRow()` reads all metas of the post once and, depending on the type, fills `fillTimeframe()` (timeframes and
bookings) or `fillRestriction()`. The **fingerprint** ignores the bookkeeping columns `source_modified_gmt` and
`projector_version` and sorts relations. If someone saves a timeframe without a domain change (title only), only
`source_modified_gmt` changes – the fingerprint stays the same and nothing is invalidated.

The backfill calls `project( id, false )`: no loading of the old row, no action. Instead it fires
`commonsbooking_index_rebuilt` once at the end.

**Example: projection of booking 9301**

| Step | Result |
|---|---|
| read metas | `type = 6`, `item-id = 812`, `location-id = 455`, `repetition-start = 1794268800`, `repetition-end = 1794355199`, … |
| assignment | union {812} × {455} → `item_id 812`, `location_id 455`, `is_multi 0`, no relations |
| period | `start_ts 1794268800`, `end_ts 1794355199` |
| grid | bookings carry no `full-day`; `grid`, times as in the meta |
| old row | none (new booking) |
| fingerprint | `null` ≠ new → action with `old = null`, `new = {row, rel}` |

**Example: normalisation rules for legacy values**

| Meta | Value | Column |
|---|---|---|
| `repetition-start` | `'2026-01-01'` (text from an old import) | `start_ts = strtotime('2026-01-01')` |
| `repetition-end` | `''` | `end_ts = NULL` (open end) |
| `repetition-end` | `'0'` | `end_ts = NULL` |
| `weekdays` | `['1','2','3','4','5']` | `weekdays = 31` |
| `start-time` | `'8:00 AM'` | `start_time = '08:00:00'` |
| `timeframe-advance-booking-days` | `'abc'` | `advance_days = NULL` |
| `item-id` = 812 **and** `item-id-list` = `['812','813']` | | union {812, 813} → `is_multi = 1` |

### 10.4 Rule for your own code

Whoever writes posts of the three types **without** WordPress hooks (direct SQL) must call `Indexer::touch( $postId )`
and `Indexer::flush()`, possibly `clean_post_cache( $postId )` before. Otherwise the index only sees the change via the
guard (status, modified date) or the daily verifier (metadata).

---

## 11. Maintaining availability: Invalidator, Calculator, Store

### 11.1 Invalidating: which pairs are affected?

`Invalidator::onIndexChange( $old, $new )` handles **old and new row separately**. For each one, `getScope()`
determines the affected pairs:

```
getScope( row ):
  if not effective (isEffective)                  → none
       timeframe:   post_status = publish
       booking:     post_status = confirmed            (unconfirmed ones do not block the calendar)
       restriction: post_status ∈ Day::RESTRICTION_STATUSES (publish, confirmed, unconfirmed)
  if the period does not touch the horizon ± 2 days (overlapsHorizon) → none
  restriction:
       without start (start_ts NULL)              → none (never takes effect; avoids a false alarm on creation)
       item 0 → all items, location 0 → all locations
  multiple selection (is_multi = 1):              → items × locations from cb_index_rel
  otherwise:                                      → (item_id, location_id)
```

Then `Store::invalidate( items|null, locations|null )`:

```sql
UPDATE wp_cb_availability SET gen = gen + 1 WHERE item_id IN (812) AND location_id IN (455);
SELECT item_id, location_id FROM wp_cb_availability WHERE item_id IN (812) AND location_id IN (455);
```

`null` means “all” and omits the condition. The second query returns the pairs that actually exist; they go into the
recomputation queue. Both take less than a millisecond and run **in the same request**. In addition
`Calculator::reset()` is called: results already computed in this request are outdated.

**Why the old row matters:** if booking 9301 is moved from Emil (812) to Paula (813), the old row yields the pair
(812, 455) and the new one (813, 455). Both are invalidated. Without the old row, Emil would remain booked on 10 Nov.

### 11.2 Recomputing: queue and limit

`enqueue()` collects the invalidated pairs (deduplicated). From 25 pairs on (filter
`commonsbooking_availability_sync_limit`) nothing more is added; instead `overflow = true`.

At the end of the request (`shutdown`, priority `PHP_INT_MAX`) `Invalidator::onShutdown()` runs:

```
Indexer::flush()                                   // last changes into the index and invalidate
if queue empty and no overflow → done
if PHP-FPM and not WP-CLI and filter allows → fastcgi_finish_request()   // response goes to the browser
processQueue():
    overflow → Maintenance::scheduleRefresh()      // cron event “now”
    for each pair in the queue: Calculator::recompute()   // errors are logged, row stays invalid
```

On overflow the first 25 pairs are still computed immediately, the rest by cron. Readers arriving earlier compute
missing pairs themselves (16.3). Without PHP-FPM (e.g. `mod_php`) recomputation runs before the response ends; a
booking then costs about 13 ms more.

### 11.3 `Calculator::recompute()` and `Store::write()`

```
recompute( item, location ):
  G      = Store::getGen( item, location )        // INSERT IGNORE (gen 1, computed_gen 0), then SELECT gen
  result = compute( item, location )
  Store::write( item, location, G, result )       // optimistic, see below
  remember the result for this request (getComputed)   // for readers in the same request
  return result

compute( item, location ):
  [from, to] = horizon (yesterday … today + 92)
  role limit (hasRoleLimit via cb_index) → { roleLimited: true }, no days
      (recompute then computes the profiles '' and R via compute( item, location, profile ), 8.8)
  Plugin::withoutCache( {
      calendar = new Model\Calendar( from, to, [location], [item] )
      for each day: grid = Day::getGrid()
                    flags[date] = DayFacts::fromGrid( grid )->getFlags()
                    slots[date] = Slots::bookableSlots( grid )
  } )
  fill the list contiguously from `from` (missing days = 0, no slots)
  return { from, flags, slots: Slots::pack(), roleLimited: false }
```

`compute()` uses the **existing** calendar – only the queries underneath go through the index. `withoutCache()` is
needed because the plugin cache could return cached weeks from before the change.

```sql
-- Store::write( 812, 455, G = 42, … )
UPDATE wp_cb_availability
   SET computed_gen = gen, days_from = '2026-11-05', days = UNHEX('01020102…'), slots = UNHEX('0100F501…'),
       has_role_limit = 0, computed_at = '2026-11-06 10:15:03', calc_version = 2
 WHERE location_id = 455 AND item_id = 812 AND gen = 42
```

### 11.4 Concurrent changes without locks

The condition `gen = G` prevents storing a result that does not know about a parallel change:

| Time | Request A (computes after booking X) | Request B (books Y) | Row `gen` / `computed_gen` |
|---|---|---|---|
| t1 | reads `gen = 42` | | 42 / 41 |
| t2 | computes (does not know Y yet) | stores Y, `gen = gen + 1` | 43 / 41 |
| t3 | `UPDATE … WHERE gen = 42` matches **nothing** | | 43 / 41 (invalid) |
| t4 | passes its result on only within its own request | `shutdown`: reads `gen = 43`, computes, writes | 43 / 43 (valid, with X and Y) |

Without `gen = G`, A would have marked a state without Y as valid in t3. There are no locks that make other requests
wait. `recompute()` returns its result even if it was not stored: it reflects the state A read, and that is correct for
A.

### 11.5 Lifecycle of a row as a timeline

```
gen/computed_gen:   1/0 ──recompute──► 1/1 ──booking──► 2/1 ──shutdown recompute──► 2/2 ──12 h──► (cron) 2/2 new
                    created            valid           invalidated                    valid           computed_at new
reader sees:        computes itself    row             computes itself or live        row             row
```

---

## 12. Rebuild (backfill) and maintenance

### 12.1 Backfill

```
Backfill::start():        delete commonsbooking_index_ready   → index not readable from now on
                          cursor = 0, schedule cron event
Backfill::runCron():      while not done and < 20 s: runBatch()   then reschedule
Backfill::runBatch(500):  SELECT ID FROM wp_posts WHERE ID > cursor AND post_type IN (…) ORDER BY ID LIMIT 500
                          _prime_post_caches( ids )             // two queries instead of two per post
                          for each ID: Projector::project( id, notify = false )
                          cursor = last ID; release object cache of these posts (memory)
                          fewer than 500 found → finish()
Backfill::finish():       delete rows without a post (cb_index, then cb_index_rel)
                          commonsbooking_index_ready = PROJECTOR_VERSION  → index readable
                          do_action( commonsbooking_index_rebuilt )        → invalidate all pairs
```

During the build the hooks already write to the index; whatever the backfill overwrites afterwards is idempotent
(`REPLACE`). Until the end, reads go through the original queries. Speed: about 5,000 posts/s; 17,100 posts in
2.5–3.4 s, 167,000 in 24–40 s. `wp commonsbooking booster enable --now` and `index rebuild` run synchronously
(`runAll()`).

Compared with the design, the backfill was simplified: no bulk `INSERT … SELECT` fast path (the PHP projection is fast
enough and there is only one projection logic) and no AJAX upgrade tasks (they depend on the plugin version and are
ignored on fresh installs).

### 12.2 `Maintenance` (precomputation)

| Task | when | what |
|---|---|---|
| `syncPairs()` | hourly (removal once a day) | determine pairs from `cb_index` (type 2, `publish`, not ending before the horizon); create missing rows, delete rows without a bookable timeframe |
| `refresh()` | hourly, immediately after overflow or rebuild | `Store::getStalePairs()`: `computed_gen ≠ gen`, old `calc_version`, never computed or older than 12 h; oldest first; 20 s budget, then rescheduled in 30 s |
| `AccessStore::cleanup()` | daily 04:30, before `verify()` | delete orphaned, outdated and access profiles unread for 30 days (8.8) |
| `verify()` | daily 04:30 | freshly compute 50 random pairs and 50 random access profiles and compare with the row (day facts **and** slots, `matches()`); deviation → rewrite |

The 12-hour rule lets the horizon move along: a row computed on Monday 10:00 starts on Sunday and ends at Monday + 92.
From Monday 22:00 it is recomputed; after 24 h at the latest it is invalid.

### 12.3 Index verifier

`Verifier::verify( 200, repair: true )` (daily 04:00, `wp commonsbooking index verify --sample=<n> --repair`): sample
of posts, compute `buildRow()` freshly, compare with the stored row, re-project deviations (which triggers
invalidation). Finds pure meta changes that bypassed the hooks and that the guard does not see.

---

## 13. Write scenarios end to end

### 13.1 Scenario A: user 17 books Emil for Mon 9 Nov in the frontend

| # | Where | What happens | Table |
|---|---|---|---|
| 1 | browser | click on “Book”; the script from `includes/Public.php` locks the form (“Please wait …”) | – |
| 2 | `Plugin::handleBookingForms()` | call to `Booking::handleFormRequest()` inside `Booster::withoutBooster()`: from here on nothing reads from index, precomputation or plugin cache | – |
| 3 | `CustomPostType\Booking::withItemLock()` | `GET_LOCK('cb_booking_1_812', 20)`: parallel requests for Emil wait | – |
| 4 | `handleBookingRequest()` | overlap check and booking rules via the **original queries** (`postmeta`) | reads `wp_postmeta` |
| 5 | WordPress | `wp_insert_post()` creates booking 9320 (`unconfirmed`), metas are set | `wp_posts`, `wp_postmeta` |
| 6 | Indexer | `added_post_meta` (relevant keys) and `transition_post_status` mark 9320 | dirty list |
| 7 | Indexer | `save_post` (`PHP_INT_MAX`) → `flush()` → `Projector::project(9320)` | `cb_index`: new row, `post_status unconfirmed` |
| 8 | Invalidator | action with old = null, new = row; `isEffective` = no (unconfirmed) → **no** invalidation | – |
| 9 | lock | `RELEASE_LOCK`; redirect to the confirmation page | – |
| 10 | confirmation | user confirms; status becomes `confirmed`, `save_post` → projection | `cb_index`: `post_status confirmed` |
| 11 | Invalidator | old = unconfirmed (not effective), new = confirmed (effective, 9 Nov within the horizon) → `UPDATE … gen = gen + 1 WHERE item_id IN (812) AND location_id IN (455)` → queue [(812, 455)] | `cb_availability`: 41 → **42** / 41 |
| 12 | `shutdown` | `fastcgi_finish_request()` – the response has reached the browser | – |
| 13 | Invalidator | `processQueue()` → `Calculator::recompute(812, 455)`: reads G = 42, computes 94 days (≈ 13 ms), writes with `WHERE gen = 42` | `cb_availability`: 42 / **42**, offset 4 (9 Nov) = 487 |
| 14 | Diagnostics | entry in the activity log (origin “website”) | option `commonsbooking_booster_activity` |

Whoever opens the map between steps 11 and 13 does not get an outdated row: 42 ≠ 41, the `Reader` computes the pair
itself (16.3).

### 13.2 Scenario B: admin cancels booking 9301 (direct SQL)

`Model\Booking::cancel()` sets `post_status` with a direct `UPDATE` and thus bypasses all post hooks. New afterwards:

```php
clean_post_cache( $this->post->ID );               // otherwise get_post() still returns “confirmed” in the same request
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( $this->post->ID, 'cancellation_time', … );   // (hook, but not a relevant key)
\CommonsBooking\Service\Index\Indexer::flush();
```

| Step | Result |
|---|---|
| projection | `cb_index`: 9301 `post_status canceled` |
| Invalidator | old = confirmed, 10 Nov within the horizon → invalidate (812, 455); new = cancelled (not effective) → nothing |
| recomputation | at request end, 10 Nov back to 513 (“free”) |

The same path applies to all callers of `cancel()`: admin status change, `Restriction::cancelBookings()` (a restriction
cancels affected bookings) and the privacy erasure function. `clean_post_cache()` also fixes a bug from 2.11.3:
subsequent reads in the same request still saw the booking as confirmed.

### 13.3 Scenario C: moving a booking to another item

An admin changes the item of booking 9301 from 812 to 813.

| Row | `getScope()` | Invalidation |
|---|---|---|
| old | (812, 455), confirmed, within the horizon | Emil @ bakery: `gen + 1` |
| new | (813, 455), confirmed, within the horizon | Paula @ bakery: `gen + 1` |

Both pairs are in the queue and computed at request end. Without the old row, 10 Nov would remain booked for Emil.

### 13.4 Scenario D: holiday for many pairs (overflow)

An admin creates a holiday “all items, all locations” on a large instance with 300 pairs.

| Step | Result |
|---|---|
| projection | `cb_index`: `type 3`, `is_multi 1`; `cb_index_rel`: all item and location rows |
| `getScope()` | items × locations from the relations |
| `Store::invalidate()` | **one** `UPDATE … WHERE item_id IN (…) AND location_id IN (…)`: all 300 rows `gen + 1` |
| queue | the first 25 pairs, then overflow |
| `shutdown` | 25 pairs computed (≈ 0.3 s after the response), `Maintenance::scheduleRefresh()` |
| cron | `refresh()` computes the remaining 275 in 20 s chunks |
| readers meanwhile | compute missing pairs themselves – as fast as with “index only”, so no regression |

Because `updateAllTimeframes()` also rewrites the lists for “all” selections whenever a new item is added, the index
picks up a new item via the meta hooks of the holiday – tested.

### 13.5 Scenario E: restriction “Emil broken, all locations”

Restriction 7004 is created: item 812, location 0, active, from 10 Nov.

| Time | Row | `getScope()` |
|---|---|---|
| draft created, metas not yet saved | `start_ts NULL` | none (a restriction without start never takes effect) |
| saved and published | `item_id 812`, `location_id 0`, `start_ts 1794268800` | items [812], locations **null = all** |

`Store::invalidate( [812], null )` → `UPDATE … WHERE item_id IN (812)` via the key `item_id`: all locations of Emil.
If the restriction is later set to `solved`, the new row is no longer “active”, but status `publish` is still
effective; the pairs are invalidated again and the computation (which only reads `active`) frees the days again.

### 13.6 Scenario F: new bookable timeframe for a new pair

Paula (813) becomes bookable at the neighbourhood shop (456) from today.

| Step | Result |
|---|---|
| projection | `cb_index`: timeframe `type 2`, (813, 456) |
| Invalidator | `Store::invalidate( [813], [456] )` matches **no** row (the pair is new) → queue empty |
| first reader (map) | `Store::get()` → no row → `Calculator::recompute()` creates the row (`getGen()` with `INSERT IGNORE`), computes, writes |
| without a reader | `Maintenance::syncPairs()` creates the row within an hour, `refresh()` computes it |

### 13.7 Scenario G: changes outside the horizon

`Service\Booking::cleanupBookings()` deletes old unconfirmed bookings via `wp_delete_post()`.

| Step | Result |
|---|---|
| `deleted_post` | `Projector::remove()`: row and relations deleted, action with new = null |
| Invalidator | old: unconfirmed → not effective → nothing |

Likewise a confirmed booking from 2024 that an admin edits later: `overlapsHorizon()` returns `false`, nothing is
invalidated. Maintaining the history thus costs no recomputation.

### 13.8 Scenario H: importer writes metadata only

An importer changes the weekdays via `update_post_meta( 501, 'weekdays', ['1','2','3','4','5','6'] )` without saving
the post.

| Step | Result |
|---|---|
| `updated_post_meta` | relevant key → 501 marked |
| `shutdown` (prio 0) | `flush()` projects 501: `weekdays 63`; action → (812, 455) invalidated |
| `shutdown` (prio `PHP_INT_MAX`) | recomputation: Saturdays now 513 instead of 60 |

Had the importer written directly to `wp_postmeta` via SQL, the index would not see it. Then the daily verifier
(sample) and the 24-hour limit of the availability rows apply (20).

### 13.9 Scenario I: error during projection

If `Projector::project()` throws an exception, `flush()` logs it (`commonsbooking_write_log`) and continues. The
request (e.g. the booking) completes normally. The index row is then outdated; the guard (status, modified date,
hourly) or the verifier (daily) finds it. Booking validation is not affected (19).

---

# Part D – Read paths

## 14. Which path runs? The decision chain

```php
Booster::isEnabled()      // setting “on”, can be overridden by filter commonsbooking_booster_enabled
Booster::canRead()        // isEnabled() && not inside withoutBooster() && state['active']
Backfill::isReady()       // canRead() && filter commonsbooking_use_index && schema installed
                          //   && commonsbooking_index_ready === PROJECTOR_VERSION
Index::isAvailable()      // = Backfill::isReady()                    → repositories
Availability::isEnabled() // filter commonsbooking_use_availability_cache && schema installed
                          //   && Backfill::isReady()                 → Reader, SlotReader
```

| Situation | `Index::isAvailable()` | `Availability::isEnabled()` | Behaviour |
|---|---|---|---|
| booster off (default) | no | no | exactly 2.11.3 |
| booster on, backfill running | no | no | hooks write, reads via `postmeta` |
| booster on, backfill done | yes | yes | index + precomputation |
| filter `commonsbooking_use_availability_cache = false` | yes | no | index, days live |
| filter `commonsbooking_use_index = false` | no | no | everything live |
| inside `withoutBooster()` (booking validation) | no | no | original path, without plugin cache |
| guard started a rebuild | no (until done) | no | original path |

After the decision, the same pattern applies in every converted method:

```php
if ( ! Index::isAvailable() ) {
    return /* unchanged code from 2.11.3 */;
}
return /* index path with identical result */;
```

---

## 15. Reading the index: `Repository\Index` and the converted repositories

### 15.1 The query layer

```php
Index::isAvailable(): bool
Index::findPostIds( array $criteria ): int[]      // sorted by post_id; calls Indexer::flush() first
Index::loadPosts( int[] $ids ): WP_Post[]         // _prime_post_caches: posts + metas in two queries
Index::sortByDate( array $posts, 'ASC'|'DESC' )   // like WP_Query orderby=date; tie: ID ascending
```

| Criterion | SQL | Note |
|---|---|---|
| `post_types` | `post_type IN (…)` | |
| `types` | `type IN (…)` | |
| `statuses` | `post_status IN (…)` | |
| `items`, `locations` | single and multiple part, see below | empty list = no filter; invalid values → `IN (0)` |
| `start_min`, `start_max` | `start_ts >= …`, `start_ts <= …` | exclude NULL (old SQL required the key) |
| `end_min` | `(end_ts IS NULL OR end_ts >= …)` | open end counts |
| `end_max` | `(end_ts IS NULL OR end_ts <= …)` | |
| `end_exact` | `end_ts = …` | reminder mails |
| `require_end` | `end_ts IS NOT NULL` | |
| `authors` | `post_author IN (…)` | |
| `restriction_states` | `restriction_state IN (…)` | |

With an item or location filter a `UNION ALL` is built (deliberately not `OR`, so each part uses its own key):

```sql
SELECT post_id FROM (
    SELECT i.post_id FROM wp_cb_index i
     WHERE i.is_multi = 0 AND i.item_id IN (812) AND i.location_id IN (455) AND <common conditions>
  UNION ALL
    SELECT i.post_id FROM wp_cb_index i
     WHERE i.is_multi = 1
       AND EXISTS (SELECT 1 FROM wp_cb_index_rel ri WHERE ri.post_id = i.post_id AND ri.entity_type = 'item'     AND ri.entity_id IN (812))
       AND EXISTS (SELECT 1 FROM wp_cb_index_rel rl WHERE rl.post_id = i.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (455))
       AND <common conditions>
) matches ORDER BY post_id
```

### 15.2 Date filters: how `Repository\Timeframe::get()` and `getInRange()` are translated

`Repository\Timeframe::queryPosts()` (new, private) reproduces the three modes of the old `getPostsByBaseParams()`:

| Mode (parameters) | old SQL | criteria in the index |
|---|---|---|
| one day (`$date`, without `$minTimestamp`) | `repetition-start BETWEEN 0 AND day 23:59` and (`repetition-end BETWEEN day 00:00 AND 3000000000` or no end) | `start_min = 0`, `start_max = day 23:59`, `end_min = day 00:00`, `end_max = 3000000000` |
| from a point in time (`$minTimestamp`) | `repetition-end >= start of day` or no end | `end_min = getLastFullDayTimestamp( min )` |
| range (`$minTimestamp`, `$maxTimestamp`) | overlap | `start_max = max`, `end_min = min` |

**Example:** `Timeframe::get( [455], [812], [2,3,6], '2026-11-10', … , ['confirmed','publish'] )`

```sql
… WHERE i.is_multi = 0 AND i.item_id IN (812) AND i.location_id IN (455)
    AND i.post_type IN ('cb_timeframe','cb_booking') AND i.type IN (2,3,6)
    AND i.post_status IN ('confirmed','publish')
    AND i.start_ts >= 0 AND i.start_ts <= 1794311940            -- 10 Nov 23:59
    AND (i.end_ts IS NULL OR i.end_ts >= 1794268800)             -- 10 Nov 00:00
    AND (i.end_ts IS NULL OR i.end_ts <= 3000000000)
UNION ALL …   (holiday 620 would appear here, but is not on 10 Nov)
→ 501, 9301
```

Afterwards, these run **unchanged**: `filterTimeframes()` (weekdays, manual dates, maximum booking duration, roles),
conversion into models, plugin cache. The only difference is that two candidates are loaded instead of 600.

### 15.3 The converted repositories at a glance

| Method | 2.11.3 | with index |
|---|---|---|
| `Repository\Timeframe::get()`, `getInRange()` → `getBookable*`, `getInRangeForCurrentUser` | `getPostIdsByType()` (all IDs of the pair) + `getPostsByBaseParams()` (date filter via meta joins) | `queryPosts()` → `Index::findPostIds()` + `loadPosts()` |
| `Repository\Timeframe::getByAuthor()` (new) | – | criterion `authors` (key `author_type`); without index SQL on `post_author` + meta `type` |
| `Repository\Booking::getByDate()`, `getByTimerange()` (→ `getExistingBookings`, `getByRestriction`, booking rules, adjacent bookings), `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` with up to 5 meta joins | `findViaIndex()`; supports `$customArgs` `author`, `post_status`, `orderby=date`, `order`; anything else → `null` → `WP_Query` as before; sorting like `WP_Query` (`post_date DESC`) |
| `Repository\Booking::getLatestPastConfirmed()` (new) | – | two index queries (15.6) |
| `Repository\Booking::getForUser()` | load all bookings, filter in PHP | load candidates (15.5), **also without index** |
| `Repository\Restriction::get()` | `queryPosts()` via SQL on `postmeta` | `queryPostsFromIndex()` with `restriction_states = [active]`; PHP filter for item/location incl. “all” unchanged |
| `Repository\BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `WP_Query` with meta `{type}-id` | `getTimeframesByRelation()`: candidates from the index, then filter on the **single** `*-id` value (old semantics), related posts loaded in bulk (also without index) |
| `Model\Map::get_locations()` | per pair `Timeframe::getBookableForCurrentUser()` | `Map::getBookableTimeframesByPair()`: **one** query for all pairs; term cache and thumbnails in bulk (also without index) |
| `Model\Item::getClosestBooking()` | load all confirmed bookings of the pair | only the candidates with the latest start (15.6) |

Not converted: admin lists (`filterAdminList`, `pre_get_posts`), `Booking::getForUserPaginated()`, the REST routes
themselves and all write paths.

### 15.4 Example: conflict check on the index path vs. booking validation

`Booking::getExistingBookings()` is fast on the index path (0.07 ms instead of `WP_Query` with five meta joins). The
**booking validation** when submitting the form still never uses it, because it is wrapped in `withoutBooster()` (19).
The index path of `getExistingBookings()` serves displays, rules in views and diagnostics.

### 15.5 “My bookings”: `Repository\Booking::getForUser()`

**2.11.3:** load all bookings of the instance (with status and `minTimestamp`), then in PHP
`commonsbooking_isUserAllowedToSee()` per booking. A booking is visible if the user is a CB admin, the author of the
booking, or an admin of the item **or** the location (`Timeframe::getAdmins()`: meta `_cb_item_admins` or
`_cb_location_admins` plus `post_author` of item and location).

**Now:** first a superset of the visible bookings, then the same filter.

```
getVisibleCandidates( user ):
  CB admin  → all bookings (as before)
  otherwise → own bookings                         Timeframe::getByAuthor( user, [booking] )
            ∪ bookings of managed items            Booking::get( [], getManagedPostIds( user, cb_item ) )
            ∪ bookings of managed locations        Booking::get( getManagedPostIds( user, cb_location ), [] )
            sorted by ID, duplicates removed
then unchanged: commonsbooking_isUserAllowedToSee() over the candidates
```

`getManagedPostIds()` finds items/locations with `post_author = user` **or** admin meta containing the user. The meta
is sometimes a serialised list (`a:2:{i:0;s:2:"17";i:1;i:23;}`), sometimes a string (`"17"`): SQL narrows roughly via
`LIKE '%17%'`, PHP then checks exactly (`maybe_unserialize`, numeric comparison). So user 1 does not match the meta of
user 11 or 17.

**Example:** user 23 has 3 own bookings and manages location 455 with 1,200 bookings; the instance has 167,000
bookings.

| | 2.11.3 | now |
|---|---|---|
| bookings loaded | 167,000 | 3 + 1,200 = 1,203 |
| checked with `isUserAllowedToSee()` | 167,000 | 1,203 |
| result | 1,203 visible | the same 1,203, same order |

This also works **without the booster**, because `Booking::get()` uses the original query without index and still only
loads the managed pairs. For CB admins all bookings are still loaded (31.3).

`BookingRuleApplied` (“only one unconfirmed booking per user”) used to fetch all *visible* unconfirmed bookings and
discard foreign ones (a location admin also got those of their customers). Now it asks directly for
`getForUserPaginated( $user, …, ['unconfirmed'] )`: own bookings only, via `WP_Query`, deliberately without index,
because rules belong to booking validation.

### 15.6 GBFS vehicle ID: `Model\Item::getClosestBooking()`

GBFS publishes an obfuscated `vehicle_id` per vehicle that changes after every rental:

```php
Item::getCloakedId() = wp_hash( $item->ID . ( getClosestBooking()->post_name ?? $item->post_name ) )
```

`getClosestBooking()` returns the **most recently started, already finished, confirmed** booking at the current
location. Used by `vehicle_availability.json` (every item), `vehicle_status.json` (every free item) and
`Repository\Item::getByCloakedId()` (computes all items until an ID matches).

**2.11.3:** load all confirmed bookings of the pair as models, filter on `isPast()` in PHP, sort (start day, ties by
start time), `array_pop()`. On dev ≈ 1,300 bookings, 10 MB and 95 ms **per item**; `vehicle_availability.json` aborted
with HTTP 500 at a 128 MB memory limit.

**Now:** `Booking::getLatestPastConfirmed( $itemId, $locationId, current_time('timestamp') )`:

```sql
-- 1. latest start among the past confirmed bookings of the pair
SELECT MAX(COALESCE(start_ts, 0)) FROM wp_cb_index
 WHERE item_id = 812 AND location_id = 455 AND type = 6 AND post_type = 'cb_booking'
   AND post_status = 'confirmed' AND (end_ts IS NULL OR end_ts < :now)
-- 2. all bookings with exactly this start (usually one)
SELECT post_id FROM wp_cb_index WHERE … AND COALESCE(start_ts, 0) = :latestStart ORDER BY post_id
```

The **unchanged** sorting runs on these candidates. `null` (no index) → original path.

| Quirk of the original | Reproduction |
|---|---|
| only `confirmed`, only type 6 | same conditions |
| “past” = `intval( getEndDate() ) < current_time('timestamp')`; without end `intval('') = 0` → past | `end_ts IS NULL OR end_ts < :now` |
| missing start = 0 | `COALESCE(start_ts, 0)` |
| tie on start day: start time decides | load all candidates with the same start, original sorting decides |
| tie on day and time: stable `usort`, `array_pop` takes the highest ID | `ORDER BY post_id` |

**Example** (today 6 Nov 12:00): 1,300 old bookings, 9290 cancelled (3 Nov), **9295 confirmed (4 Nov)**, 9301
confirmed (10 Nov, not yet over). Query 1 returns the start of 4 Nov, query 2 the ID 9295. One post is loaded instead
of 1,300; `vehicle_id = wp_hash('812' . post_name of 9295)` as before.

Verified: `dev-bench/closest-parity.php` – dev 126 items, test environment 41 items, 0 deviations. Measurement: all
items 12.0 s → 0.9 s; `vehicle_availability.json` 13.3 s / 1,289 MB → 0.8 s / 71 MB, via HTTP 200 in 0.65 s.

### 15.7 Restrictions per period instead of per day (also without the booster)

- `Model\Calendar` loads the active restrictions of the whole range **once** (`Restriction::get()` with
  `minTimestamp = start − 1`) and passes them via `Week` to every `Day` (new optional constructor parameter
  `$possibleRestrictions`).
- `Day::getRestrictions()` filters the preloaded list with `isInRestriction()` (same date condition as the former query
  per day). Without a list (`null`, direct use of `Day`) the day queries itself as before.
- New constant `Day::RESTRICTION_STATUSES = ['publish', 'confirmed', 'unconfirmed']`.

Effect: 3,000–6,000 fewer queries per map or table request. In addition, `Model\Calendar` only loads timeframes and
restrictions in `loadPosts()` when `getWeeks()` is not served from the cache (previously in the constructor, even on a
cache hit).

---

## 16. Reading availability: `Reader` for map and table

### 16.1 Interface

```php
$reader = new Reader();
$reader->preload( [ [ $itemId, $locationId ], … ] );   // rows + bookable timeframes of all pairs, one query each
$reader->getDayStatuses( $itemId, $locationId, $start, $end, $keepDaterange, $rangeFrom, $rangeTo );
    // → [ 'days' => [ 'Y-m-d' => DayStatus, … ], 'endDate' => 'Y-m-d' ]
Reader::evaluateLive( … );                              // original path via Calendar::getCalendarDataArray()
```

Used by `MapItemAvailable::create_items_availabilities()` (map) and `View\Calendar::renderTable()` /
`renderItemLocationRow()` (item table), each only if `Availability::isEnabled()`. **Not** used by: the booking calendar
of the item page (needs slots with times), booking validation.

### 16.2 `preload()`

```
Indexer::flush()                                            // changes of this request first
Store::preload( pairs )     → SELECT … FROM cb_availability WHERE (location_id = 455 AND item_id IN (812,813))
                                                              OR (location_id = 456 AND item_id IN (814)) …
                              (one query per 200 locations, without the slots column)
Timeframe::getBookableForCurrentUser( all locations, all items, …, last full hour )
                            → one index query for all bookable timeframes, assigned per pair
```

### 16.3 `getDayStatuses()` for one pair

```
1. window:    CalendarWindow::forTimeframes( bookable timeframes of the pair, start, end, keepDaterange )
              → start/end, advance-booking days, last bookable day, first bookable day
2. days:      Reader::getCalendarDates( start, end )        // like the calendar: up to Sunday of the end week
              restricted to [rangeFrom, rangeTo]
3. facts for the days within the booking window (getFacts):
     a) row valid (Store::isUsable)              → DayFacts::fromFlags( Store::getFlags( row, date ) )
                                                   a day not covered → whole pair live
     b) row role-limited and current             → row of the access profile (8.8), otherwise
                                                   Calculator::computeAccessProfile(); cap reached → live
     c) days outside the horizon                 → live
     d) otherwise                                → Calculator::getComputed() ?? Calculator::recompute()
                                                   (computes, stores, uses the result)
     live = Reader::evaluateLive() = Calendar::getCalendarDataArray() as in 2.11.3
4. per day:   CalendarWindow::mapDay( day, facts )   → day array as in the calendar JSON
              DayStatus::fromDayArray( day array, hasSlots )
```

`DayStatus::fromDayArray()` (shared by map and table, previously implemented twice):

```
no slots                                         → no-slots
holiday                                          → holiday
locked and first and last slot taken             → booked
locked and partially booked                      → (first free and last taken) ? partially-booked-start
                                                                               : partially-booked-end
locked                                           → locked
otherwise                                        → available
```

Mapping to map and table:

| `DayStatus` | map (`MapItemAvailable::STATUS_MAPPING`) | table (`View\Calendar::TABLE_STATUS_MARKUP`) |
|---|---|---|
| `no-slots` | `locked` | `<span class='is-locked'></span>` |
| `holiday` | `location-holiday` | `<span class='is-holiday'></span>` |
| `booked` | `booked` | `<span class='is-booked'></span>` |
| `partially-booked-start` / `-end` | `partially-booked` | `is-partially-booked-start` / `-end` |
| `locked` | `locked` | `<span class='is-locked'></span>` |
| `available` | `available` | `<span></span>` |

Days outside the calendar data keep the status `no-timeframe` on the map.

### 16.4 The window by example

Paula @ bakery (813, 455): daily, full day, 2 days lead time (`startday_offset = 2`), today Fri 6 Nov. The row contains
513 (“free”) for all days.

| Date | stored | window rule | Status |
|---|---|---|---|
| Thu 5 Nov | 513 | past → locked | `locked` |
| Fri 6 Nov | 513 | before first bookable day (8 Nov) | `locked` |
| Sat 7 Nov | 513 | before first bookable day | `locked` |
| Sun 8 Nov | 513 | – | `available` |
| … | 513 | – | `available` |

On Saturday 7 Nov the reader reads **the same** row: now 9 Nov is the first bookable day and 6 Nov is in the past.
Nothing was recomputed.

Emil @ bakery (812, 455), 30 days advance booking: days after 6 Dec are “free” in the row, but the window
(`lastBookableDate`) outputs them as locked. In the table they appear grey since the fix described in 28.6
(`is-locked`).

### 16.5 Map and table in detail

**Map (`MapItemAvailable::create_items_availabilities()`):** for all pairs of the map `Reader::preload()`, then per
pair `getDayStatuses()` only for the **displayed** days (map setting, e.g. 12) and `markDays()` with `STATUS_MAPPING`.
Without precomputation: `Reader::evaluateLive()`, also only for the displayed days (previously 60).

**Item table (`View\Calendar::renderTable()`):** with the booster active, all pairs are preloaded via one `Reader`;
`renderItemLocationRow( …, ?Reader $reader )` reads the day status through it, otherwise live. Cache key of the row:
`item_location_today_days`.

---

## 17. Reading slots: `SlotReader` for REST API and GBFS

### 17.1 Who requests slots

All availability data of the REST API and GBFS goes through `Model\Calendar::getAvailabilitySlots()`. It first asks
`SlotReader::getAvailabilitySlots()`; if that returns `null`, `getAvailabilitySlotsLive()` computes (the unchanged
original code, only renamed).

| Caller | Calendar | Path with booster |
|---|---|---|
| `API\AvailabilityRoute::get_item()` (`/wp-json/commonsbooking/v1/availability/<id>`) | today to today + `api_future_availability_weeks`, one item, all locations | stored |
| `API\AvailabilityRoute::get_items()` (`/availability`) | as above, per item | stored |
| `API\GBFS\StationStatus` via `Item::isCurrentlyFreeAtLocation( $loc, true )` | yesterday to tomorrow, one pair, `ignoreStartDayOffset` | stored |
| `API\GBFS\VehicleStatus::getAvailableUntil()` | yesterday to end of max. booking duration, `ignoreStartDayOffset` | stored |
| `API\GBFS\VehicleStatus` via `isCurrentlyFreeAtLocation( $loc, true, true )` | yesterday to tomorrow, `ignoreRestrictions` | **live** |

### 17.2 Flow

1. **Pre-checks** (fallbacks, 17.3).
2. **Calendar days:** `Reader::getCalendarDates()` – from the start to **Sunday** of the end week, like the live
   calendar. Thu 6 Nov to Thu 20 Nov → 6 Nov to Sun 23 Nov.
3. `Indexer::flush()`.
4. **Pairs** (`getPairs()`): index query for bookable timeframes of the items in the range, optionally by location.
   A timeframe with `is_multi = 1` → `null` (live).
5. **Rows** of all pairs in one query (`Store::loadWithSlots()`). Invalid or missing rows are computed immediately
   (`Calculator::recompute()`).
6. **Per day:** offset (`Store::getDayOffset()`), unpack slots, with `ignoreStartDayOffset = false` discard slots before
   the first bookable day of the timeframe (`Timeframe::getFirstBookableDay()`, once per timeframe and request), sort
   the slots of all pairs of the day by start, `Slots::addUnique()` like live.

**Example `/availability/813` on Fri 6 Nov**, `api_future_availability_weeks = 2`. Paula has timeframe 502 (2 days
lead time) at the bakery (455) and timeframe 504 with role limit at the repair café (457).

| Step | Result |
|---|---|
| calendar days | 6 Nov to Sun 22 Nov |
| pairs | (813, 455), (813, 457) |
| row (813, 457) | `has_role_limit = 1` → row of the access profile from `cb_availability_access` (8.8), e.g. `''` for guests |

For the pair row (813, 455) it looks like this:

| Step | Result |
|---|---|
| row (813, 455) | `days_from = 5 Nov`, offset of 6 Nov = 1 |
| first bookable day of 502 | 8 Nov; slots of 6 and 7 Nov are dropped |
| day 9 Nov | slot `[0, 86399, header 0]` → timeframe 502, location 455, item 813; start of day 1794182400 |
| output | `{"start":"2026-11-09T00:00:00+00:00","end":"2026-11-09T23:59:59+00:00","locationId":"455","itemId":"813"}` etc. |

For GBFS (`ignoreStartDayOffset = true`) the lead-time check is skipped; the same row answers both variants.

### 17.3 Fallbacks to the live path

| Condition | Reason | occurs in practice |
|---|---|---|
| precomputation off | no data | – |
| `ignoreRestrictions = true` | stored slots always respect restrictions; a second variant would double the storage | GBFS `vehicle_status`, “currently free” check |
| no item filter | would be a full query; the live calendar behaves differently without a filter | own code only |
| calendar days outside the horizon | no row | `api_future_availability_weeks` > 12 |
| timeframe with several items/locations in the range | not precomputed | multi timeframes |
| pair with role limit, cap of 8 access profiles reached or access profiles switched off | user-dependent | very many role combinations |
| computed row does not match the horizon / day not covered | day change during the request | rare |

### 17.4 Deviations from the live path (bugs in 2.11.3)

`SlotsTest::testKnownLiveDeviations()` records both; demonstrated on dev with real data.

1. **Restrictions without a location filter.** `/availability` builds the calendar without a location. Live,
   `Restriction::get( [], [$item] )` then only finds restrictions without a location and misses all with item **and**
   location as well as location-wide ones (item 0). Repair days appear free live but locked when stored, because every
   pair is computed with its location.
2. **Days after the end date.** The calendar runs until Sunday, but live it only loads posts up to the end date. A
   booking on the Friday after a Thursday end date is missing live; stored, it is taken into account.

With the booster the answers are correct in both cases; without the booster the behaviour of 2.11.3 remains (31.3).

---

## 18. Read scenarios end to end

### 18.1 Scenario K: visitor opens the map (40 locations, 100 pairs, 12 days)

| # | Step | Queries |
|---|---|---|
| 1 | `Model\Map::get_locations()`: locations, items per location, **all bookable timeframes of all pairs in one query** (`getBookableTimeframesByPair()`), terms and thumbnails in bulk | few |
| 2 | `MapItemAvailable`: `Reader::preload()` loads 100 rows from `cb_availability` (one query, ≈ 19 KB) and the bookable timeframes (one index query) | 2 |
| 3 | per pair: determine window, read 12 codes, apply window, status | 0 |
| 4 | one pair was booked a minute ago (`gen ≠ computed_gen`): only this one is computed (≈ 13 ms) and stored | a few |

Measured (development data, plugin cache off): **0.11 s** instead of 1.14 s (index only) or 15.4 s (2.11.3, a tenth of
the data). First request without stored rows: 1.36 s (all pairs are computed and stored).

### 18.2 Scenario L: page view in the same request as a change

A plugin saves a booking and then renders `[cb_items_table]` in the same request.

| Step | What happens |
|---|---|
| booking saved | projection on `save_post`, pair invalidated, queue |
| `Reader::preload()` | calls `Indexer::flush()` (nothing left), loads the row: `gen ≠ computed_gen` |
| `getFacts()` | `Calculator::recompute()`: computes with the new booking, writes |
| `shutdown` | queue: the pair is computed once more (the result from `getComputed()` was not reused after `Calculator::reset()`) – harmless |

Result: the table already shows the booking in the same request (“read your writes”).

### 18.3 Scenario M: day change at midnight

Emil's row was computed on Fri 6 Nov 10:00 (`days_from = 5 Nov`, up to 6 Feb).

| Time | What applies |
|---|---|
| Fri 6 Nov 22:00 | cron: row older than 12 h → recomputed; `days_from` stays 5 Nov (yesterday) |
| Sat 7 Nov 00:05 | row valid (2 h old). The reader applies “today = 7 Nov”: 6 Nov is in the past, advance booking ends on 7 Dec. Nothing is recomputed |
| Sat 7 Nov 10:00 | next cron run (12 h): `days_from = 6 Nov`, horizon now extends to 7 Feb |
| if cron failed | the row from Fri 22:00 is no longer used from Sat 22:00 (24 h); the first reader recomputes it |

### 18.4 Scenario N: table over 180 days

`[cb_items_table days=180]`: the horizon extends to today + 92. `getFacts()` detects that days lie outside and computes
the pair completely live (`evaluateLive()`), as fast as with “index only”.

### 18.5 Scenario O: role-limited pair

Paula @ repair café (813, 457) is bookable only for “members”. `Calculator::compute()` detects this via
`has_role_limit` in the index and stores `has_role_limit = 1`, `days = NULL` in `cb_availability`. Right afterwards
`recompute()` computes the profiles `''` and `cb_member` and stores them in `cb_availability_access` (8.8).

| Visitor | Access profile | read from | sees |
|---|---|---|---|
| guest | `''` | row `(457, 813, '')` | locked |
| subscriber (`subscriber`) | `''` (role not in R) | the same row | locked |
| member (`cb_member`) | `cb_member` | row `(457, 813, 'cb_member')` | free |
| admin | `cb_member` (= R) | the same row | free |

If a second role-limited timeframe for “school” is added, R = `cb_member,cb_school`. The `gen` of the pair increases,
all profiles become invalid, `''` and `cb_member,cb_school` are recomputed; `cb_member` and `cb_school` are created on
the first request of such a user. The plugin cache separates by role anyway (22).

### 18.6 Scenario P: booster just switched on

| Time | State | Map |
|---|---|---|
| t0 | setting saved | – |
| t0 + next request | `Booster::onInit()` → `activate()`: tables, `Backfill::start()`, state `active` | original path (index not ready yet) |
| backfill running (cron, 20 s chunks) | hooks already write | original path |
| backfill done | `index_ready`, `index_rebuilt` → all pairs invalidated, `refresh` scheduled | index; pairs computed on reading or by cron |
| a few minutes later | all rows valid | 0.11 s |

---

# Part E – Safety and consistency

## 19. Booking validation always uses the original path

### 19.1 `Booster::withoutBooster()`

```php
public static function withoutBooster( callable $callback ) {
    ++self::$bypassDepth;                               // canRead() → false, so Index::isAvailable() → false
    try {
        return Plugin::withoutCache( $callback );       // neither read nor write the plugin cache
    } finally {
        --self::$bypassDepth;
    }
}
```

| Place | File |
|---|---|
| frontend booking form | `Plugin::handleBookingForms()` around `Booking::handleFormRequest()` |
| admin: save/confirm booking | `Wordpress\CustomPostType\Booking` around `$booking->isValid()` |
| validation when saving a timeframe | `Wordpress\CustomPostType\Timeframe::validateTimeFrame()` around `$timeframe->isValid()` |

The plugin cache is bypassed as well, because it may contain results originating from the index. Writing to the index
continues inside `withoutBooster()`.

**Tested:** the index row of a booking was deliberately deleted (index stale but considered complete). A new booking for
the same day was still rejected (“There is already a booking …”), because validation uses the original queries. The
deep check then restored the row.

### 19.2 Lock against double bookings (also without the booster)

With 167,000 bookings the original validation takes about 3 s. A second click during that time created a second
unconfirmed booking (both requests found the slot free), the third ended with “Found more than one bookings”. The bug
already existed in 2.11.3.

- **Server:** `Wordpress\CustomPostType\Booking::withItemLock()` wraps validation and saving in
  `GET_LOCK('cb_booking_{blog}_{item}', 20)`. Requests for the same item run one after another; after 20 s
  `BookingDeniedException` (“Other booking requests … being processed”). Without lock support in the DB it runs as
  before.
- **Browser:** an inline script in `includes/Public.php` locks `#booking-form` and `[id^=cb-booking-form-set-]` after
  the first submit (“Please wait …”), except for the calendar download, and releases the forms when returning from the
  back-forward cache.

---

## 20. Guard, verifier and maximum age

### 20.1 Guard (`Service\Booster\Guard`)

Scenario: downgrade to a version without booster, bookings are created or cancelled there, then upgrade again. The old
version leaves no marker; the guard detects the consequences in the data.

| Check | When | Detects | Consequence |
|---|---|---|---|
| **plugin version** | every request (`Booster::onInit()`, `init` prio 0; two autoload options) | `commonsbooking_plugin_version` was changed by other code. The booster remembers its own writes via `add_option_…`/`update_option_…` | rebuild |
| **new posts without index row** | every request, only IDs above `watermark` (primary key, cheap), only after 2 min (`GRACE_SECONDS = 120`) | posts created by another version | rebuild |
| **status and modified date** | hourly (cron) and `wp commonsbooking booster check --deep` | join `wp_posts` ↔ `cb_index`: missing row, different status, different `post_modified_gmt`, rows without a post | repair individually; from 200 (`REPAIR_LIMIT`) rebuild |

The 2-minute wait prevents false alarms: a parallel request may just have created a post whose `save_post` has not run
yet. The `watermark` only moves up to just before the oldest post that is still too young.

**Example version check:** the branch carries `2.11.4-beta.4`. When 2.11.3 is installed, its upgrade routine sets
`commonsbooking_plugin_version = 2.11.3`. When switching back, the guard sees `2.11.3 ≠ 2.11.4-beta.4` (last written by
itself) and rebuilds. Had the branch the same number as the previous version, this check would not fire – hence the
separate version number. The two other checks still cover the case.

### 20.2 What the checks do not see, and who finds it then

| Change bypassing the hooks | found by | effect until then |
|---|---|---|
| new post from another version | guard, next request after 2 min | display without this post |
| cancellation by another version (direct SQL, without modified date) | guard hourly (status) | day still appears taken; it can be booked (original validation) |
| edit with saved post | guard hourly (modified date) | old display |
| pure meta change without saving | index verifier daily (sample) | old display |
| any change affecting an availability row | row expires after 24 h at the latest; verifier daily 50 pairs | old display for at most 24 h |

In no case does a double booking arise, because booking validation never uses the booster.

### 20.3 Safety net at a glance

| Level | Mechanism | protects against |
|---|---|---|
| writing | hooks + `flush()` before every read + `shutdown` | forgotten projection in the request |
| writing | `try/catch` in `flush()` | request abort due to index errors |
| availability | generation `gen`/`computed_gen` | delivering outdated days |
| availability | `WHERE gen = G` on writing | races between parallel requests |
| availability | maximum age 24 h, recomputation from 12 h | changes bypassing the hooks; horizon moves |
| operations | guard, index verifier, availability verifier | downgrade, direct SQL, program errors |
| booking | `withoutBooster()`, `withItemLock()`, form script | double bookings |
| development | parity, snapshot and variant tests in four modes | deviations between index and original path |

---

## 21. Downgrade and upgrade

### 21.1 Recommended procedure

1. Switch off the booster (settings or `wp commonsbooking booster disable`).
2. Install the older version. It ignores the tables `cb_index`, `cb_index_rel`, `cb_availability`,
   `cb_availability_access` (versions before access profiles do not know it, but leave it untouched), the options and the
   cron events (without the corresponding code WordPress does not run them).
3. Later install the new version and switch the booster on again. The index is **always** rebuilt completely.

If step 1 is forgotten, the guard catches it on reinstall. Complete removal: `wp commonsbooking booster purge`.

### 21.2 Tested scenarios (`dev-bench/downgrade-scenario.sh`)

The official version 2.11.3 is in the container under `wp-content/plugins/commonsbooking-upstream`. The script swaps
the active plugin via the `active_plugins` option, i.e. without activation hooks, as when replacing the files.

| Run | with 2.11.3 | after switching back |
|---|---|---|
| `both` | booking 501 cancelled, booking 660 created | first page: guard reports “post 660 was created without the booster”, rebuild; 17 Nov free, 26 Nov booked; 60 days precomputed = live; index and availability without deviation |
| `cancel` | booking 502 cancelled | quick checks without findings (no new post); deep check finds and repairs 502; 24 Nov free, precomputed = live |

Additionally via the booking form: 26 Nov was rejected, 24 Nov could be booked.

---

## 22. Interaction with the plugin cache

CommonsBooking buffers repository queries, calendar data and lists in the Symfony cache (`Plugin::getCacheItem()`).

- **New `Plugin::withoutCache( callable )`:** neither reads nor writes any cache meanwhile. Used by
  `Calculator::compute()` and `withoutBooster()`.
- **Role context in the cache key (also without the booster):** `Cache::getCacheId()` contains `getRoleContext()`
  (guest or sorted roles plus admin flag). Previously a guest could get the cached view of an admin and vice versa – a
  bug that existed before the booster and becomes visible with role-limited timeframes. Users with the same roles still
  share an entry.

The cache stays enabled with the booster. Measured (development, seconds):

| Request | booster, no cache | booster, cache cold / warm | no booster, no cache |
|---|---:|---:|---:|
| map data (Ajax) | 0.11 | 0.26 / 0.08 | aborted (memory) |
| `[cb_map]` + `[cb_items_table]` | 0.10 | 0.15 / 0.06 | aborted (memory) |
| `[cb_items]` | 0.13 | 0.14 / 0.11 | 33.6 |
| `[cb_locations]` | 0.12 | 0.10 / 0.12 | 33.3 |
| item page with calendar | 0.06 | 0.07 / 0.04 | 2.3 |

With the booster the cache only gains hundredths of a second; it remains useful for the booking calendar of the item
page and the live fallbacks of the API. Without the booster it is still necessary.

---

# Part F – Operations

## 23. Switch and lifecycle

### 23.1 States

| State | Condition | Behaviour |
|---|---|---|
| **off** (default) | setting not `on` | `Booster::init()` only registers `onInit` (detects the switch), settings page and WP-CLI. No index hooks, no cron, no reading |
| **building** | on, backfill running | hooks write, reads use the original queries |
| **active** | on, backfill done | repositories read from the index, map/table/API from `cb_availability` |
| **bypassed** | inside `withoutBooster()` | original queries without cache; index is still written |

### 23.2 Transitions

| Event | Method | Effect |
|---|---|---|
| switch on (setting, `booster enable [--now]`) | `onInit()` → `activate()` | create tables, `Backfill::start()` (**always** completely new, because nobody maintained the index while the booster was off), state with `plugin_version` and `watermark` |
| every request with the booster active | `onInit()` → `Guard::check()` | version and watermark check |
| switch off | `onInit()` → `deactivate()` | `Backfill::reset()` (index considered stale), remove cron jobs, state inactive; tables remain |
| plugin activation | `Plugin::activation()` → `onPluginActivation()` | state inactive; with the booster switched on `activate()` |
| plugin deactivation | `Plugin::deactivation()` → `onPluginDeactivation()` | like switching off |
| removal | `booster purge` → `purge()` | switch off + delete tables, all booster options and the setting |

## 24. WP-CLI, diagnostics, filters

### 24.1 WP-CLI

```bash
wp commonsbooking booster status|enable [--now]|disable|rebuild|check [--deep] [--dry-run]|purge
wp commonsbooking index status|rebuild|verify [--repair] [--sample=<n>]|drop             # only with the booster on
wp commonsbooking availability status|rebuild|verify [--repair] [--sample=<n>]|drop      # only with the booster on
```

`availability status` shows the role-limited pairs and the access profiles (“Access profiles: 36 (36 usable) for 18
pairs, at most 8 per pair”). `verify` checks pairs and access profiles; `--sample` applies to each separately.

### 24.2 Diagnostics in the settings

Settings → Advanced → “Performance booster” (`SettingsView`, data from `Diagnostics`):

- **State** green/yellow/red with concrete hints: index being built, number of posts ≠ index rows, booking with
  differing status, old rows, missing or overdue cron tasks, repairs of the last deep check.
- **Index:** bars per type (timeframes, bookings, restrictions), latest change in WordPress and in the index.
- **Precomputed availability:** current, changed, old and role-limited pairs (`Store::getStats()`) as well as
  stored and valid access profiles (`AccessStore::getStats()`).
- **Latest bookings:** most recently created, changed or cancelled, status in WordPress and in the index side by side.
- **Latest index activity:** ring buffer of the last 30 changes, including deletions, with origin (website, admin,
  cron, WP-CLI) and a re-check of the current state.
- **Checks and scheduled tasks:** last run, result, next run.
- Buttons **Run all checks now** and **Rebuild index** (`admin_post_…`).
- **Finish setup now** (`wp_ajax_commonsbooking_booster_setup`, without JavaScript `admin_post_…`): index and item
  calendars in steps of 8 s from the browser, independent of WP-Cron. If the setup is stuck (`Backfill::isStalled()`,
  event overdue by more than 5 minutes or missing), the status, the dashboard card and an admin notice show the
  button. The button is a link (the status sits inside the CMB2 form, no nested `<form>`). Each step also stops
  when memory runs low (`Booster::isMemoryLow()`, 60 % of `memory_limit`). Details: `design/booster.en.md`,
  "Setup without WP-Cron".

Times come from GMT columns or Unix timestamps and are shown with `wp_date()` in the WordPress time zone. Exception:
CommonsBooking stores `cancellation_time` as local time (`current_time('timestamp')`); this is converted. Texts of the
settings page are translatable (WP-CLI output stays English).

## 25. Measurements

Development data: 167,000 posts (ten times the history), 1.84 million `postmeta`, plugin cache off.

| View / operation | 2.11.3 | index only | booster | Note |
|---|---|---|---|---|
| map with availability (100 pairs) | 15.4 s (1× data) | 1.14 s | **0.11 s** | first request without rows 1.36 s |
| `[cb_items_table]` | 7.05 s (1× data) | 0.74 s | **0.08 s** | first request 1.29 s |
| map, locations only | 49.96 s | 0.09 s | 0.09 s | |
| `[cb_items]` | 25.41 s | 0.09 s | 0.09 s | |
| REST `/availability` (126 items, 2 weeks) | – | 1,067 ms | **605 ms** | “index only” = precomputation off |
| REST `/items` | – | 969 ms | **503 ms** | |
| GBFS `station_status` | – | 194 ms | **138 ms** | |
| GBFS `vehicle_status` | – | 304 ms | **281 ms** | “currently free” stays live |
| GBFS `vehicle_availability.json` (HTTP, 128 MB) | HTTP 500 | – | **0.65 s** | |
| `getClosestBooking()` for 126 items | 12.0 s | – | **0.9 s** | |
| switch on with `--now` | – | index 23.9 s | + 1.3 s for 100 pairs | |
| recomputation of one pair after a booking | – | – | 13 ms after the response | |
| booking validation (always original path) | ≈ 3 s | ≈ 3 s | ≈ 3 s | deliberately unchanged |

PHPBench (project fixture, 100 items × 110 bookings):

| Benchmark | without index | with index | index + precomputed |
|---|---|---|---|
| booking flow | 347 ms | 14.6 ms | – |
| map | 2.98 s | 0.11 s | – |
| map with availability | 9.85 s | 1.15 s | 0.15 s |
| `[cb_items_table]` | 8.78 s | 1.13 s | 0.085 s |
| GBFS vehicle availability / status / station status | 22.5 / 20.8 / 7.36 s | 1.81 / 1.02 / 0.58 s | – |
| full run | 16:43 min | 2:52 min | – |

Role-limited pairs (DEV, 102 pairs, map query as admin, median without plugin cache):

| Data | 2.11.3 | live per pair | per access profile |
|---|---|---|---|
| 18 pairs limited | – | 150 ms | **62 ms** |
| all 102 pairs limited | ≈ 1,900 ms | 936 ms | **62 ms** |

A limited pair costs 0.5–1.0 ms from the access profile instead of 1.6–3.7 ms (measured with
`dev-bench/rollentest/perf-roles.php`, `perf-roles-live.php`).

Test environment (15 pairs, browser): map query 39 ms precomputed, 153 ms live, 264 ms without index; “classic views”
off 213 ms with 1,190 queries, on 116 ms with 48. Output byte-identical in all modes.

## 26. Tests and CI

| Test | Purpose |
|---|---|
| `Service/Index/IndexerTest.php` | projection per hook: save, meta, status, `cancel()`, trash, delete, cleanup cron, category/“all” holidays, empty `repetition-end` |
| `Service/Index/TimeframeIndexParityTest.php` | more than 1,400 comparisons index ↔ `postmeta` for timeframes, bookings, restrictions |
| `View/CalendarSnapshotTest.php` + `snapshots/calendar-core.json` (1.3 MB) | calendar JSON, map status, table rows for 11 scenarios × 4 users, recorded **before** the refactoring; stored, live and freshly computed identical |
| `Service/Availability/AvailabilityTest.php` | write path: book, confirm, cancel, delete, move, holiday, wildcard, horizon, concurrency, roles, age, calc version, overflow, rebuild |
| `Service/Availability/SlotsTest.php` | API/GBFS slots stored vs. live, known deviations, fallbacks, verifier |
| `Service/Availability/AccessProfileTest.php` (14 tests) | access profile per role and admin, stored = live for map and slots, immediately computed profiles, cap per pair, invalidation, cleanup, verifier, switch |
| `Service/Booster/BoosterTest.php` (13 tests) | switch, bypass during booking validation (also with a missing index row), all three guard checks, grace period, `purge` |
| `Service/Booster/DiagnosticsTest.php`, `CacheRoleContextTest.php` | diagnostics, roles in the cache key |
| `Service/Booster/TimeframeVariantsParityTest.php` + `Fixtures/TimeframeVariants.php` | 31 timeframe configurations, comparison with the original code for guest, subscriber, editor, two cb_managers, admin |
| `Wordpress/CustomPostType/BookingItemLockTest.php` | item lock |
| `Repository/BookingTest.php::testGetForUserMatchesFilteringAllBookings` | “My bookings” for seven kinds of users, old = new |
| `Wordpress/CustomPostType/BookingTest.php::testHandleBookingRequest_onlyOneUnconfirmedBooking_managedItem` | rule “one unconfirmed booking” |
| `Model/ItemTest.php::testGetClosestBooking_indexMatchesLegacy` | `getClosestBooking()` index vs. original in seven steps |
| `View/CalendarTest.php::testRenderItemLocationRowLocksDaysWithoutStatus` | `is-locked` markup |

The suite runs with the booster switched on by default (`tests/php/bootstrap.php` builds the index). Status: 586 tests
in the default mode (7 Nov 2026, with access profiles), before that 555 tests in each of the four modes; without the
index, index and availability tests are skipped.

| Variable | Effect | CI run (`.github/workflows/phpunit.yml`) |
|---|---|---|
| – | booster on | default matrix |
| `CB_USE_BOOSTER=0` | behaviour after the update | PHP 8.1, WP latest |
| `CB_USE_INDEX=0` | original queries | PHP 8.1, WP latest |
| `CB_USE_AVAILABILITY=0` | every day live | PHP 8.1, WP latest |

Locally: `dev-bench/phpunit/run-multi.sh vendor/bin/phpunit` or the `/check` skill.

---

# Part G – Changes to existing code

## 27. Overview

Comparison of `master` (2.11.3, `7aa75a81`) with `booster/index-availability`. New files (`src/Service/Index/`,
`src/Service/Availability/`, `src/Service/Booster/`, `src/Repository/Index.php`, tests, `design/`) are described in
Parts A–F.

**Guideline:** every converted query first checks `Index::isAvailable()` or `Availability::isEnabled()`. If the booster
is off, building or bypassed, the unchanged original code runs. `wp_posts`/`wp_postmeta` are never written differently
than before.

| Area | Files | What changes | also without booster? |
|---|---|---|---|
| wiring | `src/Plugin.php`, `includes/OptionsArray.php`, `commonsbooking.php` | start/stop booster, setting, version | setting and version yes |
| data access | `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | index path next to original path | no, **except** `getForUser()` and bulk loading in `BookablePost` |
| models | `src/Model/Calendar.php`, `Week.php`, `Day.php`, `Item.php`, `Booking.php`, `Map.php` | restrictions per calendar, lazy loading, slots from precomputation, `getClosestBooking()`, `cancel()`, bulk map | partly (see 28) |
| display | `src/View/Calendar.php`, `src/Map/MapItemAvailable.php` | computation core extracted, reading via `Reader`, fixes | refactoring and fixes yes |
| booking | `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `src/Service/BookingRuleApplied.php`, `includes/Public.php` | validation without booster, item lock, double-click protection, rule check | lock, double click, rule yes |
| cache | `src/Service/Cache.php` | roles in the key, `withoutCache()` | yes |
| presentation | `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | pickup instructions without whitespace | yes |
| tests, CI, docs | `tests/…`, `.github/workflows/phpunit.yml`, `phpstan*.neon`, `TECHNICAL.md` | booster on in tests, three additional CI runs | – |
| translations | `languages/*` | new texts, German | – |

## 28. File by file: before and now

### 28.1 `src/Plugin.php`

| Place | 2.11.3 | now |
|---|---|---|
| `init()` | – | `Booster::init()`: registers hooks, cron, CLI only with the booster switched on |
| `activation()` | – | additionally `Booster::onPluginActivation()` |
| `deactivation()` | – | additionally `Booster::onPluginDeactivation()` |
| `handleBookingForms()` | `Booking::handleFormRequest()` directly | inside `Booster::withoutBooster()` |

### 28.2 `includes/OptionsArray.php`, `commonsbooking.php`

- New group “Performance booster” under *Advanced options*: checkbox `booster_enabled` (default off), status area via
  `SettingsView::render`.
- Version `2.11.4-beta.4` (plugin header and `COMMONSBOOKING_VERSION`). It is part of every cache key (old entries
  expire) and is required so that the guard's version check detects a switch between versions.

### 28.3 `src/Repository/Timeframe.php`

| Method | 2.11.3 | now |
|---|---|---|
| `get()` | `getPostIdsByType()` → `getPostsByBaseParams( $date, $minTimestamp, null, … )` inline | `queryPosts( $types, $items, $locations, $date, $minTimestamp, null, $postStatus )` |
| `getInRange()` | likewise with `$maxTimestamp` | `queryPosts( …, null, $minTimestamp, $maxTimestamp, … )` |
| `queryPosts()` | – | **new, private**: without index the old two-step path (`getPostIdsByType` + `getPostsByBaseParams`), with index criteria as in 15.2 + `Index::loadPosts()` |
| `getByAuthor()` | – | **new, public**: timeframes/bookings of an author (for `getForUser()`); index or SQL on `post_author` |
| `getPostIdsByType()`, `getPostsByBaseParams()`, `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()` | central query building blocks | **unchanged**, only called on the original path |
| `filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `filterTimeframesByMaxBookingDays()`, `filterTimeframesForCurrentUser()` | PHP filters after loading | unchanged, run on both paths |

### 28.4 `src/Repository/Booking.php`

| Method | 2.11.3 | now |
|---|---|---|
| `getByDate()`, `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` with meta queries | first `findViaIndex( $criteria, $customArgs, $postStatus )`; `null` → `WP_Query` as before |
| `findViaIndex()` | – | **new, private**: supports `author`, `post_status`, `orderby=date`, `order`; any other argument → `null`; sorting via `Index::sortByDate()` |
| `getForUser()` | load all bookings, `isUserAllowedToSee()` | candidates via `getVisibleCandidates()`, then the same filter (15.5) – **also without the booster** |
| `getVisibleCandidates()`, `getManagedPostIds()` | – | **new, private** |
| `getLatestPastConfirmed()` | – | **new, public** (15.6); `null` without index |
| `getForUserPaginated()` | `WP_Query` | unchanged |

### 28.5 `src/Repository/Restriction.php`, `src/Repository/BookablePost.php`

| Method | 2.11.3 | now |
|---|---|---|
| `Restriction::get()` | `queryPosts()` via SQL on `postmeta` | with index `queryPostsFromIndex()` (**new**, `restriction_state = active`); PHP filter `filterPosts()` unchanged |
| `BookablePost::getRelatedPosts()` | `WP_Query` with meta `{type}-id`, related posts loaded one by one | `getTimeframesByRelation()` (**new**): candidates from the index, filter on the single `*-id` value, sorting `post_date DESC`; related posts in bulk (`_prime_post_caches`) – bulk loading also without index |

### 28.6 `src/View/Calendar.php` (largest change, about 450 lines)

The day logic previously existed twice (`processSlot()`/`getLockedDayArray()` here, `markDaysInTimeframe()` in the
map). It now lives in the computation core under `Service\Availability`, shared by the live path and the
precomputation.

| Element | 2.11.3 | now |
|---|---|---|
| `processSlot( $slot, &$dayArray, &$jsonResponse, &$allLocked, &$noSlots )` (protected) | condensed every slot into the day array | **removed** → `DayFacts::fromGrid()` |
| `getLockedDayArray( Day $day )` (protected) | day array of a locked day | **removed** → `CalendarWindow::getLockedDayArray()` |
| `getDefaultCalendarEnddateTimestamp( $startDate )` (private) | default end of the calendar | **removed** → `CalendarWindow` |
| `mapDay( $day, $lastBookableDate, $endDate, &$jsonResponse, $firstBookableDay )` (protected) | applied the window and built the JSON | **new signature** `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )`; logic in `CalendarWindow::mapDay()` |
| `getCalendarDataArray()` | window logic inline | same signature, uses `CalendarWindow::forTimeframes()` |
| `prepareJsonResponse()` | defaults and assembly | same public signature |
| `renderJsonResponse( CalendarWindow, $locations, $items )` | – | **new, private** |
| `renderTable()` | every row live | with the booster active, preload all pairs via one `Reader` |
| `renderItemLocationRow( …, $days_display )` (protected) | status computed inline | additional optional parameter `?Reader $reader`; markup via `TABLE_STATUS_MARKUP` |

Fixes (also apply without the booster):

1. **Markup of locked days.** The fill loop after the status area wrote `"<span'is-locked'></span>"`. Browsers read that
   as an element `span'is-locked'` **without a class**; the cells were empty instead of grey, although `.is-locked`
   exists in `_shortcodeItemsTable.scss`. Now `TABLE_STATUS_MARKUP[ DayStatus::LOCKED ]`. Visible: with 31 days and 14
   days advance booking, days 15–31 are now grey (belongs in the changelog).
2. **Cache key of the table row.** Now `item_location_today_days`. Previously separators and the number of days were
   missing: item 1 / location 23 and item 12 / location 3 collided, a 31-day table got the row of a 14-day table. An
   inner cache item that was never found again is dropped.

Quirks of the original were deliberately kept (snapshot test): `repair` is reset by every slot, 0 advance-booking days
count as “not set”.

### 28.7 `src/Map/MapItemAvailable.php`

| Element | 2.11.3 | now |
|---|---|---|
| `create_items_availabilities()` | per pair `getCalendarDataArray()` over 60 days | with the booster active `Reader::preload()` + `getDayStatuses()`, otherwise `Reader::evaluateLive()`; only displayed days |
| `markDaysInTimeframe( $calendarData, $availabilities )` (protected) | own status logic | **removed** → `markDays( array $dayStatuses, array $availabilities )` with `STATUS_MAPPING` |

### 28.8 `src/Model/Calendar.php`, `Week.php`, `Day.php`

| Element | 2.11.3 | now |
|---|---|---|
| `Calendar::__construct()` | loaded timeframes immediately, even on a cache hit | loads nothing; `$timeframes` is `?array` |
| `Calendar::loadPosts()` | – | **new, protected**: timeframes and restrictions (once for the range), only when `getWeeks()` has to compute |
| `Calendar::getAvailabilitySlots()` | computed live | asks `SlotReader` first, otherwise `getAvailabilitySlotsLive()` |
| `Calendar::getAvailabilitySlotsLive()` | – | **new, public**: previous logic; slot creation in `Slots` (shared with the precomputation) |
| `Week::__construct()`, `Day::__construct()` | `…, array $possibleTimeframes = []` | additionally `?array $possibleRestrictions = null` |
| `Day::getRestrictions()` | query per day | filters the preloaded list via `isInRestriction()` (**new**), otherwise query |
| `Day::RESTRICTION_STATUSES` | – | **new**: `['publish', 'confirmed', 'unconfirmed']` |

### 28.9 `src/Model/Item.php`, `src/Model/Booking.php`, `src/Model/Map.php`

| Element | 2.11.3 | now |
|---|---|---|
| `Item::getClosestBooking()` | load all confirmed bookings of the pair, filter `isPast()` | first `Booking::getLatestPastConfirmed()`, on `null` original path; sorting unchanged |
| `Booking::cancel()` | direct SQL, nothing else | afterwards `clean_post_cache()`, `Indexer::touch()`, `Indexer::flush()` |
| `Map::get_locations()` | per pair `Timeframe::getBookableForCurrentUser()`, terms/images one by one | items per location, **one** query for all pairs, terms and thumbnails in bulk – also without the booster |
| `Map::getBookableTimeframesByPair( $locationIds, $itemIds )` | – | **new, public** |

### 28.10 Booking: `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `src/Service/BookingRuleApplied.php`, `includes/Public.php`

| File | Change | also without booster |
|---|---|---|
| `CustomPostType/Booking.php` | `withItemLock()` (**new**) around `handleBookingRequest()`; admin save validates inside `withoutBooster()` | lock yes |
| `CustomPostType/Timeframe.php` | `validateTimeFrame()` checks `isValid()` inside `withoutBooster()` | – |
| `Service/BookingRuleApplied.php` | “one unconfirmed booking”: `getForUserPaginated( …, ['unconfirmed'] )` instead of all visible ones | yes |
| `includes/Public.php` | inline script against double submit | yes |

### 28.11 `src/Service/Cache.php`

| Element | 2.11.3 | now |
|---|---|---|
| `getCacheId()` | without user context | with `getRoleContext()` (**new**) |
| `withoutCache( callable )` | – | **new**; `getCacheItem()` returns `false` meanwhile, `setCacheItem()` does not write |

### 28.12 Minor fixes, tests, tools

- `templates/location-calendar-header.php` + `_lists.scss`: pickup instructions in their own
  `<span class="cb-location-pickupinstructions-text">`, `white-space: pre-wrap` only there (previously the whitespace
  of the template markup produced extra blank lines).
- `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php`: booster on, build the index before the run, variables
  `CB_USE_*`. `tests/php/BaseTestCase.php`: reset static state of `Store`, `Calculator`, `Invalidator`.
  `tests/php/Model/CalendarTest.php`: expected `Week` objects with the new parameter.
- `phpstan.neon`: `WP_CLI` ignored; `phpstan-baseline.neon`: one entry for `View/Calendar.php` dropped.
- `TECHNICAL.md`: sections “Performance booster”, “Timeframe index”, “Precomputed availability”.
- `languages/*`: texts of the settings page, “Please wait …”, message of the item lock (de_DE translated; `.pot` via
  `wp i18n make-pot` without `dev-bench`, `testenv`, `design`).

### 28.13 Add-on feature “Assigned timeframes”

Since `c7215d57` the “Assigned timeframes” feature is part of the booster update (user decision of 7 Nov 2026): a meta
box on items and locations, the row action “Timeframes” and filtering the timeframe list also via
`item-id-list`/`location-id-list`. It is small, changes no postmeta semantics and therefore stays downgrade-safe, and
it is important for administrators of large instances to find the timeframes of an item or location quickly. When the
index is available, it uses it (`Index::findPostIds()`). It is described in
[`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md). New are `src/Repository/AssignedTimeframes.php` and
`src/View/Admin/AssignedTimeframes.php`; changed are `CustomPostType/CustomPostType.php`, `Item.php`, `Location.php`
and `Timeframe.php` (`filterAdminList()`, `setCustomColumnsData()`).

**How holidays and blockings take effect.** A timeframe of type “Holidays or location closed” (3) or “Blocked” (5)
applies to the pairs *selected items × selected locations*, and of those only to pairs that have a bookable timeframe on
the day in question. `Repository\Timeframe::getPostIdsByType()` joins the item list and the location list with one
`INNER JOIN` each (`getEntityQuery()`), and every calendar queries exactly one pair. “All” and “category” store a
snapshot of the matching IDs in `item-id-list`/`location-id-list` (`Timeframe::manageTimeframeMeta()`). For the calendar
this means:

| Selection | Effect |
|---|---|
| Holiday: location X, items “All” | every item bookable at X on that day (location closed) |
| Blocked: item A, locations “All” | A at every location where A is bookable on that day |
| All × All | every bookable pair of the instance |
| explicitly selected items and locations without a common bookable pair | none |

So for a holiday, “All” on the item side effectively means “all items of the selected locations”, even though the stored
list contains every item of the instance.

**Display in the meta box (`Repository\AssignedTimeframes`).** The candidate search only looks at the own side
(`timeframeCandidateIds()`: the single field or the list contains the item or location). Because of the snapshot it
therefore finds a holiday with items “All” for *every* item of the instance. `buildTimeframeEntry()` then checks:

1. *Counterparts* (`counterparts()`): the locations of the item (or items of the location) from published bookable
   timeframes, preferably from those not yet expired, otherwise from all.
2. “Applies at” (`effective_at`): intersection of the timeframe's other side with the counterparts. For bookable
   timeframes it is the other side itself.
3. **Hide:** if “Applies at” is empty, the own side is only selected implicitly (“All” or category) and the other side
   is not “All”, `buildTimeframeEntry()` returns `null` and the entry is not shown. Example: a holiday “community
   centre, all items” is not listed for an item that is only bookable at the landing stage. Likewise, “Blocked: item A,
   all locations” is not listed for a location without A.
4. **Warn:** if “Applies at” is empty but the own side is selected explicitly (single field or list), the entry stays
   with ⚠ “assigned, but not at a location of this item”. That is a configuration error the meta box is meant to reveal.

All × All stays visible, even for an item without a bookable timeframe. Restrictions follow the same rule
(`buildRestrictionEntry()`, `0` = all). The rule only affects the display, not storage or the calendar. Tests:
`testHolidayOfOtherLocationForAllItemsIsHidden`, `testBlockedOtherItemAtAllLocationsIsHidden`.

## 29. Removed, restructured and new methods

For subclasses and third-party code:

| Element | Visibility | Change | Replacement |
|---|---|---|---|
| `View\Calendar::processSlot()` | protected | removed | `Service\Availability\DayFacts::fromGrid()` |
| `View\Calendar::getLockedDayArray()` | protected | removed | `Service\Availability\CalendarWindow::getLockedDayArray()` |
| `View\Calendar::getDefaultCalendarEnddateTimestamp()` | private | removed | `CalendarWindow` |
| `View\Calendar::mapDay()` | protected | new signature | `mapDay( Day, CalendarWindow, array & )` |
| `View\Calendar::renderItemLocationRow()` | protected | additional optional parameter | `?Reader $reader = null` |
| `Map\MapItemAvailable::markDaysInTimeframe()` | protected | removed | `markDays()` + `STATUS_MAPPING` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | public | additional optional parameter | `?array $possibleRestrictions = null` |
| `Model\Calendar::$timeframes` | – | now `?array`, filled in `loadPosts()` | – |
| `Model\Calendar::getAvailabilitySlots()` | public | same signature, path via `SlotReader` | `getAvailabilitySlotsLive()` for the old path |
| `Repository\Timeframe::get()`, `getInRange()` | public | same signature, internally `queryPosts()` | – |
| `Repository\Booking::getForUser()` | public | same signature and result, internally candidates | – |

New public members in existing classes: `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`,
`Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Plugin::withoutCache()`,
`Day::RESTRICTION_STATUSES`.

## 30. Behaviour changes that apply without the booster too

| Change | visible as |
|---|---|
| item lock when booking | parallel bookings of the same item run one after another; message after 20 s |
| double-click protection | button “Please wait …” |
| roles in the cache key | slightly more cache entries; no more foreign views |
| `getForUser()` without full scan | “My bookings” faster, same result |
| rule “one unconfirmed booking” only over own bookings | same result, faster |
| map: timeframes, terms, images in bulk | fewer queries |
| restrictions once per calendar | 3,000–6,000 fewer queries |
| map computes only displayed days | less computing time |
| table cache key | no more collisions |
| `is-locked` markup | days beyond the advance-booking limit now grey |
| `cancel()` with `clean_post_cache()` | subsequent reads see “cancelled” immediately |
| pickup instructions | no extra blank lines |

---

# Part H – Limits and outlook

## 31. Deliberate deviations, limits, open points

### 31.1 Deviations from the original behaviour (with booster)

| Case | 2.11.3 | booster |
|---|---|---|
| `repetition-end` present but empty | post dropped out of the query | open end (like `getTimeframeEndDate()`), recorded by a test |
| invalid ID (`'abc'` → `IN (0)`) | matched timeframes with empty `item-id` (MySQL converts `''` to 0) | matches nothing |
| `/availability` with restrictions on the pair / bookings after the end date | output as free (17.4) | correct |

### 31.2 Limits

- The index only sees writes bypassing the hooks via guard or verifier (20.2); availability rows expire after 24 h at
  the latest. Bookings are validated correctly in every case.
- Requests beyond the horizon and role-limited pairs beyond 8 access profiles are computed live.
- `Timeframe::isBookable()` (advance-booking window) depends on today's date and influences the selection of timeframes
  during computation. When a timeframe moves into the advance-booking window, a row can be outdated until the next
  recomputation (from 12 h, at most 24 h).
- The repetition and grid columns in `cb_index` are maintained but not yet used for queries.

### 31.3 Open points

| Point | Impact | possible approach |
|---|---|---|
| “My bookings” for CB admins | loads all bookings from `startDate`, paginates in PHP; several seconds with 167,000 bookings | pagination in SQL via the index |
| booking calendar of the item page | computes live, benefits only from the plugin cache | calendar JSON from day facts; needs times and roles |
| `getClosestBooking()` without booster | still loads all confirmed bookings of the pair | `WP_Query` with `orderby`/`posts_per_page` (change to the original path) |
| `Repository\Item::getByCloakedId()` | computes `getCloakedId()` per item (with booster ≈ 7 ms per item) | cache the hash → item mapping |
| bugs in the live path of the API (17.4) | without booster wrong free days | report to the main project |
| `vehicle_status` with `ignoreRestrictions` | stays live | second slot variant without restrictions |
| admin lists | still via `postmeta` | switch `posts_clauses` to the index |

## 32. Developer checklist

| If you … | then … |
|---|---|
| write a query on timeframes, bookings or restrictions | check `Index::isAvailable()`, offer index and original path, add a parity test |
| change posts of these types via direct SQL | `clean_post_cache( $id )`, `Indexer::touch( $id )`, `Indexer::flush()` |
| introduce a new meta key that affects assignment or availability | key in `Projector::getRelevantMetaKeys()`, if needed column + `Schema::SCHEMA_VERSION`, increment `Projector::PROJECTOR_VERSION` (rebuild) |
| change the day logic (slots, holidays, status) | in `DayFacts`/`CalendarWindow`/`DayStatus`, not in `View\Calendar`; increment `Calculator::VERSION`; re-record the snapshot deliberately |
| validate a booking or a timeframe | run it inside `Booster::withoutBooster()` |
| add new classes under `src/` | `composer prefix-namespaces` |
| test | suite in all four modes (`CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`, default) |

## 33. Glossary

| Term | Meaning |
|---|---|
| pair | combination of item and location, e.g. (812, 455) |
| projection | translating a post with its metas into an index row |
| backfill | complete rebuild of the index from all posts |
| generation (`gen`) | change counter of a pair |
| `computed_gen` | generation whose state the stored days were computed from |
| horizon | precomputed period: yesterday to today + 92 days |
| day facts | the ten bits of a day in `days` |
| window | date-dependent rules applied on reading (past, advance booking, first bookable day) |
| slot | time section of a day in the grid; one per day for full-day timeframes |
| original path / live path | unchanged code from 2.11.3 |
| `withoutBooster()` | execution with original queries and without plugin cache |
| guard | checks that detect changes made without the booster |
| verifier | daily samples against fresh projection or computation |
| access profile | the roles of a user that matter for a role-limited pair (admins: all roles of the pair); users with the same profile see the same days (8.8) |
| read your writes | changes of the running request are visible when reading in the same request (`Indexer::flush()` before reading) |
