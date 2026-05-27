# AssociationManager

In diesem Projekt geht es um eine WebApp, die eine Verwaltung für Vereine zur Verfügung stellt.
Jeder Verein hat dabei seine eigene unabhängige Instanz (URL) zur Verfügung.

---

## Harte Regeln

1. **Verifikations-Pipeline nach jeder Code-Änderung** – folgende Kommandos nacheinander ausführen und nach jedem auf Fehler prüfen und korrigieren, bevor das nächste startet:
    - `composer run dev:lint:phpcs`
    - `composer run dev:analyze:phpstan`
    - `composer run dev:analyze:phpat`
    - `composer run test`

   Schlägt `dev:analyze:phpat` fehl, darfst du überlegen, ob eine Architekturregel angepasst werden sollte – **vorher nachfragen**, nie eigenmächtig ändern.

2. **Niemals direkt in die Datenbank schreiben.** Wenn du etwas an der DB ausprobieren willst, lege einen temporären Test an und führe ihn via `php artisan test --filter <ClassToTest>` aus.

3. **Tabu-Verzeichnisse:** In `./node_modules/` und `./vendor/` keine Dateien erstellen, ändern oder löschen. Der restliche Quellcode darf vollständig gelesen werden.

4. **PHPCS-Regeln (`phpcs.xml`) vor dem Schreiben prüfen**, um unnötige Lint-Roundtrips zu vermeiden.

5. **`composer.json` geändert?** Dann `composer update --lock` ausführen, damit der Hash in `composer.lock` aktualisiert wird.

---

## Arbeitsweise: Erst fragen, dann ändern

**Nichts annehmen. Unklarheiten nicht verbergen. Kompromisse offenlegen.**

- Wenn ich eine Frage stelle, beantworte sie – **keine Code-Änderungen ohne meine Bestätigung**.
- Annahmen explizit benennen. Bei Unsicherheit nachfragen.
- Wenn mehrere Interpretationen möglich sind, alle darlegen – nicht stillschweigend eine auswählen.
- Wenn es einen einfacheren Ansatz gibt, sag es. Wo angebracht, Einwände erheben.
- Wenn etwas unklar ist, innehalten. Benennen, was verwirrt. Nachfragen.

---

## Arbeitsweise: Minimal & punktgenau

**Minimaler Code, der das Problem löst. Nichts auf Vorrat. Nur den eigenen Schlamassel aufräumen.**

Beim Schreiben von Code:
- Keine Funktionen, Abstraktionen oder „Flexibilität" über das Geforderte hinaus.
- Keine Fehlerbehandlung für unmögliche Szenarien.
- Wenn du 200 Zeilen schreibst und es auch mit 50 ginge, schreib es neu.
- Selbsttest: „Würde ein erfahrener Entwickler sagen, das ist überkompliziert?" Wenn ja, vereinfachen.

Beim Bearbeiten von bestehendem Code:
- Nur anfassen, was unbedingt nötig ist. Minimal-invasiv arbeiten.
- Benachbarten Code, Kommentare oder Formatierung nicht „verbessern".
- **Nichts nebenbei refaktorieren.** Eigenständige Refactorings nur nach Rückfrage.
- Vorhandenen Stil übernehmen, auch wenn du es anders machen würdest.
- Toten Code, der dir auffällt: **darauf hinweisen, nicht löschen**.

Aufräumen verwaister Elemente:
- Imports/Variablen/Funktionen, die durch **deine** Änderungen ungenutzt wurden, entfernen.
- Bereits vorhandenen toten Code nur entfernen, wenn ich darum bitte.

Selbsttest: Jede geänderte Zeile sollte sich direkt auf meine Anfrage zurückführen lassen.

---

## Arbeitsweise: Test-getrieben & verifiziert

**Erfolgskriterien definieren. Wiederholen, bis verifiziert.**

Aufgaben in überprüfbare Ziele umwandeln:
- „Validierung hinzufügen" → „Tests für ungültige Eingaben schreiben und dann bestehen lassen"
- „Den Fehler beheben" → „Test schreiben, der ihn reproduziert, und dann bestehen lassen"
- „X refaktorieren" → „Sicherstellen, dass die Tests vorher und nachher bestehen"

Bei mehrstufigen Aufgaben einen kurzen Plan angeben:
```
1. [Schritt] → verifizieren: [Prüfung]
2. [Schritt] → verifizieren: [Prüfung]
3. [Schritt] → verifizieren: [Prüfung]
```

Test-Anforderungen für dieses Projekt:
- Neue Features ohne dazugehörige Tests gibt es nicht.
- Coverage-Ziel **für erreichbare Pfade**: ideal 100 %, Minimum 90 %. Keine Pseudo-Tests für unmögliche Szenarien (siehe „Minimal & punktgenau").
- Mocks/Stubs in Tests **so weit wie möglich vermeiden**.

Starke Erfolgskriterien ermöglichen eigenständiges Iterieren. Schwache Kriterien („bring es zum Laufen") erfordern ständige Rückfragen.

---

## Code-Konventionen

- **Architektur:** SOLID-Prinzipien und CQRS anwenden, wo sinnvoll. Im fachlichen Teil **DDD** (Domain-Driven Design).
- **Eloquent-Models:** `->saveOrFail()`, `->updateOrFail()`, `->deleteOrFail()` statt der Varianten ohne `OrFail` – Ausnahme: wenn bewusst keine Exception geworfen werden soll.
- **`assert()` vermeiden.**
- **DSGVO berücksichtigen** – die App richtet sich an den deutschen Markt.
- **Enums / Backed-Enums** statt Magic-Strings oder Magic-Numbers.
- **Code-Kommentare:** nur, wenn sie das **Warum** erklären – nicht das Was.
