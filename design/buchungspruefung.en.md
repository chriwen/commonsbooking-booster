# Booking validation on large sites

Branch `feature/confirmation-optimisation` (based on `booster/index-availability`). Reason: a tester of the hosted
instance (700 items, 500 locations, 100,000 bookings) waited about 13 s after "Continue to booking confirmation" and
about 7 s after "Confirm booking" until the page reloaded.

## Findings

Both forms run through `Plugin::handleBookingForms()` → `Booster::withoutBooster()` → `Booking::handleBookingRequest()`.
By the booster's rule the validation always uses the original data (postmeta), never the index. Measured (local copy of
the hosted instance, server time per form request, warm):

| Place | Time | Cause |
|---|---|---|
| `getExistingBookings` → `getByTimerange` | 1.7–2.3 s | `WP_Query` with 5 postmeta joins; MySQL starts at `wp_posts` and reads **all** bookings (EXPLAIN: 99,308 rows, "Using temporary; Using filesort") |
| `getByDate` | 1.1–1.4 s | the same query with `=` |
| `Invalidator::onShutdown` (confirm only) | 0.8–1.7 s | see "exit inside withoutBooster" |
| ~10× `Timeframe::get` | ~0.06–0.1 s each | timeframes of the same pair, looked up repeatedly |

The assumption in `booster.en.md` ("A validation concerns one pair and stays fast") did not hold: the query filters
on one pair but grows with the whole booking history.

## Decisions

### 1. One query instead of two

`handleBookingRequest()` loads the overlapping bookings once (`getByTimerange`) and picks the booking with exactly this
slot from them (`findExactSlot()`: start compared as number, end as string, like `getByDate`). A booking with exactly
this slot always overlaps it. With start > end (invalid request) the former path is kept.

### 2. Postmeta query for one pair without `WP_Query`

`Repository\Booking::findForItemLegacy()` replaces the meta query in `getByDate` and `getByTimerange` when an item is
given and no additional `WP_Query` arguments are passed (otherwise `WP_Query` as before, e.g. the booking rules with
`author`/`orderby`). It still reads posts and postmeta only, i.e. the original data:

- `STRAIGHT_JOIN` starting at the `item-id` rows, all other conditions by `post_id`. Variants measured:
  `WP_Query` 1.2–2.2 s; from `item-id` without `STRAIGHT_JOIN` 1.2 s (MySQL picks `wp_posts` again); from
  `repetition-end` 0.1–0.26 s, 0.8 s for past ranges; **from `item-id` with `STRAIGHT_JOIN` 0.05–0.16 s** for any date.
- Comparisons as in the meta queries: start/end as `CAST(... AS SIGNED)`, exact end, `type`, item and location as
  strings. Order `post_date DESC` (WP_Query default).
- The former queries remain as `getByDateWithMetaQuery()`/`getByTimerangeWithMetaQuery()` (internal, for the parity
  test `tests/php/Repository/BookingLegacyLookupTest.php`).

No new indexes on core tables (downgradability, rule "own tables only"). The cost still grows linearly with the number
of all `item-id` rows, but about 15 times flatter (100,000 bookings: 0.05–0.16 s).

### 3. `exit` inside `withoutBooster()` (bug in the booster branch)

`handleFormRequest()` redirected with `wp_safe_redirect(); exit;` **inside** `withoutBooster()`. `exit` skips `finally`
blocks: the booster stayed bypassed for the rest of the request, and the item lock (`withItemLock`) was only released
when the connection closed. The availability recomputation on shutdown therefore ran without the index (650 instead of
7 queries, 0.8–1.7 s), and with mod_php the browser waits for it.

Fix: `Booking::processFormRequest()` validates and saves and returns the booking id, `Booking::redirectToBooking()`
redirects. `Plugin::handleBookingForms()` redirects only after `withoutBooster()` returned. `handleFormRequest()` stays
for existing callers (validate + redirect).

### 4. Booking rules: the user's bookings first

The rules (settings → restrictions → booking rules) only run when the unconfirmed booking is created ("Continue to
booking confirmation"), not on confirmation. The rules with a range (days per week/month, x days in y days, bookings
per week/month) loaded **all** bookings of all users in the range in `checkBookingRangeForDays()`/`checkBookingAmount()`
(`WP_Query` without item and user, ~2,500 per month with 100,000 bookings) and filtered for the user in PHP only:
~1.5 s per rule, 4.6 s with all seven rule types.

