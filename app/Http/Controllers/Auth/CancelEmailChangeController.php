<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Contracts\View\View;

/**
 * Endpoint für den Cancel-Link aus `EmailChangeRequestedNotification`
 * (geht an die ALTE Adresse). Klartext-Token aus dem Pfad-Parameter; der
 * Service ist idempotent — unbekannter Token → No-Op ohne Audit-Eintrag.
 *
 * Bewusst guest-zugänglich: das Hijack-Schutz-Szenario („Angreifer hat
 * meine E-Mail geändert") setzt voraus, dass das Opfer auch ohne aktive
 * Session den Vorgang abbrechen kann.
 *
 * Es wird IMMER derselbe Erfolgs-View ausgeliefert — egal, ob tatsächlich
 * eine Anfrage abgebrochen wurde oder ob der Token unbekannt war. Eine
 * Differenzierung wäre forensisch wertvoll, würde aber die Existenz einer
 * laufenden Änderung an Token-Probierer preisgeben.
 */
final class CancelEmailChangeController extends Controller
{
    public function __invoke(string $token, UserEmailChangerContract $emailChanger): View
    {
        $emailChanger->cancelChange($token);

        return view('auth.email-change-cancelled');
    }
}
