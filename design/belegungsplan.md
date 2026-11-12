# Belegungsplan im Backend

Status: Stufen 1 bis 3 umgesetzt im Branch `feature/belegungsplan` (Basis `booster/index-availability`), nicht committet.
Keine neuen Tabellen, Optionen oder Postmeta, damit downgradebar. Funktioniert mit und ohne Booster.
English version: `belegungsplan.en.md`.

## Ziel

Admins und CB-Manager sehen für eine Station auf einen Blick, **wann welcher Artikel von wem gebucht ist** und
**warum ein Tag nicht buchbar ist** (Urlaub, Reparatur, Einschränkung, kein Zeitrahmen, außerhalb der Vorausbuchung),
mit direktem Sprung zur Bearbeitung.

## Entscheidungen des Nutzers (12.11.2026)

- **Nur Backend.** Eine Info-Schicht im Frontend-Kalender (Tooltip pro Tag) wurde verworfen.
- **CB-Manager dürfen alle Buchungsdaten an einer Station sehen**, die sie verwalten, auch für Artikel, die sie nicht
  verwalten.
- **Zeitraum frei wählbar, auch über den Vorausbuchungszeitraum hinaus**, ebenso in die Vergangenheit.

## Darstellung

Admin-Seite **Commons Booking → Belegungsplan** (`admin.php?page=cb-occupancy-plan`), außerdem Zeilenaktion
„Belegungsplan“ in der Stations- und der Artikelliste.

- **Zwei Ansichten** (Reiter): **Nach Station** (Zeilen = Artikel der Station) und **Nach Artikel** (Zeilen =
  Stationen des Artikels, `view=item`).
- **Auswahl:** Station bzw. Artikel, Kategorie der Zeilen (Artikelkategorie bzw. Stationskategorie; nur wenn
  Kategorien mit Einträgen existieren), „Stornierte Buchungen zeigen“; zweite Zeile: Startdatum, Zeitraum (1, 2, 4,
  8 Wochen, 3, 6, 12 Monate), Tagesbreite, Blättern ← Heute →. Standard: 4 Wochen ab heute − 3 Tage. Höchstens 366 Tage.
- **Tagesbreite:** „Automatisch“ richtet sich nach dem Zeitraum (bis 2 Wochen 96 px, 4 Wochen 64 px, 8 Wochen 44 px,
  3 Monate 28 px, länger 18 px), oder fest sehr schmal bis sehr breit. Breitere Tage, weil Namen bei 30 px nicht
  lesbar waren (Rückmeldung des Nutzers).
- **Zeitleiste:** Spalten = Tage (Monats- und Tageskopf fixiert, Wochentag ab 28 px, heute markiert, Wochenende
  hinterlegt, Wochen- und Monatswechsel als stärkere Linie), Zeilen = Artikel bzw. Stationen (Spalte fixiert, Link zur
  Bearbeitung bei Bearbeitungsrecht).
- **Beschriftung der Balken** nach Breite: voller Name ab 60 px, darunter bei Buchungen Initialen, bei Sperren kein
  Text. Alles Weitere zeigt die Kurzinfo.
- **Zeile „Alle Artikel der Station“** bzw. „Alle Stationen des Artikels“ (oben, nur wenn nötig): Sperren, die in *jeder* Artikelzeile gleich vorkommen
  (gleicher Post, gleiche Tage), werden dorthin verschoben, statt in jeder Zeile zu stehen. Erst ab zwei Zeilen.
- **Ebenen pro Zeile:**
  1. buchbarer Zeitrahmen als hellgrüner, halbtransparenter Hintergrund mit grüner Oberkante an den Tagen, an denen
     er gilt (Wochentage, Einzeltermine, Wiederholung); weiß = kein Zeitrahmen;
  2. Sperren als Balken: Feiertage/geschlossen (gelb), Blockiert/Reparatur (dunkelrot), Einschränkung Totalausfall
     (rot), Hinweis (gelb umrandet); nicht aktive Einschränkungen und nicht veröffentlichte Zeitrahmen blass;
  3. Buchungen als Balken mit dem Namen der buchenden Person; unbestätigt gestrichelt, storniert durchgestrichen.
  Überlappende Balken bekommen eigene Spuren (Sperren zuerst, dann Buchungen; jede Spur wird so weit wie möglich
  aufgefüllt).
