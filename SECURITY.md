# Sicherheit

## Unterstützte Versionen

Kumiator befindet sich in einer frühen Entwicklungsphase (Pre-Alpha). Es gibt keine Releases und
keine Wartungszweige — Korrekturen fließen ausschließlich in den `main`-Branch. Sicherheitsupdates
für ältere Stände werden nicht bereitgestellt.

## Umfang

Diese Richtlinie gilt für die Software Kumiator, also den Code in diesem Repository. Jede
Kumiator-Instanz betreibt ein Verein selbst oder ein Dienstleister für ihn. Probleme, die nur eine
bestimmte Instanz betreffen — etwa Server, TLS-Zertifikat oder Konfiguration —, meldest du bitte
deren Betreiber. Hat der Betreiber seinen Kontakt eingerichtet, nennt die Instanz ihn unter
`/.well-known/security.txt`. Sonst wende dich an den Verein, der die Instanz nutzt.

## Eine Schwachstelle melden

**Bitte keine öffentlichen Issues für Sicherheitslücken anlegen.** Meldungen laufen über die private
Meldefunktion von GitHub: im Repository auf den Reiter „Security" wechseln und dort
„Report a vulnerability" wählen ([Direktlink](../../security/advisories/new)). Der Bericht ist
zunächst nur für die Projektbeteiligten sichtbar.

Hilfreich für die Einordnung sind:

- der betroffene Commit oder Stand des `main`-Branch,
- eine Beschreibung, wie sich das Problem reproduzieren lässt,
- die praktische Auswirkung — welche Daten oder Konten sind betroffen, welche Rechte werden
  vorausgesetzt.

## Testen

Bitte prüfe Kumiator nur auf einer eigenen, lokalen Installation. Laufende Instanzen enthalten echte
Mitgliederdaten. Tests dagegen erlaubt diese Richtlinie nicht, denn das könnte nur der jeweilige
Betreiber. Dass eine Instanz eine `security.txt` ausliefert, ist keine Einladung zum Testen.

## Was du erwarten kannst

Kumiator wird in der Freizeit entwickelt. Ich sichte eingehende Meldungen, so bald es mir möglich
ist, und melde mich zum weiteren Vorgehen zurück — feste Reaktionszeiten kann ich jedoch nicht
zusagen. Ein Bug-Bounty-Programm gibt es nicht.

Wird eine Meldung bestätigt, wird die Lücke im `main`-Branch behoben und anschließend über ein
GitHub Security Advisory veröffentlicht. Auf Wunsch nenne ich dich dort namentlich.

## Bekannte Einschränkung

Der Einsatz im Produktivbetrieb wird derzeit ausdrücklich nicht empfohlen (siehe
[README](README.md)). Wer Kumiator dennoch produktiv betreibt, tut dies auf eigenes Risiko.
