# Stufe 3: Vorberechnete Verfügbarkeit (Entwurf)

Stand: 2026-11-06, lokaler Fork, nicht committet. Baut auf dem Index aus [timeframe-index.md](timeframe-index.md) auf.

## 1. Ziel und Ausgangslage

Seit Stufe 2 kosten die Abfragen fast nichts mehr. Was bleibt, ist die Berechnung der Tage selbst
(`Model\Calendar` → `Week` → `Day::getGrid()` → `View\Calendar::mapDay()/processSlot()`), und zwar für jedes Paar
aus Artikel und Standort bei jedem Aufruf.

Gemessen auf den 10-fachen Entwicklungsdaten (`dev-bench/pair-cost.php`, Cache aus):

| Zeitraum | Kosten pro Paar |
|---|---|
| 31 Tage | 6,8 ms |
| 90 Tage | 10,5 ms |

Das erklärt die verbliebenen Zeiten: Karte mit Verfügbarkeit 1,06 s (SQL nur 0,11 s), `[cb_items_table]` 0,56 s.
Die Zeit wächst linear mit der Zahl der Paare.

**Ziel:** Karte und Tabelle lesen den Tageszustand fertig aus einer Tabelle, statt ihn zu berechnen.
Erwartung: Karte mit Verfügbarkeit etwa 0,15 s, Tabelle etwa 0,1 s, unabhängig von der Zahl der Paare.

**Anforderung des Nutzers:** Nach jeder Buchung muss die Anzeige stimmen, ein täglicher Cron reicht nicht.

## 2. Kernidee: Korrektheit hängt nicht am Zeitpunkt der Neuberechnung

Jede gespeicherte Zeile trägt eine Generationsnummer. Jede Änderung, die ein Paar betrifft, erhöht die Generation
des Paares **synchron im selben Request** (ein einzelnes `UPDATE`, unter 1 ms). Eine Zeile mit veralteter Generation
wird nie ausgeliefert. Der Leser berechnet das Paar dann live (so wie heute) und schreibt das Ergebnis zurück.

Die Neuberechnung nach einer Buchung ist damit nur noch eine Optimierung, damit der nächste Aufruf schnell ist.
Wann sie läuft (gleich nach dem Speichern, nach Auslieferung der Antwort oder erst beim nächsten Lesen), ändert an der
Richtigkeit nichts.

## 3. Was gespeichert wird und was nicht

Der Tageszustand eines Paares hängt von zwei Arten von Eingaben ab:

| Art | Beispiele | Behandlung |
|---|---|---|
| **Beiträge** (unabhängig vom heutigen Datum) | Zeitrahmen, Buchungen (`publish`/`confirmed`), Feiertage, Reparaturen, aktive Einschränkungen | **vorberechnet** |
| **Heutiges Datum, aktuelle Uhrzeit** | Vorausbuchungstage (`getLatestPossibleBookingDateTimestamp`), Startversatz (`getFirstBookableDay`), „nächster buchbarer Zeitrahmen“ (`getClosestBookableTimeFrameForToday`), vergangene Tage gesperrt | **beim Lesen** angewendet |
| **Angemeldeter Nutzer** | Rollenbeschränkung an Zeitrahmen (`commonsbooking_isCurrentUserAllowedToBook`), Admins sehen alles | Paare mit Rollenbeschränkung **immer live** |

Geprüft: `Day::getGrid()`, `mapTimeFrames()`, `mapRestrictions()`, `getHigherPrioFrame()` und `isLocked()` hängen nur an
den Beiträgen, nicht an `time()`. Alle datumsabhängigen Teile stecken in `View\Calendar::getCalendarDataArray()` und
`mapDay()`. Der Tag selbst kann also gespeichert werden, das Fenster darum wird bei jedem Lesen neu bestimmt.

Ohne Rollenbeschränkung ist das Ergebnis für alle Nutzer gleich, auch für Admins: `commonsbooking_isCurrentUserAllowedToBook`
gibt bei leerer Rollenliste immer `true` zurück. Die Spalte `has_role_limit` im Index zeigt die betroffenen Paare.
In der Praxis sind das wenige.

### Gespeicherte Tagesfakten

Ergebnis von `processSlot()` über alle Slots eines Tages, ohne die Fensterregeln aus `mapDay()`.
Pro Tag eine Bitmaske mit 16 Bit:

