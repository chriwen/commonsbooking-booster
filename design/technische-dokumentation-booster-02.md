# Technische Dokumentation Performance-Booster (Fassung 2)

*English version: [technische-dokumentation-booster-02.en.md](technische-dokumentation-booster-02.en.md)*

Stand: 07.11.2026 · Branch `booster/index-availability` · Vergleichsbasis: CommonsBooking 2.11.3 (`master`, `7aa75a81`)
· Plugin-Version des Branches: `2.11.4-beta.3`

Diese Fassung fasst die bisherigen Dokumente zu einem zusammenhängenden Text zusammen und gleicht sie mit dem Code ab:

| Quelle | Was daraus übernommen wurde |
|---|---|
| [`booster.md`](booster.md) | Schalter, Wächter, Downgrade, Diagnose, Cache-Messung, Doppelbuchungsschutz |
| [`timeframe-index.md`](timeframe-index.md) | Entwurf, Entscheidungen und Messreihen des Index (Stufe 2, Phasen 1–5) |
| [`availability-precompute.md`](availability-precompute.md) | Entwurf, Entscheidungen und Messreihen der Vorberechnung (Stufe 3, Phasen A–E) |
| [`role-restricted-availability.md`](role-restricted-availability.md) | Zugriffsprofile für rollenbeschränkte Paare (8.8) |
| [`booster-aenderungen-bestehender-dateien.md`](booster-aenderungen-bestehender-dateien.md) | jede geänderte bestehende Datei |

Wo die Designnotizen vom Code abweichen, beschreibt dieses Dokument den Code. Die Designnotizen bleiben als
historisches Protokoll erhalten (Entwurf, Phasen, Zwischenmessungen).

**Für wen:** Entwickler, die CommonsBooking 2.11.3 kennen oder kennenlernen wollen, Reviewer des Forks und
Administratoren großer Instanzen, die verstehen wollen, was beim Einschalten passiert.

**Wie lesen:** Teil A erklärt die Idee ohne Vorwissen. Teil B beschreibt die Tabellen bis auf Spalten und Bytes.
Teil C und D zeigen Schritt für Schritt, was beim Schreiben und beim Lesen passiert, jeweils an konkreten Szenarien.
Teil E behandelt Sicherheit und Konsistenz, Teil F den Betrieb, Teil G alle Änderungen an bestehenden Dateien.

---

## Inhalt

- **Teil A – Konzept**
  1. Ausgangslage: wie CommonsBooking Daten ablegt und warum es langsam wird
  2. Ziele und Leitprinzipien
  3. Lösung in zwei Stufen und ein Schalter
  4. Die Beispielwelt dieses Dokuments
  5. Architektur und Komponenten
- **Teil B – Datenmodell**
  6. `cb_index`
  7. `cb_index_rel`
  8. `cb_availability` und `cb_availability_access`
  9. Optionen, Cron-Ereignisse, Filter und Actions
- **Teil C – Schreibprozesse**
  10. Index pflegen: Indexer und Projector
  11. Verfügbarkeit pflegen: Invalidator, Calculator, Store
  12. Neuaufbau (Backfill) und Wartung
  13. Schreibszenarien von Anfang bis Ende
- **Teil D – Leseprozesse**
  14. Welcher Weg läuft? Die Entscheidungskette
  15. Index lesen: `Repository\Index` und die umgestellten Repositories
  16. Verfügbarkeit lesen: `Reader` für Karte und Tabelle
  17. Slots lesen: `SlotReader` für REST-API und GBFS
  18. Leseszenarien von Anfang bis Ende
- **Teil E – Sicherheit und Konsistenz**
  19. Buchungsprüfung immer über den Originalweg
  20. Wächter, Verifier und Altersgrenze
  21. Downgrade und Upgrade
  22. Zusammenspiel mit dem Plugin-Cache
- **Teil F – Betrieb**
  23. Schalter und Lebenszyklus
  24. WP-CLI, Diagnose, Filter
  25. Messwerte
  26. Tests und CI
- **Teil G – Änderungen am bestehenden Code**
  27. Überblick
  28. Datei für Datei: was vorher, was jetzt
  29. Entfernte, umgebaute und neue Methoden
  30. Verhaltensänderungen, die auch ohne Booster gelten
- **Teil H – Grenzen und Ausblick**
  31. Bewusste Abweichungen, Grenzen, offene Punkte
  32. Checkliste für Entwickler
  33. Glossar

---

# Teil A – Konzept

## 1. Ausgangslage: wie CommonsBooking Daten ablegt und warum es langsam wird

### 1.1 Die fachlichen Objekte

CommonsBooking verleiht **Artikel** (z. B. ein Lastenrad) an **Standorten** (z. B. einer Bäckerei, die das Rad
ausgibt). Was wann möglich ist, beschreiben drei Arten von Einträgen:

| Objekt | Beitragstyp | Typ-Nummer (Meta `type`) | Beispiel |
|---|---|---|---|
| Öffnungszeiten | `cb_timeframe` | 1 | (selten genutzt) |
| buchbarer Zeitrahmen | `cb_timeframe` | 2 | „Lastenrad Emil an der Bäckerei, Mo–Fr ganztägig, ab 01.01.2026“ |
| Feiertag / geschlossen | `cb_timeframe` | 3 | „alle Standorte vom 24. bis 26.12. geschlossen“ |
| Off-Holidays | `cb_timeframe` | 4 | (Sonderform Feiertag) |
| Reparatur / Blockierung | `cb_timeframe` | 5 | „Emil ist vom 01. bis 03.12. blockiert“ |
| Buchung | `cb_booking` | 6 | „Nutzerin 17 leiht Emil am 10.11.“ |
| stornierte Buchung (alt) | `cb_booking` | 7 | Altbestand |
| Einschränkung | `cb_restriction` | (kein `type`) | „Emil ist ab 10.11. defekt“, Art `repair` oder `hint` |

Wie verfügbar ein Artikel an einem Tag ist, ergibt sich erst aus dem **Zusammenspiel**: ein buchbarer Zeitrahmen
öffnet Zeitfenster, Feiertage, Reparaturen, Einschränkungen und Buchungen schließen sie wieder.

### 1.2 Das Speicherformat: Beiträge und Schlüssel-Wert-Paare

CommonsBooking ist ein WordPress-Plugin und speichert alle drei Objektarten als **Beiträge**. Jeder Zeitrahmen, jede
Buchung und jede Einschränkung ist eine Zeile in `wp_posts`. Diese Zeile kennt nur allgemeine Angaben (ID, Titel,
Status, Autor, Datum). Alle fachlichen Angaben liegen in `wp_postmeta`, **eine Zeile pro Angabe**:

```
wp_posts      ID 9301 · post_type cb_booking · post_status confirmed · post_author 17

wp_postmeta   post_id  meta_key            meta_value
              9301     type                6               (Buchung)
              9301     item-id             812
              9301     location-id         455
              9301     repetition-start    1794268800      (10.11.2026 00:00)
              9301     repetition-end      1794355199      (10.11.2026 23:59:59)
              9301     _cb_bookingcode     …               (und einige weitere)
```

Im Testbestand, auf dem der Booster entwickelt wurde, kamen auf 17.100 Zeitrahmen und Buchungen rund 190.000
Meta-Zeilen, also etwa elf pro Beitrag. In der Entwicklungsumgebung mit zehnfacher Historie sind es 167.000 Beiträge
und 1,84 Millionen Meta-Zeilen.

### 1.3 Problem 1: Suchen

Fast jede Ansicht stellt im Kern dieselbe Frage: *Welche Zeitrahmen und Buchungen betreffen Artikel 812 an Standort
455 in den nächsten zwölf Tagen?* Die Karte stellt sie für jedes Paar aus Artikel und Standort, die Artikeltabelle
ebenso, der Buchungskalender für einen Artikel, die REST-API und GBFS für alle.

In `wp_postmeta` gibt es keine Spalte „Artikel“ oder „Ende“, über die die Datenbank einen passenden Schlüssel legen
könnte. CommonsBooking 2.11.3 beantwortet die Frage deshalb in zwei SQL-Stufen
(`Repository\Timeframe::getPostIdsByType()` und `getPostsByBaseParams()`):

```sql
-- Stufe 1: alle Beiträge des Typs für Artikel und Standort – OHNE Datumsbedingung
SELECT DISTINCT pm1.post_id FROM wp_postmeta pm1
  JOIN wp_postmeta pm2 ON pm2.post_id = pm1.post_id AND (  (pm2.meta_key = 'item-id' AND pm2.meta_value IN (812))
                                                        OR (pm2.meta_key = 'item-id-list' AND …) )
  JOIN wp_postmeta pm3 ON …  -- dasselbe für location-id / location-id-list
 WHERE pm1.meta_key = 'type' AND pm1.meta_value IN (2,3,5,6,…)

-- Stufe 2: Datumsfilter über weitere Meta-Joins, auf genau diese ID-Liste
SELECT DISTINCT pm1.* FROM wp_posts pm1
  JOIN wp_postmeta pm4 ON pm4.post_id = pm1.id AND ( (pm4.meta_key = 'repetition-end' AND pm4.meta_value >= …)
                                                  OR pm1.id NOT IN (SELECT post_id FROM wp_postmeta WHERE meta_key = 'repetition-end') )
 WHERE pm1.id IN ( …alle IDs aus Stufe 1… ) AND pm1.post_type IN (…) AND pm1.post_status IN (…)
```

Stufe 1 liefert **alle** Beiträge des Paares, auch jede Buchung der letzten Jahre. Wurde Emil 600-mal gebucht, stehen
600 IDs in der `IN`-Liste von Stufe 2, und MySQL muss über `postmeta` für jede einzelne prüfen, ob sie in den Zeitraum
fällt. Der Aufwand wächst mit der **gesamten Historie**, nicht mit dem, was angezeigt wird. Bei mehreren hundert Paaren
(Karte) und mehreren Abfragen pro Paar wurden daraus zehntausende Datenbankabfragen.

Dazu kamen Einzelladevorgänge: `get_post()` pro Beitrag (im Kartenszenario über 17.000 Abfragen) und
`Restriction::get()` einmal **pro Tag** und Paar (3.000–6.000 Abfragen pro Karten- bzw. Tabellenaufruf).

### 1.4 Problem 2: Rechnen

Selbst wenn die passenden Beiträge gefunden sind, steht die Antwort „frei oder belegt?“ nirgends fertig. Für jeden Tag
läuft:

```
Model\Calendar ─► Week ─► Day::getGrid()          Raster aus Zeitabschnitten („Slots“), pro Slot der Zeitrahmen
                                                   mit der höchsten Priorität (Buchung und Feiertag schlagen „buchbar“)
               ─► View\Calendar::processSlot()     (2.11.3) verdichtet die Slots zu einem Tageszustand
               ─► View\Calendar::mapDay()          wendet Vorausbuchung, ersten buchbaren Tag, Vergangenheit an
               ─► MapItemAvailable / renderTable   leitet den Status für Karte bzw. Tabelle ab
```

Das kostet pro Paar rund 7 ms für 31 Tage und gut 10 ms für 90 Tage – bei jedem Aufruf jedes Besuchers, für jedes
Paar. Die Karte berechnete außerdem 60 Tage, obwohl sie nur 12 anzeigt.

### 1.5 Ergebnis in Zahlen (2.11.3)

| Ansicht | Testbestand (17.100 Beiträge) | Entwicklung (167.000 Beiträge) |
|---|---|---|
| Karte mit Verfügbarkeit | 15,4 s, 40.800 Abfragen | Abbruch (Speicher) |
| `[cb_items_table]` | 7,05 s | Abbruch (Speicher) |
| Karte nur Standorte | 5,37 s | 49,96 s |
| `[cb_items]` | 2,78 s | 25,41 s |
| GBFS `vehicle_availability.json` | – | HTTP 500 (128 MB Speicherlimit) |

---

## 2. Ziele und Leitprinzipien

### 2.1 Ziele

1. Karten-, Listen- und API-Ansichten großer Instanzen sollen in Zehntelsekunden antworten, **unabhängig von der
   Länge der Buchungshistorie**.
2. Nach jeder Buchung muss die Anzeige **sofort** stimmen. Ein nächtlicher Neuaufbau reicht ausdrücklich nicht
   (Anforderung des Nutzers).
3. Der Booster muss **abschaltbar** und **downgrade-fest** sein: ausschalten oder auf 2.11.3 zurückgehen darf weder
   falsche Verfügbarkeiten noch Doppelbuchungen erzeugen; Wiedereinspielen ebenso wenig.

### 2.2 Leitprinzipien

| # | Prinzip | Was es konkret bedeutet |
|---|---|---|
| 1 | **Die Beiträge bleiben die einzige Quelle der Wahrheit.** | `wp_posts` und `wp_postmeta` werden genauso geschrieben und gelesen wie in 2.11.3. Admin, CMB2, REST, GBFS, Buchungscodes, Datenschutz-Export und Hooks Dritter bleiben unberührt. Die neuen Tabellen sind abgeleitet und jederzeit neu aufbaubar. |
| 2 | **Standardmäßig aus.** | Nach dem Update: keine Hooks, keine Cron-Jobs, kein Lesen aus neuen Tabellen. Verhalten wie 2.11.3. |
| 3 | **Zwei Wege, ein Ergebnis.** | Jede umgestellte Methode behält ihren Originalpfad. Eine zentrale Abfrage entscheidet, welcher läuft. Paritätstests vergleichen beide. |
| 4 | **Buchungsprüfung nie über den Booster.** | Überschneidung, Buchungsregeln und Zeitrahmen-Prüfung laufen immer mit den Originalabfragen und ohne Plugin-Cache. Ein veralteter Index kann höchstens die Anzeige verfälschen, nie eine Doppelbuchung zulassen. |
| 5 | **Sofort aktuell.** | Index und Gültigkeit der Vorberechnung werden im selben Request geändert, in dem sich ein Beitrag ändert. |
| 6 | **Nur eigene Tabellen und Optionen.** | Keine Änderung an bestehenden Tabellen oder an der Bedeutung bestehender Postmeta. Downgrade = Tabellen bleiben liegen und werden ignoriert. |

---

## 3. Lösung in zwei Stufen und ein Schalter

### 3.1 Die Idee: dieselben Daten zusätzlich in einer lesefreundlichen Form

Das Prinzip kennt jeder aus einem Sachbuch: Das **Stichwortverzeichnis** enthält keine neue Information, alles steht
auch im Text. Es ist aber so geordnet, dass man eine Stelle in Sekunden findet. Wird der Text überarbeitet, muss das
Verzeichnis nachgezogen werden – und im Zweifel gilt der Text.

Der Booster legt zwei solche „Verzeichnisse“ an. Man nennt sie *abgeleitete Daten*, *Lesemodelle* oder
*materialisierte Sichten*:

```
 Quelle der Wahrheit             Stufe „Index“                        Stufe „Vorberechnung“
 (unverändert)                   löst das Suchen                      löst das Rechnen

 wp_posts + wp_postmeta   ──►   cb_index (+ cb_index_rel)      ──►   cb_availability
 1 Beitrag =                     1 Beitrag = 1 Zeile                   1 Paar (Artikel, Standort) = 1 Zeile
 1 Post + ~11 Meta-Zeilen        mit festen, indizierten Spalten       mit dem fertigen Ergebnis
                                 (Artikel, Standort, Typ,              „wie sieht jeder Tag aus?“ und
                                 Start, Ende, Status, Autor …)          „welche Zeitfenster sind frei?“
                                                                       für gestern bis heute + 92 Tage
```

- **Index (Spiegel):** enthält nichts, was nicht auch in den Beiträgen steht, nur so sortiert, dass die Datenbank
  direkt an die richtige Stelle springt.
- **Vorberechnung (Ergebnis-Speicher):** enthält, was CommonsBooking bisher bei jedem Aufruf ausgerechnet hat,
  einmal ausgerechnet und abgelegt.
- **Booster (Schalter):** schaltet beide gemeinsam an und aus, schützt vor Inkonsistenzen (Wächter), zeigt den
  Zustand in den Einstellungen und bietet WP-CLI-Befehle.

In den Designnotizen heißen die Stufen „Stufe 2“ (Index) und „Stufe 3“ (Vorberechnung); „Stufe 1“ war der Quick Win,
nur die angezeigten Tage zu berechnen, der in Stufe 3 aufgegangen ist.

### 3.2 Was jede Stufe bringt

| Ansicht (Entwicklungsdaten, 167.000 Beiträge) | 2.11.3 | nur Index | Index + Vorberechnung |
|---|---|---|---|
| Karte mit Verfügbarkeit (100 Paare) | 15,4 s (bei 1/10 der Daten) | 1,14 s | **0,11 s** |
| `[cb_items_table]` | 7,05 s (bei 1/10 der Daten) | 0,74 s | **0,08 s** |
| Karte nur Standorte | 49,96 s | 0,09 s | 0,09 s |
| `[cb_items]` | 25,41 s | 0,09 s | 0,09 s |

Der Index allein macht alle Ansichten unabhängig von der Historie. Was danach übrig bleibt, ist reine Rechenzeit der
Tage; die beseitigt die Vorberechnung.

### 3.3 Vier Regeln, auf denen alles aufbaut

1. **Die Ableitung ist eindeutig und wiederholbar.** Aus denselben Beiträgen entstehen immer dieselben Zeilen. Jede
   Zeile kann gelöscht und neu erzeugt werden; Prüfungen vergleichen gespeicherte mit frisch berechneten Zeilen.
2. **Gespeichert wird nur, was nicht vom heutigen Datum abhängt.** Vergangenheit, Vorausbuchungsfenster und erster
   buchbarer Tag werden beim **Lesen** angewendet. Eine Zeile bleibt über Mitternacht gültig.
3. **Ungültig machen sofort, neu berechnen später.** Eine Änderung erhöht im selben Request einen Zähler (`gen`); eine
   Zeile mit abweichendem Zähler wird nie benutzt. Wann neu berechnet wird, beeinflusst nur die Geschwindigkeit.
4. **Im Zweifel die Quelle.** Wo abgeleitete Daten fehlen oder nicht passen, rechnet der Originalweg. Über Buchungen
   entscheidet immer der Originalweg.

---

## 4. Die Beispielwelt dieses Dokuments

Alle Beispiele beziehen sich auf diese Daten. Heute ist **Freitag, 06.11.2026**. Zeitstempel sind, wie in
CommonsBooking üblich, *Ortszeit als Unix-Zeitstempel* (00:00 Uhr Ortszeit wird gespeichert, als wäre es 00:00 UTC).

| Rolle | Objekt | ID | Eigenschaften |
|---|---|---|---|
| Artikel | Lastenrad „Emil“ | 812 | |
| Artikel | Lastenrad „Paula“ | 813 | |
| Artikel | Anhänger „Toni“ | 814 | stundenweise buchbar |
| Standort | Bäckerei Sonnenschein | 455 | Admin: Nutzerin 23 (`_cb_location_admins`) |
| Standort | Stadtteilladen | 456 | |
| Standort | Repair-Café | 457 | |
| buchbarer Zeitrahmen | Emil @ Bäckerei | 501 | Mo–Fr, ganztägig, ab 01.01.2026, offenes Ende, 30 Tage Vorausbuchung |
| buchbarer Zeitrahmen | Paula @ Bäckerei | 502 | täglich ganztägig, ab 01.03.2026, offenes Ende, 2 Tage Vorlauf (`booking-startday-offset`) |
| buchbarer Zeitrahmen | Toni @ Stadtteilladen | 503 | Mo–Sa, stündlich 08:00–18:00 |
| buchbarer Zeitrahmen | Paula @ Repair-Café | 504 | nur für Rolle „Mitglied“ buchbar (`allowed_user_roles`) |
| Feiertag | Weihnachten | 620 | 24.–26.12.2026, Artikel „alle“ (812, 813, 814), Standorte 455, 456, 457 |
| Einschränkung | Emil defekt | 7004 | `repair`, aktiv, ab 10.11.2026, Artikel 812, Standort **0 = alle** |
| Buchung | Emil, Di 10.11., ganztägig | 9301 | `confirmed`, Nutzerin 17 |
| Buchung | Emil, 13.–16.11. | 9302 | `canceled` |
| Buchung | Emil, Mi 04.11. | 9295 | `confirmed`, bereits vorbei |
| Buchung | Toni, Mo 09.11., 10–12 Uhr | 9310 | `confirmed`, Nutzer 31 |

Hilfreiche Zeitstempel:

| Datum (00:00 Ortszeit) | Zeitstempel |
|---|---|
| 01.01.2026 | 1767225600 |
| Do 05.11.2026 | 1793836800 |
| Fr 06.11.2026 | 1793923200 |
| Mo 09.11.2026 | 1794182400 |
| Di 10.11.2026 | 1794268800 (Ende des Tages: 1794355199) |
| Di 17.11.2026 | 1794873600 (Ende des Tages: 1794959999) |
| Do 24.12.2026 | 1798070400 |
| Sa 26.12.2026 | 1798243200 (Ende des Tages: 1798329599) |

---

## 5. Architektur und Komponenten

### 5.1 Gesamtbild

Ausführliche Grafik mit allen Methoden: [`booster-architektur.png`](booster-architektur.png)
([SVG](booster-architektur.svg)).

```
                       SCHREIBWEG                                              LESEWEG
 ┌──────────────────────────────────────────────┐        ┌──────────────────────────────────────────────┐
 │ WordPress: save_post, *_post_meta,           │        │ Repository\Timeframe, Booking, Restriction,  │
 │ transition_post_status, deleted_post         │        │ BookablePost, Model\Map, Model\Item          │
 │ CommonsBooking: Model\Booking::cancel()      │        └───────────────────┬──────────────────────────┘
 └──────────────────┬───────────────────────────┘                            │ Index::isAvailable()?
                    │ Post-ID „dirty“                                         │  ja  → Repository\Index (cb_index)
                    ▼                                                         │  nein → Originalabfrage (postmeta)
        Service\Index\Indexer ──flush()──► Projector ──REPLACE──► cb_index / cb_index_rel
                                               │
                                               │ Action commonsbooking_index_rows_changed (alte + neue Zeile)
                                               ▼
                               Service\Availability\Invalidator
                                   │ UPDATE gen = gen + 1   (sofort, im selben Request)
                                   ▼
                              cb_availability ◄── Calculator::recompute()
                                   ▲              (≤ 25 Paare am Request-Ende, sonst Cron; oder beim Lesen)
                                   │ Store::isUsable()?  ja → days / slots
                                   │                     nein → berechnen und speichern, oder live
                                   │ rollenbeschränkt? → cb_availability_access je Zugriffsprofil (8.8)
                ┌──────────────────┴───────────────────────┐
     Service\Availability\Reader                 Service\Availability\SlotReader
     ◄── MapItemAvailable (Karte)                ◄── Model\Calendar::getAvailabilitySlots()
     ◄── View\Calendar::renderTable()                (REST /availability, GBFS station/vehicle status,
         ([cb_items_table])                           GBFS vehicle availability)

 Außerhalb des Boosters (immer Originalweg, ohne Cache): Buchungsformular, Admin-Bestätigung,
 Prüfung beim Speichern eines Zeitrahmens  →  Booster::withoutBooster()
```

