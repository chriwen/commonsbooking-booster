# Booking validation on large sites

Branch `feature/confirmation-optimisation` (based on `booster/index-availability`). Change 1 has been in the booster
branch itself since commit `dd9084b4`. This document is meant for developers who want to follow and verify the
changes. For each change it gives the cause, the code before and after, the reason why the result stays the same, and
how to verify that.

## Summary

Reason: a tester of the hosted instance (700 items, 500 locations, 100,000 bookings) waited about 13 s after
"Continue to booking confirmation" and about 7 s after "Confirm booking" until the page reloaded.

| Step (server time, local copy, Docker 4 GB) | before | after |
|---|---|---|
| "Continue to booking confirmation", no booking rules | 1.2–4.9 s, cold up to 17.8 s | 0.38–0.46 s |
| "Continue to booking confirmation", all seven rule types active | ~5 s | 0.48–0.76 s |
| "Confirm booking" | 3.5–10.5 s | 0.39–0.82 s |

There are four changes. None of them adds tables, options, postmeta or indexes, so all of them stay downgradable. The
validation still reads original data only (posts and postmeta), never the booster index.

| # | Change | File(s) | Effect |
|---|---|---|---|
| 1 | Redirect (`exit`) only after `withoutBooster()` | `src/Plugin.php`, `src/Wordpress/CustomPostType/Booking.php` | 0.8–1.7 s less when confirming |
| 2 | One query for slot and overlaps instead of two | `src/Wordpress/CustomPostType/Booking.php` | one query less (formerly ~1.2 s) |
| 3 | Postmeta query for one pair without `WP_Query` | `src/Repository/Booking.php` | 1.2–2.2 s → 0.05–0.16 s per query |
| 4 | Booking rules query only the user's bookings | `src/Service/BookingRule.php` | 4.6 s → 0.14 s for all rules |

Tests: `tests/php/Repository/BookingLegacyLookupTest.php` (change 3) and
`tests/php/Service/BookingRuleUserRangeTest.php` (change 4). The verification guide is at the end.

## Flow of the booking forms

Both forms take the same path: the calendar with "Continue to booking confirmation" and the booking page with
"Confirm booking" or "Cancel". The entry point is `Plugin::handleBookingForms()` on the hook `template_redirect`.

```
Plugin::handleBookingForms()
├─ Booster::withoutBooster( … )                         validation always on original data
│  └─ CustomPostType\Booking::processFormRequest()      nonce, parameters
│     └─ withItemLock( item )                           GET_LOCK, one request per item
│        └─ handleBookingRequest()
│           ├─ Repository\Booking::getByTimerange()     change 2+3: one query (findForItemLegacy)
│           ├─ findExactSlot()                          change 2: instead of getByDate()
│           ├─ overlaps = remaining results             change 2: instead of getExistingBookings()
│           ├─ timeframes, holidays, roles …            unchanged
│           ├─ wp_insert_post() / wp_update_post()
│           └─ BookingRuleApplied::bookingConformsToRules()   new/unconfirmed booking only, change 4
└─ Booking::redirectToBooking()                         change 1: exit only here
shutdown: booster invalidator recomputes the availability of the pair (with index: 7 instead of 650 queries)
```

The booking rules only run when a **new** booking is created or an existing one is set to `unconfirmed`
(`$needsValidation`). That is the case for "Continue to booking confirmation", not when confirming.

## Measurement

- The forms are submitted like a browser does: load the calendar page, take over the form fields, set the slot, load
  the booking page, submit the confirmation form. Login works through a cookie created with
  `wp_generate_auth_cookie()`. Measured is the response time of the form requests, i.e. the server time including
  `shutdown` (mod_php).
- The causes were found with a must-use plugin: time gaps between hooks and queries via the `query` filter.
  `SAVEQUERIES` does not work here because Query Monitor's `db.php` takes it over. In addition, `EXPLAIN` of the
  generated SQL queries.
- The scripts live in the workspace, not in the plugin:
  - `hostenv/rules-setup.php`: set rules
  - `hostenv/rules-bench.php`: time per rule
  - `hostenv/rules-violations.py`: scenario with rule violations
  - `hostenv/rules-pairs.php`: find free pairs

## Change 1: `exit` inside `withoutBooster()`

In the booster branch since `dd9084b4`, included here.

### Cause

