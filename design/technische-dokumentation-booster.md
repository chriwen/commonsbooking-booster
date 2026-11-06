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
| **Vorberechnete Verfügbarkeit** | `Service\Availability` | Speichert pro Artikel/Standort-Paar den datumsunabhängigen Zustand von gestern bis heute + 92 Tage. Karte und Artikeltabelle lesen ihn fertig aus. |
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

### 1.4 Grundprinzip

Dieser Abschnitt erklärt die Idee hinter dem Booster so, dass sie auch ohne genaue Kenntnis von CommonsBooking
verständlich ist. Ein durchgehendes Beispiel begleitet alle Schritte. Die technischen Einzelheiten zu Tabellen und
Spalten stehen danach in Kapitel 2.

#### 1.4.1 Wie CommonsBooking seine Daten ablegt

CommonsBooking verleiht **Artikel** (zum Beispiel ein Lastenrad) an **Standorten** (zum Beispiel einer Bäckerei, die
das Rad ausgibt). Wann ein Artikel an einem Standort ausgeliehen werden kann, legt ein **Zeitrahmen** fest, etwa
„montags bis freitags, ganztägig, ab 1. Januar 2026, unbefristet“. Daneben gibt es weitere Zeitrahmen-Arten wie
Feiertage („Standort vom 24. bis 26. Dezember geschlossen“), **Buchungen** („Nutzerin 17 leiht das Rad am 10. November“)
und **Einschränkungen** („Rad ist ab 10. November in Reparatur“).

Für das Beispiel dieses Abschnitts gilt:

| | Beispiel | ID |
|---|---|---|
| Artikel | Lastenrad „Emil“ | 812 |
| Standort | Bäckerei Sonnenschein | 455 |
| Zeitrahmen | buchbar Mo–Fr, ganztägig, ab 01.01.2026, unbefristet, bis zu 30 Tage im Voraus | 501 |
| Buchung | Dienstag, 10.11.2026, ganztägig, Nutzerin 17 | 9301 |
| heutiges Datum | Freitag, 06.11.2026 | |

CommonsBooking ist ein WordPress-Plugin und speichert alles davon so, wie WordPress Inhalte speichert: als
**Beiträge**. Jeder Zeitrahmen, jede Buchung und jede Einschränkung ist eine Zeile in der Tabelle `wp_posts`. Diese Zeile
kennt aber nur allgemeine Angaben wie ID, Titel, Status und Autor. Alle fachlichen Angaben (welcher Artikel, welcher
Standort, von wann bis wann) stehen in einer zweiten Tabelle, `wp_postmeta`, und zwar als einzelne Schlüssel-Wert-Paare,
**eine Zeile pro Angabe**. Die Buchung 9301 sieht in der Datenbank ungefähr so aus:

```
wp_posts                       ID 9301 · post_type cb_booking · post_status confirmed · post_author 17

wp_postmeta   post_id  meta_key            meta_value
              9301     type                6               (6 = Buchung)
              9301     item-id             812
              9301     location-id         455
              9301     repetition-start    1794268800      (10.11.2026 00:00)
              9301     repetition-end      1794355199      (10.11.2026 23:59:59)
              9301     _cb_bookingcode     …               (und einige weitere)
```

Im Testbestand, auf dem der Booster entwickelt wurde, kommen auf 17.100 Zeitrahmen und Buchungen rund 190.000 solcher
Meta-Zeilen, also etwa elf pro Beitrag.

Dieses Speicherformat ist flexibel: Jedes Plugin kann neue Angaben speichern, ohne die Datenbank zu verändern. Es hat
aber einen Preis, sobald man **suchen** will. Das wird im nächsten Abschnitt deutlich.

#### 1.4.2 Warum es mit wachsender Historie langsam wird

Fast jede Ansicht in CommonsBooking stellt im Kern dieselbe Frage: *Welche Zeitrahmen und Buchungen betreffen das
Lastenrad 812 an der Bäckerei 455 in den nächsten zwölf Tagen?* Die Karte stellt diese Frage für jedes Paar aus Artikel
und Standort, die Artikeltabelle ebenso, der Buchungskalender für einen Artikel, die REST-API für alle.

**Problem 1: Suchen.** In `wp_postmeta` gibt es keine Spalte „Artikel“ und keine Spalte „Ende“, die die Datenbank
geordnet vorhalten könnte. Es gibt nur die Spalten `meta_key` und `meta_value`. Um die Frage zu beantworten, verknüpft
CommonsBooking 2.11.3 mehrere Meta-Zeilen miteinander (sinngemäß: „suche alle Beiträge, die eine Zeile `type = 6` UND
eine Zeile `item-id = 812` UND eine Zeile `location-id = 455` haben“). Die Datumsbedingung kann die Datenbank dabei nicht
nutzen; sie wird erst danach in PHP geprüft.

Das hat eine unangenehme Folge: Wurde das Lastenrad in den letzten drei Jahren 600-mal gebucht, liefert die Abfrage alle
600 Buchungen. CommonsBooking lädt dann jede einzelne (in 2.11.3 mit einem eigenen `get_post()`-Aufruf pro Buchung) und
wirft anschließend 597 davon wieder weg, weil sie in der Vergangenheit liegen. Die Arbeit wächst also mit der
**gesamten Historie**, nicht mit dem, was tatsächlich angezeigt wird. Ein Vergleich aus dem Alltag: Wer in einer
Bibliothek ohne Katalog ein Buch sucht, muss jedes Regal ablaufen, und mit jedem neuen Buch wird der Weg länger.

**Problem 2: Rechnen.** Selbst wenn die richtigen Beiträge gefunden sind, steht die Antwort „frei oder belegt?“ nirgends
fertig. CommonsBooking berechnet sie bei jedem Aufruf neu: Für jeden Tag baut es ein Raster aus Zeitabschnitten
(„Slots“), legt Zeitrahmen, Feiertage, Buchungen und Einschränkungen übereinander, entscheidet für jeden Abschnitt,
welcher Eintrag gewinnt (eine Buchung schlägt den buchbaren Zeitrahmen), und fasst das Ergebnis zu einem Tageszustand
zusammen. Das kostet pro Paar rund 7 ms für 31 Tage und gut 10 ms für 90 Tage. Das klingt wenig, aber eine Karte mit 100
Paaren bezahlt es hundertmal, bei jedem Aufruf jedes Besuchers.

Gemessen auf dem Testbestand ergab das in 2.11.3: Die Karte mit Verfügbarkeitsanzeige brauchte **15,4 Sekunden** und
**40.800 Datenbankabfragen**, die Artikeltabelle `[cb_items_table]` 7,1 Sekunden. Bei zehnfacher Historie brachen manche
Ansichten mit Speicherfehlern ab.

#### 1.4.3 Die Idee: dieselben Daten zusätzlich in einer lesefreundlichen Form

Der Booster ändert **nicht**, wo und wie CommonsBooking seine Daten speichert. `wp_posts` und `wp_postmeta` werden
genauso geschrieben und gelesen wie vorher; sie bleiben die einzige verbindliche Quelle, die *Quelle der Wahrheit*. Der
Booster legt nur **zusätzlich** zwei Tabellen an, die dieselben Informationen in einer Form enthalten, die für die
häufigen Fragen schnell zu lesen ist. Man nennt solche Tabellen *abgeleitete Daten* oder *Lesemodelle*; in der
Datenbankwelt ist auch der Begriff *materialisierte Sicht* gebräuchlich.

