# Zugeordnete Zeitrahmen je Artikel und Standort (Konzept)

Status: umgesetzt (Variante A + D + Filter-Fix), committet. Branch `feature/wirksame-zeitrahmen` (Basis `booster/index-availability`).

## 1. Problem

Zeitrahmen vom Typ **Feiertage/geschlossen** (`HOLIDAYS_ID` = 3) und **Reparatur/Blockierung** (`REPAIR_ID` = 5) können
mehrere Artikel und Standorte gleichzeitig betreffen, ausgewählt per Liste, per Kategorie oder per „Alle“.
Ein Admin oder CB-Manager sieht im Frontend z. B., dass ein Artikel an zwei Tagen nicht buchbar ist. Im Backend
lässt sich aber nicht herausfinden, welcher Zeitrahmen das verursacht:

- Die Zeitrahmen-Liste filtert „Artikel“ und „Standort“ nur über das Einzelfeld `item-id` / `location-id`
  (`CustomPostType\Timeframe::filterAdminList()`). Zeitrahmen mit Mehrfachauswahl fehlen im Ergebnis.
- Die Spalte „Artikel“ zeigt bei Mehrfachauswahl `-` (`setCustomColumnsData()` liest nur `item-id`).
- Auf der Bearbeitungsseite von Artikel und Standort gibt es keinen Hinweis auf zugeordnete Zeitrahmen.
- Einschränkungen (`cb_restriction`) mit „0 = alle“ sperren ebenfalls Tage, tauchen aber nirgends beim Artikel auf.

Ziel: Für einen Artikel oder Standort auf einen Blick sehen, **welche Zeitrahmen und Einschränkungen ihn steuern**,
mit Zeitraum, Raster und Art der Zuordnung, und mit direktem Sprung zur Bearbeitung.

## 2. Ist-Analyse: wie die Zuordnung gespeichert wird

### 2.1 Zeitrahmen (`cb_timeframe`, alles in `postmeta`)

| Meta-Key | Inhalt | Verwendet bei |
|---|---|---|
| `type` | Typ-ID (2 buchbar, 3 Feiertage, 5 Reparatur, 6/7 Buchung) | alle |
| `item-id` / `location-id` | einzelne Post-ID | buchbar, Buchung; bei 3/5 Altbestand vor 2.9 |
| `item-id-list` / `location-id-list` | serialisiertes Array von IDs **als Strings** (`a:2:{i:0;s:2:"12";…}`) | nur 3 und 5 |
| `item-select` / `location-select` | Auswahlart: `0` manuell, `1` Kategorie, `2` alle | nur 3 und 5 |
| `item-category-ids` / `location-category-ids` | serialisierte Term-IDs | nur bei Auswahlart 1 |
| `repetition-start` / `repetition-end` | Unix-Zeitstempel; Ende leer = offen | alle |
| `timeframe-repetition` | `d`, `w`, `m`, `y`, `manual`, `norep` | alle |
| `weekdays`, `timeframe_manual_date` | Wochentage bzw. manuelle Daten | bei `w` bzw. `manual` |
| `grid`, `full-day`, `start-time`, `end-time` | Raster: 0 Slot, 1 stündlich; ganztägig; Uhrzeiten | alle |

Wichtige Eigenschaften:

1. **Kategorie und „Alle“ werden materialisiert.** `Timeframe::manageTimeframeMeta()` schreibt beim Speichern die
   aktuell passenden IDs nach `item-id-list` / `location-id-list`. Die Auswahlart bleibt in `item-select` erhalten.
   Wir können also immer über die Liste suchen und die Art der Zuordnung trotzdem anzeigen.
2. **Nachführung:** `updateAllTimeframes()` läuft beim Speichern eines Artikels oder Standorts und bei Änderungen an
   deren Kategorien (`Item::savePost`, `Item::termChange`, ebenso für Standorte). Die Liste ist damit aktuell, solange
   die Hooks laufen. Gelöschte oder in den Papierkorb verschobene Posts können als „tote“ IDs in der Liste stehen
   bleiben. Die Anzeige muss also nur existierende Posts berücksichtigen.
