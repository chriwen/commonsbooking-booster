# Infobereich „Zeitrahmen derselben Kombination“ im Zeitrahmen-Editor

Status: umgesetzt im Branch `feature/timeframe-info` (Basis `booster/index-availability`). Keine neuen Daten, downgradebar.

## Anforderung

Admins sehen in der Bearbeitungsansicht eines Zeitrahmens, welche **anderen Zeitrahmen** Einstellungen zur selben
Artikel-/Standort-Kombination enthalten, mit direkten Links auf Zeitrahmen, Artikel und Standort.

## Entscheidungen

- **Darstellung:** Metabox „Zeitrahmen mit demselben Artikel und Standort“ als erste Box der Hauptspalte (gleiche
  Technik und gleiches Layout wie „Zugeordnete Zeitrahmen“ bei Artikeln/Standorten, `View\Admin\AssignedTimeframes`).
- **Kombination:** Ein anderer Zeitrahmen gehört dazu, wenn er mindestens einen Artikel **und** mindestens einen
  Standort mit dem aktuellen Zeitrahmen teilt (gleiche UND-Regel wie bei der Wirkung von Zeitrahmen). Bei Listen,
  Kategorien und „Alle“ zählen die materialisierten Listen. Bei Mehrfachauswahl wird von der kleineren Seite aus
  gesucht (`Repository\AssignedTimeframes::forTimeframe()`), damit „alle Artikel“ keine Abfrage je Artikel auslöst.
- **Umfang:** buchbar, Feiertage, Reparatur, jeweils jeder Status (nicht veröffentlichte mit ⚠). Einschränkungen und
  Buchungen sind nicht enthalten. Gruppen wie bei Artikeln: aktuell / kommend / abgelaufen.
- **Links:** Zeitrahmen, Artikel und Standort führen zur Bearbeitung; bei genau einem Artikel/Standort zusätzlich zur
  Box „Zugeordnete Zeitrahmen“ dort. Ohne Bearbeitungsrecht nur Text.
- **Neuer Zeitrahmen (auto-draft):** Hinweis, dass die Daten nach dem Speichern erscheinen. Sind noch kein Artikel
  oder Standort gespeichert, steht ein entsprechender Hinweis da.