Das Prinzip kennt jeder aus einem Sachbuch: Das **Stichwortverzeichnis** am Ende enthält keine neue Information, alles
steht auch im Text. Aber es ist so geordnet, dass man eine Stelle in Sekunden findet, statt das Buch durchzublättern.
Wird der Text überarbeitet, muss das Verzeichnis nachgezogen werden, und im Zweifel gilt der Text, nicht das Verzeichnis.
Genau so verhalten sich die neuen Tabellen.

Der Booster arbeitet in zwei Stufen, die jeweils eines der beiden Probleme lösen:

```
 Quelle der Wahrheit             Stufe 1: Index                    Stufe 2: Vorberechnung
 (unverändert)                   löst „Suchen“                     löst „Rechnen“

 wp_posts + wp_postmeta   ──►   cb_index (+ cb_index_rel)   ──►   cb_availability
 1 Beitrag =                     1 Beitrag = 1 Zeile               1 Paar (Artikel, Standort) = 1 Zeile
 1 Post + ~11 Meta-Zeilen        mit festen Spalten                mit dem fertigen Ergebnis
                                 für Artikel, Standort,            „wie sieht jeder Tag aus?“
                                 Typ, Start, Ende …                für gestern bis heute + 92 Tage
```

Die erste Stufe ist ein **Spiegel**: Sie enthält nichts, was nicht auch in den Beiträgen steht, nur besser sortiert.
Die zweite Stufe ist ein **Ergebnis-Speicher**: Sie enthält das, was CommonsBooking bisher bei jedem Aufruf ausgerechnet
hat, einmal ausgerechnet und abgelegt.

#### 1.4.4 Stufe 1, der Index: jede Angabe hat ihre eigene Spalte

Für jeden Zeitrahmen, jede Buchung und jede Einschränkung schreibt der Booster genau eine Zeile in die Tabelle
`cb_index`. Die Angaben, die vorher auf viele Meta-Zeilen verteilt waren, stehen dort nebeneinander in eigenen Spalten.
Die Buchung 9301 wird zu:

```
cb_index   post_id 9301 · post_type cb_booking · post_status confirmed · post_author 17 · type 6
           item_id 812 · location_id 455 · start_ts 1794268800 · end_ts 1794355199 · …
```

Weil es jetzt echte Spalten gibt, kann die Datenbank einen **Schlüssel** (einen sortierten Suchbaum) darüber anlegen.
Der wichtigste heißt `item_loc_range` und sortiert alle Zeilen nach Artikel, dann Standort, dann Typ, dann Ende. Man kann
sich das wie ein Telefonbuch vorstellen, das erst nach Ort, dann nach Straße, dann nach Hausnummer geordnet ist: Wer
„Artikel 812, Standort 455, Typ Buchung, Ende ab 06.11.2026“ sucht, schlägt direkt die richtige Stelle auf und liest
nur die wenigen Einträge, die dort stehen. Die 597 alten Buchungen liegen im Telefonbuch an einer anderen Stelle (ihr
Ende liegt vor dem 06.11.) und werden gar nicht erst angefasst.

Die Frage aus 1.4.2 wird damit zu einer einzigen, einfachen Abfrage:

```sql
SELECT post_id FROM wp_cb_index
 WHERE item_id = 812 AND location_id = 455
   AND type IN (2, 3, 6)                          -- buchbar, Feiertag, Buchung
   AND post_status IN ('publish', 'confirmed')
   AND (end_ts IS NULL OR end_ts >= 1793923200)   -- endet nicht vor dem 06.11.2026
   AND start_ts <= 1794959999                     -- beginnt nicht nach dem 17.11.2026
```

Angenommen, im Zeitraum liegen drei Buchungen. Dann lautet das Ergebnis: der Zeitrahmen 501 (offenes Ende, `end_ts IS NULL`) und die drei Buchungen in diesem Zeitraum, darunter 9301.
Danach lädt `Index::loadPosts()` diese vier Beiträge samt Metadaten **gebündelt** mit zwei Abfragen statt mit vier
einzelnen. Ab hier läuft der unveränderte Code von CommonsBooking weiter, er bekommt nur eine kleinere, schon passende
Liste.

**Beispielrechnung.** Im Testbestand dauerte die Suche für ein Paar über `postmeta` 26 ms, über den Index 0,12 ms. Bei
fünfzigfacher Historie (867.000 Index-Zeilen) waren es 0,18 ms; die Suchzeit hängt also praktisch nicht mehr von der
Historie ab. Die Karte stellte in 2.11.3 die Frage für 400 Paare einzeln, also 400 × 25 ms ≈ 10 s. Mit dem Index fragt sie
alle Paare in **einer** Abfrage ab: 1,7 ms. Allein durch den Index sank die Karte mit Verfügbarkeit von 15,4 s auf
etwa 1,2 s.

Was der Index **nicht** tut: Er rechnet nichts aus und entscheidet nichts. Ob ein Zeitrahmen an einem Mittwoch gilt
oder ob ein Nutzer ihn buchen darf, prüft weiterhin der bestehende PHP-Code, nachdem die Beiträge geladen sind. Der
Index sorgt nur dafür, dass dieser Code nicht mehr 600 Kandidaten bekommt, wenn 4 genügen.

#### 1.4.5 Stufe 2, die Vorberechnung: das Ergebnis aufbewahren statt neu rechnen

Nach Stufe 1 waren die Abfragen billig, aber die Tagesberechnung aus 1.4.2 (Problem 2) blieb: Die Karte verbrachte von
ihren 1,2 Sekunden nur noch 0,1 Sekunden in der Datenbank und den Rest mit Rechnen. Stufe 2 speichert deshalb das
Rechenergebnis.

Die Tabelle `cb_availability` hat eine Zeile **pro Paar aus Artikel und Standort**, für unser Beispiel also eine Zeile für
(812, 455). In dieser Zeile steht für jeden Tag von gestern bis heute + 92 Tage ein kurzer Code, der den Tag beschreibt.
Der Code ist eine Zahl aus zehn Ja/Nein-Angaben (Bits), etwa „hat der Tag überhaupt einen buchbaren Zeitrahmen?“,
„ist alles belegt?“, „ist es ein Feiertag?“, „ist der erste Abschnitt des Tages belegt?“. Für das Beispiel, berechnet am
Freitag, 06.11.2026, sieht der Anfang der Zeile so aus:

| Tag | Datum | Was gilt | Code | gespeicherte Bytes |
|---|---|---|---|---|
| 0 | Do 05.11. | buchbar, frei (gestern; wird trotzdem gespeichert) | 513 | `01 02` |
| 1 | Fr 06.11. | buchbar, frei | 513 | `01 02` |
| 2 | Sa 07.11. | kein Zeitrahmen (nur Mo–Fr) | 60 | `3C 00` |
| 3 | So 08.11. | kein Zeitrahmen | 60 | `3C 00` |
| 4 | Mo 09.11. | buchbar, frei | 513 | `01 02` |
| 5 | Di 10.11. | ganztägig gebucht (Buchung 9301) | 487 | `E7 01` |
| 6 | Mi 11.11. | buchbar, frei | 513 | `01 02` |
| … | … | … | … | … |
| 93 | Fr 05.02.2027 | buchbar, frei | 513 | `01 02` |

