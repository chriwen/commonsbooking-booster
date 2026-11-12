# Vorberechnung für rollenbeschränkte Paare (Zugriffsprofile)

*English version: [role-restricted-availability.en.md](role-restricted-availability.en.md)*

Stand: 07.11.2026, **umgesetzt, in `booster/index-availability` übernommen** (entwickelt im Branch `feature/role-restricted-availability`) (siehe 8. und 9.). Baut auf [availability-precompute.md](availability-precompute.md)
(Stufe 3) auf und löst dort Abschnitt 10, Punkt „Paare mit Rollenbeschränkung: zunächst live. Bei Bedarf eine
Variante je Rollenkombination speichern“.

## 1. Ausgangslage

Hat ein Paar (Artikel × Standort) **irgendeinen** buchbaren Zeitrahmen mit Rollenbeschränkung (`allowed_user_roles`),
speichert die Vorberechnung für das ganze Paar keine Tage (`has_role_limit = 1`, `days = NULL`). Karte, `[cb_search]`,
`[cb_items_table]`, API und GBFS rechnen es bei jedem Aufruf live. Der Zeitrahmen-Index greift weiter, er kennt keine
Rollen.

Messung in DEV (Rollentest, `dev-bench/rollentest/BERICHT.md`, Abschnitt „Leistung“; 1.000 Buchungen, 134 Paare,
ohne Cache):

| | je Paar | Kartenabfrage (Gast / admin) |
|---|---:|---:|
| vorberechnet | ~0,5 ms | 77 / 150 ms (18 Paare beschränkt) |
| live | ~7 ms | 782 / 876 ms (alle 134 Paare beschränkt, simuliert) |
| ohne Booster | | 1.617 / 1.733 ms |

Die Kosten wachsen linear: 1.000 beschränkte Paare kosten bei jedem Aufruf der Karte etwa 7 s statt 0,5 s. Ein Cache
der ganzen Antwort greift nicht, die Kartenabfrage wird nicht als Ganzes gespeichert.

Instanzen, die Rollen breit nutzen (z. B. „nur Mitglieder“ an allen Rädern, oder Wochenenden nur für Vereine), verlieren
damit den größten Teil des Gewinns durch den Booster.

## 2. Beobachtung: Der Nutzer wirkt nur an einer Stelle

Im Rechenweg der Verfügbarkeit hängt alles Nutzerabhängige an `commonsbooking_isCurrentUserAllowedToBook($timeframeId)`
(`includes/Users.php`), geprüft am 07.11.2026:

- `Model\Day::filterTimeframe()` (Raster des Tages)
- `Service\Availability\DayFacts::fromGrid()` (`isUserAllowedToBook` je Slot)
- `Repository\Timeframe::filterTimeframesForCurrentUser()` (über `getBookableForCurrentUser`, `getInRangeForCurrentUser`)

Die Funktion entscheidet so:

```
erlaubt = Rollenliste leer
       || commonsbooking_isCurrentUserAdmin()          (Rollen aus commonsbooking_admin_roles)
       || Rollen des Nutzers ∩ Rollenliste ≠ ∅
```

Daraus folgt: Für ein Paar mit der Menge `R` aller Rollen, die in seinen beschränkten Zeitrahmen vorkommen, hängt das
Ergebnis nur ab von

```
Zugriffsprofil(Nutzer, Paar) = R                               wenn Admin
                              = sortiert( Rollen des Nutzers ∩ R )   sonst
```

Das Zugriffsprofil beschreibt, welche Zugriffsberechtigungen eines Nutzers für dieses Paar überhaupt wirken. Alle
Nutzer mit demselben Zugriffsprofil sehen für dieses Paar exakt dieselben Tage. Ein Admin hat dasselbe Zugriffsprofil wie ein
Nutzer mit allen Rollen aus `R`, denn beide dürfen jeden Zeitrahmen des Paares buchen.

Beispiele aus dem Rollentest:

| Paar | R | Gast, Abonnent, Redakteur | rt_verein1 | rt_multi (Verein + Schule) | admin |
|---|---|---|---|---|---|
| R02 (nur Verein) | {rt_verein} | `∅` | `rt_verein` | `rt_verein` | `rt_verein` |
| R06 (Mo/Mi/Fr Verein, Di/Do Schule) | {rt_schule, rt_verein} | `∅` | `rt_verein` | `rt_schule,rt_verein` | `rt_schule,rt_verein` |

R02 hat damit nur zwei Zugriffsprofile, R06 vier. In der Praxis bleibt die Zahl klein, weil `R` je Paar meist ein bis zwei
Rollen enthält.

## 3. Vorschlag

**Gespeicherte Verfügbarkeit je Paar und Zugriffsprofil**, bei Bedarf berechnet, in einer eigenen Tabelle.

