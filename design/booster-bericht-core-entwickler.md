# Performance-Booster: Änderungen am bestehenden Code (Bericht für die Core-Entwickler)

Stand: 2026-11-12. Verglichen wurden `master` des Originalprojekts (`wielebenwir/commonsbooking`, Commit `7aa75a81`,
Merge von PR #2418, Basis 2.11.3) und der Branch `booster/index-availability` (Fork `chriwen/commonsbooking-booster`,
Commit `0312f3aa`, Version `2.11.4-beta.5`, 26 Commits). Englische Fassung:
[`booster-bericht-core-entwickler.en.md`](booster-bericht-core-entwickler.en.md).

Dieser Bericht richtet sich an Entwickler des Hauptprojekts. Er beantwortet: **Welche bestehenden Dateien, Klassen und
Methoden wurden geändert, wie war das Verhalten vorher, wie ist es jetzt, und wo ändert sich auch ohne eingeschalteten
Booster etwas?** Neue Klassen werden nur so weit beschrieben, wie sie in bestehenden Code eingreifen. Ihre vollständige
Beschreibung steht in [`technische-dokumentation-booster-02.md`](technische-dokumentation-booster-02.md).

Methode: Der Diff wurde Datei für Datei gelesen (`git diff 7aa75a81 HEAD`) und mit den Design-Dokumenten
(`booster.md`, `booster-aenderungen-bestehender-dateien.md`, `technische-dokumentation-booster-02.md`,
`zugeordnete-zeitrahmen.md`, `zeitrahmen-info.md`) abgeglichen. Wo der Code vom Dokument abweicht oder das Dokument
etwas nicht enthält, steht das in Abschnitt 19.6.

---

## Inhalt

