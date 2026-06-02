<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Activity-Log-Retention (Art. 5(1)(e) DSGVO — Speicherbegrenzung).
 *
 * `activitylog:clean` löscht Einträge, die älter sind als `clean_after_days`
 * aus `config/activitylog.php` (Default 365 Tage, per `ACTIVITYLOG_CLEAN_AFTER_DAYS`
 * überschreibbar). Ohne diesen Schedule würde die `activity_log`-Tabelle
 * unbegrenzt wachsen und die konfigurierte Retention wäre wirkungslos.
 *
 * Voraussetzung: Auf dem Zielsystem muss der Laravel-Scheduler via Cron laufen
 * (`* * * * * php artisan schedule:run`), siehe docs/operations.md.
 *
 * Optionen:
 *  - `dailyAt('03:30')` — Off-Peak, weg vom Mitternachts-Stau anderer Jobs.
 *  - `onOneServer()` — bei Multi-Server-Setup läuft nur ein Server den Job aus.
 *    Greift, weil `CACHE_STORE=database` als Default-Lock-Backend reicht.
 *  - `withoutOverlapping()` — defensive Absicherung für den Fall, dass die
 *    Tabelle stark gewachsen ist und ein Lauf länger als 24 h braucht.
 */
Schedule::command('activitylog:clean')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->withHealthcheck('activitylog_clean');

/*
 * Verkürzte Retention nur für den `forensic`-Kanal (anonyme Dritt-Daten:
 * `login_failed`, `login_locked_out`, `password_reset_requested` mit gekürzter
 * IP/User-Agent/E-Mail-Hash potenziell fremder Personen). Art. 5(1)(e) DSGVO —
 * Dritt-Daten dürfen nicht so lange wie der Mitglieder-Audit aufbewahrt werden.
 *
 * Spaties `activitylog:clean {log?} {--days=}` filtert via `inLog()` auf genau
 * diesen Kanal; Frist aus `config/activitylog.php` (Default 90 Tage). Versetzte
 * Minute (03:45) entzerrt gegenüber dem globalen 03:30-Lauf; eigener Healthcheck-
 * Slug, weil Healthchecks.io-Auto-Provisioning pro Job einen distinkten Slug
 * erwartet. Der globale Clean oben bleibt Catch-all für alle übrigen Kanäle.
 */
Schedule::command('activitylog:clean forensic --days=' . Config::integer('activitylog.clean_after_days_forensic'))
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping()
    ->withHealthcheck('activitylog_clean_forensic');

/*
 * Cleanup abgelaufener E-Mail-Änderungs-Anfragen (Art. 5(1)(c) DSGVO —
 * Datenminimierung; siehe `UserEmailChanger`-Doc).
 *
 * Stündliche Ausführung: die TTL beträgt 60 Minuten, häufiger als stündlich
 * räumt nichts Zusätzliches. Off-Peak-Minute (`:07`) verteilt Last gegenüber
 * Cron-Stunden-Spitzen.
 */
Schedule::command('user:cleanup-pending-email-changes')
    ->hourlyAt(7)
    ->onOneServer()
    ->withoutOverlapping()
    ->withHealthcheck('pending_email_cleanup');
