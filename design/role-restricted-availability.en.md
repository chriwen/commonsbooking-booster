# Precomputation for role-restricted pairs (access profiles)

*Deutsche Fassung: [role-restricted-availability.md](role-restricted-availability.md)*

As of: 07.11.2026, **implemented, merged into `booster/index-availability`** (developed in branch `feature/role-restricted-availability`) (see 8. and 9.). Builds on [availability-precompute.en.md](availability-precompute.en.md)
(stage 3) and resolves the item in section 10 there: "Pairs with a role restriction: live at first. If needed, store
one variant per role combination".

## 1. Starting point

If a pair (item × location) has **any** bookable timeframe with a role restriction (`allowed_user_roles`), the
precomputation stores no days for the whole pair (`has_role_limit = 1`, `days = NULL`). Map, `[cb_search]`,
`[cb_items_table]`, API and GBFS compute it live on every request. The timeframe index still applies; it knows nothing
about roles.

Measurement in DEV (role test, `dev-bench/rollentest/BERICHT.md`, section "Leistung"; 1,000 bookings, 134 pairs,
no cache):

| | per pair | map request (guest / admin) |
|---|---:|---:|
| precomputed | ~0.5 ms | 77 / 150 ms (18 pairs restricted) |
| live | ~7 ms | 782 / 876 ms (all 134 pairs restricted, simulated) |
| without booster | | 1,617 / 1,733 ms |

The cost grows linearly: 1,000 restricted pairs cost about 7 s instead of 0.5 s on every map request. A cache of the
whole response does not help; the map request is not stored as a whole.

Instances that use roles widely (e.g. "members only" on all bikes, or weekends only for associations) thus lose most of
the booster's gain.

## 2. Observation: the user matters in one place only

In the availability computation, everything user-dependent goes through
`commonsbooking_isCurrentUserAllowedToBook($timeframeId)` (`includes/Users.php`), checked on 07.11.2026:

- `Model\Day::filterTimeframe()` (grid of the day)
- `Service\Availability\DayFacts::fromGrid()` (`isUserAllowedToBook` per slot)
- `Repository\Timeframe::filterTimeframesForCurrentUser()` (via `getBookableForCurrentUser`, `getInRangeForCurrentUser`)

The function decides as follows:

```
allowed = role list empty
       || commonsbooking_isCurrentUserAdmin()          (roles from commonsbooking_admin_roles)
       || user roles ∩ role list ≠ ∅
```

It follows that for a pair with the set `R` of all roles appearing in its restricted timeframes, the result depends
only on

```
access_profile(user, pair) = R                          if admin
                            = sorted( user roles ∩ R )  otherwise
```

The access profile describes which of a user's access permissions have any effect on this pair. All users with the
same access profile see exactly the same days for this pair. An admin has the same access profile as a user
with all roles in `R`, since both may book every timeframe of the pair.

Examples from the role test:

| Pair | R | guest, subscriber, editor | rt_verein1 | rt_multi (association + school) | admin |
|---|---|---|---|---|---|
| R02 (association only) | {rt_verein} | `∅` | `rt_verein` | `rt_verein` | `rt_verein` |
| R06 (Mon/Wed/Fri association, Tue/Thu school) | {rt_schule, rt_verein} | `∅` | `rt_verein` | `rt_schule,rt_verein` | `rt_schule,rt_verein` |

R02 thus has only two access profiles, R06 four. In practice the number stays small, because `R` usually holds one or two
roles per pair.

## 3. Proposal

**Stored availability per pair and access profile**, computed on demand, in a table of its own.

### 3.1 Data model

New table; the existing `cb_availability` stays unchanged:

```sql
CREATE TABLE {prefix}cb_availability_access (
  item_id       bigint(20) unsigned NOT NULL,
  location_id   bigint(20) unsigned NOT NULL,
  access_profile varchar(191) NOT NULL,      -- sorted roles, comma-separated; '' = no matching role
  computed_gen  int unsigned NOT NULL,        -- pair generation (cb_availability.gen) at computation time
  days_from     date NOT NULL,
  days          varbinary(1024) NOT NULL,     -- like cb_availability.days
  slots         mediumblob NULL,              -- like cb_availability.slots (API, GBFS)
  computed_at   datetime NOT NULL,
  calc_version  smallint unsigned NOT NULL,
  last_read     date NULL,                    -- for cleaning up rarely used access profiles
  PRIMARY KEY (location_id, item_id, access_profile)
);
```

- **Valid** if `computed_gen = cb_availability.gen` of the pair, plus the same rules as today (`calc_version`,
  `computed_at` younger than 24 h, day range covered).
