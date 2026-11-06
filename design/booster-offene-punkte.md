# Booster: Lösungen für die offenen Punkte

Stand 2026-11-06. Betrifft die drei offenen Punkte aus `technische-dokumentation-booster.md` (Abschnitt „Offen“).
Alle drei sind umgesetzt (nicht committet), Abweichungen vom Plan siehe „Umsetzung“ am Ende.

| # | Punkt | Lösung kurz | Aufwand |
|---|---|---|---|
| 1 | API/GBFS lesen nicht aus der Vorberechnung | Freie Slots je Tag als zweite Spalte `slots` in derselben Zeile speichern, `getAvailabilitySlots()` liest daraus | 1,5–2 Tage |
| 2 | `Booking::getForUser()` lädt alle Buchungen | Kandidaten per SQL holen: eigene Buchungen + Buchungen verwalteter Artikel/Standorte; Regelprüfung nur eigene unbestätigte | 0,5–1 Tag |
| 3 | `<span'is-locked'>` in der Tabelle | Markup aus `TABLE_STATUS_MARKUP[LOCKED]` verwenden, Snapshot bewusst neu aufzeichnen | 1 Stunde |

---

## 1. API und GBFS aus der Vorberechnung

### Ausgangslage

`Model\Calendar::getAvailabilitySlots()` hat drei Aufrufer:

| Aufrufer | Zeitraum | Paare | Besonderheit |
|---|---|---|---|
| `API\AvailabilityRoute::getItemData()` | heute bis +N Wochen (`api_future_availability_weeks`) | alle Artikel, alle Standorte | – |
| `API\GBFS\VehicleStatus::getAvailableUntil()` | gestern bis erster buchbarer Tag + `maxDays` | ein Paar | `ignoreStartDayOffset` |
| `Model\Item::isCurrentlyFreeAtLocation()` (GBFS Station/Vehicle Status) | gestern bis morgen | ein Paar | `ignoreStartDayOffset`, `ignoreRestrictions` je nach Aufruf |

Die Methode baut für jeden Tag das komplette Slot-Raster (`Day::getGrid()`), behält Slots aus buchbaren Zeitrahmen,
verwirft Tage vor `getFirstBookableDay()` des Zeitrahmens und gibt je freiem Slot `start`, `end`, `locationId`,
`itemId` aus (dedupliziert).

Die gespeicherten Tagesfakten (`DayFacts`, 2 Byte je Tag) reichen dafür nicht: „teilweise gebucht“ sagt nicht,
**welche** Stunden frei sind.

### Lösung: Slot-Spalte in der bestehenden Zeile

Neue Spalte in `cb_availability`:

```sql
slots mediumblob NULL
```

Sie liegt bewusst in **derselben Zeile** wie `days`. Damit gelten Generation (`gen`/`computed_gen`), Invalidierung,
`calc_version`, `has_role_limit`, Cron und Verifier ohne weiteren Code auch für die Slots. Es gibt keinen zweiten
Zustand, der auseinanderlaufen kann.

**Format** (gepackt, little endian):

```
Kopf:     uint8 Anzahl Zeitrahmen T, dann T × uint32 Zeitrahmen-ID
je Tag:   uint8 Anzahl Slots S, dann S × (int32 startOffset, int32 endOffset, uint8 Zeitrahmen-Index)
```

- `startOffset`/`endOffset` = `slot['timestampstart'|'timestampend']` minus Tages-Zeitstempel, so wie der bestehende
  Code ihn berechnet. Damit bleiben die Eigenheiten der Zeitstempel (lokale Zeit als UTC) und Sommerzeitwechsel exakt
  erhalten, statt sie nachzubauen.
- Gespeichert werden nur Slots, die `getAvailabilitySlots()` heute ausgeben würde: buchbarer Zeitrahmen, nicht
  gesperrt, nicht gebucht, Einschränkungen berücksichtigt. Die Prüfung `getFirstBookableDay()` hängt von „heute“ ab
  und passiert deshalb beim **Lesen** (über den Zeitrahmen-Index → `startday_offset` aus dem Index).
- Größe: Tagesslot ≈ 10 Byte/Tag ≈ 1 KB je Paar bei 92 Tagen; Stundenraster im schlechtesten Fall
  24 × 9 Byte ≈ 20 KB je Paar. `mediumblob`, weil `blob` (64 KB) bei Halbstundenrastern knapp würde.

