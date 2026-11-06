# Technische Dokumentation: Performance-Booster

Stand: 06.11.2026 · Branch `booster/index-availability` · Vergleichsbasis: CommonsBooking 2.11.3 (`master`, `7aa75a81`)

Diese Dokumentation richtet sich an Entwickler, die CommonsBooking 2.11.3 kennen. Sie beschreibt, was sich geändert hat,
wo die Änderungen liegen und wie die neue Logik arbeitet. Die Entscheidungen und Messungen der einzelnen Ausbaustufen
stehen in den Designnotizen:

- [`timeframe-index.md`](timeframe-index.md): Index-Tabelle (Stufe 2)
- [`availability-precompute.md`](availability-precompute.md): vorberechnete Verfügbarkeit (Stufe 3)
- [`booster.md`](booster.md): gemeinsamer Schalter, Wächter, Diagnose
- [`../TECHNICAL.md`](../TECHNICAL.md): Kurzreferenz (englisch)

---

## 1. Überblick

### 1.1 Das Problem in 2.11.3

Zeitrahmen, Buchungen und Einschränkungen sind Custom Post Types. Ihre fachlichen Daten (Artikel, Standort, Start, Ende,
Typ, Raster …) liegen in `wp_postmeta`. Jede Abfrage nach „Artikel X an Standort Y im Zeitraum Z“ muss über
`postmeta` suchen. Dafür gibt es keinen passenden Datenbankindex, die Laufzeit wächst also mit der gesamten
Buchungshistorie. Zusätzlich berechnet jede Anzeige (Karte, Artikeltabelle) für jedes Artikel/Standort-Paar jeden Tag neu
(`Model\Calendar` → `Week` → `Day::getGrid()` → `View\Calendar::processSlot()`).

Gemessen auf einem Bestand mit 17.100 Zeitrahmen/Buchungen: Karte mit Verfügbarkeit 15,4 s und 40.800 Abfragen,
`[cb_items_table]` 7,1 s. Bei zehnfacher Historie brachen manche Ansichten mit Speicherfehler ab.

### 1.2 Die Lösung in drei Bausteinen

| Baustein | Namespace | Aufgabe |
|---|---|---|
| **Index** | `Service\Index`, `Repository\Index` | Projiziert jeden Zeitrahmen, jede Buchung und jede Einschränkung in eine schmale, indizierte Tabelle. Die Repositories lesen daraus statt aus `postmeta`. |
| **Vorberechnete Verfügbarkeit** | `Service\Availability` | Speichert pro Artikel/Standort-Paar den datumsunabhängigen Zustand der nächsten 92 Tage. Karte und Artikeltabelle lesen ihn fertig aus. |
| **Booster** | `Service\Booster` | Ein gemeinsamer Schalter für beide Bausteine, Schutz vor Inkonsistenzen (Wächter), Diagnose in den Einstellungen, WP-CLI. |

### 1.3 Leitprinzipien

1. **Die Beiträge bleiben die einzige Quelle der Wahrheit.** `cb_timeframe`, `cb_booking`, `cb_restriction` und ihre
   Metadaten werden unverändert geschrieben und gelesen. Admin-Oberfläche, CMB2, REST/GBFS, Buchungscodes und Hooks
   Dritter funktionieren wie bisher. Die neuen Tabellen sind abgeleitet und lassen sich jederzeit neu aufbauen.
2. **Standardmäßig aus.** Nach dem Update verhält sich das Plugin exakt wie 2.11.3: keine Hooks, keine Cron-Jobs, kein
   Lesen aus den neuen Tabellen.
3. **Zwei Wege, ein Ergebnis.** Jede umgestellte Methode hat weiterhin ihren Originalpfad. Welcher Pfad läuft, entscheidet
   eine zentrale Abfrage (`Index::isAvailable()` bzw. `Availability::isEnabled()`). Paritätstests vergleichen beide Wege.
4. **Buchungsprüfung nie über den Booster.** Die Prüfung auf Überschneidungen und Buchungsregeln läuft immer mit den
   Originalabfragen und ohne Plugin-Cache. Ein veralteter Index kann höchstens eine falsche Anzeige verursachen, nie
   eine Doppelbuchung.
5. **Sofort aktuell.** Index und Verfügbarkeit werden im selben Request aktualisiert, in dem sich ein Beitrag ändert.
   Ein täglicher Cron reicht ausdrücklich nicht.

### 1.4 Architektur

```
                         Schreibweg                                     Leseweg
 ┌──────────────────────────────────────────┐        ┌──────────────────────────────────────────┐
 │ save_post / *_post_meta / transition /   │        │ Repository\Timeframe, Booking,           │
 │ deleted_post / Booking::cancel()         │        │ Restriction, BookablePost, Model\Map     │
 └───────────────┬──────────────────────────┘        └───────────────┬──────────────────────────┘
                 │ Post-ID als „dirty“                               │ Index::isAvailable()?
                 ▼                                                   │   ja → Repository\Index
       Service\Index\Indexer ──flush()──► Projector ──REPLACE──► cb_index / cb_index_rel
                                              │                      │   nein → Originalabfrage (postmeta)
                                              │ Hook commonsbooking_index_rows_changed (alte + neue Zeile)
                                              ▼
                               Service\Availability\Invalidator
                                   │ UPDATE gen = gen + 1  (sofort, synchron)
                                   ▼
                              cb_availability ◄── Calculator (≤ 25 Paare am Request-Ende, sonst Cron)
                                   ▲
                                   │ Store::isUsable()? ja → Tagesfakten; nein → berechnen/live
                   Service\Availability\Reader ◄── MapItemAvailable, View\Calendar::renderTable()
```

---

## 2. Neue Datenbanktabellen

Alle Tabellen werden pro Site über `$wpdb->prefix` angelegt (Multisite-fähig), per `dbDelta()` erzeugt und tragen eine
eigene Schema-Versionsoption. Es gibt keine Fremdschlüssel und keine Trigger, die Pflege erfolgt in PHP über Hooks.
Angelegt werden die Tabellen beim Einschalten des Boosters, entfernt nur mit `wp commonsbooking booster purge`.

### 2.1 `{prefix}cb_index`: eine Zeile pro Beitrag

Klasse: `Service\Index\Schema` (Schema-Version 3, Option `commonsbooking_index_schema_version`).

Enthält eine Zeile für jeden Beitrag vom Typ `cb_timeframe`, `cb_booking` und `cb_restriction` (Status `auto-draft`
ausgenommen). Die Werte stammen aus `wp_posts` und `wp_postmeta` und werden von `Service\Index\Projector` geschrieben.

#### Spalten