3. **Wirkung = Artikel UND Standort.** `Repository\Timeframe::getPostIdsByType()` verknüpft Artikel- und
   Standortbedingung mit `INNER JOIN` (UND). Ein Feiertag sperrt einen Artikel nur an einem Standort, der ebenfalls
   ausgewählt ist. Für die Anzeige beim Artikel heißt das: Ein Zeitrahmen ist für Artikel X **zugeordnet**, wenn X
   in der Artikelauswahl steht. **Wirksam** ist er nur, wenn auch der Standort, an dem X buchbar ist (aus den
   buchbaren Zeitrahmen), in der Standortauswahl steht. Beides wird angezeigt (siehe Mock-up, Spalte „wirkt an“).
4. **Kategorie/„Alle“ dürfen nur Admins wählen** (`getSelectionOptions()`). Manager können nur aus
   `getByCurrentUser()` auswählen. Ein Admin-Feiertag „alle Artikel“ betrifft also typischerweise Artikel von Managern,
   ohne dass diese ihn bearbeiten dürfen.
5. `Repository\BookablePost::getRelatedPosts()` (z. B. `Item::getLocations()`) wertet nur `item-id`/`location-id`
   aus, also nur buchbare Zeitrahmen. Das passt für „Standort des Artikels“, nicht aber für Feiertage.

### 2.2 Einschränkungen (`cb_restriction`)

| Meta-Key | Inhalt |
|---|---|
| `restriction-item-id`, `restriction-location-id` | einzelne ID, **`0` = alle** |
| `restriction-type` | `repair` (Totalausfall, sperrt) oder `hint` (nur Hinweis) |
| `restriction-state` | `none`, `active`, `solved` |
| `restriction-start`, `restriction-end` | Zeitstempel; Ende leer = offen |

Gesperrt wird ein Tag nur bei `type = repair` **und** `state = active` (`Model\Day`, `isActive() && TYPE_REPAIR`).
Hinweise werden angezeigt, sperren aber nicht. Die Zuordnung prüft `Repository\Restriction::filterPosts()`:
0/0 betrifft alles, sonst passt der gesetzte Artikel und/oder der gesetzte Standort.

### 2.3 Booster-Index (optional)

Ist der Booster aktiv (`Booster::canRead()`), stehen dieselben Informationen indiziert bereit:

- `cb_index`: eine Zeile je Zeitrahmen oder Einschränkung mit `type`, `start_ts`, `end_ts`, `repetition`,
  `weekdays`, `grid`, `full_day`, `start_time`, `end_time`. Einzelzuordnung steht in `item_id` / `location_id`.
  Bei Einschränkungen bedeutet `0` „alle“.
- `cb_index_rel`: Bei `is_multi = 1` eine Zeile je zugeordnetem Artikel oder Standort (`entity_type`, `entity_id`).
  Schlüssel `(entity_type, entity_id, post_id)` passt genau für „alle Zeitrahmen zu Artikel X“.

Die Auswahlart (Kategorie/alle) steht **nicht** im Index. Sie wird für die angezeigten Zeilen aus `postmeta` gelesen
(höchstens ein paar Dutzend Posts, per `update_meta_cache` in einer Abfrage).

## 3. Varianten für die Anzeige

