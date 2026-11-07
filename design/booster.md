# Performance-Booster: abschaltbar und downgrade-fest

*English version: [booster.en.md](booster.en.md)*

Stand: 06.11.2026. Ergänzt `timeframe-index.md` (Stufe 2) und `availability-precompute.md` (Stufe 3). Maßgeblich für
den aktuellen Stand ist [`technische-dokumentation-booster-02.md`](technische-dokumentation-booster-02.md); diese Notiz hält die Entscheidungen und Tests zu
Schalter, Wächter und Downgrade fest.

## Ziel

Index und vorberechnete Verfügbarkeit sollen sich als eine Option an- und abschalten lassen. Ausgeschaltet läuft
CommonsBooking exakt wie die Version ohne diese Erweiterungen. Ein Update auf die Booster-Version soll sich auf eine
Vorgängerversion zurücksetzen lassen und danach wieder einspielen, ohne dass falsche Verfügbarkeiten angezeigt oder
Doppelbuchungen möglich werden.

## Entscheidungen

| Frage | Entscheidung | Grund |
|---|---|---|
| Standardwert | **aus** | Nach dem Update ändert sich nichts, bis jemand den Booster bewusst einschaltet. |
| Ort des Schalters | Einstellungen → Erweitert → „Performance booster“ (Feld `booster_enabled` in `commonsbooking_options_advanced-options`), dazu Filter `commonsbooking_booster_enabled` und WP-CLI | Passt zu den übrigen Cache-Einstellungen. |
| Was passiert bei „aus“ | keine Hooks, keine Cron-Jobs, kein Lesen aus den Tabellen. Index gilt als veraltet, Tabellen bleiben (Entfernen mit `purge`). | Ausgeschaltet identisch zum Originalcode. Wiedereinschalten braucht keinen neuen Tabellenaufbau, nur ein Neubefüllen. |
| Einschalten | Tabellen anlegen, Index **immer komplett neu aufbauen**; bis zum Ende Originalabfragen | Während der Booster aus war, hat niemand den Index gepflegt. |
| Buchungsprüfung | läuft **immer** mit den Originalabfragen und ohne Plugin-Cache (`Booster::withoutBooster()`): Buchungsformular, Admin-Buchung, Prüfung von Zeitrahmen | Ein veralteter Index kann so höchstens eine falsche Anzeige verursachen, nie eine Doppelbuchung. Der Symfony-Cache wird ebenfalls umgangen, weil er Ergebnisse enthalten kann, die aus dem Index stammen. Eine Prüfung betrifft ein Paar und bleibt schnell. |
| Erkennen eines Downgrades | Wächter (`Guard`) mit drei Prüfungen, siehe unten | Die alte Version hinterlässt keine Markierung, die neue muss die Folgen in den Daten erkennen. |

## Wächter

| Prüfung | Wann | Erkennt | Folge |
|---|---|---|---|
| Plugin-Version | jede Anfrage (autoloaded Option) | Die Upgrade-Routine einer anderen Version hat `commonsbooking_plugin_version` geändert. Eigene Änderungen merkt sich der Booster über `update_option_…`. | Neuaufbau |
| Neue Beiträge ohne Indexzeile | jede Anfrage, nur Beiträge oberhalb der zuletzt geprüften ID (Primärschlüssel, billig). Erst nach 2 Minuten, damit parallele Anfragen ihre Beiträge noch eintragen können. | Buchungen, Zeitrahmen, Einschränkungen, die eine andere Version angelegt hat | Neuaufbau |
| Status und Änderungsdatum | stündlich und `wp commonsbooking booster check --deep` (Join über alle Beiträge der drei Typen) | Stornierungen und Änderungen durch eine andere Version, gelöschte Beiträge | einzelne Beiträge reparieren, ab 200 Neuaufbau |

Grenzen:

- Hat die alte Version nur Metadaten geändert, ohne den Beitrag zu speichern, findet das erst die tägliche
  Stichprobe des Index-Verifiers. Für die Buchungsprüfung spielt das keine Rolle (Originalabfragen).
