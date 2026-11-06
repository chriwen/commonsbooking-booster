# Technical Documentation: Performance Booster

As of: 6 Nov 2026 · Branch `booster/index-availability` · Baseline: CommonsBooking 2.11.3 (`master`, `7aa75a81`)

*Deutsche Fassung: [technische-dokumentation-booster.md](technische-dokumentation-booster.md)*

This documentation is aimed at developers who know CommonsBooking 2.11.3. It describes what has changed, where the
changes are and how the new logic works. It is the **authoritative document** for the current state of the branch.
Everything that has been implemented is described here; open points are collected in 12.3.

Further documents in `design/` (each also available in German without the `.en` suffix):

| Document | Content | Role |
|---|---|---|
| [`booster-aenderungen-bestehender-dateien.en.md`](booster-aenderungen-bestehender-dateien.en.md) | every changed existing file with the reason, for review and upstream comparison | reference |
| [`booster.en.md`](booster.en.md) | decisions on switch, guard, downgrade, diagnostics; downgrade test, cache measurement | design note with measurements |
| [`timeframe-index.en.md`](timeframe-index.en.md) | design and implementation log of the index (stage 2), measurement series per phase | design note (historical) |
| [`availability-precompute.en.md`](availability-precompute.en.md) | design and implementation log of the precomputation (stage 3), measurement series | design note (historical) |
| [`booster-architektur.png`](booster-architektur.png) / [`.svg`](booster-architektur.svg) | diagram of all read and write paths with methods (German labels) | diagram |
| [`../TECHNICAL.md`](../TECHNICAL.md) | short reference (English) | reference |

The two design notes on stages 2 and 3 record how things were planned and in which steps they were implemented. Where
they differ from the result, this documentation applies.

---

## 1. Overview

### 1.1 The problem in 2.11.3

Timeframes, bookings and restrictions are custom post types. Their domain data (item, location, start, end, type,
grid …) lives in `wp_postmeta`. Every query for "item X at location Y in period Z" has to search through `postmeta`.
There is no suitable database index for that, so the run time grows with the entire booking history. In addition,
every view (map, item table) recomputes every day for every item/location pair
(`Model\Calendar` → `Week` → `Day::getGrid()` → `View\Calendar::processSlot()`).

Measured on a dataset with 17,100 timeframes/bookings: map with availability 15.4 s and 40,800 queries,
`[cb_items_table]` 7.1 s. With ten times the history some views aborted with out-of-memory errors.

### 1.2 The solution in three building blocks

| Building block | Namespace | Task |
|---|---|---|
| **Index** | `Service\Index`, `Repository\Index` | Projects every timeframe, booking and restriction into a narrow, indexed table. The repositories read from it instead of `postmeta`. |
| **Precomputed availability** | `Service\Availability` | Stores, per item/location pair, the date-independent state from yesterday to today + 92 days and the free time windows. Map, item table, REST API `/availability` and GBFS read it ready-made. |
| **Booster** | `Service\Booster` | One common switch for both building blocks, protection against inconsistencies (guard), diagnostics in the settings, WP-CLI. |

### 1.3 Guiding principles

1. **The posts remain the only source of truth.** `cb_timeframe`, `cb_booking`, `cb_restriction` and their meta data
   are written and read unchanged. Admin UI, CMB2, REST/GBFS, booking codes and third-party hooks work as before. The
   new tables are derived and can be rebuilt at any time.
2. **Off by default.** After the update the plugin behaves exactly like 2.11.3: no hooks, no cron jobs, no reading
   from the new tables.
3. **Two paths, one result.** Every converted method keeps its original path. A central check
   (`Index::isAvailable()` or `Availability::isEnabled()`) decides which path runs. Parity tests compare both paths.
4. **Booking validation never goes through the booster.** The overlap check and the booking rules always run with
   the original queries and without the plugin cache. An outdated index can at most cause a wrong display, never a
   double booking.
5. **Immediately up to date.** Index and availability are updated in the same request in which a post changes. A
   daily cron is explicitly not enough.

### 1.4 Basic principle

This section explains the idea behind the booster in a way that is understandable without detailed knowledge of
CommonsBooking. One running example accompanies all steps. The technical details of tables and columns follow in
chapter 2.

#### 1.4.1 How CommonsBooking stores its data

CommonsBooking lends **items** (for example a cargo bike) at **locations** (for example a bakery that hands out the
bike). A **timeframe** defines when an item can be borrowed at a location, for example "Monday to Friday, all day,
from 1 January 2026, open-ended". There are other timeframe kinds as well, such as holidays ("location closed from
24 to 26 December"), **bookings** ("user 17 borrows the bike on 10 November") and **restrictions** ("bike is under
repair from 10 November").

For the example in this section:

| | Example | ID |
|---|---|---|
| Item | cargo bike "Emil" | 812 |
| Location | Sunshine Bakery | 455 |
| Timeframe | bookable Mon–Fri, all day, from 1 Jan 2026, open-ended, up to 30 days in advance | 501 |
| Booking | Tuesday, 10 Nov 2026, all day, user 17 | 9301 |
| Today's date | Friday, 6 Nov 2026 | |

CommonsBooking is a WordPress plugin and stores all of this the way WordPress stores content: as **posts**. Every
timeframe, every booking and every restriction is a row in the `wp_posts` table. That row, however, only knows
general information such as ID, title, status and author. All domain information (which item, which location, from
when to when) is in a second table, `wp_postmeta`, as individual key/value pairs, **one row per piece of
information**. Booking 9301 looks roughly like this in the database:

```
wp_posts                       ID 9301 · post_type cb_booking · post_status confirmed · post_author 17

wp_postmeta   post_id  meta_key            meta_value
              9301     type                6               (6 = booking)
              9301     item-id             812
              9301     location-id         455
              9301     repetition-start    1794268800      (10 Nov 2026 00:00)
              9301     repetition-end      1794355199      (10 Nov 2026 23:59:59)
              9301     _cb_bookingcode     …               (and a few more)
```

In the test dataset on which the booster was developed, 17,100 timeframes and bookings come with about 190,000 such
meta rows, i.e. roughly eleven per post.

This storage format is flexible: every plugin can store new information without changing the database. But it has a
price as soon as you want to **search**. The next section shows why.

#### 1.4.2 Why it gets slow as history grows

Almost every view in CommonsBooking essentially asks the same question: *Which timeframes and bookings concern cargo
bike 812 at bakery 455 in the next twelve days?* The map asks this question for every item/location pair, so does the
item table, the booking calendar for one item, and the REST API for all of them.

