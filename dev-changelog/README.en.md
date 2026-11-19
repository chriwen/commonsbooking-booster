# Developer changelog

*Deutsche Fassung: [README.md](README.md)*

For every change in this fork (booster and feature branches) this directory records **why** it was made, **what**
changed and **which files** are affected. It is meant for developers who review the fork, adopt it or compare it with
the original. The user changelog in `readme.txt` is not affected.

## Rules

- **One file per change**: `YYYY-MM-DD-<topic>.md` (German) and `YYYY-MM-DD-<topic>.en.md` (English, same content).
  A single shared file would cause conflicts on every rebase of the feature branches.
- A "change" is one commit or a short series of commits on one topic. If the same change is corrected later, the
  existing entry is extended (section "Addenda") instead of adding a new one.
- The entry is created **in the same commit** as the code. The commit message names the file; the hash is not added
  afterwards (`git log -- <entry>` shows it).
- List every file that was added, changed or deleted, each with one sentence on its purpose. Generated files
  (`languages/*.mo`, `vendor-prefixed/`) as a group.
- Always assess the downgrade explicitly, even if the answer is "code only".
- Larger design decisions additionally go to `design/`; the entry links there.
- The directory is not packed into the plugin zip (`.distignore`).

## Template

```markdown
# <Short title of the change>

*Deutsche Fassung: [<file>.md](<file>.md)*

- **Date:** YYYY-MM-DD
- **Branch:** `booster/index-availability` | `feature/...`
- **Commit(s):** earlier commits on the topic, if any; the own commit: `git log -- dev-changelog/<file>.md`
- **Type:** feature | bug fix | performance | refactoring | docs | tests | tooling

## Why

Reason (bug, measurement, requirement, decision of the maintainer) and goal. For bugs: how it showed.

## What

What changes in behaviour and code, as bullet points. For switchable behaviour: setting or filter.

## Affected files

| File | Status | Purpose of the change |
|---|---|---|
| `src/...` | added / changed / deleted | ... |

## Downgrade

Result of the checklist in `TECHNICAL.md`: which data is new, where it is stored, what an older version does with it,
how to clean up. For an exception: the reason.

## Tests

New or changed tests, result of phpcbf, PHPStan and PHPUnit, manual checks.

## References

Design files, notes, related entries.
```
