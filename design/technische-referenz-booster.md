# Technische Referenz Performance-Booster

*English version: [technische-referenz-booster.en.md](technische-referenz-booster.en.md)*

Stand: 19.11.2026 · Branch `booster/index-availability` · Vergleichsbasis: CommonsBooking 2.11.3 (`master`, `7aa75a81`)
· Plugin-Version des Branches: `2.11.4-beta.5`

## 0. Wozu dieses Dokument

Nachschlagewerk für Entwickler, die den Booster-Code lesen, prüfen, ändern oder mit dem Original abgleichen. Es
beschreibt den **Code im heutigen Stand**, Klasse für Klasse und Datei für Datei, und gilt für alle Fragen der Art
„Welche Klasse macht was, was ist aktiv, wenn der Booster aus ist, was bleibt bei einem Downgrade zurück?“.

| Dokument | Inhalt | Verhältnis zu diesem Dokument |
|---|---|---|
| [`technische-dokumentation-booster-02.md`](technische-dokumentation-booster-02.md) | Konzept, Datenmodell im Detail, Schreib- und Leseprozesse mit Szenarien, Messwerte (Stand 07.11.2026) | erklärt das **Warum** und die Abläufe; hier stehen Verweise auf die Abschnitte |
| [`booster-aenderungen-bestehender-dateien.md`](booster-aenderungen-bestehender-dateien.md) | Änderungen an Originaldateien (Stand 06.11.2026) | **ersetzt durch Abschnitt 6** dieses Dokuments (enthält auch spätere Änderungen) |
| [`booster.md`](booster.md), [`timeframe-index.md`](timeframe-index.md), [`availability-precompute.md`](availability-precompute.md), [`role-restricted-availability.md`](role-restricted-availability.md), [`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md), [`zeitrahmen-info.md`](zeitrahmen-info.md) | Entwürfe und Entscheidungen | historisches Protokoll der Entscheidungen |
| `TECHNICAL.md` | Kurzfassung und Konventionen für Änderungen | verbindliche Regeln |
| `dev-changelog/` | jede Änderung seit 19.11.2026 | fortlaufend |

**Im Code finden:** Jede Änderung an einer Datei des Originals trägt einen Hinweis `@since 2.11.4 Booster: …` im
Docblock (Methode, Konstante, Eigenschaft) oder einen Kommentar `Booster (2.11.4)` / `Booster update (2.11.4 …)` in
Templates, Optionen und SCSS:

```bash
grep -rn "2.11.4 Booster\|Booster (2.11.4)\|Booster update (2.11.4" src includes templates assets/public/sass
```

Neue Klassen beschreiben im Klassen-Docblock Zweck, Zusammenspiel, Verhalten bei ausgeschaltetem Booster und Folgen
für einen Downgrade.

---

## 1. Überblick in einem Bild

```
                 Schreiben (gleicher Request)                         Lesen
 Beitrag gespeichert ──► Indexer ──► Projector ──► cb_index / cb_index_rel ──► Repository\Index ──► Repositories
 (save_post, meta,          │          │                                          (Timeframe, Booking,
  Status, Löschen)          │          └─► Aktion „index_rows_changed“                 Restriction, BookablePost)
                            │                       │
                            │                       ▼
                            │                 Invalidator ──► cb_availability.gen + 1
                            │                       │              (sofort ungültig)
                            │                       ▼
                            │                 Calculator (Request-Ende / Cron) ──► cb_availability(_access)
                            │                                                           │
                            │                                                           ▼
                            │                                      Reader (Karte, Tabelle), SlotReader (API, GBFS)
                            ▼
   Buchungsprüfung ──► Booster::withoutBooster() ──► nur Originalabfragen, kein Plugin-Cache
```

Ein Schalter (`booster_enabled`, Standard **aus**) steuert alles. Ausgeschaltet läuft der Originalcode.

---

## 2. Welcher Weg läuft? (Entscheidungskette)

| Prüfung | Methode | Folge, wenn nein |
|---|---|---|
| Booster eingeschaltet (Einstellung, Filter `commonsbooking_booster_enabled`) | `Booster::isEnabled()` | keine Hooks, kein Cron, keine Lesezugriffe; Originalverhalten |
| Booster aktiv (Einschalten abgeschlossen) und nicht umgangen | `Booster::canRead()` | Originalabfragen |
| Index vollständig, Filter `commonsbooking_use_index` nicht `false` | `Backfill::isReady()` = `Repository\Index::isAvailable()` | Originalabfragen (`postmeta`) |
| Vorberechnung erlaubt (`commonsbooking_use_availability_cache`), Tabellen installiert, Index bereit | `Availability::isEnabled()` | jeder Tag wird live berechnet wie im Original |
| Zeile nutzbar (`computed_gen = gen`, aktuelle `calc_version`, jünger als `getMaxAge()`) | `Store::isUsable()`, `AccessStore::isUsable()` | Zeile wird beim Lesen neu berechnet und gespeichert |

Innerhalb von `Booster::withoutBooster()` ist `canRead()` immer falsch und der Plugin-Cache umgangen
(`Cache::withoutCache()`). Details: Fassung 2, Abschnitt 14 und 19.

---

## 3. Komponenten

Spalte „aus“: Was die Klasse tut, wenn der Booster ausgeschaltet ist.

### 3.1 `CommonsBooking\Service\Booster` – Schalter, Wächter, Verwaltung

| Klasse | Zweck | Wichtige öffentliche Methoden | aus |
|---|---|---|---|
| `Booster` | Ein Schalter für Index und Vorberechnung, Zustandsübergänge, Umgehung für die Buchungsprüfung | `init()`, `isEnabled()`, `canRead()`, `withoutBooster()`, `activate()`, `deactivate()`, `purge()`, `getStatus()`, `onPluginActivation()`, `onPluginDeactivation()` | registriert nur Einstellungsseite und WP-CLI |
| `Guard` | erkennt Änderungen, die ohne Booster-Code gemacht wurden (Downgrade, Fremdcode), und baut neu auf oder repariert | `check()` (jeder Request, billig), `deepCheck()` (stündlich, CLI), `rebuild()` | nicht geladen |
| `Diagnostics` | Kennzahlen für die Einstellungsseite: Abdeckung, letzte Buchungen, Aktivitätsprotokoll, Prüfergebnisse, Cron-Zeiten | `getHealth()`, `getCoverage()`, `getLatestBookings()`, `runAllChecks()`, `recordCheck()` | nicht geladen |
| `SettingsView` | Statusbereich unter *Erweiterte Optionen*, Dashboard-Spalte, Knöpfe „Prüfen“, „Neu aufbauen“, „Einrichtung abschließen“ (AJAX in Schritten von 8 s) | `render()`, `renderDashboardColumn()`, `handleCheck()`, `handleRebuild()`, `handleSetupStep()` | im Admin geladen (zeigt Schalterzustand) |
| `Cli` | `wp commonsbooking booster status\|enable [--now]\|disable\|rebuild\|check [--deep] [--dry-run]\|purge [--yes]` | – | immer registriert |

### 3.2 `CommonsBooking\Service\Index` – Zeitrahmen-Index (Stufe 2)

| Klasse | Zweck | Wichtige öffentliche Methoden | aus |
|---|---|---|---|
| `Schema` | Tabellen `cb_index`, `cb_index_rel`, Schemaversion | `install()`, `uninstall()`, `isInstalled()`, `tablesExist()` | Tabellen bleiben bis `purge` |
| `Projector` | bildet einen Beitrag auf seine Indexzeile(n) ab, idempotent; meldet Änderungen per Aktion | `project()`, `remove()`, `buildRow()`, `diff()`, `getPostTypes()` | nicht aufgerufen |
| `Indexer` | Hooks auf Beiträge und Metadaten, Merkliste je Request, `flush()` vor dem Lesen und bei `shutdown` | `touch()`, `flush()` | nicht geladen |
| `Backfill` | Neuaufbau in Stapeln (WP-Cron, WP-CLI oder Zeitscheiben aus der Einstellungsseite) mit Sperre | `start()`, `runBatch()`, `runAll()`, `runFor()`, `isReady()`, `isDone()`, `isStalled()` | nicht geplant |
| `Verifier` | Stichprobe (täglich) oder Vollprüfung (CLI) gegen die Beiträge, repariert Abweichungen | `verify()` | nicht geplant |
| `Cli` | `wp commonsbooking index status\|rebuild [--batch-size=<n>]\|verify [--repair] [--sample=<n>]\|drop` | – | nicht registriert |

### 3.3 `CommonsBooking\Repository\Index` – Abfrageschicht

`isAvailable()`, `findPostIds( $criteria )` (Beitragstypen, Typen, Status, Artikel, Standorte, Autoren, Zeitraum),
`loadPosts()`, `sortByDate()`. Nur benutzen, wenn `isAvailable()` wahr ist; sonst laufen die Originalabfragen.
Wird von `Repository\Timeframe`, `Booking`, `Restriction`, `BookablePost` und `AssignedTimeframes` genutzt.

### 3.4 `CommonsBooking\Service\Availability` – Vorberechnete Verfügbarkeit (Stufe 3)

| Klasse | Zweck | Wichtige öffentliche Methoden | aus |
|---|---|---|---|
| `Availability` | Einstieg, Horizont (heute − 1 bis heute + 92), Altersgrenze | `init()`, `isEnabled()`, `getHorizon()`, `getMaxAge()` | nicht geladen |
| `Schema` | Tabellen `cb_availability`, `cb_availability_access` (reiner Cache) | `install()`, `uninstall()`, `isInstalled()` | Tabellen bleiben bis `purge` |
| `Store` | Lesen/Schreiben der Paarzeilen, Konsistenz über `gen` ohne Sperren | `preload()`, `get()`, `write()`, `invalidate()`, `isUsable()`, `getFlags()` | nicht aufgerufen |
| `AccessProfile` | Zugriffsprofil = sortierte Rollen, die für ein rollenbeschränktes Paar zählen | `forCurrentUser()`, `fromRoles()`, `runAs()` | nicht aufgerufen |
| `AccessStore` | Zeilen je Paar und Zugriffsprofil, Aufräumen ungenutzter Profile | `preload()`, `get()`, `write()`, `hasRoom()`, `cleanup()` | nicht aufgerufen |
| `Invalidator` | erhöht bei jeder Indexänderung `gen` der betroffenen Paare (synchron) und stellt sie zur Neuberechnung an | `onIndexChange()`, `processQueue()` | nicht geladen |
| `Calculator` | berechnet Tage (Flags) und Slots eines Paares mit derselben Logik wie das Kalender-JSON, ohne Buchungsfenster | `recompute()`, `compute()`, `computeAccessProfile()` | nicht aufgerufen |
| `Maintenance` | stündliche Pflege, tägliche Prüfung, WP-CLI | `runCron()`, `runVerifyCron()`, `syncPairs()`, `verify()` | nicht geplant |
| `Reader` | Tagesstatus für Karte und Artikeltabelle; Fenster wird beim Lesen berechnet | `preload()`, `getDayStatuses()`, `evaluateLive()` | nicht aufgerufen (Karte/Tabelle rechnen live) |
| `SlotReader` | Slots für REST-API und GBFS aus der Spalte `slots` | `getAvailabilitySlots()` | nicht aufgerufen (API rechnet live) |
| `DayFacts` | datumsunabhängiger Tageszustand (10 Flags), aus dem Tagesraster oder aus gespeicherten Flags | `fromGrid()`, `fromFlags()`, `toDayArray()` | **auch aus aktiv** (Kalender-JSON) |
| `CalendarWindow` | datumsabhängiger Teil: angezeigter Bereich, Buchungsfenster, Startversatz | `forTimeframes()`, `mapDay()`, `isInBookingWindow()` | **auch aus aktiv** (Kalender-JSON) |
| `DayStatus` | verdichtet einen Tag zu einem Status für Karte und Tabelle | `fromDayArray()` | **auch aus aktiv** |
| `Slots` | Slot-Erzeugung für die API (live und vorberechnet), gepacktes Binärformat | `bookableSlots()`, `toApiSlot()`, `pack()`, `unpack()` | **auch aus aktiv** (Live-API) |
| `Cli` | `wp commonsbooking availability status\|rebuild\|verify [--repair] [--sample=<n>]\|drop` | – | nicht registriert |

`DayFacts`, `CalendarWindow`, `DayStatus` und `Slots` sind aus dem Originalcode herausgelöste Logik. Weil Live- und
Vorberechnungsweg dieselben Klassen benutzen, können die Ergebnisse nicht auseinanderlaufen. Abgesichert durch
`tests/php/View/CalendarSnapshotTest.php` (Ausgabe gegen 2.11.3).

### 3.5 Zugeordnete Zeitrahmen (Teil des Booster-Updates, auch ohne Booster aktiv)

| Klasse | Zweck |
|---|---|
| `Repository\AssignedTimeframes` | findet alle Zeitrahmen und Einschränkungen, die einen Artikel oder Standort steuern (Einzel-, Mehrfach-, Kategorie- und „alle“-Auswahl), gruppiert nach aktuell / zukünftig / abgelaufen. Mit Index nur schnellere Kandidatensuche |
| `View\Admin\AssignedTimeframes` | Box „Zugeordnete Zeitrahmen“ auf der Bearbeitungsseite von Artikeln und Standorten, Infobox „Zeitrahmen mit demselben Artikel und Standort“ im Zeitrahmen-Editor, Zeilenaktion „Zeitrahmen“ in den Listen |

Nur lesend, keine eigenen Daten. Entwurf: [`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md),
[`zeitrahmen-info.md`](zeitrahmen-info.md).

