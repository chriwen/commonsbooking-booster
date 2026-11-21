# Booster-Code nach den Konventionen kommentiert, technische Referenz

*English version: [2026-11-19-kommentare-und-technische-referenz.en.md](2026-11-19-kommentare-und-technische-referenz.en.md)*

- **Datum:** 2026-11-19
- **Branch:** `booster/index-availability`
- **Commit(s):** `git log -- dev-changelog/2026-11-19-kommentare-und-technische-referenz.md`
- **Art:** Doku

## Warum

Die Konventionen aus `TECHNICAL.md` („Conventions for changes (booster fork)“, Eintrag
`2026-11-19-konventionen-und-dev-changelog.md`) gelten erst seit heute. Der vorhandene Booster-Code wurde danach
geprüft, damit er dieselben Regeln erfüllt wie künftige Änderungen:

- Klassen ohne Angabe, was bei ausgeschaltetem Booster passiert und was ein Downgrade zurücklässt;
- Methoden ohne Beschreibung oder ohne `@param`/`@return`;
- `phpcs:ignore` ohne Begründung;
- Eingriffe in Originaldateien ohne Kennzeichnung, sodass sie beim Vergleich mit upstream nicht auffielen;
- einzelne Kommentare, die nicht mehr zum Code passten.

Außerdem fehlte eine Code-Referenz im aktuellen Stand: `technische-dokumentation-booster-02.md` beschreibt Konzept und
Abläufe (Stand 07.11.), `booster-aenderungen-bestehender-dateien.md` (Stand 06.11.) kennt spätere Änderungen nicht
(zugeordnete Zeitrahmen, Infobox, `formatBookableDate()`, Weiterleitung in `handleBookingForms()`).

## Was

- **Neue Klassen** (`src/Service/Booster`, `Index`, `Availability`, `Repository/Index.php`,
  `Repository/AssignedTimeframes.php`, `View/Admin/AssignedTimeframes.php`): Klassen-Docblocks um „Booster off“ und
  „Downgrade“ ergänzt; fehlende Methodenbeschreibungen und `@param`/`@return` ergänzt; JS-Hilfsfunktionen in
  `SettingsView` kommentiert.
- **Originaldateien**: jede geänderte oder neue Methode, Konstante und Eigenschaft trägt `@since 2.11.4 Booster: <was
  und warum>`, bei Wirkung auch ohne Booster mit dem Zusatz „Also active without the booster.“; Templates, Optionen
  und SCSS mit `Booster (2.11.4)` bzw. `Booster update (2.11.4 …)`. Suche:
  `grep -rn "2.11.4 Booster\|Booster (2.11.4)\|Booster update (2.11.4" src includes templates assets/public/sass`.
- **`phpcs:ignore`/`phpcs:disable`** im Booster-Code: Begründung mit ` -- ` angehängt (43 Stellen). Unbegründete
  Ausnahmen im Originalcode (`BookingRuleApplied`, `Cache`, `CPT\Booking`) bewusst unverändert gelassen.
- **Korrigierte Kommentare**: `AccessStore::query()` (Spalte `last_read`), `Calculator::getComputeCount()` (Zweck in
  den Tests), `Booster::getState()` (tatsächlicher Inhalt der Option).
- **Neue technische Referenz** `design/technische-referenz-booster.md` und `.en.md`: Entscheidungskette, Komponenten je
  Klasse (mit Verhalten bei ausgeschaltetem Booster), Tabellen, Versionskonstanten, Optionen, Cron, Filter, Aktionen,
  WP-CLI, Abläufe mit Verweisen auf Fassung 2, alle Eingriffe in Originaldateien mit geänderten Schnittstellen,
  Downgrade, Regeln für Erweiterungen, Tests.
- Verweise: Fassung 2 und `TECHNICAL.md` zeigen auf die Referenz; `booster-aenderungen-bestehender-dateien.md` ist
  als ersetzt markiert (bleibt als Stand vom 06.11. erhalten).

Kein Laufzeitcode geändert (geprüft: der Diff unter `src`, `includes`, `templates`, `assets` enthält nur Kommentare
und Begründungen hinter `phpcs:`).

## Betroffene Dateien

| Datei | Status | Zweck der Änderung |
|---|---|---|
| `src/Service/Booster/*.php` (5) | geändert | Klassen- und Methodenkommentare, Begründungen der phpcs-Ausnahmen, JS-Kommentare in `SettingsView` |
| `src/Service/Index/*.php` (6) | geändert | Klassen- und Methodenkommentare, Begründungen der phpcs-Ausnahmen |
| `src/Service/Availability/*.php` (15) | geändert | Klassen- und Methodenkommentare, Begründungen, korrigierte Kommentare in `AccessStore`, `Calculator` |
| `src/Repository/Index.php`, `AssignedTimeframes.php`, `src/View/Admin/AssignedTimeframes.php` | geändert | Klassen- und Methodenkommentare |
| `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | geändert | `@since 2.11.4 Booster:` an geänderten Methoden |
| `src/Model/Booking.php`, `Calendar.php`, `Day.php`, `Item.php`, `Map.php`, `Timeframe.php`, `Week.php` | geändert | `@since 2.11.4 Booster:` an geänderten Methoden, Konstanten, Eigenschaften |
| `src/Plugin.php`, `src/Map/MapItemAvailable.php`, `src/View/Calendar.php` | geändert | `@since 2.11.4 Booster:` |
| `src/Service/Cache.php`, `src/Service/BookingRuleApplied.php` | geändert | `@since 2.11.4 Booster:` |
| `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `Item.php`, `Location.php`, `CustomPostType.php` | geändert | `@since 2.11.4 Booster:` |
| `includes/OptionsArray.php`, `includes/Public.php` | geändert | Kennzeichnung der Einstellungsgruppe bzw. Docblock von `commonsbooking_public()` |
| `templates/dashboard-index.php`, `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | geändert | Kennzeichnung der Eingriffe |
| `design/technische-referenz-booster.md`, `.en.md` | neu | technische Referenz des Codes |
| `design/technische-dokumentation-booster-02.md`, `.en.md` | geändert | Verweis auf die Referenz |
| `design/booster-aenderungen-bestehender-dateien.md`, `.en.md` | geändert | als ersetzt markiert |
| `TECHNICAL.md` | geändert | Verweis auf die Referenz |
| `dev-changelog/2026-11-19-kommentare-und-technische-referenz.md`, `.en.md` | neu | dieser Eintrag |

## Downgrade

Nur Kommentare und Dokumentation. Keine Änderung an Code, Daten, Optionen, Hooks oder Cron. Ohne Auswirkung auf einen
Downgrade.

## Tests

- `phpcbf`: keine Korrekturen.
- PHPStan: keine Fehler.
- `php -l` für alle geänderten PHP-Dateien: fehlerfrei.
- PHPUnit (`dev-bench/phpunit/run-multi.sh`): OK, 606 Tests, 15767 Assertions. Ein bekannter Deprecation-Hinweis aus
  `src/Messages/RestrictionMessage.php:127` (Originalcode, nicht Teil dieser Änderung).

## Verweise

`design/technische-referenz-booster.md`, `TECHNICAL.md` „Conventions for changes (booster fork)“.