**Problem 1: searching.** In `wp_postmeta` there is no "item" column and no "end" column that the database could keep
sorted. There are only the columns `meta_key` and `meta_value`. To answer the question, CommonsBooking 2.11.3 joins
several meta rows (roughly: "find all posts that have a row `type = 6` AND a row `item-id = 812` AND a row
`location-id = 455`"). The database cannot use the date condition for that; it is only checked afterwards in PHP.

That has an unpleasant consequence: if the cargo bike was booked 600 times over the last three years, the query
returns all 600 bookings. CommonsBooking then loads each one (in 2.11.3 with its own `get_post()` call per booking)
and afterwards throws away 597 of them because they are in the past. The work therefore grows with the **entire
history**, not with what is actually displayed. An everyday comparison: if you look for a book in a library without a
catalogue, you have to walk every shelf, and with every new book the walk gets longer.

**Problem 2: computing.** Even once the right posts have been found, the answer "free or taken?" is not stored
anywhere. CommonsBooking computes it on every call: for every day it builds a grid of time sections ("slots"), layers
timeframes, holidays, bookings and restrictions on top of each other, decides for every section which entry wins (a
booking beats the bookable timeframe) and condenses the result into a day state. That costs about 7 ms per pair for
31 days and a good 10 ms for 90 days. That sounds small, but a map with 100 pairs pays it a hundred times, on every
call by every visitor.

Measured on the test dataset in 2.11.3: the map with availability display took **15.4 seconds** and **40,800
database queries**, the item table `[cb_items_table]` 7.1 seconds. With ten times the history some views aborted with
out-of-memory errors.

#### 1.4.3 The idea: the same data additionally in a read-friendly form

The booster does **not** change where and how CommonsBooking stores its data. `wp_posts` and `wp_postmeta` are
written and read exactly as before; they remain the only binding source, the *source of truth*. The booster merely
creates two **additional** tables that hold the same information in a form that is quick to read for the frequent
questions. Such tables are called *derived data* or *read models*; in the database world the term *materialised view*
is also common.

Everyone knows the principle from a non-fiction book: the **index** at the back contains no new information,
everything is also in the text. But it is ordered so that you find a passage in seconds instead of leafing through
the book. If the text is revised, the index has to be updated, and in case of doubt the text applies, not the index.
The new tables behave exactly like that.

The booster works in two stages, each solving one of the two problems:

```
 Source of truth                 Stage 1: index                    Stage 2: precomputation
 (unchanged)                     solves "searching"                solves "computing"

 wp_posts + wp_postmeta   ──►   cb_index (+ cb_index_rel)   ──►   cb_availability
 1 post =                        1 post = 1 row                    1 pair (item, location) = 1 row
 1 post + ~11 meta rows          with fixed columns                with the finished result
                                 for item, location,               "what does each day look like?" and
                                 type, start, end …                "which time windows are free?"
                                                                   for yesterday to today + 92 days
```

The first stage is a **mirror**: it contains nothing that is not also in the posts, just better sorted. The second
stage is a **result store**: it contains what CommonsBooking used to compute on every call, computed once and stored.

#### 1.4.4 Stage 1, the index: every piece of information has its own column

For every timeframe, every booking and every restriction the booster writes exactly one row into the `cb_index`
table. The pieces of information that used to be spread over many meta rows sit side by side in their own columns
there. Booking 9301 becomes:

```
cb_index   post_id 9301 · post_type cb_booking · post_status confirmed · post_author 17 · type 6
           item_id 812 · location_id 455 · start_ts 1794268800 · end_ts 1794355199 · …
```

Because there are real columns now, the database can create a **key** (a sorted search tree) on them. The most
important one is called `item_loc_range` and sorts all rows by item, then location, then type, then end. Think of a
phone book ordered first by town, then by street, then by house number: whoever looks for "item 812, location 455,
type booking, end on or after 6 Nov 2026" opens the right page directly and reads only the few entries there. The
597 old bookings are in a different place in the phone book (their end lies before 6 Nov) and are never touched.

The question from 1.4.2 thus becomes a single, simple query:

```sql
SELECT post_id FROM wp_cb_index
 WHERE item_id = 812 AND location_id = 455
   AND type IN (2, 3, 6)                          -- bookable, holiday, booking
   AND post_status IN ('publish', 'confirmed')
   AND (end_ts IS NULL OR end_ts >= 1793923200)   -- does not end before 6 Nov 2026
   AND start_ts <= 1794959999                     -- does not start after 17 Nov 2026
```

Assume there are three bookings in the period. Then the result is: timeframe 501 (open end, `end_ts IS NULL`) and the
three bookings in this period, including 9301. `Index::loadPosts()` then loads these four posts including their meta
data **in bulk** with two queries instead of four individual ones. From here on the unchanged CommonsBooking code
continues; it just receives a smaller list that already fits.

**Worked example.** In the test dataset the search for one pair took 26 ms via `postmeta` and 0.12 ms via the index.
With fifty times the history (867,000 index rows) it was 0.18 ms; the search time therefore practically no longer
depends on the history. In 2.11.3 the map asked the question for 400 pairs individually, i.e. 400 × 25 ms ≈ 10 s.
With the index it asks for all pairs in **one** query: 1.7 ms. The index alone brought the map with availability down
from 15.4 s to about 1.2 s.

What the index does **not** do: it computes nothing and decides nothing. Whether a timeframe applies on a Wednesday
or whether a user may book it is still checked by the existing PHP code after the posts have been loaded. The index
only ensures that this code no longer receives 600 candidates when 4 are enough.

#### 1.4.5 Stage 2, the precomputation: keep the result instead of recomputing it

After stage 1 the queries were cheap, but the day computation from 1.4.2 (problem 2) remained: of its 1.2 seconds,
the map spent only 0.1 seconds in the database and the rest computing. Stage 2 therefore stores the result of the
computation.

The `cb_availability` table has one row **per item/location pair**, so for our example one row for (812, 455). For
every day from yesterday to today + 92 days this row holds a short code describing the day. The code is a number made
of ten yes/no flags (bits), such as "does the day have a bookable timeframe at all?", "is everything taken?", "is it a
holiday?", "is the first section of the day taken?". For the example, computed on Friday, 6 Nov 2026, the start of
the row looks like this:

| Day | Date | What applies | Code | stored bytes |
|---|---|---|---|---|
| 0 | Thu 5 Nov | bookable, free (yesterday; stored anyway) | 513 | `01 02` |
| 1 | Fri 6 Nov | bookable, free | 513 | `01 02` |
| 2 | Sat 7 Nov | no timeframe (Mon–Fri only) | 60 | `3C 00` |
| 3 | Sun 8 Nov | no timeframe | 60 | `3C 00` |
| 4 | Mon 9 Nov | bookable, free | 513 | `01 02` |
| 5 | Tue 10 Nov | booked all day (booking 9301) | 487 | `E7 01` |
| 6 | Wed 11 Nov | bookable, free | 513 | `01 02` |
| … | … | … | … | … |
| 93 | Fri 5 Feb 2027 | bookable, free | 513 | `01 02` |

Every day takes two bytes, so 94 days take 188 bytes. The codes are composed of the bits; for example 513 =
1 ("has sections") + 512 ("exactly one all-day section"). The exact meaning of every bit is given in 2.4.

**Reading a day.** If the map wants to know what Tuesday, 10 Nov, looks like, it only computes one position: the row
starts on 5 Nov (`days_from`), 10 Nov is 5 days later, so its bytes are at position 5 × 2 = 10. There it finds
`E7 01`, read as a number (low byte first) 0x01E7 = 487, i.e. "taken". Not a single post is loaded and no grid is
built.

**Worked example for the map.** The map shows twelve days each for 100 pairs. Without precomputation that means
100 × about 7 ms of computing ≈ 0.7 s, plus the queries. With precomputation the booster loads all 100 rows in a
single query (100 × 188 bytes ≈ 19 KB) and reads 1,200 codes at known positions. Measured: map with availability
**0.11 s** instead of 15.4 s in 2.11.3, item table **0.08 s** instead of 7.1 s.

**What is deliberately not stored: anything that depends on today's date.** Whether a day lies in the past, whether
it is still within the advance booking window ("up to 30 days in advance") or whether it lies before the earliest
bookable day depends on what day it is today. If that were stored, every row would be wrong at midnight and would have
to be recomputed. Instead the row only stores what follows from the posts, and the date is applied when **reading**
(`CalendarWindow`). For our example on Friday, 6 Nov, this means:

- 5 Nov carries the code "free", but lies in the past and is therefore displayed as "locked".
- 6 Nov is today and free, so "available".
- A day more than 30 days after today also carries "free", but is displayed as "locked" because of the advance
  booking window.

On Saturday, 7 Nov, the map keeps reading **the same row** unchanged. 10 Nov is still at position 10; now 6 Nov also
slides into the past and the advance booking window moves on by one day. Nothing needs to be recomputed for that. An
hourly background job recomputes rows older than twelve hours anyway, so that the 94-day period moves along; a row
older than 24 hours is not used at all.

**A second result in the same row: the free time windows.** Map and table only need the day state ("free", "taken",
"partially taken"). The REST API (`/availability`) and GBFS, through which apps and map services such as route
planners query availability, on the other hand want to know exactly **from when to when** an item is free:

```json
{ "start": "2026-11-09T00:00:00+00:00", "end": "2026-11-09T23:59:59+00:00", "locationId": "455", "itemId": "812" }
```

(The suffix `+00:00` is not a conversion to UTC: CommonsBooking stores local time as a timestamp and outputs it like
that. This was the same in 2.11.3 and remains unchanged.)

"Partially taken" is not enough for that, because it does not say **which** hours are free. The computation therefore
stores a second column `slots` in the same row: for every day the list of free time windows, each with start and end
in seconds from the start of the day. For cargo bike 812 the first days look like this:

| Date | Day code (`days`) | free time windows (`slots`) |
|---|---|---|
| Fri 6 Nov | 513 free | 0 – 86,399 s (00:00 – 23:59:59) |
| Sat 7 Nov | 60 no timeframe | none |
| Mon 9 Nov | 513 free | 0 – 86,399 s |
| Tue 10 Nov | 487 booked | none |

With an hourly grid (say 8 am – 6 pm, bookable by the hour), a day with a booking from 10 am to 12 noon would have
eight individual windows in the list: 8–9, 9–10, 12–13, … 17–18. Because both columns are in the same row and are
written together, the counter, invalidation and age limit from the next section apply to both at once. There is no
second store that could go stale separately. Details are in 2.4 and 6.6.

#### 1.4.6 Staying up to date: the generation counter

Stored results carry a fundamental risk: they can go stale. If someone books the cargo bike for Wednesday, 11 Nov,
the map must not show "free" a second later. A nightly rebuild would not be enough for that, and recomputing the
complete day state immediately on every booking would slow down the booking itself.

The booster therefore separates two things: **invalidating**, which happens immediately and cheaply, and
**recomputing**, which may happen a little later. For this, every row in `cb_availability` has two counters:

- `gen` (generation) counts how often something about the pair has changed. Every change to a timeframe, booking or
  restriction of the pair immediately increases it by 1. That is a single, tiny database statement that takes less
  than a millisecond.
- `computed_gen` records which state the stored day codes were computed from.

A row is only used if both counters are equal. Think of a notice with a version number: if the board says
"version 41" but the office is already at version 42, everyone knows the notice no longer applies, even if nobody has
put up a new one yet.

For the booking on Wednesday this works as follows:

| Point in time | What happens | `gen` | `computed_gen` | Map shows 11 Nov as |
|---|---|---|---|---|
| before | row is current | 41 | 41 | free (from the row) |
| booking is saved | WordPress saves the post, the index takes it over, the counter is increased | **42** | 41 | – |
| right after | row counts as stale because 42 ≠ 41 | 42 | 41 | taken (computed live or row freshly computed) |
| a few milliseconds later | after the response has gone to the browser, the booster recomputes the pair (about 13 ms) and stores it | 42 | **42** | taken (from the row) |

The third step is the decisive one: even if the recomputation fails (error, aborted process, a holiday change that
affects 300 pairs at once and is therefore left to the background job), the old row is **never** served. Whoever
opens the map next triggers the recomputation themselves or gets the result computed live. It can be slower once, but
not wrong.

When storing a recomputation there is one more safeguard against concurrency: the booster only writes if `gen` still
has the same value as at the start of the computation (`… WHERE gen = 42`). If a second booking comes in during those
13 ms and increases it to 43, the database discards the result that did not know the second booking yet. The row
stays invalid until a computation at version 43 has finished. This needs no locks that would make other requests
wait.

#### 1.4.7 When in doubt, the source: falling back to the original path

Every place that reads the new tables has kept its previous path and falls back to it when the derived data cannot
answer the question reliably:

| Situation | What happens |
|---|---|
| Booster switched off (default after the update) | Nothing new is read or written at all; the plugin behaves like 2.11.3 |
| Booster just switched on, index still being built | Reading continues via `postmeta` until all posts have been taken over |
| Row in `cb_availability` missing or invalid | Pair is computed immediately and stored, or computed live |
| Request goes beyond the 94-day period (e.g. table with 180 days) | computed live |
| Timeframe bookable only for certain user roles | always computed live, because the result depends on the logged-in user |
| **Validation of a booking** (overlap, booking rules) | **always** via the original queries, without index, without precomputation, without cache |

The last point is the most important safety guarantee: even if the index were ever wrong because of a bug, the worst
that could result is a wrong **display** (the map shows a day as free), never a double booking. The decision whether
a booking is accepted is always made by the original code based on `wp_postmeta`.

That both paths deliver the same result is ensured by tests that run every converted query and every view once
through the booster and once through the original path and compare them. In operation, daily samples compare stored
rows with a fresh computation and repair deviations.

#### 1.4.8 One request from start to finish: the map

A visitor opens the map on 6 Nov; it shows the availability of the next twelve days for each location. Assume there
are 40 locations with 100 pairs in total.

1. **Find pairs (index).** One query on `cb_index` returns all bookable timeframes of the 40 locations (`type = 2`,
   `post_status = publish`, end not before today). This yields the 100 pairs, including (812, 455).
2. **Load rows (precomputation).** One query loads the 100 rows from `cb_availability`. Each is checked: counters
   equal, computation version current, younger than 24 hours, no role restriction.
3. **Determine the window.** For every pair `CalendarWindow` determines from the bookable timeframes and today's date
   which days can be bookable at all (advance booking, earliest day).
4. **Read days.** For the twelve days from 6 Nov to 17 Nov the booster reads the codes at positions 2, 4, …, 24 of the
   row and applies the window. For (812, 455) this gives: 6 Nov available, 7/8 Nov no offer, 9 Nov available,
   **10 Nov taken**, 11 Nov available, …
5. **Exceptions.** If one of the 100 rows is invalid (for example because someone booked a minute ago), only this one
   pair is computed (about 13 ms) and stored; the other 99 are still read.

Altogether this is a handful of database queries and a few milliseconds of computing, regardless of how many bookings
have accumulated over the years.

#### 1.4.9 One change from start to finish: the booking

User 17 books the cargo bike for Tuesday, 10 Nov. The booking validation runs without the booster as described
above. If the booking is accepted and saved, the following happens, all within the same request:

| Step | Table | What happens |
|---|---|---|
| 1 | `wp_posts`, `wp_postmeta` | WordPress saves the booking as before: post 9301 with status `confirmed` and the meta rows from 1.4.1 |
| 2 | `cb_index` | WordPress reports "post saved" (`save_post`). The booster reads the post and rewrites its row: `post_id 9301`, `type 6`, `item_id 812`, `location_id 455`, start and end |
| 3 | `cb_availability` | Because the index row has changed, the booster determines the affected pairs, here only (812, 455), and increases their `gen`, for example from 41 to 42 |
| 4 | – | The response ("booking confirmed") goes to the browser |
| 5 | `cb_availability` | Afterwards the booster recomputes the pair: 94 days, about 13 ms, and stores the codes with `computed_gen = 42`. 10 Nov now carries code 487 |
| 6 | – | The next map request reads the current row |

If a booking is later **moved** (different item or location), two pairs are affected: the old and the new one. That
is why the index passes on the old **and** the new row with every change, and both pairs are invalidated. If a booking
is **cancelled**, CommonsBooking sets the status with a direct database statement; there the booster reports the
change to the index itself (see 7.1), so that steps 2 to 5 run as well.

#### 1.4.10 In summary: four rules

1. **The derivation is unambiguous and repeatable.** The same posts always produce the same rows. Therefore every row
   can be deleted and recreated at any time, and checks can compare a stored row with a fresh computation.
2. **Only what does not depend on today's date is stored.** The date is applied when reading. That way a row stays
   valid across midnight.
3. **Invalidate immediately, recompute later.** A change increases the counter in the same request; a row with a
   different counter is never used. When the recomputation happens only affects speed, not correctness.
4. **When in doubt, the source.** Where the derived data is missing or does not fit, the original path computes.
   Bookings are always decided by the original path.

### 1.5 Architecture

Detailed diagram with all methods: [`booster-architektur.png`](booster-architektur.png) ([SVG](booster-architektur.svg)).

```
                          Write path                                     Read path
 ┌──────────────────────────────────────────┐        ┌──────────────────────────────────────────┐
 │ save_post / *_post_meta / transition /   │        │ Repository\Timeframe, Booking,           │
 │ deleted_post / Booking::cancel()         │        │ Restriction, BookablePost, Model\Map     │
 └───────────────┬──────────────────────────┘        └───────────────┬──────────────────────────┘
                 │ post ID marked "dirty"                            │ Index::isAvailable()?
                 ▼                                                   │   yes → Repository\Index
       Service\Index\Indexer ──flush()──► Projector ──REPLACE──► cb_index / cb_index_rel
                                              │                      │   no → original query (postmeta)
                                              │ hook commonsbooking_index_rows_changed (old + new row)
                                              ▼
                               Service\Availability\Invalidator
                                   │ UPDATE gen = gen + 1  (immediately, synchronously)
                                   ▼
                              cb_availability ◄── Calculator (≤ 25 pairs at end of request, otherwise cron)
                                   ▲
                                   │ Store::isUsable()? yes → day facts (days) or slots (slots)
                                   │                    no → compute or live
              ┌────────────────────┴──────────────────────┐
   Service\Availability\Reader                 Service\Availability\SlotReader
   ◄── MapItemAvailable,                       ◄── Model\Calendar::getAvailabilitySlots()
       View\Calendar::renderTable()                (REST /availability, GBFS station/vehicle status,
                                                    GBFS vehicle availability)
```

Booking validation (form, admin confirmation, timeframe validation) is deliberately outside this diagram: it runs in
`Booster::withoutBooster()` and takes the original path (7.2).

---

## 2. New database tables

### 2.1 Overview and interaction

All tables are created per site via `$wpdb->prefix` (multisite-capable), generated with `dbDelta()` and have their own
schema version option. There are no foreign keys and no triggers; maintenance happens in PHP via hooks. The tables are
created when the booster is switched on and removed only with `wp commonsbooking booster purge`.

```
 wp_posts (cb_timeframe, cb_booking, cb_restriction)
   │ 1 : 1   post_id
   ▼
 cb_index ──────────── 1 : n ────────────► cb_index_rel
   │ item_id, location_id                    (only rows with is_multi = 1:
   │ (only timeframes type 2, publish)        entity_type item|location, entity_id)
   │ n : 1
   ▼
 cb_availability   PRIMARY (location_id, item_id)
```

| | `cb_index` | `cb_index_rel` | `cb_availability` |
|---|---|---|---|
| One row per | post (timeframe, booking, restriction) | item or location of a post with multiple selection | item/location pair with a bookable timeframe |
| Row count (example 17,100 posts, 300 pairs) | ≈ 17,100 | a few hundred | ≈ 300 |
| Content | facts from `wp_posts`/`postmeta`, typed | assignments | computed day facts + slots |
| Written by | `Index\Projector` | `Index\Projector` | `Availability\Store` (via `Calculator`, `Invalidator`, `Maintenance`) |
| Read by | `Repository\Index`, `Projector::load()` (old row for the change hook), `SlotReader`, `Maintenance`, `Guard`, `Verifier`, `Diagnostics` | `Repository\Index`, `Projector::load()` | `Availability\Reader`, `SlotReader`, `Maintenance` |
| Schema class, version | `Index\Schema`, 3 | `Index\Schema`, 3 | `Availability\Schema`, 2 |
| Structural change | `dbDelta` extends, rows stay valid | like `cb_index` | table is dropped and recreated (pure cache) |
| Change to the derivation | increase `Projector::PROJECTOR_VERSION` → rebuild | like `cb_index` | increase `Calculator::VERSION` → old rows invalid |

Data types follow two rules: whatever is searched or compared is a number (type, IDs, timestamps); text exists only
for status, repetition kind and restriction kind. And `NULL` has a fixed meaning in every column (see tables), so that
the SQL conditions reproduce the semantics of the old `postmeta` SQL exactly.

### 2.2 `{prefix}cb_index`: one row per post

Class: `Service\Index\Schema` (option `commonsbooking_index_schema_version`), written by
`Service\Index\Projector::buildRow()`.

**Content.** One row for every post of type `cb_timeframe`, `cb_booking` and `cb_restriction`, in **every** status
except `auto-draft`, so including `draft`, `trash` and `canceled`. Statuses are only filtered when reading, so that a
status change only changes one column and the guard recognises deleted or cancelled posts.

**What the row is not:** an interpretation. The projector computes no days, no availability and no bookability. It
translates meta values into columns and normalises only where the original code does so when reading as well (e.g.
`strtotime()` for legacy values). Whatever the index does not know is still decided by the PHP code after the posts
have been loaded (weekdays, `isBookable()`, roles).

#### Columns by group

**Identity and status** (from `wp_posts`)

| Column | Type | NULL / default | Origin and meaning |
|---|---|---|---|
| `post_id` | bigint unsigned, **PK** | – | `wp_posts.ID` |
| `post_type` | varchar(20) | – | `cb_timeframe`, `cb_booking` or `cb_restriction` |
| `post_status` | varchar(20) | – | `publish`, `draft`, `pending`, `private`, `trash`; for bookings `confirmed`, `unconfirmed`, `canceled` |
| `post_author` | bigint unsigned | 0 | for bookings the user **for whom** the booking was made. Basis for booking rules and "My bookings" (key `author_type`) |

**Domain type and assignment** (from the metas `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`,
for restrictions `restriction-item-id`, `restriction-location-id`)

| Column | Type | NULL / default | Origin and meaning |
|---|---|---|---|
| `type` | tinyint unsigned | 0 | meta `type`: 1 opening hours, 2 bookable, 3 holiday/closed, 4 off-holidays, 5 repair, 6 booking, 7 cancelled booking. **0 for restrictions** (they have no `type` meta) |
| `item_id` | bigint unsigned | **NULL** with multiple selection or without item | timeframe/booking: the single item. Restriction: `restriction-item-id`, **0 = all items** |
| `location_id` | bigint unsigned | like `item_id` | like `item_id`, for the location |
| `is_multi` | tinyint(1) | 0 | 1 if, after merging `*-id` and `*-id-list`, more than one item **or** more than one location remains. Then `item_id` and `location_id` are NULL and the assignment is in `cb_index_rel` |

**Period** (metas `repetition-start`, `repetition-end` or `restriction-start`, `restriction-end`)

| Column | Type | NULL means | Rules |
|---|---|---|---|
| `start_ts` | bigint (signed) | **no start stored** | numeric values unchanged, text via `strtotime()`. `0` is a valid start. Queries with `start_min`/`start_max` exclude NULL, like the old SQL, which required the meta key |
| `end_ts` | bigint (signed) | **open end** | `''`, `0`, `'0'` and a missing meta give NULL (like `Model\Timeframe::getTimeframeEndDate()`). Queries use `(end_ts IS NULL OR end_ts >= :from)` |

The timestamps are "local time as a Unix timestamp", the way CommonsBooking stores them: 10 Nov 2026 00:00 local time
is stored as `1794268800`, i.e. as if it were 00:00 UTC. The index does not convert time zones, so that all existing
comparisons stay the same.

**Repetition and grid** (timeframes and bookings only; empty for restrictions)

These columns are not needed for searching and are currently not evaluated by any read path. After the search, the
day computation still loads the `WP_Post` objects including `postmeta` (in bulk via `Index::loadPosts()`) and uses
the existing models. The columns are maintained and checked by the verifier anyway: they make the row *covering* and
are the basis for running weekday or grid filters directly in SQL later without changing the schema again.

| Column | Type | Origin | Meaning, example |
|---|---|---|---|
| `repetition` | varchar(8), NULL | `timeframe-repetition` | `norep` (once), `d` (daily), `w` (weekly), `m`, `y`, `manual`. NULL = not set |
| `weekdays` | tinyint unsigned | `weekdays` (array `'1'`…`'7'`, Monday = 1) | bit mask, Monday = bit 0 … Sunday = bit 6. Mon–Fri = `0b0011111` = 31, Sat+Sun = 96, all = 127 |
| `manual_dates` | text, NULL | `timeframe_manual_date` (comma-separated) | JSON array, only with `repetition = manual`, e.g. `["2026-12-01","2026-12-08"]` |
| `grid` | tinyint unsigned | `grid` | 0 = slot (one time window start–end), 1 = hourly |
| `full_day` | tinyint(1) | `full-day` | 1 if meta = `on` |
| `start_time`, `end_time` | time, NULL | `start-time`, `end-time` (`8:00 AM`, `18:00` …) | normalised to `H:i:s` |
| `grid_size` | tinyint unsigned, NULL | derived | like `Model\Timeframe::getGridSize()`: all day 24; slot = hours between start and end time (rounded, at most 255); otherwise value of `grid`. NULL if a time is missing for a slot |

**Booking rules** (timeframes only)

| Column | Type | Origin | Meaning |
|---|---|---|---|
| `max_days` | smallint unsigned, NULL | `timeframe-max-days` | maximum booking duration in days |
| `advance_days` | smallint unsigned, NULL | `timeframe-advance-booking-days` | advance booking window |
| `startday_offset` | smallint unsigned, NULL | `booking-startday-offset` | earliest booking day from today |
| `has_role_limit` | tinyint(1) | `allowed_user_roles` | 1 if the timeframe is bookable only for certain roles. Such pairs are always computed live |

Numeric values are clamped to 0…65535, non-numeric ones give NULL ("not set"). The evaluation stays in the PHP code:
e.g. 0 advance booking days count as "not set" there.

**Restrictions** (`cb_restriction` only)

| Column | Type | Origin | Meaning |
|---|---|---|---|
| `restriction_type` | varchar(10), NULL | `restriction-type` | `repair` (locks the item), `hint` (notice only) |
| `restriction_state` | varchar(10), NULL | `restriction-state` | `none`, `active`, `solved`. `Repository\Restriction::get()` only reads `active` |

**Housekeeping** (not part of the comparison whether a projection has changed)

| Column | Type | Meaning |
|---|---|---|
| `source_modified_gmt` | datetime, NULL | `post_modified_gmt` at the time of projection. The guard compares it hourly with `wp_posts` and thereby recognises changes made by a version without the booster |
| `projector_version` | smallint unsigned | `Projector::PROJECTOR_VERSION` (currently 2) with which the row was built |

#### Item/location assignment in detail

The projector forms the **union** of all values of `item-id` (including duplicate meta rows) and the list
`item-id-list`, removes values ≤ 0 and duplicates, and sorts. Likewise for locations. This corresponds to the previous
SQL in `Repository\Timeframe::getEntityQuery()`, which combined both metas with `OR`.

| Result | `item_id` | `location_id` | `is_multi` | `cb_index_rel` |
|---|---|---|---|---|
| one item, one location (bookings, bookable timeframes, over 99 %) | ID | ID | 0 | – |
| no item or no location (incomplete draft) | NULL | NULL / ID | 0 | – |
| more than one item **or** location (holidays, repairs with multiple, category or "all" selection) | NULL | NULL | 1 | one row per item and location |
| restriction | ID or **0 = all** | ID or **0 = all** | 0 | – |

#### Example rows (abridged)

| post_id | post_type | post_status | type | item_id | location_id | is_multi | start_ts | end_ts | other |
|---|---|---|---|---|---|---|---|---|---|
| 501 | cb_timeframe | publish | 2 | 812 | 455 | 0 | 1767225600 (1 Jan 2026) | NULL (open) | `repetition = w`, `weekdays = 31`, `full_day = 1`, `grid_size = 24`, `advance_days = 60` |
| 9301 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794268800 (10 Nov 2026 00:00) | 1794355199 (10 Nov 2026 23:59:59) | `post_author = 17` |
| 9302 | cb_booking | canceled | 6 | 812 | 455 | 0 | 1794441600 | 1794700799 | stays in the index, filtered out via `post_status` |
| 620 | cb_timeframe | publish | 3 | NULL | NULL | 1 | 1798070400 (24 Dec) | 1798329599 (26 Dec) | holiday for 3 locations, see `cb_index_rel` |
| 7004 | cb_restriction | publish | 0 | 812 | 0 | 0 | 1794268800 | NULL | `restriction_type = repair`, `restriction_state = active` (all locations of the item) |

#### Keys and what they are needed for

| Key | Columns | Used by |
|---|---|---|
| `PRIMARY` | `post_id` | projection (`REPLACE`), guard, verifier |
| `item_loc_range` | `item_id, location_id, type, end_ts, start_ts` | pair queries (calendar, map, conflict check on the index path, `SlotReader::getPairs()`). With `end_ts` in fourth place MySQL jumps directly to the posts that end after the start of the requested period: the run time depends on the number of current bookings, not of all historical ones |
| `loc_range` | `location_id, type, end_ts` | queries by location only |
| `type_range` | `type, end_ts, start_ts` | queries without item/location (e.g. reminder emails: bookings starting/ending on a day) |
| `author_type` | `post_author, type, start_ts` | bookings of a user (booking rules, "My bookings") |
| `multi_type` | `is_multi, type` | second part of the `UNION ALL` for multiple assignments |
| `ptype_item`, `ptype_location` | `post_type, item_id` / `post_type, location_id` | item ↔ location assignment (`getRelatedPosts`), restrictions, pair list |
| `ptype_end` | `post_type, end_ts` | restrictions by period |

### 2.3 `{prefix}cb_index_rel`: multiple assignments

| Column | Type | Meaning |
|---|---|---|
| `post_id` | bigint unsigned | post with `is_multi = 1` |
| `entity_type` | varchar(8) | `item` or `location` |
| `entity_id` | bigint unsigned | ID of the item or location |

Keys: `PRIMARY (entity_type, entity_id, post_id)` for the search "all posts for item X", `KEY post_id` for replacing
and deleting the assignments of a post. When writing, all rows of the post are deleted and reinserted
(`INSERT IGNORE`).

Example holiday 620 (all items 812, 813 at three locations):

| post_id | entity_type | entity_id |
|---|---|---|
| 620 | item | 812 |
| 620 | item | 813 |
| 620 | location | 455 |
| 620 | location | 456 |
| 620 | location | 457 |

Why a separate table instead of one row per pair: a holiday "all items × all locations" (500 × 300) would need
150,000 rows in the main table; the relation table needs 800. Bookings and bookable timeframes need no join. Queries
combine both cases with `UNION ALL` (not `OR`), so that each part uses its key:

```sql
SELECT i.post_id FROM cb_index i
 WHERE i.is_multi = 0 AND i.item_id IN (:items) AND i.location_id IN (:locs)
   AND i.type IN (:types) AND i.post_status IN (:status)
   AND (i.end_ts IS NULL OR i.end_ts >= :from) AND i.start_ts <= :to
UNION ALL
SELECT i.post_id FROM cb_index_rel ri
  JOIN cb_index_rel rl ON rl.post_id = ri.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (:locs)
  JOIN cb_index i      ON i.post_id  = ri.post_id
 WHERE ri.entity_type = 'item' AND ri.entity_id IN (:items)
   AND i.is_multi = 1 AND i.type IN (:types) …
```

### 2.4 `{prefix}cb_availability`: precomputed day state per pair

Class: `Service\Availability\Schema` (option `commonsbooking_availability_schema_version`), written exclusively via
`Service\Availability\Store`.

**Content.** One row per item/location pair that has a published bookable timeframe (type 2) which does not end
before the horizon (`Maintenance::getBookablePairs()`, source is `cb_index`). The row describes the days
**yesterday to today + 92**, i.e. 94 days (`Availability::getHorizon()`, filter
`commonsbooking_availability_horizon_days`), with one day of buffer backwards.

The table is a pure cache: every row can be deleted at any time; it is recreated on the next read.

#### Columns

| Column | Type | Default | Meaning |
|---|---|---|---|
| `item_id`, `location_id` | bigint unsigned, **PK** `(location_id, item_id)` | – | the pair. Location first because map and table load per location |
| `gen` | int unsigned | **1** | generation. Increased by 1 synchronously on **every** change to a post that affects the pair (`Store::invalidate()`) |
| `computed_gen` | int unsigned | **0** | generation from which `days` and `slots` were computed. New rows are therefore initially invalid |
| `days_from` | date, NULL | – | date of the first entry in `days` (on the day of computation: yesterday) |
| `days` | varbinary(1024), NULL | – | day facts, **2 bytes per day** from `days_from` (unsigned short, little endian). 94 days = 188 bytes, room for 512 days. NULL with role restriction |
| `slots` | mediumblob, NULL | – | free bookable slots per day for API and GBFS (`Slots::pack()`, see below). NULL with role restriction |
| `has_role_limit` | tinyint(1) | 0 | 1 = at least one bookable timeframe of the pair is restricted to roles. Then no days are stored, because the result depends on the user |
| `computed_at` | datetime (UTC), NULL | – | time of computation (`gmdate()`) |
| `calc_version` | smallint unsigned | 0 | `Calculator::VERSION` (currently 2) |

Keys: `PRIMARY (location_id, item_id)`, `KEY item_id` (invalidation by item only, e.g. restriction for all
locations), `KEY computed_at` (cron looks for the oldest rows).

`days` and `slots` are written as `UNHEX('…')` so that the SQL statement stays pure ASCII text: otherwise `wpdb`
strips bytes it considers invalid text. `Store::preload()` does not load `slots`, because map and table do not need
them; only `Store::loadWithSlots()` (for `SlotReader`) reads the column.

#### Life cycle of a row

| State | `gen` / `computed_gen` | `days` | How reached | On read |
|---|---|---|---|---|
| **created** | 1 / 0 | NULL | `Maintenance::syncPairs()` (hourly) or `Store::getGen()` before the first computation, each `INSERT IGNORE` | invalid → compute |
| **valid** | n / n | set | `Store::write()` after `Calculator::compute()` | day facts from `days` |
| **invalidated** | n+1 / n | old | `Store::invalidate()` on a change | invalid → compute |
| **aged** | n / n | set, `computed_at` > 24 h | time passes (cron recomputes from 12 h) | invalid → compute |
| **outdated computation version** | n / n | set, `calc_version` old | plugin update with a new `Calculator::VERSION` | invalid → compute |
| **role-restricted** | n / n | NULL, `has_role_limit = 1` | computation detects a role restriction | immediately live, without recomputation |
| **removed** | – | – | `syncPairs()`: pair no longer has a bookable timeframe | live (usually nothing to display) |

`Store::invalidate()` only increments existing rows. When a pair gets a bookable timeframe for the first time, there
is no row yet: the first reader creates it via `Calculator::recompute()`, at the latest the hourly `syncPairs()` does.

#### Validity of a row (`Store::isUsable()`)

A row is only used if **all** conditions hold:

1. `days` and `days_from` are set,
2. `has_role_limit = 0`,
3. `computed_gen = gen` (no change since the computation),
4. `calc_version = Calculator::VERSION`,
5. `computed_at` is at most 24 hours old (filter `commonsbooking_availability_max_age`).

In addition `Store::getFlags()` checks per day whether the date lies within `days`. If a requested day lies before or
after it, the `Reader` computes the whole pair live.

Correctness therefore does **not** depend on when the recomputation happens: a stale row is never served.

#### Concurrent changes without locks

`Calculator::recompute()` reads the generation `G` before computing and only writes if it still holds:

```sql
UPDATE cb_availability
   SET computed_gen = gen, days_from = ?, days = UNHEX('…'), slots = UNHEX('…'),
       has_role_limit = ?, computed_at = ?, calc_version = ?
 WHERE location_id = ? AND item_id = ? AND gen = G
```

| Time | Request A (computes) | Request B (books) | Row |
|---|---|---|---|
| t1 | reads `gen = 42` | | 42 / 41 |
| t2 | computes (without the new booking) | saves booking, `gen = gen + 1` | 43 / 41 |
| t3 | `UPDATE … WHERE gen = 42` matches **nothing** | | 43 / 41 (invalid) |
| t4 | | `shutdown`: reads `gen = 43`, computes, writes | 43 / 43 (valid, with booking) |

Without the condition `gen = G`, A would have marked a result without the new booking as valid at t3.

#### Column `days`: day facts (`Service\Availability\DayFacts`)

For every day the calendar builds the slot grid as before (`Day::getGrid()`): per time section the timeframe with the
highest priority (booking and holiday before bookable). `DayFacts::fromGrid()` condenses this grid into ten bits,
exactly according to the rules of the former `View\Calendar::processSlot()`:

| Bit | Value | Constant | set if … |
|---|---|---|---|
| 0 | 1 | `HAS_SLOTS` | at least one slot has a timeframe |
| 1 | 2 | `SLOT_LOCKED` | at least one slot is locked (holiday, repair, booking, restriction) or not bookable for the user |
| 2 | 4 | `ALL_LOCKED` | no slot is selectable (also without slots) |
| 3 | 8 | `HOLIDAY` | all slots are holidays (type 3 or 4) (also without slots) |
| 4 | 16 | `REPAIR` | legacy of the original: reset by every slot because text is compared strictly with a number there. In practice only set without slots |
| 5 | 32 | `BOOKED_DAY` | all slots are bookings or repairs (also without slots) |
| 6 | 64 | `PARTIALLY_BOOKED` | at least one slot is a booking, holiday or repair |
| 7 | 128 | `FIRST_SLOT_BOOKED` | the first slot is not bookable |
| 8 | 256 | `LAST_SLOT_BOOKED` | the last slot is not bookable |
| 9 | 512 | `FULL_DAY` | there is exactly one slot and its timeframe is all-day |

Bits 10 to 15 are free. Typical values:

| Day | Bits | Value | Bytes in `days` |
|---|---|---|---|
| no timeframe | `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY` | 60 | `3C 00` |
| bookable all day and free | `HAS_SLOTS`, `FULL_DAY` | 513 | `01 02` |
| booked all day | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (no `FULL_DAY`: bookings have no `full-day` meta) | 487 | `E7 01` |
| holiday | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (+ `FULL_DAY`) | 463 (975) | `CF 01` (`CF 03`) |

The value "no timeframe" shows why the bits must not be read on their own: without slots, `HOLIDAY` and `BOOKED_DAY`
are set. In this case `CalendarWindow::mapDay()` resets everything to "locked", like the original.

A day is read like this: offset = days between `days_from` and the date, then `unpack('v', $days, offset × 2)`.

**What is not stored** and is applied when reading by `CalendarWindow`:

| Rule | Source when reading |
|---|---|
| day lies in the past | today's date |
| day lies outside the advance booking window (`advance_days`) | bookable timeframes + today's date |
| day lies before the first bookable day (`startday_offset`) | bookable timeframes + today's date |
| day lies after the end of the requested range | parameters of the request |
| status for map/table (`no-slots`, `holiday`, `booked`, `partially-booked-start/-end`, `locked`, `available`) | `DayStatus::fromDayArray()` |
| slots with times for the booking calendar on the item page | still computed live there |

That is why nothing needs to be recomputed when the day changes. 12.2 describes one exception (`isBookable()`).

#### Column `slots`: free slots for API and GBFS (`Service\Availability\Slots`)

From the same grid `Slots::bookableSlots()` collects the slots whose highest-priority timeframe is bookable (type 2),
i.e. neither booked nor locked. That is exactly the condition the live method has always used. `Slots::pack()` stores
them little endian:

```
header   uint16  T = number of timeframes occurring
         T ×     uint32 timeframe ID, uint32 location ID, uint32 item ID   (0 = none)
per day  uint8   S = number of slots (at most 255)
         S ×     uint32 start, uint32 end (seconds from start of day), uint16 index into the header list
```

The days follow each other without gaps from `days_from`, without a date of their own. Example: timeframe 501,
bookable all day, one day free, one day booked:

```
01 00                          T = 1
F5 01 00 00  C7 01 00 00  2C 03 00 00     timeframe 501, location 455, item 812
01  00 00 00 00  7F 51 01 00  00 00         day 1: 1 slot, 0 … 86399 s, timeframe index 0
00                                          day 2: 0 slots (booked)
```

Size: 2-byte header plus 12 bytes per timeframe, per day 1 byte plus 10 bytes per slot. All day over 94 days ≈ 1 KB,
hourly grid with 10 slots ≈ 9.5 KB. The worst case (24 hourly slots on 94 days) is ≈ 22 KB. `mediumblob` instead of
`blob` (64 KB), so that half-hour or quarter-hour grids also fit safely.

**Why timeframe, location and item are in the header.** The API outputs `locationId` and `itemId` per slot, and those
are the values of the **timeframe** the slot comes from, not of the requested pair. For timeframes with one item and
one location they are the same. The header records them anyway, so that the result matches the live path byte for
byte. The reader also needs the timeframe ID for the "first bookable day" check.

**Why seconds from start of day instead of clock times.** What is stored is `timestampstart − start of day`, exactly
the way the existing code computes the slot. That keeps the peculiarities of the timestamps (local time as UTC,
daylight saving changes) exactly intact instead of reconstructing them when reading.

**Why in the same row as `days`.** Generation (`gen`/`computed_gen`), invalidation, `calc_version`, `has_role_limit`,
cron and verifier apply to the slots as well without any extra code. The rejected alternative was to derive slots
when reading from the day facts and the timeframe configuration (grid, start and end time from the index) and to
compute live only for "partially booked": that saves storage, but rebuilds the grid logic a second time and
contradicts the principle "one computation core for both paths" (6.1).

**Example: reading one day.** The question is about Monday, 9 Nov 2026, for the pair (812, 455); the row was computed
on 6 Nov (`days_from = 2026-11-05`):

1. Offset = 9 Nov − 5 Nov = 4 days. `Slots::unpack()` has unpacked the days as a list; day 4 contains one slot
   `[0, 86399, 0]`.
2. Index 0 in the header gives timeframe 501, location 455, item 812.
3. First bookable day of timeframe 501 (`Timeframe::getFirstBookableDay()`, depends on "today" and
   `booking-startday-offset`, determined once per timeframe per request): 6 Nov. 9 Nov is not before it, the slot
   stays.
4. Start of day 9 Nov = `1794182400`, so start `1794182400 + 0`, end `1794182400 + 86399`.
5. `Slots::toApiSlot()` turns this into `{"start":"2026-11-09T00:00:00+00:00","end":"2026-11-09T23:59:59+00:00",
   "locationId":"455","itemId":"812"}`; `Slots::addUnique()` prevents duplicates as on the live path.

With `ignoreStartDayOffset` (GBFS) step 3 is skipped. The same row therefore answers both variants.

**Introduction of the column on update.** The column was added later: `Availability\Schema::SCHEMA_VERSION = 2` and
`Calculator::VERSION = 2`. Anyone who had installed an earlier beta with version 1 gets the table dropped and
recreated on update (it is a pure cache, see 2.1); until the rows have been recomputed, map, table and API compute
pair by pair on request or via cron, as when first switching on. `SlotReader` does not use rows with `slots = NULL`
(e.g. role restriction); it recomputes the pair or falls back to live.

### 2.5 New options and cron events

| Option | autoload | Content |
|---|---|---|
| `commonsbooking_options_advanced-options[booster_enabled]` | (existing) | switch (`on`) |
| `commonsbooking_booster_state` | yes | `active`, `activated_at`, `plugin_version`, `watermark` (highest checked post ID) |
| `commonsbooking_index_schema_version`, `commonsbooking_availability_schema_version` | yes | installed table structure |
| `commonsbooking_index_ready` | yes | projector version of the complete backfill |
| `commonsbooking_index_backfill_cursor` | – | progress of the backfill |
| `commonsbooking_availability_pairs_synced` | – | last sync of the pair list |
| `commonsbooking_booster_checks` | no | last run and result of every check |
| `commonsbooking_booster_activity` | no | ring buffer of the last 30 index changes (diagnostics) |

| Cron hook | Interval | Class | Task |
|---|---|---|---|
| `commonsbooking_index_backfill` | reschedules itself (20 s per run, 500 posts per batch) | `Index\Backfill` | building the index |
| `commonsbooking_index_verify` | daily 04:00 | `Index\Verifier` | sample of 200 posts against the projection, repairs |
| `commonsbooking_booster_guard` | hourly | `Booster\Guard` | deep check of status/modification date |
| `commonsbooking_availability_refresh` | hourly (+ on demand) | `Availability\Maintenance` | recompute new pairs, changed rows and rows older than 12 h |
| `commonsbooking_availability_verify` | daily 04:30 | `Availability\Maintenance` | sample of 50 pairs against a fresh computation, repairs |

Switching off the booster and deactivating the plugin remove all cron events.

---

## 3. The switch: `Service\Booster\Booster`

### 3.1 States

| State | Condition | Behaviour |
|---|---|---|
| **off** (default) | setting not `on` | `Booster::init()` only registers the settings page and WP-CLI. No hook, no cron, no reading. Code paths identical to 2.11.3. |
| **building** | on, backfill running | hooks already write to the index; reading still uses the original queries |
| **active** | on, backfill finished | repositories read from the index, map and table from `cb_availability` |
| **bypassed** | inside `Booster::withoutBooster()` | original queries, no plugin cache; writing to the index continues |

Decisive checks:

```php
Booster::isEnabled()   // setting + filter commonsbooking_booster_enabled
Booster::canRead()     // on, not bypassed, state['active']
Backfill::isReady()    // canRead() + filter commonsbooking_use_index + schema installed + backfill finished
Index::isAvailable()   // = Backfill::isReady()   → repositories
Availability::isEnabled() // filter commonsbooking_use_availability_cache + schema + Backfill::isReady() → map/table
```

### 3.2 Life cycle

- **Switching on** (setting, `wp commonsbooking booster enable [--now]`, plugin activation): `Booster::onInit()`
  (on `init` with priority 0) detects the change and calls `activate()`: create tables, **always rebuild the index
  completely** (nobody maintained the index while the booster was off), save the state.
- **Switching off:** `deactivate()` resets the backfill (the index counts as outdated) and removes the cron jobs. The
  tables remain; switching on again only needs a refill.
- **Plugin activation/deactivation:** `Plugin::activation()` calls `Booster::onPluginActivation()` (state set to
  inactive, rebuild if the booster is switched on), `Plugin::deactivation()` calls `onPluginDeactivation()`.
- **Removal:** `wp commonsbooking booster purge` deletes tables, options and cron jobs.

### 3.3 Guard against changes made without the booster (`Service\Booster\Guard`)

Scenario: downgrade to a version without the booster, bookings are created or cancelled there, then upgrade again. The
old version leaves no marker; the guard recognises the consequences in the data.

| Check | When | Detects | Consequence |
|---|---|---|---|
| Plugin version | every request | `commonsbooking_plugin_version` was changed by other code (the booster remembers its own writes via `update_option_…`) | rebuild |
| New posts without an index row | every request, only IDs above `watermark`, only after a 2-minute grace period | posts created by another version | rebuild |
| Status and modification date | hourly, `booster check --deep` | cancellations/changes by the other version, deleted posts | repair individually, rebuild from 200 deviations |

Pure meta changes without saving the post are only found by the daily index verifier.

---

## 4. Index: write path

### 4.1 Components

- **`Service\Index\Indexer`** registers the hooks, collects affected post IDs per request (deduplicated) and writes
  them with `flush()`.
- **`Service\Index\Projector`** reads a post with all its meta data, builds the row and the relations and writes them
  via `REPLACE INTO` or delete + `INSERT IGNORE`. If the projection has changed (ignoring the housekeeping columns), it
  fires `commonsbooking_index_rows_changed` with the **old and new** row. The old row matters: if a booking is moved
  to another item, the old pair has to be recomputed as well.
- **`Service\Index\Backfill`** builds the index in batches (cron or CLI) and fires `commonsbooking_index_rebuilt` at
  the end.
- **`Service\Index\Verifier`** compares stored rows with a fresh projection and repairs them.

### 4.2 Hooks

| Trigger | Hook | Action |
|---|---|---|
| Post saved | `save_post`, priority `PHP_INT_MAX` (after `Timeframe::savePost` with 11, which rewrites meta data and sets invalid timeframes to `draft`) | mark and `flush()` immediately |
| Meta data (CMB2, importer, `update_post_meta`) | `added_post_meta`, `updated_post_meta`, `deleted_post_meta`, relevant keys only | mark |
| Status change, trash | `transition_post_status` | mark |
| Deletion | `deleted_post` | remove row and relations |
| Safety net | `shutdown`, priority 0 | `flush()` (runs even after fatal errors) |
| Cancellation | **`Model\Booking::cancel()`** sets the status via direct SQL, without post hooks | explicit `Indexer::touch()` + `flush()` (see 7.1) |

Relevant meta keys: `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`, `repetition-start`,
`repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`, `start-time`,
`end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`, `allowed_user_roles`,
`restriction-*`. All others (e.g. `_edit_lock`, booking codes) trigger nothing.

**Rule for new code:** whoever writes posts of these three types **without** WordPress hooks (direct SQL) must call
`Indexer::touch( $postId )` and `Indexer::flush()`.

### 4.3 Read-your-writes

`Repository\Index::findPostIds()` and `Availability\Reader` call `Indexer::flush()` before reading. Changes made in
the current request are therefore visible immediately, even if `save_post` has not run yet.

---

## 5. Index: read path and converted repositories

### 5.1 New query layer `Repository\Index`

```php
Index::isAvailable(): bool
Index::findPostIds( array $criteria ): int[]      // sorted by post_id
Index::loadPosts( int[] $ids ): WP_Post[]         // _prime_post_caches + update_meta_cache in bulk
Index::sortByDate( array $posts, 'ASC'|'DESC' )   // like WP_Query: post_date, ties by ascending ID
```

Criteria: `post_types`, `types`, `statuses`, `items`, `locations`, `start_min`, `start_max`, `end_min`, `end_max`,
`end_exact`, `require_end`, `authors`, `restriction_states`. Empty lists mean "no filter". `start_min`/`start_max`
require a start to be set (matches the old SQL, which required the meta key), `end_min`/`end_max` allow an open end.

Loading in bulk replaces the former individual `get_post()` calls (over 17,000 queries in the map scenario).

### 5.2 Mapping old → new

Every method first checks `Index::isAvailable()`. If the index is not available, the unchanged original code runs.

| File / method | 2.11.3 | Now (booster active) |
|---|---|---|
| `Repository\Timeframe::get()`, `getInRange()` and therefore `getBookable*`, `getInRangeForCurrentUser` | `getPostIdsByType()` (full scan of `postmeta`) + `getPostsByBaseParams()` | new private method `queryPosts()`: date/period filter directly in SQL via `Index::findPostIds()`. Historical bookings are no longer loaded. PHP filters (weekdays, `isBookable`, roles) and cache unchanged |
| `Repository\Booking::getByDate()`, `getByTimerange()` (→ `getExistingBookings`, `getByRestriction`, booking rules), `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` with up to 5 meta joins | new private method `findViaIndex()`. Supports `$customArgs` `author`, `post_status`, `orderby=date`, `order`; with any other argument automatically the `WP_Query`. Sorting like `WP_Query` (`post_date DESC`) |
| `Repository\Restriction::get()` | `queryPosts()` via SQL on `postmeta` | `queryPostsFromIndex()` with `restriction_state = active`. PHP filter for item/location (incl. "all") unchanged |
| `Repository\BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `WP_Query` with meta query `{type}-id` | `getTimeframesByRelation()`: candidates from the index, then a filter on the **single** `*-id` value (old semantics), related posts loaded in bulk |
| `Model\Map::get_locations()` | `Timeframe::getBookableForCurrentUser()` per pair | **one** query for all pairs (`Map::getBookableTimeframesByPair()`), items per location, term cache and thumbnails in bulk. This bundling also applies without the booster |
| `Repository\Booking::getForUser()` ("My bookings", `BookingRuleApplied`) | load all bookings of the instance, filter by visibility in PHP | load only candidates (5.4), **also without the booster** |
| `Model\Item::getClosestBooking()` (→ `getCloakedId()`, GBFS `vehicle_id`) | load all confirmed bookings of the pair, filter for past ones in PHP and sort | load only the past bookings with the latest start (5.5) |

Not converted: admin lists, `Booking::getForUserPaginated()` (paginates via `WP_Query` anyway).

### 5.3 Restrictions per period instead of per day

This applies **regardless of the booster**:

- `Model\Calendar` loads the active restrictions of the whole range once (`Restriction::get()` with
  `minTimestamp = start − 1`) and passes them via `Week` to every `Day` (new optional constructor parameter
  `$possibleRestrictions`).
- `Day::getRestrictions()` filters the preloaded list with `isInRestriction()`, which reproduces the same date
  condition as the previous per-day query. Without a preloaded list (`null`, direct use of `Day`) the query remains.
- New constant `Day::RESTRICTION_STATUSES = [ 'publish', 'confirmed', 'unconfirmed' ]`.

Effect: 3,000–6,000 fewer individual queries per map or table request.

### 5.4 "My bookings" without a full scan: `Repository\Booking::getForUser()`

**Situation in 2.11.3.** `getForUser()` loaded **all** bookings of the instance (with status and `minTimestamp`) and
then filtered them in PHP with `commonsbooking_isUserAllowedToSee()`. With 167,000 bookings that meant, for a user
with three bookings of her own: load 167,000 posts, discard 166,997. A booking is visible if

1. the user is a CB admin (`commonsbooking_isUserAdmin()`, including filter), or
2. they are the author of the booking, or
3. they are an admin of the item **or** the location of the booking. `Timeframe::getAdmins()` is the union of the meta
   `_cb_item_admins` or `_cb_location_admins` and the `post_author` of item and location respectively.

Callers: `View\Booking::getBookingListData()` ("My bookings" page, up to four times if the result is empty) and
`BookingRuleApplied` (rule "only one unconfirmed booking").

**New: candidates first, then the same filter.** The private method `getVisibleCandidates()` determines a superset of
the visible bookings without loading all of them:

```
CB admin:   all bookings as before (the result is "all" anyway)
otherwise:  own bookings                      Repository\Timeframe::getByAuthor( user, [booking] )
          ∪ bookings of managed items         Booking::get( [], getManagedPostIds( user, cb_item ) )
          ∪ bookings of managed locations     Booking::get( getManagedPostIds( user, cb_location ), [] )
            sorted by ID, duplicates removed
```

Then `commonsbooking_isUserAllowedToSee()` runs **unchanged** over the candidates. Because the candidates are a
superset, the result is exactly the same; the filter now only costs in proportion to the candidates.

- `getByAuthor()` is new in `Repository\Timeframe`: with the index via the `authors` criterion (key `author_type`),
  without the index via SQL on `post_author` plus meta `type`. Same filters as `Timeframe::get()`.
- `getManagedPostIds()` finds managed items or locations: `post_author = user` **or** the admin meta contains the
  user. The meta is sometimes a serialised list (`a:2:{i:0;s:2:"17";i:1;i:23;}`), sometimes a single string (`"17"`).
  The SQL query narrows it down roughly with `LIKE '%17%'`, PHP then checks exactly (`maybe_unserialize`, comparison
  as a number). That way user 1 does not match the meta of user 11 or 17. All post statuses, because `getAdmins()`
  does not check the status either.
- `Booking::get()` uses the index if available, otherwise the original query. The improvement therefore works **also
  without the booster**: instead of all bookings, only those of the managed items and locations are loaded.

**Example.** User 17 has 3 bookings of her own and is admin of location 455, which has 1,200 bookings. The instance
has 167,000 bookings.

| | 2.11.3 | now |
|---|---|---|
| bookings loaded | 167,000 | 3 + 1,200 = 1,203 |
| checked with `isUserAllowedToSee()` | 167,000 | 1,203 |
| result | 1,203 visible | the same 1,203, same order |

**`BookingRuleApplied`** checks "only one unconfirmed booking per user". Previously it fetched all *visible*
unconfirmed bookings and threw away the ones of other users afterwards (so a location admin also got the unconfirmed
bookings of their customers). Now it queries `getForUserPaginated( $user, …, [ 'unconfirmed' ] )` directly: only own,
only unconfirmed, via `WP_Query`. Deliberately not via the index, because booking rules belong to booking validation.

**Covered by** `BookingTest::testGetForUserMatchesFilteringAllBookings`: guest, subscriber as author, item admin,
location admin, admin via the location's `post_author`, admin with a string meta, CB admin; each with three variants
of status and `minTimestamp`, with and without the index; old and new results identical in IDs and order. In addition
`CustomPostType\BookingTest::testHandleBookingRequest_onlyOneUnconfirmedBooking_managedItem`: another user's
unconfirmed booking on a managed item does not block, an own one does.

Still open: for **CB admins** "My bookings" still loads all bookings from `startDate` and paginates in PHP (12.3).

### 5.5 Latest past booking: `Model\Item::getClosestBooking()`

**What the method is for.** GBFS publishes a `vehicle_id` for every vehicle. So that nobody can follow a vehicle's
trips via the ID, it is cloaked and changes after every rental:

```php
Item::getCloakedId() = wp_hash( $item->ID . ( getClosestBooking()->post_name ?? $item->post_name ) )
```

`getClosestBooking()` returns the **most recently started, already ended, confirmed** booking of the item at its
current location. The cloaked ID is used in three places:

| Caller | For what |
|---|---|
| `API\GBFS\VehicleAvailability` (`vehicle_availability.json`) | `vehicle_id` for **every** item |
| `API\GBFS\VehicleStatus` (`vehicle_status.json`) | `vehicle_id` and link `getCloakedURL()` for every currently free item |
| `Repository\Item::getByCloakedId()` (redirect `?commonsbooking_get_vehicle=1&commonsbooking_vehicle_cloaked_id=…`) | computes the ID of **all** items until one matches |

**The problem.** The original method loads all confirmed bookings of the pair as models including meta data, then
filters for `isPast()` in PHP and sorts. The work grows with the entire booking history. In the development
environment (167,000 bookings, 126 items, about 1,300 bookings per item) this cost about 10 MB and 95 ms **per item**.
As a result `vehicle_availability.json` needed 13 s and 1.3 GB of memory and aborted in the web server
(`memory_limit = 128M`) with error 500. The bug already exists in 2.11.3. The booster did not cause it, but only made
it visible, because the availabilities themselves now cost practically nothing.

**The solution.** The method sorts first by start date (`getStartDate()`), only on a tie by start time
(`getStartTimeDateTime()`), and then takes the last element. So only a booking with the **latest start** can win. New
in `Repository\Booking`:

```php
Booking::getLatestPastConfirmed( int $itemId, int $locationId, int $now ): ?array   // null = index not available
```

It runs two queries on `cb_index` (key `item_loc_range`) and loads only the winning candidates:

```sql
-- 1. latest start among the past confirmed bookings of the pair
SELECT MAX(COALESCE(start_ts, 0)) FROM wp_cb_index
 WHERE item_id = 812 AND location_id = 455 AND type = 6 AND post_type = 'cb_booking'
   AND post_status = 'confirmed' AND (end_ts IS NULL OR end_ts < :now)

-- 2. all bookings with exactly this start (usually one)
SELECT post_id FROM wp_cb_index WHERE … AND COALESCE(start_ts, 0) = :latestStart ORDER BY post_id
```

`getClosestBooking()` applies the **unchanged** sorting to these candidates. If the index is not available (booster
off, index being built, `withoutBooster()`), the original code runs.

**Why the result stays exactly the same.** The `vehicle_id` is public; a deviation would look like a rental to GBFS
consumers. The query therefore reproduces every peculiarity of the original:

| Peculiarity of the original | Implementation on the index path |
|---|---|
| only status `confirmed`, only type 6 | same conditions |
| "past" = `getEndDate() < current_time( 'timestamp' )`, end as `intval()` | `end_ts < :now` with `current_time( 'timestamp' )`. A booking **without an end** counts as past in the original (`intval('') = 0`); in the index its end is then `NULL`, hence `end_ts IS NULL OR …` |
| missing start counts as 0 | `COALESCE(start_ts, 0)` |
| tie on start date: start time decides | all candidates with the same start are loaded, the original sorting decides |
| tie on start date and start time: `usort` is stable, `array_pop` takes the last one in input order, which is ascending by `post_id` | candidates `ORDER BY post_id`, so the same one wins (highest ID) |
| location = current location (`getLocation()`) | determined beforehand, unchanged |

**Example.** Cargo bike 812 at location 455, today 6 Nov 2026 12:00, bookings:

| Booking | Status | Start | End | Result |
|---|---|---|---|---|
| 8100 | confirmed | 1 Mar 2024 | 2 Mar 2024 | past, but early start |
| … 1,300 more … | confirmed | 2024–2026 | | past |
| 9290 | canceled | 3 Nov 2026 | 3 Nov 2026 | status does not match |
| 9295 | confirmed | 4 Nov 2026 | 4 Nov 2026 | **latest past start** |
| 9301 | confirmed | 10 Nov 2026 | 10 Nov 2026 | not yet ended |

Query 1 returns the start of 4 Nov, query 2 the ID 9295. A single post is loaded instead of 1,300. The `vehicle_id`
is `wp_hash( '812' . post_name of 9295 )`, exactly as before.

**Side effects checked.**

- *Same `vehicle_id`:* the script `dev-bench/closest-parity.php` compares new against old for every item. Development:
  126 items, 0 deviations (25 without a past booking). Test environment: 41 items, 0 deviations.
- *Outdated index:* if the index misses a cancellation that happened past the hooks, the `vehicle_id` can be based on
  a cancelled booking until the guard repairs it (hourly). Consequence: a different but valid cloaked ID, as after a
  rental. No effect on bookings. `getByCloakedId()` computes along the same path and therefore still finds the item.
- *Redirect via the cloaked ID:* `getByCloakedId()` benefits too, because it computes the ID of all items.
- *Cache:* the original went through the plugin cache of `Booking::get()`, the index path does not. Two small
  queries per item are cheaper than a cache access to 1,300 models.
- *Without the booster* the method stays as slow as in 2.11.3 (see 12.3).

**Measurement** (development, 126 items, 167,000 bookings):

| | before | after |
|---|---|---|
| `getClosestBooking()` for all items | 12.0 s | 0.9 s |
| `vehicle_availability.json` in WP-CLI | 13.3 s, 1,289 MB | 0.8 s, 71 MB |
| `vehicle_availability.json` via HTTP (128 MB) | error 500 | 200 in 0.65 s, 67 KB |

**Test:** `tests/php/Model/ItemTest.php::testGetClosestBooking_indexMatchesLegacy` compares the index path and the
original path step by step: no booking; unconfirmed, cancelled, running bookings and bookings at another location do
not count; an older past booking; same start with different start time; same start and same start time (higher ID
wins); booking without an end.

---

## 6. Precomputed availability

### 6.1 Refactoring of the computation core (`View\Calendar`)

The biggest risk of a precomputation is that stored and live-computed days drift apart. That is why the computation
was moved out of `View\Calendar` into three classes that **both paths** use:

| New class | Takes over from 2.11.3 | Task |
|---|---|---|
| `Availability\DayFacts` | `processSlot()` (all slots of a day) | date-independent day facts from the slot grid (`fromGrid()`), packing/unpacking as a bit mask (`fromFlags()`, `getFlags()`), day array (`toDayArray()`) |
| `Availability\CalendarWindow` | window logic from `getCalendarDataArray()`, defaults from `prepareJsonResponse()`, window rules from `mapDay()`, `getLockedDayArray()` | start/end, advance booking days, last bookable day, first bookable day, past days; `mapDay()` applies the window to the facts |
| `Availability\DayStatus` | status logic that previously existed twice (`MapItemAvailable::markDaysInTimeframe()` and `renderItemLocationRow()`) | one status per day: `no-slots`, `holiday`, `booked`, `partially-booked-start`, `partially-booked-end`, `locked`, `available` |

Changes in `View\Calendar`:

- removed: `processSlot()`, `getLockedDayArray()`, `getDefaultCalendarEnddateTimestamp()`;
- `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )` with a new signature;
- new: `renderJsonResponse()`; `prepareJsonResponse()` keeps its public signature;
- `renderTable()`/`renderItemLocationRow()` read via the `Reader` (new optional parameter `?Reader $reader`).

`MapItemAvailable`: `markDaysInTimeframe()` is replaced by `markDays()` and the table `STATUS_MAPPING` (`DayStatus` →
map status). In addition the map now only computes the **displayed** days (previously 60 instead of 12).

Peculiarities of the original were deliberately kept (e.g. `repair` is reset by every slot, 0 advance booking days
count as "not set"). A snapshot test records the output of 2.11.3 (see 11).

**Two fixes in the item table `[cb_items_table]`** (also apply without the booster):

1. *Broken markup for locked days.* `renderItemLocationRow()` fills up the days after the end of the status range
   (advance booking limit, end of the timeframe). In 2.11.3 it wrote `"<span'is-locked'></span>"` there. Browsers read
   that as an unknown element `span'is-locked'` **without a class**, so the cells appeared empty instead of grey,
   although the comment says "not available" and the CSS rule `.is-locked` exists in `_shortcodeItemsTable.scss`. Now
   the markup comes from the same table as for all other statuses:

   ```php
   $days_display[ $dayIterator ] = self::TABLE_STATUS_MARKUP[ DayStatus::LOCKED ];   // <span class='is-locked'></span>
   ```

   Visible consequence: for a table over 31 days and a timeframe with 14 days of advance booking, days 15 to 31 are now
   grey instead of white. That was intended from the start and belongs in the changelog. The snapshot did not need to
   be re-recorded: the fill-up loop only runs without `keepDaterange`, which the snapshot scenarios do not use. Test:
   `View\CalendarTest::testRenderItemLocationRowLocksDaysWithoutStatus` (the reader returns fewer days than displayed;
   the test fails without the fix).
2. *Cache key of the table row.* The key is now `item_location_today_days`. Previously separators and the number of
   days were missing, so that for example item 1 / location 23 and item 12 / location 3 could collide, and a table with
   31 days got the cached row of a table with 14 days. An inner cache item in `renderItemLocationRow()` that was never
   read back is removed.

### 6.2 Reading: `Availability\Reader`

```php
$reader = new Reader();
$reader->preload( [ [ $itemId, $locationId ], … ] );    // rows + bookable timeframes of all pairs in one query each
$reader->getDayStatuses( $itemId, $locationId, $start, $end, $keepDaterange, $rangeFrom, $rangeTo );
Reader::evaluateLive( … );                               // original path via Calendar::getCalendarDataArray()
```

Flow per pair:

1. `CalendarWindow::forTimeframes()` determines the window from the bookable timeframes and **today's** date.
2. Facts are needed for the days in the booking window:
   - row valid (`Store::isUsable()`) → facts from the bit mask;
   - row invalid/missing, range within the horizon → `Calculator` computes the pair, writes the row, uses the result;
   - role restriction or range beyond the horizon → `evaluateLive()`.
3. `CalendarWindow::mapDay()` applies the window, `DayStatus::fromDayArray()` returns the status.

Used by `MapItemAvailable::create_items_availabilities()` and `View\Calendar::renderTable()` when
`Availability::isEnabled()`. **Not** used by: booking calendar on the item page (needs slots), booking validation. API
and GBFS read via `SlotReader` (6.6).

### 6.3 Computing: `Availability\Calculator` and `Store`

- `Calculator::compute()` computes the days `today − 1` to `today + 92` (filter
  `commonsbooking_availability_horizon_days`) via `Model\Calendar` and `DayFacts::fromGrid()`, inside
  `Plugin::withoutCache()`, so that no cached weeks from before a change flow in.
- `Store::write()` writes optimistically:

  ```sql
  UPDATE cb_availability SET computed_gen = gen, days_from = ?, days = UNHEX('…'), slots = UNHEX('…'),
         has_role_limit = ?, computed_at = ?, calc_version = ?            -- computed_at: gmdate(), i.e. UTC
   WHERE location_id = ? AND item_id = ? AND gen = G
  ```

  `G` is the generation read before the computation. If a parallel booking has increased `gen` in the meantime, the
  `UPDATE` matches nothing and the row stays invalid. That way, without locks, there is no time window in which stale
  data appears valid.

### 6.4 Invalidating: `Availability\Invalidator`

Listens to `commonsbooking_index_rows_changed` (old + new row) and `commonsbooking_index_rebuilt` (all pairs).

1. **Determine affected pairs** (`getScope()`): a single pair; with multiple selection all pairs from the relation
   rows; with restrictions with 0 all pairs of the item or location.
2. **Filter:** only effective statuses (timeframes `publish`, bookings `confirmed`, restrictions
   `Day::RESTRICTION_STATUSES`) and only if the period touches the horizon (with 2 days of buffer). Restrictions
   without a start trigger nothing. Cleaning up old bookings therefore invalidates nothing.
3. **Synchronously** in the same request: `UPDATE … SET gen = gen + 1 WHERE …` (under 1 ms).
4. **Recompute:** up to 25 pairs (filter `commonsbooking_availability_sync_limit`) at the end of the request on
   `shutdown`, under PHP-FPM after `fastcgi_finish_request()`, i.e. after the response has been delivered (filter
   `commonsbooking_availability_finish_request`). Larger changes (e.g. a holiday for all locations) are only
   invalidated; the cron catches up and readers compute missing pairs themselves.

Result: a booking is visible on the next page load. The user does not notice the recomputation of the pair (approx.
13 ms).

### 6.5 Operation: `Availability\Maintenance`

- hourly: create rows for new pairs, remove pairs without a bookable timeframe (once a day), recompute changed rows
  and rows older than 12 h (20 s budget, then rescheduled). This makes the horizon move along;
- daily: sample of 50 pairs against a fresh computation, repair deviations;
- rows older than 24 h are never used. This limits the effect of writes that bypass the hooks.

### 6.6 API and GBFS: `Availability\SlotReader`

#### Who requests slots

All availability data of the REST API and the GBFS feeds goes through `Model\Calendar::getAvailabilitySlots()`. This
method first asks `SlotReader::getAvailabilitySlots()`. If that returns `null`, `getAvailabilitySlotsLive()` computes
as before (the unchanged original code, just renamed). The calendar loads timeframes and restrictions only when
computing live (`loadPosts()`), no longer in the constructor; a calendar served from the store therefore runs no
timeframe queries at all.

| Caller | Calendar | Path with booster |
|---|---|---|
| `API\AvailabilityRoute::get_item()` (`/wp-json/commonsbooking/v1/availability/<id>`) | today to today + `api_future_availability_weeks`, one item, all locations | stored |
| `API\AvailabilityRoute::get_items()` (`/availability`) | as above, **per item individually** (`getItemData( $item->ID )` in a loop over all items) | stored |
| `API\GBFS\StationStatus` via `Item::isCurrentlyFreeAtLocation( $loc, true )` | yesterday to tomorrow, one pair, `ignoreStartDayOffset` | stored |
| `API\GBFS\VehicleStatus::getAvailableUntil()` | yesterday to end of the maximum booking duration, one pair, `ignoreStartDayOffset` | stored |
| `API\GBFS\VehicleStatus` via `Item::isCurrentlyFreeAtLocation( $loc, true, true )` | yesterday to tomorrow, one pair, `ignoreRestrictions` | **live** (see fallbacks) |

So the collection route `/availability` never passes an empty item filter, although `getItemData( $id = null )` would
allow it in principle. The fallback "no item filter" only applies to custom code that builds a calendar without items.

#### Storing

`Calculator::compute()` collects, from the same grid that the day facts come from, the slots that the live method
would output (`Slots::bookableSlots()`: the highest-priority slot is bookable, not booked, not restricted). Format and
size are described in 2.4 (column `slots`). In short (`Slots::pack()`, little endian):

```
header:   uint16 number of timeframes, per timeframe uint32 ID, uint32 location, uint32 item
per day:  uint8 number of slots, per slot uint32 start, uint32 end (seconds from start of day), uint16 timeframe index
```

The "first bookable day" check (`Timeframe::getFirstBookableDay()`, depends on today's date and the advance booking
offset) happens when **reading**. `ignoreStartDayOffset` is therefore only a switch when reading and not a separately
stored variant.

#### Reading step by step

1. **Pre-checks**, see fallbacks below.
2. **Determine calendar days:** `Reader::getCalendarDates()` returns the days from the start date to the **Sunday** of
   the end date's week, exactly like the live calendar (it works week by week). Example: request Thu 6 Nov to Thu 20 Nov
   → days 6 Nov to Sun 23 Nov.
3. **Take over the request's changes:** `Indexer::flush()`, so that a booking saved in the same request has already
   marked its pair as stale.
4. **Determine pairs** (`getPairs()`): one index query for bookable timeframes of the items that overlap the range,
   optionally filtered by location. A timeframe with several items or locations (`is_multi = 1`) leads to `null`.
5. **Load rows:** all pairs with one query (`Store::loadWithSlots()`). An invalid or missing row is computed
   immediately as with the `Reader` (`Calculator::recompute()`), not computed live.
6. **Assemble per day:** determine the day offset in the row (`Store::getDayOffset()`), unpack the slots, with
   `ignoreStartDayOffset = false` discard slots before the first bookable day of the timeframe (determined once per
   timeframe and request), sort all pairs of the day by start time and deduplicate via `Slots::addUnique()` as on the
   live path.

Constructed example `/availability/513` on 6 Nov with `api_future_availability_weeks = 2`; item 513 has an all-day
timeframe 60 at location 487 with two days of lead time and a booking on 12 Nov:

| Step | Result |
|---|---|
| calendar days | 6 Nov to 23 Nov (18 days) |
| pairs | `[513, 487]` |
| row | `days_from = 5 Nov` (yesterday), offset of 6 Nov = 1 |
| first bookable day of timeframe 60 | 8 Nov; 6 Nov and 7 Nov are dropped |
| stored slots | all day on every day except 12 Nov |
| output | 15 objects `{start: "2026-11-08T00:00:00+00:00", end: "2026-11-08T23:59:59+00:00", locationId: "487", itemId: "513"}` etc., 12 Nov is missing |

As in the original, the times are local time with the suffix `+00:00` (kept unchanged so that the output does not
change).

#### Fallbacks to the live path

`SlotReader` returns `null`, and the calendar then computes as in 2.11.3:

| Condition | Reason | occurs in practice |
|---|---|---|
| precomputation off (`Availability::isEnabled()`: booster off, index off or filter `commonsbooking_use_availability_cache`) | no data | – |
| `ignoreRestrictions = true` | stored slots always take restrictions into account; a second variant would double the storage | GBFS `vehicle_status`, check "free right now" |
| no item filter | pairs across all items would be a full query, and without a filter the live calendar also behaves differently (see deviations) | custom code only |
| calendar days outside the horizon (default: yesterday to today + 92 days) | no row for these days | `api_future_availability_weeks` above 12 |
| timeframes with several items/locations in the range | are not precomputed | installations with multi-timeframes |
| pair with role restriction | result depends on the user | timeframes with roles |
| computed row does not match the current horizon or day not covered | day change during the request | rare |

#### Deviations from the live path

Both are bugs of the live path from 2.11.3. `SlotsTest::testKnownLiveDeviations()` records them; on dev they were
confirmed with real data (comparison of `/availability` for all items, stored against live, 6 Nov 2026):

1. **Restrictions without a location filter.** `/availability` builds the calendar without a location. Live,
   `Restriction::get( [], [ $item ] )` then only finds restrictions without a location and misses all those that carry
   an item **and** a location, as well as location-wide restrictions (item 0). Repair days appear as free. Stored, they
   take effect, because every pair is computed with its location.
   - Example on dev: restriction 167747 for the pair item 167745 / location 167744: free live, locked when stored.
   - Example on dev: location-wide restriction 167753 (item 0) for location 167751: live it affects none of the items
     at this location, stored it affects all of them.
   - In addition the result is the union of the pairs instead of a common grid. That is the same as long as an item
     has no overlapping timeframes at different locations.
2. **Days after the end date.** The calendar always runs until Sunday, but live it only loads timeframes and bookings
   that overlap the requested range (up to the end date). A booking starting after the end date is missing live; the
   day appears free. Stored, it is taken into account.
   - Example on dev (end date Thu 20 Nov, calendar until Sun 23 Nov): bookings 3969, 9851 and 16252 on 21 Nov are
     output as free live, correctly as taken when stored.

There are no other differences; the verifier additionally compares the slots in its sample (`Maintenance::matches()`),
and `SlotsTest` compares stored against live for daily and hourly grids, start offset, repair state and all fallbacks.

#### Measurements on dev

dev: 126 items, 167,000 bookings, `api_future_availability_weeks = 2`, measured in the PHP process (WP-CLI, "live"
with the precomputation switched off):

| Route | Booster | live |
|---|---|---|
| `/availability` (all items) | 605 ms | 1,067 ms |
| `/items` | 503 ms | 969 ms |
| GBFS `station_status` | 138 ms | 194 ms |
| GBFS `vehicle_status` | 281 ms | 304 ms |

`vehicle_status` gains little, because the check "free right now" with `ignoreRestrictions` stays live; only
`available_until` comes from the store.

Anonymous HTTP requests on dev after the fix of `getClosestBooking()` (5.5): `/availability` 0.51 s,
`vehicle_availability.json` 0.65 s (previously HTTP 500, memory exhausted), `vehicle_status.json` 0.50 s,
`station_status.json` 0.18 s.

---

## 7. Changes to the booking logic and consistency

### 7.1 `Model\Booking::cancel()`

Cancellation still sets `post_status` via direct SQL. New afterwards:

```php
clean_post_cache( $this->post->ID );               // previously get_post() still returned the old status in the same request
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( … 'cancellation_time' … );
\CommonsBooking\Service\Index\Indexer::flush();
```

`clean_post_cache()` also fixes a bug from 2.11.3: subsequent reads in the same request still saw the booking as
confirmed. This affects admin status changes, `Restriction::cancelBookings()` and the privacy erasure.

### 7.2 Booking validation always via the original path

`Booster::withoutBooster( callable )` increases a counter that sets `Booster::canRead()` (and therefore
`Index::isAvailable()`) to `false`, and calls the callback inside `Plugin::withoutCache()`. Used at:

| Place | File |
|---|---|
| frontend booking form | `Plugin::handleBookingForms()` around `Booking::handleFormRequest()` |
| admin: confirm booking | `Wordpress\CustomPostType\Booking` around `$booking->isValid()` |
| validation when saving a timeframe | `Wordpress\CustomPostType\Timeframe` around `$timeframe->isValid()` |

The plugin cache is bypassed as well, because it may contain results that come from the index.

### 7.3 Lock against double bookings (also effective without the booster)

With a large history the original validation takes a few seconds. A second click during that time could create a
second booking of the same slot (bug already present in 2.11.3).

- **Server:** `Wordpress\CustomPostType\Booking::withItemLock()` puts a MySQL lock
  `GET_LOCK('cb_booking_{blog}_{item}', 20)` around validation and saving. Requests for the same item run one after
  the other. After waiting 20 s a `BookingDeniedException` with a message follows. If the database does not support
  locks, it runs without.
- **Browser:** an inline script in `includes/Public.php` locks `#booking-form` and `[id^=cb-booking-form-set-]` after
  the first submit ("Please wait …"), except for the calendar download, and releases the forms again when returning
  from the back-forward cache.