---

## 4. Daten

Alle Tabellen tragen das WordPress-Präfix, alle Optionen beginnen mit `commonsbooking_`. Bestehende Tabellen
(`wp_posts`, `wp_postmeta`, `wp_options`-Einträge des Originals) werden nie anders geschrieben als im Original.

### 4.1 Tabellen

| Tabelle | Schlüssel | Inhalt | Details |
|---|---|---|---|
| `cb_index` | `post_id` | eine Zeile je Zeitrahmen, Buchung, Einschränkung: Typ, Status, Autor, Artikel/Standort (bei Einzelauswahl), Zeitraum (`start_ts`, `end_ts`), Wiederholung, Raster, Buchungsregeln, Rollenbeschränkung, Einschränkungsart/-status, `source_modified_gmt`, `projector_version` | Fassung 2, Abschnitt 6 |
| `cb_index_rel` | `entity_type, entity_id, post_id` | Mehrfachzuordnungen (Listen, Kategorien, „alle“) | Fassung 2, Abschnitt 7 |
| `cb_availability` | `location_id, item_id` | je Paar: `gen`/`computed_gen`, `days_from`, `days` (Flags je Tag), `slots` (gepackt), `has_role_limit`, `computed_at`, `calc_version` | Fassung 2, Abschnitt 8 |
| `cb_availability_access` | `location_id, item_id, access_profile` | Tage rollenbeschränkter Paare je Zugriffsprofil, `last_read` für das Aufräumen | Fassung 2, Abschnitt 8.8 |

