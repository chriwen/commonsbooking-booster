# Auswertungsdashboard (Auslastung und Nutzung)

Status: Entwurf (09.11.2026), Branch `feature/dashboard` (Basis `booster/index-availability`). Aggregation (Phase 1, Schritt 1) umgesetzt, Oberfläche folgt.
Nur eigene Tabellen und Optionen, keine Änderung bestehender Postmeta, damit downgradebar.

## Ziel

Die Administratoren einer Instanz sehen auf einen Blick, **wie stark Artikel und Standorte genutzt werden**
und wo Handlungsbedarf besteht (Leerlauf, ausgebuchte Zeiten, späte Stornos, Ausfälle). Daraus entstehen Berichte für
Vorstand, Förderer und Kommunen. Eine Instanz gehört genau einer Initiative; eine Gruppierung mehrerer Initiativen
gibt es nicht. Das Dashboard liest ausschließlich vorberechnete Tageswerte und läuft deshalb auch bei
Instanzen mit 100.000 Buchungen in Millisekunden.

## Kennzahlen

Gruppen A bis F. Phase gibt an, wann die Kennzahl kommt (siehe „Phasen“).

| # | Kennzahl | Gruppe | Phase |
|---|---|---|---|
| 1 | Belegungsquote je Artikel, Standort, Zeitraum | A Auslastung | 1 |
| 2 | Nutzungstage und Buchungen je Artikel, auch je Monat; Summe je Standort | A | 1 |
| 3 | Leerlauf-Quote (buchbar, nie gebucht), Artikel mit dauerhaft niedriger Auslastung | A | 1 |
| 4 | Heatmap nach Wochentag, Monat und Saison | A | 1 |
| 5 | Ausgebuchte Zeiten (Anteil Tage mit 100 % Belegung) | A | 1 |
| 6 | Verfügbarkeit gesamt (Anteil der Zeit ohne Sperre oder Reparatur) | A | 1 |
| 7 | Buchungsdauer: Durchschnitt, Median, Verteilung | B Nachfrage | 1 |
| 8 | Vorlaufzeit (Anlage bis Beginn) | B | 1 |
| 9 | Stornoquote, Zeitpunkt der Stornierung (früh / kurz vor Beginn) | B | 1 |
| 10 | Buchungen je Tag, Woche, Monat mit Vorjahresvergleich | B | 1 |
| 11 | Aktive Nutzer, Neu und Wiederkehrer | C Nutzer | 2 |
| 12 | Buchungen je Nutzer (Verteilung), Anteil der Top 10 | C | 2 |
| 13 | Neuregistrierungen und Erstbuchungsquote | C | 2 |
| 14 | Ausfallzeiten (Reparatur, Einschränkungen), Defekthäufigkeit | D Betrieb | 2 |
| 15 | Buchungscodes: Vorrat in den nächsten Tagen | D | 2 |
| 16 | Konfigurationswarnungen (Artikel ohne Zeitrahmen, auslaufende Zeitrahmen, Konflikte) | D | 2 |
| 17 | Standortvergleich: Buchungen, Auslastung, Storno | E Fläche | 1 |
| 18 | Artikelkategorien im Vergleich | E | 1 |
| 19 | Kartenansicht der Auslastung (Standorte eingefärbt) | E | 3 |
| 20 | Wirkung: Nutzungstage gesamt und CO2-Schätzung mit konfigurierbarer Annahme | F Wirkung | 3 |

Nicht erfassbar, solange das Plugin keine Übergabe erfasst: tatsächliche Abholung und Rückgabe (No-Show-Quote),
Kilometer, Zustand, abgewiesene Anfragen. Das ist kein Teil dieses Entwurfs.

## Definitionen

- **Station** = Standort. Eine Instanz gehört genau einer Initiative; Gruppierungen mehrerer Initiativen oder
  Standortgruppen gibt es nicht (Entscheidung 09.11.2026, ersetzt den früheren Ansatz über Standortkategorien). Die
  Standort- und Artikelkategorien dienen nur als **Filter** (siehe „Oberfläche“).