| Bit | Feld | Herkunft in `mapDay`/`processSlot` |
|---|---|---|
| 0 | `has_slots` | `! $noSlots` |
| 1 | `slot_locked` | ein Slot mit `locked` (Feiertag, Reparatur, Buchung) |
| 2 | `all_locked` | `$allLocked` |
| 3 | `holiday` | `holiday` |
| 4 | `repair` | `repair` |
| 5 | `booked_day` | `bookedDay` |
| 6 | `partially_booked` | `partiallyBookedDay` |
| 7 | `first_slot_booked` | `firstSlotBooked` |
| 8 | `last_slot_booked` | `lastSlotBooked` |
| 9 | `full_day` | genau ein Slot, Zeitrahmen mit `full-day` |

Daraus lassen sich das Tages-Array aus `mapDay()` (ohne `slots`) sowie der Status für Karte (`markDaysInTimeframe`) und
Tabelle (`renderItemLocationRow`) vollständig herleiten. Für die Karte und die Tabelle genügt die Anzahl der Slots
(`count($day['slots'])` wird nur auf 0 geprüft).

Nicht gespeichert: die Slots mit ihren Zeitrahmen-Objekten. Die braucht nur der Buchungskalender auf der Artikelseite
(Litepicker, Zeitauswahl), der weiter live rechnet (ein Paar, mit Index schnell).

## 4. Datenmodell

Eine Zeile pro Paar, die Tage stehen gepackt in einer Spalte. Das hält die Tabelle klein (1.000 Paare = 1.000 Zeilen)
und macht Lesen und Schreiben zu je einer Anweisung.

```sql
CREATE TABLE {prefix}cb_availability (
  item_id        bigint(20) unsigned NOT NULL,
  location_id    bigint(20) unsigned NOT NULL,
  gen            int unsigned NOT NULL DEFAULT 1,  -- wird bei jeder Änderung erhöht
  computed_gen   int unsigned NOT NULL DEFAULT 0,  -- Generation, aus der `days` berechnet wurde
  days_from      date NULL,                        -- erster Tag in `days`
  days           varbinary(1024) NULL,             -- 2 Byte je Tag, bis 512 Tage
  has_role_limit tinyint(1) NOT NULL DEFAULT 0,
  computed_at    datetime NULL,
  calc_version   smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (location_id, item_id),
  KEY item (item_id)
);
```

Eine Zeile ist gültig, wenn `computed_gen = gen` und `calc_version = AVAILABILITY_VERSION` gilt und sie den
angefragten Tagesbereich abdeckt.

Welche Paare es gibt: alle Kombinationen aus Artikel und Standort, die einen buchbaren Zeitrahmen haben
(aus dem Index, Typ 2). Paare ohne buchbaren Zeitrahmen brauchen keine Zeile, die Karte zeigt dort `no-timeframe`.

## 5. Lesepfad

Neuer Dienst `Service\Availability\Reader` mit `getDays(array $pairs, string $from, string $to): array`.

1. Eine Abfrage lädt die Zeilen aller angefragten Paare.
2. Für die Paare werden die buchbaren Zeitrahmen in einem Schritt geladen (wie heute schon in
   `Map::getBookableTimeframesByPair`).
3. Pro Paar wird das Fenster bestimmt, mit derselben Logik wie heute in `getCalendarDataArray()`:
   nächster buchbarer Zeitrahmen, `advanceBookingDays`, `lastBookableDate`, `firstBookableDay`, Startdatum.
   Diese Logik wird dafür in eine eigene Methode ausgelagert und vom Live-Pfad mitbenutzt.
4. Gültige Zeilen: Tagesfakten entpacken, Fensterregeln aus `mapDay()` anwenden (gesperrt nach `lastBookableDate`,
   vor `firstBookableDay`, vor heute, nach `endDate`).
5. Ungültige, fehlende, zu kurze Zeilen und Paare mit Rollenbeschränkung: live berechnen wie bisher. Außer bei Paaren
   mit Rollenbeschränkung wird das Ergebnis mit der vor der Berechnung gelesenen Generation zurückgeschrieben
   (siehe 6.3).

Umgestellt werden:

- `MapItemAvailable::create_items_availabilities()`: berechnet nur noch die angezeigten Tage. Der Quick Win
  (60 statt 12 Tage) ist damit enthalten.
- `View\Calendar::renderTable()` / `renderItemLocationRow()`.
- Optional später: `getAvailabilitySlots()` für API und GBFS (braucht Slot-Zeiten, eigenes Format, siehe 10).

**Nie** über die Tabelle: die Prüfung beim Buchen (Überschneidungen, Regeln). Sie liest weiter die Beiträge.
Die Tabelle dient nur der Anzeige.