---

## 8. Plugin cache (`Service\Cache`)

Both changes also apply without the booster.

- **New `Plugin::withoutCache( callable )`:** reads and writes no cache entries meanwhile (`getCacheItem()` returns
  `false`, `setCacheItem()` does not write).
- **Role context in the cache key:** `getCacheId()` now contains `getRoleContext()` (guest, or sorted roles plus an
  admin flag). Timeframes can be restricted to user roles; previously a guest could get an admin's cached view and
  vice versa. Users with the same roles still share one entry. Consequence: there are somewhat more cache entries
  than before.

The Symfony cache stays switched on with the booster; map, table, API and GBFS barely benefit from it any more, but the
booking calendar on the item page still does.

---

## 9. Overview of changed existing files

| File | Change | applies without booster |
|---|---|---|
| `commonsbooking.php` | version `2.11.4-beta.2`. A number different from 2.11.3 is needed so that the guard's version check recognises a switch between the versions | yes |
| `includes/OptionsArray.php` | group "Performance booster" under Advanced: checkbox `booster_enabled`, status field with `SettingsView::render` | yes (display) |
| `includes/Public.php` | script against double submission | yes |
| `src/Plugin.php` | `Booster::init()` in `init()`, activation/deactivation, `withoutBooster()` in `handleBookingForms()` | calls only |
| `src/Service/Cache.php` | `withoutCache()`, role context in the key | yes |
| `src/Model/Booking.php` | `cancel()`: `clean_post_cache`, `Indexer::touch/flush` | `clean_post_cache` yes |
| `src/Model/Item.php` | `getClosestBooking()` loads only the candidates with the latest start when the index is available (5.5) | – (unchanged without index) |
| `src/Service/BookingRuleApplied.php` | rule "only one unconfirmed booking" queries the user's own bookings directly via `getForUserPaginated()` instead of loading all visible ones (5.4) | yes |
| `src/Model/Calendar.php`, `Week.php`, `Day.php` | preload restrictions per range; load timeframes only when computing live (`loadPosts()`); `getAvailabilitySlots()` asks `SlotReader` first (6.6) | preloading yes |
| `src/Model/Map.php` | bundling, `getBookableTimeframesByPair()` | yes |
| `src/Map/MapItemAvailable.php` | `Reader`/`DayStatus`, only displayed days | status logic and day range yes |
| `src/View/Calendar.php` | computation core moved out, table via `Reader`, `is-locked` markup and cache key fixed (6.1) | refactoring and fixes yes |
| `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | index path next to the original path; `Booking::getForUser()` without full scan (5.4), `Booking::getLatestPastConfirmed()` (5.5) | original path unchanged; `BookablePost` loads related posts in bulk |
| `src/Wordpress/CustomPostType/Booking.php` | `withItemLock()`, `withoutBooster()` on admin confirmation | lock yes |
| `src/Wordpress/CustomPostType/Timeframe.php` | `withoutBooster()` around `isValid()` | – |
| `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | pickup instructions: `pre-wrap` only on the text, no longer on the template markup (independent display fix) | yes |
| `languages/*` | new strings, German translation | yes |
| `.github/workflows/phpunit.yml`, `phpstan*.neon` | additional CI runs, ignore WP_CLI | – |
| `TECHNICAL.md` | booster section for developers (short version of this documentation) | – |
| `tests/php/BaseTestCase.php`, `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php` | reset the per-request state of the precomputation, build the index for the test run, environment variables | – |
| `tests/php/Model/CalendarTest.php` | expected `Week` objects carry the preloaded restrictions (empty) | – |
| `tests/php/Model/ItemTest.php`, `tests/php/View/CalendarTest.php`, `tests/php/Repository/BookingTest.php`, `tests/php/Wordpress/CustomPostType/BookingTest.php` | new tests, see 11 | – |

