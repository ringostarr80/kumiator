<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