Jeder Tag belegt zwei Bytes, 94 Tage also 188 Bytes. Die Codes setzen sich aus den Bits zusammen; zum Beispiel ist 513 =
1 („hat Abschnitte“) + 512 („genau ein ganztägiger Abschnitt“). Die genaue Bedeutung jedes Bits steht in 2.4.

**Ein Tag abrufen.** Möchte die Karte wissen, wie der Dienstag, 10.11., aussieht, rechnet sie nur noch eine Position
aus: Die Zeile beginnt am 05.11. (`days_from`), der 10.11. ist 5 Tage später, also stehen seine Bytes an Position 5 × 2 =
10. Dort steht `E7 01`, gelesen als Zahl (niedriges Byte zuerst) 0x01E7 = 487, also „belegt“. Es wird kein einziger
Beitrag geladen und kein Raster gebaut.

**Beispielrechnung für die Karte.** Die Karte zeigt für 100 Paare je zwölf Tage. Ohne Vorberechnung heißt das 100 ×
etwa 7 ms Rechenzeit ≈ 0,7 s, zuzüglich der Abfragen. Mit Vorberechnung lädt der Booster alle 100 Zeilen in einer
einzigen Abfrage (100 × 188 Byte ≈ 19 KB) und liest daraus 1.200 Codes an bekannten Positionen. Gemessen: Karte mit
Verfügbarkeit **0,11 s** statt 15,4 s in 2.11.3, Artikeltabelle **0,08 s** statt 7,1 s.

**Was bewusst nicht gespeichert wird: alles, was vom heutigen Datum abhängt.** Ob ein Tag in der Vergangenheit liegt, ob
er noch im Vorausbuchungsfenster liegt („bis zu 30 Tage im Voraus“) oder ob er vor dem frühesten Buchungstag liegt, hängt
davon ab, welcher Tag heute ist. Würde man das mitspeichern, wäre um Mitternacht jede Zeile falsch und müsste neu
berechnet werden. Stattdessen speichert die Zeile nur, was aus den Beiträgen folgt, und das Datum wird beim **Lesen**
angewendet (`CalendarWindow`). Für unser Beispiel bedeutet das am Freitag, 06.11.:

- Der 05.11. trägt zwar den Code „frei“, liegt aber in der Vergangenheit und wird deshalb als „gesperrt“ angezeigt.
- Der 06.11. ist heute und frei, also „verfügbar“.
- Ein Tag mehr als 30 Tage nach heute trägt ebenfalls „frei“, wird wegen des Vorausbuchungsfensters aber als „gesperrt“
  angezeigt.

Am Samstag, 07.11., liest die Karte **dieselbe Zeile** unverändert weiter. Der 10.11. steht immer noch an Position 10,
nur rutscht jetzt auch der 06.11. in die Vergangenheit und das Vorausbuchungsfenster wandert einen Tag weiter. Neu
berechnet werden muss dafür nichts. Ein stündlicher Hintergrundjob rechnet Zeilen, die älter als zwölf Stunden sind,
ohnehin neu, damit der Zeitraum von 94 Tagen mitwandert; eine Zeile, die älter als 24 Stunden ist, wird gar nicht mehr
verwendet.

#### 1.4.6 Aktuell bleiben: der Generationszähler

Gespeicherte Ergebnisse haben ein grundsätzliches Risiko: Sie können veralten. Wenn jemand das Lastenrad für Mittwoch,
11.11., bucht, darf die Karte keine Sekunde später noch „frei“ anzeigen. Ein nächtlicher Neuaufbau würde dafür nicht
reichen, und den kompletten Tageszustand bei jeder Buchung sofort neu zu berechnen, würde die Buchung selbst
verlangsamen.

Der Booster trennt deshalb zwei Dinge: das **Ungültig-Machen**, das sofort und billig passiert, und das **Neu-Berechnen**,
das etwas später passieren darf. Dafür hat jede Zeile in `cb_availability` zwei Zähler:

- `gen` (Generation) zählt, wie oft sich etwas am Paar geändert hat. Jede Änderung an einem Zeitrahmen, einer Buchung
  oder einer Einschränkung des Paares erhöht ihn sofort um 1. Das ist eine einzige, winzige Datenbankanweisung, die
  unter einer Millisekunde dauert.
- `computed_gen` merkt sich, auf welchem Stand die gespeicherten Tagescodes berechnet wurden.

Eine Zeile wird nur benutzt, wenn beide Zähler gleich sind. Man kann sich das wie einen Aushang mit Versionsnummer
vorstellen: Hängt am Brett „Stand 41“, im Büro ist man aber schon bei Stand 42, dann weiß jeder, dass der Aushang nicht
mehr gilt, auch wenn noch niemand einen neuen aufgehängt hat.

Für die Buchung am Mittwoch läuft das so ab:

| Zeitpunkt | Was passiert | `gen` | `computed_gen` | Karte zeigt den 11.11. als |
|---|---|---|---|---|
| vorher | Zeile ist aktuell | 41 | 41 | frei (aus der Zeile) |
| Buchung wird gespeichert | WordPress speichert den Beitrag, der Index übernimmt ihn, der Zähler wird erhöht | **42** | 41 | – |
| direkt danach | Zeile gilt als veraltet, weil 42 ≠ 41 | 42 | 41 | belegt (live berechnet oder Zeile frisch berechnet) |
| einige Millisekunden später | nachdem die Antwort an den Browser gegangen ist, rechnet der Booster das Paar neu (rund 13 ms) und speichert es | 42 | **42** | belegt (aus der Zeile) |

Entscheidend ist der dritte Schritt: Selbst wenn die Neuberechnung ausfällt (Fehler, abgebrochener Prozess, eine
Feiertagsänderung betrifft auf einen Schlag 300 Paare und wird deshalb dem Hintergrundjob überlassen), wird die alte
Zeile **nie** ausgeliefert. Wer als Nächstes die Karte öffnet, löst die Neuberechnung selbst aus oder bekommt das
Ergebnis live gerechnet. Langsamer kann es dann einmal sein, falsch nicht.

Beim Speichern einer Neuberechnung gibt es noch eine Absicherung gegen Gleichzeitigkeit: Der Booster schreibt nur, wenn
`gen` noch denselben Wert hat wie zu Beginn der Berechnung (`… WHERE gen = 42`). Kommt während der 13 ms eine zweite
Buchung herein und erhöht auf 43, verwirft die Datenbank das Ergebnis, das die zweite Buchung noch nicht kannte. Die
Zeile bleibt ungültig, bis eine Berechnung auf Stand 43 fertig ist. Dafür braucht es keine Sperren, die andere Anfragen
warten lassen würden.

#### 1.4.7 Im Zweifel die Quelle: Rückfall auf den Originalweg

Jede Stelle, die die neuen Tabellen liest, hat ihren bisherigen Weg behalten und fällt darauf zurück, wenn die
abgeleiteten Daten die Frage nicht sicher beantworten können:

| Situation | Was passiert |
|---|---|
| Booster ausgeschaltet (Standard nach dem Update) | Es wird überhaupt nichts Neues gelesen oder geschrieben; das Plugin verhält sich wie 2.11.3 |
| Booster gerade eingeschaltet, Index noch im Aufbau | Lesen weiter über `postmeta`, bis alle Beiträge übernommen sind |
| Zeile in `cb_availability` fehlt oder ist ungültig | Paar wird sofort berechnet und gespeichert, oder live gerechnet |
| Anfrage reicht über den Zeitraum von 94 Tagen hinaus (z. B. Tabelle mit 180 Tagen) | live gerechnet |
| Zeitrahmen nur für bestimmte Nutzerrollen buchbar | immer live gerechnet, weil das Ergebnis vom angemeldeten Nutzer abhängt |
| **Prüfung einer Buchung** (Überschneidung, Buchungsregeln) | **immer** über die ursprünglichen Abfragen, ohne Index, ohne Vorberechnung, ohne Cache |

