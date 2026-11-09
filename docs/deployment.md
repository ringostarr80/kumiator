[← Zurück zur README](../README.md)

# Production-Deployment

Dieses Dokument beschreibt das Deployment in eine Produktionsumgebung.
`composer setup` ist **ausschließlich** für das lokale Dev-Setup gedacht (es
kopiert `.env.example`, generiert einen neuen `APP_KEY` und installiert
Dev-Abhängigkeiten) und darf in Produktion nicht verwendet werden.

Den **laufenden Betrieb** nach dem Deploy (Scheduler/Cron, Schedule-Healthcheck)
beschreibt separat [docs/operations.md](operations.md).

## Datenbank

Unterstützt werden SQLite, MariaDB und PostgreSQL, die Gründe stehen in
[ADR-0006](ADR/0006-datenbanken.md). Die CI testet je eine Version, welche, steht in
`docker/test-databases/compose.yml`. MySQL wird nicht getestet.

**MariaDB** braucht `DB_CONNECTION=mariadb`, mit `DB_URL` das Schema `mariadb://`. Mit dem
Treiber `mysql` bricht `php artisan migrate` ab, weil die Migrationen sonst E-Mail- und
Passkey-Spalten anlegen, die Groß- und Kleinschreibung nicht unterscheiden.

**PostgreSQL** braucht eine UTF-8-Locale, etwa `en_US.utf8` wie im Test-Image. Unter der
Locale `C` unterscheidet die Namenssuche im Aktivitätslog bei Umlauten Groß- und
Kleinschreibung: `özdemir` findet dann `Özdemir` nicht. Die Locale wird beim Anlegen der
Datenbank festgelegt und lässt sich danach nicht mehr ändern.

## Deployment mit `composer deploy`

Alle Deploy-Schritte sind im `deploy`-Script in `composer.json` zusammengefasst und
werden in der korrekten Reihenfolge ausgeführt. Nach dem Ausrollen des neuen
Release-Stands auf den Zielserver einfach:

```bash
composer deploy
```

Auf dem Server muss Node.js in der Version aus `.nvmrc` laufen. Mit einer anderen
Version bricht `npm ci` ab, nachdem `composer install` bereits gelaufen ist.

Composer bricht bei Fehler in einem Schritt automatisch ab, sodass **nachfolgende**
Schritte nicht mehr laufen. Das ist **kein** Atomic Deploy: Bereits ausgeführte
Schritte (teilweise überschriebene Assets unter `public/build/`, teilweise
angewendete Migrationen) sind damit nicht rückgängig gemacht und das Live-System
kann für kurze Zeit in inkonsistentem Zustand sein. Für echte Zero-Downtime- und
Rollback-Fähigkeit ist ein Tool wie [Laravel Envoy](https://laravel.com/docs/envoy)
oder [Deployer](https://deployer.org/) notwendig, das mit Symlink-Switch zwischen
Release-Verzeichnissen arbeitet.
