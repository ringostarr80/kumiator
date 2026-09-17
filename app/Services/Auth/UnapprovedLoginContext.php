<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\Concerns\MarksRequestScope;
use App\Services\Concerns\RecordsRejectedLoginActivity;

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

    use RecordsRejectedLoginActivity;

    public function record(User $user, string $guard, ?string $email): void
    {
        $this->recordRejectedLogin($user, ActivityEvent::LOGIN_UNAPPROVED, $guard, $email);
    }
}