### 5.2 Komponenten nach Namespace

| Namespace / Klasse | Aufgabe | Teil |
|---|---|---|
| `Service\Booster\Booster` | Schalter, Zustand, Ein-/Ausschalten, `withoutBooster()`, `purge()` | F |
| `Service\Booster\Guard` | Wächter gegen Änderungen ohne Booster (Version, neue Beiträge, Status/Datum) | E |
| `Service\Booster\Diagnostics`, `SettingsView`, `Cli` | Diagnose auf der Einstellungsseite, Aktivitätsprotokoll, WP-CLI | F |
| `Service\Index\Schema` | Tabellen `cb_index`, `cb_index_rel` anlegen, erweitern, löschen | B |
| `Service\Index\Indexer` | Hooks, Sammeln geänderter Beiträge, `flush()` | C |
| `Service\Index\Projector` | Beitrag → Indexzeile; meldet alte und neue Zeile | C |
| `Service\Index\Backfill` | vollständiger Aufbau in Stapeln (Cron/CLI) | C |
| `Service\Index\Verifier` | Stichprobe Index ↔ frische Projektion, repariert | E |
| `Repository\Index` | Lesezugriff: `isAvailable()`, `findPostIds()`, `loadPosts()`, `sortByDate()` | D |
| `Service\Availability\Availability` | Schalter der Vorberechnung, Horizont, Altersgrenze | D |
| `Service\Availability\Schema` | Tabellen `cb_availability` und `cb_availability_access` | B |
| `Service\Availability\Store` | einziger Zugriff auf `cb_availability`: laden, prüfen, schreiben, invalidieren | B/C |
| `Service\Availability\Invalidator` | betroffene Paare bestimmen, `gen` erhöhen, Neuberechnung einplanen | C |
| `Service\Availability\Calculator` | ein Paar über den Horizont berechnen (Tagesfakten + Slots), bei Rollenbeschränkung je Zugriffsprofil | C |
| `Service\Availability\AccessProfile` | Zugriffsprofil des Nutzers für ein Paar, erlaubte Rollen je Paar, Rechnen als Profil (`runAs()`) | B/D |
| `Service\Availability\AccessStore` | einziger Zugriff auf `cb_availability_access`: laden, prüfen, schreiben, aufräumen | B/C |
| `Service\Availability\Maintenance` | Cron: neue Paare, veraltete Zeilen, Aufräumen der Zugriffsprofile, Stichprobe | C/E |
| `Service\Availability\DayFacts` | Rechenkern: Slot-Raster → 10 Bits | B |
| `Service\Availability\CalendarWindow` | Rechenkern: datumsabhängiges Fenster beim Lesen | D |
| `Service\Availability\DayStatus` | Rechenkern: Status eines Tages für Karte und Tabelle | D |
| `Service\Availability\Reader` | Lesen für Karte und Artikeltabelle | D |
| `Service\Availability\Slots`, `SlotReader` | freie Zeitfenster speichern und für API/GBFS lesen | B/D |

Nach dem Anlegen neuer Klassen unter `src/` ist `composer prefix-namespaces` nötig: Das Plugin lädt nur den
Strauss-Autoloader in `vendor-prefixed/`.

---

# Teil B – Datenmodell

Alle Tabellen werden pro Site über `$wpdb->prefix` angelegt (Multisite-fähig), per `dbDelta()` erzeugt und tragen eine
eigene Schema-Versionsoption. Es gibt keine Fremdschlüssel und keine Trigger (die auf vielen Hostern Sonderrechte
bräuchten); die Pflege erfolgt in PHP über Hooks. Angelegt werden die Tabellen beim Einschalten des Boosters, entfernt
nur mit `wp commonsbooking booster purge`.

```
 wp_posts (cb_timeframe, cb_booking, cb_restriction)
   │ 1 : 1   post_id
   ▼
 cb_index ──────────── 1 : n ────────────► cb_index_rel
   │ item_id, location_id                    (nur Beiträge mit is_multi = 1:
   │ (Paare = buchbare Zeitrahmen,            je Artikel und Standort eine Zeile)
   │  Typ 2, publish)
   │ n : 1
   ▼
 cb_availability   PRIMARY (location_id, item_id)
   │ 1 : n   (nur Paare mit has_role_limit = 1)
   ▼
 cb_availability_access   PRIMARY (location_id, item_id, access_profile)
```

| | `cb_index` | `cb_index_rel` | `cb_availability` | `cb_availability_access` |
|---|---|---|---|---|
| eine Zeile je | Beitrag | Artikel bzw. Standort eines Beitrags mit Mehrfachauswahl | Paar mit buchbarem Zeitrahmen | rollenbeschränktes Paar und Zugriffsprofil |
| Größenordnung (17.100 Beiträge, 300 Paare) | ≈ 17.100 | wenige hundert | ≈ 300 | 2 je rollenbeschränktem Paar, höchstens 8 |
| Inhalt | Fakten aus `wp_posts`/`postmeta`, typisiert | Zuordnungen | berechnete Tagesfakten + freie Slots | dasselbe für ein Zugriffsprofil |
| geschrieben von | `Index\Projector` | `Index\Projector` | `Availability\Store` | `Availability\AccessStore` |
| Schema-Klasse, Version | `Index\Schema`, 3 | `Index\Schema`, 3 | `Availability\Schema`, 3 | (dieselbe) |
| Versionsoption | `commonsbooking_index_schema_version` | (dieselbe) | `commonsbooking_availability_schema_version` | (dieselbe) |
| bei Strukturänderung | `dbDelta` erweitert, Zeilen bleiben gültig | wie `cb_index` | Tabelle wird verworfen und neu angelegt (reiner Cache) | wie `cb_availability` (beide zusammen) |
| bei geänderter Ableitungslogik | `Projector::PROJECTOR_VERSION` (2) erhöhen → Neuaufbau | wie `cb_index` | `Calculator::VERSION` (2) erhöhen → alte Zeilen ungültig | wie `cb_availability` |

Zwei Regeln für Datentypen: Was gesucht oder verglichen wird, ist eine Zahl (Typ, IDs, Zeitstempel). Und `NULL` hat in
jeder Spalte eine feste Bedeutung, damit die SQL-Bedingungen das alte `postmeta`-SQL exakt nachbilden.

---

## 6. `{prefix}cb_index`: eine Zeile pro Beitrag

### 6.1 Was hineinkommt

Eine Zeile für jeden Beitrag vom Typ `cb_timeframe`, `cb_booking` und `cb_restriction` in **jedem** Status außer
`auto-draft` – also auch `draft`, `trash` und `canceled`. Status werden erst beim Lesen gefiltert. Das hat zwei Gründe:
Eine Statusänderung ändert nur eine Spalte, und der Wächter kann stornierte oder gelöschte Beiträge erkennen.

Die Zeile ist **keine Interpretation**. Der Projector berechnet keine Tage, keine Verfügbarkeit und keine Buchbarkeit.
Er übersetzt Metawerte in Spalten und normalisiert nur dort, wo der Originalcode beim Lesen ebenfalls normalisiert
(z. B. `strtotime()` für Altwerte). Ob ein Zeitrahmen an einem Mittwoch gilt oder ob ein Nutzer ihn buchen darf,
entscheidet weiterhin der bestehende PHP-Code nach dem Laden der Beiträge.

### 6.2 Spalten

**Identität und Status** (aus `wp_posts`)

| Spalte | Typ | NULL / Standard | Bedeutung |
|---|---|---|---|
| `post_id` | bigint unsigned, **PK** | – | `wp_posts.ID` |
| `post_type` | varchar(20) | – | `cb_timeframe`, `cb_booking`, `cb_restriction` |
| `post_status` | varchar(20) | – | `publish`, `draft`, `pending`, `private`, `trash`; Buchungen auch `confirmed`, `unconfirmed`, `canceled` |
| `post_author` | bigint unsigned | 0 | bei Buchungen der Nutzer, **für den** gebucht wurde (Buchungsregeln, „Meine Buchungen“) |

**Typ und Zuordnung** (Metas `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`; bei
Einschränkungen `restriction-item-id`, `restriction-location-id`)

| Spalte | Typ | NULL / Standard | Bedeutung |
|---|---|---|---|
| `type` | tinyint unsigned | 0 | 1–7 wie in 1.1; **0 bei Einschränkungen** (die haben kein `type`-Meta) |
| `item_id` | bigint unsigned | **NULL** bei Mehrfachauswahl oder ohne Artikel | Zeitrahmen/Buchung: der einzige Artikel. Einschränkung: Artikel oder **0 = alle** |
| `location_id` | bigint unsigned | wie `item_id` | wie `item_id`, für den Standort |
| `is_multi` | tinyint(1) | 0 | 1, wenn nach Vereinigung von `*-id` und `*-id-list` mehr als ein Artikel **oder** mehr als ein Standort übrig bleibt; Zuordnung dann in `cb_index_rel` |

**Zeitraum** (`repetition-start`/`-end` bzw. `restriction-start`/`-end`)

| Spalte | Typ | NULL bedeutet | Regeln |
|---|---|---|---|
| `start_ts` | bigint (signed) | **kein Start gespeichert** | Zahl unverändert, Text per `strtotime()`. `0` ist ein gültiger Start. Kriterien `start_min`/`start_max` schließen NULL aus – wie das alte SQL, das den Meta-Schlüssel voraussetzte |
| `end_ts` | bigint (signed) | **offenes Ende** | `''`, `0`, `'0'` und fehlendes Meta → NULL (wie `Model\Timeframe::getTimeframeEndDate()`). Abfragen schreiben `(end_ts IS NULL OR end_ts >= :von)` |

**Wiederholung und Raster** (nur Zeitrahmen und Buchungen)

| Spalte | Typ | Herkunft | Bedeutung, Beispiel |
|---|---|---|---|
| `repetition` | varchar(8), NULL | `timeframe-repetition` | `norep`, `d`, `w`, `m`, `y`, `manual` |
| `weekdays` | tinyint unsigned | `weekdays` (`'1'`…`'7'`, Mo = 1) | Bitmaske Mo = Bit 0 … So = Bit 6: Mo–Fr = 31, Mo–Sa = 63, Sa+So = 96, alle = 127 |
| `manual_dates` | text, NULL | `timeframe_manual_date` | JSON-Array, nur bei `manual`, z. B. `["2026-12-01","2026-12-08"]` |
| `grid` | tinyint unsigned | `grid` | 0 = Slot (ein Fenster Start–Ende), 1 = stündlich |
| `full_day` | tinyint(1) | `full-day` | 1, wenn Meta = `on` |
| `start_time`, `end_time` | time, NULL | `start-time`, `end-time` (`8:00 AM`, `18:00` …) | normalisiert auf `H:i:s` |
| `grid_size` | tinyint unsigned, NULL | abgeleitet wie `Model\Timeframe::getGridSize()` | ganztägig 24; Slot = Stunden zwischen Start und Ende (gerundet, ≤ 255); sonst Wert von `grid`; NULL, wenn bei Slot eine Zeit fehlt |

Diese Spalten werden derzeit von **keinem Leseweg** ausgewertet: Nach der Suche lädt der Code die `WP_Post`-Objekte
samt Metadaten gebündelt und nutzt die bestehenden Modelle. Sie werden trotzdem gepflegt und vom Verifier geprüft. Sie
machen die Zeile „verdeckend“ (covering) und erlauben später Wochentags- oder Rasterfilter in SQL, ohne das Schema
erneut zu ändern und ohne zweiten Backfill.

**Buchungsregeln** (nur Zeitrahmen)

| Spalte | Typ | Herkunft | Bedeutung |
|---|---|---|---|
| `max_days` | smallint unsigned, NULL | `timeframe-max-days` | maximale Buchungsdauer |
| `advance_days` | smallint unsigned, NULL | `timeframe-advance-booking-days` | Vorausbuchungsfenster |
| `startday_offset` | smallint unsigned, NULL | `booking-startday-offset` | frühester Buchungstag ab heute |
| `has_role_limit` | tinyint(1) | `allowed_user_roles` | 1 = nur für bestimmte Rollen buchbar; solche Paare werden je Zugriffsprofil gespeichert (8.8) |

Zahlen werden auf 0…65535 begrenzt, nicht numerische Werte ergeben NULL („nicht gesetzt“). Die Auswertung bleibt im
PHP-Code (z. B. gelten 0 Vorausbuchungstage dort als „nicht gesetzt“).

**Einschränkungen** (nur `cb_restriction`)

| Spalte | Typ | Herkunft | Bedeutung |
|---|---|---|---|
| `restriction_type` | varchar(10), NULL | `restriction-type` | `repair` (sperrt), `hint` (nur Hinweis) |
| `restriction_state` | varchar(10), NULL | `restriction-state` | `none`, `active`, `solved`; gelesen wird nur `active` |

**Verwaltung** (nicht Teil des Vergleichs, ob sich eine Projektion geändert hat)

| Spalte | Typ | Bedeutung |
|---|---|---|
| `source_modified_gmt` | datetime, NULL | `post_modified_gmt` zum Zeitpunkt der Projektion. Der Wächter vergleicht stündlich mit `wp_posts` |
| `projector_version` | smallint unsigned | `Projector::PROJECTOR_VERSION` (derzeit 2) |

### 6.3 Zuordnung Artikel/Standort

Der Projector bildet die **Vereinigung** aller Werte von `item-id` (auch mehrfach vorhandene Meta-Zeilen) und der
Liste `item-id-list`, entfernt Werte ≤ 0 und Duplikate, sortiert. Ebenso für Standorte. Das entspricht dem alten SQL in
`Repository\Timeframe::getEntityQuery()`, das beide Metas per `OR` verknüpfte. (Die Model-Getter bevorzugen nur die
Liste; die Vereinigung ist deshalb bewusst gewählt, um das SQL nachzubilden.)

| Ergebnis | `item_id` | `location_id` | `is_multi` | `cb_index_rel` |
|---|---|---|---|---|
| ein Artikel, ein Standort (Buchungen, buchbare Zeitrahmen, > 99 %) | ID | ID | 0 | – |
| kein Artikel oder kein Standort (unvollständiger Entwurf) | NULL | NULL / ID | 0 | – |
| mehr als ein Artikel **oder** Standort (Feiertage, Reparaturen mit Liste, Kategorie, „alle“) | NULL | NULL | 1 | je Artikel und Standort eine Zeile |
| Einschränkung | ID oder **0 = alle** | ID oder **0 = alle** | 0 | – |

Warum „0 = alle“ in derselben Spalte? Die Abfrage lautet dann `item_id IN (0, :item)`. Das bleibt indizierbar und
bildet die vier Fälle aus `Restriction::filterPosts()` exakt ab.

### 6.4 Beispielzeilen

| post_id | post_type | post_status | type | item_id | location_id | is_multi | start_ts | end_ts | weitere |
|---|---|---|---|---|---|---|---|---|---|
| 501 | cb_timeframe | publish | 2 | 812 | 455 | 0 | 1767225600 | NULL | `repetition = w`, `weekdays = 31`, `full_day = 1`, `grid_size = 24`, `advance_days = 30` |
| 502 | cb_timeframe | publish | 2 | 813 | 455 | 0 | 1772323200 | NULL | `weekdays = 127`, `startday_offset = 2` |
| 503 | cb_timeframe | publish | 2 | 814 | 456 | 0 | … | NULL | `grid = 1`, `start_time = 08:00:00`, `end_time = 18:00:00`, `grid_size = 1`, `weekdays = 63` |
| 504 | cb_timeframe | publish | 2 | 813 | 457 | 0 | … | NULL | `has_role_limit = 1` |
| 620 | cb_timeframe | publish | 3 | NULL | NULL | 1 | 1798070400 | 1798329599 | Zuordnung in `cb_index_rel` |
| 7004 | cb_restriction | publish | 0 | 812 | **0** | 0 | 1794268800 | NULL | `restriction_type = repair`, `restriction_state = active` |
| 9295 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794096000 | 1794182399 | `post_author = 17` |
| 9301 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794268800 | 1794355199 | `post_author = 17` |
| 9302 | cb_booking | canceled | 6 | 812 | 455 | 0 | 1794528000 | 1794873599 | bleibt im Index, wird über `post_status` herausgefiltert |
| 9310 | cb_booking | confirmed | 6 | 814 | 456 | 0 | 1794218400 (09.11. 10:00) | 1794225599 (11:59:59) | `post_author = 31` |

### 6.5 Schlüssel und wofür sie gebraucht werden

| Schlüssel | Spalten | genutzt von |
|---|---|---|
| `PRIMARY` | `post_id` | Projektion (`REPLACE`), Wächter, Verifier |
| `item_loc_range` | `item_id, location_id, type, end_ts, start_ts` | Paar-Abfragen (Kalender, Karte, `SlotReader`, `getLatestPastConfirmed`) |
| `loc_range` | `location_id, type, end_ts` | Abfragen nur nach Standort |
| `type_range` | `type, end_ts, start_ts` | Abfragen ohne Artikel/Standort (Erinnerungs-Mails: beginnende/endende Buchungen eines Tages) |
| `author_type` | `post_author, type, start_ts` | Buchungen eines Nutzers |
| `multi_type` | `is_multi, type` | Teil „Mehrfachzuordnung“ der `UNION ALL` |
| `ptype_item`, `ptype_location` | `post_type, item_id` / `post_type, location_id` | Artikel ↔ Standort (`getRelatedPosts`), Einschränkungen, Paarliste |
| `ptype_end` | `post_type, end_ts` | Einschränkungen nach Zeitraum |

**Warum `end_ts` an vierter Stelle von `item_loc_range` so wichtig ist.** Man kann sich den Schlüssel wie ein
Telefonbuch vorstellen, das nach Artikel, dann Standort, dann Typ, dann Ende sortiert ist. Wer „Artikel 812, Standort
455, Typ 6, Ende ab 06.11.2026“ sucht, schlägt direkt die richtige Stelle auf und liest nur die Buchungen, die nach
dem 06.11. enden. Die 600 alten Buchungen stehen weiter vorne im Telefonbuch und werden gar nicht angefasst. Deshalb
hängt die Laufzeit von der Zahl der *aktuellen*, nicht aller Buchungen ab (`EXPLAIN` bestätigt die Nutzung).

Messung (Testbestand): Paar-Abfrage über `postmeta` 26 ms, über den Index 0,12 ms; bei fünfzigfacher Historie
(867.000 Indexzeilen) 0,18 ms. Alle Paare der Karte in **einer** Abfrage: 1,7 ms statt 400 × 25 ms ≈ 10 s.

Schema-Historie: Version 2 machte `start_ts` NULL-fähig (explizites `ALTER`, weil `dbDelta` die NULL-Eigenschaft nicht
ändert) und brachte `multi_type`. Version 3 brachte `ptype_item`, `ptype_location`, `ptype_end` – ohne sie wuchsen die
Abfragen für Einschränkungen und für die Zuordnung Artikel ↔ Standort mit der Historie. Reine Strukturänderungen lösen
keinen Neuaufbau aus; der Index bleibt beim Upgrade lesbar.

---

## 7. `{prefix}cb_index_rel`: Mehrfachzuordnungen

| Spalte | Typ | Bedeutung |
|---|---|---|
| `post_id` | bigint unsigned | Beitrag mit `is_multi = 1` |
| `entity_type` | varchar(8) | `item` oder `location` |
| `entity_id` | bigint unsigned | ID des Artikels bzw. Standorts |

Schlüssel: `PRIMARY (entity_type, entity_id, post_id)` für „alle Beiträge zu Artikel X“, `KEY post_id` für Ersetzen
und Löschen. Beim Schreiben werden alle Zeilen des Beitrags gelöscht und neu eingefügt (`INSERT IGNORE`).

Beispiel Feiertag 620:

| post_id | entity_type | entity_id |
|---|---|---|
| 620 | item | 812 |
| 620 | item | 813 |
| 620 | item | 814 |
| 620 | location | 455 |
| 620 | location | 456 |
| 620 | location | 457 |

**Warum eine eigene Tabelle statt einer Zeile pro Paar?** Ein Feiertag „alle Artikel × alle Standorte“ bei 500
Artikeln und 300 Standorten bräuchte 150.000 Zeilen in der Haupttabelle, die Relationstabelle braucht 800. Buchungen
und buchbare Zeitrahmen kommen ohne sie aus.

**Wann es Mehrfachauswahl gibt:** laut `Timeframe::removeIrrelevantPostmeta()` nur bei Feiertagen (3) und Reparaturen
(5). `Timeframe::manageTimeframeMeta()` schreibt Kategorie- und „alle“-Auswahl bereits als `item-id-list` bzw.
`location-id-list` aus; neue Artikel landen dort über `updateAllTimeframes()` und damit über die Meta-Hooks im Index.

---

## 8. `{prefix}cb_availability`: vorberechneter Tageszustand pro Paar

### 8.1 Was hineinkommt

Eine Zeile je Artikel/Standort-Paar, das einen veröffentlichten buchbaren Zeitrahmen (Typ 2) hat, der nicht vor dem
Horizont endet (`Maintenance::getBookablePairs()`, Quelle `cb_index`). Paare ohne buchbaren Zeitrahmen brauchen keine
Zeile; die Karte zeigt dort „kein Zeitrahmen“.

Die Zeile beschreibt **gestern bis heute + 92 Tage**, also 94 Tage (`Availability::getHorizon()`, Filter
`commonsbooking_availability_horizon_days`, Standard 92). Der Horizont deckt die Kartenvorgabe
(`availability_max_days_to_show`), die Tabelle (Standard 31 Tage) und Monatswechsel ab. „Gestern“ ist ein Tag Puffer
für Zeitzonen und Anfragen um Mitternacht.

Die Tabelle ist ein **reiner Cache**: Jede Zeile kann jederzeit gelöscht werden und entsteht beim nächsten Lesen neu.

### 8.2 Spalten

