# Buchbare Zeitrahmen für mehrere Artikel

Stand: 06.11.2026. Entwurf mit den Entscheidungen des Auftraggebers (siehe „Beschlossen“), noch nicht umgesetzt. Bezieht sich auf `timeframe-index.md`, `availability-precompute.md`
und `booster.md`.

## Ziel

An einem Standort gibt es oft viele gleiche Artikel (z. B. 20 Lastenräder, 30 Werkzeugkisten). Heute braucht jeder
Artikel einen eigenen buchbaren Zeitrahmen mit identischen Einstellungen. Künftig soll ein buchbarer Zeitrahmen
gelten für:

- **einen** Standort und
- **1–N Artikel** (manuelle Liste) **oder**
- **alle Artikel** **oder**
- **eine oder mehrere Artikel-Kategorien** (alle Artikel, die diesen Kategorien zugeordnet sind, auch später
  hinzukommende).

Gebucht wird weiterhin ein einzelner Artikel. Alle Regeln des Zeitrahmens (Raster, Zeiten, max. Tage, Vorlauf,
Rollen, Buchungscodes) gelten für jeden Artikel einzeln, so als gäbe es für jeden einen eigenen Zeitrahmen.

Nicht Ziel: mehrere Standorte in einem buchbaren Zeitrahmen. Ein Artikel ist zu einer Zeit nur an einem Standort
buchbar, ein Zeitrahmen mit mehreren Standorten hätte keine sinnvolle Bedeutung.

## Ausgangslage

Die Mehrfachauswahl ist im Code schon vorhanden, aber nur für Feiertage (`HOLIDAYS_ID`) und Reparatur (`REPAIR_ID`)
freigeschaltet (Issue 507 upstream, `@deprecated 2.9` an `getItem()`/`getLocation()`).

| Baustein | Stand |
|---|---|
| Metadaten `item-select`, `item-id-list`, `item-category-ids` (und für Standorte) | vorhanden, `Model\Timeframe` |
| Formularfelder | vorhanden, `CustomPostType\Timeframe::getCustomFields()` |
| Kategorie/Alle → ID-Liste | `manageTimeframeMeta()`, wird beim Speichern des Zeitrahmens aufgerufen |
| Neuberechnung bei Artikel-/Kategorieänderung | `updateAllTimeframes()` über `Item::savePost()` und `Item::termChange()`, **nur für Feiertage/Reparatur** |
| Sperre für buchbare Zeitrahmen | `timeframe.js` (Zeilen ~246 und ~277) und `removeIrrelevantPostmeta()` (löscht Listen, setzt auf manuell) |
| `getItemIDs()` / `getLocationIDs()` | vorhanden, fallen auf das Einzelfeld zurück |
| Originalabfragen `Repository\Timeframe::getEntityQuery()` | finden `item-id` **oder** Eintrag in `item-id-list` (LIKE auf serialisiertes Array) |
| Index (Booster) | `Projector::fillTimeframe()` setzt `is_multi = 1` und schreibt `cb_index_rel`; `Repository\Index::findPostIds()` fragt beides per UNION ab |
| Invalidator (Booster) | `Invalidator::getScope()` bildet alle Paare aus `cb_index_rel` |

Was **nicht** funktioniert, sobald ein buchbarer Zeitrahmen mehrere Artikel hat:

| Stelle | Problem |
|---|---|
| `Model\Timeframe::isValid()` | prüft nur `getItem()`/`getItemID()`, also keinen oder einen Artikel |
| Reihenfolge in `CustomPostType\Timeframe::savePost()` | `isValid()` läuft **vor** `manageTimeframeMeta()`, bei Kategorie/Alle ist die Liste beim Prüfen noch nicht aktuell |
| `Repository\BookablePost::getRelatedPosts()` / `getTimeframesByRelation()` | arbeitet nur mit dem Einzelfeld `item-id`/`location-id`. Davon hängen `Item::getByLocation()`, `Location::getByItem()`, Karte, Artikelliste, Standortseite und `Item::getClosestBookableTimeframe()` ab. |
| `Repository\BookingCodes::generatePeriod()`, `getCodes()`, `Model\Timeframe::bookingCodesApplicable()` | `$timeframe->getItem()` (dort schon als `TODO #507` markiert) |
| `Model\Timeframe::isUserPrivileged()` | Rechte über `getItem()`/`getLocation()` |
| `Messages\BookingCodesMessage` (Z. 122) | ein Artikel pro Zeitrahmen im Mail-Template |
| `View\BookingCodes` | Tabelle und Versand pro Zeitrahmen, Artikelspalte fehlt |
| `Model\Calendar` (Z. 213–220, API-Slots) | `itemId` aus `$timeframe->getItemID()` |
| `Service\TimeframeExport` (Z. 378) | ein Artikel pro Zeile |
| Admin-Liste Zeitrahmen | Spalte und Filter „Artikel“ lesen nur `item-id` |
| `Availability\Maintenance::getBookablePairs()` (Booster) | liest nur `item_id IS NOT NULL`, Mehrfach-Zeitrahmen fehlen |