- **Konflikte** (Stufe 2): Buchungen rot umrandet mit ⚠, Zähler „⚠ n“ an der Zeile. Konflikt heißt: am Abhol- oder
  Rückgabetag kein buchbarer Zeitrahmen oder Station geschlossen (Feiertag), oder Überschneidung mit einer Sperre, die
  nicht überbuchbar ist (Blockiert/Reparatur, aktiver Totalausfall). Abhol- und Rückgabetag werden nur geprüft, wenn
  sie im Zeitraum liegen. Stornierte Buchungen haben keine Konflikte. Es ist eine Anzeige der heutigen Regeln, keine
  Prüfung, ob die Buchung damals gültig war.
- **Ende der Vorausbuchung:** Tage nach dem spätesten Vorausbuchungsende der buchbaren Zeitrahmen schraffiert. Endet der
  Zeitrahmen vorher, gibt es keine Schraffur.
- Balken, die über den Zeitraum hinausgehen, haben an der Seite keine Rundung.

## Interaktion

- **Klick auf einen Balken** öffnet ein Seitenpanel: Art, Titel, Konflikte, Details, Button „Bearbeiten“ (nur mit
  Bearbeitungsrecht). Esc oder × schließt.
- **Klick auf einen Tag** (Stufe 2, „Warum ist dieser Tag so?“): Zelle = dieser Tag für diese Zeile, Tageskopf = alle
  Zeilen. Das Panel zeigt das Ergebnis, wie es der Buchungskalender sieht (buchbar, teilweise gebucht, ganztägig
  gebucht/gesperrt, alle Zeitfenster gesperrt, Feiertag/geschlossen, kein Zeitrahmen), Hinweise auf Vergangenheit und
  Vorausbuchung, und darunter alle Gründe (Zeitrahmen, Einschränkungen, Buchungen) aufklappbar mit Details und
  Bearbeiten-Link. Geladen per AJAX.
- **Kurzinfo beim Überfahren** als eigenes Element (`position: fixed`, über allem) statt `title`: Das native `title`
  wurde vom Scrollbereich und der fixierten Spalte verdeckt (Rückmeldung des Nutzers). Auch per Tastaturfokus.
- Details Zeitrahmen: Zeitraum, Wiederholung, Wochentage bzw. Termine, Uhrzeit, max. Buchungsdauer, Vorausbuchung,
  Rollenbeschränkung, Status (wenn nicht veröffentlicht).
- Details Einschränkung: Zeitraum, Status, Hinweistext.
- Details Buchung: Zeitraum, Status, Name, E-Mail, Telefon (falls vorhanden), Buchungscode, Kommentar, gebucht am.
- Nur lesend, kein Verschieben von Buchungen.

## Rechte

- Seite: Recht `manage_commonsbooking` (Admins und CB-Manager).
- Stationen: `Repository\Location::getByCurrentUser()`, also alle für Admins, sonst die Stationen, die der Nutzer
  verwaltet oder angelegt hat. Eine andere Station per URL fällt auf die erste erlaubte zurück.
- Artikel (Ansicht pro Artikel): `Repository\Item::getByCurrentUser()` entsprechend. Gezeigt werden alle Stationen,
  an denen der Artikel einen Zeitrahmen hat, mit allen Buchungen (sinngemäß wie die Entscheidung oben).
- Für eine erlaubte Station werden **alle** Buchungen mit vollen Daten gezeigt (Entscheidung oben). Die
  Bearbeiten-Links prüfen weiterhin `commonsbooking_isCurrentUserAllowedToEdit()`, an den bestehenden Rechten ändert
  sich also nichts.
- Tageserklärung per AJAX (`wp_ajax_cb_occupancy_day`): Nonce, Recht `manage_commonsbooking` und erlaubte Station
  bzw. erlaubter Artikel werden bei jeder Anfrage geprüft.