### 4.2 Versionskonstanten (bei Änderungen erhöhen)

| Konstante | Wert | Erhöhen, wenn … | Folge |
|---|---|---|---|
| `Index\Schema::SCHEMA_VERSION` | 3 | Tabellenstruktur des Index sich ändert | `dbDelta` beim nächsten Request; Zeilen bleiben gültig, kein Neuaufbau |
| `Index\Projector::PROJECTOR_VERSION` | 2 | sich die Abbildung Beitrag → Zeile ändert | vollständiger Neuaufbau |
| `Availability\Schema::SCHEMA_VERSION` | 3 | Tabellenstruktur der Vorberechnung sich ändert | Tabellen werden geleert und neu angelegt |
| `Availability\Calculator::VERSION` | 3 | `DayFacts`, Tagesraster oder Slot-Format sich ändern | alle Zeilen werden neu berechnet |

### 4.3 Optionen

| Option (Konstante) | Inhalt | autoload |
|---|---|---|
| `commonsbooking_options_advanced-options` → `booster_enabled` | Schalter (Teil der bestehenden Einstellungsgruppe) | wie bisher |
| `Booster::STATE_OPTION` | aktiv, Aktivierungszeit, bekannte Plugin-Version, Wasserstand des Wächters, letzter Neuaufbau | ja |
| `Backfill::READY_OPTION`, `CURSOR_OPTION`, `LOCK_OPTION` | Index fertig (Projektorversion), Fortschritt, Sperre | ja / nein / nein |
| `Index\Schema::SCHEMA_VERSION_OPTION`, `Availability\Schema::SCHEMA_VERSION_OPTION` | installierte Schemaversion | ja |
| `Maintenance::SYNC_OPTION` | Zeitpunkt des letzten Paarabgleichs | nein |
| `Diagnostics::ACTIVITY_OPTION`, `CHECKS_OPTION` | Aktivitätsprotokoll (30 Einträge), Prüfergebnisse | nein |

