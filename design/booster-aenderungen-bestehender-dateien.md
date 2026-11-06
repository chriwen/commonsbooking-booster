# Booster: Änderungen an bestehenden Dateien

Stand 2026-11-06. Vergleich `master` (wielebenwir, `7aa75a81`, entspricht 2.11.3) mit
`chriwen/commonsbooking-booster` Branch `booster/index-availability` (Arbeitsstand
nach `8ade45b8`, einschließlich der Umstellung von `Item::getClosestBooking()`).

Nur **geänderte** Dateien (37, davon 3 Sprachdateien). Neue Dateien – vor allem `src/Service/Index/`, `src/Service/Availability/`,
`src/Service/Booster/`, `src/Repository/Index.php`, neue Tests und `design/` – sind hier nicht beschrieben, sondern in
`technische-dokumentation-booster.md`.

---

## 1. Überblick

| Bereich | Dateien | Was sich ändert | Wirkt auch bei ausgeschaltetem Booster? |
|---|---|---|---|
| Verdrahtung | `Plugin.php`, `includes/OptionsArray.php`, `commonsbooking.php` | Booster starten/stoppen, Einstellung, Version | Schalter und Einstellung ja, sonst nein |
| Datenzugriff | `Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | Abfragen lesen aus dem Index statt aus `postmeta` | nein (Originalabfragen), **außer** `Booking::getForUser()` |
| Artikelmodell | `Model/Item.php` | `getClosestBooking()` (GBFS `vehicle_id`) lädt nur noch die Kandidaten mit dem spätesten Start | nein (ohne Index unverändert) |
| Kalendermodell | `Model/Calendar.php`, `Week.php`, `Day.php` | Einschränkungen einmal je Kalender laden, Beiträge erst bei Bedarf, API-Slots aus der Vorberechnung | ja (Laden), Slots nur mit Booster |
| Anzeige | `View/Calendar.php`, `Map/MapItemAvailable.php` | Tageslogik in gemeinsamen Kern ausgelagert, lesen aus der Vorberechnung | Refactoring ja, Vorberechnung nein |
| Karte | `Model/Map.php` | Zeitrahmen, Terms, Bilder für alle Standorte gebündelt laden | ja |
| Buchen | `Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `Model/Booking.php`, `Service/BookingRuleApplied.php`, `includes/Public.php` | Prüfung immer ohne Booster, Artikelsperre, Doppelklick-Schutz | **ja** (Sperre, Doppelklick, Regelprüfung) |
| Cache | `Service/Cache.php` | Rollen im Cache-Schlüssel, `withoutCache()` | **ja** |
| Kleinere Fixes | `templates/location-calendar-header.php`, `_lists.scss` | Abholhinweise ohne Leerraum | ja |
| Tests, CI, Doku | `tests/…` (u. a. `Model/ItemTest.php`), `.github/workflows/phpunit.yml`, `phpstan*.neon`, `TECHNICAL.md` | Booster in Tests an, drei zusätzliche CI-Läufe | – |
| Übersetzungen | `languages/*` | neue Texte (Einstellungsseite, Sperrmeldung) | – |

**Leitlinie des Umbaus:** Jede umgestellte Abfrage prüft zuerst `Index::isAvailable()` bzw.
`Availability::isEnabled()`. Ist der Booster aus, nicht fertig aufgebaut oder per `Booster::withoutBooster()`
umgangen, läuft der unveränderte Originalcode. `wp_posts`/`wp_postmeta` werden nie anders geschrieben als vorher.

---

## 2. Verdrahtung

**`src/Plugin.php`**
- `activation()` → `Booster::onPluginActivation()` (Index nach einer Pause neu aufbauen), `deactivation()` →
  `Booster::onPluginDeactivation()` (Cron-Jobs entfernen).
- `init()` → `Booster::init()` registriert Hooks, Cron und WP-CLI, **nur wenn eingeschaltet**.
- `handleBookingForms()` führt `Booking::handleFormRequest()` in `Booster::withoutBooster()` aus (siehe 6).

**`includes/OptionsArray.php`** – neue Gruppe „Performance booster“ in *Erweiterte Optionen*: Checkbox
`Booster::SETTINGS_FIELD`, Statusbereich über `SettingsView::render` (Diagnose, Knöpfe „Prüfen“/„Neu aufbauen“).
Standard: aus.

**`commonsbooking.php`** – Version `2.11.4-beta.2`. Die Version ist Teil jedes Cache-Schlüssels, alte Cache-Einträge
verfallen damit.

---

## 3. Datenzugriff (Repositories)

