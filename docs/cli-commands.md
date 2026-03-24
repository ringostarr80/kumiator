[← Zurück zur README](../README.md)

# CLI-Kommandos

Alle Verwaltungsaufgaben können direkt über die Kommandozeile durchgeführt werden.
Die Sprache der Ausgaben richtet sich nach der in `.env` gesetzten `APP_LOCALE`.

## Inhaltsverzeichnis

- [Benutzerverwaltung](#benutzerverwaltung)
  - [Benutzer anlegen (`user:create`)](#benutzer-anlegen-usercreate)
  - [Benutzer auflisten (`user:list`)](#benutzer-auflisten-userlist)
  - [Benutzer verifizieren (`user:verify`)](#benutzer-verifizieren-userverify)
  - [Benutzer löschen (`user:delete`)](#benutzer-löschen-userdelete)
- [Rollenverwaltung](#rollenverwaltung)
  - [Rolle anlegen (`role:create`)](#rolle-anlegen-rolecreate)
  - [Rolle zuweisen (`role:assign`)](#rolle-zuweisen-roleassign)
  - [Rollen auflisten (`role:list`)](#rollen-auflisten-rolelist)
  - [Rolle löschen (`role:delete`)](#rolle-löschen-roledelete)

---

# Benutzerverwaltung

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
+----------------+----------------------+-------+------------------+
| Name           | E-Mail               | Rolle | Erstellt am      |
+----------------+----------------------+-------+------------------+
| Max Mustermann | max@example.com      | admin | 19.03.2026 10:00 |
+----------------+----------------------+-------+------------------+
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

---

# Rollenverwaltung

## Rolle anlegen (`role:create`)

Legt eine neue Rolle an:

```bash
php artisan role:create
```

Der Befehl fragt interaktiv nach dem Rollennamen:

```
Neue Rolle anlegen
------------------
 Rollenname: admin

Rolle "admin" wurde erfolgreich angelegt.
```

## Rolle zuweisen (`role:assign`)

Weist einem Benutzer eine Rolle zu. Eine eventuell bereits zugewiesene Rolle wird dabei ersetzt:

```bash
php artisan role:assign
```

Der Befehl fragt nach der E-Mail-Adresse des Benutzers und bietet die verfügbaren Rollen zur Auswahl an:

```
Rolle zuweisen
--------------
 E-Mail: max@example.com
 Rolle:
  [0] admin
  [1] member
 > 0

Benutzer "Max Mustermann" (max@example.com) wurde die Rolle "admin" erfolgreich zugewiesen.
```

## Rollen auflisten (`role:list`)

Gibt eine tabellarische Übersicht aller vorhandenen Rollen aus:

```bash
php artisan role:list
```

```
+-------+----------+------------------+
| Name  | Benutzer | Erstellt am      |
+-------+----------+------------------+
| admin | 1        | 19.03.2026 10:00 |
+-------+----------+------------------+
Gesamt: 1 Rollen
```

## Rolle löschen (`role:delete`)

Löscht eine Rolle nach Bestätigung unwiderruflich:

```bash
php artisan role:delete
```

```
Rolle löschen
-------------
 Rollenname: admin

Rolle gefunden: admin (1 Benutzer zugewiesen)
 Soll diese Rolle wirklich gelöscht werden? (yes/no) [no]: yes

Rolle "admin" wurde erfolgreich gelöscht.
```