| | Variante | Vorteile | Nachteile |
|---|---|---|---|
| **A** | **Metabox „Zugeordnete Zeitrahmen“** auf der Bearbeitungsseite von Artikel und Standort | Genau dort, wo man den Artikel sucht; zeigt Zeitrahmen **und** Einschränkungen gemeinsam; Rechteprüfung ist einfach, die Seite ist schon geschützt (`commonsbooking_validate_user_on_edit`); keine Änderung an bestehenden Listen | Nur ein Artikel auf einmal; die Metabox muss knapp bleiben (Einklappen nötig) |
| **B** | **Filter und Spalte in der Zeitrahmen-Liste** (Filter „Artikel“/„Standort“ berücksichtigt Listen; Spalte zeigt „3 Artikel“, „Kategorie: Lastenräder“, „Alle“) | Behebt einen echten Fehler im bestehenden Filter; Mehrfachauswahl und Sortierung der WP-Liste gratis; gut für Admins mit vielen Zeitrahmen | `pre_get_posts` + `meta_query LIKE` ist bei großen Instanzen langsam (gleiches Muster wie im Repository, aber zusätzlich mit Paginierung); Einschränkungen erscheinen dort nicht; Manager-Sicht hängt von den WP-Capabilities der Liste ab |
| **C** | **Link aus dem Frontend-Kalender** für Admins/Manager: Klick auf gesperrten Tag → „gesperrt durch: Urlaub 2026 (bearbeiten)“ | Beantwortet die Frage am Ort der Entdeckung, sogar tagesgenau | Eingriff in die Kalender-Ausgabe (Litepicker-JS, `View\Calendar`, mit Booster zusätzlich `CalendarWindow`/Slots); Cache-Schlüssel hängen dann an der Rolle; aufwendigste Variante; Tagesgenauigkeit erfordert die volle Kalenderberechnung |
| **D** | **Zeilenaktion** „Zeitrahmen anzeigen“ in der Artikel- bzw. Standortliste | Sehr billig; Einstieg aus der Liste ohne Öffnen des Artikels | Kein eigener Inhalt; braucht Ziel A (Anker `#cb-assigned-timeframes`) oder B (vorgefilterte Liste) |
| **E** | Eigene Admin-Seite „Warum gesperrt?“ (Artikel + Datum wählen → Erklärung) | Präzise Diagnose inkl. Buchungen, Vorlaufzeit, Rollen | Neue Seite und Menüpunkt; überschneidet sich stark mit A; eher eine spätere Ausbaustufe |

### Empfehlung

**Stufe 1: A + D, dazu der Filter aus B.**

1. **A als Kern:** Metabox auf Artikel- und Standortseite, gruppiert nach aktuell, zukünftig und abgelaufen,
   mit Zeitrahmen und Einschränkungen.
2. **D als Einstieg:** Zeilenaktion „Zeitrahmen“ in der Artikel- und Standortliste, springt zur Metabox
   (`post.php?post=X&action=edit#cb-assigned-timeframes`).
3. **Aus B nur Filter und Spalte**, weil der bestehende Filter fehlerhaft ist: `admin_filter_item` /
   `admin_filter_location` berücksichtigen zusätzlich `item-id-list` / `location-id-list`. Die Spalte zeigt bei
   Mehrfachauswahl „Kategorie: …“, „Alle“ bzw. „N Artikel“ statt `-`. Die Metabox kann dann zusätzlich auf
   „alle in der Zeitrahmen-Liste anzeigen“ verlinken.

**C** (Frontend-Link) als mögliche Stufe 2. Mit der Metabox als Ziel reicht dort ein einfacher Admin-Link
„Zeitrahmen dieses Artikels“ unter dem Kalender. Das ist ohne Eingriff in die Tagesberechnung machbar.
**E** wird nicht umgesetzt.

Begründung: A beantwortet die Frage vollständig (inkl. Einschränkungen), ist rechtlich am einfachsten abzusichern
und ändert kein bestehendes Verhalten. Der B-Filter ist ein kleiner Fehlerfix mit großem Nutzen. C ist teuer und
berührt den Kalender, der im Booster-Branch ohnehin umgebaut ist.

## 4. Mock-ups

### 4.1 Metabox auf der Artikelseite (Variante A)