- Stornierungen der alten Version (direktes SQL, ohne Änderungsdatum) erkennt die stündliche Prüfung über den Status.
  Bis dahin erscheint der Tag höchstens noch als belegt, buchen lässt er sich schon.
- Trägt die Booster-Version dieselbe Versionsnummer wie die Vorgängerversion, greift die erste Prüfung nicht; die
  beiden anderen decken das ab. Deshalb trägt der Branch eine eigene Nummer (`2.11.4-beta.3` gegenüber 2.11.3), damit
  der Wechsel zwischen beiden Versionen schon an der Versionsoption erkannt wird. Beispiel: Wird 2.11.3 eingespielt,
  setzt deren Upgrade-Routine `commonsbooking_plugin_version` auf `2.11.3`; beim Zurückwechseln sieht der Wächter
  `2.11.3 ≠ 2.11.4-beta.3`, das die Booster-Version zuletzt selbst geschrieben hat, und baut den Index neu auf.

## Empfohlener Ablauf für ein Downgrade

1. Booster ausschalten (Einstellungen oder `wp commonsbooking booster disable`).
2. Ältere Version einspielen. Sie ignoriert die Tabellen `cb_index`, `cb_index_rel` und `cb_availability`, die Optionen
   und die geplanten Cron-Ereignisse (ohne zugehörigen Code führt WordPress sie nicht aus).
3. Später neue Version einspielen und Booster wieder einschalten. Der Index wird neu aufgebaut.

Wird Schritt 1 vergessen, fängt der Wächter das beim Wiedereinspielen ab. Vollständig entfernen:
`wp commonsbooking booster purge`.

## Messwerte

- Entwicklungsumgebung (rund 167.000 Beiträge): Einschalten mit `--now`, Index 23,9 s, Verfügbarkeit für 100 Paare 1,3 s.
- Testumgebung: Index 0,1 s, 15 Paare 0,2 s. Ausgeschaltet lädt die Seite „Klassische Ansichten“ in 213 ms mit
  1190 Datenbankabfragen, eingeschaltet in rund 116 ms mit 48.

## Downgrade-Test in der Testumgebung

Skript `dev-bench/downgrade-scenario.sh`. Dafür liegt die offizielle Version 2.11.3 von wordpress.org im Container unter
`wp-content/plugins/commonsbooking-upstream`. Das Skript tauscht das aktive Plugin über die Option `active_plugins`, also
ohne Aktivierungs-Hooks, wie bei einem Austausch der Dateien.

| Durchlauf | Mit der alten Version | Ergebnis nach dem Zurückwechseln |
|---|---|---|
| `both` | Buchung 501 storniert, Buchung 660 angelegt | Erste Seite: Wächter meldet „post 660 was created without the booster“, Neuaufbau. 17.11. frei, 26.11. gebucht, 60 Tage vorberechnet = live, Index und Verfügbarkeit ohne Abweichung. |
| `cancel` | Buchung 502 storniert | Schnelle Prüfungen ohne Befund (kein neuer Beitrag). Tiefenprüfung findet und repariert 502. 24.11. frei, vorberechnet = live. |

Zusätzlich über das Buchungsformular als `testnutzer`: Der 26.11. wurde abgelehnt („Es gibt bereits eine Buchung…“),
der 24.11. ließ sich buchen. Danach wurde die Indexzeile von Buchung 660 absichtlich gelöscht (Index veraltet, gilt
aber als vollständig). Die erneute Buchung des 26.11. wurde trotzdem abgelehnt, weil die Prüfung die Originalabfragen
nutzt. Die Tiefenprüfung hat die Zeile anschließend wiederhergestellt.

Ein- und Ausschalten über die Einstellungsseite: Aus entfernt die Cron-Jobs und schaltet auf die Originalabfragen um.
Ein baut den Index bei den nächsten Seitenaufrufen neu auf.

## Tests

- `tests/php/Service/Booster/BoosterTest.php` (13 Tests): Schalter, Umgehung bei der Buchungsprüfung (auch mit einer
  Buchung, die im Index fehlt), Ein-/Ausschalten, alle drei Wächter-Prüfungen, Wartezeit für parallele Anfragen,
  `purge`.
