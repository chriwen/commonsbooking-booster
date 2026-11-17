# Belegungsplan im Backend

Status: Stufen 1 bis 3 umgesetzt, dazu Auswahl mit Suche und Mehrfachauswahl (15.11.2026, nicht committet) im Branch `feature/belegungsplan` (Basis `booster/index-availability`), nicht committet.
Keine neuen Tabellen, Optionen oder Postmeta, damit downgradebar (nur ein Usermeta-Eintrag für die zuletzt gewählte
Auswahl, den nur diese Seite liest). Funktioniert mit und ohne Booster.
English version: `belegungsplan.en.md`.

## Ziel

Admins und CB-Manager sehen für eine Station auf einen Blick, **wann welcher Artikel von wem gebucht ist** und
**warum ein Tag nicht buchbar ist** (Urlaub, Reparatur, Einschränkung, kein Zeitrahmen, außerhalb der Vorausbuchung),
mit direktem Sprung zur Bearbeitung.

## Entscheidungen des Nutzers (12.11.2026)

- **Nur Backend.** Eine Info-Schicht im Frontend-Kalender (Tooltip pro Tag) wurde verworfen.
- **CB-Manager dürfen alle Buchungsdaten an einer Station sehen**, die sie verwalten, auch für Artikel, die sie nicht
  verwalten.
- **Zeitraum frei wählbar, auch über den Vorausbuchungszeitraum hinaus**, ebenso in die Vergangenheit.

## Entscheidungen des Nutzers (15.11.2026)

- **Auswahl per Suchfeld** (Type-in) statt langer Auswahlliste.
- **Nur aktive Stationen bzw. Artikel zur Auswahl:** veröffentlicht und Teil einer aktiven Kombination, also mit
  mindestens einem veröffentlichten buchbaren Zeitrahmen, der noch nicht beendet ist (laufend oder künftig), dessen
  Station und Artikel beide veröffentlicht sind. Entwürfe und Einträge ohne Zeitrahmen erscheinen nicht. Bereits
  ausgewählte Einträge (z. B. ein Entwurf über die Zeilenaktion) bleiben sichtbar; die Rechte prüfen weiter alle
  erlaubten Posts. Die Wahl „laufend oder künftig“ (statt nur heute gültig) ist unsere Auslegung von „aktuell aktiv“,
  damit neu angelegte Kombinationen schon vor dem Start planbar sind.
- **Letzte Auswahl wird gemerkt** und beim nächsten Aufruf ohne Parameter geladen: Ansicht und je Ansicht Auswahl,
  Kategorie, Zeitraum, Tagesbreite, „Stornierte zeigen“. Nicht gemerkt wird das Startdatum (folgt immer heute − 3
  Tage). Gespeichert im Usermeta `commonsbooking_occupancy_plan` statt im Browser, damit es ohne Umleitung beim
  ersten Seitenaufbau greift und auf allen Geräten gilt.
- **Mehrfachauswahl:** mehrere Stationen bzw. Artikel; jede bekommt im Plan einen eigenen Block mit Kopfzeile.

## Entscheidungen des Nutzers (17.11.2026): Belegungsplan im Frontend

Branch `feature/belegungsplan-frontend` (Basis `feature/belegungsplan`).

- **Shortcode `[cb_occupancy_plan]`** zeigt den Belegungsplan auf einer beliebigen Seite, mit denselben Funktionen,
  Daten und Berechnungen wie im Backend. Die Entscheidung „Nur Backend“ vom 12.11. betraf die Info-Schicht im
  Buchungskalender für alle Nutzer und gilt dafür weiter.
- **Nur Admins und CB-Manager** mit ihren bisherigen Rechten; Gäste bekommen einen Hinweis mit Anmeldelink, andere
  Mitglieder den Hinweis „Der Belegungsplan ist nur für Admins und CB-Manager verfügbar.“ ohne Daten.