## 6. Schreibpfad

### 6.1 Auslöser

Jede Änderung, die der Index sieht, wird zur Invalidierung. Der Indexer kennt beim Projizieren die alte Zeile (vor dem
`REPLACE`) und die neue. Betroffen sind die Paare aus beiden Zeilen, damit auch das Verschieben eines Zeitrahmens auf
einen anderen Artikel das alte Paar erfasst.

| Änderung | betroffene Paare |
|---|---|
| Buchung angelegt, bestätigt, storniert, gelöscht, verschoben | das Paar der Buchung (alt und neu) |
| Buchbarer Zeitrahmen, Feiertag, Reparatur, Öffnungszeiten | einzelnes Paar oder bei Mehrfachauswahl alle Paare aus den Zuordnungszeilen |
| Einschränkung (Reparatur, Hinweis, aktiv/aufgehoben) | Artikel × Standort, Platzhalter 0 = alle Paare des Artikels bzw. des Standorts |
| Artikel oder Standort gelöscht oder unveröffentlicht | Zeilen des Artikels bzw. Standorts löschen |
| Neuer Artikel bei Feiertag „alle Artikel“ | über die Meta-Änderung des Feiertags (wie im Index getestet) |
| Neuaufbau, Verifier-Reparatur | alle Paare bzw. das reparierte Paar |
| Neue Version der Berechnungslogik | `AVAILABILITY_VERSION` erhöhen, alle Zeilen ungültig |

Änderungen, die den Vorausberechnungszeitraum nicht berühren (z. B. das Aufräumen alter Buchungen), lösen nichts aus:
Der Zeitraum der alten und der neuen Zeile wird mit `[heute − 1, heute + Horizont]` verglichen.

Umsetzung: `Projector::project()` liefert alte und neue Zeile. `Indexer::flush()` gibt beide an
`Service\Availability\Invalidator` weiter. Dieser führt ein `UPDATE … SET gen = gen + 1 WHERE …` aus. Bei Platzhaltern und
Mehrfachauswahl ist das ein `WHERE item_id IN (…)` bzw. `location_id IN (…)`.

### 6.2 Neuberechnung

- **Wenige Paare** (Standardgrenze 25, entspricht etwa 250 ms): nach dem Flush neu berechnen. Unter PHP-FPM nach
  `fastcgi_finish_request()`, sonst am Ende des Requests. Eine Buchung kostet so etwa 10 ms, die der Nutzer nicht merkt.
- **Viele Paare** (z. B. ein landesweiter Feiertag für alle Standorte): nur invalidieren. Ein Cron-Job berechnet in
  Stapeln nach, Leser berechnen fehlende Paare selbst (so schnell wie heute, also kein Rückschritt).

### 6.3 Gleichzeitige Änderungen

Ablauf beim Neuberechnen: Generation `G` lesen, berechnen, dann

```sql
UPDATE … SET days = ?, days_from = ?, computed_gen = G, computed_at = NOW()
WHERE location_id = ? AND item_id = ? AND gen = G
```

Hat eine parallele Buchung in der Zwischenzeit `gen` erhöht, trifft das `UPDATE` keine Zeile. Die Zeile bleibt
ungültig und wird von der parallelen Neuberechnung oder beim nächsten Lesen erneuert. Ohne Sperren gibt es damit kein
Fenster, in dem veraltete Daten gültig erscheinen.

### 6.4 Schreibvorgänge an den Hooks vorbei

Direkte SQL-Schreibzugriffe, Importe oder andere Plugins sieht weder der Index noch die neue Tabelle. Absicherung:

- `computed_at` älter als 24 h gilt als ungültig. Veraltete Daten bleiben so höchstens einen Tag sichtbar, und das nur
  bei Schreibzugriffen an den Hooks vorbei.
- Der tägliche Verifier prüft zusätzlich eine Stichprobe von Paaren gegen eine Live-Berechnung und repariert.

## 7. Horizont und Tageswechsel

- Vorausberechnet werden `heute − 1` bis `heute + Horizont`. Standardhorizont 92 Tage, per Filter änderbar.
  Das deckt die Kartenvorgabe (`availability_max_days_to_show`), die Tabelle (Standard 31 Tage) und den Monatswechsel ab.
- Der Tageswechsel verlangt keine Neuberechnung: Vergangene Tage und Vorausbuchungsfenster werden beim Lesen angewendet.
- Ein nächtlicher Cron verlängert die Zeilen um die neuen Tage am Ende. Fällt er aus, berechnen die Leser fehlende Tage
  selbst. Für die Korrektheit ist der Cron also nicht nötig.
