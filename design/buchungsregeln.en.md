# Booking rules: bug fixes

Branch `feature/booking-rule-fixes` (based on `booster/index-availability`). Found while testing all seven rule types
on the large test instance (`design/buchungspruefung.en.md` in branch `feature/confirmation-optimisation`, scenario
`hostenv/rules-violations.py` in the workspace). Concerns original code, not the booster; no new tables, options or
postmeta, so downgradable.

## 1. Values of one rule no longer apply to the next one

`BookingRuleApplied::init()` did not reset `$appliesToAll`, `$appliedTerms` and `$selectParam` per rule. A rule for
some categories after a rule "applies to all" applied to all items as well (scenario: "6 days per month, cargo bikes
only" denied a trailer). A rule without a select value took the one of the previous rule.

Now every rule starts with empty values. Consequence for existing settings: a rule without "applies to all" and without
a category, or without a select value, was hidden by a previous rule so far and is invalid now. As with any invalid
rule, the backend shows the error and the booking check then applies no rule (behaviour of `bookingConformsToRules()`
unchanged).

## 2. Reset day of the weekly rules

The select stores 1 = Sunday … 7 = Saturday. `BookingRule::getWeekResetDay()` now applies to both weekly rules:

- "Bookings per week" accepted only 0–5, Friday and Saturday became a wrong day; the message named the day after the
  reset day ("after Tuesday" for Monday).
- "Days per week" accepted 1–6, Saturday became Sunday.
- Invalid or missing values: Sunday (the default as before).

The weekday names come from `$wp_locale` (translated), before from a CMB2 string that stayed English in German. This
also applies to the select in the settings.

## 3. Categories when counting

`filterBookingsForTermsAndUser()` checked the categories on all bookings in the range and then returned all bookings of
the user. With a rule for one category, bookings of other categories counted as soon as anybody had booked the category
in the range. Now only the user's bookings in the rule's categories count (as the docblock describes). Affects
days/bookings per week/month, x days in y days and chain bookings.

Interplay with `feature/confirmation-optimisation`: there `getUserBookingsInRange()` queries the user's bookings first
and loads all bookings only in the special case of this old behaviour. After merging, the special case can go (always
only the user's bookings, then the category filter).

## Tests

`tests/php/Service/BookingRuleFixesTest.php` (fails on the old code in all five cases).

## Open

- The chain booking message gives no reason (only "booking limit reached").