- **Gemerkte Auswahl gemeinsam mit dem Backend** (dasselbe Usermeta).
- **„Bearbeiten“-Links auch im Frontend** (führen ins Backend), nur mit Bearbeitungsrecht wie bisher.
- **Gemeinsame Darstellung:** Das Rendering liegt in einer Klasse für beide Oberflächen, nicht doppelt.
- **Attribut `lock`** für Seiten mit fester Auswahl (z. B. eine Seite je Station).

## Darstellung

Admin-Seite **Commons Booking → Belegungsplan** (`admin.php?page=cb-occupancy-plan`), außerdem Zeilenaktion
„Belegungsplan“ in der Stations- und der Artikelliste.

- **Zwei Ansichten** (Reiter): **Nach Station** (Zeilen = Artikel der Station) und **Nach Artikel** (Zeilen =
  Stationen des Artikels, `view=item`).
- **Auswahl:** Suchfeld mit Mehrfachauswahl (Chips, filtert beim Tippen ohne Rücksicht auf Groß-/Kleinschreibung und
  Akzente, Pfeiltasten, Enter wählt, Rücktaste entfernt den letzten Chip, Enter im leeren Feld oder „Anzeigen“ lädt;
  der Button wird hervorgehoben, wenn sich die Auswahl geändert hat). Leere Auswahl zeigt einen Hinweis statt eines
  Plans. Darunter Kategorie der Zeilen (Artikelkategorie bzw. Stationskategorie; nur wenn
  Kategorien mit Einträgen existieren), „Stornierte Buchungen zeigen“; zweite Zeile: Startdatum, Zeitraum (1, 2, 4,
  8 Wochen, 3, 6, 12 Monate), Tagesbreite, Blättern ← Heute →. Standard: 4 Wochen ab heute − 3 Tage. Höchstens 366 Tage.
- **Tagesbreite:** „Automatisch“ richtet sich nach dem Zeitraum (bis 2 Wochen 96 px, 4 Wochen 64 px, 8 Wochen 44 px,
  3 Monate 28 px, länger 18 px), oder fest sehr schmal bis sehr breit. Breitere Tage, weil Namen bei 30 px nicht
  lesbar waren (Rückmeldung des Nutzers).
- **Zeitleiste:** Spalten = Tage (Monats- und Tageskopf fixiert, Wochentag ab 28 px, heute markiert, Wochenende
  hinterlegt, Wochen- und Monatswechsel als stärkere Linie), Zeilen = Artikel bzw. Stationen (Spalte fixiert, Link zur
  Bearbeitung bei Bearbeitungsrecht).
- **Beschriftung der Balken** nach Breite: voller Name ab 60 px, darunter bei Buchungen Initialen, bei Sperren kein
  Text. Alles Weitere zeigt die Kurzinfo.
- **Mehrere Stationen bzw. Artikel:** ein Block je Auswahl in einem gemeinsamen Raster (gemeinsamer Tageskopf), mit
  Kopfzeile (Name, Link zur Bearbeitung) nur ab zwei Einträgen. Klick auf den Tageskopf erklärt den Tag für alle
  Blöcke (Zeilentitel „Station · Artikel“), Klick auf eine Zelle nur für deren Block.
- **Zeile „Alle Artikel der Station“** bzw. „Alle Stationen des Artikels“ (oben, nur wenn nötig): Sperren, die in *jeder* Artikelzeile gleich vorkommen
  (gleicher Post, gleiche Tage), werden dorthin verschoben, statt in jeder Zeile zu stehen. Erst ab zwei Zeilen.
  Ein „i“ hinter dem Namen erklärt das per Kurzinfo (Überfahren, Tastaturfokus oder Antippen; Wunsch des Nutzers
  17.11.), da die Zeile sonst wie ein weiterer Artikel wirkt.
