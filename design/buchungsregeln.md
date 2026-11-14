# Buchungsregeln: Fehlerkorrekturen

Branch `feature/booking-rule-fixes` (auf `booster/index-availability`). Die Fehler fielen beim Test aller sieben
Regeltypen auf der großen Testinstanz auf. Das Szenario steht in `design/buchungspruefung.md` im Branch
`feature/confirmation-optimisation`, das Skript dazu ist `hostenv/rules-violations.py` im Arbeitsbereich.

Die Änderungen betreffen Originalcode, nicht den Booster. Es gibt keine neuen Tabellen, Optionen oder Postmeta; der
Branch bleibt downgradebar.

Dieses Dokument richtet sich an Entwickler der Buchungsregeln. Zu jedem Fehler stehen hier die Ursache, der Code
vorher und nachher, das geänderte Verhalten und der Weg, es zu prüfen.

## Übersicht

| # | Fehler | Datei | Sichtbar als |
|---|---|---|---|
| 1 | Werte einer Regel gelten für die nächste weiter | `src/Service/BookingRuleApplied.php` | Regel nur für eine Kategorie gilt für alle Artikel |
| 2 | Rücksetztag der Wochenregeln | `src/Service/BookingRule.php` | „nach Tuesday“ bei Montag, Freitag/Samstag falsch, Wochentage englisch |
| 3 | Kategorien beim Zählen | `src/Service/BookingRule.php` | Buchungen anderer Kategorien zählen mit |

Tests: `tests/php/Service/BookingRuleFixesTest.php`. Alle fünf Tests schlagen auf dem alten Code fehl.

Zum Hintergrund: Die Regeln werden unter Einstellungen → Einschränkungen → Buchungsregeln konfiguriert und liegen in
der Option `commonsbooking_options_restrictions`, Schlüssel `rules_group`. Jede Regel ist ein Array mit diesen
Schlüsseln:

- `rule-type`
- `rule-param1`, `rule-param2`
- `rule-select-param`
- `rule-applies-all` (`'on'`)
- `rule-applies-categories` (Term-IDs)
- `rule-exempt-roles`

`BookingRuleApplied::init()` baut daraus die Regelobjekte. `BookingRuleApplied::bookingConformsToRules()` wendet
sie beim Anlegen einer unbestätigten Buchung an. Die Prüffunktionen bekommen
`$args = [ param1, param2, selectParam ]` (siehe `getArgs()`).

## 1. Werte einer Regel gelten nicht mehr für die nächste

### Ursache

`init()` läuft in einer Schleife über alle konfigurierten Regeln. Nur `$ruleParams` wurde je Regel zurückgesetzt.
`$selectParam`, `$appliesToAll` und `$appliedTerms` wurden lediglich gesetzt, wenn die Regel einen Wert hatte, und
behielten sonst den Wert der vorherigen Regel. Die Fallbacks `?? false`, `?? []` und `?? null` griffen nur bei der
ersten Regel.

```php
// vorher, je Regel in der Schleife
$ruleParams = [];
…
if ( ! empty( $ruleConfig['rule-select-param'] ) ) {
    $selectParam = $ruleConfig['rule-select-param']; }
if ( ! empty( $ruleConfig['rule-applies-all'] ) && $ruleConfig['rule-applies-all'] === 'on' ) {
    $appliesToAll = true;
}
// $appliedTerms wird nur bei gesetzten Kategorien belegt
…
$bookingRule->setAppliesToWhat( $appliesToAll ?? false, $appliedTerms ?? [] );
$bookingRule->setAppliedParams( $ruleParams, $selectParam ?? null );
```

Folgen:

- Eine Regel nur für bestimmte Kategorien galt nach einer Regel „gilt für alle“ ebenfalls für alle Artikel, weil
  `$appliesToAll` noch `true` war. Im Szenario lehnte die Regel „6 Tage pro Monat, nur Lastenrad“ einen Anhänger ab.
- Eine Regel ohne Kategorie und ohne „gilt für alle“ übernahm die Einstellung der vorherigen Regel.
- Eine Regel ohne Auswahlwert, etwa ohne Rücksetztag, übernahm den Auswahlwert der vorherigen Regel.

### Nachher

```php
$ruleParams   = [];
$selectParam  = null;
$appliesToAll = false;
$appliedTerms = [];
…
$bookingRule->setAppliesToWhat( $appliesToAll, $appliedTerms );
$bookingRule->setAppliedParams( $ruleParams, $selectParam );
```