Stellen, die über `Booking` gehen (`$booking->getItem()`), bleiben unverändert: Eine Buchung hat immer genau einen
Artikel und einen Standort. `Booking::getBookableTimeFrame()` sucht über `getBookable([loc], [item])` und findet den
Mehrfach-Zeitrahmen schon heute.

## Entscheidungen

| Frage | Vorschlag | Grund |
|---|---|---|
| Ansatz | **nativ**: ein Zeitrahmen-Beitrag mit Artikelliste, kein Erzeugen von Einzel-Zeitrahmen | Passt zur Upstream-Richtung, Index und Invalidator können es schon, keine Synchronisation vieler Kopien. |
| Standort | bei buchbaren Zeitrahmen **nur Einzelauswahl** (`location-id`) | Alle `getLocation()`-Aufrufe bleiben gültig, keine Bedeutungsfrage. |
| Speicherung | genau ein Artikel, manuell → wie heute nur `item-id`. Sonst → `item-id-list` (+ `item-select`, ggf. `item-category-ids`, `item-id-wanted`, `item-id-conflicts`, siehe 2a), `item-id` wird gelöscht | Bestehende Zeitrahmen ändern sich nicht. Index bleibt bei `is_multi = 0` für alle bisherigen Daten. |
| Zugriff im Code | Konsumenten verwenden `getItemIDs()`; `getItemID()` bleibt für Einzel-Zeitrahmen und liefert bei Mehrfach-Zeitrahmen `null` | Eindeutig, kein stilles „erster Artikel“. |
| Wer darf was (beschlossen 06.11.2026) | Mehrfach-Zeitrahmen (Liste, Kategorie, Alle) **nur für Admins**. cb_manager legen wie heute nur Zeitrahmen mit einem Artikel an und können Mehrfach-Zeitrahmen nicht bearbeiten. | Entscheidung Auftraggeber. |
| Bedeutung von „Alle“ (beschlossen) | alle **veröffentlichten** Artikel; kollidierende werden übersprungen (siehe unten) | Entscheidung Auftraggeber. |
| Konflikte (beschlossen) | gilt für **alle** Auswahlarten, auch die manuelle Liste: kollidierende Artikel werden übersprungen, nicht in `item-id-list` übernommen, sondern in `item-id-conflicts` gemerkt. Beim Speichern erscheint ein Hinweis mit jedem übersprungenen Artikel und dem Zeitrahmen, mit dem er verknüpft ist. Fällt der Konflikt später weg, rückt der Artikel automatisch nach (Abschnitt „Automatisches Nachrücken“). | Entscheidung Auftraggeber. Einheitliches Verhalten, Speichern scheitert nie wegen einzelner Artikel. |
| Was ist ein Konflikt | Ein anderer veröffentlichter buchbarer Zeitrahmen mit demselben Artikel an einem **anderen** Standort, der sich mit dem **verbleibenden** Zeitraum dieses Zeitrahmens überschneidet, also mit `[max(heute, Start), Ende]`. Am **selben** Standort gilt wie heute die Prüfung auf Raster und Zeiten. | Nur mit dem verbleibenden Zeitraum kann ein abgelaufener Zeitrahmen den Konflikt auflösen. Sonst bliebe die Überschneidung in der Vergangenheit für immer bestehen. |
| Konflikte am selben Standort | (zweiter Zeitrahmen für dasselbe Paar mit anderem Raster oder überlappenden Zeiten) Artikel wird ebenfalls übersprungen und im Hinweis genannt | Gleiches Verhalten wie bei anderen Standorten. |
| Artikel fällt aus Liste/Kategorie | erlaubt; bestehende künftige Buchungen dieses Artikels am Standort werden im Admin-Hinweis genannt (nicht storniert) | Entspricht dem heutigen Verhalten beim Löschen/Ändern eines Zeitrahmens. |
| Überlappung zweier Zeitrahmen am selben Standort | wie heute pro Paar: gleiches Raster nötig, keine überlappenden Zeiten | Die bestehende Prüfung wird nur pro Artikel ausgeführt. |
| Buchungscodes | pro Artikel und Tag (Tabelle hat schon `item`, `location`, `timeframe`) | Jeder Artikel braucht eigene Codes. |
| Buchungscode-Versand (beschlossen) | **ein** Dokument (eine Mail, eine iCal-Datei) mit allen Artikeln des Zeitrahmens, nach Artikel gruppiert | Entscheidung Auftraggeber. |