| Spalte | Typ | Bedeutung / Herkunft |
|---|---|---|
| `post_id` | bigint unsigned, **PK** | `wp_posts.ID` |
| `post_type` | varchar(20) | `cb_timeframe`, `cb_booking`, `cb_restriction` |
| `post_status` | varchar(20) | `publish`, `draft`, `confirmed`, `unconfirmed`, `canceled`, `trash` … |
| `post_author` | bigint unsigned | bei Buchungen der Nutzer, **für den** gebucht wurde (Buchungsregeln, „Meine Buchungen“) |
| `type` | tinyint | Meta `type`: 1 Öffnungszeiten, 2 buchbar, 3 Feiertag, 4 (Off-Holidays), 5 Reparatur, 6 Buchung, 7 stornierte Buchung. 0 bei Einschränkungen |
| `item_id` | bigint unsigned, NULL | Zeitrahmen/Buchung: der einzige Artikel. **NULL** bei Mehrfachauswahl (`is_multi = 1`). Einschränkung: Meta `restriction-item-id`, **0 = alle Artikel** |
| `location_id` | bigint unsigned, NULL | wie `item_id`, für den Standort |
| `is_multi` | tinyint(1) | 1 = mehr als ein Artikel oder Standort, Zuordnung in `cb_index_rel` |
| `start_ts` | bigint, NULL | Meta `repetition-start` bzw. `restriction-start`. NULL = kein Start gespeichert |
| `end_ts` | bigint, NULL | Meta `repetition-end` bzw. `restriction-end`. **NULL = offenes Ende** (auch bei `''`/`0`) |
| `repetition` | varchar(8), NULL | `norep`, `w`, `m`, `y`, `manual` |
| `weekdays` | tinyint unsigned | Bitmaske der Wochentage, Montag = Bit 0 … Sonntag = Bit 6 |
| `manual_dates` | text, NULL | JSON-Array `Y-m-d`, nur bei `repetition = manual` |
| `grid` | tinyint | 0 = Slot, 1 = stündlich |
| `grid_size` | tinyint, NULL | abgeleitete Rastergröße in Stunden wie `Model\Timeframe::getGridSize()` (ganztägig 24, Slot = Dauer Start bis Ende) |
| `full_day` | tinyint(1) | Meta `full-day` = `on` |
| `start_time`, `end_time` | time, NULL | Metas `start-time`, `end-time` |
| `max_days` | smallint, NULL | `timeframe-max-days` |
| `advance_days` | smallint, NULL | `timeframe-advance-booking-days` |
| `startday_offset` | smallint, NULL | `booking-startday-offset` |
| `has_role_limit` | tinyint(1) | 1, wenn `allowed_user_roles` nicht leer ist |
| `restriction_type` | varchar(10), NULL | `repair`, `hint` (nur Einschränkungen) |
| `restriction_state` | varchar(10), NULL | `none`, `active`, `solved` (nur Einschränkungen) |
| `source_modified_gmt` | datetime, NULL | `post_modified_gmt` zum Zeitpunkt der Projektion (Wächter vergleicht damit) |
| `projector_version` | smallint | `Projector::PROJECTOR_VERSION` (derzeit 2) |

Wichtige Details:

- **Zeitstempel werden unverändert übernommen.** CommonsBooking speichert „lokale Zeit als Unix-Zeitstempel“. Der Index
  rechnet keine Zeitzonen um, damit alle bestehenden Vergleiche identisch bleiben. Nicht numerische Altwerte werden wie
  in `Model\Timeframe::getStartDate()` per `strtotime()` normalisiert.
- **Zuordnung Artikel/Standort:** Die Projektion bildet die **Vereinigung** aus `item-id` und `item-id-list` (ebenso
  `location-id`/`location-id-list`). Das entspricht dem bisherigen SQL in `Repository\Timeframe::getEntityQuery()`.
- **Die Spalten ab `repetition`** werden nicht für die Suche benötigt. Sie machen die Zeile „verdeckend“: Für die
  Berechnung der Verfügbarkeit muss kein `postmeta` nachgeladen werden.

#### Schlüssel und wofür sie gebraucht werden

| Schlüssel | Spalten | Genutzt von |
|---|---|---|
| `PRIMARY` | `post_id` | Projektion (`REPLACE`), Wächter |
| `item_loc_range` | `item_id, location_id, type, end_ts, start_ts` | Paar-Abfragen (Kalender, Karte, Konfliktprüfung). `end_ts` im Schlüssel macht die Abfrage unabhängig von der Historie |
| `loc_range` | `location_id, type, end_ts` | Abfragen nur nach Standort |
| `type_range` | `type, end_ts, start_ts` | Abfragen ohne Artikel/Standort (z. B. Erinnerungs-Mails: beginnende/endende Buchungen) |
| `author_type` | `post_author, type, start_ts` | Buchungen eines Nutzers (Buchungsregeln) |
| `multi_type` | `is_multi, type` | Teilabfrage für Mehrfachzuordnungen |
| `ptype_item`, `ptype_location` | `post_type, item_id` / `post_type, location_id` | Zuordnung Artikel ↔ Standort (`getRelatedPosts`), Einschränkungen |
| `ptype_end` | `post_type, end_ts` | Einschränkungen nach Zeitraum |

### 2.2 `{prefix}cb_index_rel`: Mehrfachzuordnungen

| Spalte | Typ | Bedeutung |
|---|---|---|
| `post_id` | bigint unsigned | Beitrag mit `is_multi = 1` |
| `entity_type` | varchar(8) | `item` oder `location` |
| `entity_id` | bigint unsigned | ID des Artikels bzw. Standorts |

Schlüssel: `PRIMARY (entity_type, entity_id, post_id)`, `KEY post_id`.

Mehrfach-, Kategorie- und „Alle“-Auswahl gibt es nur bei Feiertagen (Typ 3) und Reparaturen (Typ 5). Ein Kreuzprodukt
Artikel × Standort in der Haupttabelle wäre bei „alle Artikel × alle Standorte“ (500 × 300) pro Feiertag 150.000 Zeilen.
Die Relationstabelle braucht dafür 800 Zeilen. Buchungen und buchbare Zeitrahmen (über 99 % der Zeilen) haben genau
einen Artikel und einen Standort und kommen ohne Join aus.

Abfragen kombinieren beide Fälle mit `UNION ALL` (nicht `OR`), damit beide Teile einen Schlüssel nutzen:

```sql
SELECT post_id FROM cb_index
 WHERE item_id IN (:items) AND location_id IN (:locs) AND type IN (:types) AND post_status IN (:status)
   AND (end_ts IS NULL OR end_ts >= :from) AND start_ts <= :to
UNION ALL
SELECT x.post_id FROM cb_index_rel ri
  JOIN cb_index_rel rl ON rl.post_id = ri.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (:locs)
  JOIN cb_index x      ON x.post_id  = ri.post_id
 WHERE ri.entity_type = 'item' AND ri.entity_id IN (:items) AND x.type IN (:types) …
```

### 2.3 `{prefix}cb_availability`: vorberechneter Tageszustand pro Paar

Klasse: `Service\Availability\Schema` (Schema-Version 2, Option `commonsbooking_availability_schema_version`).
Die Tabelle ist ein reiner Cache. Bei einer Strukturänderung wird sie verworfen und neu angelegt.

