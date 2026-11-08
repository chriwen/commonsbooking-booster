# Performance Booster: Switchable and Downgrade-Safe

*Deutsche Fassung: [booster.md](booster.md)*

As of 6 Nov 2026. Complements [`timeframe-index.en.md`](timeframe-index.en.md) (stage 2) and
[`availability-precompute.en.md`](availability-precompute.en.md) (stage 3). The authoritative description of the current
state is [`technische-dokumentation-booster-02.en.md`](technische-dokumentation-booster-02.en.md); this note records the
decisions and tests concerning the switch, the guard and downgrades.

## Goal

The index and the precomputed availability should be switchable on and off as one option. When switched off,
CommonsBooking runs exactly like the version without these extensions. An update to the booster version should be
revertible to a previous version and re-installable afterwards, without wrong availability being displayed or double
bookings becoming possible.

## Decisions

| Question | Decision | Reason |
|---|---|---|
| Default value | **off** | Nothing changes after the update until someone deliberately switches the booster on. |
| Location of the switch | Settings → Advanced → "Performance booster" (field `booster_enabled` in `commonsbooking_options_advanced-options`), plus filter `commonsbooking_booster_enabled` and WP-CLI | Fits the other cache settings. |
| What happens when "off" | no hooks, no cron jobs, no reading from the tables. The index counts as stale, the tables remain (removal with `purge`). | Switched off it is identical to the original code. Switching on again needs no new table setup, only refilling. |
| Switching on | create tables, **always rebuild the index completely**; original queries until it is finished | While the booster was off, nobody maintained the index. |
| Booking validation | **always** runs with the original queries and without the plugin cache (`Booster::withoutBooster()`): booking form, admin booking, timeframe validation | A stale index can then at most cause a wrong display, never a double booking. The Symfony cache is bypassed as well, because it may contain results that come from the index. A validation concerns one pair and stays fast. |
| Detecting a downgrade | guard (`Guard`) with three checks, see below | The old version leaves no marker; the new one has to recognise the consequences in the data. |

## Guard

| Check | When | Detects | Consequence |
|---|---|---|---|
| Plugin version | every request (autoloaded option) | The upgrade routine of another version has changed `commonsbooking_plugin_version`. The booster remembers its own changes via `update_option_…`. | rebuild |
| New posts without an index row | every request, only posts above the last checked ID (primary key, cheap). Only after 2 minutes, so that parallel requests can still add their posts. | bookings, timeframes, restrictions created by another version | rebuild |
| Status and modification date | hourly and `wp commonsbooking booster check --deep` (join over all posts of the three types) | cancellations and changes by another version, deleted posts | repair individual posts, rebuild from 200 on |
| Tables present | hourly (`SHOW TABLES LIKE`) | database imported without the booster tables | create tables, rebuild |

Limits:

- If the old version only changed meta data without saving the post, only the daily sample of the index verifier
  finds it. For the booking validation this does not matter (original queries).
- Cancellations by the old version (direct SQL, without modification date) are detected by the hourly check via the
  status. Until then the day at most still appears as taken; it can already be booked.
- If the booster version carries the same version number as the previous version, the first check does not apply; the
  other two cover that. That is why the branch carries its own number (`2.11.4-beta.3` versus 2.11.3), so that
  switching between the two versions is already detected via the version option. Example: when 2.11.3 is installed,
  its upgrade routine sets `commonsbooking_plugin_version` to `2.11.3`; when switching back, the guard sees
  `2.11.3 ≠ 2.11.4-beta.3`, which the booster version last wrote itself, and rebuilds the index.

## Recommended procedure for a downgrade

1. Switch the booster off (settings or `wp commonsbooking booster disable`).
2. Install the older version. It ignores the tables `cb_index`, `cb_index_rel` and `cb_availability`, the options
   and the scheduled cron events (without the corresponding code WordPress does not run them).
3. Later install the new version and switch the booster on again. The index is rebuilt.

If step 1 is forgotten, the guard catches it on re-installation. Complete removal:
`wp commonsbooking booster purge`.

## Measurements

- Development environment (about 167,000 posts): switching on with `--now`, index 23.9 s, availability for 100 pairs 1.3 s.
- Test environment: index 0.1 s, 15 pairs 0.2 s. Switched off, the page "Classic views" loads in 213 ms with
  1,190 database queries; switched on in about 116 ms with 48.

## Downgrade test in the test environment

Script `dev-bench/downgrade-scenario.sh`. For this the official version 2.11.3 from wordpress.org is located in the
container under `wp-content/plugins/commonsbooking-upstream`. The script swaps the active plugin via the option
`active_plugins`, i.e. without activation hooks, as when replacing the files.

