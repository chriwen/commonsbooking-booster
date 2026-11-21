# Conventions for changes and developer changelog

*Deutsche Fassung: [2026-11-19-konventionen-und-dev-changelog.md](2026-11-19-konventionen-und-dev-changelog.md)*

- **Date:** 2026-11-19
- **Branch:** `booster/index-availability`
- **Commit(s):** `git log -- dev-changelog/2026-11-19-konventionen-und-dev-changelog.md`
- **Type:** docs, tooling

## Why

The fork is developed further with Claude Code. Other developers must be able to see what was changed, why, and
whether a change can be downgraded. Until now the rules could only be derived from the code or lived outside the
repository; an ongoing changelog for developers was missing (`design/booster-aenderungen-bestehender-dateien.md` is a
one-time snapshot of 2026-11-06).

## What

- New section "Conventions for changes (booster fork)" in `TECHNICAL.md`: follow the existing code, code style,
  comment rules (including marking changes in original files with `Booster:`), downgrade checklist, the only exception
  `feature/multi-item-timeframes`.
- New directory `dev-changelog/` with rules and template, one file per change, German and English.
- `dev-changelog/` is not packed into the plugin zip.

## Affected files

| File | Status | Purpose of the change |
|---|---|---|
| `TECHNICAL.md` | changed | section with the conventions for changes in the fork |
| `.distignore` | changed | exclude `dev-changelog/` from the plugin zip |
| `dev-changelog/README.md`, `README.en.md` | added | rules and template of the developer changelog |
| `dev-changelog/2026-11-19-konventionen-und-dev-changelog.md`, `.en.md` | added | this entry |

## Downgrade

Documentation only, no runtime code, no data. No effect on a downgrade.

## Tests

None, no code was changed.

## References

`TECHNICAL.md`, section "Conventions for changes (booster fork)".
