# ADR-0003: WebAuthn-Bibliothek für Passkey-Authentifizierung

**Datum:** 2026-04-09

**Status:** Akzeptiert

## Kontext

Es soll eine Passkey-Authentifizierung (WebAuthn) implementiert werden, die parallel zur bestehenden Fortify/Jetstream-Passwort-Authentifizierung betrieben werden soll. Für die Implementierung des WebAuthn-Protokolls wurde eine geeignete PHP-Bibliothek benötigt.

## Betrachtete Alternativen

- **`web-auth/webauthn-lib`** (Spomky-Labs) — Low-Level WebAuthn-Implementierung, framework-agnostisch
- **`laragear/webauthn`** — Laravel-spezifische Abstraktion über WebAuthn
- **`asbiin/laravel-webauthn`** — Laravel-spezifische Abstraktion über WebAuthn

## Entscheidung

Ich habe mich für **`web-auth/webauthn-lib`** entschieden.

### Gründe

- **Vollständige Kontrolle über den Auth-Flow:** Die Bibliothek ist eine Low-Level-Implementierung ohne erzwungene Abstraktionen.
- **Framework-Unabhängigkeit:** `web-auth/webauthn-lib` ist nicht an Laravel gebunden, was die Freiheit gibt, die eigenen Services sauber nach DDD-Prinzipien zu strukturieren.
- **Vollständige Spec-Konformität:** Die Bibliothek folgt dem W3C WebAuthn-Standard eng und unterstützt alle gängigen Attestierungsformate und Extensions.
- **Weite Verbreitung:** `web-auth/webauthn-lib` ist der De-facto-Standard für WebAuthn in PHP und wird u.a. vom Symfony Security Bundle verwendet.

## Konsequenzen

- Die WebAuthn-Logik wird vollständig in eigenen Services und Repositories (DDD) implementiert.
- Für die PSR-7-Bridge werden `nyholm/psr7` und `symfony/psr-http-message-bridge` als zusätzliche Abhängigkeiten benötigt, da Laravel Request-Objekte in PSR-7 konvertiert werden müssen.
- Der höhere Implementierungsaufwand gegenüber den Laravel-Wrapper-Paketen wird bewusst in Kauf genommen, um Architekturfreiheit und langfristige Wartbarkeit zu gewährleisten.