## Änderungen im Einzelnen

### 1. Formular und Speichern

- Nur Admins (`commonsbooking_isCurrentUserAdmin()`) sehen die neuen Felder. Für cb_manager bleibt das Formular wie
  heute; serverseitig verwirft `savePost()` Listen- und Auswahlfelder von Nicht-Admins. Mehrfach-Zeitrahmen sind für
  cb_manager nicht bearbeitbar (`commonsbooking_isUserAllowedToEdit` → `false`), in der Admin-Liste aber sichtbar,
  wenn der Standort ihnen zugeordnet ist.
- `timeframe.js`: Für `BOOKABLE_ID` die Artikel-Auswahlart (manuell/Kategorie/Alle) und die Mehrfachlisten
  anzeigen. Bei manuell: Mehrfachauswahl statt Einzelauswahl (eine Auswahl → wird als `item-id` gespeichert).
  Standortfelder für buchbare Zeitrahmen fest auf Einzelauswahl.
- `removeIrrelevantPostmeta()`: Artikellisten für `BOOKABLE_ID` behalten, Standortlisten und
  `location-select ≠ manuell` weiterhin entfernen.
- `savePost()`: Reihenfolge ändern: **erst** `manageTimeframeMeta()` (ID-Liste ermitteln, Konflikte aussortieren),
  **dann** `isValid()`.
- `manageTimeframeMeta()`: bei buchbaren Zeitrahmen Konflikte ermitteln (siehe 2) und Liste normalisieren
  (genau ein Artikel manuell → `item-id`).
- `updateAllTimeframes()`: auch `BOOKABLE_ID` einbeziehen, aber nur Zeitrahmen mit `item-select ∈ {Kategorie, Alle}`
  laden (heute lädt sie alle Feiertage/Reparaturen). Mit Booster über den Index: `type` + Metafeld, sonst eine
  `meta_query` auf `item-select`.

### 2. Validierung (`Model\Timeframe::isValid()`)

- Neue Hilfsmethode `Timeframe::findItemConflicts(int[] $itemIds): array<itemId, Timeframe>`. Sie lädt einmal
  `getBookable([], $itemIds, …)` und prüft im Speicher pro Artikel die beiden bestehenden Regeln (anderer Standort
  im verbleibenden Zeitraum; selber Standort mit anderem Raster oder überlappenden Zeiten). Statt N Einzelabfragen.
- `manageTimeframeMeta()` ruft sie auf: Gewünschte Artikel (manuelle Auswahl, Kategorie oder Alle) werden in
  `item-id-wanted` gespeichert, nur die konfliktfreien in `item-id-list`, die übrigen mit dem auslösenden
  Zeitrahmen in `item-id-conflicts` (`[itemId => timeframeId]`).
- `isValid()` prüft bei Mehrfach-Zeitrahmen nur noch: `getItemIDs()` nicht leer. Sind **alle** Artikel übersprungen,
  ist das ein Fehler (Zeitrahmen ohne buchbaren Artikel), mit derselben Liste im Text.
- Hinweis beim Speichern: Transient wie bei `BookingCode::ERROR_TYPE`, als Admin-Hinweis vom Typ „warning“:
  „Folgende Artikel sind bereits in anderen Zeitrahmen verknüpft und wurden nicht berücksichtigt: Artikel A
  (Zeitrahmen 123: Titel, bis 31.03.2027), …“. Dazu ein dauerhafter Kasten in der Seitenleiste des Zeitrahmens,
  solange `item-id-conflicts` nicht leer ist, mit dem Satz „Sie werden automatisch ergänzt, sobald der andere
  Zeitrahmen endet oder entfernt wird.“
