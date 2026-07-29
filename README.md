# Kumiator

Kumiator ist eine webbasierte Applikation zur zentralen Verwaltung eines Vereins.
Sie ermöglicht die Erfassung und Pflege von Mitgliedern, Beiträgen und weiteren vereinsinternen
Daten an einem Ort. Der Zugang ist passwortgeschützt und unterstützt
Zwei-Faktor-Authentifizierung (2FA).

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

## CLI-Kommandos zur Benutzerverwaltung

Siehe [docs/cli-commands.md](docs/cli-commands.md).

## Production-Deployment

Siehe [docs/deployment.md](docs/deployment.md).

## Laufender Betrieb

Siehe [docs/operations.md](docs/operations.md).
