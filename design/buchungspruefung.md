# Buchungsprüfung auf großen Instanzen

Branch `feature/confirmation-optimisation` (auf `booster/index-availability`). Änderung 1 liegt seit Commit `dd9084b4`
direkt im Booster-Branch. Dieses Dokument richtet sich an Entwickler, die die Änderungen nachvollziehen und prüfen
wollen. Zu jeder Änderung stehen hier die Ursache, der Code vorher und nachher, die Begründung, warum das Ergebnis
gleich bleibt, und der Weg, das zu prüfen.

## Zusammenfassung

Anlass: Ein Testnutzer der Hoster-Instanz (700 Artikel, 500 Standorte, 100.000 Buchungen) brauchte nach „Weiter zur
Buchungsprüfung“ ca. 13 s und nach „Buchung bestätigen“ ca. 7 s, bis die Seite neu geladen war.

| Schritt (Serverzeit, lokale Kopie, Docker 4 GB) | vorher | nachher |
|---|---|---|
| „Weiter zur Buchungsprüfung“, ohne Buchungsregeln | 1,2–4,9 s, kalt bis 17,8 s | 0,38–0,46 s |
| „Weiter zur Buchungsprüfung“, alle sieben Regeltypen aktiv | ~5 s | 0,48–0,76 s |
| „Buchung bestätigen“ | 3,5–10,5 s | 0,39–0,82 s |

Es gibt vier Änderungen. Keine davon legt Tabellen, Optionen, Postmeta oder Indizes an, alle bleiben also
downgradebar. Die Prüfung liest weiterhin nur Originaldaten (Posts und Postmeta), nie den Booster-Index.

| # | Änderung | Datei(en) | Wirkung |
|---|---|---|---|
| 1 | Weiterleitung (`exit`) erst nach `withoutBooster()` | `src/Plugin.php`, `src/Wordpress/CustomPostType/Booking.php` | 0,8–1,7 s weniger beim Bestätigen |
| 2 | Eine Abfrage für Slot und Überlappungen statt zwei | `src/Wordpress/CustomPostType/Booking.php` | eine Abfrage weniger (alt ~1,2 s) |
| 3 | Postmeta-Abfrage für ein Paar ohne `WP_Query` | `src/Repository/Booking.php` | 1,2–2,2 s → 0,05–0,16 s je Abfrage |
| 4 | Buchungsregeln fragen nur die Buchungen des Nutzers ab | `src/Service/BookingRule.php` | 4,6 s → 0,14 s für alle Regeln |

Tests: `tests/php/Repository/BookingLegacyLookupTest.php` (Änderung 3) und
`tests/php/Service/BookingRuleUserRangeTest.php` (Änderung 4). Die Prüfanleitung steht am Ende.

## Ablauf der Buchungsformulare

Beide Formulare laufen über denselben Weg: der Kalender mit „Weiter zur Buchungsprüfung“ und die Buchungsseite mit
„Buchung bestätigen“ bzw. „Stornieren“. Einstieg ist `Plugin::handleBookingForms()` am Hook `template_redirect`.

```
Plugin::handleBookingForms()
├─ Booster::withoutBooster( … )                         Prüfung immer auf Originaldaten
│  └─ CustomPostType\Booking::processFormRequest()      Nonce, Parameter
│     └─ withItemLock( Artikel )                        GET_LOCK, eine Anfrage je Artikel
│        └─ handleBookingRequest()
│           ├─ Repository\Booking::getByTimerange()     Änderung 2+3: eine Abfrage (findForItemLegacy)
│           ├─ findExactSlot()                          Änderung 2: statt getByDate()
│           ├─ Überlappungen = Rest der Treffer         Änderung 2: statt getExistingBookings()
│           ├─ Zeitrahmen, Sperrtage, Rollen …          unverändert
│           ├─ wp_insert_post() / wp_update_post()
│           └─ BookingRuleApplied::bookingConformsToRules()   nur neue/unbestätigte Buchung, Änderung 4
└─ Booking::redirectToBooking()                         Änderung 1: exit erst hier
shutdown: Booster-Invalidator rechnet die Verfügbarkeit des Paars neu (mit Index: 7 statt 650 Abfragen)
```

Die Buchungsregeln laufen nur, wenn eine **neue** Buchung angelegt oder eine bestehende auf `unconfirmed` gesetzt
wird (`$needsValidation`). Das ist bei „Weiter zur Buchungsprüfung“ der Fall, beim Bestätigen nicht.

## Messverfahren

- Die Formulare werden wie von einem Browser abgeschickt: Kalenderseite laden, Formularfelder übernehmen, Slot setzen,
  Buchungsseite laden, Bestätigungsformular abschicken. Angemeldet wird über ein mit `wp_generate_auth_cookie()`
  erzeugtes Cookie. Gemessen wird die Antwortzeit der Formular-Requests, also die Serverzeit einschließlich
  `shutdown` (mod_php).