- **Kapazität** je Artikel, Standort und Tag = Slots der buchbaren Zeitrahmen, abzüglich Reparatur, Feiertags- und
  Sperrzeitrahmen. Gerechnet wird in den Slots des Zeitrahmens. Ganztägige und stundenweise Zeitrahmen werden **getrennt**
  geführt (Entscheidung 09.11.2026): Jede Zeile trägt die Einheit `day` (Ganztag, ein Slot je Tag) oder `hour`
  (Stundenslots). Belegungsquoten werden nur innerhalb einer Einheit gebildet, nie über Einheiten gemischt.
  Einheitenfreie Zählwerte (Buchungen, Stornos, Nutzungstage) dürfen summiert werden. Hat ein Artikel beide Arten,
  erscheint er in Ranglisten mit zwei Zeilen („… (ganztägig)“, „… (stundenweise)“).
- **Belegung** = gebuchte Slots, nur Status `confirmed`. `canceled` zählt nur in Kennzahl 9 (Zählung am Tag des
  Buchungsbeginns und am Tag der Stornierung).
- **Nutzungstag** = Kalendertag mit mindestens einer bestätigten Buchung.
- Tagesgrenzen in der Zeitzone der Website.
- **Einschränkungen** (Restriction) vom Typ Totalausfall zählen als Ausfall (Kennzahl 14), nicht als Belegung.
- **Wirkung** (Kennzahl 20, kommt in Phase 3; Entscheidung 09.11.2026): Schätzung aus den Nutzungstagen. Die Faktoren trägt der
  Administrator in den Dashboard-Einstellungen selbst ein:
  - **Strecke je Nutzungstag** in km. Standardwert: **6 km**.
  - **CO2-Faktor** in g je km des ersetzten Verkehrsmittels. Ohne Standardwert: Ist er leer, zeigt das Dashboard nur die
    ersetzten Kilometer (Nutzungstage × Strecke), keine CO2-Zahl.
  - **Quellenangabe** als Freitext (woher der CO2-Faktor stammt). Sie wird bei jeder Ausgabe der Wirkung mitgedruckt.
  Jede Ausgabe nennt die verwendeten Werte und bezeichnet das Ergebnis als Schätzung.

## Datenmodell (nur neue Tabellen)

Umgesetzt in `src/Service/Stats/` (Stand 09.11.2026). Alle Tabellen sind abgeleitet und jederzeit neu aufbaubar.

1. `wp_cb_stats_daily` (Primärschlüssel `day, item_id, location_id, unit`; `unit` = `day` oder `hour`): `capacity_slots`,
   `booked_slots`, `outage_slots`, `failure_slots`, `bookings_started`, `bookings_canceled`. Grundlage für die Kennzahlen 1 bis 6, 10, 14, 17
   und 18.
   - Nur Tage bis **heute** werden gespeichert (Auswertung der Vergangenheit; Zukunft ändert sich laufend).
   - `unit` `day`: ein Slot je Tag (ganztägiger Zeitrahmen oder ganztägige Buchung). `hour`: alles andere, gezählt in
     **Stunden** (auch feste Zeitfenster ohne Raster zählen mit ihrer Länge in Stunden).
   - Kapazität = buchbare plus gebuchte Slots nach Abzug von Reparatur-Zeitrahmen, Feiertagen und aktiven
     Reparatur-Einschränkungen; `outage_slots` = was eine Sperre an Kapazität nimmt: aktive Reparatur-Einschränkung,
     Feiertags-/Schließ-Zeitrahmen oder Reparatur-Zeitrahmen (Entscheidung 10.11.2026, vorher zählten nur
     Einschränkungen und Urlaube/Schließungen ließen die Verfügbarkeit bei 100 %). Verglichen wird der Tag mit und ohne
     Sperren (nur buchbare Zeitrahmen und Buchungen), deshalb gilt die Einheit der zugrunde liegenden Slots.
   - `failure_slots` (Entscheidung 10.11.2026) = Kapazität, die nur eine aktive Einschränkung vom Typ **Totalausfall**
     nimmt (Tag mit und ohne Einschränkungen verglichen); Feiertage, Schließungen und Reparatur-Zeitrahmen zählen hier
     nicht. **Ausfallquote** = `failure_slots` / (Kapazität + `outage_slots`), also Anteil der geplanten Kapazität.
     Sie ist eine eigene Kennzahl neben der Verfügbarkeit (die alle Sperren enthält) und steht in Kacheln und CSV.
     Schema-Version 2: nach dem Plugin-Update wird die Tabelle erweitert und die Statistik automatisch neu berechnet.
   - `bookings_started` zählt bestätigte, `bookings_canceled` stornierte Buchungen **am Starttag** der Buchung. Der
     Stornozeitpunkt selbst steht in der Buchungstabelle (Kennzahl 9).