- **Ebenen pro Zeile:**
  1. buchbarer Zeitrahmen als hellgrüner, halbtransparenter Hintergrund mit grüner Oberkante an den Tagen, an denen
     er gilt (Wochentage, Einzeltermine, Wiederholung); weiß = kein Zeitrahmen;
  2. Sperren als Balken: Feiertage/geschlossen (gelb), Blockiert/Reparatur (dunkelrot), Einschränkung Totalausfall
     (rot), Hinweis (gelb umrandet); nicht aktive Einschränkungen und nicht veröffentlichte Zeitrahmen blass;
  3. Buchungen als Balken mit dem Namen der buchenden Person; unbestätigt gestrichelt, storniert durchgestrichen.
  Überlappende Balken bekommen eigene Spuren (Sperren zuerst, dann Buchungen; jede Spur wird so weit wie möglich
  aufgefüllt).
- **Konflikte** (Stufe 2): Buchungen rot umrandet mit ⚠, Zähler „⚠ n“ an der Zeile. Konflikt heißt: am Abhol- oder
  Rückgabetag kein buchbarer Zeitrahmen oder Station geschlossen (Feiertag), oder Überschneidung mit einer Sperre, die
  nicht überbuchbar ist (Blockiert/Reparatur, aktiver Totalausfall). Abhol- und Rückgabetag werden nur geprüft, wenn
  sie im Zeitraum liegen. Stornierte Buchungen haben keine Konflikte. Es ist eine Anzeige der heutigen Regeln, keine
  Prüfung, ob die Buchung damals gültig war.
- **Ende der Vorausbuchung:** Tage nach dem spätesten Vorausbuchungsende der buchbaren Zeitrahmen schraffiert. Endet der
  Zeitrahmen vorher, gibt es keine Schraffur.
- Balken, die über den Zeitraum hinausgehen, haben an der Seite keine Rundung.

## Interaktion

- **Klick auf einen Balken** öffnet ein Seitenpanel: Art, Titel, Konflikte, Details, Button „Bearbeiten“ (nur mit
  Bearbeitungsrecht). Esc oder × schließt.
- **Klick auf einen Tag** (Stufe 2, „Warum ist dieser Tag so?“): Zelle = dieser Tag für diese Zeile, Tageskopf = alle
  Zeilen. Das Panel zeigt das Ergebnis, wie es der Buchungskalender sieht (buchbar, teilweise gebucht, ganztägig
  gebucht/gesperrt, alle Zeitfenster gesperrt, Feiertag/geschlossen, kein Zeitrahmen), Hinweise auf Vergangenheit und
  Vorausbuchung, und darunter alle Gründe (Zeitrahmen, Einschränkungen, Buchungen) aufklappbar mit Details und
  Bearbeiten-Link. Geladen per AJAX.
- **Aufbau der Tageserklärung** (Entscheidung 17.11., auf Wunsch des Nutzers: Zusammenhang Station, Artikel,
  Zeitrahmen und Buchungen war nicht auf einen Blick zu sehen):
  - Oben eine Zusammenfassung, wie viele Zeilen welches Ergebnis im Buchungskalender haben (z. B. „3 × Buchbar“,
    „4 × Ganztägig gebucht oder gesperrt“), farbig nach Ergebnis.
  - Darunter die Hierarchie wie im Plan: Station (Artikelansicht: Artikel) mit Symbol als Überschrift, die Artikel
    (bzw. Stationen) als Karten darunter, mit einer Baumlinie verbunden. Die Karte trägt links einen Farbstreifen
    nach dem Ergebnis (grün buchbar, gelb Hinweis, rot gesperrt/gebucht) und das Ergebnis als farbige Marke.
  - Die Gründe in der Karte nach Art gruppiert, mit Anzahl: Zeitrahmen (buchbar, Feiertag, geblockt),
    Einschränkungen, Buchungen. Gründe mit Konflikt zeigen ein Warnsymbol mit Anzahl und sind aufgeklappt.
  - Technik: Die AJAX-Antwort liefert pro Zeile `group_id` und `group_title`, statt den Stationsnamen vor den
    Artikelnamen zu setzen; das Skript gruppiert. Gleiches Skript für Admin und Frontend, im Admin im schlichten
    WP-Stil (Überschriften, gruppierte Gründe, Zusammenfassung), Karten, Symbole und Baumlinie nur im Frontend.