Gemeinsames Muster: Die öffentliche Methode bleibt gleich, intern entscheidet eine neue private Methode zwischen
Index (`Index::findPostIds()` + `Index::loadPosts()`) und Originalabfrage. PHP-Filter danach (Wochentage,
`isBookable`, Rollen, Artikel/Standort-Filter) und Cache bleiben unverändert.

| Datei | Methoden | Änderung |
|---|---|---|
| `Repository/Timeframe.php` | `get()`, `getInRange()` (und damit `getBookable*`, `getInRangeForCurrentUser`) | neue private `queryPosts()`: Datums- und Zeitraumfilter direkt in SQL über den Index statt `getPostIdsByType()` (Full Scan auf `postmeta`) + `getPostsByBaseParams()` |
| | neu `getByAuthor()` | Zeitrahmen/Buchungen eines Autors (für `getForUser`), mit Index oder per SQL auf `post_author` |
| `Repository/Booking.php` | `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()`, `getByDate()` | neue private `findViaIndex()`; unterstützt `$customArgs` `author`, `post_status`, `orderby=date`, `order`, bei allem anderen `null` → `WP_Query` wie bisher. Sortierung wie `WP_Query` (`post_date DESC`) |
| | neu `getLatestPastConfirmed( $itemId, $locationId, $now )` | Kandidaten für `Item::getClosestBooking()`: bestätigte Buchungen des Paares, die vor `$now` geendet haben, mit dem spätesten Start (zwei Indexabfragen). Ohne Index `null` → Aufrufer nutzt den Originalweg. Einzelheiten unten in 4a |
| | `getForUser()` | **auch ohne Booster:** lädt nicht mehr alle Buchungen, sondern Kandidaten (`getVisibleCandidates()`): eigene + Buchungen verwalteter Artikel/Standorte (`getManagedPostIds()`: Autor oder `_cb_*_admins`). CB-Admins weiter alles. `commonsbooking_isUserAllowedToSee()` filtert weiterhin, Ergebnis identisch |
| `Repository/Restriction.php` | `get()` | `queryPostsFromIndex()` mit `restriction_state = active`, sonst `queryPosts()` wie bisher |
| `Repository/BookablePost.php` | `getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `getTimeframesByRelation()`: Kandidaten aus dem Index, danach Filter auf den **einzelnen** `*-id`-Wert (alte Semantik). Verwandte Beiträge werden gebündelt geladen (`_prime_post_caches`), auch ohne Index |

Nicht umgestellt: Admin-Listen, `Booking::getForUserPaginated()`, alle Schreibwege.

---

## 4. Kalendermodell

**`Model/Calendar.php`**
- Zeitrahmen und **Einschränkungen** werden in `loadPosts()` geladen, erst wenn `getWeeks()` nicht aus dem Cache
  kommt (vorher im Konstruktor, auch wenn der Cache traf). Einschränkungen einmal für den ganzen Zeitraum
  (`Restriction::get()` mit `minTimestamp`), statt einmal pro Tag.
- `getAvailabilitySlots()` (REST `/availability`, GBFS) fragt zuerst `SlotReader` (Vorberechnung) und fällt auf das
  neue öffentliche `getAvailabilitySlotsLive()` zurück. Dessen Logik ist unverändert, nur die Slot-Erzeugung liegt in
  `Service\Availability\Slots` (gemeinsam mit der Vorberechnung).

**`Model/Week.php`, `Model/Day.php`** – neuer optionaler letzter Konstruktorparameter `$possibleRestrictions`.
`Day::getRestrictions()` filtert dann die vorgeladenen Einschränkungen in PHP (`isInRestriction()`, gleiche
Datumsbedingung wie die SQL-Abfrage), sonst fragt der Tag wie bisher selbst. Neue Konstante
`Day::RESTRICTION_STATUSES`.

**`Model/Item.php`** (4a) – `getClosestBooking()` bestimmt die zuletzt beendete bestätigte Buchung des Artikels an
seinem aktuellen Standort; daraus entsteht die verschleierte GBFS-`vehicle_id` (`getCloakedId()`), die nach jeder
Ausleihe wechselt. Bisher lud die Methode **alle** bestätigten Buchungen des Paares als Modelle und filterte in PHP
(`isPast()`), auf dev rund 1.300 Buchungen bzw. 10 MB und 95 ms je Artikel; `vehicle_availability.json` brach mit
128 MB Speicherlimit mit HTTP 500 ab. Neu:

```php
$allBookings = Repository\Booking::getLatestPastConfirmed( $this->ID, $location->ID, current_time( 'timestamp' ) );
if ( $allBookings === null ) {          // kein Index: Originalweg wie in 2.11.3
    $allBookings = Repository\Booking::get( [ $location->ID ], [ $this->ID ], null, true, null, [ 'confirmed' ] );
    $allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );
}
// danach unverändert: usort nach Starttag, bei Gleichstand nach Startzeit; array_pop()
```

Der Index liefert nur die Buchungen mit dem spätesten Start (meist eine). Die unveränderte Sortierung wählt unter
ihnen genau wie vorher unter allen, deshalb bleibt die `vehicle_id` identisch: auf dev für alle 126 Artikel, auf der
Testumgebung für alle 41 Artikel geprüft. Ergebnis: `vehicle_availability.json` 0,65 s statt HTTP 500. Ohne Booster
ändert sich nichts (auch nicht die Speicherlast). Test: `ItemTest::testGetClosestBooking_indexMatchesLegacy`.
Ausführlich: `technische-dokumentation-booster.md`, Abschnitt 5.5.

---

## 5. Anzeige: Karte und Tabelle

**`View/Calendar.php`** (größte Änderung, rund 450 geänderte Zeilen): Die Tageslogik, die vorher doppelt existierte
(`processSlot()`/`getLockedDayArray()` hier, `markDaysInTimeframe()` in der Karte), liegt jetzt in
`Service\Availability\DayFacts`, `CalendarWindow` und `DayStatus`. Ausgabe per Snapshot-Test gegen 2.11.3 abgesichert.
- entfallen: `processSlot()`, `getLockedDayArray()` (protected), `getDefaultCalendarEnddateTimestamp()` (private);
- `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )` – **geänderte Signatur** (protected);
- neu `renderJsonResponse()`; `getCalendarDataArray()` und `prepareJsonResponse()` behalten ihre Signatur;
- `renderTable()` lädt bei aktivem Booster alle Paare über einen `Reader` vor; `renderItemLocationRow()` bekommt
  optional `?Reader $reader` und liest den Tagesstatus darüber (sonst live). Markup je Status über
  `TABLE_STATUS_MARKUP`;
- **Fix Cache-Schlüssel:** Zeile jetzt `item_location_today_days` (vorher ohne Trennzeichen und ohne Tagesanzahl, also
  Kollisionen möglich); das innere, nie wiedergefundene Cache-Item in `renderItemLocationRow()` entfällt;
- **Fix:** Tage nach dem Statusbereich bekommen `<span class='is-locked'>` statt des kaputten `<span'is-locked'>`.

**`Map/MapItemAvailable.php`** – `create_items_availabilities()` lädt bei aktivem Booster alle Paare vor
(`Reader::preload`), sonst `Reader::evaluateLive()` (= `getCalendarDataArray()` wie bisher). `markDaysInTimeframe()`
(protected) ersetzt durch `markDays()` mit Tabelle `STATUS_MAPPING` (`DayStatus` → Kartenstatus). Berechnet nur noch
die angezeigten Tage.

**`Model/Map.php`** – `get_locations()` lädt Artikel je Standort, buchbare Zeitrahmen **aller** Paare (neu
`getBookableTimeframesByPair()`, eine Abfrage statt einer pro Paar), Terms und Vorschaubilder gebündelt. Gilt auch
ohne Booster.

---

## 6. Buchungsablauf

| Datei | Änderung | Grund |
|---|---|---|
| `Plugin::handleBookingForms()`, `CustomPostType/Booking.php` (Admin-Speichern), `CustomPostType/Timeframe::validateTimeFrame()` | Prüfung in `Booster::withoutBooster()` | Buchungs- und Zeitrahmenprüfung liest nie Index, Vorberechnung oder Cache. Ein veralteter Index kann nur die Anzeige betreffen, nie eine Doppelbuchung erzeugen |
| `CustomPostType/Booking.php` | neu `withItemLock()`: `GET_LOCK('cb_booking_{blog}_{item}', 20)` um `handleBookingRequest()` | **auch ohne Booster:** gleichzeitige Anfragen für denselben Artikel werden nacheinander geprüft und gespeichert. Nach 20 s Wartezeit `BookingDeniedException` („Other booking requests … being processed“). Ohne Lock-Unterstützung der DB läuft es wie bisher |
| `includes/Public.php` | Inline-Skript: Buchungsformulare nur einmal absenden, Button zeigt „Bitte warten …“, bei Zurück-Navigation wieder frei | **auch ohne Booster:** Doppelklick erzeugte zwei Anfragen |
| `Model/Booking.php` | `cancel()`: nach dem direkten SQL-Update `clean_post_cache()` + `Indexer::touch()`/`flush()` | das SQL umgeht die Post-Hooks; ohne Index-Tabellen sind die Indexer-Aufrufe wirkungslos (`flush()` prüft `Schema::isInstalled()`), `clean_post_cache` behebt zusätzlich einen veralteten Objekt-Cache |
| `Service/BookingRuleApplied.php` | „nur eine unbestätigte Buchung“: lädt nur eigene unbestätigte Buchungen (`getForUserPaginated`) statt aller sichtbaren und filtert nicht mehr nachträglich | **auch ohne Booster**, Ergebnis gleich |

---

## 7. Cache (`Service/Cache.php`)

- **Rollenkontext im Cache-Schlüssel** (`getRoleContext()`: Gast bzw. sortierte Rollen + Admin-Kennzeichen).
  Zeitrahmen können auf Rollen beschränkt sein; vorher konnte ein Gast die zwischengespeicherte Ansicht eines Admins
  bekommen. **Auch ohne Booster.** Folge: etwas mehr Cache-Einträge.
- neu `withoutCache( callable )`: liest und schreibt währenddessen keinen Cache (genutzt beim Berechnen der
  Vorberechnung und in `withoutBooster()`).

---

## 8. Kleinere Korrekturen

- `templates/location-calendar-header.php` + `assets/public/sass/partials/_lists.scss`: Abholhinweise in eigenem
  `<span class="cb-location-pickupinstructions-text">`, `white-space: pre-wrap` nur dort. Vorher erzeugte der
  Leerraum des Template-Markups zusätzliche Leerzeilen.

---

## 9. Tests, CI, Werkzeuge

- `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php`: Booster standardmäßig **an**, Index wird vor dem Lauf
  aufgebaut. Umgebungsvariablen `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`.
- `tests/php/BaseTestCase.php`: setzt statische Zustände von `Store`, `Calculator`, `Invalidator` zurück.
- `tests/php/Model/CalendarTest.php`: erwartete `Week`-Objekte mit dem neuen Parameter.
- Neue Testfälle in `Repository/BookingTest.php` (`getForUser`), `View/CalendarTest.php` (is-locked),
  `Model/ItemTest.php` (`getClosestBooking` Index gegen Originalweg),
  `CustomPostType/BookingTest.php` (Regel „eine unbestätigte Buchung“ mit verwaltetem Artikel).
- `.github/workflows/phpunit.yml`: drei zusätzliche Läufe (PHP 8.1, WP latest) mit Booster aus, ohne Index, ohne
  Vorberechnung.
- `phpstan.neon`: `WP_CLI` ignoriert (keine Stubs); `phpstan-baseline.neon`: ein Eintrag für `View/Calendar.php`
  entfällt (Code entfernt).
- `TECHNICAL.md`: neue Abschnitte „Performance booster“, „Timeframe index“, „Precomputed availability“.
- `languages/*`: Texte der Einstellungsseite, „Please wait …“, Meldung der Artikelsperre (de_DE übersetzt).

---

## 10. Hinweise für Entwickler und Reviewer

**Verhaltensänderungen, die unabhängig vom Schalter gelten:**
Artikelsperre beim Buchen, Doppelklick-Schutz, Rollen im Cache-Schlüssel, `getForUser()` ohne Vollscan, Regelprüfung
nur eigener Buchungen, gebündeltes Laden der Karte, Einschränkungen einmal je Kalender, Tabellen-Cache-Schlüssel,
`is-locked`-Markup (Tage hinter der Vorausbuchungsgrenze jetzt grau), Abholhinweise.

**Geänderte Schnittstellen (für Unterklassen oder Code Dritter):**

| Element | Änderung |
|---|---|
| `View\Calendar::mapDay()` (protected) | neue Signatur |
| `View\Calendar::processSlot()`, `getLockedDayArray()` (protected) | entfernt |
| `View\Calendar::renderItemLocationRow()` (protected) | zusätzlicher optionaler Parameter |
| `Map\MapItemAvailable::markDaysInTimeframe()` (protected) | entfernt, ersetzt durch `markDays()` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | zusätzlicher optionaler Parameter |
| `Model\Calendar` | `$timeframes` jetzt `?array`, Laden in `loadPosts()` |
| neu öffentlich | `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`, `Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Cache::withoutCache()`, `Day::RESTRICTION_STATUSES` |

**Wer Beiträge per direktem SQL ändert** (wie `Model\Booking::cancel()`), muss `Indexer::touch( $id )` und
`Indexer::flush()` aufrufen, sonst sieht der Index die Änderung erst über den Wächter bzw. die tägliche Prüfung.

**Downgrade:** Booster vorher ausschalten. Die bestehenden Tabellen werden nicht verändert; die zusätzlichen Tabellen
bleiben liegen, bis `wp commonsbooking booster purge` läuft.
