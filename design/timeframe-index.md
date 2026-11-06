# Design: Index-Tabelle für Zeitrahmen, Buchungen und Einschränkungen

Status: Entwurf · Stand: 2026-11-05 · Basis: CommonsBooking 2.11.3 (`7aa75a81`)

## 1. Ziel und Abgrenzung

Alle Abfragen, die heute über `wp_postmeta` nach Artikel, Standort, Typ und Zeitraum suchen, sollen über eine
schmale, indizierte Tabelle laufen. Die Abfragezeit soll dabei nicht mehr von der Größe der Buchungshistorie
und von `wp_postmeta` abhängen.

- **Die Beiträge bleiben die maßgebliche Quelle.** `cb_timeframe`, `cb_booking` und `cb_restriction` bleiben
  unverändert Custom Post Types mit ihren Metadaten. Admin-Oberfläche, CMB2, Post-Status, Template-Tags, REST/GBFS,
  Datenschutz-Export, Buchungscodes und Hooks Dritter funktionieren unverändert.
- Die Index-Tabelle ist eine **abgeleitete Projektion**. Sie lässt sich jederzeit vollständig aus den Beiträgen neu aufbauen.
- Sie ist die Grundlage für die vorberechnete Verfügbarkeit (Stufe 3), weil deren Neuberechnung im Buchungsrequest schnell sein muss.
- **Nicht** Teil dieses Designs: die Tabelle der vorberechneten Verfügbarkeit, der Umbau des Caches und das Entfernen der Beitragstypen.

## 2. Ausgangslage (gemessen)

Testbestand: 100 Artikel/Standorte, 17.100 Zeitrahmen und Buchungen, 190.000 `postmeta`-Zeilen.

| | heute (`getPostIdsByType`) | Index-Prototyp |
|---|---|---|
| Artikel/Standort-Paar im Zeitfenster | 26 ms (Full Scan `postmeta`) | **0,12 ms** |
| dasselbe bei 50-facher Historie (867.000 Index-Zeilen) | wächst linear | **0,18 ms** |
| alle Paare der Karte in **einer** Abfrage | 400 × 25 ms ≈ 10 s | **1,7 ms** |
| Konfliktprüfung (`getExistingBookings`) | `WP_Query` mit 5 Meta-Joins | **0,07 ms** |
| Buchungen eines Nutzers (Buchungsregeln) | `WP_Query` mit Meta-Joins | **1,1 ms** |

`EXPLAIN` zeigt: Die Paar-Abfrage nutzt den Index `item_loc_range`. Die Laufzeit ist unabhängig von der Menge der
historischen Buchungen, weil `end_ts` im Index liegt. Skript: `dev-bench/schema-test.php`.

## 3. Schema

```sql
CREATE TABLE {prefix}cb_index (
  post_id             BIGINT UNSIGNED  NOT NULL,
  post_type           VARCHAR(20)      NOT NULL,          -- cb_timeframe | cb_booking | cb_restriction
  post_status         VARCHAR(20)      NOT NULL,          -- publish, draft, confirmed, unconfirmed, canceled, trash …
  post_author         BIGINT UNSIGNED  NOT NULL DEFAULT 0, -- bei Buchungen: Nutzer, FÜR den gebucht wurde
  type                TINYINT UNSIGNED NOT NULL DEFAULT 0, -- Timeframe::*_ID (1–7), 0 bei Restriction
  item_id             BIGINT UNSIGNED  NULL,              -- NULL bei Mehrfachauswahl; 0 = Wildcard (nur Restriction)
  location_id         BIGINT UNSIGNED  NULL,              -- dto.
  is_multi            TINYINT(1)       NOT NULL DEFAULT 0, -- 1 ⇒ Zuordnung in cb_index_rel
  start_ts            BIGINT           NOT NULL DEFAULT 0, -- repetition-start bzw. restriction-start
  end_ts              BIGINT           NULL,              -- repetition-end bzw. restriction-end; NULL = offen
  -- Felder für die Verfügbarkeitsberechnung, damit kein postmeta nachgeladen werden muss:
  repetition          VARCHAR(8)       NULL,              -- norep | w | m | y | manual
  weekdays            TINYINT UNSIGNED NOT NULL DEFAULT 0, -- Bitmaske Mo=1 … So=64
  manual_dates        TEXT             NULL,              -- JSON-Array Y-m-d, nur bei repetition=manual
  grid                TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = Slot, 1 = stündlich
  grid_size           TINYINT UNSIGNED NULL,
  full_day            TINYINT(1)       NOT NULL DEFAULT 0,
  start_time          TIME             NULL,
  end_time            TIME             NULL,
  max_days            SMALLINT UNSIGNED NULL,
  advance_days        SMALLINT UNSIGNED NULL,
  startday_offset     SMALLINT UNSIGNED NULL,
  has_role_limit      TINYINT(1)       NOT NULL DEFAULT 0, -- allowed_user_roles nicht leer
  restriction_type    VARCHAR(10)      NULL,              -- repair | hint
  restriction_state   VARCHAR(10)      NULL,              -- none | active | solved
  -- Verwaltung:
  source_modified_gmt DATETIME         NOT NULL,          -- post_modified_gmt zum Zeitpunkt der Projektion
  projector_version   SMALLINT UNSIGNED NOT NULL,         -- erhöht bei Änderung der Projektionslogik ⇒ gezielter Reindex
  PRIMARY KEY (post_id),
  KEY item_loc_range (item_id, location_id, type, end_ts, start_ts),
  KEY loc_range      (location_id, type, end_ts),
  KEY type_range     (type, end_ts, start_ts),
  KEY author_type    (post_author, type, start_ts)
);

CREATE TABLE {prefix}cb_index_rel (
  post_id     BIGINT UNSIGNED        NOT NULL,
  entity_type ENUM('item','location') NOT NULL,
  entity_id   BIGINT UNSIGNED        NOT NULL,
  PRIMARY KEY (entity_type, entity_id, post_id),
  KEY post (post_id)
);
```

