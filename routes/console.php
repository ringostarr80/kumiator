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
 * (`* * * * * php artisan schedule:run`), siehe docs/deployment.md.
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
    ->withoutOverlapping();
