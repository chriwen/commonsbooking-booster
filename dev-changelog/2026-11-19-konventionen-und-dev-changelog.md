# Konventionen für Änderungen und Developer-Changelog

*English version: [2026-11-19-konventionen-und-dev-changelog.en.md](2026-11-19-konventionen-und-dev-changelog.en.md)*

- **Datum:** 2026-11-19
- **Branch:** `booster/index-availability`
- **Commit(s):** `git log -- dev-changelog/2026-11-19-konventionen-und-dev-changelog.md`
- **Art:** Doku, Werkzeuge

## Warum

Der Fork wird mit Claude Code weiterentwickelt. Andere Entwickler sollen nachvollziehen können, was geändert wurde,
warum, und ob eine Änderung downgradebar ist. Bisher waren die Regeln dafür nur aus dem Code ableitbar oder lagen
außerhalb des Repos; ein fortlaufender Changelog für Entwickler fehlte (`design/booster-aenderungen-bestehender-dateien.md`
ist ein einmaliger Stand vom 06.11.2026).

## Was

- Neuer Abschnitt „Conventions for changes (booster fork)“ in `TECHNICAL.md`: am bestehenden Code orientieren,
  Code-Stil, Kommentarregeln (u. a. Kennzeichnung von Eingriffen in Originaldateien mit `Booster:`), Downgrade-Prüfliste,
  einzige Ausnahme `feature/multi-item-timeframes`.
- Neues Verzeichnis `dev-changelog/` mit Regeln und Vorlage, eine Datei pro Änderung, deutsch und englisch.
- `dev-changelog/` wird nicht ins Plugin-Zip gepackt.

## Betroffene Dateien

| Datei | Status | Zweck der Änderung |
|---|---|---|
| `TECHNICAL.md` | geändert | Abschnitt mit den Konventionen für Änderungen im Fork |
| `.distignore` | geändert | `dev-changelog/` aus dem Plugin-Zip ausschließen |
| `dev-changelog/README.md`, `README.en.md` | neu | Regeln und Vorlage des Developer-Changelogs |
| `dev-changelog/2026-11-19-konventionen-und-dev-changelog.md`, `.en.md` | neu | dieser Eintrag |

## Downgrade

Nur Dokumentation, kein Laufzeitcode, keine Daten. Ohne Auswirkung auf einen Downgrade.

## Tests

Keine, da kein Code geändert wurde.

## Verweise

`TECHNICAL.md`, Abschnitt „Conventions for changes (booster fork)“.