### Entscheidungen

**Eine Zeile pro Beitrag, Mehrfachauswahl in eigener Relationstabelle.**
Laut `removeIrrelevantPostmeta()` gibt es Mehrfach-, Kategorie- und „Alle“-Auswahl nur bei Feiertagen (3) und Reparatur (5).
`manageTimeframeMeta()` schreibt die Auswahl bereits als `item-id-list`/`location-id-list` aus.
Ein Kreuzprodukt Artikel × Standort in der Haupttabelle wäre bei „alle Artikel × alle Standorte“ (z. B. 500 × 300) pro
Feiertag 150.000 Zeilen. Die Relationstabelle braucht dafür nur 800 Zeilen. Buchungen und buchbare Zeitrahmen
(> 99 % der Zeilen) haben genau einen Artikel und einen Standort und brauchen keinen Join.

**Einschränkungen in derselben Tabelle.** `restriction-item-id` bzw. `restriction-location-id` = 0 bedeutet heute
„alle“ (`Restriction::filterPosts`). Im Index steht dann `0`, die Abfrage lautet `item_id IN (0, :item)`. Das bleibt
indizierbar und bildet die vier Fälle aus `filterPosts` exakt ab.

**Zeitstempel unverändert übernehmen.** CommonsBooking speichert „lokale Zeit als Zeitstempel“
(siehe Docblock `Model\Timeframe::getStartDate()`). Der Index übernimmt die Werte ohne Zeitzonen-Umrechnung,
damit alle bestehenden Vergleiche identisch bleiben. Nicht numerische Altwerte normalisiert die Projektion wie
`getStartDate()` per `strtotime()`. `end_ts = NULL` entspricht „kein Ende“. Das ersetzt die heutigen
`NOT IN (SELECT … repetition-end)`-Unterabfragen.

**Verdeckende Felder für die Verfügbarkeit** (`repetition`, `weekdays`, `grid`, `full_day`, `start_time` …).
`Day::isInTimeframe()`, `processSlot()` und `sanitizeSlots()` lesen diese Werte heute pro Tag und Zeitfenster per
`get_post_meta`. Liegen sie im Index, kann die Verfügbarkeit (Stufe 3) allein aus Index-Zeilen berechnet werden.
Für Phase 1 sind sie optional, sie werden aber gleich mitgebaut, damit kein zweites Backfill nötig ist.

**Kein Fremdschlüssel, keine Trigger.** WordPress-Tabellen nutzen keine FKs, und Trigger erfordern auf vielen Hostern
Sonderrechte. Die Pflege erfolgt in PHP über Hooks (Abschnitt 4).

**Multisite:** eine Tabelle pro Site über `$wpdb->prefix`. Anlegen per `dbDelta()`, Löschen in `uninstall.php`.

## 4. Pflege der Tabelle (Projektion)

### 4.1 Komponenten