- Die Ursachen wurden per Must-Use-Plugin ermittelt: Zeitlücken zwischen Hooks und Abfragen über den Filter `query`.
  `SAVEQUERIES` taugt hier nicht, weil Query Monitors `db.php` es übernimmt. Dazu kam `EXPLAIN` der erzeugten
  SQL-Abfragen.
- Die Skripte liegen im Arbeitsbereich, nicht im Plugin:
  - `hostenv/rules-setup.php`: Regeln setzen
  - `hostenv/rules-bench.php`: Zeit je Regel
  - `hostenv/rules-violations.py`: Szenario mit Regelverstößen
  - `hostenv/rules-pairs.php`: freie Paare suchen

## Änderung 1: `exit` innerhalb von `withoutBooster()`

Seit `dd9084b4` im Booster-Branch, hier enthalten.

### Ursache

`Booster::withoutBooster()` schaltet den Booster für die Dauer eines Callbacks ab und stellt ihn im `finally` wieder
her. Die Artikelsperre `withItemLock()` gibt ihre `GET_LOCK`-Sperre ebenfalls im `finally` frei.

`handleFormRequest()` leitete aber am Ende mit `wp_safe_redirect(); exit;` weiter, also **innerhalb** beider
Callbacks. Bei `exit` führt PHP keine `finally`-Blöcke aus, nur Shutdown-Funktionen. Das hatte zwei Folgen:

- **Der Booster blieb für den Rest des Requests umgangen.** Der Invalidator rechnet in `shutdown` die Verfügbarkeit
  des gebuchten Paars neu. Weil `Backfill::isReady()` → `Booster::canRead()` dabei `false` lieferte, lief die
  Neuberechnung mit den Originalabfragen: gemessen 650 statt 7 Abfragen, 0,8–1,7 s. Unter mod_php gibt es kein
  `fastcgi_finish_request()`, der Browser wartet also darauf.
- **Die Artikelsperre wurde erst beim Schließen der Datenbankverbindung frei.**

### Vorher

```php
// Plugin::handleBookingForms()
Booster::withoutBooster( [ Booking::class, 'handleFormRequest' ] );

// CustomPostType\Booking::handleFormRequest()
$postId = self::withItemLock( (int) $itemId, fn() => self::handleBookingRequest( … ) );
$post_slug = get_post( $postId )->post_name;
wp_safe_redirect( add_query_arg( self::getPostType(), $post_slug, home_url() ) );
exit;   // innerhalb von withoutBooster(): dessen finally läuft nicht
```

### Nachher

```php
// Plugin::handleBookingForms()
$postId = Booster::withoutBooster( [ Booking::class, 'processFormRequest' ] );
if ( $postId !== null ) {
    Booking::redirectToBooking( $postId );   // exit erst nach withoutBooster()
}

// CustomPostType\Booking
public static function processFormRequest(): ?int      // Nonce, Parameter, withItemLock + handleBookingRequest,
                                                       // gibt die Buchungs-ID zurück, ohne Nonce null
public static function redirectToBooking( int $postId ): void   // wp_safe_redirect + exit
public static function handleFormRequest()             // bleibt für bestehende Aufrufer:
                                                       // processFormRequest() + redirectToBooking()
```

### Warum das Verhalten gleich bleibt

Prüfung, Speichern, Fehlermeldungen und Ziel der Weiterleitung sind unverändert. Eine `BookingDeniedException` wird
weiterhin in `handleBookingForms()` gefangen. Geändert hat sich nur die Reihenfolge: Erst wird der Booster wieder
eingeschaltet und die Sperre freigegeben, danach folgt `exit`.

Ein `exit` bleibt innerhalb des Callbacks: der ICS-Download (`calendar-download`) am Anfang von
`handleBookingRequest()`. Dabei wird nichts geschrieben, also auch nichts neu berechnet, und der Request endet sofort.
Die Sperre gibt MySQL mit dem Verbindungsende frei. Diese Stelle wurde deshalb nicht geändert.

### Prüfen

1. Code: `grep -n "exit" src/Wordpress/CustomPostType/Booking.php` darf nur `redirectToBooking()` und den
   ICS-Download zeigen.
2. Laufzeit: Eine Buchung bestätigen und in `shutdown` die Abfragen zählen, z. B. mit einem Must-Use-Plugin, das per
   Filter `query` ab `shutdown` zählt. Vorher sind es ~650, nachher ~7. Alternativ `Booster::canRead()` in
   `shutdown` loggen: Der Wert muss `true` sein.

## Änderung 2: Eine Abfrage für Slot und Überlappungen

### Ursache

`handleBookingRequest()` fragte zweimal fast dasselbe ab:

- `getByDate()` sucht die Buchung mit genau diesem Slot, um Bearbeiten von einer Neuanlage zu unterscheiden.
- `getExistingBookings()` sucht alle überlappenden Buchungen außer dieser.

Auf großen Instanzen kostete jede der beiden Abfragen über 1 s (siehe Änderung 3).

### Vorher