### 3.1 Datenmodell

Neue Tabelle, die bestehende `cb_availability` bleibt unverändert:

```sql
CREATE TABLE {prefix}cb_availability_access (
  item_id       bigint(20) unsigned NOT NULL,
  location_id   bigint(20) unsigned NOT NULL,
  access_profile varchar(191) NOT NULL,      -- sortierte Rollen, kommagetrennt; '' = keine passende Rolle
  computed_gen  int unsigned NOT NULL,        -- Generation des Paares (cb_availability.gen) bei der Berechnung
  days_from     date NOT NULL,
  days          varbinary(1024) NOT NULL,     -- wie cb_availability.days
  slots         mediumblob NULL,              -- wie cb_availability.slots (API, GBFS)
  computed_at   datetime NOT NULL,
  calc_version  smallint unsigned NOT NULL,
  last_read     date NULL,                    -- zum Aufräumen selten genutzter Zugriffsprofile
  PRIMARY KEY (location_id, item_id, access_profile)
);
```

- **Gültig**, wenn `computed_gen = cb_availability.gen` des Paares, dazu dieselben Regeln wie heute
  (`calc_version`, `computed_at` jünger als 24 h, Tagesbereich abgedeckt).
- **Invalidierung kostet nichts zusätzlich**: Jede Änderung am Paar erhöht schon heute `cb_availability.gen`, damit
  sind alle gespeicherten Zugriffsprofile des Paares ungültig. Auch das Ändern von `allowed_user_roles` läuft so (im Rollentest mit
  R13/R14 geprüft).
- **Rollenwechsel eines Nutzers** braucht keine Invalidierung: Das Zugriffsprofil wird bei jedem Aufruf aus den
  aktuellen Rollen bestimmt, der Nutzer landet dann einfach bei einem anderen Zugriffsprofil.

### 3.2 Lesen

Im `Reader` (und `SlotReader`) an der Stelle, die heute bei `has_role_limit = 1` `null` liefert:

1. Für alle angefragten Paare mit `has_role_limit = 1` die Rollenmengen `R` bestimmen: IDs der beschränkten Zeitrahmen
   aus dem Index (`has_role_limit = 1`), Rollenlisten aus den Postmeta (`update_meta_cache` für alle auf einmal).
   Ein bis zwei Abfragen je Request.
2. Zugriffsprofil des aktuellen Nutzers je Paar bilden (2.).
3. Gespeicherte Zeilen aller Paare für diese Zugriffsprofile mit einer Abfrage laden.
4. Gültige Zeile: Tagesfakten entpacken, Fensterregeln wie bisher. Das Fenster (nächster buchbarer Zeitrahmen,
   Vorausbuchung) wird wie heute live und nutzerbezogen aus dem Index bestimmt.
5. Fehlende oder ungültige Zeile: berechnen (3.3), mit Generationsprüfung schreiben, ausliefern.

### 3.3 Berechnen

`Calculator::computeAccessProfile($itemId, $locationId, $accessProfile)` nutzt denselben Rechenkern wie heute. Für die Dauer der
Berechnung wird ein Nutzer ohne ID mit genau den Rollen des Zugriffsprofils als aktueller Nutzer gesetzt (globales
`$current_user`, im `finally` zurückgesetzt). Ohne ID ist `commonsbooking_isCurrentUserAdmin()` falsch, die
Rollenprüfung entscheidet nur über die Rollen. Das deckt auch das Zugriffsprofil der Admins ab (`R`, siehe 2.).

So bleibt **ein Rechenkern** (Stufe 3, Abschnitt 8): `Calculator` und Rechenweg bleiben unverändert, nur `Reader` und
`SlotReader` bekommen den Zweig für Zugriffsprofile. Dateien des Originals werden nicht geändert.

Geschrieben wird wie in Stufe 3, 6.3: Generation `G` vor der Berechnung lesen, `INSERT … ON DUPLICATE KEY UPDATE` nur
übernehmen, wenn `cb_availability.gen` danach noch `G` ist. Eine parallele Änderung macht das Ergebnis nicht gültig.

### 3.4 Vorberechnen und Aufräumen

- Beim Neuaufbau und nach einer Invalidierung: die zwei häufigsten Zugriffsprofile je beschränktem Paar vorberechnen, `∅`
  (Gäste und alle ohne passende Rolle) und `R` (Admins und Nutzer mit allen Rollen). Weitere Zugriffsprofile entstehen beim
  ersten Aufruf.
- Grenze je Paar (Standard 8, per Filter): Darüber wird live gerechnet wie heute. Schützt vor Rollenkombinationen, die
  den Speicher füllen.
- Der tägliche Cron löscht Zeilen, die 30 Tage nicht gelesen wurden (`last_read`, höchstens einmal am Tag
  geschrieben), und prüft eine Stichprobe gegen die Live-Berechnung (Verifier).

