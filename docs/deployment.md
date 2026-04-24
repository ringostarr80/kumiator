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