Alle außer dem Schalter stehen in `Booster::OPTIONS` und werden von `purge` entfernt.

### 4.4 Cron-Ereignisse

| Hook (Konstante) | Takt | Aufgabe |
|---|---|---|
| `Guard::CRON_HOOK` | stündlich | `deepCheck()`: abweichende Beiträge reparieren, fehlende Tabellen anlegen |
| `Backfill::CRON_HOOK` | einmalig, plant sich selbst neu | nächster Stapel des Neuaufbaus |
| `Verifier::CRON_HOOK` | täglich | Stichprobe von 200 Beiträgen gegen den Index |
| `Maintenance::CRON_HOOK` | stündlich | neue Paare, geänderte, fehlende und über 12 h alte Zeilen |
| `Maintenance::VERIFY_HOOK` | täglich | ungenutzte Zugriffsprofile entfernen, Stichprobe von 50 Zeilen prüfen |

`Booster::deactivate()` entfernt alle.

### 4.5 Filter und Aktionen

| Filter | Standard | Wirkung |
|---|---|---|
| `commonsbooking_booster_enabled` | Einstellung | Schalter überschreiben (Tests, Notfall) |
| `commonsbooking_use_index` | `true` | `false`: Originalabfragen trotz Index |
| `commonsbooking_use_availability_cache` | `true` | `false`: jeder Tag live |
| `commonsbooking_availability_horizon_days` | 92 | Länge des Horizonts |
| `commonsbooking_availability_max_age` | 86400 s | Höchstalter einer Zeile |
| `commonsbooking_availability_sync_limit` | 25 | Paare, die am Request-Ende neu berechnet werden |
| `commonsbooking_availability_finish_request` | `true` | Antwort vor der Neuberechnung senden (PHP-FPM) |
| `commonsbooking_availability_refresh_after_rebuild` | `true` in WP-Cron und WP-CLI, sonst `false` (dann per Cron) | Neuberechnung direkt nach dem Index-Neuaufbau |
| `commonsbooking_availability_access_profiles`, `…_access_profiles_max` | an, 8 | Zugriffsprofile je Paar speichern, Höchstzahl |