- `CommonsBooking\Service\Index\Projector`: `project(int $postId): void` liest den Beitrag über die bestehenden Models
  (`Model\Timeframe`, `Model\Booking`, `Model\Restriction`) und schreibt die Zeile per `REPLACE INTO` sowie die
  Relationen (Löschen + Einfügen). Weil die Models genutzt werden, gilt dieselbe Semantik wie heute, z. B.
  `getItemIDs()` mit Liste oder Einzelwert, `getStartDate()` mit `strtotime`-Fallback.
  `remove(int $postId)` löscht die Zeile und die Relationen.
- `CommonsBooking\Service\Index\Indexer`: registriert die Hooks, sammelt betroffene Post-IDs pro Request
  (`$dirty`, dedupliziert) und ruft am Ende `flush()` auf. Gibt die **alten** Werte (Artikel, Standort, Zeitraum)
  zurück, bevor die Zeile ersetzt wird. Diese braucht später die Verfügbarkeits-Invalidierung (Stufe 3), um auch
  den verlassenen Zeitraum neu zu berechnen.

### 4.2 Hooks (alle Schreibpfade aus dem Code)

| Auslöser | Hook / Stelle | Aktion |
|---|---|---|
| Beitrag gespeichert | `save_post` mit Priorität `PHP_INT_MAX` (nach `Timeframe::savePost` mit Priorität 11, das Metadaten umschreibt und ungültige Zeitrahmen auf `draft` setzt) | sofort projizieren |
| Metadaten geändert (CMB2, Importer, `update_post_meta` aus Code) | `added_post_meta`, `updated_post_meta`, `deleted_post_meta` für die relevanten Schlüssel und Beitragstypen | als dirty markieren |
| Statuswechsel | `transition_post_status` | als dirty markieren |
| Papierkorb / Löschen | `trashed_post` (Status projizieren), `deleted_post` | `remove()` |
| **Stornierung** `Model\Booking::cancel()` | setzt `post_status` per direktem SQL, **ohne Post-Hooks** | **explizit** `Indexer::touch($id)` in `cancel()` ergänzen. Betrifft Admin-Statuswechsel, `Restriction::cancelBookings()` und die Datenschutz-Löschfunktion. |
| Unbestätigte Buchungen aufräumen | `Service\Booking::cleanupBookings()` → `wp_delete_post` | über `deleted_post` |
| Kategorie- oder „Alle“-Auswahl | `Timeframe::updateAllTimeframes()` → `manageTimeframeMeta()` → `update_post_meta(*-id-list)` | über die Meta-Hooks |
| Artikel/Standort gelöscht oder unveröffentlicht | Statuswechsel der Zeitrahmen ändert sich nicht | Heutige Semantik beibehalten: Zeilen bleiben, gefiltert wird beim Lesen (siehe 6.3) |
| Migrationen, Upgrade, `Migration.php:433` (direktes SQL) | — | anschließend Reindex der betroffenen Typen |

Relevante Meta-Schlüssel: `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`, `repetition-start`,
`repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`, `start-time`, `end-time`,
`timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`, `allowed_user_roles`,
`restriction-*`. Alle anderen Schlüssel werden ignoriert (z. B. `_edit_lock`, Buchungscodes, Kommentare).

### 4.3 Wann wird geschrieben?

`flush()` läuft:
1. am Ende von `save_post` (Priorität `PHP_INT_MAX`),
2. explizit am Ende von `Booking::handleBookingRequest()` und `Model\Booking::cancel()`, also **vor** dem Redirect,
3. als Auffangnetz bei `shutdown`.

Damit ist der Index **im selben Request** aktuell, in dem sich der Beitrag ändert. Das ist die Voraussetzung für die
sofortige Neuberechnung der Verfügbarkeit (Stufe 3).

### 4.4 Fehlerfall und Konsistenz

- Scheitert die Projektion, z. B. bei einem Fatal Error zwischen Speichern und `flush()`, wird die Post-ID in der Option
  `cb_index_pending` (bzw. in der Dirty-Tabelle aus Stufe 3) vermerkt. Ein Cron-Job arbeitet sie ab (z. B. alle 5 Minuten).
- `wp commonsbooking index verify [--sample=500]` vergleicht Index und Projektion und repariert Abweichungen.
  Läuft zusätzlich täglich per Cron mit einer Stichprobe.
- `projector_version`: Ändert sich die Projektionslogik, projiziert ein Upgrade-Task nur Zeilen mit älterer Version neu.

## 5. Erstbefüllung und Rollout

1. **Tabelle anlegen:** in `Plugin::activation()` und als Eintrag in `Upgrade::$upgradeTasks`, Versionsoption
   `commonsbooking_index_db_version`.