| Spalte | Typ | Standard | Bedeutung |
|---|---|---|---|
| `item_id`, `location_id` | bigint unsigned, **PK** `(location_id, item_id)` | – | das Paar; Standort zuerst, weil Karte und Tabelle standortweise laden |
| `gen` | int unsigned | **1** | Generation; bei **jeder** Änderung eines Beitrags, der das Paar betrifft, synchron +1 |
| `computed_gen` | int unsigned | **0** | Generation, aus der `days` und `slots` berechnet wurden; neue Zeilen sind dadurch zunächst ungültig |
| `days_from` | date, NULL | – | Datum des ersten Eintrags in `days` (am Berechnungstag: gestern) |
| `days` | varbinary(1024), NULL | – | Tagesfakten, **2 Byte je Tag** ab `days_from` (unsigned short, little endian); 94 Tage = 188 Byte, Platz für 512 Tage; NULL bei Rollenbeschränkung (dann je Zugriffsprofil in 8.8) |
| `slots` | mediumblob, NULL | – | freie buchbare Slots je Tag für API und GBFS (8.7); NULL bei Rollenbeschränkung (8.8) |
| `has_role_limit` | tinyint(1) | 0 | 1 = mindestens ein buchbarer Zeitrahmen des Paares ist rollenbeschränkt |
| `computed_at` | datetime (UTC), NULL | – | Zeitpunkt der Berechnung (`gmdate()`) |
| `calc_version` | smallint unsigned | 0 | `Calculator::VERSION` (derzeit 2) |

Schlüssel: `PRIMARY (location_id, item_id)`, `KEY item_id` (Invalidierung nur nach Artikel, z. B. Einschränkung für
alle Standorte), `KEY computed_at` (Cron sucht die ältesten Zeilen).

`days` und `slots` werden als `UNHEX('…')` geschrieben, damit die SQL-Anweisung reiner ASCII-Text bleibt: `wpdb`
entfernt sonst Bytes, die es für ungültigen Text hält. `Store::preload()` (Karte, Tabelle) lädt `slots` nicht mit;
nur `Store::loadWithSlots()` (für `SlotReader`) liest die Spalte.

### 8.3 Spalte `days`: zehn Bits pro Tag (`DayFacts`)

Für jeden Tag baut der Kalender wie bisher das Slot-Raster (`Day::getGrid()`). `DayFacts::fromGrid()` verdichtet es auf
zehn Bits, genau nach den Regeln des früheren `View\Calendar::processSlot()`:

| Bit | Wert | Konstante | gesetzt, wenn … |
|---|---|---|---|
| 0 | 1 | `HAS_SLOTS` | mindestens ein Slot einen Zeitrahmen hat |
| 1 | 2 | `SLOT_LOCKED` | mindestens ein Slot gesperrt ist (Feiertag, Reparatur, Buchung, Einschränkung) oder für den Nutzer nicht buchbar ist |
| 2 | 4 | `ALL_LOCKED` | kein Slot wählbar ist (auch ohne Slots) |
| 3 | 8 | `HOLIDAY` | alle Slots Feiertage (Typ 3 oder 4) sind (auch ohne Slots) |
| 4 | 16 | `REPAIR` | Altlast des Originals: wird durch jeden Slot zurückgesetzt (strikter Vergleich Text gegen Zahl); praktisch nur ohne Slots gesetzt |
| 5 | 32 | `BOOKED_DAY` | alle Slots Buchungen oder Reparaturen sind (auch ohne Slots) |
| 6 | 64 | `PARTIALLY_BOOKED` | mindestens ein Slot Buchung, Feiertag, Reparatur oder Einschränkung (`repair`) ist |
| 7 | 128 | `FIRST_SLOT_BOOKED` | der erste Slot nicht buchbar ist |
| 8 | 256 | `LAST_SLOT_BOOKED` | der letzte Slot nicht buchbar ist |
| 9 | 512 | `FULL_DAY` | es genau einen Slot gibt und dessen Zeitrahmen ganztägig ist |

Bits 10–15 sind frei. Typische Werte (Bytes little endian, niedriges Byte zuerst):

| Tag | Bits | Wert | Bytes |
|---|---|---|---|
| kein Zeitrahmen (z. B. Samstag bei Mo–Fr) | `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY` | 60 | `3C 00` |
| ganztägig buchbar und frei | `HAS_SLOTS`, `FULL_DAY` | 513 | `01 02` |
| ganztägig gebucht | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (kein `FULL_DAY`: Buchungen haben kein Meta `full-day`) | 487 | `E7 01` |
| Feiertag (ganztägiger Feiertag: + `FULL_DAY`) | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` | 463 (975) | `CF 01` (`CF 03`) |
| Stundenraster 08–18, gebucht 10–12 | `HAS_SLOTS`, `SLOT_LOCKED`, `PARTIALLY_BOOKED` | 67 | `43 00` |
| Stundenraster 08–18, gebucht 14–18 | wie oben + `LAST_SLOT_BOOKED` | 323 | `43 01` |

Der Wert „kein Zeitrahmen“ zeigt, warum die Bits nicht einzeln gelesen werden dürfen: Ohne Slots sind `HOLIDAY` und
`BOOKED_DAY` gesetzt. `CalendarWindow::mapDay()` setzt in diesem Fall wie das Original alles auf „gesperrt“ zurück.

**Beispiel: die Zeile von Emil @ Bäckerei (812, 455), berechnet am Fr 06.11.**

| Versatz | Datum | Was gilt | Wert | Bytes an Position |
|---|---|---|---|---|
| 0 | Do 05.11. | buchbar, frei (gestern) | 513 | 0–1: `01 02` |
| 1 | Fr 06.11. | buchbar, frei | 513 | 2–3: `01 02` |
| 2 | Sa 07.11. | kein Zeitrahmen | 60 | 4–5: `3C 00` |
| 3 | So 08.11. | kein Zeitrahmen | 60 | 6–7: `3C 00` |
| 4 | Mo 09.11. | buchbar, frei | 513 | 8–9: `01 02` |
| 5 | Di 10.11. | Buchung 9301 + Einschränkung 7004 | 487 | 10–11: `E7 01` |
| 6 | Mi 11.11. | Einschränkung 7004 (Reparatur, aktiv) sperrt | gesperrt | 12–13 |
| … | … | … | … | … |
| 93 | Fr 05.02.2027 | … | … | 186–187 |

**Einen Tag lesen:** Versatz = Tage zwischen `days_from` und Datum, dann `unpack('v', $days, Versatz × 2)`
(`Store::getFlags()`). Für Di 10.11.: Versatz 5, Bytes 10–11 = `E7 01` = 0x01E7 = 487 = „gebucht“. Es wird kein Beitrag
geladen und kein Raster gebaut.

(Die Einschränkung 7004 macht Emil ab 10.11. in diesem Beispiel unbuchbar; um die übrigen Beispiele übersichtlich zu
halten, wird sie in Teil C und D nur dort betrachtet, wo es um Einschränkungen geht.)

### 8.4 Was nicht gespeichert wird

Alles, was vom heutigen Datum oder vom angemeldeten Nutzer abhängt, wird beim **Lesen** angewendet:

| Regel | angewendet von | Quelle |
|---|---|---|
| Tag liegt in der Vergangenheit | `CalendarWindow::mapDay()` | heutiges Datum |
| Tag liegt außerhalb des Vorausbuchungsfensters (`advance_days`) | `CalendarWindow` (`lastBookableDate`) | buchbare Zeitrahmen + heute |
| Tag liegt vor dem ersten buchbaren Tag (`startday_offset`) | `CalendarWindow` (`firstBookableDay`) | buchbare Zeitrahmen + heute |
| Tag liegt nach dem Ende des angefragten Bereichs | `CalendarWindow` | Parameter der Anfrage |
| Status für Karte/Tabelle | `DayStatus::fromDayArray()` | Tagesfakten nach dem Fenster |
| Rollenbeschränkung | Zeile je Zugriffsprofil in `cb_availability_access` (8.8), Auswahl beim Lesen | Rollen des angemeldeten Nutzers |
| Slots mit Uhrzeiten für den Buchungskalender der Artikelseite | dort live | – |

Geprüft wurde, dass `Day::getGrid()`, `mapTimeFrames()`, `mapRestrictions()`, `getHigherPrioFrame()` und `isLocked()`
nur von den Beiträgen abhängen, nicht von `time()`. Alle datumsabhängigen Teile steckten in
`View\Calendar::getCalendarDataArray()` und `mapDay()` und liegen jetzt in `CalendarWindow`. Eine Ausnahme beschreibt
31.2 (`Timeframe::isBookable()`).

Ohne Rollenbeschränkung ist das Ergebnis für alle Nutzer gleich, auch für Admins:
`commonsbooking_isCurrentUserAllowedToBook()` liefert bei leerer Rollenliste immer `true`. Mit Rollenbeschränkung
hängt es nur vom Zugriffsprofil ab (8.8).

### 8.5 Lebenszyklus einer Zeile

| Zustand | `gen` / `computed_gen` | `days` | wie erreicht | beim Lesen |
|---|---|---|---|---|
| **angelegt** | 1 / 0 | NULL | `Maintenance::syncPairs()` (stündlich) oder `Store::getGen()` vor der ersten Berechnung (`INSERT IGNORE`) | ungültig → berechnen |
| **gültig** | n / n | gesetzt | `Store::write()` nach `Calculator::compute()` | Tagesfakten aus `days` |
| **invalidiert** | n+1 / n | alt | `Store::invalidate()` bei einer Änderung | ungültig → berechnen |
| **gealtert** | n / n | gesetzt, `computed_at` > 24 h | Zeit vergeht (Cron rechnet ab 12 h nach) | ungültig → berechnen |
| **veraltete Rechenversion** | n / n | gesetzt, `calc_version` alt | Update mit neuer `Calculator::VERSION` | ungültig → berechnen |
| **rollenbeschränkt** | n / n | NULL, `has_role_limit = 1` | Berechnung stellt Rollenbeschränkung fest | Tage aus der Zeile des Zugriffsprofils (8.8) |
| **entfernt** | – | – | `syncPairs()`: kein buchbarer Zeitrahmen mehr | live (meist nichts anzuzeigen) |

`Store::invalidate()` erhöht nur **vorhandene** Zeilen. Bekommt ein Paar erstmals einen buchbaren Zeitrahmen, gibt es
noch keine Zeile: Der erste Leser legt sie über `Calculator::recompute()` an, spätestens der stündliche `syncPairs()`.

### 8.6 Gültigkeit einer Zeile (`Store::isUsable()`)

Eine Zeile wird nur verwendet, wenn **alle** Bedingungen gelten:

1. `days` und `days_from` sind gesetzt,
2. `has_role_limit = 0`,
3. `computed_gen = gen` (keine Änderung seit der Berechnung),
4. `calc_version = Calculator::VERSION`,
5. `computed_at` ist höchstens 24 Stunden alt (Filter `commonsbooking_availability_max_age`, Standard 86400 s).

Zusätzlich prüft `Store::getFlags()` je Tag, ob das Datum in `days` liegt. Liegt ein angefragter Tag außerhalb, rechnet
der `Reader` das ganze Paar live.

Damit hängt die Korrektheit **nicht** davon ab, wann neu berechnet wird: Eine veraltete Zeile wird nie ausgeliefert.

Ein Bild dafür: Am Brett hängt ein Aushang „Stand 41“, im Büro ist man schon bei Stand 42. Jeder weiß, dass der Aushang
nicht mehr gilt, auch wenn noch niemand einen neuen aufgehängt hat.

### 8.7 Spalte `slots`: freie Zeitfenster für API und GBFS (`Slots`)

Karte und Tabelle brauchen nur den Tageszustand. Die REST-API (`/availability`) und GBFS wollen wissen, **von wann bis
wann** ein Artikel frei ist:

```json
{ "start": "2026-11-09T00:00:00+00:00", "end": "2026-11-09T23:59:59+00:00", "locationId": "455", "itemId": "812" }
```

(`+00:00` ist keine Umrechnung nach UTC: CommonsBooking speichert Ortszeit als Zeitstempel und gibt sie so aus. Das war
in 2.11.3 genauso.)

Aus demselben Raster sammelt `Slots::bookableSlots()` die Slots, deren höchstpriorer Zeitrahmen buchbar ist (Typ 2),
also weder gebucht noch gesperrt – exakt die Bedingung der bisherigen Live-Methode. `Slots::pack()` speichert sie
little endian:

```
Kopf     uint16  T = Anzahl der vorkommenden Zeitrahmen
         T ×     uint32 Zeitrahmen-ID, uint32 Standort-ID, uint32 Artikel-ID   (0 = keiner)
je Tag   uint8   S = Anzahl Slots (höchstens 255)
         S ×     uint32 Start, uint32 Ende (Sekunden ab Tagesbeginn), uint16 Index in der Kopfliste
```

Die Tage stehen lückenlos ab `days_from`. Beispiel: ganztägiger Zeitrahmen 501, ein Tag frei, ein Tag gebucht:

```
01 00                                       T = 1
F5 01 00 00  C7 01 00 00  2C 03 00 00       Zeitrahmen 501, Standort 455, Artikel 812
01  00 00 00 00  7F 51 01 00  00 00         Tag 1: 1 Slot, 0 … 86399 s, Kopf-Index 0
00                                          Tag 2: 0 Slots (gebucht)
```

Beispiel Stundenraster Toni (814 @ 456) am Mo 09.11. mit Buchung 9310 von 10 bis 12 Uhr: acht Slots
08–09, 09–10, 12–13, 13–14, 14–15, 15–16, 16–17, 17–18, also Start/Ende 28800/32399, 32400/35999, 43200/46799 … .

Größe: 2 Byte Kopf + 12 Byte je Zeitrahmen, je Tag 1 Byte + 10 Byte je Slot. Ganztägig über 94 Tage ≈ 1 KB,
Stundenraster mit 10 Slots ≈ 9,5 KB, schlechtester Fall (24 Stundenslots an 94 Tagen) ≈ 22 KB. `mediumblob` statt
`blob` (64 KB), damit auch Halb- oder Viertelstundenraster Platz haben.

Begründungen:

- **Zeitrahmen, Standort, Artikel im Kopf:** Die API gibt je Slot `locationId` und `itemId` des **Zeitrahmens** aus,
  aus dem der Slot stammt. Der Kopf hält das fest, damit das Ergebnis Byte für Byte dem Live-Weg entspricht. Die
  Zeitrahmen-ID braucht der Leser außerdem für die Prüfung „erster buchbarer Tag“.
- **Sekunden ab Tagesbeginn statt Uhrzeiten:** gespeichert wird `timestampstart − Tagesbeginn`, genau wie der
  bestehende Code den Slot berechnet. Eigenheiten der Zeitstempel (Ortszeit als UTC, Sommerzeit) bleiben erhalten.
- **Dieselbe Zeile wie `days`:** Generation, Invalidierung, `calc_version`, `has_role_limit`, Cron und Verifier gelten
  ohne weiteren Code auch für die Slots; es gibt keinen zweiten Speicher, der getrennt veralten könnte. Verworfen wurde,
  Slots beim Lesen aus Tagesfakten und Rasterkonfiguration abzuleiten: spart Speicher, baut aber die Rasterlogik ein
  zweites Mal nach und widerspricht „ein Rechenkern für beide Wege“.

Die Spalte kam nachträglich hinzu (`Availability\Schema::SCHEMA_VERSION = 2`, `Calculator::VERSION = 2`). Wer eine
frühere Beta hatte, bekommt die Tabelle beim Update verworfen und neu angelegt.

### 8.8 `{prefix}cb_availability_access`: rollenbeschränkte Paare je Zugriffsprofil

Hat ein Paar einen rollenbeschränkten Zeitrahmen, sehen nicht alle Nutzer dieselben Tage: Ein Gast sieht „gesperrt“,
ein Mitglied „frei“. Der einzige nutzerabhängige Schritt der Tagesberechnung ist
`commonsbooking_isCurrentUserAllowedToBook()` (in `Day`, `DayFacts`, `Repository\Timeframe`). Er hängt nur davon ab,
welche der erlaubten Rollen der Nutzer hat, und ob er Admin ist. Daraus folgt das **Zugriffsprofil**
(`AccessProfile::forCurrentUser()`):

```
R      = sortierte Vereinigung der allowed_user_roles aller rollenbeschränkten Zeitrahmen des Paares
         (publish/confirmed, aus cb_index mit has_role_limit = 1, Mehrfach-Zeitrahmen über cb_index_rel)
Profil = R                                    für Admins (commonsbooking_isCurrentUserAdmin)
       = sortiert( Rollen des Nutzers ∩ R )   sonst, mit Kommas verbunden; '' = keine passende Rolle
```

Alle Nutzer mit gleichem Profil sehen dieselben Tage. Ein Admin bekommt das Profil eines Nutzers mit allen Rollen
aus R: Beide dürfen jeden Zeitrahmen des Paares buchen. Bei Paula @ Repair-Café (504, nur „Mitglied“) gibt es also
genau zwei Profile: `''` (Gäste, Abonnenten, alle ohne „Mitglied“) und `cb_member` (Mitglieder und Admins;
`cb_member` steht hier als Rollenname für „Mitglied“).

Spalten (Primärschlüssel `(location_id, item_id, access_profile)`, `KEY last_read`):

| Spalte | Typ | Bedeutung |
|---|---|---|
| `item_id`, `location_id` | bigint unsigned | das Paar |
| `access_profile` | varchar(191) | Zugriffsprofil wie oben |
| `computed_gen` | int unsigned | `gen` der Paarzeile in `cb_availability` zur Zeit der Berechnung |
| `days_from`, `days` | date, varbinary(1024) | Tagesfakten im Format von 8.3 |
| `slots` | mediumblob, NULL | freie Slots im Format von 8.7 |
| `computed_at`, `calc_version` | datetime (UTC), smallint | wie in 8.2 |
| `last_read` | date, NULL | letzter Lesezugriff, höchstens einmal am Tag aktualisiert |

**Gültigkeit** (`AccessStore::isUsable()`): Die Paarzeile ist rollenbeschränkt, `computed_gen` gleich ihrer `gen`,
`calc_version` aktuell, `computed_at` höchstens 24 Stunden alt, der Tag liegt in `days`. Das Profil hat keine eigene
Generation: Jede Änderung am Paar erhöht die `gen` der Paarzeile (11.1) und macht damit alle Profile des Paares
ungültig, ohne diese Tabelle anzufassen.

**Berechnen** (`Calculator::compute( item, location, profil )`): `AccessProfile::runAs()` setzt für die Dauer der
Berechnung `$current_user` auf einen nicht angemeldeten `WP_User` (ID 0, also kein Admin) mit genau den Rollen des
Profils und stellt den echten Nutzer im `finally` wieder her. Dann läuft derselbe Rechenkern wie für jedes Paar.
Geschrieben wird mit Generationsprüfung in einer Anweisung:

```sql
INSERT INTO wp_cb_availability_access (item_id, location_id, access_profile, computed_gen, days_from, days, slots, …)
SELECT 813, 457, '', 42, '2026-11-05', UNHEX('…'), UNHEX('…'), …
  FROM wp_cb_availability WHERE location_id = 457 AND item_id = 813 AND gen = 42
ON DUPLICATE KEY UPDATE computed_gen = VALUES(computed_gen), days = VALUES(days), …
```

Hat sich `gen` inzwischen erhöht, schreibt die Anweisung nichts (wie 11.4).

**Wann gerechnet wird:** `Calculator::recompute()` stellt für ein rollenbeschränktes Paar die Paarzeile fertig und
berechnet sofort die beiden häufigsten Profile `''` und `R`. Weitere Profile (z. B. Nutzer mit nur einer von zwei
erlaubten Rollen) entstehen beim ersten Lesen. Je Paar werden höchstens **8** Profile gespeichert (Filter
`commonsbooking_availability_access_profiles_max`, mindestens 2); jedes weitere Profil rechnet der Leser live, wie vor
den Zugriffsprofilen.

**Aufräumen** (`AccessStore::cleanup()`, täglich vor der Stichprobe): Zeilen ohne Paarzeile, Zeilen von Paaren ohne
Rollenbeschränkung, Zeilen mit alter `computed_gen` und Zeilen, die 30 Tage nicht gelesen wurden
(`AccessStore::UNUSED_DAYS`), werden gelöscht.

**Schalter:** Filter `commonsbooking_availability_access_profiles` (Standard `true`). Mit `false` rechnet der Leser
rollenbeschränkte Paare wieder immer live.

**Schema und Downgrade:** Die Tabelle kam mit `Availability\Schema::SCHEMA_VERSION = 3`. Beim Update werden beide
Tabellen einmal verworfen und neu angelegt (reiner Cache). Eine ältere Version kennt die Tabelle nicht und lässt sie
liegen; sie wird nie gelesen, `wp commonsbooking booster purge` und die Deinstallation entfernen sie.

---

## 9. Optionen, Cron-Ereignisse, Filter und Actions

### 9.1 Optionen

| Option | autoload | Inhalt |
|---|---|---|
| `commonsbooking_options_advanced-options[booster_enabled]` | (bestehend) | Schalter (`on`) |
| `commonsbooking_booster_state` | ja | `active`, `activated_at`, `plugin_version`, `watermark` (höchste geprüfte Post-ID), `last_rebuild_reason`, `last_rebuild` |
| `commonsbooking_index_schema_version` | ja | installierte Struktur von `cb_index`/`cb_index_rel` |
| `commonsbooking_availability_schema_version` | ja | installierte Struktur von `cb_availability` |
| `commonsbooking_index_ready` | ja | `PROJECTOR_VERSION` des vollständigen Backfills; fehlt = Index nicht lesbar |
| `commonsbooking_index_backfill_cursor` | nein | höchste bereits projizierte Post-ID während des Aufbaus |
| `commonsbooking_availability_pairs_synced` | – | letzter Abgleich der Paarliste |
| `commonsbooking_booster_checks` | nein | letzter Lauf und Ergebnis jeder Prüfung |
| `commonsbooking_booster_activity` | nein | Ringpuffer der letzten 30 Index-Änderungen (Diagnose) |

### 9.2 Cron-Ereignisse

| Hook | Rhythmus | Klasse | Aufgabe |
|---|---|---|---|
| `commonsbooking_index_backfill` | plant sich selbst neu, 20 s pro Lauf, 500 Beiträge je Stapel | `Index\Backfill` | Index-Aufbau |
| `commonsbooking_index_verify` | täglich 04:00 | `Index\Verifier` | Stichprobe von 200 Beiträgen gegen frische Projektion, repariert |
| `commonsbooking_booster_guard` | stündlich | `Booster\Guard` | Tiefenprüfung Status/Änderungsdatum |
| `commonsbooking_availability_refresh` | stündlich, bei Bedarf zusätzlich | `Availability\Maintenance` | neue Paare, geänderte und über 12 h alte Zeilen neu berechnen (20 s Budget) |
| `commonsbooking_availability_verify` | täglich 04:30 | `Availability\Maintenance` | Zugriffsprofile aufräumen, Stichprobe von 50 Paaren und 50 Zugriffsprofilen gegen frische Berechnung, repariert |

Ausschalten des Boosters und Deaktivieren des Plugins entfernen alle Ereignisse.

### 9.3 Filter

| Filter | Standard | Wirkung |
|---|---|---|
| `commonsbooking_booster_enabled` | Einstellung | Booster an/aus (überstimmt die Einstellung) |
| `commonsbooking_use_index` | `true` | `false`: Repositories lesen über `postmeta`, Vorberechnung ebenfalls aus |
| `commonsbooking_use_availability_cache` | `true` | `false`: Karte/Tabelle/API rechnen jeden Tag live, Index bleibt |
| `commonsbooking_availability_horizon_days` | 92 | Vorberechnungszeitraum |
| `commonsbooking_availability_max_age` | 86400 | maximales Alter einer Zeile in Sekunden |
| `commonsbooking_availability_sync_limit` | 25 | Paare, die am Request-Ende neu berechnet werden |
| `commonsbooking_availability_finish_request` | `true` | `fastcgi_finish_request()` vor der Neuberechnung |
| `commonsbooking_availability_access_profiles` | `true` | `false`: rollenbeschränkte Paare immer live statt je Zugriffsprofil |
| `commonsbooking_availability_access_profiles_max` | 8 | höchstens gespeicherte Zugriffsprofile je Paar (mindestens 2) |

### 9.4 Actions

| Action | Parameter | Ausgelöst von | Zuhörer |
|---|---|---|---|
| `commonsbooking_index_rows_changed` | `$old`, `$new`, `$postId` | `Projector::notify()`, wenn sich die Projektion (ohne Verwaltungsspalten) geändert hat | `Invalidator::onIndexChange()` (Prio 10), `Diagnostics::onIndexChange()` (Prio 20) |
| `commonsbooking_index_rebuilt` | – | `Backfill::finish()` | `Invalidator::onIndexRebuilt()` |

---

# Teil C – Schreibprozesse

## 10. Index pflegen: Indexer und Projector

### 10.1 Hooks

`Indexer::init()` registriert (nur bei eingeschaltetem Booster):

| Hook | Priorität | Methode | Aktion |
|---|---|---|---|
| `init` | 1 | `Schema::maybeInstall()` | Tabellenstruktur prüfen/erweitern |
| `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | 10 | `onMetaChange()` | relevanter Schlüssel und Beitragstyp → `touch()` |
| `transition_post_status` | 10 | `onTransitionPostStatus()` | Beitragstyp passt → `touch()` |
| `save_post` | **`PHP_INT_MAX`** | `onSavePost()` | `touch()` und sofort `flush()` |
| `deleted_post` | 10 | `onDeletedPost()` | aus der Merkliste nehmen, `Projector::remove()` |
| `shutdown` | 0 | `flush()` | Auffangnetz, läuft auch nach Fatal Errors |
| `commonsbooking_index_backfill`, `commonsbooking_index_verify` | – | Cron | siehe 12 |