Der letzte Punkt ist die wichtigste Sicherheitsgarantie: Selbst wenn der Index durch einen Fehler einmal nicht stimmen
sollte, kann daraus höchstens eine falsche **Anzeige** entstehen (die Karte zeigt einen Tag als frei), aber nie eine
Doppelbuchung. Die Entscheidung, ob eine Buchung angenommen wird, trifft immer der Originalcode auf Basis von
`wp_postmeta`.

Dass beide Wege dasselbe Ergebnis liefern, ist durch Tests abgesichert, die jede umgestellte Abfrage und jede Ansicht
einmal über den Booster und einmal über den Originalweg ausführen und vergleichen. Im Betrieb vergleichen tägliche
Stichproben gespeicherte Zeilen mit einer frischen Berechnung und reparieren Abweichungen.

#### 1.4.8 Ein Abruf von Anfang bis Ende: die Karte

Eine Besucherin öffnet am 06.11. die Karte, die für jeden Standort die Verfügbarkeit der nächsten zwölf Tage zeigt.
Angenommen, es gibt 40 Standorte mit zusammen 100 Paaren.

1. **Paare finden (Index).** Eine Abfrage auf `cb_index` liefert alle buchbaren Zeitrahmen der 40 Standorte
   (`type = 2`, `post_status = publish`, Ende nicht vor heute). Daraus ergeben sich die 100 Paare, darunter (812, 455).
2. **Zeilen laden (Vorberechnung).** Eine Abfrage lädt die 100 Zeilen aus `cb_availability`. Jede wird geprüft: Zähler
   gleich, Rechenversion aktuell, jünger als 24 Stunden, keine Rollenbeschränkung.
3. **Fenster bestimmen.** Für jedes Paar ermittelt `CalendarWindow` aus den buchbaren Zeitrahmen und dem heutigen Datum,
   welche Tage überhaupt buchbar sein können (Vorausbuchung, frühester Tag).
4. **Tage lesen.** Für die zwölf Tage vom 06.11. bis 17.11. liest der Booster die Codes an den Positionen 2, 4, …, 24
   der Zeile und wendet das Fenster an. Für (812, 455) ergibt das: 06.11. verfügbar, 07./08.11. kein Angebot, 09.11.
   verfügbar, **10.11. belegt**, 11.11. verfügbar, …
5. **Ausnahmen.** Ist eine der 100 Zeilen ungültig (etwa weil vor einer Minute gebucht wurde), wird nur dieses eine Paar
   berechnet (rund 13 ms) und gespeichert; die anderen 99 bleiben gelesen.

Zusammen sind das eine Handvoll Datenbankabfragen und wenige Millisekunden Rechenzeit, unabhängig davon, wie viele
Buchungen sich in den letzten Jahren angesammelt haben.

#### 1.4.9 Eine Änderung von Anfang bis Ende: die Buchung

Nutzerin 17 bucht das Lastenrad für Dienstag, 10.11. Die Buchungsprüfung läuft wie oben beschrieben ohne Booster. Wird
die Buchung angenommen und gespeichert, passiert Folgendes, alles innerhalb derselben Anfrage:

| Schritt | Tabelle | Was passiert |
|---|---|---|
| 1 | `wp_posts`, `wp_postmeta` | WordPress speichert die Buchung wie bisher: Beitrag 9301 mit Status `confirmed` und den Meta-Zeilen aus 1.4.1 |
| 2 | `cb_index` | WordPress meldet „Beitrag gespeichert“ (`save_post`). Der Booster liest den Beitrag und schreibt seine Zeile neu: `post_id 9301`, `type 6`, `item_id 812`, `location_id 455`, Start und Ende |
| 3 | `cb_availability` | Weil sich die Index-Zeile geändert hat, bestimmt der Booster die betroffenen Paare, hier nur (812, 455), und erhöht deren `gen`, zum Beispiel von 41 auf 42 |
| 4 | – | Die Antwort („Buchung bestätigt“) geht an den Browser |
| 5 | `cb_availability` | Danach rechnet der Booster das Paar neu: 94 Tage, rund 13 ms, und speichert die Codes mit `computed_gen = 42`. Der 10.11. trägt jetzt den Code 487 |
| 6 | – | Der nächste Kartenaufruf liest die aktuelle Zeile |

Wird eine Buchung später **verschoben** (anderer Artikel oder Standort), betrifft das zwei Paare: das alte und das neue.
Deshalb gibt der Index bei jeder Änderung die alte **und** die neue Zeile weiter, und beide Paare werden ungültig gemacht.
Wird eine Buchung **storniert**, setzt CommonsBooking den Status per direktem Datenbankbefehl; dort meldet der Booster die
Änderung selbst an den Index (siehe 7.1), damit Schritt 2 bis 5 ebenfalls ablaufen.

#### 1.4.10 Zusammengefasst: vier Regeln

1. **Die Ableitung ist eindeutig und wiederholbar.** Aus denselben Beiträgen entstehen immer dieselben Zeilen. Deshalb
   kann jede Zeile jederzeit gelöscht und neu erzeugt werden, und Prüfungen können eine gespeicherte Zeile mit einer
   frischen Berechnung vergleichen.
2. **Gespeichert wird nur, was nicht vom heutigen Datum abhängt.** Das Datum wird beim Lesen angewendet. So bleibt eine
   Zeile über Mitternacht gültig.
3. **Ungültig machen sofort, neu berechnen später.** Eine Änderung erhöht in derselben Anfrage den Zähler; eine Zeile mit
   abweichendem Zähler wird nie benutzt. Wann neu berechnet wird, beeinflusst nur die Geschwindigkeit, nicht die
   Richtigkeit.
4. **Im Zweifel die Quelle.** Wo die abgeleiteten Daten fehlen oder nicht passen, wird über den Originalweg gerechnet.
   Über Buchungen entscheidet immer der Originalweg.

### 1.5 Architektur

Ausführliche Grafik mit allen Methoden: [`booster-architektur.png`](booster-architektur.png) ([SVG](booster-architektur.svg)).

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

### 2.1 Überblick und Zusammenspiel

Alle Tabellen werden pro Site über `$wpdb->prefix` angelegt (Multisite-fähig), per `dbDelta()` erzeugt und tragen eine
eigene Schema-Versionsoption. Es gibt keine Fremdschlüssel und keine Trigger, die Pflege erfolgt in PHP über Hooks.
Angelegt werden die Tabellen beim Einschalten des Boosters, entfernt nur mit `wp commonsbooking booster purge`.

```
 wp_posts (cb_timeframe, cb_booking, cb_restriction)
   │ 1 : 1   post_id
   ▼
 cb_index ──────────── 1 : n ────────────► cb_index_rel
   │ item_id, location_id                    (nur Zeilen mit is_multi = 1:
   │ (nur Zeitrahmen Typ 2, publish)          entity_type item|location, entity_id)
   │ n : 1
   ▼
 cb_availability   PRIMARY (location_id, item_id)
```

