# Buchungsprüfung auf großen Instanzen

Branch `feature/confirmation-optimisation` (auf `booster/index-availability`). Anlass: Ein Testnutzer der Hoster-Instanz
(700 Artikel, 500 Standorte, 100.000 Buchungen) brauchte nach „Weiter zur Buchungsprüfung“ ca. 13 s und nach
„Buchung bestätigen“ ca. 7 s, bis die Seite neu geladen war.

## Befund

Beide Formulare laufen über `Plugin::handleBookingForms()` → `Booster::withoutBooster()` → `Booking::handleBookingRequest()`.
Die Prüfung nutzt nach der Booster-Vorgabe immer die Originaldaten (Postmeta), nie den Index. Gemessen (lokale Kopie der
Hoster-Instanz, Serverzeit je Formular-Request, warm):

| Stelle | Zeit | Ursache |
|---|---|---|
| `getExistingBookings` → `getByTimerange` | 1,7–2,3 s | `WP_Query` mit 5 Postmeta-Joins; MySQL beginnt bei `wp_posts` und liest **alle** Buchungen (EXPLAIN: 99.308 Zeilen, „Using temporary; Using filesort“) |
| `getByDate` | 1,1–1,4 s | dieselbe Abfrage mit `=` |
| `Invalidator::onShutdown` (nur Bestätigen) | 0,8–1,7 s | siehe „exit in withoutBooster“ |
| ~10× `Timeframe::get` | je ~0,06–0,1 s | Zeitrahmen desselben Paars, mehrfach gesucht |

Die Annahme in `booster.md` („Eine Prüfung betrifft ein Paar und bleibt schnell“) galt nicht: Die Abfrage filtert zwar
auf ein Paar, wächst aber mit der gesamten Buchungshistorie.

## Entscheidungen

### 1. Eine Abfrage statt zwei

`handleBookingRequest()` holt die überlappenden Buchungen einmal (`getByTimerange`) und sucht darin die Buchung mit
genau diesem Slot (`findExactSlot()`: Start als Zahl, Ende als Zeichenkette verglichen, wie `getByDate`). Eine Buchung
mit genau diesem Slot überlappt ihn immer. Bei Start > Ende (ungültige Anfrage) bleibt es beim bisherigen Weg.

### 2. Postmeta-Abfrage für ein Paar ohne `WP_Query`

`Repository\Booking::findForItemLegacy()` ersetzt die Meta-Query in `getByDate` und `getByTimerange`, wenn ein Artikel
angegeben ist und keine zusätzlichen `WP_Query`-Argumente übergeben werden (sonst unverändert `WP_Query`, z. B. bei
den Buchungsregeln mit `author`/`orderby`). Sie liest weiterhin nur Posts und Postmeta, also die Originaldaten:

- `STRAIGHT_JOIN` ab den `item-id`-Zeilen, alle anderen Bedingungen per `post_id`. Varianten gemessen:
  `WP_Query` 1,2–2,2 s; ab `item-id` ohne `STRAIGHT_JOIN` 1,2 s (MySQL wählt wieder `wp_posts`); ab `repetition-end`
  0,1–0,26 s, für vergangene Zeiträume 0,8 s; **ab `item-id` mit `STRAIGHT_JOIN` 0,05–0,16 s** unabhängig vom Datum.
- Vergleiche wie die Meta-Queries: Start/Ende als `CAST(... AS SIGNED)`, exaktes Ende, `type`, Artikel und Standort als
  Zeichenkette. Reihenfolge `post_date DESC` (WP_Query-Standard).
- Die bisherigen Abfragen bleiben als `getByDateWithMetaQuery()`/`getByTimerangeWithMetaQuery()` erhalten (intern,
  für den Paritätstest `tests/php/Repository/BookingLegacyLookupTest.php`).

Keine neuen Indizes auf Core-Tabellen (Downgradebarkeit, Vorgabe „nur eigene Tabellen“). Die Kosten wachsen weiter
linear mit der Zahl aller `item-id`-Zeilen, aber etwa 15-mal flacher (100.000 Buchungen: 0,05–0,16 s).

### 3. `exit` in `withoutBooster()` (Fehler im Booster-Branch)