`save_post` läuft mit der höchsten Priorität, also **nach** `CustomPostType\Timeframe::savePost()` (Priorität 11), das
Metadaten umschreibt und ungültige Zeitrahmen auf `draft` setzt. Der Index sieht damit den endgültigen Stand.

**Relevante Meta-Schlüssel:** `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`,
`repetition-start`, `repetition-end`, `timeframe-repetition`, `weekdays`, `timeframe_manual_date`, `grid`, `full-day`,
`start-time`, `end-time`, `timeframe-max-days`, `timeframe-advance-booking-days`, `booking-startday-offset`,
`allowed_user_roles`, `restriction-*`. Alle anderen (`_edit_lock`, Buchungscodes, Kommentare …) lösen nichts aus.

### 10.2 Merkliste und `flush()`

`Indexer` hält eine Merkliste `$dirty` (Post-ID → `true`) pro Request. Mehrfaches Markieren desselben Beitrags (z. B.
zehn `update_post_meta`-Aufrufe beim Speichern eines Zeitrahmens) führt zu **einer** Projektion.

```php
Indexer::flush():
    wenn Merkliste leer oder Tabellen nicht installiert → nichts tun
    IDs übernehmen, Merkliste leeren            // Projektion kann neue Hooks auslösen
    für jede ID: try { Projector::project( id ) } catch ( Throwable ) { Log }   // Request bricht nie ab
```

`flush()` läuft:

1. am Ende von `save_post` (Priorität `PHP_INT_MAX`),
2. explizit in `Model\Booking::cancel()` nach dem direkten SQL,
3. **vor jedem Lesen** aus dem Index (`Repository\Index::findPostIds()`) und aus der Vorberechnung
   (`Reader::preload()`, `SlotReader`, `Invalidator::processQueue()`) – das ist „read your writes“,
4. auf `shutdown` (Priorität 0) als Auffangnetz.

Ohne Index-Tabellen ist `flush()` wirkungslos (`Schema::isInstalled()`), deshalb können die Aufrufe in `cancel()`
auch bei ausgeschaltetem Booster bleiben.

### 10.3 `Projector::project()` Schritt für Schritt

```
project( postId, notify = true ):
  1. post = get_post( postId )
  2. neu  = buildRow( post )                    // null, wenn kein CB-Typ oder auto-draft oder gelöscht
  3. alt  = load( postId )                      // nur wenn notify und jemand auf CHANGED_HOOK hört
  4. neu === null ? delete( postId )            // Zeile + Relationen löschen
                  : write( row, rel )           // REPLACE INTO cb_index; DELETE + INSERT IGNORE cb_index_rel
  5. notify: fingerprint( alt ) ≠ fingerprint( neu )  →  do_action( commonsbooking_index_rows_changed, alt, neu, postId )
```

`buildRow()` liest alle Metas des Beitrags einmal und füllt je nach Typ `fillTimeframe()` (Zeitrahmen und Buchungen)
oder `fillRestriction()`. Der **Fingerabdruck** ignoriert die Verwaltungsspalten `source_modified_gmt` und
`projector_version` und sortiert Relationen. Speichert jemand einen Zeitrahmen ohne fachliche Änderung (nur Titel),
ändert sich nur `source_modified_gmt` – der Fingerabdruck bleibt gleich, es wird nichts invalidiert.

Der Backfill ruft `project( id, false )` auf: kein Laden der alten Zeile, keine Action. Stattdessen feuert er am Ende
einmal `commonsbooking_index_rebuilt`.

**Beispiel: Projektion der Buchung 9301**

| Schritt | Ergebnis |
|---|---|
| Metas lesen | `type = 6`, `item-id = 812`, `location-id = 455`, `repetition-start = 1794268800`, `repetition-end = 1794355199`, … |
| Zuordnung | Vereinigung {812} × {455} → `item_id 812`, `location_id 455`, `is_multi 0`, keine Relationen |
| Zeitraum | `start_ts 1794268800`, `end_ts 1794355199` |
| Raster | Buchungen tragen kein `full-day`; `grid`, Zeiten wie im Meta |
| alte Zeile | keine (neue Buchung) |
| Fingerabdruck | `null` ≠ neu → Action mit `alt = null`, `neu = {row, rel}` |

**Beispiel: Normalisierungsregeln an Altwerten**

| Meta | Wert | Spalte |
|---|---|---|
| `repetition-start` | `'2026-01-01'` (Text aus einem alten Import) | `start_ts = strtotime('2026-01-01')` |
| `repetition-end` | `''` | `end_ts = NULL` (offenes Ende) |
| `repetition-end` | `'0'` | `end_ts = NULL` |
| `weekdays` | `['1','2','3','4','5']` | `weekdays = 31` |
| `start-time` | `'8:00 AM'` | `start_time = '08:00:00'` |
| `timeframe-advance-booking-days` | `'abc'` | `advance_days = NULL` |
| `item-id` = 812 **und** `item-id-list` = `['812','813']` | | Vereinigung {812, 813} → `is_multi = 1` |

### 10.4 Regel für eigenen Code

Wer Beiträge der drei Typen **ohne** WordPress-Hooks schreibt (direktes SQL), muss
`Indexer::touch( $postId )` und `Indexer::flush()` aufrufen, ggf. vorher `clean_post_cache( $postId )`. Sonst sieht der
Index die Änderung erst über den Wächter (Status, Änderungsdatum) oder den täglichen Verifier (Metadaten).

---

## 11. Verfügbarkeit pflegen: Invalidator, Calculator, Store

### 11.1 Invalidieren: welche Paare sind betroffen?

`Invalidator::onIndexChange( $old, $new )` behandelt **alte und neue Zeile getrennt**. Für jede bestimmt `getScope()`
die betroffenen Paare:

```
getScope( zeile ):
  wenn nicht wirksam (isEffective)                → keine
       Zeitrahmen: post_status = publish
       Buchung:    post_status = confirmed            (unbestätigte blockieren den Kalender nicht)
       Einschränkung: post_status ∈ Day::RESTRICTION_STATUSES (publish, confirmed, unconfirmed)
  wenn Zeitraum den Horizont ± 2 Tage nicht berührt (overlapsHorizon) → keine
  Einschränkung:
       ohne Start (start_ts NULL)                 → keine (wirkt nie; vermeidet Fehlalarm beim Anlegen)
       Artikel 0 → alle Artikel, Standort 0 → alle Standorte
  Mehrfachauswahl (is_multi = 1):                 → Artikel × Standorte aus cb_index_rel
  sonst:                                          → (item_id, location_id)
```

Danach `Store::invalidate( artikel|null, standorte|null )`:

```sql
UPDATE wp_cb_availability SET gen = gen + 1 WHERE item_id IN (812) AND location_id IN (455);
SELECT item_id, location_id FROM wp_cb_availability WHERE item_id IN (812) AND location_id IN (455);
```

`null` heißt „alle“ und lässt die Bedingung weg. Die zweite Abfrage liefert die tatsächlich vorhandenen Paare, die in
die Warteschlange zur Neuberechnung gehen. Beides dauert unter einer Millisekunde und läuft **im selben Request**.
Außerdem wird `Calculator::reset()` aufgerufen: Ergebnisse, die in diesem Request schon berechnet wurden, sind veraltet.

**Warum die alte Zeile wichtig ist:** Wird Buchung 9301 von Emil (812) auf Paula (813) umgebucht, liefert die alte
Zeile das Paar (812, 455) und die neue (813, 455). Beide werden invalidiert. Ohne alte Zeile bliebe Emil am 10.11. als
gebucht stehen.

### 11.2 Neu berechnen: Warteschlange und Grenze

`enqueue()` sammelt die invalidierten Paare (dedupliziert). Ab 25 Paaren (Filter
`commonsbooking_availability_sync_limit`) wird nichts mehr aufgenommen, stattdessen `overflow = true`.

Am Ende des Requests (`shutdown`, Priorität `PHP_INT_MAX`) läuft `Invalidator::onShutdown()`:

```
Indexer::flush()                                   // letzte Änderungen in den Index und invalidieren
wenn Warteschlange leer und kein Überlauf → fertig
wenn PHP-FPM und nicht WP-CLI und Filter erlaubt → fastcgi_finish_request()   // Antwort geht an den Browser
processQueue():
    Überlauf → Maintenance::scheduleRefresh()      // Cron-Ereignis „sofort“
    für jedes Paar der Warteschlange: Calculator::recompute()   // Fehler werden geloggt, Zeile bleibt ungültig
```

Bei einem Überlauf werden die ersten 25 Paare trotzdem sofort berechnet, der Rest vom Cron. Leser, die vorher kommen,
berechnen fehlende Paare selbst (16.3). Ohne PHP-FPM (z. B. `mod_php`) läuft die Neuberechnung vor dem Ende der
Antwort; eine Buchung kostet dann rund 13 ms mehr.

### 11.3 `Calculator::recompute()` und `Store::write()`

```
recompute( item, location ):
  G      = Store::getGen( item, location )        // INSERT IGNORE (gen 1, computed_gen 0), dann SELECT gen
  result = compute( item, location )
  Store::write( item, location, G, result )       // optimistisch, siehe unten
  Ergebnis im Request merken (getComputed)        // für Leser im selben Request
  return result

compute( item, location ):
  [from, to] = Horizont (gestern … heute + 92)
  Rollenbeschränkung (hasRoleLimit über cb_index) → { roleLimited: true }, keine Tage
      (recompute rechnet dann die Profile '' und R über compute( item, location, profil ), 8.8)
  Plugin::withoutCache( {
      calendar = new Model\Calendar( from, to, [location], [item] )
      für jeden Tag: grid = Day::getGrid()
                     flags[datum] = DayFacts::fromGrid( grid )->getFlags()
                     slots[datum] = Slots::bookableSlots( grid )
  } )
  Liste lückenlos ab from auffüllen (fehlende Tage = 0, keine Slots)
  return { from, flags, slots: Slots::pack(), roleLimited: false }
```

`compute()` nutzt den **bestehenden** Kalender – nur die Abfragen darunter laufen über den Index. `withoutCache()` ist
nötig, weil der Plugin-Cache zwischengespeicherte Wochen aus der Zeit vor der Änderung liefern könnte.

```sql
-- Store::write( 812, 455, G = 42, … )
UPDATE wp_cb_availability
   SET computed_gen = gen, days_from = '2026-11-05', days = UNHEX('0102010 2…'), slots = UNHEX('0100F501…'),
       has_role_limit = 0, computed_at = '2026-11-06 10:15:03', calc_version = 2
 WHERE location_id = 455 AND item_id = 812 AND gen = 42
```

### 11.4 Gleichzeitige Änderungen ohne Sperren

Die Bedingung `gen = G` verhindert, dass ein Ergebnis gespeichert wird, das eine parallele Änderung nicht kennt:

| Zeit | Anfrage A (rechnet nach Buchung X) | Anfrage B (bucht Y) | Zeile `gen` / `computed_gen` |
|---|---|---|---|
| t1 | liest `gen = 42` | | 42 / 41 |
| t2 | rechnet (kennt Y noch nicht) | speichert Y, `gen = gen + 1` | 43 / 41 |
| t3 | `UPDATE … WHERE gen = 42` trifft **nichts** | | 43 / 41 (ungültig) |
| t4 | gibt ihr Ergebnis nur im eigenen Request weiter | `shutdown`: liest `gen = 43`, rechnet, schreibt | 43 / 43 (gültig, mit X und Y) |

Ohne `gen = G` hätte A in t3 einen Stand ohne Y als gültig markiert. Es gibt keine Sperren, die andere Anfragen warten
lassen. `recompute()` liefert sein Ergebnis auch dann zurück, wenn es nicht gespeichert wurde: Es spiegelt den Stand,
den A gelesen hat, und das ist für A korrekt.

### 11.5 Ablauf einer Zeile als Zeitstrahl

```
gen/computed_gen:   1/0 ──recompute──► 1/1 ──Buchung──► 2/1 ──shutdown recompute──► 2/2 ──12 h──► (Cron) 2/2 neu
                    angelegt           gültig          invalidiert                    gültig          computed_at neu
Leser sieht:        berechnet selbst   Zeile           berechnet selbst oder live     Zeile           Zeile
```

---

## 12. Neuaufbau (Backfill) und Wartung

### 12.1 Backfill

```
Backfill::start():        commonsbooking_index_ready löschen   → Index ab sofort nicht lesbar
                          cursor = 0, Cron-Ereignis einplanen
Backfill::runCron():      solange nicht fertig und < 20 s: runBatch()   danach neu einplanen
Backfill::runBatch(500):  SELECT ID FROM wp_posts WHERE ID > cursor AND post_type IN (…) ORDER BY ID LIMIT 500
                          _prime_post_caches( ids )             // zwei Abfragen statt zwei je Beitrag
                          für jede ID: Projector::project( id, notify = false )
                          cursor = letzte ID; Objekt-Cache dieser Beiträge freigeben (Speicher)
                          weniger als 500 gefunden → finish()
Backfill::finish():       Zeilen ohne Beitrag löschen (cb_index, dann cb_index_rel)
                          commonsbooking_index_ready = PROJECTOR_VERSION  → Index lesbar
                          do_action( commonsbooking_index_rebuilt )        → alle Paare invalidieren
```

Während des Aufbaus schreiben die Hooks bereits in den Index; was der Backfill danach überschreibt, ist idempotent
(`REPLACE`). Gelesen wird bis zum Ende über die Originalabfragen. Geschwindigkeit: rund 5.000 Beiträge/s; 17.100
Beiträge in 2,5–3,4 s, 167.000 in 24–40 s. `wp commonsbooking booster enable --now` bzw. `index rebuild` laufen
synchron (`runAll()`).

Gegenüber dem Entwurf wurde der Backfill vereinfacht: kein Bulk-`INSERT … SELECT` als Schnellpfad (die
PHP-Projektion ist schnell genug und es gibt nur eine Projektionslogik) und keine AJAX-Upgrade-Tasks (die hängen an
der Plugin-Version und werden bei Neuinstallationen ignoriert).

### 12.2 `Maintenance` (Vorberechnung)

| Aufgabe | wann | was |
|---|---|---|
| `syncPairs()` | stündlich (Entfernen einmal am Tag) | Paare aus `cb_index` (Typ 2, `publish`, nicht vor dem Horizont endend) ermitteln; fehlende Zeilen anlegen, Zeilen ohne buchbaren Zeitrahmen löschen |
| `refresh()` | stündlich, nach Überlauf oder Neuaufbau sofort | `Store::getStalePairs()`: `computed_gen ≠ gen`, alte `calc_version`, nie berechnet oder älter als 12 h; älteste zuerst; 20 s Budget, danach in 30 s neu eingeplant |
| `AccessStore::cleanup()` | täglich 04:30, vor `verify()` | verwaiste, veraltete und 30 Tage ungelesene Zugriffsprofile löschen (8.8) |
| `verify()` | täglich 04:30 | 50 zufällige Paare und 50 zufällige Zugriffsprofile frisch berechnen und mit der Zeile vergleichen (Tagesfakten **und** Slots, `matches()`); Abweichung → neu schreiben |

Die 12-Stunden-Regel lässt den Horizont mitwandern: Eine am Montag 10 Uhr berechnete Zeile beginnt bei Sonntag und
endet bei Montag + 92. Ab Montag 22 Uhr wird sie neu berechnet, spätestens nach 24 h ist sie ungültig.

### 12.3 Index-Verifier

`Verifier::verify( 200, repair: true )` (täglich 04:00, `wp commonsbooking index verify --sample=<n> --repair`):
Stichprobe von Beiträgen, `buildRow()` frisch berechnen, mit der gespeicherten Zeile vergleichen, Abweichungen neu
projizieren (das löst die Invalidierung aus). Findet reine Meta-Änderungen an den Hooks vorbei, die der Wächter nicht
sieht.

---

## 13. Schreibszenarien von Anfang bis Ende

### 13.1 Szenario A: Nutzerin 17 bucht Emil für Mo 09.11. im Frontend

| # | Wo | Was passiert | Tabelle |
|---|---|---|---|
| 1 | Browser | Klick auf „Buchen“; das Skript aus `includes/Public.php` sperrt das Formular („Bitte warten …“) | – |
| 2 | `Plugin::handleBookingForms()` | Aufruf von `Booking::handleFormRequest()` innerhalb von `Booster::withoutBooster()`: ab hier liest nichts aus Index, Vorberechnung oder Plugin-Cache | – |
| 3 | `CustomPostType\Booking::withItemLock()` | `GET_LOCK('cb_booking_1_812', 20)`: parallele Anfragen für Emil warten | – |
| 4 | `handleBookingRequest()` | Überschneidungsprüfung und Buchungsregeln über die **Originalabfragen** (`postmeta`) | liest `wp_postmeta` |
| 5 | WordPress | `wp_insert_post()` legt Buchung 9320 an (`unconfirmed`), Metas werden gesetzt | `wp_posts`, `wp_postmeta` |
| 6 | Indexer | `added_post_meta` (relevante Schlüssel) und `transition_post_status` markieren 9320 | Merkliste |
| 7 | Indexer | `save_post` (`PHP_INT_MAX`) → `flush()` → `Projector::project(9320)` | `cb_index`: neue Zeile, `post_status unconfirmed` |
| 8 | Invalidator | Action mit alt = null, neu = Zeile; `isEffective` = nein (unbestätigt) → **keine** Invalidierung | – |
| 9 | Lock | `RELEASE_LOCK`; Weiterleitung zur Bestätigungsseite | – |
| 10 | Bestätigung | Nutzerin bestätigt; Status wird `confirmed`, `save_post` → Projektion | `cb_index`: `post_status confirmed` |
| 11 | Invalidator | alt = unbestätigt (nicht wirksam), neu = bestätigt (wirksam, 09.11. im Horizont) → `UPDATE … gen = gen + 1 WHERE item_id IN (812) AND location_id IN (455)` → Warteschlange [(812, 455)] | `cb_availability`: 41 → **42** / 41 |
| 12 | `shutdown` | `fastcgi_finish_request()` – die Antwort ist beim Browser | – |
| 13 | Invalidator | `processQueue()` → `Calculator::recompute(812, 455)`: liest G = 42, rechnet 94 Tage (≈ 13 ms), schreibt mit `WHERE gen = 42` | `cb_availability`: 42 / **42**, Versatz 4 (09.11.) = 487 |
| 14 | Diagnostics | Eintrag im Aktivitätsprotokoll (Herkunft „Website“) | Option `commonsbooking_booster_activity` |

Wer zwischen Schritt 11 und 13 die Karte öffnet, bekommt keine veraltete Zeile: 42 ≠ 41, der `Reader` berechnet das
Paar selbst (16.3).

### 13.2 Szenario B: Admin storniert Buchung 9301 (direktes SQL)

`Model\Booking::cancel()` setzt `post_status` per direktem `UPDATE` und umgeht damit alle Post-Hooks. Neu danach:

```php
clean_post_cache( $this->post->ID );               // sonst liefert get_post() im selben Request noch „confirmed“
\CommonsBooking\Service\Index\Indexer::touch( $this->post->ID );
update_post_meta( $this->post->ID, 'cancellation_time', … );   // (Hook, aber kein relevanter Schlüssel)
\CommonsBooking\Service\Index\Indexer::flush();
```

| Schritt | Ergebnis |
|---|---|
| Projektion | `cb_index`: 9301 `post_status canceled` |
| Invalidator | alt = bestätigt, 10.11. im Horizont → (812, 455) invalidieren; neu = storniert (nicht wirksam) → nichts |
| Neuberechnung | am Request-Ende, 10.11. wieder 513 („frei“) |

Derselbe Weg gilt für alle Aufrufer von `cancel()`: Admin-Statuswechsel, `Restriction::cancelBookings()` (Einschränkung
storniert betroffene Buchungen) und die Datenschutz-Löschfunktion. `clean_post_cache()` behebt nebenbei einen Fehler aus
2.11.3: Nachfolgende Lesezugriffe im selben Request sahen die Buchung noch als bestätigt.