2. `wp_cb_stats_booking` (Primärschlüssel `booking_id`): `item_id`, `location_id`, `user_id` (Autor), `unit`, `slots`
   (Tage bei `day`, Stunden bei `hour`), `status`, `created_ts`, `start_ts`, `end_ts`, `canceled_ts` (Meta
   `cancellation_time`, sonst NULL). Zeitstempel wie im Zeitrahmen-Index „Ortszeit als Zeitstempel“. Nur bestätigte und
   stornierte Buchungen. Grundlage für 7 bis 9 und 11 bis 13.
3. `wp_cb_stats_pairs` (Primärschlüssel `item_id, location_id`): Arbeitswarteschlange mit `dirty_from`, `dirty_to`
   (zu berechnende Tage) und `computed_at`. `dirty_from` = `1970-01-01` heißt „ab dem ersten Tag des Paares“.
4. Optionen `commonsbooking_stats_enabled` (Standard aus), `commonsbooking_stats_ready` (Erstaufbau fertig) und
   `commonsbooking_stats_schema_version`. `wp commonsbooking stats purge` entfernt Tabellen und Optionen vollständig.

Das Dashboard nutzt **keine** Buchungs-Posts zur Laufzeit. Die Aggregation ist **unabhängig vom Booster**: die Buchungstabelle
entsteht mengenbasiert per SQL aus Posts und Meta, die Tageswerte aus dem Tagesraster (`Model\Day`) ohne Cache, als
Nutzer mit allen Rollen des Paares (`AccessProfile::runAs`). Ist der Zeitrahmen-Index fertig, findet er Paare und den
ersten Tag (auch Mehrfachauswahl); ohne ihn werden nur Zeitrahmen mit genau einem Artikel und einem Standort gefunden.
Der Verlauf ist auf 1826 Tage begrenzt (Filter `commonsbooking_stats_max_history_days`).

## Aktualisierung

- **Ereignisse:** Hooks (`transition_post_status` für Buchungen, `save_post_cb_timeframe`/`_cb_restriction`,
  `before_delete_post`) sammeln nur Post-IDs. Am Ende der Anfrage (`shutdown`) werden Buchungszeilen neu projiziert
  (Meta wie `cancellation_time` entsteht erst nach dem Statuswechsel) und die betroffenen Paare ab dem Starttag als
  „dirty“ markiert. Höchstens 2 Paare werden sofort berechnet, der Rest per WP-Cron (Einzelereignis +30 s).
- **Storno per SQL (12.11.2026):** `Model\Booking::cancel()` setzt den Status per direktem SQL, ohne
  `transition_post_status` (z. B. beim „Senden“ einer Totalausfall-Einschränkung). Deshalb hört `Stats` zusätzlich
  auf `added_post_meta`/`updated_post_meta` mit dem Schlüssel `cancellation_time` bei Buchungen
  (`Stats::onMetaChange`). Der Booster-Index nutzt denselben Weg. Gefunden im Frontendtest (F2).
