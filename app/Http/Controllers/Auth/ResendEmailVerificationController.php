<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\User\Contracts\EmailVerificationResenderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Versendet die E-Mail-Verifizierungs-Mail erneut (Self-Service-Resend).
 *
 * Überschreibt Fortifys `verification.send`-Route, weil Fortifys
 * `EmailVerificationNotificationController` den Versand ohne Audit-Trail
 * durchführt — der Versand + das `email_verification_requested`-Audit liegen
 * daher im {@see EmailVerificationResenderContract}-Service (Controller dürfen
 * `Spatie\Activitylog` nicht direkt aufrufen, siehe
 * `ControllersAreIndependentTest`).
 *
 * Die Response repliziert Fortifys Verhalten, OHNE dessen
 * `EmailVerificationNotificationSentResponse`-Contract zu nutzen — die
 * Architektur-Regel verbietet `Laravel\Fortify\*` als Controller-Abhängigkeit,
 * daher nur Illuminate-Rückgabetypen. Der bereits-verifiziert-Pfad bleibt
 * un-auditiert (reine View-Routing-Entscheidung ohne Resend, analog
 * {@see VerifyEmailController}).
 */
final class ResendEmailVerificationController extends Controller
{
    /**
     * Spiegelt `Laravel\Fortify\Fortify::VERIFICATION_LINK_SENT` — der
     * Session-Status, auf den die verify-email-Blade-View prüft. Als lokale
     * Konstante dupliziert, weil der Vendor-Wert hier nicht importiert werden
     * darf (Controller-Architektur-Regel).
     */
    private const string VERIFICATION_LINK_SENT = 'verification-link-sent';

    public function __invoke(
        Request $request,
        EmailVerificationResenderContract $resender,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();

        if (!$user instanceof User) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return $request->wantsJson()
                ? new JsonResponse('', 204)
                : redirect()->route('verification.notice');
        }

        $resender->resend($user);

        return $request->wantsJson()
            ? new JsonResponse('', 202)
            : back()->with('status', self::VERIFICATION_LINK_SENT);
    }
}