| Run | With the old version | Result after switching back |
|---|---|---|
| `both` | booking 501 cancelled, booking 660 created | First page: guard reports "post 660 was created without the booster", rebuild. 17 Nov free, 26 Nov booked, 60 days precomputed = live, index and availability without deviations. |
| `cancel` | booking 502 cancelled | Quick checks without findings (no new post). Deep check finds and repairs 502. 24 Nov free, precomputed = live. |

Additionally via the booking form as `testnutzer`: 26 Nov was rejected ("There is already a booking…"),
24 Nov could be booked. Afterwards the index row of booking 660 was deliberately deleted (index stale, but considered
complete). Booking 26 Nov again was still rejected, because the validation uses the original queries. The deep check
restored the row afterwards.

Switching on and off via the settings page: off removes the cron jobs and switches to the original queries.
On rebuilds the index during the next page views.

## Tests

- `tests/php/Service/Booster/BoosterTest.php` (13 tests): switch, bypass during booking validation (also with a
  booking that is missing from the index), switching on/off, all three guard checks, waiting time for parallel requests,
  `purge`.
- Suite in four modes: booster on (555 tests green, as of 6 Nov 2026), `CB_USE_BOOSTER=0` (index and
  availability tests skipped),
  `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`. The additional CI run with the booster switched off is configured in
  `.github/workflows/phpunit.yml`.

## Translation

The strings of the settings page, the status values and the reasons for a rebuild are translatable (WP-CLI output
stays English). `languages/commonsbooking.pot` was generated with `wp i18n make-pot` as in `bin/update-pot.sh`
(without `dev-bench`, `testenv`, `design`), the German `.po` was synchronised with `wp i18n update-po` and completed,
the `.mo` generated with `wp i18n make-mo`. The plugin loads its own `.mo` before the language pack from wordpress.org,
so the translations take effect immediately.

## Symfony cache and booster

All booster measurements come from environments with `WP_DEBUG`, i.e. without the plugin cache. Map and table no
longer need it with the booster. It still buffers repository queries, calendar data of the item page
and lists, however. API and GBFS now also read from the precomputation (technical documentation 6.6), except for
the live fallbacks there; for those and for the item page the cache remains useful. It therefore stays
switched on. Only the booking validation bypasses it.

### Measurement with and without plugin cache (6 Nov 2026)

Measured in the development environment with 167,000 bookings and 100 stations (`dev-bench/cache-measure.sh`,
result in `dev-bench/cache-measure.txt`). Shown is the response time in seconds: with an empty cache, with a
warm cache (median of 3) and directly after a new booking.

| Request                         | Booster, no cache | Booster, cache empty / warm | no booster, no cache |
|---------------------------------|------------------:|----------------------------:|---------------------:|
| map data (Ajax)                 |              0.11 |                 0.26 / 0.08 |  aborted (memory)    |
| `[cb_map]` + `[cb_items_table]` |              0.10 |                 0.15 / 0.06 |  aborted (memory)    |
| `[cb_items]`                    |              0.13 |                 0.14 / 0.11 |                 33.6 |
| `[cb_locations]`                |              0.12 |                 0.10 / 0.12 |                 33.3 |
| item page with calendar         |              0.06 |                 0.07 / 0.04 |                  2.3 |

Result: with the booster the cache only gains a few hundredths of a second; filling it the first time costs a bit more.
It does no harm and stays switched on. Without the booster it is still necessary.

### Cache key and user roles

Timeframes can be restricted to user roles (empty field: everyone may book). Map, tables and calendar therefore
display such items differently depending on the user. The booster always computes these pairs live
(`has_role_limit`). The plugin cache, however, previously did not have the user in its key, so a guest could get
an admin's display from the cache and vice versa. This is a bug that already existed before the booster.
Now `Cache::getCacheId()` contains the role context (guest, sorted roles, admin flag). Users with
the same roles still share one entry. The booking validation itself does not use the cache.

## Diagnostics on the settings page

Under Settings → Advanced → Performance booster (`SettingsView`, data from `Diagnostics`):

- **State** (green/yellow/red) with concrete hints: index being built, number of posts ≠ index rows, booking with
  a deviating status, old precomputed rows, missing or overdue cron tasks, repairs of the last
  deep check.
- **Index:** bar per type (timeframes, bookings, restrictions), latest change in WordPress and in the index.
- **Precomputed availability:** current, changed, old and role-restricted pairs.
- **Latest bookings:** most recently created, changed or cancelled (the cancellation time is taken into account),
  status in WordPress and in the index side by side.
- **Latest index activity:** ring buffer of the last 30 changes (option `commonsbooking_booster_activity`, not
  autoloaded), including deletions, with origin (website, admin, cron, WP-CLI) and a re-check of the current
  state.