- **Änderungen ohne diesen Code (12.11.2026, Entscheidung):** Downgrade, DB-Import oder Code, der die Hooks umgeht.
  Die Statistik hat keinen eigenen Wächter, sondern nutzt den des Boosters (`Booster\Guard`). Der Guard löst
  `commonsbooking_booster_rebuild` aus, wenn er den Index neu aufbaut (Versionswechsel, Beitrag ohne Index, viele
  Änderungen, fehlende Tabellen), und `commonsbooking_booster_repaired` mit den IDs einzeln reparierter Beiträge.
  `Stats::onBoosterRebuild` startet dann `Build::start()` (bis zum Ende nicht `ready`), `onBoosterRepaired` projiziert
  die betroffenen Buchungen neu, auch gelöschte. Ein manueller Index-Neuaufbau (`manual`) löst nichts aus.
  Verworfen: ein zweiter Wächter für die Statistik (doppelte Logik, eigene Fehlerquellen). Grenze: Bei
  ausgeschaltetem Booster gibt es keinen Abgleich, dann hilft `wp commonsbooking stats rebuild`. Gefunden im
  Frontendtest (F3).
- **Nachtlauf (00:20):** neue Paare finden, gestern und heute aller Paare neu berechnen (Rollover; fängt auch Fälle ab,
  die die Hooks nicht erfassen, z. B. Zeitrahmen mit Kategorie- oder Listenauswahl).
- **Erstaufbau:** `wp commonsbooking stats enable` bzw. `rebuild`; Warteschlange in 20-Sekunden-Läufen per WP-Cron.
  Bis `ready` zeigt das Dashboard einen Hinweis statt Teilwerten.
- **Selbsttest:** `wp commonsbooking stats verify [--pairs=n]` berechnet zufällige Paare neu und vergleicht die Summen.
- **Befehle:** `stats status | enable | process [--limit] | rebuild | verify | disable | purge`.

### Messung (09.11.2026, `hostenv/`, 99.680 Buchungen, 719 Paare)

Buchungstabelle 5 s, Tageswerte aller Paare 63 s (rund 0,1 s je Paar), zusammen etwa 70 s; 414.445 Tageszeilen
(343.435 `day`, 71.010 `hour`). Stichprobe: Buchungs-Slots der Buchungstabelle und `booked_slots` stimmen bis auf
0,05 % überein (Buchungen, die über Mitternacht von heute laufen).

### Erstaufbau in Schritten (Shared Hosting)

Entscheidung (09.11.2026): „Statistik einschalten“ rechnet nichts im Request, sondern startet nur den Aufbau
(`Service\Stats\Build`). Der Aufbau arbeitet in kleinen Schritten mit Zeitbudget (höchstens 8 s und höchstens 40 %
von `max_execution_time`) und Speicherwächter (70 % von `memory_limit`):

- Phasen: Buchungen (Id-Bereiche zu 5.000) → Paare finden → Tageswerte je Paar → fertig. Der Zustand liegt in der
  Option `commonsbooking_stats_build`, jeder Schritt setzt dort fort, auch nach Abbruch oder Timeout.
- Wer zuerst kommt, treibt den Aufbau voran: die geöffnete Statistikseite (Fortschrittsbalken, ruft den
  Admin-AJAX-Endpunkt `commonsbooking_stats_step` wiederholt auf, nur Administratoren, Nonce), WP-Cron (Einzelereignis,
  falls die Seite zu ist) oder WP-CLI. Ohne JavaScript macht jedes Neuladen einen Schritt. Eine Sperre
  (Option mit Zeitstempel, nach 120 s verfallen) verhindert parallele Läufe.
- Fortschritt in Prozent: Buchungen 20 %, Paare 5 %, Tageswerte 75 % (nach erledigten Paaren).
- Ein Paar, das einen Fehler wirft, blockiert die Warteschlange nicht; es wird vermerkt
  (`commonsbooking_stats_failures`, letzte 20) und nachts neu versucht.
- Änderungen während des Aufbaus werden nur markiert, die Verarbeitung übernimmt der Aufbau.

