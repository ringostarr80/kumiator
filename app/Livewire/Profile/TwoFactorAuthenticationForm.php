<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Profile\Concerns\RequiresFreshPasswordConfirmation;
use Illuminate\Contracts\View\View;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm as JetstreamTwoFactorAuthenticationForm;

/**
 * Die Schalter, nach denen das Formular Setup-Key und Wiederherstellungscodes
 * zeigt, kommen ungeprüft herein: Der Client setzt sie per Update, und ein
 * Snapshot ist nur signiert, nicht an Sitzung oder Nutzer gebunden — ein
 * anderes Konto bringt sie nach eigener Bestätigung mit. Erst das Rendern
 * gleicht sie deshalb mit der Bestätigung der laufenden Sitzung ab.
 *
 * Die Frist ist dieselbe wie an den Aktionen; eine kürzere blendete aus, was
 * die Aktion eben noch freigegeben hat.
 */
final class TwoFactorAuthenticationForm extends JetstreamTwoFactorAuthenticationForm
{
    use RequiresFreshPasswordConfirmation;

    public function render(): View
    {
        if (! $this->passwordIsConfirmed(self::FRESH_CONFIRMATION_SECONDS)) {
            $this->showingQrCode = false;
            $this->showingRecoveryCodes = false;
        }

        return parent::render();
    }

    /**
     * Jetstreams Aktionen fragen ohne Frist und fielen damit auf die drei
     * Stunden zurück.
     */
    protected function ensurePasswordIsConfirmed(mixed $maximumSecondsSinceConfirmation = null): void
    {
        parent::ensurePasswordIsConfirmed(self::FRESH_CONFIRMATION_SECONDS);
    }
}