1. [Kurzfassung](#1-kurzfassung)
2. [Grundprinzip und Entscheidungslogik](#2-grundprinzip-und-entscheidungslogik)
3. [Übersicht aller geänderten Laufzeitdateien](#3-übersicht-aller-geänderten-laufzeitdateien)
4. [Verdrahtung: Plugin, Optionen, Dashboard](#4-verdrahtung-plugin-optionen-dashboard)
5. [Datenzugriff: Repositories](#5-datenzugriff-repositories)
6. [Modelle: Calendar, Week, Day, Item, Booking, Map, Timeframe](#6-modelle-calendar-week-day-item-booking-map-timeframe)
7. [Anzeige: View\Calendar, Karte, Tabelle](#7-anzeige-viewcalendar-karte-tabelle)
8. [Buchungsablauf](#8-buchungsablauf)
9. [Cache](#9-cache)
10. [Admin-Zusatzfunktion „Zugeordnete Zeitrahmen“](#10-admin-zusatzfunktion-zugeordnete-zeitrahmen)
11. [Kleinere Änderungen: Template, SCSS, Frontend-Skript](#11-kleinere-änderungen-template-scss-frontend-skript)
12. [Was bewusst nicht geändert wurde](#12-was-bewusst-nicht-geändert-wurde)
13. [Wie neue Komponenten in bestehende Abläufe eingreifen (Hooks, Optionen, Cron)](#13-wie-neue-komponenten-in-bestehende-abläufe-eingreifen)
14. [Verhaltensänderungen unabhängig vom Schalter](#14-verhaltensänderungen-unabhängig-vom-schalter)
15. [Abweichungen vom Originalverhalten mit eingeschaltetem Booster](#15-abweichungen-vom-originalverhalten-mit-eingeschaltetem-booster)
16. [Geänderte Schnittstellen (Kompatibilität für Unterklassen und Dritte)](#16-geänderte-schnittstellen)
17. [Konsistenz und Sicherheit](#17-konsistenz-und-sicherheit)
18. [Tests, CI, Werkzeuge](#18-tests-ci-werkzeuge)
19. [Hinweise für das Review, Grenzen, Abgleich mit den Design-Dokumenten](#19-hinweise-für-das-review-grenzen-abgleich-mit-den-design-dokumenten)
20. [Regeln für künftigen Code](#20-regeln-für-künftigen-code)

---

## 1. Kurzfassung

**Zweck.** Auf großen Instanzen (Referenz: 167.000 Buchungen, 100 Stationen) brauchen Karte, `[cb_items_table]`,
`[cb_items]`, `[cb_locations]`, API und GBFS Sekunden bis Minuten oder brechen am Speicherlimit ab (Ursache:
Meta-Joins über `wp_postmeta` pro Artikel/Standort/Tag sowie das Berechnen jedes Tages mit allen Zeitrahmen).
Der Booster legt dieselben Daten zusätzlich in vier eigenen Tabellen ab und liest Anzeigen daraus.

**Vier Tabellen, nichts Bestehendes wird angefasst.**

| Tabelle | Inhalt | Geschrieben von |
|---|---|---|
| `{prefix}cb_index` | eine Zeile je Zeitrahmen/Buchung/Einschränkung (Typ, Artikel, Standort, Start/Ende als Zahl, Status, Autor, Raster, Regeln) | `Service\Index\Projector` |
| `{prefix}cb_index_rel` | Zuordnung bei Mehrfachauswahl (Feiertage, Reparaturen) | `Projector` |
| `{prefix}cb_availability` | je Artikel/Standort-Paar vorberechnete Tagesfakten (10 Bit je Tag) und freie Slots für 94 Tage | `Service\Availability\Store` |
| `{prefix}cb_availability_access` | dasselbe je Zugriffsprofil bei rollenbeschränkten Paaren | `AccessStore` |

`wp_posts` und `wp_postmeta` werden **nie anders geschrieben als vorher**. Der Booster ist ein reiner, jederzeit
neu aufbaubarer Lesecache mit Hooks als Pflegeweg.

**Ein Schalter, Standard aus.** Einstellungen → Erweitert → „Performance booster“ (`booster_enabled` in
`commonsbooking_options_advanced-options`), Filter `commonsbooking_booster_enabled`, WP-CLI `commonsbooking booster
enable|disable`. Ausgeschaltet laufen keine Hooks, keine Cron-Jobs und kein Lesen aus den Tabellen.

**Umfang der Änderung an bestehendem Code (ohne Übersetzungen, Doku, Tests):**

| | Anzahl |
|---|---|
| geänderte Laufzeitdateien (`src/`, `includes/`, `templates/`, `assets/`, `commonsbooking.php`) | 27 |
| davon mit Wirkung **auch bei ausgeschaltetem Booster** | 15 (Abschnitt 14) |
| neue Klassen | 29 (`src/Service/Index`, `src/Service/Availability`, `src/Service/Booster`, `src/Repository/Index.php`, `src/Repository/AssignedTimeframes.php`, `src/View/Admin/AssignedTimeframes.php`) |
| entfernte Methoden | 4 (`View\Calendar::processSlot`, `getLockedDayArray`, `getDefaultCalendarEnddateTimestamp`, `Map\MapItemAvailable::markDaysInTimeframe`) |
| Methoden mit geänderter Signatur | 3 (`View\Calendar::mapDay`, `Model\Day::__construct`, `Model\Week::__construct`) plus eine mit zusätzlichem optionalem Parameter (`renderItemLocationRow`) |
| Änderungen in Testbootstrap und CI | Booster standardmäßig an, drei zusätzliche CI-Läufe (Abschnitt 18) |

**Größte Eingriffe** (nach Umfang und Risiko): `View\Calendar` (Tageslogik in einen gemeinsamen Rechenkern
ausgelagert, Ausgabe per Snapshot gegen 2.11.3 abgesichert), `Repository\Booking` und `Repository\Timeframe`
(Indexweg neben dem Originalweg), `Model\Calendar`/`Week`/`Day` (Einschränkungen einmal je Kalender),
`CustomPostType\Booking` (Artikelsperre gegen Doppelbuchungen), `Service\Cache` (Rollen im Schlüssel).

---

## 2. Grundprinzip und Entscheidungslogik

### 2.1 Das einheitliche Muster

Jede umgestellte Methode behält ihre öffentliche Signatur und ihr Ergebnis. Intern gilt:

```php
if ( ! Index::isAvailable() ) {
    return /* unveränderter Code aus 2.11.3 */;
}
return /* Indexweg mit identischem Ergebnis */;
```

PHP-Filter nach dem Laden (`filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `isBookable`, Rollenprüfung,
Artikel-/Standortfilter in `Restriction::filterPosts()`) und der Plugin-Cache laufen in **beiden** Pfaden unverändert.
Der Index ersetzt nur das **Finden der Beitrags-IDs**; die Beiträge samt Metadaten werden danach gebündelt über
`_prime_post_caches()` geladen und von den bestehenden Modellen ausgewertet. Die Index-Zeile enthält bewusst keine
Interpretation (keine Tage, keine Buchbarkeit).

### 2.2 Entscheidungskette

```php
Booster::isEnabled()      // Einstellung „on“, überstimmbar per Filter commonsbooking_booster_enabled
Booster::canRead()        // isEnabled() && nicht innerhalb withoutBooster() && Zustand „active“
Backfill::isReady()       // canRead() && Filter commonsbooking_use_index && Schema installiert
                          //   && Option commonsbooking_index_ready === Projector::PROJECTOR_VERSION
Index::isAvailable()      // = Backfill::isReady()                     → Repositories
Availability::isEnabled() // Filter commonsbooking_use_availability_cache && Schema installiert
                          //   && Backfill::isReady()                  → Reader, SlotReader
```

| Situation | Index gelesen | Vorberechnung gelesen | Verhalten |
|---|---|---|---|
| Booster aus (Standard) | nein | nein | wie 2.11.3 |
| Booster an, Index im Aufbau | nein | nein | Hooks schreiben, gelesen wird über `postmeta` |
| Booster an, Index komplett | ja | ja | Indexweg und Vorberechnung |
| Filter `commonsbooking_use_availability_cache = false` | ja | nein | Index, Tage live |
| Filter `commonsbooking_use_index = false` | nein | nein | alles live |
| innerhalb `Booster::withoutBooster()` | nein | nein | Originalweg, ohne Plugin-Cache |
| Wächter hat Neuaufbau gestartet | nein bis fertig | nein | Originalweg |

### 2.3 Wichtigste Sicherheitsentscheidung

Buchungs- und Zeitrahmenprüfung laufen **immer** im Originalweg (`Booster::withoutBooster()`, Abschnitt 8). Ein
veralteter Index kann dadurch höchstens eine falsche Anzeige erzeugen, nie eine Doppelbuchung.

---

## 3. Übersicht aller geänderten Laufzeitdateien

„Ohne Booster wirksam“ bedeutet: Die Änderung gilt auch, wenn der Schalter aus ist.

| Datei | Änderung (Kurzform) | Ohne Booster wirksam |
|---|---|---|
| `commonsbooking.php` | Version `2.11.4-beta.5` | ja (Cache-Schlüssel enthält die Version) |
| `includes/OptionsArray.php` | neue Gruppe „Performance booster“ unter *Erweiterte Optionen* | ja (Einstellung sichtbar) |
| `includes/Public.php` | Inline-Skript: Buchungsformulare nur einmal absenden | ja |
| `src/Plugin.php` | `Booster::init()`, `onPluginActivation()`, `onPluginDeactivation()`, `handleBookingForms()` in `withoutBooster()` | Init/Hooks nein, sonst wirkungslos |
| `src/Repository/Timeframe.php` | neue `queryPosts()` (Index oder Original), neue `getByAuthor()` | nur `getByAuthor()` |
| `src/Repository/Booking.php` | `findViaIndex()`, `getLatestPastConfirmed()`, `getForUser()` mit Kandidaten | `getForUser()` ja |
| `src/Repository/Restriction.php` | `queryPostsFromIndex()` | nein |
| `src/Repository/BookablePost.php` | `getTimeframesByRelation()`, gebündeltes Laden verwandter Beiträge | Bündeln ja |
| `src/Model/Calendar.php` | spätes Laden, Einschränkungen einmal je Kalender, Slots aus Vorberechnung | Laden ja |
| `src/Model/Week.php`, `src/Model/Day.php` | neuer optionaler Parameter, `isInRestriction()`, `RESTRICTION_STATUSES` | ja |
| `src/Model/Item.php` | `getClosestBooking()` über `getLatestPastConfirmed()` | nein |
| `src/Model/Booking.php` | `cancel()`: `clean_post_cache()` und Index-Aufrufe | `clean_post_cache()` ja |
| `src/Model/Map.php` | `get_locations()` lädt alles gebündelt, neu `getBookableTimeframesByPair()` | ja |
| `src/Model/Timeframe.php` | neu `withoutBookingWindow()`: Vorausbuchungsfenster während der Vorberechnung ausblenden | nein |
| `src/View/Calendar.php` | Tageslogik ausgelagert, `Reader` für Tabelle, zwei Fehlerkorrekturen | Refactoring und Korrekturen ja |
| `src/Map/MapItemAvailable.php` | liest über `Reader`, `markDays()` statt `markDaysInTimeframe()` | Refactoring ja |
| `src/Service/BookingRuleApplied.php` | Regel „eine unbestätigte Buchung“ fragt nur eigene Buchungen | ja |
| `src/Service/Cache.php` | Rollenkontext im Schlüssel, `withoutCache()` | ja |
| `src/Wordpress/CustomPostType/Booking.php` | `withItemLock()`, Admin-Prüfung in `withoutBooster()` | Sperre ja |
| `src/Wordpress/CustomPostType/Timeframe.php` | `validateTimeFrame()` in `withoutBooster()`, Admin-Filter und Spalte für Mehrfachauswahl, Infobox | Filter, Spalte, Infobox ja |
| `src/Wordpress/CustomPostType/CustomPostType.php` | Zeilenaktion „Zeitrahmen“ für Artikel und Standorte | ja |
| `src/Wordpress/CustomPostType/Item.php`, `Location.php` | Metabox „Zugeordnete Zeitrahmen“ | ja |
| `templates/dashboard-index.php` | vierte Dashboard-Spalte (nur Admins) | ja |
| `templates/location-calendar-header.php` | Abholhinweise in eigenem `<span>` | ja |
| `assets/public/sass/partials/_lists.scss` | `white-space: pre-wrap` nur noch am Text der Abholhinweise | ja |

Nicht im Laufzeitumfang, aber geändert: `.distignore`, `.github/workflows/phpunit.yml`, `phpstan.neon`,
`phpstan-baseline.neon`, `TECHNICAL.md`, `languages/*`, Tests (Abschnitt 18).

---

## 4. Verdrahtung: Plugin, Optionen, Dashboard

### 4.1 `src/Plugin.php`

| Stelle | 2.11.3 | Booster |
|---|---|---|
| `Plugin::init()` | – | ruft `Service\Booster\Booster::init()` direkt nach `do_action( 'cmb2_init' )` |
| `Plugin::activation()` | – | `Booster::onPluginActivation()` vor `self::clearCache()`: setzt den Zustand auf „inaktiv“ und baut den Index neu auf, falls der Schalter an ist (der Index kann nach einer Pause veraltet sein) |
| `Plugin::deactivation()` | `do_action( Scheduler::UNSCHEDULER_HOOK )` | zusätzlich `Booster::onPluginDeactivation()` (entfernt Cron-Ereignisse, markiert Index als veraltet) |
| `Plugin::handleBookingForms()` | `Booking::handleFormRequest()` direkt | in `Booster::withoutBooster( [ Booking::class, 'handleFormRequest' ] )`; der `catch ( BookingDeniedException )` bleibt unverändert |

`Booster::init()` registriert nur `init` Priorität 0 (`Booster::onInit()`: Ein-/Ausschalten, Wächter) und bei
`is_admin()` die Einstellungsseite. Erst wenn der Schalter an ist, folgen `Indexer::init()`, `Availability::init()`,
`Guard::init()`, `Diagnostics::init()` (alle Hooks aus Abschnitt 13). WP-CLI-Befehle werden nur unter WP-CLI registriert.

### 4.2 `includes/OptionsArray.php`

Neue Gruppe `booster` (Titel „Performance booster“, ID `booster_group`) vor der Gruppe „Advanced caching settings“:

- Checkbox `Booster::SETTINGS_FIELD` (`booster_enabled`), Standard: nicht gesetzt (aus).
- Statusfeld `booster_status` mit `render_row_cb` → `SettingsView::render()` (Diagnose, Knöpfe „Prüfen“ /
  „Neu aufbauen“ / „Einrichtung jetzt abschließen“). Das Feld speichert nichts.

Der Anker der Gruppe in CMB2 ist `#booster-group-header`.

### 4.3 `commonsbooking.php`

`COMMONSBOOKING_VERSION` und Plugin-Header: `2.11.3` → `2.11.4-beta.5`. Die Versionsnummer ist Teil jedes
Cache-Schlüssels (`Cache::getCacheId()`) und wird vom Wächter zur Erkennung eines Versionswechsels genutzt
(`Upgrade::VERSION_OPTION`, bestehende Option `commonsbooking_plugin_version`). Der Branch muss deshalb eine **eigene**
Nummer tragen, andernfalls greift die Versionsprüfung des Wächters nicht (die beiden anderen Prüfungen fangen den Fall
trotzdem ab, siehe 17.3).

### 4.4 `templates/dashboard-index.php`

- Dem Container `.cb_welcome-panel-column-container` wird für Administratoren die Klasse `cb-dashboard-four-columns`
  angehängt (`commonsbooking_isCurrentUserAdmin()`).
- Nach der dritten Spalte: `SettingsView::renderDashboardColumn()` (nur Admins). Die Spalte zeigt Status
  (aus / wird eingerichtet / Hinweise / Problem / aktiv), zwei Kennzahlen „x von y“, „Zuletzt aktualisiert vor …“, einen
  Link in die Einstellungen und – solange der Booster noch nie an war – das Abzeichen „Neu“. Sie führt keine Prüfungen
  aus und liest nur Zählungen. Die Spaltenstile stehen im Markup der Spalte, das kompilierte Admin-CSS bleibt unverändert.

---

## 5. Datenzugriff: Repositories

### 5.1 `Repository\Timeframe`

| Methode | 2.11.3 | Booster |
|---|---|---|
| `get()` | `getPostIdsByType( $types, $items, $locations )`, danach `getPostsByBaseParams( $date, $minTimestamp, null, $postIds, $postStatus )` inline | `self::queryPosts( $types, $items, $locations, $date, $minTimestamp, null, $postStatus )` |
| `getInRange()` | wie oben mit `$maxTimestamp` | `self::queryPosts( …, null, $minTimestamp, $maxTimestamp, … )` |
| `queryPosts()` | – | **neu, privat.** Ohne Index: derselbe Zweiweg wie früher. Mit Index: `Index::findPostIds()` + `Index::loadPosts()` |
| `getByAuthor()` | – | **neu, öffentlich:** Zeitrahmen/Buchungen eines Autors (`post_author`), mit Index oder per SQL (`posts` ⨝ `postmeta type`). Nutzt `filterTimeframes()` und `castPostsToModels()` wie `get()`. Sortierung nach Post-ID |
| `getPostIdsByType()`, `getPostsByBaseParams()`, `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()` | zentrale SQL-Bausteine | **unverändert**, laufen nur noch im Originalweg |
| `filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `filterTimeframesByMaxBookingDays()`, `filterTimeframesForCurrentUser()` | PHP-Filter nach dem Laden | unverändert, in beiden Pfaden |

**Übersetzung der Datumsfilter** (`queryPosts()`; entspricht den drei Modi von `getPostsByBaseParams()`):

| Modus | altes SQL | Indexkriterien |
|---|---|---|
| ein Tag (`$date` ohne `$minTimestamp`) | `repetition-start BETWEEN 0 AND Tag 23:59` und (`repetition-end BETWEEN Tag 00:00 AND 3000000000` oder kein Ende) | `start_min = 0`, `start_max = strtotime( $date . 'T23:59' )`, `end_min = strtotime( $date )`, `end_max = 3000000000` |
| ab Zeitpunkt (`$minTimestamp`) | `repetition-end >= Tagesbeginn` oder kein Ende | `end_min = Helper::getLastFullDayTimestamp( $minTimestamp )` |
| Zeitraum (`$minTimestamp` und `$maxTimestamp`) | Überlappung | `start_max = $maxTimestamp`, `end_min = $minTimestamp` |

Gleiche Randfälle wie das alte SQL: `start_*` schließt NULL aus (das alte SQL setzte den Meta-Schlüssel voraus),
`end_*` lässt ein offenes Ende (`end_ts IS NULL`) zu.

Mit Artikel-/Standortfilter erzeugt `Index::findPostIds()` eine `UNION ALL` aus (a) Einzelzuordnung
(`is_multi = 0`, `item_id`/`location_id`) und (b) Mehrfachzuordnung (`is_multi = 1`, `EXISTS` auf `cb_index_rel`).
Das ersetzt die zwei `INNER JOIN`s von `getEntityQuery()` (Einzelfeld **oder** Liste, Artikel **und** Standort).

### 5.2 `Repository\Booking`

| Methode | 2.11.3 | Booster |
|---|---|---|
| `getEndingBookingsByDate()` | `WP_Query` mit Meta-Abfragen | zuerst `findViaIndex( [ require_end, end_min, end_max ], $customArgs, [ 'confirmed', 'unconfirmed' ] )` |
| `getBeginningBookingsByDate()` | `WP_Query` | zuerst `findViaIndex( [ start_min, start_max ], $customArgs, [ 'confirmed' ] )` |
| `getByDate()` | `WP_Query`, mehr als ein Treffer → `Exception` | zuerst `findViaIndex( [ start_min, start_max, end_exact, items, locations ] )`; die Ausnahme „Found more than one bookings“ bleibt erhalten |
| `getByTimerange()` (Basis von `getExistingBookings()`, `getByRestriction()`, Buchungsregeln) | `WP_Query` mit bis zu fünf Meta-Joins | zuerst `findViaIndex( [ start_min = PHP_INT_MIN, start_max = $endDate, require_end, end_min = $startDate, locations?, items? ] )` |
| `findViaIndex()` | – | **neu, privat.** Unterstützt aus `$customArgs` nur `author` (numerisch), `post_status`, `orderby = date`, `order`. **Jedes andere Argument → `null`**, dann läuft die `WP_Query` unverändert. Sortierung wie `WP_Query` über `Index::sortByDate()` (`post_date`, Gleichstand: ID aufsteigend) |
| `getLatestPastConfirmed( $itemId, $locationId, $now )` | – | **neu, öffentlich.** Siehe 6.4. Ohne Index `null` |
| `getForUser()` | `self::get( [], [], null, $asModel, $minTimestamp, $postStatus )` lädt **alle** Buchungen, danach `commonsbooking_isUserAllowedToSee()` | `getVisibleCandidates()`, danach derselbe Filter. **Auch ohne Booster** |
| `getVisibleCandidates()` | – | **neu, privat.** CB-Admin → alle wie bisher. Sonst: eigene Buchungen (`Timeframe::getByAuthor`) ∪ Buchungen verwalteter Artikel ∪ Buchungen verwalteter Standorte; Duplikate entfernt, nach ID sortiert |
| `getManagedPostIds()` | – | **neu, privat.** IDs der Artikel/Standorte mit `post_author = Nutzer` **oder** Nutzer in `_cb_item_admins` / `_cb_location_admins`. Die Admin-Meta ist teils serialisierte Liste, teils Einzelwert: SQL grenzt per `LIKE '%<id>%'` grob ein, PHP prüft exakt nach `maybe_unserialize()` (Nutzer 1 trifft nicht Nutzer 11/17) |
| `getForUserPaginated()` | – | unverändert |

**Warum `getForUser()` gleiches Ergebnis liefert.** Sichtbar ist eine Buchung für einen Nicht-Admin, wenn er Autor ist
oder Admin des Artikels **oder** Standorts (`Model\Timeframe::getAdmins()` vereinigt beide Quellen plus `post_author` von
Artikel und Standort). Die Kandidatenmenge ist eine Obermenge davon; der unveränderte
`commonsbooking_isUserAllowedToSee()`-Filter entscheidet. Die Reihenfolge bleibt nach Post-ID. Für CB-Admins ändert sich
nichts (weiterhin alle Buchungen, siehe 19.4).

### 5.3 `Repository\Restriction::get()`

```php
// 2.11.3
$posts = self::queryPosts( $date, $minTimestamp, $postStatus );
if ( $posts && count( $posts ) ) { $posts = Wordpress::flattenWpdbResult( $posts ); … }

// Booster
if ( Index::isAvailable() ) {
    $posts = self::queryPostsFromIndex( $date, $minTimestamp, $postStatus );
} else {
    $posts = self::queryPosts( $date, $minTimestamp, $postStatus );
    $posts = $posts ? Wordpress::flattenWpdbResult( $posts ) : [];
}
```

`queryPostsFromIndex()` (neu, privat) setzt `restriction_states = [ active ]` (wie das alte SQL, das nur aktive
Einschränkungen liest) und übersetzt `$minTimestamp` zu `end_min = $minTimestamp + 1` bzw. `$date` zu
`start_min 0`, `start_max = Tag 23:59`, `end_min = Tagesbeginn`, `end_max = Restriction::NO_END_TIMESTAMP`.
Die Artikel-/Standortzuordnung (`0` = alle) prüft weiterhin der bestehende PHP-Filter `filterPosts()`. Im Index steht
`0` für „alle“ in derselben Spalte, die Zuordnung wird dadurch nicht verändert.

### 5.4 `Repository\BookablePost::getRelatedPosts()`

(Grundlage von `Item::getByLocation()` und `Location::getByItem()`.)

| | 2.11.3 | Booster |
|---|---|---|
| Kandidaten | `WP_Query` auf `cb_timeframe` mit Meta `{typ}-id = $postId` | `getTimeframesByRelation( $postId, $originType )` (**neu, privat**) |
| ohne Index | – | dieselbe `WP_Query` (Argumente unverändert) |
| mit Index | – | Kandidaten über `Index::findPostIds( [ post_types, statuses, items|locations ] )`, danach **Filter auf den einzelnen `{typ}-id`-Wert** (die alte Semantik; der Index würde auch Listen finden), Sortierung `post_date DESC` |
| verwandte Beiträge | einzeln geladen | gebündelt mit `_prime_post_caches()` – **auch ohne Index** |

---

## 6. Modelle: Calendar, Week, Day, Item, Booking, Map, Timeframe

### 6.1 `Model\Calendar`

| Element | 2.11.3 | Booster |
|---|---|---|
| `$timeframes` | `protected array`, im Konstruktor befüllt (auch bei Cache-Treffer von `getWeeks()`) | `protected ?array = null`, befüllt in `loadPosts()` |
| `$restrictions` | – | **neu**, `protected array` |
| `__construct()` | lud `Timeframe::getInRange()` | lädt nichts |
| `loadPosts()` | – | **neu, protected.** Idempotent. Lädt Zeitrahmen wie früher (`getInRange( start, end, locations, items, types, true, [ confirmed, publish ] )`) und **Einschränkungen einmal für die Range**: `Restriction::get( $locations, $items, null, true, $startTimestamp - 1, Day::RESTRICTION_STATUSES )` |
| `getWeeks()` | Wochen ohne Einschränkungen | ruft `loadPosts()` erst, wenn nicht aus dem Cache beantwortet; übergibt `$this->restrictions` als letzten Parameter an `new Week( … )` |
| `getAvailabilitySlots()` | rechnete live | fragt zuerst `SlotReader::getAvailabilitySlots( start, end, locations, items, ignoreStartDayOffset, ignoreRestrictions )`; bei `null` → `getAvailabilitySlotsLive()` |
| `getAvailabilitySlotsLive()` | – | **neu, öffentlich.** Der bisherige Rechenweg; die Erzeugung der Slot-Objekte liegt in `Service\Availability\Slots` (`bookableSlots()`, `getEntityIds()`, `toApiSlot()`, `addUnique()`), gemeinsam mit der Vorberechnung |

Slot-Logik live, vorher/nachher: Die Typprüfung `get_post_meta( id, 'type' ) != BOOKABLE_ID` wanderte nach
`Slots::bookableSlots( $day->getGrid() )`, der Aufbau von `start`/`end` (UTC, `Y-m-d\TH:i:sP`), `locationId`, `itemId`
und die Dublettenprüfung (`md5( serialize( $slot ) )`) nach `Slots`. `getFirstBookableDay()`-Prüfung bleibt in
`getAvailabilitySlotsLive()`. Ausgabe: gleiche Objekte.

### 6.2 `Model\Week`, `Model\Day`

- `Week::__construct()` und `Day::__construct()` bekommen als **letzten** Parameter `?array $possibleRestrictions = null`.
  `Week` reicht ihn an jeden `Day` durch. Aufrufe ohne den Parameter verhalten sich wie vorher.
- `Day::RESTRICTION_STATUSES = [ 'publish', 'confirmed', 'unconfirmed' ]` (vorher als Literal in `getRestrictions()`).
- `Day::getRestrictions()`:
  - `$possibleRestrictions !== null`: `array_filter( …, isInRestriction() )`.
  - sonst: wie früher `Restriction::get( locations, items, getDate(), true, null, RESTRICTION_STATUSES )`.
- `Day::isInRestriction( Restriction $r )` (**neu, protected**) bildet die Datumsbedingung der alten SQL-Abfrage pro Tag
  nach: Start gesetzt (`''` → nein), `0 <= start <= Tag 23:59`; ohne Ende → ja, sonst
  `Tagesbeginn <= end <= Restriction::NO_END_TIMESTAMP`.

**Wirkung:** Ein Kalender (Karte, Tabelle, Artikelseite, REST) rief bisher je Tag `Restriction::get()` auf
(3.000–6.000 Abfragen je Karten-/Tabellenaufruf). Jetzt ist es eine Abfrage je Kalender. Diese Änderung gilt **auch ohne
Booster**.

### 6.3 `Model\Booking::cancel()`

Das direkte SQL-Update des Status (`$wpdb->query( $sql )`) umgeht alle Post-Hooks. Neu danach:

```php
clean_post_cache( $this->post->ID );                    // behebt einen veralteten Objekt-Cache (auch ohne Booster)
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( $this->post->ID, 'cancellation_time', current_time( 'timestamp' ) );
\CommonsBooking\Service\Index\Indexer::flush();         // Index (und Verfügbarkeit) sofort aktualisieren
$this->sendCancellationMail();
```

`touch()` und `flush()` sind ohne installierte Index-Tabellen wirkungslos (`flush()` prüft `Schema::isInstalled()`).
Nuance: Sind die Tabellen von früher noch vorhanden und der Booster ausgeschaltet, schreibt `flush()` die Zeile in die
(dann ungenutzte) Tabelle. Beim nächsten Einschalten wird ohnehin komplett neu aufgebaut.

### 6.4 `Model\Item::getClosestBooking()`

Liefert die zuletzt begonnene, bereits beendete, bestätigte Buchung des Artikels am aktuellen Standort. Daraus entsteht
die verschleierte GBFS-`vehicle_id` (`getCloakedId()`), die nach jeder Ausleihe wechselt.

```php
// 2.11.3
$allBookings = Booking::get( [ $location->ID ], [ $this->ID ], null, true, null, [ 'confirmed' ] );
$allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );

// Booster
$allBookings = Booking::getLatestPastConfirmed( $this->ID, $location->ID, current_time( 'timestamp' ) );
if ( $allBookings === null ) {                 // kein Index: Originalweg
    $allBookings = Booking::get( … gleiche Argumente … );
    $allBookings = array_filter( $allBookings, fn( $b ) => $b->isPast() );
}
// danach unverändert: usort nach Starttag, bei Gleichstand nach Startzeit; array_pop()
```

`getLatestPastConfirmed()` führt zwei Indexabfragen aus:

```sql
SELECT MAX(COALESCE(start_ts, 0)) FROM cb_index
 WHERE item_id = %d AND location_id = %d AND type = 6 AND post_type = 'cb_booking'
   AND post_status = 'confirmed' AND (end_ts IS NULL OR end_ts < :jetzt);
SELECT post_id FROM cb_index WHERE <gleiche Bedingung> AND COALESCE(start_ts, 0) = :spaetesterStart ORDER BY post_id;
```

Semantik des Originals bleibt erhalten:

| Eigenheit | Nachbildung |
|---|---|
| nur `confirmed`, nur Typ 6 | gleiche Bedingungen |
| `isPast()` vergleicht das End-Meta als Integer, fehlendes Ende (`intval('') = 0`) gilt als vergangen | `end_ts IS NULL OR end_ts < :jetzt` |
| fehlender Start = 0 | `COALESCE( start_ts, 0 )` |
| Gleichstand beim Starttag: Startzeit entscheidet; danach stabiles `usort`, `array_pop` nimmt die höchste ID | alle Kandidaten mit gleichem Start werden geladen, die **unveränderte** Sortierung entscheidet; `ORDER BY post_id` |

Ergebnis: Statt aller bestätigten Buchungen des Paares (auf der Referenzinstanz ≈ 1.300 Buchungen, 10 MB, 95 ms je
Artikel; `vehicle_availability.json` brach mit 128 MB Speicherlimit mit HTTP 500 ab) wird meist eine Buchung geladen.
`vehicle_id` ist identisch: auf der Referenzinstanz für alle 126 Artikel, auf der Testumgebung für alle 41 Artikel
verglichen (`dev-bench/closest-parity.php`, 0 Abweichungen). Test: `ItemTest::testGetClosestBooking_indexMatchesLegacy`.
Ohne Booster bleibt die Speicherlast wie in 2.11.3.

### 6.5 `Model\Map::get_locations()`

| | 2.11.3 | Booster (**auch ohne Booster**) |
|---|---|---|
| Artikel je Standort | `Item::getByLocation()` in der Hauptschleife | vorab für alle Standorte (`$itemsByLocation`) |
| Zeitrahmen | je Paar `Timeframe::getBookableForCurrentUser( [ $loc ], [ $item ], null, true )` | **eine** Abfrage für alle Paare: `Map::getBookableTimeframesByPair( $locationIds, $allItemIds )`, Zugriff `$timeframesByPair[ $loc ][ $item ] ?? []` |
| Terms | `wp_get_post_terms()` je Artikel mit Einzelabfrage | vorher `update_object_term_cache()` für Artikel und Standorte |
| Vorschaubilder | einzeln | `_prime_post_caches( $thumbnailIds, false, true )` |

`getBookableTimeframesByPair()` (neu, öffentlich) ruft `getBookableForCurrentUser( $locationIds, $itemIds, null, true )`
einmal auf und gruppiert nach `getLocationIDs() × getItemIDs()`. Das ist gleichwertig zum Aufruf je Paar, weil buchbare
Zeitrahmen genau einen Artikel und Standort haben (Listen werden für diesen Typ beim Speichern entfernt) und alle Filter
des Repositories je Zeitrahmen arbeiten.

### 6.6 `Model\Timeframe::withoutBookingWindow()`

`getEndDate()` kürzt das Ende eines buchbaren Zeitrahmens auf das Vorausbuchungsfenster
(`getLatestPossibleBookingDateTimestamp()` = `time()` + Vorausbuchungstage), `isBookable()` fragt dasselbe Fenster ab.
`Model\Day`, `Model\Week` und `Repository\Timeframe::filterTimeframesForTimerange()` nutzen beides. Damit hing auch
das Raster eines Tages (`Day::getGrid()`) vom heutigen Datum ab.

```php
// neu, public static
Timeframe::withoutBookingWindow( callable $callback );   // setzt ein statisches Flag für die Dauer des Callbacks
// am Anfang von getLatestPossibleBookingDateTimestamp():
if ( self::$ignoreBookingWindow ) { return PHP_INT_MAX; }
```

Einziger Aufrufer ist `Service\Availability\Calculator::computeDays()`. Die gespeicherten Tagesfakten enthalten den
Zeitrahmen dadurch bis zu seinem eigentlichen Ende, das Fenster wenden die Leser an: `CalendarWindow`
(`lastBookableDate`), `Reader::getFacts()` (rechnet ein Paar live, wenn ein weiterer buchbarer Zeitrahmen ein kürzeres
Fenster hat) und `SlotReader` (je Zeitrahmen). Vorher stand das Fenster vom Rechenzeitpunkt in der Zeile, und nach
Mitternacht blieb der letzte Tag des Fensters in Karte, Tabelle, API und GBFS bis zur nächsten Neuberechnung (bis 12 h)
gesperrt (gefunden im Frontendtest vom 12.11.2026). `Calculator::VERSION` stieg dafür von 2 auf 3. Außerhalb des
Callbacks und bei ausgeschaltetem Booster ändert sich nichts; die Buchungsprüfung nutzt das Flag nie.

---

## 7. Anzeige: View\Calendar, Karte, Tabelle

### 7.1 Ausgangslage in 2.11.3

Dieselbe Tageslogik (wann ist ein Tag gesperrt, gebucht, Feiertag, teilgebucht) existierte zweimal:
`View\Calendar::processSlot()` / `mapDay()` für JSON und Tabelle und `MapItemAvailable::markDaysInTimeframe()` für die
Karte. Beide rechneten je Paar und Tag das vollständige Slot-Raster (`Day::getGrid()`), auch für Tage, die nicht
angezeigt werden (Karte: 60 Tage statt der angezeigten).

### 7.2 Neuer gemeinsamer Rechenkern (`Service\Availability`)

| Klasse | Aufgabe | Ersetzt |
|---|---|---|
| `DayFacts` | verdichtet ein Slot-Raster auf zehn Bits (`HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_SLOT_BOOKED`, `LAST_SLOT_BOOKED`, `FULL_DAY`) und liefert daraus das Tages-Array des JSON | `View\Calendar::processSlot()` |
| `CalendarWindow` | alles, was vom heutigen Datum abhängt: Anzeigebereich, Vorausbuchungsgrenze, erster buchbarer Tag, `mapDay()` | Fensterlogik aus `getCalendarDataArray()`, `prepareJsonResponse()`, `mapDay()`, `getLockedDayArray()`, `getDefaultCalendarEnddateTimestamp()` |
| `DayStatus` | ein Status je Tag (`no-slots`, `holiday`, `booked`, `partially-booked-start`, `partially-booked-end`, `locked`, `available`) aus dem Tages-Array | die identischen if-Ketten in `renderItemLocationRow()` und `markDaysInTimeframe()` |
| `Reader` | liefert `DayStatus` je Tag für Karte und Tabelle: aus den gespeicherten Bits plus Fenster, sonst live | – |

`DayFacts::fromGrid()` übernimmt die Regeln von `processSlot()` **einschließlich ihrer Eigenheiten**, die per
Snapshot-Test festgehalten sind: `repair` wird von jedem Slot zurückgesetzt (strikter Vergleich Text gegen Zahl,
`$timeFrameType !== Timeframe::REPAIR_ID`); 0 Vorausbuchungstage gelten als „nicht gesetzt“ (`== null`);
`maxDays`/`startDayOffset` kommen vom Zeitrahmen des ersten Slots, wenn dieser buchbar ist.

### 7.3 `View\Calendar`

| Element | 2.11.3 | Booster |
|---|---|---|
| `processSlot( $slot, &$dayArray, &$jsonResponse, &$allLocked, &$noSlots )` (protected) | verdichtet einen Slot ins Tages-Array | **entfernt** → `DayFacts::fromGrid()` |
| `getLockedDayArray( Day )` (protected) | Tages-Array eines gesperrten Tages | **entfernt** → `CalendarWindow::getLockedDayArray()` (public static) |
| `getDefaultCalendarEnddateTimestamp( $startDate )` (private) | `strtotime( 'last day of +3 months', … )` | **entfernt** → `CalendarWindow::forTimeframes()` |
| `mapDay( $day, $lastBookableDate, $endDate, &$jsonResponse, $firstBookableDay )` (protected) | Fenster anwenden, JSON bauen | **neue Signatur** `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )`: wertet `DayFacts::fromGrid()` nur innerhalb des Buchungsfensters aus, setzt `maxDays` und `startDayOffset`, schreibt das Ergebnis von `CalendarWindow::mapDay()` in `days` und die Listen `holidays`, `bookedDays`, `lockDays`, `partiallyBookedDays` |
| `getCalendarDataArray()` | Fensterlogik inline | Signatur gleich; `CalendarWindow::forTimeframes( $bookableTimeframes, new Day( $start ), new Day( $end ), $keepDaterange )` und `renderJsonResponse()` |
| `prepareJsonResponse()` | Standardwerte und Aufbau | Signatur gleich; erzeugt `new CalendarWindow( … )` und ruft `renderJsonResponse()` |
| `renderJsonResponse( CalendarWindow, $locations, $items )` | – | **neu, privat**; enthält den früheren Aufbau (Cache-Schlüssel `serialize( $current_user->roles )`, `new Model\Calendar( … )`, `getWeeks()`, `mapDay()`) |
| `renderTable()` | jede Zeile live | bei `Availability::isEnabled()` ein `Reader`, `preload()` aller (Artikel, Standort)-Paare vorab; `$reader` an `renderItemLocationRow()` |
| `renderItemLocationRow( …, $days_display, ?Reader $reader = null )` (protected) | berechnete `getCalendarDataArray()`, if-Kette je Tag | `$reader->getDayStatuses()` oder `Reader::evaluateLive()`; Markup je Status aus Konstante `TABLE_STATUS_MARKUP` |

**Korrekturen in `View\Calendar`, gültig auch ohne Booster:**

1. **Kaputtes Markup gesperrter Tage.** Die Auffüllschleife nach dem Statusbereich schrieb
   `"<span'is-locked'></span>"`. Browser lesen das als Element `span'is-locked'` **ohne Klasse**; die Zellen blieben leer
   statt grau, obwohl `.is-locked` in `_shortcodeItemsTable.scss` existiert. Jetzt
   `TABLE_STATUS_MARKUP[ DayStatus::LOCKED ]` = `<span class='is-locked'></span>`. Sichtbar: Bei 31 Tagen Tabelle und 14
   Tagen Vorausbuchung sind die Tage 15–31 grau.
2. **Cache-Schlüssel der Tabellenzeile.** `$item->ID . $locationId . $today` →
   `$item->ID . '_' . $locationId . '_' . $today . '_' . $days`. Vorher fehlten Trennzeichen und Tagesanzahl: Artikel 1 /
   Standort 23 und Artikel 12 / Standort 3 kollidierten, eine 31-Tage-Tabelle bekam die Zeile einer 14-Tage-Tabelle.
   Das innere, nie wiedergefundene Cache-Item in `renderItemLocationRow()` (Schlüssel aus allen Argumenten, also nie
   gleich) entfällt; der Aufrufer `renderTable()` cacht die Zeile.

Hinweis zu `Calendar::getClosestBookableTimeFrameForToday()`: unverändert (public static), wird von
`CalendarWindow::forTimeframes()` aufgerufen.

### 7.4 `Map\MapItemAvailable`

| Element | 2.11.3 | Booster |
|---|---|---|
| `create_items_availabilities()` | je Paar `Calendar::getCalendarDataArray( item, location, start, end )`, danach `markDaysInTimeframe()` | bei `Availability::isEnabled()`: ein `Reader`, `preload()` aller Paare der Karte; je Paar `$reader->getDayStatuses( …, false, $from, $to )`, sonst `Reader::evaluateLive()` (= `getCalendarDataArray()` wie bisher); berechnet nur die **angezeigten** Tage `$from … $to` |
| `markDaysInTimeframe( $calendarData, $availabilities )` (protected) | eigene if-Kette | **entfernt** → `markDays( array $dayStatuses, array $availabilities )` (protected) mit Konstante `STATUS_MAPPING` (`DayStatus` → `ITEM_LOCKED`, `LOCATION_HOLIDAY`, `ITEM_BOOKED`, `ITEM_PARTIALLY_BOOKED`, `ITEM_AVAILABLE`). Tage, die nicht in den Daten stehen, behalten den Status „no-timeframe“ |

Das Kommentar-Versprechen des Originals („diese Logik muss `processSlot()` entsprechen, sonst werden Tage im Plugin
unterschiedlich abgebildet“) wird nun durch gemeinsamen Code statt durch Disziplin eingelöst.

---

## 8. Buchungsablauf

| Stelle | Änderung | Grund | Ohne Booster |
|---|---|---|---|
| `Plugin::handleBookingForms()` | `Booking::handleFormRequest()` in `Booster::withoutBooster()` | Prüfung liest nie Index, Vorberechnung oder Plugin-Cache | ohne Wirkung |
| `CustomPostType\Booking` (Admin-Bestätigung, `isValid()`) | `Booster::withoutBooster( [ $booking, 'isValid' ] )` | wie oben | ohne Wirkung |
| `CustomPostType\Timeframe::validateTimeFrame()` | `Booster::withoutBooster( [ $timeframe, 'isValid' ] )` | Konfliktprüfung beim Speichern eines Zeitrahmens | ohne Wirkung |
| `CustomPostType\Booking::handleFormRequest()` | `handleBookingRequest()` in `withItemLock( (int) $itemId, fn() => … )` | siehe unten | **ja** |
| `CustomPostType\Booking::withItemLock()` | **neu, privat** | siehe unten | **ja** |
| `Service\BookingRuleApplied` | Regel „nur eine unbestätigte Buchung“ | siehe unten | **ja** |
| `includes\Public.php` | Doppelklick-Schutz | siehe unten | **ja** |

**`withoutBooster()`** erhöht einen Zähler (`Booster::$bypassDepth`), woraufhin `canRead()` → `false` und damit
`Index::isAvailable()` und `Availability::isEnabled()` → `false` liefern, und führt den Callback in
`Plugin::withoutCache()` aus. Schreiben in den Index läuft weiter.

**Artikelsperre `withItemLock()`.** Die Originalprüfung braucht bei 167.000 Buchungen etwa 3 s. Ein zweiter Klick in
dieser Zeit legte eine zweite unbestätigte Buchung an (beide Anfragen fanden den Slot frei), ein dritter endete mit
„Found more than one bookings“. Das Problem bestand schon in 2.11.3.

```php
$name   = 'cb_booking_' . get_current_blog_id() . '_' . $itemId;
$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 20 ) );
if ( $locked === '0' ) { throw new BookingDeniedException( __( 'Other booking requests for this item are being processed right now. …' ) ); }
// $locked === null: DB ohne Lock-Unterstützung → weiter wie bisher
try { return $callback(); } finally { if ( $locked === '1' ) { $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); } }
```

Anfragen für denselben Artikel (und dieselbe Site bei Multisite) laufen nacheinander; nach 20 s Wartezeit erhält der
Nutzer eine Meldung. Bei `itemId = 0` wird nicht gesperrt.

**`BookingRuleApplied`.** Vorher: `Booking::getForCurrentUser( true, null, [ 'unconfirmed' ] )` (alle für den Nutzer
**sichtbaren** unbestätigten Buchungen, also bei Standort-Admins auch die der Kundschaft), danach Filter auf
`post_author === aktueller Nutzer` und auf die aktuelle Buchung. Jetzt:
`Booking::getForUserPaginated( wp_get_current_user(), 1, -1, [], [ 'unconfirmed' ] )` (nur eigene, `WP_Query`,
bewusst ohne Index) und Ausschluss der aktuellen Buchung per ID. Ergebnis gleich, ohne Laden aller Buchungen. Gäste
(`! is_user_logged_in()`) ergeben eine leere Liste.

**Doppelklick-Schutz (`includes/Public.php`).** Ein per `wp_add_inline_script( 'cb-scripts-public', … )` angehängtes
Skript fängt `submit` für `#booking-form` und `[id^=cb-booking-form-set-]` ab, setzt `data-cb-sent`, deaktiviert die
Submit-Buttons (außer `calendar-download`), zeigt „Bitte warten …“ und gibt die Formulare bei Rückkehr aus dem
Back-Forward-Cache (`pageshow`, `persisted`) wieder frei. Der Text ist übersetzbar (`wp_json_encode( __( 'Please wait …' ) )`).

---

## 9. Cache

`Service\Cache` (Trait, von `Plugin` und weiteren Klassen genutzt):

| Element | 2.11.3 | Booster |
|---|---|---|
| `getCacheId()` | Namespace aus Version, Plugin-Verzeichnis, Blog-ID, Klasse, Funktion, Argumente | zusätzlich `'_' . self::getRoleContext()` nach der Blog-ID |
| `getRoleContext()` | – | **neu, privat.** `'guest'` oder sortierte Rollen des Nutzers plus `|admin`, wenn `commonsbooking_isCurrentUserAdmin()`. Nutzer mit gleichen Rollen teilen Einträge |
| `withoutCache( callable )` | – | **neu, öffentlich** (über `Plugin::withoutCache`): erhöht `$cacheBypassDepth`, führt den Callback aus |
| `getCacheItem()` | `if ( WP_DEBUG ) return false;` | `if ( WP_DEBUG \|\| self::$cacheBypassDepth > 0 ) return false;` |
| `setCacheItem()` | – | schreibt nichts, solange `$cacheBypassDepth > 0` (liefert `false`) |

**Warum Rollen im Schlüssel (auch ohne Booster).** Zeitrahmen können auf Benutzerrollen beschränkt sein
(`allowed_user_roles`). Karte, Tabelle, Kalender und Artikelseite zeigen solche Artikel deshalb je Benutzer anders an.
Der Plugin-Cache hatte den Benutzer bisher nicht im Schlüssel: Ein Gast konnte die zwischengespeicherte Ansicht eines
Admins bekommen und umgekehrt. Folge der Änderung: etwas mehr Cache-Einträge (ein Eintrag je Rollenkombination).

`withoutCache()` wird von `Booster::withoutBooster()` und von `Calculator::compute()` genutzt (Berechnen der
Vorberechnung aus dem aktuellen Datenbankstand, während der Cache noch Werte von vor einer Änderung enthalten kann).

---

## 10. Admin-Zusatzfunktion „Zugeordnete Zeitrahmen“

Auf Entscheidung des Nutzers (2026-11-07) Teil des Booster-Updates, weil sie klein ist, keine Postmeta-Semantik ändert
und für Administratoren großer Instanzen das Finden der Zeitrahmen eines Artikels oder Standorts erst praktikabel macht.
Hintergrund: Feiertage (Typ 3) und Blockierungen (Typ 5) können mehrere Artikel/Standorte per Liste, Kategorie oder
„Alle“ betreffen (`item-id-list` / `location-id-list`, Auswahlart in `item-select`); die Admin-Liste filterte nur das
Einzelfeld `item-id` / `location-id`, die Spalte zeigte bei Mehrfachauswahl `-`.

Neue Klassen: `Repository\AssignedTimeframes` (Abfragen, nutzt `Index::findPostIds()` wenn vorhanden),
`View\Admin\AssignedTimeframes` (Metabox, Rendering). Änderungen an bestehenden Dateien:

| Datei | Änderung |
|---|---|
| `CustomPostType\Item::__construct()`, `Location::__construct()` | `add_action( 'add_meta_boxes_{type}', … AssignedTimeframes::addMetaBox() )`: Metabox „Assigned timeframes“ (Zeitrahmen **und** Einschränkungen, die den Artikel/Standort steuern) als erste Box der Hauptspalte |
| `CustomPostType\Timeframe` (Konstruktions-/Hook-Teil) | gleiche Technik: Metabox „Timeframes of the same item and location“ im Zeitrahmen-Editor (andere Zeitrahmen mit mindestens einem gemeinsamen Artikel **und** Standort) |
| `CustomPostType\CustomPostType` (Zeilenaktionen) | für Artikel und Standorte (nicht im Papierkorb, mit Bearbeitungsrecht) Aktion `cb_assigned_timeframes` „Timeframes“ mit Link auf die Metabox (`AssignedTimeframes::getLink()`, Anker `#cb-assigned-timeframes`) |
| `CustomPostType\Timeframe::filterAdminList()` | Filter „Artikel“ / „Standort“ der Zeitrahmenliste: statt nur `item-id` = Wert jetzt `OR`-Meta-Abfrage aus Einzelfeld **und** `LIKE ':"<id>";'` auf `item-id-list` bzw. `location-id-list` |
| `CustomPostType\Timeframe::setCustomColumnsData()` | Spalten „Artikel“ / „Standort“ bei Feiertagen und Reparaturen mit Mehrfachauswahl: `AssignedTimeframes::assignment()` → `assignmentHtml()` („3 Artikel“, „Kategorie: …“, „Alle“), bei direkter Zuordnung weiterhin die alte Ausgabe |

Regeln der Anzeige (nur Darstellung, keine Änderung von Speicherung oder Kalender): Ein Eintrag steht beim Artikel,
wenn dieser in der Artikelauswahl steht. „Wirkt an“ ist die Schnittmenge der Gegenseite des Zeitrahmens mit den
Standorten, an denen der Artikel buchbar ist. Ist sie leer und wurde die eigene Seite nur implizit gewählt („Alle“ oder
Kategorie), wird der Eintrag ausgeblendet; bei ausdrücklicher Auswahl bleibt er mit Warnhinweis stehen. Ein Feiertag oder
eine Blockierung wirkt auf Paare *gewählte Artikel × gewählte Standorte* (`getEntityQuery()` verknüpft beide mit
`INNER JOIN`, jeder Kalender fragt mit genau einem Paar ab). „Alle“ speichert eine Momentaufnahme der IDs
(`Timeframe::manageTimeframeMeta()`, aktualisiert durch `updateAllTimeframes()`).

---

## 11. Kleinere Änderungen: Template, SCSS, Frontend-Skript

- `templates/location-calendar-header.php`: Der Text der Abholhinweise steht jetzt in
  `<span class="cb-location-pickupinstructions-text">` (Aufruf von `trim()` auf `formattedPickupInstructionsOneLine()`),
  ohne Leerraum im Template-Markup.
- `assets/public/sass/partials/_lists.scss`: `white-space: pre-wrap` stand an `.cb-address.cb-location-pickupinstructions`
  und erzeugte dadurch zusätzliche Leerzeilen aus dem Whitespace des Templates. Es steht jetzt nur noch an
  `.cb-location-pickupinstructions-text`. Zeilenumbrüche des Metafelds bleiben erhalten. **Das kompilierte CSS ist nicht
  im Repository**; nach Änderungen an SCSS ist `npm run dist` nötig.
- `includes/Public.php`: siehe Abschnitt 8.

---

## 12. Was bewusst nicht geändert wurde

Zur Abgrenzung für Reviewer:

- **Keine Änderung der Postmeta-Semantik.** Keine neuen Meta-Schlüssel, keine geänderten Werte, keine Migration. Die
  Tabellen werden mit `dbDelta()` angelegt, mit eigener Schema-Versionsoption, ohne Fremdschlüssel und ohne Trigger.
- **Schreibwege** (`savePost`, Meta-Handler, `Timeframe::manageTimeframeMeta()`, Importer, Buchungscodes) sind unverändert.
  Der Booster hört nur mit (Abschnitt 13).
- **Unverändert im Originalweg:** `Repository\Timeframe::getPostIdsByType()`, `getPostsByBaseParams()`,
  `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()`, alle
  `filter*`-Methoden, `Repository\Booking::getForUserPaginated()`, `Repository\Item`/`Location`, die REST-Routen und
  GBFS-Klassen selbst (sie profitieren über `Model\Calendar::getAvailabilitySlots()`), `Model\Timeframe::isValid()`,
  `Model\Booking::isValid()` und alle Buchungsregeln außer der oben genannten.
- **Admin-Listen** laufen weiter über `postmeta` (außer dem erweiterten Filter aus Abschnitt 10).
- **`Model\Timeframe::isBookable()`, `getFirstBookableDay()`** und andere datumsabhängige Logik bleibt im PHP-Code; die
  Vorberechnung speichert nur datumsunabhängige Tagesfakten (dafür blendet sie das Vorausbuchungsfenster mit
  `withoutBookingWindow()` aus, 6.6), die datumsabhängigen Regeln wenden `CalendarWindow`, `Reader` und `SlotReader`
  beim Lesen an.
- **Die REST-API-Antwortformate** (`/availability`, GBFS) bleiben gleich (Ausnahmen: 15.2).

---

## 13. Wie neue Komponenten in bestehende Abläufe eingreifen

Alle Hooks werden **nur bei eingeschaltetem Booster** registriert (Ausnahme: `init` Priorität 0 für den Schalter und die
Einstellungsseite im Admin).

### 13.1 WordPress-Hooks

| Hook | Priorität | Klasse::Methode | Zweck |
|---|---|---|---|
| `init` | 0 | `Booster::onInit` | Ein-/Ausschalten erkennen, Wächter |
| `init` | 1 / 2 | `Index\Schema::maybeInstall`, `Availability\Schema::maybeInstall` | Tabellenstruktur prüfen/erweitern |
| `init` | 50 | `Verifier::schedule`, `Maintenance::schedule`, `Guard::schedule` | Cron-Ereignisse anlegen |
| `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | 10 | `Indexer::onMetaChange` | relevanter Schlüssel und CB-Beitragstyp → Beitrag vormerken |
| `transition_post_status` | 10 | `Indexer::onTransitionPostStatus` | Beitrag vormerken |
| `save_post` | **`PHP_INT_MAX`** | `Indexer::onSavePost` | vormerken und sofort `flush()`; läuft **nach** `CustomPostType\Timeframe::savePost()` (Priorität 11), der Index sieht also den endgültigen Stand |
| `deleted_post` | 10 | `Indexer::onDeletedPost` | Zeile entfernen |
| `shutdown` | 0 | `Indexer::flush` | Auffangnetz |
| `shutdown` | 5 | `Diagnostics::writeActivity` | Aktivitätsprotokoll schreiben |
| `shutdown` | `PHP_INT_MAX` | `Invalidator::onShutdown` | Neuberechnung (≤ 25 Paare, nach `fastcgi_finish_request()`) |
| `add_option_`/`update_option_commonsbooking_plugin_version` | 10 | `Guard::onOwnVersionWrite` | eigene Versionsänderung von fremder unterscheiden |
| `admin_init` | 10 | `Booster::workInRequest` | 5 s Einrichtung im Admin-Request nach dem Einschalten |
| `admin_post_*`, `wp_ajax_commonsbooking_booster_setup`, `admin_notices` | 10 | `SettingsView` | Knöpfe der Einstellungsseite, Fortschritt, Hinweis bei hängender Einrichtung |

Relevante Meta-Schlüssel (alle anderen lösen nichts aus): `type`, `item-id`, `location-id`, `item-id-list`,
`location-id-list`, `repetition-start`, `repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`,
`grid`, `full-day`, `start-time`, `end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`,
`booking-startday-offset`, `allowed_user_roles`, `restriction-*`.

### 13.2 Eigene Actions und Filter

| Name | Art | Bedeutung |
|---|---|---|
| `commonsbooking_index_rows_changed` | Action (`$old`, `$new`, `$postId`) | Projektion hat sich fachlich geändert (Fingerabdruck ohne Verwaltungsspalten); hört `Invalidator` (Prio 10), `Diagnostics` (Prio 20) |
| `commonsbooking_index_rebuilt` | Action | Backfill beendet; `Invalidator::onIndexRebuilt` rechnet Kalender im Hintergrund-Request |
| `commonsbooking_booster_enabled` | Filter | überstimmt die Einstellung |
| `commonsbooking_use_index` | Filter (`true`) | `false`: alles über `postmeta` |
| `commonsbooking_use_availability_cache` | Filter (`true`) | `false`: Tage live, Index bleibt |
| `commonsbooking_availability_horizon_days` | Filter (92) | Horizont |
| `commonsbooking_availability_max_age` | Filter (86400) | maximales Alter einer Zeile |
| `commonsbooking_availability_sync_limit` | Filter (25) | Paare, die am Request-Ende neu berechnet werden |
| `commonsbooking_availability_finish_request` | Filter (`true`) | `fastcgi_finish_request()` vor der Neuberechnung |
| `commonsbooking_availability_refresh_after_rebuild` | Filter | Kalender sofort nach dem Indexaufbau rechnen |
| `commonsbooking_availability_access_profiles` / `_max` | Filter (`true` / 8) | Zugriffsprofile je Paar |

### 13.3 Cron-Ereignisse

| Hook | Rhythmus | Aufgabe |
|---|---|---|
| `commonsbooking_index_backfill` | plant sich selbst, 20 s je Lauf | Index-Aufbau |
| `commonsbooking_index_verify` | täglich 04:00 | Stichprobe 200 Beiträge Index ↔ frische Projektion, repariert |
| `commonsbooking_booster_guard` | stündlich | Tiefenprüfung Status/Änderungsdatum, Tabellen vorhanden |
| `commonsbooking_availability_refresh` | stündlich + bei Bedarf | neue Paare, geänderte und über 12 h alte Zeilen neu rechnen (20 s Budget) |
| `commonsbooking_availability_verify` | täglich 04:30 | Stichprobe 50 Paare / 50 Zugriffsprofile |

Alle werden beim Ausschalten und Deaktivieren des Plugins entfernt. Die Einrichtung kann zusätzlich ohne WP-Cron im
Browser des Admins abgeschlossen werden (`SettingsView::runSetupStep()`, Schritte à 8 s).

### 13.4 Optionen und WP-CLI

Optionen: `commonsbooking_booster_state`, `commonsbooking_index_schema_version`, `commonsbooking_availability_schema_version`,
`commonsbooking_index_ready`, `commonsbooking_index_backfill_cursor`, `commonsbooking_index_backfill_lock`,
`commonsbooking_availability_pairs_synced`, `commonsbooking_booster_checks`, `commonsbooking_booster_activity`.
Entfernen: `wp commonsbooking booster purge`.

WP-CLI: `commonsbooking booster status|enable|disable|rebuild|check [--deep]|purge`,
`commonsbooking index status|rebuild|verify|drop`, `commonsbooking availability status|rebuild|verify|drop`.

---

## 14. Verhaltensänderungen unabhängig vom Schalter

Diese Änderungen gelten auch, wenn der Booster ausgeschaltet ist, und gehören deshalb in den Changelog:

| Änderung | Sichtbar als | Stelle |
|---|---|---|
| Artikelsperre beim Buchen | parallele Buchungen desselben Artikels laufen nacheinander; nach 20 s Meldung „Other booking requests …“ | `CustomPostType\Booking::withItemLock()` |
| Doppelklick-Schutz | Button „Bitte warten …“ | `includes/Public.php` |
| Rollen im Cache-Schlüssel | etwas mehr Cache-Einträge; keine fremden Ansichten mehr | `Service\Cache` |
| `getForUser()` ohne Vollscan | „Meine Buchungen“ schneller, Ergebnis gleich | `Repository\Booking` |
| Regel „eine unbestätigte Buchung“ nur über eigene Buchungen | Ergebnis gleich, schneller | `Service\BookingRuleApplied` |
| Karte lädt Zeitrahmen, Terms, Bilder gebündelt | weniger Abfragen | `Model\Map` |
| verwandte Beiträge gebündelt geladen | weniger Abfragen | `Repository\BookablePost` |
| Einschränkungen einmal je Kalender, Zeitrahmen/Einschränkungen erst bei Cache-Fehlschlag | 3.000–6.000 Abfragen weniger je Karten-/Tabellenaufruf | `Model\Calendar`, `Week`, `Day` |
| Karte rechnet nur angezeigte Tage | weniger Rechenzeit | `Map\MapItemAvailable` |
| Tabellen-Cache-Schlüssel mit Trennzeichen und Tagesanzahl | keine Kollisionen mehr | `View\Calendar` |
| `is-locked`-Markup korrigiert | Tage hinter der Vorausbuchungsgrenze sind grau | `View\Calendar` |
| `cancel()` mit `clean_post_cache()` | nachfolgende Lesezugriffe im selben Request sehen „storniert“ | `Model\Booking` |
| Abholhinweise ohne zusätzliche Leerzeilen | Darstellung | Template, SCSS |
| Admin: Filter und Spalten für Mehrfachauswahl, Metaboxen, Zeilenaktion | Backend | Abschnitt 10 |
| Dashboard: vierte Spalte für Admins | Backend | Abschnitt 4.4 |
| neue Einstellung „Performance booster“ | Backend | Abschnitt 4.2 |
| Version `2.11.4-beta.5` | Cache-Schlüssel, Versionsanzeige | `commonsbooking.php` |

---

## 15. Abweichungen vom Originalverhalten mit eingeschaltetem Booster

### 15.1 Randfälle des Indexweges (per Test festgehalten)

| Fall | 2.11.3 | Booster |
|---|---|---|
| `repetition-end` vorhanden, aber leer | Beitrag fiel aus der Abfrage heraus | offenes Ende (wie `Model\Timeframe::getTimeframeEndDate()`) |
| ungültige ID (`'abc'` → `IN (0)`) | traf Zeitrahmen mit leerem `item-id` (MySQL wandelt `''` in 0) | trifft nichts |

### 15.2 Fehler im Live-Weg der API von 2.11.3, die der Booster nicht reproduziert

`SlotsTest::testKnownLiveDeviations()` hält beide fest; auf der Referenzinstanz mit echten Daten nachgewiesen.

1. **Einschränkungen ohne Standortfilter.** `/availability` baut den Kalender ohne Standort. Live findet
   `Restriction::get( [], [ $item ] )` dann nur Einschränkungen ohne Standort und übersieht alle mit Artikel **und**
   Standort sowie standortweite (Artikel 0). Reparaturtage erscheinen live als frei, mit Booster als gesperrt, weil jedes
   Paar mit seinem Standort berechnet wird.
2. **Tage nach dem Enddatum.** Der Kalender läuft bis Sonntag, lädt live aber nur Beiträge bis zum Enddatum. Eine Buchung
   am Freitag nach einem Donnerstag-Enddatum fehlt live; mit Booster ist sie berücksichtigt.

Ohne Booster bleibt das Verhalten von 2.11.3. **Empfehlung: beide Fehler dem Hauptprojekt melden** (Abschnitt 19.5).

### 15.3 Rückfälle auf den Live-Weg (kein Verhaltensunterschied, aber langsamer)

Live gerechnet werden: `ignoreRestrictions = true` (GBFS `vehicle_status`), Kalendertage außerhalb des Horizonts
(`api_future_availability_weeks` > 12), Zeitrahmen mit Mehrfachauswahl im Bereich, rollenbeschränkte Paare jenseits der
8 Zugriffsprofile, Anfragen ohne Artikelfilter.

---

## 16. Geänderte Schnittstellen

Für Unterklassen, Child-Plugins und Code Dritter:

| Element | Sichtbarkeit | Änderung | Ersatz / Hinweis |
|---|---|---|---|
| `View\Calendar::processSlot()` | protected | **entfernt** | `Service\Availability\DayFacts::fromGrid()` |
| `View\Calendar::getLockedDayArray()` | protected | **entfernt** | `CalendarWindow::getLockedDayArray()` (public static) |
| `View\Calendar::getDefaultCalendarEnddateTimestamp()` | private | **entfernt** | in `CalendarWindow::forTimeframes()` |
| `View\Calendar::mapDay()` | protected | **neue Signatur** | `mapDay( Day, CalendarWindow, array & )` |
| `View\Calendar::renderItemLocationRow()` | protected | zusätzlicher optionaler Parameter | `?Reader $reader = null` |
| `Map\MapItemAvailable::markDaysInTimeframe()` | protected | **entfernt** | `markDays( array $dayStatuses, array $availabilities )` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | public | zusätzlicher optionaler letzter Parameter | `?array $possibleRestrictions = null` |
| `Model\Calendar::$timeframes` | protected | `array` → `?array`, Laden in `loadPosts()` | Unterklassen, die `$this->timeframes` im Konstruktor lesen, sehen `null` |
| `Model\Calendar::getAvailabilitySlots()` | public | Signatur gleich, Weg über `SlotReader` | `getAvailabilitySlotsLive()` für den alten Weg |
| `Repository\Timeframe::get()`, `getInRange()` | public | Signatur und Ergebnis gleich | – |
| `Repository\Booking::getForUser()` | public | Signatur und Ergebnis gleich | – |
| `Service\Cache::getCacheId()` | public | Schlüssel enthält den Rollenkontext | gespeicherte Schlüssel anderer Quellen passen nicht mehr (Cache verfällt ohnehin mit der Version) |

Neu öffentlich in bestehenden Klassen: `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`,
`Repository\Booking::getLatestPastConfirmed()`, `Model\Map::getBookableTimeframesByPair()`, `Plugin::withoutCache()`
(über das Trait `Cache`), `Day::RESTRICTION_STATUSES`, `Model\Timeframe::withoutBookingWindow()`.

---

## 17. Konsistenz und Sicherheit

### 17.1 Wie der Index aktuell bleibt

1. Hooks (Abschnitt 13.1) markieren geänderte Beiträge in einer Merkliste (`Indexer::$dirty`), mehrfaches Markieren
   desselben Beitrags ergibt **eine** Projektion.
2. `Indexer::flush()` projiziert (`Projector::project()`), jeweils in `try/catch` – ein Fehler bricht nie den Request ab.
   Es läuft am Ende von `save_post`, in `Model\Booking::cancel()`, **vor jedem Lesen** aus dem Index
   (`Index::findPostIds()`, `Reader::preload()`, `SlotReader`) und auf `shutdown`. Dadurch sieht jede Anzeige im selben
   Request die eigenen Schreibvorgänge („read your writes“).
3. Ändert sich eine Projektion fachlich, feuert `commonsbooking_index_rows_changed`; `Invalidator` erhöht synchron die
   Generation `gen` der betroffenen Paare in `cb_availability`. Gelesen wird eine Zeile nur, wenn `computed_gen = gen`.
   Neuberechnung: bis 25 Paare am Request-Ende, sonst per Cron oder beim nächsten Lesen. Zeilen verfallen spätestens nach
   24 h.

### 17.2 Buchungen bleiben immer korrekt geprüft

Siehe Abschnitt 8. Getestet: Die Indexzeile einer Buchung wurde absichtlich gelöscht (Index veraltet, gilt aber als
vollständig); die erneute Buchung desselben Tages wurde trotzdem abgelehnt, weil die Prüfung die Originalabfragen nutzt.

### 17.3 Downgrade und Upgrade

Ablauf: Booster ausschalten, ältere Version einspielen (sie ignoriert Tabellen, Optionen und Cron-Ereignisse), später
neue Version einspielen und Booster wieder einschalten; der Index wird **immer** komplett neu aufgebaut.

Wird das Ausschalten vergessen, erkennt der Wächter (`Service\Booster\Guard`) die Folgen in den Daten:

| Prüfung | Wann | Erkennt | Folge |
|---|---|---|---|
| Plugin-Version | jede Anfrage | `commonsbooking_plugin_version` wurde von anderer Version geändert | Neuaufbau |
| neue Beiträge ohne Indexzeile | jede Anfrage, nur IDs oberhalb der `watermark`, nach 2 min | Beiträge, die eine andere Version angelegt hat | Neuaufbau |
| Status/Änderungsdatum | stündlich, `booster check --deep` | Stornierungen/Änderungen einer anderen Version, gelöschte Beiträge | einzeln reparieren, ab 200 Neuaufbau |
| Tabellen vorhanden | stündlich | Datenbank ohne die Booster-Tabellen eingespielt | Tabellen anlegen, Neuaufbau |

Getestet mit der offiziellen Version 2.11.3 als Zweitplugin (`dev-bench/downgrade-scenario.sh`, Szenarien `both` und
`cancel`): Index und Verfügbarkeit nach dem Zurückwechseln ohne Abweichung; Buchungsprüfung über das Formular
abgelehnt/zugelassen wie erwartet.

### 17.4 Grenzen der Absicherung

Änderungen an den Hooks vorbei (direktes SQL, Importer ohne `save_post`) sieht der Index erst über Wächter oder
täglichen Verifier; Verfügbarkeitszeilen verfallen nach 24 h. Eine reine Meta-Änderung ohne Speichern findet erst die
tägliche Stichprobe. Eine Doppelbuchung kann daraus nie entstehen.

---

## 18. Tests, CI, Werkzeuge

| Datei | Änderung |
|---|---|
| `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php` | Filter `commonsbooking_booster_enabled` standardmäßig `true`, Index wird vor dem Lauf gebaut (`Schema::install()`, `Backfill::runAll()`), Zustand auf „aktiv“. Variablen `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0` schalten Teile ab |
| `tests/php/BaseTestCase.php` | `setUp()` setzt statische Zustände von `Store`, `Calculator`, `Invalidator` zurück |
| `tests/php/Model/CalendarTest.php` | erwartete `Week`-Objekte mit neuem Parameter |
| `tests/php/Model/ItemTest.php` | `testGetClosestBooking_indexMatchesLegacy` |
| `tests/php/Repository/BookingTest.php` | `getForUser()` mit verwalteten Artikeln/Standorten |
| `tests/php/View/CalendarTest.php` | `is-locked`-Markup |
| `tests/php/Wordpress/CustomPostType/BookingTest.php` | Regel „eine unbestätigte Buchung“ mit verwaltetem Artikel |
| `.github/workflows/phpunit.yml` | Matrix um `use_index`, `use_availability`, `use_booster` erweitert; **drei zusätzliche Läufe** (PHP 8.1, WP latest): Booster aus, Index aus, Vorberechnung aus; Variablen werden an `phpunit` durchgereicht |
| `phpstan.neon` | `'#unknown class WP_CLI#'` ignoriert (keine Stubs) |
| `phpstan-baseline.neon` | Eintrag `WP_Post::$locked` in `View/Calendar.php` entfällt (Code entfernt) |
| `.distignore` | Plugin-Zip enthält nur noch Laufzeitdateien: ausgeschlossen `design/`, `screenshots/`, `phpbench.json`, JS-Quellen, Admin-SCSS, unminifizierte Varianten, Source-Maps, `.po`/`.pot`; **nicht** ausgeschlossen `assets/global/sass` und `assets/public/sass` (`View::getColorCSS()` kompiliert sie zur Laufzeit) |
| `TECHNICAL.md` | neue Abschnitte „Performance booster“, „Timeframe index“, „Precomputed availability“ |
| `languages/*` | neue Texte (de_DE übersetzt); `.pot` neu erzeugt (`wp i18n make-pot`), `.mo` neu gebaut. Kurze Einzelwörter des Aktivitätsprotokolls stehen mit Kontext (`_x( …, 'index activity' )`), weil `shortcode-bookings.php` Filterwerte (auch Benutzernamen) durch `__()` schickt |

**Neue Tests** (Auswahl): `TimeframeIndexParityTest` und `IndexerTest` (Index ↔ Originalweg), `CalendarSnapshotTest`
(Ausgabe gegen 2.11.3, Snapshot `tests/php/View/snapshots/calendar-core.json`), `AvailabilityTest`, `SlotsTest`,
`AccessProfileTest`, `BoosterTest` (Schalter, Umgehung bei der Buchungsprüfung, Wächter, `purge`), `SetupTest`,
`DiagnosticsTest`, `CacheRoleContextTest`, `BookingItemLockTest`, `AssignedTimeframesTest`,
`AssignedTimeframesViewTest`, Tests mit vorgestellter Uhr für das Vorausbuchungsfenster
(`AvailabilityTest::testBookingWindowMovesWithTheDate`, `SlotsTest::testBookingWindowIsAppliedOnRead`), sowie `TimeframeVariantsParityTest` mit `Fixtures/TimeframeVariants.php` (31 Zeitrahmen-
Konfigurationen, verglichen für Gast, Abonnent, Redakteur, zwei cb_manager und Admin). Die Suite läuft in vier Modi
(Standard, `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`).

**Messwerte** (Referenzinstanz mit 167.000 Buchungen und 100 Stationen, `WP_DEBUG`, ohne Plugin-Cache):
Kartendaten 0,11 s und `[cb_map]` + `[cb_items_table]` 0,10 s (ohne Booster: Abbruch am Speicherlimit),
`[cb_items]` 0,13 s (ohne Booster 33,6 s), `[cb_locations]` 0,12 s (33,3 s), Artikelseite 0,06 s (2,3 s),
`vehicle_availability.json` 0,65 s (ohne Booster HTTP 500). Index-Aufbau 23,9 s, Vorberechnung von 100 Paaren 1,3 s.
Testumgebung: „Klassische Ansichten“ 213 ms/1.190 Abfragen → 116 ms/48 Abfragen.

---

## 19. Hinweise für das Review, Grenzen, Abgleich mit den Design-Dokumenten

### 19.1 Wo ein Review am meisten bringt

1. `Day::isInRestriction()` gegen `Restriction::queryPosts()` / `queryPostsFromIndex()`: drei Stellen bilden dieselbe
   Datumsbedingung nach (SQL, Index, PHP-Filter pro Tag).
2. `Repository\Booking::findViaIndex()`: Die Whitelist der `$customArgs` ist die Stelle, an der ein künftiges neues
   WP_Query-Argument still ignoriert werden könnte. Aktuell führt jedes unbekannte Argument zum `null`-Rückfall.
3. `DayFacts::fromGrid()` ↔ ursprüngliches `processSlot()`: bewusst Eigenheit für Eigenheit übernommen, abgesichert
   durch `CalendarSnapshotTest`. Änderungen der Tageslogik gehören künftig dorthin, nicht in `View\Calendar`
   (dann `Calculator::VERSION` erhöhen und Snapshot bewusst neu aufzeichnen).
4. `Cache::getRoleContext()`: beeinflusst **jeden** Cache-Schlüssel des Plugins.
5. `CustomPostType\Booking::withItemLock()`: nutzt `GET_LOCK()`; auf Datenbanken ohne Lock-Unterstützung läuft der Code
   wie bisher, auf geteilten Verbindungen (Proxy-Poolern) hängt die Wirkung an der Verbindungssemantik.

### 19.2 Der Booster ist kein Ersatz für die Originalprüfung

Alles, was eine Buchung zulässt oder ablehnt, bleibt im Originalweg. Die Beschleunigung betrifft ausschließlich
Anzeigen (Karte, Tabellen, Listen, API, GBFS) und Admin-Auflistungen.

### 19.3 Weitere bekannte Grenzen

- Wiederholungs- und Rasterspalten in `cb_index` werden gepflegt und vom Verifier geprüft, aber noch von keinem Leseweg
  genutzt (Vorbereitung für Wochentags- oder Rasterfilter in SQL).
- Plugins wie Query Monitor, die alle Abfragen im Speicher halten, können die ersten Admin-Seiten nach dem Einschalten
  an das Speicherlimit bringen; die Einrichtung arbeitet deshalb in Scheiben und bricht bei mehr als 60 % belegtem
  Speicher ab.

### 19.4 Offene Punkte (nicht Teil dieser Änderung)

| Punkt | Auswirkung |
|---|---|
| „Meine Buchungen“ für CB-Admins | lädt alle Buchungen ab `startDate`, paginiert in PHP |
| Buchungskalender der Artikelseite | rechnet live, profitiert nur vom Plugin-Cache |
| `Item::getClosestBooking()` ohne Booster | lädt weiterhin alle bestätigten Buchungen des Paares (Änderung am Originalweg wäre `WP_Query` mit `orderby`/`posts_per_page`) |
| `Repository\Item::getByCloakedId()` | berechnet `getCloakedId()` je Artikel |
| `vehicle_status` mit `ignoreRestrictions` | bleibt live |
| Admin-Listen | weiter über `postmeta` |

### 19.5 Vorschläge, die unabhängig vom Booster ins Hauptprojekt übernommen werden könnten

Diese Änderungen sind klein, schalterunabhängig und lassen sich einzeln als PR extrahieren:

1. `is-locked`-Markup-Fix (`<span'is-locked'>` → `<span class='is-locked'>`) und die Cache-Schlüssel-Korrektur der
   Tabellenzeile.
2. Rollen im Cache-Schlüssel (Datenschutz-/Korrektheitsproblem bei rollenbeschränkten Zeitrahmen).
3. Artikelsperre und Doppelklick-Schutz gegen doppelte Buchungen.
4. Einschränkungen einmal je Kalender statt je Tag (`Calendar`/`Week`/`Day`).
5. `Booking::getForUser()` ohne Vollscan und die schlankere Regel „eine unbestätigte Buchung“.
6. Bündeln in `Map::get_locations()` und `BookablePost::getRelatedPosts()`.
7. Die beiden Fehler der API aus 15.2 (Einschränkungen ohne Standortfilter, Tage nach dem Enddatum) als Issues.
8. Admin-Filter und -Spalten für Mehrfachauswahl (Abschnitt 10).

### 19.6 Abgleich mit den vorhandenen Design-Dokumenten (Stand dieses Berichts)

Beim Lesen des Diffs gegenüber `booster-aenderungen-bestehender-dateien.md` und Teil G der technischen Dokumentation
fielen folgende Unterschiede auf:

- Beide Dokumente zählen **38** geänderte Dateien (Stand 2026-11-06). Der aktuelle Diff enthält **40** Nicht-Neu-Dateien
  außerhalb von `languages/`; neu hinzugekommen sind die Änderungen für „Zugeordnete Zeitrahmen“ an
  `CustomPostType\CustomPostType`, `Item`, `Location` und `Timeframe` sowie `Model\Timeframe` (6.6). Die Übersicht in
  `booster-aenderungen-bestehender-dateien.md` enthält diese Dateien nicht; Teil G der technischen Dokumentation nennt sie
  in 28.13, beschreibt aber die Infobox im Zeitrahmen-Editor (Commit `afc3f6ee`, `add_meta_boxes_cb_timeframe`) noch nicht.
- `booster-aenderungen-bestehender-dateien.md` verweist im Kopf auf den Arbeitsstand „nach `8ade45b8`“; der Branch steht
  inzwischen bei `0312f3aa`. Dort fehlt auch `src/Model/Timeframe.php` (6.6, Commit `bca2700f`); beschrieben ist die
  Änderung in Abschnitt 8.4 der technischen Dokumentation.
- Die Doku nennt „bei ausgeschaltetem Booster ohne Wirkung“ für `cancel()` über `Schema::isInstalled()`. Zutreffend ist das
  nur ohne Tabellen. Sind sie von einer früheren Aktivierung noch vorhanden, projiziert `flush()` weiterhin in sie
  (Abschnitt 6.3). Das ist unkritisch, weil jedes Einschalten neu aufbaut.
- Alle Zahlen und Methodenbeschreibungen in Abschnitt 5–10 wurden gegen den Code geprüft und stimmen mit der technischen
  Dokumentation überein.

---

## 20. Regeln für künftigen Code

| Wenn du … | dann … |
|---|---|
| eine Abfrage auf Zeitrahmen, Buchungen oder Einschränkungen schreibst | `Index::isAvailable()` prüfen, Index- und Originalpfad anbieten, Paritätstest ergänzen |
| Beiträge dieser Typen per direktem SQL änderst | `clean_post_cache( $id )`, `Indexer::touch( $id )`, `Indexer::flush()` |
| einen neuen Meta-Schlüssel einführst, der Zuordnung oder Verfügbarkeit beeinflusst | Schlüssel in `Projector::getRelevantMetaKeys()`, ggf. Spalte und `Schema::SCHEMA_VERSION`, `Projector::PROJECTOR_VERSION` erhöhen (löst Neuaufbau aus) |
| die Tageslogik (Slots, Feiertage, Status) änderst | in `DayFacts` / `CalendarWindow` / `DayStatus`, nicht in `View\Calendar`; `Calculator::VERSION` erhöhen; Snapshot bewusst neu aufzeichnen |
| eine Buchung oder einen Zeitrahmen prüfst | in `Booster::withoutBooster()` ausführen |
| neue Klassen unter `src/` anlegst | `composer run prefix-namespaces` (das Plugin lädt nur den Strauss-Autoloader in `vendor-prefixed/`) |
| testest | Suite in allen vier Modi (Standard, `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`) |

Englische Fassung: [`booster-bericht-core-entwickler.en.md`](booster-bericht-core-entwickler.en.md).
