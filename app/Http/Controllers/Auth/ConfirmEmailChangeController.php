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
 * GET (statt POST): konsistent mit Laravels Email-Verify-Pattern. Risiko
 * Link-Prefetch durch Antiviren/Mail-Clients wurde bewusst akzeptiert
 * (Plan-Festlegung). Der Service ist nicht idempotent — ein zweiter Klick
 * auf denselben Token landet im Invalid-Pfad, weil die `pending_email*`-
 * Felder beim Erfolg geräumt wurden. Das ist akzeptabel, weil der erste
 * Klick (Prefetch oder User) immer die korrekte State-Transition vornimmt.
 */
final class ConfirmEmailChangeController extends Controller
{
    public function __invoke(string $token, UserEmailChangerContract $emailChanger): View
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