Messung: 99.680 Buchungen, 719 Paare: 78 s in Schritten à 30 s (WP-CLI).

### Gesamtauslastung statt strikter Trennung (10.11.2026, umgesetzt)

Anlass: Gefragt ist meist „wie stark war die Station oder das Rad insgesamt ausgelastet“. Die strikte Trennung nach Tages-
und Stundenbuchungen beantwortet das nicht, Artikel mit beiden Arten erscheinen doppelt.

- Slots verschiedener Einheiten lassen sich nicht sauber addieren (ein Tag hat keine feste Stundenzahl, die Öffnungszeit
  ist Sache des Standorts). Eine Umrechnung Tag → Stunden wäre willkürlich.
- Einheitenfrei kombinierbar sind: Buchungen (Summe), Stornoquote (Zählungen) und eine **Tagesquote**: Tage mit mindestens
  einer Buchung geteilt durch Tage mit angebotener Kapazität, je Artikel und Standort über beide Einheiten vereinigt.
  Sie ist aus `cb_stats_daily` ohne Schemaänderung ableitbar. Schwäche: Eine einzelne Stunde macht den Tag zum
  „Nutzungstag“, stundenweise Artikel wirken dadurch stärker ausgelastet.
- Vorschlag: Standardansicht „Gesamt“ mit Buchungen, Tagesquote, Stornoquote, Verfügbarkeit und Ausfallquote
  (Verfügbarkeit und Ausfall tageweise: Tag zählt als verfügbar, wenn Kapazität bleibt). Darunter getrennt die
  Slotauslastung in Tagen und in Stunden mit Buchungszahl je Art. Ranglisten zeigen eine Zeile je Artikel
  (Tagesquote) mit Spalten für Tage- und Stunden-Slots; der Filter „Buchungsart“ behält „nur ganztägig“ und
  „nur stundenweise“.
Umsetzung: Filter „Buchungsart“ hat `all` (Standard, alter Wert `both` wird übernommen), `day`, `hour`. Bei `all` zeigt die Übersicht zuerst „Gesamt“ (Tagesquote, Buchungen, Nutzungstage, Stornoquote, voll gebuchte Tage, Verfügbarkeit, Ausfallquote tageweise mit Anteilen je Tag und Art gemittelt), danach die Aufschlüsselung je Art. Ranglisten haben eine Zeile je Artikel/Standort mit Tagesquote und den Spalten „Auslastung ganztägig/stundenweise“. Der CSV-Export bleibt je Art getrennt.

## Oberfläche

Eigene Seite unter dem CommonsBooking-Menü, ohne zusätzliche Bibliotheken (Diagramme: siehe „Diagramme“).

**Filter** (gelten für alle Ansichten und den Export und stehen in der URL, damit Ansichten verlinkbar sind):

- Zeitraum: Schnellwahl (30 Tage, 12 Monate, laufendes Jahr, Vorjahr) oder frei; Vergleich mit Vorperiode oder Vorjahr.
- Standort und Artikel: einzeln oder mehrere. Dazu Standortkategorie und Artikelkategorie.
- Auflösung: Tag, Woche oder Monat (Standard: Monat).
- Buchungsstatus (bestätigt, storniert), wo die Kennzahl es zulässt.
- Buchungsart: ganztägig, stundenweise oder beide getrennt (Standard). Die Auswahl erscheint nur, wenn die Instanz beide
  Arten hat.

**Ansichten:**

- **Übersicht:** Kennzahlen-Kacheln (Belegung, Buchungen, Nutzungstage, Storno) und Verlauf.
- **Artikel:** Tabelle mit Sortierung nach Auslastung, Leerlauf, Storno; Detailseite mit Heatmap und Verteilungen.
- **Standorte:** Vergleich der Standorte, Karte (Phase 3).
- **Betrieb:** Ausfälle, Codes-Vorrat, Konfigurationswarnungen (Phase 2).
- **Bericht:** druckbare Ansicht des aktuellen Filters (Phase 2).

