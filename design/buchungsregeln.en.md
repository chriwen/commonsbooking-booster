# Booking rules: bug fixes

Branch `feature/booking-rule-fixes` (based on `booster/index-availability`). The bugs showed up while testing all
seven rule types on the large test instance. The scenario is described in `design/buchungspruefung.en.md` in the
branch `feature/confirmation-optimisation`; its script is `hostenv/rules-violations.py` in the workspace.

The changes concern original code, not the booster. There are no new tables, options or postmeta, so the branch stays
downgradable.

This document is meant for developers of the booking rules. For each bug it gives the cause, the code before and
after, the changed behaviour and how to verify it.

## Overview

| # | Bug | File | Visible as |
|---|---|---|---|
| 1 | Values of one rule carry over to the next | `src/Service/BookingRuleApplied.php` | a rule for one category applies to all items |
| 2 | Reset day of the weekly rules | `src/Service/BookingRule.php` | "after Tuesday" for Monday, Friday/Saturday wrong, weekdays in English |
| 3 | Categories when counting | `src/Service/BookingRule.php` | bookings of other categories are counted |

Tests: `tests/php/Service/BookingRuleFixesTest.php`. All five tests fail on the old code.

Background: The rules are configured under Settings → Restrictions → Booking rules and are stored in the option
`commonsbooking_options_restrictions`, key `rules_group`. Each rule is an array with these keys:

- `rule-type`
- `rule-param1`, `rule-param2`
- `rule-select-param`
- `rule-applies-all` (`'on'`)
- `rule-applies-categories` (term IDs)
- `rule-exempt-roles`

`BookingRuleApplied::init()` builds the rule objects from it. `BookingRuleApplied::bookingConformsToRules()` applies
them when an unconfirmed booking is created. The check functions receive
`$args = [ param1, param2, selectParam ]` (see `getArgs()`).

## 1. Values of one rule no longer apply to the next one

### Cause

`init()` loops over all configured rules. Only `$ruleParams` was reset per rule. `$selectParam`, `$appliesToAll` and
`$appliedTerms` were only assigned when the rule had a value; otherwise they kept the value of the previous rule. The
fallbacks `?? false`, `?? []` and `?? null` only took effect for the first rule.

```php
// before, per rule in the loop
$ruleParams = [];
…
if ( ! empty( $ruleConfig['rule-select-param'] ) ) {
    $selectParam = $ruleConfig['rule-select-param']; }
if ( ! empty( $ruleConfig['rule-applies-all'] ) && $ruleConfig['rule-applies-all'] === 'on' ) {
    $appliesToAll = true;
}
// $appliedTerms is only set when categories are configured
…
$bookingRule->setAppliesToWhat( $appliesToAll ?? false, $appliedTerms ?? [] );
$bookingRule->setAppliedParams( $ruleParams, $selectParam ?? null );
```

Consequences:

- A rule for some categories after a rule "applies to all" applied to all items as well, because `$appliesToAll` was
  still `true`. In the scenario, the rule "6 days per month, cargo bikes only" denied a trailer.
- A rule without a category and without "applies to all" took the setting of the previous rule.
- A rule without a select value, e.g. without a reset day, took the select value of the previous rule.

### After

```php
$ruleParams   = [];
$selectParam  = null;
$appliesToAll = false;
$appliedTerms = [];
…
$bookingRule->setAppliesToWhat( $appliesToAll, $appliedTerms );
$bookingRule->setAppliedParams( $ruleParams, $selectParam );
```

### Consequences for existing settings

For correctly filled-in rules only the result changes: category rules now really apply to their categories only.

A rule that lacks a value was hidden by the previous rule so far and is invalid now. This concerns rules without
"applies to all" and without a category, and rules that need a select value and have none. For invalid rules, the
behaviour does not change:

- When the settings are saved, `Plugin` calls `BookingRuleApplied::validateRules()`. Invalid rules produce the known
  error notice in the backend.
- `bookingConformsToRules()` catches the `BookingRuleException` from `init()` and then applies **no** rule. This was
  already the case before.
- `getRulesJSON()` (settings page) calls `init( true )` and still shows the rules.

Before an update you can check whether an instance is affected:

```bash
wp eval 'try { echo count( CommonsBooking\Service\BookingRuleApplied::init() ), " rules valid\n"; } catch ( Exception $e ) { echo "invalid: ", $e->getMessage(), "\n"; }'
```