New classes (after adding further classes under `src/`, `composer prefix-namespaces` is required; the plugin only loads
the Strauss autoloader in `vendor-prefixed/`):

```
src/Repository/Index.php
src/Service/Index/        Schema, Projector, Indexer, Backfill, Verifier, Cli
src/Service/Availability/ Availability, Schema, Store, Calculator, Reader, SlotReader, Slots, Invalidator,
                          Maintenance, DayFacts, DayStatus, CalendarWindow, Cli
src/Service/Booster/      Booster, Guard, Diagnostics, SettingsView, Cli
```

---

## 10. Operation and tools

### 10.1 WP-CLI

```bash
wp commonsbooking booster status|enable [--now]|disable|rebuild|check [--deep] [--dry-run]|purge
wp commonsbooking index status|rebuild|verify [--repair] [--sample=<n>]|drop             # only with the booster on
wp commonsbooking availability status|rebuild|verify [--repair] [--sample=<n>]|drop      # only with the booster on
```

### 10.2 Filters

| Filter | Default | Effect |
|---|---|---|
| `commonsbooking_booster_enabled` | setting | booster on/off |
| `commonsbooking_use_index` | `true` | `false`: repositories read via `postmeta` (precomputation then off as well) |
| `commonsbooking_use_availability_cache` | `true` | `false`: map/table compute every day live |
| `commonsbooking_availability_horizon_days` | 92 | precomputation period |
| `commonsbooking_availability_max_age` | 86400 | maximum age of a row in seconds |
| `commonsbooking_availability_sync_limit` | 25 | pairs recomputed at the end of the request |
| `commonsbooking_availability_finish_request` | `true` | `fastcgi_finish_request()` before the recomputation |

