<?php

declare(strict_types=1);

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

/*
 * Konfiguration für spatie/laravel-activitylog.
 *
 * Diese Datei wurde bewusst aus dem Vendor-Default publish-t (statt sich auf
 * den Vendor-Default zu verlassen), damit DSGVO-relevante Einstellungen —
 * insbesondere die Retention (`clean_after_days`) — sichtbar versioniert sind
 * und bei Major-Updates des Pakets nicht stillschweigend mitwandern.
 *
 * **Retention / Speicherbegrenzung (Art. 5(1)(e) DSGVO)**
 * Activity-Log-Einträge werden nach `clean_after_days` automatisch gelöscht,
 * sobald `php artisan activitylog:clean` läuft (siehe Schedule in
 * routes/console.php). Der Default von 365 Tagen deckt einen vollen
 * Jahreszyklus eines Vereins ab (Mitgliederversammlung, Rechenschaftsbericht)
 * und ist für die nicht-buchhalterischen Vorgänge in dieser Tabelle
 * (Rollen-Änderungen, Profil-Edits, Passkey-Lifecycle) verhältnismäßig.
 *
 * **Verkürzte Frist für anonyme Dritt-Forensik** (`clean_after_days_forensic`)
 * Der `forensic`-Kanal (siehe {@see App\Enums\ActivityChannel::FORENSIC})
 * sammelt Fehlversuche/Anforderungen ohne authentifizierten Causer
 * (`login_failed`, `login_locked_out`, `password_reset_requested`,
 * `passkey_login_failed`) mit gekürzter IP, User-Agent, E-Mail-Hash bzw.
 * Credential-ID-Hash potenziell fremder Personen. Diese
 * Dritt-Daten dürfen nicht so lange wie der Mitglieder-Audit vorgehalten werden;
 * ein eigener Schedule löscht den Kanal nach 90 Tagen
 * (`activitylog:clean forensic --days=...`). Der globale 365-Tage-Clean bleibt
 * Catch-all für alle übrigen Kanäle (greift im `forensic`-Kanal nicht mehr,
 * weil der kürzere Lauf dort längst aufgeräumt hat).
 *
 * **Activity-Model (bewusst Vendor-Klasse)**
 * `activity_model` zeigt absichtlich auf `Spatie\Activitylog\Models\Activity`,
 * NICHT auf `App\Models\Activity`. Der App-eigene Wrapper ist heute leer und
 * existiert nur als Architektur-Anker für Lese-Zugriffe (siehe PHPDoc dort).
 * Sobald der Wrapper eigene Domain-Logik trägt, ist hier auf
 * `App\Models\Activity::class` umzustellen — und zwar im selben PR wie die
 * Logik-Erweiterung, damit Schreib- und Lese-Pfad symmetrisch bleiben.
 */

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * Default ist bewusst `true`: Ein Aus-Schalten verletzt die Rechenschafts-
     * pflicht aus Art. 5(2) / 32 DSGVO (kein Audit-Trail bei Vorfällen) und
     * sollte ausschließlich in CI-/Test-Setups erfolgen.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * When the clean command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'clean_after_days' => (int) env('ACTIVITYLOG_CLEAN_AFTER_DAYS', 365),

    /*
     * Verkürzte Retention nur für den `forensic`-Kanal (anonyme Dritt-Daten).
     * Wird vom dedizierten Schedule in routes/console.php an
     * `activitylog:clean forensic --days=...` durchgereicht. Siehe Doc oben
     * (Art. 5(1)(e) DSGVO — Speicherbegrenzung für Dritt-Daten).
     */
    'clean_after_days_forensic' => (int) env('ACTIVITYLOG_CLEAN_AFTER_DAYS_FORENSIC', 90),

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     * This can significantly reduce the number of database queries when
     * many activities are logged during a single request.
     *
     * Only enable this if your application logs a high volume of activities
     * per request. Buffered activities will not have an ID until the
     * buffer is flushed.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