2. **Backfill in Teilen** über die vorhandene AJAX-Upgrade-Infrastruktur (`Upgrade::$ajaxUpgradeTasks`,
   `runAJAXUpgradeTasks`), je 500 Beiträge, fortsetzbar. Parallel dazu `wp commonsbooking index rebuild [--type=…]`
   für große Instanzen. Schnellpfad: Ein Bulk-`INSERT … SELECT` über `postmeta` (wie im Prototyp) befüllt die
   Grundspalten in Sekunden. Die PHP-Projektion korrigiert danach Sonderfälle (Mehrfachauswahl, Altwerte, `manual_dates`).
3. **Schalter:** Die Repositories nutzen den Index nur, wenn `get_option('commonsbooking_index_ready') === projector_version`.
   Bis dahin, und wenn der Filter `commonsbooking_use_index` `false` liefert, läuft der bisherige Weg über `postmeta`.
   So ist eine schrittweise Freigabe und ein sofortiger Rückweg möglich.
4. Die Hooks schreiben **ab Aktivierung**, auch während des Backfills. Was das Backfill danach überschreibt, ist
   idempotent (`REPLACE`).

## 6. Umstellung der Repositories

### 6.1 Neue Abfrageschicht `CommonsBooking\Repository\Index`

```php
Index::find( array $criteria ): int[]   // liefert post_ids
// criteria: items[], locations[], types[], post_types[], status[], from_ts, to_ts, date (Y-m-d),
//           authors[], restriction_state, include_multi=true, include_wildcards (nur Restriction)
Index::rows( array $criteria ): array   // liefert die Index-Zeilen selbst (für Verfügbarkeit, Gruppierung)
```

Danach immer gebündelt laden: `_prime_post_caches( $ids )` plus `update_meta_cache( 'post', $ids )`, dann die Models.
Das ersetzt die heutigen Einzelaufrufe von `get_post()` (17.200 Abfragen im Kartenszenario).

Abfragemuster (Paar mit Mehrfachauswahl, bewusst `UNION ALL` statt `OR`, damit beide Teile einen Index nutzen):

```sql
SELECT post_id FROM cb_index
 WHERE item_id IN (:items) AND location_id IN (:locs) AND type IN (:types)
   AND post_status IN (:status) AND (end_ts IS NULL OR end_ts >= :from) AND start_ts <= :to
UNION ALL
SELECT x.post_id FROM cb_index_rel ri
  JOIN cb_index_rel rl ON rl.post_id = ri.post_id AND rl.entity_type='location' AND rl.entity_id IN (:locs)
  JOIN cb_index x      ON x.post_id  = ri.post_id
 WHERE ri.entity_type='item' AND ri.entity_id IN (:items)
   AND x.type IN (:types) AND x.post_status IN (:status)
   AND (x.end_ts IS NULL OR x.end_ts >= :from) AND x.start_ts <= :to
```

Bei leerer Artikel- oder Standortliste entfällt die jeweilige Bedingung bzw. der Join, wie heute auch.

### 6.2 Zuordnung der bestehenden Methoden

| Bestehende Methode | Neu |
|---|---|
| `Repository\Timeframe::getPostIdsByType()` + `getPostsByBaseParams()` (Grundlage von `get`, `getInRange`, `getBookable*`) | `Index::find()` mit Datums- bzw. Zeitraumfilter direkt in SQL. **Historische Buchungen werden nicht mehr geladen.** |
| `Timeframe::filterTimeframesByConfiguredDays()` | Wochentag/Manuell aus der Index-Zeile, ohne `get_post_meta` |
| `Timeframe::getByLocationItemTimestamp()` | `Index::find()` + Vergleich `start_time`/`end_time` aus der Zeile |
| `Repository\Booking::getByDate/getByTimerange/getExistingBookings` | `Index::find(post_types=[cb_booking], type=6, …)`. **Konfliktprüfung**: liest die Quelle (Index ist im selben Request aktuell, siehe 4.3) |
| `Booking::getForUser/getForCurrentUser`, Buchungsregeln (`BookingRule::check*`) | `Index::find(authors=[…])` über `author_type` |
| `Booking::getBeginningBookingsByDate/getEndingBookingsByDate` (Erinnerungs-Mails) | `type_range` |
| `Repository\Restriction::get/queryPosts` | `Index::find(post_types=[cb_restriction], restriction_state='active', include_wildcards)`. **Einmal pro Zeitraum statt pro Tag.** |
| `BookablePost::getRelatedPosts()` (Artikel ↔ Standort, Karte/Listen) | `SELECT DISTINCT location_id/item_id FROM cb_index WHERE … post_type='cb_timeframe' AND is_multi=0`. Dieselbe Semantik wie heute: nur Einzelzuordnung, alle Typen und Status aus der bestehenden Abfrage. |