- Zeitrahmen mit einem Artikel (heutiges Verhalten, auch für cb_manager) bleiben bei der harten Prüfung mit Fehler.
- Die Buchungsprüfung (Booster: immer Originalabfragen) braucht keine Änderung.

### 2a. Automatisches Nachrücken übersprungener Artikel

Ein übersprungener Artikel soll automatisch in den Zeitrahmen aufgenommen werden, sobald der Konflikt wegfällt.

**Metadaten** (nur bei Mehrfach-Zeitrahmen):

| Feld | Inhalt |
|---|---|
| `item-select`, `item-category-ids` | wie heute |
| `item-id-wanted` | nur bei manueller Liste: die vom Admin gewählten Artikel. Bei Kategorie/Alle wird die Wunschliste jedes Mal neu ermittelt. |
| `item-id-list` | tatsächlich gebundene, konfliktfreie Artikel. Nur dieses Feld lesen Abfragen, Index und `getItemIDs()`. |
| `item-id-conflicts` | `[itemId => timeframeId]` der übersprungenen Artikel |

**Neuberechnung** = `manageTimeframeMeta()` für einen Zeitrahmen: Wunschliste ermitteln, Konflikte bestimmen,
`item-id-list` und `item-id-conflicts` nur schreiben, wenn sie sich ändern (sonst unnötige Index-Invalidierung).

**Auslöser:**

| Ereignis | Was neu berechnet wird |
|---|---|
| Zeitrahmen mit Konflikten wird gespeichert | dieser Zeitrahmen |
| Ein buchbarer Zeitrahmen wird gelöscht, in den Papierkorb gelegt, auf Entwurf gesetzt oder gespeichert (Enddatum, Artikel, Standort, Typ geändert) | alle Zeitrahmen, deren `item-id-conflicts` auf ihn zeigt |
| Ein Zeitrahmen läuft ab | täglicher Cron (`commonsbooking_timeframe_conflicts`, kurz nach Mitternacht Ortszeit): alle Zeitrahmen mit nicht leerem `item-id-conflicts` |
| Artikel gespeichert, Kategorie geändert (`updateAllTimeframes()`) | alle Mehrfach-Zeitrahmen mit Kategorie/Alle, und alle mit Konflikten |
| Manuell | `wp commonsbooking timeframe conflicts [--fix]` (anzeigen bzw. neu berechnen) |

Ablauf wird über den Konfliktbegriff „verbleibender Zeitraum“ erkannt: Endet Zeitrahmen A am 31.03., überschneidet
er sich ab dem 01.04. nicht mehr mit `[heute, Ende]` von Zeitrahmen B, der Cron nimmt den Artikel in B auf.
Damit ist der Artikel frühestens ab dem Tag nach dem Ende von A in B buchbar, vorher nicht. Ein früheres
Buchbarmachen (Artikel in B nur ab 01.04.) würde bedeuten, dass ein Zeitrahmen pro Artikel unterschiedliche
Zeiträume hat; das ist bewusst nicht vorgesehen.

Die Zuordnung der Zeitrahmen mit Konflikten wird über eine `meta_query` auf `item-id-conflicts` (`EXISTS`) gefunden.
Davon gibt es wenige, der Rückwärtsbezug auf den auslösenden Zeitrahmen wird im Speicher gefiltert.

**Mehrere Bewerber:** Wollen zwei Mehrfach-Zeitrahmen an verschiedenen Standorten denselben Artikel, behält ihn der
Zeitrahmen, der ihn schon gebunden hat. Werden beide in einem Lauf neu berechnet, gewinnt der mit der kleineren ID.
Die Prüfung schaut nur auf `item-id-list` anderer Zeitrahmen, nie auf deren Wunschlisten, daher keine gegenseitige
Blockade.

**Folgen beim Nachrücken:**
- Index und vorberechnete Verfügbarkeit werden über die normalen Hooks aktualisiert (`updated_post_meta` →
  `Indexer` → `Invalidator`). Der Cron läuft außerhalb einer Anfrage, die Paare werden dort sofort berechnet.
- Plugin-Cache des Zeitrahmens und der betroffenen Artikel leeren.
- Buchungscodes für den neuen Artikel erzeugen (`BookingCodes::generate()` ist pro Artikel idempotent zu machen).
- Eintrag im Aktivitätsprotokoll der Booster-Diagnose bzw. im Debug-Log. Keine Mail.