### Folgen für bestehende Einstellungen

Für korrekt ausgefüllte Regeln ändert sich nur das Ergebnis: Kategorie-Regeln gelten jetzt wirklich nur für ihre
Kategorien.

Eine Regel, der eine Angabe fehlt, war bisher durch die vorherige Regel verdeckt und ist jetzt ungültig. Das betrifft
Regeln ohne „gilt für alle“ und ohne Kategorie sowie Regeln, die einen Auswahlwert brauchen und keinen haben. Für
ungültige Regeln ändert sich am Verhalten nichts:

- Beim Speichern der Einstellungen ruft `Plugin` `BookingRuleApplied::validateRules()` auf. Ungültige Regeln erzeugen
  den bekannten Fehlerhinweis im Backend.
- `bookingConformsToRules()` fängt die `BookingRuleException` aus `init()` ab und wendet dann **keine** Regel an.
  Das war schon vorher so.
- `getRulesJSON()` (Einstellungsseite) ruft `init( true )` auf und zeigt die Regeln trotzdem an.

Vor einem Update kann man prüfen, ob eine Instanz betroffen ist:

```bash
wp eval 'try { echo count( CommonsBooking\Service\BookingRuleApplied::init() ), " Regeln gültig\n"; } catch ( Exception $e ) { echo "ungültig: ", $e->getMessage(), "\n"; }'
```

Den Befehl einmal mit dem alten und einmal mit dem neuen Code ausführen. Wird eine Regel nur mit dem neuen Code
ungültig, verdeckte bisher die vorherige Regel eine fehlende Angabe.

### Prüfen

- `testCategoryRuleAfterRuleForAllKeepsItsCategories`: Regel „alle“, dann Kategorie-Regel, dann wieder „alle“. Die
  Kategorie-Regel muss `appliesToAll = false` und nur ihre Kategorie haben, die dritte Regel keine Kategorien.
- `testMissingSelectParamIsNotTakenFromPreviousRule`: Eine Wochenregel ohne Rücksetztag nach einer mit Rücksetztag
  muss eine `BookingRuleException` auslösen.

## 2. Rücksetztag der Wochenregeln

Die Auswahl speichert 1 = Sonntag, 2 = Montag … 7 = Samstag. Die 0 entfällt, weil CMB2 sie als leer behandelt.
`getBookingWeekRange( $booking, $resetDay )` erwartet diesen Wert und zieht selbst 1 ab. Die Regeln verwendeten den
Wert aber unterschiedlich.

### Vorher

```php
// checkMaxBookingDaysPerWeek(): 1–6, Samstag (7) wird zu Sonntag (1)
$resetDay = ( $args[2] < 7 && $args[2] >= 1 ) ? $args[2] : 1;

// checkMaxBookingsWeek(): 0–5, Freitag (6) und Samstag (7) werden zu 0
$resetDay = ( $args[2] < 6 && $args[2] >= 0 ) ? $args[2] : 0;

// maxDaysWeekErrorMessage(): richtig (Index ab 0)
$resetDayString = explode( ', ', $weekDays )[ $args[2] - 1 ];

// maxBookingsWeekErrorMessage(): ein Tag zu spät, bei 7 undefinierter Index
$resetDayString = explode( ', ', $weekDays )[ $args[2] ];

// Wochentage: Zeichenkette aus der CMB2-Textdomain, in deutschen Instanzen meist unübersetzt
$weekDays = __( 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday', 'cmb2' );
```

Nachgerechnet für „Buchungen pro Woche“ mit dem alten Code (Spalten: Wochentag der Buchung, Start der Woche und
Länge des Zeitraums):

| Auswahl | Woche beginnt | Auffälligkeit |
|---|---|---|
| 1–5 (So–Do) | richtig | Meldung nennt den Folgetag („nach Tuesday“ bei Montag) |
| 6 (Freitag) | Samstag | falscher Tag |
| 7 (Samstag) | Samstag | Buchung an einem Samstag: Zeitraum 14 statt 7 Tage, Meldung ohne Tag |

Ursache für die beiden letzten Zeilen: Der Wert 0 wird in `getBookingWeekRange()` zu −1. Daraus wird
`"Sunday +-1 days"`, also Samstag. Der Vergleich `format( 'w' ) == -1` trifft nie zu, deshalb läuft an einem
Samstag `last saturday` bis `next saturday`.

### Nachher