`Booster::withoutBooster()` turns the booster off for the duration of a callback and restores it in `finally`. The item
lock `withItemLock()` also releases its `GET_LOCK` lock in `finally`.

`handleFormRequest()`, however, ended with `wp_safe_redirect(); exit;`, i.e. **inside** both callbacks. On `exit`,
PHP runs no `finally` blocks, only shutdown functions. This had two consequences:

- **The booster stayed bypassed for the rest of the request.** The invalidator recomputes the availability of the
  booked pair in `shutdown`. Because `Backfill::isReady()` → `Booster::canRead()` returned `false`, the recomputation
  ran with the original queries: measured 650 instead of 7 queries, 0.8–1.7 s. mod_php has no
  `fastcgi_finish_request()`, so the browser waits for it.
- **The item lock was only released when the database connection closed.**

### Before

```php
// Plugin::handleBookingForms()
Booster::withoutBooster( [ Booking::class, 'handleFormRequest' ] );

// CustomPostType\Booking::handleFormRequest()
$postId = self::withItemLock( (int) $itemId, fn() => self::handleBookingRequest( … ) );
$post_slug = get_post( $postId )->post_name;
wp_safe_redirect( add_query_arg( self::getPostType(), $post_slug, home_url() ) );
exit;   // inside withoutBooster(): its finally does not run
```

### After

```php
// Plugin::handleBookingForms()
$postId = Booster::withoutBooster( [ Booking::class, 'processFormRequest' ] );
if ( $postId !== null ) {
    Booking::redirectToBooking( $postId );   // exit only after withoutBooster()
}

// CustomPostType\Booking
public static function processFormRequest(): ?int      // nonce, parameters, withItemLock + handleBookingRequest,
                                                       // returns the booking id, null without nonce
public static function redirectToBooking( int $postId ): void   // wp_safe_redirect + exit
public static function handleFormRequest()             // kept for existing callers:
                                                       // processFormRequest() + redirectToBooking()
```

### Why the behaviour stays the same

Validation, saving, error messages and the redirect target are unchanged. A `BookingDeniedException` is still caught in
`handleBookingForms()`. Only the order has changed: first the booster is turned back on and the lock is released, then
`exit` follows.

One `exit` remains inside the callback: the ICS download (`calendar-download`) at the start of
`handleBookingRequest()`. Nothing is written there, so nothing is recomputed, and the request ends right away. MySQL
releases the lock when the connection ends. This place was therefore left unchanged.

### Verify

1. Code: `grep -n "exit" src/Wordpress/CustomPostType/Booking.php` must only show `redirectToBooking()` and the ICS
   download.
2. Runtime: confirm a booking and count the queries in `shutdown`, e.g. with a must-use plugin that counts via the
   `query` filter from `shutdown` on. Before about 650, after about 7. Alternatively log `Booster::canRead()` in
   `shutdown`: the value must be `true`.

## Change 2: one query for slot and overlaps

### Cause

`handleBookingRequest()` queried almost the same thing twice:

- `getByDate()` looks for the booking with exactly this slot, to tell editing apart from a new booking.
- `getExistingBookings()` looks for all overlapping bookings except that one.

On large sites each of the two queries took over 1 s (see change 3).

### Before

```php
$booking = Repository\Booking::getByDate( $repetitionStart, $repetitionEnd, $locationId, $itemId );
…
$existingBookings = Repository\Booking::getExistingBookings(
    $itemId, $locationId, $repetitionStart, $repetitionEnd, $booking->ID ?? null
);
```

### After

```php
$overlapping = $repetitionStart <= $repetitionEnd
    ? Repository\Booking::getByTimerange( $repetitionStart, $repetitionEnd, $locationId, $itemId )
    : null;
$booking = $overlapping !== null
    ? self::findExactSlot( $overlapping, $repetitionStart, $repetitionEnd )
    : Repository\Booking::getByDate( $repetitionStart, $repetitionEnd, $locationId, $itemId );  // invalid request: as before
…
$existingBookings = $overlapping !== null
    ? array_filter( $overlapping, fn( $other ) => $other->ID !== ( $booking->ID ?? null ) )
    : Repository\Booking::getExistingBookings( … );
```

`findExactSlot()` filters the results on `(int) repetition-start === start` and
`(string) repetition-end === (string) end`. With more than one match it throws the same exception as `getByDate()`.