**Hinweis beim Speichern** des Zeitrahmens: siehe Abschnitt 2. Zusätzlich zeigt die Admin-Liste in der Spalte
„Artikel“ „12 Artikel, 3 übersprungen“.

### 3. Zuordnung Artikel ↔ Standort (`Repository\BookablePost`)

- `getTimeframesByRelation()`: Ohne Index zusätzlich `item-id-list LIKE '%:"<id>";%'` abfragen (wie
  `getEntityQuery()`). Mit Index entfällt der Filter auf das Einzelfeld; stattdessen gegen
  `getItemIDs()`/`getLocationIDs()` prüfen.
- `getRelatedPosts()`: Verwandte IDs aus `getItemIDs()`/`getLocationIDs()` statt aus `get_post_meta(…-id)`.
- Achtung: `getTimeframesByRelation()` liefert Zeitrahmen **aller** Typen. Damit Feiertage mit Artikellisten
  nicht plötzlich Artikel-Standort-Beziehungen erzeugen, die es heute nicht gibt, gilt die Erweiterung nur für
  `BOOKABLE_ID` (heute liefern Feiertage über das Einzelfeld ebenfalls eine Beziehung, das bleibt so).
- Damit funktionieren `Item::getByLocation()`, `Location::getByItem()`, Karte, Shortcodes `cb_items`/`cb_locations`
  und `Item::getClosestBookableTimeframe()`.

### 4. Buchungscodes

- `BookingCodes::generatePeriod()`: Schleife über `getItemIDs()`. Die Codes werden pro Artikel aus der Codeliste
  gezogen, wie heute pro Zeitrahmen.
- `BookingCodes::getCodes()`: `WHERE item IN (…)` statt `item = %d`, Ergebnis nach Artikel und Datum sortiert.
- `View\BookingCodes` und `Messages\BookingCodesMessage`: **eine** Mail bzw. **eine** iCal-Datei pro Zeitrahmen mit
  allen Artikeln, nach Artikel gruppiert (Tabelle: Datum × Artikel oder Abschnitt pro Artikel). Das Mail-Template
  bekommt neben `item` (bei Einzel-Zeitrahmen unverändert) eine Variable `items`; das Standard-Template gibt die
  Artikelnamen aus. Bestehende angepasste Templates mit `{{item:post_title}}` zeigen bei Mehrfach-Zeitrahmen die
  Artikelliste als Text.
- `BookingCodes::backwardCompatibilityFilter()`: unverändert, gilt pro Artikel.

### 5. API, Export, Admin-Liste

- `Model\Calendar` (API-Slots): pro Artikel ein Slot. Achtung: Hier wird über Zeitrahmen iteriert, die für einen
  bestimmten Artikel abgefragt wurden. Der Slot bekommt den **abgefragten** Artikel, nicht alle Artikel des
  Zeitrahmens.
- `TimeframeExport`: pro Artikel eine Zeile (Spalten bleiben gleich), Option „zusammengefasst“ nicht nötig.
- Admin-Liste: Spalte „Artikel“ zeigt den Artikel bzw. „Kategorie: X (12 Artikel)“, „Alle (48 Artikel)“ oder
  „5 Artikel“ mit Tooltip. Filter „Artikel“ verwendet `getEntityQuery()` statt der Einzelmeta-Abfrage
  (`filterAdminList`, Zeilen ~1300–1330).
- `Item::getLocation()` und GBFS bleiben unverändert (Standort ist einzeln).

### 6. Booster