- Anfragen über den Horizont hinaus (z. B. `[cb_items_table days=180]`) werden für den Rest live berechnet.

## 8. Ein Rechenkern für beide Wege

Größtes Risiko ist, dass vorberechnete und live berechnete Tage auseinanderlaufen. Deshalb:

1. `processSlot()`/`mapDay()` werden in zwei Teile zerlegt: `DayFacts::fromDay(Day)` (nur Beiträge) und
   `DayFacts::applyWindow(…)` (Datum und Fenster).
2. Der bisherige Live-Pfad (`prepareJsonResponse`) benutzt dieselben beiden Funktionen. So gibt es nur eine Implementierung.
3. Paritätstests vergleichen für alle Testfälle das Ergebnis von Karte, Tabelle und Kalender-JSON (ohne Slots)
   zwischen beiden Wegen, auch mit eingefrorener Uhr (`ClockMock`) über Mitternacht und bei Sommerzeitwechsel.

## 9. Umsetzung in Phasen

| Phase | Inhalt | Ergebnis |
|---|---|---|
| A | Rechenkern auslagern (`DayFacts`, Fensterlogik), `View\Calendar` nutzt ihn, keine Verhaltensänderung | bestehende Tests grün, neue Tests für den Kern |
| B | Tabelle, Writer, `Reader` mit Lesen und Zurückschreiben, Feature-Flag `commonsbooking_use_availability_cache` (zuerst aus) | Karte und Tabelle lesen aus der Tabelle, Paritätstests |
| C | Invalidierung aus dem Indexer (alte und neue Zeile), Generationsprüfung, Neuberechnung nach Auslieferung der Antwort | Tests: Buchung, Stornierung, Feiertag, Einschränkung, gleichzeitige Änderungen |
| D | Nacht-Cron für den Horizont, Stapel-Cron, WP-CLI (`wp commonsbooking availability status / rebuild / verify`), Verifier | Betrieb abgesichert |
| E | Messung (1× und 10× Daten, PHPBench), CI-Lauf mit ausgeschaltetem Flag, Doku in `TECHNICAL.md` | Flag standardmäßig an |

Die Phasen bauen aufeinander auf. Nach A ist nichts sichtbar anders, nach B ist der Gewinn messbar, ab C ist es
produktionsreif.

## 10. Nicht enthalten / später

- **API und GBFS (`getAvailabilitySlots`):** Brauchen Slot-Zeiten statt Tagesstatus. Möglich wäre eine zweite Spalte
  mit den Slot-Intervallen pro Tag. Erst angehen, wenn die Tagesfakten stabil laufen.
- **Paare mit Rollenbeschränkung:** zunächst live. Bei Bedarf eine Variante je Rollenkombination speichern.
- **Buchungskalender auf der Artikelseite:** weiter live (braucht Slots und ist nutzerabhängig).
- **Bestehende Transient-Caches** (`Plugin::getCacheItem`): unverändert. Für Karte und Tabelle werden sie durch die
  neue Tabelle weitgehend überflüssig. Der Fehler beim Cache-Schlüssel in `renderTable` sollte trotzdem behoben werden.

## 11. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Abweichungen zwischen vorberechnetem und live berechnetem Tag | ein Rechenkern (8), Paritätstests, Verifier mit Stichprobe |
| Vergessener Auslöser | alle Auslöser laufen über den Index, der schon in Phase 1–5 auf Vollständigkeit getestet ist, dazu die 24-h-Grenze |
| Große Invalidierungen (globaler Feiertag) | ab der Grenze nur invalidieren, Leser rechnen live (nicht langsamer als heute), Cron arbeitet nach |
| Gleichzeitige Buchungen | Generationsprüfung beim Schreiben (6.3) |
| Index nicht bereit (Neuaufbau läuft) | Vorberechnung abgeschaltet, Live-Pfad wie bisher |

## 12. Entscheidungen

1. Horizont: 92 Tage (vom Nutzer bestätigt).
2. Paare mit Rollenbeschränkung werden live berechnet.
3. API und GBFS kommen später.

## 13. Stand der Umsetzung (2026-11-06, nicht committet)

Alle Phasen A–E sind umgesetzt. Code in `src/Service/Availability/`, Tests in `tests/php/View/CalendarSnapshotTest.php`
und `tests/php/Service/Availability/AvailabilityTest.php`.

