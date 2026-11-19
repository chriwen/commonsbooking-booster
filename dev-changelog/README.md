# Developer-Changelog

*English version: [README.en.md](README.en.md)*

Hier steht für jede Änderung im Fork (Booster und Feature-Branches), **warum** sie gemacht wurde, **was** sich
geändert hat und **welche Dateien** betroffen sind. Der Changelog richtet sich an Entwickler, die den Fork prüfen,
übernehmen oder mit dem Original abgleichen. Der Nutzer-Changelog in `readme.txt` bleibt davon unberührt.

## Regeln

- **Eine Datei pro Änderung**: `JJJJ-MM-TT-<thema>.md` (deutsch) und `JJJJ-MM-TT-<thema>.en.md` (englisch, gleicher
  Inhalt). Eine gemeinsame Datei für alle würde bei jedem Rebase der Feature-Branches Konflikte erzeugen.
- Eine „Änderung“ ist ein zusammengehöriger Commit oder eine kleine Commit-Folge zu einem Thema. Wird dieselbe
  Änderung später nachgebessert, wird der bestehende Eintrag ergänzt (Abschnitt „Nachträge“), statt einen neuen
  anzulegen.
- Der Eintrag wird **im selben Commit** wie der Code angelegt. Die Commit-Nachricht nennt die Datei; der Hash wird nicht
  nachgetragen (er ergibt sich aus `git log -- <eintrag>`).
- Alle Dateien nennen, die neu, geändert oder gelöscht wurden, je mit einem Satz zum Zweck. Generierte Dateien
  (`languages/*.mo`, `vendor-prefixed/`) als Gruppe.
- Downgrade immer ausdrücklich bewerten, auch wenn die Antwort „nur Code“ ist.
- Größere Designentscheidungen gehören zusätzlich nach `design/`; der Eintrag verweist dorthin.
- Das Verzeichnis wird nicht ins Plugin-Zip gepackt (`.distignore`).

## Vorlage

```markdown
# <Kurztitel der Änderung>

*English version: [<datei>.en.md](<datei>.en.md)*

- **Datum:** JJJJ-MM-TT
- **Branch:** `booster/index-availability` | `feature/...`
- **Commit(s):** frühere Commits zum Thema, falls vorhanden; der eigene Commit: `git log -- dev-changelog/<datei>.md`
- **Art:** Feature | Fehlerbehebung | Leistung | Refactoring | Doku | Tests | Werkzeuge

## Warum

Anlass (Fehler, Messung, Anforderung, Entscheidung des Nutzers) und Ziel. Bei Fehlern: wie er sich zeigte.

## Was

Was sich am Verhalten und am Code ändert, in Stichpunkten. Bei abschaltbarem Verhalten: Schalter bzw. Filter.

## Betroffene Dateien

| Datei | Status | Zweck der Änderung |
|---|---|---|
| `src/...` | neu / geändert / gelöscht | ... |

## Downgrade

Ergebnis der Prüfliste aus `TECHNICAL.md`: welche Daten neu sind, wo sie liegen, was eine ältere Version damit tut,
wie man aufräumt. Bei einer Ausnahme: Begründung.

## Tests

Neue oder geänderte Tests, Ergebnis von phpcbf, PHPStan und PHPUnit, manuelle Prüfung.

## Verweise

Design-Dateien, Notizen, verwandte Einträge.
```
