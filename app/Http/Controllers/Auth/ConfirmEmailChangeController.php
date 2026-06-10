<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Exceptions\EmailChangeConflictException;
use App\Services\User\Exceptions\EmailChangeTargetNotEligibleException;
use App\Services\User\Exceptions\EmailChangeTokenExpiredException;
use App\Services\User\Exceptions\EmailChangeTokenInvalidException;
use Illuminate\Contracts\View\View;

/**
 * Endpoint für den Confirm-Link aus `VerifyEmailChangeNotification` (geht an
 * die NEUE Adresse). Klartext-Token aus dem Pfad-Parameter wird über den
 * Service verarbeitet; die Service-Schicht entscheidet über Erfolg/Fehlertyp.
 *
 * Bewusst guest-zugänglich: der Token IST die Berechtigung. Authentifizierung
 * wäre eine Sackgasse, wenn der User die Mail auf einem anderen Gerät öffnet
 * (analog zur Begründung im `VerifyEmailController`).
 *
 * GET rendert nur eine Landingpage mit Bestätigen-Button — bewusst ohne
 * Token-Lookup und ohne Seiteneffekt, damit Link-Prefetch durch
 * Antiviren/Mail-Scanner den Tausch nicht auslösen kann (bei einem
 * Tippfehler in der neuen Adresse würde sonst das fremde Mailsystem den
 * Wechsel vollziehen und den User aussperren). Erst der POST des Formulars
 * führt die Aktion aus. Der Service ist nicht idempotent — ein zweiter
 * Submit desselben Tokens landet im Invalid-Pfad, weil die
 * `pending_email*`-Felder beim Erfolg geräumt wurden. Das ist akzeptabel,
 * weil der erste Submit immer die korrekte State-Transition vornimmt.
 */
final class ConfirmEmailChangeController extends Controller
{
    public function show(string $token): View
    {
        return view('auth.email-change-confirm', ['token' => $token]);
    }

    public function confirm(string $token, UserEmailChangerContract $emailChanger): View
    {
        try {
            $emailChanger->confirmChange($token);
        } catch (EmailChangeTokenExpiredException) {
            return view('auth.email-change-expired');
        } catch (EmailChangeConflictException) {
            return view('auth.email-change-conflict');
        } catch (EmailChangeTokenInvalidException | EmailChangeTargetNotEligibleException) {
            // Bewusst gleicher View — sonst leakt das UI, ob ein Token einer
            // gelöschten/aktiven Identität entsprach (Account-Existenz-Oracle).
            return view('auth.email-change-invalid');
        }

        return view('auth.email-change-confirmed');
    }
}