Run the command once with the old code and once with the new code. If a rule becomes invalid only with the new code,
the previous rule used to hide a missing value.

### Verify

- `testCategoryRuleAfterRuleForAllKeepsItsCategories`: rule "all", then a category rule, then "all" again. The
  category rule must have `appliesToAll = false` and only its category, the third rule no categories.
- `testMissingSelectParamIsNotTakenFromPreviousRule`: a weekly rule without a reset day after one with a reset day
  must throw a `BookingRuleException`.

## 2. Reset day of the weekly rules

The select stores 1 = Sunday, 2 = Monday … 7 = Saturday. The 0 is left out because CMB2 treats it as empty.
`getBookingWeekRange( $booking, $resetDay )` expects this value and subtracts 1 itself. The rules used the value in
different ways, though.

### Before

```php
// checkMaxBookingDaysPerWeek(): 1–6, Saturday (7) becomes Sunday (1)
$resetDay = ( $args[2] < 7 && $args[2] >= 1 ) ? $args[2] : 1;

// checkMaxBookingsWeek(): 0–5, Friday (6) and Saturday (7) become 0
$resetDay = ( $args[2] < 6 && $args[2] >= 0 ) ? $args[2] : 0;

// maxDaysWeekErrorMessage(): correct (index from 0)
$resetDayString = explode( ', ', $weekDays )[ $args[2] - 1 ];

// maxBookingsWeekErrorMessage(): one day late, undefined index for 7
$resetDayString = explode( ', ', $weekDays )[ $args[2] ];

// weekdays: string from the CMB2 text domain, mostly untranslated on German instances
$weekDays = __( 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday', 'cmb2' );
```

Recomputed for "bookings per week" with the old code (for each weekday of the booking: start of the week and length
of the range):

| Select | Week starts | Issue |
|---|---|---|
| 1–5 (Sun–Thu) | correct | message names the following day ("after Tuesday" for Monday) |
| 6 (Friday) | Saturday | wrong day |
| 7 (Saturday) | Saturday | booking on a Saturday: range of 14 instead of 7 days, message without a day |

Cause of the last two rows: in `getBookingWeekRange()` the value 0 becomes −1. That turns into `"Sunday +-1 days"`,
i.e. Saturday. The comparison `format( 'w' ) == -1` never matches, so on a Saturday the range runs from
`last saturday` to `next saturday`.

### After

```php
private static function getWeekResetDay( array $args ): int {
    $resetDay = $args[2] ?? null;
    return ( is_numeric( $resetDay ) && $resetDay >= 1 && $resetDay <= 7 ) ? (int) $resetDay : 1;
}

private static function getWeekDayNames(): array {   // index 0 = Sunday
    global $wp_locale;
    if ( $wp_locale instanceof \WP_Locale ) {
        return array_map( fn( $day ) => $wp_locale->get_weekday( $day ), range( 0, 6 ) );
    }
    return explode( ', ', __( 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday', 'cmb2' ) );
}
```

The methods use the helpers like this:

- `checkMaxBookingDaysPerWeek()` and `checkMaxBookingsWeek()` call
  `getBookingWeekRange( $booking, self::getWeekResetDay( $args ) )`.
- Both messages use `self::getWeekDayNames()[ self::getWeekResetDay( $args ) - 1 ]`.
- The select in the settings (`BookingRule::init()`) uses the same names:
  `array_combine( range( 1, 7 ), self::getWeekDayNames() )`. The stored values 1–7 stay the same; only the displayed
  names are translated now.

Invalid or missing values result in Sunday, which was already the default.

### Changed behaviour

| Rule | Select | before | after |
|---|---|---|---|
| Days per week | 7 (Saturday) | week from Sunday | week from Saturday |
| Bookings per week | 6 (Friday) | week from Saturday | week from Friday |
| Bookings per week | 7 (Saturday), booking on a Saturday | 14 days | 7 days |
| Bookings per week | any, message | following day or empty | the configured day |
| both | weekday in message and select | English | language of the instance |

All other settings count exactly as before.

### Verify

- `testWeekResetDay`: checks the values 1, 2, 6, 7, `'7'`, 0, 8 and `null`. For a booking on a Wednesday, start and
  end of the week must fall on the configured weekday (select 1, 2, 6, 7).