- Suite in vier Modi: Booster an (555 Tests grün, Stand 06.11.2026), `CB_USE_BOOSTER=0` (Index- und
  Verfügbarkeitstests übersprungen),
  `CB_USE_INDEX=0`, `CB_USE_AVAILABILITY=0`. Der zusätzliche CI-Lauf mit ausgeschaltetem Booster ist in
  `.github/workflows/phpunit.yml` eingetragen.

## Übersetzung

Die Texte der Einstellungsseite, die Statuswerte und die Gründe für einen Neuaufbau sind übersetzbar (WP-CLI-Ausgaben
bleiben englisch). `languages/commonsbooking.pot` wurde wie in `bin/update-pot.sh` mit `wp i18n make-pot` erzeugt
(ohne `dev-bench`, `testenv`, `design`), die deutsche `.po` mit `wp i18n update-po` abgeglichen und ergänzt, die `.mo`
mit `wp i18n make-mo` erzeugt. Das Plugin lädt die eigene `.mo` vor dem Sprachpaket von wordpress.org, die
Übersetzungen wirken also sofort.

## Symfony-Cache und Booster

Alle Messwerte des Boosters stammen aus Umgebungen mit `WP_DEBUG`, also ohne den Plugin-Cache. Karte und Tabelle
brauchen ihn mit Booster nicht mehr. Er puffert aber weiterhin Repository-Abfragen, Kalenderdaten der Artikelseite
und Listen. API und GBFS lesen inzwischen ebenfalls aus der Vorberechnung (technische Dokumentation 6.6), außer bei
den Live-Rückfällen dort; für diese und für die Artikelseite bleibt der Cache nützlich. Er bleibt deshalb
eingeschaltet. Nur die Buchungsprüfung umgeht ihn.

### Messung mit und ohne Plugin-Cache (06.11.2026)

Gemessen in der Entwicklungsumgebung mit 167.000 Buchungen und 100 Stationen (`dev-bench/cache-measure.sh`,
Ergebnis in `dev-bench/cache-measure.txt`). Angegeben ist die Antwortzeit in Sekunden: bei leerem Cache, bei
warmem Cache (Median aus 3) und direkt nach einer neuen Buchung.

| Anfrage                         | Booster, ohne Cache | Booster, Cache leer / warm | ohne Booster, ohne Cache |
|---------------------------------|--------------------:|---------------------------:|-------------------------:|
| Kartendaten (Ajax)              |                0,11 |                0,26 / 0,08 |     Abbruch (Speicher)    |
| `[cb_map]` + `[cb_items_table]` |                0,10 |                0,15 / 0,06 |     Abbruch (Speicher)    |
| `[cb_items]`                    |                0,13 |                0,14 / 0,11 |                     33,6 |
| `[cb_locations]`                |                0,12 |                0,10 / 0,12 |                     33,3 |
| Artikelseite mit Kalender       |                0,06 |                0,07 / 0,04 |                      2,3 |

Ergebnis: Mit Booster bringt der Cache nur noch wenige Hundertstelsekunden, das erste Füllen kostet etwas mehr.
Er schadet nicht und bleibt eingeschaltet. Ohne Booster ist er weiterhin nötig.

### Cache-Schlüssel und Benutzerrollen

Zeitrahmen können auf Benutzerrollen beschränkt sein (leeres Feld: alle dürfen buchen). Karte, Tabellen und Kalender
zeigen solche Artikel deshalb je nach Benutzer unterschiedlich an. Der Booster rechnet diese Paare immer live
(`has_role_limit`). Der Plugin-Cache hat den Benutzer aber bisher nicht im Schlüssel, ein Gast konnte also
die Anzeige eines Admins aus dem Cache bekommen und umgekehrt. Das ist ein Fehler, den es schon vor dem Booster gab.
Jetzt enthält `Cache::getCacheId()` den Rollenkontext (Gast, sortierte Rollen, Admin-Kennzeichen). Benutzer mit
denselben Rollen teilen sich weiter einen Eintrag. Die Buchungsprüfung selbst nutzt den Cache nicht.