### Why the result stays the same

| Point | before (`getByDate` / `getExistingBookings`) | after |
|---|---|---|
| Status | both `confirmed`, `unconfirmed` | default of `getByTimerange`: `confirmed`, `unconfirmed` |
| Pair | item and location | the same |
| Is the slot booking among the results? | separate query | overlap means start ≤ E and end ≥ S. A booking with start = S and end = E satisfies this as long as S ≤ E. That is why the new path only applies when S ≤ E. |
| Comparison with the exact slot | `CAST(start AS SIGNED) = S`, `end = 'E'` (string) | `(int) start === S`, `(string) end === 'E'` |
| several exact matches | exception "Found more than one bookings" | the same exception |
| overlaps without the slot booking | `getExistingBookings( …, $booking->ID )` | `array_filter` with `!==` on the same id |

`getExistingBookings()` itself is just `getByTimerange()` with the same arguments, removing the booking with
`$val->ID === $postId` from the result. So the new path runs the same query and removes the same booking. It only
saves the second query that `getByDate()` used to run in addition.

In theory there is one difference: MySQL compares strings with the table collation and ignores trailing spaces, PHP
compares exactly. An end value with trailing spaces does not occur in the plugin, because timestamps are stored there.

### Verify

1. Read the code: `handleBookingRequest()` and `findExactSlot()` in `src/Wordpress/CustomPostType/Booking.php`.
2. Existing tests: `tests/php/Wordpress/CustomPostType/BookingTest.php` covers new bookings, editing, overlaps and
   cancelling.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter 'CustomPostType\\BookingTest'
   ```

## Change 3: postmeta query for one pair without `WP_Query`

### Cause

`getByDate()` and `getByTimerange()` build a `WP_Query` with five meta conditions. WordPress turns that into five
joins on `wp_postmeta`. MySQL starts at `wp_posts` and reads **every** booking of the site, each with five postmeta
lookups. The query does filter on one pair, but it grows with the whole booking history. The assumption in
`booster.en.md` ("A validation concerns one pair and stays fast") therefore did not hold.

### Old query

This is the query `WP_Query` generates (hostenv, item 617, location 618, one day):

```sql
SELECT wp_posts.* FROM wp_posts
 INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )
 INNER JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id )
 INNER JOIN wp_postmeta AS mt2 ON ( wp_posts.ID = mt2.post_id )
 INNER JOIN wp_postmeta AS mt3 ON ( wp_posts.ID = mt3.post_id )
 INNER JOIN wp_postmeta AS mt4 ON ( wp_posts.ID = mt4.post_id )
 WHERE 1=1 AND (
   ( wp_postmeta.meta_key = 'repetition-start' AND CAST(wp_postmeta.meta_value AS SIGNED) <= '1797292799' )
   AND ( mt1.meta_key = 'repetition-end' AND CAST(mt1.meta_value AS SIGNED) >= '1797206400' )
   AND ( mt2.meta_key = 'type' AND mt2.meta_value = '6' )
   AND ( mt3.meta_key = 'location-id' AND mt3.meta_value = '618' )
   AND ( mt4.meta_key = 'item-id' AND mt4.meta_value = '617' )
 ) AND wp_posts.post_type = 'cb_booking'
   AND ((wp_posts.post_status = 'confirmed' OR wp_posts.post_status = 'unconfirmed'))
 GROUP BY wp_posts.ID ORDER BY wp_posts.post_date DESC
```

```
1.263 s
id table        type  key      rows   Extra
1  wp_posts     index PRIMARY  99329  Using where; Using temporary; Using filesort
1  wp_postmeta  ref   post_id  11     Using where
1  mt1 … mt4    ref   post_id  11     Using where
```

### New query

`Repository\Booking::findForItemLegacy()`:

```sql
SELECT STRAIGHT_JOIN DISTINCT p.ID, p.post_date
 FROM wp_postmeta mi
 INNER JOIN wp_posts p ON p.ID = mi.post_id
 INNER JOIN wp_postmeta ml ON ml.post_id = p.ID AND ml.meta_key = 'location-id' AND ml.meta_value = '618'
 INNER JOIN wp_postmeta mt ON mt.post_id = p.ID AND mt.meta_key = 'type' AND mt.meta_value = '6'
 INNER JOIN wp_postmeta ms ON ms.post_id = p.ID AND ms.meta_key = 'repetition-start' AND CAST(ms.meta_value AS SIGNED) <= 1797292799
 INNER JOIN wp_postmeta me ON me.post_id = p.ID AND me.meta_key = 'repetition-end' AND CAST(me.meta_value AS SIGNED) >= 1797206400
 WHERE mi.meta_key = 'item-id' AND mi.meta_value = '617'
   AND p.post_type = 'cb_booking' AND p.post_status IN ('confirmed','unconfirmed')
 ORDER BY p.post_date DESC
