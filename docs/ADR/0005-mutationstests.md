# ADR-0005: Mutationstests mit Infection

**Datum:** 2026-09-30

**Status:** Akzeptiert

## Kontext

Die Coverage-Schwelle von 90 % stellt sicher, dass die Tests eine Zeile ausführen, aber nicht, dass einer von ihnen scheitert, wenn sich die Zeile ändert. Ein Mutationstest verändert den Code gezielt – ein `continue` wird zum `break`, ein Methodenaufruf fällt weg – und prüft, ob mindestens ein Test die Veränderung bemerkt.

## Betrachtete Alternativen

- **Infection** — eigenständiges Werkzeug mit Adaptern für PHPUnit und weitere Test-Frameworks
- **Pest Mutation Testing** (`pest --mutate`) — Teil von Pest, läuft nur über den Pest-Runner

Humbug ist seit 2018 archiviert und verweist selbst auf Infection.

## Entscheidung

Ich habe mich für **Infection** entschieden.

### Gründe

- **Testframework:** Die Tests sind PHPUnit-Klassen und laufen ohne Pest. Für `pest --mutate` käme Pest als Runner dazu.
- **Geänderte Zeilen:** Infection mutiert per `--git-diff-lines` nur, was sich gegenüber `origin/main` geändert hat. Pest grenzt nur nach Pfad oder Klasse ein.
- **PHPStan:** Infection prüft überlebende Mutanten zusätzlich mit PHPStan, Pest nicht.

### Installation per Composer

Infection steht wie die übrigen Entwicklungswerkzeuge in `require-dev`. Laut Infection-Dokumentation ist die Phar der empfohlene Weg; sie bündelt die Adapter aller unterstützten Test-Frameworks, bringt ihre Abhängigkeiten per PhpScoper umbenannt mit und ist mit GPG signiert. Für Composer sprechen:

- **Lieferkette:** `composer audit` in der CI und Dependabot mit seiner Wartezeit für neue Versionen erfassen nur, was im `composer.lock` steht. Eine Phar müsste von Hand aktualisiert und geprüft werden.
- **Adapter:** Das Projekt testet nur mit PHPUnit, und dessen Adapter steckt auch im Composer-Paket.
- **Kapselung:** Die Abhängigkeiten von Infection vertragen sich mit denen des Projekts; das Lock bekam nur neue Pakete, keine geänderten Versionen. Blockiert Infection später ein Update, ist der Wechsel zur Phar billig, weil sich nur der Aufruf ändert.

## Konsequenzen

- Ein Volllauf dauert Stunden. Er läuft deshalb nur einmal im Monat und auf Abruf; Pull Requests und die lokale Verifikations-Pipeline mutieren nur die geänderten Zeilen unter `app/`.
- Bis der erste Volllauf Werte für `minMsi` und `minCoveredMsi` liefert, melden beide Läufe nur und blockieren nichts.
- Infection übernimmt Coverage und JUnit-Log aus dem Testlauf unmittelbar davor (`--skip-initial-tests`), statt die Suite ein zweites Mal auszuführen.
