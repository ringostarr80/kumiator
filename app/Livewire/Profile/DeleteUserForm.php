<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Profile\Concerns\RequiresFreshPasswordConfirmation;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Livewire\Component;

/**
 * Ersetzt Jetstreams Komponente durch den zentralen Bestätigungspfad.
 *
 * Jetstream vergleicht das Passwort selbst gegen den Hash und geht damit an
 * `Fortify::confirmPasswordsUsing` vorbei. Der abgeschaltete Passwort-Login
 * bliebe ausgerechnet vor der folgenreichsten Aktion des Profils wirkungslos
 * — die Löschung ist ein Hard-Delete —, und einem Konto ohne nutzbares
 * Passwort wäre sie umgekehrt gar nicht mehr zugänglich. `RequiresFreshPasswordConfirmation`
 * bedient beide Seiten: Die Abschaltung greift, und der Dialog bietet den
 * Passkey als Nachweis an.
 *
 * Ableiten statt ersetzen scheidet aus, weil Livewire jede public Methode der
 * Komponente aufrufbar macht: Jetstreams `$password` bliebe im Client-State
 * stehen, und der geerbte Rückgabetyp verträgt sich nicht mit Livewires
 * eigenem Redirect-Weg.
 */
final class DeleteUserForm extends Component
{
    use RequiresFreshPasswordConfirmation;

    public bool $confirmingUserDeletion = false;

    /** Der Nachweis liegt vor dieser Rückfrage; sie ist die letzte Hürde, nicht die einzige. */
    public function confirmUserDeletion(): void
    {
        $this->confirmingUserDeletion = true;
    }

    public function deleteUser(Request $request, DeletesUsers $deleter, StatefulGuard $auth): void
    {
        $this->ensurePasswordIsConfirmed(self::FRESH_CONFIRMATION_SECONDS);

        // Der Löscher braucht eine eigene Instanz: `logout()` zykliert unten den
        // Remember-Token auf dem Model, das der Guard hält. Wäre das dasselbe
        // Objekt, legte dessen `save()` den gerade gelöschten Datensatz wieder an.
        $deleter->delete(User::query()->whereKey($this->currentUser()->getKey())->firstOrFail());

        $auth->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Fortify legt den Schlüssel mit `null` an, wenn kein Ziel gesetzt ist —
        // `Config::string()` bekommt seinen Default deshalb nie zu sehen.
        $target = Config::get('fortify.redirects.logout');

        $this->redirect(is_string($target) ? $target : '/');
    }

    public function render(): View
    {
        return view('profile.delete-user-form');
    }

    private function currentUser(): User
    {
        return Auth::user() ?? abort(Response::HTTP_UNAUTHORIZED);
    }
}