- **Kurzinfo beim Überfahren** als eigenes Element (`position: fixed`, über allem) statt `title`: Das native `title`
  wurde vom Scrollbereich und der fixierten Spalte verdeckt (Rückmeldung des Nutzers). Auch per Tastaturfokus.
- **Kurzanleitung** (Entscheidung 17.11., auf Wunsch des Nutzers): „So funktioniert der Plan“ am Ende der Legende
  öffnet eine Karte mit den Bedienschritten (Tag anklicken, Balken anklicken, Einklappen, Zeile „Alle Artikel der
  Station“, Konflikte, Namensspalte, Zeitraum). Standardmäßig geschlossen, damit der Plan nicht weiter nach unten
  rutscht; `<details>` ohne Skript, Esc oder Klick daneben schließt. Gleich in Admin-Seite und Frontend. Unter dem
  Raster bleiben 16 px frei, damit überlagernde Scrollbalken (macOS, Touch) die letzte Zeile nicht verdecken.
- **Stationen ein- und ausklappen** (Entscheidung 17.11., auf Wunsch des Nutzers: CB-Manager mit mehreren Stationen
  wollen schnell eine einzelne prüfen, ohne die Auswahl zu ändern). Nur wenn mehrere Stationen (bzw. Artikel in der
  Artikelansicht) gewählt sind:
  - Die Gruppenzeile trägt rechts im Namensfeld einen Pfeil; Klick auf Pfeil oder Namensfeld (außer dem Link) klappt
    ein und aus. Eingeklappte Gruppen bleiben als Zeile sichtbar, mit Zusammenfassung „n Artikel“ und Konfliktzahl,
    damit nichts übersehen wird.
  - „Nur diesen“ (erscheint beim Überfahren der Gruppenzeile, auf Touch-Geräten immer) klappt alle anderen Gruppen
    ein. „Alle aufklappen“ in der linken oberen Ecke erscheint, sobald etwas eingeklappt ist.
  - Die Tageserklärung übernimmt den Zustand: eingeklappte Gruppen stehen dort als geschlossene Abschnitte
    (`<details>`) mit Zusammenfassung, lassen sich aber einzeln öffnen, ohne den Plan zu ändern.
  - Gemerkt nur im Browser (`localStorage`, Schlüssel `cbPlanCollapsed`, getrennt nach Station und Artikel), nicht im
    Konto: Einklappen ist ein kurzer Blickwechsel, keine Einstellung. Wer eine Station neu in der Suche wählt,
    bekommt sie aufgeklappt. Mit nur einer Gruppe wirkt nichts davon.
- **Scrollposition halten** (Entscheidung 17.11., auf Wunsch des Nutzers): Jede Änderung (←/Heute/→, Ansichtswechsel,
  „Anzeigen“) lädt die Seite neu. Das Skript merkt sich vorher die Scrollposition (`sessionStorage`, Schlüssel
  `cbPlanScroll`, höchstens 30 s, nur für dieselbe Seite) und springt nach dem Laden dorthin zurück, statt oben zu
  beginnen. Sobald man selbst scrollt, korrigiert es nicht mehr nach. Ein Anker in der Adresse hat Vorrang.
