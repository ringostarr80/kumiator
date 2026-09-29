<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Contracts\SelfRegistrarContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ersetzt Fortifys Registrierung: Deren Controller meldet das neue Konto
 * sofort an, und angemeldet zu sein oder nicht verriete, ob die Adresse noch
 * frei war. Hier endet jede gültige Registrierung auf derselben Seite.
 */
final class RegisterController extends Controller
{
    public function __invoke(Request $request, SelfRegistrarContract $registrar): RedirectResponse
    {
        $registrar->register($request->all());

        return redirect()->route('login')->with('status', __('app.registration_submitted'));
    }
}