- Kein Zwischenspeicher für die Seite (der Kalender-JSON wird nur nach Rollen geschlüsselt und darf keine Namen
  enthalten).

## Technik

- `Service\OccupancyPlan::build( $locationId, $from, $to, $withCanceled, $onlyRows )` und
  `buildForItem( $itemId, … )` liefern Ansicht, Tage, Zeilen (`row_id`, `item_id`, `location_id`, `conflicts`),
  Segmente (`kind`, `post_id`, `from`/`to` als Tagesindex, `clip_start`/`clip_end`, `lane`, `label`, `state`,
  `conflict`) und die Details je Post. `$onlyRows` ist der Kategoriefilter (IDs aus `get_objects_in_term()`).
- `explainDay( $view, $id, $date, $rowId )` baut den Plan für einen Tag und liefert je Zeile `dayResult()` (Ergebnis
  über `DayFacts::fromGrid()` des `Model\Day`, also dieselbe Auswertung wie der Kalender) und die Gründe. `View\Admin\OccupancyPlan` rendert daraus ein CSS-Grid (Subgrid je Zeile), das Panel ist ein kleines
  Inline-Skript ohne Abhängigkeiten.
- **Plan serverseitig gerendert** (Abweichung vom Konzept): Die Seite lädt per GET neu. Nur die Tageserklärung kommt
  per AJAX, weil sie pro Tag das Kalenderraster rechnet und das für alle Tage beim Seitenaufbau zu teuer wäre. Die Details aller sichtbaren Posts stehen als JSON in der Seite, nur für
  berechtigte Nutzer.
- **Datenquellen:**
  - Artikel der Station: buchbare Zeitrahmen der Station im Zeitraum (`Repository\Timeframe::getInRange`) plus Artikel
    mit Buchungen im Zeitraum.
  - Zeitrahmen pro Artikel: `getInRange( …, [ $location ], [ $item ], [ buchbar, Feiertage, Reparatur ] )` wie
    `Model\Calendar`, also auch Listen, Kategorien und „alle“.
  - Einschränkungen: `Repository\Restriction::get()` mit den Status aus `Day::RESTRICTION_STATUSES`.
  - Buchungen: `Repository\Booking::getByTimerange()`; nutzt bei eingeschaltetem Booster den Index.
- **Gleiche Regeln wie der Kalender:** Ob ein Zeitrahmen an einem Tag gilt, entscheiden
  `Repository\Timeframe::filterTimeframesForTimerange()` und `Model\Day::isInTimeframe()`, ausgeführt in
  `Model\Timeframe::withoutBookingWindow()`, damit auch Tage nach der Vorausbuchung erscheinen. Keine zweite
  Implementierung der Wiederholungsregeln.
- **Tests** (`tests/php/Service/OccupancyPlanTest.php`): Wochentage und Vorausbuchung, Buchungen mit Status und
  Zuschnitt, Stationszeile, Einschränkung ohne Ende, Spuren, Rechte für CB-Manager, Vergleich mit `DayFacts` aus dem
  Kalenderraster, gleiches Ergebnis mit und ohne Booster, Ansicht pro Artikel und Kategoriefilter, Konflikte (Feiertag
  am Abholtag, Sperre, kein Zeitrahmen, Zuschnitt), Tageserklärung (Gründe, Ergebnis, Hinweise).

## Ausbaustufen

1. **Umgesetzt:** Zeitleiste pro Station, Detailpanel, Rechte, Tests.
2. **Umgesetzt:** „Warum ist dieser Tag so?“ und Konfliktmarkierung.
3. **Umgesetzt:** Ansicht pro Artikel, Filter nach Kategorie; dazu überarbeitetes Layout (Tagesbreite, Kurzinfo).
4. Optional: Druck/Export, Verknüpfung mit `feature/dashboard`.

## Offene Fragen

- Standardzeitraum und Stationsauswahl „alle Stationen“ für Admins.
- Buchungen mit Stundenraster zeigen bisher nur den Tag; die Uhrzeit steht im Panel.