Eine Zeile pro Artikel/Standort-Paar, das einen buchbaren Zeitrahmen (Typ 2) hat. Die Tage stehen gepackt in einer
Binärspalte, Lesen und Schreiben eines Paares ist damit je eine Anweisung.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `item_id`, `location_id` | bigint unsigned, **PK** `(location_id, item_id)` | das Paar |
| `gen` | int unsigned | Generation. Wird bei **jeder** Änderung, die das Paar betrifft, synchron um 1 erhöht |
| `computed_gen` | int unsigned | Generation, aus der `days` berechnet wurde |
| `days_from` | date, NULL | erster Tag in `days` (heute − 1 zum Berechnungszeitpunkt) |
| `days` | varbinary(1024), NULL | 2 Byte pro Tag (unsigned short, little endian), bis 512 Tage. Geschrieben per `UNHEX()`, weil `wpdb` sonst Bytes entfernen kann, die es für ungültigen Text hält |
| `has_role_limit` | tinyint(1) | Paar hat Zeitrahmen mit Rollenbeschränkung, wird immer live berechnet |
| `computed_at` | datetime (UTC) | Zeitpunkt der Berechnung |
| `calc_version` | smallint | `Calculator::VERSION` (derzeit 2) |
| `slots` | mediumblob, NULL | freie buchbare Slots je Tag für API und GBFS (Format siehe 6.6). NULL bei Rollenbeschränkung. Wird nur von `SlotReader` und dem Verifier gelesen, `Store::preload()` lädt die Spalte nicht |

Schlüssel: `PRIMARY (location_id, item_id)`, `KEY item_id`, `KEY computed_at` (Cron sucht alte Zeilen).

#### Gültigkeit einer Zeile (`Store::isUsable()`)

Eine Zeile wird nur verwendet, wenn **alle** Bedingungen gelten:

1. `days` und `days_from` sind gesetzt,
2. `has_role_limit = 0`,
3. `computed_gen = gen` (keine Änderung seit der Berechnung),
4. `calc_version = Calculator::VERSION`,
5. `computed_at` ist jünger als 24 Stunden (Filter `commonsbooking_availability_max_age`),
6. die angefragten Tage liegen innerhalb von `days` (sonst wird live gerechnet).

Damit hängt die Korrektheit **nicht** vom Zeitpunkt der Neuberechnung ab: Eine veraltete Zeile wird nie ausgeliefert.

#### Tagesfakten (Bitmaske pro Tag, `Service\Availability\DayFacts`)

| Bit | Wert | Konstante | Bedeutung (Herkunft im alten `processSlot()`/`mapDay()`) |
|---|---|---|---|
| 0 | 1 | `HAS_SLOTS` | Der Tag hat Slots (`! $noSlots`) |
| 1 | 2 | `SLOT_LOCKED` | mindestens ein Slot gesperrt (Feiertag, Reparatur, Buchung) |
| 2 | 4 | `ALL_LOCKED` | alle Slots gesperrt (`$allLocked`) |
| 3 | 8 | `HOLIDAY` | Feiertag |
| 4 | 16 | `REPAIR` | Reparatur |
| 5 | 32 | `BOOKED_DAY` | `bookedDay` |
| 6 | 64 | `PARTIALLY_BOOKED` | `partiallyBookedDay` |
| 7 | 128 | `FIRST_SLOT_BOOKED` | `firstSlotBooked` |
| 8 | 256 | `LAST_SLOT_BOOKED` | `lastSlotBooked` |
| 9 | 512 | `FULL_DAY` | genau ein Slot eines Ganztags-Zeitrahmens |

Gespeichert wird nur, was aus den **Beiträgen** folgt. Was vom **heutigen Datum** abhängt (Vorausbuchungstage,
Startversatz, vergangene Tage, nächster buchbarer Zeitrahmen), wird bei jedem Lesen neu angewendet
(`CalendarWindow`). Deshalb ist beim Tageswechsel keine Neuberechnung nötig. Nicht gespeichert werden die Slots mit
ihren Zeiten, die braucht nur der Buchungskalender der Artikelseite, der weiter live rechnet.

### 2.4 Neue Optionen und Cron-Ereignisse

| Option | autoload | Inhalt |
|---|---|---|
| `commonsbooking_options_advanced-options[booster_enabled]` | (bestehend) | Schalter (`on`) |
| `commonsbooking_booster_state` | ja | `active`, `activated_at`, `plugin_version`, `watermark` (höchste geprüfte Post-ID) |
| `commonsbooking_index_schema_version`, `commonsbooking_availability_schema_version` | ja | installierte Tabellenstruktur |
| `commonsbooking_index_ready` | ja | Projector-Version des vollständigen Backfills |
| `commonsbooking_index_backfill_cursor` | – | Fortschritt des Backfills |
| `commonsbooking_availability_pairs_synced` | – | letzter Abgleich der Paarliste |
| `commonsbooking_booster_checks` | nein | letzter Lauf und Ergebnis jeder Prüfung |
| `commonsbooking_booster_activity` | nein | Ringpuffer der letzten 30 Index-Änderungen (Diagnose) |

| Cron-Hook | Rhythmus | Klasse | Aufgabe |
|---|---|---|---|
| `commonsbooking_index_backfill` | plant sich selbst neu (20 s pro Lauf, 500 Beiträge pro Stapel) | `Index\Backfill` | Index-Aufbau |
| `commonsbooking_index_verify` | täglich 04:00 | `Index\Verifier` | Stichprobe von 200 Beiträgen gegen die Projektion, repariert |
| `commonsbooking_booster_guard` | stündlich | `Booster\Guard` | Tiefenprüfung Status/Änderungsdatum |
| `commonsbooking_availability_refresh` | stündlich (+ bei Bedarf) | `Availability\Maintenance` | neue Paare, geänderte und über 12 h alte Zeilen neu berechnen |
| `commonsbooking_availability_verify` | täglich 04:30 | `Availability\Maintenance` | Stichprobe von 50 Paaren gegen frische Berechnung, repariert |

Ausschalten des Boosters und Deaktivieren des Plugins entfernen alle Cron-Ereignisse.

---

## 3. Der Schalter: `Service\Booster\Booster`

### 3.1 Zustände

| Zustand | Bedingung | Verhalten |
|---|---|---|
| **aus** (Standard) | Einstellung nicht `on` | `Booster::init()` registriert nur die Einstellungsseite und WP-CLI. Kein Hook, kein Cron, kein Lesen. Code-Pfade identisch zu 2.11.3. |
| **im Aufbau** | an, Backfill läuft | Hooks schreiben bereits in den Index, gelesen wird weiter über die Originalabfragen |
| **aktiv** | an, Backfill fertig | Repositories lesen aus dem Index, Karte und Tabelle aus `cb_availability` |
| **umgangen** | innerhalb von `Booster::withoutBooster()` | Originalabfragen, kein Plugin-Cache; Schreiben in den Index läuft weiter |

Entscheidende Abfragen:

```php
Booster::isEnabled()   // Einstellung + Filter commonsbooking_booster_enabled
Booster::canRead()     // an, nicht umgangen, state['active']
Backfill::isReady()    // canRead() + Filter commonsbooking_use_index + Schema installiert + Backfill fertig
Index::isAvailable()   // = Backfill::isReady()   → Repositories
Availability::isEnabled() // Filter commonsbooking_use_availability_cache + Schema + Backfill::isReady() → Karte/Tabelle
```

