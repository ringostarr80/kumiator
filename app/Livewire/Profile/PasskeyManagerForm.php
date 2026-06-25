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
 * Livewire component that lists a user's passkeys and handles deletion.
 *
 * Registration is initiated client-side via JavaScript (passkeys.js) and
 * completed via the JSON API endpoints. After registration the JS dispatches
 * a "passkey-registered" browser event, which triggers onPasskeyRegistered()
 * to refresh the list without a full page reload.
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

    /**
     * Switch the row of the given passkey into rename-edit mode and prefill
     * the input with the current name.
     */
    public function startRenaming(string $passkeyId): void
    {
        $passkey = $this->repository->findByIdOrFail($passkeyId);

        Gate::authorize('update', $passkey);

        $this->editingPasskeyId = $passkey->id;
        $this->editingPasskeyName = $passkey->name;
        $this->resetValidation();
    }

    /**
     * Leave rename-edit mode without persisting changes.
     */
    public function cancelRenaming(): void
    {
        $this->editingPasskeyId = null;
        $this->editingPasskeyName = '';
        $this->resetValidation();
    }

    /**
     * Persist the new name for the currently-edited passkey. Authorization
     * is delegated to PasskeyCredentialPolicy.
     */
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

        if (!($user instanceof User)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $this->passkeys = $this->repository->findAllForUser($user);
    }
}