| Aktion (Konstante) | Parameter | Auslöser |
|---|---|---|
| `Projector::CHANGED_HOOK` (`commonsbooking_index_rows_changed`) | alte, neue Zeilen | jede Änderung einer Indexzeile; nutzt der `Invalidator` |
| `Backfill::REBUILT_HOOK` (`commonsbooking_index_rebuilt`) | – | Neuaufbau abgeschlossen |

Admin-Aktionen (`admin_post_` / `wp_ajax_`): `commonsbooking_booster_check`, `commonsbooking_booster_rebuild`,
`commonsbooking_booster_setup`; Rechte `manage_options` und Nonce (`SettingsView::authorize()`).

### 4.6 Hooks des Index

`added_post_meta`, `updated_post_meta`, `deleted_post_meta`, `transition_post_status` → Beitrag merken;
`save_post` (Priorität `PHP_INT_MAX`) → `flush()`; `deleted_post` → Zeilen sofort löschen; `shutdown` → `flush()`.
Wer Beiträge per SQL schreibt, ruft `Indexer::touch( $id )` und `Indexer::flush()` (Beispiel
`Model\Booking::cancel()`).

---

## 5. Abläufe (Kurzfassung)

| Ablauf | Kurz | Fassung 2 |
|---|---|---|
| Einschalten | Tabellen anlegen, Neuaufbau starten (8 s im selben Request, dann Einstellungsseite oder WP-Cron); bis zum Abschluss Originalabfragen | 12.1, 23 |
| Beitrag ändern | Indexer merkt, Projector schreibt im selben Request, Invalidator erhöht `gen`, Calculator rechnet am Request-Ende bis 25 Paare, Rest per Cron | 10, 11, 13 |
| Karte / Tabelle | `Reader::preload()` lädt alle Paare, Fenster beim Lesen, fehlende Zeilen werden berechnet und gespeichert | 16, 18 |
| API / GBFS | `SlotReader` aus `slots`, Rückfall auf Live-Weg außerhalb des Horizonts oder bei Mehrfachzuordnung | 17 |
| Buchen | Prüfung in `withoutBooster()` (Originalabfragen, kein Cache), Artikelsperre `GET_LOCK` 20 s | 19 |
| Downgrade erkannt | Wächter: Versionsoption fremd geschrieben oder Beiträge ohne Indexzeile → Neuaufbau | 20, 21 |