```php
$booking = Repository\Booking::getByDate( $repetitionStart, $repetitionEnd, $locationId, $itemId );
…
$existingBookings = Repository\Booking::getExistingBookings(
    $itemId, $locationId, $repetitionStart, $repetitionEnd, $booking->ID ?? null
);
```

### Nachher

```php
$overlapping = $repetitionStart <= $repetitionEnd
    ? Repository\Booking::getByTimerange( $repetitionStart, $repetitionEnd, $locationId, $itemId )
    : null;
$booking = $overlapping !== null
    ? self::findExactSlot( $overlapping, $repetitionStart, $repetitionEnd )
    : Repository\Booking::getByDate( $repetitionStart, $repetitionEnd, $locationId, $itemId );  // ungültige Anfrage: wie bisher
…
$existingBookings = $overlapping !== null
    ? array_filter( $overlapping, fn( $other ) => $other->ID !== ( $booking->ID ?? null ) )
    : Repository\Booking::getExistingBookings( … );
```

`findExactSlot()` filtert die Treffer auf `(int) repetition-start === Start` und
`(string) repetition-end === (string) Ende`. Bei mehr als einem Treffer wirft die Methode dieselbe Exception wie
`getByDate()`.

### Warum das Ergebnis gleich bleibt

| Punkt | vorher (`getByDate` / `getExistingBookings`) | nachher |
|---|---|---|
| Status | beide `confirmed`, `unconfirmed` | Standard von `getByTimerange`: `confirmed`, `unconfirmed` |
| Paar | Artikel und Standort | dasselbe |
| Ist die Slot-Buchung unter den Treffern? | eigene Abfrage | Überlappung heißt Start ≤ E und Ende ≥ S. Eine Buchung mit Start = S und Ende = E erfüllt das, sofern S ≤ E. Deshalb gilt der neue Weg nur bei S ≤ E. |
| Vergleich mit dem exakten Slot | `CAST(start AS SIGNED) = S`, `end = 'E'` (Zeichenkette) | `(int) start === S`, `(string) end === 'E'` |
| mehrere exakte Treffer | Exception „Found more than one bookings“ | dieselbe Exception |
| Überlappungen ohne die Slot-Buchung | `getExistingBookings( …, $booking->ID )` | `array_filter` mit `!==` auf dieselbe ID |

Theoretisch gibt es einen Unterschied: MySQL vergleicht Zeichenketten mit der Tabellenkollation und ignoriert dabei
Leerzeichen am Ende, PHP vergleicht exakt. Ein Ende-Wert mit angehängten Leerzeichen kommt im Plugin nicht vor, weil
dort Zeitstempel gespeichert werden.

`getExistingBookings()` ist selbst nur `getByTimerange()` mit denselben Argumenten, aus dessen Ergebnis die
Buchung mit `$val->ID === $postId` entfernt wird. Der neue Weg ruft also dieselbe Abfrage auf und entfernt dieselbe
Buchung. Er spart nur die zweite Abfrage, die `getByDate()` bisher zusätzlich stellte.

### Prüfen

1. Code lesen: `handleBookingRequest()` und `findExactSlot()` in `src/Wordpress/CustomPostType/Booking.php`.
2. Bestehende Tests: `tests/php/Wordpress/CustomPostType/BookingTest.php` deckt Neuanlage, Bearbeiten, Überlappung und
   Stornieren ab.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter 'CustomPostType\\BookingTest'
   ```

## Änderung 3: Postmeta-Abfrage für ein Paar ohne `WP_Query`

### Ursache

`getByDate()` und `getByTimerange()` bauen eine `WP_Query` mit fünf Meta-Bedingungen. WordPress erzeugt daraus fünf
Joins auf `wp_postmeta`. MySQL beginnt bei `wp_posts` und liest **jede** Buchung der Instanz, mit je fünf
Postmeta-Lookups. Die Abfrage filtert zwar auf ein Paar, wächst aber mit der gesamten Buchungshistorie. Die Annahme in
`booster.md` („Eine Prüfung betrifft ein Paar und bleibt schnell“) galt deshalb nicht.

### Alte Abfrage

So erzeugt `WP_Query` die Abfrage (hostenv, Artikel 617, Standort 618, ein Tag):

```sql
SELECT wp_posts.* FROM wp_posts
 INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )
 INNER JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id )
 INNER JOIN wp_postmeta AS mt2 ON ( wp_posts.ID = mt2.post_id )
 INNER JOIN wp_postmeta AS mt3 ON ( wp_posts.ID = mt3.post_id )
 INNER JOIN wp_postmeta AS mt4 ON ( wp_posts.ID = mt4.post_id )
 WHERE 1=1 AND (
   ( wp_postmeta.meta_key = 'repetition-start' AND CAST(wp_postmeta.meta_value AS SIGNED) <= '1797292799' )
   AND ( mt1.meta_key = 'repetition-end' AND CAST(mt1.meta_value AS SIGNED) >= '1797206400' )
   AND ( mt2.meta_key = 'type' AND mt2.meta_value = '6' )
   AND ( mt3.meta_key = 'location-id' AND mt3.meta_value = '618' )
   AND ( mt4.meta_key = 'item-id' AND mt4.meta_value = '617' )
 ) AND wp_posts.post_type = 'cb_booking'
   AND ((wp_posts.post_status = 'confirmed' OR wp_posts.post_status = 'unconfirmed'))
 GROUP BY wp_posts.ID ORDER BY wp_posts.post_date DESC