New actions: `commonsbooking_index_rows_changed( $old, $new )`, `commonsbooking_index_rebuilt`.

### 10.3 Diagnostics in the settings

Settings → Advanced → Performance booster (`SettingsView`, data from `Diagnostics`): state (green/yellow/red) with
hints, index coverage per post type, state of the precomputed pairs, latest bookings with their status in WordPress
and in the index, the last 30 index changes with their origin (website, admin, cron, WP-CLI), last/next run of all
checks. Buttons "Run all checks now" and "Rebuild index".

### 10.4 Measurements at a glance

Summarised from the sections of this documentation and the design notes. Development data: 167,000 posts (ten times
the history), 1.84 million `postmeta` rows, plugin cache off.

| View / operation | 2.11.3 | index only | booster (index + precomputation) | Source |
|---|---|---|---|---|
| map with availability (100 pairs) | 15.4 s | 1.14 s | **0.11 s** | `availability-precompute.en.md` |
| `[cb_items_table]` | 7.05 s | 0.74 s | **0.08 s** | `availability-precompute.en.md` |
| map, locations only | 49.96 s | 0.09 s | 0.09 s | `timeframe-index.en.md` |
| `[cb_items]` | 25.41 s | 0.09 s | 0.09 s | `timeframe-index.en.md` |
| REST `/availability` (126 items, 2 weeks) | – | 1,067 ms | **605 ms** | 6.6 |
| REST `/items` | – | 969 ms | **503 ms** | 6.6 |
| GBFS `station_status` | – | 194 ms | **138 ms** | 6.6 |
| GBFS `vehicle_status` | – | 304 ms | **281 ms** | 6.6 |
| GBFS `vehicle_availability.json` (HTTP, 128 MB limit) | – | error 500 | **0.65 s** | 5.5 |
| `getClosestBooking()` for 126 items | 12.0 s | – | **0.9 s** | 5.5 |
| switching on with `--now` (index + 100 pairs) | – | 23.9 s | + 1.3 s | `booster.en.md` |
| recomputing a pair after a booking | – | – | 13 ms after the response | 1.4.9 |