**Schreiben:** `Calculator` hat das Raster beim Berechnen der `DayFacts` ohnehin in der Hand (`DayFacts::fromGrid()`).
Ein neuer `SlotPacker` packt aus demselben Raster die freien Slots. Kein zusätzlicher Rechenschritt, nur ein zweites
Ergebnis. `calc_version` wird erhöht, damit alle Zeilen einmal neu berechnet werden.

**Lesen:** Neuer `Service\Availability\SlotReader`. `Model\Calendar::getAvailabilitySlots()` delegiert, wenn

- `Availability::isEnabled()` (Booster an, Index bereit),
- `ignoreRestrictions` **nicht** gesetzt ist (Variante ist nicht gespeichert → live),
- das Paar nicht `has_role_limit` hat (wie bisher live),
- die Zeile nutzbar ist (`Store::isUsable()`, Generation aktuell).

Tage außerhalb des Horizonts (92 Tage, API-Einstellung kann mehr Wochen verlangen) werden für diesen Teilbereich live
berechnet: Live-`Calendar` nur für den Rest-Zeitraum, Ergebnisse zusammenführen, dann deduplizieren wie bisher.
`ignoreStartDayOffset` ist nur ein Schalter beim Lesen, braucht also keine eigene Variante.

`AvailabilityRoute` lädt vorher alle Paare mit einem `Store::preload()` (eine Query statt einer je Paar).

### Absicherung

- Paritätstest wie in Stufe 3: `getAvailabilitySlots()` live gegen gespeichert für alle Snapshot-Szenarien,
  mit `ClockMock` über Mitternacht und Sommerzeitwechsel, mit und ohne `ignoreStartDayOffset`.
- `Verifier` (`wp commonsbooking availability verify`) vergleicht in der Stichprobe zusätzlich die Slots.
- Messung: GBFS Vehicle Status / Station Status und `/availability` vorher/nachher (Basis: 1,02 s / 0,58 s nach
  Stufe 2).

### Verworfene Alternative

Slots beim Lesen aus Tagesfakten + Zeitrahmen-Konfiguration (Index: `grid`, `start_time`, `end_time`) ableiten und
nur bei „teilweise gebucht“ live rechnen. Spart Speicher, baut aber die Raster-Logik ein zweites Mal nach. Das
widerspricht dem Grundsatz „ein Rechenkern“ aus Stufe 3.

---

## 2. `Booking::getForUser()` ohne Vollscan

### Ausgangslage

`getForUser()` lädt **alle** Buchungen der Instanz (mit Status und `minTimestamp`) und filtert in PHP mit
`commonsbooking_isUserAllowedToSee()`. Sichtbar ist eine Buchung, wenn

1. der Nutzer CB-Admin ist (`commonsbooking_isUserAdmin()`, inkl. Filter), oder
2. er Autor der Buchung ist, oder
3. er Admin des Artikels **oder** des Standorts ist (`Timeframe::getAdmins()` = Vereinigung aus
   `_cb_item_admins`/`_cb_location_admins` plus jeweils `post_author` von Artikel bzw. Standort).

Aufrufer: `View\Booking::getBookingListData()` („Meine Buchungen“, bis zu 4× bei leerem Ergebnis) und
`BookingRuleApplied` (unbestätigte Buchungen).

### Lösung

**a) Kandidaten per SQL statt Vollscan.** Neue private Methode `getVisibleCandidates()`:

```
wenn isUserAdmin($user):  wie bisher alle Buchungen (Ergebnis ist ohnehin „alle“)
sonst:
  $items     = IDs der Artikel mit post_author = user ODER Admin-Meta enthält user
  $locations = IDs der Standorte, ebenso
  Kandidaten = Buchungen mit  post_author = user
                         ODER item_id     IN $items
                         ODER location_id IN $locations
               (Typ BOOKING_ID, $postStatus, Ende ≥ minTimestamp)
```

- Verwaltete Artikel/Standorte: eine Query je Post-Typ. Die Admin-Meta ist serialisiert **oder** ein einzelner String
  (siehe `Location::getAdmins()`), also `meta_value LIKE '%"ID"%' OR meta_value = 'ID'`. Alle Post-Status, weil
  `getAdmins()` den Status auch nicht prüft. `BookablePost::getByCurrentUser()` deckt das nur für den aktuellen Nutzer
  und nur mit dem `LIKE` ab, deshalb nicht direkt wiederverwenden.