```

```
1,263 s
id table        type  key      rows   Extra
1  wp_posts     index PRIMARY  99329  Using where; Using temporary; Using filesort
1  wp_postmeta  ref   post_id  11     Using where
1  mt1 … mt4    ref   post_id  11     Using where
```

### Neue Abfrage

`Repository\Booking::findForItemLegacy()`:

```sql
SELECT STRAIGHT_JOIN DISTINCT p.ID, p.post_date
 FROM wp_postmeta mi
 INNER JOIN wp_posts p ON p.ID = mi.post_id
 INNER JOIN wp_postmeta ml ON ml.post_id = p.ID AND ml.meta_key = 'location-id' AND ml.meta_value = '618'
 INNER JOIN wp_postmeta mt ON mt.post_id = p.ID AND mt.meta_key = 'type' AND mt.meta_value = '6'
 INNER JOIN wp_postmeta ms ON ms.post_id = p.ID AND ms.meta_key = 'repetition-start' AND CAST(ms.meta_value AS SIGNED) <= 1797292799
 INNER JOIN wp_postmeta me ON me.post_id = p.ID AND me.meta_key = 'repetition-end' AND CAST(me.meta_value AS SIGNED) >= 1797206400
 WHERE mi.meta_key = 'item-id' AND mi.meta_value = '617'
   AND p.post_type = 'cb_booking' AND p.post_status IN ('confirmed','unconfirmed')
 ORDER BY p.post_date DESC