`handleFormRequest()` leitete mit `wp_safe_redirect(); exit;` **innerhalb** von `withoutBooster()` weiter. Bei `exit`
laufen keine `finally`-Blöcke: Der Booster blieb für den Rest des Requests umgangen, und die Artikelsperre
(`withItemLock`) wurde erst beim Verbindungsende frei. Die Neuberechnung der Verfügbarkeit in `shutdown` lief deshalb
ohne Index (650 statt 7 Abfragen, 0,8–1,7 s), und unter mod_php wartet der Browser darauf.

Lösung: `Booking::processFormRequest()` prüft und speichert und gibt die Buchungs-ID zurück,
`Booking::redirectToBooking()` leitet weiter. `Plugin::handleBookingForms()` ruft die Weiterleitung erst nach
`withoutBooster()` auf. `handleFormRequest()` bleibt für bestehende Aufrufer (prüfen + weiterleiten).

### 4. Buchungsregeln: zuerst die Buchungen des Nutzers

Die Regeln (Einstellungen → Einschränkungen → Buchungsregeln) laufen nur beim Anlegen der unbestätigten Buchung
(„Weiter zur Buchungsprüfung“), nicht beim Bestätigen. Die Regeln mit Zeitraum (Tage pro Woche/Monat, x Tage in
y Tagen, Buchungen pro Woche/Monat) luden in `checkBookingRangeForDays()`/`checkBookingAmount()` **alle** Buchungen
aller Nutzer im Zeitraum (`WP_Query` ohne Artikel und Nutzer, bei 100.000 Buchungen ~2.500 je Monat) und filterten
erst in PHP auf den Nutzer: ~1,5 s je Regel, mit allen sieben Regeltypen 4,6 s.

`BookingRule::getUserBookingsInRange()` fragt zuerst nur die Buchungen des Nutzers ab (`author`, 0,004–0,04 s). Das
Ergebnis bleibt gleich wie bisher (`filterBookingsForTermsAndUser()` über alle Buchungen): Nur wenn die Regel auf
Kategorien beschränkt ist und keine Buchung des Nutzers eine davon hat, hängt es von den Buchungen anderer Nutzer ab
(siehe „Auffälligkeiten“, 3); dann wird wie bisher alles geladen. Paritätstest:
`tests/php/Service/BookingRuleUserRangeTest.php`.

### Ergebnis

| Schritt | vorher | nachher |
|---|---|---|
| „Weiter zur Buchungsprüfung“ (Serverzeit), ohne Buchungsregeln | 1,2–4,9 s, kalt bis 17,8 s | 0,38–0,46 s |
| „Weiter zur Buchungsprüfung“, alle sieben Regeltypen aktiv | ~5 s | 0,48–0,76 s |
| „Buchung bestätigen“ (Serverzeit) | 3,5–10,5 s | 0,39–0,82 s |

Gemessen mit demselben Skript (Formulare wie ein Browser, `testnutzer` bzw. `regeltest`), lokal, Docker mit 4 GB.
Regeln: `hostenv/rules-setup.php`, Zeit je Regel: `hostenv/rules-bench.php` (Arbeitsbereich). Die Kettenbuchungs-Regel
braucht weiter ~0,1–0,2 s (Nachbarbuchungen einzeln gesucht), alle anderen Regeln zusammen unter 0,01 s.

### Verhalten der Buchungsregeln (Szenario mit Verstößen)

Mit allen sieben Regeltypen hat ein Szenario mit 13 Buchungsversuchen (`regeltest`, vier Artikel) jede Regel
ausgelöst. Abgelehnt wird mit der Meldung der Regel und den betroffenen Buchungen. Die Ergebnisse waren vor und nach
4 gleich. Auffälligkeiten (im Originalcode, nicht geändert):

1. **Kategorie-Einschränkung rutscht durch:** `BookingRuleApplied::init()` setzt `$appliesToAll`/`$appliedTerms`
   je Regel nicht zurück. Eine Regel nur für „Lastenrad“ nach einer Regel „gilt für alle“ gilt ebenfalls für alle
   (im Szenario: 6-Tage-pro-Monat-Regel lehnt einen Anhänger ab).
