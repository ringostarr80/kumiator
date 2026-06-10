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
 * GET rendert nur eine Landingpage mit Abbrechen-Button — bewusst ohne
 * Token-Lookup und ohne Seiteneffekt: Ein Link-Scanner in der Mailbox der
 * alten Adresse würde sonst jede legitime Änderung abbrechen, bevor der
 * User den Confirm-Link klicken kann. Erst der POST des Formulars bricht ab.
 *
 * Es wird IMMER derselbe Erfolgs-View ausgeliefert — egal, ob tatsächlich
 * eine Anfrage abgebrochen wurde oder ob der Token unbekannt war. Eine
 * Differenzierung wäre forensisch wertvoll, würde aber die Existenz einer
 * laufenden Änderung an Token-Probierer preisgeben.
 */
final class CancelEmailChangeController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.email-change-cancel', ['token' => $token]);
    }

    public function cancel(string $token, UserEmailChangerContract $emailChanger): View
    {
        $emailChanger->cancelChange($token);

        return view('auth.email-change-cancelled');
    }
}
