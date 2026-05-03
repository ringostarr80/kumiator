<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditEmailHasher;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use Spatie\Activitylog\Facades\Activity;

/**
 * Request-scoped Marker + Audit-Helfer für nicht freigeschaltete Logins.
 *
 * Marker-Aspekt: Im Passwort-Pfad (`FortifyServiceProvider::authenticateUsing()`)
 * gibt das Closure `null` zurück, sobald der Account zwar valide Credentials
 * hat, aber noch nicht freigeschaltet ist (`approved_at === null`). Fortify
 * feuert daraufhin `Illuminate\Auth\Events\Failed`, das ohne Marker als
 * generischer `login_failed`-Eintrag landen würde — und zwar zusätzlich zum
 * bereits geschriebenen `login_unapproved`-Eintrag. Mit dem Marker überspringt
 * `LogAuthenticationActivityListener::handleFailed()` diesen Doppel-Log:
 * `login_unapproved` ist die fachlich präzise Aussage, `login_failed` wäre
 * redundant und würde Reports/Korrelationen verzerren.
 *
 * Audit-Helfer-Aspekt: `record()` kapselt das eigentliche Schreiben des
 * `login_unapproved`-Eintrags, damit Passkey- und Passwort-Pfad denselben
 * Code teilen (Hashing, Properties, Marker-Setzung, Translation-Key).
 *
 * Statisches Design ist hier vertretbar, weil PHP-Requests einen frischen
 * Prozess-Zustand haben (kein Carry-over zwischen Requests). In Tests muss
 * `clear()` zwischen Szenarien aufgerufen werden — der TestCase tut das
 * automatisch im `setUp()`.
 */
final class UnapprovedLoginContext implements UnapprovedLoginContextContract
{
    private const LOG_NAME = 'auth';

    private static bool $active = false;

    /**
     * Schreibt einen `login_unapproved`-Audit-Eintrag und setzt den Marker.
     *
     * Causer/Subject werden bewusst auf den User gesetzt: anders als bei
     * anonymen `login_failed`-Versuchen ist hier die Identität verifiziert
     * (Passwort-Hash bzw. Passkey-Verifikation war erfolgreich) — eine
     * Doppel-Speicherung als `email_hash` UND `causer_id` ist für die
     * Symmetrie zum `login_failed`-Pfad ausdrücklich gewünscht (erlaubt
     * Reports, die nur über `email_hash` korrelieren, ohne Causer aufzulösen).
     *
     * Der Marker wird auch dann gesetzt, wenn das eigentliche `Activity::log()`
     * fehlschlägt — sonst würde im Passwort-Pfad bei einem kaputten Audit-Pfad
     * trotzdem der nachfolgende `login_failed`-Eintrag rauschen.
     */
    public function record(User $user, string $guard, ?string $email): void
    {
        self::markActive();

        $properties = ['guard' => $guard];

        $emailHash = AuditEmailHasher::hash($email);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('login_unapproved')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties($properties)
            ->log(__('app.activity_login_unapproved'));
    }

    public static function markActive(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function clear(): void
    {
        self::$active = false;
    }
}