- `testWeekMessagesNameTheResetDay`: the messages name Monday or Saturday, not Tuesday. The tests run in English.
- By hand: create the rule "bookings per week" with reset on Monday and limit 1, then try two bookings in the same
  week. The message must say "after Monday".

## 3. Categories when counting

### Cause

`filterBookingsForTermsAndUser()` is called by the rules with a range (days per week/month, x days in y days,
bookings per week/month) and by the chain booking rule. The function checked the categories on **all** bookings and
then returned all bookings of the user, not the filtered ones:

```php
// before
$filteredTerms = Booking::filterTermsApply( $bookings, $terms );
if ( ! empty( $filteredTerms ) ) {
    return Booking::filterForUser( $bookings, $user );   // $bookings, not $filteredTerms
} else {
    return null;
}
```

So with a rule for one category, the user's bookings in other categories counted as soon as anybody had booked the
category in the range. Whether a rule applied thus depended on the bookings of other users. The docblock and the name
of the function, on the other hand, describe "category **and** user".

### After

```php
$filteredTerms = Booking::filterTermsApply( $bookings, $terms );
if ( empty( $filteredTerms ) ) {
    return null;
}
$filtered = Booking::filterForUser( $filteredTerms, $user );
return empty( $filtered ) ? null : $filtered;
```

`filterTermsApply()` returns all bookings unchanged for empty categories (`false`, `[]`, rule "applies to all"). So
nothing changes for rules that apply to all items. `filterForUser()` never returns `null`, only an empty array. The
callers treat `null` and `[]` the same (`empty()`). The function now consistently returns `null`.

### Changed behaviour

The behaviour only changes for rules restricted to categories:

| Bookings in the range | counted before | counted after |
|---|---|---|
| user: 1× category A, 1× B; rule for A | A and B | A |
| user: 1× B; other user: 1× A; rule for A | B | nothing |
| user: 1× B; nobody A; rule for A | nothing | nothing |

For the chain booking rule, the filter only decides whether there is an adjacent booking of the user in the rule's
categories at all. The length of the chain (`getBookingChain()`) is still computed without a category filter. That is
unchanged and not part of this branch.

### Verify

- `testOnlyBookingsOfTheRuleCategoriesCount`: three bookings (user in A, user in B, other user in A).
  - Rule for A: only the user's A booking.
  - Rule for B: only the user's B booking.
  - Rule without a category: both bookings of the user.
  - Only the other user booked A: `null`.
- By hand: rule "bookings per month = 1, category A only". The user first books an item from B, then one from A. The
  second booking must go through; before, it was denied as soon as anybody had booked A in that month.

## Interplay with `feature/confirmation-optimisation`

There, `BookingRule::getUserBookingsInRange()` first queries only the user's bookings. It loads all bookings in the
range only in the special case where the old behaviour of bug 3 depended on other users' bookings. That branch has
to keep the old behaviour exactly, because it is only meant to make things faster.

In `booster2` both branches come together. There the special case is gone: only the user's bookings count, filtered
by category. After this fix, that is exactly the result of `filterBookingsForTermsAndUser()` over all bookings.

## How to verify

In the workspace:

```bash
# only the tests of this branch, then everything like the CI
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRuleFixesTest
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit
(cd checkouts/booking-rule-fixes && vendor/bin/phpstan analyse --memory-limit=1G)
(cd checkouts/booking-rule-fixes && vendor/bin/phpcbf -q src templates includes tests commonsbooking.php)
```

Cross-check that the tests really detect the bugs: reset `src/` to the state of the booster branch and run the tests
again. All five must fail.

```bash
git -C checkouts/booking-rule-fixes checkout booster/index-availability -- src
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRuleFixesTest
git -C checkouts/booking-rule-fixes checkout HEAD -- src
```

Scenario on the large instance: `hostenv/rules-setup.php set` sets the rules, and the scenario runs with
`hostenv/rules-violations.py` (see `design/buchungspruefung.en.md`). With this branch, the cargo bike rule no longer
denies attempt 11 (item B, no cargo bike category). The booking is still denied, but now by one of the
other rules, because December is already used up (e.g. "bookings per month": three bookings). Which rule applies first
depends on the order and on the range of the rule "x days in y days". What can be checked is that the message no
longer comes from the cargo bike rule.

## Open

- The chain booking message gives no reason, only "booking limit reached".
- The chain booking rule computes the length of the chain without a category filter (see bug 3).