### 13.3 Szenario C: Umbuchung auf einen anderen Artikel

Ein Admin ändert bei Buchung 9301 den Artikel von 812 auf 813.

| Zeile | `getScope()` | Invalidierung |
|---|---|---|
| alt | (812, 455), bestätigt, im Horizont | Emil @ Bäckerei: `gen + 1` |
| neu | (813, 455), bestätigt, im Horizont | Paula @ Bäckerei: `gen + 1` |

Beide Paare stehen in der Warteschlange und werden am Request-Ende berechnet. Ohne alte Zeile bliebe der 10.11. bei
Emil als gebucht stehen.

### 13.4 Szenario D: Feiertag für viele Paare (Überlauf)

Ein Admin legt einen Feiertag „alle Artikel, alle Standorte“ an, an einer großen Instanz mit 300 Paaren.

| Schritt | Ergebnis |
|---|---|
| Projektion | `cb_index`: `type 3`, `is_multi 1`; `cb_index_rel`: alle Artikel- und Standortzeilen |
| `getScope()` | Artikel × Standorte aus den Relationen |
| `Store::invalidate()` | **ein** `UPDATE … WHERE item_id IN (…) AND location_id IN (…)`: alle 300 Zeilen `gen + 1` |
| Warteschlange | die ersten 25 Paare, dann Überlauf |
| `shutdown` | 25 Paare berechnet (≈ 0,3 s nach der Antwort), `Maintenance::scheduleRefresh()` |
| Cron | `refresh()` rechnet die übrigen 275 in 20-s-Stücken |
| Leser in der Zwischenzeit | berechnen fehlende Paare selbst – so schnell wie mit „nur Index“, also kein Rückschritt |

Weil `updateAllTimeframes()` bei „alle“-Auswahl auch bei jedem neuen Artikel die Listen neu schreibt, erfasst der Index
einen neuen Artikel über die Meta-Hooks des Feiertags – getestet.

### 13.5 Szenario E: Einschränkung „Emil defekt, alle Standorte“

Einschränkung 7004 wird angelegt: Artikel 812, Standort 0, aktiv, ab 10.11.

| Zeitpunkt | Zeile | `getScope()` |
|---|---|---|
| Entwurf angelegt, Metas noch nicht gespeichert | `start_ts NULL` | keine (Einschränkung ohne Start wirkt nie) |
| gespeichert und veröffentlicht | `item_id 812`, `location_id 0`, `start_ts 1794268800` | Artikel [812], Standorte **null = alle** |

`Store::invalidate( [812], null )` → `UPDATE … WHERE item_id IN (812)` über den Schlüssel `item_id`: alle Standorte
von Emil. Wird die Einschränkung später auf `solved` gesetzt, ist die neue Zeile zwar nicht mehr „aktiv“, aber der
Status `publish` ist weiterhin wirksam; die Paare werden erneut invalidiert und die Berechnung (die nur `active` liest)
gibt die Tage wieder frei.

### 13.6 Szenario F: neuer buchbarer Zeitrahmen für ein neues Paar

Paula (813) wird ab heute auch am Stadtteilladen (456) buchbar.

| Schritt | Ergebnis |
|---|---|
| Projektion | `cb_index`: Zeitrahmen `type 2`, (813, 456) |
| Invalidator | `Store::invalidate( [813], [456] )` trifft **keine** Zeile (das Paar ist neu) → Warteschlange leer |
| erster Leser (Karte) | `Store::get()` → keine Zeile → `Calculator::recompute()` legt die Zeile an (`getGen()` mit `INSERT IGNORE`), rechnet, schreibt |
| ohne Leser | `Maintenance::syncPairs()` legt die Zeile innerhalb einer Stunde an, `refresh()` berechnet sie |

### 13.7 Szenario G: Änderungen außerhalb des Horizonts

`Service\Booking::cleanupBookings()` löscht alte unbestätigte Buchungen per `wp_delete_post()`.

| Schritt | Ergebnis |
|---|---|
| `deleted_post` | `Projector::remove()`: Zeile und Relationen gelöscht, Action mit neu = null |
| Invalidator | alt: unbestätigt → nicht wirksam → nichts |

Ebenso eine bestätigte Buchung aus dem Jahr 2024, die ein Admin nachträglich ändert: `overlapsHorizon()` liefert
`false`, es wird nichts invalidiert. So kostet Pflege der Historie keine Neuberechnungen.

### 13.8 Szenario H: Importer schreibt nur Metadaten

Ein Importer ändert per `update_post_meta( 501, 'weekdays', ['1','2','3','4','5','6'] )` die Wochentage, ohne den
Beitrag zu speichern.

| Schritt | Ergebnis |
|---|---|
| `updated_post_meta` | relevanter Schlüssel → 501 markiert |
| `shutdown` (Prio 0) | `flush()` projiziert 501: `weekdays 63`; Action → (812, 455) invalidiert |
| `shutdown` (Prio `PHP_INT_MAX`) | Neuberechnung: Samstage jetzt 513 statt 60 |

Hätte der Importer direkt per SQL in `wp_postmeta` geschrieben, sähe der Index das nicht. Dann greifen der tägliche
Verifier (Stichprobe) und die 24-Stunden-Grenze der Verfügbarkeitszeilen (20).

### 13.9 Szenario I: Fehler während der Projektion

Wirft `Projector::project()` eine Ausnahme, protokolliert `flush()` sie (`commonsbooking_write_log`) und macht weiter.
Der Request (z. B. die Buchung) läuft normal zu Ende. Die Indexzeile ist dann veraltet; das finden der Wächter (Status,
Änderungsdatum, stündlich) bzw. der Verifier (täglich). Buchungsprüfungen sind nicht betroffen (19).

---

# Teil D – Leseprozesse

## 14. Welcher Weg läuft? Die Entscheidungskette

```php
Booster::isEnabled()      // Einstellung „on“, überstimmbar per Filter commonsbooking_booster_enabled
Booster::canRead()        // isEnabled() && nicht innerhalb withoutBooster() && state['active']
Backfill::isReady()       // canRead() && Filter commonsbooking_use_index && Schema installiert
                          //   && commonsbooking_index_ready === PROJECTOR_VERSION
Index::isAvailable()      // = Backfill::isReady()                    → Repositories
Availability::isEnabled() // Filter commonsbooking_use_availability_cache && Schema installiert
                          //   && Backfill::isReady()                 → Reader, SlotReader
```

| Situation | `Index::isAvailable()` | `Availability::isEnabled()` | Verhalten |
|---|---|---|---|
| Booster aus (Standard) | nein | nein | exakt 2.11.3 |
| Booster an, Backfill läuft | nein | nein | Hooks schreiben, gelesen wird über `postmeta` |
| Booster an, Backfill fertig | ja | ja | Index + Vorberechnung |
| Filter `commonsbooking_use_availability_cache = false` | ja | nein | Index, Tage live |
| Filter `commonsbooking_use_index = false` | nein | nein | alles live |
| innerhalb `withoutBooster()` (Buchungsprüfung) | nein | nein | Originalweg, ohne Plugin-Cache |
| Wächter hat Neuaufbau gestartet | nein (bis fertig) | nein | Originalweg |

Nach der Entscheidung gilt in jeder umgestellten Methode dasselbe Muster:

```php
if ( ! Index::isAvailable() ) {
    return /* unveränderter Code aus 2.11.3 */;
}
return /* Indexweg mit identischem Ergebnis */;
```

---

## 15. Index lesen: `Repository\Index` und die umgestellten Repositories

### 15.1 Die Abfrageschicht

```php
Index::isAvailable(): bool
Index::findPostIds( array $criteria ): int[]      // sortiert nach post_id; ruft vorher Indexer::flush()
Index::loadPosts( int[] $ids ): WP_Post[]         // _prime_post_caches: Beiträge + Metas in zwei Abfragen
Index::sortByDate( array $posts, 'ASC'|'DESC' )   // wie WP_Query orderby=date; Gleichstand: ID aufsteigend
```

| Kriterium | SQL | Bemerkung |
|---|---|---|
| `post_types` | `post_type IN (…)` | |
| `types` | `type IN (…)` | |
| `statuses` | `post_status IN (…)` | |
| `items`, `locations` | Einzel- und Mehrfachteil, siehe unten | leere Liste = kein Filter; ungültige Werte → `IN (0)` |
| `start_min`, `start_max` | `start_ts >= …`, `start_ts <= …` | schließen NULL aus (altes SQL setzte den Schlüssel voraus) |
| `end_min` | `(end_ts IS NULL OR end_ts >= …)` | offenes Ende zählt |
| `end_max` | `(end_ts IS NULL OR end_ts <= …)` | |
| `end_exact` | `end_ts = …` | Erinnerungs-Mails |
| `require_end` | `end_ts IS NOT NULL` | |
| `authors` | `post_author IN (…)` | |
| `restriction_states` | `restriction_state IN (…)` | |

Mit Artikel- oder Standortfilter entsteht eine `UNION ALL` (bewusst nicht `OR`, damit jeder Teil seinen Schlüssel
nutzt):

```sql
SELECT post_id FROM (
    SELECT i.post_id FROM wp_cb_index i
     WHERE i.is_multi = 0 AND i.item_id IN (812) AND i.location_id IN (455) AND <gemeinsame Bedingungen>
  UNION ALL
    SELECT i.post_id FROM wp_cb_index i
     WHERE i.is_multi = 1
       AND EXISTS (SELECT 1 FROM wp_cb_index_rel ri WHERE ri.post_id = i.post_id AND ri.entity_type = 'item'     AND ri.entity_id IN (812))
       AND EXISTS (SELECT 1 FROM wp_cb_index_rel rl WHERE rl.post_id = i.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (455))
       AND <gemeinsame Bedingungen>
) matches ORDER BY post_id
```

### 15.2 Datumsfilter: wie `Repository\Timeframe::get()` und `getInRange()` übersetzt werden

`Repository\Timeframe::queryPosts()` (neu, privat) bildet die drei Modi des alten `getPostsByBaseParams()` nach:

| Modus (Parameter) | altes SQL | Kriterien im Index |
|---|---|---|
| ein Tag (`$date`, ohne `$minTimestamp`) | `repetition-start BETWEEN 0 AND Tag 23:59` und (`repetition-end BETWEEN Tag 00:00 AND 3000000000` oder kein Ende) | `start_min = 0`, `start_max = Tag 23:59`, `end_min = Tag 00:00`, `end_max = 3000000000` |
| ab Zeitpunkt (`$minTimestamp`) | `repetition-end >= Tagesbeginn` oder kein Ende | `end_min = getLastFullDayTimestamp( min )` |
| Zeitraum (`$minTimestamp`, `$maxTimestamp`) | Überlappung | `start_max = max`, `end_min = min` |

**Beispiel:** `Timeframe::get( [455], [812], [2,3,6], '2026-11-10', … , ['confirmed','publish'] )`

```sql
… WHERE i.is_multi = 0 AND i.item_id IN (812) AND i.location_id IN (455)
    AND i.post_type IN ('cb_timeframe','cb_booking') AND i.type IN (2,3,6)
    AND i.post_status IN ('confirmed','publish')
    AND i.start_ts >= 0 AND i.start_ts <= 1794311940            -- 10.11. 23:59
    AND (i.end_ts IS NULL OR i.end_ts >= 1794268800)             -- 10.11. 00:00
    AND (i.end_ts IS NULL OR i.end_ts <= 3000000000)
UNION ALL …   (Feiertag 620 käme hier, liegt aber nicht am 10.11.)
→ 501, 9301
```

Danach laufen **unverändert**: `filterTimeframes()` (Wochentage, manuelle Termine, maximale Buchungsdauer, Rollen),
Umwandlung in Modelle, Plugin-Cache. Der Unterschied ist nur, dass statt 600 Kandidaten zwei geladen werden.

### 15.3 Die umgestellten Repositories im Überblick

| Methode | 2.11.3 | mit Index |
|---|---|---|
| `Repository\Timeframe::get()`, `getInRange()` → `getBookable*`, `getInRangeForCurrentUser` | `getPostIdsByType()` (alle IDs des Paares) + `getPostsByBaseParams()` (Datumsfilter über Meta-Joins) | `queryPosts()` → `Index::findPostIds()` + `loadPosts()` |
| `Repository\Timeframe::getByAuthor()` (neu) | – | Kriterium `authors` (Schlüssel `author_type`); ohne Index SQL auf `post_author` + Meta `type` |
| `Repository\Booking::getByDate()`, `getByTimerange()` (→ `getExistingBookings`, `getByRestriction`, Buchungsregeln, angrenzende Buchungen), `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` mit bis zu 5 Meta-Joins | `findViaIndex()`; unterstützt `$customArgs` `author`, `post_status`, `orderby=date`, `order`; bei allem anderen `null` → `WP_Query` wie bisher; Sortierung wie `WP_Query` (`post_date DESC`) |
| `Repository\Booking::getLatestPastConfirmed()` (neu) | – | zwei Indexabfragen (15.6) |
| `Repository\Booking::getForUser()` | alle Buchungen laden, in PHP filtern | Kandidaten laden (15.5), **auch ohne Index** |
| `Repository\Restriction::get()` | `queryPosts()` per SQL über `postmeta` | `queryPostsFromIndex()` mit `restriction_states = [active]`; PHP-Filter für Artikel/Standort inkl. „alle“ unverändert |
| `Repository\BookablePost::getRelatedPosts()` (`Item::getByLocation`, `Location::getByItem`) | `WP_Query` mit Meta `{typ}-id` | `getTimeframesByRelation()`: Kandidaten aus dem Index, danach Filter auf den **einzelnen** `*-id`-Wert (alte Semantik), verwandte Beiträge gebündelt geladen (auch ohne Index) |
| `Model\Map::get_locations()` | pro Paar `Timeframe::getBookableForCurrentUser()` | `Map::getBookableTimeframesByPair()`: **eine** Abfrage für alle Paare; Term-Cache und Vorschaubilder gebündelt (auch ohne Index) |
| `Model\Item::getClosestBooking()` | alle bestätigten Buchungen des Paares laden | nur die Kandidaten mit dem spätesten Start (15.6) |

Nicht umgestellt: Admin-Listen (`filterAdminList`, `pre_get_posts`), `Booking::getForUserPaginated()`, die REST-Routen
selbst und alle Schreibwege.

### 15.4 Beispiel: Konfliktprüfung im Indexweg vs. Buchungsprüfung

`Booking::getExistingBookings()` ist im Indexweg schnell (0,07 ms statt `WP_Query` mit fünf Meta-Joins). Die
**Buchungsprüfung** beim Absenden des Formulars läuft trotzdem nie darüber, weil sie in `withoutBooster()`
eingeschlossen ist (19). Der Indexweg von `getExistingBookings()` dient Anzeigen, Regeln in Ansichten und Diagnosen.

### 15.5 „Meine Buchungen“: `Repository\Booking::getForUser()`

**2.11.3:** alle Buchungen der Instanz laden (mit Status und `minTimestamp`), dann in PHP
`commonsbooking_isUserAllowedToSee()` je Buchung. Sichtbar ist eine Buchung, wenn der Nutzer CB-Admin ist, Autor der
Buchung ist oder Admin des Artikels **oder** Standorts (`Timeframe::getAdmins()`: Meta `_cb_item_admins` bzw.
`_cb_location_admins` plus `post_author` von Artikel und Standort).

**Jetzt:** erst eine Obermenge der sichtbaren Buchungen, dann derselbe Filter.

```
getVisibleCandidates( user ):
  CB-Admin  → alle Buchungen (wie bisher)
  sonst     → eigene Buchungen                     Timeframe::getByAuthor( user, [Buchung] )
            ∪ Buchungen verwalteter Artikel        Booking::get( [], getManagedPostIds( user, cb_item ) )
            ∪ Buchungen verwalteter Standorte      Booking::get( getManagedPostIds( user, cb_location ), [] )
            nach ID sortiert, Duplikate entfernt
danach unverändert: commonsbooking_isUserAllowedToSee() über die Kandidaten
```

`getManagedPostIds()` findet Artikel/Standorte mit `post_author = user` **oder** Admin-Meta mit dem Nutzer. Die Meta
ist mal serialisierte Liste (`a:2:{i:0;s:2:"17";i:1;i:23;}`), mal String (`"17"`): SQL grenzt per `LIKE '%17%'` grob
ein, PHP prüft danach exakt (`maybe_unserialize`, Zahlvergleich). So trifft Nutzer 1 nicht die Meta von Nutzer 11
oder 17.

**Beispiel:** Nutzerin 23 hat 3 eigene Buchungen und verwaltet Standort 455 mit 1.200 Buchungen; die Instanz hat
167.000 Buchungen.

| | 2.11.3 | jetzt |
|---|---|---|
| geladene Buchungen | 167.000 | 3 + 1.200 = 1.203 |
| geprüft mit `isUserAllowedToSee()` | 167.000 | 1.203 |
| Ergebnis | 1.203 sichtbar | dieselben 1.203, gleiche Reihenfolge |

Das wirkt **auch ohne Booster**, weil `Booking::get()` ohne Index die Originalabfrage nutzt und trotzdem nur die
verwalteten Paare lädt. Für CB-Admins bleibt es beim Laden aller Buchungen (31.3).

`BookingRuleApplied` („nur eine unbestätigte Buchung je Nutzer“) holte bisher alle *sichtbaren* unbestätigten Buchungen
und warf fremde weg (ein Standort-Admin bekam auch die seiner Kundschaft). Jetzt fragt es direkt
`getForUserPaginated( $user, …, ['unconfirmed'] )`: nur eigene, über `WP_Query`, bewusst ohne Index, weil Regeln zur
Buchungsprüfung gehören.

### 15.6 GBFS-Fahrzeug-ID: `Model\Item::getClosestBooking()`

GBFS veröffentlicht je Fahrzeug eine verschleierte `vehicle_id`, die sich nach jeder Ausleihe ändert:

```php
Item::getCloakedId() = wp_hash( $item->ID . ( getClosestBooking()->post_name ?? $item->post_name ) )
```

`getClosestBooking()` liefert die **zuletzt begonnene, bereits beendete, bestätigte** Buchung am aktuellen Standort.
Verwendet von `vehicle_availability.json` (jeder Artikel), `vehicle_status.json` (jeder freie Artikel) und
`Repository\Item::getByCloakedId()` (rechnet alle Artikel, bis eine ID passt).

**2.11.3:** alle bestätigten Buchungen des Paares als Modelle laden, in PHP auf `isPast()` filtern, sortieren
(Starttag, bei Gleichstand Startzeit), `array_pop()`. Auf dev ≈ 1.300 Buchungen, 10 MB und 95 ms **je Artikel**;
`vehicle_availability.json` brach mit 128 MB Speicherlimit mit HTTP 500 ab.

**Jetzt:** `Booking::getLatestPastConfirmed( $itemId, $locationId, current_time('timestamp') )`:

```sql
-- 1. spätester Start unter den vergangenen bestätigten Buchungen des Paares
SELECT MAX(COALESCE(start_ts, 0)) FROM wp_cb_index
 WHERE item_id = 812 AND location_id = 455 AND type = 6 AND post_type = 'cb_booking'
   AND post_status = 'confirmed' AND (end_ts IS NULL OR end_ts < :jetzt)
-- 2. alle Buchungen mit genau diesem Start (meist eine)
SELECT post_id FROM wp_cb_index WHERE … AND COALESCE(start_ts, 0) = :spaetesterStart ORDER BY post_id
```

Auf diese Kandidaten läuft die **unveränderte** Sortierung. `null` (kein Index) → Originalweg.

| Eigenheit des Originals | Nachbildung |
|---|---|
| nur `confirmed`, nur Typ 6 | gleiche Bedingungen |
| „vergangen“ = `intval( getEndDate() ) < current_time('timestamp')`; ohne Ende gilt `intval('') = 0` → vergangen | `end_ts IS NULL OR end_ts < :jetzt` |
| fehlender Start = 0 | `COALESCE(start_ts, 0)` |
| Gleichstand beim Starttag: Startzeit entscheidet | alle Kandidaten mit gleichem Start laden, Originalsortierung entscheidet |
| Gleichstand bei Tag und Zeit: stabiles `usort`, `array_pop` nimmt die höchste ID | `ORDER BY post_id` |

**Beispiel** (heute 06.11. 12:00): 1.300 alte Buchungen, 9290 storniert (03.11.), **9295 bestätigt (04.11.)**, 9301
bestätigt (10.11., noch nicht vorbei). Abfrage 1 liefert den Start vom 04.11., Abfrage 2 die ID 9295. Geladen wird ein
Beitrag statt 1.300; `vehicle_id = wp_hash('812' . post_name von 9295)` wie vorher.

Geprüft: `dev-bench/closest-parity.php` – dev 126 Artikel, Testumgebung 41 Artikel, 0 Abweichungen. Messung: alle
Artikel 12,0 s → 0,9 s; `vehicle_availability.json` 13,3 s / 1.289 MB → 0,8 s / 71 MB, über HTTP 200 in 0,65 s.

### 15.7 Einschränkungen pro Zeitraum statt pro Tag (auch ohne Booster)

- `Model\Calendar` lädt die aktiven Einschränkungen der gesamten Range **einmal** (`Restriction::get()` mit
  `minTimestamp = Start − 1`) und reicht sie über `Week` an jeden `Day` weiter (neuer optionaler Konstruktorparameter
  `$possibleRestrictions`).
- `Day::getRestrictions()` filtert die vorgeladene Liste mit `isInRestriction()` (gleiche Datumsbedingung wie die
  frühere Abfrage pro Tag). Ohne Liste (`null`, direkte Nutzung von `Day`) fragt der Tag wie bisher selbst.
- Neue Konstante `Day::RESTRICTION_STATUSES = ['publish', 'confirmed', 'unconfirmed']`.

Effekt: 3.000–6.000 Abfragen weniger je Karten- bzw. Tabellenaufruf. Zusätzlich lädt `Model\Calendar` Zeitrahmen und
Einschränkungen erst in `loadPosts()`, wenn `getWeeks()` nicht aus dem Cache kommt (vorher im Konstruktor, auch bei
Cache-Treffer).

---

## 16. Verfügbarkeit lesen: `Reader` für Karte und Tabelle

### 16.1 Schnittstelle

```php
$reader = new Reader();
$reader->preload( [ [ $itemId, $locationId ], … ] );   // Zeilen + buchbare Zeitrahmen aller Paare, je eine Abfrage
$reader->getDayStatuses( $itemId, $locationId, $start, $end, $keepDaterange, $rangeFrom, $rangeTo );
    // → [ 'days' => [ 'Y-m-d' => DayStatus, … ], 'endDate' => 'Y-m-d' ]
Reader::evaluateLive( … );                              // Originalweg über Calendar::getCalendarDataArray()
```