Admin-Listen (`filterAdminList`, `pre_get_posts`) und die REST-API bleiben in Phase 1 unverändert. Sie können später
über `posts_clauses` auf den Index umgestellt werden.

### 6.3 Verhalten bewusst beibehalten

- Gelöschte oder unveröffentlichte Artikel/Standorte: Heute bleiben Zeitrahmen mit veralteten IDs bestehen und werden
  über `post_status` des Artikels gefiltert (`View::getShortcodeData`, `getRelatedPosts`). Der Index übernimmt das
  unverändert. Abweichungen davon gehören in ein eigenes Ticket.
- Unbestätigte Buchungen blockieren den Kalender weiterhin nicht (`getInRange` mit `confirmed, publish`), die
  Konfliktprüfung aber schon (`confirmed, unconfirmed`). Beide Filter kommen unverändert als `status` in die Kriterien.

## 7. Tests

- **Paritätstest:** Für zufällige Kriterien (Artikel/Standort, Typen, Datum, Zeitraum, Status) müssen Index-Weg und
  `postmeta`-Weg dieselben IDs liefern. Läuft in PHPUnit gegen die Fixtures aus `CPTCreationTrait`, inklusive Feiertagen
  mit Kategorie- und „Alle“-Auswahl, Einschränkungen mit Wildcards, manueller Wiederholung und offenem Ende.
- **Projektionstests pro Hook:** speichern, Metadaten ändern, Statuswechsel, `cancel()`, `wp_trash_post`, `wp_delete_post`,
  `cleanupBookings`, Kategorie-Neuzuordnung.
- **Gesamte bestehende Testsuite zweimal** ausführen, mit `commonsbooking_use_index` an und aus (Data-Provider bzw.
  CI-Matrix).
- **Benchmarks:** `tests/benchmark/Map/MapDataBench.php`, `CalendarBench`, `ShortcodeBench`, `BookingBench` vorher und
  nachher. Zusätzlich ein Szenario mit 10-facher Historie, um die Unabhängigkeit von der Historie nachzuweisen.

## 8. Phasen und Aufwand (Schätzung)

| Phase | Inhalt | Aufwand |
|---|---|---|
| 1 | Schema, Projector, Indexer mit Hooks inkl. `cancel()`, Backfill, CLI `rebuild`/`verify`, Schalter | 4–5 Tage |
| 2 | `Repository\Index` + Umstellung `Timeframe::get/getInRange/getBookable*`, gebündeltes Laden | 2–3 Tage |
| 3 | Umstellung `Booking`-Repository (Konfliktprüfung, Nutzerbuchungen, Erinnerungen) und `Restriction` | 2 Tage |
| 4 | `getRelatedPosts`, Karte (`Map::get_locations` mit einer Abfrage für alle Paare) | 1–2 Tage |
| 5 | Paritäts- und Hook-Tests, CI-Matrix, Benchmarks | 2–3 Tage |

Danach Stufe 3 (vorberechnete Verfügbarkeit): Sie nutzt `Index::rows()` und die alten Werte aus `Indexer` für die
gezielte Neuberechnung.

## 8a. Stand Phase 1 (umgesetzt am 2026-11-06, nicht committet)

Code: `src/Service/Index/` (`Schema`, `Projector`, `Indexer`, `Backfill`, `Verifier`, `Cli`), Tests:
`tests/php/Service/Index/IndexerTest.php` (14 Tests). Geändert: `Plugin::init/activation/deactivation`,
`Model\Booking::cancel()`, `phpstan.neon` (WP_CLI ignorieren). Die Suite ist grün (475 Tests), PHPStan meldet keine
Fehler, `phpcbf` lässt den Code unverändert.

Abweichungen vom Entwurf oben:

| Entwurf | Umsetzung | Grund |
|---|---|---|
| Backfill über `Upgrade::$ajaxUpgradeTasks` | Eigene Schema-Version (`commonsbooking_index_schema_version`), Installation auf `init`. Backfill über sich selbst neu planenden WP-Cron (20 s pro Lauf) oder `wp commonsbooking index rebuild` | Die Upgrade-Tasks hängen an der Plugin-Version (Fork bleibt 2.11.3), und AJAX-Tasks werden bei Neuinstallationen ignoriert. |
| Bulk-`INSERT … SELECT` als Schnellpfad | Nicht nötig: PHP-Projektion mit `_prime_post_caches` schafft ca. 5.000 Beiträge/s (17.100 in 2,5–3,4 s) | weniger Code, eine einzige Projektionslogik |
| Option `cb_index_pending` für abgebrochene Projektionen | `flush()` am Ende von `save_post` sowie bei `shutdown` (läuft auch nach Fatal Errors). Dazu täglicher `Verifier`-Cron (Stichprobe 200, repariert) und `wp commonsbooking index verify --repair` | kein zusätzlicher Schreibzugriff pro Meta-Änderung |
| Artikel/Standort-Zuordnung über die Model-Getter | **Vereinigung** von `item-id` (alle Werte) und `item-id-list` (ebenso für Standorte) | entspricht exakt dem bisherigen SQL in `getEntityQuery()` (Single **oder** Liste). Die Getter bevorzugen nur die Liste. |
| — | `cancel()` ruft zusätzlich `clean_post_cache()` auf | Ohne diesen Aufruf liefert `get_post()` im selben Request noch den alten Status. Das betraf schon bisher alle nachfolgenden Lesezugriffe (die Tests umgehen es mit `wp_cache_flush()`). |
| — | `repetition-end` = `''`/`0` wird zu `end_ts = NULL` (offenes Ende) | Entspricht der Model-Semantik (`getTimeframeEndDate()`). Das bisherige SQL hätte einen Beitrag mit vorhandenem, aber leerem Schlüssel ausgeschlossen. **Paritätstest in Phase 2 prüfen.** |

Hinweis für die Entwicklung: Neue Klassen unter `src/` erfordern `composer prefix-namespaces`, weil das Plugin nur den
Strauss-Autoloader in `vendor-prefixed/` lädt.

## 8b. Stand Phase 2 (umgesetzt am 2026-11-06, nicht committet)

- `src/Repository/Index.php`: `findPostIds()` (UNION ALL aus Einzel- und Mehrfachzuordnung, sortiert nach `post_id`,
  ruft vorher `Indexer::flush()` auf, damit Änderungen des eigenen Requests sichtbar sind) und `loadPosts()`
  (gebündeltes Laden von Beiträgen und Metadaten).
- `Repository\Timeframe::get()` und `getInRange()` (damit auch `getBookable*`, `getInRangeForCurrentUser`) nutzen
  über `queryPosts()` den Index, wenn `Index::isAvailable()`. Sonst läuft der bisherige Weg. Die PHP-Filter
  (Wochentage, `isBookable`, Rollen) und der Cache sind unverändert.
- Schema v2: `start_ts` darf NULL sein (das bisherige SQL verlangt beim Datums- und Zeitraumfilter den
  Meta-Schlüssel `repetition-start`). Dazu der Index `multi_type`. Ein explizites `ALTER` ist nötig, weil `dbDelta`
  die NULL-Eigenschaft nicht ändert.
- Tests: `tests/php/bootstrap.php` baut den Index vor der Suite auf. `CB_USE_INDEX=0` schaltet den Index ab,
  `dev-bench/phpunit/run.sh` reicht die Variable durch. Paritätstest mit über 1.400 Vergleichen alt vs. neu
  (`TimeframeIndexParityTest`). Die Suite ist in beiden Modi grün (479 Tests).
- Bewusste Abweichung: Eine ungültige ID (`'abc'` → `IN (0)`) traf im alten SQL Zeitrahmen mit leerem `item-id`
  (MySQL wandelt `''` in 0 um). Im Index trifft sie nichts.

Messung auf dem Testbestand (Cache aus, 100 Artikel, 17.100 Zeitrahmen und Buchungen):

| Ansicht | vorher | nachher | Faktor |
|---|---|---|---|
| Karte, nur Standorte | 5,37 s / 1.005 Queries | 0,21 s / 805 | 25× |
| Karte mit Verfügbarkeit | 15,41 s / 40.800 | 2,39 s / 6.600 | 6,4× |
| `[cb_items_table]` 31 Tage | 7,05 s / 3.604 | 1,25 s / 3.403 | 5,6× |
| `[cb_items]` | 2,78 s / 201 | 0,10 s / 101 | 28× |

Restkosten: `Restriction::get()` pro Tag (6.000 bzw. 3.200 Abfragen, rund 1 s) → Phase 3. Die Karte berechnet weiter
60 statt 12 Tage → Stufe 1 (Quick Win).

## 8c. Stand Phase 3 (umgesetzt am 2026-11-06, nicht committet)