### Phase A: Rechenkern

- `DayFacts` (Tagesfakten aus dem Slot-Raster, 10 Bits), `CalendarWindow` (Fenster aus `getCalendarDataArray()`,
  Standardwerte aus `prepareJsonResponse()`, Fensterregeln aus `mapDay()`), `DayStatus` (gemeinsame Statuslogik von
  Karte und Tabelle, vorher doppelt implementiert).
- `View\Calendar::processSlot()`, `getLockedDayArray()` und `getDefaultCalendarEnddateTimestamp()` entfallen,
  `mapDay()` und `getCalendarDataArray()` nutzen den Kern. `prepareJsonResponse()` behält seine Signatur.
- Eigenheiten bewusst übernommen und dokumentiert: `repair` wird von jedem Slot zurückgesetzt (strikter Vergleich
  String gegen Integer), 0 Vorausbuchungstage gelten als „nicht gesetzt“, `<span'is-locked'>` in der Tabelle.
- Snapshot-Test: Kalender-JSON (drei Zeiträume), Kartenstatus und Tabellenzeilen für 11 Szenarien und 4 Nutzer
  (Gast, Abonnent, Redakteur, Admin) wurden **vor** dem Umbau aufgezeichnet (`tests/php/View/snapshots/calendar-core.json`,
  1,3 MB). Mit und ohne Index identisch, nach dem Umbau unverändert.
- Ein zweiter Test zeigt für über 1.000 Tage, dass die gepackten Bits dasselbe Tages-Array (ohne Slots) und denselben
  Status ergeben wie die Auswertung des Rasters. Alle sieben Status kommen in den Szenarien vor.

### Phase B: Tabelle und Lesen

- `Schema` (`{prefix}cb_availability`, eigene Versionsoption, bei Strukturänderung wird die Tabelle neu angelegt,
  sie ist nur ein Cache), `Store` (Zugriffe, Generationsprüfung), `Calculator` (Berechnung über `Model\Calendar`
  ohne Plugin-Cache), `Reader` (Fenster beim Lesen, Tage aus der Tabelle, sonst berechnen und speichern, sonst live).
- Die Tage werden als Hex über `UNHEX()` geschrieben: `wpdb` kann Bytes aus Abfragen entfernen, die es für ungültigen
  Text hält.
- Neu im Cache-Trait: `Plugin::withoutCache()`. Die Berechnung darf keine zwischengespeicherten Wochen aus der Zeit
  vor einer Änderung lesen.
- Karte (`MapItemAvailable`) und Tabelle (`renderItemLocationRow`) lesen über den `Reader`. Die Karte rechnet nur
  noch die angezeigten Tage (Quick Win), auch im Live-Pfad werden nur diese Tage ausgewertet.
- Abweichung vom Entwurf: die Generation gilt für das ganze Paar, nicht für einen Tagesbereich. Ein Paar neu zu
  berechnen kostet etwa 13 ms, das ist einfacher und genauso schnell.

### Phase C: Invalidierung

- `Projector` feuert `commonsbooking_index_rows_changed` mit alter und neuer Zeile, wenn sich die Projektion ändert
  (ohne Buchhaltungsspalten). Der Neuaufbau feuert nur `commonsbooking_index_rebuilt`, das alle Paare ungültig macht.
- `Invalidator` bestimmt die Paare: einzelne Zeile, Mehrfachauswahl über die Zuordnungszeilen, Platzhalter 0 bei
  Einschränkungen. Nur wirksame Status (Zeitrahmen `publish`, Buchungen `confirmed`, Einschränkungen
  `Day::RESTRICTION_STATUSES`), nur wenn der Zeitraum den Horizont (mit 2 Tagen Puffer) berührt.
  Einschränkungen ohne Start zählen nicht (sie wirken nie; vermeidet beim Anlegen eine Invalidierung aller Paare,
  solange die Meta-Daten noch fehlen).
- Der `Reader` ruft vor dem Lesen `Indexer::flush()` auf: Änderungen desselben Requests sind sofort sichtbar.
- Bis 25 Paare werden am Ende des Requests neu berechnet (`shutdown`, mit `fastcgi_finish_request()` nach der Antwort).

### Phase D: Betrieb

- `Maintenance`: stündlich neue Paare anlegen, Paare ohne buchbaren Zeitrahmen entfernen (einmal am Tag), geänderte
  und über 12 Stunden alte Zeilen neu berechnen (20 s Budget, danach neu eingeplant). Täglich eine Stichprobe von 50
  Paaren mit frischer Berechnung vergleichen und reparieren.