Verwendet von `MapItemAvailable::create_items_availabilities()` (Karte) und `View\Calendar::renderTable()` /
`renderItemLocationRow()` (Artikeltabelle), jeweils nur wenn `Availability::isEnabled()`. **Nicht** verwendet von:
Buchungskalender der Artikelseite (braucht Slots mit Uhrzeiten), Buchungsprüfung.

### 16.2 `preload()`

```
Indexer::flush()                                            // Änderungen dieses Requests zuerst
Store::preload( paare )     → SELECT … FROM cb_availability WHERE (location_id = 455 AND item_id IN (812,813))
                                                              OR (location_id = 456 AND item_id IN (814)) …
                              (je 200 Standorte eine Abfrage, ohne Spalte slots)
Timeframe::getBookableForCurrentUser( alle Standorte, alle Artikel, …, letzte volle Stunde )
                            → eine Indexabfrage für alle buchbaren Zeitrahmen, je Paar zugeordnet
```

### 16.3 `getDayStatuses()` für ein Paar

```
1. Fenster:   CalendarWindow::forTimeframes( buchbare Zeitrahmen des Paares, Start, Ende, keepDaterange )
              → Start/Ende, Vorausbuchungstage, letzter buchbarer Tag, erster buchbarer Tag
2. Tage:      Reader::getCalendarDates( Start, Ende )        // wie der Kalender: bis Sonntag der Endwoche
              auf [rangeFrom, rangeTo] beschränken
3. Fakten für die Tage im Buchungsfenster (getFacts):
     a) Zeile gültig (Store::isUsable)          → DayFacts::fromFlags( Store::getFlags( zeile, datum ) )
                                                   ein Tag nicht abgedeckt → ganzes Paar live
     b) Zeile rollenbeschränkt und aktuell      → Zeile des Zugriffsprofils (8.8), sonst
                                                   Calculator::computeAccessProfile(); Grenze erreicht → live
     c) Tage außerhalb des Horizonts            → live
     d) sonst                                   → Calculator::getComputed() ?? Calculator::recompute()
                                                   (berechnet, speichert, nutzt das Ergebnis)
     live = Reader::evaluateLive() = Calendar::getCalendarDataArray() wie in 2.11.3
4. Pro Tag:   CalendarWindow::mapDay( Tag, Fakten )   → Tages-Array wie im Kalender-JSON
              DayStatus::fromDayArray( Tages-Array, hatSlots )
```

`DayStatus::fromDayArray()` (gemeinsam für Karte und Tabelle, vorher doppelt implementiert):

```
keine Slots                                      → no-slots
holiday                                          → holiday
locked und erster und letzter Slot belegt        → booked
locked und teilweise belegt                      → (erster frei und letzter belegt) ? partially-booked-start
                                                                                    : partially-booked-end
locked                                           → locked
sonst                                            → available
```

Abbildung auf Karte und Tabelle:

| `DayStatus` | Karte (`MapItemAvailable::STATUS_MAPPING`) | Tabelle (`View\Calendar::TABLE_STATUS_MARKUP`) |
|---|---|---|
| `no-slots` | `locked` | `<span class='is-locked'></span>` |
| `holiday` | `location-holiday` | `<span class='is-holiday'></span>` |
| `booked` | `booked` | `<span class='is-booked'></span>` |
| `partially-booked-start` / `-end` | `partially-booked` | `is-partially-booked-start` / `-end` |
| `locked` | `locked` | `<span class='is-locked'></span>` |
| `available` | `available` | `<span></span>` |

Tage außerhalb der Kalenderdaten behalten auf der Karte den Status `no-timeframe`.

### 16.4 Das Fenster an einem Beispiel

Paula @ Bäckerei (813, 455): täglich ganztägig, 2 Tage Vorlauf (`startday_offset = 2`), heute Fr 06.11. Die Zeile
enthält für alle Tage 513 („frei“).

| Datum | gespeichert | Fensterregel | Status |
|---|---|---|---|
| Do 05.11. | 513 | Vergangenheit → gesperrt | `locked` |
| Fr 06.11. | 513 | vor erstem buchbaren Tag (08.11.) | `locked` |
| Sa 07.11. | 513 | vor erstem buchbaren Tag | `locked` |
| So 08.11. | 513 | – | `available` |
| … | 513 | – | `available` |

Am Samstag 07.11. liest der Reader **dieselbe** Zeile: Jetzt ist der 09.11. der erste buchbare Tag, der 06.11.
Vergangenheit. Es wurde nichts neu berechnet.

Emil @ Bäckerei (812, 455), 30 Tage Vorausbuchung: Tage nach dem 06.12. tragen in der Zeile „frei“, werden aber vom
Fenster (`lastBookableDate`) als gesperrt ausgegeben. In der Tabelle erscheinen sie seit der Korrektur in 28.6 grau
(`is-locked`).

### 16.5 Karte und Tabelle im Detail

**Karte (`MapItemAvailable::create_items_availabilities()`):** Für alle Paare der Karte `Reader::preload()`, dann je
Paar `getDayStatuses()` nur für die **angezeigten** Tage (Kartenvorgabe, z. B. 12) und `markDays()` mit
`STATUS_MAPPING`. Ohne Vorberechnung: `Reader::evaluateLive()`, ebenfalls nur für die angezeigten Tage (vorher 60).

**Artikeltabelle (`View\Calendar::renderTable()`):** bei aktivem Booster alle Paare über einen `Reader` vorladen;
`renderItemLocationRow( …, ?Reader $reader )` liest den Tagesstatus darüber, sonst live. Cache-Schlüssel der Zeile:
`item_location_today_days`.

---

## 17. Slots lesen: `SlotReader` für REST-API und GBFS

### 17.1 Wer fragt Slots ab

Alle Verfügbarkeitsangaben von REST-API und GBFS laufen über `Model\Calendar::getAvailabilitySlots()`. Diese fragt
zuerst `SlotReader::getAvailabilitySlots()`; liefert der `null`, rechnet `getAvailabilitySlotsLive()` (der
unveränderte Originalcode, nur umbenannt).

| Aufrufer | Kalender | Weg mit Booster |
|---|---|---|
| `API\AvailabilityRoute::get_item()` (`/wp-json/commonsbooking/v1/availability/<id>`) | heute bis heute + `api_future_availability_weeks`, ein Artikel, alle Standorte | gespeichert |
| `API\AvailabilityRoute::get_items()` (`/availability`) | wie oben, je Artikel einzeln | gespeichert |
| `API\GBFS\StationStatus` über `Item::isCurrentlyFreeAtLocation( $loc, true )` | gestern bis morgen, ein Paar, `ignoreStartDayOffset` | gespeichert |
| `API\GBFS\VehicleStatus::getAvailableUntil()` | gestern bis Ende der max. Buchungsdauer, `ignoreStartDayOffset` | gespeichert |
| `API\GBFS\VehicleStatus` über `isCurrentlyFreeAtLocation( $loc, true, true )` | gestern bis morgen, `ignoreRestrictions` | **live** |

### 17.2 Ablauf

1. **Vorprüfungen** (Rückfälle, 17.3).
2. **Kalendertage:** `Reader::getCalendarDates()` – vom Start bis **Sonntag** der Endwoche, wie der Live-Kalender.
   Do 06.11. bis Do 20.11. → 06.11. bis So 23.11.
3. `Indexer::flush()`.
4. **Paare** (`getPairs()`): Indexabfrage nach buchbaren Zeitrahmen der Artikel im Bereich, optional nach Standort.
   Ein Zeitrahmen mit `is_multi = 1` → `null` (live).
5. **Zeilen** aller Paare mit einer Abfrage (`Store::loadWithSlots()`). Ungültige oder fehlende Zeilen werden sofort
   berechnet (`Calculator::recompute()`).
6. **Pro Tag:** Versatz (`Store::getDayOffset()`), Slots entpacken, bei `ignoreStartDayOffset = false` Slots vor dem
   ersten buchbaren Tag des Zeitrahmens verwerfen (`Timeframe::getFirstBookableDay()`, einmal je Zeitrahmen und
   Request), Slots aller Paare des Tages nach Start sortieren, `Slots::addUnique()` wie live.

**Beispiel `/availability/813` am Fr 06.11.**, `api_future_availability_weeks = 2`. Paula hat an der Bäckerei (455)
Zeitrahmen 502 (2 Tage Vorlauf) und am Repair-Café (457) Zeitrahmen 504 mit Rollenbeschränkung.

| Schritt | Ergebnis |
|---|---|
| Kalendertage | 06.11. bis So 22.11. |
| Paare | (813, 455), (813, 457) |
| Zeile (813, 457) | `has_role_limit = 1` → Zeile des Zugriffsprofils aus `cb_availability_access` (8.8), z. B. `''` für Gäste |

Für die Paarzeile (813, 455) sieht es so aus:

| Schritt | Ergebnis |
|---|---|
| Zeile (813, 455) | `days_from = 05.11.`, Versatz 06.11. = 1 |
| erster buchbarer Tag 502 | 08.11.; Slots vom 06. und 07.11. entfallen |
| Tag 09.11. | Slot `[0, 86399, Kopf 0]` → Zeitrahmen 502, Standort 455, Artikel 813; Tagesbeginn 1794182400 |
| Ausgabe | `{"start":"2026-11-09T00:00:00+00:00","end":"2026-11-09T23:59:59+00:00","locationId":"455","itemId":"813"}` usw. |

Für GBFS (`ignoreStartDayOffset = true`) entfällt die Vorlauf-Prüfung; dieselbe Zeile beantwortet beide Varianten.

### 17.3 Rückfälle auf den Live-Weg

| Bedingung | Grund | trifft in der Praxis |
|---|---|---|
| Vorberechnung aus | keine Daten | – |
| `ignoreRestrictions = true` | gespeicherte Slots berücksichtigen Einschränkungen immer; eine zweite Variante würde den Speicher verdoppeln | GBFS `vehicle_status`, Prüfung „gerade frei“ |
| kein Artikelfilter | wäre eine Vollabfrage; der Live-Kalender verhält sich ohne Filter anders | nur eigener Code |
| Kalendertage außerhalb des Horizonts | keine Zeile | `api_future_availability_weeks` > 12 |
| Zeitrahmen mit mehreren Artikeln/Standorten im Bereich | nicht vorberechnet | Mehrfach-Zeitrahmen |
| Paar mit Rollenbeschränkung, Grenze von 8 Zugriffsprofilen erreicht oder Zugriffsprofile abgeschaltet | nutzerabhängig | sehr viele Rollenkombinationen |
| berechnete Zeile passt nicht zum Horizont / Tag nicht abgedeckt | Tageswechsel während des Requests | selten |

### 17.4 Abweichungen vom Live-Weg (Fehler in 2.11.3)

`SlotsTest::testKnownLiveDeviations()` hält beide fest; auf dev mit echten Daten nachgewiesen.

1. **Einschränkungen ohne Standortfilter.** `/availability` baut den Kalender ohne Standort. Live findet
   `Restriction::get( [], [$item] )` dann nur Einschränkungen ohne Standort und übersieht alle mit Artikel **und**
   Standort sowie standortweite (Artikel 0). Reparaturtage erscheinen live als frei, gespeichert gesperrt, weil jedes
   Paar mit seinem Standort berechnet wird.
2. **Tage nach dem Enddatum.** Der Kalender läuft bis Sonntag, lädt live aber nur Beiträge bis zum Enddatum. Eine
   Buchung am Freitag nach einem Donnerstag-Enddatum fehlt live; gespeichert ist sie berücksichtigt.

Mit Booster sind die Antworten in beiden Fällen korrekt; ohne Booster bleibt das Verhalten von 2.11.3 (31.3).

---

## 18. Leseszenarien von Anfang bis Ende

### 18.1 Szenario K: Besucherin öffnet die Karte (40 Standorte, 100 Paare, 12 Tage)

| # | Schritt | Abfragen |
|---|---|---|
| 1 | `Model\Map::get_locations()`: Standorte, Artikel je Standort, **alle buchbaren Zeitrahmen aller Paare in einer Abfrage** (`getBookableTimeframesByPair()`), Terms und Vorschaubilder gebündelt | wenige |
| 2 | `MapItemAvailable`: `Reader::preload()` lädt 100 Zeilen aus `cb_availability` (eine Abfrage, ≈ 19 KB) und die buchbaren Zeitrahmen (eine Indexabfrage) | 2 |
| 3 | je Paar: Fenster bestimmen, 12 Codes lesen, Fenster anwenden, Status | 0 |
| 4 | ein Paar wurde vor einer Minute gebucht (`gen ≠ computed_gen`): nur dieses wird berechnet (≈ 13 ms) und gespeichert | einige |

Gemessen (Entwicklungsdaten, Plugin-Cache aus): **0,11 s** statt 1,14 s (nur Index) bzw. 15,4 s (2.11.3, ein Zehntel der
Daten). Erster Aufruf ohne gespeicherte Zeilen: 1,36 s (alle Paare werden berechnet und gespeichert).

### 18.2 Szenario L: Seitenaufruf im selben Request wie eine Änderung

Ein Plugin speichert eine Buchung und rendert danach im selben Request `[cb_items_table]`.

| Schritt | Was passiert |
|---|---|
| Buchung gespeichert | Projektion auf `save_post`, Paar invalidiert, Warteschlange |
| `Reader::preload()` | ruft `Indexer::flush()` (nichts mehr offen), lädt die Zeile: `gen ≠ computed_gen` |
| `getFacts()` | `Calculator::recompute()`: berechnet mit der neuen Buchung, schreibt |
| `shutdown` | Warteschlange: Paar wird noch einmal berechnet (Ergebnis aus `getComputed()` wurde nach `Calculator::reset()` nicht übernommen) – unschädlich |

Ergebnis: Die Tabelle zeigt die Buchung bereits im selben Request („read your writes“).

### 18.3 Szenario M: Tageswechsel um Mitternacht

Die Zeile von Emil wurde Fr 06.11. 10:00 berechnet (`days_from = 05.11.`, bis 06.02.).

| Zeitpunkt | Was gilt |
|---|---|
| Fr 06.11. 22:00 | Cron: Zeile älter als 12 h → neu berechnet; `days_from` bleibt 05.11. (gestern) |
| Sa 07.11. 00:05 | Zeile gültig (2 h alt). Der Reader wendet „heute = 07.11.“ an: 06.11. ist Vergangenheit, Vorausbuchung endet am 07.12. Nichts wird neu berechnet |
| Sa 07.11. 10:00 | nächster Cron-Lauf (12 h): `days_from = 06.11.`, Horizont reicht jetzt bis 07.02. |
| fiele der Cron aus | Die Zeile von Fr 22:00 wird ab Sa 22:00 (24 h) nicht mehr verwendet; der erste Leser berechnet sie neu |

### 18.4 Szenario N: Tabelle über 180 Tage

`[cb_items_table days=180]`: Der Horizont reicht bis heute + 92. `getFacts()` erkennt, dass Tage außerhalb liegen, und
rechnet das Paar komplett live (`evaluateLive()`), so schnell wie mit „nur Index“.

### 18.5 Szenario O: rollenbeschränktes Paar

Paula @ Repair-Café (813, 457) ist nur für „Mitglieder“ buchbar. `Calculator::compute()` erkennt das über
`has_role_limit` im Index und speichert in `cb_availability` `has_role_limit = 1`, `days = NULL`. `recompute()`
berechnet gleich danach die Profile `''` und `cb_member` und legt sie in `cb_availability_access` ab (8.8).

| Besucher | Zugriffsprofil | gelesen aus | sieht |
|---|---|---|---|
| Gast | `''` | Zeile `(457, 813, '')` | gesperrt |
| Abonnent (`subscriber`) | `''` (Rolle nicht in R) | dieselbe Zeile | gesperrt |
| Mitglied (`cb_member`) | `cb_member` | Zeile `(457, 813, 'cb_member')` | frei |
| Admin | `cb_member` (= R) | dieselbe Zeile | frei |

Kommt ein zweiter rollenbeschränkter Zeitrahmen für „Schule“ hinzu, ist R = `cb_member,cb_school`. Die `gen` des
Paares steigt, alle Profile werden ungültig, `''` und `cb_member,cb_school` werden neu berechnet; `cb_member` und
`cb_school` entstehen beim ersten Aufruf eines solchen Nutzers. Der Plugin-Cache trennt ohnehin nach Rollen (22).

### 18.6 Szenario P: Booster gerade eingeschaltet

| Zeit | Zustand | Karte |
|---|---|---|
| t0 | Einstellung gespeichert | – |
| t0 + nächste Anfrage | `Booster::onInit()` → `activate()`: Tabellen, `Backfill::start()`, Zustand `active` | Originalweg (Index noch nicht fertig) |
| Backfill läuft (Cron, 20-s-Stücke) | Hooks schreiben schon | Originalweg |
| Backfill fertig | `index_ready`, `index_rebuilt` → alle Paare invalidiert, `refresh` eingeplant | Index; Paare werden beim Lesen oder per Cron berechnet |
| einige Minuten später | alle Zeilen gültig | 0,11 s |

---

# Teil E – Sicherheit und Konsistenz

## 19. Buchungsprüfung immer über den Originalweg

### 19.1 `Booster::withoutBooster()`

```php
public static function withoutBooster( callable $callback ) {
    ++self::$bypassDepth;                               // canRead() → false, damit Index::isAvailable() → false
    try {
        return Plugin::withoutCache( $callback );       // Plugin-Cache weder lesen noch schreiben
    } finally {
        --self::$bypassDepth;
    }
}
```

| Stelle | Datei |
|---|---|
| Frontend-Buchungsformular | `Plugin::handleBookingForms()` um `Booking::handleFormRequest()` |
| Admin: Buchung speichern/bestätigen | `Wordpress\CustomPostType\Booking` um `$booking->isValid()` |
| Prüfung beim Speichern eines Zeitrahmens | `Wordpress\CustomPostType\Timeframe::validateTimeFrame()` um `$timeframe->isValid()` |

Der Plugin-Cache wird ebenfalls umgangen, weil er Ergebnisse enthalten kann, die aus dem Index stammen. Schreiben in
den Index läuft innerhalb von `withoutBooster()` weiter.

**Getestet:** Die Indexzeile einer Buchung wurde absichtlich gelöscht (Index veraltet, gilt aber als vollständig). Eine
erneute Buchung desselben Tages wurde trotzdem abgelehnt („Es gibt bereits eine Buchung …“), weil die Prüfung die
Originalabfragen nutzt. Die Tiefenprüfung hat die Zeile danach wiederhergestellt.

### 19.2 Sperre gegen Doppelbuchungen (auch ohne Booster)

Bei 167.000 Buchungen dauert die Originalprüfung etwa 3 s. Ein zweiter Klick in dieser Zeit legte eine zweite
unbestätigte Buchung an (beide Anfragen fanden den Slot frei), der dritte endete mit „Found more than one bookings“.
Den Fehler gab es schon in 2.11.3.

- **Server:** `Wordpress\CustomPostType\Booking::withItemLock()` legt um Prüfung und Speichern
  `GET_LOCK('cb_booking_{blog}_{item}', 20)`. Anfragen für denselben Artikel laufen nacheinander; nach 20 s
  `BookingDeniedException` („Other booking requests … being processed“). Ohne Lock-Unterstützung der DB läuft es wie
  bisher.
- **Browser:** Inline-Skript in `includes/Public.php` sperrt `#booking-form` und `[id^=cb-booking-form-set-]` nach dem
  ersten Absenden („Bitte warten …“), außer für den Kalender-Download, und gibt die Formulare bei Rückkehr aus dem
  Back-Forward-Cache wieder frei.

---

## 20. Wächter, Verifier und Altersgrenze

### 20.1 Wächter (`Service\Booster\Guard`)

Szenario: Downgrade auf eine Version ohne Booster, dort werden Buchungen angelegt oder storniert, danach wieder Upgrade.
Die alte Version hinterlässt keine Markierung; der Wächter erkennt die Folgen in den Daten.

| Prüfung | Wann | Erkennt | Folge |
|---|---|---|---|
| **Plugin-Version** | jede Anfrage (`Booster::onInit()`, `init` Prio 0; zwei autoload-Optionen) | `commonsbooking_plugin_version` wurde von anderem Code geändert. Eigene Schreibvorgänge merkt sich der Booster über `add_option_…`/`update_option_…` | Neuaufbau |
| **Neue Beiträge ohne Indexzeile** | jede Anfrage, nur IDs oberhalb `watermark` (Primärschlüssel, billig), erst nach 2 min (`GRACE_SECONDS = 120`) | Beiträge, die eine andere Version angelegt hat | Neuaufbau |
| **Status und Änderungsdatum** | stündlich (Cron) und `wp commonsbooking booster check --deep` | Join `wp_posts` ↔ `cb_index`: fehlende Zeile, abweichender Status, abweichendes `post_modified_gmt`, Zeilen ohne Beitrag | einzeln reparieren; ab 200 (`REPAIR_LIMIT`) Neuaufbau |

Die Wartezeit von 2 Minuten verhindert Fehlalarme: Eine parallele Anfrage kann gerade einen Beitrag angelegt haben,
dessen `save_post` noch nicht durchgelaufen ist. Die `watermark` wandert nur bis vor den ältesten noch zu jungen
Beitrag.

**Beispiel Versionsprüfung:** Der Branch trägt `2.11.4-beta.3`. Wird 2.11.3 eingespielt, setzt deren Upgrade-Routine
`commonsbooking_plugin_version = 2.11.3`. Beim Zurückwechseln sieht der Wächter `2.11.3 ≠ 2.11.4-beta.3` (zuletzt
selbst geschrieben) und baut neu auf. Hätte der Branch dieselbe Nummer wie die Vorgängerversion, griffe diese Prüfung
nicht – deshalb die eigene Versionsnummer. Die beiden anderen Prüfungen decken den Fall trotzdem ab.

### 20.2 Was die Prüfungen nicht sehen, und wer es dann findet

| Änderung an den Hooks vorbei | gefunden von | Wirkung bis dahin |
|---|---|---|
| neuer Beitrag einer anderen Version | Wächter, nächste Anfrage nach 2 min | Anzeige ohne diesen Beitrag |
| Stornierung einer anderen Version (direktes SQL, ohne Änderungsdatum) | Wächter stündlich (Status) | Tag erscheint noch als belegt; buchen lässt er sich (Originalprüfung) |
| Bearbeitung mit gespeichertem Beitrag | Wächter stündlich (Änderungsdatum) | alte Anzeige |
| reine Meta-Änderung ohne Speichern | Index-Verifier täglich (Stichprobe) | alte Anzeige |
| beliebige Änderung, Verfügbarkeitszeile betroffen | Zeile verfällt spätestens nach 24 h; Verifier täglich 50 Paare | alte Anzeige höchstens 24 h |