```php
private static function getWeekResetDay( array $args ): int {
    $resetDay = $args[2] ?? null;
    return ( is_numeric( $resetDay ) && $resetDay >= 1 && $resetDay <= 7 ) ? (int) $resetDay : 1;
}

private static function getWeekDayNames(): array {   // Index 0 = Sonntag
    global $wp_locale;
    if ( $wp_locale instanceof \WP_Locale ) {
        return array_map( fn( $day ) => $wp_locale->get_weekday( $day ), range( 0, 6 ) );
    }
    return explode( ', ', __( 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday', 'cmb2' ) );
}
```

Die Methoden verwenden die Helfer so:

- `checkMaxBookingDaysPerWeek()` und `checkMaxBookingsWeek()` rufen
  `getBookingWeekRange( $booking, self::getWeekResetDay( $args ) )` auf.
- Beide Meldungen verwenden `self::getWeekDayNames()[ self::getWeekResetDay( $args ) - 1 ]`.
- Die Auswahl in den Einstellungen (`BookingRule::init()`) nimmt dieselben Namen:
  `array_combine( range( 1, 7 ), self::getWeekDayNames() )`. Die gespeicherten Werte 1–7 bleiben gleich, nur die
  angezeigten Namen sind jetzt übersetzt.

Ungültige oder fehlende Werte ergeben Sonntag, wie bisher der Standard.

### Geändertes Verhalten

| Regel | Auswahl | vorher | nachher |
|---|---|---|---|
| Tage pro Woche | 7 (Samstag) | Woche ab Sonntag | Woche ab Samstag |
| Buchungen pro Woche | 6 (Freitag) | Woche ab Samstag | Woche ab Freitag |
| Buchungen pro Woche | 7 (Samstag), Buchung am Samstag | 14 Tage | 7 Tage |
| Buchungen pro Woche | jede, Meldung | Folgetag bzw. leer | der eingestellte Tag |
| beide | Wochentag in Meldung und Auswahl | englisch | Sprache der Instanz |

Alle anderen Einstellungen zählen genau wie vorher.

### Prüfen

- `testWeekResetDay`: prüft die Werte 1, 2, 6, 7, `'7'`, 0, 8 und `null`. Für eine Buchung an einem Mittwoch müssen
  Start und Ende der Woche auf dem eingestellten Wochentag liegen (Auswahl 1, 2, 6, 7).
- `testWeekMessagesNameTheResetDay`: Die Meldungen nennen Monday bzw. Saturday und nicht Tuesday. Die Tests laufen
  auf Englisch.
- Von Hand: Regel „Buchungen pro Woche“ mit Rücksetzen am Montag und Grenze 1 anlegen, zwei Buchungen in derselben
  Woche versuchen. Die Meldung muss „nach Montag“ nennen.

## 3. Kategorien beim Zählen

### Ursache

`filterBookingsForTermsAndUser()` wird von den Regeln mit Zeitraum aufgerufen (Tage pro Woche/Monat, x Tage in
y Tagen, Buchungen pro Woche/Monat) und von der Kettenbuchungs-Regel. Die Funktion prüfte die Kategorien an **allen**
Buchungen und gab dann alle Buchungen des Nutzers zurück, nicht die gefilterten:

```php
// vorher
$filteredTerms = Booking::filterTermsApply( $bookings, $terms );
if ( ! empty( $filteredTerms ) ) {
    return Booking::filterForUser( $bookings, $user );   // $bookings, nicht $filteredTerms
} else {
    return null;
}
```

Bei einer Regel für eine Kategorie zählten so auch die Buchungen des Nutzers in anderen Kategorien, sobald
irgendjemand im Zeitraum die Kategorie gebucht hatte. Ob eine Regel griff, hing damit von den Buchungen anderer
Nutzer ab. Der Docblock und der Name der Funktion beschreiben dagegen „Kategorie **und** Nutzer“.

### Nachher

```php
$filteredTerms = Booking::filterTermsApply( $bookings, $terms );
if ( empty( $filteredTerms ) ) {
    return null;
}
$filtered = Booking::filterForUser( $filteredTerms, $user );
return empty( $filtered ) ? null : $filtered;
```

`filterTermsApply()` gibt bei leeren Kategorien (`false`, `[]`, Regel „gilt für alle“) alle Buchungen unverändert
zurück. Für Regeln, die für alle Artikel gelten, ändert sich also nichts. `filterForUser()` gibt nie `null` zurück,
sondern ein leeres Array. Die Aufrufer behandeln `null` und `[]` gleich (`empty()`). Die Funktion gibt jetzt
einheitlich `null` zurück.