```

```
0,126 s
id table  type    key       rows    Extra
1  mi     ref     meta_key  194070  Using where; Using temporary; Using filesort   (Schätzung: alle item-id-Zeilen)
1  p      eq_ref  PRIMARY   1       Using where
1  ml/mt/ms/me ref post_id  11      Using where; Distinct
```

Danach lädt `_prime_post_caches()` die gefundenen Posts samt Postmeta in einem Schritt. Daraus entstehen
`Model\Booking`-Objekte.

### Warum `STRAIGHT_JOIN`

Ohne diesen Hinweis wählt MySQL wieder `wp_posts` als erste Tabelle (gemessen 1,2 s). Mit `STRAIGHT_JOIN` gilt die
Reihenfolge der `FROM`-Klausel: zuerst die `item-id`-Zeilen über den Index `meta_key`, dann alles Weitere über
`post_id` bzw. `PRIMARY`.

| Variante | Zeit |
|---|---|
| `WP_Query` | 1,2–2,2 s |
| ab `item-id`, ohne `STRAIGHT_JOIN` | 1,2 s |
| ab `repetition-end` | 0,1–0,26 s, für vergangene Zeiträume 0,8 s |
| **ab `item-id`, mit `STRAIGHT_JOIN`** | **0,05–0,16 s, unabhängig vom Datum** |

Die Kosten wachsen weiter linear mit der Zahl aller `item-id`-Zeilen, weil der Core-Index `meta_key` den
`meta_value` nicht enthält, aber etwa 15-mal flacher als vorher. Neue Indizes auf Core-Tabellen wurden bewusst nicht
angelegt (Downgradebarkeit, Vorgabe „nur eigene Tabellen“).

### Bedingungen im Vergleich

| Bedingung | `WP_Query`-Meta-Query (vorher) | `findForItemLegacy()` |
|---|---|---|
| Artikel | `item-id = '617'` (Zeichenkette) | `mi.meta_value = '617'` |
| Standort (optional) | `location-id = '618'`, nur wenn angegeben | `ml`-Join, nur wenn angegeben |
| Typ | `type = '6'` | `mt.meta_value = '6'` |
| Zeitraum (`getByTimerange`) | `CAST(start AS SIGNED) <= Ende`, `CAST(end AS SIGNED) >= Start` | `start_max`, `end_min`: dasselbe |
| exakter Slot (`getByDate`) | `CAST(start AS SIGNED) = S`, `end = 'E'` (ohne CAST) | `start_exact`, `end_exact`: dasselbe |
| Status | `post_status = …` und Nachfilter in PHP (`getModelsFromQuery()`) | `post_status IN (…)` |
| doppelte Treffer | `GROUP BY wp_posts.ID` | `DISTINCT p.ID` |
| Reihenfolge | `post_date DESC` (WP_Query-Standard) | `ORDER BY p.post_date DESC` |

### Wann der neue Weg gilt

Siehe `src/Repository/Booking.php`.

- **`getByTimerange()`:** wenn alle drei Bedingungen erfüllt sind:
  - Der Index ist nicht lesbar, weil die Prüfung in `withoutBooster()` läuft oder der Booster aus bzw. nicht fertig
    ist.
  - Ein Artikel ist angegeben.
  - Es gibt keine `$customArgs`. Mit `$customArgs` (z. B. `author`, `orderby` der Buchungsregeln) läuft weiter
    `getByTimerangeWithMetaQuery()`, also die bisherige `WP_Query`.
- **`getByDate()`:** immer, wenn der Index nicht lesbar ist. Ein Artikel ist hier immer angegeben.

Außerhalb der Buchungsprüfung betrifft das bei abgeschaltetem Booster diese Aufrufer. Sie bekommen dieselben
Buchungen wie vorher, nur schneller:

- `Repository\Booking::getByRestriction()`
- `getExistingBookings()`, z. B. in `MassOperations` und `Model\Booking` für Überlappungen
- `Model\Booking::adjacent()` (Kettenbuchungen)

### Bewusst in Kauf genommene Unterschiede

- **`WP_Query`-Filter greifen nicht mehr.** `pre_get_posts`, `posts_where`, `posts_clauses` usw. wirken auf diesem
  Weg nicht. Im Plugin hängen solche Filter nur an der Hauptabfrage der Admin-Listen (`filterAdminList` prüft
  `is_main_query()`), also nicht hier. Ein Fremd-Plugin, das Buchungsabfragen per Filter verändert, würde hier nicht
  mehr wirken. Beim Booster-Index ist das genauso.
- **Keine Einschränkung nach Nutzer.** Die Buchungsstatus sind als `public` registriert
  (`src/Wordpress/PostStatus/PostStatus.php`), `WP_Query` schränkt sie also nicht nach Nutzer ein. Die direkte
  Abfrage verhält sich deshalb für jeden Nutzer gleich.
- **Kein `WP_Query`-Objekt-Cache.** Der Cache `post-queries` wird nicht genutzt. In der Prüfung soll ohnehin nichts aus
  Caches kommen.

Die bisherigen Abfragen bleiben als `getByDateWithMetaQuery()` und `getByTimerangeWithMetaQuery()` erhalten, als
`@internal` markiert. Sie dienen dem Paritätstest und weiterhin dem Fall mit `$customArgs`.

### Prüfen

1. **Paritätstest** `tests/php/Repository/BookingLegacyLookupTest.php`:
   - `testTimerangeMatchesMetaQuery`: vergleicht die neue mit der alten Abfrage über 8 Zeiträume × 3 Statusmengen ×
     5 Kombinationen aus Artikel und Standort. Dabei sind ein `null`-Standort, IDs als Zeichenketten, Grenzen auf
     die Sekunde, Stundenslots und ein Zeitraum über zwei Jahre.
   - `testExactSlotMatchesMetaQuery`: vergleicht `getByDate()` mit der alten Abfrage für 7 Slots × 2 Standorte.
     4 Treffer werden erwartet; ein Slot weicht beim Ende um 1 s ab und darf nichts finden.
   - Daten: ganze Tage mit den Status `confirmed`, `unconfirmed`, `canceled` und `draft`, Nachbarslots, ein anderer
     Artikel und ein anderer Standort sowie ein Zeitrahmen, der nicht gefunden werden darf.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingLegacyLookupTest
   ```
2. **SQL und `EXPLAIN` selbst ansehen:** In einer großen Instanz beide Methoden mit einem `query`-Filter aufrufen, der
   das SQL ausgibt, und `EXPLAIN <sql>` ausführen. So entstanden die Ausgaben oben.
3. **Auf echten Daten vergleichen**, z. B. per `wp eval` für zufällige Paare und Zeiträume:
   ```php
   $a = Booking::getByTimerangeWithMetaQuery( $s, $e, $loc, $item );
   $b = Booster::withoutBooster( fn() => Booking::getByTimerange( $s, $e, $loc, $item ) );
   // gleiche IDs in gleicher Reihenfolge (post_date DESC)
   ```

## Änderung 4: Buchungsregeln fragen nur die Buchungen des Nutzers ab

Die Buchungsregeln werden unter Einstellungen → Einschränkungen → Buchungsregeln konfiguriert.

### Ursache

Die Regeln mit Zeitraum rufen in `BookingRule::checkBookingRangeForDays()` und `checkBookingAmount()` diese Abfrage
auf. Das betrifft die Regeln „Tage pro Woche“, „Tage pro Monat“, „x Tage in y Tagen“, „Buchungen pro Woche“ und
„Buchungen pro Monat“.

```php
Repository\Booking::getByTimerange( $start, $end, null, null, [ 'orderby' => 'date', 'order' => 'ASC' ], $status );
```