- **Invalidation costs nothing extra**: every change to the pair already bumps `cb_availability.gen`, which makes all
  stored access profiles of the pair invalid. Changing `allowed_user_roles` works the same way (checked in the role test with
  R13/R14).
- **A user's role change** needs no invalidation: the access profile is derived from the current roles on every
  request, so the user simply lands on a different access profile.

### 3.2 Reading

In the `Reader` (and `SlotReader`), at the point that returns `null` today when `has_role_limit = 1`:

1. For all requested pairs with `has_role_limit = 1`, determine the role sets `R`: IDs of the restricted timeframes
   from the index (`has_role_limit = 1`), role lists from postmeta (`update_meta_cache` for all at once).
   One or two queries per request.
2. Build the current user's access profile per pair (2.).
3. Load the stored rows of all pairs for these access profiles with one query.
4. Valid row: unpack the day facts, apply window rules as before. The window (next bookable timeframe, advance
   booking) is determined live and per user from the index, as today.
5. Missing or invalid row: compute (3.3), write with the generation check, return.

### 3.3 Computing

`Calculator::computeAccessProfile($itemId, $locationId, $accessProfile)` uses the same computation core as today. For the
duration of the computation, a user without an ID and with exactly the access profile's roles is set as the current user
(global `$current_user`, restored in `finally`). Without an ID, `commonsbooking_isCurrentUserAdmin()` is false and the
role check decides by roles alone. This also covers the admins' access profile (`R`, see 2.).

So there stays **one computation core** (stage 3, section 8): `Calculator` and the computation path stay unchanged,
only `Reader` and `SlotReader` get the access profile branch. No files of the original change.

Writing works as in stage 3, 6.3: read generation `G` before computing, apply `INSERT … ON DUPLICATE KEY UPDATE` only
if `cb_availability.gen` is still `G` afterwards. A concurrent change does not make the result valid.

### 3.4 Precomputation and cleanup

- On rebuild and after an invalidation: precompute the two most common access profiles per restricted pair, `∅` (guests and
  everyone without a matching role) and `R` (admins and users with all roles). Further access profiles are created on first
  request.
- Cap per pair (default 8, filterable): above it, compute live as today. Protects against role combinations filling
  the storage.
- The daily cron deletes rows not read for 30 days (`last_read`, written at most once a day) and checks a sample
  against the live computation (verifier).

### 3.5 Backward compatibility and downgrade

