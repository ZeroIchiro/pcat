Einrichtung des Admin-Bereichs

1. Datenbank und Benutzer anlegen und anschließend tat_schema.sql importieren.
2. Die Platzhalter in inc/db.inc.php ersetzen oder besser eine geschützte Konfiguration außerhalb des Webroots verwenden.
3. Einen Admin-Benutzer mit einem sicheren Passwort anlegen. Beispiel für einen Hash:

   php -r 'echo password_hash("HIER_EIN_LANGES_EINMALIGES_PASSWORT", PASSWORD_DEFAULT), PHP_EOL;'

   INSERT INTO admin_users (username, password_hash)
   VALUES ('admin', 'HIER_DEN_HASH_EINSETZEN');

4. Danach ist der Bereich unter /tat/admin.php erreichbar.

Die Anwendung verwendet PDO mit echten Prepared Statements, CSRF-Schutz,
Session-Härtung und getrennte CSS-/JavaScript-Dateien.

CSV-Import für die Abrechnung

Auf der Seite /tat/billing.php kann eine JTL-Verkaufs-CSV hochgeladen werden.
Die Datei muss mindestens diese Spalten enthalten:

   Kundengruppe;ISBN;Menge
   Großhandel;9781234567890;25
   Buchhandel;9781234567890;12
   Endkunden;9781234567890;8

Erlaubte Spaltennamen:
- Kundengruppe: Kundengruppe, Kundengruppen oder Gruppe
- Buchkennung: ISBN, ISBN/EAN, EAN oder ISBN Print
- Verkaufsmenge: Menge, Anzahl, Verkaufsmenge oder Quantity

Unterstützte CSV-Trennzeichen sind Semikolon, Komma und Tabulator. Die Print-ISBN
wird mit der in TAT hinterlegten Print-ISBN verglichen. Es werden nur Bücher mit
Print-ISBN verarbeitet; eBook-only-Bücher bleiben ausgeschlossen.

Die Kundengruppen werden wie folgt den Abrechnungswegen zugeordnet:
- Großhandel, Grosshandel oder Buchgrosshandel -> Großhandel
- Buchhandel -> Buchhandel
- Endkunden, Direktkunden, Direkt oder Privatkunden -> Direkt

Mehrere Zeilen für dieselbe ISBN und Kundengruppe werden addiert. Nach dem Upload
zeigt TAT eine Vorschau. Erst nach der Bestätigung und Auswahl der gewünschten
Bücher werden die Mengen gespeichert. Die bisher gespeicherten Mengen der
bestätigten Bücher werden dabei ersetzt; nicht ausgewählte Bücher bleiben unverändert.
Die maximale Dateigröße beträgt 5 MB.

CSV-Struktur für die eBook-Abrechnung

Für eBooks wird eine separate CSV-Datei verwendet. Der Verkaufsweg wird
beibehalten, beeinflusst die Berechnung aber nicht. Es gibt keine Zuordnung zu
Großhandel, Buchhandel oder Direktverkauf.

Verbindliche Spaltenstruktur:

   Verkaufsdatum;Verkaufsweg;ISBN;Titel;Erlös
   2026-01-05;Amazon Kindle;9781234567890;Buchtitel A;17,97
   2026-01-06;Apple Books;9781234567890;Buchtitel A;11,98
   2026-01-08;Tolino;9781234567890;Buchtitel A;5,99
   2026-01-10;Amazon Kindle;9789876543210;Buchtitel B;23,96

Pflichtspalten:
- Verkaufsdatum: Verkaufs- oder Abrechnungsdatum des Distributors; daraus wird der Abrechnungsmonat abgeleitet
- Verkaufsweg: Der vom Distributor gelieferte Verkaufsweg, zum Beispiel Amazon Kindle, Apple Books oder Tolino
- ISBN: Abgleich mit der in TAT hinterlegten eBook-ISBN
- Titel: Bezeichnung des eBooks zur Kontrolle
- Erlös: Der vom Distributor gemeldete Erlös dieser Zeile in Euro

Die CSV enthält keine Spalte für Abrechnungszeitraum, Transaktionsnummer,
Menge, Währung oder Korrektur. Der Abrechnungszeitraum wird aus dem
Verkaufsdatum abgeleitet. Der Erlös ist der vollständige Betrag der jeweiligen
Zeile. Stornos oder Korrekturen werden als negativer Erlös übertragen.

Der Erlös wird unverändert übernommen. TAT zieht keine Druckkosten,
Vertriebskosten oder sonstige Abzüge ab. Die Berechnung lautet:

   Autorenanteil = Erlös x Autorenmarge / 100

Beispiel:

   Erlös: 17,97 EUR
   Autorenmarge: 50 Prozent
   Autorenanteil: 8,985 EUR, angezeigt als 8,99 EUR

Mehrere Zeilen werden nach eBook, Verkaufsdatum und Verkaufsweg
zusammengefasst. Der Verkaufsweg bleibt dabei in der Auswertung erhalten und
wird nicht in eine Print-Vertriebskategorie umgewandelt.

Die Datei wird als UTF-8-CSV mit Semikolon als Trennzeichen und Dezimalkomma
bereitgestellt. Die ISBN ist der eindeutige Buchschlüssel; der Titel dient nur
der Kontrolle.