| Baustein | Änderung |
|---|---|
| `Index\Projector` | keine. Neues Metafeld `item-id-conflicts` braucht keine Projektion. |
| `Index\Verifier` | keine, vergleicht schon `cb_index_rel`. Testfälle ergänzen. |
| `Availability\Invalidator::getScope()` | keine. Bei Änderung eines Zeitrahmens mit 50 Artikeln werden 50 Paare veraltet; über dem Sync-Limit übernimmt der Cron. |
| `Availability\Maintenance::getBookablePairs()` | UNION mit `cb_index_rel` (Artikel × Standort für `is_multi = 1`). Ohne diese Änderung fehlen die Paare in `syncPairs()`, der Abdeckung und der Diagnose. |
| Invalidierung beim **Entfernen** eines Artikels aus der Liste | keine Änderung: `Projector` übergibt mit `Projector::load()` die alte Zeile samt Relationsliste, `onIndexChange($old, $new)` markiert beide Scopes. Nur Test ergänzen. |
| `updateAllTimeframes()` bei jedem Speichern eines Artikels | Schreibt `item-id-list` neu, auch wenn sich nichts ändert → Indexänderung → Invalidierung aller Paare. Nur schreiben, wenn sich die Liste wirklich ändert. |
| Diagnose (`Booster\Diagnostics`) | Neuer Check „Zeitrahmen mit ausgelassenen Artikeln“ (aus `item-id-conflicts`), Anzahl Mehrfach-Zeitrahmen und Paare. |
| Downgrade (`booster.md`) | Siehe Risiken: Eine Originalversion kann Mehrfach-Zeitrahmen nicht anzeigen. |

### 7. Werkzeug zum Aufteilen

`wp commonsbooking timeframe split <id>` (und Massenaktion in der Admin-Liste): erzeugt aus einem
Mehrfach-Zeitrahmen N Einzel-Zeitrahmen mit gleichen Einstellungen und setzt den Mehrfach-Zeitrahmen auf Entwurf.
Buchungen müssen nicht angepasst werden, weil sie über Artikel und Standort zugeordnet sind. Gegenstück
`merge` ist nicht nötig. Zweck: Downgrade auf die Originalversion und Ausweg, falls ein Artikel abweichende
Regeln braucht.

## Phasen und Aufwand (Schätzung)

| Phase | Inhalt | Aufwand |
|---|---|---|
| 1 | Formular (nur Admins), Speichern (Reihenfolge, Normalisierung, `updateAllTimeframes`), Konflikte überspringen, Hinweis beim Speichern | 1,5 Tage |
| 1a | Automatisches Nachrücken: Auslöser bei Zeitrahmen-Änderungen, täglicher Cron, CLI `conflicts` | 1 Tag |
| 2 | Artikel ↔ Standort in `BookablePost` (mit und ohne Index), Karte und Listen prüfen | 1 Tag |
| 3 | Buchungscodes (Erzeugen, Anzeige, Mail, iCal) | 1 Tag |
| 4 | API-Slots, Export, Admin-Spalte und -Filter | 0,5 Tage |
| 5 | Booster: `getBookablePairs()`, Invalidierung beim Entfernen, Diagnose | 0,5 Tage |
| 6 | `split`-Befehl, Übersetzungen, Tests | 1 Tag |

## Tests

- `TimeframeVariants`: neue Varianten „3 Artikel manuell“, „Kategorie“, „Alle mit einem kollidierenden Artikel“,
  „Mehrfach + überlappender Einzel-Zeitrahmen am selben Standort“. `TimeframeVariantsParityTest` vergleicht Kalender,
  vorberechnete Tage und Kartendaten mit Booster an/aus. Paritätsreferenz ist hier nicht der Originalcode (der
  kennt das Feature nicht), sondern dieselbe Konfiguration als N Einzel-Zeitrahmen.
- Konflikte: manuelle Liste, Kategorie und Alle mit kollidierendem Artikel → Artikel übersprungen, Hinweis enthält
  Artikel und Zeitrahmen; alle Artikel kollidieren → Fehler; Einzel-Zeitrahmen mit Konflikt → Fehler wie heute.
- Konflikt nur in der Vergangenheit (anderer Zeitrahmen endete gestern) → kein Konflikt.
- Nachrücken: anderer Zeitrahmen gelöscht, in den Papierkorb, auf Entwurf, Enddatum verkürzt, Artikel entfernt →
  Artikel sofort aufgenommen; anderer Zeitrahmen läuft ab → Cron nimmt Artikel auf (Zeit per Filter vorstellen).
  Danach: Index, vorberechnete Verfügbarkeit, Karte und Buchungscodes enthalten den Artikel.
- Zwei Mehrfach-Zeitrahmen wollen denselben Artikel → genau einer bekommt ihn, der andere nach Ende.
- Dynamik: Artikel bekommt Kategorie → wird buchbar; Kategorie entfernt → Paar wird nicht mehr als verfügbar
  vorberechnet.
- Rechte: cb_manager sieht keine Mehrfachfelder, gesendete Listenfelder werden verworfen, Bearbeiten eines
  Mehrfach-Zeitrahmens wird verweigert.