- **Lange Namen** (Entscheidung 17.11., auf Wunsch des Nutzers, Admin-Seite und Frontend):
  - Namen in der linken Spalte werden mit „…“ gekürzt; beim Überfahren (oder Tastaturfokus auf dem Link) zeigt die
    Kurzinfo den vollen Namen, aber nur, wenn er tatsächlich gekürzt ist.
  - Die Namensspalte lässt sich verbreitern: Griff am rechten Rand des Tabellenkopfs ziehen (Maus, Stift, Touch),
    per Tastatur mit ←/→ in 20-px-Schritten, Doppelklick oder Pos1/Esc setzt auf die Standardbreite zurück. Grenzen
    100 px bis 75 % der sichtbaren Breite (höchstens 640 px). Beim Ziehen färbt sich die Spaltenkante in der
    Akzentfarbe.
  - Die gewählte Breite wird im Browser gespeichert (`localStorage`, Schlüssel `cbPlanLabelWidth`), gilt für Admin-Seite
    und Frontend gleichermaßen und überlebt das Neuladen. Bewusst nicht in der gemerkten Auswahl (User-Meta): Die
    passende Breite hängt vom Bildschirm ab, nicht vom Konto. Ohne `localStorage` (privates Fenster) funktioniert
    das Ziehen trotzdem, nur ohne Merken.
  - Umsetzung nur im Skript: Es setzt `--cb-plan-label` direkt auf `.cb-plan`; das Raster, die fixierte Spalte und der
    mitlaufende Monatsname nutzen diese Variable. Ohne JavaScript bleibt die Standardbreite.
- Details Zeitrahmen: Zeitraum, Wiederholung, Wochentage bzw. Termine, Uhrzeit, max. Buchungsdauer, Vorausbuchung,
  Rollenbeschränkung, Status (wenn nicht veröffentlicht).
- Details Einschränkung: Zeitraum, Status, Hinweistext.
- Details Buchung: Zeitraum, Status, Name, E-Mail, Telefon, Kommentar (diese drei immer, leer als „–“), Buchungscode,
  gebucht am. Telefon ist das Profilfeld `phone`, Kommentar das Buchungsfeld `comment`.
- Nur lesend, kein Verschieben von Buchungen.

## Frontend (Shortcode)

```
[cb_occupancy_plan]
[cb_occupancy_plan view="item" days="56"]
[cb_occupancy_plan location="12,15" lock="1" align="wide"]
```

| Attribut | Bedeutung | Standard |
|---|---|---|
| `view` | `location` oder `item`; ohne Angabe ergibt sich die Ansicht aus `location`/`item`, sonst aus der gemerkten Auswahl | `location` |
| `location` / `item` | Vorauswahl (IDs, Komma-getrennt), immer mit den Rechten geschnitten | gemerkte Auswahl |
| `days` | 7, 14, 28, 56, 91, 182, 365 | 28 |
| `zoom` | `xs`, `s`, `m`, `l`, `xl` oder leer (automatisch) | automatisch |
| `canceled` | `1` = stornierte Buchungen zeigen | `0` |
| `tabs` | `0` = Ansichtsreiter ausblenden | `1` |
| `lock` | `1` = Auswahl fest: Suchfeld und Reiter ausgeblendet, Ansicht und Auswahl aus URL oder gemerkter Auswahl werden ignoriert, die gemerkte Auswahl wird nicht überschrieben. Zeitraum, Kategorie usw. bleiben bedienbar. Wirkt auf die Auswahl nur mit `location` bzw. `item`. | `0` |
| `align` | `wide` oder `full` (Klassen `alignwide`/`alignfull` für Block-Themes) | – |

- **Vorrang:** URL-Parameter (nach Absenden des Formulars oder Blättern) vor Attributen vor gemerkter Auswahl.
- **URL-Parameter mit Präfix `cbp_`** (`cbp_view`, `cbp_location[]`, `cbp_item[]`, `cbp_cat`, `cbp_from`, `cbp_days`,
  `cbp_zoom`, `cbp_canceled`, `cbp_sel`): Ohne Präfix würde `cat` als WordPress-Kategorieabfrage gelesen. Das Backend
  behält seine Namen. Die Abfrage der Seite (z. B. `page_id` bei einfachen Permalinks) bleibt im GET-Formular als
  verstecktes Feld erhalten.
- **Eine Instanz pro Seite** (feste Element-IDs); eine zweite zeigt einen Hinweis.
- **Kein Seiten-Cache:** Auf Seiten mit dem Shortcode `nocache_headers()` (über `template_redirect`) und
  `DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCACHEDB` sowie `litespeed_control_set_nocache`, weil der Plan Namen,
  E-Mail und Telefon enthält.
