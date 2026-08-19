<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Listet die Passkeys eines Nutzers auf und löscht sie.
 *
 * Die Registrierung startet clientseitig im JavaScript und läuft über die
 * JSON-Endpunkte. Danach löst das JavaScript das Browser-Event
 * "passkey-registered" aus, worauf die Liste ohne vollen Seiten-Reload neu lädt.
 */
class PasskeyManagerForm extends Component
{
    /**
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\PasskeyCredential>
     */
    public Collection $passkeys;

    public ?string $editingPasskeyId = null;

    public string $editingPasskeyName = '';

    private PasskeyCredentialRepositoryContract $repository;

    public function boot(PasskeyCredentialRepositoryContract $repository): void
    {
        $this->repository = $repository;
    }

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    #[On('passkey-registered')]
    public function onPasskeyRegistered(): void
    {
        $this->loadPasskeys();
    }

    public function startRenaming(string $passkeyId): void
    {
        $passkey = $this->repository->findByIdOrFail($passkeyId);

        Gate::authorize('update', $passkey);

        $this->editingPasskeyId = $passkey->id;
        $this->editingPasskeyName = $passkey->name;
        $this->resetValidation();
    }

    public function cancelRenaming(): void
    {
        $this->editingPasskeyId = null;
        $this->editingPasskeyName = '';
        $this->resetValidation();
    }

    public function renamePasskey(): void
    {
        $this->validate([
            'editingPasskeyName' => 'required|string|max:80',
        ]);

        if ($this->editingPasskeyId === null) {
            return;
        }

        $passkey = $this->repository->findByIdOrFail($this->editingPasskeyId);

        Gate::authorize('update', $passkey);

        $this->repository->updateName($passkey, trim($this->editingPasskeyName));

        $this->editingPasskeyId = null;
        $this->editingPasskeyName = '';

        $this->loadPasskeys();

        session()->flash('passkey_renamed', true);
    }

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

        if (!($user instanceof User)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $this->passkeys = $this->repository->findAllForUser($user);
    }
}