### Geändertes Verhalten

Das Verhalten ändert sich nur bei Regeln, die auf Kategorien beschränkt sind:

| Buchungen im Zeitraum | vorher gezählt | nachher gezählt |
|---|---|---|
| Nutzer: 1× Kategorie A, 1× B; Regel für A | A und B | A |
| Nutzer: 1× B; anderer Nutzer: 1× A; Regel für A | B | nichts |
| Nutzer: 1× B; niemand A; Regel für A | nichts | nichts |

Bei der Kettenbuchungs-Regel entscheidet der Filter nur, ob überhaupt eine angrenzende Buchung des Nutzers in den
Kategorien der Regel liegt. Die Länge der Kette (`getBookingChain()`) wird weiterhin ohne Kategoriefilter
berechnet. Das ist unverändert und nicht Teil dieses Branches.

### Prüfen

- `testOnlyBookingsOfTheRuleCategoriesCount`: drei Buchungen (Nutzer in A, Nutzer in B, anderer Nutzer in A).
  - Regel für A: nur die A-Buchung des Nutzers.
  - Regel für B: nur seine B-Buchung.
  - Regel ohne Kategorie: beide Buchungen des Nutzers.
  - Nur der andere Nutzer hat A gebucht: `null`.
- Von Hand: Regel „Buchungen pro Monat = 1, nur Kategorie A“. Der Nutzer bucht zuerst einen Artikel aus B, dann einen
  aus A. Die zweite Buchung muss durchgehen; vorher wurde sie abgelehnt, sobald jemand im Monat A gebucht hatte.

## Zusammenspiel mit `feature/confirmation-optimisation`

Dort fragt `BookingRule::getUserBookingsInRange()` zuerst nur die Buchungen des Nutzers ab. Alle Buchungen im
Zeitraum lädt sie nur in dem Sonderfall, in dem das alte Verhalten von Fehler 3 von fremden Buchungen abhing. Der
Branch muss das alte Verhalten exakt erhalten, weil er nur beschleunigen soll.

In `booster2` kommen beide Branches zusammen. Dort entfällt der Sonderfall: Es zählen immer nur die Buchungen des
Nutzers, gefiltert nach Kategorie. Nach diesem Fix ist das genau das Ergebnis von `filterBookingsForTermsAndUser()`
über alle Buchungen.

## Prüfanleitung

Im Arbeitsbereich:

```bash
# nur die Tests dieses Branches, dann alles wie die CI
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRuleFixesTest
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit
(cd checkouts/booking-rule-fixes && vendor/bin/phpstan analyse --memory-limit=1G)
(cd checkouts/booking-rule-fixes && vendor/bin/phpcbf -q src templates includes tests commonsbooking.php)
```

Gegenprobe, ob die Tests die Fehler wirklich erkennen: `src/` auf den Stand des Booster-Branches zurücksetzen und die
Tests erneut laufen lassen. Alle fünf müssen fehlschlagen.

```bash
git -C checkouts/booking-rule-fixes checkout booster/index-availability -- src
CB_ROOT=booking-rule-fixes dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRuleFixesTest
git -C checkouts/booking-rule-fixes checkout HEAD -- src
```

Szenario auf der großen Instanz: Die Regeln setzt `hostenv/rules-setup.php set`, das Szenario läuft mit
`hostenv/rules-violations.py` (siehe `design/buchungspruefung.md`). Mit diesem Branch lehnt die Lastenrad-Regel
Versuch 11 (Artikel B, keine Lastenrad-Kategorie) nicht mehr ab. Abgelehnt wird die Buchung trotzdem, jetzt aber von einer
der anderen Regeln, weil der Dezember dann schon ausgeschöpft ist (z. B. „Buchungen pro Monat“: drei Buchungen).
Welche Regel zuerst greift, hängt von der Reihenfolge und vom Zeitraum der Regel „x Tage in y Tagen“ ab. Prüfen
lässt sich, dass die Meldung nicht mehr von der Lastenrad-Regel kommt.

## Offen

- Die Meldung bei Kettenbuchungen nennt keinen Grund, nur „Buchungslimit erreicht“.
- Die Kettenbuchungs-Regel berechnet die Länge der Kette ohne Kategoriefilter (siehe Fehler 3).