- **Darstellung in Themes:**
  - Alle Regeln gelten unter den IDs `#cb-plan-root` (Hülle), `#cb-plan-panel` und `#cb-plan-tip`. Panel und Kurzinfo
    hängt das Skript an `<body>`, damit `position: fixed` nicht von Vorfahren mit `transform` oder `overflow`
    abhängt. Theme-Regeln wie `.entry-content button` (Spezifität 0,1,1) können den Plan so nicht überschreiben.
  - Im Frontend setzen Reset-Regeln mit `#cb-plan-root:where(.cb-plan-front) button` (1,0,1) die typischen
    Theme-Vorgaben zurück (Buttons, Listen, Links, Formularfelder, Überschriften im Panel). Sie liegen über
    Theme-Regeln und unter den eigenen Regeln des Plans (1,1,0).
  - Aufbau aus Karten statt einer Hülle: die Ansicht als Segmentschalter, die Filter als hellgraue Werkzeugkarte
    (Zeilen Auswahl, Optionen, Zeitraum durch Haarlinien getrennt, Beschriftungen klein über den Feldern, Felder 36 px
    hoch mit Fokusring, „Anzeigen“ als Hauptbutton, ← Heute → als Buttongruppe), Legende und Plan zusammen in einer
    weißen Karte (12 px Radius). Rahmen und Raster als 1-px-Haarlinien in hellem Grau, der heutige Tag als leichte
    Tönung statt dicker Linien, Balken mit 6 px Radius. Entscheidung 17.11. auf Wunsch des Nutzers (zu dicke Linien,
    zu wenig gegliederte Filter). Gilt nur im Frontend (`.cb-plan-front`), die Admin-Seite bleibt im WP-Stil.
  - Hierarchie im Frontend (Entscheidung 17.11., auf Wunsch des Nutzers): Gruppenzeilen (Station bzw. Artikel) mit
    Symbol in Akzentfarbe, fett auf grauem Grund; die Einträge darunter eingerückt, mit eigenem Symbol (Stecknadel für
    Stationen, Paket für Artikel, Ebenen für „Alle Artikel der Station“) und einer Baumlinie, die beim letzten Eintrag
    endet. Symbole als CSS-Masken, im Admin ausgeblendet. Mobil Namensspalte 150 px statt 120 px.
  - Für beide Seiten: Die Namensspalte deckt den Zeilenabstand mit ab (vorher schauten Balken oben und unten durch),
    und der Monatsname bleibt beim seitlichen Scrollen neben der Namensspalte stehen.
  - Zeilen als Spuren (Entscheidung 17.11., auf Wunsch des Nutzers): Jede Zeile ist eine hellgraue Spur, zwischen
    zwei Zeilen liegt ein kleiner weißer Abstand (`--cb-plan-gap`, 4 px oben und unten), damit Balken eindeutig zu
    einer Zeile gehören. Grau heißt „kein Zeitrahmen“ (neuer Legendeneintrag „Kein Zeitrahmen“), Wochenende und
    Vergangenheit etwas dunkler, heute hellblau, Tage durch weiße Fugen getrennt. Buchbare Zeiträume sind grüne
    Blöcke mit abgerundeten Ecken (am Rand des Zeitraums gerade) statt grüner Fläche mit grüner Oberkante; dadurch
    reißt die Zeile bei Tagen ohne Zeitrahmen nicht mehr weiß ab. Beim Überfahren werden Name und Spur gemeinsam
    hervorgehoben. Im Admin bleibt die bisherige Darstellung (nur der Legendeneintrag kommt dazu).
  - Schrift vom Theme, Grundgröße 14 px (die Tagesbreiten sind in px), eigene Reiter und Buttons statt der
    Admin-Klassen, Akzentfarbe über die CSS-Variable `--cb-plan-accent` überschreibbar. Abweichung vom Konzept: keine Größen in
    `rem` und keine Übernahme von `--wp--preset--color--primary`, weil die Themes diese Variable uneinheitlich
    belegen.
  - Scrollbereich höchstens 75 % der Fensterhöhe, Panel über Theme-Kopfzeilen (`z-index` 99999), mit Admin-Leiste
    darunter, unter 600 px volle Breite.
  - Geprüft im Browser mit Twenty Twenty-Five (Block-Theme) und Twenty Twenty-One (klassisch), Desktop und 375 px.

