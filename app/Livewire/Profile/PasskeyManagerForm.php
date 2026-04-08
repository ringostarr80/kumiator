<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Repositories\PasskeyCredentialRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\PasskeyCredential>
     */
    public Collection $passkeys;

    private PasskeyCredentialRepository $repository;

    public function boot(PasskeyCredentialRepository $repository): void
    {
        $this->repository = $repository;
    }

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    /**
     * Delete a passkey. Authorization is delegated to PasskeyCredentialPolicy.
     */
    public function deletePasskey(string $passkeyId): void
    {
        $passkey = $this->repository->findByIdOrFail($passkeyId);

        Gate::authorize('delete', $passkey);

        $this->repository->delete($passkey);

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

        $this->passkeys = $this->repository->findAllForUser($user);
    }
}
