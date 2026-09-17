<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Profile\Concerns\RequiresFreshPasswordConfirmation;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
    use RequiresFreshPasswordConfirmation;

    /**
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\PasskeyCredential>
     */
    public Collection $passkeys;

    public ?string $editingPasskeyId = null;

    public string $editingPasskeyName = '';

    private PasskeyCredentialRepositoryContract $repository;

    private LoginMethodChangerContract $loginMethods;

    public function boot(
        PasskeyCredentialRepositoryContract $repository,
        LoginMethodChangerContract $loginMethods,
    ): void {
        $this->repository = $repository;
        $this->loginMethods = $loginMethods;
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
     * Der Alpine-Teil des Formulars hört auf das Event; die Freigabe fällt
     * hier, damit sie am selben Gate hängt wie die JSON-Endpunkte dahinter.
     */
    public function startPasskeyRegistration(): void
    {
        $this->ensurePasswordIsConfirmed();

        $this->dispatch('passkey-registration-confirmed');
    }

    /**
     * Bewusst ohne Passwortbestätigung: der Wechsel in den Bearbeitungsmodus
     * schreibt nichts, und `editingPasskeyId` lässt sich als public Property
     * ohnehin direkt setzen. Die Bestätigung hängt deshalb am Speichern.
     */
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

        // Erst das Gate, dann die Bestätigung: andernfalls endete ein Zugriff
        // auf fremde Passkeys am 403 der Bestätigung, bevor der
        // `authorization_denied`-Eintrag entstehen kann.
        $this->ensurePasswordIsConfirmed();

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

        // Reihenfolge wie beim Umbenennen: das Gate zuerst, damit ein
        // Fremdzugriff im Audit-Log landet.
        $this->ensurePasswordIsConfirmed();

        if (!$this->loginMethods->deletePasskey($passkey)) {
            // Eigener Schlüssel, weil die Meldung an der Liste steht und
            // nicht beim Knopf für den Passwort-Login, der `passkeys` belegt.
            throw ValidationException::withMessages([
                'passkey_delete' => [__('app.passkey_delete_last_blocked')],
            ]);
        }

        $this->loadPasskeys();

        session()->flash('passkey_deleted', true);
    }

    /**
     * Das Abschalten verlangt einen registrierten Passkey, sonst bliebe kein Weg
     * ins Konto.
     */
    public function disablePasswordLogin(): void
    {
        $this->ensurePasswordIsConfirmed(self::FRESH_CONFIRMATION_SECONDS);

        $user = $this->currentUser();

        if (!$this->loginMethods->disablePasswordLogin($user)) {
            throw ValidationException::withMessages([
                'passkeys' => [__('app.password_login_needs_passkey')],
            ]);
        }

        // Geschrieben hat der Dienst auf der Zeile, die er gesperrt hat, nicht auf
        // dieser Instanz. Ohne das Nachladen zeigte die Anzeige den Passwort-Login
        // noch als offen, obwohl er abgeschaltet ist.
        $user->refresh();

        $this->flashPasswordLoginState(true);
    }

    public function enablePasswordLogin(): void
    {
        $this->ensurePasswordIsConfirmed();

        $user = $this->currentUser();

        if ($this->loginMethods->enablePasswordLogin($user)) {
            // Geschrieben hat der Dienst auf der gesperrten Zeile; ohne das Nachladen
            // zeigte die Anzeige den Passwort-Login noch als abgeschaltet.
            $user->refresh();

            // Ein verworfenes Passwort ist ein neuer Hash, und `AuthenticateSession`
            // beendet jede Sitzung, die noch den alten trägt — die anderen Geräte
            // wie nach jedem Passwortwechsel, und ohne diese Zeile auch diese hier:
            // Livewire lässt die Middleware vor der Aktion laufen, sie sähe den
            // Wechsel erst beim nächsten Request und hielte ihn für fremd.
            session()->put(['password_hash_' . Auth::getDefaultDriver() => $user->getAuthPassword()]);
        }

        $this->flashPasswordLoginState(false);
    }

    /**
     * Ob der Passwort-Login abgeschaltet ist, wird bei jedem Rendern gelesen statt
     * zwischen den Aufrufen gehalten: Schaltet ein zweiter Tab ihn um, zeigte ein
     * gehaltener Wert bis zum nächsten Seitenaufbau den falschen Text und den
     * falschen Knopf.
     */
    public function render(): View
    {
        $user = $this->currentUser();

        return view('livewire.profile.passkey-manager-form', [
            'passwordLoginDisabled' => $user->isPasswordLoginDisabled(),
            'passwordDistrusted' => $user->hasDistrustedPassword(),
        ]);
    }

    /**
     * Nur das Abschalten sperrt fremde Geräte aus; das Einschalten meldet sie
     * bei verworfenem Passwort zwar ab, doch der Passkey, ohne den es kein
     * Abschalten gab, bringt sie wieder hinein. Die Registrierung bleibt an der
     * Frist der JSON-Endpunkte dahinter — ein strengerer Dialog davor schützte
     * sie nicht —, und Umbenennen wie Löschen tragen keine Folge, die den
     * kürzeren Nachweis rechtfertigte.
     *
     * @return list<string>
     */
    protected function freshlyConfirmedActions(): array
    {
        return ['disablePasswordLogin'];
    }

    private function flashPasswordLoginState(bool $disabled): void
    {
        session()->flash($disabled ? 'password_login_disabled' : 'password_login_enabled', true);
    }

    private function loadPasskeys(): void
    {
        $this->passkeys = $this->repository->findAllForUser($this->currentUser());
    }

    private function currentUser(): User
    {
        return Auth::user() ?? abort(Response::HTTP_UNAUTHORIZED);
    }
}