- `Repository\Booking`: `getByDate`, `getByTimerange` (und damit `getExistingBookings`, `getByRestriction`,
  Buchungsregeln, angrenzende Buchungen), `getBeginningBookingsByDate` und `getEndingBookingsByDate` (Dashboard,
  Erinnerungs-Mails) lesen über `findViaIndex()`. Unterstützte `$customArgs`: `author`, `post_status`,
  `orderby=date`, `order`. Bei anderen Argumenten läuft automatisch die bisherige `WP_Query`. Die Sortierung
  entspricht `WP_Query` (`post_date`, Standard DESC) und ist per Test abgesichert.
- `Repository\Restriction::get()` liest über den Index (`restriction_state = active`). Der PHP-Filter für
  Artikel/Standort (inkl. „alle“) ist unverändert.
- **Einschränkungen pro Zeitraum statt pro Tag:** `Model\Calendar` lädt die aktiven Einschränkungen der Range einmal und
  reicht sie über `Week` an `Day` weiter. `Day::getRestrictions()` filtert sie mit derselben Datumsbedingung wie die
  Abfrage pro Tag. Ohne vorab geladene Liste (direkte `Day`-Nutzung) bleibt es bei der Abfrage.
- Nicht umgestellt: `getForUserPaginated` (Pagination mit `author`, unkritisch) und `getForUser` (lädt alle
  Buchungen und filtert in PHP nach Sichtbarkeit → eigener Punkt, siehe unten).
- Tests: Paritätstests für Buchungen, Einschränkungen und die vorab geladenen Tages-Einschränkungen (zusammen über 3.800
  Assertions, mit Prüfung auf nicht-leere Ergebnisse). Bei `CalendarTest::testGetDays` wurden die erwarteten
  `Week`-Objekte um das neue Feld ergänzt. Die Suite ist in beiden Modi grün (482 Tests).

Messung auf dem Testbestand (Cache aus):

| Ansicht | Ausgang | Phase 2 | Phase 3 |
|---|---|---|---|
| Karte mit Verfügbarkeit | 15,41 s / 40.800 Queries | 2,39 s / 6.600 | **1,17 s / 700** |
| `[cb_items_table]` 31 Tage | 7,05 s / 3.604 | 1,25 s / 3.403 | **0,72 s / 303** |
| `[cb_items]` | 2,78 s / 201 | 0,10 s / 101 | **0,09 s / 101** |
| Karte, nur Standorte | 5,37 s / 1.005 | 0,21 s / 805 | **0,20 s / 805** |

Der Rest ist jetzt überwiegend PHP-Rechenzeit (Karte: 0,26 s SQL von 1,17 s). Größter Hebel: Die Karte berechnet
60 statt der angezeigten 12 Tage (Quick Win). Danach folgt Stufe 3 (vorberechnete Verfügbarkeit).

Offener Punkt: `Booking::getForUser()` (Seite „Meine Buchungen“, `BookingRuleApplied`) lädt **alle** Buchungen der
Instanz und filtert in PHP nach `commonsbooking_isUserAllowedToSee()`. Das skaliert mit der Gesamtzahl der
Buchungen. Eine Umstellung braucht eine Index-Abfrage „eigene Buchungen + Buchungen administrierter
Artikel/Standorte“.

## 8d. Stand Phase 4 (umgesetzt am 2026-11-06, nicht committet)