Ohne Artikel und ohne Nutzer liefert sie **alle** Buchungen aller Nutzer im Zeitraum, auf hostenv ~2.500 je Monat.
Erst danach filtert `filterBookingsForTermsAndUser()` in PHP auf den Nutzer. Gemessen waren das ~1,5 s je Regel, mit
allen sieben Regeltypen 4,6 s nur für die Regeln. Den größten Teil kostet die `WP_Query` über alle Buchungen. Dazu
kommen hunderte Abfragen für Nutzer, Artikel und Kategorien der fremden Buchungen.

### Vorher

In beiden Methoden:

```php
$rangeBookingsArray = Repository\Booking::getByTimerange( $start, $end, null, null, $order, $countedPostTypes );
$rangeBookingsArray = self::filterBookingsForTermsAndUser( $rangeBookingsArray, $booking->getUserData(), $appliedTerms );
```

### Nachher

```php
$rangeBookingsArray = self::getUserBookingsInRange( $start, $end, $booking, $appliedTerms, $countedPostTypes );

private static function getUserBookingsInRange( \DateTime $start, \DateTime $end, Booking $booking, $appliedTerms, array $postStatus ): ?array {
    $order        = [ 'orderby' => 'date', 'order' => 'ASC' ];
    $user         = $booking->getUserData();
    $userBookings = Repository\Booking::getByTimerange( …, null, null, $order + [ 'author' => $user->ID ], $postStatus );
    if ( empty( $userBookings ) ) {
        return null;
    }
    if ( empty( $appliedTerms ) || Booking::filterTermsApply( $userBookings, $appliedTerms ) ) {
        return $userBookings;
    }
    // Sonderfall (siehe unten): wie bisher alle Buchungen laden
    $rangeBookings = Repository\Booking::getByTimerange( …, null, null, $order, $postStatus );
    return self::filterBookingsForTermsAndUser( $rangeBookings, $user, $appliedTerms );
}
```

Mit `author` braucht die Monatsabfrage 0,004–0,04 s statt 1,55 s, bei Nutzern mit 0 bis 17 Buchungen im Monat. Weil
`$customArgs` gesetzt sind, läuft sie weiter über `WP_Query` und nicht über Änderung 3.

### Warum das Ergebnis gleich bleibt

`filterBookingsForTermsAndUser( $alle, $user, $terms )` arbeitete bisher so:

- Es gibt `null` zurück, wenn **keine** Buchung im Zeitraum, egal von wem, eine der Kategorien hat.
- Sonst gibt es **alle** Buchungen des Nutzers zurück, auch die in anderen Kategorien.

Sei U die Menge der Buchungen des Nutzers im Zeitraum:

| Fall | bisher | neu |
|---|---|---|
| U ist leer | der Nutzerfilter ergibt `[]`, der Aufrufer meldet keinen Verstoß | `null`, kein Verstoß |
| Regel gilt für alle (`$appliedTerms` leer oder `false`) | `filterTermsApply()` gibt alle zurück, Ergebnis U | U |
| eine Buchung in U hat eine der Kategorien | dann hat auch „alle“ eine, Ergebnis U | U |
| keine Buchung in U hat eine der Kategorien | hängt von fremden Buchungen ab | bisherige Abfrage über alle, identisch |

`author` filtert auf `post_author`. `filterForUser()` prüft `getUserData()->ID`, also `get_userdata( post_author )`.
Beides ist dieselbe Spalte. Die Reihenfolge `post_date ASC` bleibt erhalten. Die Aufrufer zählen die Buchungen bzw.
summieren die Tage und listen die Buchungen in der Fehlermeldung auf.

Der Sonderfall in der letzten Zeile ist ein Fehler im Originalcode, denn die Kategorien werden an fremden Buchungen
geprüft. Er wird in `feature/booking-rule-fixes` behoben (siehe `design/buchungsregeln.md` dort). In `booster2`, wo
beide Branches zusammenkommen, entfällt der Sonderfall: Dort zählen immer nur die Buchungen in U, gefiltert nach
Kategorie.

Zwei Regeln brauchten keine Änderung:

- `checkSimultaneousBookings()` fragte schon vorher mit `author` ab (0,002 s).
- `checkChainBooking()` sucht die Nachbarbuchungen einzeln über `Model\Booking::adjacent()` mit Artikel und Standort.
  Sie profitiert von Änderung 3 und braucht weiter 0,06–0,18 s.

### Prüfen

1. **Paritätstest** `tests/php/Service/BookingRuleUserRangeTest.php`:
   - Er vergleicht `getUserBookingsInRange()` mit dem bisherigen Weg: alle Buchungen laden, dann
     `filterBookingsForTermsAndUser()`.
   - Abgedeckt sind 6 Zeiträume × 6 Kategorie-Sets × 2 Statusmengen, jeweils mit und ohne Booster. Unter den
     Kategorie-Sets sind `false`, `[]`, eine nicht genutzte Kategorie und der Sonderfall.
   - Daten:
     - Buchungen des Nutzers in zwei Kategorien und ohne Kategorie, darunter eine stornierte.
     - Buchungen eines anderen Nutzers in einer Kategorie, die der Nutzer im Zeitraum nicht hat.
   ```bash
   CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit --filter BookingRule
   ```
