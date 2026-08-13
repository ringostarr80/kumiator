# Kumiator

[![Pre-Alpha](https://img.shields.io/badge/Status-Pre--Alpha-orange)](https://github.com/ringostarr80/kumiator)
[![License](https://img.shields.io/github/license/ringostarr80/kumiator)](LICENSE)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)](https://laravel.com/)
[![CI Status](https://github.com/ringostarr80/kumiator/actions/workflows/php.yml/badge.svg)](https://github.com/ringostarr80/kumiator/actions/workflows/php.yml)
[![codecov](https://codecov.io/gh/ringostarr80/kumiator/branch/main/graph/badge.svg)](https://codecov.io/gh/ringostarr80/kumiator)
[![Quality gate](https://sonarcloud.io/api/project_badges/quality_gate?project=ringostarr80_kumiator)](https://sonarcloud.io/summary/new_code?id=ringostarr80_kumiator)

Kumiator ist eine webbasierte Applikation zur zentralen Verwaltung eines Vereins.
Sie ermöglicht die Erfassung und Pflege von Mitgliedern, Beiträgen und weiteren vereinsinternen
Daten an einem Ort. Der Zugang ist passwortgeschützt und unterstützt
Zwei-Faktor-Authentifizierung (2FA).

## Projektstatus

**Frühe Entwicklungsphase (Pre-Alpha) — es gibt noch kein Release, und der Einsatz im
Produktivbetrieb wird ausdrücklich nicht empfohlen.**

Fertiggestellt ist das technische Fundament: Registrierung mit Freischaltung durch
Administratoren, Anmeldung per Passwort, Zwei-Faktor-Authentifizierung und Passkeys, Rollen- und
Rechteverwaltung, geprüfter E-Mail-Wechsel, Sitzungsverwaltung, API-Token, ein Aktivitätsprotokoll
sowie CLI-Kommandos zur Benutzerverwaltung. Die Oberfläche liegt auf Deutsch und Englisch vor.

Die fachliche Vereinsverwaltung — Mitglieder, Beiträge und weitere vereinsinterne Daten — ist
dagegen noch nicht umgesetzt.

## Browser-Unterstützung

Die Oberfläche setzt Chrome/Edge 111, Firefox 128 und Safari 16.4 (auch auf iOS) voraus. Diese
Grenze gibt Tailwind CSS über seine Kompilierziele vor; sie lässt sich im Projekt nicht
einstellen. Das Stylesheet nutzt `oklch()` für seine Farbwerte ohne Rückfallebene — ältere
Browser verwerfen diese Deklarationen und stellen die Anwendung entstellt dar.

## Wortherkunft

Der Name verbindet einen japanischen mit einem lateinischen Bestandteil:

- **組合** (*kumiai*) — japanisch für Genossenschaft, Verband oder Vereinigung, zusammengesetzt aus
  組 (*kumi*, Gruppe) und 合 (*ai*, zusammenfügen).
- **-ator** — lateinisches Suffix für den Handelnden, wie in Kurator, Administrator oder Moderator.

Zusammengenommen: derjenige, der den Verein verwaltet.

## Entwicklung mit KI

Kumiator wird KI-gestützt entwickelt, aber nicht „gevibecoded": Der Code entsteht überwiegend
mit KI-Werkzeugen unter durchgehender menschlicher Aufsicht (*Human-in-the-Loop*). Jede Änderung
wird gelesen, geprüft und einzeln freigegeben, bevor sie in das Repository gelangt — es gibt hier
keine Zeile, die nicht ein Mensch verantwortet.

Abgesichert wird das durch eine Verifikations-Pipeline, die nach jeder Änderung läuft: statische
Analyse (PHPStan auf maximaler Stufe), Coding-Standards (PHP_CodeSniffer), Architekturregeln
(PHPat) und die vollständige Testsuite inklusive Coverage.

## Schnellstart (lokale Entwicklung)

Vorausgesetzt werden PHP 8.4 und Node.js — oder alternativ Docker, dann genügt Docker allein.
Als Datenbank dient standardmäßig SQLite, ein eigener Datenbankserver ist also nicht nötig.

### Mit Docker

Die Vorgaben in `.env.example` sind auf dieses Setup zugeschnitten: HTTPS auf Port 8443 mit einem
selbstsignierten Zertifikat, das beim ersten Start erzeugt wird.

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan db:seed --class=RoleSeeder
```

Der erste Start dauert einige Minuten, weil im Container die Abhängigkeiten installiert, die
Assets gebaut und die Migrationen ausgeführt werden. Danach ist die Anwendung unter
<https://localhost:8443> erreichbar; das selbstsignierte Zertifikat quittiert der Browser
mit einer Warnung.

### Ohne Docker

```bash
composer setup   # Abhängigkeiten, .env, App-Key, Migrationen, Rollen und Assets
composer dev     # Webserver, Queue-Worker, Logs und Vite in einem Rutsch
```

Die Anwendung läuft dann unter <http://localhost:8000>. Dafür sind in der `.env` zusätzlich
`APP_URL=http://localhost:8000` und `SESSION_SECURE_COOKIE=false` zu setzen — die Vorgaben
verlangen HTTPS, andernfalls nimmt der Browser das Sitzungs-Cookie nicht an.

### Ersten Benutzer anlegen

Über die Oberfläche registrierte Konten müssen erst von einem Administrator freigeschaltet werden.
Der allererste Zugang entsteht deshalb auf der Kommandozeile:

```bash
php artisan user:create
# mit Docker: docker compose exec app php artisan user:create
```

## CLI-Kommandos zur Benutzerverwaltung

Siehe [docs/cli-commands.md](docs/cli-commands.md).

## Production-Deployment

Siehe [docs/deployment.md](docs/deployment.md).

## Laufender Betrieb

Siehe [docs/operations.md](docs/operations.md).