- **Checks and scheduled tasks:** last run, result and next run (option `commonsbooking_booster_checks`).
- **Finish setup now** with progress while the index is incomplete or item calendars are missing (see "Setup
  without WP-Cron").
- Buttons **Run all checks now** (guard, deep check, samples, repairs deviations) and
  **Rebuild index**.

All times come from the GMT columns or Unix timestamps and are displayed with `wp_date()` in the time zone of the
WordPress settings. Exception: CommonsBooking stores `cancellation_time` as local time
(`current_time( 'timestamp' )`); this is converted. If the time zone is changed in WordPress, older
posts keep their correct GMT times. Only the local column (`post_date`) of older posts stays in the old time zone.

Tests: `tests/php/Service/Booster/DiagnosticsTest.php`, `CacheRoleContextTest.php`.

## Status card on the CommonsBooking dashboard

So that admins discover the booster after an update, the dashboard (CommonsBooking → Dashboard) gets a **fourth
column "Performance booster"** for admins next to "Configuration" (`SettingsView::renderDashboardColumn()`, included
in `templates/dashboard-index.php`). Requirements from the user:

- A column of its own instead of a card below "Configuration" (it broke the layout there). The four columns always
  stay **in one row** and only get narrower (flexbox without wrapping, weights 1 / 1 / 0.7 / 1.3). The column styles
  come with the column (class `cb-dashboard-four-columns` on the container, admins only); the compiled admin CSS is
  unchanged. The `<style>` block sits inside the column so that it stays the fourth child.
- For users without technical knowledge: no technical terms such as index, original queries or cron names on the
  card. Notes are only counted ("2 notes, details in the settings"); details are on the settings page.

Content:

- **Status dot** with text: grey = switched off, yellow = being set up (with progress bar) or notes, red = problem,
  green = active and fine. The level comes from `Diagnostics::getHealth()`, as on the settings page.
- **"Active, item calendars are being prepared"** (yellow) instead of "everything is fine" as long as not all item
  calendars are up to date, e.g. after many changes at once or when WP-Cron has not run for a while. Until then
  everything is computed live. One sentence below explains this, so that "40 of 41" does not look like an error.
- **Two key figures as "x of y"**, so it is obvious whether everything is captured: timeframes, bookings and
  restrictions in the index out of all in WordPress, and up-to-date prepared item calendars (item/location pairs;
  role-restricted pairs are not counted, as on the settings page) out of all. Unequal values are highlighted in
  yellow. If there are no pairs yet, "–" is shown with a note that they are prepared when the map is shown next.
- **"Last updated … ago"**: latest change in the index (exact time in the tooltip), to give a feeling for whether
  everything is up to date.
- **Short description** in one sentence and a **link** to the performance booster section of the advanced settings
  (anchor `#booster-group-header`, the ID generated by CMB2; the former anchor `#booster_group` did not exist, and
  the redirect after "run checks" now uses the correct anchor as well).
- Badge **"New"** as long as the booster has never been switched on (`activated_at` in the state is 0).

**Calendars right after the index build (7 Nov 2026):** after switching on, the card showed "0 of 41" for up to two
minutes. Cause: the index build marks all rows as outdated and requests a refresh. The request was dropped because
the hourly cron run was due in 60 s anyway right after switching on, and WP-Cron only starts with page views (at most
once per 60 s). Page views in the meantime only computed the pairs they showed.
Decision: `Invalidator::onIndexRebuilt()` computes the calendars in the same background request (WP-Cron, WP-CLI)
via `Maintenance::refreshAfterRebuild()`: sync the pairs (`syncPairs()`, they may have changed while the booster was
off), then compute with the time budget of a cron run (20 s), the rest 30 s later. The computation is cheap (test
environment: 46 pairs in 1.5 s, index build and calendars together 0.9 s in the cron run). Outside background
requests (e.g. tests) the cron run is requested as before. Switch: filter
`commonsbooking_availability_refresh_after_rebuild`. Test: `AvailabilityTest::testIndexRebuildInBackgroundComputesAllPairs`.

Trade-off: the column runs no checks and only reads counts (the same queries as the settings page), so the dashboard
stays fast. It belongs to the booster branch because it only reads and stores nothing (downgradable).

## Setup without WP-Cron (07.11.2026)

**Reason:** On the hosted test instance (all-inkl, 100,000 bookings) the setup stayed at 0 % without any notice after
switching the booster off and on. The rebuild only ran via WP-Cron (`Backfill::start()` schedules an event). WP-Cron
starts itself on page views with a loopback request to the own domain; a directory protection (HTTP password) answers
it with 401 and nobody notices. Until the end the original queries ran and the CB dashboard stayed white. "Rebuild
index" also depended on WP-Cron, and "scheduled task overdue" only appeared after 30 minutes.

Decisions:

| Question | Decision | Reason |
|---|---|---|
| Work without WP-Cron | `Backfill::runFor( $seconds )`: batches until the time budget is used. WP-Cron (20 s), WP-CLI and the settings page use the same path. | One path that every caller can run in slices. |
| Concurrent runs | Lock `commonsbooking_index_backfill_lock` (expiry time, `INSERT IGNORE` on the unique option name, `add_option` is not atomic). If taken, `runFor` returns `busy`, WP-Cron tries again 60 s later. Expires after budget + 60 s if the process dies. | WP-Cron and browser (or two tabs) must not process the same posts twice or trigger `finish()` twice. |
| A slice right away | After switching on (`admin_init` of the next admin page) and after "Rebuild index", 5 s in the same request, only for admins in the backend (not frontend, AJAX, cron, WP-CLI). | Small sites are done at once. Not at `init` priority 0, because the index and availability hooks are registered later. |
| Button | **"Finish setup now"** in the status on the settings page while the index is incomplete or item calendars are missing. The script calls `wp_ajax_commonsbooking_booster_setup` in steps of 8 s (`SettingsView::runSetupStep()`): index first, then calendars (`syncPairs()`, `Maintenance::refresh(8)`), reload at the end. Failures (e.g. time limit) are retried twice, then it stops with a message. Without JavaScript: the same button is a link to `admin-post` with a nonce, one step per click. No `<form>` of its own: the status sits inside the CMB2 settings form, and the browser drops a nested form (script error; without JavaScript the button would have saved the settings). | Runs in the admin's browser and depends neither on WP-Cron nor on the loopback; the browser knows the password of a directory protection. 8 s stays well below common time limits (30 s). |
| Wording | "Finish setup now", not "build in the browser"; progress "Setup in progress … 42 %", "Item calendars are being prepared …", "Done, the booster is active." Technical details (cron name, WP-CLI command) in small print below. | User requirement: understandable without technical knowledge, matching "Being set up" on the dashboard. |
| Automatic start | right after switching on, after "Rebuild index" and via the link in the notice or on the dashboard (`?cb-booster-setup=1`) | The admin is on the page anyway. |
| Is the setup stuck? | `Backfill::isStalled()`: running, and the event is missing or overdue by more than 5 minutes. Then an **error** finding in the status, a notice with button on the WP dashboard and the CommonsBooking pages (only `manage_options`), note and button on the dashboard card. | Early notice instead of 30 minutes. With an external cron and `DISABLE_WP_CRON` every 15 minutes it appears as well; there the setup really only advances by 20 s per 15 minutes. |
| Memory | `runFor()` and `Maintenance::refresh()` also stop once more than 60 % of `memory_limit` is in use (`Booster::isMemoryLow()`); the next step continues. | In the browser test the first admin page after switching on aborted with "Allowed memory size exhausted": Query Monitor (`db.php` drop-in) keeps every query with its backtrace. Such plugins are rare on live sites, but possible. |
| Missing tables | `Schema::tablesExist()` (`SHOW TABLES LIKE`) in `Booster::activate()`, in the hourly guard (then rebuild, reason `tables_missing`) and as an error in the status. Not on every request. | After a database import without the booster tables the options said "installed", `rebuild` created nothing, reads ran into SQL errors. |

Rejected: **keep the index when switching off and on and only repair it with the deep check.** While the booster is
off no hooks run; changes of meta data only (without a new modification date) would not be found by the deep check.
That contradicts "every switch-on rebuilds". With the button and the immediate slice the rebuild finishes quickly
anyway.

For hosts with directory protection: exclude `wp-cron.php` from it (`<Files "wp-cron.php"> Require all granted
</Files>` in `.htaccess`), then WP-Cron and an external cron job run without credentials.

Tests: `tests/php/Service/Booster/SetupTest.php`.

## Double bookings with slow validation

The booking validation always uses the original queries. With 167,000 bookings it takes about 3 seconds. A
second click during that time created a second unconfirmed booking (both requests found the slot free); the
third ended with "Found more than one bookings". This already existed before the booster. Remedy:
- `Booking::withItemLock()`: MySQL lock (`GET_LOCK`) per item around validation and saving. Requests for the same
  item run one after the other; after waiting 20 s there is a message.
- In the browser a small script (`includes/Public.php`) locks the booking forms after the first submit
  ("Please wait …").

## Timeframe variants

`tests/php/Fixtures/TimeframeVariants.php` creates 31 configurations (see `testenv/README.md`).
`TimeframeVariantsParityTest` compares calendar data, precomputed days and map data with the original code
for guest, subscriber, editor, two cb_managers (assigned / not assigned) and admin. Findings while creating them:
CommonsBooking extends the end date to 23:59:59 when saving (so pass the last day with 00:00).
Overlapping bookable timeframes need the same grid. Bookable timeframes have exactly one item and location;
an item is bookable at only one location at a time.