- Buchungscodes: N Artikel × Tage, Anzeige, eine Mail und eine iCal-Datei mit allen Artikeln.
- Buchung eines Artikels aus Mehrfach-Zeitrahmen, Doppelbuchungsschutz, Stornierung.
- `split`: erzeugt N Zeitrahmen, Verfügbarkeit vorher und nachher gleich.

## Risiken

- **Downgrade auf die Originalversion**: Dort fehlt `item-id`, `getItem()` liefert `null`. Zeitrahmen erscheinen
  nicht auf der Karte, Kalender und Buchungscodes brechen, beim erneuten Speichern löscht
  `removeIrrelevantPostmeta()` die Liste. Vor einem Downgrade müssen Mehrfach-Zeitrahmen mit `split` aufgeteilt
  werden. Der Wächter (`Guard`) kann das nicht abfangen; Hinweis in `booster.md` und beim Ausschalten des Boosters.
- **Upstream**: Weicht die spätere Upstream-Lösung für Issue 507 ab (z. B. Relationstabelle statt
  serialisierter Liste), ist eine Migration nötig. Die Datenhaltung hier nutzt bewusst die vorhandenen Upstream-Felder.
- **Ohne Booster** wird `item-id-list` per `LIKE` auf ein serialisiertes Array gesucht. Bei „Alle“ mit vielen
  Artikeln ist der Metawert groß und die Suche langsam, aber korrekt. Mit Booster spielt das keine Rolle.
- **Plugin-Cache**: Cache-Tags kommen aus `getItemIDs()` (`Helper\Wordpress::getRelatedPostIds`) und decken alle
  Artikel ab. Geleert wird aber nur in `Plugin::savePostActions()` (Hook `save_post`). `updateAllTimeframes()`
  ändert die Liste nur per `update_post_meta`, der Zeitrahmen selbst wird nicht gespeichert. Ändert sich die Liste
  dort, muss `Plugin::clearCache()` mit den Tags des Zeitrahmens (alte **und** neue Artikel) aufgerufen werden.
  Der Booster-Index ist davon nicht betroffen (`Indexer` hört auf `updated_post_meta`), und der Invalidator bekommt
  die alte Relationsliste über `Projector::load()` mit.
- **Rechte**: `Model\Timeframe::isUserPrivileged()` (Z. ~285) prüft `getItem()` **oder** `getLocation()`. Für
  Mehrfach-Zeitrahmen: privilegiert, wenn der Nutzer den Standort verwaltet **oder** den gebuchten Artikel (der
  Artikel muss dafür als Parameter hereinkommen). Das betrifft Buchungsrechte, nicht das Bearbeiten: Bearbeiten
  von Mehrfach-Zeitrahmen ist nur Admins erlaubt (beschlossen).
- **Nachrücken erst nach Ablauf**: Ein übersprungener Artikel ist im neuen Zeitrahmen erst buchbar, wenn der alte
  Zeitrahmen abgelaufen ist (Cron am Folgetag). Vorausbuchungen für die Zeit danach sind vorher nicht möglich.
  Wer das braucht, beendet den alten Zeitrahmen früher oder legt für den Artikel einen eigenen Zeitrahmen an.
- **Cron fällt aus**: Ohne WP-Cron (z. B. `DISABLE_WP_CRON` ohne System-Cron) rücken Artikel nach Ablauf nicht nach.
  Die Booster-Diagnose zeigt Zeitrahmen mit Konflikten, deren auslösender Zeitrahmen schon abgelaufen ist.
- **`bookingCodesApplicable()`** (Z. ~418) verlangt `getItem()`. Umstellen auf `getItemIDs() !== []`, sonst
  werden für Mehrfach-Zeitrahmen still keine Codes erzeugt.

## Beschlossen (06.11.2026)

1. „Alle“ = alle veröffentlichten Artikel.
2. Artikel, die schon in anderen Zeitrahmen verknüpft sind, werden übersprungen. Beim Speichern erscheint ein
   Hinweis mit diesen Artikeln. Fällt der Konflikt später weg (Zeitrahmen abgelaufen, gelöscht usw.), rücken sie
   automatisch nach (Abschnitt 2a).
3. Buchungscodes: ein Dokument mit allen Artikeln.
4. cb_manager dürfen keine Mehrfach-Zeitrahmen anlegen.