### 3.2 Lebenszyklus

- **Einschalten** (Einstellung, `wp commonsbooking booster enable [--now]`, Plugin-Aktivierung): `Booster::onInit()`
  (auf `init` mit Priorität 0) erkennt den Wechsel und ruft `activate()` auf: Tabellen anlegen, **Index immer komplett
  neu aufbauen** (während der Booster aus war, hat niemand den Index gepflegt), Zustand speichern.
- **Ausschalten:** `deactivate()` setzt den Backfill zurück (Index gilt als veraltet), entfernt die Cron-Jobs. Die
  Tabellen bleiben, ein Wiedereinschalten braucht nur ein Neubefüllen.
- **Plugin-Aktivierung/-Deaktivierung:** `Plugin::activation()` ruft `Booster::onPluginActivation()` (Zustand auf
  inaktiv, bei eingeschaltetem Booster Neuaufbau), `Plugin::deactivation()` ruft `onPluginDeactivation()`.
- **Entfernen:** `wp commonsbooking booster purge` löscht Tabellen, Optionen und Cron-Jobs.

### 3.3 Wächter gegen Änderungen ohne Booster (`Service\Booster\Guard`)

Szenario: Downgrade auf eine Version ohne Booster, dort werden Buchungen angelegt oder storniert, danach wieder Upgrade.
Die alte Version hinterlässt keine Markierung, der Wächter erkennt die Folgen in den Daten.

| Prüfung | Wann | Erkennt | Folge |
|---|---|---|---|
| Plugin-Version | jede Anfrage | `commonsbooking_plugin_version` wurde von anderem Code geändert (eigene Schreibvorgänge merkt sich der Booster über `update_option_…`) | Neuaufbau |
| neue Beiträge ohne Indexzeile | jede Anfrage, nur IDs oberhalb `watermark`, erst nach 2 min Wartezeit | Beiträge, die eine andere Version angelegt hat | Neuaufbau |
| Status und Änderungsdatum | stündlich, `booster check --deep` | Stornierungen/Änderungen der anderen Version, gelöschte Beiträge | einzeln reparieren, ab 200 Abweichungen Neuaufbau |

Reine Meta-Änderungen ohne Speichern des Beitrags findet erst der tägliche Index-Verifier.

---

## 4. Index: Schreibweg

### 4.1 Komponenten

- **`Service\Index\Indexer`** registriert die Hooks, sammelt betroffene Post-IDs pro Request (dedupliziert) und
  schreibt sie mit `flush()`.
- **`Service\Index\Projector`** liest einen Beitrag mit allen Metadaten, baut die Zeile und die Relationen und schreibt
  sie per `REPLACE INTO` bzw. Löschen + `INSERT IGNORE`. Hat sich die Projektion geändert (ohne Verwaltungsspalten),
  feuert er `commonsbooking_index_rows_changed` mit **alter und neuer** Zeile. Die alte Zeile ist wichtig: Wird eine
  Buchung auf einen anderen Artikel verschoben, muss auch das alte Paar neu berechnet werden.
- **`Service\Index\Backfill`** baut den Index stapelweise auf (Cron oder CLI) und feuert am Ende
  `commonsbooking_index_rebuilt`.
- **`Service\Index\Verifier`** vergleicht gespeicherte Zeilen mit einer frischen Projektion und repariert.

### 4.2 Hooks

| Auslöser | Hook | Aktion |
|---|---|---|
| Beitrag gespeichert | `save_post`, Priorität `PHP_INT_MAX` (nach `Timeframe::savePost` mit 11, das Metadaten umschreibt und ungültige Zeitrahmen auf `draft` setzt) | markieren und sofort `flush()` |
| Metadaten (CMB2, Importer, `update_post_meta`) | `added_post_meta`, `updated_post_meta`, `deleted_post_meta`, nur relevante Schlüssel | markieren |
| Statuswechsel, Papierkorb | `transition_post_status` | markieren |
| Löschen | `deleted_post` | Zeile und Relationen entfernen |
| Auffangnetz | `shutdown`, Priorität 0 | `flush()` (läuft auch nach Fatal Errors) |
| Stornierung | **`Model\Booking::cancel()`** setzt den Status per direktem SQL, ohne Post-Hooks | explizit `Indexer::touch()` + `flush()` (siehe 7.1) |

Relevante Meta-Schlüssel: `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`, `repetition-start`,
`repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`, `start-time`,
`end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`, `allowed_user_roles`,
`restriction-*`. Alle anderen (z. B. `_edit_lock`, Buchungscodes) lösen nichts aus.

**Regel für neuen Code:** Wer Beiträge dieser drei Typen **ohne** WordPress-Hooks schreibt (direktes SQL), muss
`Indexer::touch( $postId )` und `Indexer::flush()` aufrufen.

### 4.3 Read-your-writes

`Repository\Index::findPostIds()` und `Availability\Reader` rufen vor dem Lesen `Indexer::flush()` auf. Änderungen des
laufenden Requests sind damit sofort sichtbar, auch wenn `save_post` noch nicht durchgelaufen ist.

---

## 5. Index: Leseweg und umgestellte Repositories

### 5.1 Neue Abfrageschicht `Repository\Index`

```php
Index::isAvailable(): bool
Index::findPostIds( array $criteria ): int[]      // sortiert nach post_id
Index::loadPosts( int[] $ids ): WP_Post[]         // _prime_post_caches + update_meta_cache gebündelt
Index::sortByDate( array $posts, 'ASC'|'DESC' )   // wie WP_Query: post_date, bei Gleichstand ID aufsteigend
```

Kriterien: `post_types`, `types`, `statuses`, `items`, `locations`, `start_min`, `start_max`, `end_min`, `end_max`,
`end_exact`, `require_end`, `authors`, `restriction_states`. Leere Listen bedeuten „kein Filter“.
`start_min`/`start_max` verlangen einen gesetzten Start (entspricht dem alten SQL, das den Meta-Schlüssel voraussetzte),
`end_min`/`end_max` lassen ein offenes Ende zu.

Das gebündelte Laden ersetzt die früheren Einzelaufrufe von `get_post()` (im Kartenszenario über 17.000 Abfragen).

### 5.2 Zuordnung alt → neu

Jede Methode prüft zuerst `Index::isAvailable()`. Ist der Index nicht verfügbar, läuft der unveränderte Originalcode.

