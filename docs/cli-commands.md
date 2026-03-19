[← Zurück zur README](../README.md)

# CLI-Kommandos zur Benutzerverwaltung

Alle Benutzerverwaltungsaufgaben können direkt über die Kommandozeile durchgeführt werden.
Die Sprache der Ausgaben richtet sich nach der in `.env` gesetzten `APP_LOCALE`.

## Benutzer anlegen (`user:create`)

Nach der Erstinstallation gibt es noch keine Benutzer. Den ersten Administrator-Account
legt man direkt über die Kommandozeile an:

```bash
php artisan user:create
```

Der Befehl fragt interaktiv nach Name, E-Mail-Adresse und Passwort:

```
Neuen Benutzer anlegen
----------------------
 Name: Max Mustermann
 E-Mail: max@example.com
 Passwort:
 Passwort bestätigen:

Benutzer "Max Mustermann" (max@example.com) wurde erfolgreich angelegt.
```

Anschließend kann man sich unter `/login` mit den angelegten Zugangsdaten einloggen.

## Benutzer auflisten (`user:list`)

Gibt eine tabellarische Übersicht aller vorhandenen Benutzer aus:

```bash
php artisan user:list
```

```
+----------------+----------------------+------------------+
| Name           | E-Mail               | Erstellt am      |
+----------------+----------------------+------------------+
| Max Mustermann | max@example.com      | 19.03.2026 10:00 |
+----------------+----------------------+------------------+
Gesamt: 1 Benutzer
```

## Benutzer verifizieren (`user:verify`)

Verifiziert die E-Mail-Adresse eines Benutzers manuell, ohne dass dieser den
Bestätigungslink anklicken muss:

```bash
php artisan user:verify
```

```
Benutzer verifizieren
---------------------
 E-Mail: max@example.com

Benutzer "Max Mustermann" (max@example.com) wurde erfolgreich verifiziert.
```

## Benutzer löschen (`user:delete`)

Löscht einen Benutzer nach Bestätigung unwiderruflich:

```bash
php artisan user:delete
```

```
Benutzer löschen
----------------
 E-Mail: max@example.com

Benutzer gefunden: Max Mustermann (max@example.com)
 Soll dieser Benutzer wirklich gelöscht werden? (yes/no) [no]: yes

Benutzer "Max Mustermann" (max@example.com) wurde erfolgreich gelöscht.
```