### 3.5 Abwärtskompatibilität und Downgrade

- Nur eine neue Tabelle, keine Änderung an Postmeta, an `cb_availability` oder am Index.
- Ältere Booster-Versionen kennen die Tabelle nicht und rechnen beschränkte Paare live wie bisher.
- `wp commonsbooking booster purge` entfernt die neue Tabelle mit.
- Schalter: Teil von `Availability::isEnabled()`, zusätzlich per Filter abschaltbar.
- Umsetzung: `Schema::SCHEMA_VERSION` 2 → 3. Beim Update leert die bestehende Regel („Strukturänderung leert den
  Cache“) einmal `cb_availability`, Readers und Cron füllen sie neu. Beim Downgrade auf eine ältere Booster-Version
  erkennt diese Version 3 als fremd, leert `cb_availability` und arbeitet normal. Die Tabelle `cb_availability_access`
  bleibt dann ungenutzt liegen, auch ein `purge` der älteren Version entfernt sie nicht (harmlos, ein erneutes Update
  oder `purge` dieser Version entfernt sie).

## 4. Verworfene Alternativen

| Alternative | Warum nicht |
|---|---|
| Je Zeitrahmen speichern und beim Lesen überlagern | Buchungen, Feiertage, Einschränkungen und die Priorität überlappender Zeitrahmen wirken auf das ganze Paar. Das Überlagern müsste die Logik von `Day::getGrid()` nachbauen und verletzt „ein Rechenkern“. |
| Ganze Kartenantwort je Rollenkontext cachen | Bei jeder Buchung irgendwo ungültig, groß, je Seite und Karte getrennt. Hilft nur bei wiederholten Aufrufen. |
| Speichern je Nutzer | Viele Zeilen, kaum Wiederverwendung. Das Zugriffsprofil fasst alle Nutzer mit gleicher Wirkung zusammen. |
| Speichern je Rollenkombination der ganzen Instanz | Die meisten Rollen sind für ein Paar ohne Wirkung. Die Schnittmenge mit `R` hält die Zahl je Paar klein. |
| Profilzeilen in `cb_availability` statt eigener Tabelle (Entscheidung 08.11.2026, auch ohne Rücksicht auf Abwärtskompatibilität) | Die Paarzeile trägt `gen` (Invalidierung, Generationsprüfung beim Schreiben) und gehört genau einmal je Paar. Profilzeilen kommen mehrfach je Paar, mit eigener Lebensdauer (`last_read`, Grenze je Paar). In einer Tabelle gäbe es zwei Zeilenarten mit je anderen Pflichtfeldern, Sonderzeilen im Primärschlüssel und Filter in jeder Abfrage, und der Hot Path unbeschränkter Paare teilte sich Tabelle und Index mit den Profilzeilen. Eine Tabelle je Zeilenart bleibt das saubere Modell. |

## 5. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Die Rollenprüfung im Original ändert sich (z. B. neue Ausnahme für Standort-Admins oder ein Filter) | Zugriffsprofil und Prüfung liegen in einer Klasse mit Verweis auf `commonsbooking_isCurrentUserAllowedToBook`. Der Rollentest (`dev-bench/rollentest`, Booster gegen Original je Nutzer) und ein PHPUnit-Test mit denselben Fällen schlagen dann an. |
| Fremde Plugins filtern Rollen oder die Rollenprüfung | Die Funktion hat heute keinen Filter. Filter `commonsbooking_admin_roles` wird über das Zugriffsprofil der Admins abgedeckt. Ein Filter zum Abschalten bleibt als Ausweg. |
| Sehr viele Zugriffsprofile je Paar | Grenze je Paar (3.4), darüber live |
| Erster Aufruf je Zugriffsprofil nach einer Änderung ist so langsam wie heute | Vorberechnung von `∅` und `R` (3.4). Andere Zugriffsprofile kosten einmal ~7 ms je Paar. |

## 6. Erwartete Wirkung

Nach dem ersten Aufruf je Zugriffsprofil kostet ein beschränktes Paar so viel wie ein offenes (~0,5–1 ms). In der Simulation
„alle Paare beschränkt“ sinkt die Kartenabfrage damit von ~800 ms auf das Niveau ohne Beschränkung (~80–150 ms).
Speicher: rund 190 Byte Tage je Zugriffsprofil, dazu `slots`.

## 7. Tests

- Rollentest (`dev-bench/rollentest`): `check.php`, `http_check.py`, `search_check.py`, `search-parity.php` müssen bei
  0 Abweichungen bleiben, jetzt mit gespeicherten statt live gerechneten Zugriffsprofilen. `store.php` zeigt die
  Zugriffsprofile.