| Datei / Methode | 2.11.3 | Jetzt (Booster aktiv) |
|---|---|---|
| `Repository\Timeframe::get()`, `getInRange()` und damit `getBookable*`, `getInRangeForCurrentUser` | `getPostIdsByType()` (Full Scan `postmeta`) + `getPostsByBaseParams()` | neue private Methode `queryPosts()`: Datums-/Zeitraumfilter direkt in SQL über `Index::findPostIds()`. Historische Buchungen werden nicht mehr geladen. PHP-Filter (Wochentage, `isBookable`, Rollen) und Cache unverändert |
| `Repository\Booking::getByDate()`, `getByTimerange()` (→ `getExistingBookings`, `getByRestriction`, Buchungsregeln), `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` mit bis zu 5 Meta-Joins | neue private Methode `findViaIndex()`. Unterstützt `$customArgs` `author`, `post_status`, `orderby=date`, `order`; bei jedem anderen Argument automatisch die `WP_Query`. Sortierung wie `WP_Query` (`post_date DESC`) |
| `Repository\Restriction::get()` | `queryPosts()` per SQL über `postmeta` | `queryPostsFromIndex()` mit `restriction_state = active`. PHP-Filter für Artikel/Standort (inkl. „alle“) unverändert |
| `Repository\BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `WP_Query` mit Meta-Abfrage `{type}-id` | `getTimeframesByRelation()`: Kandidaten aus dem Index, danach Filter auf den **einzelnen** `*-id`-Wert (alte Semantik), verwandte Beiträge gebündelt geladen |
| `Model\Map::get_locations()` | pro Paar `Timeframe::getBookableForCurrentUser()` | **eine** Abfrage für alle Paare (`Map::getBookableTimeframesByPair()`), Artikel pro Standort, Term-Cache und Vorschaubilder gebündelt. Diese Bündelung gilt auch ohne Booster |

`Repository\Booking::getForUser()` („Meine Buchungen“, auch ohne Booster): lädt nicht mehr alle Buchungen, sondern
die Vereinigung aus eigenen Buchungen (`Timeframe::getByAuthor()`, mit Index über das Kriterium `authors`) und den
Buchungen der verwalteten Artikel und Standorte (Autor oder Admin-Meta, serialisiert oder als einzelner String).
CB-Admins erhalten wie bisher alle Buchungen. `commonsbooking_isUserAllowedToSee()` läuft weiterhin über die
Kandidaten, das Ergebnis ist identisch. `BookingRuleApplied` fragt nur noch die eigenen unbestätigten Buchungen ab
(`getForUserPaginated()`).

Nicht umgestellt: Admin-Listen, `Booking::getForUserPaginated()`.

### 5.3 Einschränkungen pro Zeitraum statt pro Tag

Dies gilt **unabhängig vom Booster**:

- `Model\Calendar` lädt die aktiven Einschränkungen der gesamten Range einmal (`Restriction::get()` mit
  `minTimestamp = Start − 1`) und reicht sie über `Week` an jeden `Day` weiter (neuer optionaler Konstruktorparameter
  `$possibleRestrictions`).
- `Day::getRestrictions()` filtert die vorgeladene Liste mit `isInRestriction()`, das dieselbe Datumsbedingung wie die
  bisherige Abfrage pro Tag nachbildet. Ohne vorgeladene Liste (`null`, direkte Nutzung von `Day`) bleibt es bei der
  Abfrage.
- Neue Konstante `Day::RESTRICTION_STATUSES = [ 'publish', 'confirmed', 'unconfirmed' ]`.

Effekt: 3.000–6.000 Einzelabfragen weniger pro Karten- bzw. Tabellenaufruf.

---

## 6. Vorberechnete Verfügbarkeit

### 6.1 Refactoring des Rechenkerns (`View\Calendar`)

Größtes Risiko einer Vorberechnung ist, dass gespeicherte und live berechnete Tage auseinanderlaufen. Deshalb wurde die
Berechnung aus `View\Calendar` in drei Klassen ausgelagert, die **beide Wege** benutzen:

| Neue Klasse | Übernimmt aus 2.11.3 | Aufgabe |
|---|---|---|
| `Availability\DayFacts` | `processSlot()` (alle Slots eines Tages) | datumsunabhängige Tagesfakten aus dem Slot-Raster (`fromGrid()`), Packen/Entpacken als Bitmaske (`fromFlags()`, `getFlags()`), Tages-Array (`toDayArray()`) |
| `Availability\CalendarWindow` | Fensterlogik aus `getCalendarDataArray()`, Standardwerte aus `prepareJsonResponse()`, Fensterregeln aus `mapDay()`, `getLockedDayArray()` | Start/Ende, Vorausbuchungstage, letzter buchbarer Tag, erster buchbarer Tag, vergangene Tage; `mapDay()` wendet das Fenster auf die Fakten an |
| `Availability\DayStatus` | Statuslogik, die es vorher doppelt gab (`MapItemAvailable::markDaysInTimeframe()` und `renderItemLocationRow()`) | ein Status pro Tag: `no-slots`, `holiday`, `booked`, `partially-booked-start`, `partially-booked-end`, `locked`, `available` |

Änderungen in `View\Calendar`:

- entfallen: `processSlot()`, `getLockedDayArray()`, `getDefaultCalendarEnddateTimestamp()`;
- `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )` mit neuer Signatur;
- neu: `renderJsonResponse()`; `prepareJsonResponse()` behält seine öffentliche Signatur;
- `renderTable()`/`renderItemLocationRow()` lesen über den `Reader` (neuer optionaler Parameter `?Reader $reader`).

`MapItemAvailable`: `markDaysInTimeframe()` wird durch `markDays()` und die Tabelle `STATUS_MAPPING`
(`DayStatus` → Kartenstatus) ersetzt. Die Karte berechnet außerdem nur noch die **angezeigten** Tage (vorher 60 statt 12).

Eigenheiten des Originals wurden bewusst übernommen (z. B. `repair` wird von jedem Slot zurückgesetzt, 0
Vorausbuchungstage gelten als „nicht gesetzt“). Ein Snapshot-Test hält die Ausgabe von 2.11.3 fest (siehe 10).

### 6.2 Lesen: `Availability\Reader`

```php
$reader = new Reader();
$reader->preload( [ [ $itemId, $locationId ], … ] );    // Zeilen + buchbare Zeitrahmen aller Paare in je einer Abfrage
$reader->getDayStatuses( $itemId, $locationId, $start, $end, $keepDaterange, $rangeFrom, $rangeTo );
Reader::evaluateLive( … );                               // Originalweg über Calendar::getCalendarDataArray()
```

Ablauf pro Paar:

1. `CalendarWindow::forTimeframes()` bestimmt das Fenster aus den buchbaren Zeitrahmen und dem **heutigen** Datum.
2. Für die Tage im Buchungsfenster werden Fakten benötigt:
   - Zeile gültig (`Store::isUsable()`) → Fakten aus der Bitmaske;
   - Zeile ungültig/fehlt, Bereich im Horizont → `Calculator` berechnet das Paar, schreibt die Zeile, nutzt das Ergebnis;
   - Rollenbeschränkung oder Bereich über den Horizont hinaus → `evaluateLive()`.
3. `CalendarWindow::mapDay()` wendet das Fenster an, `DayStatus::fromDayArray()` liefert den Status.

Verwendet von `MapItemAvailable::create_items_availabilities()` und `View\Calendar::renderTable()`, wenn
`Availability::isEnabled()`. **Nicht** verwendet von: Buchungskalender der Artikelseite (braucht Slots),
Buchungsprüfung. API und GBFS lesen über `SlotReader` (6.6).

### 6.3 Berechnen: `Availability\Calculator` und `Store`

- `Calculator::compute()` berechnet die Tage `heute − 1` bis `heute + 92` (Filter
  `commonsbooking_availability_horizon_days`) über `Model\Calendar` und `DayFacts::fromGrid()`, innerhalb von
  `Plugin::withoutCache()`, damit keine zwischengespeicherten Wochen aus der Zeit vor einer Änderung einfließen.
- `Store::write()` schreibt optimistisch:

  ```sql
  UPDATE cb_availability SET computed_gen = gen, days_from = ?, days = UNHEX('…'), slots = UNHEX('…'),
         has_role_limit = ?, computed_at = ?, calc_version = ?            -- computed_at: gmdate(), also UTC
   WHERE location_id = ? AND item_id = ? AND gen = G
  ```

  `G` ist die vor der Berechnung gelesene Generation. Hat eine parallele Buchung `gen` inzwischen erhöht, trifft das
  `UPDATE` nichts, die Zeile bleibt ungültig. So entsteht ohne Sperren kein Zeitfenster, in dem veraltete Daten gültig
  erscheinen.

### 6.4 Invalidieren: `Availability\Invalidator`

Hört auf `commonsbooking_index_rows_changed` (alte + neue Zeile) und `commonsbooking_index_rebuilt` (alle Paare).

1. **Betroffene Paare bestimmen** (`getScope()`): einzelnes Paar, bei Mehrfachauswahl alle Paare aus den
   Relationszeilen, bei Einschränkungen mit 0 alle Paare des Artikels bzw. Standorts.
2. **Filtern:** nur wirksame Status (Zeitrahmen `publish`, Buchungen `confirmed`, Einschränkungen
   `Day::RESTRICTION_STATUSES`) und nur, wenn der Zeitraum den Horizont (mit 2 Tagen Puffer) berührt. Einschränkungen
   ohne Start lösen nichts aus. Das Aufräumen alter Buchungen invalidiert also nichts.
3. **Synchron** im selben Request: `UPDATE … SET gen = gen + 1 WHERE …` (unter 1 ms).
4. **Neu berechnen:** Bis 25 Paare (Filter `commonsbooking_availability_sync_limit`) am Request-Ende auf `shutdown`,
   unter PHP-FPM nach `fastcgi_finish_request()`, also nach Auslieferung der Antwort (Filter
   `commonsbooking_availability_finish_request`). Größere Änderungen (z. B. Feiertag für alle Standorte) nur
   invalidieren, der Cron arbeitet nach, Leser berechnen fehlende Paare selbst.

Ergebnis: Eine Buchung ist beim nächsten Seitenaufruf sichtbar. Die Neuberechnung des Paares (ca. 13 ms) merkt der
Nutzer nicht.

### 6.5 Betrieb: `Availability\Maintenance`

- stündlich: Zeilen für neue Paare anlegen, Paare ohne buchbaren Zeitrahmen entfernen (einmal täglich), geänderte und
  über 12 h alte Zeilen neu berechnen (20 s Budget, danach neu eingeplant). Damit wandert der Horizont mit;
- täglich: Stichprobe von 50 Paaren gegen eine frische Berechnung, Abweichungen reparieren;
- Zeilen älter als 24 h werden nie verwendet. Das begrenzt die Wirkung von Schreibvorgängen an den Hooks vorbei.


### 6.6 API und GBFS: `Availability\SlotReader`

`Model\Calendar::getAvailabilitySlots()` (REST `/availability`, GBFS Vehicle/Station Status) fragt zuerst
`SlotReader::getAvailabilitySlots()`. Liefert der `null`, rechnet wie bisher `getAvailabilitySlotsLive()`. Der Kalender
lädt Zeitrahmen und Einschränkungen erst beim Live-Rechnen (`loadPosts()`), nicht mehr im Konstruktor.

**Speichern:** `Calculator::compute()` sammelt aus demselben Raster, aus dem die Tagesfakten entstehen, die Slots,
die die Live-Methode ausgeben würde (`Slots::bookableSlots()`: höchstpriorer Slot ist buchbar, nicht gebucht, nicht
eingeschränkt). Gepackt (`Slots::pack()`, little endian):

```
Kopf:    uint16 Anzahl Zeitrahmen, je Zeitrahmen uint32 ID, uint32 Standort, uint32 Artikel
je Tag:  uint8 Anzahl Slots, je Slot uint32 Start, uint32 Ende (Sekunden ab Tagesbeginn), uint16 Zeitrahmen-Index
```

Die Prüfung „erster buchbarer Tag“ (`getFirstBookableDay()`, hängt vom heutigen Datum ab) passiert beim **Lesen**,
`ignoreStartDayOffset` ist deshalb nur ein Schalter und keine eigene Variante.

**Lesen:** Paare aus dem Index (buchbare Zeitrahmen der Artikel im Bereich, optional nach Standort gefiltert), Zeilen
mit einer Abfrage (`Store::loadWithSlots()`), ungültige Zeilen werden wie beim `Reader` sofort berechnet. Pro Tag
werden die Slots aller Paare nach Startzeit sortiert und wie live dedupliziert.

**Live gerechnet** (`null`) wird bei: Vorberechnung aus, `ignoreRestrictions` (GBFS `isCurrentlyFreeAtLocation(…, true)`),
keinem Artikelfilter, Bereich außerhalb des Horizonts, Zeitrahmen mit mehreren Artikeln/Standorten, Rollenbeschränkung.

**Abweichungen vom Live-Weg** (beide sind Fehler des Live-Wegs, per `SlotsTest::testKnownLiveDeviations()` festgehalten):

- Kalender ohne Standortfilter (`/availability`): live findet `Restriction::get( [], [ $item ] )` keine Einschränkungen,
  die Artikel **und** Standort tragen; Reparaturtage erscheinen als frei. Gespeichert wirken sie. Außerdem ist das
  Ergebnis die Vereinigung der Paare statt eines gemeinsamen Rasters; gleich, solange ein Artikel keine sich
  überschneidenden Zeitrahmen an verschiedenen Standorten hat.
- Tage nach dem Enddatum (der Kalender läuft immer bis Sonntag): live kennt dort nur die Zeitrahmen, die den
  angefragten Bereich überlappen; eine Buchung, die danach beginnt, fehlt. Gespeichert wird sie berücksichtigt.

Der Verifier vergleicht in der Stichprobe zusätzlich die Slots (`Maintenance::matches()`).

---

## 7. Änderungen an der Buchungslogik und Konsistenz

### 7.1 `Model\Booking::cancel()`

Die Stornierung setzt `post_status` weiterhin per direktem SQL. Neu danach:

```php
clean_post_cache( $this->post->ID );               // vorher lieferte get_post() im selben Request noch den alten Status
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( … 'cancellation_time' … );
\CommonsBooking\Service\Index\Indexer::flush();
```

`clean_post_cache()` behebt nebenbei einen Fehler aus 2.11.3: Nachfolgende Lesezugriffe im selben Request sahen die
Buchung noch als bestätigt. Betrifft Admin-Statuswechsel, `Restriction::cancelBookings()` und die Datenschutz-Löschung.

### 7.2 Buchungsprüfung immer über den Originalweg

`Booster::withoutBooster( callable )` erhöht einen Zähler, der `Booster::canRead()` (und damit `Index::isAvailable()`)
auf `false` setzt, und ruft den Callback innerhalb von `Plugin::withoutCache()` auf. Eingesetzt an:

| Stelle | Datei |
|---|---|
| Frontend-Buchungsformular | `Plugin::handleBookingForms()` um `Booking::handleFormRequest()` |
| Admin: Buchung bestätigen | `Wordpress\CustomPostType\Booking` um `$booking->isValid()` |
| Prüfung beim Speichern eines Zeitrahmens | `Wordpress\CustomPostType\Timeframe` um `$timeframe->isValid()` |

Der Plugin-Cache wird ebenfalls umgangen, weil er Ergebnisse enthalten kann, die aus dem Index stammen.

### 7.3 Sperre gegen Doppelbuchungen (auch ohne Booster wirksam)

Bei großer Historie dauert die Originalprüfung einige Sekunden. Ein zweiter Klick konnte in dieser Zeit eine zweite
Buchung desselben Slots anlegen (Fehler bestand schon in 2.11.3).

- **Server:** `Wordpress\CustomPostType\Booking::withItemLock()` legt um Prüfung und Speichern eine MySQL-Sperre
  `GET_LOCK('cb_booking_{blog}_{item}', 20)`. Anfragen für denselben Artikel laufen nacheinander. Nach 20 s Wartezeit
  folgt eine `BookingDeniedException` mit Hinweis. Unterstützt die Datenbank keine Sperren, läuft es ohne.
- **Browser:** Ein Inline-Skript in `includes/Public.php` sperrt `#booking-form` und `[id^=cb-booking-form-set-]` nach
  dem ersten Absenden („Bitte warten …“), außer für den Kalender-Download, und gibt die Formulare bei Rückkehr aus dem
  Back-Forward-Cache wieder frei.

