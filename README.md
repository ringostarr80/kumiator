# AssociationManager

AssociationManager ist eine webbasierte Applikation zur zentralen Verwaltung eines Vereins.
Sie ermöglicht die Erfassung und Pflege von Mitgliedern, Beiträgen und weiteren vereinsinternen
Daten an einem Ort. Der Zugang ist passwortgeschützt und unterstützt
Zwei-Faktor-Authentifizierung (2FA).

## Ersten Benutzer anlegen

Nach der Erstinstallation gibt es noch keine Benutzer. Den ersten Administrator-Account
legt man direkt über die Kommandozeile an:

```bash
php artisan user:create
```

Der Befehl fragt interaktiv nach Name, E-Mail-Adresse und Passwort:

```
Neuen Benutzer anlegen
----------------------------
 Name: Max Mustermann
 E-Mail: max@example.com
 Passwort:
 Passwort bestätigen:

Benutzer "Max Mustermann" (max@example.com) wurde erfolgreich angelegt.
```

Anschließend kann man sich unter `/login` mit den angelegten Zugangsdaten einloggen.