**CSV-Export** (Phase 1): Jede Ansicht hat die Schaltfläche „Als CSV exportieren“. Exportiert wird genau die aktuell
gefilterte, **aggregierte** Tabelle in der gewählten Auflösung, nie Einzelbuchungen.

- Spalten (Beispiel Artikel und Standort): Zeitraum, Standort, Artikel, Einheit (ganztägig / stundenweise), Kapazität,
  gebuchte Slots, Belegungsquote, Buchungen, Stornos, Nutzungstage. Die Spaltenköpfe sind übersetzt.
- Format (Entscheidung 09.11.2026): UTF-8 mit BOM, Trennzeichen Semikolon, **Dezimalkomma** (`0,73`; die Belegungsquote ist
  ein Anteil, 0,73 = 73 %), ISO-Daten. So öffnet die Datei in deutschem Excel direkt. Das Format ist über einen Filter
  änderbar, falls eine Instanz Dezimalpunkte braucht.
- Zellen, die mit `=`, `+`, `-` oder `@` beginnen, werden entschärft (Schutz vor Formeleinschleusung über Artikelnamen).
- Auslieferung gestreamt; bei Auflösung „Tag“ über viele Artikel und Standorte kann die Zeilenzahl groß werden, deshalb
  Warnung ab einer Schwelle und Monat als Standard.
- Zugriff wie das Dashboard (Administratoren; CB-Manager nur mit ihrem Zuschnitt, siehe „Rechte“; Nonce).

## Umsetzung der Oberfläche (Phase 1)

Stand 09.11.2026. Seite „Statistik“ unter dem CommonsBooking-Menü, nur für Administratoren (Prüfung in Seite und Export,
Nonce beim Export). Klassen: `Service\Stats\Filters` (Filter aus der URL, validiert), `Service\Stats\Query` (liest nur
die Aggregattabellen), `View\Admin\Stats\Page`, `Chart`, `Export`.

- **Einschalten:** Ist die Statistik aus, zeigt die Seite einen Knopf „Statistik einschalten“ (baut Tabellen und Warteschlange
  auf); bis zum Abschluss steht ein Hinweis mit der Zahl offener Paare da.
- **Filter:** Zeitraum (30 Tage, 12 Monate, dieses/letztes Jahr, frei), Vergleich (Vorperiode, Vorjahr), Auflösung,
  Buchungsart, Artikel, Standorte, Artikel- und Standortkategorien. Ein Filter ohne Treffer (leere Kategorie) ergibt eine
  leere Auswahl, nicht „alles“. Zeitraum höchstens 1830 Tage, nie in der Zukunft.
- **Ansichten:** Übersicht (Kacheln je Buchungsart mit Vergleichswert, Verlauf der Auslastung und der Buchungen, Heatmap
  Wochentag × Monat, Dauer, Vorlaufzeit, Stornozeitpunkt), Artikel und Standorte (Rangfolge der ersten 20 als Balken,
  sortierbare Tabelle). Jedes Diagramm hat darunter die Werte als einklappbare Tabelle.
- **Bewusst noch nicht:** Filter „Buchungsstatus“ (bestätigt/storniert): Buchungen und Stornierungen stehen in eigenen
  Kacheln und Spalten nebeneinander, das deckt den Bedarf ohne Umschalter. Detailseite je Artikel folgt mit Phase 2.
- **CSV:** Knopf „Als CSV exportieren“ in jeder Ansicht, exportiert die aggregierte Tabelle der Ansicht mit den aktuellen
  Filtern (Übersicht: Zeitraum × Standort × Artikel; Artikel; Standorte). UTF-8 mit BOM, Semikolon, Dezimalkomma
  (Filter `commonsbooking_stats_csv_decimal`), Formelneutralisierung, gestreamt.
- **Kennzahlen:** Auslastung = gebuchte / Kapazität je Buchungsart; Stornoquote = storniert / (bestätigt + storniert) am
  Starttag; Verfügbarkeit = Kapazität / (Kapazität + Ausfall); ungenutzte Tage = Tage mit Kapazität ohne Buchung;
  ausgebuchte Tage = Tage mit gebuchten ≥ Kapazität.

