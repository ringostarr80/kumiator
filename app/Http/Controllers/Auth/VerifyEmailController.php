<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
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
 */
final class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('login')
                ->with('status', __('app.email_already_verified_message'));
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect()->route('login')
            ->with('status', __('app.email_verified_pending_approval_message'));
    }
}
