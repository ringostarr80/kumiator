<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Audit\AuditEmailHasher;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\Concerns\MarksRequestScope;
use Spatie\Activitylog\Facades\Activity;

/**
 * Audit-Schreiber für nicht freigeschaltete Logins plus der Marker, der den
 * im Passwort-Pfad sonst entstehenden Doppel-Eintrag verhindert.
 *
 * Im Passwort-Pfad (`FortifyServiceProvider::authenticateUsing()`) gibt das
 * Closure `null` zurück, sobald der Account valide Credentials hat, aber noch
 * nicht freigeschaltet ist (`approved_at === null`). Fortify feuert daraufhin
 * `Illuminate\Auth\Events\Failed`, das ohne Marker — zusätzlich zum bereits
 * geschriebenen `login_unapproved`-Eintrag — als generischer `login_failed`
 * landen würde. Der Marker lässt `LogAuthenticationActivityListener::handleFailed()`
 * diesen Doppel-Log überspringen: `login_unapproved` ist die fachlich präzise
 * Aussage, `login_failed` wäre redundant und würde Reports verzerren.
 *
 * `record()` schreibt nur den Audit-Eintrag und wird von Passwort- und
 * Passkey-Pfad geteilt; den Marker setzt allein der Passwort-Pfad, weil nur
 * dort ein `Failed`-Event folgt. Die scoped-Lebensdauer des Markers begründet
 * der `MarksRequestScope`-Trait.
 */
final class UnapprovedLoginContext implements UnapprovedLoginContextContract
{
    use MarksRequestScope;

    /**
     * Geteilter Schreibpfad für den `login_unapproved`-Eintrag, damit Passwort-
     * und Passkey-Pfad Hashing, Properties und Translation-Key nicht doppelt
     * pflegen.
     *
     * Causer/Subject werden bewusst auf den User gesetzt: anders als bei
     * anonymen `login_failed`-Versuchen ist hier die Identität verifiziert
     * (Passwort-Hash bzw. Passkey-Verifikation war erfolgreich) — eine
     * Doppel-Speicherung als `email_hash` UND `causer_id` ist für die
     * Symmetrie zum `login_failed`-Pfad ausdrücklich gewünscht (erlaubt
     * Reports, die nur über `email_hash` korrelieren, ohne Causer aufzulösen).
     */
    public function record(User $user, string $guard, ?string $email): void
    {
        $properties = ['guard' => $guard];

        $emailHash = AuditEmailHasher::hash($email);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::LOGIN_UNAPPROVED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties($properties)
            ->log(ActivityEvent::LOGIN_UNAPPROVED->description());
    }
}