### Gestaltung (09.11.2026)

Karten mit abgerundeten Ecken und weichem Schatten, Kennzahlen als Kacheln mit Anteilsbalken, Tabs als Segmentschalter,
Diagramme in Karten (Auslastung und Buchungen nebeneinander), Akzentfarben Indigo (`--cb-stats-a`) und Bernstein
(`--cb-stats-b`). Jede wichtige Kennzahl hat ein (i): ein Klick öffnet eine kurze Erläuterung (natives `<details>`,
funktioniert ohne JavaScript und per Tastatur).

Filter für Artikel, Standorte und Kategorien sind Dropdowns mit Suchfeld und Checkboxen (Anzeige „n ausgewählt“, „Auswahl löschen“, Esc/Klick außerhalb schließt); „Filter zurücksetzen“ lädt die Seite ohne Filter. Ohne JavaScript bleibt die Checkbox-Liste benutzbar.

## Diagramme

Entscheidung 09.11.2026: **serverseitig erzeugtes Inline-SVG, ohne JavaScript-Bibliothek.** Das Plugin bringt keine
Diagrammbibliothek mit (Abhängigkeiten: Leaflet, Vue für das Frontend-Paket, Select2, Litepicker).

Gründe:

- **Passt zum Ablauf:** Filter stehen in der URL, die Seite wird per GET neu geladen. Dann genügt es, die Diagramme beim
  Rendern aus den aggregierten Tabellen zu zeichnen; es gibt keine zweite Datenschnittstelle für JavaScript.
- **Druck und Bericht:** SVG druckt scharf und bleibt im druckbaren Bericht (Phase 2) und in einem späteren Mailbericht
  verwendbar. Eine Canvas-Bibliothek müsste dafür extra behandelt werden.
- **Kein Zusatzgewicht:** Chart.js und ähnliche kosten rund 200 KB, dazu Lizenzprüfung, Updates und Einbindung in den
  Grunt-Build. Gebraucht werden nur wenige einfache Typen.
- **Barrierefrei:** Jedes Diagramm hat `<title>`/`<desc>` und direkt darunter dieselben Werte als Tabelle (einklappbar).
  Werte sind beschriftet, nie nur über Farbe codiert.

Umsetzung: eine kleine Klasse `View\Admin\Stats\Chart` mit festen Typen. Die Typen decken alle Kennzahlen ab:

| Typ | Verwendung (Kennzahl) |
|---|---|
| Säulen / Linie über die Zeit, optional Vorperiode als zweite Reihe | Belegung, Buchungen, Storno über Zeit (1, 2, 9, 10) |
| Horizontale Balken, Rangliste | Artikel und Standorte nach Auslastung, Leerlauf, Storno (2, 3, 17, 18) |
| Heatmap (Wochentag × Monat) | Saisonmuster (4, 5) |
| Histogramm | Buchungsdauer, Vorlaufzeit (7, 8) |
| Kachel mit Kleinstverlauf (Sparkline) | Übersicht (1, 2, 9) |

Regeln:

- Farben aus CSS-Variablen, mit Vorgabe aus dem aktiven WordPress-Admin-Farbschema; ein farbenblind-taugliches Paar für
  Vorperiode und Zeitraum. Dunkle Admin-Schemata werden über `currentColor` und Variablen mitgenommen.
- Ranglisten zeigen die obersten 20 Einträge; der Rest steht in der Tabelle und im CSV.
- Hinweise beim Überfahren über `<title>` der SVG-Elemente. Eigenes JavaScript nur falls später gewünscht
  (Fortschrittsverbesserung), nie Voraussetzung.
- Tests: Die Klasse ist reine Funktion von Daten zu SVG-Text und läuft in PHPUnit gegen Referenzausgaben.

## Rechte und Datenschutz

