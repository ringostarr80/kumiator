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
 * Im Gegensatz zum Fortify-Default ist hier KEIN `auth`-Middleware zwingend,
 * weil typische Realfälle (Mail wird auf einem anderen Gerät geöffnet als
 * das Registrier-Gerät) sonst in einer Sackgasse enden — der User kommt
 * mit `approved_at = null` ohnehin nicht durch den Login.
 *
 * Sicherheitsbasis: signierte URL (HMAC-SHA256 + APP_KEY, Ablauffrist) plus
 * Hash-Vergleich des aktuellen E-Mail-Strings (alte Links werden nach einem
 * Email-Wechsel automatisch ungültig).
 *
 * Verify- und Audit-Logik im {@see SelfEmailVerifierContract}: Controller
 * dürfen `Spatie\Activitylog` nicht direkt aufrufen (siehe
 * `ControllersAreIndependentTest`); die Failure-Audits können also nicht
 * inline hier landen. Der Already-Verified-Pfad bleibt hier oben, weil er
 * reine View-Routing-Entscheidung ohne Audit-Bedarf ist.
 */
final class VerifyEmailController extends Controller
{
    public function __invoke(
        Request $request,
        int $id,
        string $hash,
        SelfEmailVerifierContract $verifier,
    ): RedirectResponse {
        $user = User::find($id);

        if ($user !== null && $user->hasVerifiedEmail()) {
            return redirect()->route('login')
                ->with('status', __('app.email_already_verified_message'));
        }

        try {
            $verifier->verify($id, $hash);
        } catch (SelfEmailVerificationFailedException) {
            abort(403);
        }

        return redirect()->route('login')
            ->with('status', __('app.email_verified_pending_approval_message'));
    }
}
