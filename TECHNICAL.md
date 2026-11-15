### Formatter

We adhere to [PHPCS](https://github.com/PHPCSStandards/PHP_CodeSniffer) rules defined in the [phpcs.xml](https://github.com/wielebenwir/commonsbooking/blob/master/.phpcs.xml.dist) rules file, as  it is a mature tool and well established in the Wordpress-Plugin development scene.
A program to apply auto-formattable rules of PHPCS is `phpcbf` and we encourage everyone
to configure this tool in their IDE so that contribution commits consist of properly formatted code.
Both are already in the dev dependencies of the repository code.

We have an automatic check [as Github Action](https://github.com/wielebenwir/commonsbooking/tree/master/.github/workflows/phpcbf-check.yml) in our CI/CD-Pipeline, which prevents code contributions that not adhere to the rules.

#### Ignore formatter revisions

We use .git-blame-ignore-revs to track repo-wide cosmetic refactorings by auto format tools like prettier/phpcbf.
See [Github Documentation](https://docs.github.com/de/repositories/working-with-files/using-files/viewing-and-understanding-files#ignore-commits-in-the-blame-view)

You can also configure your local git so it always ignores the revs in that file:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

### Performance booster

The timeframe index and the precomputed availability (both below) form the *performance booster*, a single switch
under CommonsBooking → Settings → Advanced Options (`Service\Booster\Booster`, setting `booster_enabled`, filter
`commonsbooking_booster_enabled`). It is **off by default**. Design notes: `design/booster.md`.

- **Off:** no hooks, no cron jobs, no reads from the additional tables. The plugin runs the original queries, exactly
  like a version without the booster. The existing tables (`wp_posts`, `wp_postmeta`, ...) are never changed by the
  booster, so the update can be downgraded.
- **Switching on** (setting, `wp commonsbooking booster enable [--now]`, plugin activation): installs the tables and
  rebuilds the index from scratch, because nobody maintained it while the booster was off. Until the rebuild is
  complete, the original queries run. Switching off stops the cron jobs and marks the index as outdated.
- **Booking validation** never reads from the booster tables or the plugin cache (which may hold results computed from
  the index): the frontend booking form
  (`Plugin::handleBookingForms()`), the admin booking form and the timeframe validation run inside
  `Booster::withoutBooster()`. An outdated index can only cause an outdated display, never a double booking.
- **Guard against changes made without the booster** (`Service\Booster\Guard`), e.g. a downgrade to a version
  without the booster and an upgrade again:
  - every request: the stored plugin version was changed by other code (the booster records its own writes), or posts
    of the indexed types above the last checked id have no index row (after a grace period of 2 minutes) → full rebuild;
  - hourly and `wp commonsbooking booster check --deep`: posts whose status or modification date differ from the index,
    index rows of deleted posts → repaired individually (full rebuild if more than 200).
  Meta-only changes without a post update are found by the daily index verification.
- **Settings page diagnostics** (`Service\Booster\SettingsView`, `Service\Booster\Diagnostics`): health with
  findings, index coverage per post type, precomputed availability, latest bookings with their status in WordPress and
  in the index, a log of the last 30 index changes (including deletions) and the last/next run of every check.
  Buttons run all checks (repairing differences) or rebuild the index.
- **Plugin cache and user roles:** `Cache::getCacheId()` includes the user's roles, because timeframes limited to user
  roles make map, tables and calendars differ per user.
- **WP-CLI:** `wp commonsbooking booster status|enable [--now]|disable|rebuild|check [--deep] [--dry-run]|purge`.
  `purge` removes tables, options and cron jobs. The commands `wp commonsbooking index …` and
  `wp commonsbooking availability …` are only registered while the booster is switched on.

Tests run with the booster switched on. Run them with `CB_USE_BOOSTER=0` to test the plugin without it (the CI runs
the suite once this way; the index and availability tests are skipped then).

### Timeframe index

Timeframes, bookings and restrictions are custom post types whose relevant data lives in `postmeta`. Querying
`postmeta` by item, location and date cannot use database indexes and gets slow with a growing booking history.
Therefore these posts are additionally projected into two tables, `{prefix}cb_index` (one row per post) and
`{prefix}cb_index_rel` (items/locations of timeframes with multi selection). Design notes: `design/timeframe-index.md`.

- **The posts stay the source of truth.** The index is derived and can always be rebuilt.
- **Writing:** `CommonsBooking\Service\Index\Indexer` listens to `save_post`, meta changes, status transitions and
  deletions and re-projects the post (`Projector`) within the same request. Code that writes posts *without* WordPress
  hooks (direct SQL) must call `Indexer::touch( $postId )` and `Indexer::flush()`, like `Model\Booking::cancel()`.
- **Reading:** `CommonsBooking\Repository\Index` is used by the timeframe, booking, restriction and bookable post
  repositories when `Index::isAvailable()`, i.e. the booster is on, not bypassed and the backfill is complete.
  Otherwise the legacy `postmeta` queries run.
  The filter `commonsbooking_use_index` (return `false`) forces the legacy queries.
- **Installation / backfill:** The tables are created when the booster is switched on (own schema version option).
  The fill runs in batches via WP-Cron. Changing `Projector::PROJECTOR_VERSION` triggers a rebuild.
- **Consistency:** a daily cron job verifies and repairs a random sample.
- **WP-CLI:** `wp commonsbooking index status|rebuild|verify [--repair] [--sample=<n>]|drop`

Tests and benchmarks build the index before running. Run them against the legacy queries with `CB_USE_INDEX=0`
(the CI runs the suite once this way). Parity tests in `tests/php/Service/Index/` compare both paths.

### Precomputed availability

Map (`[cb_map]`, `[cb_search]`) and items table (`[cb_items_table]`) show the state of every day for every
item/location pair. Computing a day means building its slot grid from timeframes, bookings and restrictions, which
costs about 10 ms per pair and request. Therefore the date independent part of each day is stored in
`{prefix}cb_availability` (one row per pair, 2 bytes per day, today − 1 to today + 92 days).
Design notes: `design/availability-precompute.md`.

- **Split of the calculation:** `Service\Availability\DayFacts` holds what follows from the posts (slots, holiday,
  booked, locked, ...), `CalendarWindow` what depends on the current date (displayed range, advance booking days,
  start day offset, past days). The calendar json (`View\Calendar`) uses the same two classes, so stored and live
  results cannot drift apart. `DayStatus` condenses a day for map and table.
- **Reading:** `Service\Availability\Reader` returns the day status of pairs. The window is computed on every read.
  Missing or outdated rows are computed on the way and stored. Pairs with timeframes limited to user roles and ranges
  beyond the horizon are evaluated live with the calendar json.
- **Writing:** every change of an index row (`Projector::CHANGED_HOOK`) increases the `gen` of the affected pairs in
  the same request (`Invalidator`). A row is only used if it was computed from the current `gen`
  (`Store::isUsable()`), so a booking is visible immediately. The affected pairs (up to 25) are recomputed at the end
  of the request, after the response was sent when PHP-FPM is used. Larger changes are processed by cron.
- **Cron:** hourly `Maintenance::runCron()` (new pairs, changed rows, rows older than 12 h), daily verification of a
  sample. Rows older than 24 h are never used, to limit the effect of writes that bypass the hooks.
- **Requirements / switches:** needs a complete timeframe index. The filter `commonsbooking_use_availability_cache`
  (return `false`) computes every day live. Further filters: `commonsbooking_availability_horizon_days`,
  `commonsbooking_availability_max_age`, `commonsbooking_availability_sync_limit`,
  `commonsbooking_availability_finish_request`.
- **Changing the calculation:** increase `Calculator::VERSION` when `DayFacts` or the day grid changes, all rows are
  recomputed then. `tests/php/View/CalendarSnapshotTest.php` pins the output of the calendar json, map and table for
  many scenarios and checks stored, live and freshly computed results against each other.
- **WP-CLI:** `wp commonsbooking availability status|rebuild|verify [--repair] [--sample=<n>]|drop`

Tests run with the precomputed availability. Run them with `CB_USE_AVAILABILITY=0` to compute every day live (the CI
runs the suite once this way).

After adding classes under `src/`, run `composer prefix-namespaces`: the plugin only loads the Strauss autoloader in
`vendor-prefixed/`.