2. **Falscher Wochentag in der Meldung:** „Buchungen pro Woche“ mit Zurücksetzen am Montag meldet „nach Tuesday“
   (`maxBookingsWeekErrorMessage()` nutzt den Auswahlwert 1–7 als Index ab 0). Freitag und Samstag als Rücksetztag
   werden in `checkMaxBookingsWeek()` zu Sonntag. Wochentage erscheinen englisch („Monday“).
3. **Kategorien beim Zählen:** `filterBookingsForTermsAndUser()` prüft die Kategorien an allen Buchungen im Zeitraum,
   gibt aber alle Buchungen des Nutzers zurück. Bei Regeln für eine Kategorie zählen so auch Buchungen anderer
   Kategorien, sobald irgendwer im Zeitraum die Kategorie gebucht hat.
4. Die Kettenbuchungs-Meldung nennt keinen Grund (nur „Buchungslimit erreicht“).

## Geprüft, nicht umgesetzt: Index für die Buchungsprüfung (optional)

Frage: Den Booster-Index optional (abschaltbar, mit Hinweis im Backend) für die Suche nach Konfliktbuchungen nutzen?

**Wie zuverlässig ist der Index?**

- Aktualisierung synchron im selben Request: `added/updated/deleted_post_meta`, `transition_post_status`,
  `save_post` (Priorität `PHP_INT_MAX`), `deleted_post`; `meta_input` ist vor `save_post` geschrieben. Lesen schreibt
  vorher offene Änderungen (read-your-writes). Frontend-Buchungen desselben Artikels laufen nacheinander (Sperre), die
  neue Buchung steht vor dem Freigeben der Sperre im Index.
- Ist der Index nicht fertig (Neuaufbau), fällt jede Abfrage auf die Originalabfragen zurück.
- Vollständiger Abgleich auf der großen Instanz (`wp commonsbooking index verify`): **101.079 Beiträge, 0 Abweichungen,
  0 verwaiste Zeilen**.

**Wo er abweichen kann** (jeweils eine mögliche Doppelbuchung, wenn die Prüfung ihn nutzt):

1. Fehler beim Projizieren werden geloggt und verschluckt („Never break the actual request“); die Buchung fehlt bis
   zur Reparatur.
2. Schreiben an WordPress vorbei (andere Plugins, SQL, phpMyAdmin, Importe): neue Posts erkennt der Wächter erst nach
   120 s Karenz, Status-/Datumsänderungen stündlich, **reine Meta-Änderungen nur über die tägliche Stichprobe von
   200 Posts** (bei 100.000 Buchungen statistisch erst nach Jahren vollständig).
3. Phasen, in denen der Booster-Code nicht lief (Downgrade), erkennt der Wächter über die Versionsoption; bis dahin
   (erster Request danach) ist der Index alt.

In einem normalen System ohne Fremdeingriffe liefert der Index korrekte Werte. Garantieren lässt sich das nicht.
Die Vorgabe „Prüfung nie aus dem Index“ schließt genau diese Restfälle aus.

**Abwägung:** Nach 1–4 dauert die Prüfung auf den Originaldaten 0,05–0,16 s; der Index brächte noch ~0,1 s je Request.
Das rechtfertigt das Restrisiko und eine zusätzliche Option nicht. Empfehlung: nicht umsetzen, solange die
Originalabfrage in diesem Rahmen bleibt (neu bewerten ab etwa 500.000 Buchungen). Falls doch: Index nur als
Vorfilter, Kandidaten und alle seit der letzten Wächterprüfung geänderten Posts auf Postmeta nachprüfen, bei einem
Projektionsfehler bis zur Reparatur automatisch auf die Originalabfrage zurückfallen, standardmäßig aus.

## Offen

- Zeitrahmen-Suchen je Request zusammenfassen (~0,3–0,5 s, betrifft auch kleine Instanzen kaum).
- Auffälligkeiten 1–4 der Buchungsregeln beheben (ändert Verhalten, eigener Branch oder Upstream).
- E-Mail beim Bestätigen läuft synchron (in der Testinstanz abgeschaltet), beim Hoster messen.