```

```
0.126 s
id table  type    key       rows    Extra
1  mi     ref     meta_key  194070  Using where; Using temporary; Using filesort   (estimate: all item-id rows)
1  p      eq_ref  PRIMARY   1       Using where
1  ml/mt/ms/me ref post_id  11      Using where; Distinct
```

Afterwards `_prime_post_caches()` loads the found posts including postmeta in one go. `Model\Booking` objects are built
from them.

### Why `STRAIGHT_JOIN`

Without this hint MySQL picks `wp_posts` as the first table again (measured 1.2 s). With `STRAIGHT_JOIN` the order of
the `FROM` clause applies: first the `item-id` rows via the `meta_key` index, then everything else via `post_id` or
`PRIMARY`.

| Variant | Time |
|---|---|
| `WP_Query` | 1.2–2.2 s |
| from `item-id`, without `STRAIGHT_JOIN` | 1.2 s |
| from `repetition-end` | 0.1–0.26 s, 0.8 s for past ranges |
| **from `item-id`, with `STRAIGHT_JOIN`** | **0.05–0.16 s, independent of the date** |

The cost still grows linearly with the number of all `item-id` rows, because the core index `meta_key` does not
contain `meta_value`, but about 15 times more slowly than before. New indexes on core tables were deliberately not
added (downgradability, rule "own tables only").

### Conditions compared

| Condition | `WP_Query` meta query (before) | `findForItemLegacy()` |
|---|---|---|
| Item | `item-id = '617'` (string) | `mi.meta_value = '617'` |
| Location (optional) | `location-id = '618'`, only if given | `ml` join, only if given |
| Type | `type = '6'` | `mt.meta_value = '6'` |
| Range (`getByTimerange`) | `CAST(start AS SIGNED) <= end`, `CAST(end AS SIGNED) >= start` | `start_max`, `end_min`: the same |
| Exact slot (`getByDate`) | `CAST(start AS SIGNED) = S`, `end = 'E'` (no CAST) | `start_exact`, `end_exact`: the same |
| Status | `post_status = …` plus a PHP post-filter (`getModelsFromQuery()`) | `post_status IN (…)` |
| Duplicate results | `GROUP BY wp_posts.ID` | `DISTINCT p.ID` |
| Order | `post_date DESC` (WP_Query default) | `ORDER BY p.post_date DESC` |

### When the new path applies

See `src/Repository/Booking.php`.

- **`getByTimerange()`:** when all three conditions hold:
  - The index is not readable, because the validation runs in `withoutBooster()` or the booster is off or not ready.
  - An item is given.
  - There are no `$customArgs`. With `$customArgs` (e.g. `author`, `orderby` of the booking rules) it still runs
    `getByTimerangeWithMetaQuery()`, i.e. the former `WP_Query`.
- **`getByDate()`:** always when the index is not readable. An item is always given here.

Outside the booking validation, this affects the following callers when the booster is off. They get the same
bookings as before, only faster:

- `Repository\Booking::getByRestriction()`
- `getExistingBookings()`, e.g. in `MassOperations` and in `Model\Booking` for overlaps
- `Model\Booking::adjacent()` (chain bookings)

### Differences accepted on purpose

- **`WP_Query` filters no longer apply.** `pre_get_posts`, `posts_where`, `posts_clauses` etc. have no effect on this
  path. In the plugin such filters only hook into the main query of the admin lists (`filterAdminList` checks
  `is_main_query()`), so not here. A third-party plugin that changes booking queries through filters would no longer
  have an effect here. It is the same with the booster index.
- **No restriction by user.** The booking statuses are registered as `public`
  (`src/Wordpress/PostStatus/PostStatus.php`), so `WP_Query` does not restrict them by user. The direct query
  therefore behaves the same for every user.
- **No `WP_Query` object cache.** The `post-queries` cache is not used. Nothing in the validation should come from
  caches anyway.

The former queries are kept as `getByDateWithMetaQuery()` and `getByTimerangeWithMetaQuery()`, marked `@internal`.
They serve the parity test and still handle the case with `$customArgs`.

### Verify

1. **Parity test** `tests/php/Repository/BookingLegacyLookupTest.php`:
   - `testTimerangeMatchesMetaQuery`: compares the new with the old query over 8 ranges × 3 status sets × 5
     combinations of item and location. They include a `null` location, ids as strings, boundaries to the second,
     hourly slots and a range of two years.
   - `testExactSlotMatchesMetaQuery`: compares `getByDate()` with the old query for 7 slots × 2 locations. 4 matches
     are expected; one slot differs by 1 s at the end and must find nothing.
   - Data: full days with the statuses `confirmed`, `unconfirmed`, `canceled` and `draft`, neighbouring slots,
     another item and another location, and a timeframe that must not be found.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingLegacyLookupTest
   ```