In keinem Fall entsteht eine Doppelbuchung, weil die Buchungsprüfung nie über den Booster läuft.

### 20.3 Sicherheitsnetz im Überblick

| Ebene | Mechanismus | schützt vor |
|---|---|---|
| Schreiben | Hooks + `flush()` vor jedem Lesen + `shutdown` | vergessener Projektion im Request |
| Schreiben | `try/catch` in `flush()` | Abbruch des Requests durch Index-Fehler |
| Verfügbarkeit | Generation `gen`/`computed_gen` | Ausliefern veralteter Tage |
| Verfügbarkeit | `WHERE gen = G` beim Schreiben | Rennen zwischen parallelen Anfragen |
| Verfügbarkeit | Altersgrenze 24 h, Neuberechnung ab 12 h | Änderungen an den Hooks vorbei; Horizont wandert |
| Betrieb | Wächter, Index-Verifier, Verfügbarkeits-Verifier | Downgrade, direktes SQL, Programmfehler |
| Buchen | `withoutBooster()`, `withItemLock()`, Formular-Skript | Doppelbuchungen |
| Entwicklung | Paritäts-, Snapshot- und Variantentests in vier Modi | Abweichungen zwischen Index- und Originalweg |

---

## 21. Downgrade und Upgrade

### 21.1 Empfohlener Ablauf

1. Booster ausschalten (Einstellungen oder `wp commonsbooking booster disable`).
2. Ältere Version einspielen. Sie ignoriert die Tabellen `cb_index`, `cb_index_rel`, `cb_availability`,
   `cb_availability_access` (Versionen vor den Zugriffsprofilen kennen sie nicht, lassen sie aber unberührt), die Optionen
   und die Cron-Ereignisse (ohne zugehörigen Code führt WordPress sie nicht aus).
3. Später neue Version einspielen und Booster wieder einschalten. Der Index wird **immer** komplett neu aufgebaut.

Wird Schritt 1 vergessen, fängt der Wächter das beim Wiedereinspielen ab. Vollständig entfernen:
`wp commonsbooking booster purge`.

### 21.2 Getestete Szenarien (`dev-bench/downgrade-scenario.sh`)

Die offizielle Version 2.11.3 liegt im Container unter `wp-content/plugins/commonsbooking-upstream`. Das Skript tauscht
das aktive Plugin über die Option `active_plugins`, also ohne Aktivierungs-Hooks, wie bei einem Austausch der Dateien.

| Durchlauf | mit 2.11.3 | nach dem Zurückwechseln |
|---|---|---|
| `both` | Buchung 501 storniert, Buchung 660 angelegt | erste Seite: Wächter meldet „post 660 was created without the booster“, Neuaufbau; 17.11. frei, 26.11. gebucht; 60 Tage vorberechnet = live; Index und Verfügbarkeit ohne Abweichung |
| `cancel` | Buchung 502 storniert | schnelle Prüfungen ohne Befund (kein neuer Beitrag); Tiefenprüfung findet und repariert 502; 24.11. frei, vorberechnet = live |

Zusätzlich über das Buchungsformular: der 26.11. wurde abgelehnt, der 24.11. ließ sich buchen.

---

## 22. Zusammenspiel mit dem Plugin-Cache

CommonsBooking puffert Repository-Abfragen, Kalenderdaten und Listen im Symfony-Cache (`Plugin::getCacheItem()`).

- **Neu `Plugin::withoutCache( callable )`:** liest und schreibt währenddessen keinen Cache. Genutzt von
  `Calculator::compute()` und `withoutBooster()`.
- **Rollenkontext im Cache-Schlüssel (auch ohne Booster):** `Cache::getCacheId()` enthält `getRoleContext()` (Gast bzw.
  sortierte Rollen plus Admin-Kennzeichen). Vorher konnte ein Gast die zwischengespeicherte Anzeige eines Admins
  bekommen und umgekehrt – ein Fehler, den es schon vor dem Booster gab und der bei rollenbeschränkten Zeitrahmen
  sichtbar wird. Nutzer mit gleichen Rollen teilen sich weiter einen Eintrag.

Der Cache bleibt mit Booster eingeschaltet. Gemessen (Entwicklung, Sekunden):

| Anfrage | Booster, ohne Cache | Booster, Cache leer / warm | ohne Booster, ohne Cache |
|---|---:|---:|---:|
| Kartendaten (Ajax) | 0,11 | 0,26 / 0,08 | Abbruch (Speicher) |
| `[cb_map]` + `[cb_items_table]` | 0,10 | 0,15 / 0,06 | Abbruch (Speicher) |
| `[cb_items]` | 0,13 | 0,14 / 0,11 | 33,6 |
| `[cb_locations]` | 0,12 | 0,10 / 0,12 | 33,3 |
| Artikelseite mit Kalender | 0,06 | 0,07 / 0,04 | 2,3 |

Mit Booster bringt der Cache nur noch Hundertstelsekunden; für den Buchungskalender der Artikelseite und die
Live-Rückfälle der API bleibt er nützlich. Ohne Booster ist er weiterhin nötig.

---

# Teil F – Betrieb

## 23. Schalter und Lebenszyklus

### 23.1 Zustände

| Zustand | Bedingung | Verhalten |
|---|---|---|
| **aus** (Standard) | Einstellung nicht `on` | `Booster::init()` registriert nur `onInit` (erkennt den Wechsel), Einstellungsseite und WP-CLI. Keine Index-Hooks, kein Cron, kein Lesen |
| **im Aufbau** | an, Backfill läuft | Hooks schreiben, gelesen wird über die Originalabfragen |
| **aktiv** | an, Backfill fertig | Repositories lesen aus dem Index, Karte/Tabelle/API aus `cb_availability` |
| **umgangen** | innerhalb `withoutBooster()` | Originalabfragen ohne Cache; Index wird weiter geschrieben |

### 23.2 Übergänge

| Ereignis | Methode | Wirkung |
|---|---|---|
| Einschalten (Einstellung, `booster enable [--now]`) | `onInit()` → `activate()` | Tabellen anlegen, `Backfill::start()` (**immer** komplett neu, weil niemand den Index gepflegt hat, solange der Booster aus war), Zustand mit `plugin_version` und `watermark` |
| jede Anfrage bei aktivem Booster | `onInit()` → `Guard::check()` | Versions- und Wasserstandsprüfung |
| Ausschalten | `onInit()` → `deactivate()` | `Backfill::reset()` (Index gilt als veraltet), Cron-Jobs entfernen, Zustand inaktiv; Tabellen bleiben |
| Plugin-Aktivierung | `Plugin::activation()` → `onPluginActivation()` | Zustand inaktiv; bei eingeschaltetem Booster `activate()` |
| Plugin-Deaktivierung | `Plugin::deactivation()` → `onPluginDeactivation()` | wie Ausschalten |
| Entfernen | `booster purge` → `purge()` | Ausschalten + Tabellen, alle Booster-Optionen und die Einstellung löschen |

## 24. WP-CLI, Diagnose, Filter

### 24.1 WP-CLI

```bash
wp commonsbooking booster status|enable [--now]|disable|rebuild|check [--deep] [--dry-run]|purge
wp commonsbooking index status|rebuild|verify [--repair] [--sample=<n>]|drop             # nur bei eingeschaltetem Booster
wp commonsbooking availability status|rebuild|verify [--repair] [--sample=<n>]|drop      # nur bei eingeschaltetem Booster
```

`availability status` zeigt die rollenbeschränkten Paare und die Zugriffsprofile („Access profiles: 36 (36 usable) for
18 pairs, at most 8 per pair“). `verify` prüft Paare und Zugriffsprofile; `--sample` gilt für beide getrennt.

### 24.2 Diagnose in den Einstellungen

Einstellungen → Erweitert → „Performance booster“ (`SettingsView`, Daten aus `Diagnostics`):

- **Zustand** grün/gelb/rot mit konkreten Hinweisen: Index im Aufbau, Anzahl Beiträge ≠ Indexzeilen, Buchung mit
  abweichendem Status, alte Zeilen, fehlende oder überfällige Cron-Aufgaben, Reparaturen der letzten Tiefenprüfung.
- **Index:** Balken je Typ (Zeitrahmen, Buchungen, Einschränkungen), neueste Änderung in WordPress und im Index.
- **Vorberechnete Verfügbarkeit:** aktuelle, geänderte, alte und rollenbeschränkte Paare (`Store::getStats()`) sowie
  gespeicherte und gültige Zugriffsprofile (`AccessStore::getStats()`).
- **Letzte Buchungen:** zuletzt angelegt, geändert oder storniert, Status in WordPress und im Index nebeneinander.
- **Letzte Index-Aktivität:** Ringpuffer der letzten 30 Änderungen, auch Löschungen, mit Herkunft (Website,
  Verwaltung, Cron, WP-CLI) und erneuter Prüfung des aktuellen Stands.
- **Prüfungen und geplante Aufgaben:** letzter Lauf, Ergebnis, nächster Lauf.
- Knöpfe **Jetzt alle Prüfungen ausführen** und **Index neu aufbauen** (`admin_post_…`).

Zeiten kommen aus GMT-Spalten bzw. Unix-Zeitstempeln und werden mit `wp_date()` in der WordPress-Zeitzone angezeigt.
Ausnahme: CommonsBooking speichert `cancellation_time` als lokale Zeit (`current_time('timestamp')`); das wird
umgerechnet. Texte der Einstellungsseite sind übersetzbar (WP-CLI-Ausgaben bleiben englisch).

## 25. Messwerte

Entwicklungsdaten: 167.000 Beiträge (zehnfache Historie), 1,84 Mio. `postmeta`, Plugin-Cache aus.

| Ansicht / Vorgang | 2.11.3 | nur Index | Booster | Bemerkung |
|---|---|---|---|---|
| Karte mit Verfügbarkeit (100 Paare) | 15,4 s (1×-Daten) | 1,14 s | **0,11 s** | erster Aufruf ohne Zeilen 1,36 s |
| `[cb_items_table]` | 7,05 s (1×-Daten) | 0,74 s | **0,08 s** | erster Aufruf 1,29 s |
| Karte nur Standorte | 49,96 s | 0,09 s | 0,09 s | |
| `[cb_items]` | 25,41 s | 0,09 s | 0,09 s | |
| REST `/availability` (126 Artikel, 2 Wochen) | – | 1.067 ms | **605 ms** | „nur Index“ = Vorberechnung aus |
| REST `/items` | – | 969 ms | **503 ms** | |
| GBFS `station_status` | – | 194 ms | **138 ms** | |
| GBFS `vehicle_status` | – | 304 ms | **281 ms** | „gerade frei“ bleibt live |
| GBFS `vehicle_availability.json` (HTTP, 128 MB) | HTTP 500 | – | **0,65 s** | |
| `getClosestBooking()` für 126 Artikel | 12,0 s | – | **0,9 s** | |
| Einschalten mit `--now` | – | Index 23,9 s | + 1,3 s für 100 Paare | |
| Neuberechnung eines Paares nach einer Buchung | – | – | 13 ms nach der Antwort | |
| Buchungsprüfung (immer Originalweg) | ≈ 3 s | ≈ 3 s | ≈ 3 s | bewusst unverändert |

PHPBench (Fixture des Projekts, 100 Artikel × 110 Buchungen):

| Benchmark | ohne Index | mit Index | Index + vorberechnet |
|---|---|---|---|
| Buchungsablauf | 347 ms | 14,6 ms | – |
| Karte | 2,98 s | 0,11 s | – |
| Karte mit Verfügbarkeit | 9,85 s | 1,15 s | 0,15 s |
| `[cb_items_table]` | 8,78 s | 1,13 s | 0,085 s |
| GBFS Vehicle Availability / Status / Station Status | 22,5 / 20,8 / 7,36 s | 1,81 / 1,02 / 0,58 s | – |
| gesamter Lauf | 16:43 min | 2:52 min | – |

Rollenbeschränkte Paare (DEV, 102 Paare, Kartenabfrage als Admin, Median ohne Plugin-Cache):

| Datenstand | 2.11.3 | live je Paar | je Zugriffsprofil |
|---|---|---|---|
| 18 Paare beschränkt | – | 150 ms | **62 ms** |
| alle 102 Paare beschränkt | ≈ 1.900 ms | 936 ms | **62 ms** |

Ein beschränktes Paar kostet aus dem Zugriffsprofil 0,5–1,0 ms statt 1,6–3,7 ms (Messung
`dev-bench/rollentest/perf-roles.php`, `perf-roles-live.php`).

Testumgebung (15 Paare, Browser): Kartenabfrage 39 ms vorberechnet, 153 ms live, 264 ms ohne Index; „Klassische
Ansichten“ ausgeschaltet 213 ms mit 1.190 Abfragen, eingeschaltet 116 ms mit 48. Ausgabe in allen Modi byte-gleich.

## 26. Tests und CI

| Test | Zweck |
|---|---|
| `Service/Index/IndexerTest.php` | Projektion je Hook: Speichern, Meta, Status, `cancel()`, Papierkorb, Löschen, Aufräum-Cron, Kategorie-/„alle“-Feiertage, leeres `repetition-end` |
| `Service/Index/TimeframeIndexParityTest.php` | über 1.400 Vergleiche Index ↔ `postmeta` für Zeitrahmen, Buchungen, Einschränkungen |
| `View/CalendarSnapshotTest.php` + `snapshots/calendar-core.json` (1,3 MB) | Kalender-JSON, Kartenstatus, Tabellenzeilen für 11 Szenarien × 4 Nutzer, **vor** dem Umbau aufgezeichnet; gespeichert, live und frisch berechnet identisch |
| `Service/Availability/AvailabilityTest.php` | Schreibweg: Buchen, Bestätigen, Stornieren, Löschen, Umbuchen, Feiertag, Platzhalter, Horizont, Gleichzeitigkeit, Rollen, Alter, Rechenversion, Überlauf, Neuaufbau |
| `Service/Availability/SlotsTest.php` | API/GBFS-Slots gespeichert vs. live, bekannte Abweichungen, Rückfälle, Verifier |
| `Service/Availability/AccessProfileTest.php` (14 Tests) | Zugriffsprofil je Rolle und Admin, gespeichert = live für Karte und Slots, sofort berechnete Profile, Grenze je Paar, Invalidierung, Aufräumen, Verifier, Schalter |
| `Service/Booster/BoosterTest.php` (13 Tests) | Schalter, Umgehung bei der Buchungsprüfung (auch mit fehlender Indexzeile), alle drei Wächter-Prüfungen, Wartezeit, `purge` |
| `Service/Booster/DiagnosticsTest.php`, `CacheRoleContextTest.php` | Diagnose, Rollen im Cache-Schlüssel |
| `Service/Booster/TimeframeVariantsParityTest.php` + `Fixtures/TimeframeVariants.php` | 31 Zeitrahmen-Konfigurationen, Vergleich mit dem Originalcode für Gast, Abonnent, Redakteur, zwei cb_manager, Admin |
| `Wordpress/CustomPostType/BookingItemLockTest.php` | Artikelsperre |
| `Repository/BookingTest.php::testGetForUserMatchesFilteringAllBookings` | „Meine Buchungen“ für sieben Nutzerarten, alt = neu |
| `Wordpress/CustomPostType/BookingTest.php::testHandleBookingRequest_onlyOneUnconfirmedBooking_managedItem` | Regel „eine unbestätigte Buchung“ |
| `Model/ItemTest.php::testGetClosestBooking_indexMatchesLegacy` | `getClosestBooking()` Index vs. Original in sieben Schritten |
| `View/CalendarTest.php::testRenderItemLocationRowLocksDaysWithoutStatus` | `is-locked`-Markup |

Die Suite läuft standardmäßig mit eingeschaltetem Booster (`tests/php/bootstrap.php` baut den Index auf). Stand:
586 Tests im Standardmodus (07.11.2026, mit Zugriffsprofilen), zuvor 555 Tests in jedem der vier Modi; ohne Index
werden Index- und Verfügbarkeitstests übersprungen.

| Variable | Wirkung | CI-Lauf (`.github/workflows/phpunit.yml`) |
|---|---|---|
| – | Booster an | Standard-Matrix |
| `CB_USE_BOOSTER=0` | Verhalten nach dem Update | PHP 8.1, WP latest |
| `CB_USE_INDEX=0` | Originalabfragen | PHP 8.1, WP latest |
| `CB_USE_AVAILABILITY=0` | jeder Tag live | PHP 8.1, WP latest |

Lokal: `dev-bench/phpunit/run-multi.sh vendor/bin/phpunit` bzw. Skill `/check`.

---

# Teil G – Änderungen am bestehenden Code

## 27. Überblick

Vergleich `master` (2.11.3, `7aa75a81`) mit `booster/index-availability`. Neue Dateien (`src/Service/Index/`,
`src/Service/Availability/`, `src/Service/Booster/`, `src/Repository/Index.php`, Tests, `design/`) sind in Teil A–F
beschrieben.

**Leitlinie:** Jede umgestellte Abfrage prüft zuerst `Index::isAvailable()` bzw. `Availability::isEnabled()`. Ist der
Booster aus, im Aufbau oder umgangen, läuft der unveränderte Originalcode. `wp_posts`/`wp_postmeta` werden nie anders
geschrieben als vorher.