For API and GBFS the column "index only" means: index on, precomputation switched off. The booking validation itself
always runs via the original path (7.2) and takes about 3 s with 167,000 bookings.

---

## 11. Tests and CI

| Test | Purpose |
|---|---|
| `tests/php/Service/Index/IndexerTest.php` | projection per hook: save, meta, status, `cancel()`, trash, delete, cleanup cron, category/"all" holidays |
| `tests/php/Service/Index/TimeframeIndexParityTest.php` | over 1,400 comparisons index ↔ `postmeta` for timeframes, bookings, restrictions |
| `tests/php/View/CalendarSnapshotTest.php` + `snapshots/calendar-core.json` | calendar JSON, map status and table rows for 11 scenarios × 4 users, recorded **before** the refactoring; compares stored, live and freshly computed results |
| `tests/php/Service/Availability/AvailabilityTest.php` | write path: booking, confirming, cancelling, deleting, rebooking, holidays, wildcards, horizon, concurrent change, roles, age, computation version |
| `tests/php/Service/Booster/*` | switch, bypass during booking validation, guard, `purge`, diagnostics, cache role context |
| `tests/php/Service/Booster/TimeframeVariantsParityTest.php` + `Fixtures/TimeframeVariants.php` | 31 timeframe configurations, comparison with the original code for six user roles |
| `tests/php/Wordpress/CustomPostType/BookingItemLockTest.php` | item lock |
| `tests/php/Service/Availability/SlotsTest.php` | API/GBFS slots stored against live (daily and hourly grid, start offset, repair), deviations, live fallbacks, verifier |
| `tests/php/Repository/BookingTest.php::testGetForUserMatchesFilteringAllBookings` | "My bookings": result equal to filtering all bookings, for seven kinds of users |
| `tests/php/Wordpress/CustomPostType/BookingTest.php::testHandleBookingRequest_onlyOneUnconfirmedBooking_managedItem` | rule "only one unconfirmed booking": unconfirmed bookings of other users on a managed item do not count |
| `tests/php/Model/ItemTest.php::testGetClosestBooking_indexMatchesLegacy` | `getClosestBooking()` index against original path in seven steps: no booking, ignored statuses/locations/running bookings, same start day with different and same time, missing `repetition-end` |
| `tests/php/View/CalendarTest.php::testRenderItemLocationRowLocksDaysWithoutStatus` | item table: days without status as `<span class='is-locked'>` instead of broken markup |