2. **Look at SQL and `EXPLAIN` yourself:** on a large site, call both methods with a `query` filter that prints the
   SQL, and run `EXPLAIN <sql>`. That is how the outputs above were produced.
3. **Compare on real data**, e.g. via `wp eval` for random pairs and ranges:
   ```php
   $a = Booking::getByTimerangeWithMetaQuery( $s, $e, $loc, $item );
   $b = Booster::withoutBooster( fn() => Booking::getByTimerange( $s, $e, $loc, $item ) );
   // same ids in the same order (post_date DESC)
   ```

## Change 4: booking rules query only the user's bookings

The booking rules are configured under Settings → Restrictions → Booking rules.

### Cause

The rules with a range call this query in `BookingRule::checkBookingRangeForDays()` and `checkBookingAmount()`. That
concerns the rules "days per week", "days per month", "x days in y days", "bookings per week" and "bookings per
month".

```php
Repository\Booking::getByTimerange( $start, $end, null, null, [ 'orderby' => 'date', 'order' => 'ASC' ], $status );
```

Without item and without user it returns **all** bookings of all users in the range, ~2,500 per month on hostenv.
Only afterwards does `filterBookingsForTermsAndUser()` filter for the user in PHP. That measured ~1.5 s per rule, and
4.6 s just for the rules with all seven rule types. Most of it is the `WP_Query` over all bookings. On top come
hundreds of queries for users, items and categories of other people's bookings.

### Before

In both methods:

```php
$rangeBookingsArray = Repository\Booking::getByTimerange( $start, $end, null, null, $order, $countedPostTypes );
$rangeBookingsArray = self::filterBookingsForTermsAndUser( $rangeBookingsArray, $booking->getUserData(), $appliedTerms );
```

### After

```php
$rangeBookingsArray = self::getUserBookingsInRange( $start, $end, $booking, $appliedTerms, $countedPostTypes );

private static function getUserBookingsInRange( \DateTime $start, \DateTime $end, Booking $booking, $appliedTerms, array $postStatus ): ?array {
    $order        = [ 'orderby' => 'date', 'order' => 'ASC' ];
    $user         = $booking->getUserData();
    $userBookings = Repository\Booking::getByTimerange( …, null, null, $order + [ 'author' => $user->ID ], $postStatus );
    if ( empty( $userBookings ) ) {
        return null;
    }
    if ( empty( $appliedTerms ) || Booking::filterTermsApply( $userBookings, $appliedTerms ) ) {
        return $userBookings;
    }
    // special case (see below): load all bookings as before
    $rangeBookings = Repository\Booking::getByTimerange( …, null, null, $order, $postStatus );
    return self::filterBookingsForTermsAndUser( $rangeBookings, $user, $appliedTerms );
}
```

With `author` the month query takes 0.004–0.04 s instead of 1.55 s, for users with 0 to 17 bookings per month.
Because `$customArgs` are set, it still runs through `WP_Query` and not through change 3.

### Why the result stays the same

`filterBookingsForTermsAndUser( $all, $user, $terms )` used to work like this:

- It returns `null` if **no** booking in the range, by anybody, has one of the categories.
- Otherwise it returns **all** bookings of the user, including those in other categories.

Let U be the set of the user's bookings in the range:

| Case | before | after |
|---|---|---|
| U is empty | the user filter gives `[]`, the caller reports no violation | `null`, no violation |
| rule applies to all (`$appliedTerms` empty or `false`) | `filterTermsApply()` returns all, result U | U |
| a booking in U has one of the categories | then "all" has one too, result U | U |
| no booking in U has one of the categories | depends on other people's bookings | former query over all, identical |

`author` filters on `post_author`. `filterForUser()` checks `getUserData()->ID`, i.e. `get_userdata( post_author )`.
Both are the same column. The order `post_date ASC` is kept. The callers count the bookings or sum up the days and
list the bookings in the error message.

The special case in the last row is a bug in the original code, because the categories are checked on other people's
bookings. It is fixed in `feature/booking-rule-fixes` (see `design/buchungsregeln.en.md` there). In `booster2`, where
both branches come together, the special case is gone: there only the bookings in U count, filtered by category.

Two rules needed no change:

- `checkSimultaneousBookings()` already queried with `author` (0.002 s).
- `checkChainBooking()` looks up the adjacent bookings one by one via `Model\Booking::adjacent()` with item and
  location. It benefits from change 3 and still takes 0.06–0.18 s.

### Verify

1. **Parity test** `tests/php/Service/BookingRuleUserRangeTest.php`:
   - It compares `getUserBookingsInRange()` with the former path: load all bookings, then
     `filterBookingsForTermsAndUser()`.
   - Covered are 6 ranges × 6 category sets × 2 status sets, each with and without the booster. The category sets
     include `false`, `[]`, an unused category and the special case.
   - Data:
     - Bookings of the user in two categories and without a category, one of them cancelled.
     - Bookings of another user in a category the user does not have in the range.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRule
   ```
2. **Time per rule on a large site:** `rules-bench.php` creates an unconfirmed test booking per run, checks all
   configured rules and deletes the booking again. Expected are times below 0.01 s for all rules except the chain
   booking.
   ```bash
   npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-bench.php 4
   ```
3. **Behaviour:** the scenario `hostenv/rules-violations.py` (see below). The results must be the same before and
   after the change.

## Behaviour of the booking rules (scenario with violations)

Configuration via `hostenv/rules-setup.php set`, cancelled bookings count:

| # | Rule | Setting |
|---|---|---|
| 1 | No simultaneous bookings | all |
| 2 | No chain bookings | all |
| 3 | Days per week | 3, reset Monday |
| 4 | Days per month | 6, reset on the 1st, cargo bike category only |
| 5 | x days in y days | 4 in 14 |
| 6 | Bookings per week | 2, reset Monday |
| 7 | Bookings per month | 3, reset on the 1st |

The scenario runs as the subscriber `regeltest` with four items without the cargo bike category (A–D). The forms are
submitted like a browser does.

| # | Attempt | Result | Rule |
|---|---|---|---|
| 1 | A 30 Nov, 1 day | booked | – |
| 2 | B 30 Nov, 1 day | denied | 1 simultaneous |
| 3 | B 1 Dec, 1 day | booked | – |
| 4 | C 3 Dec, 1 day | denied | 6 (2 bookings in the week) |
| 5 | A 14–16 Dec | booked | – |
| 6 | A 17 Dec, 1 day | denied | 2 (chain of 4 days > max. 3) |
| 7 | D 19 Dec, 1 day | denied | 3 (3 + 1 days in the week) |
| 8 | D 21–22 Dec | denied | 5 (3 + 2 days in 14 days) |
| 9 | D 28 Dec, 1 day | booked | – |
| 10 | C 24 Dec, 1 day | denied | 7 (3 bookings in the month) |
| 11 | B 30–31 Dec | denied | 4, although configured for cargo bikes only (issue 1) |
| 12 | A 5 Jan, left unconfirmed | created | – |
| 13 | C 7 Jan, 1 day | denied | open unconfirmed booking |

Each denial shows the rule's message and the affected bookings as links. Before and after change 4 the results were
the same. The time for step 1 dropped from ~5 s to 0.5–0.8 s.

Issues in the original code. Points 1–3 are fixed in `feature/booking-rule-fixes`, see `design/buchungsregeln.en.md`
there.

1. **Category restriction leaks:** `BookingRuleApplied::init()` does not reset `$appliesToAll` and `$appliedTerms`
   per rule. A rule for "cargo bikes" only that follows a rule "applies to all" therefore applies to all as well.
2. **Wrong weekday in the message:**
   - "Bookings per week" with reset on Monday says "after Tuesday".
   - Friday and Saturday as reset day are handled wrongly.
   - The weekdays appear in English.
3. **Categories when counting:** see change 4, special case.
4. **Chain booking message without a reason:** it only says "booking limit reached". Not fixed.

## How to verify (everything)

In the workspace, not in the checkout:

```bash
# tests, static analysis, code style (like the CI)
CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit
(cd checkouts/confirmation-optimisation && vendor/bin/phpstan analyse --memory-limit=1G)
(cd checkouts/confirmation-optimisation && vendor/bin/phpcbf -q src templates includes tests commonsbooking.php)
```

On the large site (hostenv, port 8891). First point the plugin in `hostenv/.wp-env.json` to this checkout; the
commands run in the folder `hostenv/`.

```bash
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-setup.php set     # rules + user regeltest
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-bench.php 4       # time per rule
python3 rules-violations.py "<cookie of regeltest>" A=617:618,B=38:9,C=18:5,D=624:625   # scenario above
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-setup.php clear   # rules off again
```

The days must be free for the scenario. `rules-pairs.php` finds free pairs; cancel or delete existing bookings of
`regeltest` beforehand.

By hand in the browser: book a free day as a subscriber and look at the response time of the form requests in the
developer tools (network). That is the redirect with status 302 to `?cb_booking=…`.

## Downgrade

All four changes concern code only. There are no new tables, options, postmeta or indexes. Switching to a version
without this branch needs no cleanup.

## Checked, not implemented: index for the booking validation (optional)

Question: should the search for conflicting bookings use the booster index, optionally (switchable, with a notice in
the backend)?

**How reliable is the index?**

- **Synchronous update in the same request:**
  - Hooks: `added/updated/deleted_post_meta`, `transition_post_status`, `save_post` (priority `PHP_INT_MAX`),
    `deleted_post`. `meta_input` is written before `save_post`.
  - Pending changes are written before every read (read-your-writes).
  - Thanks to the lock, frontend bookings of the same item run one after another. The new booking is in the index
    before the lock is released.
- **Fallback during a rebuild:** if the index is not ready, every query uses the original queries.
- **Comparison:** a full comparison on the large site (`wp commonsbooking index verify`) found **101,079 posts,
  0 differences, 0 orphaned rows**.

**Where it can differ.** In each of these cases a double booking is possible if the validation uses the index.

1. Errors while projecting are logged and swallowed ("Never break the actual request"). The booking is then missing
   until the repair.
2. Writing past WordPress (other plugins, SQL, phpMyAdmin, imports):
   - The watcher only detects new posts after a grace period of 120 s.
   - It detects status and date changes hourly.
   - It detects pure meta changes **only through the daily sample of 200 posts**. With 100,000 bookings that is
     statistically complete only after years.
3. Phases in which the booster code did not run (downgrade) are detected by the watcher through the version option.
   Until the first request afterwards the index is outdated.

The CB1 migration writes postmeta via SQL. It is left out of consideration here, because it will be dropped with one
of the next updates.

In a normal system without outside interference the index returns correct values. That cannot be guaranteed, though.
The rule "validation never from the index" rules out exactly these remaining cases.

**Trade-off:** after changes 1–4 the validation on the original data takes 0.05–0.16 s per query. The index would
save another ~0.1 s per request. That justifies neither the remaining risk nor an additional option.

**Decision** (14 Nov 2026): do not implement as long as the original query stays in this range. Reassess at about
500,000 bookings. If it becomes necessary after all:

- use the index only as a pre-filter,
- re-check the candidates and all posts changed since the last watcher run against postmeta,
- fall back to the original query automatically after a projection error until the repair,
- keep the option off by default.

## Open

- Combine the timeframe lookups per request. Today `Timeframe::get` is called ~10 times for the same pair, 0.06–0.1 s
  each, 0.3–0.5 s in total. This hardly affects small sites.
- Chain booking rule: the adjacent bookings are looked up one by one (0.06–0.18 s).
- The email on confirmation is sent synchronously. It is turned off in the test instance; measure at the hoster.
- The chain booking message gives no reason (issue 4).
