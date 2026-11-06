# Performance Booster: Switchable and Downgrade-Safe

*Deutsche Fassung: [booster.md](booster.md)*

As of 6 Nov 2026. Complements [`timeframe-index.en.md`](timeframe-index.en.md) (stage 2) and
[`availability-precompute.en.md`](availability-precompute.en.md) (stage 3). The authoritative description of the current
state is [`technische-dokumentation-booster.en.md`](technische-dokumentation-booster.en.md); this note records the
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

Limits:

- If the old version only changed meta data without saving the post, only the daily sample of the index verifier
  finds it. For the booking validation this does not matter (original queries).
- Cancellations by the old version (direct SQL, without modification date) are detected by the hourly check via the
  status. Until then the day at most still appears as taken; it can already be booked.
- If the booster version carries the same version number as the previous version, the first check does not apply; the
  other two cover that. That is why the branch carries its own number (`2.11.4-beta.2` versus 2.11.3), so that
  switching between the two versions is already detected via the version option. Example: when 2.11.3 is installed,
  its upgrade routine sets `commonsbooking_plugin_version` to `2.11.3`; when switching back, the guard sees
  `2.11.3 ≠ 2.11.4-beta.2`, which the booster version last wrote itself, and rebuilds the index.

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
- Buttons **Run all checks now** (guard, deep check, samples, repairs deviations) and
  **Rebuild index**.

All times come from the GMT columns or Unix timestamps and are displayed with `wp_date()` in the time zone of the
WordPress settings. Exception: CommonsBooking stores `cancellation_time` as local time
(`current_time( 'timestamp' )`); this is converted. If the time zone is changed in WordPress, older
posts keep their correct GMT times. Only the local column (`post_date`) of older posts stays in the old time zone.

Tests: `tests/php/Service/Booster/DiagnosticsTest.php`, `CacheRoleContextTest.php`.

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