- Mit Index: drei `Index::findPostIds()`-Aufrufe (Kriterien `authors`, `items`, `locations`; Schlüssel
  `author_type`, `ptype_item`, `ptype_location`), Vereinigung in PHP. Drei einfache Abfragen statt eines `OR`, weil
  MySQL beim `OR` über drei Spalten oft keinen Index nutzt.
- Ohne Index: dieselben drei Abfragen über `Timeframe::get()` (Artikel- bzw. Standortfilter) und eine
  `WP_Query` mit `author`. Damit profitiert auch die Installation ohne Booster.
- Danach läuft **weiterhin** `commonsbooking_isUserAllowedToSee()` über die Kandidaten. Die Kandidatenmenge ist eine
  Obermenge der sichtbaren Buchungen, das Ergebnis bleibt also exakt gleich; der Filter kostet jetzt nur noch
  proportional zu den Kandidaten.

**b) `BookingRuleApplied` braucht nur eigene Buchungen.** Der Aufruf holt heute alle sichtbaren unbestätigten
Buchungen und wirft die fremden danach weg (Kommentar im Code). Ersetzen durch eine direkte Abfrage
`author = current user, status = unconfirmed` (wie `getForUserPaginated()`). Bewusst über `WP_Query`, nicht über den
Index, weil die Buchungsprüfung laut Booster-Design immer die Originalabfragen benutzt. Ergebnis identisch, Aufwand
konstant.

**c) Später (optional):** Für CB-Admins lädt „Meine Buchungen“ weiterhin alle Buchungen ab `startDate` und paginiert
in PHP. Sortierung, Filter (Artikel, Standort, Status, Nutzer) und Seitenbildung lassen sich über den Index in SQL
verlagern (`start_ts`, `item_id`, `location_id`, `post_status`, `post_author` sind vorhanden). Das ändert aber die
Filterlisten-Logik der Ansicht und ist ein eigener Schritt.

### Absicherung

- Test: für Gast, Abonnent (Autor), Artikel-Admin, Standort-Admin, Admin über `post_author` des Standorts,
  Admin mit String-Meta, CB-Admin: Ergebnis alt gegen neu identisch (IDs und Reihenfolge), mit und ohne Index.
- Test `BookingRuleApplied`: fremde unbestätigte Buchung an verwaltetem Artikel blockiert nicht, eigene schon.
- Messung „Meine Buchungen“ als Abonnent und als Standort-Admin bei 10× Daten.

---

## 3. `<span'is-locked'>` in der Artikeltabelle

### Ausgangslage

`View\Calendar::renderItemLocationRow()` füllt Tage nach dem Ende des Statusbereichs (Vorausbuchungsgrenze, Ende des
Zeitrahmens) mit `"<span'is-locked'></span>"`. Browser lesen das als unbekanntes Element `span'is-locked'` **ohne
Klasse**. Die Zellen erscheinen leer statt grau gesperrt, obwohl der Kommentar darüber „not available“ meint und die
CSS-Regel `.is-locked` in `_shortcodeItemsTable.scss` dafür existiert.

### Lösung

```php
$days_display[ $dayIterator ] = self::TABLE_STATUS_MARKUP[ DayStatus::LOCKED ];
```

- Sichtbare Änderung: Tage hinter der Vorausbuchungsgrenze werden in `[cb_items_table]` grau statt leer angezeigt.
  Das ist das ursprünglich beabsichtigte Verhalten, sollte aber im Changelog stehen.
- Der Snapshot `tests/php/View/snapshots/calendar-core.json` enthält das alte Markup. Ihn **gezielt** neu aufzeichnen
  und im Diff prüfen, dass sich ausschließlich diese Zellen ändern (`grep -c "span'is-locked'"` vorher > 0,
  nachher 0; sonst keine Änderung).
- Der Tabellen-Cache wird über die Plugin-Version im Cache-Schlüssel ohnehin verworfen.
- Kandidat für einen kleinen Upstream-PR, weil der Fehler nicht vom Booster stammt.

---

## Empfohlene Reihenfolge

1. Punkt 3 (klein, unabhängig, sofort sichtbar).
2. Punkt 2 (kein Schemawechsel, wirkt auch ohne Booster).
3. Punkt 1 (Schema- und `calc_version`-Änderung, braucht Paritätstests und Neuberechnung aller Zeilen).