State of this branch: 555 tests in each of the four modes (booster on, `CB_USE_INDEX=0`, `CB_USE_BOOSTER=0`,
`CB_USE_AVAILABILITY=0`); in the modes without the index the index and availability tests are skipped.
`Cache_AJAX_Test::testWarmupCache_bodyAttributes` occasionally fails when `wp_update_plugins()` cannot reach
WordPress.org (network of the test environment, unrelated to the booster).

The suite runs with the booster switched on by default (`tests/php/bootstrap.php` builds the index). Environment
variables:

| Variable | Effect | CI run |
|---|---|---|
| `CB_USE_BOOSTER=0` | booster off (behaviour after the update), index/availability tests skipped | yes |
| `CB_USE_INDEX=0` | original queries | yes |
| `CB_USE_AVAILABILITY=0` | every day live | yes |

---

## 12. Deliberate deviations, limits, open points

### 12.1 Deviations from the original behaviour (with booster)

| Case | 2.11.3 | Booster |
|---|---|---|
| `repetition-end` present but empty | post dropped out of the query | open end (matches `getTimeframeEndDate()`), recorded by a test |
| invalid ID (`'abc'` → `IN (0)`) | matched timeframes with an empty `item-id` (MySQL converts `''` to 0) | matches nothing |

### 12.2 Limits