| | `cb_index` | `cb_index_rel` | `cb_availability` |
|---|---|---|---|
| Eine Zeile je | Beitrag (Zeitrahmen, Buchung, Einschränkung) | Artikel bzw. Standort eines Beitrags mit Mehrfachauswahl | Artikel/Standort-Paar mit buchbarem Zeitrahmen |
| Zeilenzahl (Beispiel 17.100 Beiträge, 300 Paare) | ≈ 17.100 | wenige hundert | ≈ 300 |
| Inhalt | Fakten aus `wp_posts`/`postmeta`, typisiert | Zuordnungen | berechnete Tagesfakten + Slots |
| Geschrieben von | `Index\Projector` | `Index\Projector` | `Availability\Store` (über `Calculator`, `Invalidator`, `Maintenance`) |
| Gelesen von | `Repository\Index`, `Projector::load()` (alte Zeile für den Änderungs-Hook), `SlotReader`, `Maintenance`, `Guard`, `Verifier`, `Diagnostics` | `Repository\Index`, `Projector::load()` | `Availability\Reader`, `SlotReader`, `Maintenance` |
| Schema-Klasse, Version | `Index\Schema`, 3 | `Index\Schema`, 3 | `Availability\Schema`, 2 |
| Strukturänderung | `dbDelta` erweitert, Zeilen bleiben gültig | wie `cb_index` | Tabelle wird verworfen und neu angelegt (reiner Cache) |
| Inhaltliche Änderung der Ableitung | `Projector::PROJECTOR_VERSION` erhöhen → Neuaufbau | wie `cb_index` | `Calculator::VERSION` erhöhen → alte Zeilen ungültig |

Datentypen folgen zwei Regeln: Was gesucht oder verglichen wird, ist eine Zahl (Typ, IDs, Zeitstempel); Text gibt es nur
für Status, Wiederholungsart und Einschränkungsart. Und `NULL` hat in jeder Spalte eine feste Bedeutung (siehe Tabellen),
damit die SQL-Bedingungen die Semantik des alten `postmeta`-SQL exakt nachbilden.

### 2.2 `{prefix}cb_index`: eine Zeile pro Beitrag

Klasse: `Service\Index\Schema` (Option `commonsbooking_index_schema_version`), geschrieben von
`Service\Index\Projector::buildRow()`.

**Inhalt.** Eine Zeile für jeden Beitrag vom Typ `cb_timeframe`, `cb_booking` und `cb_restriction`, in **jedem**
Status außer `auto-draft`, also auch `draft`, `trash` und `canceled`. Die Status werden erst beim Lesen gefiltert, damit
eine Statusänderung nur eine Spalte ändert und der Wächter gelöschte oder stornierte Beiträge erkennt.

**Was die Zeile nicht ist:** keine Interpretation. Der Projector berechnet keine Tage, keine Verfügbarkeit und keine
Buchbarkeit. Er übersetzt Metawerte in Spalten und normalisiert nur dort, wo der Originalcode es beim Lesen ebenfalls
tut (z. B. `strtotime()` für Altwerte). Was der Index nicht weiß, entscheidet weiterhin der PHP-Code nach dem Laden der
Beiträge (Wochentage, `isBookable()`, Rollen).

#### Spalten nach Gruppen

**Identität und Status** (aus `wp_posts`)

| Spalte | Typ | NULL / Standard | Herkunft und Bedeutung |
|---|---|---|---|
| `post_id` | bigint unsigned, **PK** | – | `wp_posts.ID` |
| `post_type` | varchar(20) | – | `cb_timeframe`, `cb_booking` oder `cb_restriction` |
| `post_status` | varchar(20) | – | `publish`, `draft`, `pending`, `private`, `trash`; bei Buchungen `confirmed`, `unconfirmed`, `canceled` |
| `post_author` | bigint unsigned | 0 | bei Buchungen der Nutzer, **für den** gebucht wurde. Grundlage für Buchungsregeln und „Meine Buchungen“ (Schlüssel `author_type`) |

**Fachlicher Typ und Zuordnung** (aus den Metas `type`, `item-id`, `location-id`, `item-id-list`, `location-id-list`,
bei Einschränkungen `restriction-item-id`, `restriction-location-id`)

| Spalte | Typ | NULL / Standard | Herkunft und Bedeutung |
|---|---|---|---|
| `type` | tinyint unsigned | 0 | Meta `type`: 1 Öffnungszeiten, 2 buchbar, 3 Feiertag/geschlossen, 4 Off-Holidays, 5 Reparatur, 6 Buchung, 7 stornierte Buchung. **0 bei Einschränkungen** (die haben kein `type`-Meta) |
| `item_id` | bigint unsigned | **NULL** bei Mehrfachauswahl oder ohne Artikel | Zeitrahmen/Buchung: der einzige Artikel. Einschränkung: `restriction-item-id`, **0 = alle Artikel** |
| `location_id` | bigint unsigned | wie `item_id` | wie `item_id`, für den Standort |
| `is_multi` | tinyint(1) | 0 | 1, wenn nach Vereinigung von `*-id` und `*-id-list` mehr als ein Artikel **oder** mehr als ein Standort übrig bleibt. Dann sind `item_id` und `location_id` NULL und die Zuordnung steht in `cb_index_rel` |

**Zeitraum** (Metas `repetition-start`, `repetition-end` bzw. `restriction-start`, `restriction-end`)

| Spalte | Typ | NULL bedeutet | Regeln |
|---|---|---|---|
| `start_ts` | bigint (signed) | **kein Start gespeichert** | numerische Werte unverändert, Text per `strtotime()`. `0` ist ein gültiger Start. Abfragen mit `start_min`/`start_max` schließen NULL aus, wie das alte SQL, das den Meta-Schlüssel voraussetzte |
| `end_ts` | bigint (signed) | **offenes Ende** | `''`, `0`, `'0'` und fehlendes Meta ergeben NULL (wie `Model\Timeframe::getTimeframeEndDate()`). Abfragen schreiben `(end_ts IS NULL OR end_ts >= :von)` |

Die Zeitstempel sind „lokale Zeit als Unix-Zeitstempel“, so wie CommonsBooking sie speichert: 10.11.2026 00:00 Uhr
Ortszeit wird als `1794268800` abgelegt, also als wäre es 00:00 UTC. Der Index rechnet keine Zeitzonen um, damit alle
bestehenden Vergleiche gleich bleiben.

**Wiederholung und Raster** (nur Zeitrahmen und Buchungen; für Einschränkungen leer)

Diese Spalten werden nicht für die Suche gebraucht und derzeit von keinem Leseweg ausgewertet. Die Tagesberechnung
lädt nach der Suche weiterhin die `WP_Post`-Objekte samt `postmeta` (gebündelt über `Index::loadPosts()`) und nutzt die
bestehenden Modelle. Die Spalten werden trotzdem gepflegt und vom Verifier geprüft: Sie machen die Zeile *verdeckend*
(covering) und sind die Grundlage, später Wochentags- oder Rasterfilter direkt in SQL auszuführen, ohne das Schema
erneut zu ändern.

