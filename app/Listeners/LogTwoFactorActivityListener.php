<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Spatie\Activitylog\Facades\Activity;

/**
 * Schreibt Activity-Log-Einträge für Fortify-2FA-Ereignisse: Aktivierung,
 * Bestätigung, Deaktivierung sowie Recovery-Code-Lifecycle und fehlgeschlagene
 * Code-Eingaben. Symmetrie zum {@see LogAuthenticationActivityListener},
 * gleicher `log_name` ('auth'), damit der gesamte Authentifizierungs-Trail in
 * einem Filter abrufbar ist.
 *
 * Sicherheitsbedeutung (DSGVO Art. 32): Aktivierung/Deaktivierung von 2FA und
 * Recovery-Code-Regeneration sind sicherheitsrelevant — fehlt der Audit-Trail,
 * lässt sich im Streitfall nicht rekonstruieren, wer 2FA wann abgeschaltet
 * oder Recovery-Codes erneuert hat.
 *
 * Bewusst NICHT geloggt:
 *  - `ValidTwoFactorAuthenticationCodeProvided` — der nachfolgende `Login`
 *    wird bereits in {@see LogAuthenticationActivityListener::handleLogin}
 *    erfasst; ein zusätzlicher Eintrag wäre redundant.
 *  - `TwoFactorAuthenticationChallenged` — feuert bei jedem Login eines
 *    2FA-Users und wäre nur Rauschen.
 *
 * Registrierung: Event-Auto-Discovery wertet die `handle*`-Type-Hints aus.
 */
final class LogTwoFactorActivityListener
{
    private const LOG_NAME = 'auth';

    public function handleEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->logForUser($event->user, '2fa_enabled', __('app.activity_2fa_enabled'));
    }

    public function handleConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->logForUser($event->user, '2fa_confirmed', __('app.activity_2fa_confirmed'));
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->logForUser($event->user, '2fa_disabled', __('app.activity_2fa_disabled'));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->logForUser(
            $event->user,
            '2fa_recovery_codes_regenerated',
            __('app.activity_2fa_recovery_codes_regenerated'),
        );
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        // Den verbrauchten Code selbst NICHT mitloggen — er ist Geheimnismaterial,
        // das nirgends im Klartext landen darf. Die Tatsache des Verbrauchs reicht
        // forensisch (n verbrauchte Codes ⇒ Recovery-Pool schrumpft).
        $this->logForUser(
            $event->user,
            '2fa_recovery_code_used',
            __('app.activity_2fa_recovery_code_used'),
        );
    }

    public function handleFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->logForUser($event->user, '2fa_failed', __('app.activity_2fa_failed'));
    }

    private function logForUser(mixed $user, string $eventCode, string $description): void
    {
        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(self::LOG_NAME)
            ->event($eventCode)
            ->causedBy($user)
            ->performedOn($user)
            ->log($description);
    }
}