```
┌─ Zugeordnete Zeitrahmen ───────────────────────────────────────────────────────────────────┐
│ Lastenrad „Emma“ – buchbar an: Stadtteilladen Nord                                      │
│ [x] Einschränkungen anzeigen   [ ] Entwürfe anzeigen        → in Zeitrahmen-Liste öffnen │
│                                                                                         │
│ AKTUELL (3)                                                                             │
│ Typ          Titel (Status)              Zeitraum               Wiederholung   Raster    Zuordnung               wirkt an      │
│ Buchbar      Emma Nord ✎ (veröffentl.)   01.01.2026 – offen     wöchentl. Mo–Fr Slot 10–18  direkt              Nord          │
│ Feiertage    Weihnachtsurlaub ✎ (veröff.) 22.12.2026 – 02.01.2027 täglich      ganztägig   Artikel: alle ·      Nord          │
│                                                                                        Standorte: Kat. „Läden“ │
│ Einschränkung Bremse defekt ✎ (aktiv)    05.11.2026 – offen     –              –          direkt (Totalausfall) Nord          │
│                                                                                         │
│ ZUKÜNFTIG (1)                                                                           │
│ Reparatur    Inspektion (veröffentl.)    15.03.2027 – 16.03.2027 manuell: 15.3., 16.3. stündl. 9–12  Liste (4 Artikel)   – (Standort nicht gewählt) ⚠ │
│                                                                                         │
│ ▸ ABGELAUFEN (12) – aufklappen                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

Legende:

- ✎ = Titel ist ein Bearbeitungslink, nur wenn `commonsbooking_isCurrentUserAllowedToEdit( $timeframe )`.
  Sonst Titel ohne Link mit Zusatz „(verwaltet von Admin)“.
- „Zuordnung“: `direkt` (Einzelfeld) · `Liste (N Artikel)` · `Kategorie „…“` · `alle`, getrennt für Artikel und Standort.
- „wirkt an“: Schnittmenge aus den Standorten des Zeitrahmens und den Standorten, an denen der Artikel buchbar ist.
  Ist sie leer, wird die Zeile mit ⚠ markiert („zugeordnet, aber an keinem Standort dieses Artikels wirksam“).
  Genau dieser Fall führt in der Praxis oft zu Verwirrung.
- Einschränkungen vom Typ `hint` werden grau angezeigt („nur Hinweis, sperrt nicht“), `solved` unter „abgelaufen“.
- Abgelaufene Einträge sind eingeklappt und auf 20 begrenzt, mit Link „alle in der Liste anzeigen“.

### 4.2 Standortseite

Gleiche Tabelle, Spalte „wirkt an“ wird zu „betrifft Artikel“ (z. B. „alle 14 Artikel dieses Standorts“ oder
„3 von 14: Emma, Paul, Kalle“).

### 4.3 Zeitrahmen-Liste (Fix aus B)

```
Titel              Typ        Artikel                    Standort              Start       Ende
Weihnachtsurlaub   Feiertage  alle (37)                  Kategorie: Läden (5)  22.12.2026  02.01.2027
Inspektion         Reparatur  Emma, Paul, +2             Nord                  15.03.2027  16.03.2027
Emma Nord          Buchbar    Emma                       Nord                  01.01.2026  –
```

Filter „Artikel: Emma“ findet jetzt alle drei Zeilen; bisher nur „Emma Nord“.

### 4.4 Zeilenaktion (D)

```
Emma   Bearbeiten | Papierkorb | Ansehen | Zeitrahmen (4)
```

Die Zahl wird nur angezeigt, wenn sie ohne zusätzliche Abfrage je Zeile ermittelt werden kann (Booster aktiv:
eine gruppierte Abfrage für die ganze Seite). Ohne Booster wird nur „Zeitrahmen“ ohne Zahl angezeigt.

## 5. Rechte

| Rolle | Metabox sichtbar | Zeitrahmen sichtbar | Bearbeitungslink |
|---|---|---|---|
| Admin (`commonsbooking_isCurrentUserAdmin`) | auf jedem Artikel/Standort | alle zugeordneten | immer |
| CB-Manager | nur auf eigenen Artikeln/Standorten (Seite ist bereits durch `commonsbooking_validate_user_on_edit` geschützt) | **alle**, die diesen Artikel betreffen, auch von Admins angelegte | nur wenn `commonsbooking_isCurrentUserAllowedToEdit( $timeframe )` |
| andere | keine Metabox | – | – |

Begründung für „Manager sieht auch fremde Zeitrahmen, nur lesend“: Genau der Admin-Feiertag „alle Artikel“ ist der
typische Fall, den der Manager nicht findet. Ein Zeitrahmen mit Mehrfachauswahl ist für den Manager laut
`Model\Timeframe::getAdmins()` (Schnittmenge der Admins aller Artikel) meist **nicht** sichtbar. Ihn ganz
auszublenden, würde das Feature für Manager wertlos machen.

Datenschutz bei Listen: Andere Artikel und Standorte eines Zeitrahmens zeigen wir dem Manager nur als Anzahl
(„Liste: 37 Artikel“), Namen nur für Posts, die er selbst bearbeiten darf. Kommentare (`comment`) und Autor des
fremden Zeitrahmens werden nicht angezeigt.

Zeilenaktion D: nur, wenn `commonsbooking_isCurrentUserAllowedToEdit( $item )`. Die Liste ist für Manager bereits
auf `getByCurrentUser()` gefiltert (`Item::filterAdminList`).

Filter B: Die Auswahlliste des Filters nutzt für Manager bereits `getByCurrentUser()`. Welche Zeitrahmen ein Manager
in der Liste überhaupt sieht, regeln die bestehenden Capabilities. Daran ändert der Fix nichts.

## 6. Abfragestrategie

Neue Klasse **`Repository\AssignedTimeframes`** (ohne Booster-Abhängigkeit) mit:

```php
AssignedTimeframes::forItem( int $itemId, array $options = [] ): AssignedTimeframesResult
AssignedTimeframes::forLocation( int $locationId, array $options = [] ): AssignedTimeframesResult
```

`$options`: `include_restrictions`, `include_drafts`, `types` (Standard: 2, 3, 5; Buchungen 6/7 nie), `now`
(für Tests). Ergebnis: Einträge mit DTO-Feldern (Typ, Titel, Status, Start, Ende, Wiederholung, Wochentage,
Raster, Zeiten, Zuordnungsart je Seite, Anzahl, „wirkt an“, `can_edit`, Gruppe aktuell/zukünftig/abgelaufen).

### 6.1 Standardweg: `postmeta` (Booster aus)

1. Kandidaten-IDs mit **einer** Abfrage, analog zu `getEntityQuery()`:
   ```sql
   SELECT DISTINCT pm.post_id
   FROM wp_postmeta pm
   WHERE (pm.meta_key = 'item-id'      AND pm.meta_value = %d)
      OR (pm.meta_key = 'item-id-list' AND pm.meta_value LIKE '%:"%d";%')
   ```
   anschließend ein Join auf `type IN (2,3,5)` und `post_status` (Standard: `publish`; optional auch Entwürfe).
   Der LIKE-Ausdruck entspricht dem bestehenden Muster in `Repository\Timeframe`. Die IDs stehen als Strings in
   der Liste, daher `:"12";`. Sicherheitsnetz: falls ein Altbestand Integer serialisiert hat (`i:12;`), wird
   zusätzlich `LIKE '%;i:%d;%'` geprüft. Ob es solche Daten gibt, prüft der Test (6.4).
2. `update_meta_cache( 'post', $ids )` und `_prime_post_caches()`: alle Metadaten in zwei Abfragen.
3. „Wirkt an“: Standorte des Artikels = `item->getLocations()` (buchbare Zeitrahmen, gecacht). Schnittmenge mit
   `getLocationIDs()` des Zeitrahmens. Für den Standort umgekehrt.
4. Einschränkungen: `WP_Query` auf `cb_restriction` mit `meta_query` `restriction-item-id IN (X, 0)` bzw.
   Standort `IN (Standorte von X, 0)`, danach dieselbe Logik wie `Repository\Restriction::filterPosts()`.
   Diese Logik wird dafür als `public static` Methode `Restriction::appliesTo( $post, $locations, $items )`
   herausgezogen, damit sie nicht dupliziert wird.
5. Gruppierung in PHP: abgelaufen = `end < heute 00:00`, zukünftig = `start > heute 23:59`, sonst aktuell.
   Offenes Ende zählt nie als abgelaufen. Einschränkungen mit Status `solved` zählen als abgelaufen.

Ergebnis wird im Plugin-Cache abgelegt (`Plugin::setCacheItem`, Tags `[$itemId]` plus die Zeitrahmen-IDs). Die
bestehende Invalidierung beim Speichern von Zeitrahmen, Artikeln und Einschränkungen greift damit automatisch.
Der Cache-Schlüssel enthält bereits Rolle und Argumente (`Cache::getCacheId`).

### 6.2 Schneller Weg: Booster-Index (nur wenn `Booster::canRead()`)

```sql
SELECT post_id FROM wp_cb_index     WHERE post_type = 'cb_timeframe' AND item_id = %d AND type IN (2,3,5)
UNION
SELECT r.post_id FROM wp_cb_index_rel r JOIN wp_cb_index i ON i.post_id = r.post_id
 WHERE r.entity_type = 'item' AND r.entity_id = %d AND i.type IN (2,3,5)