## Diagnose auf der Einstellungsseite

Unter Einstellungen → Erweitert → Performance-Booster (`SettingsView`, Daten aus `Diagnostics`):

- **Zustand** (grün/gelb/rot) mit konkreten Hinweisen: Index im Aufbau, Anzahl Beiträge ≠ Indexzeilen, Buchung mit
  abweichendem Status, alte vorberechnete Zeilen, fehlende oder überfällige Cron-Aufgaben, Reparaturen der letzten
  Tiefenprüfung.
- **Index:** Balken je Typ (Zeitrahmen, Buchungen, Einschränkungen), neueste Änderung in WordPress und im Index.
- **Vorberechnete Verfügbarkeit:** aktuelle, geänderte, alte und rollenbeschränkte Paare.
- **Letzte Buchungen:** zuletzt angelegt, geändert oder storniert (Stornozeit wird berücksichtigt), Status in WordPress
  und im Index nebeneinander.
- **Letzte Index-Aktivität:** Ringpuffer der letzten 30 Änderungen (Option `commonsbooking_booster_activity`, nicht
  autoload), auch Löschungen, mit Herkunft (Website, Verwaltung, Cron, WP-CLI) und erneuter Prüfung des aktuellen
  Stands.
- **Prüfungen und geplante Aufgaben:** letzter Lauf, Ergebnis und nächster Lauf (Option `commonsbooking_booster_checks`).
- Knöpfe **Jetzt alle Prüfungen ausführen** (Wächter, Tiefenprüfung, Stichproben, repariert Abweichungen) und
  **Index neu aufbauen**.

Alle Zeiten kommen aus den GMT-Spalten bzw. Unix-Zeitstempeln und werden mit `wp_date()` in der Zeitzone der
WordPress-Einstellungen angezeigt. Ausnahme: CommonsBooking speichert `cancellation_time` als lokale Zeit
(`current_time( 'timestamp' )`), das wird umgerechnet. Wird die Zeitzone in WordPress geändert, behalten ältere
Beiträge ihre korrekten GMT-Zeiten. Nur die lokale Spalte (`post_date`) älterer Beiträge bleibt in der alten Zeitzone.

Tests: `tests/php/Service/Booster/DiagnosticsTest.php`, `CacheRoleContextTest.php`.

## Doppelte Buchungen bei langsamer Prüfung

Die Buchungsprüfung nutzt immer die ursprünglichen Abfragen. Bei 167.000 Buchungen dauert sie etwa 3 Sekunden. Ein
zweiter Klick in dieser Zeit legte eine zweite unbestätigte Buchung an (beide Anfragen fanden den Slot frei), der
dritte endete mit „Found more than one bookings“. Das gab es schon vor dem Booster. Abhilfe:
- `Booking::withItemLock()`: MySQL-Sperre (`GET_LOCK`) je Artikel um die Prüfung und das Speichern. Anfragen für denselben
  Artikel laufen nacheinander, nach 20 s Wartezeit gibt es eine Meldung.
- Im Browser sperrt ein kleines Skript (`includes/Public.php`) die Buchungsformulare nach dem ersten Absenden
  („Bitte warten …“).

## Zeitrahmen-Varianten

`tests/php/Fixtures/TimeframeVariants.php` erzeugt 31 Konfigurationen (siehe `testenv/README.md`).
`TimeframeVariantsParityTest` vergleicht Kalenderdaten, vorberechnete Tage und Kartendaten mit dem ursprünglichen Code
für Gast, Abonnent, Redakteur, zwei cb_manager (zugeordnet / nicht zugeordnet) und Admin. Erkenntnisse beim Anlegen:
CommonsBooking ergänzt das Enddatum beim Speichern um 23:59:59 (also den letzten Tag mit 00:00 übergeben).
Überlappende buchbare Zeitrahmen brauchen dasselbe Raster. Buchbare Zeitrahmen haben genau einen Artikel und Standort,
ein Artikel ist zur selben Zeit nur an einem Standort buchbar.
