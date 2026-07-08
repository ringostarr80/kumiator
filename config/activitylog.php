<?php

declare(strict_types=1);

use App\Models\Activity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

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
 * **Activity-Model (`App\Models\Activity`)**
 * `activity_model` zeigt auf den App-eigenen Wrapper, damit Schreib- und
 * Lese-Pfad symmetrisch über dieselbe Klasse laufen. Der Wrapper trägt
 * eigene Domain-Logik: die `description`-Spalte wurde entfernt und wird zur
 * Lesezeit aus dem `event`-Code abgeleitet (siehe PHPDoc dort). Modell-Events
 * sind klassengebunden — die `Activity::saving`-Hooks im AppServiceProvider
 * müssen daher ebenfalls auf `App\Models\Activity` registriert sein.
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
     * Bewusst hart `false` statt `env(...)`: Spaties Buffer sammelt die Einträge
     * im Speicher und schreibt sie per Query-Builder-`insert()` — also an allen
     * Model-Events vorbei. Der `Activity::saving`-Hook im AppServiceProvider
     * (Event-Remapping, Self-Registration-Marker, CLI-Causer-Anonymisierung)
     * feuerte dann nie, still und ohne Fehler. Weil der publizierte Wert den
     * Vendor-Default überlagert, bleibt `ACTIVITYLOG_BUFFER_ENABLED` wirkungslos.
     * Der Buffer spart ohnehin nur Queries bei vielen Einträgen pro Request —
     * diese Anwendung schreibt einen, selten zwei.
     */
    'buffer' => [
        'enabled' => false,
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
