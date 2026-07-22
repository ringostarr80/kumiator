<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortifys Controller reichen die Anmeldefelder roh an eine string-Grenze
 * (`mb_strtolower()` bzw. eine `?string`-Signatur), noch bevor eine Validierung
 * greift. Array-Input schlägt dort mit einem TypeError zu HTTP 500 durch — auf
 * dem öffentlich erreichbaren Registrierungsweg von außen auslösbar. Dieser
 * Guard weist Nicht-Skalare in den vom Aufrufer benannten Feldern vorab mit
 * einem regulären 422 ab.
 */
final class EnsureFortifyCredentialsAreScalar
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$fields): Response
    {
        foreach ($fields as $field) {
            if (is_array($request->input($field))) {
                throw ValidationException::withMessages([
                    $field => __('validation.string', ['attribute' => $field]),
                ]);
            }
        }

        return $next($request);
    }
}