- `BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) holt die Zeitrahmen über
  `getTimeframesByRelation()` aus dem Index. Danach filtert es auf den **einzelnen** `*-id`-Wert (Semantik der
  bisherigen Meta-Abfrage) und sortiert wie `WP_Query` (`post_date DESC`). Die zugehörigen Artikel bzw. Standorte
  werden gebündelt geladen.
- `Index::sortByDate()`: Bei gleichem `post_date` gilt die ID aufsteigend. So liefert MySQL Gleichstände bei
  `ORDER BY post_date` (im Paritätstest beobachtet).
- `Model\Map::get_locations()` lädt Artikel pro Standort zuerst und dann **alle buchbaren Zeitrahmen in einer Abfrage**
  (`Map::getBookableTimeframesByPair()`, gruppiert nach Standort und Artikel). Dazu kommen Term-Cache und
  Vorschaubilder gebündelt. Die Gleichwertigkeit zur Abfrage pro Paar ist per Test abgesichert, ebenso die Gleichheit
  der kompletten Kartendaten mit und ohne Index.
- Suite in beiden Modi grün (485 Tests), PHPStan ohne Fehler.

| Ansicht | Ausgang | nach Phase 4 |
|---|---|---|
| Karte, nur Standorte | 5,37 s / 1.005 Queries | **0,09 s / 707** |
| Karte mit Verfügbarkeit | 15,41 s / 40.800 | **1,17 s / 701** |
| `[cb_items_table]` 31 Tage | 7,05 s / 3.604 | **0,67 s / 302** |
| `[cb_items]` | 2,78 s / 201 | **0,09 s / 101** |

Die Abfragen bei `get_locations` sind jetzt günstige Einzelabfragen pro Standort (Index, Metadaten, Kategorien,
zusammen 0,05 s). Die Karte mit Verfügbarkeit wird von der PHP-Berechnung der Tage dominiert (0,27 s SQL von
1,17 s). Nächster Hebel: nur den angezeigten Zeitraum berechnen (Quick Win), danach die vorberechnete Verfügbarkeit
(Stufe 3).

## 8e. Stand Phase 5 (umgesetzt am 2026-11-06, nicht committet)

- Weitere Hook-Tests: Stornierung durch `Restriction::apply()`, Aufräum-Cron für unbestätigte Buchungen, neuer
  Artikel bei „alle Artikel“-Feiertag, Papierkorb/Wiederherstellen. Dazu ein Test, der die Abweichung bei leerem
  `repetition-end` (offenes Ende statt ausgeschlossen) festhält.
- CI (`.github/workflows/phpunit.yml`): zusätzlicher Matrix-Lauf mit `CB_USE_INDEX=0` (PHP 8.1, WP latest).
- Benchmarks: `tests/benchmark/bootstrap.php` baut den Index auf, `CB_USE_INDEX=0` misst den alten Weg.
- **Schema v3:** Indizes `ptype_item`, `ptype_location`, `ptype_end`. Ohne sie wuchsen die Abfragen für Einschränkungen
  (Full Scan) und für die Zuordnung Artikel ↔ Standort (alle Buchungen eines Standorts) mit der Historie.
- `Schema::maybeInstall()` startet keinen Neuaufbau mehr bei reinen Strukturänderungen, nur bei geänderter
  `PROJECTOR_VERSION`. Der Index bleibt beim Upgrade lesbar.
- Entwickler-Doku in `TECHNICAL.md`. Suite in beiden Modi grün (490 Tests), PHPStan ohne Fehler.

PHPBench (Fixture des Projekts: 100 Artikel × 110 Buchungen, Zeit pro Aufruf, Cache aus):

| Benchmark | ohne Index | mit Index | Faktor |
|---|---|---|---|
| Buchungsablauf | 347 ms | 14,6 ms | 24× |
| Buchungsablauf mit Buchungsregel | 852–1.157 ms | 17–108 ms | 11–50× |
| Karte | 2,98 s | 0,11 s | 28× |
| Karte mit Verfügbarkeit | 9,85 s | 2,03 s | 4,8× |
| `[cb_items]` / `[cb_locations]` | 3,14 s / 2,90 s | 0,17 s / 0,17 s | 18× |
| `[cb_items_table]` | 8,78 s | 1,96 s | 4,5× |
| GBFS Vehicle Availability / Vehicle Status / Station Status | 22,5 s / 20,8 s / 7,36 s | 1,81 s / 1,02 s / 0,58 s | 12–20× |
| gesamter Lauf | 16:43 min | 2:52 min | |

Skalierung (Entwicklungsdaten auf das Zehnfache vergrößert: 167.000 Beiträge, 1,84 Mio. `postmeta`; Index-Neuaufbau 40 s):

| Ansicht | 1× mit Index | 10× mit Index | 10× ohne Index |
|---|---|---|---|
| Karte, nur Standorte | 0,09 s | 0,09 s | 49,96 s |
| Karte mit Verfügbarkeit | 1,17 s | 1,06 s | (nicht gemessen, Minuten) |
| `[cb_items_table]` | 0,67 s | 0,56 s | – |
| `[cb_items]` | 0,09 s | 0,09 s | 25,41 s |

## 9. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Index weicht von den Beiträgen ab (Schreibweg ohne Hooks, direktes SQL durch Drittanbieter) | `verify` per Cron, `rebuild` per CLI, Schalter für den Rückweg, `projector_version` |
| Performance beim Speichern sinkt | Projektion pro Beitrag ≈ 1 `REPLACE` + wenige `INSERT`s; Metadaten liegen nach dem Speichern bereits im Cache |
| `updateAllTimeframes()` bei vielen Artikeln (Kategorie- oder „Alle“-Feiertage) | Schreibt bereits heute alle Listen. Der Index projiziert dedupliziert einmal pro Zeitrahmen. |
| Großes Backfill auf Shared Hosting | Bulk-`INSERT … SELECT`, AJAX in Teilen mit Fortsetzung, CLI |
| Semantische Abweichung zum Altweg | Projektion über die bestehenden Models, Paritätstests, Schalter |