## Rechte

- Seite und Shortcode: Recht `manage_commonsbooking` (Admins und CB-Manager). Im Frontend ohne Recht nur ein Hinweis,
  keine Daten, keine Auswahlliste, kein Nonce.
- Stationen: `Repository\Location::getByCurrentUser()`, also alle für Admins, sonst die Stationen, die der Nutzer
  verwaltet oder angelegt hat. Eine andere Station per URL fällt auf die erste erlaubte zurück.
- Artikel (Ansicht pro Artikel): `Repository\Item::getByCurrentUser()` entsprechend. Gezeigt werden alle Stationen,
  an denen der Artikel einen Zeitrahmen hat, mit allen Buchungen (sinngemäß wie die Entscheidung oben).
- Für eine erlaubte Station werden **alle** Buchungen mit vollen Daten gezeigt (Entscheidung oben). Die
  Bearbeiten-Links prüfen weiterhin `commonsbooking_isCurrentUserAllowedToEdit()`, an den bestehenden Rechten ändert
  sich also nichts.
- Tageserklärung per AJAX (`wp_ajax_cb_occupancy_day`, Parameter `ids` als Liste): Nonce, Recht
  `manage_commonsbooking` und jede Station bzw. jeder Artikel der Liste werden bei jeder Anfrage geprüft.
- Kein Zwischenspeicher für die Seite (der Kalender-JSON wird nur nach Rollen geschlüsselt und darf keine Namen
  enthalten).

## Technik

- `Service\OccupancyPlan::build( $locationId, $from, $to, $withCanceled, $onlyRows )` und
  `buildForItem( $itemId, … )` liefern Ansicht, Tage, Zeilen (`row_id`, `item_id`, `location_id`, `conflicts`),
  Segmente (`kind`, `post_id`, `from`/`to` als Tagesindex, `clip_start`/`clip_end`, `lane`, `label`, `state`,
  `conflict`) und die Details je Post. `$onlyRows` ist der Kategoriefilter (IDs aus `get_objects_in_term()`).
- `explainDay( $view, $id, $date, $rowId )` baut den Plan für einen Tag und liefert je Zeile `dayResult()` (Ergebnis
  über `DayFacts::fromGrid()` des `Model\Day`, also dieselbe Auswertung wie der Kalender) und die Gründe.
- `View\OccupancyPlan::render( $context )` rendert daraus für Backend und Shortcode ein CSS-Grid (Subgrid je Zeile);
  der Kontext legt nur Basis-URL, Parameter-Präfix, Reiter, Button-Klassen und die Hülle fest.
  `View\Admin\OccupancyPlan` ist die dünne Hülle für Menü, Zeilenaktionen und Admin-Seite. CSS und Skript (ohne
  Abhängigkeiten) liegen in `assets/occupancy-plan/` ohne Build-Schritt und werden nur auf der Admin-Seite bzw. auf
  Seiten mit dem Shortcode geladen.
- **Plan serverseitig gerendert** (Abweichung vom Konzept): Die Seite lädt per GET neu. Nur die Tageserklärung kommt
  per AJAX, weil sie pro Tag das Kalenderraster rechnet und das für alle Tage beim Seitenaufbau zu teuer wäre. Die Details aller sichtbaren Posts stehen als JSON in der Seite, nur für
  berechtigte Nutzer.
- **Datenquellen:**
  - Artikel der Station: buchbare Zeitrahmen der Station im Zeitraum (`Repository\Timeframe::getInRange`) plus Artikel
    mit Buchungen im Zeitraum.
  - Zeitrahmen pro Artikel: `getInRange( …, [ $location ], [ $item ], [ buchbar, Feiertage, Reparatur ] )` wie
    `Model\Calendar`, also auch Listen, Kategorien und „alle“.
  - Einschränkungen: `Repository\Restriction::get()` mit den Status aus `Day::RESTRICTION_STATUSES`.
  - Buchungen: `Repository\Booking::getByTimerange()`; nutzt bei eingeschaltetem Booster den Index.