- PHPUnit: Zugriffsprofil (leer, eine Rolle, mehrere, Admin, Rolle ohne Wirkung), Gültigkeit nach `gen`-Erhöhung,
  Generationsprüfung beim Schreiben, Grenze je Paar, Rollenwechsel eines Nutzers.
- `perf-roles.php` mit Zugriffsprofilen: je Paar und gesamt, vor und nach dem ersten Aufruf.

## 8. Entscheidungen

Vom Nutzer am 07.11.2026 entschieden:

1. **Branch:** gehört in den Booster-Branch `booster/index-availability` (PR #1). Begründung: nur eine eigene Tabelle,
   downgradebar, und es schließt eine Lücke der Vorberechnung (Stufe 3, Abschnitt 10). Ergänzt am 07.11.2026:
   zuerst in einem eigenen Branch `feature/role-restricted-availability` (Stand von `booster/index-availability`)
   umsetzen, prüfen und als Test-Release ausliefern. Erst wenn alles funktioniert, in den Booster-Branch übernehmen
   und danach die Feature-Branches darauf rebasen. Am 07.11.2026 auf Anweisung des Nutzers übernommen.
2. **Grenze je Paar:** 8 Zugriffsprofile als Standard, per Filter änderbar. Darüber live wie bisher.
3. **Vorberechnung:** Beim Neuaufbau und nach einer Invalidierung werden `∅` (Gäste und alle ohne passende Rolle) und
   `R` (Admins und Nutzer mit allen Rollen) sofort berechnet. Weitere Zugriffsprofile entstehen beim ersten Aufruf.

## 9. Umsetzung und Prüfung

Branch `feature/role-restricted-availability` (Stand von `booster/index-availability`, 572bb932), Stand 07.11.2026.

**Code**

| Datei | Inhalt |
|---|---|
| `src/Service/Availability/AccessProfile.php` | neu: Zugriffsprofil des Nutzers, Rollen `R` je Paar (Index + Postmeta, gebündelt), `runAs()`, Grenze und Schalter |
| `src/Service/Availability/AccessStore.php` | neu: Tabelle `cb_availability_access` lesen, schreiben (mit Generationsprüfung), Aufräumen, Statistik |
| `Schema.php` | zweite Tabelle, `SCHEMA_VERSION` 3, `uninstall` entfernt beide |
| `Calculator.php` | `compute()` mit Zugriffsprofil, `computeAccessProfile()`, `recompute()` berechnet `∅` und `R` mit |
| `Reader.php`, `SlotReader.php` | beschränkte Paare lesen das Zugriffsprofil des Nutzers, sonst wie bisher |
| `Maintenance.php`, `Cli.php` | täglicher Cron räumt auf, `verify` prüft auch Zugriffsprofile, `status` zeigt sie |
| `Booster/SettingsView.php` | Einstellungsseite zeigt die Zugriffsprofile, Übersetzungen ergänzt |

Filter: `commonsbooking_availability_access_profiles` (an/aus, Standard an),
`commonsbooking_availability_access_profiles_max` (Standard 8).

**Prüfung**

- PHPUnit: 586 Tests grün, davon 14 neue in `tests/php/Service/Availability/AccessProfileTest.php` (Zugriffsprofile je
  Nutzer, Ergebnis gleich live für Gast, Abonnent, Redakteur, Autor, beide Rollen, Admin, keine Neuberechnung bei
  gespeicherten Profilen, Invalidierung durch Buchung und geänderte Rollen, Rollenwechsel, Grenze, Abschalten,
  Aufräumen, `verify` mit Reparatur, API-Slots). PHPStan und phpcbf ohne Fehler.
- Rollentest in DEV (11 Nutzer × 17 Paare, 92 Tage): `check.php`, `http_check.py`, `search_check.py`,
  `search-parity.php`, `table-parity.php` 14 und 31: 0 Abweichungen. `verify`: 0 Abweichungen.
- Messung DEV (`perf-roles.php`, Median, ohne Cache):

| | je beschränktes Paar | Kartenabfrage admin |
|---|---:|---:|
| Rollentest, 18 von 102 Paaren beschränkt | 0,5–1,0 ms (live 1,6–3,7 ms) | 62 ms (vorher 150 ms) |
| alle 102 Paare beschränkt (`sim-all-restricted.php`), ohne Zugriffsprofile | 8–9 ms | 936 ms |
| alle 102 Paare beschränkt, mit Zugriffsprofilen | 0,6–1,0 ms | 62 ms |
| Original ohne Booster | 7,5–8 ms | 1.900 ms |

**Hinweis für Worktrees:** `vendor-prefixed/` enthält die Classmap des Plugins (`include_root_autoload`). Nach neuen
Klassen `composer run prefix-namespaces` ausführen, sonst fehlen sie außerhalb von PHPUnit.