- WP-CLI: `wp commonsbooking availability status|rebuild|verify [--sample=<n>] [--repair]|drop`.
- Aktivierung legt die Tabelle an, Deaktivierung entfernt die Cron-Jobs.

### Phase E: Abschluss

- Tests: 20 neue Tests für den Schreibweg (Buchung, Bestätigen, Stornieren, Löschen, Umbuchen auf anderen Artikel,
  Feiertag für mehrere Paare, Platzhalter, außerhalb des Horizonts, gleichzeitige Änderung, Rollenbeschränkung,
  Alter, Rechenversion, Bereich über den Horizont hinaus, große Änderungen per Cron, Index-Neuaufbau,
  Paar-Abgleich, Prüfung findet an den Hooks vorbei geschriebene Änderungen). Suite grün mit Vorberechnung, ohne
  Vorberechnung (`CB_USE_AVAILABILITY=0`) und ohne Index (`CB_USE_INDEX=0`): je 512 Tests.
- CI: zusätzlicher Lauf mit `CB_USE_AVAILABILITY=0`.
- Doku: Abschnitt „Precomputed availability“ in `TECHNICAL.md`.
- Feature-Flag `commonsbooking_use_availability_cache` ist standardmäßig an.

### Messungen (Entwicklungsdaten mit 10-facher Historie, 100 Paare, Plugin-Cache aus)

| Ansicht | Original (1×-Daten) | Index (Stufe 2) | Vorberechnet (Stufe 3) |
|---|---|---|---|
| Karte mit Verfügbarkeit | 15,4 s | 1,14 s | **0,11 s** |
| `[cb_items_table]` | 7,05 s | 0,74 s | **0,08 s** |

- Erster Aufruf ohne gespeicherte Zeilen: Karte 1,36 s, Tabelle 1,29 s (alle Paare werden berechnet und gespeichert).
- Kompletter Aufbau für 100 Paare: 1,3 s (`wp commonsbooking availability rebuild`).
- Eine Buchung: Index und Markierung im Request, Neuberechnung des Paares 13 ms nach der Antwort.
  Ende-zu-Ende geprüft (`dev-bench/booking-cost.php`): Tag vorher frei, nach der Buchung sofort gebucht,
  nach dem Löschen sofort wieder frei.

PHPBench (Fixture des Projekts, gleiche Sitzung, Zeit pro Aufruf):

| Benchmark | Index, live gerechnet | Index + vorberechnet |
|---|---|---|
| Karte mit Verfügbarkeit | 1,153 s | 0,151 s (erste Iteration berechnet die Zeilen, daher ±99 %) |
| `[cb_items_table]` | 1,131 s | 0,085 s |
| Karte ohne Verfügbarkeit, `[cb_items]`, `[cb_locations]` | unverändert (65 ms, 112 ms, 105 ms) | unverändert |

Testumgebung (`testenv/`, 15 Paare, Browser, Zeit bis zur Antwort): Kartenabfrage 39 ms vorberechnet, 153 ms live,
264 ms ohne Index; Tabelle 55 / 130 / 270 ms. Ausgabe in allen drei Modi byte-gleich.

### Lokale Testumgebung

`testenv/` (git-ausgeschlossen): eigene wp-env-Instanz auf Port 8890 mit Beispieldaten (6 Stationen in Köln,
15 Artikel, rund 560 Buchungen, Feiertage, Reparatur, Rollenbeschränkung), Seiten für alle Shortcodes und einem
MU-Plugin zum Umschalten zwischen vorberechnet, live und ohne Index (`?cb-mode=`). Anleitung in `testenv/README.md`.

### Offene Punkte

- API und GBFS (`getAvailabilitySlots`) auf gespeicherte Slot-Intervalle umstellen.
- Buchungskalender der Artikelseite rechnet weiter live (ein Paar, braucht Slots).
- Bestehende Fehler, nicht Teil von Stufe 3: Cache-Schlüssel in `renderTable`, `<span'is-locked'>`,
  `Booking::getForUser` lädt alle Buchungen.


## Nachtrag 06.11.2026: Performance-Booster

Index und vorberechnete Verfügbarkeit sind jetzt ein gemeinsamer, standardmäßig ausgeschalteter Schalter. Die
Buchungsprüfung nutzt immer die Originalabfragen, ein Wächter erkennt Änderungen aus einer Version ohne Booster.
Siehe `design/booster.md`.