- **Gleiche Regeln wie der Kalender:** Ob ein Zeitrahmen an einem Tag gilt, entscheiden
  `Repository\Timeframe::filterTimeframesForTimerange()` und `Model\Day::isInTimeframe()`, ausgeführt in
  `Model\Timeframe::withoutBookingWindow()`, damit auch Tage nach der Vorausbuchung erscheinen. Keine zweite
  Implementierung der Wiederholungsregeln.
- **Tests** (`tests/php/Service/OccupancyPlanTest.php`): Wochentage und Vorausbuchung, Buchungen mit Status und
  Zuschnitt, Stationszeile, Einschränkung ohne Ende, Spuren, Rechte für CB-Manager, Vergleich mit `DayFacts` aus dem
  Kalenderraster, gleiches Ergebnis mit und ohne Booster, Ansicht pro Artikel und Kategoriefilter, Konflikte (Feiertag
  am Abholtag, Sperre, kein Zeitrahmen, Zuschnitt), Tageserklärung (Gründe, Ergebnis, Hinweise), aktive Kombinationen (`activeIds()`: laufend, künftig, beendet,
  Entwurf, nur Feiertag, ohne Zeitrahmen).
- **Tests Shortcode** (`tests/php/View/OccupancyPlanShortcodeTest.php`): Gast, Mitglied ohne Recht, CB-Manager ohne
  und mit Station (fremde IDs per Attribut und URL fallen weg), Admin, Parameter-Präfix und `page_id` im Formular,
  gleiches Raster wie die Admin-Seite, URL vor Attribut, Abwählen von „Stornierte“, `lock`, Ansicht aus den IDs,
  gemeinsame gemerkte Auswahl, nur eine Instanz, Artikelansicht mit `align`.
- **Manuell testen:** Die Zeitrahmen-Varianten aus `tests/php/Fixtures/TimeframeVariants.php` decken die meisten
  Darstellungen ab, je eine Station „Variante: …“: *Feiertage* (viele Feiertage, Tageserklärung), *Einschränkungen*
  (Totalausfall und Hinweis), *Vormittag/Nachmittag Mo–Do* (mehrere Buchungen pro Tag, Konflikte, mehrere Spuren),
  *Lange Buchungen* (mehrtägig, über den Rand, storniert), *Liste Standort 1* (zwei Artikel, Stationszeile),
  *Kurzer Vorlauf* (Ende der Vorausbuchung), *Von Manager betreut* (Rechte für CB-Manager). Für Gruppen mit vielen
  Artikeln mehrere Stationen zusammen auswählen. Unbestätigte Buchungen kommen in den Varianten nicht vor.

## Ausbaustufen

1. **Umgesetzt:** Zeitleiste pro Station, Detailpanel, Rechte, Tests.
2. **Umgesetzt:** „Warum ist dieser Tag so?“ und Konfliktmarkierung.
3. **Umgesetzt:** Ansicht pro Artikel, Filter nach Kategorie; dazu überarbeitetes Layout (Tagesbreite, Kurzinfo).
   Danach (15.11.): Suchfeld mit Mehrfachauswahl, nur aktive Einträge, gemerkte Auswahl.
4. Optional: Druck/Export, Verknüpfung mit `feature/dashboard`.
5. **Umgesetzt (17.11.2026, Branch `feature/belegungsplan-frontend`):** Shortcode `[cb_occupancy_plan]`, gemeinsame
   Darstellung für Backend und Frontend.

## Offene Fragen

- Standardzeitraum. Keine Obergrenze für die Zahl gewählter Einträge; jeder Block rechnet seinen Plan einzeln.
- Buchungen mit Stundenraster zeigen bisher nur den Tag; die Uhrzeit steht im Panel.
