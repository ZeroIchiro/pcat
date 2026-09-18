# Änderungsprotokoll

## 2026-09-18

Seit Commit `0eb32fb` wurden folgende Änderungen vorgenommen:

- Neue geschützte Admin-Seite `author_price.php` für die Berechnung von Autorenpreisen.
- Printbücher können per Dropdown ausgewählt werden.
- Berechnung des Autorenpreises auf Grundlage von Brutto-Preis, 7 % Mehrwertsteuer, Druckkosten und einem frei wählbaren Rabatt.
- Der Rabatt ist standardmäßig mit 85 % vorbelegt und kann zwischen 0 und 100 % eingegeben werden.
- Die Ersparnis pro Exemplar wird separat ausgewiesen.
- Der Autorenrabatt wird als eigener Rechenposten angezeigt.
- Checkbox ergänzt, um alternativ die kumulierte Autorenmarge aller am Buch beteiligten Autoren als Rabatt zu verwenden.
- Das manuelle Rabattfeld wird bei aktivierter Margen-Checkbox deaktiviert.
- Die kumulierte Autorenmarge wird aus allen Buch-Autor-Zuordnungen berechnet.
- JavaScript für die unmittelbare Aktivierung und Deaktivierung des Rabattfelds ergänzt.
- Navigation im Admin-Bereich um den Link zum Autorenpreis-Rechner erweitert.
- CSS für die Anordnung von Rabattfeld und Checkbox ergänzt.
- PHP-, SQL- und Zugriffsschutz-Prüfungen für die neue Funktion durchgeführt.