`BookingRule::getUserBookingsInRange()` first queries only the user's bookings (`author`, 0.004–0.04 s). The result
stays the same as before (`filterBookingsForTermsAndUser()` over all bookings): only when the rule is limited to
categories and none of the user's bookings has one of them, it depends on the bookings of other users (see
"Observations", 3); then everything is loaded as before. Parity test: `tests/php/Service/BookingRuleUserRangeTest.php`.

### Result

| Step | before | after |
|---|---|---|
| "Continue to booking confirmation" (server time), no booking rules | 1.2–4.9 s, cold up to 17.8 s | 0.38–0.46 s |
| "Continue to booking confirmation", all seven rule types active | ~5 s | 0.48–0.76 s |
| "Confirm booking" (server time) | 3.5–10.5 s | 0.39–0.82 s |

Measured with the same script (forms like a browser, `testnutzer` or `regeltest`), locally, Docker with 4 GB. Rules:
`hostenv/rules-setup.php`, time per rule: `hostenv/rules-bench.php` (workspace). The chain booking rule still takes
~0.1–0.2 s (adjacent bookings looked up one by one), all other rules together less than 0.01 s.

### Behaviour of the booking rules (scenario with violations)

With all seven rule types, a scenario with 13 booking attempts (`regeltest`, four items) triggered every rule. A
denial shows the rule's message and the affected bookings. The results were the same before and after 4. Observations
(in the original code, not changed):

1. **Category limit leaks:** `BookingRuleApplied::init()` does not reset `$appliesToAll`/`$appliedTerms` per rule. A
   rule for "cargo bike" only, after a rule "applies to all", applies to all as well (in the scenario: the 6 days per
   month rule denies a trailer).
2. **Wrong weekday in the message:** "bookings per week" with reset on Monday says "after Tuesday"
   (`maxBookingsWeekErrorMessage()` uses the select value 1–7 as a 0-based index). Friday and Saturday as reset day
   become Sunday in `checkMaxBookingsWeek()`. Weekdays appear in English ("Monday").
3. **Categories when counting:** `filterBookingsForTermsAndUser()` checks the categories on all bookings in the range
   but returns all bookings of the user. With rules for one category, bookings of other categories count as soon as
   anybody booked the category in the range.
4. The chain booking message gives no reason (only "booking limit reached").

## Examined, not implemented: index for the booking validation (optional)

Question: use the booster index optionally (switchable, with a note in the backend) to find conflicting bookings?

**How reliable is the index?**

- Updated synchronously in the same request: `added/updated/deleted_post_meta`, `transition_post_status`, `save_post`
  (priority `PHP_INT_MAX`), `deleted_post`; `meta_input` is written before `save_post`. Reading flushes pending changes
  first (read-your-writes). Frontend bookings of the same item run one after the other (lock), the new booking is in
  the index before the lock is released.
- While the index is not complete (rebuild), every query falls back to the original queries.
- Full comparison on the large instance (`wp commonsbooking index verify`): **101,079 posts, 0 mismatches, 0 orphaned
  rows**.

**Where it can differ** (each a possible double booking if the validation used it):

1. Projection errors are logged and swallowed ("Never break the actual request"); the booking is missing until repaired.
2. Writes bypassing WordPress (other plugins, SQL, phpMyAdmin, imports): the guard finds new posts after a 120 s grace
   period, status/date changes hourly, **meta-only changes only through the daily sample of 200 posts** (with 100,000
   bookings statistically complete only after years).
3. Periods in which the booster code did not run (downgrade) are detected by the guard via the version option; until
   then (the first request afterwards) the index is outdated.

On a normal system without outside interference the index returns correct values. This cannot be guaranteed. The rule
"validation never from the index" excludes exactly these remaining cases.

**Trade-off:** after 1–4 the validation on the original data takes 0.05–0.16 s; the index would save another ~0.1 s
per request. That does not justify the remaining risk and an additional option. Recommendation: do not implement as
long as the original query stays in this range (reassess at about 500,000 bookings). If it is done anyway: index only
as a pre-filter, re-check the candidates and all posts changed since the last guard check on postmeta, fall back to the
original query automatically after a projection error until it is repaired, off by default.

## Open

- Combine the timeframe lookups per request (~0.3–0.5 s, hardly matters on small sites).
- Fix observations 1–4 of the booking rules (changes behaviour, own branch or upstream).
- The confirmation e-mail is sent synchronously (switched off in the test instance), measure on the hosted site.
