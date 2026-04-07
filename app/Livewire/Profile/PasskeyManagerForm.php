<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\PasskeyCredential;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Livewire component that lists a user's passkeys and handles deletion.
 *
 * Registration is initiated client-side via JavaScript (passkeys.js) and
 * completed via the JSON API endpoints. After registration the page is
 * reloaded via JS so this component automatically re-renders with the new list.
 */
class PasskeyManagerForm extends Component
{
    /**
     * @var \Illuminate\Database\Eloquent\Collection<int, PasskeyCredential>
     */
    public Collection $passkeys;

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    /**
     * Delete a passkey. Verifies that the credential belongs to the current user.
     */
    public function deletePasskey(string $passkeyId): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $passkey = PasskeyCredential::where('id', $passkeyId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $passkey->delete();

        $this->loadPasskeys();

        session()->flash('passkey_deleted', true);
    }

    public function render(): View
    {
        return view('livewire.profile.passkey-manager-form');
    }

    private function loadPasskeys(): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $this->passkeys = PasskeyCredential::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