---

## 8. Plugin-Cache (`Service\Cache`)

Beide Änderungen gelten auch ohne Booster.

- **Neu `Plugin::withoutCache( callable )`:** liest und schreibt währenddessen keine Cache-Einträge
  (`getCacheItem()` liefert `false`, `setCacheItem()` schreibt nicht).
- **Rollenkontext im Cache-Schlüssel:** `getCacheId()` enthält jetzt `getRoleContext()` (Gast bzw. sortierte Rollen
  plus Admin-Kennzeichen). Zeitrahmen können auf Benutzerrollen beschränkt sein; bisher konnte ein Gast die
  zwischengespeicherte Anzeige eines Admins erhalten und umgekehrt. Nutzer mit denselben Rollen teilen sich weiterhin
  einen Eintrag. Folge: Es gibt etwas mehr Cache-Einträge als vorher.

Der Symfony-Cache bleibt mit Booster eingeschaltet; Karte, Tabelle, API und GBFS profitieren kaum noch, der
Buchungskalender der Artikelseite aber weiterhin.

---

## 9. Übersicht der geänderten bestehenden Dateien

| Datei | Änderung | gilt ohne Booster |
|---|---|---|
| `commonsbooking.php` | Version `2.11.4-beta.1`. Eine von 2.11.3 abweichende Nummer ist nötig, damit die Versionsprüfung des Wächters einen Wechsel zwischen den Versionen erkennt | ja |
| `includes/OptionsArray.php` | Gruppe „Performance booster“ unter Erweitert: Checkbox `booster_enabled`, Statusfeld mit `SettingsView::render` | ja (Anzeige) |
| `includes/Public.php` | Skript gegen doppeltes Absenden | ja |
| `src/Plugin.php` | `Booster::init()` in `init()`, Aktivierung/Deaktivierung, `withoutBooster()` in `handleBookingForms()` | nur Aufrufe |
| `src/Service/Cache.php` | `withoutCache()`, Rollenkontext im Schlüssel | ja |
| `src/Model/Booking.php` | `cancel()`: `clean_post_cache`, `Indexer::touch/flush` | `clean_post_cache` ja |
| `src/Model/Calendar.php`, `Week.php`, `Day.php` | Einschränkungen pro Range vorladen | ja |
| `src/Model/Map.php` | Bündeln, `getBookableTimeframesByPair()` | ja |
| `src/Map/MapItemAvailable.php` | `Reader`/`DayStatus`, nur angezeigte Tage | Statuslogik und Tagesbereich ja |
| `src/View/Calendar.php` | Rechenkern ausgelagert, Tabelle über `Reader` | Refactoring ja |
| `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | Indexpfad neben Originalpfad | Originalpfad unverändert; `BookablePost` lädt verwandte Beiträge gebündelt |
| `src/Wordpress/CustomPostType/Booking.php` | `withItemLock()`, `withoutBooster()` bei Admin-Bestätigung | Sperre ja |
| `src/Wordpress/CustomPostType/Timeframe.php` | `withoutBooster()` um `isValid()` | – |
| `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | Abholhinweise: `pre-wrap` nur noch auf dem Text, nicht auf dem Template-Markup (unabhängige Darstellungskorrektur) | ja |
| `languages/*` | neue Texte, deutsche Übersetzung | ja |
| `.github/workflows/phpunit.yml`, `phpstan*.neon` | zusätzliche CI-Läufe, WP_CLI ignorieren | – |

