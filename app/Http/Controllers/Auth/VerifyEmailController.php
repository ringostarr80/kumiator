<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\User\Contracts\SelfEmailVerifierContract;
use App\Services\User\Exceptions\SelfEmailVerificationFailedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bestätigt die E-Mail-Adresse eines Users über eine signierte URL.
 *
 * Im Gegensatz zum Fortify-Default ist hier KEIN `auth`-Middleware zwingend:
 * Wer die Mail auf einem anderen Gerät öffnet als dem, auf dem er angemeldet
 * ist, müsste sich sonst erst dort anmelden, nur um die Adresse zu bestätigen.
 *
 * Sicherheitsbasis: signierte URL (HMAC-SHA256 + APP_KEY) plus
 * Hash-Vergleich des aktuellen E-Mail-Strings (alte Links werden nach einem
 * Email-Wechsel automatisch ungültig). Die Ablauffrist steckt mitsigniert im
 * ausgestellten Link; `signed` selbst verlangt keine.
 *
 * Verify- und Audit-Logik im {@see SelfEmailVerifierContract}: Controller
 * dürfen `Spatie\Activitylog` nicht direkt aufrufen (siehe
 * `ControllersAreIndependentTest`); die Failure-Audits können also nicht
 * inline hier landen. Der Already-Verified-Pfad bleibt hier oben, weil er
 * reine View-Routing-Entscheidung ohne Audit-Bedarf ist.
 */
final class VerifyEmailController extends Controller
{
    /**
     * Angemeldete schickt `guest` von `login` gleich zum Dashboard weiter, und
     * dieser Zwischenschritt verbraucht den Flash. Ein anderes angemeldetes Konto
     * bekommt das Banner nicht, weil die Meldung nicht von ihm handelt.
     */
    private function redirectWithMessage(Request $request, ?User $user, string $message): RedirectResponse
    {
        if ($request->user()?->is($user) === true) {
            return redirect()->route('dashboard')->banner($message);
        }

        return redirect()->route('login')->with('status', $message);
    }

    public function __invoke(
        Request $request,
        int $id,
        string $hash,
        SelfEmailVerifierContract $verifier,
    ): RedirectResponse {
        $user = User::find($id);

        if ($user !== null && $user->hasVerifiedEmail()) {
            return $this->redirectWithMessage($request, $user, __('app.email_already_verified_message'));
        }

        try {
            $verifier->verify($id, $hash);
        } catch (SelfEmailVerificationFailedException) {
            abort(403);
        }

        return $this->redirectWithMessage($request, $user, __('app.email_verified_message'));
    }
}