---

## 6. Eingriffe in Dateien des Originals

„ohne“ = wirkt auch bei ausgeschaltetem Booster. Jede Zeile ist im Code mit `@since 2.11.4 Booster:` gekennzeichnet.

### 6.1 Verdrahtung

| Datei | Stelle | Änderung | ohne |
|---|---|---|---|
| `src/Plugin.php` | `activation()`, `deactivation()` | Index nach Pause neu aufbauen, Cron-Jobs entfernen | – |
| | `init()` | `Booster::init()` | – |
| | `handleBookingForms()` | Prüfung in `withoutBooster()`, Weiterleitung erst danach (sonst überspringt `exit` den `finally`-Block) | – |
| `includes/OptionsArray.php` | Gruppe `booster` | Schalter und Statusbereich unter *Erweiterte Optionen* | Schalter ja |
| `templates/dashboard-index.php` | Spaltencontainer | vierte Spalte mit dem Boosterzustand (nur Admins) | ja |
| `commonsbooking.php` | Version | `2.11.4-beta.5` (Teil jedes Cache-Schlüssels) | ja |

### 6.2 Datenzugriff

| Datei | Stelle | Änderung | ohne |
|---|---|---|---|
| `src/Repository/Timeframe.php` | `get()`, `getInRange()`, neu `queryPosts()` | Datumsfilter als Indexkriterien; ohne Index Originalabfragen | – |
| | neu `getByAuthor()` | Zeitrahmen/Buchungen eines Autors für `getForUser()` | ja |
| `src/Repository/Booking.php` | `getByDate()`, `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()`, neu `findViaIndex()` | Index, sonst (oder bei nicht unterstützten `$customArgs`) `WP_Query` | – |
| | `getForUser()`, neu `getVisibleCandidates()`, `getManagedPostIds()` | nur Kandidaten statt aller Buchungen, Sichtbarkeitsfilter unverändert | ja |
| | neu `getLatestPastConfirmed()` | Kandidaten für `Item::getClosestBooking()` | – |
| `src/Repository/Restriction.php` | `get()`, neu `queryPostsFromIndex()` | aktive Einschränkungen aus dem Index | – |
| `src/Repository/BookablePost.php` | `getRelatedPosts()`, neu `getTimeframesByRelation()` | Kandidaten aus dem Index, alte Semantik (nur Einzel-ID); verknüpfte Beiträge in einer Abfrage | Massenladen ja |

### 6.3 Modelle

| Datei | Stelle | Änderung | ohne |
|---|---|---|---|
| `src/Model/Calendar.php` | `__construct()`, `getWeeks()`, neu `loadPosts()`, `$timeframes` | Laden erst bei Cache-Fehlschlag, Einschränkungen einmal je Kalender | ja |
| | `getAvailabilitySlots()`, neu `getAvailabilitySlotsLive()` | erst `SlotReader`, sonst Live-Berechnung (Logik unverändert) | Live-Weg ja |
| `src/Model/Week.php`, `src/Model/Day.php` | Konstruktoren, `getRestrictions()`, neu `isInRestriction()`, `RESTRICTION_STATUSES` | vorgeladene Einschränkungen in PHP filtern | ja |
| `src/Model/Item.php` | `getClosestBooking()` | mit Index nur Buchungen mit spätestem Start laden (vorher Speicherlimit in `vehicle_availability.json`) | – |
| `src/Model/Map.php` | `get_locations()`, neu `getBookableTimeframesByPair()` | Artikel, Zeitrahmen, Begriffe, Bilder gebündelt laden | ja |
| `src/Model/Timeframe.php` | neu `withoutBookingWindow()`, `getLatestPossibleBookingDateTimestamp()` | gespeicherte Tage unabhängig vom aktuellen Datum | – |
| | `formatBookableDate()` | Korrektur: ganzer Satz „Permanently available here“ | ja |
| `src/Model/Booking.php` | `cancel()` | nach direktem SQL `clean_post_cache()` und `Indexer::touch()/flush()` | Cache ja |