| Spalte | Typ | Herkunft | Bedeutung, Beispiel |
|---|---|---|---|
| `repetition` | varchar(8), NULL | `timeframe-repetition` | `norep` (einmalig), `d` (täglich), `w` (wöchentlich), `m`, `y`, `manual`. NULL = nicht gesetzt |
| `weekdays` | tinyint unsigned | `weekdays` (Array `'1'`…`'7'`, Montag = 1) | Bitmaske, Montag = Bit 0 … Sonntag = Bit 6. Mo–Fr = `0b0011111` = 31, Sa+So = 96, alle = 127 |
| `manual_dates` | text, NULL | `timeframe_manual_date` (kommagetrennt) | JSON-Array, nur bei `repetition = manual`, z. B. `["2026-12-01","2026-12-08"]` |
| `grid` | tinyint unsigned | `grid` | 0 = Slot (ein Zeitfenster Start–Ende), 1 = stündlich |
| `full_day` | tinyint(1) | `full-day` | 1, wenn Meta = `on` |
| `start_time`, `end_time` | time, NULL | `start-time`, `end-time` (`8:00 AM`, `18:00` …) | normalisiert auf `H:i:s` |
| `grid_size` | tinyint unsigned, NULL | abgeleitet | wie `Model\Timeframe::getGridSize()`: ganztägig 24; Slot = Stunden zwischen Start- und Endzeit (gerundet, höchstens 255); sonst Wert von `grid`. NULL, wenn bei Slot eine Zeit fehlt |

**Buchungsregeln** (nur Zeitrahmen)

| Spalte | Typ | Herkunft | Bedeutung |
|---|---|---|---|
| `max_days` | smallint unsigned, NULL | `timeframe-max-days` | maximale Buchungsdauer in Tagen |
| `advance_days` | smallint unsigned, NULL | `timeframe-advance-booking-days` | Vorausbuchungsfenster |
| `startday_offset` | smallint unsigned, NULL | `booking-startday-offset` | frühester Buchungstag ab heute |
| `has_role_limit` | tinyint(1) | `allowed_user_roles` | 1, wenn der Zeitrahmen nur für bestimmte Rollen buchbar ist. Solche Paare werden immer live berechnet |

Numerische Werte werden auf 0…65535 begrenzt, nicht numerische ergeben NULL („nicht gesetzt“). Die Auswertung bleibt im
PHP-Code: z. B. gelten 0 Vorausbuchungstage dort als „nicht gesetzt“.

**Einschränkungen** (nur `cb_restriction`)

| Spalte | Typ | Herkunft | Bedeutung |
|---|---|---|---|
| `restriction_type` | varchar(10), NULL | `restriction-type` | `repair` (sperrt den Artikel), `hint` (nur Hinweis) |
| `restriction_state` | varchar(10), NULL | `restriction-state` | `none`, `active`, `solved`. `Repository\Restriction::get()` liest nur `active` |

**Verwaltung** (nicht Teil des Vergleichs, ob sich eine Projektion geändert hat)

| Spalte | Typ | Bedeutung |
|---|---|---|
| `source_modified_gmt` | datetime, NULL | `post_modified_gmt` zum Zeitpunkt der Projektion. Der Wächter vergleicht stündlich mit `wp_posts` und erkennt so Änderungen, die eine Version ohne Booster gemacht hat |
| `projector_version` | smallint unsigned | `Projector::PROJECTOR_VERSION` (derzeit 2), mit der die Zeile gebaut wurde |

#### Zuordnung Artikel/Standort im Detail

Der Projector bildet die **Vereinigung** aller Werte von `item-id` (auch mehrfach vorhandene Meta-Zeilen) und der Liste
`item-id-list`, entfernt Werte ≤ 0 und Duplikate und sortiert. Ebenso für Standorte. Das entspricht dem bisherigen SQL
in `Repository\Timeframe::getEntityQuery()`, das beide Metas per `OR` verknüpfte.

| Ergebnis | `item_id` | `location_id` | `is_multi` | `cb_index_rel` |
|---|---|---|---|---|
| ein Artikel, ein Standort (Buchungen, buchbare Zeitrahmen, über 99 %) | ID | ID | 0 | – |
| kein Artikel oder kein Standort (unvollständiger Entwurf) | NULL | NULL / ID | 0 | – |
| mehr als ein Artikel **oder** Standort (Feiertage, Reparaturen mit Mehrfach-, Kategorie- oder „Alle“-Auswahl) | NULL | NULL | 1 | je Artikel und Standort eine Zeile |
| Einschränkung | ID oder **0 = alle** | ID oder **0 = alle** | 0 | – |

#### Beispielzeilen (gekürzt)

| post_id | post_type | post_status | type | item_id | location_id | is_multi | start_ts | end_ts | weitere |
|---|---|---|---|---|---|---|---|---|---|
| 501 | cb_timeframe | publish | 2 | 812 | 455 | 0 | 1767225600 (01.01.2026) | NULL (offen) | `repetition = w`, `weekdays = 31`, `full_day = 1`, `grid_size = 24`, `advance_days = 60` |
| 9301 | cb_booking | confirmed | 6 | 812 | 455 | 0 | 1794268800 (10.11.2026 00:00) | 1794355199 (10.11.2026 23:59:59) | `post_author = 17` |
| 9302 | cb_booking | canceled | 6 | 812 | 455 | 0 | 1794441600 | 1794700799 | bleibt im Index, wird über `post_status` herausgefiltert |
| 620 | cb_timeframe | publish | 3 | NULL | NULL | 1 | 1798070400 (24.12.) | 1798329599 (26.12.) | Feiertag für 3 Standorte, siehe `cb_index_rel` |
| 7004 | cb_restriction | publish | 0 | 812 | 0 | 0 | 1794268800 | NULL | `restriction_type = repair`, `restriction_state = active` (alle Standorte des Artikels) |

#### Schlüssel und wofür sie gebraucht werden

| Schlüssel | Spalten | Genutzt von |
|---|---|---|
| `PRIMARY` | `post_id` | Projektion (`REPLACE`), Wächter, Verifier |
| `item_loc_range` | `item_id, location_id, type, end_ts, start_ts` | Paar-Abfragen (Kalender, Karte, Konfliktprüfung im Indexpfad, `SlotReader::getPairs()`). Durch `end_ts` an vierter Stelle springt MySQL direkt zu den Beiträgen, die nach dem Anfang des gesuchten Zeitraums enden: Die Laufzeit hängt von der Zahl der aktuellen, nicht aller historischen Buchungen ab |
| `loc_range` | `location_id, type, end_ts` | Abfragen nur nach Standort |
| `type_range` | `type, end_ts, start_ts` | Abfragen ohne Artikel/Standort (z. B. Erinnerungs-Mails: beginnende/endende Buchungen eines Tages) |
| `author_type` | `post_author, type, start_ts` | Buchungen eines Nutzers (Buchungsregeln, „Meine Buchungen“) |
| `multi_type` | `is_multi, type` | zweiter Teil der `UNION ALL` für Mehrfachzuordnungen |
| `ptype_item`, `ptype_location` | `post_type, item_id` / `post_type, location_id` | Zuordnung Artikel ↔ Standort (`getRelatedPosts`), Einschränkungen, Paarliste |
| `ptype_end` | `post_type, end_ts` | Einschränkungen nach Zeitraum |

### 2.3 `{prefix}cb_index_rel`: Mehrfachzuordnungen

| Spalte | Typ | Bedeutung |
|---|---|---|
| `post_id` | bigint unsigned | Beitrag mit `is_multi = 1` |
| `entity_type` | varchar(8) | `item` oder `location` |
| `entity_id` | bigint unsigned | ID des Artikels bzw. Standorts |

