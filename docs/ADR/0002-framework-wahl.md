# ADR-0002: Framework-Wahl für das Backend

**Datum:** 2026-03-17

**Status:** Akzeptiert

## Kontext

Nach der Entscheidung für PHP als Backend-Sprache (siehe [ADR-0001](0001-programmiersprache.md)) musste ein geeignetes Framework für die Entwicklung des AssociationManagers gewählt werden.

## Betrachtete Alternativen

- **Laravel**
- **Symfony**
- **Pures PHP** (ohne Framework)

## Entscheidung

Ich habe mich für **Laravel** als Backend-Framework entschieden.

### Gründe

- **Erfahrung:** Erste gute berufliche Erfahrungen mit Laravel waren bereits vorhanden.
- **Routing:** Leichtes Handling von URL-Routes.
- **Authentifizierung & Autorisierung:** Laravel bietet hierfür ausgereifte, integrierte Lösungen.
- **Dokumentation & Pflege:** Laravel ist gut dokumentiert und wird aktiv gepflegt.

## Konsequenzen

- Das Backend basiert auf dem Laravel-Framework und folgt dessen Konventionen.
- Abhängigkeit von Laravels Release- und Support-Zyklen.
- Neue Teammitglieder sollten Laravel-Kenntnisse mitbringen oder bereit sein, diese aufzubauen.
