[← Zurück zur README](../README.md)

# Production-Deployment

Dieses Dokument beschreibt das Deployment in eine Produktionsumgebung.
`composer setup` ist **ausschließlich** für das lokale Dev-Setup gedacht (es
kopiert `.env.example`, generiert einen neuen `APP_KEY` und installiert
Dev-Abhängigkeiten) und darf in Produktion nicht verwendet werden.

## Deployment mit `composer deploy`

Alle Deploy-Schritte sind im `deploy`-Script in `composer.json` zusammengefasst und
werden in der korrekten Reihenfolge ausgeführt. Nach dem Ausrollen des neuen
Release-Stands auf den Zielserver einfach:

```bash
composer deploy
```

Composer bricht bei Fehler in einem Schritt automatisch ab, sodass **nachfolgende**
Schritte nicht mehr laufen. Das ist **kein** Atomic Deploy: Bereits ausgeführte
Schritte (teilweise überschriebene Assets unter `public/build/`, teilweise
angewendete Migrationen) sind damit nicht rückgängig gemacht und das Live-System
kann für kurze Zeit in inkonsistentem Zustand sein. Für echte Zero-Downtime- und
Rollback-Fähigkeit ist ein Tool wie [Laravel Envoy](https://laravel.com/docs/envoy)
oder [Deployer](https://deployer.org/) notwendig, das mit Symlink-Switch zwischen
Release-Verzeichnissen arbeitet.

## Scheduler / Cron-Setup

Für Hintergrund-Aufgaben mit zeitlicher Steuerung nutzt das Projekt Laravels
Task Scheduler. Damit der Scheduler überhaupt etwas tut, muss auf dem Zielsystem
ein OS-Cron-Job laufen, der jede Minute `php artisan schedule:run` aufruft —
ohne diesen Eintrag werden die in `routes/console.php` definierten Schedules
**ignoriert**, und alle Retention- bzw. Wartungsläufe entfallen still.

### Einrichtung

In der `crontab` des Anwendungs-Users (`crontab -e`) folgenden Eintrag ergänzen:

```cron
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Bestandteile:

- `* * * * *` — Cron-Pattern „jede Minute". Der Scheduler entscheidet anhand
  der Schedule-Definitionen im Code selbst, was wann tatsächlich läuft.
- `cd /pfad/zur/app` — Arbeitsverzeichnis auf das Release der App setzen
  (anpassen je nach Server-Layout).
- `>> /dev/null 2>&1` — verwirft `stdout` und `stderr`. Ohne diese Umleitung
  würde Cron auf vielen Systemen pro Minute eine Mail an `root@` versenden.
  Wer Logging will, kann stattdessen z. B.
  `>> /var/log/laravel-schedule.log 2>&1` setzen.

### Verifikation

Nach dem Deploy prüft

```bash
php artisan schedule:list
```

ob alle erwarteten Schedule-Einträge registriert sind. Fehlt ein Eintrag,
liegt das in der Regel an einer veralteten Config-Cache-Datei
(`bootstrap/cache/config.php`); ein erneuter `composer deploy` oder ein
manuelles `php artisan config:clear` löst das.

### Aktuell registrierte Schedule-Einträge

| Command             | Cadence            | Zweck                                                                                            |
|---------------------|--------------------|--------------------------------------------------------------------------------------------------|
| `activitylog:clean` | täglich, **03:30** | Activity-Log-Retention — Begründung & Frist siehe `config/activitylog.php` (Art. 5(1)(e) DSGVO). |