UNION
SELECT post_id FROM wp_cb_index     WHERE post_type = 'cb_restriction' AND item_id IN (0, %d)
```

Danach dieselben Schritte 2–5 wie oben. Der Index liefert nur die **Kandidaten**, Darstellung und Rechte bleiben
gemeinsamer Code. Die Weiche liegt in einer einzigen privaten Methode `candidateIds()`, alles andere bleibt
identisch. Tests laufen gegen beide Wege und vergleichen die Ergebnisse (Paritätstest).

Die Zahl in der Zeilenaktion (D) gibt es nur auf diesem Weg: eine `GROUP BY entity_id`-Abfrage für alle Artikel
der aktuellen Listen-Seite.

### 6.3 Filter-Fix in der Zeitrahmen-Liste (B)

In `CustomPostType\Timeframe::filterAdminList()` wird für `admin_filter_item` statt `['key' => 'item-id', 'value' => X]`
eine verschachtelte `meta_query` gebaut:

```php
[
  'relation' => 'OR',
  [ 'key' => 'item-id',      'value' => X ],
  [ 'key' => 'item-id-list', 'value' => ':"' . X . '";', 'compare' => 'LIKE' ],
]
```

Mit Booster ersetzt eine `post__in`-Liste aus 6.2 die `meta_query`. Spalte: neuer Fall in `setCustomColumnsData()`
für `item-id`/`location-id`, wenn das Einzelfeld leer, die Liste aber gesetzt ist.

### 6.4 Performance

- Metabox: 1 Kandidatenabfrage + 2 Cache-Primer + 1 Einschränkungsabfrage + `getLocations()` (gecacht).
  Keine Kalenderberechnung, keine Abfrage pro Zeile.
- LIKE auf `meta_value` ist ein Full Scan über die `item-id-list`-Zeilen. Davon gibt es nur so viele, wie es
  Feiertags- und Reparatur-Zeitrahmen gibt (Index `meta_key` grenzt ein). Das ist auch ohne Booster unkritisch.

## 7. Betroffene Dateien

| Datei | Änderung |
|---|---|
| `src/Repository/AssignedTimeframes.php` | **neu**: Kandidatensuche (postmeta / Index), Aufbereitung, Gruppierung, Rechte |
| `src/View/Admin/AssignedTimeframes.php` + `templates/admin/effective-timeframes.php` | **neu**: Rendering der Metabox |
| `src/Wordpress/CustomPostType/Item.php`, `Location.php` | Metabox registrieren (`add_meta_boxes`, nicht CMB2, da nur Anzeige); Zeilenaktion |
| `src/Wordpress/CustomPostType/CustomPostType.php` | `modifyRowActions()` um Aktion „Zeitrahmen“ für Artikel/Standort erweitern |
| `src/Wordpress/CustomPostType/Timeframe.php` | Filter-Fix `filterAdminList()`, Spalte `setCustomColumnsData()` |
| `src/Repository/Restriction.php` | `filterPosts()`-Logik als `appliesTo()` öffentlich machen (verhaltensgleich) |
| `src/Repository/Index.php` | optional kleine Methode `findIdsForEntity( string $type, int $id, array $types )` |
| `assets/admin/css/…` | Gruppen, Einklappen, Grau für Hinweise |
| `languages/commonsbooking-de_DE.po` | neue Strings |
| `tests/php/Repository/AssignedTimeframesTest.php` | **neu** |
| `tests/php/Wordpress/CustomPostType/TimeframeTest.php` | Filter-Fix |

## 8. Testplan

### 8.1 PHPUnit (`tests/php`, `CPTCreationTrait`)

`AssignedTimeframesTest`, jeweils ohne Booster und mit Booster (Paritätstest: gleiche IDs, gleiche Gruppen):

1. Buchbarer Zeitrahmen direkt → erscheint unter „aktuell“, Zuordnung „direkt“.
2. Feiertag mit `item-id-list` [X, Y] → erscheint bei X und Y, nicht bei Z; Zuordnung „Liste (2)“.
3. Feiertag „alle Artikel“ → erscheint bei jedem Artikel; nach Anlegen eines neuen Artikels (Hook
   `updateAllTimeframes`) auch bei diesem.
4. Feiertag per Kategorie → Artikel aus der Kategorie entfernen → verschwindet; hinzufügen → erscheint.
5. Feiertag nur mit anderem Standort → erscheint mit ⚠ „wirkt an keinem Standort“.
6. Gruppen: vergangenes Ende → abgelaufen; Start in Zukunft → zukünftig; offenes Ende → aktuell.
7. ID-Präfixfalle: Artikel 1 darf Zeitrahmen mit Liste [11, 21] nicht finden (LIKE-Muster `:"1";`).
8. Entwurf nur mit `include_drafts`; Buchungen (6/7) nie.
9. Einschränkung mit Artikel 0 / Standort 0 → bei allen; `hint` als nicht sperrend markiert; `solved` → abgelaufen.
10. Gelöschter Artikel in `item-id-list` → keine Warnung, wird nicht als Name angezeigt.
11. Rechte: Manager von Artikel X sieht Admin-Feiertag „alle“, `can_edit = false`, keine fremden Artikelnamen;
    eigener Zeitrahmen → `can_edit = true`. Admin → immer `can_edit = true`.
12. Cache: Zeitrahmen speichern → Ergebnis aktualisiert (Tag-Invalidierung).

`TimeframeTest` (Filter-Fix): `filterAdminList` mit `admin_filter_item = X` liefert Einzel- und Listen-Zeitrahmen;
Spalte zeigt „alle (N)“, „Kategorie: …“, „Liste“.

### 8.2 Manuell

- Admin und CB-Manager, je mit Booster an/aus: Metabox auf Artikel und Standort, Gruppen auf- und zuklappen,
  Bearbeitungslinks, Zeilenaktion springt zum Anker.
- Szenario aus der Aufgabe: Artikel im Frontend an 2 Tagen gesperrt → Artikel im Backend öffnen → Urlaub ist
  unter „aktuell/zukünftig“ mit passendem Zeitraum zu sehen.
- Große Instanz (Benchmark-Daten aus dem Booster): Ladezeit der Artikelseite < 100 ms zusätzlich.
- `composer phpcs` / PHPStan ohne neue Fehler.

## 9. Entscheidungen (07.11.2026)

1. Umgesetzt wird **A + D + Filter-Fix aus B** (Metabox, Zeilenaktion, Filter und Spalte der Zeitrahmen-Liste).
2. CB-Manager sehen fremde Zeitrahmen, die ihre Artikel oder Standorte betreffen, **nur lesend** (wie unter 5).
3. Buchbare Zeitrahmen werden **mit angezeigt**, zusammen mit Feiertagen, Reparatur und Einschränkungen.

## 10. Umsetzungsstand (07.11.2026)

**Begriff:** Die Metabox heißt „Zugeordnete Zeitrahmen“ (englisch „Assigned timeframes“), die Klassen heißen
`AssignedTimeframes`. „Wirksam“ trifft nicht für alle Einträge zu: Gezeigt werden alle zugeordneten Zeitrahmen und
Einschränkungen. Ob ein Eintrag tatsächlich sperrt, zeigt die Spalte „Wirkt an“ („Applies at“), nicht wirksame
Einträge sind mit ⚠ markiert. Oben in der Metabox steht eine kurze Erläuterung dazu.

**Darstellung:** Oben steht eine Leiste mit „Buchbar an“ und dem Button zur gefilterten Zeitrahmen-Liste. Darunter folgen
aufklappbare Gruppen (Aktuell/Zukünftig/Abgelaufen) mit farbigem Punkt und Anzahl. Jeder Eintrag ist eine
Tabellenzeile. Ein farbiger Streifen links und ein Typ-Badge mit Dashicon zeigen die Art: grün = buchbar, gelb =
Feiertag, rot = Blockierung/Totalausfall, blau = Hinweis, grau = nicht wirksam. Status und „nur Administrator“ stehen als
Pills beim Titel. Es werden nur die Farben des WordPress-Admin genutzt. Bei schmaler Breite scrollt die Tabelle
waagerecht.

Umgesetzt:

| Datei | Inhalt |
|---|---|
| `src/Repository/AssignedTimeframes.php` | `forItem()` / `forLocation()`, Kandidatensuche über `postmeta` oder – falls `Index::isAvailable()` – über `cb_index`/`cb_index_rel`, Zuordnungsart, „wirkt an“, Gruppen, `can_edit` |
| `src/View/Admin/AssignedTimeframes.php` | Metabox (Anker `#cb-assigned-timeframes`), Beschriftungen für Wiederholung, Raster, Zuordnung; `assignmentHtml()` auch für die Listenspalte |
| `Item.php`, `Location.php` | Registrierung der Metabox über `add_meta_boxes_{post_type}`; sie steht als erste Box in der Hauptspalte (siehe unten) |
| `CustomPostType.php` | Zeilenaktion „Timeframes“ in Artikel- und Standortliste |
| `Timeframe.php` | Listenfilter Artikel/Standort berücksichtigt `item-id-list`/`location-id-list`; Spalte zeigt „all (N)“, „Category: …“, „List (N)“ |
| `tests/php/Repository/AssignedTimeframesTest.php`, `tests/php/View/AssignedTimeframesViewTest.php` | 17 Tests, grün mit und ohne Booster |
| `languages/commonsbooking-de_DE.po/.mo` | deutsche Übersetzungen der neuen Strings |