- **Administratoren** der Instanz (`commonsbooking_isCurrentUserAdmin()`) sehen alles und dürfen exportieren. Die Prüfung
  gilt in jedem Endpunkt (Seite, Abfragen, Export, Nonce), nicht nur beim Menüpunkt.
- **CB-Manager** (Rolle `cb_manager`) erhalten eine **lesende** Sicht (Entscheidung 09.11.2026, Phase 2), wenn der Betreiber
  sie in den Dashboard-Einstellungen einschaltet (Standard aus):
  - Sichtbar sind nur Zeilen, deren Artikel oder Standort dem Nutzer als Admin zugeordnet ist (Meta `_cb_item_admins` und
    `_cb_location_admins`, siehe `Repository\BookablePost::getByCurrentUser` für Artikel und Standorte). Das entspricht der
    Sicht auf Buchungen, bei der Artikel- und Standort-Admins zusammengefasst werden.
  - Durchsetzung in der Abfrage (Einschränkung auf die erlaubten Artikel- und Standort-IDs), nicht nur in den
    Auswahllisten der Filter. Summen und Kacheln zählen nur sichtbare Zeilen und heißen „Ihre Artikel und Standorte“.
  - Der Export ist erlaubt, mit demselben Zuschnitt.
  - Nutzerkennzahlen (Gruppe C) und Konfigurationswarnungen bleiben Administratoren vorbehalten.
  - Zwischengespeicherte Ergebnisse sind nach Zuschnitt getrennt, damit kein Manager Werte eines anderen erhält.
- Nutzerkennzahlen (Gruppe C) sind aggregiert; keine Namen oder Einzelverläufe.
- `wp_cb_stats_booking.user_id` wird beim Löschen eines Nutzers auf 0 gesetzt (Anonymisierung), passend zum
  WordPress-Hook für Datenlöschung.
- Export und Berichte enthalten nur Aggregate.

## Phasen

1. **Aggregation, Kern, Filter und CSV-Export:** Tabellen, Backfill, Hooks, Übersicht, Artikel, Standortvergleich
   (Kennzahlen 1 bis 10, 17, 18), Filter nach Zeitraum, Standort, Artikel, Buchungsart und Kategorien, Export der
   gefilterten Tabelle.
2. **Nutzer, Betrieb und Manager-Sicht:** Gruppen C und D, druckbarer Bericht, lesender Zugriff für CB-Manager.
3. **Wirkung und Karte:** Kennzahlen 19 und 20.

## Testdaten

Stand der lokalen Build-Instanz `hostenv/` (Port 8891), gemessen am 09.11.2026:

- 700 Artikel, 500 Standorte, 688 Artikel-Standort-Paare mit Buchungen; 1.012 veröffentlichte Zeitrahmen, 370
  Einschränkungen; Booster-Index gefüllt (rund 98.700 Zeilen).
- **99.680 Buchungen:** 92.607 bestätigt, 7.073 storniert. Beginn von 11/2024 bis 01/2027 (2024: 7.121,
  2025: 46.875, 2026: 45.334, 2027: 350), davon 5.305 in der Zukunft.
- 173 Buchende; 94.667 verschiedene Anlagezeitpunkte, damit ist die Vorlaufzeit (Kennzahl 8) testbar.
- Muster: Laut `hostenv/README.md` sind etwa 11 % der Artikel stundenweise (9 % und 2 %), dazu 5 % mit Vor- und Nachmittag.
  Gemessen wurde der Anteil der stundenweisen Buchungen noch nicht.

Das reicht, um Backfill und Abfragezeiten bei 100.000 Buchungen zu messen.

**Stornozeitpunkte:** Die Build-Instanz enthält `cancellation_time` für alle 7.073 stornierten Buchungen (Kennzahl 9).
Nachgetragen am 09.11.2026 mit `hostenv/patch-cancellation-time.php`; neue Builds erzeugen sie über
`hostenv/seed-scale.php` (meist früh, 15 % unter 24 Stunden vor Beginn). Damit sind die Testdaten für alle Kennzahlen
der Phase 1 vorhanden.

## Offene Fragen

Keine.