2. **Zeit je Regel auf einer großen Instanz:** `rules-bench.php` legt je Lauf eine unbestätigte Testbuchung an, prüft
   alle konfigurierten Regeln und löscht die Buchung wieder. Erwartet werden für alle Regeln außer der Kettenbuchung
   Zeiten unter 0,01 s.
   ```bash
   npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-bench.php 4
   ```
3. **Verhalten:** das Szenario `hostenv/rules-violations.py` (siehe unten). Die Ergebnisse müssen vor und nach der
   Änderung gleich sein.

## Verhalten der Buchungsregeln (Szenario mit Verstößen)

Konfiguration per `hostenv/rules-setup.php set`, stornierte Buchungen zählen mit:

| # | Regel | Einstellung |
|---|---|---|
| 1 | Keine gleichzeitigen Buchungen | alle |
| 2 | Keine Kettenbuchungen | alle |
| 3 | Tage pro Woche | 3, Rücksetzen Montag |
| 4 | Tage pro Monat | 6, Rücksetzen am 1., nur Kategorie Lastenrad |
| 5 | x Tage in y Tagen | 4 in 14 |
| 6 | Buchungen pro Woche | 2, Rücksetzen Montag |
| 7 | Buchungen pro Monat | 3, Rücksetzen am 1. |

Das Szenario läuft als Abonnent `regeltest` mit vier Artikeln ohne Lastenrad-Kategorie (A–D). Die Formulare werden
wie von einem Browser abgeschickt.

| # | Versuch | Ergebnis | Regel |
|---|---|---|---|
| 1 | A 30.11., 1 Tag | gebucht | – |
| 2 | B 30.11., 1 Tag | abgelehnt | 1 gleichzeitig |
| 3 | B 01.12., 1 Tag | gebucht | – |
| 4 | C 03.12., 1 Tag | abgelehnt | 6 (2 Buchungen in der Woche) |
| 5 | A 14.–16.12. | gebucht | – |
| 6 | A 17.12., 1 Tag | abgelehnt | 2 (Kette 4 Tage > max. 3) |
| 7 | D 19.12., 1 Tag | abgelehnt | 3 (3 + 1 Tage in der Woche) |
| 8 | D 21.–22.12. | abgelehnt | 5 (3 + 2 Tage in 14 Tagen) |
| 9 | D 28.12., 1 Tag | gebucht | – |
| 10 | C 24.12., 1 Tag | abgelehnt | 7 (3 Buchungen im Monat) |
| 11 | B 30.–31.12. | abgelehnt | 4, obwohl nur für Lastenrad konfiguriert (Auffälligkeit 1) |
| 12 | A 05.01., unbestätigt gelassen | angelegt | – |
| 13 | C 07.01., 1 Tag | abgelehnt | offene unbestätigte Buchung |

Jede Ablehnung zeigt die Meldung der Regel und die betroffenen Buchungen als Links. Vor und nach Änderung 4 kamen
dieselben Ergebnisse heraus. Die Zeit für Schritt 1 sank von ~5 s auf 0,5–0,8 s.

Auffälligkeiten im Originalcode. Die Punkte 1–3 sind in `feature/booking-rule-fixes` behoben, siehe
`design/buchungsregeln.md` dort.

1. **Kategorie-Einschränkung rutscht durch:** `BookingRuleApplied::init()` setzt `$appliesToAll` und `$appliedTerms`
   je Regel nicht zurück. Eine Regel nur für „Lastenrad“, die nach einer Regel „gilt für alle“ steht, gilt dadurch
   ebenfalls für alle.
2. **Falscher Wochentag in der Meldung:**
   - „Buchungen pro Woche“ mit Rücksetzen am Montag meldet „nach Tuesday“.
   - Freitag und Samstag als Rücksetztag werden falsch behandelt.
   - Die Wochentage erscheinen englisch.
3. **Kategorien beim Zählen:** siehe Änderung 4, Sonderfall.
4. **Kettenbuchungs-Meldung ohne Grund:** Sie lautet nur „Buchungslimit erreicht“. Das ist nicht behoben.

## Prüfanleitung (alles zusammen)

Im Arbeitsbereich, nicht im Checkout:

```bash
# Tests, statische Analyse, Codestil (wie die CI)
CB_ROOT=confirmation-optimisation dev-bench/phpunit/run-multi.sh vendor/bin/phpunit
(cd checkouts/confirmation-optimisation && vendor/bin/phpstan analyse --memory-limit=1G)
(cd checkouts/confirmation-optimisation && vendor/bin/phpcbf -q src templates includes tests commonsbooking.php)
```

Auf der großen Instanz (hostenv, Port 8891). Vorher in `hostenv/.wp-env.json` das Plugin auf diesen Checkout
stellen; die Befehle laufen im Ordner `hostenv/`.

