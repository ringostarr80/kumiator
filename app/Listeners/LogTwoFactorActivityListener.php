<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
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
    public function handleEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->logForUser($event->user, ActivityEvent::TWO_FA_ENABLED);
    }

    public function handleConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->logForUser($event->user, ActivityEvent::TWO_FA_CONFIRMED);
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $user = $event->user;

        // Fortify dispatcht dasselbe Event in zwei fachlich unterschiedlichen
        // Situationen: (a) ein bereits bestätigtes 2FA wird entfernt, (b) ein
        // angefangener, aber nie bestätigter Setup wird verworfen. Beides geht
        // sicherheitstechnisch verschieden weit (Schutz tatsächlich gefallen
        // vs. Aufräumen vor dem ersten Wirken). `wasChanged()` prüft direkt
        // nach dem Save in der Fortify-Action: war `two_factor_confirmed_at`
        // vor dem Disable belegt (Timestamp → null), ist es geändert → echte
        // Deaktivierung. War es schon null (wird auf null „gesetzt"), kein
        // Change → Setup-Abbruch.
        //
        // Voraussetzung: das User-Model muss `two_factor_confirmed_at` in
        // seinem `original`-Snapshot kennen — das ist im Produktivpfad immer
        // der Fall, weil der User vor dem Disable aus der DB geladen wird.
        // In Tests ggf. `$user->fresh()` vor dem Disable aufrufen.
        if ($user->wasChanged('two_factor_confirmed_at')) {
            $this->logForUser($user, ActivityEvent::TWO_FA_DISABLED);

            return;
        }

        $this->logForUser($user, ActivityEvent::TWO_FA_SETUP_ABORTED);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->logForUser($event->user, ActivityEvent::TWO_FA_RECOVERY_CODES_REGENERATED);
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        // Den verbrauchten Code selbst NICHT mitloggen — er ist Geheimnismaterial,
        // das nirgends im Klartext landen darf. Die Tatsache des Verbrauchs reicht
        // forensisch (n verbrauchte Codes ⇒ Recovery-Pool schrumpft).
        $this->logForUser($event->user, ActivityEvent::TWO_FA_RECOVERY_CODE_USED);
    }

    public function handleFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->logForUser($event->user, ActivityEvent::TWO_FA_FAILED);
    }

    private function logForUser(mixed $user, ActivityEvent $event): void
    {
        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event($event->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log($event->description());
    }
}