- Only one new table; no change to postmeta, to `cb_availability` or to the index.
- Older booster versions do not know the table and compute restricted pairs live as before.
- `wp commonsbooking booster purge` removes the new table as well.
- Switch: part of `Availability::isEnabled()`, additionally switchable off by filter.
- Implementation: `Schema::SCHEMA_VERSION` 2 → 3. On update, the existing rule ("a structure change empties the
  cache") empties `cb_availability` once; readers and cron fill it again. On a downgrade to an older booster version,
  that version sees version 3 as foreign, empties `cb_availability` and works normally. The table
  `cb_availability_access` then stays unused; a `purge` of the older version does not remove it either (harmless, a
  new update or a `purge` of this version removes it).

## 4. Rejected alternatives

| Alternative | Why not |
|---|---|
| Store per timeframe and overlay on read | Bookings, holidays, restrictions and the priority of overlapping timeframes act on the whole pair. Overlaying would have to rebuild the logic of `Day::getGrid()` and violates "one computation core". |
| Cache the whole map response per role context | Invalid on every booking anywhere, large, separate per page and map. Only helps repeated requests. |
| Store per user | Many rows, little reuse. The access profile merges all users with the same effect. |
| Store per role combination of the whole instance | Most roles have no effect on a given pair. The intersection with `R` keeps the number per pair small. |
| Profile rows in `cb_availability` instead of the separate table `cb_availability_access` | **Rejected on 2026-11-08; profile rows stay in `cb_availability_access`, even if backward compatibility did not matter.** The pair row carries `gen` (invalidation, generation check on write) and exists exactly once per pair. Profile rows exist several times per pair, with their own lifetime (`last_read`, per-pair limit). One table would mean two row kinds with different mandatory fields, a special row in the primary key and a filter in every query, and the hot path of unrestricted pairs would share table and index with the profile rows. One table per row kind remains the clean model. |

## 5. Risks

| Risk | Countermeasure |
|---|---|
| The role check in the original changes (e.g. a new exception for location admins, or a filter) | Access profile and check live in one class referencing `commonsbooking_isCurrentUserAllowedToBook`. The role test (`dev-bench/rollentest`, booster vs. original per user) and a PHPUnit test with the same cases will catch it. |
| Third-party plugins filter roles or the role check | The function has no filter today. The `commonsbooking_admin_roles` filter is covered by the admins' access profile. A filter to switch this off remains as a fallback. |
| Very many access profiles per pair | Cap per pair (3.4), above it live |
| First request per access profile after a change is as slow as today | Precompute `∅` and `R` (3.4). Other access profiles cost ~7 ms per pair once. |

## 6. Expected effect

After the first request per access profile, a restricted pair costs as much as an open one (~0.5–1 ms). In the simulation
"all pairs restricted", the map request thus drops from ~800 ms to the level without restrictions (~80–150 ms).
Storage: about 190 bytes of days per access profile, plus `slots`.

## 7. Tests

- Role test (`dev-bench/rollentest`): `check.php`, `http_check.py`, `search_check.py`, `search-parity.php` must stay at
  0 deviations, now with stored rather than live-computed access profiles. `store.php` shows the
  access profiles.
- PHPUnit: access profile (empty, one role, several, admin, role without effect), validity after a `gen` bump, generation
  check on write, cap per pair, a user's role change.
- `perf-roles.php` with access profiles: per pair and in total, before and after the first request.

## 8. Decisions

Decided by the user on 07.11.2026:

1. **Branch:** belongs in the booster branch `booster/index-availability` (PR #1). Reason: only one table of its own,
   downgradable, and it closes a gap in the precomputation (stage 3, section 10). Added on 07.11.2026: implement it
   first in a branch of its own, `feature/role-restricted-availability` (state of `booster/index-availability`), check
   it and ship it as a test release. Only when everything works, take it over into the booster branch and then rebase
   the feature branches onto it. Merged on 07.11.2026 at the user's request.
2. **Cap per pair:** 8 access profiles by default, changeable by filter. Above it, live as before.
3. **Precomputation:** on rebuild and after an invalidation, `∅` (guests and everyone without a matching role) and `R`
   (admins and users with all roles) are computed right away. Further access profiles are created on first request.

## 9. Implementation and verification

Branch `feature/role-restricted-availability` (state of `booster/index-availability`, 572bb932), as of 07.11.2026.

**Code**

| File | Content |
|---|---|
| `src/Service/Availability/AccessProfile.php` | new: the user's access profile, roles `R` per pair (index + postmeta, batched), `runAs()`, cap and switch |
| `src/Service/Availability/AccessStore.php` | new: read and write table `cb_availability_access` (with generation check), cleanup, statistics |
| `Schema.php` | second table, `SCHEMA_VERSION` 3, `uninstall` removes both |
| `Calculator.php` | `compute()` with access profile, `computeAccessProfile()`, `recompute()` also computes `∅` and `R` |
| `Reader.php`, `SlotReader.php` | restricted pairs read the user's access profile, otherwise as before |
| `Maintenance.php`, `Cli.php` | daily cron cleans up, `verify` also checks access profiles, `status` shows them |
| `Booster/SettingsView.php` | settings page shows the access profiles, translations added |

Filters: `commonsbooking_availability_access_profiles` (on/off, default on),
`commonsbooking_availability_access_profiles_max` (default 8).

**Verification**

- PHPUnit: 586 tests green, 14 of them new in `tests/php/Service/Availability/AccessProfileTest.php` (access profile per
  user, result equal to live for guest, subscriber, editor, author, both roles, admin, no recomputation for stored
  profiles, invalidation by booking and changed roles, role change, cap, switch off, cleanup, `verify` with repair, API
  slots). PHPStan and phpcbf without errors.
- Role test in DEV (11 users × 17 pairs, 92 days): `check.php`, `http_check.py`, `search_check.py`,
  `search-parity.php`, `table-parity.php` 14 and 31: 0 deviations. `verify`: 0 deviations.
- Measurement DEV (`perf-roles.php`, median, no cache):

| | per restricted pair | map request admin |
|---|---:|---:|
| role test, 18 of 102 pairs restricted | 0.5–1.0 ms (live 1.6–3.7 ms) | 62 ms (before 150 ms) |
| all 102 pairs restricted (`sim-all-restricted.php`), without access profiles | 8–9 ms | 936 ms |
| all 102 pairs restricted, with access profiles | 0.6–1.0 ms | 62 ms |
| original without booster | 7.5–8 ms | 1,900 ms |

**Note for worktrees:** `vendor-prefixed/` contains the plugin's classmap (`include_root_autoload`). After adding
classes run `composer run prefix-namespaces`, otherwise they are missing outside PHPUnit.