---

## Umsetzung (2026-11-06)

### Punkt 3

- `View\Calendar::renderItemLocationRow()` nutzt `TABLE_STATUS_MARKUP[ DayStatus::LOCKED ]`.
- Der Snapshot musste **nicht** neu aufgezeichnet werden: Die Schleife läuft nur ohne `keepDaterange`, die
  Snapshot-Szenarien erreichen sie nicht. Neuer Test `CalendarTest::testRenderItemLocationRowLocksDaysWithoutStatus`
  (Reader liefert weniger Tage als angezeigt, schlägt ohne die Korrektur fehl).

### Punkt 2

- `Repository\Booking::getForUser()` → private `getVisibleCandidates()`: CB-Admin wie bisher alles; sonst Vereinigung
  aus `Repository\Timeframe::getByAuthor()` (neu, mit Index über `authors`, ohne Index per SQL auf `post_author` +
  Meta `type`) und `Booking::get()` für verwaltete Artikel bzw. Standorte. Verwaltete Posts über
  `getManagedPostIds()`: `post_author` oder Admin-Meta per `LIKE`, danach in PHP exakt geprüft (serialisierte
  String-/Int-Listen, einzelner String, keine Präfix-Treffer wie 11 bei 1).
- Statt drei `Index::findPostIds()`-Aufrufen wird `Booking::get()` genutzt, das ohnehin Index und Originalweg kennt.
- `BookingRuleApplied` nutzt `getForUserPaginated( …, [ 'unconfirmed' ] )` (WP_Query, nur eigene Buchungen).
- Tests: `BookingTest::testGetForUserMatchesFilteringAllBookings` (sieben Nutzerarten, drei Varianten, mit und ohne
  Index), `CustomPostType\BookingTest::testHandleBookingRequest_onlyOneUnconfirmedBooking_managedItem`.

### Punkt 1

- `Schema::SCHEMA_VERSION = 2` (Spalte `slots mediumblob`), `Calculator::VERSION = 2`: Beim Update wird die Tabelle
  verworfen und neu berechnet.
- Gemeinsamer Kern `Service\Availability\Slots` für Live-Weg und Vorberechnung (`bookableSlots()`, `toApiSlot()`,
  `addUnique()`, `pack()`/`unpack()`).
- Format abweichend vom Plan: Kopf `uint16` Anzahl, je Zeitrahmen `uint32` ID, Standort, Artikel (die API gibt Standort
  und Artikel des Zeitrahmens aus); je Slot `uint32` Start, `uint32` Ende, `uint16` Index.
- `SlotReader` liest; `Model\Calendar::getAvailabilitySlots()` delegiert und fällt auf `getAvailabilitySlotsLive()`
  zurück. Abweichend vom Plan wird außerhalb des Horizonts **der ganze** Kalender live gerechnet (kein Zusammensetzen),
  und es gibt kein separates `preload()` in `AvailabilityRoute`: ein Kalender lädt alle Paare seiner Artikel mit einer
  Abfrage.
- Live-Rückfälle: `ignoreRestrictions`, kein Artikelfilter, außerhalb des Horizonts, Zeitrahmen mit mehreren
  Artikeln/Standorten, Rollenbeschränkung, Vorberechnung aus.
- Befunde beim Paritätstest (Fehler des Live-Wegs, gespeichert korrekt, Test `SlotsTest::testKnownLiveDeviations`):
  - Ohne Standortfilter findet `Restriction::get( [], [ $item ] )` keine Einschränkungen mit Standort, Reparaturen
    werden in `/availability` live ignoriert.
  - Tage nach dem Enddatum (bis Sonntag) kennen live nur Zeitrahmen, die [Start, Ende] überlappen.
- Weitere Grenze (auch Stufe 3): `isBookable()` hängt vom heutigen Datum ab; rückt ein Zeitrahmen ins
  Vorausbuchungsfenster, kann eine Zeile bis zu 24 h veraltet sein.
- Verifier vergleicht zusätzlich die Slots.
- Tests: `tests/php/Service/Availability/SlotsTest.php` (Parität API/GBFS für Tages- und Stundenraster, Startversatz,
  Reparatur; Inhalte; erster buchbarer Tag; sofortige Sichtbarkeit von Änderungen; Rückfälle; Packformat; Verifier).
- Noch nicht gemessen: GBFS und `/availability` vorher/nachher.