Schlüssel: `PRIMARY (entity_type, entity_id, post_id)` für die Suche „alle Beiträge zu Artikel X“, `KEY post_id` für das
Ersetzen und Löschen der Zuordnungen eines Beitrags. Beim Schreiben werden alle Zeilen des Beitrags gelöscht und neu
eingefügt (`INSERT IGNORE`).

Beispiel Feiertag 620 (alle Artikel 812, 813 an drei Standorten):

| post_id | entity_type | entity_id |
|---|---|---|
| 620 | item | 812 |
| 620 | item | 813 |
| 620 | location | 455 |
| 620 | location | 456 |
| 620 | location | 457 |

Warum eine eigene Tabelle statt einer Zeile pro Paar: Ein Feiertag „alle Artikel × alle Standorte“ (500 × 300) bräuchte
150.000 Zeilen in der Haupttabelle, die Relationstabelle braucht 800. Buchungen und buchbare Zeitrahmen kommen ohne Join
aus. Abfragen kombinieren beide Fälle mit `UNION ALL` (nicht `OR`), damit jeder Teil seinen Schlüssel nutzt:

```sql
SELECT i.post_id FROM cb_index i
 WHERE i.is_multi = 0 AND i.item_id IN (:items) AND i.location_id IN (:locs)
   AND i.type IN (:types) AND i.post_status IN (:status)
   AND (i.end_ts IS NULL OR i.end_ts >= :from) AND i.start_ts <= :to
UNION ALL
SELECT i.post_id FROM cb_index_rel ri
  JOIN cb_index_rel rl ON rl.post_id = ri.post_id AND rl.entity_type = 'location' AND rl.entity_id IN (:locs)
  JOIN cb_index i      ON i.post_id  = ri.post_id
 WHERE ri.entity_type = 'item' AND ri.entity_id IN (:items)
   AND i.is_multi = 1 AND i.type IN (:types) …
```

### 2.4 `{prefix}cb_availability`: vorberechneter Tageszustand pro Paar

Klasse: `Service\Availability\Schema` (Option `commonsbooking_availability_schema_version`), geschrieben ausschließlich
über `Service\Availability\Store`.

**Inhalt.** Eine Zeile je Artikel/Standort-Paar, das einen veröffentlichten buchbaren Zeitrahmen (Typ 2) hat, der nicht
vor dem Horizont endet (`Maintenance::getBookablePairs()`, Quelle ist `cb_index`). Die Zeile beschreibt die Tage
**gestern bis heute + 92**, also 94 Tage (`Availability::getHorizon()`, Filter
`commonsbooking_availability_horizon_days`), mit einem Tag Puffer nach hinten.

Die Tabelle ist ein reiner Cache: Jede Zeile kann jederzeit gelöscht werden, sie entsteht beim nächsten Lesen neu.

#### Spalten

| Spalte | Typ | Standard | Bedeutung |
|---|---|---|---|
| `item_id`, `location_id` | bigint unsigned, **PK** `(location_id, item_id)` | – | das Paar. Standort zuerst, weil Karte und Tabelle standortweise laden |
| `gen` | int unsigned | **1** | Generation. Wird bei **jeder** Änderung eines Beitrags, der das Paar betrifft, synchron um 1 erhöht (`Store::invalidate()`) |
| `computed_gen` | int unsigned | **0** | Generation, aus der `days` und `slots` berechnet wurden. Neue Zeilen sind dadurch zunächst ungültig |
| `days_from` | date, NULL | – | Datum des ersten Eintrags in `days` (am Berechnungstag: gestern) |
| `days` | varbinary(1024), NULL | – | Tagesfakten, **2 Byte je Tag** ab `days_from` (unsigned short, little endian). 94 Tage = 188 Byte, Platz für 512 Tage. NULL bei Rollenbeschränkung |
| `slots` | mediumblob, NULL | – | freie buchbare Slots je Tag für API und GBFS (`Slots::pack()`, siehe unten). NULL bei Rollenbeschränkung |
| `has_role_limit` | tinyint(1) | 0 | 1 = mindestens ein buchbarer Zeitrahmen des Paares ist auf Rollen beschränkt. Dann werden keine Tage gespeichert, weil das Ergebnis vom Nutzer abhängt |
| `computed_at` | datetime (UTC), NULL | – | Zeitpunkt der Berechnung (`gmdate()`) |
| `calc_version` | smallint unsigned | 0 | `Calculator::VERSION` (derzeit 2) |

Schlüssel: `PRIMARY (location_id, item_id)`, `KEY item_id` (Invalidierung nur nach Artikel, z. B. Einschränkung für alle
Standorte), `KEY computed_at` (Cron sucht die ältesten Zeilen).

`days` und `slots` werden als `UNHEX('…')` geschrieben, damit die SQL-Anweisung reiner ASCII-Text bleibt: `wpdb`
entfernt sonst Bytes, die es für ungültigen Text hält. `Store::preload()` lädt `slots` nicht mit, weil Karte und Tabelle
sie nicht brauchen; nur `Store::loadWithSlots()` (für `SlotReader`) liest die Spalte.

#### Lebenszyklus einer Zeile

| Zustand | `gen` / `computed_gen` | `days` | Wie erreicht | Beim Lesen |
|---|---|---|---|---|
| **angelegt** | 1 / 0 | NULL | `Maintenance::syncPairs()` (stündlich) oder `Store::getGen()` vor der ersten Berechnung, jeweils `INSERT IGNORE` | ungültig → berechnen |
| **gültig** | n / n | gesetzt | `Store::write()` nach `Calculator::compute()` | Tagesfakten aus `days` |
| **invalidiert** | n+1 / n | alt | `Store::invalidate()` bei einer Änderung | ungültig → berechnen |
| **gealtert** | n / n | gesetzt, `computed_at` > 24 h | Zeit vergeht (Cron rechnet ab 12 h nach) | ungültig → berechnen |
| **veraltete Rechenversion** | n / n | gesetzt, `calc_version` alt | Plugin-Update mit neuer `Calculator::VERSION` | ungültig → berechnen |
| **rollenbeschränkt** | n / n | NULL, `has_role_limit = 1` | Berechnung stellt Rollenbeschränkung fest | sofort live, ohne Neuberechnung |
| **entfernt** | – | – | `syncPairs()`: Paar hat keinen buchbaren Zeitrahmen mehr | live (meist nichts anzuzeigen) |

`Store::invalidate()` erhöht nur vorhandene Zeilen. Bekommt ein Paar erstmals einen buchbaren Zeitrahmen, gibt es noch
keine Zeile: Der erste Leser legt sie über `Calculator::recompute()` an, spätestens der stündliche `syncPairs()`.

#### Gültigkeit einer Zeile (`Store::isUsable()`)

Eine Zeile wird nur verwendet, wenn **alle** Bedingungen gelten:

1. `days` und `days_from` sind gesetzt,
2. `has_role_limit = 0`,
3. `computed_gen = gen` (keine Änderung seit der Berechnung),
4. `calc_version = Calculator::VERSION`,
5. `computed_at` ist höchstens 24 Stunden alt (Filter `commonsbooking_availability_max_age`).

Zusätzlich prüft `Store::getFlags()` je Tag, ob das Datum in `days` liegt. Liegt ein angefragter Tag davor oder dahinter,
rechnet der `Reader` das ganze Paar live.

Damit hängt die Korrektheit **nicht** davon ab, wann neu berechnet wird: Eine veraltete Zeile wird nie ausgeliefert.

