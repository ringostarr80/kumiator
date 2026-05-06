<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contracts\MustBeApproved;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendant zu `Illuminate\Auth\Middleware\EnsureEmailIsVerified`: blockt
 * eingeloggte User, deren Konto noch nicht durch einen Admin freigeschaltet
 * wurde, und leitet sie auf die Statusseite `registration.pending` um.
 *
 * `approved_at` ist der zweite, vom Email-Verify unabhängige Gate — nur in
 * Kombination mit `verified` decken beide Tore alle Compliance- und
 * Sicherheitsfälle ab (Email-Echtheit + manuelle Vereinsfreigabe).
 */
final class EnsureUserIsApproved
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustBeApproved && ! $user->isApproved()) {
            return $request->expectsJson()
                ? response()->json(['message' => __('app.registration_pending_message')], Response::HTTP_FORBIDDEN)
                : redirect()->route('registration.pending');
        }

        return $next($request);
    }
}