- Writes that bypass the WordPress hooks (direct SQL by third parties) are only seen by the index via the guard
  (status, modification date) or the daily verifier (meta data). The availability rows expire after 24 h at the
  latest. Bookings are always validated correctly in any case (original queries).
- Pairs with a role restriction are always computed live.
- Requests beyond the horizon (e.g. `[cb_items_table days=180]`) are computed live.
- `Timeframe::isBookable()` (advance booking window) depends on today's date and influences the selection of
  timeframes during the computation. If a timeframe moves into the advance booking window, a row (day facts and slots)
  can be stale until the nightly recomputation, at most 24 h.

### 12.3 Open points

Implemented and described in this documentation: API/GBFS from the precomputation (6.6), "My bookings" without a full
scan (5.4), latest past booking without a full scan (5.5), `is-locked` markup and cache key of the item table (6.1).
Open:

| Point | Impact | possible approach |
|---|---|---|
| "My bookings" for CB admins | still loads all bookings and paginates in PHP (5.4); several seconds with 167,000 bookings | pagination via SQL on the index, filter in the query |
| booking calendar on the item page | computes live, only benefits from the Symfony cache | generate the calendar JSON from day facts; needs clock times and roles, hence a larger refactoring |
| `getClosestBooking()` without the booster | still loads all confirmed bookings of the pair (≈ 10 MB, 95 ms per item); without the booster `vehicle_availability.json` can still hit the memory limit with a large history (already the case in 2.11.3) | a plain `WP_Query` with `orderby`/`posts_per_page` would also be possible without the index, but would be a change to the original path |
| `Repository\Item::getByCloakedId()` | computes `getCloakedId()` for every item; with the booster ≈ 7 ms per item, without the booster as above | cache the hash → item mapping |
| bugs in the API's live path (6.6) | without the booster `/availability` outputs repair days with a location and bookings after the end date as free | report as bugs to the main project; already correct with the booster |
| `vehicle_status` with `ignoreRestrictions` | stays live (6.6) | store a second slot variant without restrictions if the request becomes too slow |

---

## 13. Developer checklist

| If you … | then … |
|---|---|
| write a query on timeframes/bookings/restrictions | check `Index::isAvailable()`, offer an index path and the original path, add a parity test |
| change posts of these types via direct SQL | call `Indexer::touch( $id )` and `Indexer::flush()`, and `clean_post_cache()` if needed |
| introduce a new meta key that affects availability or assignment | add the key to the `Projector`, if needed add a column + increase `Schema::SCHEMA_VERSION`, increase `Projector::PROJECTOR_VERSION` (triggers a rebuild) |
| change the day logic (slots, holidays, booking status) | change it in `DayFacts`/`CalendarWindow`/`DayStatus`, not in `View\Calendar`; increase `Calculator::VERSION`; re-record the snapshot deliberately |
| validate a booking or a timeframe | run it inside `Booster::withoutBooster()` |
| add new classes under `src/` | `composer prefix-namespaces` |
| test | run the suite with and without `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0` |