| Bereich | Dateien | Was sich ändert | auch ohne Booster? |
|---|---|---|---|
| Verdrahtung | `src/Plugin.php`, `includes/OptionsArray.php`, `commonsbooking.php` | Booster starten/stoppen, Einstellung, Version | Einstellung und Version ja |
| Datenzugriff | `src/Repository/Timeframe.php`, `Booking.php`, `Restriction.php`, `BookablePost.php` | Indexpfad neben Originalpfad | nein, **außer** `getForUser()` und gebündeltes Laden in `BookablePost` |
| Modelle | `src/Model/Calendar.php`, `Week.php`, `Day.php`, `Item.php`, `Booking.php`, `Map.php` | Einschränkungen je Kalender, spätes Laden, Slots aus Vorberechnung, `getClosestBooking()`, `cancel()`, gebündelte Karte | teilweise (siehe 28) |
| Anzeige | `src/View/Calendar.php`, `src/Map/MapItemAvailable.php` | Rechenkern ausgelagert, Lesen über `Reader`, Korrekturen | Refactoring und Korrekturen ja |
| Buchen | `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `src/Service/BookingRuleApplied.php`, `includes/Public.php` | Prüfung ohne Booster, Artikelsperre, Doppelklick-Schutz, Regelprüfung | Sperre, Doppelklick, Regel ja |
| Cache | `src/Service/Cache.php` | Rollen im Schlüssel, `withoutCache()` | ja |
| Darstellung | `templates/location-calendar-header.php`, `assets/public/sass/partials/_lists.scss` | Abholhinweise ohne Leerraum | ja |
| Tests, CI, Doku | `tests/…`, `.github/workflows/phpunit.yml`, `phpstan*.neon`, `TECHNICAL.md` | Booster in Tests an, drei zusätzliche CI-Läufe | – |
| Übersetzungen | `languages/*` | neue Texte, Deutsch | – |

## 28. Datei für Datei: was vorher, was jetzt

### 28.1 `src/Plugin.php`

| Stelle | 2.11.3 | jetzt |
|---|---|---|
| `init()` | – | `Booster::init()`: registriert Hooks, Cron, CLI nur bei eingeschaltetem Booster |
| `activation()` | – | zusätzlich `Booster::onPluginActivation()` |
| `deactivation()` | – | zusätzlich `Booster::onPluginDeactivation()` |
| `handleBookingForms()` | `Booking::handleFormRequest()` direkt | in `Booster::withoutBooster()` |

### 28.2 `includes/OptionsArray.php`, `commonsbooking.php`

- Neue Gruppe „Performance booster“ unter *Erweiterte Optionen*: Checkbox `booster_enabled` (Standard aus),
  Statusbereich über `SettingsView::render`.
- Version `2.11.4-beta.3` (Plugin-Header und `COMMONSBOOKING_VERSION`). Sie ist Teil jedes Cache-Schlüssels (alte
  Einträge verfallen) und nötig, damit die Versionsprüfung des Wächters einen Wechsel zwischen den Versionen erkennt.

### 28.3 `src/Repository/Timeframe.php`

| Methode | 2.11.3 | jetzt |
|---|---|---|
| `get()` | `getPostIdsByType()` → `getPostsByBaseParams( $date, $minTimestamp, null, … )` inline | `queryPosts( $types, $items, $locations, $date, $minTimestamp, null, $postStatus )` |
| `getInRange()` | ebenso mit `$maxTimestamp` | `queryPosts( …, null, $minTimestamp, $maxTimestamp, … )` |
| `queryPosts()` | – | **neu, privat**: ohne Index der alte Zweiweg (`getPostIdsByType` + `getPostsByBaseParams`), mit Index Kriterien gemäß 15.2 + `Index::loadPosts()` |
| `getByAuthor()` | – | **neu, öffentlich**: Zeitrahmen/Buchungen eines Autors (für `getForUser()`); Index oder SQL auf `post_author` |
| `getPostIdsByType()`, `getPostsByBaseParams()`, `getEntityQuery()`, `getFilterByDateQuery()`, `getFilterFromDateQuery()`, `getTimerangeQuery()` | zentrale Abfragebausteine | **unverändert**, nur noch im Originalpfad aufgerufen |
| `filterTimeframes()`, `filterTimeframesByConfiguredDays()`, `filterTimeframesByMaxBookingDays()`, `filterTimeframesForCurrentUser()` | PHP-Filter nach dem Laden | unverändert, laufen in beiden Pfaden |

### 28.4 `src/Repository/Booking.php`

| Methode | 2.11.3 | jetzt |
|---|---|---|
| `getByDate()`, `getByTimerange()`, `getBeginningBookingsByDate()`, `getEndingBookingsByDate()` | `WP_Query` mit Meta-Abfragen | zuerst `findViaIndex( $criteria, $customArgs, $postStatus )`; `null` → `WP_Query` wie bisher |
| `findViaIndex()` | – | **neu, privat**: unterstützt `author`, `post_status`, `orderby=date`, `order`; jedes andere Argument → `null`; Sortierung über `Index::sortByDate()` |
| `getForUser()` | alle Buchungen laden, `isUserAllowedToSee()` | Kandidaten über `getVisibleCandidates()`, danach derselbe Filter (15.5) – **auch ohne Booster** |
| `getVisibleCandidates()`, `getManagedPostIds()` | – | **neu, privat** |
| `getLatestPastConfirmed()` | – | **neu, öffentlich** (15.6); `null` ohne Index |
| `getForUserPaginated()` | `WP_Query` | unverändert |

### 28.5 `src/Repository/Restriction.php`, `src/Repository/BookablePost.php`

| Methode | 2.11.3 | jetzt |
|---|---|---|
| `Restriction::get()` | `queryPosts()` per SQL über `postmeta` | mit Index `queryPostsFromIndex()` (**neu**, `restriction_state = active`); PHP-Filter `filterPosts()` unverändert |
| `BookablePost::getRelatedPosts()` | `WP_Query` mit Meta `{typ}-id`, verwandte Beiträge einzeln geladen | `getTimeframesByRelation()` (**neu**): Kandidaten aus dem Index, Filter auf den einzelnen `*-id`-Wert, Sortierung `post_date DESC`; verwandte Beiträge gebündelt (`_prime_post_caches`) – Bündeln auch ohne Index |

### 28.6 `src/View/Calendar.php` (größte Änderung, rund 450 Zeilen)

Die Tageslogik existierte vorher doppelt (`processSlot()`/`getLockedDayArray()` hier, `markDaysInTimeframe()` in der
Karte). Sie liegt jetzt im Rechenkern unter `Service\Availability`, den Live-Weg und Vorberechnung gemeinsam nutzen.

| Element | 2.11.3 | jetzt |
|---|---|---|
| `processSlot( $slot, &$dayArray, &$jsonResponse, &$allLocked, &$noSlots )` (protected) | verdichtete jeden Slot in das Tages-Array | **entfernt** → `DayFacts::fromGrid()` |
| `getLockedDayArray( Day $day )` (protected) | Tages-Array eines gesperrten Tages | **entfernt** → `CalendarWindow::getLockedDayArray()` |
| `getDefaultCalendarEnddateTimestamp( $startDate )` (private) | Standardende des Kalenders | **entfernt** → `CalendarWindow` |
| `mapDay( $day, $lastBookableDate, $endDate, &$jsonResponse, $firstBookableDay )` (protected) | wendete das Fenster an und baute das JSON | **neue Signatur** `mapDay( Day $day, CalendarWindow $window, array &$jsonResponse )`; Logik in `CalendarWindow::mapDay()` |
| `getCalendarDataArray()` | Fensterlogik inline | Signatur gleich, nutzt `CalendarWindow::forTimeframes()` |
| `prepareJsonResponse()` | Standardwerte und Aufbau | öffentliche Signatur gleich |
| `renderJsonResponse( CalendarWindow, $locations, $items )` | – | **neu, privat** |
| `renderTable()` | jede Zeile live | bei aktivem Booster alle Paare über einen `Reader` vorladen |
| `renderItemLocationRow( …, $days_display )` (protected) | Status inline berechnet | zusätzlicher optionaler Parameter `?Reader $reader`; Markup über `TABLE_STATUS_MARKUP` |

Korrekturen (gelten auch ohne Booster):

1. **Markup gesperrter Tage.** Die Auffüllschleife nach dem Statusbereich schrieb `"<span'is-locked'></span>"`. Browser
   lesen das als Element `span'is-locked'` **ohne Klasse**; die Zellen waren leer statt grau, obwohl `.is-locked` in
   `_shortcodeItemsTable.scss` existiert. Jetzt `TABLE_STATUS_MARKUP[ DayStatus::LOCKED ]`. Sichtbar: Bei 31 Tagen und
   14 Tagen Vorausbuchung sind die Tage 15–31 jetzt grau (gehört in den Changelog).
2. **Cache-Schlüssel der Tabellenzeile.** Jetzt `item_location_today_days`. Vorher fehlten Trennzeichen und Tagesanzahl:
   Artikel 1 / Standort 23 und Artikel 12 / Standort 3 kollidierten, eine 31-Tage-Tabelle bekam die Zeile einer
   14-Tage-Tabelle. Ein inneres, nie wiedergefundenes Cache-Item entfällt.

Eigenheiten des Originals wurden bewusst übernommen (Snapshot-Test): `repair` wird von jedem Slot zurückgesetzt,
0 Vorausbuchungstage gelten als „nicht gesetzt“.

### 28.7 `src/Map/MapItemAvailable.php`

| Element | 2.11.3 | jetzt |
|---|---|---|
| `create_items_availabilities()` | je Paar `getCalendarDataArray()` über 60 Tage | bei aktivem Booster `Reader::preload()` + `getDayStatuses()`, sonst `Reader::evaluateLive()`; nur angezeigte Tage |
| `markDaysInTimeframe( $calendarData, $availabilities )` (protected) | eigene Statuslogik | **entfernt** → `markDays( array $dayStatuses, array $availabilities )` mit `STATUS_MAPPING` |

### 28.8 `src/Model/Calendar.php`, `Week.php`, `Day.php`

| Element | 2.11.3 | jetzt |
|---|---|---|
| `Calendar::__construct()` | lud Zeitrahmen sofort, auch bei Cache-Treffer | lädt nichts; `$timeframes` ist `?array` |
| `Calendar::loadPosts()` | – | **neu, protected**: Zeitrahmen und Einschränkungen (einmal für die Range), erst wenn `getWeeks()` rechnen muss |
| `Calendar::getAvailabilitySlots()` | rechnete live | fragt zuerst `SlotReader`, sonst `getAvailabilitySlotsLive()` |
| `Calendar::getAvailabilitySlotsLive()` | – | **neu, öffentlich**: bisherige Logik; Slot-Erzeugung in `Slots` (gemeinsam mit der Vorberechnung) |
| `Week::__construct()`, `Day::__construct()` | `…, array $possibleTimeframes = []` | zusätzlich `?array $possibleRestrictions = null` |
| `Day::getRestrictions()` | Abfrage je Tag | filtert vorgeladene Liste über `isInRestriction()` (**neu**), sonst Abfrage |
| `Day::RESTRICTION_STATUSES` | – | **neu**: `['publish', 'confirmed', 'unconfirmed']` |

### 28.9 `src/Model/Item.php`, `src/Model/Booking.php`, `src/Model/Map.php`

| Element | 2.11.3 | jetzt |
|---|---|---|
| `Item::getClosestBooking()` | alle bestätigten Buchungen des Paares laden, `isPast()` filtern | erst `Booking::getLatestPastConfirmed()`, bei `null` Originalweg; Sortierung unverändert |
| `Booking::cancel()` | direktes SQL, sonst nichts | danach `clean_post_cache()`, `Indexer::touch()`, `Indexer::flush()` |
| `Map::get_locations()` | pro Paar `Timeframe::getBookableForCurrentUser()`, Terms/Bilder einzeln | Artikel je Standort, **eine** Abfrage für alle Paare, Terms und Vorschaubilder gebündelt – auch ohne Booster |
| `Map::getBookableTimeframesByPair( $locationIds, $itemIds )` | – | **neu, öffentlich** |

### 28.10 Buchen: `src/Wordpress/CustomPostType/Booking.php`, `Timeframe.php`, `src/Service/BookingRuleApplied.php`, `includes/Public.php`

| Datei | Änderung | auch ohne Booster |
|---|---|---|
| `CustomPostType/Booking.php` | `withItemLock()` (**neu**) um `handleBookingRequest()`; Admin-Speichern prüft in `withoutBooster()` | Sperre ja |
| `CustomPostType/Timeframe.php` | `validateTimeFrame()` prüft `isValid()` in `withoutBooster()` | – |
| `Service/BookingRuleApplied.php` | „eine unbestätigte Buchung“: `getForUserPaginated( …, ['unconfirmed'] )` statt aller sichtbaren | ja |
| `includes/Public.php` | Inline-Skript gegen doppeltes Absenden | ja |

### 28.11 `src/Service/Cache.php`

| Element | 2.11.3 | jetzt |
|---|---|---|
| `getCacheId()` | ohne Benutzerbezug | mit `getRoleContext()` (**neu**) |
| `withoutCache( callable )` | – | **neu**; `getCacheItem()` liefert währenddessen `false`, `setCacheItem()` schreibt nicht |

### 28.12 Kleinere Korrekturen, Tests, Werkzeuge

- `templates/location-calendar-header.php` + `_lists.scss`: Abholhinweise in eigenem
  `<span class="cb-location-pickupinstructions-text">`, `white-space: pre-wrap` nur dort (vorher erzeugte der Leerraum
  des Template-Markups zusätzliche Leerzeilen).
- `tests/php/bootstrap.php`, `tests/benchmark/bootstrap.php`: Booster an, Index vor dem Lauf aufbauen, Variablen
  `CB_USE_*`. `tests/php/BaseTestCase.php`: statische Zustände von `Store`, `Calculator`, `Invalidator` zurücksetzen.
  `tests/php/Model/CalendarTest.php`: erwartete `Week`-Objekte mit neuem Parameter.
- `phpstan.neon`: `WP_CLI` ignoriert; `phpstan-baseline.neon`: ein Eintrag für `View/Calendar.php` entfällt.
- `TECHNICAL.md`: Abschnitte „Performance booster“, „Timeframe index“, „Precomputed availability“.
- `languages/*`: Texte der Einstellungsseite, „Please wait …“, Meldung der Artikelsperre (de_DE übersetzt; `.pot` per
  `wp i18n make-pot` ohne `dev-bench`, `testenv`, `design`).

### 28.13 Zusatzfunktion „Zugeordnete Zeitrahmen“

Seit `c7215d57` gehört die Funktion „Zugeordnete Zeitrahmen“ zum Booster-Update (Entscheidung des Nutzers vom
07.11.2026): eine Metabox an Artikeln und Standorten, die Zeilenaktion „Zeitrahmen“ und der Filter der
Zeitrahmenliste auch über `item-id-list`/`location-id-list`. Sie ist klein, ändert keine Postmeta-Semantik, bleibt also
downgradebar, und ist für Administratoren großer Instanzen wichtig, um die Zeitrahmen eines Artikels oder Standorts
schnell zu finden. Liegt der Index vor, nutzt sie ihn (`Index::findPostIds()`). Beschrieben ist sie in
[`zugeordnete-zeitrahmen.md`](zugeordnete-zeitrahmen.md). Neu sind `src/Repository/AssignedTimeframes.php` und
`src/View/Admin/AssignedTimeframes.php`, geändert `CustomPostType/CustomPostType.php`, `Item.php`, `Location.php` und
`Timeframe.php` (`filterAdminList()`, `setCustomColumnsData()`).

**Wie Feiertage und Blockierungen wirken.** Ein Zeitrahmen vom Typ „Feiertage oder Station geschlossen“ (3) oder
„Geblockt“ (5) gilt für die Paare *gewählte Artikel × gewählte Standorte*, und davon nur für Paare, die am jeweiligen
Tag einen buchbaren Zeitrahmen haben. `Repository\Timeframe::getPostIdsByType()` verknüpft dazu die Artikel- und die
Standortliste mit je einem `INNER JOIN` (`getEntityQuery()`), und jeder Kalender fragt mit genau einem Paar ab. „Alle“
und „Kategorie“ speichern in `item-id-list`/`location-id-list` eine Momentaufnahme der passenden IDs
(`Timeframe::manageTimeframeMeta()`). Für den Kalender heißt das:

| Auswahl | Wirkung |
|---|---|
| Feiertag: Standort X, Artikel „Alle“ | alle Artikel, die am Tag an X buchbar sind (Station geschlossen) |
| Geblockt: Artikel A, Standorte „Alle“ | A an jedem Standort, an dem A am Tag buchbar ist |
| Alle × Alle | jedes buchbare Paar der Instanz |
| ausdrücklich gewählte Artikel und Standorte ohne gemeinsames buchbares Paar | keine |

„Alle“ auf der Artikelseite bedeutet bei einem Feiertag also praktisch „alle Artikel der gewählten Standorte“, auch
wenn die gespeicherte Liste alle Artikel der Instanz enthält.

**Anzeige in der Metabox (`Repository\AssignedTimeframes`).** Die Kandidatensuche läuft nur über die eigene Seite
(`timeframeCandidateIds()`: Einzelfeld oder Liste enthält den Artikel bzw. Standort). Wegen der Momentaufnahme findet sie
deshalb einen Feiertag mit Artikel „Alle“ bei *jedem* Artikel der Instanz. `buildTimeframeEntry()` prüft danach:

1. *Gegenstücke* (`counterparts()`): die Standorte des Artikels (bzw. Artikel des Standorts) aus veröffentlichten
   buchbaren Zeitrahmen, bevorzugt aus nicht abgelaufenen, sonst aus allen.
2. „Wirkt an“ (`effective_at`): Schnittmenge der Gegenseite des Zeitrahmens mit den Gegenstücken. Bei buchbaren
   Zeitrahmen ist es die Gegenseite selbst.
3. **Ausblenden:** Ist „Wirkt an“ leer, ist die eigene Seite nur implizit gewählt („Alle“ oder Kategorie) und steht die
   Gegenseite nicht auf „Alle“, liefert `buildTimeframeEntry()` `null`. Der Eintrag erscheint dann nicht. Beispiel: Ein
   Feiertag „Bürgerzentrum, alle Artikel“ steht nicht bei einem Artikel, der nur an der Anlegestelle buchbar ist.
   Ebenso ein Eintrag „Geblockt: Artikel A, alle Standorte“ nicht bei einem Standort ohne A.
4. **Warnen:** Ist „Wirkt an“ leer, die eigene Seite aber ausdrücklich gewählt (Einzelfeld oder Liste), bleibt der
   Eintrag mit ⚠ „zugeordnet, aber an keinem Standort dieses Artikels“ stehen. Das ist ein Konfigurationsfehler, den die
   Metabox sichtbar machen soll.

Alle × Alle bleibt sichtbar, auch bei einem Artikel ohne buchbaren Zeitrahmen. Einschränkungen folgen derselben Regel
(`buildRestrictionEntry()`, `0` = alle). Die Regel betrifft nur die Anzeige, nicht Speicherung oder Kalender. Tests:
`testHolidayOfOtherLocationForAllItemsIsHidden`, `testBlockedOtherItemAtAllLocationsIsHidden`.

## 29. Entfernte, umgebaute und neue Methoden

Für Unterklassen und Code Dritter:

| Element | Sichtbarkeit | Änderung | Ersatz |
|---|---|---|---|
| `View\Calendar::processSlot()` | protected | entfernt | `Service\Availability\DayFacts::fromGrid()` |
| `View\Calendar::getLockedDayArray()` | protected | entfernt | `Service\Availability\CalendarWindow::getLockedDayArray()` |
| `View\Calendar::getDefaultCalendarEnddateTimestamp()` | private | entfernt | `CalendarWindow` |
| `View\Calendar::mapDay()` | protected | neue Signatur | `mapDay( Day, CalendarWindow, array & )` |
| `View\Calendar::renderItemLocationRow()` | protected | zusätzlicher optionaler Parameter | `?Reader $reader = null` |
| `Map\MapItemAvailable::markDaysInTimeframe()` | protected | entfernt | `markDays()` + `STATUS_MAPPING` |
| `Model\Day::__construct()`, `Model\Week::__construct()` | public | zusätzlicher optionaler Parameter | `?array $possibleRestrictions = null` |
| `Model\Calendar::$timeframes` | – | jetzt `?array`, befüllt in `loadPosts()` | – |
| `Model\Calendar::getAvailabilitySlots()` | public | Signatur gleich, Weg über `SlotReader` | `getAvailabilitySlotsLive()` für den alten Weg |
| `Repository\Timeframe::get()`, `getInRange()` | public | Signatur gleich, intern `queryPosts()` | – |
| `Repository\Booking::getForUser()` | public | Signatur und Ergebnis gleich, intern Kandidaten | – |

Neu öffentlich in bestehenden Klassen: `Calendar::getAvailabilitySlotsLive()`, `Repository\Timeframe::getByAuthor()`,
`Repository\Booking::getLatestPastConfirmed()`, `Map::getBookableTimeframesByPair()`, `Plugin::withoutCache()`,
`Day::RESTRICTION_STATUSES`.

## 30. Verhaltensänderungen, die auch ohne Booster gelten

| Änderung | sichtbar als |
|---|---|
| Artikelsperre beim Buchen | parallele Buchungen desselben Artikels laufen nacheinander; nach 20 s Meldung |
| Doppelklick-Schutz | Button „Bitte warten …“ |
| Rollen im Cache-Schlüssel | etwas mehr Cache-Einträge; keine fremden Ansichten mehr |
| `getForUser()` ohne Vollscan | „Meine Buchungen“ schneller, Ergebnis gleich |
| Regel „eine unbestätigte Buchung“ nur über eigene Buchungen | Ergebnis gleich, schneller |
| Karte: Zeitrahmen, Terms, Bilder gebündelt | weniger Abfragen |
| Einschränkungen einmal je Kalender | 3.000–6.000 Abfragen weniger |
| Karte rechnet nur angezeigte Tage | weniger Rechenzeit |
| Tabellen-Cache-Schlüssel | keine Kollisionen mehr |
| `is-locked`-Markup | Tage hinter der Vorausbuchungsgrenze jetzt grau |
| `cancel()` mit `clean_post_cache()` | nachfolgende Lesezugriffe sehen sofort „storniert“ |
| Abholhinweise | keine zusätzlichen Leerzeilen |

---

# Teil H – Grenzen und Ausblick

## 31. Bewusste Abweichungen, Grenzen, offene Punkte

### 31.1 Abweichungen vom Originalverhalten (mit Booster)

| Fall | 2.11.3 | Booster |
|---|---|---|
| `repetition-end` vorhanden, aber leer | Beitrag fiel aus der Abfrage heraus | offenes Ende (wie `getTimeframeEndDate()`), per Test festgehalten |
| ungültige ID (`'abc'` → `IN (0)`) | traf Zeitrahmen mit leerem `item-id` (MySQL wandelt `''` in 0) | trifft nichts |
| `/availability` mit Einschränkungen am Paar / Buchungen nach dem Enddatum | als frei ausgegeben (17.4) | korrekt |

### 31.2 Grenzen

- Schreibvorgänge an den Hooks vorbei sieht der Index erst über Wächter oder Verifier (20.2); Verfügbarkeitszeilen
  verfallen spätestens nach 24 h. Buchungen bleiben in jedem Fall korrekt geprüft.
- Anfragen über den Horizont hinaus und rollenbeschränkte Paare jenseits von 8 Zugriffsprofilen werden live berechnet.
- `Timeframe::isBookable()` (Vorausbuchungsfenster) hängt vom heutigen Datum ab und fließt beim Berechnen in die Auswahl
  der Zeitrahmen ein. Rückt ein Zeitrahmen ins Vorausbuchungsfenster, kann eine Zeile bis zur nächsten Neuberechnung
  (ab 12 h, höchstens 24 h) veraltet sein.
- Die Wiederholungs- und Rasterspalten in `cb_index` werden gepflegt, aber noch nicht für Abfragen genutzt.

### 31.3 Offene Punkte

| Punkt | Auswirkung | möglicher Ansatz |
|---|---|---|
| „Meine Buchungen“ für CB-Admins | lädt alle Buchungen ab `startDate`, paginiert in PHP; bei 167.000 Buchungen mehrere Sekunden | Paginierung per SQL über den Index |
| Buchungskalender der Artikelseite | rechnet live, profitiert nur vom Plugin-Cache | Kalender-JSON aus Tagesfakten; braucht Uhrzeiten und Rollen |
| `getClosestBooking()` ohne Booster | lädt weiterhin alle bestätigten Buchungen des Paares | `WP_Query` mit `orderby`/`posts_per_page` (Änderung am Originalweg) |
| `Repository\Item::getByCloakedId()` | berechnet `getCloakedId()` je Artikel (mit Booster ≈ 7 ms je Artikel) | Zuordnung Hash → Artikel zwischenspeichern |
| Fehler im Live-Weg der API (17.4) | ohne Booster falsche freie Tage | an das Hauptprojekt melden |
| `vehicle_status` mit `ignoreRestrictions` | bleibt live | zweite Slot-Variante ohne Einschränkungen |
| Admin-Listen | weiter über `postmeta` | `posts_clauses` auf den Index umstellen |

## 32. Checkliste für Entwickler

| Wenn du … | dann … |
|---|---|
| eine Abfrage auf Zeitrahmen, Buchungen oder Einschränkungen schreibst | `Index::isAvailable()` prüfen, Index- und Originalpfad anbieten, Paritätstest ergänzen |
| Beiträge dieser Typen per direktem SQL änderst | `clean_post_cache( $id )`, `Indexer::touch( $id )`, `Indexer::flush()` |
| einen neuen Meta-Schlüssel einführst, der Zuordnung oder Verfügbarkeit beeinflusst | Schlüssel in `Projector::getRelevantMetaKeys()`, ggf. Spalte + `Schema::SCHEMA_VERSION`, `Projector::PROJECTOR_VERSION` erhöhen (Neuaufbau) |
| die Tageslogik (Slots, Feiertage, Status) änderst | in `DayFacts`/`CalendarWindow`/`DayStatus`, nicht in `View\Calendar`; `Calculator::VERSION` erhöhen; Snapshot bewusst neu aufzeichnen |
| eine Buchung oder einen Zeitrahmen prüfst | in `Booster::withoutBooster()` ausführen |
| neue Klassen unter `src/` anlegst | `composer prefix-namespaces` |
| testest | Suite in allen vier Modi (`CB_USE_BOOSTER=0`, `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`, Standard) |

## 33. Glossar

| Begriff | Bedeutung |
|---|---|
| Paar | Kombination aus Artikel und Standort, z. B. (812, 455) |
| Projektion | Übersetzen eines Beitrags samt Metas in eine Indexzeile |
| Backfill | vollständiger Neuaufbau des Index aus allen Beiträgen |
| Generation (`gen`) | Änderungszähler eines Paares |
| `computed_gen` | Generation, auf deren Stand die gespeicherten Tage berechnet wurden |
| Horizont | vorberechneter Zeitraum: gestern bis heute + 92 Tage |
| Tagesfakten | die zehn Bits eines Tages in `days` |
| Fenster | datumsabhängige Regeln beim Lesen (Vergangenheit, Vorausbuchung, erster buchbarer Tag) |
| Slot | Zeitabschnitt eines Tages im Raster; bei ganztägigen Zeitrahmen einer pro Tag |
| Originalweg / Live-Weg | unveränderter Code aus 2.11.3 |
| `withoutBooster()` | Ausführung mit Originalabfragen und ohne Plugin-Cache |
| Wächter | Prüfungen, die Änderungen ohne Booster erkennen |
| Verifier | tägliche Stichproben gegen frische Projektion bzw. Berechnung |
| Zugriffsprofil | die für ein rollenbeschränktes Paar wirksamen Rollen eines Nutzers (Admins: alle Rollen des Paares); Nutzer mit gleichem Profil sehen dieselben Tage (8.8) |
| Read your writes | Änderungen des laufenden Requests sind beim Lesen im selben Request sichtbar (`Indexer::flush()` vor dem Lesen) |
