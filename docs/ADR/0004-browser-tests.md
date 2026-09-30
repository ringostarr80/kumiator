# ADR-0004: Werkzeug für Browser-Tests

**Datum:** 2026-09-30

**Status:** Akzeptiert

## Kontext

Nach dem Umstieg auf Tailwind v4 lagen die Dialoge auf der Profilseite unter ihrem eigenen grauen Hintergrund und ließen sich nicht mehr bedienen. Am Markup hatte sich nichts geändert, nur die Wirkung der vorhandenen Klasse `transform`. Die PHPUnit- und Livewire-Tests prüfen HTML und Zustand, die Vitest-Tests unter `tests/css` das gebaute Stylesheet als Text. Ob ein Element im Browser sichtbar ist und einen Klick bekommt, prüft keiner von ihnen. Das lässt sich nur in einem echten Browser feststellen.

## Betrachtete Alternativen

- **Laravel Dusk** — läuft mit PHPUnit und steuert den Browser über WebDriver
- **Playwright** (`@playwright/test`) — Testrunner für Node mit eigenen Browser-Builds

Beide melden einen verdeckten Knopf von selbst: WebDriver antwortet mit „element click intercepted“, Playwright klickt erst, wenn das Element „the hit target of the pointer event at the action point“ ist, und bricht sonst nach Ablauf der Wartezeit ab.

## Entscheidung

Ich habe mich für **Playwright** entschieden.

### Gründe

- **Fehlersuche:** Zu jedem fehlgeschlagenen Test entsteht ein Trace, der den Zustand der Seite bei jedem Schritt zeigt. Die CI lädt ihn mit dem Report als Artefakt hoch.
- **Werkzeugkette:** Die Tests sind TypeScript und laufen über npm; `tsc` prüft ihre Typen zusammen mit den übrigen Frontend-Tests.
- **Passender Browser:** `npx playwright install` lädt genau den Browser-Build, für den die installierte Playwright-Version gebaut ist.

## Konsequenzen

- Testdaten entstehen nicht im Test über Factories, sondern im `E2eSeeder`, der zu Beginn jedes Laufs in eine frische Datenbank schreibt. Jeder Test meldet sich mit einem eigenen Konto an, weil er es verändert.
- Die Tests laufen gegen `php artisan serve` mit `APP_ENV=e2e` und damit gegen `.env.e2e`. Fehlt die Datei, lädt Laravel stillschweigend die `.env`; `playwright.config.ts` bricht deshalb vorher ab.
- Zur PHP-Coverage tragen die Tests nichts bei, weil die App in einem eigenen Prozess läuft.
- Browser-Tests sind deutlich langsamer als die übrigen Tests und bleiben deshalb auf wenige Abläufe beschränkt, in einem eigenen CI-Job.
- Nach einem Update von `@playwright/test` muss lokal einmal der passende Browser nachgeladen werden (`npx playwright install chromium`).