### 6.4 Anzeige

| Datei | Stelle | Änderung | ohne |
|---|---|---|---|
| `src/View/Calendar.php` | `getCalendarDataArray()`, `prepareJsonResponse()`, neu `renderJsonResponse()`, `mapDay()` (neue Signatur) | Tageslogik über `DayFacts`/`CalendarWindow`, gleiche Ausgabe | ja |
| | `renderTable()`, `renderItemLocationRow()`, `TABLE_STATUS_MARKUP` | Vorladen über `Reader`; Cache-Schlüssel `item_location_today_days`; Korrektur `<span'is-locked'>` | Korrekturen ja |
| | entfernt | `processSlot()`, `getLockedDayArray()` (protected), `getDefaultCalendarEnddateTimestamp()` (private) | – |
| `src/Map/MapItemAvailable.php` | `create_items_availabilities()`, neu `markDays()`, `STATUS_MAPPING`; entfernt `markDaysInTimeframe()` | Tagesstatus über `Reader` bzw. live über dieselbe Logik | ja |
| `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | Abholhinweise | Korrektur: keine Leerzeilen aus dem Template-Markup | ja |

### 6.5 Buchen, Cache, Admin

| Datei | Stelle | Änderung | ohne |
|---|---|---|---|
| `src/Wordpress/CustomPostType/Booking.php` | `savePost()` | Admin-Prüfung in `withoutBooster()` | – |
| | neu `withItemLock()`, `ITEM_LOCK_TIMEOUT` | Datenbanksperre je Artikel, 20 s, danach `BookingDeniedException` | ja |
| | `handleFormRequest()`, neu `processFormRequest()`, `redirectToBooking()` | aufgeteilt, damit die Weiterleitung nach `withoutBooster()` kommt | – |
| `src/Wordpress/CustomPostType/Timeframe.php` | `validateTimeFrame()` | Prüfung in `withoutBooster()` | – |
| | `filterAdminList()`, `setCustomColumnsData()`, `initHooks()` | Filter findet Listenauswahl, Spalte zeigt Listen/Kategorien/„alle“, Infobox | ja |
| `src/Wordpress/CustomPostType/Item.php`, `Location.php`, `CustomPostType.php` | `initHooks()`, `modifyRowActions()` | Box und Zeilenaktion „Zugeordnete Zeitrahmen“ | ja |
| `src/Service/BookingRuleApplied.php` | `bookingConformsToRules()` | Regel „eine unbestätigte Buchung“ lädt nur eigene Buchungen | ja |
| `src/Service/Cache.php` | `getCacheId()`, neu `getRoleContext()` | Rollen im Cache-Schlüssel (vorher konnte ein Gast die Admin-Ansicht bekommen) | ja |
| | neu `withoutCache()`, `getCacheItem()`, `setCacheItem()` | Cache vorübergehend abschalten | – |
| `includes/Public.php` | `commonsbooking_public()` | Doppelklickschutz der Buchungsformulare | ja |

### 6.6 Geänderte Schnittstellen (für Unterklassen und Fremdcode)

| Element | Änderung |
|---|---|
| `View\Calendar::mapDay()` (protected) | neue Signatur `( Day, CalendarWindow, array & )` |
| `View\Calendar::processSlot()`, `getLockedDayArray()` (protected) | entfernt (`CalendarWindow::getLockedDayArray()`) |
| `View\Calendar::renderItemLocationRow()` (protected) | zusätzlicher optionaler Parameter `?Reader` |
| `Map\MapItemAvailable::markDaysInTimeframe()` (protected) | entfernt, ersetzt durch `markDays()` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | zusätzlicher optionaler Parameter |
| `Model\Calendar::$timeframes` | jetzt `?array`, geladen in `loadPosts()` |
| neu öffentlich | `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`, `Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Cache::withoutCache()`, `Day::RESTRICTION_STATUSES`, `Timeframe::withoutBookingWindow()`, `CPT\Booking::processFormRequest()`, `redirectToBooking()` |

---

## 7. Downgrade

Ergebnis der Prüfliste aus `TECHNICAL.md`:

| Punkt | Ergebnis |
|---|---|
| Bestehende Postmeta, Optionen, Usermeta, Tabellen | nicht umgedeutet, nicht umgeschrieben, nicht gelöscht. Einzige Schreiberweiterung am Bestand: `clean_post_cache()` nach `cancel()` |
| Neue Daten | vier eigene Tabellen (4.1), eigene Optionen (4.3), Schalter in der bestehenden Einstellungsgruppe; eine ältere Version ignoriert sie |
| Schalter aus | keine Hooks, kein Cron, keine Lesezugriffe (Ausnahme: Einstellungsseite, WP-CLI `booster`). Aktiv bleiben nur die als „ohne“ markierten Änderungen aus Abschnitt 6; sie schreiben keine Daten |
| Downgrade und erneutes Upgrade | `Guard` erkennt fremd geschriebene Plugin-Version und Beiträge ohne Indexzeile → Neuaufbau; stündliche Tiefenprüfung; Zeilen älter als 24 h werden nie benutzt |
| Aufräumen | `wp commonsbooking booster purge --yes`: Tabellen, Optionen, Cron-Jobs |
| Buchungsprüfung | nie aus abgeleiteten Daten (`withoutBooster()`) |

Empfohlener Ablauf: Booster ausschalten, ältere Version einspielen, optional `purge` (vorher, solange der Booster-Code
noch da ist) oder Tabellen/Optionen von Hand löschen. Fassung 2, Abschnitt 21.

---

## 8. Erweitern und ändern

- **Posts per SQL schreiben:** danach `Indexer::touch( $id )` und `Indexer::flush()`.
- **Abbildung, Tabellen oder Tagesberechnung ändern:** passende Versionskonstante erhöhen (4.2).
- **Neue Abfrage über Zeitrahmen/Buchungen:** zuerst `Repository\Index::isAvailable()` prüfen, sonst die
  Originalabfrage; Parität mit einem Test belegen (Vorbild `tests/php/Service/Index/TimeframeIndexParityTest.php`).
- **Neue Prüfung von Buchungen:** innerhalb von `Booster::withoutBooster()` ausführen.
- **Neue Klassen:** `composer run prefix-namespaces`.
- **Konventionen und Changelog:** `TECHNICAL.md` „Conventions for changes (booster fork)“, `dev-changelog/`.

---

## 9. Tests

Die Suite läuft standardmäßig mit eingeschaltetem Booster und vollständig aufgebautem Index. Die CI wiederholt sie mit
`CB_USE_BOOSTER=0` (Original), `CB_USE_INDEX=0` (Originalabfragen) und `CB_USE_AVAILABILITY=0` (live).

| Test | prüft |
|---|---|
| `Service/Index/TimeframeIndexParityTest.php`, `Service/Booster/TimeframeVariantsParityTest.php` (+ `Fixtures/TimeframeVariants.php`) | Index und Originalabfragen liefern dieselben Beiträge, über viele Zeitrahmenvarianten |
| `Service/Index/IndexerTest.php` | Hooks, Merkliste, Löschen, direkte SQL-Schreibzugriffe |
| `Service/Availability/AvailabilityTest.php`, `AccessProfileTest.php`, `SlotsTest.php` | Vorberechnung, Invalidierung, Zugriffsprofile, Slot-Format |
| `View/CalendarSnapshotTest.php` (+ `snapshots/calendar-core.json`) | Kalender-JSON, Karte, Tabelle gegen 2.11.3; gespeichert = live = frisch berechnet |
| `Service/Booster/BoosterTest.php`, `SetupTest.php`, `DiagnosticsTest.php`, `CacheRoleContextTest.php` | Schalter, Übergänge, Wächter, Einrichtung, Diagnose, Cache-Schlüssel |
| `Wordpress/CustomPostType/BookingItemLockTest.php` | Artikelsperre |
| `Repository/AssignedTimeframesTest.php`, `View/AssignedTimeframesViewTest.php` | Zugeordnete Zeitrahmen |
| ergänzt: `Repository/BookingTest.php`, `Model/ItemTest.php`, `View/CalendarTest.php`, `Wordpress/CustomPostType/BookingTest.php` | `getForUser()`, `getClosestBooking()`, `is-locked`, Regel „eine unbestätigte Buchung“ |
