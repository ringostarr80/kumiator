[← Zurück zur README](../README.md)

# Betrieb

Dieses Dokument beschreibt den **laufenden Betrieb** der Anwendung nach dem
Deployment ([docs/deployment.md](deployment.md)): die zeitgesteuerten
Hintergrund-Aufgaben (Scheduler/Cron), den Queue-Worker für asynchrone Jobs und
deren Ausfall-Überwachung (Schedule-Healthcheck).

## Inhaltsverzeichnis

- [Scheduler / Cron-Setup](#scheduler--cron-setup)
  - [Einrichtung](#einrichtung)
  - [Verifikation](#verifikation)
  - [Aktuell registrierte Schedule-Einträge](#aktuell-registrierte-schedule-einträge)
- [Queue-Worker](#queue-worker)
  - [Worker als systemd-Service](#worker-als-systemd-service)
  - [Neustart nach jedem Deploy](#neustart-nach-jedem-deploy)
- [Schedule-Healthcheck (Healthchecks.io)](#schedule-healthcheck-healthchecksio)
  - [Modell: Push / Dead-Man-Switch](#modell-push--dead-man-switch)
  - [Einrichtung](#einrichtung-1)
  - [DSGVO](#dsgvo)

---

## Scheduler / Cron-Setup

Für Hintergrund-Aufgaben mit zeitlicher Steuerung nutzt das Projekt Laravels
Task Scheduler. Damit der Scheduler überhaupt etwas tut, muss auf dem Zielsystem
ein OS-Cron-Job laufen, der jede Minute `php artisan schedule:run` aufruft —
ohne diesen Eintrag werden die in `routes/console.php` definierten Schedules
**ignoriert**, und alle Retention- bzw. Wartungsläufe entfallen still.

### Einrichtung

In der `crontab` des Anwendungs-Users (`crontab -e`) folgenden Eintrag ergänzen:

```cron
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Bestandteile:

- `* * * * *` — Cron-Pattern „jede Minute". Der Scheduler entscheidet anhand
  der Schedule-Definitionen im Code selbst, was wann tatsächlich läuft.
- `cd /pfad/zur/app` — Arbeitsverzeichnis auf das Release der App setzen
  (anpassen je nach Server-Layout).
- `>> /dev/null 2>&1` — verwirft `stdout` und `stderr`. Ohne diese Umleitung
  würde Cron auf vielen Systemen pro Minute eine Mail an `root@` versenden.
  Wer Logging will, kann stattdessen z. B.
  `>> /var/log/laravel-schedule.log 2>&1` setzen.

### Verifikation

Nach dem Deploy prüft

```bash
php artisan schedule:list
```

ob alle erwarteten Schedule-Einträge registriert sind. Fehlt ein Eintrag,
liegt das in der Regel an einer veralteten Config-Cache-Datei
(`bootstrap/cache/config.php`); ein erneuter `composer deploy` oder ein
manuelles `php artisan config:clear` löst das.

### Aktuell registrierte Schedule-Einträge

| Command                              | Cadence            | Healthcheck-Slug        | Zweck                                                                                            |
|--------------------------------------|--------------------|-------------------------|--------------------------------------------------------------------------------------------------|
| `activitylog:clean`                  | täglich, **03:30** | `activitylog_clean`     | Activity-Log-Retention (alle Kanäle, 365 Tage) — Begründung & Frist siehe `config/activitylog.php` (Art. 5(1)(e) DSGVO). |
| `activitylog:clean forensic`         | täglich, **03:45** | `activitylog_clean_forensic` | Verkürzte Retention (90 Tage) nur für den `forensic`-Kanal (anonyme Dritt-Daten) — Art. 5(1)(e) DSGVO, siehe `config/activitylog.php`. |
| `user:cleanup-pending-email-changes` | stündlich, **:07** | `pending_email_cleanup` | Löscht abgelaufene E-Mail-Änderungs-Anfragen (TTL 60 Min) — Datenminimierung (Art. 5(1)(c) DSGVO). |

## Queue-Worker

Die Anwendung stellt E-Mail-Versand asynchron über Laravels Queue zu (Default
`QUEUE_CONNECTION=database`): So aufgeschobene Jobs — etwa die Bestätigungs- und
Abbruch-Mails beim E-Mail-Adress-Wechsel — werden **nicht** sofort abgearbeitet,
sondern als Zeile in die `jobs`-Tabelle geschrieben und warten dort auf einen
**dauerhaft laufenden Worker-Prozess**, der sie abholt.

Ohne diesen Prozess bleiben die Jobs unbemerkt in der Tabelle liegen: Die Mails
kommen **nie** an, und beim E-Mail-Wechsel räumt der stündliche
`user:cleanup-pending-email-changes`-Lauf den Antrag nach Ablauf der TTL wieder
weg — der Wechsel schlägt in Produktion still fehl, ganz **ohne** Fehlermeldung.

Anders als der Scheduler, den der Minuten-Cron nur kurz **anstößt**, ist der
Worker ein **langlebiger Daemon**. Er muss von einem Prozess-Manager überwacht,
bei Absturz neu gestartet und beim Boot automatisch hochgefahren werden. Cron
allein genügt dafür nicht.

### Worker als systemd-Service

Eine Unit-Datei unter `/etc/systemd/system/association-worker.service` anlegen
(Platzhalter an das Server-Layout anpassen):

```ini
[Unit]
Description=AssociationManager Queue-Worker
After=network.target

[Service]
User=<app-user>
Group=<app-group>
Restart=always
RestartSec=5
WorkingDirectory=/pfad/zur/app
ExecStart=/usr/bin/php /pfad/zur/app/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

Bestandteile:

- `Restart=always` / `RestartSec=5` — systemd hält den Worker am Leben und startet
  ihn nach Absturz oder geplantem Stopp (`queue:restart`, siehe unten) neu.
- `--tries=3` — ein fehlschlagender Job wird bis zu dreimal versucht, danach in
  die `failed_jobs`-Tabelle verschoben (`php artisan queue:failed` listet sie).
- `--max-time=3600` — der Worker beendet sich nach einer Stunde von selbst und
  wird von systemd frisch gestartet; das begrenzt den Speicherverbrauch
  langlebiger PHP-Prozesse.

Aktivieren und starten:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now association-worker.service
```

`systemctl status association-worker.service` zeigt anschließend den laufenden
Prozess. (Wer statt systemd Supervisor betreibt, bildet dasselbe mit einem
`[program:association-worker]`-Block und `autostart=true`/`autorestart=true` ab.)

### Neustart nach jedem Deploy

Ein laufender Worker hält den zum Startzeitpunkt geladenen Code dauerhaft im
Speicher — nach einem Deploy arbeitet er also weiter mit dem **alten** Stand.
Deshalb enthält das `deploy`-Script (`composer.json`) als letzten Schritt
`@php artisan queue:restart`: Das Kommando signalisiert allen Workern, sich nach
dem aktuellen Job sauber zu beenden; `Restart=always` fährt sie danach mit dem
neuen Code wieder hoch. Ohne diesen Schritt liefe der Worker unbegrenzt mit
veraltetem Code weiter.

## Schedule-Healthcheck (Healthchecks.io)

Beide oben gelisteten Schedules erfüllen DSGVO-Aufbewahrungspflichten. Fällt der
Master-Cron (`schedule:run`) still aus — Server-Reboot ohne Cron-Restart, falsche
crontab nach Deploy, Container ohne Cron-Daemon —, läuft **kein Fehler** auf:
die Tabellen wachsen unbemerkt weiter und verletzen die konfigurierte Retention.
Der Healthcheck macht diesen stillen Ausfall beobachtbar.

### Modell: Push / Dead-Man-Switch

Anders als bei klassischen Pull-Monitoren (die einen Endpunkt *anklopfen* und die
Antwort prüfen) ist hier die **App der Initiator**: jeder Job meldet sich nach
jedem Lauf bei Healthchecks.io. Das **Ausbleiben** der erwarteten Meldung ist das
Alarmsignal — der einzig mögliche Ansatz für kurzlebige Cron-Jobs, die keinen
dauerhaft erreichbaren Endpunkt bereitstellen. Vorteil nebenbei: nur **ausgehendes**
HTTPS nötig, keine eingehende Portfreigabe.

```
  VPS                                            Healthchecks.io
  ───                                            ───────────────
  cron  * * * * *  php artisan schedule:run
            │
            ▼  (um 03:30 fällig)
     Job activitylog:clean
       ├─ before()    ──►  ping /start ───────►  Lauf-Beginn registriert
       │  [ Job arbeitet ]
       ├─ onSuccess() ──►  ping (success) ────►  Check grün, Uhr läuft neu
       └─ onFailure() ──►  ping /fail ────────►  Check rot, SOFORT Alarm
                                                       │
            (Cron tot → gar kein Ping)                 │ Frist + Gnade
                                                       ▼  überschritten
                                                  🔴 Alarm an Notification-Channel
```

Die Ping-Phasen mappen auf die Healthchecks.io-URL-Suffixe:

| Phase     | URL-Suffix  | Hook                | Bedeutung                          |
|-----------|-------------|---------------------|------------------------------------|
| Start     | `/start`    | `->before()`        | Lauf-Beginn (Laufzeit-Messung)     |
| Success   | _(leer)_    | `->onSuccess()`     | Exit-Code 0 → Check grün           |
| Failure   | `/fail`     | `->onFailure()`     | Exit-Code ≠ 0 → sofortiger Alarm   |

Ein Healthchecks.io-Ausfall darf den Cron-Job nicht kippen: der Pinger
(`App\Services\Schedule\ScheduleHealthcheckPinger`) schluckt HTTP-Fehler und
loggt sie nur als Warnung.

### Einrichtung

1. Bei [Healthchecks.io](https://healthchecks.io) ein Projekt anlegen und dessen
   **Ping-Key** (Project Settings → API Access) übernehmen. Mit diesem einen Key
   werden alle Checks per Auto-Provisioning beim ersten Ping automatisch erzeugt.
2. In der Produktions-`.env` setzen:

   ```dotenv
   HEALTHCHECKS_PING_KEY=<projekt-ping-key>
   # optional, Default https://hc-ping.com — nur für self-hosted Instanzen:
   # HEALTHCHECKS_BASE_URL=https://hc.example.com/ping
   ```

   Ist `HEALTHCHECKS_PING_KEY` leer/ungesetzt (Default in local/testing),
   überspringt der Pinger sämtliche HTTP-Calls — es entstehen also keine Pings
   aus Dev-Umgebungen oder der Test-Suite.
3. Nach dem ersten erfolgreichen Lauf erscheinen beide Checks im Dashboard. Die
   erwartete Frequenz muss dort **einmalig pro Check** gesetzt werden
   (Auto-Provisioning legt als Default „every 1 day" an):

   | Check                        | Period (erwartet) | Grace (Toleranz) |
   |------------------------------|-------------------|------------------|
   | `activitylog_clean`          | 1 day             | 2 hours          |
   | `activitylog_clean_forensic` | 1 day             | 2 hours          |
   | `pending_email_cleanup`      | 1 hour            | 30 minutes       |

4. In den Project-Integrations einen Notification-Channel (Mail, Slack, …)
   hinterlegen, der bei rotem Check alarmiert.

### DSGVO

Die Heartbeat-Pings übertragen nur Ping-Key, Job-Slug und Zeitstempel — **keine**
personenbezogenen Daten aus der Anwendung. Healthchecks.io protokolliert
allerdings die Quell-IP des pingenden Servers (Feld `remote_addr` pro Ping, siehe
[Management API](https://healthchecks.io/docs/api/)); ob diese als
personenbezogenes Datum gilt, ist im Einzelfall (Betreiber, Hosting-Konstellation)
zu bewerten.

**Anbieter und Hosting liegen in der EU/im EWR:** Betreiber ist SIA Monkey See
Monkey Do (Rīga, Lettland; [About](https://healthchecks.io/about/)), die Server
stehen bei Hetzner im Rechenzentrum Falkenstein, Deutschland
([Hosting-Setup 2022](https://blog.healthchecks.io/2022/02/healthchecks-io-hosting-setup-2022-edition/)).
Eine Übermittlung in ein Drittland (Art. 44 ff. DSGVO) liegt damit **nicht** vor;
die Verarbeitung findet im EU/EWR-Raum statt. (Infrastruktur-Stand 2022 — bei
Bedarf erneut verifizieren.)

Davon unabhängig ist die Frage der Auftragsverarbeitung; sie hängt allein daran,
ob personenbezogene Daten verarbeitet werden:

- **Werden keine personenbezogenen Daten verarbeitet** (Server-IP als unkritisch
  eingestuft), liegt **keine Auftragsverarbeitung** vor: ein AVV/DPA nach Art. 28
  und ein Eintrag im Verzeichnis von Verarbeitungstätigkeiten (Art. 30) sind dann
  **nicht** erforderlich. Die Bewertung selbst sollte dokumentiert werden
  (Rechenschaftspflicht, Art. 5(2) DSGVO) — sie ist der Nachweis, dass geprüft wurde.
- **Wird die Server-IP** (oder eine künftig erweiterte Ping-Payload) **als
  personenbezogen eingestuft**, ist Healthchecks.io Auftragsverarbeiter: dann
  einen AVV/DPA nach Art. 28 abschließen und die Verarbeitung ins Verzeichnis
  aufnehmen. Ein zusätzlicher Transfermechanismus (SCCs o. ä.) ist wegen des
  EU/EWR-Hostings (siehe oben) **nicht** nötig.

**Self-Hosting als datenschutzfreundlichste Variante:** Healthchecks ist Open
Source (BSD-3-Clause) und offiziell selbst-hostbar (Docker-Images verfügbar,
siehe [Self-Hosted-Doku](https://healthchecks.io/docs/self_hosted/)). Betreibt
man eine eigene Instanz auf selbst kontrollierter Infrastruktur (z. B. derselben
VPS oder einem eigenen EU-Server), verlässt **kein** Ping die eigene
Verantwortlichen-Sphäre: Es gibt keinen externen Empfänger, damit entfallen die
Frage der Auftragsverarbeitung (Art. 28) und der Drittlandtransfer (Art. 44 ff.)
ganz — es bleibt reine interne Verarbeitung unter der ohnehin geltenden
Rechtsgrundlage und Retention. `HEALTHCHECKS_BASE_URL` zeigt dafür einfach auf die
eigene Instanz. (Läuft die Instanz bei einem Hosting-Dienstleister, ist dieser —
wie bei jeder selbst betriebenen Komponente — ggf. eigener Auftragsverarbeiter.)

> Keine Rechtsberatung — die finale Einordnung der Server-IP gehört zum
> Datenschutzbeauftragten / zur Rechtsberatung.