**Position der Metabox (07.11.2026):** Die Metabox steht auf Artikel- und Standortseite an erster Stelle der
Hauptspalte, damit man die Zeitrahmen direkt beim Öffnen sieht (Vorgabe des Nutzers). Sie wird mit Priorität `high`
registriert und in `$wp_meta_boxes[…]['normal']['high']` vor die CMB2-Boxen gesetzt, die schon vorher auf
`add_meta_boxes` registriert wurden. Hat ein Nutzer die Boxen per Drag-and-drop umsortiert, gilt weiter seine
gespeicherte Reihenfolge.

Abweichungen vom Konzept:

- **Kein Plugin-Cache** für das Ergebnis: Es sind nur 2–3 Abfragen pro Seitenaufruf, und so gibt es keine Fehler durch veraltete Einträge.
- **Kein eigenes Template**: Die Ausgabe steht direkt in der View-Klasse, weil sie nur aus einer Tabelle besteht (CSS inline in der Metabox).
- **Entwürfe werden immer angezeigt**, mit Status und ⚠ „not published“. Einen Schalter dafür gibt es nicht.
- **Kein Integer-Fallback im LIKE-Muster**: Das Muster `i:N;` ist nicht eindeutig, weil Array-Schlüssel genauso aussehen. Das bestehende Repository prüft ebenfalls nur `:"N";`.
- **Listenfilter ohne Booster-Weg**: Er bleibt eine `meta_query`, weil die Kandidatensuche auf Typ und Status eingeschränkt ist und die Liste sonst verfälschen würde.
- **Keine Anzahl in der Zeilenaktion**.

Offen:

- Für ein Release `vendor-prefixed` neu erzeugen. Die Classmap ist dort autoritativ, neue Klassen werden sonst nicht gefunden.
