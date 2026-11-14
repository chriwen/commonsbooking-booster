# Buchungsregeln: Fehlerkorrekturen

Branch `feature/booking-rule-fixes` (auf `booster/index-availability`). Gefunden beim Test aller sieben Regeltypen auf
der großen Testinstanz (`design/buchungspruefung.md` im Branch `feature/confirmation-optimisation`, Szenario
`hostenv/rules-violations.py` im Arbeitsbereich). Betrifft Originalcode, nicht den Booster; keine neuen Tabellen,
Optionen oder Postmeta, also downgradebar.

## 1. Werte einer Regel gelten nicht mehr für die nächste

`BookingRuleApplied::init()` setzte `$appliesToAll`, `$appliedTerms` und `$selectParam` je Regel nicht zurück. Eine
Regel nur für bestimmte Kategorien nach einer Regel „gilt für alle“ galt ebenfalls für alle Artikel (Szenario: „6 Tage
pro Monat, nur Lastenrad“ lehnte einen Anhänger ab). Eine Regel ohne Auswahlwert übernahm den der vorherigen Regel.

Jetzt beginnt jede Regel mit leeren Werten. Folge für bestehende Einstellungen: Eine Regel ohne „gilt für alle“ und
ohne Kategorie oder ohne Auswahlwert war bisher durch eine vorherige Regel verdeckt, jetzt ist sie ungültig. Wie bei
jeder ungültigen Regel zeigt das Backend den Fehler und die Buchungsprüfung wendet dann keine Regel an (Verhalten von
`bookingConformsToRules()` unverändert).

## 2. Rücksetztag der Wochenregeln

Die Auswahl speichert 1 = Sonntag … 7 = Samstag. `BookingRule::getWeekResetDay()` gilt jetzt für beide Wochenregeln:

- „Buchungen pro Woche“ nahm nur 0–5 an, Freitag und Samstag wurden zu einem falschen Tag; die Meldung nannte den Tag
  nach dem Rücksetztag („nach Tuesday“ bei Montag).
- „Tage pro Woche“ nahm 1–6 an, Samstag wurde zu Sonntag.
- Ungültige oder fehlende Werte: Sonntag (wie bisher der Standard).

Die Wochentage kommen aus `$wp_locale` (übersetzt), vorher aus einer CMB2-Zeichenkette, die auf Deutsch englisch blieb.
Das gilt auch für die Auswahl in den Einstellungen.

## 3. Kategorien beim Zählen

`filterBookingsForTermsAndUser()` prüfte die Kategorien an allen Buchungen im Zeitraum und gab dann alle Buchungen
des Nutzers zurück. Bei einer Regel für eine Kategorie zählten so auch Buchungen anderer Kategorien, sobald irgendwer
im Zeitraum die Kategorie gebucht hatte. Jetzt zählen nur Buchungen des Nutzers in den Kategorien der Regel (so
beschreibt es auch der Docblock). Betrifft Tage/Buchungen pro Woche/Monat, x Tage in y Tagen und Kettenbuchungen.

Zusammenspiel mit `feature/confirmation-optimisation`: Dort fragt `getUserBookingsInRange()` zuerst die Buchungen des
Nutzers ab und lädt nur im Sonderfall dieses alten Verhaltens alle Buchungen. Nach dem Zusammenführen kann der
Sonderfall entfallen (immer nur die Buchungen des Nutzers, dann Kategoriefilter).

## Tests

`tests/php/Service/BookingRuleFixesTest.php` (schlägt auf dem alten Code in allen fünf Fällen fehl).

## Offen

- Die Meldung bei Kettenbuchungen nennt keinen Grund (nur „Buchungslimit erreicht“).