Neue Klassen (nach dem Anlegen weiterer Klassen unter `src/` ist `composer prefix-namespaces` nötig, das Plugin lädt nur
den Strauss-Autoloader in `vendor-prefixed/`):

```
src/Repository/Index.php
src/Service/Index/        Schema, Projector, Indexer, Backfill, Verifier, Cli
src/Service/Availability/ Availability, Schema, Store, Calculator, Reader, Invalidator, Maintenance,
                          DayFacts, DayStatus, CalendarWindow, Cli
src/Service/Booster/      Booster, Guard, Diagnostics, SettingsView, Cli
```

---

## 10. Betrieb und Werkzeuge

### 10.1 WP-CLI

```bash
wp commonsbooking booster status|enable [--now]|disable|rebuild|check [--deep] [--dry-run]|purge
wp commonsbooking index status|rebuild|verify [--repair] [--sample=<n>]|drop             # nur bei eingeschaltetem Booster
wp commonsbooking availability status|rebuild|verify [--repair] [--sample=<n>]|drop      # nur bei eingeschaltetem Booster
```

### 10.2 Filter

| Filter | Standard | Wirkung |
|---|---|---|
| `commonsbooking_booster_enabled` | Einstellung | Booster an/aus |
| `commonsbooking_use_index` | `true` | `false`: Repositories lesen über `postmeta` (Vorberechnung dann ebenfalls aus) |
| `commonsbooking_use_availability_cache` | `true` | `false`: Karte/Tabelle rechnen jeden Tag live |
| `commonsbooking_availability_horizon_days` | 92 | Vorberechnungszeitraum |
| `commonsbooking_availability_max_age` | 86400 | maximales Alter einer Zeile in Sekunden |
| `commonsbooking_availability_sync_limit` | 25 | Paare, die am Request-Ende neu berechnet werden |
| `commonsbooking_availability_finish_request` | `true` | `fastcgi_finish_request()` vor der Neuberechnung |