#### Gleichzeitige Änderungen ohne Sperren

`Calculator::recompute()` liest vor dem Rechnen die Generation `G` und schreibt nur, wenn sie noch gilt:

```sql
UPDATE cb_availability
   SET computed_gen = gen, days_from = ?, days = UNHEX('…'), slots = UNHEX('…'),
       has_role_limit = ?, computed_at = ?, calc_version = ?
 WHERE location_id = ? AND item_id = ? AND gen = G
```

| Zeit | Anfrage A (rechnet) | Anfrage B (bucht) | Zeile |
|---|---|---|---|
| t1 | liest `gen = 42` | | 42 / 41 |
| t2 | rechnet (ohne die neue Buchung) | speichert Buchung, `gen = gen + 1` | 43 / 41 |
| t3 | `UPDATE … WHERE gen = 42` trifft **nichts** | | 43 / 41 (ungültig) |
| t4 | | `shutdown`: liest `gen = 43`, rechnet, schreibt | 43 / 43 (gültig, mit Buchung) |

Ohne die Bedingung `gen = G` hätte A in t3 ein Ergebnis ohne die neue Buchung als gültig markiert.

#### Spalte `days`: Tagesfakten (`Service\Availability\DayFacts`)

Für jeden Tag baut der Kalender wie bisher das Slot-Raster (`Day::getGrid()`): pro Zeitabschnitt der Zeitrahmen mit der
höchsten Priorität (Buchung und Feiertag vor buchbar). `DayFacts::fromGrid()` verdichtet dieses Raster auf zehn Bits,
genau nach den Regeln des früheren `View\Calendar::processSlot()`:

| Bit | Wert | Konstante | gesetzt, wenn … |
|---|---|---|---|
| 0 | 1 | `HAS_SLOTS` | mindestens ein Slot einen Zeitrahmen hat |
| 1 | 2 | `SLOT_LOCKED` | mindestens ein Slot gesperrt ist (Feiertag, Reparatur, Buchung, Einschränkung) oder für den Nutzer nicht buchbar ist |
| 2 | 4 | `ALL_LOCKED` | kein Slot wählbar ist (auch ohne Slots) |
| 3 | 8 | `HOLIDAY` | alle Slots Feiertage (Typ 3 oder 4) sind (auch ohne Slots) |
| 4 | 16 | `REPAIR` | Altlast des Originals: wird durch jeden Slot zurückgesetzt, weil dort Text mit Zahl strikt verglichen wird. Praktisch nur ohne Slots gesetzt |
| 5 | 32 | `BOOKED_DAY` | alle Slots Buchungen oder Reparaturen sind (auch ohne Slots) |
| 6 | 64 | `PARTIALLY_BOOKED` | mindestens ein Slot eine Buchung, ein Feiertag oder eine Reparatur ist |
| 7 | 128 | `FIRST_SLOT_BOOKED` | der erste Slot nicht buchbar ist |
| 8 | 256 | `LAST_SLOT_BOOKED` | der letzte Slot nicht buchbar ist |
| 9 | 512 | `FULL_DAY` | es genau einen Slot gibt und dessen Zeitrahmen ganztägig ist |

Bits 10 bis 15 sind frei. Typische Werte:

| Tag | Bits | Wert | Bytes in `days` |
|---|---|---|---|
| kein Zeitrahmen | `ALL_LOCKED`, `HOLIDAY`, `REPAIR`, `BOOKED_DAY` | 60 | `3C 00` |
| ganztägig buchbar und frei | `HAS_SLOTS`, `FULL_DAY` | 513 | `01 02` |
| ganztägig gebucht | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `BOOKED_DAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (kein `FULL_DAY`: Buchungen haben kein Meta `full-day`) | 487 | `E7 01` |
| Feiertag | `HAS_SLOTS`, `SLOT_LOCKED`, `ALL_LOCKED`, `HOLIDAY`, `PARTIALLY_BOOKED`, `FIRST_…`, `LAST_SLOT_BOOKED` (+ `FULL_DAY`) | 463 (975) | `CF 01` (`CF 03`) |

Der Wert „kein Zeitrahmen“ zeigt, warum die Bits nicht allein gelesen werden dürfen: Ohne Slots sind `HOLIDAY` und
`BOOKED_DAY` gesetzt. `CalendarWindow::mapDay()` setzt in diesem Fall wie das Original alles auf „gesperrt“ zurück.

Ein Tag wird so gelesen: Versatz = Tage zwischen `days_from` und Datum, dann `unpack('v', $days, Versatz × 2)`.

**Was nicht gespeichert wird** und beim Lesen von `CalendarWindow` angewendet wird:

| Regel | Quelle beim Lesen |
|---|---|
| Tag liegt in der Vergangenheit | heutiges Datum |
| Tag liegt außerhalb des Vorausbuchungsfensters (`advance_days`) | buchbare Zeitrahmen + heutiges Datum |
| Tag liegt vor dem ersten buchbaren Tag (`startday_offset`) | buchbare Zeitrahmen + heutiges Datum |
| Tag liegt nach dem Ende des angefragten Bereichs | Parameter der Anfrage |
| Status für Karte/Tabelle (`no-slots`, `holiday`, `booked`, `partially-booked-start/-end`, `locked`, `available`) | `DayStatus::fromDayArray()` |
| Slots mit Uhrzeiten für den Buchungskalender der Artikelseite | wird dort weiter live berechnet |

Deshalb muss beim Tageswechsel nichts neu berechnet werden. Eine Ausnahme beschreibt 12.2 (`isBookable()`).

#### Spalte `slots`: freie Slots für API und GBFS (`Service\Availability\Slots`)

Aus demselben Raster sammelt `Slots::bookableSlots()` die Slots, deren höchstpriorer Zeitrahmen buchbar ist (Typ 2),
also weder gebucht noch gesperrt. Das ist genau die Bedingung, die die Live-Methode schon immer verwendet hat.
`Slots::pack()` speichert sie little endian:

```
Kopf     uint16  T = Anzahl der vorkommenden Zeitrahmen
         T ×     uint32 Zeitrahmen-ID, uint32 Standort-ID, uint32 Artikel-ID   (0 = keiner)
je Tag   uint8   S = Anzahl Slots (höchstens 255)
         S ×     uint32 Start, uint32 Ende (Sekunden ab Tagesbeginn), uint16 Index in der Kopfliste
```

Die Tage stehen lückenlos ab `days_from`, ohne eigenes Datum. Beispiel ganztägig buchbarer Zeitrahmen 501, ein Tag frei,
ein Tag gebucht:

```
01 00                          T = 1
F5 01 00 00  C7 01 00 00  2C 03 00 00     Zeitrahmen 501, Standort 455, Artikel 812
01  00 00 00 00  7F 51 01 00  00 00         Tag 1: 1 Slot, 0 … 86399 s, Zeitrahmen-Index 0
00                                          Tag 2: 0 Slots (gebucht)
```

Größe: 14 Byte Kopf plus je Tag 1 Byte plus 10 Byte je Slot. Ganztägig über 94 Tage ≈ 1 KB, stündliches Raster mit
10 Slots ≈ 9,5 KB. `SlotReader` entpackt, wendet `getFirstBookableDay()` (datumsabhängig) an und formt die Slots mit
`Slots::toApiSlot()` in das API-Format um.

### 2.5 Neue Optionen und Cron-Ereignisse

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