```bash
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-setup.php set     # Regeln + Nutzer regeltest
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-bench.php 4       # Zeit je Regel
python3 rules-violations.py "<Cookie regeltest>" A=617:618,B=38:9,C=18:5,D=624:625   # Szenario oben
npx wp-env run cli -- wp eval-file wp-content/cb-hostenv/rules-setup.php clear   # Regeln wieder aus
```

Für das Szenario müssen die Tage frei sein. Freie Paare findet `rules-pairs.php`, bereits angelegte Buchungen von
`regeltest` vorher stornieren oder löschen.

Von Hand im Browser: als Abonnent einen freien Tag buchen und in den Entwicklerwerkzeugen (Netzwerk) die Antwortzeit
der Formular-Requests ansehen. Das ist die Weiterleitung mit Status 302 auf `?cb_booking=…`.

## Downgrade

Alle vier Änderungen betreffen nur Code. Es gibt keine neuen Tabellen, Optionen, Postmeta oder Indizes. Ein Wechsel
auf eine Version ohne diesen Branch braucht keine Aufräumarbeiten.

## Geprüft, nicht umgesetzt: Index für die Buchungsprüfung (optional)

Frage: Soll die Suche nach Konfliktbuchungen den Booster-Index nutzen, optional (abschaltbar, mit Hinweis im Backend)?

**Wie zuverlässig ist der Index?**

- **Synchrone Aktualisierung im selben Request:**
  - Hooks: `added/updated/deleted_post_meta`, `transition_post_status`, `save_post` (Priorität `PHP_INT_MAX`),
    `deleted_post`. `meta_input` ist vor `save_post` geschrieben.
  - Vor jedem Lesen werden offene Änderungen geschrieben (read-your-writes).
  - Frontend-Buchungen desselben Artikels laufen dank der Sperre nacheinander. Die neue Buchung steht im Index, bevor
    die Sperre freigegeben wird.
- **Rückfall während eines Neuaufbaus:** Ist der Index nicht fertig, nutzt jede Abfrage die Originalabfragen.
- **Abgleich:** Ein vollständiger Abgleich auf der großen Instanz (`wp commonsbooking index verify`) ergab
  **101.079 Beiträge, 0 Abweichungen, 0 verwaiste Zeilen**.

**Wo er abweichen kann.** In jedem dieser Fälle droht eine Doppelbuchung, wenn die Prüfung den Index nutzt.

1. Fehler beim Projizieren werden geloggt und verschluckt („Never break the actual request“). Die Buchung fehlt dann
   bis zur Reparatur.
2. Schreiben an WordPress vorbei (andere Plugins, SQL, phpMyAdmin, Importe):
   - Neue Posts erkennt der Wächter erst nach 120 s Karenz.
   - Status- und Datumsänderungen erkennt er stündlich.
   - Reine Meta-Änderungen erkennt er **nur über die tägliche Stichprobe von 200 Posts**. Bei 100.000 Buchungen ist
     das statistisch erst nach Jahren vollständig.
3. Phasen, in denen der Booster-Code nicht lief (Downgrade), erkennt der Wächter über die Versionsoption. Bis zum
   ersten Request danach ist der Index alt.

Die CB1-Migration schreibt Postmeta per SQL. Sie bleibt hier außer Betracht, weil sie mit einem der nächsten Updates
entfällt.

In einem normalen System ohne Fremdeingriffe liefert der Index korrekte Werte. Garantieren lässt sich das aber nicht.
Die Vorgabe „Prüfung nie aus dem Index“ schließt genau diese Restfälle aus.

**Abwägung:** Nach den Änderungen 1–4 dauert die Prüfung auf den Originaldaten 0,05–0,16 s je Abfrage. Der Index
brächte noch ~0,1 s je Request. Das rechtfertigt weder das Restrisiko noch eine zusätzliche Option.

**Entscheidung** (14.11.2026): nicht umsetzen, solange die Originalabfrage in diesem Rahmen bleibt. Ab etwa 500.000
Buchungen neu bewerten. Falls es doch nötig wird:

- den Index nur als Vorfilter nutzen,
- die Kandidaten und alle seit der letzten Wächterprüfung geänderten Posts auf Postmeta nachprüfen,
- bei einem Projektionsfehler bis zur Reparatur automatisch auf die Originalabfrage zurückfallen,
- die Option standardmäßig ausschalten.

## Offen

- Die Zeitrahmen-Suchen je Request zusammenfassen. Heute wird `Timeframe::get` für dasselbe Paar ~10-mal aufgerufen,
  je 0,06–0,1 s, zusammen ~0,3–0,5 s. Kleine Instanzen betrifft das kaum.
- Kettenbuchungs-Regel: Die Nachbarbuchungen werden einzeln gesucht (0,06–0,18 s).
- Die E-Mail beim Bestätigen läuft synchron. In der Testinstanz ist sie abgeschaltet; beim Hoster messen.
- Die Kettenbuchungs-Meldung nennt keinen Grund (Auffälligkeit 4).