Neue Actions: `commonsbooking_index_rows_changed( $old, $new )`, `commonsbooking_index_rebuilt`.

### 10.3 Diagnose in den Einstellungen

Einstellungen → Erweitert → Performance-Booster (`SettingsView`, Daten aus `Diagnostics`): Zustand (grün/gelb/rot) mit
Hinweisen, Index-Abdeckung je Beitragstyp, Zustand der vorberechneten Paare, letzte Buchungen mit Status in WordPress und
im Index, die letzten 30 Index-Änderungen mit Herkunft (Website, Verwaltung, Cron, WP-CLI), letzter/nächster Lauf aller
Prüfungen. Knöpfe „Jetzt alle Prüfungen ausführen“ und „Index neu aufbauen“.

---

## 11. Tests und CI

| Test | Zweck |
|---|---|
| `tests/php/Service/Index/IndexerTest.php` | Projektion je Hook: Speichern, Meta, Status, `cancel()`, Papierkorb, Löschen, Aufräum-Cron, Kategorie-/„Alle“-Feiertage |
| `tests/php/Service/Index/TimeframeIndexParityTest.php` | über 1.400 Vergleiche Index ↔ `postmeta` für Zeitrahmen, Buchungen, Einschränkungen |
| `tests/php/View/CalendarSnapshotTest.php` + `snapshots/calendar-core.json` | Kalender-JSON, Kartenstatus und Tabellenzeilen für 11 Szenarien × 4 Nutzer, **vor** dem Umbau aufgezeichnet; vergleicht gespeicherte, live und frisch berechnete Ergebnisse |
| `tests/php/Service/Availability/AvailabilityTest.php` | Schreibweg: Buchen, Bestätigen, Stornieren, Löschen, Umbuchen, Feiertage, Platzhalter, Horizont, gleichzeitige Änderung, Rollen, Alter, Rechenversion |
| `tests/php/Service/Booster/*` | Schalter, Umgehung bei der Buchungsprüfung, Wächter, `purge`, Diagnose, Cache-Rollenkontext |
| `tests/php/Service/Booster/TimeframeVariantsParityTest.php` + `Fixtures/TimeframeVariants.php` | 31 Zeitrahmen-Konfigurationen, Vergleich mit dem Originalcode für sechs Nutzerrollen |
| `tests/php/Wordpress/CustomPostType/BookingItemLockTest.php` | Artikelsperre |
| `tests/php/Service/Availability/SlotsTest.php` | API/GBFS-Slots gespeichert gegen live (Tages- und Stundenraster, Startversatz, Reparatur), Abweichungen, Live-Rückfälle, Verifier |
| `tests/php/Repository/BookingTest.php::testGetForUserMatchesFilteringAllBookings` | „Meine Buchungen“: Ergebnis gleich dem Filter über alle Buchungen für sieben Nutzerarten |

Die Suite läuft standardmäßig mit eingeschaltetem Booster (`tests/php/bootstrap.php` baut den Index auf). Umgebungsvariablen:

| Variable | Wirkung | CI-Lauf |
|---|---|---|
| `CB_USE_BOOSTER=0` | Booster aus (Verhalten nach dem Update), Index-/Verfügbarkeitstests übersprungen | ja |
| `CB_USE_INDEX=0` | Originalabfragen | ja |
| `CB_USE_AVAILABILITY=0` | jeder Tag live | ja |

---

## 12. Bewusste Abweichungen, Grenzen, offene Punkte

### 12.1 Abweichungen vom Originalverhalten (mit Booster)

| Fall | 2.11.3 | Booster |
|---|---|---|
| `repetition-end` vorhanden, aber leer | Beitrag fiel aus der Abfrage heraus | offenes Ende (entspricht `getTimeframeEndDate()`), per Test festgehalten |
| ungültige ID (`'abc'` → `IN (0)`) | traf Zeitrahmen mit leerem `item-id` (MySQL wandelt `''` in 0) | trifft nichts |

### 12.2 Grenzen

- Schreibvorgänge an den WordPress-Hooks vorbei (direktes SQL Dritter) sieht der Index erst über Wächter (Status,
  Änderungsdatum) oder den täglichen Verifier (Metadaten). Die Verfügbarkeitszeilen verfallen spätestens nach 24 h.
  Buchungen bleiben in jedem Fall korrekt geprüft (Originalabfragen).
- Paare mit Rollenbeschränkung werden immer live berechnet.
- Anfragen über den Horizont hinaus (z. B. `[cb_items_table days=180]`) werden live berechnet.
- `Timeframe::isBookable()` (Vorausbuchungsfenster) hängt vom heutigen Datum ab und fließt beim Berechnen in die Auswahl
  der Zeitrahmen ein. Rückt ein Zeitrahmen ins Vorausbuchungsfenster, kann eine Zeile (Tagesfakten und Slots) bis zur
  nächtlichen Neuberechnung, höchstens 24 h, veraltet sein.

### 12.3 Offene Punkte

Die drei früheren Punkte sind umgesetzt (API/GBFS aus der Vorberechnung, `getForUser()` ohne Vollscan,
`<span'is-locked'>` korrigiert), siehe `booster-offene-punkte.md`. Offen:

- Für CB-Admins lädt „Meine Buchungen“ weiterhin alle Buchungen und paginiert in PHP.
- Buchungskalender der Artikelseite rechnet weiter live.

---

## 13. Checkliste für Entwickler

| Wenn du … | dann … |
|---|---|
| eine Abfrage auf Zeitrahmen/Buchungen/Einschränkungen schreibst | `Index::isAvailable()` prüfen, Indexpfad und Originalpfad anbieten, Paritätstest ergänzen |
| Beiträge dieser Typen per direktem SQL änderst | `Indexer::touch( $id )` und `Indexer::flush()` aufrufen, ggf. `clean_post_cache()` |
| einen neuen Meta-Schlüssel einführst, der Verfügbarkeit oder Zuordnung beeinflusst | Schlüssel im `Projector` aufnehmen, ggf. Spalte + `Schema::SCHEMA_VERSION` erhöhen, `Projector::PROJECTOR_VERSION` erhöhen (löst Neuaufbau aus) |
| die Tageslogik (Slots, Feiertage, Buchungsstatus) änderst | in `DayFacts`/`CalendarWindow`/`DayStatus` ändern, nicht in `View\Calendar`; `Calculator::VERSION` erhöhen; Snapshot bewusst neu aufzeichnen |
| eine Buchung oder einen Zeitrahmen prüfst | in `Booster::withoutBooster()` ausführen |
| neue Klassen unter `src/` anlegst | `composer prefix-namespaces` |
| testest | Suite mit und ohne `CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0` |
