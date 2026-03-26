# ADR-0001: Programmiersprache für das Backend

**Datum:** 2026-03-17

**Status:** Akzeptiert

## Kontext

Für das Projekt AssociationManager musste eine Backend-Programmiersprache gewählt werden. Es handelt sich um eine Webanwendung zur Vereinsverwaltung, bei der jeder Verein eine eigene unabhängige Instanz (URL) erhält.

## Betrachtete Alternativen

- **PHP**
- **C#**

## Entscheidung

Ich habe mich für **PHP** als Backend-Sprache entschieden.

### Gründe

- **Erfahrung:** Ich habe die meiste Erfahrung mit PHP.
- **Web-Ökosystem:** PHP ist eine der am weitesten verbreiteten Sprachen im Web und bietet eine ausgereifte Infrastruktur für Webentwicklung.
- **Frameworks:** Es gibt eine große Auswahl an etablierten Frameworks, die übergreifende Themen wie Authentifizierung, Logging und weitere Querschnittsaspekte abdecken.

## Konsequenzen

- Das Backend wird vollständig in PHP entwickelt.
- Die Framework-Wahl wird in einer separaten Entscheidung dokumentiert.
- Neue Teammitglieder sollten PHP-Kenntnisse mitbringen oder bereit sein, diese aufzubauen.
