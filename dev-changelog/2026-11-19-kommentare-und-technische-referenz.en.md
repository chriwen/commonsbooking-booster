# Booster code commented according to the conventions, technical reference

*Deutsche Fassung: [2026-11-19-kommentare-und-technische-referenz.md](2026-11-19-kommentare-und-technische-referenz.md)*

- **Date:** 2026-11-19
- **Branch:** `booster/index-availability`
- **Commit(s):** `git log -- dev-changelog/2026-11-19-kommentare-und-technische-referenz.md`
- **Type:** docs

## Why

The conventions in `TECHNICAL.md` ("Conventions for changes (booster fork)", entry
`2026-11-19-konventionen-und-dev-changelog.en.md`) apply as of today. The existing booster code was reviewed against
them so that it meets the same rules as future changes:

- classes did not say what happens while the booster is off and what a downgrade leaves behind;
- methods lacked a description or `@param`/`@return`;
- `phpcs:ignore` without a reason;
- changes to original files were not marked, so they did not stand out when comparing with upstream;
- some comments no longer matched the code.

A code reference reflecting the current state was also missing: `technische-dokumentation-booster-02.en.md` describes
concept and flows (as of 7 Nov), `booster-aenderungen-bestehender-dateien.en.md` (as of 6 Nov) does not know later
changes (assigned timeframes, info box, `formatBookableDate()`, redirect in `handleBookingForms()`).

## What

- **New classes** (`src/Service/Booster`, `Index`, `Availability`, `Repository/Index.php`,
  `Repository/AssignedTimeframes.php`, `View/Admin/AssignedTimeframes.php`): class docblocks extended with "Booster
  off" and "Downgrade"; missing method descriptions and `@param`/`@return` added; JS helpers in `SettingsView`
  commented.
- **Original files**: every changed or new method, constant and property carries `@since 2.11.4 Booster: <what and
  why>`, with "Also active without the booster." where it applies; templates, options and SCSS carry
  `Booster (2.11.4)` or `Booster update (2.11.4 …)`. Search:
  `grep -rn "2.11.4 Booster\|Booster (2.11.4)\|Booster update (2.11.4" src includes templates assets/public/sass`.
- **`phpcs:ignore`/`phpcs:disable`** in booster code: reason appended with ` -- ` (43 places). Ignores without a reason
  in original code (`BookingRuleApplied`, `Cache`, `CPT\Booking`) were left unchanged on purpose.
- **Corrected comments**: `AccessStore::query()` (column `last_read`), `Calculator::getComputeCount()` (purpose in the
  tests), `Booster::getState()` (actual content of the option).
- **New technical reference** `design/technische-referenz-booster.md` and `.en.md`: decision chain, components per
  class (with behaviour while the booster is off), tables, version constants, options, cron, filters, actions, WP-CLI,
  flows with pointers to version 2, all changes to original files including changed interfaces, downgrade, rules for
  extending, tests.
- Pointers: version 2 and `TECHNICAL.md` link to the reference; `booster-aenderungen-bestehender-dateien` is marked as
  superseded (kept as the state of 6 Nov).

No runtime code changed (checked: the diff under `src`, `includes`, `templates`, `assets` contains only comments and
reasons after `phpcs:`).

## Affected files

| File | Status | Purpose of the change |
|---|---|---|
| `src/Service/Booster/*.php` (5) | changed | class and method comments, phpcs reasons, JS comments in `SettingsView` |
| `src/Service/Index/*.php` (6) | changed | class and method comments, phpcs reasons |
| `src/Service/Availability/*.php` (15) | changed | class and method comments, reasons, corrected comments in `AccessStore`, `Calculator` |
| `src/Repository/Index.php`, `AssignedTimeframes.php`, `src/View/Admin/AssignedTimeframes.php` | changed | class and method comments |
| `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | changed | `@since 2.11.4 Booster:` on changed methods |
| `src/Model/Booking.php`, `Calendar.php`, `Day.php`, `Item.php`, `Map.php`, `Timeframe.php`, `Week.php` | changed | `@since 2.11.4 Booster:` on changed methods, constants, properties |
| `src/Plugin.php`, `src/Map/MapItemAvailable.php`, `src/View/Calendar.php` | changed | `@since 2.11.4 Booster:` |
| `src/Service/Cache.php`, `src/Service/BookingRuleApplied.php` | changed | `@since 2.11.4 Booster:` |
| `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `Item.php`, `Location.php`, `CustomPostType.php` | changed | `@since 2.11.4 Booster:` |
| `includes/OptionsArray.php`, `includes/Public.php` | changed | marker on the settings group, docblock of `commonsbooking_public()` |
| `templates/dashboard-index.php`, `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | changed | markers on the changes |
| `design/technische-referenz-booster.md`, `.en.md` | new | technical reference of the code |
| `design/technische-dokumentation-booster-02.md`, `.en.md` | changed | pointer to the reference |
| `design/booster-aenderungen-bestehender-dateien.md`, `.en.md` | changed | marked as superseded |
| `TECHNICAL.md` | changed | pointer to the reference |
| `dev-changelog/2026-11-19-kommentare-und-technische-referenz.md`, `.en.md` | new | this entry |

## Downgrade

Comments and documentation only. No change to code, data, options, hooks or cron. No effect on a downgrade.

## Tests

- `phpcbf`: nothing to fix.
- PHPStan: no errors.
- `php -l` on all changed PHP files: no errors.
- PHPUnit (`dev-bench/phpunit/run-multi.sh`): OK, 606 tests, 15767 assertions. One known deprecation notice from
  `src/Messages/RestrictionMessage.php:127` (original code, not part of this change).

## References

`design/technische-referenz-booster.en.md`, `TECHNICAL.md` "Conventions for changes (booster fork)".
